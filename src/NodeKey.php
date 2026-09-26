<?php

declare(strict_types=1);

namespace Macula;

/**
 * A node's identity key, as macula 12 has it: ML-DSA-87 (pq_pure) or the LAMPS
 * composite ML-DSA-87 + RSA-4096-PSS (pq_hybrid, the fleet's profile), whose
 * node_id solves the admission puzzle. Stored in a key file readable by its
 * owner only. The native key is freed with the object, or by free().
 */
final class NodeKey
{
    private function __construct(private ?int $handle)
    {
    }

    /** A new key whose node_id solves the admission puzzle; takes a second or so. */
    public static function generate(Profile $profile = Profile::PqHybrid): self
    {
        return new self(Binding::call(fn ($err) => Binding::ffi()->macula_key_generate($profile->value, $err)));
    }

    /** The key in the key file at path. A file its group or others can read,
     * or holding a key of another profile, is refused. */
    public static function load(string $path, Profile $profile = Profile::PqHybrid): self
    {
        return new self(Binding::call(fn ($err) => Binding::ffi()->macula_key_load($path, $profile->value, $err)));
    }

    /** The key at path, or a new one saved there when the file does not exist. */
    public static function loadOrCreate(string $path, Profile $profile = Profile::PqHybrid): self
    {
        if (file_exists($path)) {
            return self::load($path, $profile);
        }
        $key = self::generate($profile);
        $key->save($path);
        return $key;
    }

    /** Writes the key to path, readable by its owner only. */
    public function save(string $path): void
    {
        $h = $this->live();
        Binding::call(fn ($err) => Binding::ffi()->macula_key_save($h, $path, $err));
    }

    /** The key's 32-byte node_id. */
    public function nodeId(): string
    {
        return hex2bin($this->nodeIdHex());
    }

    /** The node_id as lowercase hex. */
    public function nodeIdHex(): string
    {
        $h = $this->live();
        return Binding::id32Out(fn ($out, $err) => Binding::ffi()->macula_key_node_id($h, $out, $err));
    }

    /** The public key as carried on the wire. */
    public function publicKey(): string
    {
        $h = $this->live();
        $n = Binding::size();
        $b = Binding::call(fn ($err) => Binding::ffi()->macula_key_public_key($h, \FFI::addr($n), $err));
        return Binding::takeBytes($b, $n->cdata);
    }

    /** The key's profile. */
    public function profile(): Profile
    {
        $h = $this->live();
        return Profile::from(Binding::takeString(Binding::call(fn ($err) => Binding::ffi()->macula_key_profile($h, $err))));
    }

    /** Signs data as given. */
    public function sign(string $data): string
    {
        $h = $this->live();
        $n = Binding::size();
        $buf = Binding::buffer($data);
        $b = Binding::call(fn ($err) => Binding::ffi()->macula_key_sign($h, $buf, strlen($data), \FFI::addr($n), $err));
        return Binding::takeBytes($b, $n->cdata);
    }

    /** Frees the native key. The NodeKey is unusable after. */
    public function free(): void
    {
        if ($this->handle !== null) {
            Binding::ffi()->macula_key_free($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->free();
    }

    /** @internal */
    public function live(): int
    {
        return $this->handle ?? throw new MaculaException('macula-php: this NodeKey was freed');
    }
}
