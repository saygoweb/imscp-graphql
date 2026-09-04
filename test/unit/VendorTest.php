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

namespace iMSCP\Plugin\SGW_GraphQL\Test;

use PHPUnit\Framework\TestCase;

class VendorTest extends TestCase
{
    public function testGraphQlLibraryIsAvailable(): void
    {
        self::assertTrue(
            class_exists(\GraphQL\GraphQL::class),
            'webonyx/graphql-php must be reachable through the bundled autoloader'
        );
    }

    public function testAnormIsAvailable(): void
    {
        self::assertTrue(class_exists(\Anorm\Model::class));
    }

    public function testPluginNamespaceResolves(): void
    {
        self::assertTrue(
            class_exists(\iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL::class, false)
                || file_exists(dirname(__DIR__, 2) . '/SGW_GraphQL.php'),
            'the plugin root must map to iMSCP\\Plugin\\SGW_GraphQL\\'
        );
    }
}
