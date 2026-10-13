<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Binding;
use Macula\MaculaException;
use PHPUnit\Framework\TestCase;

/**
 * The load-time wire guard. Since ABI 1, libmacula reports the wire it speaks
 * (macula_wire_version), so a library from the same ABI but another wire is
 * refused when it is loaded, not after a call is already on the wire. Every
 * wire 1.x peer shares the frozen core, so another major is refused and
 * another minor is taken (cabi/CONTRACT.md, "Version").
 */
final class WireVersionTest extends TestCase
{
    public function testWireVersionMatchesTheHeadersMacro(): void
    {
        $header = file_get_contents(__DIR__ . '/../abi/macula.h');
        self::assertIsString($header);
        self::assertSame(1, preg_match('/^#define MACULA_WIRE_VERSION "([^"]+)"$/m', $header, $m),
            'abi/macula.h declares no MACULA_WIRE_VERSION');
        self::assertSame(Binding::WIRE_VERSION, $m[1], 'Binding::WIRE_VERSION drifted from abi/macula.h');
        self::assertSame(Binding::WIRE_MAJOR, strtok($m[1], '.'), 'the wire major drifted from abi/macula.h');
    }

    public function testTheLoadedLibrarySpeaksThisBindingsWire(): void
    {
        $wire = \FFI::string(Binding::ffi()->macula_wire_version());
        self::assertSame(Binding::WIRE_MAJOR, strtok($wire, '.'), 'the fetched libmacula is not the intended wire');
    }

    public function testThisWireAndAnotherMinorAreAccepted(): void
    {
        Binding::checkWireVersion('1.0', '/nonexistent');
        Binding::checkWireVersion('1.1', '/nonexistent');
        $this->addToAssertionCount(2);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('otherWires')]
    public function testAnotherMajorIsRefused(string $wire): void
    {
        $this->expectException(MaculaException::class);
        $this->expectExceptionMessageMatches('/speaks wire .*; macula-php needs wire 1\.x/');
        Binding::checkWireVersion($wire, '/nonexistent/libmacula.so');
    }

    public static function otherWires(): array
    {
        return [['2.0'], ['0.13'], ['0.9'], ['']];
    }
}
