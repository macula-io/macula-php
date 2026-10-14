<?php

declare(strict_types=1);

namespace Macula;

/**
 * A sealed group refused a publish or a subscribe: `reason` as this binding
 * holds it, one of the closed set below, and the `prefix` of the group it was
 * about when macula knows it (a publish in the clear under a group this node
 * holds is REASON_HELD, and names its prefix).
 *
 * A join is refused, never silently downgraded: the publish or subscribe
 * fails with this and nothing is sent.
 *
 * The distributor's own vocabulary is not this set. It refuses a pull with a
 * `handler_error` whose detail names one of not_a_member, membership_unknown,
 * unknown_epoch, epoch_expired or unknown_group; the first four are held as
 * they are named, and `unknown_group`, like any failure the distributor does
 * not name (an unreachable one, one that refuses the caller's grant before
 * its handler, a reply that is not the protocol's), is held as
 * REASON_NO_DISTRIBUTOR.
 */
final class GroupError extends MaculaException
{
    /** A sealed event this node cannot open, because it is under no group. */
    public const REASON_NO_GROUP = 'no_group';
    /** An event sealed under a scheme this library does not know. */
    public const REASON_TAG_INVALID = 'tag_invalid';
    /** The distributor refuses this node the group's keys. */
    public const REASON_NOT_A_MEMBER = 'not_a_member';
    /** The distributor does not know whether this node is a member. */
    public const REASON_MEMBERSHIP_UNKNOWN = 'membership_unknown';
    /** The epoch asked for is not one the distributor hands out. */
    public const REASON_UNKNOWN_EPOCH = 'unknown_epoch';
    /** The epoch asked for is past its accept_until. */
    public const REASON_EPOCH_EXPIRED = 'epoch_expired';
    /** No trusted distributor served the group's procedure, or the one that
     * did failed in a way it did not name. */
    public const REASON_NO_DISTRIBUTOR = 'no_distributor';
    /** A publish in the clear under a prefix whose group this node holds:
     * refused rather than sent in the clear or sealed on the publisher's
     * behalf. `prefix` names it. */
    public const REASON_HELD = 'group_held';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $prefix,
        string $message,
    ) {
        parent::__construct($message === '' ? "macula-php: group: {$reason}" : $message, 'group');
    }
}
