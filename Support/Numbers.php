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
 * Checkpoint C finding C4: `schema/schema.graphql:24` documents `BigInt` as
 * "serialised as a decimal string", and `SchemaFactory` builds the schema
 * through `BuildSchema` with no `parseValue` for it, so a conforming client's
 * string arrives in PHP exactly as sent - a bare `is_int()` refuses it. One
 * coercion, used everywhere a BigInt-typed input field is read
 * (`Service\ResellerService::allowancesFor()`,
 * `Support\Allowances::limitValue()` and its own `mailQuota()`), so the shape
 * a client is told to send is the shape every entry point accepts.
 */
final class Numbers
{
    /**
     * @param mixed $value
     * @return int|null null when $value is neither an int nor a string of
     *                   digits (an optional leading '-', for the -1 sentinel
     *                   a limit uses).
     */
    public static function coerceBigInt($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int)$value;
        }

        return null;
    }
}
