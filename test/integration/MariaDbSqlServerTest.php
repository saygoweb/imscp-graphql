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
use iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer;
use PDO;
use PDOException;

/**
 * Real DDL against the box's MariaDB. No fixture and no transaction: each of
 * these statements commits whatever is open, so a transaction here would be a
 * lie. Everything is named sgwt_ddl* and removed in setUp() and tearDown(), so
 * a run killed half way is cleaned up by the next.
 */
class MariaDbSqlServerTest extends IntegrationTestCase
{
    const DATABASE  = 'sgwt_ddl_a_c';
    /** What the grant's pattern would match if '_' were left a wildcard. */
    const LOOKALIKE = 'sgwtxddlxaxc';
    const USER      = 'sgwt_ddl_u';
    const HOST      = 'localhost';
    const PASSWORD  = 'Ddl0Password';

    /** @var Db */
    private $db;

    /** @var MariaDbSqlServer */
    private $server;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();

        self::assertFalse(
            $this->db->pdo()->inTransaction(),
            'A transaction is open: the DDL below would commit it. A previous test left it behind.'
        );

        $this->server = MariaDbSqlServer::fromPanel($this->db);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->execute('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->db->execute('DROP DATABASE IF EXISTS `' . self::LOOKALIKE . '`');
        $this->db->execute("DROP USER IF EXISTS '" . self::USER . "'@'" . self::HOST . "'");
    }

    /**
     * A connection as the SQL user, over the server's socket: a user whose host
     * is 'localhost' matches a socket connection, not TCP to 127.0.0.1.
     */
    private function connectAs(string $password): PDO
    {
        $socket = (string)$this->db->value('SELECT @@socket');

        return new PDO('mysql:unix_socket=' . $socket, self::USER, $password, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ));
    }

    /** @return string[] */
    private function databasesVisibleTo(PDO $pdo): array
    {
        return $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testADatabaseIsCreatedFoundAndDropped(): void
    {
        self::assertFalse($this->server->databaseExists(self::DATABASE));

        $this->server->createDatabase(self::DATABASE);
        self::assertTrue($this->server->databaseExists(self::DATABASE));

        $this->server->dropDatabase(self::DATABASE);
        self::assertFalse($this->server->databaseExists(self::DATABASE));
    }

    public function testExistenceIsAnExactNameNotAPattern(): void
    {
        // C11 item 7: SHOW DATABASES LIKE 'sgwt_ddl_a_c' matches this.
        $this->server->createDatabase(self::LOOKALIKE);

        self::assertFalse($this->server->databaseExists(self::DATABASE));
    }

    public function testAUserIsCreatedFoundAndDropped(): void
    {
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);
        self::assertTrue($this->server->userExists(self::USER, self::HOST));
        $this->connectAs(self::PASSWORD);

        $this->server->dropUser(self::USER, self::HOST);
        self::assertFalse($this->server->userExists(self::USER, self::HOST));
    }

    public function testAGrantOpensTheDatabaseAndNotALookalike(): void
    {
        $this->server->createDatabase(self::DATABASE);
        $this->server->createDatabase(self::LOOKALIKE);
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);

        $this->server->grantDatabase(self::USER, self::HOST, self::DATABASE);

        $visible = $this->databasesVisibleTo($this->connectAs(self::PASSWORD));
        self::assertContains(self::DATABASE, $visible);
        self::assertNotContains(self::LOOKALIKE, $visible, 'the grant pattern was not escaped');
    }

    public function testRevokingOneGrantLeavesTheUser(): void
    {
        $this->server->createDatabase(self::DATABASE);
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);
        $this->server->grantDatabase(self::USER, self::HOST, self::DATABASE);

        $this->server->revokeDatabase(self::USER, self::HOST, self::DATABASE);

        self::assertTrue($this->server->userExists(self::USER, self::HOST));
        self::assertNotContains(self::DATABASE, $this->databasesVisibleTo($this->connectAs(self::PASSWORD)));
    }

    public function testSettingThePasswordChangesTheLogin(): void
    {
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);

        $this->server->setPassword(self::USER, self::HOST, 'Ddl0Changed');

        $this->connectAs('Ddl0Changed');

        $this->expectException(PDOException::class);
        $this->connectAs(self::PASSWORD);
    }

    public function testTheGrantPatternEscapesBothWildcards(): void
    {
        self::assertSame('a\_b\%c', MariaDbSqlServer::grantPattern('a_b%c'));
    }

    public function testAnIdentifierWithABacktickIsQuotedNotInjected(): void
    {
        // The panel refuses no character in a database name but length. A
        // backtick must stay inside the identifier.
        $name = 'sgwt_ddl`x';

        try {
            $this->server->createDatabase($name);
            self::assertTrue($this->server->databaseExists($name));
        } finally {
            $this->server->dropDatabase($name);
        }

        self::assertFalse($this->server->databaseExists($name));
    }
}
