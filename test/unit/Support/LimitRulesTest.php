<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Unit\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use PHPUnit\Framework\TestCase;

class LimitRulesTest extends TestCase
{
    /** The panel's own order: domain_edit.php:1050 says not to change it. */
    public function testUnlimitedIsRefusedWhenTheResellerIsItselfLimited(): void
    {
        self::assertSame(
            'The subdomains limit for this customer cannot be unlimited because you are limited for this service.',
            LimitRules::reason(0, 0, 5, 10, 50, 'subdomains')
        );
        self::assertSame(
            'The subdomains limit for this customer cannot be unlimited because you are limited for this service.',
            LimitRules::reason(0, 0, 5, 10, -1, 'subdomains'),
            'a reseller withheld from the service is limited too'
        );
        self::assertNull(
            LimitRules::reason(0, 0, 5, 10, 0, 'subdomains'),
            'an unlimited reseller may give an unlimited customer'
        );
    }

    public function testWithholdingIsRefusedWhenTheCustomerAlreadyHasSome(): void
    {
        self::assertSame(
            "The subdomains limit for this customer cannot be set to 'disabled' because they already have 3 subdomains.",
            LimitRules::reason(-1, 3, 5, 10, 0, 'subdomains')
        );
        self::assertNull(LimitRules::reason(-1, 0, 5, 10, 0, 'subdomains'));
    }

    public function testTheNewLimitMayNotExceedWhatTheResellerHasLeftPlusWhatThisCustomerHolds(): void
    {
        // The reseller may sell 50 and has sold 10, of which this customer
        // holds 5: 50 - 10 + 5 = 45.
        self::assertNull(LimitRules::reason(45, 0, 5, 10, 50, 'subdomains'));
        self::assertSame(
            'The subdomains limit for this customer cannot be greater than 45, your calculated limit.',
            LimitRules::reason(46, 0, 5, 10, 50, 'subdomains')
        );
    }

    public function testAFiniteLimitMayNotBeBelowWhatTheCustomerAlreadyUses(): void
    {
        self::assertSame(
            'The subdomains limit for this customer cannot be lower than 7, the total they already use.',
            LimitRules::reason(5, 7, 10, 0, 0, 'subdomains')
        );
        self::assertNull(LimitRules::reason(7, 7, 10, 0, 0, 'subdomains'));
        self::assertNull(
            LimitRules::reason(0, 7, 10, 0, 0, 'subdomains'),
            'unlimited is not "below" anything'
        );
    }

    public function testTheOrderOfTheTestsIsTheOrderTheyAreReported(): void
    {
        // Unlimited asked of a limited reseller, and the customer already has
        // more than the limit asked for: the panel reports the first.
        self::assertSame(
            'The subdomains limit for this customer cannot be unlimited because you are limited for this service.',
            LimitRules::reason(0, 99, 5, 10, 50, 'subdomains')
        );
    }

    public function testEveryAllowanceHasAName(): void
    {
        foreach (array('subdomains', 'domainAliases', 'mailAccounts', 'ftpUsers', 'sqlDatabases', 'sqlUsers') as $one) {
            self::assertArrayHasKey($one, LimitRules::SERVICES);
            self::assertNotSame('', LimitRules::SERVICES[$one]);
        }
    }

    public function testAnUnknownServiceIsARefusalToGuess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LimitRules::reason(1, 0, 0, 0, 0, 'wombats');
    }
}
