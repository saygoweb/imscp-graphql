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
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1335, USA.
 */

use InvalidArgumentException;

/**
 * The account a request acts as, and the authority the presented credential
 * carries.
 *
 * Constructed once per request and threaded explicitly. The role is read from
 * the database on every request rather than carried in the token, so that a
 * demotion takes effect at once.
 */
final class Identity
{
    const ROLE_ADMIN    = 'ADMIN';
    const ROLE_RESELLER = 'RESELLER';
    const ROLE_CUSTOMER = 'CUSTOMER';

    const ROLE_BY_ADMIN_TYPE = array(
        'admin'    => self::ROLE_ADMIN,
        'reseller' => self::ROLE_RESELLER,
        'user'     => self::ROLE_CUSTOMER
    );

    /** @var int */
    private $adminId;

    /** @var string */
    private $username;

    /** @var string */
    private $adminType;

    /** @var int|null */
    private $createdBy;

    /** @var string|null */
    private $email;

    /** @var string[] */
    private $scopes;

    /** @var int|null */
    private $tokenId;

    public function __construct(
        int $adminId,
        string $username,
        string $adminType,
        ?int $createdBy,
        ?string $email,
        array $scopes,
        ?int $tokenId
    ) {
        if (!isset(self::ROLE_BY_ADMIN_TYPE[$adminType])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown admin_type "%s".', $adminType
            ));
        }

        $this->adminId = $adminId;
        $this->username = $username;
        $this->adminType = $adminType;
        $this->createdBy = $createdBy;
        $this->email = $email;
        $this->scopes = $scopes;
        $this->tokenId = $tokenId;
    }

    public function getAdminId(): int
    {
        return $this->adminId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getAdminType(): string
    {
        return $this->adminType;
    }

    public function getRole(): string
    {
        return self::ROLE_BY_ADMIN_TYPE[$this->adminType];
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getTokenId(): ?int
    {
        return $this->tokenId;
    }

    /**
     * Scopes only ever narrow. A credential that records none — a panel
     * session, for instance — is limited by its role alone.
     */
    public function hasScope(string $scope): bool
    {
        return $this->scopes === array() || in_array($scope, $this->scopes, true);
    }
}
