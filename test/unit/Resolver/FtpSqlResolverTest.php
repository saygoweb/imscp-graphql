<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class FtpSqlResolverTest extends TestCase
{
    public function testAnFtpUsersIdentifierCarriesItsStringPrimaryKey(): void
    {
        // Decision D2. ftp_users' primary key is userid varchar(255); encoding
        // it as an integer would turn every FTP login into FtpUser:0.
        $shape = FtpSqlResolver::shapeFtp(array(
            'userid'   => 'shop@sgwtcustomer.test',
            'admin_id' => 7,
            'homedir'  => '/var/www/virtual/sgwtcustomer.test',
            'status'   => 'ok'
        ));

        self::assertSame(
            GlobalId::encodeKey(NodeType::FTP_USER, 'shop@sgwtcustomer.test'),
            $shape['id']
        );
        self::assertSame('shop@sgwtcustomer.test', $shape['username']);
        self::assertSame('/var/www/virtual/sgwtcustomer.test', $shape['homeDirectory']);
        self::assertSame(7, $shape['__ownerId']);
        self::assertSame('OK', $shape['provisioning']['state']);
    }

    public function testASqlDatabaseCarriesItsOwnerForTheCustomerEdge(): void
    {
        // sql_database has only a domain_id, so the loader joins `domain` to
        // get the owner. Without it, SqlDatabase.customer would be a query per
        // database.
        $shape = FtpSqlResolver::shapeDatabase(array(
            'sqld_id'         => 41,
            'domain_id'       => 12,
            'sqld_name'       => 'sgwt_shop',
            'domain_admin_id' => 7
        ));

        self::assertSame(GlobalId::encode(NodeType::SQL_DATABASE, 41), $shape['id']);
        self::assertSame('sgwt_shop', $shape['name']);
        self::assertSame(7, $shape['__ownerId']);
        self::assertSame(12, $shape['__domainId']);
    }

    public function testASqlUserCarriesItsNameAndTheHostItMayConnectFrom(): void
    {
        $shape = FtpSqlResolver::shapeSqlUser(array(
            'sqlu_id'   => 51,
            'sqld_id'   => 41,
            'sqlu_name' => 'sgwt_u1',
            'sqlu_host' => 'localhost'
        ));

        self::assertSame(GlobalId::encode(NodeType::SQL_USER, 51), $shape['id']);
        self::assertSame('sgwt_u1', $shape['name']);
        self::assertSame('localhost', $shape['host']);
        self::assertSame('sgwt_u1', $shape['__name']);
        self::assertSame(41, $shape['__sqldId']);
    }

    public function testNeitherSqlTypeHasProvisioning(): void
    {
        // Spec section 2.1: the panel creates these synchronously. A
        // provisioning key in the shape would be dead weight that a reader
        // would reasonably take for a schema field.
        self::assertArrayNotHasKey('provisioning', FtpSqlResolver::shapeDatabase(array(
            'sqld_id' => 41, 'domain_id' => 12, 'sqld_name' => 'x',
            'domain_admin_id' => 7
        )));
        self::assertArrayNotHasKey('provisioning', FtpSqlResolver::shapeSqlUser(array(
            'sqlu_id' => 51, 'sqld_id' => 41, 'sqlu_name' => 'u', 'sqlu_host' => '%'
        )));
    }

    public function testTheMapOwnsTheFtpAndSqlFieldsOfEveryTypeThatHasThem(): void
    {
        $resolver = new FtpSqlResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            static function (int $adminId) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        self::assertSame(array(
            'Customer.ftpUsers', 'Customer.sqlDatabases', 'Customer.sqlUsers',
            'FtpUser.customer', 'SqlDatabase.customer', 'SqlDatabase.users',
            'SqlUser.databases'
        ), $keys);
    }
}
