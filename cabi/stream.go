package main

/*
#include <stdint.h>
*/
import "C"

import (
	"context"
	"runtime/cgo"
	"time"

	"github.com/macula-io/macula-go/cbor"
	"github.com/macula-io/macula-go/connection"
	"github.com/macula-io/macula-go/frame"
	"github.com/macula-io/macula-go/identity"
	"github.com/macula-io/macula-go/stream"
)

// streamReply pairs stream.Handle.AwaitReply's two return values into
// one named type -- cgo.NewHandle needs a single value to hold, and
// Go's own API returns a tuple here.
type streamReply struct {
	payload     cbor.Value
	respondedBy []byte
}

func streamHandleFrom(h C.uintptr_t) (*stream.Handle, bool) {
	v, ok := cgo.Handle(h).Value().(*stream.Handle)
	return v, ok
}

//export macula_stream_open
func macula_stream_open(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	mode C.int,
	argsKind C.int, argsInt C.longlong, argsBytes *C.uchar, argsBytesLen C.int, argsFloat C.double,
	deadlineMs C.longlong,
	identityHandle C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	args := phpValueToGo(argsKind, argsInt, argsBytes, argsBytesLen, argsFloat)
	handle, err := stream.Open(context.Background(), session, C.GoString(procedure), bytes32FromC(realm32), frame.StreamMode(mode), args, int64(deadlineMs), id)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(handle))
}

//export macula_stream_accept
func macula_stream_accept(sessionHandle C.uintptr_t, timeoutMs C.int, openInfoHandleOut *C.uintptr_t, errOut **C.char) C.uintptr_t {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		setErr(errOut, errInvalidSessionHandle)
		return 0
	}
	handle, info, err := stream.Accept(session, time.Duration(timeoutMs)*time.Millisecond)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	if openInfoHandleOut != nil {
		*openInfoHandleOut = C.uintptr_t(cgo.NewHandle(info))
	}
	return C.uintptr_t(cgo.NewHandle(handle))
}

//export macula_stream_open_info_procedure
func macula_stream_open_info_procedure(infoHandle C.uintptr_t) *C.char {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	return C.CString(info.Procedure)
}

//export macula_stream_open_info_realm
func macula_stream_open_info_realm(infoHandle C.uintptr_t, out32 *C.uchar) {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	copy32(out32, info.Realm)
}

//export macula_stream_open_info_mode
func macula_stream_open_info_mode(infoHandle C.uintptr_t) C.int {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	return C.int(info.Mode)
}

//export macula_stream_open_info_args_kind
func macula_stream_open_info_args_kind(infoHandle C.uintptr_t) C.int {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	kind, _, _, _ := goValueToPhp(info.Args)
	return C.int(kind)
}

//export macula_stream_open_info_args_int
func macula_stream_open_info_args_int(infoHandle C.uintptr_t) C.longlong {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	_, n, _, _ := goValueToPhp(info.Args)
	return C.longlong(n)
}

//export macula_stream_open_info_args_float
func macula_stream_open_info_args_float(infoHandle C.uintptr_t) C.double {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	_, _, _, f := goValueToPhp(info.Args)
	return C.double(f)
}

//export macula_stream_open_info_args_bytes_len
func macula_stream_open_info_args_bytes_len(infoHandle C.uintptr_t) C.int {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	_, _, b, _ := goValueToPhp(info.Args)
	return C.int(len(b))
}

//export macula_stream_open_info_args_bytes
func macula_stream_open_info_args_bytes(infoHandle C.uintptr_t, out *C.uchar) {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	_, _, b, _ := goValueToPhp(info.Args)
	copy(cOutSlice(out, len(b)), b)
}

//export macula_stream_open_info_deadline_ms
func macula_stream_open_info_deadline_ms(infoHandle C.uintptr_t) C.longlong {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	return C.longlong(info.DeadlineMs)
}

//export macula_stream_open_info_caller
func macula_stream_open_info_caller(infoHandle C.uintptr_t, out32 *C.uchar) {
	info, _ := cgo.Handle(infoHandle).Value().(frame.StreamOpenInfo)
	copy32(out32, info.Caller)
}

//export macula_stream_open_info_free
func macula_stream_open_info_free(infoHandle C.uintptr_t) {
	safeDeleteHandle(cgo.Handle(infoHandle))
}

//export macula_stream_send_data
func macula_stream_send_data(
	streamHandle C.uintptr_t,
	encoding C.int,
	bodyKind C.int, bodyInt C.longlong, bodyBytes *C.uchar, bodyBytesLen C.int, bodyFloat C.double,
	identityHandle C.uintptr_t,
	errOut **C.char,
) {
	handle, ok := streamHandleFrom(streamHandle)
	if !ok {
		setErr(errOut, errInvalidStreamHandle)
		return
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return
	}
	body := phpValueToGo(bodyKind, bodyInt, bodyBytes, bodyBytesLen, bodyFloat)
	if err := handle.SendData(frame.StreamEncoding(encoding), body, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_stream_close_send
func macula_stream_close_send(streamHandle C.uintptr_t, identityHandle C.uintptr_t, errOut **C.char) {
	handle, ok := streamHandleFrom(streamHandle)
	if !ok {
		setErr(errOut, errInvalidStreamHandle)
		return
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return
	}
	if err := handle.CloseSend(id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_stream_send_reply
func macula_stream_send_reply(
	streamHandle C.uintptr_t,
	payloadKind C.int, payloadInt C.longlong, payloadBytes *C.uchar, payloadBytesLen C.int, payloadFloat C.double,
	identityHandle C.uintptr_t,
	errOut **C.char,
) {
	handle, ok := streamHandleFrom(streamHandle)
	if !ok {
		setErr(errOut, errInvalidStreamHandle)
		return
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return
	}
	payload := phpValueToGo(payloadKind, payloadInt, payloadBytes, payloadBytesLen, payloadFloat)
	if err := handle.SendReply(payload, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_stream_recv
func macula_stream_recv(streamHandle C.uintptr_t, timeoutMs C.int, errOut **C.char) C.uintptr_t {
	handle, ok := streamHandleFrom(streamHandle)
	if !ok {
		setErr(errOut, errInvalidStreamHandle)
		return 0
	}
	item, err := handle.Recv(time.Duration(timeoutMs) * time.Millisecond)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(item))
}

//export macula_stream_item_is_eof
func macula_stream_item_is_eof(itemHandle C.uintptr_t) C.int {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	if item.IsEOF {
		return 1
	}
	return 0
}

//export macula_stream_item_seq
func macula_stream_item_seq(itemHandle C.uintptr_t) C.ulonglong {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	return C.ulonglong(item.Seq)
}

//export macula_stream_item_encoding
func macula_stream_item_encoding(itemHandle C.uintptr_t) C.int {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	return C.int(item.Encoding)
}

//export macula_stream_item_body_kind
func macula_stream_item_body_kind(itemHandle C.uintptr_t) C.int {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	kind, _, _, _ := goValueToPhp(item.Body)
	return C.int(kind)
}

//export macula_stream_item_body_int
func macula_stream_item_body_int(itemHandle C.uintptr_t) C.longlong {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	_, n, _, _ := goValueToPhp(item.Body)
	return C.longlong(n)
}

//export macula_stream_item_body_float
func macula_stream_item_body_float(itemHandle C.uintptr_t) C.double {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	_, _, _, f := goValueToPhp(item.Body)
	return C.double(f)
}

//export macula_stream_item_body_bytes_len
func macula_stream_item_body_bytes_len(itemHandle C.uintptr_t) C.int {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	_, _, b, _ := goValueToPhp(item.Body)
	return C.int(len(b))
}

//export macula_stream_item_body_bytes
func macula_stream_item_body_bytes(itemHandle C.uintptr_t, out *C.uchar) {
	item, _ := cgo.Handle(itemHandle).Value().(stream.Item)
	_, _, b, _ := goValueToPhp(item.Body)
	copy(cOutSlice(out, len(b)), b)
}

//export macula_stream_item_free
func macula_stream_item_free(itemHandle C.uintptr_t) {
	safeDeleteHandle(cgo.Handle(itemHandle))
}

//export macula_stream_await_reply
func macula_stream_await_reply(streamHandle C.uintptr_t, timeoutMs C.int, errOut **C.char) C.uintptr_t {
	handle, ok := streamHandleFrom(streamHandle)
	if !ok {
		setErr(errOut, errInvalidStreamHandle)
		return 0
	}
	payload, respondedBy, err := handle.AwaitReply(time.Duration(timeoutMs) * time.Millisecond)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(streamReply{payload: payload, respondedBy: respondedBy}))
}

//export macula_stream_reply_kind
func macula_stream_reply_kind(replyHandle C.uintptr_t) C.int {
	reply, _ := cgo.Handle(replyHandle).Value().(streamReply)
	kind, _, _, _ := goValueToPhp(reply.payload)
	return C.int(kind)
}

//export macula_stream_reply_int
func macula_stream_reply_int(replyHandle C.uintptr_t) C.longlong {
	reply, _ := cgo.Handle(replyHandle).Value().(streamReply)
	_, n, _, _ := goValueToPhp(reply.payload)
	return C.longlong(n)
}

//export macula_stream_reply_float
func macula_stream_reply_float(replyHandle C.uintptr_t) C.double {
	reply, _ := cgo.Handle(replyHandle).Value().(streamReply)
	_, _, _, f := goValueToPhp(reply.payload)
	return C.double(f)
}

//export macula_stream_reply_bytes_len
func macula_stream_reply_bytes_len(replyHandle C.uintptr_t) C.int {
	reply, _ := cgo.Handle(replyHandle).Value().(streamReply)
	_, _, b, _ := goValueToPhp(reply.payload)
	return C.int(len(b))
}

//export macula_stream_reply_bytes
func macula_stream_reply_bytes(replyHandle C.uintptr_t, out *C.uchar) {
	reply, _ := cgo.Handle(replyHandle).Value().(streamReply)
	_, _, b, _ := goValueToPhp(reply.payload)
	copy(cOutSlice(out, len(b)), b)
}

//export macula_stream_reply_responded_by
func macula_stream_reply_responded_by(replyHandle C.uintptr_t, out32 *C.uchar) {
	reply, _ := cgo.Handle(replyHandle).Value().(streamReply)
	copy32(out32, reply.respondedBy)
}

//export macula_stream_reply_free
func macula_stream_reply_free(replyHandle C.uintptr_t) {
	safeDeleteHandle(cgo.Handle(replyHandle))
}

//export macula_stream_abort
func macula_stream_abort(streamHandle C.uintptr_t, code *C.char, message *C.char, identityHandle C.uintptr_t) {
	handle, ok := streamHandleFrom(streamHandle)
	if !ok {
		return
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		return
	}
	handle.Abort(C.GoString(code), C.GoString(message), id)
}

//export macula_stream_free
func macula_stream_free(streamHandle C.uintptr_t) {
	safeDeleteHandle(cgo.Handle(streamHandle))
}
