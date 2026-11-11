<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Binding;
use Macula\MaculaException;
use PHPUnit\Framework\TestCase;

/**
 * The load-time ABI guard, and the refusal that makes it matter. ABI 2 is the
 * ABI whose serve options must name a policy (cabi/CONTRACT.md, "Serving and
 * streams"), so a binding written for ABI 1 is refused when the library loads
 * rather than serving anyone in silence (macula-php#21, macula-go#57, #60).
 */
final class AbiVersionTest extends TestCase
{
    public function testTheAbiVersionMatchesTheHeadersMacro(): void
    {
        $header = file_get_contents(__DIR__ . '/../abi/macula.h');
        self::assertIsString($header);
        self::assertSame(1, preg_match('/^#define MACULA_ABI_VERSION (\d+)$/m', $header, $m),
            'abi/macula.h declares no MACULA_ABI_VERSION');
        self::assertSame(Binding::ABI_VERSION, (int) $m[1], 'Binding::ABI_VERSION drifted from abi/macula.h');
    }

    public function testTheLoadedLibrarySpeaksThisBindingsAbi(): void
    {
        self::assertSame(Binding::ABI_VERSION, Binding::ffi()->macula_abi_version(),
            'the fetched libmacula is not the ABI this binding is written against');
    }

    /**
     * The guard as ffi() runs it. Every other test loads the right library,
     * so deleting the check from ffi() leaves them all passing (M4b): this
     * one goes through ffi() and is told the opened library reports another
     * ABI. Its own process, since ffi() caches the library it loaded.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testFfiRefusesALibraryOfAnotherAbi(): void
    {
        $other = Binding::ABI_VERSION - 1;
        Binding::reportAbi($other);
        try {
            Binding::ffi();
            self::fail("ffi() accepted a library reporting ABI {$other}");
        } catch (MaculaException $e) {
            self::assertStringContainsString("is ABI {$other}", $e->getMessage(),
                'ffi() refused without naming the ABI the library reported');
            self::assertStringContainsString('macula-php needs ABI ' . Binding::ABI_VERSION, $e->getMessage(),
                'ffi() refused without naming the ABI this binding is written for');
        } finally {
            Binding::reportAbi(null);
        }
    }

    /**
     * The guard's own refusal, read directly: a library of another ABI is
     * refused by the version it has and the one needed, naming the path.
     */
    public function testAnotherAbiIsRefusedInThisPackagesWords(): void
    {
        $path = '/nonexistent/libmacula.so';
        $other = Binding::ABI_VERSION - 1;
        try {
            Binding::checkAbiVersion($other, $path);
            self::fail("an ABI {$other} library was accepted by an ABI " . Binding::ABI_VERSION . ' binding');
        } catch (MaculaException $e) {
            self::assertStringContainsString("libmacula at {$path} is ABI {$other}", $e->getMessage(),
                'the refusal names neither the path nor the ABI the library has');
            self::assertStringContainsString('macula-php needs ABI ' . Binding::ABI_VERSION, $e->getMessage(),
                'the refusal does not name the ABI this binding is written for');
        }
    }

    public function testThisAbiIsAccepted(): void
    {
        Binding::checkAbiVersion(Binding::ABI_VERSION, '/nonexistent/libmacula.so');
        $this->addToAssertionCount(1);
    }

    /**
     * The ABI's own refusal, through the binding's own handle: options are
     * judged before the pool handle is looked up, so no pool and no station is
     * needed to see that a serve naming no policy is refused. The byte string
     * is a realm the call never reaches, and the refusal names both fixes —
     * the open policy, which this package always sends, or the gated door.
     */
    public function testTheAbiRefusesServeOptionsThatNameNoPolicy(): void
    {
        $realm = Binding::buffer(str_repeat("\0", 32));
        try {
            Binding::call(fn ($err) => Binding::ffi()->macula_pool_serve_opts(0, $realm, 'acme/echo',
                '{"confidential":"preferred"}', $err));
            self::fail('the ABI served a procedure whose options named no policy');
        } catch (MaculaException $e) {
            self::assertSame('invalid_argument', $e->kind, 'a serve with no policy is not an invalid argument');
            self::assertStringContainsString('policy is required', $e->getMessage(), 'the refusal names no policy');
            self::assertStringContainsString('{"policy":{"kind":"open"}}', $e->getMessage(),
                'the refusal does not name the open policy');
            self::assertStringContainsString('macula_pool_serve_gated', $e->getMessage(),
                'the refusal does not name the gated door');
        }
    }
}
