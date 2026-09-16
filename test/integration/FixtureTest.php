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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class FixtureTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    public function testTheSeededGraphHangsOffTheOneCustomer(): void
    {
        // Every ownership query in this plugin walks up to domain_admin_id or
        // to ftp_users.admin_id. A fixture that wired any of these to the
        // wrong parent would make the authorisation tests pass for the wrong
        // reason, so the chain is asserted here rather than assumed.
        $this->fixture->seed();

        $resolver = new OwnershipResolver(function (string $sql, array $bind) {
            return $this->db->rows($sql, $bind);
        });

        $customerId = $this->fixture->customerId();

        $cases = array(
            NodeType::DOMAIN          => $this->fixture->domainId(),
            NodeType::SUBDOMAIN       => $this->fixture->subdomainId(),
            NodeType::DOMAIN_ALIAS    => $this->fixture->aliasId(),
            NodeType::ALIAS_SUBDOMAIN => $this->fixture->aliasSubdomainId(),
            NodeType::MAIL_ACCOUNT    => $this->fixture->mailboxId(),
            NodeType::SQL_DATABASE    => $this->fixture->sqlDatabaseId(),
            NodeType::SQL_USER        => $this->fixture->sqlUserId(),
            NodeType::DNS_RECORD      => $this->fixture->dnsRecordId()
        );

        foreach ($cases as $tag => $id) {
            self::assertSame(
                $customerId,
                $resolver->ownerOf(GlobalId::decode(GlobalId::encode($tag, $id))),
                $tag
            );
        }

        self::assertSame($customerId, $resolver->ownerOf(GlobalId::decodeKey(
            GlobalId::encodeKey(NodeType::FTP_USER, $this->fixture->ftpUserId())
        )));
    }

    public function testTheSiblingAndTheOtherResellerAreWiredForNegativeCases(): void
    {
        $this->fixture->seed();

        self::assertSame(
            $this->fixture->resellerId(),
            (int)$this->db->value(
                'SELECT created_by FROM admin WHERE admin_id = ?',
                array($this->fixture->siblingId())
            )
        );
        self::assertSame(
            $this->fixture->otherResellerId(),
            (int)$this->db->value(
                'SELECT created_by FROM admin WHERE admin_id = ?',
                array($this->fixture->otherCustomerId())
            )
        );
        self::assertNotSame(
            $this->fixture->resellerId(), $this->fixture->otherResellerId()
        );
    }

    public function testTheHostingPlanBelongsToTheReseller(): void
    {
        $this->fixture->seed();

        self::assertSame(
            $this->fixture->resellerId(),
            (int)$this->db->value(
                'SELECT reseller_id FROM hosting_plans WHERE id = ?',
                array($this->fixture->hostingPlanId())
            )
        );
    }

    public function testRollingBackLeavesNoRowBehind(): void
    {
        // The test that matters. A fixture that commits would leave the box's
        // database dirty and the next run would either collide on
        // admin.admin_name or pass because of the previous run's rows.
        $before = $this->counts();

        $this->fixture->seed();
        $seeded = $this->counts();

        self::assertGreaterThan($before['admin'], $seeded['admin'], 'the seed did nothing');

        $this->fixture->rollBack();

        self::assertSame($before, $this->counts());
    }

    public function testRollingBackTwiceIsHarmless(): void
    {
        $this->fixture->seed();
        $this->fixture->rollBack();
        $this->fixture->rollBack();

        self::assertSame(0, (int)$this->db->value(
            'SELECT COUNT(*) FROM admin WHERE admin_name LIKE ?',
            array(Fixture::PREFIX . '%')
        ));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = array();

        foreach (array(
            'admin', 'domain', 'subdomain', 'domain_aliasses', 'subdomain_alias',
            'mail_users', 'ftp_users', 'sql_database', 'sql_user', 'domain_dns',
            'hosting_plans', 'reseller_props', 'server_ips'
        ) as $table) {
            $counts[$table] = (int)$this->db->value('SELECT COUNT(*) FROM `' . $table . '`');
        }

        return $counts;
    }
}
