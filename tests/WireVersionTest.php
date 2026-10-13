<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Binding;
use Macula\MaculaException;
use PHPUnit\Framework\TestCase;

/**
 * The load-time wire guard. Since ABI 1, libmacula reports the wire it speaks
 * (macula_wire_version), so a library from the same ABI but another wire is
 * refused when it is loaded, not after a call is already on the wire.
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
    }

    public function testTheLoadedLibrarySpeaksThisBindingsWire(): void
    {
        $wire = \FFI::string(Binding::ffi()->macula_wire_version());
        self::assertSame(Binding::WIRE_VERSION, $wire, 'the fetched libmacula is not the intended wire');
    }

    public function testAMatchingWireIsAccepted(): void
    {
        Binding::checkWireVersion(Binding::WIRE_VERSION, '/nonexistent');
        $this->addToAssertionCount(1);
    }

    public function testAnotherWireIsRefused(): void
    {
        $this->expectException(MaculaException::class);
        $this->expectExceptionMessageMatches('/speaks wire 0\.13; macula-php needs wire 1\.0/');
        Binding::checkWireVersion('0.13', '/nonexistent/libmacula.so');
    }
}
