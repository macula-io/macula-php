package main

/*
#include <stdint.h>
*/
import "C"

import "github.com/macula-io/macula-go/cbor"

// Payload kind tags shared with the PHP side (see src/Value.php) --
// mirrors macula-rust-ffi's own FfiValue restriction to
// Null/Int/Bytes/Text/Float (no List/Map yet -- recursive shapes are a
// deliberate v1 cut, not a wire limitation, matching that precedent
// exactly).
const (
	kindNull  = 0
	kindInt   = 1
	kindBytes = 2
	kindText  = 3
	kindFloat = 4
)

// phpValueToGo builds a cbor.Value from the flat scalar parameters a
// cgo-exported function receives for a payload. Only one of intVal/
// bytesVal/floatVal is meaningful, selected by kind -- matches how
// every payload-carrying export (macula_call, macula_publish, ...)
// takes its own payload_kind/payload_int/payload_bytes/payload_bytes_len/
// payload_float quintet.
func phpValueToGo(kind C.int, intVal C.longlong, bytesVal *C.uchar, bytesLen C.int, floatVal C.double) cbor.Value {
	switch int(kind) {
	case kindInt:
		return cbor.Int(int64(intVal))
	case kindBytes:
		return cbor.Bytes(cBytesToGo(bytesVal, bytesLen))
	case kindText:
		return cbor.Text(string(cBytesToGo(bytesVal, bytesLen)))
	case kindFloat:
		return cbor.Float(float64(floatVal))
	default:
		return cbor.Null()
	}
}

// goValueToPhp is the reverse: decomposes a cbor.Value into the flat
// fields a cgo-exported accessor function can hand back to PHP one at a
// time (kind first, then the caller reads only the field that kind
// implies). bytes/text length is always available via a paired
// *_len accessor -- see e.g. macula_response_result_bytes_len.
func goValueToPhp(v cbor.Value) (kind int, intVal int64, bytesVal []byte, floatVal float64) {
	switch v.Kind() {
	case cbor.KindUInt, cbor.KindNegInt:
		n, _ := v.AsInt64()
		return kindInt, n, nil, 0
	case cbor.KindBytes:
		b, _ := v.AsBytes()
		return kindBytes, 0, b, 0
	case cbor.KindText:
		t, _ := v.AsText()
		return kindText, 0, []byte(t), 0
	case cbor.KindFloat:
		f, _ := v.AsFloat()
		return kindFloat, 0, nil, f
	default:
		return kindNull, 0, nil, 0
	}
}
