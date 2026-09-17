<?php
namespace iMSCP\Plugin\SGW_GraphQL\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/** An object the caller has been shown to reach, and whose it is. */
final class Target
{
    /** @var GlobalId */
    private $id;

    /** @var int */
    private $ownerId;

    public function __construct(GlobalId $id, int $ownerId)
    {
        $this->id = $id;
        $this->ownerId = $ownerId;
    }

    public function getId(): GlobalId
    {
        return $this->id;
    }

    public function getTag(): string
    {
        return $this->id->getType();
    }

    /**
     * The integer key, or the string key for a string-keyed tag (FtpUser).
     *
     * @return int|string
     */
    public function getKey()
    {
        return NodeType::isStringKeyed($this->id->getType())
            ? $this->id->getKey()
            : $this->id->getId();
    }

    /** The owning customer's admin_id. */
    public function getOwnerId(): int
    {
        return $this->ownerId;
    }
}
