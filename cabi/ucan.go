package main

/*
#include <stdint.h>
*/
import "C"

import (
	"encoding/json"
	"errors"
	"runtime/cgo"
	"time"

	"github.com/macula-io/macula-go/cbor"
	"github.com/macula-io/macula-go/connection"
	"github.com/macula-io/macula-go/frame"
	"github.com/macula-io/macula-go/identity"
	"github.com/macula-io/macula-go/ucan"
)

// errCallRejectedByPolicy surfaces a UCAN policy rejection to PHP as a
// distinguishable error -- see macula_serve_wait_for_call_gated's doc.
var errCallRejectedByPolicy = errors.New("macula-php/cabi: inbound call refused by UCAN policy (no matching handler ever ran)")

// UCAN capabilities/proofs cross the FFI boundary as JSON strings, not a
// new flat scalar tuple like payload values -- both are already
// arbitrary-shaped ([]ucan.Capability, []string) that ucan.Capability's
// own json tags (`with`/`can`) and Go's stdlib encoding/json handle
// directly, and neither is large or hot-path enough to justify a bespoke
// wire format the way Value's kind/int/bytes/float quintet is for
// payloads exchanged on every single call.

//export macula_ucan_create
func macula_ucan_create(
	issuer *C.char,
	audience *C.char,
	capabilitiesJSON *C.char, // JSON array of {"with":"...","can":"..."}, or NULL for none
	hasExpiresAt C.int, expiresAtUnixSec C.longlong,
	hasNotBefore C.int, notBeforeUnixSec C.longlong,
	identityHandle C.uintptr_t,
	errOut **C.char,
) C.uintptr_t {
	id, ok := cgo.Handle(identityHandle).Value().(identity.KeyPair)
	if !ok {
		setErr(errOut, errInvalidIdentityHandle)
		return 0
	}

	var caps []ucan.Capability
	if capabilitiesJSON != nil {
		if err := json.Unmarshal([]byte(C.GoString(capabilitiesJSON)), &caps); err != nil {
			setErr(errOut, err)
			return 0
		}
	}

	opts := ucan.CreateOpts{}
	if hasExpiresAt != 0 {
		v := int64(expiresAtUnixSec)
		opts.ExpiresAt = &v
	}
	if hasNotBefore != 0 {
		v := int64(notBeforeUnixSec)
		opts.NotBefore = &v
	}

	token, err := ucan.Create(C.GoString(issuer), C.GoString(audience), caps, id, opts)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(token))
}

//export macula_ucan_verify
func macula_ucan_verify(tokenBytes *C.uchar, tokenLen C.int, publicKey32 *C.uchar, errOut **C.char) C.uintptr_t {
	payload, err := ucan.Verify(cBytesToGo(tokenBytes, tokenLen), bytes32FromC(publicKey32))
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(payload))
}

//export macula_ucan_decode
func macula_ucan_decode(tokenBytes *C.uchar, tokenLen C.int, errOut **C.char) C.uintptr_t {
	payload, err := ucan.Decode(cBytesToGo(tokenBytes, tokenLen))
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(payload))
}

//export macula_ucan_is_expired
func macula_ucan_is_expired(tokenBytes *C.uchar, tokenLen C.int, errOut **C.char) C.int {
	expired, err := ucan.IsExpired(cBytesToGo(tokenBytes, tokenLen))
	if err != nil {
		setErr(errOut, err)
		return -1
	}
	if expired {
		return 1
	}
	return 0
}

//export macula_ucan_payload_issuer
func macula_ucan_payload_issuer(payloadHandle C.uintptr_t) *C.char {
	p, _ := cgo.Handle(payloadHandle).Value().(ucan.Payload)
	return C.CString(p.Issuer)
}

//export macula_ucan_payload_audience
func macula_ucan_payload_audience(payloadHandle C.uintptr_t) *C.char {
	p, _ := cgo.Handle(payloadHandle).Value().(ucan.Payload)
	return C.CString(p.Audience)
}

// macula_ucan_payload_expires_at writes 1/0 into has_out to distinguish
// "no expiry claim at all" from an expiry of unix-epoch-zero, and returns
// the value itself (meaningless when has_out is 0).
//
//export macula_ucan_payload_expires_at
func macula_ucan_payload_expires_at(payloadHandle C.uintptr_t, hasOut *C.int) C.longlong {
	p, _ := cgo.Handle(payloadHandle).Value().(ucan.Payload)
	if p.ExpiresAt == nil {
		*hasOut = 0
		return 0
	}
	*hasOut = 1
	return C.longlong(*p.ExpiresAt)
}

//export macula_ucan_payload_not_before
func macula_ucan_payload_not_before(payloadHandle C.uintptr_t, hasOut *C.int) C.longlong {
	p, _ := cgo.Handle(payloadHandle).Value().(ucan.Payload)
	if p.NotBefore == nil {
		*hasOut = 0
		return 0
	}
	*hasOut = 1
	return C.longlong(*p.NotBefore)
}

// macula_ucan_payload_capabilities_json returns the payload's
// capabilities as a JSON array, mirroring macula_ucan_create's own
// capabilities_json input shape.
//
//export macula_ucan_payload_capabilities_json
func macula_ucan_payload_capabilities_json(payloadHandle C.uintptr_t) *C.char {
	p, _ := cgo.Handle(payloadHandle).Value().(ucan.Payload)
	b, err := json.Marshal(p.Capabilities)
	if err != nil {
		return C.CString("[]")
	}
	return C.CString(string(b))
}

//export macula_ucan_payload_proofs_json
func macula_ucan_payload_proofs_json(payloadHandle C.uintptr_t) *C.char {
	p, _ := cgo.Handle(payloadHandle).Value().(ucan.Payload)
	b, err := json.Marshal(p.Proofs)
	if err != nil {
		return C.CString("[]")
	}
	return C.CString(string(b))
}

//export macula_ucan_payload_free
func macula_ucan_payload_free(payloadHandle C.uintptr_t) {
	cgo.Handle(payloadHandle).Delete()
}

// The minted token is plain []byte -- read it back with the existing
// macula_bytes_handle_len/_read/_free trio content.go's macula_content_get
// already exports; both wrap the identical Go type, so a second set of
// accessors would be pure duplication.

//export macula_call_with_ucan
func macula_call_with_ucan(
	sessionHandle C.uintptr_t,
	procedure *C.char,
	realm32 *C.uchar,
	payloadKind C.int, payloadInt C.longlong, payloadBytes *C.uchar, payloadBytesLen C.int, payloadFloat C.double,
	timeoutMs C.int,
	identityHandle C.uintptr_t,
	ucanToken *C.uchar, ucanTokenLen C.int,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	payload := phpValueToGo(payloadKind, payloadInt, payloadBytes, payloadBytesLen, payloadFloat)
	timeout := time.Duration(timeoutMs) * time.Millisecond
	deadlineMs := time.Now().Add(timeout).UnixMilli()
	response, err := session.CallWithUCAN(C.GoString(procedure), bytes32FromC(realm32), payload, deadlineMs, id, timeout, cBytesToGo(ucanToken, ucanTokenLen))
	if err != nil {
		setErr(errOut, err)
		return 0
	}
	return C.uintptr_t(cgo.NewHandle(response))
}

// macula_serve_wait_for_call_gated is macula_serve_wait_for_call, plus
// UCAN policy gating evaluated BEFORE the returned PendingCall's handler
// ever runs -- a rejected caller never reaches PHP at all, matching
// connection.ServeOneCallGated's own contract exactly. Pass
// required_issuer32 as NULL for an open (ungated) policy, or a 32-byte
// Ed25519 public key to require a verifying token from that issuer.
//
//export macula_serve_wait_for_call_gated
func macula_serve_wait_for_call_gated(
	sessionHandle C.uintptr_t,
	identityHandle C.uintptr_t,
	timeoutMs C.int,
	requiredIssuer32 *C.uchar,
	errOut **C.char,
) C.uintptr_t {
	session, id, err := sessionAndIdentity(sessionHandle, identityHandle)
	if err != nil {
		setErr(errOut, err)
		return 0
	}

	policy := ucan.Open
	if requiredIssuer32 != nil {
		policy = ucan.Required(bytes32FromC(requiredIssuer32))
	}

	// Reuses pendingCall/callReply/errServeReply from serve.go verbatim
	// (same package) -- the rendezvous shape is identical to plain
	// macula_serve_wait_for_call, only the ServeOneCall vs
	// ServeOneCallGated call and its extra policy argument differ.
	callCh := make(chan frame.CallInfo, 1)
	replyCh := make(chan callReply, 1)
	doneCh := make(chan error, 1)

	lookup := func(realm []byte, procedure string) (connection.CallHandler, bool) {
		return connection.CallHandler(func(payload cbor.Value) (cbor.Value, error) {
			callCh <- frame.CallInfo{Procedure: procedure, Realm: append([]byte(nil), realm...), Payload: payload}
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
	policyLookup := func(_ []byte, _ string) ucan.Policy { return policy }

	go func() {
		doneCh <- session.ServeOneCallGated(lookup, policyLookup, id, time.Duration(timeoutMs)*time.Millisecond)
	}()

	select {
	case info := <-callCh:
		return C.uintptr_t(cgo.NewHandle(&pendingCall{info: info, replyCh: replyCh, doneCh: doneCh}))
	case err := <-doneCh:
		// ServeOneCallGated returning nil here (as opposed to a real
		// transport/timeout error) means it successfully sent SOME
		// reply without lookup ever running -- the only way that
		// happens is the policy check itself refused the call (a
		// business-logic ERROR from a handler would have gone through
		// lookup/callCh first). Surface that as a distinguishable
		// error rather than silently returning a zero/invalid handle
		// with err_out left unset, which PHP could not tell apart
		// from "succeeded."
		if err == nil {
			err = errCallRejectedByPolicy
		}
		setErr(errOut, err)
		return 0
	}
}
