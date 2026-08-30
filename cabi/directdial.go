package main

/*
#include <stdint.h>
*/
import "C"

import (
	"context"
	"runtime/cgo"
	"time"

	"github.com/macula-io/macula-go/directdial"
	"github.com/macula-io/macula-go/frame"
	"github.com/macula-io/macula-go/manifest"
)

// macula_resolve_direct resolves procedure's currently-advertised serving
// station via the mesh DHT, using sessionHandle only to query it (it need
// not be connected to the station that will end up serving the call).
// Writes the 32-byte station id and dialable port into the given
// out-params and returns the host as a C string (caller must
// macula_free_string it); returns -1 with err_out set on failure.
//
//export macula_resolve_direct
func macula_resolve_direct(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	identityHandle C.uintptr_t,
	stationOut *C.uchar, // 32 bytes
	portOut *C.uint16_t,
	errOut **C.char,
) *C.char {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return nil
	}
	station, host, port, err := directdial.Resolve(session, id, bytes32FromC(realm32), C.GoString(procedure))
	if err != nil {
		setErr(errOut, err)
		return nil
	}
	copy32(stationOut, station)
	*portOut = C.uint16_t(port)
	return C.CString(host)
}

// macula_resolve_direct_with_cert_chain is macula_resolve_direct, plus
// Slice 7c Direction B managed-realm authorization: only an advertisement
// whose embedded cert chain validates to realm_ca_pem and names
// expected_org is trusted.
//
//export macula_resolve_direct_with_cert_chain
func macula_resolve_direct_with_cert_chain(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	realmCAPEM *C.uchar, realmCAPEMLen C.int,
	expectedOrg *C.char,
	identityHandle C.uintptr_t,
	stationOut *C.uchar,
	portOut *C.uint16_t,
	errOut **C.char,
) *C.char {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return nil
	}
	station, host, port, err := directdial.ResolveWithCertChain(
		session, id, bytes32FromC(realm32), C.GoString(procedure),
		cBytesToGo(realmCAPEM, realmCAPEMLen), C.GoString(expectedOrg),
	)
	if err != nil {
		setErr(errOut, err)
		return nil
	}
	copy32(stationOut, station)
	*portOut = C.uint16_t(port)
	return C.CString(host)
}

// macula_call_direct resolves procedure via the mesh DHT (using
// resolve_via_session_handle to query it) and dials its serving station
// directly, in one hop -- instead of depending on ordinary advertise-
// gossip having propagated a route between whichever two stations happen
// to be involved. Returns the same response handle shape macula_call
// does (see macula_response_* accessors).
//
//export macula_call_direct
func macula_call_direct(
	resolveViaSessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	payloadKind C.int, payloadInt C.longlong, payloadBytes *C.uchar, payloadBytesLen C.int, payloadFloat C.double,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(resolveViaSessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	payload := phpValueToGo(payloadKind, payloadInt, payloadBytes, payloadBytesLen, payloadFloat)
	timeout := time.Duration(timeoutMs) * time.Millisecond
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	response, err := directdial.Call(ctx, session, id, bytes32FromC(realm32), C.GoString(procedure), payload, timeout)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(response))
}

// macula_call_direct_with_cert_chain is macula_call_direct, resolved via
// managed-realm cert-chain authorization instead of plain trust.
//
//export macula_call_direct_with_cert_chain
func macula_call_direct_with_cert_chain(
	resolveViaSessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	realmCAPEM *C.uchar, realmCAPEMLen C.int,
	expectedOrg *C.char,
	payloadKind C.int, payloadInt C.longlong, payloadBytes *C.uchar, payloadBytesLen C.int, payloadFloat C.double,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(resolveViaSessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	payload := phpValueToGo(payloadKind, payloadInt, payloadBytes, payloadBytesLen, payloadFloat)
	timeout := time.Duration(timeoutMs) * time.Millisecond
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	response, err := directdial.CallWithCertChain(
		ctx, session, id, bytes32FromC(realm32), C.GoString(procedure),
		cBytesToGo(realmCAPEM, realmCAPEMLen), C.GoString(expectedOrg),
		payload, timeout,
	)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(response))
}

// macula_advertise_direct publishes a signed procedure_advertisement DHT
// record naming session_handle's own currently-connected station as
// procedure's server, discoverable by any caller's resolve/call_direct.
// One-shot: a station's registration for a procedure does not survive the
// connection that sent it being replaced, so a long-lived server calls
// this again on its own schedule (this repo intentionally does not wrap a
// background re-advertise loop -- see the module doc note in this file).
//
//export macula_advertise_direct
func macula_advertise_direct(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	ttlMs C.longlong,
	identityHandle C.uintptr_t,
	errOut **C.char,
) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	if err := directdial.AdvertiseDirect(session, id, bytes32FromC(realm32), C.GoString(procedure), time.Duration(ttlMs)*time.Millisecond); err != nil {
		setErr(errOut, err)
	}
}

// macula_advertise_direct_with_cert_chain is macula_advertise_direct,
// additionally embedding cert_chain_pem in the published record so a
// resolve_direct_with_cert_chain caller can authorize this advertiser for
// a specific org.
//
//export macula_advertise_direct_with_cert_chain
func macula_advertise_direct_with_cert_chain(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	ttlMs C.longlong,
	certChainPEM *C.uchar, certChainPEMLen C.int,
	identityHandle C.uintptr_t,
	errOut **C.char,
) {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return
	}
	err = directdial.AdvertiseDirectWithCertChain(
		session, id, bytes32FromC(realm32), C.GoString(procedure),
		time.Duration(ttlMs)*time.Millisecond, cBytesToGo(certChainPEM, certChainPEMLen),
	)
	if err != nil {
		setErr(errOut, err)
	}
}

// macula_stream_open_direct is macula_stream_open's direct-dial
// counterpart: resolves procedure via the mesh DHT and dials its serving
// station directly, then opens a dedicated stream against that new
// connection. session_handle_out receives the new session's handle
// (distinct from resolve_via_session_handle -- direct-dial always opens a
// FRESH connection to the resolved station); the caller owns it and must
// eventually macula_session_close it, same as any other session.
//
//export macula_stream_open_direct
func macula_stream_open_direct(
	resolveViaSessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	mode C.int,
	argsKind C.int, argsInt C.longlong, argsBytes *C.uchar, argsBytesLen C.int, argsFloat C.double,
	deadlineMs C.longlong,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	sessionHandleOut *C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(resolveViaSessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	args := phpValueToGo(argsKind, argsInt, argsBytes, argsBytesLen, argsFloat)
	timeout := time.Duration(timeoutMs) * time.Millisecond
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	newSession, handle, err := directdial.OpenStreamDirect(ctx, session, id, bytes32FromC(realm32), C.GoString(procedure), frame.StreamMode(mode), args, int64(deadlineMs), timeout)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	if sessionHandleOut != nil {
		*sessionHandleOut = C.uintptr_t(cgo.NewHandle(newSession))
	}
	return C.uintptr_t(cgo.NewHandle(handle))
}

// macula_stream_open_direct_with_cert_chain is macula_stream_open_direct,
// resolved via managed-realm cert-chain authorization.
//
//export macula_stream_open_direct_with_cert_chain
func macula_stream_open_direct_with_cert_chain(
	resolveViaSessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	realmCAPEM *C.uchar, realmCAPEMLen C.int,
	expectedOrg *C.char,
	mode C.int,
	argsKind C.int, argsInt C.longlong, argsBytes *C.uchar, argsBytesLen C.int, argsFloat C.double,
	deadlineMs C.longlong,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	sessionHandleOut *C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(resolveViaSessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	args := phpValueToGo(argsKind, argsInt, argsBytes, argsBytesLen, argsFloat)
	timeout := time.Duration(timeoutMs) * time.Millisecond
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	newSession, handle, err := directdial.OpenStreamDirectWithCertChain(
		ctx, session, id, bytes32FromC(realm32), C.GoString(procedure),
		cBytesToGo(realmCAPEM, realmCAPEMLen), C.GoString(expectedOrg),
		frame.StreamMode(mode), args, int64(deadlineMs), timeout,
	)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	if sessionHandleOut != nil {
		*sessionHandleOut = C.uintptr_t(cgo.NewHandle(newSession))
	}
	return C.uintptr_t(cgo.NewHandle(handle))
}

// macula_put_direct stores data on a KNOWN station (identified by its
// 32-byte node id, e.g. one already resolved via macula_resolve_direct or
// otherwise known out of band), dialing it directly. Unlike a procedure
// advertisement, there is no DHT lookup here -- content storage has no
// "advertisement" step of its own, matching macula_feeder's own design.
//
//export macula_put_direct
func macula_put_direct(
	resolveViaSessionHandle C.uintptr_t,
	station32 *C.uchar,
	data *C.uchar, dataLen C.int,
	name *C.char,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	mcidOut *C.uchar, // 34 bytes
	errOut **C.char,
) C.int {
	session, id, err := sessionAndIdentity(resolveViaSessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return -1
	}
	timeout := time.Duration(timeoutMs) * time.Millisecond
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	mcid, err := directdial.PutDirect(ctx, session, id, bytes32FromC(station32), cBytesToGo(data, dataLen), C.GoString(name), timeout)
	if err != nil {
		setErr(errOut, err)
		return -1
	}
	copy(unsafe34(mcidOut), mcid[:])
	return 0
}

// macula_get_direct fetches content addressed by mcid34, resolving its
// serving endpoint via a content_announcement DHT record (published only
// by something independently-dialable, e.g. a station/relay -- a leaf SDK
// identity cannot legitimately publish one, so there is no
// macula_announce_content_direct in this SDK, matching macula-go's
// own scope exactly).
//
//export macula_get_direct
func macula_get_direct(
	resolveViaSessionHandle C.uintptr_t,
	mcid34 *C.uchar,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(resolveViaSessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	var mcid manifest.Mcid
	copy(mcid[:], unsafe34(mcid34))
	timeout := time.Duration(timeoutMs) * time.Millisecond
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	data, err := directdial.GetDirect(ctx, session, id, mcid, timeout)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(data))
}
