<?php

declare(strict_types=1);

namespace Macula;

/**
 * The network refused it (kind "refused"). For a serve or subscribe (since
 * macula-go v0.27.0-rc.4, macula#12) it is the station's verdict: `reason` is
 * the station's (too_many_providers, advertiser_quota, name_too_long,
 * record_refused, not_authorized, too_many_realms, refused) or
 * REASON_UNACKNOWLEDGED when no station answered within 30 seconds,
 * `registration` is "advertise" or "subscribe", and `station` the station's
 * node_id as hex. Any other refusal (an admission, a key) has all three null.
 */
final class RefusedError extends MaculaException
{
    /** No station answered the registration within 30 seconds. */
    public const REASON_UNACKNOWLEDGED = 'unacknowledged';

    public function __construct(
        public readonly ?string $reason,
        public readonly ?string $registration,
        public readonly ?string $station,
        string $message,
    ) {
        parent::__construct($message === '' ? 'macula-php: refused: ' . ($reason ?? 'refused') : $message, 'refused');
    }
}
