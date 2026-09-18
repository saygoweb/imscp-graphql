<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Config\FileConfig;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Registry;
use PDOException;

/**
 * The statements i-MSCP issues against the SQL server itself.
 *
 * CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:57-62,
 *   sql_user_add.php:276-298, sql_change_password.php:75-84, and
 *   delete_sql_database() / sql_delete_user() in gui/include/Shared.php:623-777,
 *   which cannot be called because they interleave this DDL with row writes
 *   (measurement M5). Retire when SqlDatabaseService and SqlUserService land in
 *   core (spec section 21, C3 row 1).
 */
final class MariaDbSqlServer implements SqlServer
{
    /** MariaDB's ER_DB_CREATE_EXISTS. */
    const DB_CREATE_EXISTS = 1007;

    /** @var Db */
    private $db;

    /**
     * MariaDB, and MySQL before 5.7.6, take the older password syntax
     * (sql_user_add.php:277).
     *
     * @var bool
     */
    private $legacyPasswordSyntax;

    public function __construct(Db $db, string $serverType, string $serverVersion)
    {
        $this->db = $db;
        $this->legacyPasswordSyntax = $serverType === 'mariadb' || version_compare($serverVersion, '5.7.6', '<');
    }

    public static function fromPanel(Db $db): self
    {
        $config = new FileConfig(Registry::get('config')['CONF_DIR'] . '/mysql/mysql.data');

        return new self($db, (string)$config['SQLD_TYPE'], (string)$config['SQLD_VERSION']);
    }

    public function databaseExists(string $name): bool
    {
        // CORE-DEBT(C11): C11 item 7 - an exact name, not SHOW DATABASES LIKE.
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            array($name)
        ) > 0;
    }

    /**
     * D3: no IF NOT EXISTS. That clause made an existing database a silent
     * success, so the loser of a create race passed this call, committed its
     * own sql_database row, and then its compensating drop - armed by what it
     * believed was its own CREATE - destroyed the database the winner had
     * just handed to the customer. An existing database is now an error, so
     * SqlService can tell its own CREATE apart from one that merely found the
     * database already there.
     *
     * @throws DatabaseExistsException the database is already there
     */
    public function createDatabase(string $name): void
    {
        try {
            $this->db->execute('CREATE DATABASE ' . self::identifier($name));
        } catch (PDOException $e) {
            if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === self::DB_CREATE_EXISTS) {
                throw new DatabaseExistsException(sprintf('The database %s already exists.', $name), 0, $e);
            }

            throw $e;
        }
    }

    public function dropDatabase(string $name): void
    {
        $this->db->execute('DROP DATABASE IF EXISTS ' . self::identifier($name));
    }

    public function userExists(string $user, string $host): bool
    {
        return (int)$this->db->value(
            'SELECT COUNT(User) FROM mysql.user WHERE User = ? AND Host = ?',
            array($user, $host)
        ) > 0;
    }

    public function createUser(string $user, string $host, string $password): void
    {
        $this->db->execute(
            $this->legacyPasswordSyntax
                ? 'CREATE USER ?@? IDENTIFIED BY ?'
                : 'CREATE USER ?@? IDENTIFIED BY ? PASSWORD EXPIRE NEVER',
            array($user, $host, $password)
        );
    }

    public function grantDatabase(string $user, string $host, string $database): void
    {
        $this->db->execute(
            sprintf('GRANT ALL PRIVILEGES ON %s.* TO ?@?', self::identifier(self::grantPattern($database))),
            array($user, $host)
        );
    }

    public function revokeDatabase(string $user, string $host, string $database): void
    {
        // sql_delete_user()'s own statements (Shared.php:680-684). Measurement
        // M21: they still work on MariaDB 10.11.
        $this->db->execute(
            'DELETE FROM mysql.db WHERE Host = ? AND Db = ? AND User = ?',
            array($host, self::grantPattern($database), $user)
        );
        $this->db->execute('FLUSH PRIVILEGES');
    }

    public function dropUser(string $user, string $host): void
    {
        // Shared.php:672-677.
        $this->db->execute('DELETE FROM mysql.user WHERE User = ? AND Host = ?', array($user, $host));
        $this->db->execute('DELETE FROM mysql.db WHERE Host = ? AND User = ?', array($host, $user));
        $this->db->execute('FLUSH PRIVILEGES');
    }

    public function setPassword(string $user, string $host, string $password): void
    {
        $this->db->execute(
            $this->legacyPasswordSyntax
                ? 'SET PASSWORD FOR ?@? = PASSWORD(?)'
                : 'ALTER USER ?@? IDENTIFIED BY ? PASSWORD EXPIRE NEVER',
            array($user, $host, $password)
        );
    }

    /**
     * A database name as a GRANT pattern: '_' and '%' are wildcards there, so
     * without this a grant on a_c also opens abc (sql_user_add.php:286-293).
     */
    public static function grantPattern(string $database): string
    {
        return (string)preg_replace('/([%_])/', '\\\\$1', $database);
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
