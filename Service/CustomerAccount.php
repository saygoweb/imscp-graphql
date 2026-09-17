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

/**
 * The customer a write is for: their admin row's identity columns and their
 * whole `domain` row, read once per service call.
 *
 * Not the caller. A reseller creating a subdomain for a customer is checked
 * against the customer's limits, hashed into the customer's FTP group, and
 * logged as the reseller.
 */
final class CustomerAccount
{
    /** @var array<string, mixed> */
    private $admin;

    /** @var array<string, mixed> */
    private $domain;

    /**
     * @param array<string, mixed> $admin  admin_id, admin_name, created_by,
     *                                     email, admin_sys_uid, admin_sys_gid
     * @param array<string, mixed> $domain A whole row of `domain`
     */
    public function __construct(array $admin, array $domain)
    {
        $this->admin = $admin;
        $this->domain = $domain;
    }

    public function getAdminId(): int
    {
        return (int)$this->admin['admin_id'];
    }

    /** admin.admin_name: the customer's login, and their FTP group's name. */
    public function getUsername(): string
    {
        return (string)$this->admin['admin_name'];
    }

    /** admin.created_by, or 0 for an account no reseller created (spec section 7.5). */
    public function getResellerId(): int
    {
        return (int)($this->admin['created_by'] ?? 0);
    }

    public function getEmail(): string
    {
        return (string)($this->admin['email'] ?? '');
    }

    public function getSysUid(): int
    {
        return (int)$this->admin['admin_sys_uid'];
    }

    public function getSysGid(): int
    {
        return (int)$this->admin['admin_sys_gid'];
    }

    public function getDomainId(): int
    {
        return (int)$this->domain['domain_id'];
    }

    /** ASCII, as stored. */
    public function getDomainName(): string
    {
        return (string)$this->domain['domain_name'];
    }

    public function getDomainIpId(): int
    {
        return (int)$this->domain['domain_ip_id'];
    }

    /**
     * One column of the customer's `domain` row.
     *
     * @return mixed
     * @throws InvalidArgumentException for a column the row does not have, so
     *                                  a typo is loud rather than null
     */
    public function domain(string $column)
    {
        if (!array_key_exists($column, $this->domain)) {
            throw new InvalidArgumentException(sprintf('The domain row has no column "%s".', $column));
        }

        return $this->domain[$column];
    }

    /** @return array<string, mixed> */
    public function getDomainRow(): array
    {
        return $this->domain;
    }
}
