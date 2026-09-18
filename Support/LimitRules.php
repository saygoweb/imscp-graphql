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

use InvalidArgumentException;

/**
 * Whether a reseller may give a customer a limit, measured against both
 * ledgers: what the customer already uses, and what the reseller has left.
 *
 * CORE-DEBT(C3): transcribed from gui/public/reseller/domain_edit.php:1045-1102
 *   isValidServiceLimit(). The four tests keep the page's order, which its own
 *   comment ("Please, don't change test order") says is load-bearing: a request
 *   that is wrong in two ways must be told the same thing the panel would tell
 *   it. Retire when CustomerService lands in core.
 *
 * The vocabulary is spec section 2.3's: -1 withheld, 0 unlimited, n = n.
 */
final class LimitRules
{
    const WITHHELD  = -1;
    const UNLIMITED = 0;

    /** The GraphQL allowance name => the noun the message uses. */
    const SERVICES = array(
        'subdomains'    => 'subdomains',
        'domainAliases' => 'domain aliases',
        'mailAccounts'  => 'mail accounts',
        'ftpUsers'      => 'FTP accounts',
        'sqlDatabases'  => 'SQL databases',
        'sqlUsers'      => 'SQL users',
        // Checkpoint B, B3: domain_edit.php:737,749 passes these same two
        // nouns to tr() for the traffic and disk checks it runs
        // unconditionally (never gated by a customer "-1" fallback, unlike
        // the six above) - reason() needs no other change to accept them.
        'traffic'       => 'traffic',
        'disk'          => 'disk space'
    );

    /**
     * @param int    $newLimit        what the caller is asking for
     * @param int    $customerUsed    how many the customer already has
     * @param int    $customerLimit   the customer's limit today
     * @param int    $resellerUsed    the reseller's current_* counter (M9)
     * @param int    $resellerLimit   the reseller's max_* counter
     * @param string $service         a key of self::SERVICES
     * @return string|null            the refusal, or null when it is allowed
     * @throws InvalidArgumentException for an unknown service
     */
    public static function reason(
        int $newLimit, int $customerUsed, int $customerLimit,
        int $resellerUsed, int $resellerLimit, string $service
    ): ?string {
        if (!isset(self::SERVICES[$service])) {
            throw new InvalidArgumentException(sprintf('There is no "%s" allowance.', $service));
        }

        $name = self::SERVICES[$service];

        // 1. The page reads "($resellerLimit == -1 || $resellerLimit > 0)" as
        //    "the reseller is not unlimited", so a reseller withheld from the
        //    service cannot hand out an unlimited one either.
        if (($resellerLimit === self::WITHHELD || $resellerLimit > 0) && $newLimit === self::UNLIMITED) {
            return sprintf(
                'The %s limit for this customer cannot be unlimited because you are limited for this service.', $name
            );
        }

        if ($newLimit === self::WITHHELD && $customerUsed > 0) {
            return sprintf(
                "The %s limit for this customer cannot be set to 'disabled' because they already have %d %s.",
                $name, $customerUsed, $name
            );
        }

        if ($resellerLimit !== self::UNLIMITED
            && $newLimit > ($resellerLimit - $resellerUsed) + $customerLimit
        ) {
            return sprintf(
                'The %s limit for this customer cannot be greater than %d, your calculated limit.',
                $name, ($resellerLimit - $resellerUsed) + $customerLimit
            );
        }

        if ($newLimit !== self::WITHHELD && $newLimit !== self::UNLIMITED && $newLimit < $customerUsed) {
            return sprintf(
                'The %s limit for this customer cannot be lower than %d, the total they already use.',
                $name, $customerUsed
            );
        }

        return null;
    }

    /**
     * The create path's rule, which is not the edit path's.
     *
     * CORE-DEBT(C3): transcribed from gui/include/Reseller.php:40-243
     *   reseller_limits_check(), which user_add2.php and hosting_plan_add.php
     *   both call. Retire when CustomerService lands in core.
     *
     * @param array<string, int> $wanted   allowance name => the limit asked for,
     *                                     plus 'disk' and 'traffic'
     * @param callable $resellerMax        fn(string $allowance): int
     * @param callable $resellerUsed       fn(string $allowance): int
     * @return string|null the first refusal, in the panel's order, or null
     */
    public static function createReason(array $wanted, callable $resellerMax, callable $resellerUsed): ?string
    {
        // 1. Reseller.php:91 - the customer count, before any service.
        $maxCustomers = (int)$resellerMax('customers');

        if ($maxCustomers !== self::UNLIMITED && (int)$resellerUsed('customers') + 1 > $maxCustomers) {
            return 'You have reached your domains limit. You cannot add more domains.';
        }

        // 2. Reseller.php:101-131 - subdomains, then domain aliases. Both may
        // be withheld (-1), and the panel misspells "your" for aliases alone
        // (Reseller.php:128); transcribed as written.
        $reason = self::serviceReason($wanted, $resellerMax, $resellerUsed, 'subdomains', 'subdomains', true, null);

        if ($reason !== null) {
            return $reason;
        }

        $reason = self::serviceReason(
            $wanted, $resellerMax, $resellerUsed, 'domainAliases', 'domain aliases', true,
            'You are exceeding you domain aliases limit.'
        );

        if ($reason !== null) {
            return $reason;
        }

        // 3. Reseller.php:133-165 - mail accounts, then FTP accounts. Neither
        // has a "!= -1" guard in the source, so unlike every other service
        // here, a withheld (-1) ask is not escaped: it is measured as -1.
        $reason = self::serviceReason($wanted, $resellerMax, $resellerUsed, 'mailAccounts', 'mail accounts', false, null);

        if ($reason !== null) {
            return $reason;
        }

        $reason = self::serviceReason($wanted, $resellerMax, $resellerUsed, 'ftpUsers', 'FTP accounts', false, null);

        if ($reason !== null) {
            return $reason;
        }

        // 4. Reseller.php:167-180 - SQL databases, withholdable.
        $reason = self::serviceReason($wanted, $resellerMax, $resellerUsed, 'sqlDatabases', 'SQL databases', true, null);

        if ($reason !== null) {
            return $reason;
        }

        // 5. Reseller.php:182-205 - SQL users, plus the cross-check against
        // SQL databases.
        $reason = self::sqlUsersReason($wanted, $resellerMax, $resellerUsed);

        if ($reason !== null) {
            return $reason;
        }

        // 6. Reseller.php:207-241 - traffic, then disk. Neither is ever
        // withholdable: the source tests only "max != 0", never "!= -1".
        $reason = self::serviceReason($wanted, $resellerMax, $resellerUsed, 'traffic', 'monthly traffic', false, null);

        if ($reason !== null) {
            return $reason;
        }

        return self::serviceReason($wanted, $resellerMax, $resellerUsed, 'disk', 'disk space', false, null);
    }

    /**
     * The shape every plain service test in reseller_limits_check() shares:
     * skip when the reseller is unlimited for it, or (when $escapable) the
     * caller withheld it; otherwise refuse an unlimited ask, then refuse one
     * that overruns what is left.
     *
     * @param array<string, int> $wanted
     */
    private static function serviceReason(
        array $wanted, callable $resellerMax, callable $resellerUsed,
        string $service, string $name, bool $escapable, ?string $exceedingMessage
    ): ?string {
        $max = (int)$resellerMax($service);
        $new = (int)$wanted[$service];

        if ($max === self::UNLIMITED || ($escapable && $new === self::WITHHELD)) {
            return null;
        }

        if ($new === self::UNLIMITED) {
            return sprintf('You have a %s limit. You cannot add a user with unlimited %s.', $name, $name);
        }

        if ((int)$resellerUsed($service) + $new > $max) {
            return $exceedingMessage ?? sprintf('You are exceeding your %s limit.', $name);
        }

        return null;
    }

    /**
     * Reseller.php:182-205. SQL users share the plain shape, but with one more
     * test between "unlimited" and "exceeding": a caller who did not withhold
     * SQL users (above) but withheld SQL databases is refused. This sits
     * inside the same "$maxSqlUserLimit != 0 && $newSqlUserLimit != -1" guard
     * as the other two SQL-user tests - it does not run at all for a reseller
     * unlimited on SQL users, or a caller who withheld SQL users outright.
     *
     * @param array<string, int> $wanted
     */
    private static function sqlUsersReason(array $wanted, callable $resellerMax, callable $resellerUsed): ?string
    {
        $max = (int)$resellerMax('sqlUsers');
        $new = (int)$wanted['sqlUsers'];

        if ($max === self::UNLIMITED || $new === self::WITHHELD) {
            return null;
        }

        if ($new === self::UNLIMITED) {
            return 'You have a SQL users limit. You cannot add a user with unlimited SQL users.';
        }

        if ((int)$wanted['sqlDatabases'] === self::WITHHELD) {
            return 'You have disabled SQL databases for this user. You cannot have SQL users here.';
        }

        if ((int)$resellerUsed('sqlUsers') + $new > $max) {
            return 'You are exceeding your SQL users limit.';
        }

        return null;
    }
}
