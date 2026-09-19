<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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
use RuntimeException;

/**
 * Specification section 10.3's fixed-window counters: APCu where the box has
 * it, the `api_rate` table where it does not.
 *
 * **The database path is approximate, and says so.** One charge there is an
 * upsert followed by a read of the row it just wrote, and those are two
 * statements. Between them another request can increment the same row, so the
 * value read back can be any hit count between this request's own and the
 * final one - and because the comparison is `hits > limit`, the error is
 * always in the direction of counting *too few* refusals, never too many. The
 * worst case is that up to (concurrent requests - 1) extra requests get
 * through in a window. It does not drift beyond that: every charge is a real
 * `hits = hits + 1`, so nothing is ever lost, only read early.
 *
 * That is a deliberate trade and isApproximate() is how a caller finds out it
 * was made, so an audit row can record which counter answered rather than
 * implying both are the same thing. (An exact database counter is possible -
 * `ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)` and then
 * `LAST_INSERT_ID()`, one statement - but it reads the count back out of a
 * connection-scoped side effect of the write, and this plugin shares the
 * panel's connection with core helpers that issue their own inserts. The
 * approximation that is documented is preferable to the exactness that
 * depends on nobody else touching the handle in between.)
 *
 * **APCu is a cache, and a cache may evict a live counter.** `apcu_inc()` on a
 * key that has been evicted recreates it at 1 and reports success, which is
 * indistinguishable from a first hit - so under memory pressure a window can
 * restart mid-flight and a caller gets a fresh allowance it had already spent.
 * It cannot be detected from inside `apcu_inc()`, so it is not fixed here; it
 * is said out loud instead. Unlike the database path's under-count it is not
 * bounded by the number of concurrent requests. An installation that needs a
 * hard bound configures the database store, whose failure mode is the bounded
 * under-count described above (checkpoint D, D5).
 *
 * What this class will *not* do is stop limiting because a backend was
 * missing: an unusable APCu falls through to the database, a limiter built
 * with neither backend refuses to be constructed, and a database error
 * propagates. The only way to switch a bucket off is to configure a negative
 * limit, which is an operator saying so out loud.
 *
 * Fixed windows, not a sliding log: specification section 10.3 says "in a
 * fixed window", and a token bucket would need a second value per key and a
 * read-modify-write that APCu cannot make atomic.
 */
final class RateLimiter
{
    /** The buckets of specification section 10.3. */
    const BUCKET_QUERIES   = 'queries';
    const BUCKET_MUTATIONS = 'mutations';

    /**
     * `tokenIssue` is two limits over two window lengths - 5 a minute per
     * source address and 10 an hour per username - and they are two buckets
     * rather than one, because a bucket may only ever be charged with a
     * single window length. See charge().
     */
    const BUCKET_TOKEN_ISSUE_IP   = 'tokenIssueIp';
    const BUCKET_TOKEN_ISSUE_USER = 'tokenIssueUser';

    const WINDOW_MINUTE = 60;
    const WINDOW_HOUR   = 3600;

    /**
     * `api_rate`.`rate_key` is varchar(190). A longer key is hashed rather
     * than truncated: truncation would silently merge two callers' counters.
     */
    const MAX_KEY_LENGTH = 190;

    /**
     * Rows more than this many windows behind the current one are dead, and a
     * charge has a 1-in-PRUNE_ODDS chance of deleting them. Opportunistic so
     * that no cron is needed and no request pays for it twice.
     */
    const PRUNE_WINDOWS = 2;
    const PRUNE_ODDS    = 100;

    /** @var callable fn(): int the current Unix time */
    private $now;

    /** @var ApcuStore|null Null unless APCu is actually usable here. */
    private $apcu;

    /** @var Db|null */
    private $db;

    /** @var bool */
    private $approximate;

    /** @var array<string, int> bucket => the window length it was charged with */
    private $windows = array();

    /**
     * @param callable       $now  fn(): int. Injected so the unit suite can
     *                             drive a clock rather than sleep through a
     *                             real window.
     * @param ApcuStore|null $apcu The store, or null to skip APCu outright. A
     *                             store that reports itself unavailable is
     *                             treated exactly like null - see
     *                             ApcuStore::available(), which is the
     *                             difference between "the extension is
     *                             loaded" and "it will actually count".
     * @param Db|null        $db   The fallback counter.
     *
     * @throws InvalidArgumentException when neither backend can count. A
     *                                  limiter with nowhere to keep a number
     *                                  cannot limit anything, and one that
     *                                  was built anyway would allow every
     *                                  request for the life of the
     *                                  installation without a single line
     *                                  anywhere saying so. It is refused at
     *                                  construction, where the wiring is, and
     *                                  not at the first charge, where the
     *                                  stack trace would blame a request.
     */
    public function __construct(callable $now, ?ApcuStore $apcu, ?Db $db)
    {
        $this->now = $now;
        $this->apcu = $apcu !== null && $apcu->available() ? $apcu : null;
        $this->db = $db;

        if ($this->apcu === null && $this->db === null) {
            throw new InvalidArgumentException(
                'A RateLimiter needs APCu or a database to count in; it was given neither.'
            );
        }

        $this->approximate = $this->apcu === null;
    }

    /**
     * Whether the counter that answered is the approximate one.
     *
     * True from construction when APCu was unusable, and true from the moment
     * any charge in this request falls back to the database because APCu
     * refused one. It never goes back to false within a request: a caller
     * recording "exact" for a request that was partly counted in the database
     * would be recording something that is not so.
     */
    public function isApproximate(): bool
    {
        return $this->approximate;
    }

    /**
     * Charge one hit against a bucket.
     *
     * @param int $limit         The number of hits *allowed* in a window, not
     *                           the number at which one is refused: at limit 3
     *                           the third hit passes and the fourth does not.
     *                           Zero refuses everything. **Negative means
     *                           unlimited** - `rate_limit_queries` and its two
     *                           siblings are operator-facing keys in
     *                           config.php, and an operator who writes -1
     *                           there means "do not limit this", which is the
     *                           reading every other limit in this panel has.
     *                           Reading it as "refuse every request" would
     *                           take the endpoint off the air over a
     *                           configuration file. An unlimited bucket is not
     *                           counted at all, so it costs nothing.
     * @param int $windowSeconds The fixed window. A bucket must be charged
     *                           with one window length and only one: the
     *                           opportunistic prune deletes a bucket's rows
     *                           two windows behind the current one, so a
     *                           bucket charged with both 60 and 3600 would
     *                           have its live hourly rows deleted by its
     *                           minutely charges - a limiter switching itself
     *                           off, silently, under exactly the load it
     *                           exists for. A mismatch within one request is
     *                           refused here; across requests it is the
     *                           invariant the four BUCKET_ constants above
     *                           keep.
     *
     * @return int seconds the caller must wait, or 0 when the hit is allowed.
     *             Never negative and never longer than the window.
     *
     * @throws InvalidArgumentException on a window of less than a second, or a
     *                                  bucket charged with two window lengths.
     * @throws RuntimeException         when no counter could answer.
     */
    public function charge(string $bucket, string $key, int $limit, int $windowSeconds): int
    {
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException(sprintf(
                'A rate limit window must be at least one second; %d given for "%s".',
                $windowSeconds, $bucket
            ));
        }

        if (isset($this->windows[$bucket]) && $this->windows[$bucket] !== $windowSeconds) {
            throw new InvalidArgumentException(sprintf(
                'Bucket "%s" was charged with a %d second window and then a %d second one; '
                    . 'one bucket has one window length.',
                $bucket, $this->windows[$bucket], $windowSeconds
            ));
        }

        $this->windows[$bucket] = $windowSeconds;

        if ($limit < 0) {
            return 0;
        }

        // A clock before the epoch would make the modulo below negative, which
        // would put the window *ahead* of now and hand back a Retry-After
        // longer than the window itself. It cannot happen on a working box,
        // which is why it is answered here once rather than trusted.
        $now = max(0, (int)call_user_func($this->now));
        $window = $now - ($now % $windowSeconds);
        $hits = $this->increment($bucket, $this->boundedKey($key), $window, $windowSeconds);

        if ($hits <= $limit) {
            return 0;
        }

        // $now is inside [$window, $window + $windowSeconds), so this is
        // already inside [1, $windowSeconds]. Clamped anyway: a Retry-After of
        // zero or less tells a client to retry immediately, and one longer
        // than the window locks out a caller the limiter has already
        // forgotten about. Both are worse than the header being absent.
        return max(1, min($windowSeconds, ($window + $windowSeconds) - $now));
    }

    /**
     * The hit count after this request's hit.
     *
     * @throws RuntimeException
     */
    private function increment(string $bucket, string $key, int $window, int $windowSeconds): int
    {
        if ($this->apcu !== null) {
            $hits = $this->apcu->increment(
                $bucket . ':' . $key . ':' . $window, $windowSeconds
            );

            if ($hits !== null) {
                return $hits;
            }

            // APCu was usable when this object was built and has just refused
            // - a full cache segment, most likely. Treating that as "no hits"
            // would turn the limiter off for as long as the condition lasted.
            // The approximate counter is the honest answer where there is one.
            if ($this->db === null) {
                throw new RuntimeException(
                    'APCu refused to count and there is no database to fall back to; '
                        . 'the rate limit cannot be enforced.'
                );
            }

            $this->approximate = true;
        }

        return $this->incrementInDatabase($bucket, $key, $window, $windowSeconds);
    }

    /**
     * The approximate counter. See the class docblock for what "approximate"
     * means here, and in which direction.
     */
    private function incrementInDatabase(
        string $bucket, string $key, int $window, int $windowSeconds
    ): int {
        $this->db->execute(
            'INSERT INTO api_rate (bucket, rate_key, window_at, hits) VALUES (?, ?, ?, 1)'
                . ' ON DUPLICATE KEY UPDATE hits = hits + 1',
            array($bucket, $key, $window)
        );

        $hits = $this->db->value(
            'SELECT hits FROM api_rate WHERE bucket = ? AND rate_key = ? AND window_at = ?',
            array($bucket, $key, $window)
        );

        $this->prune($bucket, $window, $windowSeconds);

        // The row was written a statement ago, so a missing one means somebody
        // else's prune ran in between. That is this request's first hit as far
        // as anything can tell, and it is the count that under-states rather
        // than the one that over-states.
        return $hits === null ? 1 : (int)$hits;
    }

    /**
     * Drop this bucket's dead rows, about once in PRUNE_ODDS charges.
     *
     * Scoped to the bucket. The table holds windows of different lengths - a
     * minute for queries, an hour for the per-username token bucket - and an
     * unscoped `window_at < ?` computed from a 60 second window would delete
     * hourly rows that are still counting.
     */
    private function prune(string $bucket, int $window, int $windowSeconds): void
    {
        if (random_int(1, self::PRUNE_ODDS) !== 1) {
            return;
        }

        $this->db->execute(
            'DELETE FROM api_rate WHERE bucket = ? AND window_at < ?',
            array($bucket, max(0, $window - (self::PRUNE_WINDOWS * $windowSeconds)))
        );
    }

    /**
     * A key `api_rate`.`rate_key` can hold whole.
     *
     * Nothing this plugin charges comes close - the longest is an IPv6 address
     * or a panel username - so this is a guard, not a code path. It hashes
     * rather than truncates because two keys cut to the same 190 characters
     * would share one counter, and sharing a counter between two callers is
     * the one thing a per-caller limit must not do.
     */
    private function boundedKey(string $key): string
    {
        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        return 'sha1:' . sha1($key);
    }
}
