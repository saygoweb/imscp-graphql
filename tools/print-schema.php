<?php
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
 * Prints the schema the way test/schema/SchemaTest compares it, so that a
 * schema change's snapshot is regenerated rather than hand-edited. Run it in
 * the container, redirecting there, so that the file keeps its owner and its
 * line endings:
 *
 *   ../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql \
 *       && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql'
 *
 * Then read the diff: it is the change a reviewer will see.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use GraphQL\Utils\SchemaPrinter;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;

echo SchemaPrinter::doPrint((new SchemaFactory(
    dirname(__DIR__) . '/schema/schema.graphql',
    null,
    new ResolverMap(array()),
    array(TypeResolver::class, 'resolveType')
))->create());
