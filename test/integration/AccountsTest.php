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
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;

class AccountsTest extends IntegrationTestCase
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

    public function testACustomerIsReadWithTheirDomain(): void
    {
        $account = (new Accounts($this->db))->customer($this->fixture->customerId());

        self::assertSame($this->fixture->customerId(), $account->getAdminId());
        self::assertSame('sgwtcustomer', $account->getUsername());
        self::assertSame($this->fixture->resellerId(), $account->getResellerId());
        self::assertSame('sgwtcustomer@example.test', $account->getEmail());
        self::assertSame($this->fixture->domainId(), $account->getDomainId());
        self::assertSame($this->fixture->domainName(), $account->getDomainName());
        self::assertSame($this->fixture->ipId(), $account->getDomainIpId());
        self::assertSame('10', (string)$account->domain('domain_subd_limit'));
    }

    public function testItIsReadFreshEveryTime(): void
    {
        // get_domain_default_props() caches for the request (M6); a service
        // that changed a limit and read it back would see the old one.
        $accounts = new Accounts($this->db);
        $accounts->customer($this->fixture->customerId());

        $this->db->execute(
            'UPDATE domain SET domain_subd_limit = 3 WHERE domain_id = ?',
            array($this->fixture->domainId())
        );

        self::assertSame('3', (string)$accounts->customer($this->fixture->customerId())->domain('domain_subd_limit'));
    }

    public function testAResellerIsNotACustomer(): void
    {
        try {
            (new Accounts($this->db))->customer($this->fixture->resellerId());
            self::fail('expected NOT_FOUND');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAnUnknownDomainColumnIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Accounts($this->db))->customer($this->fixture->customerId())->domain('no_such_column');
    }

    public function testTheToolkitReadsAVhostFresh(): void
    {
        $kit = new Toolkit(
            $this->db, new PanelCore(false),
            new Guard(new OwnershipResolver(function (string $sql, array $bind = array()) {
                return $this->db->rows($sql, $bind);
            })),
            new Accounts($this->db), new VirtualHosts($this->db), new Counts($this->db, true),
            new Writer($this->db), new FakeDirectoryProbe()
        );

        $row = $kit->vhost(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId());

        self::assertSame('toadd', $row['status']);
        self::assertSame('blog.' . $this->fixture->aliasName(), $row['name']);

        try {
            $kit->vhost(NodeType::SUBDOMAIN, 999999999);
            self::fail('expected NOT_FOUND');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }
}
