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
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class MailResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var MailResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new MailResolver(
            $this->db,
            new BatchLoader($this->db),
            'decode_idna',
            static function (string $address) {
                // The maildir does not exist for a fixture account, and this
                // test is not about the filesystem: it is about which
                // addresses are asked at all.
                return $address === 'sales@sgwtcustomer.test' ? 4096 : null;
            },
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

    private function customer(): array
    {
        return array('__domainId' => $this->fixture->domainId());
    }

    public function testTheConnectionCarriesBothSeededAccounts(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
        self::assertCount(2, $connection['nodes']);
        self::assertSame(
            'sales@' . $this->fixture->domainName(), $connection['nodes'][0]['address']
        );
        self::assertSame('MAILBOX', $connection['nodes'][0]['kind']);
        self::assertSame('FORWARD', $connection['nodes'][1]['kind']);
        self::assertSame(
            array('a@example.net', 'b@example.net'), $connection['nodes'][1]['forwardTo']
        );
    }

    public function testFilteringByKindMatchesEveryVhostFlavourOfThatKind(): void
    {
        // The fixture's forward is stored as 'alssub_forward'. A filter that
        // compared the schema's FORWARD to the column would match nothing.
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('filter' => array('kind' => 'FORWARD')),
            $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame('hello@blog.' . $this->fixture->aliasName(),
            $connection['nodes'][0]['address']);
    }

    public function testFilteringByAddressIsASubstringMatch(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('filter' => array('address' => 'sale')),
            $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
    }

    public function testADisabledFilterFindsAnAccountWhoseStatusIsNull(): void
    {
        // mail_users.status is the one status column in the schema that is
        // nullable, and Provisioning::fromStatus(null) is DISABLED - so this
        // row is reported as DISABLED and has to be findable as one. It was
        // not: '= ?' is never true for NULL.
        $this->nullTheMailboxStatus();

        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('filter' => array('state' => 'DISABLED')),
            $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            'sales@' . $this->fixture->domainName(), $connection['nodes'][0]['address']
        );
        // What the API says the row is, beside what the filter for that state
        // returns. The finding was that the two disagreed.
        self::assertSame(
            'DISABLED', $connection['nodes'][0]['provisioning']['state']
        );
    }

    public function testAnErrorFilterDoesNotClaimAnAccountWhoseStatusIsNull(): void
    {
        $this->nullTheMailboxStatus();

        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('filter' => array('state' => 'ERROR')),
            $this->context(), $this->info()
        ));

        self::assertSame(0, $connection['totalCount']);
    }

    private function nullTheMailboxStatus(): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE mail_users SET status = NULL WHERE mail_id = ?'
        );
        $statement->execute(array($this->fixture->mailboxId()));
    }

    public function testAPageNarrowsTheNodesAndNotTheTotal(): void
    {
        // totalCount is what the filter matched, not what the page returned -
        // otherwise a client can never tell it has more to fetch.
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('page' => array('limit' => 1, 'offset' => 1)),
            $this->context(), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
        self::assertCount(1, $connection['nodes']);
        self::assertSame('FORWARD', $connection['nodes'][0]['kind']);
    }

    public function testAMainDomainAddressHangsOffTheDomainAndAnAliasSubdomainAddressOffTheAliasSubdomain(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        $mailbox = $this->value($this->resolver->resolveHost(
            $connection['nodes'][0], array(), $this->context(), $this->info()
        ));
        $forward = $this->value($this->resolver->resolveHost(
            $connection['nodes'][1], array(), $this->context(), $this->info()
        ));

        self::assertSame(NodeType::DOMAIN, $mailbox['__tag']);
        self::assertSame($this->fixture->domainId(), $mailbox['__key']);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $forward['__tag']);
        self::assertSame($this->fixture->aliasSubdomainId(), $forward['__key']);
    }

    public function testQuotaUsedIsOnlyAskedForAnAccountThatHasAMailbox(): void
    {
        // The forward has quota 0 - unlimited - and no mailbox, so the
        // filesystem is not consulted at all. Asking would be a stat per row
        // for an answer that is always null.
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame('4096', $this->resolver->resolveQuotaUsed(
            $connection['nodes'][0], array(), $this->context(), $this->info()
        ));
        self::assertNull($this->resolver->resolveQuotaUsed(
            $connection['nodes'][1], array(), $this->context(), $this->info()
        ));
    }

    public function testSixCustomersMailAccountsCostOneQuery(): void
    {
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveCustomerMailAccounts(
                        $customer, array(), $context, $info
                    );
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
    }

    public function testTwoDifferentFiltersAreTwoQueriesNotOneWrongOne(): void
    {
        // The bucket carries the filter. Sharing one bucket between two
        // filters would serve one client the other's rows, which is worse
        // than a second query.
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                $resolver->resolveCustomerMailAccounts($customer, array(), $context, $info);
                $resolver->resolveCustomerMailAccounts(
                    $customer, array('filter' => array('kind' => 'FORWARD')),
                    $context, $info
                );

                SyncPromise::runQueue();
            }
        );

        self::assertSame(2, $count);
    }

    public function testANarrowedTokenIsRefusedRatherThanGivenAnEmptyMailbox(): void
    {
        $customer = $this->fixture->identity('customer');
        $context = array('identity' => new Identity(
            $customer->getAdminId(), $customer->getUsername(), 'user',
            $customer->getCreatedBy(), $customer->getEmail(),
            array(Scope::DNS_READ), 1
        ));

        try {
            $this->resolver->resolveCustomerMailAccounts(
                $this->customer(), array(), $context, $this->info()
            );
            self::fail('a token without MAIL_READ must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }
}
