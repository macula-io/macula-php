# Signature fixtures

## `lamps_mldsa87_rsa4096_pss_sha512/`, `lamps_composite_zero_dropped/`

The LAMPS draft's own vector for id-MLDSA87-RSA4096-PSS-SHA512, the composite
pq_hybrid signs with, and a composite every stack must refuse, copied byte for
byte from macula v12.7.0's `test/fixtures/` (the same bytes macula-go carries).
The draft's are `src/testvectors.json` of
[lamps-wg/draft-composite-sigs](https://github.com/lamps-wg/draft-composite-sigs)
at commit `f0627ab34acfe1aee0abce4bee91ed2b577eab76`, the vectors of
`draft-ietf-lamps-pq-composite-sigs`; macula's README beside them says how they
were fetched and checked. `tests/LampsCompositeTest.php` pins each by sha256.

| File | Bytes | What it is |
|------|------:|------------|
| `m.bin` | 44 | the message |
| `ctx.bin` | 71 | the context `s_with_context.bin` was made with |
| `pk.bin` | 3,118 | ML-DSA-87 public key, then the DER `RSAPublicKey` |
| `sk.bin` | 2,380 | ML-DSA-87 seed (32 bytes), then the DER `RSAPrivateKey` |
| `s.bin` | 5,139 | signature over `m.bin` with the empty context |
| `s_with_context.bin` | 5,139 | signature over `m.bin` with `ctx.bin` |
| `lamps_composite_zero_dropped/sig.bin` | 5,138 | a composite by the draft's key whose RSA-PSS half lost its leading zero byte |

## `e2e_seal_v1_group_keys.json`

macula's group-key vectors (macula 13.2, `docs/design/DESIGN_E2E_SEALED_PUBSUB.md`),
copied byte for byte from macula-go at the tag `abi/MACULA_GO_REF` pins,
`group/testdata/e2e_seal_v1_group_keys.json` (v0.27.0-rc.3, sha256
`e2f9f9a35eab8931cae260301f0034fe5a9b6a6b6d5776b480f242b1b2a6d7a2`, which
`tests/GroupTest.php` pins). Its `generator` is
`scripts/generate-seal-group-keys-vectors.sh` there; its `spec`,
`test/vectors/E2E_SEAL_V1.md`; the same bytes macula carries.

The file is carried whole so the vectors are here rather than only at a tag, but
this SDK cannot re-derive them: it has no CBOR and no crypto of its own, the
CALL and the RESULT crossing as CBOR inside libmacula. What `GroupTest` reads
from it is what PHP is party to at all: the `refusals` list, which is the
distributor-side vocabulary the keyring maps into its own closed set, and the
epoch `id` and `key` of `payloads.result`, which its stand-in distributor hands
back so a sealed event carries the vector's own epoch. The byte-for-byte half is
libmacula's, at the pinned release whose own tests carry these vectors; the live
half is `scripts/interop/groups.sh` (the `sealed-interop` workflow), where a PHP
member and an Erlang member open each other's sealed event under one epoch from
macula's real `macula_group_keys` distributor.

## `macula_12_cross/`

Written by `scripts/cross-verify-macula.sh`. `php_signed/` is a composite this
SDK signed with a pq_hybrid key made for the run, which macula verified (and
refused altered); `macula_signed/` is one macula signed with a key of its own,
which this SDK verifies. Each holds `m.bin`, `pk.bin` (the key as carried) and
`s.bin`. The script prints the macula and OTP versions it ran.
