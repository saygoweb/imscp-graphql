<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test;

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

use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use iMSCP\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6.2: "With allowed_by_default = true (the default), an account with
 * no row may use the API." customerHasApiAccess() used to hardcode that
 * default and never read the plugin's configuration at all, so an
 * administrator who set allowed_by_default = false to make the API opt-in
 * silently got opt-out behaviour instead. These tests replay both
 * directions of that default against a missing row, which is the only case
 * the config value is meant to affect.
 *
 * @runTestsInSeparateProcesses
 *
 * SGW_GraphQLStubs.php (required from setUp(), not up here at file scope —
 * PHPUnit loads every test file to discover its tests before running any of
 * them, in the one main process, so a file-scope require would define the
 * stub there regardless of this annotation) declares a global exec_query():
 * the exact global function TokenService::issue() probes with
 * function_exists('exec_query') to decide whether it is running against a
 * real, bootstrapped panel (see that method's own guard around
 * \iMSCP\Database\DatabaseMySQL). Defining it in the shared main process
 * would make TokenServiceTest take that panel-only branch and fail on a
 * class that never exists in the unit suite. Process isolation confines the
 * stub (and the fake Registry it also declares) to this class's own child
 * process, exactly as it would be confined to a single request in
 * production.
 */
class SGW_GraphQLTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/SGW_GraphQLStubs.php';

        // No row: the case this fix is about.
        $GLOBALS['sgw_graphql_test_api_perm_row'] = false;
    }

    private function registerPluginConfig(array $config): void
    {
        Registry::set(
            'pluginManager',
            new \SGW_GraphQL_Test_FakePluginManager(new \SGW_GraphQL_Test_FakePlugin($config))
        );
    }

    public function testAMissingRowIsAllowedWhenAllowedByDefaultIsTrue(): void
    {
        $this->registerPluginConfig(array('allowed_by_default' => true));

        // A fresh admin id each test: customerHasApiAccess() memoises per
        // request via a `static $hasAccess` array that outlives one test
        // method in the same PHPUnit process, so reusing an id here would
        // read a previous test's cached result instead of exercising the
        // config read this fix adds.
        self::assertTrue(SGW_GraphQL::customerHasApiAccess(90001));
    }

    public function testAMissingRowIsDeniedWhenAllowedByDefaultIsFalse(): void
    {
        $this->registerPluginConfig(array('allowed_by_default' => false));

        self::assertFalse(SGW_GraphQL::customerHasApiAccess(90002));
    }

    public function testAnExistingRowIsHonouredRegardlessOfTheConfiguredDefault(): void
    {
        // The config default must only ever apply to a MISSING row: an
        // explicit grant or withdrawal recorded in api_perm must not be
        // overridden by it in either direction.
        $GLOBALS['sgw_graphql_test_api_perm_row'] = array('allowed' => 0);
        $this->registerPluginConfig(array('allowed_by_default' => true));

        self::assertFalse(SGW_GraphQL::customerHasApiAccess(90003));

        $GLOBALS['sgw_graphql_test_api_perm_row'] = array('allowed' => 1);
        $this->registerPluginConfig(array('allowed_by_default' => false));

        self::assertTrue(SGW_GraphQL::customerHasApiAccess(90004));
    }
}
