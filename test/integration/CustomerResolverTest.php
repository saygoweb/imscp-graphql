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
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class CustomerResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var CustomerResolver */
    private $resolver;

    /**
     * A fixed panel configuration, not the box's. Every feature this asserts
     * is then a statement about the customer's own row rather than about
     * whatever the reference box happens to be configured with today.
     */
    private function config(): array
    {
        return array(
            'NAMED_PACKAGE'          => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES' => 'Awstats',
            'BACKUP_DOMAINS'         => 'yes',
            'ENABLE_SSL'             => 1,
            'IMSCP_SUPPORT_SYSTEM'   => 1
        );
    }

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new CustomerResolver(
            $this->db,
            new BatchLoader($this->db),
            new Counts($this->db, true),
            new VirtualHosts($this->db),
            $this->config(),
            true,
            static function () {
                // A window wide enough to hold anything the fixture writes,
                // and narrow enough that it is still a window.
                return array(0, 4102444800);
            },
            'decode_idna',
            static function (int $resellerId) {
                return new \GraphQL\Deferred(static function () use ($resellerId) {
                    return array(
                        '__tag' => NodeType::RESELLER, '__key' => $resellerId
                    );
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

    private function narrowContext(): array
    {
        $customer = $this->fixture->identity('customer');

        return array('identity' => new Identity(
            $customer->getAdminId(), $customer->getUsername(), 'user',
            $customer->getCreatedBy(), $customer->getEmail(),
            array(Scope::MAIL_READ), 1
        ));
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

    private function customer(): array
    {
        return $this->value($this->resolver->reference($this->fixture->customerId()));
    }

    public function testTheCustomerShapesFromTheJoinedRow(): void
    {
        $customer = $this->customer();

        self::assertSame('sgwtcustomer', $customer['username']);
        self::assertSame('REF-sgwtcustomer', $customer['reference']);
        self::assertSame('OK', $customer['provisioning']['state']);
        self::assertSame($this->fixture->domainId(), $customer['__domainId']);
        self::assertSame($this->fixture->resellerId(), $customer['__resellerId']);
    }

    public function testAnAccountThatIsNotACustomerIsNotReturned(): void
    {
        // The reseller has no domain row, so the inward join drops it. A
        // reference() that returned a half-populated Customer here would null
        // whatever list it appeared in.
        self::assertNull($this->value(
            $this->resolver->reference($this->fixture->resellerId())
        ));
    }

    public function testQuotasNormaliseAllThreeOfImscpsLimitEncodings(): void
    {
        // The fixture is written so that all three appear at once: 10 is a
        // limit, 0 is unlimited, -1 is withheld. A client that read them raw
        // would offer a feature the customer does not have.
        $quotas = $this->value($this->resolver->resolveQuotas(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        // shop, plus the alias subdomain blog: Counts::subdomains sums both
        // tables, and so must this.
        self::assertSame(
            array('enabled' => true, 'limit' => 10, 'used' => 2, 'remaining' => 8),
            $quotas['subdomains']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 5, 'used' => 1, 'remaining' => 4),
            $quotas['domainAliases']
        );
        // domain_mailacc_limit is 0: unlimited, not "none allowed".
        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 2, 'remaining' => null),
            $quotas['mailAccounts']
        );
        // domain_ftpacc_limit is -1: withheld. The one FTP user the fixture
        // writes is still counted, because it exists.
        self::assertSame(
            array('enabled' => false, 'limit' => 0, 'used' => 1, 'remaining' => 0),
            $quotas['ftpUsers']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 2, 'used' => 1, 'remaining' => 1),
            $quotas['sqlDatabases']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 2, 'used' => 1, 'remaining' => 1),
            $quotas['sqlUsers']
        );
    }

    public function testStorageIsBytesEverywhereIncludingTheLimits(): void
    {
        // Decision D3, and the reason it exists: the fixture's disk limit is
        // 5120 MiB and its disk usage 1048576 bytes. Passing the limit through
        // unconverted would tell a client the customer had used a fifth of
        // their allowance when they have used two ten-thousandths of it.
        $storage = $this->value($this->resolver->resolveStorage(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame('5368709120', $storage['diskLimit']);
        self::assertSame('1048576', $storage['diskUsed']);
        self::assertSame('524288', $storage['diskFiles']);
        self::assertSame('262144', $storage['diskMail']);
        self::assertSame('262144', $storage['diskSql']);
        self::assertSame('10737418240', $storage['trafficLimit']);
        // The fixture writes no domain_traffic rows, and a customer with no
        // traffic has used none - not null, which is not what the SDL allows.
        self::assertSame('0', $storage['trafficUsed']);
        self::assertSame('1073741824', $storage['mailQuota']);
    }

    public function testFeaturesReadTheCustomersRowAndTheResellersSupportSetting(): void
    {
        $features = $this->value($this->resolver->resolveFeatures(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertTrue($features['php']);
        self::assertTrue($features['phpEditor']);
        self::assertTrue($features['cgi']);
        self::assertTrue($features['customDns']);
        self::assertTrue($features['externalMail']);
        self::assertTrue($features['backup']);
        self::assertTrue($features['ssl']);
        self::assertTrue($features['webStats']);
        // reseller_props.support_system is 'yes' in the fixture, and
        // IMSCP_SUPPORT_SYSTEM is on in config() above.
        self::assertTrue($features['supportSystem']);
    }

    public function testAnAccountWithNoResellerAnswersNullAndCostsNoQuery(): void
    {
        // shape() keeps admin.created_by's null rather than flattening it, and
        // the edge has to keep it too: (int)null is 0, and reference(0) is a
        // query for a reseller that cannot exist.
        $shape = CustomerResolver::shape(
            array_merge($this->row(), array('created_by' => null)), 'decode_idna'
        );

        self::assertNull($shape['__resellerId']);

        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();
        $reseller = false;

        $count = $this->db->countQueries(
            function () use ($resolver, $shape, $context, $info, &$reseller) {
                $reseller = $resolver->resolveReseller($shape, array(), $context, $info);

                SyncPromise::runQueue();
            }
        );

        self::assertNull($reseller);
        self::assertSame(0, $count);
    }

    public function testAnAccountWithNoResellerHasNoSupportSystem(): void
    {
        // support_system lives on the reseller, so an account with no reseller
        // has no row to read it from - which is what the panel's own
        // customerHasFeature() concludes as well.
        $shape = CustomerResolver::shape(
            array_merge($this->row(), array('created_by' => null)), 'decode_idna'
        );

        $features = $this->value($this->resolver->resolveFeatures(
            $shape, array(), $this->context(), $this->info()
        ));

        self::assertFalse($features['supportSystem']);
        // The rest of the row still answers: this is one feature going false,
        // not the block failing.
        self::assertTrue($features['php']);
    }

    /**
     * The fixture customer's joined row, as CustomerResolver::SELECT returns it.
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->db->rows(
            CustomerResolver::SELECT . ' WHERE a.admin_id = ?',
            array($this->fixture->customerId())
        );

        return $rows[0];
    }

    public function testApiAccessFallsBackToThePluginsDefaultWithNoPermRow(): void
    {
        // api_perm is empty in production, so this is the branch every real
        // request takes.
        self::assertTrue($this->value($this->resolver->resolveApiAccess(
            $this->customer(), array(), $this->context(), $this->info()
        )));
    }

    public function testAnApiPermRowWinsOverTheDefault(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO api_perm (admin_id, allowed) VALUES (?, 0)'
        )->execute(array($this->fixture->customerId()));

        self::assertFalse($this->value($this->resolver->resolveApiAccess(
            $this->customer(), array(), $this->context(), $this->info()
        )));
    }

    public function testTheMainDomainSubdomainsAndAliasesShareOneVhostBatch(): void
    {
        // The bucket this class shares between three fields, so a document
        // selecting domain, subdomains and domainAliases together pays for one
        // flush of it rather than three. That flush is not one SQL statement,
        // though: VirtualHosts::forDomains() itself issues one query per vhost
        // kind - dmn, sub, als, alssub - unconditionally (Task 9, and Task 12's
        // own testAReferenceToFourKindsCostsFourQueriesRegardlessOfCount()
        // measures the same four). So four is the batched cost here; twelve -
        // three fields each paying that four-query cost on its own - is what
        // an unshared bucket would have cost instead.
        //
        // The brief this test was drafted from asserted 1 here, which is a
        // brief defect: it assumed one shared bucket meant one shared SQL
        // statement, but VirtualHosts::forDomains() does not run as one
        // statement even for a single domain. Corrected to 4, matching
        // forDomains()'s already-tested cost.
        $customer = $this->customer();
        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();
        $results = array();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info, &$results) {
                $results['domain'] = $resolver->resolveDomain($customer, array(), $context, $info);
                $results['subs'] = $resolver->resolveSubdomains($customer, array(), $context, $info);
                $results['aliases'] = $resolver->resolveDomainAliases($customer, array(), $context, $info);

                SyncPromise::runQueue();
            }
        );

        self::assertSame(4, $count);
        self::assertSame($this->fixture->domainName(), $results['domain']->result['name']);
        // shop on the main domain and blog on the alias, in one list.
        self::assertCount(2, $results['subs']->result);
        self::assertCount(1, $results['aliases']->result);

        $tags = array(
            $results['subs']->result[0]['__tag'], $results['subs']->result[1]['__tag']
        );
        sort($tags);
        self::assertSame(
            array(NodeType::ALIAS_SUBDOMAIN, NodeType::SUBDOMAIN), $tags
        );
    }

    public function testSixQuotasRequestsForOneCustomerCostSevenQueriesNotFortyTwo(): void
    {
        // Decision D5. Six counting rules, batched so that asking for the same
        // customer's quotas six times over - which six fields of one document
        // resolving against the same source would do - costs what one ask
        // costs, not six times that.
        //
        // The batched cost is seven, not six: five of the six rules
        // (domainAliases, mailAccounts, ftpUsers, sqlDatabases, sqlUsers) are
        // one query each, but Counts::subdomains() is two - it sums the
        // `subdomain` table and the `subdomain_alias` table, because a
        // customer's subdomain allowance covers both (Counting.php:467, and
        // CountsTest's own fixture asserts the same two-query shape). The
        // brief this test was drafted from asserted 6, which is a brief
        // defect - "one query each" does not hold for the one rule that reads
        // two tables. Corrected to 7; the method name's "not forty-two"
        // baseline is 6 customers x 7 real per-customer queries unbatched.
        $customer = $this->customer();
        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveQuotas($customer, array(), $context, $info);
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(7, $count);
    }

    public function testANarrowedTokenIsRefusedRatherThanGivenAnEmptyAccount(): void
    {
        // A MAIL_READ-only token asking for storage must be told no. Zeroes
        // would read as "this customer uses nothing", which is a worse answer
        // than an error.
        try {
            $this->resolver->resolveStorage(
                $this->customer(), array(), $this->narrowContext(), $this->info()
            );
            self::fail('a token without ACCOUNT_READ must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }
}
