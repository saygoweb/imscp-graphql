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
 * The scopes a token may carry. Scopes only ever narrow what the identity
 * could already do; they never widen it.
 */
final class Scope
{
    const ACCOUNT_READ    = 'ACCOUNT_READ';
    const DOMAINS_READ    = 'DOMAINS_READ';
    const DOMAINS_WRITE   = 'DOMAINS_WRITE';
    const MAIL_READ       = 'MAIL_READ';
    const MAIL_WRITE      = 'MAIL_WRITE';
    const FTP_READ        = 'FTP_READ';
    const FTP_WRITE       = 'FTP_WRITE';
    const SQL_READ        = 'SQL_READ';
    const SQL_WRITE       = 'SQL_WRITE';
    const DNS_READ        = 'DNS_READ';
    const DNS_WRITE       = 'DNS_WRITE';
    const CUSTOMERS_READ  = 'CUSTOMERS_READ';
    const CUSTOMERS_WRITE = 'CUSTOMERS_WRITE';
    const RESELLERS_READ  = 'RESELLERS_READ';
    const RESELLERS_WRITE = 'RESELLERS_WRITE';

    public static function all(): array
    {
        return array(
            self::ACCOUNT_READ,
            self::DOMAINS_READ, self::DOMAINS_WRITE,
            self::MAIL_READ, self::MAIL_WRITE,
            self::FTP_READ, self::FTP_WRITE,
            self::SQL_READ, self::SQL_WRITE,
            self::DNS_READ, self::DNS_WRITE,
            self::CUSTOMERS_READ, self::CUSTOMERS_WRITE,
            self::RESELLERS_READ, self::RESELLERS_WRITE
        );
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }
}
