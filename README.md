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

> **Status, 2026-10-05:** over macula-go's **released** libmacula (the tag in
> `abi/MACULA_GO_REF`, ABI 1), so on the macula 13 wire: handshake v5 bound to
> the TLS session, end-to-end sealed calls and streams to a provider that
> advertises a KEM key (`Confidentiality`), post-quantum identities (ML-DSA-87,
> as the ML-DSA-87 + RSA-PSS-4096 composite in pq_hybrid, the fleet's profile)
> and ML-KEM hybrid key exchange. Calls and streams by direct dial, serving
> (under an org or in a node's own namespace), publish/subscribe, the DHT and
> node-served content are tested against in-process stations on every
> `composer test`, as are UCAN-gated calls and serving and the seal report.
> Releases before 0.7.0 bind macula-go v0.12.0 and cannot call a provider
> that advertises a KEM key.

## What is this?

A PHP SDK for the Macula mesh: a node's key, a pool of links to stations it
pins by node_id, calls and streams that reach a provider by direct dial,
serving procedures, publish/subscribe, node-served content and the DHT. It is
an FFI binding over [macula-go](https://github.com/macula-io/macula-go): the Go
SDK's released shared library, libmacula, which PHP loads with `ext-ffi`,
rather than a third implementation of QUIC, TLS 1.3 with a hybrid
post-quantum key exchange, deterministic CBOR and signed frames.

## Quick start

```bash
composer require macula-io/macula-php
cd vendor/macula-io/macula-php && composer build   # fetches and checks libmacula
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
| Node keys (`NodeKey`) | ✅ | ✅ | `pq_hybrid` (the fleet's) or `pq_pure`; key files readable by the owner only; `sign` and `verify`, pq_hybrid checked against the LAMPS draft's own vector and cross-verified with macula 12.7.0 |
| Pool of station links (`Pool::connect`) | ✅ | ✅ | Seeds pinned by node_id; realm keys pinned; links redialed with subscriptions and served procedures replayed |
| Calls by direct dial (`call`, `providers`) | ✅ | ✅ | Errors arrive as `ProviderError` / `RelayError`; every other failure is a `MaculaException` whose `kind` is the library's error kind |
| UCANs (`NodeKey::ucan`, `Ucan::proofId`, `ucan:`/`proofs:`, `ServePolicy`) | ✅ | ✅ | A token minted for the node that presents it, delegated by `prf`; a procedure served under `ServePolicy::ucanRequired` or `realmMemberRequired` reaches its handler only with a chain the policy accepts, refused otherwise as `ProviderError` `unauthorized` |
| The seal report (`callReport`, `Stream::report`) | ✅ | — | Whether the exchange behind a result was sealed, to which provider and key |
| End-to-end sealing (`confidential:`, `kemAdvertise:`) | ✅ | ✅ | A call or stream is sealed whenever the provider's advertisement names a KEM key; `Confidentiality::Required` fails with `ConfidentialityError` rather than go in the clear; a served `Request` says whether it came `sealed` |
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
src/ (PHP API)  ──  src/Binding.php (ext-ffi)  ──  libmacula (macula-go's C ABI)  ──  macula-go pool
```

libmacula is macula-go's C ABI (`abi/macula.h`, its contract in macula-go's
`cabi/CONTRACT.md`), the same library macula-py, Macula .NET and macula-ts
load. `composer build` downloads it for the tag in `abi/MACULA_GO_REF`, checks
it against the release's `SHA256SUMS` and build attestation, and refuses a
release whose `macula.h` is not `abi/macula.h`; `Binding` refuses a library of
another ABI version. Payloads cross as JSON. Every call that does network I/O
blocks the calling PHP thread. What arrives on its own (a subscription's
events, a served procedure's calls, a streaming procedure's sessions) waits in
a bounded inbox in the library until a `*_next` function takes it.

## Not yet implemented

Nothing that libmacula offers a PHP node is missing today.

## Testing

```bash
composer install
composer build        # libmacula into build/native, and build/teststation
composer test         # the offline suite
composer test:live    # one live station, see below
```

`composer test` runs `tests/PoolTest.php` against macula-go's teststation at
the same tag, which runs two in-process stations sharing a DHT, with a test realm that admits the test's provider nodes. It
exercises keys, calls by direct dial and their errors, providers, server and
client streams and a provider that aborts one (and that no stream is left
unreleased), a sealed call and a required call refused in the clear, a gated
procedure refusing a missing, misaddressed or foreign token and serving a
granted and a delegated one, the seal report of a call and a stream, pubsub, node-served content and the DHT, through the real library.
A provider a test calls runs in a PHP process of its own
(`tests/fixtures/provider.php`). No network is needed. The suite needs PHP ≥
8.3 (PHPUnit 12's floor); the library itself runs on PHP ≥ 8.1.

`tests/LampsCompositeTest.php` holds pq_hybrid, the LAMPS composite
id-MLDSA87-RSA4096-PSS-SHA512, to `draft-ietf-lamps-pq-composite-sigs`' own
vector (the one macula and macula-go check), and to composites that crossed
both ways with macula 12.x. `scripts/cross-verify-macula.sh` renews those: this
SDK signs, macula (from hex, in the image macula's own CI runs in) verifies
and signs its own, and this SDK verifies it.

`tests/live/FleetTest.php` runs against one real station and is not part of
`composer test`. It needs `MACULA_PHP_LIVE_SEED` (host:port),
`MACULA_PHP_LIVE_STATION_ID` (the station's node_id), `MACULA_PHP_LIVE_REALM`
and `MACULA_PHP_LIVE_REALM_KEY`; an unset one fails the run naming it. With a
key generated for the run and never saved, it reads the DHT, calls
`mcl-echo/echo` by direct dial and hears its own publication.

## Requirements

- PHP ≥ 8.1 with `ext-ffi`. `ext-ffi` is not always enabled in distro PHP
  builds: check `php -m | grep FFI`; PHP is built with it by `--with-ffi`.
- `gh` with a `GH_TOKEN` and `sha256sum`, for `composer build` to fetch and
  check libmacula. Go 1.27, only for the test suite's teststation.
- Composer.

## Sibling SDKs

| Repo | Approach |
|---|---|
| [macula](https://github.com/macula-io/macula) | The reference SDK (Erlang/OTP) |
| [macula-go](https://github.com/macula-io/macula-go) | Go port, and what this SDK binds |
| [macula-ts](https://github.com/macula-io/macula-ts) | FFI binding over the same libmacula, for Node.js |
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
