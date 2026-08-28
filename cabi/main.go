// Package main is macula-php-sdk's C ABI layer: a thin cgo export
// wrapping macula-go-sdk's existing blocking public API, built as a
// shared library (`go build -buildmode=c-shared`) for PHP's `ext-ffi`
// to load directly. macula-go-sdk itself needs zero changes for this —
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
// Walking skeleton scope only, matching exactly what macula-go-sdk and
// macula-rust-sdk each proved first: identity generation, the
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
	"runtime/cgo"
	"time"
	"unsafe"

	"github.com/macula-io/macula-go-sdk/connection"
	"github.com/macula-io/macula-go-sdk/identity"
	"github.com/macula-io/macula-go-sdk/transport"
)

var errInvalidIdentityHandle = errors.New("macula-php-sdk/cabi: invalid identity handle")

func setErr(errOut **C.char, err error) {
	if errOut == nil || err == nil {
		return
	}
	*errOut = C.CString(err.Error())
}

//export macula_free_string
func macula_free_string(s *C.char) {
	C.free(unsafe.Pointer(s))
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

//export macula_identity_free
func macula_identity_free(identityHandle C.uintptr_t) {
	cgo.Handle(identityHandle).Delete()
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
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if ok {
		id, idOk := cgo.Handle(identityHandle).Value().(identity.KeyPair)
		if idOk {
			_ = session.Close("normal", nil, id)
		}
	}
	cgo.Handle(sessionHandle).Delete()
}

func main() {} // required by -buildmode=c-shared, never actually run
