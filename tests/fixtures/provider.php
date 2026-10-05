<?php

declare(strict_types=1);

// A provider in a process of its own, for the tests: PHP runs one thing at a
// time, so a call from the test blocks until its answer, and the answer has to
// come from another process. argv[1] is JSON {seed: {host, port, node_id},
// realm_id, realm_key (null for none), org, kind, admitted}. It prints its
// node_id; when admitted it waits for a line on stdin (the test admits it
// meanwhile); then it connects, serves the procedure `kind` names, prints that
// procedure, and answers until stdin closes.

require __DIR__ . '/../../vendor/autoload.php';

use Macula\Confidentiality;
use Macula\NodeKey;
use Macula\Pool;
use Macula\Profile;
use Macula\Request;
use Macula\Seed;
use Macula\Stream;
use Macula\StreamData;
use Macula\StreamEnd;
use Macula\StreamMode;

$config = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$key = NodeKey::generate(Profile::PqPure);
echo $key->nodeIdHex(), "\n";
if ($config['admitted']) {
    fgets(STDIN);
}
$seed = new Seed($config['seed']['host'], $config['seed']['port'], $config['seed']['node_id']);
$realm = $config['realm_id'];
$pool = Pool::connect($key, [$seed], realmTrust: $config['realm_key'] === null ? [] : [$realm => $config['realm_key']],
    kemAdvertise: $config['kind'] === 'sealed');
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
    'sealed' => [
        "{$org}/sealed",
        $pool->serve($realm, "{$org}/sealed", confidential: Confidentiality::Required),
        fn (Request $r): mixed => ['sealed' => $r->sealed ? 1 : 0, 'echo' => $r->payload],
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
