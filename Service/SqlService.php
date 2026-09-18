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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use LogicException;
use Throwable;

/**
 * SQL databases and SQL users: the synchronous exception (spec section 2.1).
 *
 * No status, no daemon, nothing to settle, so spec section 8.1's steps 5 and
 * 9 do not apply. And the server's DDL implicitly commits, so step 8's single
 * transaction cannot hold both the DDL and the row: the DDL goes first, the
 * row in a transaction second, and a row that fails undoes the DDL.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/sql_database_add.php,
 *   sql_user_add.php, sql_change_password.php and delete_sql_database() /
 *   sql_delete_user() in gui/include/Shared.php. Retire when
 *   SqlDatabaseService and SqlUserService land in core (spec section 21, C3
 *   row 1).
 */
final class SqlService
{
    /** sql_database_add.php:50. */
    const RESERVED_DATABASES = array('information_schema', 'mysql', 'performance_schema', 'sys', 'test');

    /** sql_user_add.php:223. */
    const RESERVED_USERS = array('debian-sys-maint', 'mysql.user', 'root');

    const PREFIXES = array('NONE', 'START', 'END');

    /** @var Toolkit */
    private $kit;

    /** @var callable fn(): SqlServer */
    private $sqlServer;

    public function __construct(Toolkit $kit, callable $sqlServer)
    {
        $this->kit = $kit;
        $this->sqlServer = $sqlServer;
    }

    public function createDatabase(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $domain = $kit->guard()->target($caller, $input['domainId'] ?? null, array(NodeType::DOMAIN), Scope::SQL_WRITE, 'input.domainId');
        $account = $kit->accounts()->customer($domain->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_sqld_limit'),
            $kit->counts()->sqlDatabases(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'sql');

        // 6. CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:36-55.
        $name = trim((string)($input['name'] ?? ''), ' ');

        if ($name === '') {
            throw Guard::badInput('input.name', 'A database name is required.');
        }

        $name = $this->prefixed($name, $input, $account->getDomainId());

        if (strlen($name) > 64) {
            throw Guard::badInput('input.name', 'The database name is too long.', array('maximum' => 64));
        }

        if (in_array($name, self::RESERVED_DATABASES, true)) {
            throw Guard::badInput('input.name', 'That database name is reserved.');
        }

        // 7.
        Guard::requireQuota($quota, 'sqlDatabases');

        $server = $this->server();

        if ($server->databaseExists($name)) {
            throw Guard::conflict(sprintf('The database %s already exists.', $name));
        }

        // CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:57-68.
        $core->dispatch(Events::onBeforeAddSqlDb, array('dbName' => $name));

        // D3: caught narrowly. The pre-check above is the cheap, friendly
        // path for the ordinary case; this is what catches the loser of a
        // create race - a CREATE that failed because the database was
        // already there reads to the client exactly like the pre-check's
        // refusal. Anything else is a real failure and propagates.
        try {
            $server->createDatabase($name);
        } catch (DatabaseExistsException $e) {
            throw Guard::conflict(sprintf('The database %s already exists.', $name));
        }

        // Only reached once createDatabase() above has actually succeeded,
        // so the compensating drop below can never destroy a database this
        // call did not create.
        try {
            $id = $kit->writer()->run(function () use ($kit, $core, $account, $name) {
                $kit->db()->execute(
                    'INSERT INTO sql_database (domain_id, sqld_name) VALUES (?, ?)',
                    array($account->getDomainId(), $name)
                );
                $id = $kit->db()->lastInsertId();
                $core->dispatch(Events::onAfterAddSqlDb, array('dbId' => $id, 'dbName' => $name));

                return $id;
            });
        } catch (Throwable $e) {
            $server->dropDatabase($name);

            throw $e;
        }

        $core->writeLog(sprintf('A new database (%s) has been created by %s', $name, $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::SQL_DATABASE, $id);
    }

    public function deleteDatabase(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::SQL_DATABASE), Scope::SQL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0, 'sql');

        $row = $kit->db()->row(
            '
                SELECT sd.sqld_id, sd.domain_id, sd.sqld_name, d.domain_admin_id
                FROM sql_database AS sd JOIN domain AS d ON d.domain_id = sd.domain_id
                WHERE sd.sqld_id = ?
            ',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        $server = $this->server();
        $databaseId = (int)$row['sqld_id'];
        $name = (string)$row['sqld_name'];
        $params = array('sqlDbId' => $databaseId, 'sqlDatabaseName' => $name);

        // CORE-DEBT(C3): transcribed from delete_sql_database(), gui/include/Shared.php:706-777.
        $core->dispatch(Events::onBeforeDeleteSqlDb, $params);

        foreach ($kit->db()->rows(
            'SELECT sqlu_id, sqld_id, sqlu_name, sqlu_host FROM sql_user WHERE sqld_id = ?',
            array($databaseId)
        ) as $user) {
            $this->removeUser($server, $user + array('sqld_name' => $name));
        }

        $server->dropDatabase($name);
        $kit->db()->execute('DELETE FROM sql_database WHERE domain_id = ? AND sqld_id = ?', array($row['domain_id'], $databaseId));

        // Measurement M15: this constant's value is 'onAfterSqlDb'.
        $core->dispatch(Events::onAfterDeleteSqlDb, $params);

        $core->writeLog(sprintf('%s deleted SQL database with ID %s', $caller->getUsername(), $databaseId), E_USER_NOTICE);

        return new ObjectRef(NodeType::SQL_DATABASE, $databaseId, $row);
    }

    public function createUser(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $database = $kit->guard()->target($caller, $input['databaseId'] ?? null, array(NodeType::SQL_DATABASE), Scope::SQL_WRITE, 'input.databaseId');
        $account = $kit->accounts()->customer($database->getOwnerId());

        // 4. sql_user_add.php:379 asks 'sql'; a withheld user limit leaves
        //    nothing to add either.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_sqlu_limit'),
            $kit->counts()->sqlUsers(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0 && $quota->isEnabled(), 'sql');

        $databaseRow = $kit->db()->row('SELECT sqld_id, sqld_name FROM sql_database WHERE sqld_id = ?', array((int)$database->getKey()));

        if ($databaseRow === null) {
            throw Guard::notFound();
        }

        // 6.
        $isNew = isset($input['name']);

        if ($isNew === isset($input['existingUserId'])) {
            throw Guard::badInput('input.name', 'Give either name, for a new user, or existingUserId, to grant an existing one.');
        }

        $server = $this->server();
        $password = null;

        if ($isNew) {
            // CORE-DEBT(C3): transcribed from gui/public/client/sql_user_add.php:156-229.
            $name = trim((string)$input['name'], ' ');

            if ($name === '') {
                throw Guard::badInput('input.name', 'A user name is required.');
            }

            $host = isset($input['host'])
                ? trim((string)$input['host'], ' ')
                : (string)$core->config('DATABASE_USER_HOST', 'localhost');

            if ($host === '127.0.0.1') {
                // What the page offers as the default (sql_user_add.php:364).
                $host = 'localhost';
            }

            $host = $core->toAscii($host);

            if ($host === '' || !$core->isValidSqlHost($host)) {
                throw Guard::badInput('input.host', 'Invalid SQL user host.');
            }

            $password = $this->password($input['password'] ?? null, 'input.password');
            $name = $this->prefixed($name, $input, $account->getDomainId());

            if (strlen($name) > 16) {
                throw Guard::badInput('input.name', 'The SQL user name is too long.', array('maximum' => 16));
            }

            if (in_array($name, self::RESERVED_USERS, true)) {
                throw Guard::badInput('input.name', 'That SQL user name is reserved.');
            }

            // 7. CORE-DEBT(C11): C11 item 3 - asked before the user exists.
            //    Counting.php:586 counts DISTINCT names, so a name the customer
            //    already has on another host costs nothing more.
            $known = (int)$kit->db()->value(
                '
                    SELECT COUNT(*) FROM sql_user AS su JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                    WHERE sd.domain_id = ? AND su.sqlu_name = ?
                ',
                array($account->getDomainId(), $name)
            ) > 0;

            if (!$known) {
                Guard::requireQuota($quota, 'sqlUsers');
            }

            if ($server->userExists($name, $host)) {
                throw Guard::conflict(sprintf('The SQL user %s@%s is not available.', $name, $host));
            }
        } else {
            $user = $kit->guard()->target($caller, $input['existingUserId'], array(NodeType::SQL_USER), Scope::SQL_WRITE, 'input.existingUserId');

            if ($user->getOwnerId() !== $database->getOwnerId()) {
                throw new ApiException(ErrorCode::NOT_FOUND, Guard::notFound()->getMessage(), array('field' => 'input.existingUserId'));
            }

            $userRow = $this->userRow($user);
            $name = (string)$userRow['sqlu_name'];
            $host = (string)$userRow['sqlu_host'];

            if ((int)$kit->db()->value(
                'SELECT COUNT(*) FROM sql_user WHERE sqld_id = ? AND sqlu_name = ? AND sqlu_host = ?',
                array($databaseRow['sqld_id'], $name, $host)
            ) > 0) {
                throw Guard::conflict('That SQL user is already granted this database.');
            }
        }

        $databaseId = (int)$databaseRow['sqld_id'];
        $databaseName = (string)$databaseRow['sqld_name'];

        // CORE-DEBT(C3): transcribed from gui/public/client/sql_user_add.php:266-307.
        $core->dispatch(Events::onBeforeAddSqlUser, array(
            'SqlUsername' => $name, 'SqlUserHost' => $host, 'SqlUserPassword' => $password ?? ''
        ));

        if ($isNew) {
            $server->createUser($name, $host, $password);
        }

        $server->grantDatabase($name, $host, $databaseName);

        try {
            $id = $kit->writer()->run(function () use ($kit, $core, $databaseId, $name, $host, $password) {
                $kit->db()->execute(
                    'INSERT INTO sql_user (sqld_id, sqlu_name, sqlu_host) VALUES (?, ?, ?)',
                    array($databaseId, $name, $host)
                );
                $id = $kit->db()->lastInsertId();

                $core->dispatch(Events::onAfterAddSqlUser, array(
                    'SqlUserId'       => $id,
                    'SqlUsername'     => $name,
                    'SqlUserHost'     => $host,
                    'SqlUserPassword' => $password ?? '',
                    'SqlDatabaseId'   => $databaseId
                ));

                return $id;
            });
        } catch (Throwable $e) {
            if ($isNew) {
                $server->dropUser($name, $host);
            } else {
                $server->revokeDatabase($name, $host, $databaseName);
            }

            throw $e;
        }

        $core->writeLog(sprintf('A SQL user has been added by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::SQL_USER, $id);
    }

    public function setUserPassword(Identity $caller, string $id, string $password): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::SQL_USER), Scope::SQL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0, 'sql');

        $row = $this->userRow($target);
        $password = $this->password($password, 'password');
        $userId = (int)$row['sqlu_id'];
        $params = array('sqlUserId' => $userId, 'sqlUserPassword' => $password);

        // CORE-DEBT(C3): transcribed from gui/public/client/sql_change_password.php:69-94.
        $core->dispatch(Events::onBeforeEditSqlUser, $params);
        $this->server()->setPassword((string)$row['sqlu_name'], (string)$row['sqlu_host'], $password);
        $core->writeLog(
            sprintf('%s updated %s@%s SQL user password.', $caller->getUsername(), $row['sqlu_name'], $row['sqlu_host']),
            E_USER_NOTICE
        );
        $core->dispatch(Events::onAfterEditSqlUser, $params);

        return new ObjectRef(NodeType::SQL_USER, $userId);
    }

    public function deleteUser(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;

        $target = $kit->guard()->target($caller, $id, array(NodeType::SQL_USER), Scope::SQL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0, 'sql');

        $row = $kit->db()->row(
            '
                SELECT su.sqlu_id, su.sqld_id, su.sqlu_name, su.sqlu_host, sd.sqld_name
                FROM sql_user AS su JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                WHERE su.sqlu_id = ?
            ',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        $this->removeUser($this->server(), $row);
        $kit->core()->writeLog(
            sprintf('%s deleted SQL user with ID %d', $caller->getUsername(), $row['sqlu_id']),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::SQL_USER, (int)$row['sqlu_id'], $row);
    }

    /**
     * One sql_user row: its grant, and the account itself when this was its
     * last grant anywhere on the server.
     *
     * CORE-DEBT(C3): transcribed from sql_delete_user(), gui/include/Shared.php:623-700.
     *
     * @param array<string, mixed> $row sqlu_id, sqlu_name, sqlu_host, sqld_name
     */
    private function removeUser(SqlServer $server, array $row): void
    {
        $kit = $this->kit;
        $params = array(
            'sqlUserId'   => (int)$row['sqlu_id'],
            'sqlUsername' => (string)$row['sqlu_name'],
            'sqlUserHost' => (string)$row['sqlu_host']
        );

        $kit->core()->dispatch(Events::onBeforeDeleteSqlUser, $params);

        $grants = (int)$kit->db()->value(
            'SELECT COUNT(sqlu_id) FROM sql_user WHERE sqlu_name = ? AND sqlu_host = ?',
            array($params['sqlUsername'], $params['sqlUserHost'])
        );

        if ($grants < 2) {
            $server->dropUser($params['sqlUsername'], $params['sqlUserHost']);
        } else {
            $server->revokeDatabase($params['sqlUsername'], $params['sqlUserHost'], (string)$row['sqld_name']);
        }

        $kit->db()->execute('DELETE FROM sql_user WHERE sqlu_id = ?', array($params['sqlUserId']));
        $kit->core()->dispatch(Events::onAfterDeleteSqlUser, $params);
    }

    /**
     * The domain id placed as the panel's settings allow.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:33-43,
     *   and the template's handling of MYSQL_PREFIX (sql_database_add.php:83-106):
     *   'none' lets the customer choose, 'infront' and 'behind' decide for them.
     */
    private function prefixed(string $name, array $input, int $domainId): string
    {
        $mode = (string)$this->kit->core()->config('MYSQL_PREFIX', 'none');
        $requested = isset($input['prefix']) ? (string)$input['prefix'] : null;

        if ($mode === 'infront' || $mode === 'behind') {
            $forced = $mode === 'infront' ? 'START' : 'END';

            if ($requested !== null && $requested !== $forced) {
                throw Guard::badInput(
                    'input.prefix',
                    "The panel's settings decide where the domain id goes.",
                    array('required' => $forced)
                );
            }

            $position = $forced;
        } else {
            $position = $requested ?? 'NONE';
        }

        if (!in_array($position, self::PREFIXES, true)) {
            throw Guard::badInput('input.prefix', 'Unknown prefix position.');
        }

        if ($position === 'START') {
            return $domainId . '_' . $name;
        }

        return $position === 'END' ? $name . '_' . $domainId : $name;
    }

    /**
     * @param mixed $value
     */
    private function password($value, string $field): string
    {
        $core = $this->kit->core();
        $password = trim((string)$value, ' ');

        if ($password === '' || !$core->isAcceptablePassword($password)) {
            throw Guard::badInput($field, "The password does not meet the panel's password policy.", array(
                'minLength'                => max(6, (int)$core->config('PASSWD_CHARS', 6)),
                'requiresLettersAndDigits' => (bool)$core->config('PASSWD_STRONG', false)
            ));
        }

        return $password;
    }

    /**
     * @return array{sqlu_id: string, sqlu_name: string, sqlu_host: string}
     */
    private function userRow(Target $target): array
    {
        $row = $this->kit->db()->row(
            'SELECT sqlu_id, sqlu_name, sqlu_host FROM sql_user WHERE sqlu_id = ?',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        return $row;
    }

    private function server(): SqlServer
    {
        $server = call_user_func($this->sqlServer);

        if (!$server instanceof SqlServer) {
            throw new LogicException('No SQL server is configured for this request.');
        }

        return $server;
    }
}
