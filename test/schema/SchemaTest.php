<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

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

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Utils\SchemaPrinter;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private function sdlPath(): string
    {
        return dirname(__DIR__, 2) . '/schema/schema.graphql';
    }

    private function factory(): SchemaFactory
    {
        // No cache directory: a schema test must read the file it is testing,
        // not whatever a previous run left in the cache.
        return new SchemaFactory(
            $this->sdlPath(), null, new ResolverMap(array()),
            array(TypeResolver::class, 'resolveType')
        );
    }

    public function testTheSchemaBuildsAndIsValid(): void
    {
        $schema = $this->factory()->create();
        $schema->assertValid();

        self::assertInstanceOf(ObjectType::class, $schema->getQueryType());
    }

    public function testEveryNodeTypeTagNamesATypeInTheSchema(): void
    {
        // The failure this catches: a tag added to NodeType with no type in
        // the SDL, which resolveType would turn into "abstract type must
        // resolve to an Object type" at run time, for one customer, in
        // production.
        $schema = $this->factory()->create();

        foreach (array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::SUBDOMAIN,
            NodeType::ALIAS_SUBDOMAIN, NodeType::DOMAIN_ALIAS,
            NodeType::MAIL_ACCOUNT, NodeType::FTP_USER, NodeType::SQL_DATABASE,
            NodeType::SQL_USER, NodeType::DNS_RECORD, NodeType::RESELLER,
            NodeType::HOSTING_PLAN, NodeType::IP_ADDRESS
        ) as $tag) {
            $type = $schema->getType(NodeType::graphqlType($tag));

            self::assertInstanceOf(ObjectType::class, $type, $tag);
        }
    }

    public function testTheThreeInterfacesHaveARuntimeTypeResolver(): void
    {
        // An interface built from SDL has no resolveType of its own. Missing
        // it is not a build error and not a validation error - it fails only
        // when a query first selects an interface field.
        $schema = $this->factory()->create();

        foreach (array('Node', 'Provisioned', 'VirtualHost') as $name) {
            $type = $schema->getType($name);

            self::assertInstanceOf(InterfaceType::class, $type, $name);
            self::assertIsCallable($type->config['resolveType'] ?? null, $name);
        }
    }

    public function testEveryVirtualHostTypeImplementsAllThreeInterfaces(): void
    {
        $schema = $this->factory()->create();

        foreach (array('Domain', 'Subdomain', 'DomainAlias') as $name) {
            /** @var ObjectType $type */
            $type = $schema->getType($name);
            $interfaces = array();

            foreach ($type->getInterfaces() as $interface) {
                $interfaces[] = $interface->name;
            }

            sort($interfaces);
            self::assertSame(array('Node', 'Provisioned', 'VirtualHost'), $interfaces, $name);
        }
    }

    public function testSqlDatabaseAndSqlUserAreNotProvisioned(): void
    {
        // Spec section 2.1: the panel creates these synchronously. Giving them
        // a provisioning field would promise a settled/pending distinction
        // that the database cannot answer, because there is no status column.
        $schema = $this->factory()->create();

        foreach (array('SqlDatabase', 'SqlUser') as $name) {
            /** @var ObjectType $type */
            $type = $schema->getType($name);

            self::assertFalse($type->hasField('provisioning'), $name);
        }
    }

    public function testThePrintedSchemaMatchesTheCommittedSnapshot(): void
    {
        // Spec section 17: "a committed snapshot of the SDL must match, so a
        // schema change is always a visible diff in review". The snapshot is
        // the printed schema rather than the file, so that a change made by
        // reordering or by a comment does not produce a diff and a change to
        // an actual type always does.
        // It lives beside the SDL, in schema/, because it is also what a
        // client generator builds against and so has to ship in the release
        // archive. tools/export-schema.php writes it; this test only reads.
        $printed = SchemaPrinter::doPrint($this->factory()->create());
        $snapshot = dirname(__DIR__, 2) . '/schema/schema.printed.graphql';

        self::assertFileExists($snapshot);
        self::assertSame(
            file_get_contents($snapshot),
            $printed,
            'The schema changed. Regenerate the snapshot, then review the diff: '
                . 'php7.4 /var/www/imscp/gui/bin/composer.phar schema'
        );
    }
}
