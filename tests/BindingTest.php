<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Binding;
use Macula\Value;
use PHPUnit\Framework\TestCase;

final class BindingTest extends TestCase
{
    public function testValueFromPartsInt(): void
    {
        $v = Binding::valueFromParts(Value::KIND_INT, 42, '', 0.0);
        $this->assertSame(Value::KIND_INT, $v->kind);
        $this->assertSame(42, $v->intValue);
    }

    public function testValueFromPartsBytes(): void
    {
        $v = Binding::valueFromParts(Value::KIND_BYTES, 0, "\x00\xff", 0.0);
        $this->assertSame(Value::KIND_BYTES, $v->kind);
        $this->assertSame("\x00\xff", $v->bytesValue);
    }

    public function testValueFromPartsText(): void
    {
        $v = Binding::valueFromParts(Value::KIND_TEXT, 0, 'hello', 0.0);
        $this->assertSame(Value::KIND_TEXT, $v->kind);
        $this->assertSame('hello', $v->asText());
    }

    public function testValueFromPartsFloat(): void
    {
        $v = Binding::valueFromParts(Value::KIND_FLOAT, 0, '', 2.5);
        $this->assertSame(Value::KIND_FLOAT, $v->kind);
        $this->assertSame(2.5, $v->floatValue);
    }

    public function testValueFromPartsUnknownKindDefaultsToNull(): void
    {
        // Matches cabi/value.go's own goValueToPhp default case -- an
        // unrecognized kind tag (e.g. protocol drift) degrades to Null
        // rather than throwing, on both sides of the FFI boundary.
        $v = Binding::valueFromParts(99, 0, '', 0.0);
        $this->assertTrue($v->isNull());
    }

    /**
     * cBytes()/readVarBytes() are the only Binding helpers that touch
     * the real FFI boundary (both call Binding::get(), which loads
     * cabi/libmacula.so) -- this needs the .so built (`cd cabi && go
     * build -buildmode=c-shared -o libmacula.so .`) but no network at
     * all, same as identity generation. Round-trips raw bytes through
     * an actual allocated C buffer and back via FFI::string(), which is
     * exactly what every payload/args/body parameter does on its way
     * into a real CALL/PUBLISH/etc.
     */
    public function testCBytesRoundTripsThroughARealBuffer(): void
    {
        $raw = "macula\x00\x01\xff";
        $buf = Binding::cBytes($raw);
        $this->assertSame($raw, \FFI::string($buf, strlen($raw)));
    }

    public function testCBytesHandlesEmptyString(): void
    {
        $buf = Binding::cBytes('');
        $this->assertSame('', \FFI::string($buf, 0));
    }
}
