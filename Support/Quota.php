<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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
 * One countable allowance, normalised.
 *
 * i-MSCP encodes a limit in three different ways depending on which table it
 * is in (spec section 2.3):
 *
 *   customer (domain.domain_*_limit)  -1 withheld,  0 unlimited,  n = n
 *   reseller (reseller_props.max_*)                 0 unlimited,  n = n
 *
 * Every client would otherwise have to know that, and any client that got it
 * wrong would either offer a feature the customer does not have or hide one
 * they do. Spec section 7.4 therefore has exactly one Quota type and this is
 * the only place the encoding is read.
 */
final class Quota
{
    /** @var bool */
    private $enabled;

    /** @var int|null Null means unlimited. */
    private $limit;

    /** @var int */
    private $used;

    private function __construct(bool $enabled, ?int $limit, int $used)
    {
        $this->enabled = $enabled;
        $this->limit = $limit;
        $this->used = $used;
    }

    public static function fromCustomerLimit(int $limit, int $used): self
    {
        if ($limit < 0) {
            // Withheld. Not unlimited, and not a limit of zero: the feature is
            // not available to this account at all.
            return new self(false, 0, $used);
        }

        return new self(true, $limit === 0 ? null : $limit, $used);
    }

    public static function fromResellerLimit(int $limit, int $used): self
    {
        if ($limit < 0) {
            // reseller_props has no withheld state, so this cannot occur in
            // sound data. Treating it as withheld is the safe direction.
            return new self(false, 0, $used);
        }

        return new self(true, $limit === 0 ? null : $limit, $used);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getUsed(): int
    {
        return $this->used;
    }

    /**
     * Null when unlimited. Never negative: a limit lowered below current usage
     * is ordinary in this panel, and a negative "remaining" reads as a bug to
     * every client that sees it.
     */
    public function getRemaining(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * @return array{enabled: bool, limit: int|null, used: int, remaining: int|null}
     */
    public function toArray(): array
    {
        return array(
            'enabled'   => $this->enabled,
            'limit'     => $this->limit,
            'used'      => $this->used,
            'remaining' => $this->getRemaining()
        );
    }
}
