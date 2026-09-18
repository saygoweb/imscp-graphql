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

use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;

class ResellerAccountTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function accounts(): Accounts
    {
        return new Accounts($this->db);
    }

    public function testAResellerReadsBackWithItsLimitsAndCounters(): void
    {
        $reseller = $this->accounts()->reseller($this->fixture->resellerId());

        self::assertSame($this->fixture->resellerId(), $reseller->getAdminId());
        self::assertNotSame('', $reseller->getUsername());
        self::assertSame(
            (int)$this->db->value('SELECT max_sub_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())),
            $reseller->maxOf('subdomains')
        );
        self::assertSame(
            (int)$this->db->value('SELECT current_sub_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())),
            $reseller->usedOf('subdomains')
        );
    }

    public function testTheIpListDropsItsTrailingSemicolon(): void
    {
        // M17: the panel stores "1;2;" - sorted, with a trailing separator.
        $this->db->execute(
            'UPDATE reseller_props SET reseller_ips = ? WHERE reseller_id = ?',
            array('1;7;', $this->fixture->resellerId())
        );

        $reseller = $this->accounts()->reseller($this->fixture->resellerId());

        self::assertSame(array(1, 7), $reseller->ipIds());
        self::assertTrue($reseller->hasIp(7));
        self::assertFalse($reseller->hasIp(2));
    }

    public function testAnEmptyIpListIsNoIpsRatherThanOneEmptyOne(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET reseller_ips = ? WHERE reseller_id = ?',
            array('', $this->fixture->resellerId())
        );

        self::assertSame(array(), $this->accounts()->reseller($this->fixture->resellerId())->ipIds());
    }

    public function testAnAccountThatIsNotAResellerIsNotFound(): void
    {
        try {
            $this->accounts()->reseller($this->fixture->customerId());
            self::fail('a customer is not a reseller');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testEveryAllowanceMapsToAColumnThatExists(): void
    {
        $reseller = $this->accounts()->reseller($this->fixture->resellerId());

        foreach (array_keys(\iMSCP\Plugin\SGW_GraphQL\Support\LimitRules::SERVICES) as $allowance) {
            self::assertIsInt($reseller->maxOf($allowance), $allowance);
            self::assertIsInt($reseller->usedOf($allowance), $allowance);
        }
    }
}
