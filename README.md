# macula-php

[![CI](https://img.shields.io/github/actions/workflow/status/macula-io/macula-php/ci.yml?branch=main&label=CI)](https://github.com/macula-io/macula-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](#license)
[![PHP](https://img.shields.io/badge/php-8.1%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Go](https://img.shields.io/badge/go-1.26%2B-00ADD8?logo=go)](https://go.dev)
[![GitHub Sponsors](https://img.shields.io/badge/GitHub%20Sponsors-support-ea4aaa.svg?logo=githubsponsors&logoColor=white)](https://github.com/sponsors/rgfaber)

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/macula-php-full-dark.svg">
    <img src="assets/macula-php-full-light.svg" alt="Macula" width="320">
  </picture>
</p>

<p align="center">
  <strong>PHP SDK for the Macula mesh, via FFI over macula-go</strong>
</p>

---

> **Status, 2026-09-26:** on the **macula 12** wire (post-quantum: ML-DSA-87
> identities, ML-KEM hybrid key exchange, signed requests), over macula-go
> v0.12.0's pool. Calls and streams by direct dial, serving (under an org or in
> a node's own namespace), publish/subscribe, the DHT and node-served content
> are tested against in-process macula 12 stations on every `composer test`.
> UCAN-gated calls are not here yet; see [Not yet
> implemented](#not-yet-implemented). Releases before 0.5.0 speak the retired
> 10.x wire and cannot reach the current fleet.

## What is this?

A PHP SDK for the Macula mesh: a node's key, a pool of links to stations it
pins by node_id, calls and streams that reach a provider by direct dial,
serving procedures, publish/subscribe, node-served content and the DHT. It is
an FFI binding over [macula-go](https://github.com/macula-io/macula-go): the Go
SDK is compiled into one shared library, `libmacula.so`, which PHP loads with
`ext-ffi`, rather than a third implementation of QUIC, post-quantum TLS,
deterministic CBOR and signed frames.

## Quick start

```bash
composer require macula-io/macula-php
cd vendor/macula-io/macula-php && composer build   # compiles cabi/ into libmacula.so
```

A node needs a station to link to, **pinned by its node_id**, and the key of
each realm it trusts, which the realm publishes. Its own key is created on
first use and kept in a file readable by its owner only.

```php
use Macula\NodeKey;
use Macula\Pool;
use Macula\Seed;
use Macula\StreamData;
use Macula\StreamEnd;
use Macula\StreamMode;

$key = NodeKey::loadOrCreate('node.key');
$pool = Pool::connect($key, [new Seed('station-fi-helsinki.macula.io', 4433, $stationId)],
    realmTrust: [$realm => $realmKeyHex]);

// A call reaches a provider by direct dial: its advertisement from the DHT,
// trusted only when the realm key authorizes it, and its station dialed.
$answer = $pool->call($realm, 'mcl-echo/echo', 'hello');

// Publish and subscribe; topics name a kind of fact, ids go in the payload.
$sub = $pool->subscribe($realm, 'acme/demo/greeting_sent_v1');
$pool->publish($realm, 'acme/demo/greeting_sent_v1', ['text' => 'hi']);
foreach ($sub->events(idleMs: 2_000) as $event) {
    echo json_encode($event->payload), "\n";
}

// Streams: a server stream's chunks arrive until its end.
$stream = $pool->openStream($realm, 'mcl-tube/watch', StreamMode::Server);
foreach ($stream as $event) {
    if ($event instanceof StreamEnd) {
        break;
    }
}
$stream->free();

$pool->close();
```

Serving is a worker of its own, since a PHP process runs one thing at a time
(see [Serving](#serving)):

```php
$served = $pool->serve($realm, $pool->ownProcedure('ring'));   // ~<node_id>/ring: no org, no realm key
while (true) {
    $served->handle(fn (Macula\Request $r) => ['answered' => $r->caller], timeoutMs: 1_000);
}
```

Runnable versions are in [`examples/`](examples).

### Coming from 0.4 and earlier

Everything moved to the macula 12 wire, and the API with it. There is no
compatibility layer.

- **New identities.** A macula 12 node_id derives from an ML-DSA-87 key (or the
  LAMPS composite in `pq_hybrid`), so no Ed25519 identity carries over.
  `NodeKey::loadOrCreate($path)` makes a new key file. **Re-join your realms
  and re-trust your agents**: anything that named your old node_id must be
  redone with the new one.
- `KeyPair` is now `NodeKey`; `Session` is `Pool`, whose seeds carry the
  station's node_id and whose `realmTrust` pins realm keys; `callDirect` is
  simply `call`; `resolveDirect` is `providers`; `serveWaitForCall` is
  `Served::next` or `Served::handle`; `streamAccept` is `ServedStream::next`
  or `ServedStream::handle`; `putDirect`/`getDirect` are `shareContent`/
  `getContent`.
- `Value` is gone: a payload is a plain PHP value (see [Payloads](#payloads)).
- `Ucan` is gone until macula 12's UCANs are (see [Not yet
  implemented](#not-yet-implemented)).
- Serving an org procedure needs the realm's org directory and the org's
  delegation to your node in the DHT: a realm admits orgs through a human.

## What's implemented

| Primitive | Caller | Provider | Notes |
|---|---|---|---|
| Node keys (`NodeKey`) | ✅ | ✅ | `pq_hybrid` (the fleet's) or `pq_pure`; key files readable by the owner only |
| Pool of station links (`Pool::connect`) | ✅ | ✅ | Seeds pinned by node_id; realm keys pinned; links redialed with subscriptions and served procedures replayed |
| Calls by direct dial (`call`, `providers`) | ✅ | ✅ | Errors arrive as `ProviderError` / `RelayError` |
| A node's own namespace (`ownProcedure`) | ✅ | ✅ | `~<node_id>/<name>`: served and called with no org and no realm key; the node's signature authorizes it |
| Streams (`openStream`, `serveStream`) | ✅ | ✅ | Server, client and bidi; a QUIC stream per session, released on every path |
| Publish/subscribe | ✅ | ✅ | Signed publications, delivered once across links |
| DHT (`findRecord`, `findRecords`, `findRecordsByType`, `putRecord`) | ✅ | — | Records verified before they are handed on |
| Node-served content (`shareContent`, `unshareContent`, `getContent`) | ✅ | ✅ | Shared on the node's own `~<node_id>/content_v1` and announced; a fetch checks the block, the manifest and every chunk against the content id, bounded, with no realm key; `NotSharedError` / `ContentUnavailableError` |

## Serving

A served call waits in the library until PHP takes it. `Served::next()`
returns a `PendingCall` (its `request`, then `reply()` or `fail()` once), and
`Served::handle($handler)` does the whole round: it takes one call, answers it
with what the handler returns, or a `handler_error` with the message of what it
throws, and reports whether there was one. A call nobody answers by its
deadline is answered for it with an error. Streams work the same way:
`ServedStream::handle($handler)` runs the handler on one session, closes the
stream when the handler returns, aborts it with code `error` when it throws,
and frees it either way.

Because the call a PHP process makes blocks until it is answered, a process
cannot call a procedure it serves itself: run the provider as a worker of its
own, as [`examples/02_serve.php`](examples/02_serve.php) does.

## Payloads

A payload is what macula's wire CBOR carries: `null`, int, float, string,
list, map. **There is no boolean**: write 1 or 0; a PHP `true`/`false`
anywhere in a payload is refused with an `InvalidArgumentException` before it
reaches the wire. A list is a PHP list, a map an array with string keys; an
empty map is `new \stdClass()`, since `[]` is an empty list. A map comes back
in the wire's canonical key order.

Bytes have no JSON shape. Going in, give them as `Wire::bytes($raw)`; a plain
string is always text. Coming out, bytes are a `"0x"`-prefixed lowercase hex
string, or `['$bytes' => base64]` when a method is given
`bytes: BytesOutput::Tagged`.

Ids (realms, nodes, record keys) are taken as 64 hex characters or 32 raw
bytes, and come back as hex.

## Architecture

```
src/ (PHP API)  ──  src/Binding.php (ext-ffi)  ──  cabi/ (Go, libmacula.so)  ──  macula-go pool
```

`cabi/` exports C functions over macula-go's `pool` (and `stationlink`
streams). Every Go value crosses as a `runtime/cgo.Handle`; payloads cross as
JSON. Every call that does network I/O blocks the calling PHP thread. PHP's FFI
cannot take a callback on a Go thread, so what arrives on its own (a
subscription's events, a served procedure's calls, a streaming procedure's
sessions) waits in a bounded inbox in `cabi/inbox.go` until a `*_next`
function takes it, with a wait of its own.

## Not yet implemented

- **UCAN-gated calls and serving.** macula 12 uses post-quantum UCANs
  (macula-go#2). Calls carry no token yet, and a gated procedure cannot be
  served.

## Testing

```bash
composer install
composer build        # libmacula.so and build/teststation
composer test         # the offline suite
composer test:live    # one live station, see below
```

`composer test` runs `tests/PoolTest.php` against `cabi/cmd/teststation`, a
helper that runs two in-process macula 12 stations (macula-go's `teststation`)
sharing a DHT, with a test realm that admits the test's provider nodes. It
exercises keys, calls by direct dial and their errors, providers, server and
client streams and a provider that aborts one (and that no stream is left
unreleased), pubsub, node-served content and the DHT, through the real library.
A provider a test calls runs in a PHP process of its own
(`tests/fixtures/provider.php`). No network is needed. The suite needs PHP ≥
8.3 (PHPUnit 12's floor); the library itself runs on PHP ≥ 8.1.

`tests/live/FleetTest.php` runs against one real station and is not part of
`composer test`. It needs `MACULA_PHP_LIVE_SEED` (host:port),
`MACULA_PHP_LIVE_STATION_ID` (the station's node_id), `MACULA_PHP_LIVE_REALM`
and `MACULA_PHP_LIVE_REALM_KEY`; an unset one fails the run naming it. With a
key generated for the run and never saved, it reads the DHT, calls
`mcl-echo/echo` by direct dial and hears its own publication.

## Requirements

- PHP ≥ 8.1 with `ext-ffi`. `ext-ffi` is not always enabled in distro PHP
  builds: check `php -m | grep FFI`; PHP is built with it by `--with-ffi`.
- Go ≥ 1.26 and a C compiler (cgo), to build `cabi/`. `composer build` is the
  only Go command you run; you never write Go.
- Composer.

## Sibling SDKs

| Repo | Approach |
|---|---|
| [macula](https://github.com/macula-io/macula) | The reference SDK (Erlang/OTP) |
| [macula-go](https://github.com/macula-io/macula-go) | Go port, and what this SDK binds |
| [macula-ts](https://github.com/macula-io/macula-ts) | FFI binding over macula-go, for Node.js; this SDK's `cabi/` follows its pool model |
| [macula-rust](https://github.com/macula-io/macula-rust) | Native reimplementation (quinn, pure Rust) |
| [macula-station](https://github.com/macula-io/macula-station) | The station: DHT, SWIM, routing, peering |
| [macula-realm](https://github.com/macula-io/macula-realm) | Managed-realm identity + certificate authority |

## License

Licensed under the Apache License, Version 2.0 ([LICENSE](LICENSE) or
<http://www.apache.org/licenses/LICENSE-2.0>).

The PHP emblem in this README's header logo is the [official PHP
logo](https://www.php.net/download-logos.php), © Colin Viebrock,
licensed
[CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) — used
and redistributed here (as part of `assets/macula-php-full-{dark,light}.svg`)
under that license's own attribution and share-alike terms, distinct
from this repo's own Apache-2.0 license above.

---

<p align="center">
  <sub>Built with the BEAM's protocol, ported to PHP — <a href="https://github.com/sponsors/rgfaber">sponsor the work</a> if this saved you some time</sub>
</p>
