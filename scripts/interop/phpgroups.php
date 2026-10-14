<?php

declare(strict_types=1);

// macula-php's side of sealed groups across macula 13.2 and this SDK, for
// scripts/interop/groups.sh and erlang_groups.escript, through one station and
// macula's own distributor (macula_group_keys, in Erlang, run by the script).
//
//   php scripts/interop/phpgroups.php -station <host:port@node_id hex> -profile <pq_hybrid|pq_pure>
//       -realm <hex> -realm-key <hex> -work <dir> -prefix <p> -topic <t> member
//   php scripts/interop/phpgroups.php ... stranger
//   php scripts/interop/phpgroups.php ... impostor
//
// Every mode prints "node <hex>" first and waits for <work>/grant.<hex>, the
// org's grant the script minted for this node. member subscribes to the topic
// under the group prefix, pulling its epoch from macula's distributor, writes
// <work>/ready.php, waits for <work>/ready.erlang, publishes "from php" sealed
// under the group, and waits for the Erlang member's "from erlang" to arrive
// opened. stranger, a node the distributor's removed set names, checks its
// subscribe is refused not_a_member. impostor joins with its own grant and
// then, from a second node, presents that grant again: another node's token
// must be refused.
//
// This SDK seals and opens nothing of its own: every seal, open, and key pull
// happens inside libmacula, and PHP holds no epoch key and re-encodes no
// sealed payload. What PHP is party to is the payload it publishes and the
// reason a refusal is held as.

require __DIR__ . '/../../vendor/autoload.php';

use Macula\Group;
use Macula\GroupError;
use Macula\NodeKey;
use Macula\Pool;
use Macula\Profile;
use Macula\Seed;
use Macula\Subscription;

/** How many times a join is retried while the distributor is no_distributor:
 *  its advertisement reaches the DHT after it is served. 150 x 200 ms = 30 s. */
const JOIN_TRIES = 150;

/** How long the PHP member waits for the Erlang member's event. */
const HEARD_MS = 60_000;

$argvv = array_slice($argv, 1);
$mode = (string) array_pop($argvv);
$opts = [];
for ($i = 0; $i + 1 < count($argvv); $i += 2) {
    if (!str_starts_with($argvv[$i], '-')) {
        fail("{$argvv[$i]} is not an option");
    }
    $opts[substr($argvv[$i], 1)] = $argvv[$i + 1];
}

exit(run($mode, $opts));

/** @param array<string, string> $opts */
function run(string $mode, array $opts): int
{
    if (!in_array($mode, ['member', 'stranger', 'impostor'], true)) {
        fail("mode {$mode} is member, stranger or impostor");
    }
    $work = need($opts, 'work');
    $realm = need($opts, 'realm');
    $realmKey = need($opts, 'realm-key');
    $prefix = need($opts, 'prefix');
    $topic = need($opts, 'topic');
    $profileName = $opts['profile'] ?? 'pq_pure';
    $station = need($opts, 'station');

    [$host, $port, $nodeId] = seedOf($station);
    $profile = Profile::tryFrom($profileName);
    if ($profile === null) {
        fail("profile {$profileName} is pq_hybrid or pq_pure");
    }
    $seed = new Seed($host, $port, $nodeId);
    $trust = [$realm => $realmKey];

    $node = NodeKey::generate($profile);
    $pool = Pool::connect($node, [$seed], realmTrust: $trust);
    say('node ' . $node->nodeIdHex());

    try {
        return match ($mode) {
            'member' => member($pool, $work, $realm, $topic, $prefix),
            'stranger' => stranger($pool, $work, $realm, $topic, $prefix, $node->nodeIdHex()),
            'impostor' => impostor($pool, $seed, $trust, $work, $realm, $topic, $prefix, $profile),
        };
    } finally {
        $pool->close();
    }
}

/** Subscribe under the group, publish sealed, and open the Erlang member's
 *  event under the same epoch. */
function member(Pool $pool, string $work, string $realm, string $topic, string $prefix): int
{
    $grant = trim(waited("{$work}/grant." . $pool->nodeId()));
    $group = new Group($prefix, ucan: $grant);
    $sub = joinGroup($pool, $realm, $topic, $group, JOIN_TRIES) ?? fail('the PHP member was refused its group');
    say('subscribed');
    try {
        write("{$work}/ready.php");
        waited("{$work}/ready.erlang");
        $pool->publishGroup($realm, $topic, 'from php', $group, timeoutMs: 20_000);
        say('published');
        return heard($sub);
    } finally {
        $sub->stop();
    }
}

/** The distributor's removed set names this node, so its own grant is accepted
 *  and the handler refuses it. */
function stranger(Pool $pool, string $work, string $realm, string $topic, string $prefix, string $node): int
{
    $grant = trim(waited("{$work}/grant.{$node}"));
    $refused = refusal($pool, $realm, $topic, new Group($prefix, ucan: $grant), JOIN_TRIES);
    if ($refused === null) {
        fail('the removed node was not refused');
    }
    say("stranger subscribe: {$refused->reason}");
    return $refused->reason === GroupError::REASON_NOT_A_MEMBER
        ? 0
        : fail("the removed node was refused {$refused->reason}, not not_a_member");
}

/** The grant the org minted for this node is refused when another node
 *  presents it: macula's own gate refuses the caller, and the handler never
 *  runs, so the reason is no_distributor.
 *
 *  Two pools, and both of them deliberately:
 *
 *  - the first joins with its own grant, so a refusal that follows is the
 *    token's and not a distributor that is not reachable from this host;
 *  - the second is a fresh node. It cannot be the first pool presenting a
 *    token, because a group already held is not pulled again: the keyring
 *    would answer from what it holds and the impostor would sail through.
 *
 *  The second pool is not given the retry the first gets. That retry exists
 *  for one reason, a distributor whose advertisement has not reached the DHT
 *  yet, and it is indistinguishable from the refusal this mode is checking
 *  for. So reachability is established the way the offline suite establishes
 *  it, by waiting until the procedure is advertised, and the one subscribe
 *  that follows is the verdict.
 *
 *  The verdict is held to more than its reason, because no_distributor is the
 *  catch-all: a distributor that has gone away since the first pool's pull
 *  answers the same reason, so the message must also carry the gate's own
 *  code, `unauthorized`. */
function impostor(
    Pool $first,
    Seed $seed,
    array $trust,
    string $work,
    string $realm,
    string $topic,
    string $prefix,
    Profile $profile,
): int {
    $own = trim(waited("{$work}/grant." . $first->nodeId()));
    $joined = joinGroup($first, $realm, $topic, new Group($prefix, ucan: $own), JOIN_TRIES);
    if ($joined === null) {
        fail('the impostor check never reached the distributor: its own join was refused');
    }
    $joined->stop();
    say('the grant works for the node it was minted for');

    $second = NodeKey::generate($profile);
    $pool = Pool::connect($second, [$seed], realmTrust: $trust);
    say('impostor node ' . $second->nodeIdHex());
    try {
        $procedure = explode('/', $prefix)[1] . '/group_keys_v1';
        $deadline = microtime(true) + 30;
        while ($pool->providers($realm, $procedure) === []) {
            if (microtime(true) > $deadline) {
                fail("no {$procedure} is advertised");
            }
            usleep(200_000);
        }
        $refused = refusal($pool, $realm, $topic, new Group($prefix, ucan: $own), 1);
    } finally {
        $pool->close();
    }
    if ($refused === null) {
        fail("a grant minted for another node was accepted by {$second->nodeIdHex()}");
    }
    // The reason and the message both, so the log carries the gate's own code
    // and not only the catch-all reason it is held as.
    say("impostor subscribe: {$refused->reason}: {$refused->getMessage()}");
    if ($refused->reason !== GroupError::REASON_NO_DISTRIBUTOR) {
        fail("a grant minted for another node was refused {$refused->reason}, not no_distributor");
    }
    // no_distributor is the catch-all: a distributor that is unreachable, one
    // that has died, and a gate that refuses the caller all arrive as it, so
    // no_distributor alone would stay green in a run where the distributor
    // went away. The gate's own code in the message is what an outage cannot
    // give: macula refuses a caller its policy does not authorize with
    // `unauthorized` (macula_station_link), which crosses to here as the
    // provider's code.
    return str_contains($refused->getMessage(), 'unauthorized')
        ? 0
        : fail("the impostor was refused {$refused->reason} without the gate's code: {$refused->getMessage()}");
}

/** The event the Erlang member sealed, opened under the group. Its own publish
 *  comes back to it and is not the one waited for. */
function heard(Subscription $sub): int
{
    $deadline = microtime(true) + HEARD_MS / 1000;
    while (microtime(true) < $deadline) {
        $event = $sub->next(5_000);
        if ($event === null) {
            continue;
        }
        if ($event->unopened !== null) {
            return fail("an event arrived unopened: {$event->unopened}");
        }
        if (!$event->sealed) {
            return fail('a group subscription delivered an event not sealed under it');
        }
        if ($event->payload === 'from php') {
            continue;
        }
        if ($event->payload === 'from erlang') {
            say("opened from erlang under {$event->sealKeyId}");
            return 0;
        }
        return fail('unexpected event: ' . json_encode($event->payload, JSON_THROW_ON_ERROR));
    }
    return fail('nothing from erlang');
}

/** Subscribes under the group, waiting while the distributor's advertisement
 *  has not reached the DHT yet: that is the one reason a retry can change a
 *  join, and a join that never got that far is no_distributor. It answers the
 *  subscription, a thrown refusal that is not that one, or null when every
 *  attempt was no_distributor. */
function joinGroup(Pool $pool, string $realm, string $topic, Group $group, int $tries): ?Subscription
{
    for ($i = 0; $i < $tries; $i++) {
        try {
            return $pool->subscribeGroup($realm, $topic, $group, timeoutMs: 20_000);
        } catch (GroupError $e) {
            if ($e->reason !== GroupError::REASON_NO_DISTRIBUTOR) {
                throw $e;
            }
            usleep(200_000);
        }
    }
    return null;
}

/** What a subscribe was refused with, or null when it was not refused: any
 *  other failure is this run's, not the distributor's verdict.
 *
 *  The retry above is worth having only while no_distributor is NOT what is
 *  being checked for. Where it is, a single attempt is asked for and its
 *  answer is the verdict, which is why the last refusal is returned rather
 *  than the exhausted attempts read as an acceptance. */
function refusal(Pool $pool, string $realm, string $topic, Group $group, int $tries): ?GroupError
{
    $last = null;
    for ($i = 0; $i < $tries; $i++) {
        try {
            $pool->subscribeGroup($realm, $topic, $group, timeoutMs: 20_000)->stop();
            return null;
        } catch (GroupError $e) {
            $last = $e;
            if ($e->reason !== GroupError::REASON_NO_DISTRIBUTOR) {
                return $e;
            }
            usleep(200_000);
        }
    }
    return $last;
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

function fail(string $why): never
{
    fwrite(STDERR, "phpgroups: {$why}\n");
    exit(1);
}

/** @param array<string, string> $opts */
function need(array $opts, string $key): string
{
    return $opts[$key] ?? fail("-{$key} is required");
}

/** A station given as host:port@<node_id hex>. @return array{string, int, string} */
function seedOf(string $station): array
{
    if (!str_contains($station, '@') || !str_contains($station, ':')) {
        fail("-station is host:port@<node_id hex>");
    }
    [$host, $rest] = explode(':', $station, 2);
    [$port, $nodeId] = explode('@', $rest, 2);
    if (!ctype_xdigit($nodeId) || strlen($nodeId) !== 64 || !ctype_digit($port)) {
        fail("-station is host:port@<node_id hex>");
    }
    return [$host, (int) $port, $nodeId];
}

/** A file the orchestrating script writes, read once it is there. */
function waited(string $path): string
{
    $deadline = microtime(true) + 180;
    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
        usleep(200_000);
    }
    fail("no {$path}");
}

function write(string $path): void
{
    if (file_put_contents($path, '') === false) {
        fail("cannot write {$path}");
    }
}
