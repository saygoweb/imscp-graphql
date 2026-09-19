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

use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use iMSCP\Registry;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
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

    /**
     * Item 1 of the fix round: api_perm is empty in production, so the
     * "no row" branch below used to reach Registry on *every* API request
     * (through Container::fromPlugin()'s access-checker closure, which
     * called customerHasApiAccess() with no default at all). These tests
     * drive the new, explicit $defaultAllowed parameter directly and
     * deliberately never call registerPluginConfig() — Registry holds
     * nothing here, so a regression back to the old shape fails on a null
     * pluginManager rather than merely asserting wrongly.
     */
    public function testAMissingRowIsAllowedWhenTheGivenDefaultIsTrue(): void
    {
        self::assertTrue(SGW_GraphQL::customerHasApiAccess(90005, true));
    }

    public function testAMissingRowIsDeniedWhenTheGivenDefaultIsFalse(): void
    {
        self::assertFalse(SGW_GraphQL::customerHasApiAccess(90006, false));
    }

    /**
     * The fail-open shape this branch has already met four times: a row
     * recording an explicit withdrawal must win regardless of what the
     * caller passes as the default.
     */
    public function testAnExistingWithdrawalOverridesAGivenDefaultOfTrue(): void
    {
        $GLOBALS['sgw_graphql_test_api_perm_row'] = array('allowed' => 0);

        self::assertFalse(SGW_GraphQL::customerHasApiAccess(90007, true));
    }

    public function testAnExistingGrantOverridesAGivenDefaultOfFalse(): void
    {
        $GLOBALS['sgw_graphql_test_api_perm_row'] = array('allowed' => 1);

        self::assertTrue(SGW_GraphQL::customerHasApiAccess(90008, false));
    }

    /**
     * The actual wiring change item 1 makes: Container::fromPlugin() must
     * resolve 'allowed_by_default' from the $plugin it already holds and
     * bake it into the access-checker closure, rather than leave the
     * closure to call customerHasApiAccess() with no default — which is
     * exactly what would send every API request through Registry, since
     * api_perm is empty in production. Registry is never registered in
     * this test either, for the same reason as above.
     */
    public function testContainerFromPluginBakesTheConfiguredDefaultIntoTheAccessChecker(): void
    {
        $plugin = new \SGW_GraphQL_Test_FakeContainerPlugin(
            array('allowed_by_default' => false)
        );
        $container = Container::fromPlugin($plugin);

        $property = new \ReflectionProperty(Container::class, 'apiAccessChecker');
        $property->setAccessible(true);
        $checker = $property->getValue($container);

        self::assertFalse($checker(90009));
    }

    /**
     * Checkpoint E, finding E4: "Navigation must not offer a page the key
     * disables."
     *
     * The three menu entries under their three parents, driven through the
     * real setupNavigation() against a navigation object that records what
     * was added to it.
     *
     * @param string $level
     * @param string $parentUri
     * @param string $explorerUri
     */
    private function navigationFor(array $config, $level, array $parentUris)
    {
        $navigation = new \SGW_GraphQL_Test_FakeNavigation($parentUris);
        Registry::set('navigation', $navigation);

        $plugin = new \SGW_GraphQL_Test_FakeContainerPlugin($config);
        $method = new \ReflectionMethod(SGW_GraphQL::class, 'setupNavigation');
        $method->setAccessible(true);
        $method->invoke($plugin, $level);

        return $navigation;
    }

    public function testTheMenuOffersNoExplorerOnAStockInstall(): void
    {
        // Stock: 'explorer' absent from the configuration entirely, so the
        // default this reads is the one the code carries. Introspection is
        // on, which is what used to be enough to serve the page.
        $stock = array('introspection' => true);

        self::assertSame(
            array('/client/api_tokens.php'),
            $this->navigationFor(
                $stock, 'client',
                array('/client/profile.php', '/client/domains_manage.php')
            )->addedUris()
        );

        self::assertSame(
            array('/reseller/api_access.php', '/reseller/api_tokens.php'),
            $this->navigationFor(
                $stock, 'reseller',
                array('/reseller/users.php', '/reseller/profile.php')
            )->addedUris()
        );

        self::assertSame(
            array('/admin/api_audit.php'),
            $this->navigationFor(
                $stock, 'admin', array('/admin/system_info.php')
            )->addedUris()
        );
    }

    /**
     * Checkpoint E, finding E9: a missing explorer asset was served as an
     * empty 200. @file_get_contents() suppressed the failure and `false` was
     * cast to '', so a partial deploy rendered a blank explorer with
     * "GraphiQL is not defined" in the browser console and nothing at all on
     * the server side.
     */
    private function assetHandler(string $dir, string $file): callable
    {
        $method = new \ReflectionMethod(SGW_GraphQL::class, 'explorerAssetRoutes');
        $method->setAccessible(true);

        foreach ($method->invoke(null, $dir) as $route) {
            if ($route['pattern'] === '/api/graphql/explorer-assets/' . $file) {
                return $route['handler'];
            }
        }

        self::fail('no route serves ' . $file);
    }

    public function testAMissingExplorerAssetIs404AndSaysSoInTheLog(): void
    {
        $GLOBALS['sgw_graphql_test_log'] = array();

        $handler = $this->assetHandler('/nonexistent-plugin-dir', 'graphiql.min.js');
        $response = $handler(
            Request::createFromEnvironment(Environment::mock()), new Response()
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith(
            'text/plain', $response->getHeaderLine('Content-Type')
        );

        self::assertCount(1, $GLOBALS['sgw_graphql_test_log']);
        self::assertStringContainsString(
            'graphiql.min.js', $GLOBALS['sgw_graphql_test_log'][0]['message']
        );
        self::assertSame(E_USER_ERROR, $GLOBALS['sgw_graphql_test_log'][0]['level']);
    }

    public function testAnAssetThatIsThereIsStillServedWithItsOwnContentType(): void
    {
        $GLOBALS['sgw_graphql_test_log'] = array();

        $handler = $this->assetHandler(dirname(__DIR__, 2), 'graphiql.min.css');
        $response = $handler(
            Request::createFromEnvironment(Environment::mock()), new Response()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertNotSame('', (string)$response->getBody());
        self::assertSame(array(), $GLOBALS['sgw_graphql_test_log']);
    }

    public function testTheMenuOffersTheExplorerOnceTheKeyIsOn(): void
    {
        $on = array('explorer' => true, 'introspection' => true);

        self::assertContains(
            '/client/api_explorer.php',
            $this->navigationFor(
                $on, 'client',
                array('/client/profile.php', '/client/domains_manage.php')
            )->addedUris()
        );

        self::assertContains(
            '/reseller/api_explorer.php',
            $this->navigationFor(
                $on, 'reseller',
                array('/reseller/users.php', '/reseller/profile.php')
            )->addedUris()
        );

        self::assertContains(
            '/admin/api_explorer.php',
            $this->navigationFor(
                $on, 'admin', array('/admin/system_info.php')
            )->addedUris()
        );
    }
}
