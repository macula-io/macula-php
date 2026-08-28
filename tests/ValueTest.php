<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Value;
use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    public function testNull(): void
    {
        $v = Value::null();
        $this->assertSame(Value::KIND_NULL, $v->kind);
        $this->assertTrue($v->isNull());
    }

    public function testInt(): void
    {
        $v = Value::int(42);
        $this->assertSame(Value::KIND_INT, $v->kind);
        $this->assertSame(42, $v->intValue);
        $this->assertFalse($v->isNull());
    }

    public function testNegativeInt(): void
    {
        $v = Value::int(-7);
        $this->assertSame(-7, $v->intValue);
    }

    public function testBytes(): void
    {
        $raw = "\x00\x01\xff\xfe binary";
        $v = Value::bytes($raw);
        $this->assertSame(Value::KIND_BYTES, $v->kind);
        $this->assertSame($raw, $v->bytesValue);
    }

    public function testText(): void
    {
        $v = Value::text('hello, macula');
        $this->assertSame(Value::KIND_TEXT, $v->kind);
        $this->assertSame('hello, macula', $v->asText());
    }

    public function testFloat(): void
    {
        $v = Value::float(3.5);
        $this->assertSame(Value::KIND_FLOAT, $v->kind);
        $this->assertSame(3.5, $v->floatValue);
    }

    public function testDefaultFieldsAreZeroedForEachKind(): void
    {
        // Only the field matching `kind` is meaningful; the constructors
        // shouldn't leak a stale value into the others.
        $v = Value::int(9);
        $this->assertSame('', $v->bytesValue);
        $this->assertSame(0.0, $v->floatValue);
    }
}
