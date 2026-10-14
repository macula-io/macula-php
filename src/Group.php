<?php

declare(strict_types=1);

namespace Macula;

/**
 * The sealed group a publish or subscribe is under (macula 13.2,
 * DESIGN_E2E_SEALED_PUBSUB.md): its `prefix`, which the topic must be under
 * and whose second `/`-segment is the org whose distributor holds the group's
 * keys; the org's `ucan`, a grant carrying `can` "group_keys", presented on
 * every key pull; and the `distributor`'s node id, when the group's keys must
 * come from that node (a `~<node_id>` group needs it).
 *
 * A group is a value: build it once and pass it to Pool::publishGroup and
 * Pool::subscribeGroup, which join it first (pulling the current epoch from
 * the distributor over a sealed call the grant authorizes) and then seal the
 * publish under, or open the subscription's events under, that epoch.
 */
final class Group
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
}
