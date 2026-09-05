<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\KeyPair;
use PHPUnit\Framework\TestCase;

/**
 * Real FFI mechanics (loads cabi/libmacula.so, calls into real Go
 * identity.Generate()) but no network at all -- Ed25519 keygen and
 * S/Kademlia puzzle-hardening are entirely local. Needs the .so built
 * first: cd cabi && go build -buildmode=c-shared -o libmacula.so .
 */
final class KeyPairTest extends TestCase
{
    public function testGenerateProducesA32ByteNodeId(): void
    {
        $identity = KeyPair::generate();
        $this->assertSame(32, strlen($identity->nodeId()));
    }

    public function testTwoGeneratedIdentitiesAreDifferent(): void
    {
        $a = KeyPair::generate();
        $b = KeyPair::generate();
        $this->assertNotSame($a->nodeId(), $b->nodeId());
    }

    public function testFromSeedBytesReconstructsTheSameNodeId(): void
    {
        $original = KeyPair::generate();
        $seed = $original->privateBytes();

        $reconstructed = KeyPair::fromSeedBytes($seed);

        $this->assertSame($original->nodeId(), $reconstructed->nodeId());
    }

    public function testFromSeedBytesIsDeterministic(): void
    {
        $seed = KeyPair::generate()->privateBytes();

        $a = KeyPair::fromSeedBytes($seed);
        $b = KeyPair::fromSeedBytes($seed);

        $this->assertSame($a->nodeId(), $b->nodeId());
    }

    public function testFromSeedBytesRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyPair::fromSeedBytes('too short');
    }

    public function testFreeThenNodeIdThrows(): void
    {
        $identity = KeyPair::generate();
        $identity->free();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been freed');
        $identity->nodeId();
    }

    public function testFreeIsIdempotent(): void
    {
        $identity = KeyPair::generate();
        $identity->free();
        $identity->free(); // must not throw or double-free the underlying handle
        $this->addToAssertionCount(1);
    }

    /**
     * PHP's clone shallow-copies $handle into a second object before
     * __clone() runs. Without __clone() nulling that copy before
     * throwing, the doomed clone (never assigned to a variable, so
     * immediately destructed when the throw unwinds the expression)
     * would free the ORIGINAL's handle out from under it -- the
     * original's own later free() then double-frees an already-deleted
     * cgo.Handle, which panics on the Go side. cabi/main.go's
     * safeDeleteHandle recovers that panic so it no longer kills the
     * whole PHP process, but this PHP-side guard is what turns the
     * misuse into a normal catchable exception instead of a silent
     * handle mixup.
     */
    public function testCloneThrowsAndLeavesOriginalUsable(): void
    {
        $identity = KeyPair::generate();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be cloned');
        try {
            clone $identity;
        } finally {
            // Runs even though the expected exception is still pending --
            // proves the original survived the failed clone attempt.
            $this->assertSame(32, strlen($identity->nodeId()));
        }
    }

    /**
     * serialize()/unserialize() is a second door to the exact same bug
     * clone() guards against: it copies $handle by value into the
     * serialized string, and unserialize() would hand back a second
     * live object holding that same raw handle -- reachable via
     * ordinary PHP ($_SESSION, an object cache, a queue payload), no
     * reflection needed, and no clone() call anywhere in sight.
     */
    public function testSerializeThrows(): void
    {
        $identity = KeyPair::generate();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be serialized');
        serialize($identity);
    }

    /** A hand-crafted serialized blob must be rejected too, not just serialize() of a live instance. */
    public function testUnserializeThrows(): void
    {
        $blob = 'O:14:"Macula\KeyPair":1:{s:22:"' . "\0" . 'Macula\KeyPair' . "\0" . 'handle";i:1;}';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be unserialized');
        unserialize($blob);
    }
}
