<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Model;

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

use iMSCP\Plugin\SGW_GraphQL\Model\AdminModel;
use iMSCP\Plugin\SGW_GraphQL\Model\AliasSubdomainModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DnsRecordModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainAliasModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;
use iMSCP\Plugin\SGW_GraphQL\Model\FtpUserModel;
use iMSCP\Plugin\SGW_GraphQL\Model\HostingPlanModel;
use iMSCP\Plugin\SGW_GraphQL\Model\MailAccountModel;
use iMSCP\Plugin\SGW_GraphQL\Model\ResellerPropsModel;
use iMSCP\Plugin\SGW_GraphQL\Model\ServerIpModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SqlDatabaseModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SqlUserModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SubdomainModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ModelMappingTest extends TestCase
{
    public function models(): array
    {
        return array(
            'admin'           => array(AdminModel::class, 'admin', 'admin_id'),
            'domain'          => array(DomainModel::class, 'domain', 'domain_id'),
            'subdomain'       => array(SubdomainModel::class, 'subdomain', 'subdomain_id'),
            'domain alias'    => array(DomainAliasModel::class, 'domain_aliasses', 'alias_id'),
            'alias subdomain' => array(
                AliasSubdomainModel::class, 'subdomain_alias', 'subdomain_alias_id'
            ),
            'server ip'       => array(ServerIpModel::class, 'server_ips', 'ip_id'),
            'mail account'    => array(MailAccountModel::class, 'mail_users', 'mail_id'),
            'ftp user'        => array(FtpUserModel::class, 'ftp_users', 'userid'),
            'sql database'    => array(SqlDatabaseModel::class, 'sql_database', 'sqld_id'),
            'sql user'        => array(SqlUserModel::class, 'sql_user', 'sqlu_id'),
            'dns record'      => array(DnsRecordModel::class, 'domain_dns', 'domain_dns_id'),
            'reseller props'  => array(ResellerPropsModel::class, 'reseller_props', 'id'),
            'hosting plan'    => array(HostingPlanModel::class, 'hosting_plans', 'id')
        );
    }

    /**
     * @dataProvider models
     */
    public function testTheTableAndPrimaryKeyAreTheImscpOnes(
        string $class, string $table, string $primaryKey
    ): void {
        self::assertSame($table, constant($class . '::TABLE'));
        self::assertSame($primaryKey, constant($class . '::PRIMARY_KEY'));
        self::assertContains($primaryKey, constant($class . '::COLUMNS'));
    }

    /**
     * @dataProvider models
     */
    public function testEveryColumnIsADeclaredPublicProperty(
        string $class, string $table, string $primaryKey
    ): void {
        // Anorm's DataMapper::readArray() assigns straight to $model->$property.
        // A column with no declared property is a dynamic property, which PHP
        // 8.2 deprecates and 8.3 still warns about - and this plugin must lint
        // and run clean under 8.3.
        $declared = array();

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $declared[] = $property->getName();
            }
        }

        foreach (constant($class . '::COLUMNS') as $column) {
            self::assertContains($column, $declared, $class . '::$' . $column);
        }
    }

    /**
     * @dataProvider models
     */
    public function testNoPropertyIsTyped(
        string $class, string $table, string $primaryKey
    ): void {
        // PDO::FETCH_ASSOC hands back strings. A typed property would throw a
        // TypeError on the first row read, and nothing in the unit suite would
        // see it because the unit suite never reads a row.
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                self::assertFalse(
                    $property->hasType(),
                    $class . '::$' . $property->getName() . ' must be untyped'
                );
            }
        }
    }

    /**
     * @dataProvider models
     */
    public function testColumnNamesAreUniqueAndSnakeCase(
        string $class, string $table, string $primaryKey
    ): void {
        // The map Anorm is given is array_combine(COLUMNS, COLUMNS), so a
        // duplicate would silently shorten it, and a camelCase entry would name
        // a column that does not exist in i-MSCP.
        $columns = constant($class . '::COLUMNS');

        self::assertSame(array_values(array_unique($columns)), array_values($columns));

        foreach ($columns as $column) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $column, $class);
        }
    }

    public function testEveryRelationshipTargetIsDeclaredToo(): void
    {
        // Anorm's distributeBatchResults() assigns the loaded relation to
        // $model->{$relationshipName}. Same dynamic-property problem, one level
        // out, and this is the list later tasks pass to BatchLoader by name.
        $expected = array(
            AdminModel::class          => array('reseller', 'domains', 'ftpUsers'),
            DomainModel::class         => array(
                'customer', 'ipAddress', 'mailAccounts', 'sqlDatabases', 'dnsRecords'
            ),
            SubdomainModel::class      => array('domain'),
            DomainAliasModel::class    => array('domain'),
            AliasSubdomainModel::class => array('alias'),
            MailAccountModel::class    => array('domain'),
            FtpUserModel::class        => array('customer'),
            SqlDatabaseModel::class    => array('domain', 'users'),
            SqlUserModel::class        => array('database'),
            DnsRecordModel::class      => array('domain'),
            HostingPlanModel::class    => array('reseller'),
            ResellerPropsModel::class  => array('reseller')
        );

        foreach ($expected as $class => $relationships) {
            $declared = array();

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if ($property->isPublic() && !$property->isStatic()) {
                    $declared[] = $property->getName();
                }
            }

            foreach ($relationships as $relationship) {
                self::assertContains($relationship, $declared, $class . '::$' . $relationship);
            }
        }
    }
}
