package main

/*
#include <stdint.h>
*/
import "C"

import (
	"runtime/cgo"
	"time"

	"github.com/macula-io/macula-go/connection"
	"github.com/macula-io/macula-go/frame"
	"github.com/macula-io/macula-go/identity"
)

func sessionAndIdentity(sessionHandle, identityHandle C.uintptr_t) (*connection.Session, identity.KeyPair, error) {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		return nil, identity.KeyPair{}, errInvalidSessionHandle
	}
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		return nil, identity.KeyPair{}, errInvalidIdentityHandle
	}
	return session, id, nil
}

//export macula_publish
func macula_publish(
	sessionHandle C.uintptr_t,
	topic *C.char,
	realm32 *C.uchar,
	seq C.ulonglong,
	payloadKind C.int, payloadInt C.longlong, payloadBytes *C.uchar, payloadBytesLen C.int, payloadFloat C.double,
	publishedAtMs C.longlong,
	identityHandle C.uintptr_t,
	errOut **C.char,
) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	payload := phpValueToGo(payloadKind, payloadInt, payloadBytes, payloadBytesLen, payloadFloat)
	spec := frame.NewPublishSpec(C.GoString(topic), bytes32FromC(realm32), id.NodeID(), uint64(seq), payload, int64(publishedAtMs))
	if err := session.Publish(spec, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_subscribe
func macula_subscribe(sessionHandle C.uintptr_t, topic *C.char, realm32 *C.uchar, identityHandle C.uintptr_t, errOut **C.char) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	spec := frame.NewSubscribeSpec(C.GoString(topic), bytes32FromC(realm32), id.NodeID())
	if err := session.Subscribe(spec, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_unsubscribe
func macula_unsubscribe(sessionHandle C.uintptr_t, topic *C.char, realm32 *C.uchar, identityHandle C.uintptr_t, errOut **C.char) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	spec := frame.NewUnsubscribeSpec(C.GoString(topic), bytes32FromC(realm32), id.NodeID())
	if err := session.Unsubscribe(spec, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_advertise
func macula_advertise(sessionHandle C.uintptr_t, procedure *C.char, realm32 *C.uchar, identityHandle C.uintptr_t, errOut **C.char) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	spec := frame.NewAdvertiseSpec(bytes32FromC(realm32), C.GoString(procedure), id.NodeID())
	if err := session.Advertise(spec, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_unadvertise
func macula_unadvertise(sessionHandle C.uintptr_t, procedure *C.char, realm32 *C.uchar, identityHandle C.uintptr_t, errOut **C.char) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	spec := frame.NewUnadvertiseSpec(bytes32FromC(realm32), C.GoString(procedure), id.NodeID())
	if err := session.Unadvertise(spec, id); err != nil {
		setErr(errOut, err)
	}
}

//export macula_recv_event
func macula_recv_event(sessionHandle C.uintptr_t, timeoutMs C.int, errOut **C.char) C.uintptr_t {
	session, ok := cgo.Handle(sessionHandle).Value().(*connection.Session)
	if !ok {
		setErr(errOut, errInvalidSessionHandle)
		return 0
	}
	event, err := session.RecvEvent(time.Duration(timeoutMs) * time.Millisecond)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(event))
}

//export macula_event_topic
func macula_event_topic(eventHandle C.uintptr_t) *C.char {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	return C.CString(event.Topic)
}

//export macula_event_realm
func macula_event_realm(eventHandle C.uintptr_t, out32 *C.uchar) {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	copy32(out32, event.Realm)
}

//export macula_event_publisher
func macula_event_publisher(eventHandle C.uintptr_t, out32 *C.uchar) {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	copy32(out32, event.Publisher)
}

//export macula_event_seq
func macula_event_seq(eventHandle C.uintptr_t) C.ulonglong {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	return C.ulonglong(event.Seq)
}

//export macula_event_delivered_via
func macula_event_delivered_via(eventHandle C.uintptr_t) *C.char {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	return C.CString(event.DeliveredVia)
}

//export macula_event_payload_kind
func macula_event_payload_kind(eventHandle C.uintptr_t) C.int {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	kind, _, _, _ := goValueToPhp(event.Payload)
	return C.int(kind)
}

//export macula_event_payload_int
func macula_event_payload_int(eventHandle C.uintptr_t) C.longlong {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	_, n, _, _ := goValueToPhp(event.Payload)
	return C.longlong(n)
}

//export macula_event_payload_float
func macula_event_payload_float(eventHandle C.uintptr_t) C.double {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	_, _, _, f := goValueToPhp(event.Payload)
	return C.double(f)
}

//export macula_event_payload_bytes_len
func macula_event_payload_bytes_len(eventHandle C.uintptr_t) C.int {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	_, _, b, _ := goValueToPhp(event.Payload)
	return C.int(len(b))
}

//export macula_event_payload_bytes
func macula_event_payload_bytes(eventHandle C.uintptr_t, out *C.uchar) {
	event, _ := cgo.Handle(eventHandle).Value().(frame.EventInfo)
	_, _, b, _ := goValueToPhp(event.Payload)
	copy(cOutSlice(out, len(b)), b)
}

//export macula_event_free
func macula_event_free(eventHandle C.uintptr_t) {
	safeDeleteHandle(cgo.Handle(eventHandle))
}
