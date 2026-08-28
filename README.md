# macula-php-sdk

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
                needed to macula-go-sdk itself.
cabi/testc/     A standalone C smoke test, independent of PHP -- proves the
                cgo boundary and a real handshake work without needing PHP
                installed at all.
src/            PHP composer package. Binding.php loads libmacula.so via
                FFI::cdef() (hand-written declarations, not the raw
                cgo-generated header -- FFI::cdef() has no C preprocessor,
                so #include/#ifdef in the generated header can't be fed to
                it directly). KeyPair, Session, Value, CallResponse, Event,
                StreamHandle/StreamItem/StreamReply/StreamOpenInfo,
                PendingCall are the public API.
examples/       Runnable scripts against the real production fleet.
```

## Quick start

```bash
cd cabi && go build -buildmode=c-shared -o libmacula.so . && cd ..
composer install
php examples/handshake.php
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
bash examples/run_stream_provider_test.sh
bash examples/run_rpc_provider_test.sh
```

Both spawn a provider process in the background, give the station a
moment to register its `advertise()`, then run a caller process against
it — the realistic shape a real deployment takes anyway (a provider
daemon process, separate from whatever calls it), not a workaround
adopted only for testing.

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
$ php examples/handshake.php
identity node_id: 912e278c91bc32aa8834b556381ba1ccac829a0d5884fa1bf8d29915012c108b
accepted: true
station node_id: 808d48be8780338f9739b96b17a09c086caea2fdac878b28e8b89fc8d72592a6
OK

$ php examples/rpc_pubsub.php
CALL -> ERROR (expected): code=1 name=unknown_next_peer detail=(none)
EVENT received: topic=... seq=1 delivered_via=direct payload=hello from macula-php-sdk

$ php examples/content.php
put single block: mcid=...  single-block round trip OK
put chunked: mcid=...  size=536633  chunked round trip OK

$ php examples/stream_probe.php
no reply within 5s, as: stream: peer aborted the stream: unknown_next_peer (procedure not advertised)

$ bash examples/run_stream_provider_test.sh
[provider] accepted stream_open for procedure=...  mode=0
[caller] received chunk: hello from the provider
[caller] received Eof

$ bash examples/run_rpc_provider_test.sh
[provider] serving CALL for procedure=...
[caller] got RESULT 42
```

Every empirical finding here matches `macula-go-sdk`'s and
`macula-rust-sdk`'s own live results exactly (`unknown_next_peer` for
both an un-advertised CALL and an un-advertised STREAM_OPEN,
`delivered_via=direct` for a subscriber receiving its own publish) —
three independent implementations, now four, agreeing not just on wire
bytes but on live protocol behavior.

## Requirements

- PHP ≥ 8.1 with `ext-ffi` and `ext-sodium` enabled (`ext-pcntl` only
  if you want to run this repo's own two-process examples via a single
  orchestrating script rather than shelling out manually). `ext-ffi` is
  not always enabled by default in distro PHP builds — check
  `php -m | grep FFI`; if missing, PHP needs to be (re)built with
  `--with-ffi` (**not** `--enable-ffi` — that flag doesn't exist and is
  silently ignored by `configure`, which was this repo's own first
  mistake building it).
- Go ≥ 1.25 and a C compiler (`cgo` requirement) to build `cabi/`.
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
