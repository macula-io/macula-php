<?php

declare(strict_types=1);

namespace Macula\Tests\Live;

use Macula\NodeKey;
use Macula\Pool;
use Macula\Profile;
use Macula\RecordType;
use Macula\Seed;
use PHPUnit\Framework\TestCase;

/**
 * A live run against one macula 12 station: the pool connects pinned with a
 * key generated for the run and never saved, reads the DHT, calls
 * mcl-echo/echo by direct dial, and hears its own publication. Runs only in
 * the live suite (`composer test:live`), and needs every one of
 * MACULA_PHP_LIVE_SEED (host:port, [v6]:port for IPv6),
 * MACULA_PHP_LIVE_STATION_ID, MACULA_PHP_LIVE_REALM and
 * MACULA_PHP_LIVE_REALM_KEY: an unset one fails the run, naming it. It puts
 * nothing in the DHT, and publishes once.
 */
final class FleetTest extends TestCase
{
    private const VARIABLES = ['MACULA_PHP_LIVE_SEED', 'MACULA_PHP_LIVE_STATION_ID', 'MACULA_PHP_LIVE_REALM',
        'MACULA_PHP_LIVE_REALM_KEY'];

    private static Pool $pool;
    private static string $realm;

    public static function setUpBeforeClass(): void
    {
        $missing = array_values(array_filter(self::VARIABLES, fn ($v) => (getenv($v) ?: '') === ''));
        if ($missing !== []) {
            throw new \RuntimeException('live tests need ' . implode(', ', $missing));
        }
        $seed = getenv('MACULA_PHP_LIVE_SEED');
        if (preg_match('/^\\[?([^\\]]+?)\\]?:(\\d+)$/', $seed, $m) !== 1) {
            throw new \RuntimeException("MACULA_PHP_LIVE_SEED must be host:port, got {$seed}");
        }
        self::$realm = getenv('MACULA_PHP_LIVE_REALM');
        self::$pool = Pool::connect(NodeKey::generate(Profile::PqHybrid),
            [new Seed($m[1], (int) $m[2], getenv('MACULA_PHP_LIVE_STATION_ID'))],
            realmTrust: [self::$realm => getenv('MACULA_PHP_LIVE_REALM_KEY')], timeoutMs: 60_000);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pool->close();
    }

    public function testTheStationHoldsVerifiedNodeRecords(): void
    {
        self::assertNotEmpty(self::$pool->findRecordsByType(RecordType::NodeRecord, timeoutMs: 15_000)->records);
    }

    public function testMclEchoIsReachedByDirectDial(): void
    {
        self::assertSame('hello', self::$pool->call(self::$realm, 'mcl-echo/echo', 'hello', timeoutMs: 15_000));
    }

    public function testThePoolHearsItsOwnPublication(): void
    {
        $topic = 'mcl-php/live/check/publication_heard_v1/' . bin2hex(random_bytes(8));
        $sub = self::$pool->subscribe(self::$realm, $topic);
        usleep(300_000);
        self::$pool->publish(self::$realm, $topic, 'heard');
        $event = $sub->next(10_000);
        $sub->stop();
        self::assertSame('heard', $event?->payload);
    }
}
