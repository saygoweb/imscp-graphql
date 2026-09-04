<?php
namespace iMSCP\Plugin\SGW_GraphQL\Schema;

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

/**
 * Type.field -> callable.
 *
 * Schema-first means the SDL is the source of truth and resolvers attach to it
 * by name. A field with no entry here falls back to reading the key of the same
 * name off the source array, which covers every plain data field.
 */
final class ResolverMap
{
    /** @var array<string, callable> */
    private $map;

    public function __construct(array $map)
    {
        foreach ($map as $key => $resolver) {
            if (!is_string($key) || substr_count($key, '.') !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'A resolver key must be "Type.field"; got "%s".', (string)$key
                ));
            }

            if (!is_callable($resolver)) {
                throw new InvalidArgumentException(sprintf(
                    'The resolver for "%s" is not callable.', $key
                ));
            }
        }

        $this->map = $map;
    }

    public function for(string $type, string $field): ?callable
    {
        return $this->map[$type . '.' . $field] ?? null;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->map);
    }
}
