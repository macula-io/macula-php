// Package main is macula-php's C ABI layer: a thin cgo export
// wrapping macula-go's existing blocking public API, built as a
// shared library (`go build -buildmode=c-shared`) for PHP's `ext-ffi`
// to load directly. macula-go itself needs zero changes for this —
// this file is an ordinary consumer of its public API, same as any Go
// program importing the module.
//
// Handles: every Go value that crosses the boundary (identities,
// sessions) is wrapped as a `runtime/cgo.Handle` — an opaque uintptr
// safe to pass through C and back, the standard Go mechanism for
// exactly this. PHP holds the uintptr, never touches the Go value
// directly, and must call the matching `_free`/`_close` function when
// done — there is no GC coordination across this boundary.
//
// Errors: any function that can fail takes a `char** err_out`. On
// failure it mallocs a C string into `*err_out` (via C.CString) and
// returns a zero/negative sentinel; the caller must free it with
// macula_free_string. On success `*err_out` is left untouched.
//
// Walking skeleton scope only, matching exactly what macula-go and
// macula-rust each proved first: identity generation, the
// CONNECT/HELLO handshake, and close — live-verified against a real
// station before anything else gets built on top.
package main

/*
#include <stdlib.h>
#include <stdint.h>
*/
import "C"

import (
	"context"
	"errors"
	"fmt"
	"net"
	"runtime/cgo"
	"strconv"
	"strings"
	"time"
	"unsafe"

	"github.com/macula-io/macula-go/connection"
	"github.com/macula-io/macula-go/identity"
	"github.com/macula-io/macula-go/transport"
)

var (
	errInvalidIdentityHandle    = errors.New("macula-php/cabi: invalid identity handle")
	errInvalidSessionHandle     = errors.New("macula-php/cabi: invalid session handle")
	errInvalidStreamHandle      = errors.New("macula-php/cabi: invalid stream handle")
	errInvalidPendingCallHandle = errors.New("macula-php/cabi: invalid pending-call handle")
)

func setErr(errOut **C.char, err error) {
	if errOut == nil || err == nil {
		return
	}
	*errOut = C.CString(err.Error())
}

// cBytesToGo copies a C buffer into a fresh Go []byte -- used for every
// bytes/text payload field crossing the boundary, so the returned slice
// never aliases PHP-owned memory past the call that produced it.
func cBytesToGo(ptr *C.uchar, length C.int) []byte {
	if ptr == nil || length <= 0 {
		return nil
	}
	return C.GoBytes(unsafe.Pointer(ptr), length)
}

// copy32 writes src (any length) into a 32-byte C output buffer,
// zero-padding or truncating as needed -- every node_id/realm/call_id-
// adjacent field crossing the boundary is exactly 32 bytes by protocol
// construction, so truncation never actually happens in practice; this
// just avoids a panic if it somehow did.
func copy32(dst *C.uchar, src []byte) {
	out := unsafe.Slice((*byte)(unsafe.Pointer(dst)), 32)
	n := copy(out, src)
	for i := n; i < 32; i++ {
		out[i] = 0
	}
}

// bytes32FromC reads a fixed 32-byte C buffer into a Go []byte.
func bytes32FromC(src *C.uchar) []byte {
	return append([]byte(nil), unsafe.Slice((*byte)(unsafe.Pointer(src)), 32)...)
}

// unsafe34 views a 34-byte C buffer (an MCID -- version+codec+32-byte
// hash, plans/PLAN_WIRE_PROTOCOL.md §12.1) as a Go []byte, for reading
// from or writing into.
func unsafe34(buf *C.uchar) []byte {
	return unsafe.Slice((*byte)(unsafe.Pointer(buf)), 34)
}

// cOutSlice views a PHP-allocated output buffer of the given length as
// a Go []byte to copy into -- the PHP side is responsible for
// allocating exactly this many bytes first (it always knows the length
// up front via a paired *_len accessor).
func cOutSlice(dst *C.uchar, length int) []byte {
	if length <= 0 {
		return nil
	}
	return unsafe.Slice((*byte)(unsafe.Pointer(dst)), length)
}

//export macula_free_string
func macula_free_string(s *C.char) {
	C.free(unsafe.Pointer(s))
}

// safeDeleteHandle deletes h, recovering a panic instead of letting it
// propagate. cgo.Handle.Delete (like .Value) panics on an
// already-deleted or otherwise invalid handle, and an unrecovered
// panic inside any cgo-exported function is fatal to the WHOLE host
// process -- Go's panic unwinding doesn't stop at the C caller, it
// terminates the process outright, taking down every other in-flight
// request this same PHP process happens to be serving, not just the
// one holding the bad handle.
//
// The PHP side is the primary guard (every wrapper class nulls its own
// handle after freeing and rejects further use via handleOrFail(),
// and __clone() on every one of them explicitly nulls the clone's copy
// before throwing -- see e.g. KeyPair::__clone()). This is defense in
// depth for whatever that tracking doesn't catch: a double-free must
// cost nothing, never the whole process, same principle as
// macula-go's own pool.deliverOne recovering a subscriber handler's
// panic so one bad callback doesn't kill every other subscriber.
func safeDeleteHandle(h cgo.Handle) {
	defer func() { recover() }()
	h.Delete()
}

//export macula_identity_generate
func macula_identity_generate(errOut **C.char) C.uintptr_t {
	id, err := identity.Generate()
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(id))
}

//export macula_identity_node_id
func macula_identity_node_id(identityHandle C.uintptr_t, out32 *C.uchar) C.int {
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		return -1
	}
	nodeID := id.NodeID()
	dst := unsafe.Slice((*byte)(unsafe.Pointer(out32)), 32)
	copy(dst, nodeID)
	return 0
}

//export macula_identity_from_seed_bytes
func macula_identity_from_seed_bytes(seed32 *C.uchar, errOut **C.char) C.uintptr_t {
	id, err := identity.FromSeed(bytes32FromC(seed32))
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(id))
}

//export macula_identity_private_bytes
func macula_identity_private_bytes(identityHandle C.uintptr_t, out32 *C.uchar) C.int {
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		return -1
	}
	seed := id.Private.Seed()
	dst := unsafe.Slice((*byte)(unsafe.Pointer(out32)), 32)
	copy(dst, seed)
	return 0
}

//export macula_identity_free
func macula_identity_free(identityHandle C.uintptr_t) {
	safeDeleteHandle(cgo.Handle(identityHandle))
}

//export macula_connect
func macula_connect(host *C.char, port C.uint16_t, identityHandle C.uintptr_t, timeoutMs C.int, errOut **C.char) C.uintptr_t {
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return 0
	}

	ctx, cancel := context.WithTimeout(context.Background(), time.Duration(timeoutMs)*time.Millisecond)
	defer cancel()

	session, err := connection.Connect(ctx, C.GoString(host), uint16(port), transport.WebPKI{}, id)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(session))
}

// parseSeedsCSV parses "host1[:port1],host2[:port2],..." into an
// ordered candidate list -- port defaults to 4433 (macula-station's
// standard QUIC port across the demo fleet) when omitted, matching
// macula-cli's own parseHostPort. Blank entries (from a stray comma)
// are skipped rather than erroring, so trailing/doubled commas from a
// PHP-side implode() aren't a footgun.
func parseSeedsCSV(csv string) ([]connection.Seed, error) {
	parts := strings.Split(csv, ",")
	seeds := make([]connection.Seed, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		host, portStr, err := net.SplitHostPort(p)
		if err != nil {
			// No port present at all -- net.SplitHostPort's own error for
			// that case is indistinguishable from a real syntax error
			// without string-matching it, so just retry as host-only.
			seeds = append(seeds, connection.Seed{Host: p, Port: 4433})
			continue
		}
		port, err := strconv.ParseUint(portStr, 10, 16)
		if err != nil {
			return nil, fmt.Errorf("macula-php/cabi: invalid port in seed %q: %w", p, err)
		}
		seeds = append(seeds, connection.Seed{Host: host, Port: uint16(port)})
	}
	if len(seeds) == 0 {
		return nil, errors.New("macula-php/cabi: macula_connect_seeds requires at least one non-empty seed")
	}
	return seeds, nil
}

// macula_connect_seeds is macula_connect's multi-station counterpart:
// seedsCSV is "host1[:port1],host2[:port2],..." (see parseSeedsCSV),
// tried in order via connection.ConnectSeeds -- the first that
// answers wins. A single delimited string rather than a char** array:
// PHP's ext-ffi has no reliable way to marshal an array of C strings
// across this boundary without inventing bespoke plumbing a plain
// string parameter doesn't need, and this package's own scope is
// deliberately a thin walking skeleton over macula-go's public API,
// not new cross-language array-passing machinery.
//
//export macula_connect_seeds
func macula_connect_seeds(seedsCSV *C.char, identityHandle C.uintptr_t, timeoutMs C.int, errOut **C.char) C.uintptr_t {
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return 0
	}

	seeds, err := parseSeedsCSV(C.GoString(seedsCSV))
	if err != nil {
		setErr(errOut, err)
		return 0
	}

	ctx, cancel := context.WithTimeout(context.Background(), time.Duration(timeoutMs)*time.Millisecond)
	defer cancel()

	session, err := connection.ConnectSeeds(ctx, seeds, transport.WebPKI{}, id)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(session))
}

//export macula_session_accepted
func macula_session_accepted(sessionHandle C.uintptr_t) C.int {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		return 0
	}
	if session.Station.Accepted {
		return 1
	}
	return 0
}

//export macula_session_station_node_id
func macula_session_station_node_id(sessionHandle C.uintptr_t, out32 *C.uchar) C.int {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		return -1
	}
	dst := unsafe.Slice((*byte)(unsafe.Pointer(out32)), 32)
	copy(dst, session.Station.NodeID)
	return 0
}

//export macula_session_close
func macula_session_close(sessionHandle C.uintptr_t, identityHandle C.uintptr_t) {
	// safeDeleteHandle is deferred FIRST (so it runs LAST -- defers are
	// LIFO) and unconditionally, before sessionHandle has even been
	// validated: if the recover() below fires partway through (e.g. an
	// invalid identityHandle), execution would otherwise return without
	// ever reaching a trailing Delete() call, leaking the session
	// handle (and its still-open QUIC connection) forever. Ordering
	// this defer outermost means the handle gets deleted regardless of
	// where -- or whether -- a panic happened above it; safeDeleteHandle
	// has its own recover() too, so this is safe even if sessionHandle
	// itself turns out to be invalid.
	defer safeDeleteHandle(cgo.Handle(sessionHandle))
	// Recovers a panic from EITHER .Value() call below (an invalid
	// sessionHandle or identityHandle) -- see safeDeleteHandle's doc for
	// why an unrecovered panic here is fatal to the whole process, not
	// just this call.
	defer func() { recover() }()

	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		return
	}
	id, idOk := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !idOk {
		return
	}
	_ = session.Close("normal", nil, id)
}

func main() {} // required by -buildmode=c-shared, never actually run
