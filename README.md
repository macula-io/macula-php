# macula-php-sdk

[![CI](https://img.shields.io/github/actions/workflow/status/macula-io/macula-php-sdk/ci.yml?branch=main&label=CI)](https://github.com/macula-io/macula-php-sdk/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0%20OR%20MIT-blue.svg)](#license)
[![PHP](https://img.shields.io/badge/php-8.1%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Go](https://img.shields.io/badge/go-1.25%2B-00ADD8?logo=go)](https://go.dev)
[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-yellow.svg)](https://buymeacoffee.com/rlefever)

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/macula-php-full-dark.svg">
    <img src="assets/macula-php-full-light.svg" alt="Macula" width="320">
  </picture>
</p>

<p align="center">
  <strong>PHP client for the Macula SDK wire protocol</strong>
</p>

---

**Status, 2026-08-28 — feature-complete, live-verified end to end**
(PHP → `ext-ffi` → Go C ABI → QUIC → a real production station), matching
[`macula-go-sdk`](https://github.com/macula-io/macula-go-sdk) and
[`macula-rust-sdk`](https://github.com/macula-io/macula-rust-sdk):
handshake, unary RPC, PubSub, content transfer, and streaming RPC, every
primitive in both caller and provider roles.

A PHP client for the [Macula](https://github.com/macula-io/macula) wire
protocol. Architecturally this is a thin binding, not a third from-scratch
protocol port: `macula-go-sdk` already has a complete, live-verified
implementation of the wire protocol with a plain blocking API (no
goroutines/channels required of the caller) — a close match for PHP's own
default blocking-call execution model. This repo wraps that existing SDK
as a C shared library and loads it from PHP via `ext-ffi`, rather than
re-implementing CBOR/QUIC/Ed25519/frame signing a third time.

Rust's own mobile bindings (`macula-rust-sdk-ffi`) took the analogous
approach for Kotlin/Swift via UniFFI — no UniFFI backend exists for PHP,
so this repo hand-builds a much simpler *synchronous* C ABI directly
(PHP calls a blocking C function; the Go side blocks on the QUIC
operation internally and returns), skipping the async-callback plumbing
UniFFI needs for Kotlin coroutines / Swift `async` entirely — with one
exception: unary-RPC provider dispatch needs a real rendezvous (a PHP
handler runs *between* "a CALL arrived" and "send the reply"), which
this repo builds as a goroutine + channel pair on the Go side, split
into two PHP-facing calls (`Session::serveWaitForCall()` returning a
`PendingCall`, then `PendingCall::replyResult()`/`replyError()`) — see
[Provider dispatch](#provider-dispatch-unary-rpc) below.

## New to Go? You'll never write any

This SDK is PHP. The only thing Go is used for is compiling one shared
library (`libmacula.so`) that PHP loads at runtime — you run one build
command and never touch Go again. If you don't have Go installed:

- **Any OS**: download the installer from
  [go.dev/dl](https://go.dev/dl/) and follow its instructions, or
- **macOS**: `brew install go`
- **Debian/Ubuntu**: `sudo apt install golang-go` (check the version is
  ≥ 1.25 — if your distro's package is older, use the go.dev installer
  instead)
- **Arch**: `sudo pacman -S go`
- **Fedora**: `sudo dnf install golang`

Verify with `go version`, then follow [Quick start](#quick-start) below
— `cd cabi && go build ...` is the one and only Go command you'll ever
run. A future release may ship prebuilt `libmacula.so` binaries via
Composer so this step isn't needed at all; for now, building it
yourself is a single command that takes a few seconds.

## Features

| Primitive | Caller | Provider | Notes |
|---|---|---|---|
| Handshake (CONNECT/HELLO) | ✅ | — | |
| Unary RPC (CALL/RESULT/ERROR) | ✅ | ✅ | Provider via a goroutine+channel rendezvous, see below |
| PubSub (PUBLISH/SUBSCRIBE/EVENT) | ✅ | ✅ | A subscriber gets its own publish, verified live |
| Content transfer (single-block + chunked) | ✅ | ✅ | Content-addressed, BLAKE3/SHA-256, Merkle-verified |
| Streaming RPC (STREAM_OPEN/DATA/END/REPLY) | ✅ | ✅ | Provider via `streamAccept()` — no rendezvous needed, unlike unary RPC |
| RPC advertise/unadvertise | ✅ | — | |

## Structure

```
cabi/           Go module, builds libmacula.so via `go build -buildmode=c-shared`.
                Plain consumer of macula-go-sdk's public API -- no changes
                needed to macula-go-sdk itself. You never edit this unless
                you're adding a new wire primitive.
cabi/testc/     A standalone C smoke test, independent of PHP -- proves the
                cgo boundary and a real handshake work without needing PHP
                installed at all.
src/            PHP composer package -- this is what you actually use.
                Binding.php loads libmacula.so via FFI::cdef(); KeyPair,
                Session, Value, CallResponse, Event, StreamHandle/
                StreamItem/StreamReply/StreamOpenInfo, PendingCall are
                the public API.
examples/       One runnable script per wire primitive, against the real
                production fleet -- see Examples below.
tests/          Offline PHPUnit suite -- no network, no live station.
                See Testing below.
```

## Quick start

```bash
cd cabi && go build -buildmode=c-shared -o libmacula.so . && cd ..
composer install
php examples/01_handshake.php
```

```php
<?php
require 'vendor/autoload.php';

use Macula\KeyPair;
use Macula\Session;
use Macula\Value;

$identity = KeyPair::generate(); // puzzle-hardened by construction
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

$response = $session->call('io.macula.echo', str_repeat("\x00", 32), Value::text('hello'));
printf("is_error=%s payload=%s\n", $response->isError() ? 'true' : 'false', $response->payload()->asText());

$session->close();
```

`Value` is a restricted mirror of `macula-go-sdk`'s `cbor.Value` --
`Null`/`Int`/`Bytes`/`Text`/`Float` (no `List`/`Map` yet, the same v1
cut `macula-rust-sdk-ffi`'s own `FfiValue` made — a payload needing
structure today should be encoded as `Bytes`).

## Examples

One script per wire primitive, each runnable on its own against the
real production fleet (`station-de-frankfurt.macula.io`) — read them in
order, they build on each other:

| # | File | Primitive |
|---|---|---|
| 1 | [`01_handshake.php`](examples/01_handshake.php) | Identity + CONNECT/HELLO handshake |
| 2 | [`02_call.php`](examples/02_call.php) | Unary RPC, caller role |
| 3 | [`03_publish_subscribe.php`](examples/03_publish_subscribe.php) | PubSub: SUBSCRIBE → PUBLISH → EVENT |
| 4 | [`04_content.php`](examples/04_content.php) | Content transfer: single-block and chunked put/get |
| 5 | [`05_stream_open_caller.php`](examples/05_stream_open_caller.php) | Streaming RPC, caller role |
| 6 | [`06_run_rpc_provider.sh`](examples/06_run_rpc_provider.sh) | Unary RPC, provider role (`serveWaitForCall`) — two processes |
| 7 | [`07_run_stream_provider.sh`](examples/07_run_stream_provider.sh) | Streaming RPC, provider role (`streamAccept`) — two processes |

Run any of 1–5 directly (`php examples/02_call.php`); 6 and 7 are
`.sh` scripts because the provider role genuinely needs two independent
connections — see [Two-process pattern](#two-process-pattern-for-provider-role-examples)
for why that's two OS processes (`06_rpc_provider_serve.php` +
`06_rpc_provider_call.php`, `07_stream_provider_serve.php` +
`07_stream_provider_call.php`) rather than one script.

## Testing

```bash
cd cabi && go build -buildmode=c-shared -o libmacula.so . && cd ..
composer install
composer test   # or: vendor/bin/phpunit
```

`tests/` is an **offline** PHPUnit suite — no network, no live station,
runs in CI on every push. It's not testing everything the examples
above prove; it's testing what's actually testable without a real
station: `Value` construction (pure PHP), `Binding`'s marshaling
helpers (`valueFromParts()`, `cBytes()` — the latter does load
`libmacula.so` and allocate a real C buffer, but never opens a
connection), and `KeyPair` lifecycle (`generate()`/`nodeId()`/`free()`
against the real compiled library — Ed25519 keygen and S/Kademlia
puzzle-hardening are entirely local computation, no network involved
at all). Everything that needs an actual CONNECT/HELLO handshake —
which is most of the wire protocol — is proven by the
[examples](#examples) instead, run manually against the real
production fleet, the same live-verification discipline
`macula-go-sdk` and `macula-rust-sdk` both use.

## Provider dispatch (unary RPC)

```php
$session->advertise('math.double', $realm);

$pending = $session->serveWaitForCall(30000); // blocks for the next inbound CALL
$n = $pending->payload()->intValue;
$pending->replyResult(Value::int($n * 2));
```

Unlike every other primitive here, this can't be a single blocking
call: `macula-go-sdk`'s own `Session.ServeOneCall` takes a Go closure
as the handler and runs "wait, invoke, reply" as one atomic operation
— PHP has nothing to hand across the FFI boundary in place of that
closure. `cabi/serve.go` splits it instead: `macula_serve_wait_for_call`
spawns a goroutine running the real `ServeOneCall`, whose handler
closure blocks on a Go channel; `macula_serve_wait_for_call` itself
blocks on a second channel until that handler fires, then returns a
`PendingCall` handle. `PendingCall::replyResult()`/`replyError()`
sends PHP's answer back down the first channel and blocks until
`ServeOneCall` has actually sent the wire frame. **Exactly one reply
call is required per `PendingCall`** — dropping one without replying
leaks the waiting goroutine, since there's no cross-boundary way to
notice "PHP gave up" and unblock it (`PendingCall` warns via
`trigger_error` if this happens).

## Two-process pattern for provider-role examples

`fork()` after a cgo-backed shared library (`libmacula.so`) is loaded
is not safe for continued execution in the child — confirmed against
[golang/go#15538](https://github.com/golang/go/issues/15538): `fork()`
only duplicates the calling OS thread, not the extra threads Go's own
scheduler/netpoller depend on, so a forked child gets a broken copy of
the Go runtime (silent I/O stalls, not a crash you'd notice
immediately — this repo hit it directly building the streaming
example). The standard fix is "fork() only safe when immediately
followed by exec()"; this repo's own provider-role examples use two
real OS processes instead of `pcntl_fork()`:

```bash
bash examples/06_run_rpc_provider.sh
bash examples/07_run_stream_provider.sh
```

Both spawn a provider process in the background, give the station a
moment to register its `advertise()`, then run a caller process against
it — the realistic shape a real deployment takes anyway (a provider
daemon process, separate from whatever calls it), not a workaround
adopted only for testing.

**A second real gotcha found running these:** `Session::close()` tears
down the whole QUIC connection immediately (`macula-go-sdk`'s own
`Session.Close` has no drain step). For unary RPC this is harmless —
the caller only returns from `call()` after actually receiving the
RESULT frame, so by the time either side closes, the exchange is
already confirmed complete. For streaming, `closeSend()` is
fire-and-forget (no acknowledgment the peer's `recv()` has seen the
STREAM_END yet), so a provider closing its session immediately after
`closeSend()` can race the frame it just queued, and the caller
sometimes sees a hard connection-level EOF instead of a graceful
end-of-stream — reproduced directly building this repo (`07`'s
provider failed intermittently, `06`'s never did, and the RPC-vs-
streaming acknowledgment difference above is exactly why).

This is inherent to `closeSend()`'s fire-and-forget design, not
something a fixed delay can fully eliminate (a longer `usleep()` only
narrows the window, and did not fully close it under stress testing —
23/24 runs clean, one still racing). `07_stream_provider_serve.php`
keeps a short `usleep()` before `close()` as a courtesy that helps in
the common case; `07_stream_provider_call.php` is the actual fix —
it treats a connection-level EOF on the final `recv()` as an accepted,
documented outcome rather than a failure, since by that point the
real data has already arrived and both shapes mean the same thing
("nothing more is coming"). A real long-lived provider daemon
wouldn't hit this at all, since it has no reason to close its
connection right after every response.

## The C ABI

Handles: every Go value that crosses the boundary (identities,
sessions, call responses, events, streams, pending calls) is wrapped as
a `runtime/cgo.Handle` — an opaque `uintptr_t` PHP holds and passes
back, never touching the Go value directly. Payloads: `Value`s cross as
five flat scalar parameters (`kind`, `int_val`, `bytes_val`/`bytes_len`,
`float_val`) rather than a struct, deliberately — a struct's memory
layout has to match byte-for-byte between Go's cgo-generated version and
PHP's own hand-written `FFI::cdef()` declaration, and getting that wrong
fails as a segfault, not a type error; flat scalar parameters carry no
such risk. `Session` holds a PHP reference to the `KeyPair` it was
connected with (and `StreamHandle` to the `KeyPair` it was
opened/accepted with) for its whole lifetime — both because
macula-go-sdk's own signing operations need the identity again on every
call, and because that reference keeps PHP's refcounting GC from
freeing the identity out from under a still-open session/stream (their
destruction order isn't otherwise guaranteed). Errors: functions that
can fail take a `char** err_out` — on failure they `malloc` a C string
into `*err_out` that the caller frees via `macula_free_string`;
`Binding::withErrOut()` wraps this pattern once so the public classes
never touch it directly.

**Live-verified, 2026-08-28, every primitive, against
`station-de-frankfurt.macula.io`:**

```
$ php examples/01_handshake.php
identity node_id: 912e278c91bc32aa8834b556381ba1ccac829a0d5884fa1bf8d29915012c108b
accepted: true
station node_id: 808d48be8780338f9739b96b17a09c086caea2fdac878b28e8b89fc8d72592a6
OK

$ php examples/02_call.php
OBSERVED: got an ERROR (expected for a nonexistent procedure): code=1 name=unknown_next_peer

$ php examples/03_publish_subscribe.php
OBSERVED: received our own EVENT back: topic=... seq=1 delivered_via=direct payload=hello from macula-php-sdk

$ php examples/04_content.php
put single block: mcid=...  single-block round trip OK
put chunked: mcid=...  size=536633  chunked round trip OK

$ php examples/05_stream_open_caller.php
no reply within 5s, as: stream: peer aborted the stream: unknown_next_peer (procedure not advertised)

$ bash examples/06_run_rpc_provider.sh
[provider] serving CALL for procedure=...
[caller] got RESULT 42

$ bash examples/07_run_stream_provider.sh
[provider] accepted stream_open for procedure=...  mode=0
[caller] received chunk: hello from the provider
[caller] received Eof
```

Every empirical finding here matches `macula-go-sdk`'s and
`macula-rust-sdk`'s own live results exactly (`unknown_next_peer` for
both an un-advertised CALL and an un-advertised STREAM_OPEN,
`delivered_via=direct` for a subscriber receiving its own publish) —
three independent implementations, now four, agreeing not just on wire
bytes but on live protocol behavior.

## Requirements

- PHP ≥ 8.1 with `ext-ffi` and `ext-sodium` enabled (`ext-pcntl` is
  not required — this repo's own provider-role examples orchestrate two
  processes via plain shell `&`/`wait`, not PHP-level forking). `ext-ffi`
  is not always enabled by default in distro PHP builds — check
  `php -m | grep FFI`; if missing, PHP needs to be (re)built with
  `--with-ffi` (**not** `--enable-ffi` — that flag doesn't exist and is
  silently ignored by `configure`, which was this repo's own first
  mistake building it).
- Go ≥ 1.25 and a C compiler (`cgo` requirement) to build `cabi/` —
  see [New to Go?](#new-to-go-youll-never-write-any) above if you don't
  have it installed; you'll run one build command and never touch Go
  again.
- Composer.

## Status

**Built and live-verified, feature-complete:** identity, CONNECT/HELLO,
unary RPC (both roles), PubSub, content transfer, streaming RPC (both
roles) — the same wire-protocol scope `macula-go-sdk` and
`macula-rust-sdk` cover, all driven through the full real stack from
genuine PHP.

**Not built, and out of scope for a leaf SDK entirely** (not a gap):
DHT/HyParView/Plumtree gossip primitives — station-to-station overlay
membership/broadcast, never a leaf-client concern; `macula-go-sdk`'s
own spec says so explicitly.

## Related projects

| Project | Description |
|---|---|
| [macula-go-sdk](https://github.com/macula-io/macula-go-sdk) | The Go SDK this repo binds to |
| [macula-rust-sdk](https://github.com/macula-io/macula-rust-sdk) | The Rust port — mobile bindings via UniFFI |
| [macula](https://github.com/macula-io/macula) | The reference SDK (Erlang/OTP) |

## License

Licensed under either of

- Apache License, Version 2.0 ([LICENSE-APACHE](LICENSE-APACHE) or <http://www.apache.org/licenses/LICENSE-2.0>)
- MIT license ([LICENSE-MIT](LICENSE-MIT) or <http://opensource.org/licenses/MIT>)

at your option.

The PHP emblem in this README's header logo is the [official PHP
logo](https://www.php.net/download-logos.php), © Colin Viebrock,
licensed
[CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) — used
and redistributed here (as part of `assets/macula-php-full-{dark,light}.svg`)
under that license's own attribution and share-alike terms, distinct
from this repo's own dual Apache-2.0/MIT license above.

---

<p align="center">
  <sub>Built with the BEAM's protocol, ported to PHP — <a href="https://buymeacoffee.com/rlefever">buy me a coffee</a> if this saved you some time</sub>
</p>
