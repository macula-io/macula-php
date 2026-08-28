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
}
