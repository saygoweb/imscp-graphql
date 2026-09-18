<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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

use iMSCP\Plugin\SGW_GraphQL\Service\SqlServer;
use Throwable;

/**
 * No DDL: every statement MariaDbSqlServer would issue implicitly commits, and
 * a commit inside a test's fixture leaves the fixture's rows on the box
 * (decision D12). Remembers what exists so a service's existence checks see
 * the effect of its own earlier calls.
 */
final class FakeSqlServer implements SqlServer
{
    /** @var array<int, array> Every call, in order, e.g. array('createDatabase', 'x'). */
    public $operations = array();

    /** @var array<string, true> */
    public $databases = array('mysql' => true, 'information_schema' => true);

    /** @var array<string, string> "user@host" => password */
    public $users = array();

    /**
     * @var array<string, Throwable> name => the exception createDatabase()
     *      throws instead of creating it, once. D3's race: a CREATE that
     *      fails although the pre-check passed.
     */
    public $failCreateDatabase = array();

    public function databaseExists(string $name): bool
    {
        return isset($this->databases[$name]);
    }

    public function createDatabase(string $name): void
    {
        if (isset($this->failCreateDatabase[$name])) {
            $exception = $this->failCreateDatabase[$name];
            unset($this->failCreateDatabase[$name]);

            throw $exception;
        }

        $this->operations[] = array('createDatabase', $name);
        $this->databases[$name] = true;
    }

    public function dropDatabase(string $name): void
    {
        $this->operations[] = array('dropDatabase', $name);
        unset($this->databases[$name]);
    }

    public function userExists(string $user, string $host): bool
    {
        return isset($this->users[$user . '@' . $host]);
    }

    public function createUser(string $user, string $host, string $password): void
    {
        $this->operations[] = array('createUser', $user, $host);
        $this->users[$user . '@' . $host] = $password;
    }

    public function grantDatabase(string $user, string $host, string $database): void
    {
        $this->operations[] = array('grantDatabase', $user, $host, $database);
    }

    public function revokeDatabase(string $user, string $host, string $database): void
    {
        $this->operations[] = array('revokeDatabase', $user, $host, $database);
    }

    public function dropUser(string $user, string $host): void
    {
        $this->operations[] = array('dropUser', $user, $host);
        unset($this->users[$user . '@' . $host]);
    }

    public function setPassword(string $user, string $host, string $password): void
    {
        $this->operations[] = array('setPassword', $user, $host);
        $this->users[$user . '@' . $host] = $password;
    }
}
