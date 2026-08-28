# macula-php-sdk

**Status, 2026-08-28 — walking skeleton, live-verified end to end**
(PHP → `ext-ffi` → Go C ABI → QUIC → a real production station).

A PHP client for the [Macula](https://github.com/macula-io/macula) wire
protocol. Architecturally this is a thin binding, not a third from-scratch
protocol port: [`macula-go-sdk`](https://github.com/macula-io/macula-go-sdk)
already has a complete, live-verified implementation of the wire protocol
with a plain blocking API (no goroutines/channels required of the caller) —
a close match for PHP's own default blocking-call execution model. This
repo wraps that existing SDK as a C shared library and loads it from PHP
via `ext-ffi`, rather than re-implementing CBOR/QUIC/Ed25519/frame
signing a third time.

Rust's own mobile bindings (`macula-rust-sdk-ffi`) took the analogous
approach for Kotlin/Swift via UniFFI — no UniFFI backend exists for PHP,
so this repo hand-builds a much simpler *synchronous* C ABI directly
(PHP calls a blocking C function; the Go side blocks on the QUIC
operation internally and returns), skipping the async-callback plumbing
UniFFI needs for Kotlin coroutines / Swift `async` entirely.

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
                it directly); KeyPair.php and Session.php are the public API.
examples/       Runnable scripts, e.g. handshake.php.
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

$identity = KeyPair::generate(); // puzzle-hardened by construction
$session = Session::connect('station-de-frankfurt.macula.io', 4433, $identity);

printf("accepted: %s\n", $session->accepted ? 'true' : 'false');
printf("station node_id: %s\n", bin2hex($session->stationNodeId));

$session->close();
```

## The C ABI

Handles: every Go value that crosses the boundary (identities, sessions)
is wrapped as a `runtime/cgo.Handle` — an opaque `uintptr_t` PHP holds
and passes back, never touching the Go value directly. `Session` holds
a PHP reference to the `KeyPair` it was connected with for its whole
lifetime, both because macula-go-sdk's own `Close` needs the identity
again to sign the GOODBYE frame, and because that reference keeps PHP's
refcounting GC from freeing the identity out from under a still-open
session — their destruction order isn't otherwise guaranteed. Errors:
functions that can fail take a `char** err_out` — on failure they
`malloc` a C string into `*err_out` that the caller frees via
`macula_free_string`; `Binding::withErrOut()` wraps this pattern once
so `KeyPair`/`Session` never touch it directly.

**Live-verified twice, independently, 2026-08-28:**

1. `cabi/testc/smoke.c` — a standalone C program, no PHP involved,
   linked directly against `libmacula.so`:
   ```
   identity node_id: 65ceb482a8fe8e590798fa6c10d96b2d721c2988299c4213a91cd888de373a6d
   accepted: 1
   station node_id: 808d48be8780338f9739b96b17a09c086caea2fdac878b28e8b89fc8d72592a6
   OK
   ```
2. `examples/handshake.php` — the real target, PHP through `ext-ffi`:
   ```
   identity node_id: 912e278c91bc32aa8834b556381ba1ccac829a0d5884fa1bf8d29915012c108b
   accepted: true
   station node_id: 808d48be8780338f9739b96b17a09c086caea2fdac878b28e8b89fc8d72592a6
   OK
   ```

Both runs hit `station-de-frankfurt.macula.io`, the real fleet, and both
report the same station identity — proving the cgo handle-passing and
blocking-call boundary survive being driven from genuine PHP, not just
from C or from Go's own test harness.

## Requirements

- PHP ≥ 8.1 with `ext-ffi` and `ext-sodium` enabled. `ext-ffi` is not
  always enabled by default in distro PHP builds — check `php -m | grep
  FFI`; if missing, PHP needs to be (re)built with `--with-ffi` (not
  `--enable-ffi` — that flag doesn't exist and is silently ignored by
  `configure`, which was this repo's own first mistake building it).
- Go ≥ 1.25 and a C compiler (`cgo` requirement) to build `cabi/`.
- Composer.

## Status

**Built and live-verified, both layers:**
- The Go C-ABI layer (`cabi/`) — identity generation, CONNECT/HELLO
  handshake, close.
- The PHP wrapper (`src/`) — `KeyPair`, `Session`, loaded via
  `ext-ffi`, driving the same operations through the full real stack.

**Not yet built, deferred past the walking skeleton** (same order the Go
and Rust ports built them in): unary RPC, PubSub, content transfer,
streaming RPC. `macula-go-sdk` already has all of these live-verified;
extending `cabi/`'s C ABI and `src/`'s PHP classes to cover them is the
same mechanical pattern as the identity/connect/close slice already
proven here.

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
