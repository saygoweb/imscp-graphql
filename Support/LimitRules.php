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
        'sqlUsers'      => 'SQL users'
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
}
