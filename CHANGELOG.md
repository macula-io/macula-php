# Changelog

All notable changes to this project are documented in this file. Releases up
to v0.3.4 are described in their annotated git tag messages.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
