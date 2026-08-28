package main

/*
#include <stdint.h>
*/
import "C"

import (
	"context"
	"runtime/cgo"

	"github.com/macula-io/macula-go-sdk/connection"
	"github.com/macula-io/macula-go-sdk/content"
	"github.com/macula-io/macula-go-sdk/identity"
	"github.com/macula-io/macula-go-sdk/manifest"
)

//export macula_content_put
func macula_content_put(
	sessionHandle C.uintptr_t,
	data *C.uchar, dataLen C.int,
	name *C.char,
	identityHandle C.uintptr_t,
	mcidOut *C.uchar, // 34 bytes
	errOut **C.char,
) C.int {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		setErr(errOut, errInvalidSessionHandle)
		return -1
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return -1
	}

	mcid, err := content.Put(context.Background(), session, cBytesToGo(data, dataLen), C.GoString(name), id)
	if err != nil {
		setErr(errOut, err)
		return -1
	}
	dst := unsafe34(mcidOut)
	copy(dst, mcid[:])
	return 0
}

//export macula_content_get
func macula_content_get(
	sessionHandle C.uintptr_t,
	mcid34 *C.uchar, // 34 bytes in
	identityHandle C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		setErr(errOut, errInvalidSessionHandle)
		return 0
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return 0
	}

	var mcid manifest.Mcid
	copy(mcid[:], unsafe34(mcid34))

	data, err := content.Get(context.Background(), session, mcid, id)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(data))
}

//export macula_bytes_handle_len
func macula_bytes_handle_len(bytesHandle C.uintptr_t) C.int {
	data, _ := cgo.Handle(bytesHandle).Value().([]byte)
	return C.int(len(data))
}

//export macula_bytes_handle_read
func macula_bytes_handle_read(bytesHandle C.uintptr_t, out *C.uchar) {
	data, _ := cgo.Handle(bytesHandle).Value().([]byte)
	copy(cOutSlice(out, len(data)), data)
}

//export macula_bytes_handle_free
func macula_bytes_handle_free(bytesHandle C.uintptr_t) {
	cgo.Handle(bytesHandle).Delete()
}
