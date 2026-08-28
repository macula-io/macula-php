# macula-php-sdk

**Status, 2026-08-28 — walking skeleton, Go/C-ABI layer live-verified,
PHP wrapper not yet built.**

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
cabi/        Go module, builds libmacula.so via `go build -buildmode=c-shared`.
             Plain consumer of macula-go-sdk's public API -- no changes
             needed to macula-go-sdk itself.
cabi/testc/  A standalone C smoke test, independent of PHP -- proves the
             cgo boundary and a real handshake work before any PHP code
             exists to test through it.
src/         (not yet built) PHP composer package, FFI::cdef() against
             libmacula.h.
```

## Building the Go layer

```bash
cd cabi
go build -buildmode=c-shared -o libmacula.so .
```

Produces `libmacula.so` + `libmacula.h`. Handles: every Go value that
crosses the boundary (identities, sessions) is wrapped as a
`runtime/cgo.Handle` — an opaque `uintptr_t` PHP holds and passes back,
never touching the Go value directly. PHP must call the matching
`_free`/`_close` function when done; there's no GC coordination across
this boundary. Errors: functions that can fail take a `char** err_out`
— on failure they `malloc` a C string into `*err_out` that the caller
must free via `macula_free_string`.

**Live-verified, 2026-08-28**, via `cabi/testc/smoke.c` (real C code,
not Go's own test harness) — a full CONNECT/HELLO handshake against
`station-de-frankfurt.macula.io` through the compiled `.so`:

```
identity node_id: 65ceb482a8fe8e590798fa6c10d96b2d721c2988299c4213a91cd888de373a6d
accepted: 1
station node_id: 808d48be8780338f9739b96b17a09c086caea2fdac878b28e8b89fc8d72592a6
OK
```

```bash
cc -o cabi/testc/smoke cabi/testc/smoke.c -Lcabi -lmacula -Wl,-rpath,cabi
./cabi/testc/smoke
```

## Status

**Built and live-verified:** the Go C-ABI layer (`cabi/`) — identity
generation, CONNECT/HELLO handshake, close. Proven end-to-end through
real compiled C code calling into the compiled `.so`, against the real
production station, not just exercised from within Go itself.

**Not yet built:** the PHP side (`src/`) — this machine didn't have PHP
installed; `ext-ffi` wrapper classes loading `libmacula.h`/`libmacula.so`
are the next piece, once PHP + `ext-ffi` are available to develop and
test against.

**Not yet built, deferred past the walking skeleton** (same order the Go
and Rust ports built them in): unary RPC, PubSub, content transfer,
streaming RPC. `macula-go-sdk` already has all of these live-verified;
extending `cabi/`'s C ABI to cover them is the same mechanical pattern
as the identity/connect/close slice already proven here.

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
