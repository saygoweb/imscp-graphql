<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

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

use GraphQL\Deferred;

/**
 * One query per edge per level, whatever the number of parents.
 *
 * Spec section 10.1 asks for the DataLoader pattern and names the edges:
 * customer->domain, domain->subdomains, domain->aliases, alias->subdomains,
 * domain->mail accounts, customer->ftp users, database->sql users, and the
 * reverse of each. GraphQL\Deferred supplies the timing - it queues a callback
 * that runs only when the executor can make no further progress, which is
 * exactly the end of a level - and each caller's loader supplies one
 * IN-clause query for every key asked for in that level.
 *
 * Plan 2 built this over Anorm's IN-clause loaders as well; plan 3 removed
 * Anorm (decision D17), and keyed() was already carrying every edge but one.
 */
final class BatchLoader
{
    /** @var Db */
    private $db;

    /**
     * Keys waiting on a keyed() load.
     *
     * bucket => array(stringified key => original key).
     *
     * @var array<string, array<string, mixed>>
     */
    private $pendingKeys = array();

    /** @var array<string, callable> bucket => loader */
    private $loaders = array();

    /**
     * Answers already loaded, kept for the life of the request - or until a
     * mutation resets them (decision D18).
     *
     * bucket => array(stringified key => value). A parent resolved at three
     * depths of one document is asked for once.
     *
     * @var array<string, array<string, mixed>>
     */
    private $results = array();

    /** @var int */
    private $flushes = 0;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @param string   $bucket One namespace per distinct query shape. Two
     *                         edges that share a bucket would hand each other's
     *                         answers back, so a bucket name carries the table
     *                         and the column, never just the column.
     * @param mixed    $key
     * @param callable $loader fn(array $keys): array - a key => value map.
     *                         A key the loader omits resolves to null.
     */
    public function keyed(string $bucket, $key, callable $loader): Deferred
    {
        if ($key === null) {
            // A null key matches nothing - SQL's IN (NULL) is never true - so
            // it is answered here instead of being enqueued. Enqueuing it
            // costs a round trip to learn nothing, and worse: the pending
            // entry's *value* is the key itself, so a null one is invisible to
            // an isset() guard, never flushes, and is dragged along by the
            // next flush of that bucket as a spurious NULL in the IN list.
            // domain.domain_ip_id is the caller that makes this reachable.
            return new Deferred(static function () {
                return null;
            });
        }

        $index = (string)$key;

        if (!isset($this->results[$bucket])
            || !array_key_exists($index, $this->results[$bucket])
        ) {
            $this->pendingKeys[$bucket][$index] = $key;
            $this->loaders[$bucket] = $loader;
        }

        return new Deferred(function () use ($bucket, $index) {
            // array_key_exists, not isset, for the same reason the memo check
            // above uses it: the guard asks "is this key still pending", and
            // isset() would answer no for a pending entry whose value is null.
            if (isset($this->pendingKeys[$bucket])
                && array_key_exists($index, $this->pendingKeys[$bucket])
            ) {
                $this->flushKeys($bucket);
            }

            return $this->results[$bucket][$index] ?? null;
        });
    }

    /**
     * How many batch queries have run. QueryCountTest measures the real count
     * with Db::countQueries(); this is the cheaper in-process view of the same
     * thing, for a unit test that has no database.
     */
    public function flushes(): int
    {
        return $this->flushes;
    }

    public function reset(): void
    {
        $this->pendingKeys = array();
        $this->loaders = array();
        $this->results = array();
        $this->flushes = 0;
    }

    private function flushKeys(string $bucket): void
    {
        $keys = array_values($this->pendingKeys[$bucket]);
        $loader = $this->loaders[$bucket];

        // Called before the pending list is cleared. GraphQL\Deferred catches
        // its executor's throw per promise, so a failed batch rejects only the
        // promise that happened to trigger the flush; clearing first would
        // leave every sibling finding nothing pending and falling through to
        // "no answer for this key" - null - so one dropped connection would
        // read as "this customer has nothing", inside a 200 response. Leaving
        // the keys pending makes the next sibling retry and fail the same way.
        $loaded = call_user_func($loader, $keys);

        unset($this->pendingKeys[$bucket], $this->loaders[$bucket]);

        if (!isset($this->results[$bucket])) {
            $this->results[$bucket] = array();
        }

        foreach ($loaded as $key => $value) {
            $this->results[$bucket][(string)$key] = $value;
        }

        // Every key that was asked for gets an entry, so a second ask for a
        // key with no rows does not run the query again.
        foreach ($keys as $key) {
            if (!array_key_exists((string)$key, $this->results[$bucket])) {
                $this->results[$bucket][(string)$key] = null;
            }
        }

        $this->flushes++;
    }
}
