<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use PHPUnit\Framework\TestCase;

/**
 * The matrix is only as complete as the catalogue. This keeps the two from
 * drifting apart in either direction. No database: it reads the SDL.
 */
class CatalogueCoverageTest extends TestCase
{
    /** Spec section 7.11's customer-level mutations, less D16's two. */
    const CUSTOMER_MUTATIONS = array(
        'dnsRecordCreate', 'dnsRecordDelete', 'dnsRecordUpdate', 'domainAliasCreate',
        'domainAliasDelete', 'domainAliasUpdate', 'domainUpdate', 'ftpUserCreate',
        'ftpUserDelete', 'ftpUserUpdate', 'mailAccountCreate', 'mailAccountDelete',
        'mailAccountUpdate', 'mailAutoresponderSet', 'mailCatchallCreate',
        'mailCatchallDelete', 'sqlDatabaseCreate', 'sqlDatabaseDelete', 'sqlUserCreate',
        'sqlUserDelete', 'sqlUserSetPassword', 'subdomainCreate', 'subdomainDelete',
        'subdomainUpdate'
    );

    public function testTheCatalogueIsExactlyTheCustomerMutations(): void
    {
        $fields = array_keys(MutationCatalogue::all());
        sort($fields);

        self::assertSame(self::CUSTOMER_MUTATIONS, $fields);
    }

    public function testEveryEntryIsWellFormed(): void
    {
        foreach (MutationCatalogue::all() as $field => $entry) {
            self::assertSame(array('scope', 'document', 'prepare', 'variables'), array_keys($entry), $field);
            self::assertStringContainsString($field . '(', $entry['document'], $field);
            self::assertIsCallable($entry['prepare'], $field);
            self::assertIsCallable($entry['variables'], $field);
        }
    }

    public function testEveryMutationInTheSchemaHasAnEntry(): void
    {
        $schema = (new SchemaFactory(
            dirname(__DIR__, 2) . '/schema/schema.graphql', null, new ResolverMap(array()),
            array(TypeResolver::class, 'resolveType')
        ))->create();
        $mutation = $schema->getMutationType();
        $fields = $mutation === null ? array() : array_keys($mutation->getFields());

        self::assertSame(
            array(),
            array_values(array_diff($fields, array_keys(MutationCatalogue::all()))),
            'A mutation reached the schema with no row in the authorisation matrix.'
        );
    }

    public function testEveryCatalogueEntryIsInTheSchema(): void
    {
        // Until phase 3's last task the matrix skipped rows whose mutation had
        // not yet reached the schema. From here a skipped row is a mutation
        // that went missing, so it fails instead.
        $schema = (new SchemaFactory(
            dirname(__DIR__, 2) . '/schema/schema.graphql', null, new ResolverMap(array()),
            array(TypeResolver::class, 'resolveType')
        ))->create();
        $mutation = $schema->getMutationType();
        $fields = $mutation === null ? array() : array_keys($mutation->getFields());

        self::assertSame(
            array(),
            array_values(array_diff(array_keys(MutationCatalogue::all()), $fields)),
            'A mutation the authorisation matrix covers is not in the schema.'
        );
    }
}
