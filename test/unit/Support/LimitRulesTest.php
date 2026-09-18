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

    // ---- createReason(): the create path's own rule (reseller_limits_check()) ----

    /** Every allowance createReason() reads, defaulted to an unlimited ask against an unlimited reseller. */
    private function fullWanted(array $overrides = array()): array
    {
        return $overrides + array(
            'subdomains' => 0, 'domainAliases' => 0, 'mailAccounts' => 0, 'ftpUsers' => 0,
            'sqlDatabases' => 0, 'sqlUsers' => 0, 'traffic' => 0, 'disk' => 0
        );
    }

    /** @return callable[] [$resellerMax, $resellerUsed], defaulting an unmentioned allowance to unlimited/0. */
    private function accessors(array $max, array $used): array
    {
        return array(
            function (string $allowance) use ($max): int {
                return $max[$allowance] ?? 0;
            },
            function (string $allowance) use ($used): int {
                return $used[$allowance] ?? 0;
            }
        );
    }

    public function testTheCustomerCountIsCheckedBeforeAnyService(): void
    {
        // Out of customers and out of subdomains: the panel reports the
        // customer count, because Reseller.php:91 runs before Reseller.php:101.
        list($max, $used) = $this->accessors(
            array('customers' => 5, 'subdomains' => 5), array('customers' => 5, 'subdomains' => 5)
        );

        self::assertSame(
            'You have reached your domains limit. You cannot add more domains.',
            LimitRules::createReason($this->fullWanted(array('subdomains' => 2)), $max, $used)
        );
    }

    public function testAWithheldAskSkipsTheWholeCheckForAnEscapableService(): void
    {
        // Reseller.php:101, 118, 167, 182: a limit of -1 is not measured at
        // all, however far over the reseller already is.
        foreach (array('subdomains', 'domainAliases', 'sqlDatabases', 'sqlUsers') as $service) {
            list($max, $used) = $this->accessors(array($service => 5), array($service => 10));

            self::assertNull(
                LimitRules::createReason($this->fullWanted(array($service => -1)), $max, $used),
                $service
            );
        }
    }

    public function testMailAndFtpHaveNoWithheldEscape(): void
    {
        // Reseller.php:133, 150: neither guard tests "!= -1", unlike every
        // other service, so a -1 ask is measured as -1, not skipped.
        foreach (array('mailAccounts' => 'mail accounts', 'ftpUsers' => 'FTP accounts') as $service => $name) {
            list($max, $used) = $this->accessors(array($service => 5), array($service => 10));

            self::assertSame(
                sprintf('You are exceeding your %s limit.', $name),
                LimitRules::createReason($this->fullWanted(array($service => -1)), $max, $used),
                $service
            );
        }
    }

    public function testDiskAndTrafficAreCheckedWithNoWithheldEscape(): void
    {
        // A1: disk and traffic were never measured at all before this fix.
        foreach (array('traffic' => 'monthly traffic', 'disk' => 'disk space') as $service => $name) {
            list($max, $used) = $this->accessors(array($service => 100), array($service => 60));

            self::assertSame(
                sprintf('You are exceeding your %s limit.', $name),
                LimitRules::createReason($this->fullWanted(array($service => 50)), $max, $used),
                $service . ': a plain overrun is caught'
            );

            // Reseller.php:207, 225: neither guard tests "!= -1" either, so a
            // reseller already over its own limit cannot be escaped by
            // withholding, unlike subdomains/aliases/SQL databases/SQL users.
            list($max, $used) = $this->accessors(array($service => 100), array($service => 105));

            self::assertSame(
                sprintf('You are exceeding your %s limit.', $name),
                LimitRules::createReason($this->fullWanted(array($service => -1)), $max, $used),
                $service . ': -1 is not escaped'
            );
        }
    }

    public function testAnUnlimitedAskIsRefusedWhenTheResellerIsLimited(): void
    {
        list($max, $used) = $this->accessors(array('mailAccounts' => 5), array('mailAccounts' => 0));

        self::assertSame(
            'You have a mail accounts limit. You cannot add a user with unlimited mail accounts.',
            LimitRules::createReason($this->fullWanted(array('mailAccounts' => 0)), $max, $used)
        );
    }

    public function testTheDomainAliasesExceedingMessageKeepsThePanelsOwnMisspelling(): void
    {
        list($max, $used) = $this->accessors(array('domainAliases' => 5), array('domainAliases' => 4));

        self::assertSame(
            'You are exceeding you domain aliases limit.',
            LimitRules::createReason($this->fullWanted(array('domainAliases' => 2)), $max, $used)
        );
    }

    public function testSqlUsersAreCrossCheckedAgainstSqlDatabases(): void
    {
        // Reseller.php:190-197: SQL users allowed, but SQL databases withheld.
        list($max, $used) = $this->accessors(array('sqlUsers' => 5), array('sqlUsers' => 0));

        self::assertSame(
            'You have disabled SQL databases for this user. You cannot have SQL users here.',
            LimitRules::createReason($this->fullWanted(array('sqlUsers' => 2, 'sqlDatabases' => -1)), $max, $used)
        );
    }

    public function testTheSqlDatabasesCrossCheckDoesNotRunWhenSqlUsersIsUnlimitedOrWithheld(): void
    {
        // The cross-check sits inside Reseller.php:182's own guard
        // ("$maxSqlUserLimit != 0 && $newSqlUserLimit != -1"): a reseller
        // unlimited for SQL users, or a caller who withheld SQL users
        // outright, never reaches it - whatever SQL databases asks for.
        list($max, $used) = $this->accessors(array(), array());

        self::assertNull(LimitRules::createReason(
            $this->fullWanted(array('sqlUsers' => 0, 'sqlDatabases' => -1)), $max, $used
        ), 'an unlimited reseller for SQL users never reaches the cross-check');

        list($max, $used) = $this->accessors(array('sqlUsers' => 5), array('sqlUsers' => 0));

        self::assertNull(LimitRules::createReason(
            $this->fullWanted(array('sqlUsers' => -1, 'sqlDatabases' => -1)), $max, $used
        ), 'SQL users withheld outright never reaches the cross-check either');
    }
}
