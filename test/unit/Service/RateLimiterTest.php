<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

/**
 * i-MSCP SGW_GraphQL plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use InvalidArgumentException;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\ApcuStore;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\InMemoryApcuStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The `api_rate` upsert, in an array, with every statement recorded.
 *
 * It is not a general SQL engine: it understands the two statements
 * RateLimiter issues and nothing else, which is what makes a query this class
 * does not recognise a test failure rather than a silent zero.
 */
class CountingDb extends Db
{
    /** @var array<string, int> "bucket\0key\0window" => hits */
    public $rows = array();

    /** @var array<int, array{0: string, 1: array}> */
    public $log = array();

    public function __construct()
    {
        // No connection: both methods RateLimiter uses are overridden.
    }

    public function execute(string $sql, array $bind = array()): int
    {
        $this->log[] = array($sql, $bind);

        if (strpos($sql, 'INSERT INTO api_rate') === 0) {
            $key = implode("\0", array($bind[0], $bind[1], $bind[2]));
            $this->rows[$key] = ($this->rows[$key] ?? 0) + 1;

            return 1;
        }

        if (strpos($sql, 'DELETE FROM api_rate') === 0) {
            $deleted = 0;

            foreach (array_keys($this->rows) as $key) {
                list($bucket, , $window) = explode("\0", $key);

                if ($bucket === $bind[0] && (int)$window < (int)$bind[1]) {
                    unset($this->rows[$key]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        throw new RuntimeException('Unexpected statement: ' . $sql);
    }

    public function value(string $sql, array $bind = array())
    {
        $this->log[] = array($sql, $bind);

        if (strpos($sql, 'SELECT hits FROM api_rate') !== 0) {
            throw new RuntimeException('Unexpected statement: ' . $sql);
        }

        return $this->rows[implode("\0", array($bind[0], $bind[1], $bind[2]))] ?? null;
    }

    /** @return array<int, array{0: string, 1: array}> */
    public function statementsLike(string $needle): array
    {
        $found = array();

        foreach ($this->log as $entry) {
            if (strpos($entry[0], $needle) === 0) {
                $found[] = $entry;
            }
        }

        return $found;
    }
}

class RateLimiterTest extends TestCase
{
    /** @var int The clock both limiters read. */
    private $now = 1767225600;   // 2026-01-01T00:00:00Z, a whole minute

    /** @var InMemoryApcuStore */
    private $apcu;

    /** @var CountingDb */
    private $db;

    protected function setUp(): void
    {
        $this->now = 1767225600;
        $this->apcu = new InMemoryApcuStore();
        $this->db = new CountingDb();
    }

    /** The exact counter: APCu offered and usable. */
    private function limiter(): RateLimiter
    {
        return new RateLimiter(
            function () { return $this->now; }, $this->apcu, $this->db
        );
    }

    /** The approximate counter: no usable APCu, so the database answers. */
    private function databaseLimiter(): RateLimiter
    {
        return new RateLimiter(
            function () { return $this->now; }, new InMemoryApcuStore(false), $this->db
        );
    }

    /**
     * Every test of a counting rule runs against both backends. The fallback
     * exists precisely so that a box without APCu is still limited, and a
     * suite that only exercised the path this box happens to take would be
     * proving that for the wrong half of the installations.
     *
     * @return array<string, array{0: string}>
     */
    public function backends(): array
    {
        return array('apcu' => array('apcu'), 'database' => array('database'));
    }

    private function backend(string $which): RateLimiter
    {
        return $which === 'apcu' ? $this->limiter() : $this->databaseLimiter();
    }

    /**
     * @dataProvider backends
     */
    public function testTheFirstRequestInAWindowIsAllowed(string $backend): void
    {
        self::assertSame(
            0, $this->backend($backend)->charge('queries', 'token:1', 120, 60)
        );
    }

    /**
     * @dataProvider backends
     */
    public function testTheLimitIsTheNumberAllowedNotTheNumberRefused(string $backend): void
    {
        $limiter = $this->backend($backend);

        self::assertSame(0, $limiter->charge('queries', 'token:1', 3, 60));
        self::assertSame(0, $limiter->charge('queries', 'token:1', 3, 60));
        self::assertSame(0, $limiter->charge('queries', 'token:1', 3, 60));
        self::assertGreaterThan(0, $limiter->charge('queries', 'token:1', 3, 60));
    }

    /**
     * @dataProvider backends
     */
    public function testTheWaitIsTheTimeLeftInTheWindowNotTheWholeWindow(string $backend): void
    {
        $this->now += 10;
        $limiter = $this->backend($backend);

        $limiter->charge('queries', 'token:1', 3, 60);
        $limiter->charge('queries', 'token:1', 3, 60);
        $limiter->charge('queries', 'token:1', 3, 60);

        self::assertSame(50, $limiter->charge('queries', 'token:1', 3, 60));
    }

    /**
     * @dataProvider backends
     */
    public function testANewWindowStartsCleanly(string $backend): void
    {
        $limiter = $this->backend($backend);

        $limiter->charge('queries', 'token:1', 1, 60);
        self::assertGreaterThan(0, $limiter->charge('queries', 'token:1', 1, 60));

        $this->now += 60;

        self::assertSame(0, $limiter->charge('queries', 'token:1', 1, 60));
    }

    /**
     * @dataProvider backends
     */
    public function testTwoKeysInOneBucketDoNotShareACount(string $backend): void
    {
        $limiter = $this->backend($backend);

        $limiter->charge('queries', 'token:1', 1, 60);
        self::assertGreaterThan(0, $limiter->charge('queries', 'token:1', 1, 60));

        self::assertSame(0, $limiter->charge('queries', 'token:2', 1, 60));
    }

    /**
     * @dataProvider backends
     */
    public function testTwoBucketsWithOneKeyDoNotShareACount(string $backend): void
    {
        $limiter = $this->backend($backend);

        $limiter->charge('queries', 'token:1', 1, 60);
        self::assertGreaterThan(0, $limiter->charge('queries', 'token:1', 1, 60));

        self::assertSame(0, $limiter->charge('mutations', 'token:1', 1, 60));
    }

    /**
     * @dataProvider backends
     */
    public function testALimitOfZeroRefusesEverything(string $backend): void
    {
        self::assertGreaterThan(
            0, $this->backend($backend)->charge('queries', 'token:1', 0, 60)
        );
    }

    /**
     * @dataProvider backends
     */
    public function testANegativeLimitMeansUnlimited(string $backend): void
    {
        // The config keys are operator-facing: -1 in rate_limit_queries is an
        // operator turning the limit off, and reading it as "refuse all" would
        // take the endpoint down over a configuration file.
        $limiter = $this->backend($backend);

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(0, $limiter->charge('queries', 'token:1', -1, 60));
        }
    }

    public function testAnUnlimitedBucketIsNotCountedAtAll(): void
    {
        // Not merely allowed: never written. An unlimited bucket that still
        // wrote a row would make the cheapest configuration the most expensive
        // one.
        $this->databaseLimiter()->charge('queries', 'token:1', -1, 60);

        self::assertSame(array(), $this->db->log);
    }

    /**
     * @dataProvider backends
     */
    public function testTheWaitIsNeverNegativeAndNeverLongerThanTheWindow(string $backend): void
    {
        // Swept across every second of a window rather than spot-checked: the
        // arithmetic is a modulo, and the two ways it can go wrong - a wait of
        // zero, telling a refused client to retry at once, and a wait longer
        // than the window, locking out a caller the limiter has already
        // forgotten - both live at the edges.
        for ($second = 0; $second < 60; $second++) {
            $this->setUp();
            $this->now = 1767225600 + $second;
            $wait = $this->backend($backend)->charge('queries', 'token:1', 0, 60);

            self::assertGreaterThanOrEqual(1, $wait, 'at +' . $second);
            self::assertLessThanOrEqual(60, $wait, 'at +' . $second);
            self::assertSame(60 - $second, $wait, 'at +' . $second);
        }
    }

    public function testApcuIsExactAndTheDatabaseSaysItIsNot(): void
    {
        self::assertFalse($this->limiter()->isApproximate());
        self::assertTrue($this->databaseLimiter()->isApproximate());
    }

    public function testAnApcuStoreThatIsLoadedButDisabledIsNotUsed(): void
    {
        // The trap this whole design turns on, measured on the reference box:
        // the extension is loaded on the CLI but apc.enable_cli is 0, so
        // function_exists() says yes and nothing is actually stored. A limiter
        // that believed function_exists() would count to one for ever.
        $limiter = new RateLimiter(
            function () { return $this->now; }, new InMemoryApcuStore(false), $this->db
        );

        $limiter->charge('queries', 'token:1', 1, 60);

        self::assertTrue($limiter->isApproximate());
        self::assertNotSame(array(), $this->db->log, 'the database must have counted');
    }

    public function testApcuRefusingPartWayThroughFallsToTheDatabaseAndSaysSo(): void
    {
        // A full APCu segment must not read as "no hits yet". The count has to
        // carry on somewhere, and isApproximate() has to stop claiming the
        // exact counter answered.
        $limiter = new RateLimiter(
            function () { return $this->now; },
            new InMemoryApcuStore(true, 2),
            $this->db
        );

        self::assertSame(0, $limiter->charge('queries', 'token:1', 1, 60));
        self::assertFalse($limiter->isApproximate());

        // APCu refuses this one, so the database counts it - as its first hit,
        // which is the under-count the class docblock describes, not a pass.
        $limiter->charge('queries', 'token:1', 1, 60);

        self::assertTrue($limiter->isApproximate());
        self::assertNotSame(array(), $this->db->statementsLike('INSERT INTO api_rate'));
    }

    public function testALimiterWithNowhereToCountRefusesToBeBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RateLimiter(function () { return $this->now; }, new InMemoryApcuStore(false), null);
    }

    public function testApcuRefusingWithNoDatabaseIsAnErrorRatherThanAPass(): void
    {
        $limiter = new RateLimiter(
            function () { return $this->now; }, new InMemoryApcuStore(true, 1), null
        );

        $this->expectException(RuntimeException::class);

        $limiter->charge('queries', 'token:1', 1, 60);
    }

    public function testAWindowOfLessThanASecondIsRefused(): void
    {
        // Zero would be a modulo by zero, which is a warning and a false in
        // PHP 7.4 and a fatal in PHP 8 - either way a limiter that has stopped
        // limiting. It is a wiring error, so it is loud.
        $this->expectException(InvalidArgumentException::class);

        $this->limiter()->charge('queries', 'token:1', 1, 0);
    }

    public function testOneBucketMayNotBeChargedWithTwoWindowLengths(): void
    {
        // The prune deletes a bucket's rows two windows behind the current one.
        // A bucket charged with 60 and then 3600 would have its live hourly
        // rows deleted by its minutely charges: the limiter switching itself
        // off under exactly the load it exists for.
        $limiter = $this->limiter();
        $limiter->charge('tokenIssue', 'ip:203.0.113.7', 5, 60);

        $this->expectException(InvalidArgumentException::class);

        $limiter->charge('tokenIssue', 'user:alice', 10, 3600);
    }

    public function testTheApcuTtlIsTheWindowAndIsAppliedOnceOnly(): void
    {
        $limiter = $this->limiter();

        $limiter->charge('queries', 'token:1', 10, 60);
        $limiter->charge('queries', 'token:1', 10, 60);

        self::assertSame(array('queries:token:1:1767225600' => 60), $this->apcu->ttls);
        self::assertSame(array('queries:token:1:1767225600' => 2), $this->apcu->values);
    }

    public function testTheDatabaseRowIsKeyedByBucketKeyAndWindow(): void
    {
        $this->databaseLimiter()->charge('queries', 'token:1', 10, 60);

        $inserts = $this->db->statementsLike('INSERT INTO api_rate');

        self::assertCount(1, $inserts);
        self::assertSame(array('queries', 'token:1', 1767225600), $inserts[0][1]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE hits = hits + 1', $inserts[0][0]);
    }

    public function testThePruneIsScopedToTheBucketItWasChargedFor(): void
    {
        // An unscoped "window_at < ?" computed from a 60 second window deletes
        // live rows of the hourly bucket. Pruning is one charge in a hundred,
        // so this drives enough charges that not seeing one would be a
        // one-in-a-billion event rather than a flake.
        $limiter = $this->databaseLimiter();

        for ($i = 0; $i < 2000; $i++) {
            $limiter->charge('queries', 'token:1', 100000, 60);
        }

        $deletes = $this->db->statementsLike('DELETE FROM api_rate');

        self::assertNotSame(array(), $deletes, 'no prune ran in 2000 charges');

        foreach ($deletes as $delete) {
            self::assertStringContainsString('WHERE bucket = ? AND window_at < ?', $delete[0]);
            self::assertSame(array('queries', 1767225600 - 120), $delete[1]);
        }
    }

    public function testAKeyTooLongForTheColumnIsHashedRatherThanTruncated(): void
    {
        // Truncation would give two callers one counter, which is the one
        // thing a per-caller limit must not do.
        $limiter = $this->databaseLimiter();
        $prefix = str_repeat('k', 200);

        $limiter->charge('queries', $prefix . 'a', 1, 60);
        $limiter->charge('queries', $prefix . 'b', 1, 60);

        $inserts = $this->db->statementsLike('INSERT INTO api_rate');

        self::assertCount(2, $inserts);
        self::assertNotSame($inserts[0][1][1], $inserts[1][1][1]);

        foreach ($inserts as $insert) {
            self::assertLessThanOrEqual(190, strlen($insert[1][1]));
        }
    }

    public function testTheRealStoreAgreesWithApcuAboutWhetherItCanCount(): void
    {
        // Not a tautology: available() could have asked function_exists(),
        // which is true on this box's CLI while apcu_enabled() is false.
        self::assertSame(
            function_exists('apcu_enabled') && apcu_enabled(),
            (new ApcuStore())->available()
        );
    }
}
