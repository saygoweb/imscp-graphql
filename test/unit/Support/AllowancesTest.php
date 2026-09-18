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

use iMSCP\Plugin\SGW_GraphQL\Support\Allowances;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use PHPUnit\Framework\TestCase;

class AllowancesTest extends TestCase
{
    public function testAnInputBecomesTheColumnsTheDomainRowWants(): void
    {
        $allowances = Allowances::fromInput(array(
            'subdomains' => 5, 'domainAliases' => 3, 'mailAccounts' => 20, 'ftpUsers' => 10,
            'sqlDatabases' => 4, 'sqlUsers' => 4, 'traffic' => 10240 * 1048576, 'disk' => 5120 * 1048576,
            'mailQuota' => 104857600, 'php' => true, 'cgi' => false, 'customDns' => true,
            'externalMail' => false, 'backup' => array('DOMAIN'), 'phpEditor' => true
        ));

        $columns = $allowances->domainColumns();

        self::assertSame(5, $columns['domain_subd_limit']);
        self::assertSame(104857600, $columns['mail_quota']);
        self::assertSame('yes', $columns['domain_php'], 'M7: the domain row has no underscores');
        self::assertSame('no', $columns['domain_cgi']);
        self::assertSame('yes', $columns['domain_dns']);
        self::assertSame('dmn', $columns['allowbackup']);
        self::assertSame('yes', $columns['web_folder_protection'], 'the panel default is on');
        self::assertSame('yes', $columns['phpini_perm_system']);
    }

    /** A4: the props store MiB; the input is bytes, like every other BigInt. */
    public function testDiskAndTrafficAreBytesInAndMibInTheProps(): void
    {
        $allowances = Allowances::fromInput($this->complete());

        self::assertSame(10240, $allowances->storage('traffic'), 'the props field is MiB');
        self::assertSame(5120, $allowances->storage('disk'));
        // domain_traffic_limit/domain_disk_limit are MiB too - the panel's
        // own columns, unconverted; only the API boundary is bytes.
        self::assertSame(10240, $allowances->domainColumns()['domain_traffic_limit']);
        self::assertSame(5120, $allowances->domainColumns()['domain_disk_limit']);
    }

    public function testAWithheldOrUnlimitedDiskOrTrafficPassesThrough(): void
    {
        $allowances = Allowances::fromInput($this->complete(array('traffic' => -1, 'disk' => 0)));

        self::assertSame(-1, $allowances->storage('traffic'));
        self::assertSame(0, $allowances->storage('disk'));
    }

    public function testADiskOrTrafficLimitThatIsNotAWholeNumberOfMibIsRefused(): void
    {
        $this->expectRefusal('input.allowances.disk', function (): void {
            Allowances::fromInput($this->complete(array('disk' => 1048576 + 1)));
        });
        $this->expectRefusal('input.allowances.traffic', function (): void {
            Allowances::fromInput($this->complete(array('traffic' => 1048576 - 1)));
        });
    }

    public function testNoBackupTargetIsTheStringTheColumnExpects(): void
    {
        $columns = Allowances::fromInput($this->complete(array('backup' => array())))->domainColumns();

        self::assertSame('no', $columns['allowbackup']);
    }

    public function testAnUnknownBackupTargetIsRefused(): void
    {
        $this->expectRefusal('input.allowances.backup', function (): void {
            Allowances::fromInput($this->complete(array('backup' => array('TAPE'))));
        });
    }

    public function testALimitBelowMinusOneIsRefused(): void
    {
        $this->expectRefusal('input.allowances.subdomains', function (): void {
            Allowances::fromInput($this->complete(array('subdomains' => -2)));
        });
    }

    public function testANegativeMailQuotaIsRefused(): void
    {
        $this->expectRefusal('input.allowances.mailQuota', function (): void {
            Allowances::fromInput($this->complete(array('mailQuota' => -1)));
        });
    }

    /** A5: the panel's form requires every one of these; a create is not the edit page's partial update. */
    public function testACreateMustNameEveryAllowance(): void
    {
        foreach (array(
            'subdomains', 'domainAliases', 'mailAccounts', 'ftpUsers', 'sqlDatabases', 'sqlUsers',
            'traffic', 'disk', 'mailQuota'
        ) as $name) {
            $input = $this->complete();
            unset($input[$name]);

            $this->expectRefusal('input.allowances.' . $name, function () use ($input): void {
                Allowances::fromInput($input);
            });
        }
    }

    /** An explicit null is not a value either. */
    public function testANullAllowanceIsRefusedTheSameWayAMissingOneIs(): void
    {
        $this->expectRefusal('input.allowances.subdomains', function (): void {
            Allowances::fromInput($this->complete(array('subdomains' => null)));
        });
    }

    // ---- A6: user_add2.php:454-473 - the mail quota inside the disk limit ----

    public function testAMailQuotaLargerThanAFiniteDiskLimitIsRefused(): void
    {
        $this->expectRefusal('input.allowances.mailQuota', function (): void {
            Allowances::fromInput($this->complete(array('disk' => 1048576, 'mailQuota' => 1048576 + 1)));
        });
    }

    public function testAnUnlimitedMailQuotaAgainstAFiniteDiskLimitIsRefused(): void
    {
        $this->expectRefusal('input.allowances.mailQuota', function (): void {
            Allowances::fromInput($this->complete(array('disk' => 1048576, 'mailQuota' => 0)));
        });
    }

    public function testAMailQuotaNoLargerThanTheDiskLimitIsAccepted(): void
    {
        $allowances = Allowances::fromInput($this->complete(array('disk' => 1048576, 'mailQuota' => 1048576)));

        self::assertSame(1048576, $allowances->storage('mailQuota'));
    }

    public function testAnUnlimitedDiskLimitNeedsNoMailQuotaCheck(): void
    {
        $allowances = Allowances::fromInput($this->complete(array('disk' => 0, 'mailQuota' => 0)));

        self::assertSame(0, $allowances->storage('mailQuota'));
    }

    public function testAPlanAndAnInputProduceTheSameProps(): void
    {
        $props = '_yes_;_no_;5;3;20;10;4;4;10240;5120;_dmn_;_yes_;yes;no;no;no;no;0;0;0;0;0;_no_;_yes_;104857600';
        $fromPlan = Allowances::fromPlanProps(PlanProps::parse($props));

        self::assertSame($props, $fromPlan->toProps()->toString());
        self::assertSame($fromPlan->domainColumns(), Allowances::fromInput(array(
            'subdomains' => 5, 'domainAliases' => 3, 'mailAccounts' => 20, 'ftpUsers' => 10,
            'sqlDatabases' => 4, 'sqlUsers' => 4, 'traffic' => 10240 * 1048576, 'disk' => 5120 * 1048576,
            'mailQuota' => 104857600, 'php' => true, 'cgi' => false, 'customDns' => true,
            'externalMail' => false, 'backup' => array('DOMAIN'), 'phpEditor' => true
        ))->domainColumns());
    }

    /** A5: every allowance a create must name, complete and valid. */
    private function complete(array $overrides = array()): array
    {
        return $overrides + array(
            'subdomains' => 5, 'domainAliases' => 3, 'mailAccounts' => 20, 'ftpUsers' => 10,
            'sqlDatabases' => 4, 'sqlUsers' => 4, 'traffic' => 10240 * 1048576, 'disk' => 5120 * 1048576,
            'mailQuota' => 104857600
        );
    }

    private function expectRefusal(string $field, callable $call): void
    {
        try {
            $call();
            self::fail('expected a refusal on ' . $field);
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertSame($field, $e->getExtensions()['field'] ?? null);
        }
    }
}
