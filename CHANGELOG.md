# Changelog

All notable changes to this project are documented in this file. Releases up
to v0.3.4 are described in their annotated git tag messages.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.0-rc.3] - 2026-10-14

On macula-go v0.27.0-rc.3's libmacula (was v0.27.0-rc.1), which carries the
group calls this binding declares. A pre-release: a default
`composer require macula-io/macula-php` keeps resolving 0.8.2.

### Added

- **Sealed groups, the member side** (`Group`, `publishGroup`,
  `subscribeGroup`). A group is a topic prefix whose second `/`-segment names
  an org; the org's distributor holds the group's epoch keys. A publish and a
  subscription under the prefix both join the group first, one sealed call to
  the distributor carrying the org's `group_keys` grant, and then seal under,
  or open under, the epoch it hands back. A refusal is a `GroupError` holding
  macula's own reason: `not_a_member`, `membership_unknown`, `unknown_epoch`,
  `epoch_expired`, or `no_distributor` when no distributor answered or the one
  that did failed in a way it did not name; an event that cannot be opened
  arrives once with `Event::$unopened` naming `no_group` or `tag_invalid`. A
  group held is not a downgrade: a clear publish under a prefix this node
  holds is refused `group_held` and nothing is sent. `Event` gained `sealed`,
  `sealKeyId` and `unopened`. This binding holds no epoch key and seals
  nothing of its own; `Group` carries a prefix, a grant and optionally the
  distributor's node id. #9.
- `scripts/interop/groups.sh` and `.github/workflows/sealed-interop.yml`: a
  PHP member and an Erlang member under one org's group, each opening the
  other's sealed event under one epoch, a removed node refused `not_a_member`
  and a node presenting another node's grant refused, on every pull request
  and main push, in both profiles.

### Changed

- The native library is built from macula-go v0.27.0-rc.3, and the in-process
  test stations are that tag's.

## [0.9.0-rc.2] - 2026-10-13

On macula-go v0.27.0-rc.1's libmacula, as 0.9.0-rc.1. A pre-release: a default
`composer require macula-io/macula-php` keeps resolving 0.8.2, which carries the
defect below; 0.9.0 is where it is fixed.

### Fixed

- **`Pool::status()` no longer throws on a pool with a link.** It passed the
  library's `0/1`, as everything on the wire is, straight into `LinkStatus`'s
  `bool` parameters, which is a `TypeError` under `declare(strict_types=1)`, so
  the first link ended the call. Nothing in this package called `status()`, so
  neither the suite nor CI ever reached the line, and it dates from 0.5.0: every
  release since carries it, the whole 0.8.x line included. The fix lands here,
  not there. Both fields are now read as `=== 1`, and a seed link comes back
  `direct: false`, as macula-go sets that only for a station the pool dials for
  a call. #5.

## [0.9.0-rc.1] - 2026-10-13

Wire 1.0, a release candidate: a pre-release stability version, so a default
`composer require macula-io/macula-php` keeps resolving 0.8.2 for the live
fleet until wire 1.0 ships there.

On macula-go v0.27.0-rc.1's libmacula (was v0.26.0), which speaks **wire 1.0**
(macula 15) and nothing older (macula-go#30): the wire 1.0 envelope, handshake
version 5 with session proof V2, records 1.0 and the versioned `_dht.*`
procedures. A pre-1.0 station does not accept it.

### Added

- **A load-time wire check.** libmacula now reports the wire it speaks
  (`macula_wire_version`, macula-go v0.27.0), and `Binding` refuses a library of
  another wire major (`Binding::WIRE_MAJOR`), as it already refused a wrong ABI
  at load; another minor is taken, since every wire 1.x peer shares the frozen
  core (cabi/CONTRACT.md). ABI stayed 1, so the ABI check alone would load a
  wire 0.x library and fail only after a frame reached the peer. A library from
  before macula-go v0.27.0 reports no wire at all, since it fails resolving
  `macula_wire_version`, and `Binding` refuses that too in its own words,
  naming the path and `composer build`, rather than letting FFI's error out.

### Changed

- The native library is built from macula-go v0.27.0-rc.1 (macula-go#30), and
  `abi/macula.h` is the tag's header. The in-process test stations are wire 1.0
  too, and every test passes on them.

## [0.8.2] - 2026-10-09

On macula-go v0.26.0's libmacula (was v0.23.0).

### Security

- **A UCAN whose issuer did:key is over 4,400 characters is refused at once**
  (macula#87, macula-go#27, #4). Base58 decodes in time quadratic in its
  length, ahead of the signature check; on v0.23.0 a gated procedure decoding
  a 300,000-character issuer outlasted a 5 s call. It is now refused
  `unauthorized` before the handler runs. The library also refuses, at every
  link of a chain, an `exp` more than ten years past now (macula#68, v0.25.0),
  and `NodeKey::ucan` will not mint one (v0.24.0).

## [0.8.1] - 2026-10-06

### Fixed

- Connections now negotiate SecP384r1MLKEM1024 with every station, on
  macula-go v0.23.0's libmacula (was v0.22.0). They used to land on
  SecP256r1MLKEM768: Go ignores the order of its curve preferences and offered
  SecP256r1MLKEM768 first, and a station takes the client's first group. A
  station that accepts only SecP256r1MLKEM768 now fails in the TLS handshake
  (macula-go#20). Closes #3.

## [0.8.0] - 2026-10-05

### Added

- UCANs (macula's D7): `NodeKey::ucan($audience, $caps, $expS, nbf:, nnc:,
  fct:, prf:)` mints a token for the node that will present it, and
  `Ucan::proofId($token)` names a parent in a delegated token's `prf`.
  `Pool::call` and `Pool::openStream` take `ucan:` and `proofs:` (the chain's
  parents); proofs without a token are refused before anything is sent.
- Gated serving: `Pool::serve` and `Pool::serveStream` take `policy:`, a
  `ServePolicy::ucanRequired($issuerNodeId)` or
  `ServePolicy::realmMemberRequired($keyId, $can)`. libmacula checks each call
  and open before it reaches PHP; a refused call is a `ProviderError` of code
  `unauthorized`.
- The seal report: `Pool::callReport(...)` returns a `ReportedCall` (`result`
  and `report`), and `Stream::report()` a caller stream's `SealReport`
  (`sealed`, `provider`, `sealKeyId`) once it settled.

## [0.7.0] - 2026-10-05

### Changed

- macula-php loads macula-go's **released** libmacula, the tag in
  `abi/MACULA_GO_REF`, instead of its own `cabi/` over macula-go v0.12.0,
  which is gone. `composer build` downloads the library for this platform,
  checks it against the release's `SHA256SUMS` and build attestation, and
  refuses a release whose `macula.h` is not `abi/macula.h`; `Binding` refuses
  a library of another ABI version. The test suite runs macula-go's own
  teststation at the same tag. This brings the macula 13 wire: handshake v5
  bound to the TLS session, and macula 13 advertisements that name a
  provider's KEM key, which v0.12.0 refused as malformed ("no trusted
  provider").
- Errors cross as libmacula's JSON errors: `MaculaException::$kind` is the
  library's error kind ("timeout", "not_found", "no_provider", "refused", ...),
  and `ProviderError`, `RelayError`, `NotSharedError` and
  `ContentUnavailableError` are thrown for their kinds as before.
- `NodeKey::loadOrCreate` is libmacula's: a key file that exists but cannot be
  read is an error, never a reason to make a new key.

### Added

- End-to-end sealing: `Pool::call` and `Pool::openStream` take
  `confidential: Confidentiality::Preferred|Required`, sealed whenever the
  provider's advertisement names a KEM key; `Required` fails with a
  `ConfidentialityError` (`reason`, `named`, `found`) rather than go in the
  clear. `Pool::serve` and `Pool::serveStream` take `confidential:` too (`Off`
  as well), and `Pool::connect` takes `kemAdvertise:` to name this node's KEM
  key in its advertisements. A served `Request` has `sealed`.

## [0.6.0] - 2026-09-26

### Added

- `NodeKey::verify($message, $signature, $publicKey, $profile)`: whether a
  signature is valid for a public key as carried, ML-DSA-87 in pq_pure and in
  pq_hybrid the LAMPS composite id-MLDSA87-RSA4096-PSS-SHA512 with the empty
  context, both halves verified (cabi `macula_verify`).
- `scripts/cross-verify-macula.sh`, which cross-verifies pq_hybrid composites
  both ways with macula 12.x.

### Verified

- pq_hybrid against `draft-ietf-lamps-pq-composite-sigs`' own vector, the one
  macula and macula-go check (`tests/LampsCompositeTest.php`): the draft's
  signature verifies through the public API, and an altered message, an
  altered ML-DSA-87 half, an altered RSA-PSS half, pq_pure, the draft's
  signature made with a context, and the composite whose RSA-PSS half lost its
  zero byte are each refused. The draft's private key, loaded with
  `NodeKey::load` as pq_hybrid, carries the draft's public key, and its
  signature over the draft's message verifies under the draft's public key.
- Cross-verified both ways with macula 12.7.0 (from hex, on OTP 28.4.3, in the
  image macula v12.7.0's own test job pins): macula verified a composite this
  SDK signed and refused it altered, and this SDK verifies a composite macula
  signed. Both are kept in `tests/fixtures/macula_12_cross/` and checked on
  every `composer test`.

## [0.5.0] - 2026-09-26

### Breaking

- The macula 12 wire, over macula-go v0.12.0's pool. Releases before 0.5.0
  speak the retired 10.x wire and cannot reach the current fleet. There is no
  compatibility layer.
- New identities: `NodeKey` (ML-DSA-87, `pq_pure`, or the LAMPS composite,
  `pq_hybrid`, the fleet's profile) replaces `KeyPair`. No Ed25519 identity
  carries over; anything that named an old node_id must be redone.
- `Pool` replaces `Session`: seeds are pinned by node_id, realm keys by
  `realmTrust`, and calls and streams reach a provider by direct dial.
  `callDirect` is `call`, `resolveDirect` is `providers`,
  `serveWaitForCall` is `Served::next`/`Served::handle`, `streamAccept` is
  `ServedStream::next`/`ServedStream::handle`, `putDirect`/`getDirect` are
  `shareContent`/`getContent`.
- Payloads are plain PHP values; `Value` is removed. A boolean anywhere in a
  payload is refused before it reaches the wire. Bytes go in as
  `Wire::bytes()` and come out as hex, or tagged with `BytesOutput::Tagged`.
- `Ucan` and `UcanPayload` are removed until macula 12's UCANs are
  implemented (macula-go#2).
- The C ABI in `cabi/` is rewritten on the macula-ts pool model. A
  subscription's events, a served procedure's calls and a streaming
  procedure's sessions wait in a bounded inbox until PHP takes them with
  `macula_subscription_next` or `macula_served_next`: PHP's FFI takes no
  callback on a Go thread.

### Added

- A node's own namespace, `Pool::ownProcedure()`: served and called with no
  org and no realm key.
- Node-served content (`shareContent`, `unshareContent`, `getContent`) and the
  DHT (`findRecord`, `findRecords`, `findRecordsByType`, `putRecord`).
- `composer build`, which builds `libmacula.so` and `build/teststation`.
- An offline suite against two in-process macula 12 stations
  (`cabi/cmd/teststation`), and a live suite against one real station
  (`tests/live/FleetTest.php`).

## [0.4.0] - 2026-09-11

### Breaking

- A provider serving with `Session::serveWaitForCallGated()` accepts a token
  only from the caller its audience names: that caller's 32-byte node id as
  lowercase hex, `bin2hex($caller->nodeId())`. Mint a token with
  `Ucan::create()` for the identity that will present it (macula-go v0.8.0).
- `Session::serveWaitForCall()` and `serveWaitForCallGated()` answer only
  CALLs signed by the caller they name; any other CALL gets no reply and never
  reaches PHP (macula-go v0.8.0).

### Changed

- macula-go v0.7.1 to v0.8.2.
- Direct dial treats every advertisement that verifies as a candidate.
  `resolveDirect()` and `resolveDirectWithCertChain()` ask the DHT again until
  one's station endpoint resolves or `$timeoutMs` passes. `callDirect()`,
  `callDirectWithUcan()` and `callDirectWithCertChain()` move on to the next
  candidate when a dial fails, and `getDirect()` when a fetch fails, within
  their `$timeoutMs`. When every candidate fails before the request is sent,
  the most recent candidate's failure is reported.
- `putDirect()`'s `$timeoutMs` bounds the station endpoint lookup as well as
  the dial.
- The gated examples mint each token for the identity that presents it, and
  `examples/13_direct_dial_ucan_gated_call.php` also shows a token minted for
  another caller being refused.

### Added

- `$timeoutMs` on `Session::resolveDirect()` and
  `resolveDirectWithCertChain()`, 10000 by default.
