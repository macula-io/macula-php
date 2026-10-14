<?php

declare(strict_types=1);

namespace Macula;

/**
 * The sealed group a publish or subscribe is under (macula 13.2,
 * DESIGN_E2E_SEALED_PUBSUB.md): its `prefix`, which the topic must be under
 * and whose second `/`-segment is the org whose distributor holds the group's
 * keys; the org's `ucan`, a grant carrying `can` "group_keys", which the
 * group's key pulls are authorized with, the grant of the first join for a
 * prefix being the one the library holds; and the `distributor`'s node id,
 * when the group's keys must come from that node (a `~<node_id>` group needs
 * it).
 *
 * A group is a value: build it once and pass it to Pool::publishGroup and
 * Pool::subscribeGroup, which join it first (pulling the current epoch from
 * the distributor over a sealed call the grant authorizes) and then seal the
 * publish under, or open the subscription's events under, that epoch.
 *
 * The `ucan` is a bearer credential, so what this class can redact, it redacts:
 * `print_r`, `var_dump` (the object, and anything holding one), `debug_zval_dump`,
 * an exception trace that prints arguments, and `json_encode`, the last
 * including a Group nested in the context a logger encodes — Monolog's
 * normalizer, for instance, takes jsonSerialize. macula-py's Group and
 * macula-dotnet's UcanPresentation do the same for the same reason.
 *
 * What is NOT redacted, and cannot be, because a PHP class has no hook for
 * anything that reads the property table: `get_object_vars`, a `foreach` over
 * the object, an `(array)` cast, an IDE step debugger (DBGp reads the property
 * table), and Symfony's VarDumper, whose `dump()` and `dd()` start from an
 * `(array)` cast and add __debugInfo's keys alongside the real ones rather than
 * instead of them. Two of those matter in practice: PHPUnit's own exporter
 * reports a failed `assertEquals`/`assertSame` on a Group through `(array)`, so
 * a failing test prints the grant into the CI log, and `dump()` prints it to
 * the page or the console. Do not reach for those when what is wanted is
 * something to print.
 *
 * `serialize()` also writes it, which is a choice rather than a limit, since
 * __serialize() exists: a Group persisted to a queue or a cache is meant to
 * still work when it comes back, and that is worth more than hiding the grant
 * from the caller who asked to store it.
 */
final class Group implements \JsonSerializable
{
    /**
     * @param string $prefix the group's prefix, at least two `/`-segments
     *   with none empty, whose second segment is its org; a prefix that is
     *   not is refused by libmacula, on the publish or the subscribe, as a
     *   MaculaException of kind "invalid_argument"
     * @param string|null $ucan the org's grant, as the token text, or null
     *   when this node needs none
     * @param string|null $distributor the distributor's node id, 64 hex
     *   characters or 32 bytes, or null for whichever provider serves the
     *   org's `group_keys_v1`
     */
    public function __construct(
        public readonly string $prefix,
        public readonly ?string $ucan = null,
        public readonly ?string $distributor = null,
    ) {
        if ($prefix === '') {
            throw new \InvalidArgumentException('macula-php: a group names a prefix');
        }
        if ($ucan === '') {
            throw new \InvalidArgumentException('macula-php: a group\'s UCAN is never empty; leave it null for none');
        }
    }

    /** This group as the library takes it in options_json. */
    public function options(): array
    {
        $o = ['prefix' => $this->prefix];
        if ($this->ucan !== null) {
            $o['ucan'] = $this->ucan;
        }
        if ($this->distributor !== null) {
            $o['distributor'] = bin2hex(Wire::id32($this->distributor, "a group's distributor"));
        }
        return $o;
    }

    /**
     * What `print_r`, `var_dump`, `debug_zval_dump`, an exception trace and
     * every debugger that honours it show: the group, with its grant named as
     * present rather than printed. A grant is a bearer credential a distributor
     * accepts, so a debugging aid that showed it would hand out the group's keys
     * to whoever reads the output.
     *
     * This reaches the paths that honour __debugInfo. It does not reach the ones
     * that read the property table — an `(array)` cast, `get_object_vars`, a
     * `foreach`, an IDE step debugger, Symfony's VarDumper, and PHPUnit's own
     * exporter when a comparison fails. The class doc lists them; none is a
     * reason to print a Group.
     *
     * @return array{prefix: string, ucan: string|null, distributor: string|null}
     */
    public function __debugInfo(): array
    {
        return [
            'prefix' => $this->prefix,
            'ucan' => $this->ucan === null ? null : '(set)',
            'distributor' => $this->distributor,
        ];
    }

    /**
     * What `json_encode` shows, including when a Group sits inside the context
     * a logger encodes. The grant is redacted for the reason __debugInfo gives.
     *
     * @return array{prefix: string, ucan: string|null, distributor: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
