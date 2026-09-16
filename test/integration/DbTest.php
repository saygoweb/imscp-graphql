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

// composer.json has no autoload-dev section (Task 8 adds it), so the test
// namespace is not autoloaded; require the base class explicitly rather than
// relying on PHPUnit's own directory scan to have loaded it first (it scans
// alphabetically, and "DbTest.php" sorts before "IntegrationTestCase.php").
require_once __DIR__ . '/IntegrationTestCase.php';

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
}
