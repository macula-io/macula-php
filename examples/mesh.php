<?php

declare(strict_types=1);

// What every example joins the mesh with, from the environment:
//   MACULA_SEED       the station, host:port ([v6]:port for IPv6)
//   MACULA_STATION_ID its node_id, 64 hex: the station must prove it
//   MACULA_REALM      the realm id, 64 hex
//   MACULA_REALM_KEY  the realm's key as carried, hex (the realm publishes it)
//   MACULA_KEY        this node's key file, created on first use (node.key)

require __DIR__ . '/../vendor/autoload.php';

use Macula\NodeKey;
use Macula\Pool;
use Macula\Seed;

function env(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "set {$name} (see examples/README.md)\n");
        exit(2);
    }
    return $value;
}

function realm(): string
{
    return env('MACULA_REALM');
}

function connect(?string $keyFile = null): Pool
{
    if (preg_match('/^\[?([^\]]+?)\]?:(\d+)$/', env('MACULA_SEED'), $m) !== 1) {
        fwrite(STDERR, "MACULA_SEED must be host:port\n");
        exit(2);
    }
    $key = NodeKey::loadOrCreate($keyFile ?? (getenv('MACULA_KEY') ?: 'node.key'));
    return Pool::connect($key, [new Seed($m[1], (int) $m[2], env('MACULA_STATION_ID'))],
        realmTrust: [realm() => env('MACULA_REALM_KEY')]);
}
