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
 * composer.json has no autoload-dev section yet (that is Task 8's edit), so
 * this class is not found by the autoloader; a subclass must require_once
 * this file itself rather than relying on PSR-4 to find it.
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
