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

/**
 * The statements the panel issues against MariaDB itself for a customer's
 * databases and users.
 *
 * Every one of them implicitly commits (measurement M5), so none may run
 * inside a transaction a caller expects to roll back, and none may run inside
 * a test's fixture. Behind this port for that reason (decision D12);
 * MariaDbSqlServer (Task 12) is the implementation and is tested on its own.
 */
interface SqlServer
{
    public function databaseExists(string $name): bool;

    public function createDatabase(string $name): void;

    public function dropDatabase(string $name): void;

    public function userExists(string $user, string $host): bool;

    public function createUser(string $user, string $host, string $password): void;

    /** ALL PRIVILEGES on one database, with its name's LIKE wildcards escaped. */
    public function grantDatabase(string $user, string $host, string $database): void;

    /** Removes the grant on one database and leaves the user. */
    public function revokeDatabase(string $user, string $host, string $database): void;

    public function dropUser(string $user, string $host): void;

    public function setPassword(string $user, string $host, string $password): void;
}
