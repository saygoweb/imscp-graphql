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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

class SqlMutationsTest extends AuthzTestCase
{
    public function testADeletedDatabaseAnswersAsItWas(): void
    {
        $id = GlobalId::encode(NodeType::SQL_DATABASE, $this->fixture->sqlDatabaseId());

        $result = $this->execute(
            'mutation($id: ID!) { sqlDatabaseDelete(id: $id) { id name customer { username } } }',
            array('id' => $id),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(
            array('id' => $id, 'name' => 'sgwt_shop', 'customer' => array('username' => 'sgwtcustomer')),
            $result['data']['sqlDatabaseDelete']
        );
    }

    public function testACreatedUserReadsBackWithItsHost(): void
    {
        $result = $this->execute(
            'mutation($input: SqlUserCreateInput!) { sqlUserCreate(input: $input) { name host databases { name } } }',
            array('input' => array(
                'databaseId' => GlobalId::encode(NodeType::SQL_DATABASE, $this->fixture->sqlDatabaseId()),
                'name' => 'sgwt_new', 'host' => '%', 'password' => 'Sql0Password'
            )),
            $this->fixture->identity('customer')
        );

        self::assertSame(
            array('name' => 'sgwt_new', 'host' => '%', 'databases' => array(array('name' => 'sgwt_shop'))),
            $result['data']['sqlUserCreate']
        );
    }
}
