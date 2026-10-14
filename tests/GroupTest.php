<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Group;
use Macula\GroupError;
use Macula\MaculaException;
use Macula\NodeKey;
use Macula\Pool;
use Macula\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The member side of sealed groups over libmacula (macula 13.2,
 * DESIGN_E2E_SEALED_PUBSUB.md): a publish sealed under the group's current
 * epoch, a subscription that opens it, a plain subscription that gets the same
 * event unopened, and every refusal, each on the PHP side of the C ABI.
 *
 * The org's distributor is STOOD IN FOR, by tests/fixtures/provider.php's
 * `groupdist` kind. That is a FIXTURE and not this SDK sealing anything: it
 * serves `<org>/group_keys_v1` sealed under the org's own grant policy, holds
 * no key of its own, and hands back the epoch a test gives it. The epoch's
 * `id` and `key` are the group-key vectors'
 * (fixtures/e2e_seal_v1_group_keys.json, which the first test pins), so a
 * member's `seal_key_id` is the vector's own. Every seal and every open of a
 * group event happens inside libmacula; PHP holds no epoch key and re-encodes
 * no sealed payload.
 *
 * The vectors' epoch times are their generator's clock (September 2026), long
 * past, so the fixture is handed live ones: `issued_at` a second ago, a
 * 15-minute publishing window (macula's DefaultRotateAfterMs) and an accept
 * window 65 minutes past that (its eventLifeMs), which is the shape a real
 * distributor's epoch always has.
 *
 * What this SDK cannot do, and what carries it instead: the CALL and the
 * RESULT cross the ABI as CBOR inside libmacula, so PHP asserts at the value
 * level and the byte-for-byte is libmacula's at the pinned release (whose own
 * tests carry these vectors), and the six org-grant UCAN cases need
 * `ucan.Authorize`, which the ABI does not export and PHP cannot re-run. The
 * live half is the Erlang cross-test, scripts/interop/groups.sh, where
 * macula_group_keys is the authorizer.
 */
final class GroupTest extends TestCase
{
    /** The vector file as macula-go carries it at the tag abi/MACULA_GO_REF
     * pins. */
    private const VECTORS_SHA256 = 'e2f9f9a35eab8931cae260301f0034fe5a9b6a6b6d5776b480f242b1b2a6d7a2';

    private static TestStations $env;

    public static function setUpBeforeClass(): void
    {
        self::$env = TestStations::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$env->stop();
    }

    private function node(int $station): Pool
    {
        return Pool::connect(NodeKey::generate(Profile::PqPure), [self::$env->seed($station)],
            realmTrust: self::$env->trust());
    }

    /** The realm and org this run is under, as a group prefix: the vectors'
     * own `io.macula/acme/chat` names a realm and an org that no test station
     * holds. */
    private function prefix(): string
    {
        return self::$env->realmName . '/' . self::$env->org . '/chat';
    }

    /** The group-key vectors, checked against the bytes they were copied from. */
    private function vectors(): array
    {
        $path = __DIR__ . '/fixtures/e2e_seal_v1_group_keys.json';
        self::assertSame(self::VECTORS_SHA256, hash_file('sha256', $path));
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The vectors' first epoch, with live times (this class's header says
     * why). */
    private function epoch(): array
    {
        $e = $this->vectors()['payloads']['result']['epochs'][0];
        $now = (int) (microtime(true) * 1000);
        $publishUntil = $now - 1_000 + 900_000;
        return ['id' => $e['id'], 'key' => $e['key'], 'issued_at' => $now - 1_000,
            'publish_until' => $publishUntil, 'accept_until' => $publishUntil + 3_900_000];
    }

    private function tempFile(string $what): string
    {
        $path = tempnam(sys_get_temp_dir(), $what);
        if ($path === false) {
            throw new \RuntimeException("no temporary file for {$what}");
        }
        return $path;
    }

    /** The org's stand-in distributor, serving `group_keys_v1` for prefix. It
     * refuses the reason in $refuseFile when that file holds one, and appends
     * every CALL it is asked to $callsFile. */
    private function distributor(string $prefix, string $callsFile, string $refuseFile): ProviderProcess
    {
        return ProviderProcess::start(self::$env, 'groupdist', 0, admitted: true, extra: [
            'org_key_id' => self::$env->orgKeyId,
            'prefix' => $prefix,
            'epoch' => $this->epoch(),
            'calls_file' => $callsFile,
            'refuse_file' => $refuseFile,
        ]);
    }

    /** The CALLs the stand-in was asked, each with its keys sorted so it can be
     * compared. */
    private function calls(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(static function (string $line): array {
            $call = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            ksort($call);
            return $call;
        }, $lines);
    }

    /** Waits until the org's distributor is advertised, so a join finds it: it
     * is a DHT lookup, and the serve it follows lags. */
    private function awaitDistributor(Pool $pool, string $prefix): void
    {
        $procedure = explode('/', $prefix)[1] . '/group_keys_v1';
        $deadline = microtime(true) + 30;
        while ($pool->providers(self::$env->realmId, $procedure) === []) {
            if (microtime(true) > $deadline) {
                self::fail("never: {$procedure} is advertised");
            }
            usleep(100_000);
        }
    }

    /** The vectors, whole: they are carried byte for byte, and what PHP is
     * party to at all is the refusals' names and the first epoch's id and
     * key. */
    public function testTheGroupKeyVectorsAreCarriedAndNameTheRefusals(): void
    {
        $v = $this->vectors();
        self::assertSame('io.macula', $v['realm_name']);
        self::assertSame('scripts/generate-seal-group-keys-vectors.sh', $v['generator']);
        self::assertSame('sealed groups, macula 13.2 (docs/design/DESIGN_E2E_SEALED_PUBSUB.md)', $v['scheme']);
        self::assertSame('test/vectors/E2E_SEAL_V1.md', $v['spec']);

        // The distributor's own vocabulary, which the vectors list. It is not
        // the closed set this SDK holds: the last of them is the one the
        // keyring maps to no_distributor.
        self::assertSame(['not_a_member', 'membership_unknown', 'unknown_epoch', 'epoch_expired', 'unknown_group'],
            $v['refusals']);

        // The CALL the vectors pin, and the RESULT's first epoch, which the
        // stand-in distributor serves.
        self::assertSame('io.macula/acme/chat', $v['payloads']['call_current']['prefix']);
        self::assertSame('current', $v['payloads']['call_current']['epoch']);
        self::assertSame('0101010101010101', $v['payloads']['call_by_id']['epoch_id']);
        $epoch = $v['payloads']['result']['epochs'][0];
        self::assertSame('0101010101010101', $epoch['id']);
        self::assertSame(str_repeat('a1', 32), $epoch['key']);
        self::assertSame('required', $v['payloads']['result']['policy']);
        // an epoch accepts for exactly one event life past its publishing
        self::assertSame(3_900_000, $epoch['accept_until'] - $epoch['publish_until']);
        self::assertSame(900_000, $epoch['publish_until'] - $epoch['issued_at']);
    }

    /** A member opens the event the publisher sealed, a plain subscriber on
     * the same station gets it unopened, and a clear publish under the held
     * group is refused rather than sent in the clear. */
    public function testAMemberOpensASealedEventAndAPlainSubscriberGetsItUnopened(): void
    {
        $prefix = $this->prefix();
        $topic = $prefix . '/said_v1';
        $callsFile = $this->tempFile('macula-php-group-calls-');
        $refuseFile = $this->tempFile('macula-php-group-refuse-');
        unlink($refuseFile);
        $distributor = $this->distributor($prefix, $callsFile, $refuseFile);
        // The stations relay no publication between them, so the plain
        // subscriber hears the publisher's station.
        $publisher = $this->node(0);
        $reader = $this->node(1);
        $outsider = $this->node(0);
        try {
            $this->awaitDistributor($reader, $prefix);
            $member = new Group($prefix, ucan: self::$env->grant($reader->nodeId()));
            $sub = $reader->subscribeGroup(self::$env->realmId, $topic, $member, timeoutMs: 20_000);
            $plain = $outsider->subscribe(self::$env->realmId, $topic);
            usleep(200_000);
            $publisher->publishGroup(self::$env->realmId, $topic, ['text' => 'hello'],
                new Group($prefix, ucan: self::$env->grant($publisher->nodeId())), timeoutMs: 20_000);

            $event = $sub->next(15_000);
            self::assertNotNull($event, 'the member heard the event');
            self::assertSame($topic, $event->topic);
            self::assertTrue($event->sealed);
            self::assertSame($this->vectors()['payloads']['result']['epochs'][0]['id'], $event->sealKeyId,
                'the vectors\' epoch is what sealed it');
            self::assertNull($event->unopened, 'the member opened it');
            self::assertSame(['text' => 'hello'], $event->payload);

            $unopened = $plain->next(15_000);
            self::assertNotNull($unopened, 'the plain subscriber heard it too');
            self::assertSame(GroupError::REASON_NO_GROUP, $unopened->unopened);
            self::assertNull($unopened->payload);
            self::assertSame($topic, $unopened->topic);

            // What the group asked the distributor for, as it crossed: the
            // current epoch of this prefix, the CALL the vectors pin.
            self::assertContainsEquals(['epoch' => 'current', 'prefix' => $prefix], $this->calls($callsFile));

            // A clear publish under a prefix this node holds is refused, and
            // names the prefix, rather than going out in the clear.
            $held = null;
            try {
                $publisher->publish(self::$env->realmId, $topic, ['text' => 'clear']);
            } catch (GroupError $e) {
                $held = $e;
            }
            self::assertInstanceOf(GroupError::class, $held, 'a clear publish under the held group');
            self::assertSame(GroupError::REASON_HELD, $held->reason);
            self::assertSame($prefix, $held->prefix);

            $sub->stop();
            $plain->stop();
        } finally {
            $distributor->stop();
            $publisher->close();
            $reader->close();
            $outsider->close();
            @unlink($callsFile);
            @unlink($refuseFile);
        }
    }

    /** An impostor presenting a grant the org minted for another node is
     * refused and gets no epoch: the gate refuses the caller before the
     * handler, which names nothing, so the reason is no_distributor. */
    public function testAnImpostorIsRefusedAndGetsNoKey(): void
    {
        $prefix = $this->prefix();
        $topic = $prefix . '/said_v1';
        $callsFile = $this->tempFile('macula-php-group-calls-');
        $refuseFile = $this->tempFile('macula-php-group-refuse-');
        unlink($refuseFile);
        $distributor = $this->distributor($prefix, $callsFile, $refuseFile);
        $member = $this->node(0);
        $impostor = $this->node(0);
        try {
            $this->awaitDistributor($member, $prefix);
            // The org's own grant is taken first, so a refusal below is the
            // impostor's and not a distributor not yet reachable.
            $grant = self::$env->grant($member->nodeId());
            $joined = $member->subscribeGroup(self::$env->realmId, $topic, new Group($prefix, ucan: $grant),
                timeoutMs: 20_000);
            $joined->stop();

            $refused = null;
            try {
                $impostor->subscribeGroup(self::$env->realmId, $topic, new Group($prefix, ucan: $grant),
                    timeoutMs: 20_000);
            } catch (GroupError $e) {
                $refused = $e;
            }
            self::assertInstanceOf(GroupError::class, $refused, 'a grant minted for another node');
            self::assertSame(GroupError::REASON_NO_DISTRIBUTOR, $refused->reason);
            self::assertNull($refused->prefix, 'only a held group names a prefix');
            // no_distributor is the catch-all: a distributor that is
            // unreachable, one that died, and a gate that refuses the caller
            // all arrive as it. So the verdict is held to the two things only
            // the gate gives: the refusal names the gate's code, which an
            // outage does not, and the impostor's pull never reached the
            // handler, the member's single pull being the only call in the
            // file.
            self::assertStringContainsString('unauthorized', $refused->getMessage());
            self::assertCount(1, $this->calls($callsFile), 'only the member\'s pull reached the handler');
        } finally {
            $distributor->stop();
            $member->close();
            $impostor->close();
            @unlink($callsFile);
            @unlink($refuseFile);
        }
    }

    /** The distributor's named refusals, each held as this SDK holds it. */
    public function testTheDistributorsRefusalsAreHeldAsTheSdksOwnReasons(): void
    {
        $prefix = $this->prefix();
        $topic = $prefix . '/said_v1';
        $callsFile = $this->tempFile('macula-php-group-calls-');
        $refuseFile = $this->tempFile('macula-php-group-refuse-');
        $distributor = $this->distributor($prefix, $callsFile, $refuseFile);
        try {
            $awaiting = $this->node(0);
            $this->awaitDistributor($awaiting, $prefix);
            $awaiting->close();

            // Every name the vectors list, and what it is held as: the first
            // four as they are named, the last, the distributor's word for a
            // group it does not serve, as no_distributor.
            $named = ['not_a_member' => GroupError::REASON_NOT_A_MEMBER,
                'membership_unknown' => GroupError::REASON_MEMBERSHIP_UNKNOWN,
                'unknown_epoch' => GroupError::REASON_UNKNOWN_EPOCH,
                'epoch_expired' => GroupError::REASON_EPOCH_EXPIRED,
                'unknown_group' => GroupError::REASON_NO_DISTRIBUTOR];
            self::assertSame($this->vectors()['refusals'], array_keys($named),
                'the vectors name exactly these');

            foreach ($named as $refusal => $held) {
                file_put_contents($refuseFile, $refusal . "\n");
                $refused = null;
                $node = $this->node(0);
                try {
                    $node->publishGroup(self::$env->realmId, $topic, ['text' => 'hello'],
                        new Group($prefix, ucan: self::$env->grant($node->nodeId())), timeoutMs: 20_000);
                } catch (GroupError $e) {
                    $refused = $e;
                } finally {
                    $node->close();
                }
                self::assertInstanceOf(GroupError::class, $refused, "the distributor refusing {$refusal}");
                self::assertSame($held, $refused->reason, "the distributor refusing {$refusal}");
            }

            // A distributor that names nothing is no_distributor as well: the
            // same path a timeout or a malformed reply takes.
            file_put_contents($refuseFile, "unauthorized\n");
            $refused = null;
            $node = $this->node(0);
            try {
                $node->publishGroup(self::$env->realmId, $topic, ['text' => 'hello'],
                    new Group($prefix, ucan: self::$env->grant($node->nodeId())), timeoutMs: 20_000);
            } catch (GroupError $e) {
                $refused = $e;
            } finally {
                $node->close();
            }
            self::assertInstanceOf(GroupError::class, $refused);
            self::assertSame(GroupError::REASON_NO_DISTRIBUTOR, $refused->reason);
        } finally {
            $distributor->stop();
            @unlink($callsFile);
            @unlink($refuseFile);
        }
    }

    /** @return list<array{string, \Closure(): mixed}> */
    public static function badGroupOptions(): array
    {
        return [
            'no prefix' => ['a group names a prefix', fn () => new Group('')],
            'an empty ucan' => ['never empty', fn () => new Group('r/o/chat', '')],
            'a distributor that is not an id' => ['64 hex characters or 32 bytes',
                fn () => (new Group('r/o/chat', null, 'zz'))->options()],
            'a distributor of 63 hex' => ['64 hex characters or 32 bytes',
                fn () => (new Group('r/o/chat', null, str_repeat('ab', 31) . 'a'))->options()],
        ];
    }

    /** A group this SDK can carry is refused here, with a PHP exception, and
     * never reaches the library. */
    #[DataProvider('badGroupOptions')]
    public function testAGroupRefusesAnOptionItCannotCarry(string $message, \Closure $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        $make();
    }

    /** A group's prefix and its topic are checked by libmacula before anything
     * is sent, and a negative ttl by this SDK before the library sees it. */
    public function testTheLibraryRefusesAPrefixAndATopicThatAreNotAGroups(): void
    {
        $pool = $this->node(0);
        $realm = self::$env->realmId;
        try {
            // fewer than two segments, so not a group at all
            $notAGroup = null;
            try {
                $pool->publishGroup($realm, 'one/one_v1', ['text' => 'hello'], new Group('one'), timeoutMs: 20_000);
            } catch (MaculaException $e) {
                $notAGroup = $e;
            }
            self::assertInstanceOf(MaculaException::class, $notAGroup, 'a prefix that is not realm/org/...');
            self::assertSame('invalid_argument', $notAGroup->kind);

            // a topic outside the prefix it is published under
            $outside = null;
            try {
                $pool->subscribeGroup($realm, 'elsewhere/said_v1',
                    new Group($this->prefix(), ucan: self::$env->grant($pool->nodeId())), timeoutMs: 20_000);
            } catch (MaculaException $e) {
                $outside = $e;
            }
            self::assertSame('invalid_argument', $outside?->kind, 'a topic not under the prefix');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/negative ttlMs/');
            $pool->publishGroup($realm, 'a/b/said_v1', ['text' => 'hello'], new Group('a/b'), ttlMs: -1);
        } finally {
            $pool->close();
        }
    }

    /**
     * macula-php#11: a group's grant is a bearer credential a distributor
     * accepts, so what a class can redact is redacted, and the three paths PHP
     * gives a class no hook for are pinned here rather than assumed — a PHP that
     * ever gains one fails this test instead of silently leaving the class doc
     * wrong.
     */
    public function testAGroupNeverPrintsItsGrant(): void
    {
        // A grant for a node that is never called: this test formats a group and
        // touches no station beyond the harness the class already starts.
        $grant = self::$env->grant(str_repeat('ab', 32));
        $group = new Group($this->prefix(), ucan: $grant);
        $without = new Group($this->prefix());

        ob_start();
        var_dump($group);
        $dumped = (string) ob_get_clean();

        foreach ([
            'print_r' => print_r($group, true),
            'var_dump' => $dumped,
            'json_encode' => json_encode($group, JSON_THROW_ON_ERROR),
            'a json_encode with the group nested' => json_encode(['group' => $group], JSON_THROW_ON_ERROR),
        ] as $path => $printed) {
            self::assertStringNotContainsString($grant, $printed, $path . ' shows the grant');
            // Not a part of it either, so a redaction that trimmed the text
            // rather than leaving it out would fail here.
            self::assertStringNotContainsString(substr($grant, 0, 64), $printed, $path . ' shows part of the grant');
            // Still the group, though: json_encode escapes the prefix's slashes
            // unless it is asked not to, and that is the only difference.
            self::assertStringContainsString($group->prefix, str_replace('\\/', '/', $printed),
                $path . ' still names the group');
        }

        self::assertSame('(set)', $group->__debugInfo()['ucan'], 'a grant that is there reads as present');
        self::assertNull($without->__debugInfo()['ucan'], 'and a group with none says so rather than redacting');
        self::assertStringContainsString('"ucan":null', json_encode($without, JSON_THROW_ON_ERROR));

        // What PHP does not let a class close: an (array) cast and var_export()
        // ignore __debugInfo, and serialize() writes what a caller asked to keep.
        // Asserted so the class doc's boundary is checked, not believed.
        self::assertStringContainsString($grant, print_r((array) $group, true), 'an (array) cast exposes it');
        self::assertStringContainsString($grant, var_export($group, true), 'var_export() exposes it');
        self::assertStringContainsString($grant, serialize($group), 'serialize() keeps it');
    }
}
