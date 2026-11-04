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

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;

/**
 * A mail account or DNS record whose host row is gone.
 *
 * Deleting a subdomain or alias marks its mail and DNS 'todelete' and leaves
 * the engine to remove them. Until it does - or for good, when it fails - the
 * row's sub_id or alias_id names nothing. MailAccount.host and DnsRecord.host
 * were non-null, so one such row nulled the whole connection with graphql-php's
 * "Cannot return null for non-nullable field" (seen in production on
 * MailAccount.host). The host is null instead, and the rest of the row reads.
 */
class OrphanedHostTest extends IntegrationTestCase
{
    const DOCUMENT = '
        query {
          customers {
            nodes {
              username
              mailAccounts { nodes { address host { id } } }
              dnsRecords { value host { id } }
            }
          }
        }
    ';

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

    private function execute(): array
    {
        $container = Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db,
            array()
        );

        return GraphQL::executeQuery(
            $container->schemaFactory()->create(), self::DOCUMENT, null,
            array('identity' => $this->fixture->identity('reseller')),
            null, null, null, array()
        )->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
    }

    private function customerNode(array $response): array
    {
        foreach ($response['data']['customers']['nodes'] as $node) {
            if ($node['username'] === Fixture::PREFIX . 'customer') {
                return $node;
            }
        }

        self::fail('The document did not return the fixture customer.');
    }

    public function testAMailAccountWhoseHostIsGoneReadsWithANullHost(): void
    {
        // The fixture's forward hangs off this alias subdomain.
        $this->db->execute(
            'DELETE FROM subdomain_alias WHERE subdomain_alias_id = ?',
            array($this->fixture->aliasSubdomainId())
        );

        $response = $this->execute();

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );

        $accounts = $this->customerNode($response)['mailAccounts']['nodes'];
        $hosts = array();

        foreach ($accounts as $account) {
            $hosts[$account['address']] = $account['host'];
        }

        self::assertNotNull($hosts['sales@' . $this->fixture->domainName()]);
        self::assertArrayHasKey('hello@blog.' . $this->fixture->aliasName(), $hosts);
        self::assertNull($hosts['hello@blog.' . $this->fixture->aliasName()]);
    }

    public function testADnsRecordWhoseAliasIsGoneReadsWithANullHost(): void
    {
        $this->db->execute(
            'UPDATE domain_dns SET alias_id = ? WHERE domain_dns_id = ?',
            array($this->fixture->aliasId(), $this->fixture->dnsRecordId())
        );
        $this->db->execute(
            'DELETE FROM subdomain_alias WHERE alias_id = ?',
            array($this->fixture->aliasId())
        );
        $this->db->execute(
            'DELETE FROM domain_aliasses WHERE alias_id = ?',
            array($this->fixture->aliasId())
        );

        $response = $this->execute();

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );

        $records = $this->customerNode($response)['dnsRecords'];

        self::assertCount(1, $records);
        self::assertSame('203.0.113.7', $records[0]['value']);
        self::assertNull($records[0]['host']);
    }
}
