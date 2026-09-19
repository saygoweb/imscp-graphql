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

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DnsResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var DnsResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new DnsResolver(
            $this->db,
            new BatchLoader($this->db),
            static function (string $tag, $key) {
                return new Deferred(static function () use ($tag, $key) {
                    return array('__tag' => $tag, '__key' => $key);
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        return array('identity' => $this->fixture->identity('customer'));
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

    public function testTheSeededRecordComesBackWhole(): void
    {
        $records = $this->value($this->resolver->resolveCustomerDnsRecords(
            array('__domainId' => $this->fixture->domainId()), array(),
            $this->context(), $this->info()
        ));

        self::assertCount(1, $records);
        self::assertSame('mail', $records[0]['name']);
        self::assertSame('IN', $records[0]['class']);
        self::assertSame('A', $records[0]['type']);
        self::assertSame('203.0.113.7', $records[0]['value']);
        self::assertSame('custom_dns_feature', $records[0]['ownedBy']);
        self::assertSame('OK', $records[0]['provisioning']['state']);
    }

    public function testARecordWithAliasIdZeroHangsOffTheMainDomain(): void
    {
        $records = $this->value($this->resolver->resolveCustomerDnsRecords(
            array('__domainId' => $this->fixture->domainId()), array(),
            $this->context(), $this->info()
        ));

        $host = $this->value($this->resolver->resolveHost(
            $records[0], array(), $this->context(), $this->info()
        ));

        self::assertSame(NodeType::DOMAIN, $host['__tag']);
        self::assertSame($this->fixture->domainId(), $host['__key']);
    }

    public function testACustomerWithNoRecordsGetsAnEmptyListNotANull(): void
    {
        // dnsRecords is [DnsRecord!]!. A null here nulls the Customer, and a
        // reseller listing customers would lose the whole list to one
        // customer with no DNS records.
        $records = $this->value($this->resolver->resolveCustomerDnsRecords(
            array('__domainId' => -1), array(), $this->context(), $this->info()
        ));

        self::assertSame(array(), $records);
    }

    public function testTheSeededIpAddressShapesFromServerIps(): void
    {
        $ip = $this->value($this->resolver->ipReference($this->fixture->ipId()));

        self::assertSame('203.0.113.7', $ip['address']);
        self::assertSame(24, $ip['netmask']);
        self::assertSame('eth0', $ip['card']);
    }

    public function testAllReturnsEveryServerIpNotJustAReferencedOne(): void
    {
        // Unfiltered: the box's own addresses may or may not be seeded, but
        // the fixture's is always there, so its presence is what tells this
        // apart from ipReference()'s single-id lookup.
        $addresses = $this->value($this->resolver->all());
        $numbers = array();

        foreach ($addresses as $address) {
            $numbers[] = $address['address'];
        }

        self::assertContains('203.0.113.7', $numbers);
    }

    public function testTenIpReferencesCostOneQuery(): void
    {
        $resolver = $this->resolver;
        $ipId = $this->fixture->ipId();

        $count = $this->db->countQueries(function () use ($resolver, $ipId) {
            for ($i = 0; $i < 10; $i++) {
                $resolver->ipReference($ipId);
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testSixCustomersDnsRecordsCostOneQuery(): void
    {
        $resolver = $this->resolver;
        $domainId = $this->fixture->domainId();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $domainId, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveCustomerDnsRecords(
                        array('__domainId' => $domainId), array(), $context, $info
                    );
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
    }
}
