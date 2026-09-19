<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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

use iMSCP\Plugin\SGW_GraphQL\Service\ApcuStore;

/**
 * APCu's contract, in one process's memory.
 *
 * The reference box loads the extension in every SAPI but leaves
 * `apc.enable_cli` at 0, so `apcu_enabled()` is false under PHPUnit and the
 * real store cannot be driven from a test at all (task 11, step 1). This
 * stands in for it, with the two behaviours the limiter depends on and which
 * were measured against APCu 5.1.28 rather than assumed:
 *
 *   - increment() creates an absent key at 1 and applies the TTL then;
 *   - a later increment of a live key leaves that TTL where it was, so a
 *     fixed window cannot be pushed forward by the traffic inside it.
 *
 * It keeps no clock of its own, so nothing here expires: a test that wants a
 * new window uses a new key, which is exactly what RateLimiter does when its
 * clock crosses a window boundary.
 */
final class InMemoryApcuStore extends ApcuStore
{
    /** @var array<string, int> */
    public $values = array();

    /** @var array<string, int> The TTL each key was created with. */
    public $ttls = array();

    /** @var bool */
    private $available;

    /** @var int|null Refuse from this call onwards; null never refuses. */
    private $refuseFrom;

    /** @var int */
    public $calls = 0;

    /**
     * @param bool     $available  What available() answers.
     * @param int|null $refuseFrom The 1-based call at which increment() starts
     *                             answering null, which is how a full cache
     *                             segment shows up.
     */
    public function __construct(bool $available = true, ?int $refuseFrom = null)
    {
        $this->available = $available;
        $this->refuseFrom = $refuseFrom;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function increment(string $name, int $ttlSeconds): ?int
    {
        $this->calls++;

        if ($this->refuseFrom !== null && $this->calls >= $this->refuseFrom) {
            return null;
        }

        if (!isset($this->values[$name])) {
            $this->values[$name] = 0;
            $this->ttls[$name] = $ttlSeconds;
        }

        return ++$this->values[$name];
    }
}
