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

use iMSCP\Plugin\SGW_GraphQL\Service\DatabaseExistsException;
use iMSCP\Plugin\SGW_GraphQL\Service\SqlService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeSqlServer;

class SqlServiceTest extends ServiceTestCase
{
    /** @var FakeSqlServer */
    private $sqlServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqlServer = new FakeSqlServer();
    }

    private function service(): SqlService
    {
        return new SqlService($this->kit, function () {
            return $this->sqlServer;
        });
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function databaseId(): string
    {
        return GlobalId::encode(NodeType::SQL_DATABASE, $this->fixture->sqlDatabaseId());
    }

    private function sqlUserId(): string
    {
        return GlobalId::encode(NodeType::SQL_USER, $this->fixture->sqlUserId());
    }

    private function newUser(array $extra = array()): array
    {
        return array_merge(array(
            'databaseId' => $this->databaseId(), 'name' => 'sgwt_new', 'host' => 'localhost', 'password' => 'Sql0Password'
        ), $extra);
    }

    /** A second database of the customer's, with no users. */
    private function secondDatabase(): int
    {
        return $this->insert('sql_database', array('domain_id' => $this->fixture->domainId(), 'sqld_name' => 'sgwt_two'));
    }

    // ---- databases ------------------------------------------------------

    public function testADatabaseIsCreatedAndItsRowWritten(): void
    {
        $ref = $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));

        self::assertSame(NodeType::SQL_DATABASE, $ref->getTag());
        self::assertSame(array(array('createDatabase', 'sgwt_new')), $this->sqlServer->operations);
        self::assertSame(
            array('domain_id' => (string)$this->fixture->domainId(), 'sqld_name' => 'sgwt_new'),
            array_map('strval', $this->db->row('SELECT domain_id, sqld_name FROM sql_database WHERE sqld_id = ?', array($ref->getKey())))
        );
        self::assertSame(array('onBeforeAddSqlDb', 'onAfterAddSqlDb'), $this->core->eventNames());
        self::assertSame(array('dbName' => 'sgwt_new'), $this->core->events[0][1]);
        self::assertSame(array('dbId' => $ref->getKey(), 'dbName' => 'sgwt_new'), $this->core->events[1][1]);
        self::assertSame(0, $this->core->requests, 'synchronous: nothing for the daemon to do');
        self::assertSame('A new database (sgwt_new) has been created by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testTheDomainIdMayBePrefixedOrSuffixed(): void
    {
        $domainId = $this->fixture->domainId();

        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'a', 'prefix' => 'START'));
        self::assertTrue($this->sqlServer->databaseExists($domainId . '_a'));

        $this->db->execute('UPDATE domain SET domain_sqld_limit = 0 WHERE domain_id = ?', array($domainId));
        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'b', 'prefix' => 'END'));
        self::assertTrue($this->sqlServer->databaseExists('b_' . $domainId));
    }

    public function testAPanelThatPlacesTheDomainIdAllowsOnlyThatPlacement(): void
    {
        $this->reconfigure(array('MYSQL_PREFIX' => 'infront'));

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'a', 'prefix' => 'END'));
        });
        self::assertSame(array('field' => 'input.prefix', 'required' => 'START'), $e->getExtensions());

        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'a'));
        self::assertTrue($this->sqlServer->databaseExists($this->fixture->domainId() . '_a'));
    }

    public function testATooLongOrReservedNameIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => str_repeat('x', 65)));
        });
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'mysql'));
        });

        self::assertSame(array(), $this->sqlServer->operations);
    }

    public function testADatabaseTheServerAlreadyHasIsAConflict(): void
    {
        // D3: created directly through the server, with no sql_database row,
        // the way an orphaned or racing database would look.
        $this->sqlServer->createDatabase('sgwt_new');

        try {
            $this->refused(ErrorCode::CONFLICT, function () {
                $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));
            });

            self::assertSame(
                array(array('createDatabase', 'sgwt_new')), $this->sqlServer->operations,
                'the pre-check refuses before any further DDL is attempted'
            );
            self::assertTrue($this->sqlServer->databaseExists('sgwt_new'), 'the existing database must survive the refused attempt');
        } finally {
            $this->sqlServer->dropDatabase('sgwt_new');
        }
    }

    public function testTheCompensatingDropIsNotReachedWhenCreateItselfFails(): void
    {
        // D3: the pre-check passes (nothing named this on the fixture's
        // server), but the CREATE itself loses a race and fails. The
        // compensating drop must never run for a database this call did not
        // create.
        $this->sqlServer->failCreateDatabase['sgwt_new'] = new DatabaseExistsException('The database sgwt_new already exists.');

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));
        });

        self::assertSame(array(), $this->sqlServer->operations, 'no createDatabase or dropDatabase was recorded');
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        // Limit 2; the fixture has one.
        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_a'));

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_b'));
        });

        self::assertSame(array('quota' => 'sqlDatabases', 'limit' => 2, 'used' => 2), $e->getExtensions());
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_sqld_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));
        });
    }

    public function testADatabaseWhoseRowCannotBeWrittenIsDroppedAgain(): void
    {
        // sqld_name is unique. The fake does not know the fixture's
        // sgwt_shop exists, so the server step succeeds and the row fails.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_shop'));
        });

        self::assertSame(
            array(array('createDatabase', 'sgwt_shop'), array('dropDatabase', 'sgwt_shop')),
            $this->sqlServer->operations
        );
    }

    public function testDeletingADatabaseDropsItsOnlyGrantedUsersFirst(): void
    {
        $ref = $this->service()->deleteDatabase($this->caller('customer'), $this->databaseId());

        self::assertSame(
            array(array('dropUser', 'sgwt_u1', 'localhost'), array('dropDatabase', 'sgwt_shop')),
            $this->sqlServer->operations
        );
        self::assertNull($this->db->value('SELECT sqld_id FROM sql_database WHERE sqld_id = ?', array($this->fixture->sqlDatabaseId())));
        self::assertNull($this->db->value('SELECT sqlu_id FROM sql_user WHERE sqlu_id = ?', array($this->fixture->sqlUserId())));
        self::assertSame(
            array('onBeforeDeleteSqlDb', 'onBeforeDeleteSqlUser', 'onAfterDeleteSqlUser', 'onAfterSqlDb'),
            $this->core->eventNames(),
            'Events::onAfterDeleteSqlDb is the string onAfterSqlDb (M15)'
        );
        self::assertSame('sgwt_shop', $ref->getSnapshot()['sqld_name']);
        self::assertSame($this->fixture->customerId(), (int)$ref->getSnapshot()['domain_admin_id']);
        self::assertSame(
            'sgwtcustomer deleted SQL database with ID ' . $this->fixture->sqlDatabaseId(),
            $this->core->logs[0][0]
        );
    }

    public function testAUserGrantedElsewhereTooOnlyLosesThisGrant(): void
    {
        $two = $this->secondDatabase();
        $this->insert('sql_user', array('sqld_id' => $two, 'sqlu_name' => 'sgwt_u1', 'sqlu_host' => 'localhost'));

        $this->service()->deleteDatabase($this->caller('customer'), $this->databaseId());

        self::assertSame(
            array(array('revokeDatabase', 'sgwt_u1', 'localhost', 'sgwt_shop'), array('dropDatabase', 'sgwt_shop')),
            $this->sqlServer->operations
        );
    }

    // ---- users ----------------------------------------------------------

    public function testANewUserIsCreatedAndGranted(): void
    {
        $ref = $this->service()->createUser($this->caller('customer'), $this->newUser());

        self::assertSame(NodeType::SQL_USER, $ref->getTag());
        self::assertSame(
            array(array('createUser', 'sgwt_new', 'localhost'), array('grantDatabase', 'sgwt_new', 'localhost', 'sgwt_shop')),
            $this->sqlServer->operations
        );
        self::assertSame(
            array('sqld_id' => (string)$this->fixture->sqlDatabaseId(), 'sqlu_name' => 'sgwt_new', 'sqlu_host' => 'localhost'),
            array_map('strval', $this->db->row('SELECT sqld_id, sqlu_name, sqlu_host FROM sql_user WHERE sqlu_id = ?', array($ref->getKey())))
        );
        self::assertSame(array('onBeforeAddSqlUser', 'onAfterAddSqlUser'), $this->core->eventNames());
        self::assertSame(array(
            'SqlUserId'       => $ref->getKey(),
            'SqlUsername'     => 'sgwt_new',
            'SqlUserHost'     => 'localhost',
            'SqlUserPassword' => 'Sql0Password',
            'SqlDatabaseId'   => $this->fixture->sqlDatabaseId()
        ), $this->core->events[1][1]);
        self::assertSame('A SQL user has been added by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testTheHostDefaultsToThePanelsSetting(): void
    {
        $input = $this->newUser();
        unset($input['host']);

        $this->reconfigure(array('DATABASE_USER_HOST' => '127.0.0.1'));
        $this->service()->createUser($this->caller('customer'), $input);
        self::assertTrue($this->sqlServer->userExists('sgwt_new', 'localhost'), '127.0.0.1 is offered as localhost');
    }

    public function testAnInvalidHostIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('host' => 'bad host')));
        });

        self::assertSame('input.host', $e->getExtensions()['field']);
    }

    public function testATooLongOrReservedUserNameIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('name' => str_repeat('u', 17))));
        });
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('name' => 'root')));
        });
    }

    public function testExactlyOneOfNameOrExistingUserIsGiven(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), array('databaseId' => $this->databaseId()));
        });
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('existingUserId' => $this->sqlUserId())));
        });
    }

    public function testAUserTheServerAlreadyHasIsAConflict(): void
    {
        $this->sqlServer->users['sgwt_new@localhost'] = 'x';

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser());
        });
    }

    public function testTheUserLimitIsAskedBeforeTheUserIsCreated(): void
    {
        // C11 item 3. Limit 1, and the fixture has one distinct user name.
        $this->db->execute('UPDATE domain SET domain_sqlu_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser());
        });

        self::assertSame(array(), $this->sqlServer->operations);
    }

    public function testANameTheCustomerAlreadyHasDoesNotCountAgain(): void
    {
        // Counting.php:586 counts DISTINCT sqlu_name.
        $this->db->execute('UPDATE domain SET domain_sqlu_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->service()->createUser($this->caller('customer'), $this->newUser(array('name' => 'sgwt_u1', 'host' => '%')));

        self::assertTrue($this->sqlServer->userExists('sgwt_u1', '%'));
    }

    public function testAnExistingUserIsGrantedAnotherDatabase(): void
    {
        $two = $this->secondDatabase();

        $ref = $this->service()->createUser($this->caller('customer'), array(
            'databaseId' => GlobalId::encode(NodeType::SQL_DATABASE, $two), 'existingUserId' => $this->sqlUserId()
        ));

        self::assertSame(array(array('grantDatabase', 'sgwt_u1', 'localhost', 'sgwt_two')), $this->sqlServer->operations);
        self::assertSame((string)$two, (string)$this->db->value('SELECT sqld_id FROM sql_user WHERE sqlu_id = ?', array($ref->getKey())));
        self::assertSame('', $this->core->events[0][1]['SqlUserPassword']);
    }

    public function testGrantingADatabaseTheUserAlreadyHasIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createUser($this->caller('customer'), array(
                'databaseId' => $this->databaseId(), 'existingUserId' => $this->sqlUserId()
            ));
        });
    }

    public function testAnotherCustomersUserCannotBeGranted(): void
    {
        // The reseller reaches both customers; the user is still not this
        // database's customer's.
        $siblingDb = $this->insert('sql_database', array('domain_id' => $this->fixture->siblingDomainId(), 'sqld_name' => 'sgwt_sib'));
        $siblingUser = $this->insert('sql_user', array('sqld_id' => $siblingDb, 'sqlu_name' => 'sgwt_sib', 'sqlu_host' => 'localhost'));

        $e = $this->refused(ErrorCode::NOT_FOUND, function () use ($siblingUser) {
            $this->service()->createUser($this->caller('reseller'), array(
                'databaseId' => $this->databaseId(), 'existingUserId' => GlobalId::encode(NodeType::SQL_USER, $siblingUser)
            ));
        });

        self::assertSame('input.existingUserId', $e->getExtensions()['field']);
    }

    public function testAPasswordIsSetOnTheServer(): void
    {
        $this->service()->setUserPassword($this->caller('customer'), $this->sqlUserId(), 'Sql0Password2');

        self::assertSame(array(array('setPassword', 'sgwt_u1', 'localhost')), $this->sqlServer->operations);
        self::assertSame(array('onBeforeEditSqlUser', 'onAfterEditSqlUser'), $this->core->eventNames());
        self::assertSame('sgwtcustomer updated sgwt_u1@localhost SQL user password.', $this->core->logs[0][0]);
    }

    public function testAWeakPasswordIsRefusedOnTheArgument(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->setUserPassword($this->caller('customer'), $this->sqlUserId(), 'short');
        });

        self::assertSame('password', $e->getExtensions()['field']);
    }

    public function testDeletingAUsersOnlyGrantDropsTheUser(): void
    {
        $ref = $this->service()->deleteUser($this->caller('customer'), $this->sqlUserId());

        self::assertSame(array(array('dropUser', 'sgwt_u1', 'localhost')), $this->sqlServer->operations);
        self::assertNull($this->db->value('SELECT sqlu_id FROM sql_user WHERE sqlu_id = ?', array($this->fixture->sqlUserId())));
        self::assertSame('sgwt_u1', $ref->getSnapshot()['sqlu_name']);
        self::assertSame(array('onBeforeDeleteSqlUser', 'onAfterDeleteSqlUser'), $this->core->eventNames());
        self::assertSame(
            array('sqlUserId' => $this->fixture->sqlUserId(), 'sqlUsername' => 'sgwt_u1', 'sqlUserHost' => 'localhost'),
            $this->core->events[0][1]
        );
        self::assertSame('sgwtcustomer deleted SQL user with ID ' . $this->fixture->sqlUserId(), $this->core->logs[0][0]);
    }
}
