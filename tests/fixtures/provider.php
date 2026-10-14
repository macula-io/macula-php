<?php

declare(strict_types=1);

// A provider in a process of its own, for the tests: PHP runs one thing at a
// time, so a call from the test blocks until its answer, and the answer has to
// come from another process. argv[1] is JSON {seed: {host, port, node_id},
// realm_id, realm_key (null for none), org, kind, admitted, issuer (the
// UCAN root a gated kind requires), org_key_id, prefix, epoch, calls_file,
// refuse_file (all of them the `groupdist' kind's)}. It prints its
// node_id; when admitted it waits for a line on stdin (the test admits it
// meanwhile); then it connects, serves the procedure `kind` names, prints that
// procedure, and answers until stdin closes.
//
// `groupdist' is a FIXTURE, not the SDK sealing anything: a stand-in for the
// org's distributor, serving <org>/group_keys_v1 with the vector's
// realm_member_required policy, sealed, answering every pull with the one
// epoch `epoch' names. It holds no crypto of its own: the epoch key is a
// value the test hands it and it hands back, and every seal and open of a
// group event happens inside libmacula. It appends each CALL it is asked to
// the file `calls_file' names, so the test can see what crossed the wire, and
// refuses with the reason `refuse_file' holds when that file is there, so one
// distributor can be asked for every refusal in turn.

require __DIR__ . '/../../vendor/autoload.php';

use Macula\Confidentiality;
use Macula\NodeKey;
use Macula\Pool;
use Macula\Profile;
use Macula\Request;
use Macula\Seed;
use Macula\ServePolicy;
use Macula\Stream;
use Macula\StreamData;
use Macula\StreamEnd;
use Macula\StreamMode;
use Macula\Wire;

$config = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$key = NodeKey::generate(Profile::PqPure);
echo $key->nodeIdHex(), "\n";
if ($config['admitted']) {
    fgets(STDIN);
}
$seed = new Seed($config['seed']['host'], $config['seed']['port'], $config['seed']['node_id']);
$realm = $config['realm_id'];
$pool = Pool::connect($key, [$seed], realmTrust: $config['realm_key'] === null ? [] : [$realm => $config['realm_key']],
    kemAdvertise: in_array($config['kind'], ['sealed', 'groupdist'], true));
$org = $config['org'];

[$procedure, $served, $handler] = match ($config['kind']) {
    'echo' => [
        "{$org}/echo",
        $pool->serve($realm, "{$org}/echo"),
        function (Request $r): mixed {
            if ($r->payload === 'fail') {
                throw new RuntimeException('refused by the handler');
            }
            return ['echo' => $r->payload, 'caller' => $r->caller];
        },
    ],
    'gated' => [
        "{$org}/gated",
        $pool->serve($realm, "{$org}/gated", policy: ServePolicy::ucanRequired($config['issuer'])),
        fn (Request $r): mixed => ['served' => $r->caller],
    ],
    'sealed' => [
        "{$org}/sealed",
        $pool->serve($realm, "{$org}/sealed", confidential: Confidentiality::Required),
        fn (Request $r): mixed => ['sealed' => $r->sealed ? 1 : 0, 'echo' => $r->payload],
    ],
    // The org's distributor, stood in for (see the header): gated on the
    // org's grant carrying can "group_keys", sealed, and answering every
    // pull with the epoch the test handed it.
    'groupdist' => [
        "{$org}/group_keys_v1",
        $pool->serve($realm, "{$org}/group_keys_v1", confidential: Confidentiality::Required,
            policy: ServePolicy::realmMemberRequired($config['org_key_id'], 'group_keys')),
        function (Request $r) use ($config): mixed {
            file_put_contents($config['calls_file'], json_encode($r->payload, JSON_THROW_ON_ERROR) . "\n",
                FILE_APPEND);
            $refusal = is_file($config['refuse_file']) ? trim((string) file_get_contents($config['refuse_file'])) : '';
            if ($refusal !== '') {
                // How macula_group_keys refuses: a handler_error whose detail
                // is the reason, which the keyring holds as that reason.
                throw new RuntimeException($refusal);
            }
            // The reply the vectors pin: an epoch's id and key are BYTE
            // strings (8 and 32), not their hex, which is what the keyring
            // reads.
            $e = $config['epoch'];
            return ['prefix' => $config['prefix'], 'policy' => 'required', 'epochs' => [[
                'id' => Wire::bytes((string) hex2bin($e['id'])),
                'key' => Wire::bytes((string) hex2bin($e['key'])),
                'issued_at' => $e['issued_at'],
                'publish_until' => $e['publish_until'],
                'accept_until' => $e['accept_until'],
            ]]];
        },
    ],
    'ring' => [
        $pool->ownProcedure('ring'),
        $pool->serve($realm, $pool->ownProcedure('ring')),
        fn (Request $r): mixed => ['rung_by' => $r->caller],
    ],
    'watch' => [
        "{$org}/watch",
        $pool->serveStream($realm, "{$org}/watch", StreamMode::Server),
        function (Stream $s): void {
            foreach (['one', 'two', 'three'] as $chunk) {
                $s->send($chunk);
            }
        },
    ],
    'refuse' => [
        "{$org}/refuse",
        $pool->serveStream($realm, "{$org}/refuse", StreamMode::Server),
        function (Stream $s): void {
            throw new RuntimeException('no watching today');
        },
    ],
    'count' => [
        "{$org}/count",
        $pool->serveStream($realm, "{$org}/count", StreamMode::Client),
        function (Stream $s): void {
            $total = 0;
            foreach ($s as $event) {
                if ($event instanceof StreamData) {
                    $total += strlen($event->body) / 2 - 1;
                }
                if ($event instanceof StreamEnd) {
                    break;
                }
            }
            $s->reply($total);
        },
    ],
};
echo $procedure, "\n";

stream_set_blocking(STDIN, false);
while (true) {
    $served->handle($handler, 100);
    if (fgets(STDIN) === false && feof(STDIN)) {
        break;
    }
}
$served->stop();
$pool->close();
