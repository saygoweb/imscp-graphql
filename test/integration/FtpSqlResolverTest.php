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
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class FtpSqlResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var FtpSqlResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new FtpSqlResolver(
            $this->db,
            new BatchLoader($this->db),
            static function (int $adminId) {
                return new Deferred(static function () use ($adminId) {
                    return array('__tag' => NodeType::CUSTOMER, '__key' => $adminId);
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
        return array(
            '__key'      => $this->fixture->customerId(),
            '__domainId' => $this->fixture->domainId()
        );
    }

    public function testTheFtpConnectionCarriesTheStringKeyedUser(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerFtpUsers(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            $this->fixture->ftpUserId(), $connection['nodes'][0]['username']
        );
        self::assertSame(
            GlobalId::encodeKey(NodeType::FTP_USER, $this->fixture->ftpUserId()),
            $connection['nodes'][0]['id']
        );
        self::assertSame(
            '/var/www/virtual/' . $this->fixture->domainName(),
            $connection['nodes'][0]['homeDirectory']
        );
    }

    public function testAnFtpUserResolvesBackToItsOwner(): void
    {
        $ftp = $this->value($this->resolver->ftpReference($this->fixture->ftpUserId()));
        $owner = $this->value($this->resolver->resolveOwner(
            $ftp, array(), $this->context(), $this->info()
        ));

        self::assertSame($this->fixture->customerId(), $owner['__key']);
    }

    public function testADatabaseCarriesItsOwnerWithoutASecondQuery(): void
    {
        // sql_database has no admin_id, so the owner comes from the join. If
        // it did not, SqlDatabase.customer would be a query per database.
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();
        $databases = null;

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info, &$databases) {
                $databases = $resolver->resolveCustomerDatabases(
                    $customer, array(), $context, $info
                );

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
        self::assertCount(1, $databases->result);
        self::assertSame('sgwt_shop', $databases->result[0]['name']);
        self::assertSame(
            $this->fixture->customerId(), $databases->result[0]['__ownerId']
        );
    }

    public function testADatabasesUsersAndAUsersDatabasesAgree(): void
    {
        $database = $this->value(
            $this->resolver->databaseReference($this->fixture->sqlDatabaseId())
        );
        $users = $this->value($this->resolver->resolveDatabaseUsers(
            $database, array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $users);
        self::assertSame('sgwt_u1', $users[0]['name']);
        self::assertSame('localhost', $users[0]['host']);

        $databases = $this->value($this->resolver->resolveSqlUserDatabases(
            $users[0], array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $databases);
        self::assertSame(
            $this->fixture->sqlDatabaseId(), $databases[0]['__key']
        );
    }

    public function testAUsersDatabasesStopAtTheOwningCustomer(): void
    {
        // Two customers holding a grant of the same sqlu_name. Reachable
        // whenever i-MSCP's per-customer SQL-user prefix is turned off, and
        // whenever an administrator or reseller writes a grant by hand.
        //
        // The leak this asserts against is not only the database name:
        // shapeDatabase() carries __ownerId, so SqlDatabase.customer would
        // then resolve the *other* customer's account, with no ownership
        // check anywhere on the path.
        $this->collidingGrant();

        $user = $this->value(
            $this->resolver->sqlUserReference($this->fixture->sqlUserId())
        );
        $databases = $this->value($this->resolver->resolveSqlUserDatabases(
            $user, array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $databases);
        self::assertSame($this->fixture->sqlDatabaseId(), $databases[0]['__key']);
        self::assertSame(
            $this->fixture->customerId(), $databases[0]['__ownerId']
        );
    }

    public function testTheStrangersOwnGrantStillListsTheirOwnDatabase(): void
    {
        // The other direction, so the fix is a filter and not a truncation:
        // the colliding grant must still see its own customer's database.
        $strangerUserId = $this->collidingGrant();

        $user = $this->value($this->resolver->sqlUserReference($strangerUserId));
        $databases = $this->value($this->resolver->resolveSqlUserDatabases(
            $user, array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $databases);
        self::assertSame(
            $this->fixture->otherCustomerId(), $databases[0]['__ownerId']
        );
    }

    /**
     * A second customer with a database of their own and a grant of the same
     * sqlu_name as the fixture's.
     *
     * @return int the stranger's sql_user id
     */
    private function collidingGrant(): int
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO sql_database (domain_id, sqld_name) VALUES (?, ?)'
        );
        $statement->execute(array($this->fixture->otherDomainId(), 'sgwt_stranger'));
        $databaseId = (int)$pdo->lastInsertId();

        $statement = $pdo->prepare(
            'INSERT INTO sql_user (sqld_id, sqlu_name, sqlu_host) VALUES (?, ?, ?)'
        );
        $statement->execute(array($databaseId, 'sgwt_u1', 'localhost'));

        return (int)$pdo->lastInsertId();
    }

    public function testSixCustomersFtpSqlAndUserListsCostThreeQueries(): void
    {
        // One per rule, not one per customer.
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveCustomerFtpUsers($customer, array(), $context, $info);
                    $resolver->resolveCustomerDatabases($customer, array(), $context, $info);
                    $resolver->resolveCustomerSqlUsers($customer, array(), $context, $info);
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(3, $count);
    }
}
