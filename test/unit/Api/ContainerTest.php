<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Api;

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
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\CorsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use LogicException;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;

class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        IdentityShim::reset();
    }

    /**
     * @param callable|null $apiAccessChecker fn(int $adminId): bool. Defaults
     *                                        to a stub that always grants —
     *                                        fine here, because a test file
     *                                        choosing to assume access for
     *                                        tests that are not about the
     *                                        access check is a local, visible
     *                                        choice, unlike defaulting to
     *                                        grant inside Container itself.
     */
    private function container(
        array $configOverrides = [], ?callable $accountLoader = null,
        ?callable $apiAccessChecker = null
    ): Container {
        return Container::forTesting(
            dirname(__DIR__, 3),
            array_merge([
                'debug' => false, 'introspection' => true,
                'max_query_depth' => 15, 'max_query_complexity' => 1000,
                'require_tls' => true, 'allowed_origins' => [],
                'allow_session_auth' => true,
            ], $configOverrides),
            function (string $sql, array $bind = []) { return null; },
            $accountLoader ?? function (int $adminId) { return null; },
            $apiAccessChecker ?? function (int $adminId) { return true; }
        );
    }

    private function customerRow(int $adminId): array
    {
        return [
            'admin_id' => $adminId, 'admin_name' => 'wpcache.test', 'admin_type' => 'user',
            'created_by' => 3, 'email' => 'c@example.com', 'admin_status' => 'ok',
        ];
    }

    private function authenticatedSessionRequest(): Request
    {
        $env = Environment::mock([
            'REQUEST_METHOD'    => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_IMSCP_CSRF' => 'sekrit',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ apiVersion }']));
        rewind($stream);

        return Request::createFromEnvironment($env)->withBody(new Stream($stream));
    }

    public function testItBuildsAHandler(): void
    {
        self::assertInstanceOf(GraphQLHandler::class, $this->container()->handler());
    }

    public function testTheMiddlewareIsOrderedOutermostFirst(): void
    {
        // Slim applies middleware in reverse order of addition, so the array is
        // written innermost-last and the transport checks must come before any
        // credential is looked at.
        $middleware = $this->container()->middleware();

        self::assertInstanceOf(TlsMiddleware::class, $middleware[0]);
        self::assertInstanceOf(CorsMiddleware::class, $middleware[1]);
        self::assertInstanceOf(AuthenticateMiddleware::class, $middleware[2]);
    }

    public function testTheSchemaBuildsThroughTheContainer(): void
    {
        $this->container()->schemaFactory()->create()->assertValid();
        $this->addToAssertionCount(1);
    }

    /**
     * Slim\App::map() (vendor/slim/slim/Slim/App.php) unconditionally calls
     * $callable->bindTo($this->container) on any Closure handed to it as a
     * route handler. Closure::bindTo() on a *static* closure does not rebind
     * it: it emits a warning and returns null instead, so a static closure
     * returned here would silently become a route with no handler at all the
     * moment it reached the router — this is exactly what happened when this
     * method first returned one, confirmed against the deployed core in
     * Task 13. This test replays that exact call rather than inspecting the
     * closure, so it catches the failure mode Slim actually has, not a proxy
     * for it.
     */
    public function testTheRouteHandlerSurvivesTheRebindingSlimPerformsOnEveryRouteClosure(): void
    {
        $handler = $this->container()->routeHandler();
        self::assertInstanceOf(\Closure::class, $handler);

        $rebound = @$handler->bindTo(new \stdClass());

        self::assertNotNull(
            $rebound,
            'a static closure here would silently be nulled out by Slim\App::map()'
        );
    }

    /**
     * The schema route's handler (SGW_GraphQL::getRoutes(), via
     * Container::schemaRouteHandler()) has the identical bindTo() hazard as
     * routeHandler() above, and no guard before this round: a `static`
     * slipping back in there registers a null-handler route that only a
     * request against the box would reveal. This replays the exact same
     * Slim call rather than inspecting the closure, for the same reason as
     * the test above.
     */
    public function testTheSchemaRouteHandlerSurvivesTheRebindingSlimPerformsOnEveryRouteClosure(): void
    {
        $handler = $this->container()->schemaRouteHandler();
        self::assertInstanceOf(\Closure::class, $handler);

        $rebound = @$handler->bindTo(new \stdClass());

        self::assertNotNull(
            $rebound,
            'a static closure here would silently be nulled out by Slim\App::map()'
        );
    }

    /**
     * PluginRoutesInjector cannot attach a route's or a route group's
     * 'middleware' key at all (see the note on Container::routeHandler()), so
     * the whole stack has to work as one callable. This drives a plain HTTP
     * request all the way through that callable and checks the outermost
     * layer (TLS) still runs first, exactly as it would from a real request.
     */
    public function testRouteHandlerRefusesPlainHttpBeforeAnyCredentialIsLookedAt(): void
    {
        $container = $this->container(['require_tls' => true]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'   => 'application/json',
        ]);
        $request = Request::createFromEnvironment($env);

        $response = ($container->routeHandler())($request, new Response(), []);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertSame('FORBIDDEN', $body['errors'][0]['extensions']['code']);
    }

    /**
     * The positive case for the same collapsed pipeline: a request that
     * clears TLS, CORS and session authentication must still reach the
     * GraphQL handler and get a real answer back, not merely fail to be
     * blocked.
     */
    public function testRouteHandlerReachesTheGraphQlHandlerOnceAuthenticated(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $container = $this->container(
            ['require_tls' => false, 'allow_session_auth' => true],
            function (int $adminId) {
                return [
                    'admin_id' => 7, 'admin_name' => 'wpcache.test', 'admin_type' => 'user',
                    'created_by' => 3, 'email' => 'c@example.com', 'admin_status' => 'ok',
                ];
            }
        );

        $env = Environment::mock([
            'REQUEST_METHOD'    => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_IMSCP_CSRF' => 'sekrit',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ apiVersion }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)->withBody(new Stream($stream));

        $response = ($container->routeHandler())($request, new Response(), []);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('1.2.0', $body['data']['apiVersion']);
    }

    /**
     * Drives a denying access checker through the whole composed pipeline —
     * routeHandler(), not AuthenticateMiddleware in isolation, which is
     * already covered by AuthenticateMiddlewareTest. What is uncovered there
     * is the *wiring*: whether Container actually threads its access checker
     * into the position AuthenticateMiddleware calls unconditionally.
     *
     * The request otherwise clears every earlier check (TLS off, valid
     * session, correct CSRF header) and carries a query the GraphQL handler
     * could resolve, so the absence of a "data" key is meaningful: it is only
     * absent because the pipeline never reached the handler, not because the
     * query would have failed anyway.
     */
    public function testRouteHandlerDeniesWithdrawnAccessThroughTheWholeComposedPipeline(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $container = $this->container(
            ['require_tls' => false, 'allow_session_auth' => true],
            function (int $adminId) { return $this->customerRow(7); },
            function (int $adminId) { return false; }
        );

        $response = ($container->routeHandler())($this->authenticatedSessionRequest(), new Response(), []);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('API_ACCESS_WITHDRAWN', $body['errors'][0]['extensions']['code']);
        self::assertArrayNotHasKey('data', $body, 'the GraphQL handler must not have run');
    }

    /**
     * The mis-wire this guards against type-checks fine and fails open in
     * production: passing $this->accountLoader instead of
     * $this->apiAccessChecker into AuthenticateMiddleware's fourth
     * constructor argument is a callable either way, and the account loader
     * returns a non-empty row for a real account, which is truthy. A test
     * that only checks the *outcome* of a grant wouldn't catch that swap,
     * because both callables would grant here. Recording which callable was
     * actually invoked, and with what argument, does: only the real access
     * checker calls the recorder, so a mis-wire leaves it untouched and this
     * assertion fails.
     */
    public function testRouteHandlerCallsTheContainersOwnAccessCheckerWithTheAuthenticatedAdminId(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $seen = [];
        $container = $this->container(
            ['require_tls' => false, 'allow_session_auth' => true],
            function (int $adminId) { return $this->customerRow(7); },
            function (int $adminId) use (&$seen) {
                $seen[] = $adminId;
                return false;
            }
        );

        ($container->routeHandler())($this->authenticatedSessionRequest(), new Response(), []);

        self::assertSame([7], $seen, 'the access checker the container was given must be the one called, exactly once, with the real admin id');
    }

    public function testTheToolkitCarriesTheInjectedPorts(): void
    {
        $core = new \iMSCP\Plugin\SGW_GraphQL\Service\PanelCore(false);
        $probe = new \iMSCP\Plugin\SGW_GraphQL\Service\UncheckedDirectoryProbe();

        $container = Container::forTesting(
            dirname(__DIR__, 3), array(),
            function (string $sql, array $bind = []) { return null; },
            function (int $adminId) { return null; },
            function (int $adminId) { return true; },
            null, array(), $core, $probe
        );

        self::assertSame($core, $container->toolkit()->core());
        self::assertSame($probe, $container->toolkit()->probe());
        self::assertSame($container->toolkit(), $container->toolkit(), 'built once');
        self::assertNull($container->sqlServer());
    }

    /**
     * A test that builds a Container without naming a Core or DirectoryProbe
     * is not exercising a mutation, so its toolkit's ports must fail loudly
     * the moment anything actually calls them, rather than a PanelCore
     * quietly dispatching to real listeners or a probe quietly answering
     * "exists".
     */
    public function testForTestingWithNoPortsYieldsAToolkitThatThrowsOnUse(): void
    {
        $toolkit = $this->container()->toolkit();

        $this->expectException(LogicException::class);

        $toolkit->core()->writeLog('should not run', E_USER_NOTICE);
    }

    public function testForTestingWithNoProbeYieldsAToolkitThatThrowsOnUse(): void
    {
        $toolkit = $this->container()->toolkit();

        $this->expectException(LogicException::class);

        $toolkit->probe()->exists('customer', '/', 'shop');
    }
}
