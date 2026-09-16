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

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need the panel and its database.
 *
 * The panel is bootstrapped once per process. Off the box there is no panel,
 * so every such test is skipped rather than failed: the unit suite must stay
 * runnable on a machine that has only PHP.
 *
 * Found by the `autoload-dev` PSR-4 mapping in composer.json (Task 8), which
 * maps this namespace's `Integration\` segment directly onto `test/integration`
 * rather than deriving it from `Test\` => `test/`: the directory is
 * lower-case but the namespace segment is not, and PSR-4 resolution is
 * case-sensitive on a case-sensitive filesystem.
 */
abstract class IntegrationTestCase extends TestCase
{
    const IMSCP_LIB = '/var/www/imscp/gui/include/imscp-lib.php';

    /** @var bool */
    private static $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        if (!@is_readable(self::IMSCP_LIB)) {
            self::markTestSkipped('the panel is not installed on this machine');
        }

        require_once self::IMSCP_LIB;
        self::$bootstrapped = true;
    }
}
