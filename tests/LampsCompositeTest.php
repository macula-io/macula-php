<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\NodeKey;
use Macula\Profile;
use PHPUnit\Framework\TestCase;

/**
 * pq_hybrid is the LAMPS composite id-MLDSA87-RSA4096-PSS-SHA512
 * (draft-ietf-lamps-pq-composite-sigs), and this holds the SDK's public API to
 * the draft's own vector, the vector macula and macula-go check: the draft's
 * signature verifies and every alteration of it is refused; the draft's key,
 * loaded as a node key, carries the draft's public key and signs composites
 * that verify under it; and signatures cross both ways with macula 12.x
 * (tests/fixtures/macula_12_cross, written by scripts/cross-verify-macula.sh).
 * No station is needed.
 */
final class LampsCompositeTest extends TestCase
{
    /**
     * The draft's bytes as macula v12.7.0 carries them (test/fixtures/), pinned
     * by sha256 so a drifted copy fails here rather than passing on bytes
     * nobody else signed. The same sums macula-go pins.
     */
    private const PINNED = [
        'lamps_mldsa87_rsa4096_pss_sha512/m.bin' => 'ef537f25c895bfa782526529a9b63d97aa631564d5d789c2b765448c8635fb6c',
        'lamps_mldsa87_rsa4096_pss_sha512/pk.bin' => '88560e139b35d0738857f9c8e29bbcfb108e3539bd2bf6f4994bb4b34beb019d',
        'lamps_mldsa87_rsa4096_pss_sha512/sk.bin' => '0d4c65edb8735b5b677ea88050662406c7affd8e29ae27184726822a5ca889ce',
        'lamps_mldsa87_rsa4096_pss_sha512/s.bin' => '95e17c93e9c1d6b5c3c4bae9d8687cd1606e232dca0af38e437e7e2e16894303',
        'lamps_mldsa87_rsa4096_pss_sha512/s_with_context.bin' =>
            '7261d9aeaaee3eb2612bb868d00d8eb6e174717bc427e8e6fa24cb7a73dcdeec',
        'lamps_composite_zero_dropped/sig.bin' => '4e43a85def2b0acec014724d7d4b23ac86685ef35f28a9be85d9aff4a7cd30cd',
    ];

    /** The ML-DSA-87 half of a composite signature, in bytes. */
    private const MLDSA_SIGNATURE_BYTES = 4627;

    public function testTheFixturesAreTheBytesMaculaPins(): void
    {
        foreach (self::PINNED as $name => $sha256) {
            self::assertSame($sha256, hash('sha256', self::fixture($name)), $name);
        }
    }

    public function testTheDraftsSignatureVerifiesAndEveryAlterationIsRefused(): void
    {
        [$message, $public, $signature] = self::draft();
        self::assertTrue(NodeKey::verify($message, $signature, $public, Profile::PqHybrid));
        self::assertFalse(NodeKey::verify($message . "\x00", $signature, $public, Profile::PqHybrid));
        self::assertFalse(NodeKey::verify($message, self::flipped($signature, 10), $public, Profile::PqHybrid));
        self::assertFalse(NodeKey::verify($message, self::flipped($signature, self::MLDSA_SIGNATURE_BYTES + 10), $public,
            Profile::PqHybrid));
        self::assertFalse(NodeKey::verify($message, $signature, $public, Profile::PqPure));
    }

    /** Every Macula object signs with the empty context, so the draft's
     * signature made with one is refused. */
    public function testTheDraftsSignatureWithAContextIsRefused(): void
    {
        [$message, $public] = self::draft();
        $withContext = self::fixture('lamps_mldsa87_rsa4096_pss_sha512/s_with_context.bin');
        self::assertFalse(NodeKey::verify($message, $withContext, $public, Profile::PqHybrid));
    }

    /** A composite whose RSA-PSS half lost its leading zero byte: each half
     * verifies on its own, so only the composite's fixed length refuses it. */
    public function testAZeroDroppedCompositeIsRefused(): void
    {
        [$message, $public] = self::draft();
        $zeroDropped = self::fixture('lamps_composite_zero_dropped/sig.bin');
        self::assertSame(self::MLDSA_SIGNATURE_BYTES + 511, strlen($zeroDropped));
        self::assertFalse(NodeKey::verify($message, $zeroDropped, $public, Profile::PqHybrid));
    }

    /**
     * The draft's private key, the ML-DSA-87 seed followed by the DER
     * RSAPrivateKey, is a node key: saved in a key file it loads through
     * NodeKey::load as pq_hybrid, carries the draft's public key, and signs the
     * draft's message with a composite that verifies under the draft's public
     * key and is refused once altered.
     */
    public function testTheDraftsKeySignsThroughThePublicApiAndVerifiesUnderTheDraftsPublicKey(): void
    {
        [$message, $public] = self::draft();
        $dir = sys_get_temp_dir() . '/macula-php-lamps-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $path = "{$dir}/draft.key";
        try {
            file_put_contents($path, self::keyFile(self::fixture('lamps_mldsa87_rsa4096_pss_sha512/sk.bin'), $public));
            chmod($path, 0600);
            $key = NodeKey::load($path, Profile::PqHybrid);
            self::assertSame(Profile::PqHybrid, $key->profile());
            self::assertSame($public, $key->publicKey());
            $signature = $key->sign($message);
            self::assertSame(strlen(self::fixture('lamps_mldsa87_rsa4096_pss_sha512/s.bin')), strlen($signature));
            self::assertTrue(NodeKey::verify($message, $signature, $public, Profile::PqHybrid));
            self::assertFalse(NodeKey::verify($message, self::flipped($signature, self::MLDSA_SIGNATURE_BYTES + 10),
                $public, Profile::PqHybrid));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testAGeneratedHybridKeysSignatureVerifiesUnderItsPublicKeyOnly(): void
    {
        $key = NodeKey::generate(Profile::PqHybrid);
        $signature = $key->sign('a fact');
        self::assertTrue(NodeKey::verify('a fact', $signature, $key->publicKey(), Profile::PqHybrid));
        [, $draftPublic] = self::draft();
        self::assertFalse(NodeKey::verify('a fact', $signature, $draftPublic, Profile::PqHybrid));
    }

    /** A composite macula 12.x signed with a key of its own verifies here. */
    public function testASignatureMadeByMacula12Verifies(): void
    {
        [$message, $public, $signature] = self::cross('macula_signed');
        self::assertTrue(NodeKey::verify($message, $signature, $public, Profile::PqHybrid));
        self::assertFalse(NodeKey::verify($message, self::flipped($signature, self::MLDSA_SIGNATURE_BYTES + 10), $public,
            Profile::PqHybrid));
    }

    /** The composite this SDK signed and macula 12.x verified
     * (scripts/cross-verify-macula.sh) verifies here too. */
    public function testTheSignatureMacula12VerifiedVerifiesHere(): void
    {
        [$message, $public, $signature] = self::cross('php_signed');
        self::assertTrue(NodeKey::verify($message, $signature, $public, Profile::PqHybrid));
    }

    /** @return array{0: string, 1: string, 2: string} the draft's message, public key and signature */
    private static function draft(): array
    {
        return [
            self::fixture('lamps_mldsa87_rsa4096_pss_sha512/m.bin'),
            self::fixture('lamps_mldsa87_rsa4096_pss_sha512/pk.bin'),
            self::fixture('lamps_mldsa87_rsa4096_pss_sha512/s.bin'),
        ];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function cross(string $signer): array
    {
        return [
            self::fixture("macula_12_cross/{$signer}/m.bin"),
            self::fixture("macula_12_cross/{$signer}/pk.bin"),
            self::fixture("macula_12_cross/{$signer}/s.bin"),
        ];
    }

    /**
     * A pq_hybrid identity key file (macula-go's seed form): the magic, the
     * purpose (identity, 1), the profile (pq_hybrid, 2) and two halves, each its
     * algorithm tag and its public and private keys, four-byte big-endian
     * length-prefixed.
     */
    private static function keyFile(string $draftSk, string $draftPk): string
    {
        $half = static fn (int $tag, string $public, string $private): string =>
            chr($tag) . pack('N', strlen($public)) . $public . pack('N', strlen($private)) . $private;
        return "macula-node-key-seed-v1\x00" . "\x01\x02\x02"
            . $half(1, substr($draftPk, 0, 2592), substr($draftSk, 0, 32))
            . $half(2, substr($draftPk, 2592), substr($draftSk, 32));
    }

    private static function flipped(string $bytes, int $at): string
    {
        $bytes[$at] = chr(ord($bytes[$at]) ^ 1);
        return $bytes;
    }

    private static function fixture(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . "/fixtures/{$name}");
        self::assertNotFalse($bytes, "fixture {$name}");
        return $bytes;
    }
}
