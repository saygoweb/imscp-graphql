<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\HostingPlanService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;

class HostingPlanServiceTest extends ServiceTestCase
{
    private function service(): HostingPlanService
    {
        return new HostingPlanService($this->kit);
    }

    /**
     * Allowances::fromInput() takes disk, traffic and mailQuota as bytes
     * (D3); 1024 and 512 here are MiB-sized plan limits, so they are given as
     * whole numbers of MiB in bytes - 1048576 bytes each - the same unit
     * PlanProps::mibToBytes() converts back from. The mail quota must be a
     * positive value no larger than the disk limit once the disk limit is
     * finite (Allowances::fromInput()'s A6 cross-check), so it is 128 MiB
     * rather than the unlimited 0 a MiB-oblivious reading would suggest.
     */
    private function input(array $overrides = array()): array
    {
        return $overrides + array(
            'name' => 'sgwt-plan', 'description' => 'Written by a test', 'available' => true,
            'allowances' => array(
                'subdomains' => 2, 'domainAliases' => 1, 'mailAccounts' => 5, 'ftpUsers' => 2,
                'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024 * 1048576, 'disk' => 512 * 1048576,
                'mailQuota' => 128 * 1048576, 'php' => true, 'cgi' => false, 'customDns' => false,
                'externalMail' => false, 'backup' => array('DOMAIN'), 'phpEditor' => false
            )
        );
    }

    public function testAPlanIsStoredWithTheTwentyFiveFieldProps(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $row = $this->db->row('SELECT * FROM hosting_plans WHERE id = ?', array($ref->getKey()));
        self::assertSame('sgwt-plan', $row['name']);
        self::assertSame($this->fixture->resellerId(), (int)$row['reseller_id']);
        self::assertSame(1, (int)$row['status']);
        self::assertCount(25, explode(';', (string)$row['props']));
        self::assertSame(2, PlanProps::parse((string)$row['props'])->allowance('subdomains')['limit']);
    }

    public function testAPlanIsSynchronousAndPokesNoDaemon(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        self::assertSame(array(), $this->core->callsNamed('sendRequest'));
    }

    public function testTwoPlansOfTheSameNameForOneResellerIsAConflict(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input());
        });
    }

    public function testAPlanBeyondTheResellersOwnLimitsIsRefused(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET max_sub_cnt = 1, current_sub_cnt = 0 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('subdomains' => 5) + $this->input()['allowances']
            )));
        });
    }

    public function testAnUpdateReplacesThePropsWhole(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $this->service()->update($this->caller('reseller'), GlobalId::encode(NodeType::HOSTING_PLAN, $ref->getKey()), $this->input(array(
            'name' => 'sgwt-plan-2', 'available' => false,
            'allowances' => array('subdomains' => 9) + $this->input()['allowances']
        )));

        $row = $this->db->row('SELECT * FROM hosting_plans WHERE id = ?', array($ref->getKey()));
        self::assertSame('sgwt-plan-2', $row['name']);
        self::assertSame(0, (int)$row['status']);
        self::assertSame(9, PlanProps::parse((string)$row['props'])->allowance('subdomains')['limit']);
    }

    public function testAPlanIsDeletedOutright(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $this->service()->delete($this->caller('reseller'), GlobalId::encode(NodeType::HOSTING_PLAN, $ref->getKey()));

        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM hosting_plans WHERE id = ?', array($ref->getKey()))
        );
    }

    public function testAnotherResellersPlanIsNotFound(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $this->refused(ErrorCode::NOT_FOUND, function () use ($ref): void {
            $this->service()->delete(
                $this->caller('otherReseller'), GlobalId::encode(NodeType::HOSTING_PLAN, $ref->getKey())
            );
        });
    }

    public function testACustomerMayNotTouchPlansAtAll(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->create($this->caller('customer'), $this->input());
        });
    }
}
