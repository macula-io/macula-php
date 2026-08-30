package main

/*
#include <stdint.h>
*/
import "C"

import (
	"runtime/cgo"
	"time"

	"github.com/macula-io/macula-go/cbor"
	"github.com/macula-io/macula-go/connection"
	"github.com/macula-io/macula-go/frame"
)

// pendingCall is the rendezvous point between the background goroutine
// driving Session.ServeOneCall and the two, separate, later cgo calls
// PHP makes to inspect and reply to it. Session.ServeOneCall bundles
// "wait for a CALL, invoke a handler, send the reply" into one
// synchronous Go call with an embedded callback -- PHP has no
// equivalent of a Go closure to hand across the FFI boundary, so this
// splits that one call into three: macula_serve_wait_for_call blocks
// until the CALL arrives and returns a handle; PHP inspects it via
// ordinary accessors; macula_pending_call_reply_result/_error resumes
// the waiting goroutine with PHP's answer and blocks again until
// ServeOneCall has actually sent the reply frame.
//
// This is plain Go concurrency (a goroutine + two channels), NOT
// fork() -- cgo + fork() is a real, documented incompatibility
// (golang/go#15538: fork() only duplicates the calling thread, leaving
// a forked child's copy of the Go runtime's scheduler/netpoller
// broken), which is why the streaming-provider example in this repo
// uses two OS processes instead of pcntl_fork(). Goroutines have no
// such restriction; this file never forks anything.
type pendingCall struct {
	info    frame.CallInfo
	replyCh chan callReply
	doneCh  chan error
}

type callReply struct {
	isError bool
	value   cbor.Value // RESULT payload, when !isError
	detail  *string    // ERROR detail, when isError
}

//export macula_serve_wait_for_call
func macula_serve_wait_for_call(sessionHandle C.uintptr_t, identityHandle C.uintptr_t, timeoutMs C.int, errOut **C.char) C.uintptr_t {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}

	callCh := make(chan frame.CallInfo, 1)
	replyCh := make(chan callReply, 1)
	doneCh := make(chan error, 1)

	lookup := func(realm []byte, procedure string) (connection.CallHandler, bool) {
		return connection.CallHandler(func(payload cbor.Value) (cbor.Value, error) {
			callCh <- frame.CallInfo{
				Procedure: procedure,
				Realm:     append([]byte(nil), realm...),
				Payload:   payload,
			}
			reply := <-replyCh
			if reply.isError {
				detail := ""
				if reply.detail != nil {
					detail = *reply.detail
				}
				return cbor.Null(), errServeReply(detail)
			}
			return reply.value, nil
		}), true
	}

	go func() {
		doneCh <- session.ServeOneCall(lookup, id, time.Duration(timeoutMs)*time.Millisecond)
	}()

	select {
	case info := <-callCh:
		return C.uintptr_t(cgo.NewHandle(&pendingCall{info: info, replyCh: replyCh, doneCh: doneCh}))
	case err := <-doneCh:
		// ServeOneCall returned without ever reaching the handler --
		// a timeout, or a transport-level error.
		if err != nil {
			setErr(errOut, err)
		}
		return 0
	}
}

type errServeReply string

func (e errServeReply) Error() string { return string(e) }

//export macula_pending_call_procedure
func macula_pending_call_procedure(pendingHandle C.uintptr_t) *C.char {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	return C.CString(pending.info.Procedure)
}

//export macula_pending_call_realm
func macula_pending_call_realm(pendingHandle C.uintptr_t, out32 *C.uchar) {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	copy32(out32, pending.info.Realm)
}

//export macula_pending_call_payload_kind
func macula_pending_call_payload_kind(pendingHandle C.uintptr_t) C.int {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	kind, _, _, _ := goValueToPhp(pending.info.Payload)
	return C.int(kind)
}

//export macula_pending_call_payload_int
func macula_pending_call_payload_int(pendingHandle C.uintptr_t) C.longlong {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	_, n, _, _ := goValueToPhp(pending.info.Payload)
	return C.longlong(n)
}

//export macula_pending_call_payload_float
func macula_pending_call_payload_float(pendingHandle C.uintptr_t) C.double {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	_, _, _, f := goValueToPhp(pending.info.Payload)
	return C.double(f)
}

//export macula_pending_call_payload_bytes_len
func macula_pending_call_payload_bytes_len(pendingHandle C.uintptr_t) C.int {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	_, _, b, _ := goValueToPhp(pending.info.Payload)
	return C.int(len(b))
}

//export macula_pending_call_payload_bytes
func macula_pending_call_payload_bytes(pendingHandle C.uintptr_t, out *C.uchar) {
	pending, _ := cgo.Handle(pendingHandle).Value().(*pendingCall)
	_, _, b, _ := goValueToPhp(pending.info.Payload)
	copy(cOutSlice(out, len(b)), b)
}

//export macula_pending_call_reply_result
func macula_pending_call_reply_result(
	pendingHandle C.uintptr_t,
	kind C.int, intVal C.longlong, bytesVal *C.uchar, bytesLen C.int, floatVal C.double,
	errOut **C.char,
) {
	pending, ok := cgo.Handle(pendingHandle).Value().(*pendingCall)
	if !ok {
		setErr(errOut, errInvalidPendingCallHandle)
		return
	}
	pending.replyCh <- callReply{value: phpValueToGo(kind, intVal, bytesVal, bytesLen, floatVal)}
	if err := <-pending.doneCh; err != nil {
		setErr(errOut, err)
	}
}

//export macula_pending_call_reply_error
func macula_pending_call_reply_error(pendingHandle C.uintptr_t, detail *C.char, errOut **C.char) {
	pending, ok := cgo.Handle(pendingHandle).Value().(*pendingCall)
	if !ok {
		setErr(errOut, errInvalidPendingCallHandle)
		return
	}
	var d *string
	if detail != nil {
		s := C.GoString(detail)
		d = &s
	}
	pending.replyCh <- callReply{isError: true, detail: d}
	if err := <-pending.doneCh; err != nil {
		setErr(errOut, err)
	}
}

//export macula_pending_call_free
func macula_pending_call_free(pendingHandle C.uintptr_t) {
	cgo.Handle(pendingHandle).Delete()
}
