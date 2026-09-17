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
 * Every panel facility a service may use, and nothing else.
 *
 * Decision D10: a global panel function is called from the API path only if
 * it takes every identity explicitly, reports failure by value or exception,
 * cannot reach exit, and issues no DDL. PanelCore is the only implementation
 * that calls them, and CoreCallsTest holds the list.
 *
 * The first three methods are side effects a test must be able to observe
 * without performing (decision D12). The rest are the panel's own validators
 * and helpers, which tests run for real.
 */
interface Core
{
    /** EventAggregator::dispatch(), with the panel's own event name and parameters. */
    public function dispatch(string $event, array $params): void;

    /** send_request(): ask the daemon to process the queue. Never inside a transaction. */
    public function sendRequest(): void;

    /** write_log(), so API actions appear in the panel's log beside UI actions (spec section 11). */
    public function writeLog(string $message, int $level): void;

    /**
     * A panel configuration value, or $default when the key is absent.
     *
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null);

    /** encode_idna(). */
    public function toAscii(string $name): string;

    /** decode_idna(). */
    public function toUnicode(string $name): string;

    /** isValidDomainName(): null when valid, otherwise the panel's own reason. */
    public function domainNameError(string $name): ?string;

    /** chk_email() over a whole address. */
    public function isValidEmail(string $address): bool;

    /** chk_email() over a local part only. */
    public function isValidEmailLocalPart(string $localPart): bool;

    /** checkPasswordSyntax() with no page message: PASSWD_CHARS and PASSWD_STRONG. */
    public function isAcceptablePassword(string $password): bool;

    /** validates_username(). */
    public function isValidUsername(string $username): bool;

    /** The host rule of sql_user_add.php:174-180. */
    public function isValidSqlHost(string $asciiHost): bool;

    /** utils_normalizePath(). */
    public function normalisePath(string $path): string;

    /** Crypt::sha512(): how the backend expects mail and FTP passwords (spec section 12). */
    public function hashPassword(string $password): string;

    /** imscp_domain_exists(): taken, or a subzone of another reseller's domain. */
    public function domainExists(string $name, int $resellerId): bool;

    /** createDefaultMailAccounts(). Writes rows; opens and nests its own transaction. */
    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void;

    /** The php_ini row a new subdomain or alias needs, through PhpEditor. */
    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void;

    /**
     * A forward URL as the pages normalise it (subdomain_add.php:341-379).
     *
     * @throws InvalidArgumentException carrying the reason
     */
    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string;

    /** alias_add.php's send_alias_order_email(), for an explicit customer. */
    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void;

    /** delete_autoreplies_log_entries(). */
    public function pruneAutoreplyLog(): void;
}
