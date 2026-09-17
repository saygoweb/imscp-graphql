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

class DbTest extends IntegrationTestCase
{
    private function db(): Db
    {
        return Db::fromPanel();
    }

    public function testItSharesThePanelsConnection(): void
    {
        // Same connection, same transaction: spec section 3.2. A second
        // connection would not see a mutation's uncommitted rows.
        self::assertSame(
            \iMSCP\Database\DatabaseMySQL::getPDO(),
            $this->db()->pdo()
        );
    }

    public function testThePanelUsesEmulatedPrepares(): void
    {
        // countQueries() counts server round trips, and that is only exact
        // because PDO::ATTR_EMULATE_PREPARES makes prepare() free (see
        // Repository\Db::countQueries()'s doc comment). Read the live
        // attribute back from the panel's own PDO handle, not a constant:
        // the panel's DatabaseMySQL.php carries a "# FIXME should be FALSE"
        // on this very attribute, and the day someone acts on it, every
        // query count Tasks 12-17 assert on would quietly start measuring
        // something other than what it thinks it is. This test is the
        // tripwire: it fails, by name, on that day, instead of leaving it
        // to whoever first disbelieves a passing N+1 test.
        self::assertTrue(
            (bool)$this->db()->pdo()->getAttribute(\PDO::ATTR_EMULATE_PREPARES),
            'the panel turned off emulated prepares; Db::countQueries() no '
            . 'longer counts one statement per Db call and must be revisited'
        );
    }

    public function testRowsReturnsAListOfAssociativeRows(): void
    {
        $rows = $this->db()->rows(
            "SELECT admin_id, admin_name FROM admin WHERE admin_type = 'admin' LIMIT 2"
        );

        self::assertNotEmpty($rows, 'the box always has an administrator');
        self::assertArrayHasKey('admin_name', $rows[0]);
        self::assertArrayNotHasKey(0, $rows[0], 'associative, not numeric');
    }

    public function testRowReturnsNullWhenThereIsNoRow(): void
    {
        self::assertNull($this->db()->row('SELECT admin_id FROM admin WHERE admin_id = ?', array(-1)));
    }

    public function testValueReturnsTheFirstColumn(): void
    {
        self::assertSame('1', (string)$this->db()->value('SELECT 1'));
    }

    public function testValueReturnsNullWhenThereIsNoRow(): void
    {
        self::assertNull($this->db()->value('SELECT admin_id FROM admin WHERE admin_id = ?', array(-1)));
    }

    public function testPlaceholdersBuildsAnInClause(): void
    {
        self::assertSame('?,?,?', $this->db()->placeholders(3));
        self::assertSame('?', $this->db()->placeholders(1));
    }

    public function testCountQueriesCountsExactlyTheStatementsRun(): void
    {
        $db = $this->db();

        $count = $db->countQueries(function () use ($db) {
            $db->value('SELECT 1');
            $db->value('SELECT 2');
            $db->value('SELECT 3');
        });

        self::assertSame(3, $count);
    }

    public function testCountQueriesReturnsZeroForNoQueries(): void
    {
        self::assertSame(0, $this->db()->countQueries(function () {
        }));
    }

    public function testAServiceTransactionNestsInsideTheFixturesAndIsUndoneWithIt(): void
    {
        // The arrangement every service test in this plan relies on: the
        // fixture's transaction is the real one, the service's is a savepoint
        // inside it, and the service's commit is a savepoint release that the
        // fixture's rollback still undoes.
        $db = $this->db();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $db->beginTransaction();
            $db->execute(
                "UPDATE domain SET domain_subd_limit = 77 WHERE domain_id = ?",
                array($fixture->domainId())
            );
            $db->commit();

            self::assertSame(
                '77',
                (string)$db->value(
                    'SELECT domain_subd_limit FROM domain WHERE domain_id = ?',
                    array($fixture->domainId())
                ),
                'the committed savepoint is visible inside the fixture'
            );

            $domainId = $fixture->domainId();
        } finally {
            $fixture->rollBack();
        }

        self::assertNull(
            $db->value('SELECT domain_id FROM domain WHERE domain_id = ?', array($domainId)),
            'the fixture rollback undid the service commit as well'
        );
    }

    public function testACoreHelperThatOpensItsOwnTransactionNestsToo(): void
    {
        // M2's failure, turned round: DatabaseMySQL::beginTransaction() inside
        // the fixture is a savepoint, not a refused second transaction.
        $db = $this->db();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $panel = \iMSCP\Database\DatabaseMySQL::getInstance();
            $panel->beginTransaction();
            $panel->commit();
            $this->addToAssertionCount(1);
        } finally {
            $fixture->rollBack();
        }
    }

    public function testTheFixtureUnwindsATransactionAFailingTestLeftOpen(): void
    {
        $db = $this->db();
        $fixture = new Fixture($db);
        $fixture->seed();
        $domainId = $fixture->domainId();

        // A service that began and then died before rolling back.
        $db->beginTransaction();
        $db->beginTransaction();

        $fixture->rollBack();

        self::assertFalse($db->pdo()->inTransaction());
        self::assertNull(
            $db->value('SELECT domain_id FROM domain WHERE domain_id = ?', array($domainId))
        );
    }
}
