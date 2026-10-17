<?php

declare(strict_types=1);

namespace Macula\Tests;

use Macula\Binding;
use Macula\MaculaException;
use Macula\RefusedError;
use PHPUnit\Framework\TestCase;

/**
 * A station's verdict on a serve or subscribe (cabi/CONTRACT.md "Errors",
 * kind refused, since macula-go v0.27.0-rc.4) is a RefusedError naming the
 * station's reason, the registration and the station; any other refusal
 * names none of them.
 */
final class RefusedTest extends TestCase
{
    private static function error(string $json): MaculaException
    {
        return (new \ReflectionMethod(Binding::class, 'error'))->invoke(null, $json);
    }

    public function testARegistrationRefusalNamesItsReasonRegistrationAndStation(): void
    {
        $station = str_repeat('ab', 32);
        $e = self::error(json_encode(['kind' => 'refused', 'message' => 'm', 'reason' => 'too_many_providers',
            'registration' => 'advertise', 'station' => $station]));
        self::assertInstanceOf(RefusedError::class, $e);
        self::assertSame('refused', $e->kind);
        self::assertSame(['too_many_providers', 'advertise', $station], [$e->reason, $e->registration, $e->station]);
    }

    public function testNoStationAnsweringIsUnacknowledged(): void
    {
        $e = self::error(json_encode(['kind' => 'refused', 'message' => 'm', 'reason' => 'unacknowledged',
            'registration' => 'subscribe', 'station' => str_repeat('cd', 32)]));
        self::assertInstanceOf(RefusedError::class, $e);
        self::assertSame([RefusedError::REASON_UNACKNOWLEDGED, 'subscribe'], [$e->reason, $e->registration]);
    }

    public function testARefusalThatIsNotARegistrationsNamesNothing(): void
    {
        $e = self::error(json_encode(['kind' => 'refused', 'message' => 'm']));
        self::assertInstanceOf(RefusedError::class, $e);
        self::assertSame([null, null, null], [$e->reason, $e->registration, $e->station]);
    }
}
