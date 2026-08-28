package main

/*
#include <stdint.h>
*/
import "C"

import (
	"runtime/cgo"
	"time"

	"github.com/macula-io/macula-go-sdk/connection"
	"github.com/macula-io/macula-go-sdk/frame"
	"github.com/macula-io/macula-go-sdk/identity"
)

//export macula_call
func macula_call(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	payloadKind C.int, payloadInt C.longlong, payloadBytes *C.uchar, payloadBytesLen C.int, payloadFloat C.double,
	timeoutMs C.int,
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

	payload := phpValueToGo(payloadKind, payloadInt, payloadBytes, payloadBytesLen, payloadFloat)
	timeout := time.Duration(timeoutMs) * time.Millisecond
	deadlineMs := time.Now().Add(timeout).UnixMilli()

	response, err := session.Call(C.GoString(procedure), bytes32FromC(realm32), payload, deadlineMs, id, timeout)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(response))
}

//export macula_response_is_error
func macula_response_is_error(responseHandle C.uintptr_t) C.int {
	response, ok := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	if !ok || !response.IsError {
		return 0
	}
	return 1
}

//export macula_response_result_kind
func macula_response_result_kind(responseHandle C.uintptr_t) C.int {
	response, ok := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	if !ok {
		return kindNull
	}
	kind, _, _, _ := goValueToPhp(response.Payload)
	return C.int(kind)
}

//export macula_response_result_int
func macula_response_result_int(responseHandle C.uintptr_t) C.longlong {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	_, n, _, _ := goValueToPhp(response.Payload)
	return C.longlong(n)
}

//export macula_response_result_float
func macula_response_result_float(responseHandle C.uintptr_t) C.double {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	_, _, _, f := goValueToPhp(response.Payload)
	return C.double(f)
}

//export macula_response_result_bytes_len
func macula_response_result_bytes_len(responseHandle C.uintptr_t) C.int {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	_, _, b, _ := goValueToPhp(response.Payload)
	return C.int(len(b))
}

//export macula_response_result_bytes
func macula_response_result_bytes(responseHandle C.uintptr_t, out *C.uchar) {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	_, _, b, _ := goValueToPhp(response.Payload)
	dst := cOutSlice(out, len(b))
	copy(dst, b)
}

//export macula_response_responded_by
func macula_response_responded_by(responseHandle C.uintptr_t, out32 *C.uchar) {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	copy32(out32, response.RespondedBy)
}

//export macula_response_error_code
func macula_response_error_code(responseHandle C.uintptr_t) C.int {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	return C.int(response.Code)
}

//export macula_response_error_name
func macula_response_error_name(responseHandle C.uintptr_t) *C.char {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	return C.CString(response.Name)
}

//export macula_response_reported_by
func macula_response_reported_by(responseHandle C.uintptr_t, out32 *C.uchar) {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	copy32(out32, response.ReportedBy)
}

//export macula_response_error_detail
func macula_response_error_detail(responseHandle C.uintptr_t) *C.char {
	response, _ := cgo.Handle(responseHandle).Value().(frame.CallResponse)
	if response.Detail == nil {
		return nil
	}
	return C.CString(*response.Detail)
}

//export macula_response_free
func macula_response_free(responseHandle C.uintptr_t) {
	cgo.Handle(responseHandle).Delete()
}
