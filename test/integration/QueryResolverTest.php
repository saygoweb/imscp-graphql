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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\QueryResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class QueryResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var QueryResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();

        // Built through the container, not by hand: this test is as much
        // about the wiring as about the six fields.
        $container = Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db
        );
        $maps = $container->resolverMaps();
        $this->resolver = $this->queryResolverOf($maps);
    }

    /**
     * The container hands back maps, not resolvers; the bound object of any
     * Query.* entry is the QueryResolver that owns it.
     *
     * @param array<string, array<string, callable>> $maps
     */
    private function queryResolverOf(array $maps): QueryResolver
    {
        $entry = $maps['QueryResolver']['Query.node'];

        return $entry[0];
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(string $who): array
    {
        return array('identity' => $this->fixture->identity($who));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    public function testNodeFindsEveryKindTheCallerOwns(): void
    {
        $cases = array(
            NodeType::CUSTOMER       => $this->fixture->customerId(),
            NodeType::DOMAIN         => $this->fixture->domainId(),
            NodeType::SUBDOMAIN      => $this->fixture->subdomainId(),
            NodeType::DOMAIN_ALIAS   => $this->fixture->aliasId(),
            NodeType::MAIL_ACCOUNT   => $this->fixture->mailboxId(),
            NodeType::SQL_DATABASE   => $this->fixture->sqlDatabaseId(),
            NodeType::SQL_USER       => $this->fixture->sqlUserId(),
            NodeType::DNS_RECORD     => $this->fixture->dnsRecordId()
        );

        foreach ($cases as $tag => $id) {
            $node = $this->value($this->resolver->resolveNode(
                null, array('id' => GlobalId::encode($tag, $id)),
                $this->context('customer'), $this->info()
            ));

            self::assertNotNull($node, $tag);
            self::assertSame($tag, $node['__tag'], $tag);
        }
    }

    public function testNodeFindsAStringKeyedFtpUser(): void
    {
        $node = $this->value($this->resolver->resolveNode(
            null,
            array('id' => GlobalId::encodeKey(
                NodeType::FTP_USER, $this->fixture->ftpUserId()
            )),
            $this->context('customer'), $this->info()
        ));

        self::assertSame($this->fixture->ftpUserId(), $node['username']);
    }

    public function testAnAliasSubdomainIsFoundByItsOwnTag(): void
    {
        // Decision D1 end to end: the identifier says AliasSubdomain and the
        // object says its __typename is Subdomain.
        $node = $this->value($this->resolver->resolveNode(
            null,
            array('id' => GlobalId::encode(
                NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()
            )),
            $this->context('customer'), $this->info()
        ));

        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $node['__tag']);
        self::assertSame('blog.' . $this->fixture->aliasName(), $node['name']);
    }

    public function testAStrangersObjectIsNotFoundRatherThanForbidden(): void
    {
        // Spec section 6.3. FORBIDDEN would confirm the object exists, which
        // is exactly the disclosure NOT_FOUND is chosen to avoid.
        try {
            $this->resolver->resolveNode(
                null,
                array('id' => GlobalId::encode(
                    NodeType::DOMAIN, $this->fixture->domainId()
                )),
                $this->context('otherCustomer'), $this->info()
            );
            self::fail('a stranger must not reach this domain');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAMalformedIdentifierIsAlsoNotFound(): void
    {
        // A third answer here - BAD_USER_INPUT - would let a caller tell a
        // well-formed unreachable id from a malformed one, and then probe.
        foreach (array('', 'not-base64', GlobalId::encode('Htaccess', 1)) as $id) {
            try {
                $this->resolver->resolveNode(
                    null, array('id' => $id), $this->context('customer'), $this->info()
                );
                self::fail('a malformed identifier must be NOT_FOUND: ' . $id);
            } catch (ApiException $e) {
                self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode(), $id);
            }
        }
    }

    public function testACustomerListingCustomersSeesOnlyThemselves(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            null, array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
            $connection['nodes'][0]['id']
        );
    }

    public function testAResellerListingCustomersSeesTheirOwnTwo(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            null, array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
    }

    public function testAResellersCustomerListIgnoresAResellerIdFilter(): void
    {
        // Documented behaviour: "Administrators only; ignored for a reseller,
        // who only ever sees their own." Honouring it would let a reseller
        // read another reseller's customer list.
        $connection = $this->value($this->resolver->resolveCustomers(
            null,
            array('filter' => array('resellerId' => GlobalId::encode(
                NodeType::RESELLER, $this->fixture->otherResellerId()
            ))),
            $this->context('reseller'), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
    }

    public function testACustomerListingResellersSeesNone(): void
    {
        $connection = $this->value($this->resolver->resolveResellers(
            null, array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(0, $connection['totalCount']);
        self::assertSame(array(), $connection['nodes']);
    }

    public function testAResellerListingResellersSeesOnlyThemselves(): void
    {
        $connection = $this->value($this->resolver->resolveResellers(
            null, array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame('sgwtreseller', $connection['nodes'][0]['username']);
    }

    public function testPendingReturnsTheOneUnsettledObjectTheCustomerOwns(): void
    {
        // The fixture writes exactly one: the alias subdomain, status 'toadd'.
        // Anything else appearing here means pendingFor() is reaching outside
        // the customer.
        $pending = $this->value($this->resolver->resolvePending(
            null, array(), $this->context('customer'), $this->info()
        ));

        self::assertCount(1, $pending);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $pending[0]['__tag']);
        self::assertSame('PENDING', $pending[0]['provisioning']['state']);
    }

    public function testAStrangerHasNothingPending(): void
    {
        $pending = $this->value($this->resolver->resolvePending(
            null, array(), $this->context('otherCustomer'), $this->info()
        ));

        self::assertSame(array(), $pending);
    }
}
