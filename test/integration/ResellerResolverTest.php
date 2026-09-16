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
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class ResellerResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var ResellerResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new ResellerResolver(
            $this->db,
            new BatchLoader($this->db),
            true,
            static function () { return array(0, 4102444800); },
            'decode_idna',
            static function (int $adminId) {
                return new Deferred(static function () use ($adminId) {
                    return array('__tag' => NodeType::CUSTOMER, '__key' => $adminId);
                });
            },
            static function (array $adminIds) {
                return new Deferred(static function () use ($adminIds) {
                    $nodes = array();

                    foreach ($adminIds as $adminId) {
                        $nodes[] = array(
                            '__tag' => NodeType::CUSTOMER, '__key' => (int)$adminId
                        );
                    }

                    return $nodes;
                });
            },
            static function (array $ipIds) {
                return new Deferred(static function () use ($ipIds) {
                    return $ipIds;
                });
            }
        );
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

    private function reseller(): array
    {
        return $this->value($this->resolver->reference($this->fixture->resellerId()));
    }

    public function testTheResellerShapesFromAdminJoinedToItsProps(): void
    {
        $reseller = $this->reseller();

        self::assertSame('sgwtreseller', $reseller['username']);
        self::assertSame('2026-01-01T00:00:00Z', $reseller['createdAt']);
        self::assertSame('OK', $reseller['provisioning']['state']);
        self::assertSame(array($this->fixture->ipId()), $reseller['__ipIds']);
    }

    public function testAnAdministratorIsNotAReseller(): void
    {
        // The administrator has no reseller_props row, and the inward join is
        // what keeps Query.reseller from returning a Reseller with no quotas.
        self::assertNull($this->value(
            $this->resolver->reference($this->fixture->adminId())
        ));
    }

    public function testQuotasComeFromTheMaintainedCounters(): void
    {
        $quotas = $this->resolver->resolveQuotas(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        );

        // The fixture writes zeroed counters, which is what a freshly created
        // reseller has. The point of the assertion is the encoding, not the
        // number: max_mail_cnt is 0 and that means unlimited.
        self::assertSame(
            array('enabled' => true, 'limit' => 20, 'used' => 0, 'remaining' => 20),
            $quotas['customers']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 0, 'remaining' => null),
            $quotas['mailAccounts']
        );
    }

    public function testStorageIsSummedOverTheResellersOwnCustomers(): void
    {
        // Two customers, each with the fixture's domain figures - and the
        // other reseller's customer excluded, which is the assertion that
        // matters: a missing WHERE would bill this reseller for a stranger.
        $storage = $this->value($this->resolver->resolveStorage(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame('53687091200', $storage['diskLimit']);
        self::assertSame('2097152', $storage['diskUsed']);
        self::assertSame('1048576', $storage['diskFiles']);
        self::assertSame('524288', $storage['diskMail']);
        self::assertSame('524288', $storage['diskSql']);
        self::assertSame('107374182400', $storage['trafficLimit']);
        self::assertSame('0', $storage['trafficUsed']);
        self::assertNull($storage['mailQuota']);
    }

    public function testACustomerMayReadTheirResellersIdentityAndNothingElse(): void
    {
        $reseller = $this->reseller();

        // Identity: allowed, and it is already in the shape.
        self::assertSame('sgwtreseller', $reseller['username']);
        self::assertSame('Test', $reseller['contact']['firstName']);

        foreach (array('resolveQuotas', 'resolveStorage', 'resolveIpAddresses',
            'resolveApiAccess', 'resolveHostingPlans') as $method
        ) {
            try {
                $this->resolver->$method(
                    $reseller, array(), $this->context('customer'), $this->info()
                );
                self::fail($method . ' must refuse a customer');
            } catch (ApiException $e) {
                self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode(), $method);
            }
        }
    }

    public function testAnotherResellerIsRefusedTheSameFields(): void
    {
        try {
            $this->resolver->resolveQuotas(
                $this->reseller(), array(), $this->context('otherReseller'),
                $this->info()
            );
            self::fail('another reseller must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }

    public function testTheResellerSeesBothTheirCustomersAndNotTheStranger(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);

        $ids = array(
            $connection['nodes'][0]['__key'], $connection['nodes'][1]['__key']
        );
        sort($ids);
        $expected = array($this->fixture->customerId(), $this->fixture->siblingId());
        sort($expected);

        self::assertSame($expected, $ids);
    }

    public function testACustomerAskingForTheirResellersCustomersSeesOnlyThemselves(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            $this->reseller(), array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            $this->fixture->customerId(), $connection['nodes'][0]['__key']
        );
    }

    public function testACustomerAskingAnotherResellerSeesNobody(): void
    {
        // Not an error: the object is reachable and the answer is honestly
        // empty. An error here would let a customer probe which resellers
        // exist by watching which questions fail.
        $other = $this->value(
            $this->resolver->reference($this->fixture->otherResellerId())
        );
        $connection = $this->value($this->resolver->resolveCustomers(
            $other, array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(0, $connection['totalCount']);
        self::assertSame(array(), $connection['nodes']);
    }

    public function testFilteringCustomersByUsernameNarrowsTheList(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            $this->reseller(), array('filter' => array('username' => 'sibling')),
            $this->context('reseller'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            $this->fixture->siblingId(), $connection['nodes'][0]['__key']
        );
    }

    public function testTheHostingPlanDecodesItsProps(): void
    {
        $plans = $this->value($this->resolver->resolveHostingPlans(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        ));

        self::assertCount(1, $plans);
        self::assertSame('sgwt plan', $plans[0]['name']);
        self::assertTrue($plans[0]['available']);
        self::assertSame(
            array('enabled' => true, 'limit' => 10), $plans[0]['quotas']['subdomains']
        );
        self::assertSame(array('DOMAIN', 'SQL'), $plans[0]['features']['backup']);
    }

    public function testTheViewerOfAResellerCarriesTheResellerAndNoCustomer(): void
    {
        $context = $this->context('reseller');

        self::assertNull($this->resolver->resolveViewerCustomer(
            array(), array(), $context, $this->info()
        ));
        self::assertSame($this->fixture->resellerId(), $this->value(
            $this->resolver->resolveViewerReseller(array(), array(), $context, $this->info())
        )['__key']);
    }

    public function testTheViewerOfACustomerCarriesTheCustomerAndNoReseller(): void
    {
        $context = $this->context('customer');

        self::assertNull($this->resolver->resolveViewerReseller(
            array(), array(), $context, $this->info()
        ));
        self::assertSame($this->fixture->customerId(), $this->value(
            $this->resolver->resolveViewerCustomer(array(), array(), $context, $this->info())
        )['__key']);
    }

    public function testTheViewersContactComesFromTheAdminRow(): void
    {
        $contact = $this->value($this->resolver->resolveViewerContact(
            array(), array(), $this->context('customer'), $this->info()
        ));

        self::assertSame('Test', $contact['firstName']);
        self::assertSame('User', $contact['lastName']);
        self::assertSame('UNSPECIFIED', $contact['gender']);
        self::assertSame('Testville', $contact['city']);
    }
}
