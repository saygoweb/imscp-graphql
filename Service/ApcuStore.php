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

/**
 * The one place the plugin calls APCu, so that the counter it backs can be
 * faked in a test and so that a box without the extension has exactly one file
 * to look at.
 *
 * Not final: `test/Double/InMemoryApcuStore.php` subclasses it to give the
 * unit suite the same contract without the extension.
 *
 * `function_exists('apcu_inc')` is *not* the question this class asks.
 * Measured on the reference box (task 11, step 1): the extension is loaded in
 * every SAPI, so `function_exists()` is true even on the CLI - but
 * `apc.enable_cli` is `0` there, so `apcu_enabled()` is false and every store
 * and increment silently does nothing while still reporting success. A limiter
 * built on that answer would count to one for ever and never refuse anybody,
 * which is the exact failure this plugin cannot have. `apcu_enabled()` is the
 * only honest question, and available() asks it.
 */
class ApcuStore
{
    /**
     * Whether this process can actually keep a counter in shared memory.
     */
    public function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled()
            && function_exists('apcu_inc');
    }

    /**
     * Add one to $name, creating it with a $ttlSeconds lifetime when absent.
     *
     * `apcu_inc()` is atomic across processes - that is the whole reason this
     * path exists - and its fourth argument (APCu >= 5.1.12; 5.1.28 measured
     * on the reference box) applies the TTL only on the creating call. Both
     * halves were measured rather than assumed: a second increment of a live
     * key leaves `ttl` and `creation_time` untouched, so a fixed window cannot
     * be pushed forward by traffic inside it.
     *
     * The third argument is APCu's success flag, not a "was it created" flag.
     *
     * Only ever called when available() is true.
     *
     * @return int|null the new value, or null when APCu refused - which the
     *                  caller must treat as "this store cannot count", never
     *                  as "no hits yet".
     */
    public function increment(string $name, int $ttlSeconds): ?int
    {
        $succeeded = false;
        $value = apcu_inc($name, 1, $succeeded, $ttlSeconds);

        return $succeeded && is_int($value) ? $value : null;
    }
}
