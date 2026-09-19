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

use LogicException;

/**
 * The Db::detached() precedent, for Core.
 *
 * Container::forTesting()'s previous default - a real PanelCore(false) -
 * still dispatched events to whatever listeners the test process happened to
 * have registered, wrote the panel's log (which may send mail), sent the
 * alias-order mail and pruned rows: every one of those is a side effect a
 * test that did not ask for a mutation should never be able to trigger by
 * accident. Constructing this class touches nothing, so the unit suite can
 * still build the whole resolver map with one; every method throws the
 * moment it is actually called.
 */
final class DetachedCore implements Core
{
    const MESSAGE = 'This Core is detached: a test that runs a mutation '
        . 'must pass a Core to Container::forTesting().';

    public function authenticate(string $username, string $password): ?array
    {
        throw new LogicException(self::MESSAGE);
    }

    public function dispatch(string $event, array $params): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function sendRequest(): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function writeLog(string $message, int $level): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function config(string $key, $default = null)
    {
        throw new LogicException(self::MESSAGE);
    }

    public function toAscii(string $name): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function toUnicode(string $name): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function domainNameError(string $name): ?string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function isValidEmail(string $address): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function isValidEmailLocalPart(string $localPart): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function isAcceptablePassword(string $password): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function isValidUsername(string $username): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function isValidSqlHost(string $asciiHost): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function normalisePath(string $path): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function hashPassword(string $password): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function hashAccountPassword(string $password): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function domainExists(string $name, int $resellerId): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void {
        throw new LogicException(self::MESSAGE);
    }

    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void {
        throw new LogicException(self::MESSAGE);
    }

    public function savePhpIniForNewDomain(int $resellerId, int $customerAdminId, int $domainId, array $values): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string
    {
        throw new LogicException(self::MESSAGE);
    }

    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function monthBounds(): array
    {
        throw new LogicException(self::MESSAGE);
    }

    public function syncMailboxQuota(int $domainId, int $bytes): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function updatePhpIniForDomain(int $customerAdminId, int $domainId, array $values): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function sendAccountCreatedEmail(
        int $createdBy, string $username, string $password, string $email,
        string $firstName, string $lastName, string $role
    ): bool {
        throw new LogicException(self::MESSAGE);
    }

    public function updateResellerCounters(int $resellerId): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function pruneAutoreplyLog(): void
    {
        throw new LogicException(self::MESSAGE);
    }

    public function deleteCustomer(int $customerAdminId): bool
    {
        throw new LogicException(self::MESSAGE);
    }

    public function deleteReseller(int $resellerAdminId): bool
    {
        throw new LogicException(self::MESSAGE);
    }
}
