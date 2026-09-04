<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;

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
 * One api_token row. Never carries the secret: only its hash was ever stored.
 */
final class Token
{
    /** @var array */
    private $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function getTokenId(): int
    {
        return (int)$this->row['token_id'];
    }

    public function getAdminId(): int
    {
        return (int)$this->row['admin_id'];
    }

    public function getName(): string
    {
        return (string)$this->row['name'];
    }

    public function getPrefix(): string
    {
        return (string)$this->row['token_prefix'];
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        $scopes = trim((string)$this->row['scopes']);

        return $scopes === '' ? array() : explode(',', $scopes);
    }

    public function getCreatedAt(): int
    {
        return (int)$this->row['created_at'];
    }

    public function getExpiresAt(): ?int
    {
        return $this->row['expires_at'] === null ? null : (int)$this->row['expires_at'];
    }

    public function getLastUsedAt(): ?int
    {
        return $this->row['last_used_at'] === null ? null : (int)$this->row['last_used_at'];
    }

    public function getLastUsedIp(): ?string
    {
        return $this->row['last_used_ip'];
    }

    public function getRevokedAt(): ?int
    {
        return $this->row['revoked_at'] === null ? null : (int)$this->row['revoked_at'];
    }

    public function getIpAllowlist(): ?string
    {
        return $this->row['ip_allowlist'];
    }
}
