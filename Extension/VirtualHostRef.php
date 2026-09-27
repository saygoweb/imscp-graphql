<?php
namespace iMSCP\Plugin\SGW_GraphQL\Extension;

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
 * One virtual host - a Domain, Subdomain, DomainAlias or alias subdomain - in
 * the vocabulary i-MSCP's own tables and the plugins beside it use.
 *
 * getKind() and getKey() are the panel's (domain_type, domain_id) pair: 'dmn'
 * and a domain_id, 'sub' and a subdomain_id, 'als' and an alias_id, 'alssub'
 * and a subdomain_alias_id. A plugin that keys its own rows on that pair can
 * use the two values as they are.
 */
final class VirtualHostRef
{
    /** @var string */
    private $tag;

    /** @var string */
    private $kind;

    /** @var int */
    private $key;

    /** @var int */
    private $domainId;

    /** @var int */
    private $ownerId;

    /** @var string|null */
    private $status;

    public function __construct(
        string $tag, string $kind, int $key, int $domainId, int $ownerId, ?string $status
    ) {
        $this->tag = $tag;
        $this->kind = $kind;
        $this->key = $key;
        $this->domainId = $domainId;
        $this->ownerId = $ownerId;
        $this->status = $status;
    }

    /** The API's identifier tag: Domain, Subdomain, AliasSubdomain or DomainAlias. */
    public function getTag(): string
    {
        return $this->tag;
    }

    /** The panel's domain_type: dmn, sub, als or alssub. */
    public function getKind(): string
    {
        return $this->kind;
    }

    /** The panel's id for this vhost in its own table. */
    public function getKey(): int
    {
        return $this->key;
    }

    /** The main domain it belongs to: its own id for a Domain. */
    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /** The owning customer's admin_id. */
    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    /**
     * The vhost's own status column as read at the moment of a write, for
     * Guard::requireState(). Null on a reference taken from a query's source,
     * which was read whenever the document happened to load it and is no
     * basis for a write decision.
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }
}
