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
 * What a service hands back: which object it wrote, and - when the object no
 * longer exists to be read back - what it looked like.
 *
 * A snapshot is only ever set for an object whose row the mutation removed
 * outright: an SQL database or user (spec section 2.1: synchronous), or an
 * alias order that never reached the backend. Everything else is still there
 * with a to... status, and is read back through the read model.
 */
final class ObjectRef
{
    /** @var string */
    private $tag;

    /** @var int|string */
    private $key;

    /** @var array|null */
    private $snapshot;

    /**
     * @param int|string $key
     * @param array|null $snapshot The row, in the shape the type's read-model
     *                             shape() function takes
     */
    public function __construct(string $tag, $key, ?array $snapshot = null)
    {
        $this->tag = $tag;
        $this->key = $key;
        $this->snapshot = $snapshot;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * @return int|string
     */
    public function getKey()
    {
        return $this->key;
    }

    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }
}
