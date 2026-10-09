<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\BytesOutput;
use Macula\Confidentiality;
use Macula\ConfidentialityError;
use Macula\ContentUnavailableError;
use Macula\NodeKey;
use Macula\NotSharedError;
use Macula\Pool;
use Macula\Profile;
use Macula\ProviderError;
use Macula\Ucan;
use Macula\RecordType;
use Macula\StreamData;
use Macula\StreamEnd;
use Macula\StreamError;
use Macula\StreamMode;
use Macula\StreamReply;
use Macula\Wire;
use PHPUnit\Framework\TestCase;

/**
 * The PHP API over libmacula.so, against two in-process macula 12 stations
 * (TestStations): keys, calls by direct dial and their errors, providers,
 * streams (and that every stream is released), pubsub, content and the DHT.
 * A provider a test calls runs in a process of its own (ProviderProcess).
 */
final class PoolTest extends TestCase
{
    private static TestStations $env;

    public static function setUpBeforeClass(): void
    {
        self::$env = TestStations::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$env->stop();
    }

    private function node(int $station, bool $trusted = true): Pool
    {
        return Pool::connect(NodeKey::generate(Profile::PqPure), [self::$env->seed($station)],
            realmTrust: $trusted ? self::$env->trust() : []);
    }

    private function eventually(string $what, callable $ok): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if ($ok()) {
                return;
            }
            usleep(25_000);
        }
        self::fail("never: {$what}");
    }

    public function testAKeyIsSavedReadableByItsOwnerOnlyAndLoadsBackAsTheSameNode(): void
    {
        $dir = sys_get_temp_dir() . '/macula-php-key-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $path = "{$dir}/node.key";
        try {
            $created = NodeKey::loadOrCreate($path, Profile::PqPure);
            self::assertSame(0600, fileperms($path) & 0777);
            $loaded = NodeKey::load($path, Profile::PqPure);
            self::assertSame($created->nodeIdHex(), $loaded->nodeIdHex());
            self::assertSame(Profile::PqPure, $loaded->profile());
            self::assertNotSame('', $loaded->sign("\x01\x02\x03"));
            self::assertSame(32, strlen($loaded->nodeId()));
            $this->expectException(\Macula\MaculaException::class);
            NodeKey::load($path, Profile::PqHybrid);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testACallReachesAProviderOnAnotherStationAndBringsItsErrorBack(): void
    {
        $provider = ProviderProcess::start(self::$env, 'echo', 0, admitted: true);
        $caller = $this->node(1);
        try {
            $result = $caller->call(self::$env->realmId, $provider->procedure, 'hello');
            self::assertEquals(['echo' => 'hello', 'caller' => $caller->nodeId()], $result);
            try {
                $caller->call(self::$env->realmId, $provider->procedure, 'fail');
                self::fail('a failing handler answered');
            } catch (ProviderError $e) {
                self::assertSame('handler_error', $e->errorCode);
                self::assertSame('refused by the handler', $e->detail);
            }
            $nodes = array_map(fn ($p) => $p->node, $caller->providers(self::$env->realmId, $provider->procedure));
            self::assertContains($provider->nodeId, $nodes);
        } finally {
            $caller->close();
            $provider->stop();
        }
    }

    public function testARequiredCallIsSealedToTheKeyTheProviderAdvertises(): void
    {
        $provider = ProviderProcess::start(self::$env, 'sealed', 0, admitted: true);
        $pool = $this->node(1);
        try {
            $result = null;
            $this->eventually('the sealed provider answers', function () use ($pool, $provider, &$result): bool {
                try {
                    $result = $pool->call(self::$env->realmId, $provider->procedure, 'secret',
                        confidential: Confidentiality::Required);
                    return true;
                } catch (\Macula\MaculaException $e) {
                    return false;
                }
            });
            self::assertEquals(['echo' => 'secret', 'sealed' => 1], $result);
        } finally {
            $pool->close();
            $provider->stop();
        }
    }

    public function testARequiredCallToAProviderThatNamesNoKeyIsAConfidentialityError(): void
    {
        $provider = ProviderProcess::start(self::$env, 'echo', 0, admitted: true);
        $pool = $this->node(1);
        try {
            $this->eventually('the provider is advertised', fn (): bool =>
                $pool->providers(self::$env->realmId, $provider->procedure) !== []);
            try {
                $pool->call(self::$env->realmId, $provider->procedure, 'secret', confidential: Confidentiality::Required);
                self::fail('a required call went in the clear');
            } catch (ConfidentialityError $e) {
                self::assertSame('confidentiality', $e->kind);
                self::assertNotSame('', $e->reason);
            }
        } finally {
            $pool->close();
            $provider->stop();
        }
    }

    public function testAGatedProcedureServesOnlyACallerItsRootGranted(): void
    {
        $root = NodeKey::generate(Profile::PqPure);
        $alice = NodeKey::generate(Profile::PqPure);
        $provider = ProviderProcess::start(self::$env, 'gated', 0, admitted: true, issuer: $root->nodeIdHex());
        $pool = $this->node(1);
        $realm = self::$env->realmId;
        $caps = [['with' => 'mri:org:' . self::$env->realmName . '/' . self::$env->org, 'can' => 'invoke']];
        $exp = time() + 300;
        try {
            $this->eventually('the gated provider is advertised', fn (): bool =>
                $pool->providers($realm, $provider->procedure) !== []);
            $refused = [
                'no token' => [null, []],
                'a token for another node' => [$root->ucan($alice->nodeIdHex(), $caps, $exp), []],
                'a token from another root' => [$alice->ucan($pool->nodeId(), $caps, $exp), []],
            ];
            foreach ($refused as $name => [$token, $proofs]) {
                try {
                    $pool->call($realm, $provider->procedure, ucan: $token, proofs: $proofs);
                    self::fail("{$name}: served");
                } catch (ProviderError $e) {
                    self::assertSame('unauthorized', $e->errorCode, $name);
                }
            }
            $granted = $root->ucan($pool->nodeId(), $caps, $exp);
            self::assertSame(['served' => $pool->nodeId()],
                $pool->call($realm, $provider->procedure, ucan: $granted));
            $toAlice = $root->ucan($alice->nodeIdHex(), $caps, $exp);
            $delegated = $alice->ucan($pool->nodeId(), $caps, $exp, prf: [Ucan::proofId($toAlice)]);
            self::assertSame(['served' => $pool->nodeId()],
                $pool->call($realm, $provider->procedure, ucan: $delegated, proofs: [$toAlice]));
        } finally {
            $pool->close();
            $provider->stop();
        }
    }

    public function testAnOverlongIssuerDidKeyIsRefusedAtOnce(): void
    {
        $root = NodeKey::generate(Profile::PqPure);
        $provider = ProviderProcess::start(self::$env, 'gated', 0, admitted: true, issuer: $root->nodeIdHex());
        $pool = $this->node(1);
        $realm = self::$env->realmId;
        $caps = [['with' => 'mri:org:' . self::$env->realmName . '/' . self::$env->org, 'can' => 'invoke']];
        try {
            $this->eventually('the gated provider is advertised', fn (): bool =>
                $pool->providers($realm, $provider->procedure) !== []);
            $granted = $root->ucan($pool->nodeId(), $caps, time() + 300);
            self::assertSame(['served' => $pool->nodeId()],
                $pool->call($realm, $provider->procedure, ucan: $granted));

            // Base58 decodes in time quadratic in its length, ahead of the
            // signature check: 300,000 characters outlast a 5 s call unbounded.
            [$header, $claims, $signature] = explode('.', $granted);
            $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
            $decoded['iss'] = 'did:key:z' . str_repeat('2', 300_000);
            $forged = $header . '.' . rtrim(strtr(base64_encode(json_encode($decoded, JSON_THROW_ON_ERROR)), '+/', '-_'), '=')
                . '.' . $signature;
            $started = hrtime(true);
            try {
                $pool->call($realm, $provider->procedure, ucan: $forged, timeoutMs: 5000);
                self::fail('an over-long issuer was served');
            } catch (ProviderError $e) {
                self::assertSame('unauthorized', $e->errorCode);
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            self::assertLessThan(5.0, $elapsed, "refused after {$elapsed} s");
        } finally {
            $pool->close();
            $provider->stop();
        }
    }

    public function testACallReportSaysWhetherTheExchangeWasSealedAndToWhichKey(): void
    {
        $sealed = ProviderProcess::start(self::$env, 'sealed', 0, admitted: true);
        $clear = ProviderProcess::start(self::$env, 'echo', 0, admitted: true);
        $pool = $this->node(1);
        $realm = self::$env->realmId;
        try {
            $reported = null;
            $this->eventually('the sealed provider answers', function () use ($pool, $realm, $sealed, &$reported): bool {
                try {
                    $reported = $pool->callReport($realm, $sealed->procedure, 'secret',
                        confidential: Confidentiality::Required);
                    return true;
                } catch (\Macula\MaculaException) {
                    return false;
                }
            });
            self::assertEquals(['echo' => 'secret', 'sealed' => 1], $reported->result);
            self::assertTrue($reported->report->sealed);
            self::assertSame($sealed->nodeId, $reported->report->provider);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $reported->report->sealKeyId);

            $plain = $pool->callReport($realm, $clear->procedure, 'hello');
            self::assertEquals(['echo' => 'hello', 'caller' => $pool->nodeId()], $plain->result);
            self::assertFalse($plain->report->sealed);
            self::assertSame($clear->nodeId, $plain->report->provider);
            self::assertNull($plain->report->sealKeyId);
        } finally {
            $pool->close();
            $sealed->stop();
            $clear->stop();
        }
    }

    public function testACallIsRefusedForAnUnpinnedRealmAndFindsNoProviderForWhatNobodyServes(): void
    {
        $caller = $this->node(0);
        try {
            try {
                $caller->call(str_repeat('11', 32), self::$env->org . '/echo');
                self::fail('a call in an unpinned realm went out');
            } catch (\Macula\MaculaException $e) {
                self::assertMatchesRegularExpression('/realm key/', $e->getMessage());
            }
            $this->expectExceptionMessageMatches('/no trusted provider/');
            $caller->call(self::$env->realmId, self::$env->org . '/nothing');
        } finally {
            $caller->close();
        }
    }

    public function testABooleanPayloadIsRefusedBeforeItReachesTheWire(): void
    {
        $caller = $this->node(0);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $caller->call(self::$env->realmId, self::$env->org . '/echo', ['ok' => true]);
        } finally {
            $caller->close();
        }
    }

    public function testAnEmptyUcanIsRefusedBeforeItReachesTheWire(): void
    {
        $caller = $this->node(0);
        try {
            $this->expectExceptionMessageMatches('/never empty/');
            $caller->call(self::$env->realmId, self::$env->org . '/echo', ucan: '');
        } finally {
            $caller->close();
        }
    }

    public function testARealmMemberPolicyTakesItsKeyIdAsAnyIdIsTaken(): void
    {
        $id = str_repeat('ab', 32);
        self::assertSame($id, \Macula\ServePolicy::realmMemberRequired(strtoupper($id), 'call')->toArray()['key_id']);
        self::assertSame($id, \Macula\ServePolicy::realmMemberRequired(hex2bin($id), 'call')->toArray()['key_id']);
        $this->expectException(\InvalidArgumentException::class);
        \Macula\ServePolicy::realmMemberRequired('abcd', 'call');
    }

    public function testANodesOwnNamespaceIsServedAndCalledWithNoRealmKeyPinned(): void
    {
        $provider = ProviderProcess::start(self::$env, 'ring', 0, admitted: false, trusted: false);
        $caller = $this->node(1, trusted: false);
        try {
            self::assertSame("~{$provider->nodeId}/ring", $provider->procedure);
            self::assertSame(['rung_by' => $caller->nodeId()], $caller->call(self::$env->realmId, $provider->procedure));
            $nodes = array_map(fn ($p) => $p->node, $caller->providers(self::$env->realmId, $provider->procedure));
            self::assertSame([$provider->nodeId], $nodes);
        } finally {
            $caller->close();
            $provider->stop();
        }
    }

    public function testServingInAnotherNodesNamespaceIsRefused(): void
    {
        $node = $this->node(0, trusted: false);
        try {
            $this->expectExceptionMessageMatches('/own namespace/');
            $node->serve(self::$env->realmId, '~' . str_repeat('01', 32) . '/ring');
        } finally {
            $node->close();
        }
    }

    public function testAServedProcedureNobodyCallsHandsOverNothingAndEndsWhenStopped(): void
    {
        $node = $this->node(0, trusted: false);
        try {
            $served = $node->serve(self::$env->realmId, $node->ownProcedure('idle'));
            self::assertNull($served->next(50));
            self::assertFalse($served->handle(fn () => 1, 50));
            $served->stop();
            self::assertNull($served->next(50));
            self::assertTrue($served->ended());
        } finally {
            $node->close();
        }
    }

    public function testAServerStreamDeliversItsChunksAndEndsAndLeavesNothingRelayed(): void
    {
        $provider = ProviderProcess::start(self::$env, 'watch', 0, admitted: true);
        $caller = $this->node(1);
        try {
            $stream = $caller->openStream(self::$env->realmId, $provider->procedure, StreamMode::Server);
            $got = [];
            foreach ($stream as $event) {
                if ($event instanceof StreamData) {
                    $got[] = hex2bin(substr($event->body, 2));
                }
                if ($event instanceof StreamEnd) {
                    break;
                }
            }
            self::assertSame(['one', 'two', 'three'], $got);
            $report = $stream->report();
            self::assertFalse($report->sealed, 'a clear stream settles clear on its first data');
            self::assertSame($provider->nodeId, $report->provider);
            self::assertNull($report->sealKeyId);
            $stream->free();
            $this->eventually('every stream released', fn () => self::$env->relayed() === 0);
        } finally {
            $caller->close();
            $provider->stop();
        }
    }

    public function testAClientStreamIsAnsweredWithTheProvidersReply(): void
    {
        $provider = ProviderProcess::start(self::$env, 'count', 0, admitted: true);
        $caller = $this->node(0);
        try {
            $stream = $caller->openStream(self::$env->realmId, $provider->procedure, StreamMode::Client);
            foreach (['ab', 'cde', 'f'] as $chunk) {
                $stream->send($chunk);
            }
            $stream->closeSend();
            self::assertEquals(new StreamReply(6), $stream->recv(5_000));
            $stream->free();
            $this->eventually('every stream released', fn () => self::$env->relayed() === 0);
        } finally {
            $caller->close();
            $provider->stop();
        }
    }

    public function testAProviderHandlerThatThrowsEndsTheStreamWithAStreamError(): void
    {
        $provider = ProviderProcess::start(self::$env, 'refuse', 0, admitted: true);
        $caller = $this->node(1);
        try {
            $stream = $caller->openStream(self::$env->realmId, $provider->procedure, StreamMode::Server);
            try {
                $stream->recv(5_000);
                self::fail('a refused stream handed over a frame');
            } catch (StreamError $e) {
                self::assertSame('error', $e->errorCode);
                self::assertSame('no watching today', $e->detail);
                self::assertFalse($e->relay);
            }
            $stream->free();
            $this->eventually('every stream released', fn () => self::$env->relayed() === 0);
        } finally {
            $caller->close();
            $provider->stop();
        }
    }

    public function testAPublicationIsHeardOnceBySubscriberOnTheSameStation(): void
    {
        $listener = $this->node(0);
        $publisher = $this->node(0);
        try {
            $topic = 'mcl-php/tests/greeting_sent_v1';
            $sub = $listener->subscribe(self::$env->realmId, $topic);
            usleep(200_000);
            $publisher->publish(self::$env->realmId, $topic, 'hi');
            $event = $sub->next(5_000);
            self::assertNotNull($event);
            self::assertSame('hi', $event->payload);
            self::assertSame($publisher->nodeId(), $event->publisher);
            self::assertSame($topic, $event->topic);
            self::assertNull($sub->next(300));
            self::assertFalse($sub->ended());
            $sub->stop();
            self::assertNull($sub->next(50));
            self::assertTrue($sub->ended());
        } finally {
            $listener->close();
            $publisher->close();
        }
    }

    public function testBytesGoInTaggedAndComeOutAsHexOrTaggedAsAsked(): void
    {
        $listener = $this->node(0);
        $publisher = $this->node(0);
        try {
            $topic = 'mcl-php/tests/bytes_sent_v1';
            $hex = $listener->subscribe(self::$env->realmId, $topic);
            $tagged = $listener->subscribe(self::$env->realmId, $topic, BytesOutput::Tagged);
            usleep(200_000);
            $publisher->publish(self::$env->realmId, $topic, ['raw' => Wire::bytes("\x01\x02\x03"), 'text' => 'AQID']);
            self::assertEquals(['raw' => '0x010203', 'text' => 'AQID'], $hex->next(5_000)?->payload);
            self::assertEquals(['raw' => ['$bytes' => 'AQID'], 'text' => 'AQID'], $tagged->next(5_000)?->payload);
        } finally {
            $listener->close();
            $publisher->close();
        }
    }

    public function testContentIsSharedByOneNodeAndFetchedByAnotherUntilItIsUnshared(): void
    {
        $sharer = $this->node(0, trusted: false);
        $fetcher = $this->node(1, trusted: false);
        try {
            foreach ([10_000, 600_000] as $size) {
                $data = self::pattern($size);
                $mcid = $sharer->shareContent(self::$env->realmId, $data, 'blob.bin');
                self::assertMatchesRegularExpression('/^02(55|56)[0-9a-f]{96}$/', $mcid);
                self::assertSame($data, $fetcher->getContent(self::$env->realmId, $mcid));
                $sharer->unshareContent(self::$env->realmId, $mcid);
                try {
                    $fetcher->getContent(self::$env->realmId, $mcid);
                    self::fail('unshared content was fetched');
                } catch (NotSharedError) {
                }
            }
        } finally {
            $sharer->close();
            $fetcher->close();
        }
    }

    public function testContentOverTheBoundsAndAContentIdThatIsNotOneAreRefused(): void
    {
        $sharer = $this->node(0, trusted: false);
        $fetcher = $this->node(1, trusted: false);
        try {
            $mcid = $sharer->shareContent(self::$env->realmId, self::pattern(600_000), 'big.bin');
            try {
                $fetcher->getContent(self::$env->realmId, $mcid, maxBytes: 500_000);
                self::fail('content over the bounds was fetched');
            } catch (ContentUnavailableError $e) {
                self::assertMatchesRegularExpression('/over the bounds/', $e->getMessage());
            }
            $this->expectExceptionMessageMatches('/content id/');
            $fetcher->getContent(self::$env->realmId, '02' . str_repeat('55', 10));
        } finally {
            $sharer->close();
            $fetcher->close();
        }
    }

    public function testTheStationsOwnEndpointRecordsAreFoundVerified(): void
    {
        $node = $this->node(0);
        try {
            $found = $node->findRecordsByType(RecordType::StationEndpoint);
            self::assertSame(0, $found->dropped);
            $keyIds = array_map(fn ($r) => $r->keyId, $found->records);
            sort($keyIds);
            $stations = array_map(fn ($s) => $s['node_id'], self::$env->stations);
            sort($stations);
            self::assertSame($stations, $keyIds);
            self::assertNull($node->findRecord(str_repeat('22', 32)));
        } finally {
            $node->close();
        }
    }

    private static function pattern(int $n): string
    {
        $out = '';
        for ($i = 0; $i < $n; $i++) {
            $out .= chr($i % 251);
        }
        return $out;
    }
}
