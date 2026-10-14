# Sealed groups across macula and macula-php

`groups.sh` runs macula's own distributor (`macula_group_keys`, policy
`required`) for the test station's org, in Erlang, admitted by the org's
delegation and advertised with its grant policy and `confidential => required`.
An Erlang member (`erlang_groups.escript member`) and a PHP member
(`phpgroups.php member`) each get the org's `group_keys` grant from the test
station (`grant <node_id>`), subscribe under the group, pulling its epoch over
a sealed call, and publish sealed under it; each must open the other's event,
under the same epoch. Then a PHP node the distributor's removed set names must
be refused `not_a_member`, and a PHP node presenting the grant the org minted
for another node must be refused. The Erlang legs run in macula's pinned CI
image.

This is the live half of macula-php#9. The offline half is `tests/GroupTest.php`,
where the distributor is stood in for by `tests/fixtures/provider.php` and the
group-key vectors pin what crosses the ABI. Together: the epochs are macula's,
the refusals are macula's, and this SDK carries both unchanged.

## Running

```sh
composer install && composer build   # libmacula for abi/MACULA_GO_REF, and build/teststation
MACULA_BUILD=<macula 15.0.0-rc.1 or later, compiled> scripts/interop/groups.sh pq_hybrid
MACULA_BUILD=<macula 15.0.0-rc.1 or later, compiled> scripts/interop/groups.sh pq_pure
```

`MACULA_BUILD` is a compiled macula tree; the Erlang legs load its modules from
a container, so it may be a rebar3 project that depends on macula from hex (a
directory holding `rebar.config`, as `scripts/cross-verify-macula.sh` takes).
The script runs macula-go's `build/teststation` for two in-process stations and
one realm, and needs `podman`, `python3` and the CI image it names.

## What each leg proves, and what PHP is party to

- **the members**: a PHP app's publish under a group is a macula event the
  Erlang member opens, and a macula event sealed under the group is one the PHP
  app opens. Both under the one epoch the distributor handed out, whose id is
  printed on both sides.
- **the removed node**: the distributor's own `not_a_member` reaches a PHP app
  as that reason, not as a generic failure. The handler refuses it after
  macula's gate admitted it, which is the handler path and not the gate's.
- **the impostor**: macula's gate refuses a caller whose UCAN was minted for
  another node, and the PHP app holds that refusal as this SDK's
  `no_distributor`, the same reason a caller sees when no distributor answers.
  The check joins with the grant first, from the node it was minted for, so the
  refusal that follows is the token's and not an unreachable distributor; and
  it checks from a second node, because a group already held is not pulled
  again.

Every seal, every open and every key pull happens inside libmacula. The PHP
side holds no epoch key, caches no key material and re-encodes no sealed
payload: what it is party to is the payload it publishes and the reason a
refusal is held as.

## The last run

2026-10-14, `macula-ci-otp@sha256:aff1d39b...`, macula 15.0.0-rc.2 from hex as
a rebar3 dependency: both profiles, both members opened the other's event under
one epoch, the removed node was refused `not_a_member`, the impostor was
refused `no_distributor`, exit 0.

`.github/workflows/sealed-interop.yml` runs both profiles on every PR and main
push, and records the log as an artifact.
