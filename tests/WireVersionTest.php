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

    /**
     * A library that will not load at all is refused in this package's own
     * words, naming the path and the remedy, rather than letting FFI's error
     * out. The one to expect in the field is a release older than macula-go
     * v0.27.0, whose symbols predate macula_wire_version. Its own process:
     * Binding caches the loaded library, so this load has to be the first.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testALibraryThatWillNotLoadIsRefusedInThisPackagesWords(): void
    {
        $notALibrary = realpath(__DIR__ . '/../composer.json');
        self::assertIsString($notALibrary, 'composer.json is missing, so there is nothing to load as a library');
        putenv("MACULA_LIBRARY_PATH={$notALibrary}");

        try {
            Binding::ffi();
            self::fail('a file that is not a library loaded as one');
        } catch (MaculaException $e) {
            self::assertStringContainsString($notALibrary, $e->getMessage(), 'the refused path is not named');
            self::assertStringContainsString('composer build', $e->getMessage(), 'no remedy is given');
            self::assertInstanceOf(\FFI\Exception::class, $e->getPrevious(),
                'the FFI error itself is chained, so the reason it failed is still readable');
        } finally {
            putenv('MACULA_LIBRARY_PATH');
        }
    }

    public function testTheLoadedLibrarySpeaksThisBindingsWire(): void
    {
        $wire = Binding::ffi()->macula_wire_version();
        self::assertSame(Binding::WIRE_MAJOR, strtok($wire, '.'), 'the fetched libmacula is not the intended wire');
    }

    /**
     * macula_wire_version() is declared const, so FFI hands a NULL over as
     * null and not as a pointer to nothing. The guard is typed, so it has to
     * refuse that itself: a TypeError out of it would say nothing about the
     * library that answered.
     */
    public function testAWireTheLibraryDoesNotReportIsRefusedInThisPackagesWords(): void
    {
        $this->expectException(MaculaException::class);
        $this->expectExceptionMessageMatches('/returned no wire version/');
        Binding::checkWireVersion(null, '/nonexistent/libmacula.so');
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
