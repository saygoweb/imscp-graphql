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

use iMSCP\Plugin\SGW_GraphQL\Service\CustomerService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class ApiAccessTest extends ServiceTestCase
{
    public function testWithdrawingAccessWritesThePermissionRow(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());

        (new CustomerService($this->kit))->setApiAccess($this->caller('reseller'), $id, false);

        self::assertSame(
            0,
            (int)$this->db->value('SELECT allowed FROM api_perm WHERE admin_id = ?', array($this->fixture->customerId()))
        );
    }

    public function testGrantingItBackUpdatesTheSameRow(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());
        $service = new CustomerService($this->kit);

        $service->setApiAccess($this->caller('reseller'), $id, false);
        $service->setApiAccess($this->caller('reseller'), $id, true);

        self::assertSame(
            1, (int)$this->db->value('SELECT COUNT(*) FROM api_perm WHERE admin_id = ?', array($this->fixture->customerId()))
        );
        self::assertSame(
            1,
            (int)$this->db->value('SELECT allowed FROM api_perm WHERE admin_id = ?', array($this->fixture->customerId()))
        );
    }

    public function testACustomerMayNotGrantItselfAccess(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            (new CustomerService($this->kit))->setApiAccess(
                $this->caller('customer'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), true
            );
        });
    }

    public function testAnotherResellersCustomerIsNotFound(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            (new CustomerService($this->kit))->setApiAccess(
                $this->caller('otherReseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), false
            );
        });
    }

    public function testTheChangeIsLogged(): void
    {
        (new CustomerService($this->kit))->setApiAccess(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), false
        );

        self::assertNotSame(array(), $this->core->callsNamed('writeLog'));
    }

    // ---- B14: the harness must give AccessService the contract Container does ----

    public function testIsCustomerOfRecognisesTheFixturesOwnCustomer(): void
    {
        self::assertTrue($this->kit->access()->isCustomerOf($this->fixture->resellerId(), $this->fixture->customerId()));
    }

    public function testIsCustomerOfRefusesAnotherResellersCustomer(): void
    {
        self::assertFalse(
            $this->kit->access()->isCustomerOf($this->fixture->otherResellerId(), $this->fixture->customerId())
        );
    }
}
