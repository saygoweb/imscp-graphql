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

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;

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

    /**
     * Which scopes an account of this `admin_type` can hold at all.
     *
     * Identity::hasScope() reads an empty scope list as a full credential, so
     * a scope a role cannot use is not harmless clutter on a token - it is a
     * claim the token makes that nothing downstream re-checks against the
     * role. The role check is where it belongs: at the places a token is
     * minted, which are `tokenIssue` and Api\Executor::issueToken(). Both ask
     * here, so there is one rule and not two that could drift apart.
     *
     * An `admin_type` Identity does not know is read as a customer, the
     * narrowest role there is.
     *
     * @return string[]
     */
    public static function grantableTo(string $adminType): array
    {
        $role = Identity::ROLE_BY_ADMIN_TYPE[$adminType] ?? Identity::ROLE_CUSTOMER;

        if ($role === Identity::ROLE_ADMIN) {
            return self::all();
        }

        if ($role === Identity::ROLE_RESELLER) {
            // A reseller administers its own customers, not other resellers.
            return array_values(array_diff(
                self::all(),
                array(self::RESELLERS_READ, self::RESELLERS_WRITE)
            ));
        }

        // A customer administers nothing but its own account's objects.
        return array_values(array_diff(
            self::all(),
            array(
                self::CUSTOMERS_READ, self::CUSTOMERS_WRITE,
                self::RESELLERS_READ, self::RESELLERS_WRITE
            )
        ));
    }

    /**
     * The scopes a new token will carry: $asked, without repeats, once every
     * one of them has been shown to be something this role can hold.
     *
     * A scope the role could never hold is refused rather than dropped: a
     * client that asked for RESELLERS_WRITE and was quietly handed a token
     * without it would find out at the first request that needed it, and
     * would have no way to tell that from a revoked token. FORBIDDEN here
     * names the caller's own role, which it already knows. A string that is
     * not a scope at all is BAD_USER_INPUT, as TokenService::issue() answers
     * it - a GraphQL caller cannot send one past the enum, an in-process one
     * can.
     *
     * An empty list is refused too. Not clutter, and not a harmless default:
     * Identity::hasScope() reads a credential that records no scopes at all
     * as a full one (a token issued before scopes existed). A token minted
     * with an empty list would therefore be the widest token the account can
     * have, which is the opposite of what asking for nothing means.
     *
     * @param array<int, mixed> $asked
     * @param string            $field Where the error points: `input.scopes`
     *                                 for `tokenIssue`, `scopes` for a PHP
     *                                 caller.
     * @return string[]
     * @throws ApiException
     */
    public static function requireGrantable(
        string $adminType, array $asked, string $field = 'input.scopes'
    ): array {
        $available = self::grantableTo($adminType);
        $scopes = array();

        foreach ($asked as $scope) {
            $scope = (string)$scope;

            if (!self::isValid($scope)) {
                throw new ApiException(
                    ErrorCode::BAD_USER_INPUT,
                    sprintf('Unknown scope "%s".', $scope),
                    array('field' => $field)
                );
            }

            if (!in_array($scope, $available, true)) {
                throw new ApiException(
                    ErrorCode::FORBIDDEN,
                    'This account\'s role cannot hold the scope this token asks for.',
                    array('scope' => $scope)
                );
            }

            if (!in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        if ($scopes === array()) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT,
                'A token needs at least one scope.',
                array('field' => $field)
            );
        }

        return $scopes;
    }
}
