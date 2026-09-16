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
use iMSCP\Plugin\SGW_GraphQL\Model\AdminModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;
use iMSCP\Plugin\SGW_GraphQL\Model\MailAccountModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SqlDatabaseModel;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;

class BatchLoaderTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var BatchLoader */
    private $loader;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->loader = new BatchLoader($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function drain(): void
    {
        SyncPromise::runQueue();
    }

    /**
     * @return AdminModel[]
     */
    private function customers(): array
    {
        $deferred = $this->loader->byColumn(
            AdminModel::class, 'created_by', $this->fixture->resellerId()
        );
        $this->drain();

        return $deferred->result;
    }

    public function testByColumnHydratesModelsFromTheDatabase(): void
    {
        $names = array();

        foreach ($this->customers() as $customer) {
            $names[] = $customer->admin_name;
        }

        sort($names);
        self::assertSame(
            array(Fixture::PREFIX . 'customer', Fixture::PREFIX . 'sibling'), $names
        );
    }

    public function testAOneHasManyEdgeCostsOneQueryForBothParents(): void
    {
        // Two customers, one query for their domains. Asking per customer is
        // the N+1 spec section 10.1 calls a defect, and this is the assertion
        // that would fail if related() flushed per model.
        $customers = $this->customers();
        $loader = $this->loader;

        $count = $this->db->countQueries(function () use ($loader, $customers) {
            foreach ($customers as $customer) {
                $loader->related($customer, 'domains');
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testAOneHasManyEdgeReturnsTheRightChildrenPerParent(): void
    {
        // One query is worthless if the rows are handed to the wrong parent.
        $customers = $this->customers();
        $deferreds = array();

        foreach ($customers as $customer) {
            $deferreds[(int)$customer->admin_id] = $this->loader->related($customer, 'domains');
        }

        $this->drain();

        $domains = $deferreds[$this->fixture->customerId()]->result;

        self::assertCount(1, $domains);
        self::assertSame($this->fixture->domainName(), $domains[0]->domain_name);
        self::assertSame(
            $this->fixture->domainId(), (int)$domains[0]->domain_id
        );
    }

    public function testAParentWithNoChildrenGetsAnEmptyArrayNotNull(): void
    {
        // The sibling customer has a domain but no mail accounts. A resolver
        // returning null for a non-null list field would null the whole
        // object it hangs off.
        $sibling = null;

        foreach ($this->customers() as $customer) {
            if ((int)$customer->admin_id === $this->fixture->siblingId()) {
                $sibling = $customer;
            }
        }

        self::assertNotNull($sibling, 'the fixture seeds a sibling customer');

        $domains = $this->loader->related($sibling, 'domains');
        $this->drain();

        $mail = $this->loader->related($domains->result[0], 'mailAccounts');
        $this->drain();

        self::assertSame(array(), $mail->result);
    }

    public function testAManyHasOneEdgeCostsOneQueryForEveryChild(): void
    {
        $rows = $this->db->rows(
            'SELECT * FROM mail_users WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        $mailboxes = array();

        foreach ($rows as $row) {
            $model = new MailAccountModel($this->db->pdo());
            $model->_mapper->readArray($model, $row);
            $mailboxes[] = $model;
        }

        self::assertCount(2, $mailboxes, 'the fixture seeds a mailbox and a forward');

        $loader = $this->loader;
        $deferreds = array();

        $count = $this->db->countQueries(function () use ($loader, $mailboxes, &$deferreds) {
            foreach ($mailboxes as $mailbox) {
                $deferreds[] = $loader->parent($mailbox, 'domain');
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
        self::assertInstanceOf(DomainModel::class, $deferreds[0]->result);
        self::assertSame(
            $this->fixture->domainName(), $deferreds[0]->result->domain_name
        );
    }

    public function testTheSameParentAskedForTwiceCostsOneQuery(): void
    {
        // A deep document reaches the same domain from the mailbox and from
        // the forward. Both mail accounts share a parent, so the IN clause
        // de-duplicates and one row comes back - but the assertion that
        // matters is that the second ask does not open a second query.
        $customers = $this->customers();
        $loader = $this->loader;

        $count = $this->db->countQueries(function () use ($loader, $customers) {
            $loader->related($customers[0], 'domains');
            SyncPromise::runQueue();
            $loader->related($customers[0], 'domains');
            SyncPromise::runQueue();
        });

        self::assertSame(1, $count, 'the second ask must be served from the model');
    }

    public function testByColumnMemoisesAcrossLevels(): void
    {
        $loader = $this->loader;
        $resellerId = $this->fixture->resellerId();

        $count = $this->db->countQueries(function () use ($loader, $resellerId) {
            $loader->byColumn(AdminModel::class, 'created_by', $resellerId);
            SyncPromise::runQueue();
            $loader->byColumn(AdminModel::class, 'created_by', $resellerId);
            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testTheDatabaseToSqlUsersEdgeLoadsThroughAnorm(): void
    {
        // Named in spec section 10.1's list of edges the loader must cover.
        $rows = $this->db->rows(
            'SELECT * FROM sql_database WHERE sqld_id = ?',
            array($this->fixture->sqlDatabaseId())
        );
        $database = new SqlDatabaseModel($this->db->pdo());
        $database->_mapper->readArray($database, $rows[0]);

        $users = $this->loader->related($database, 'users');
        $this->drain();

        self::assertCount(1, $users->result);
        self::assertSame(Fixture::PREFIX . '_u1', $users->result[0]->sqlu_name);
    }

    public function testNoAnormStrategyClassIsLoadedByARealBatchLoad(): void
    {
        // The unit suite asserts this for keyed(). This asserts it for the
        // path that actually calls into Anorm, which is the one that would
        // silently fall back to individual loading - the N+1 - if it ever
        // reached the strategy layer at these source counts.
        $customers = $this->customers();

        foreach ($customers as $customer) {
            $this->loader->related($customer, 'domains');
            $this->loader->parent($customer, 'reseller');
        }

        $this->drain();

        foreach (array(
            'Anorm\Relationship\BatchLoadingOrchestrator',
            'Anorm\Relationship\Strategy\QueryStrategySelector',
            'Anorm\Relationship\Strategy\FieldSelectionParser',
            'Anorm\Relationship\Strategy\DataSizeEstimator',
            'Anorm\Relationship\Strategy\JoinWithSelectionLoader'
        ) as $class) {
            self::assertFalse(class_exists($class, false), $class . ' was loaded');
        }
    }
}
