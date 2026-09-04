<?php
namespace iMSCP\Plugin\SGW_GraphQL\Api;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\CorsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ViewerResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use PDO;

/**
 * Assembles the endpoint from its parts.
 *
 * Small enough not to want a DI library, and explicit enough that the wiring
 * is readable in one place.
 */
final class Container
{
    /** @var string */
    private $pluginDir;

    /** @var array */
    private $config;

    /** @var callable */
    private $query;

    /** @var callable */
    private $accountLoader;

    /** @var callable fn(int $adminId): bool */
    private $apiAccessChecker;

    /** @var TokenService|null */
    private $tokens;

    private function __construct(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker
    ) {
        $this->pluginDir = $pluginDir;
        $this->config = $config;
        $this->query = $query;
        $this->accountLoader = $accountLoader;
        $this->apiAccessChecker = $apiAccessChecker;
    }

    public static function fromPlugin(SGW_GraphQL $plugin): self
    {
        $pluginDir = $plugin->getPluginManager()->pluginGetRootDir()
            . '/' . $plugin->getName();

        // Resolved once, here, from the $plugin already in hand — not inside
        // the closure below. api_perm is empty in production, so every single
        // API request takes customerHasApiAccess()'s "no row" branch; leaving
        // that branch to read Registry itself would mean every request paid
        // for a Registry::get('pluginManager')->pluginGet() round trip that
        // this one line already has everything needed to avoid.
        $allowedByDefault = (bool)$plugin->getConfigParam('allowed_by_default', true);

        return new self(
            $pluginDir,
            $plugin->getConfig(),
            function (string $sql, array $bind = array()) {
                return exec_query($sql, $bind);
            },
            static function (int $adminId) {
                $stmt = exec_query(
                    '
                        SELECT admin_id, admin_name, admin_type, created_by, email,
                            admin_status
                        FROM admin WHERE admin_id = ?
                    ',
                    array($adminId)
                );

                return $stmt->rowCount() ? $stmt->fetchRow(PDO::FETCH_ASSOC) : null;
            },
            // Called unconditionally by AuthenticateMiddleware, and denied on
            // false: see that class's own note on why this must never fail
            // open. $allowedByDefault is passed straight through to
            // customerHasApiAccess(), whatever it is — an existing api_perm
            // row still wins over it either way, and no exception this call
            // can throw is caught here into a truthy result.
            static function (int $adminId) use ($allowedByDefault) {
                return SGW_GraphQL::customerHasApiAccess($adminId, $allowedByDefault);
            }
        );
    }

    /**
     * @param callable $apiAccessChecker fn(int $adminId): bool. Required, not
     *                                   defaulted: a production factory that
     *                                   silently grants access when nobody
     *                                   asked it to is exactly the fail-open
     *                                   shape this plugin keeps having to
     *                                   close. A test that does not care about
     *                                   the access check must say so itself,
     *                                   at the call site.
     */
    public static function forTesting(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker
    ): self {
        return new self(
            $pluginDir, $config, $query, $accountLoader, $apiAccessChecker
        );
    }

    public function tokens(): TokenService
    {
        if ($this->tokens === null) {
            $this->tokens = new TokenService($this->query);
        }

        return $this->tokens;
    }

    public function schemaFactory(): SchemaFactory
    {
        $viewer = new ViewerResolver($this->apiVersion());

        return new SchemaFactory(
            $this->pluginDir . '/schema/schema.graphql',
            defined('CACHE_PATH') ? CACHE_PATH : null,
            new ResolverMap($viewer->map())
        );
    }

    public function handler(): GraphQLHandler
    {
        return new GraphQLHandler($this->schemaFactory(), array(
            'debug'               => (bool)($this->config['debug'] ?? false),
            'introspection'       => (bool)($this->config['introspection'] ?? true),
            'maxQueryDepth'       => (int)($this->config['max_query_depth'] ?? 15),
            'maxQueryComplexity'  => (int)($this->config['max_query_complexity'] ?? 1000)
        ));
    }

    /**
     * Outermost first: TLS, then CORS, then authentication.
     *
     * This is not handed to Slim as route middleware — PluginRoutesInjector
     * cannot attach it in either shape it offers, see the note on
     * routeHandler() — it is the ordering that routeHandler() folds into one
     * callable. array_reverse() there walks this list from the innermost
     * (last) element outward, wrapping each one around what came before, so
     * the first element here ends up as the outermost wrapper of the composed
     * pipeline: still ordering as described, for a different reason than
     * "passed to the route".
     *
     * @return callable[]
     */
    public function middleware(): array
    {
        return array(
            new TlsMiddleware(
                (bool)($this->config['require_tls'] ?? true), $this->tokens()
            ),
            new CorsMiddleware((array)($this->config['allowed_origins'] ?? array())),
            new AuthenticateMiddleware(
                $this->tokens(),
                $this->accountLoader,
                (bool)($this->config['allow_session_auth'] ?? true),
                $this->apiAccessChecker
            )
        );
    }

    /**
     * The whole pipeline collapsed into one Slim-invokable callable: the
     * transport checks, then authentication, then the GraphQL handler.
     *
     * PluginRoutesInjector (gui/src/Plugin/PluginRoutesInjector.php) cannot
     * attach middleware from a route spec at all, in either of the two shapes
     * it offers. injectRoute() reads a plain route's 'middleware' key off an
     * undefined $routeSpec rather than the $spec it was actually passed, so
     * the key is silently never applied. injectRouteGroup() fares worse: the
     * closure it hands to Slim's App::group() closes over $this (the
     * injector) so that it can call $this->injectRoute(...), but
     * Slim\RouteGroup::__invoke() rebinds that closure's $this to the Slim
     * App before calling it — so the call becomes App->injectRoute(), which
     * does not exist, and Slim's __call() throws BadMethodCallException.
     * Both were confirmed against the deployed core in Task 13 with a
     * request replayed through the real dispatch path, not inferred from
     * reading the source.
     *
     * The workaround is to never hand the router anything to add as
     * middleware: this method bakes the whole stack into the single callable
     * the route spec calls its 'handler'.
     *
     * @return callable
     */
    public function routeHandler(): callable
    {
        $target = $this->handler();

        // Not `static`: Slim\App::map() unconditionally calls
        // $callable->bindTo($this->container) on any Closure handed to it as
        // a route handler. bindTo() on a *static* closure does not rebind it
        // — it emits a warning and returns null, silently turning the route
        // into a route with no handler at all. Confirmed against the
        // deployed core in Task 13: this is what a static closure here
        // actually did. Neither closure below uses $this, so the rebinding
        // Slim performs is harmless; it only has to be legal to perform.
        $pipeline = function ($request, $response, array $args) use ($target) {
            return $target($request, $response, $args);
        };

        foreach (array_reverse($this->middleware()) as $middleware) {
            $inner = $pipeline;
            $pipeline = function ($request, $response, array $args) use ($middleware, $inner) {
                return $middleware($request, $response, static function ($req, $res) use ($inner, $args) {
                    return $inner($req, $res, $args);
                });
            };
        }

        return $pipeline;
    }

    /**
     * The schema route's handler: serves the raw SDL as plain text.
     *
     * Wrapped here, rather than left as a closure literal inline in
     * SGW_GraphQL::getRoutes(), for the same reason as routeHandler(): so
     * that the identical bindTo() hazard on this closure — see
     * routeHandler()'s docblock — has a production callable a test can
     * replay Slim's rebinding against directly, since getRoutes() itself
     * cannot be exercised in the unit suite (it lives on a class that
     * extends the panel's own AbstractPlugin, not present outside a running
     * panel).
     *
     * @return callable
     */
    public function schemaRouteHandler(): callable
    {
        $schemaPath = $this->schemaPath();

        // Not `static`: see routeHandler()'s docblock. This closure does not
        // use $this either, so the rebinding Slim performs is harmless.
        return function ($request, $response) use ($schemaPath) {
            $response->getBody()->write(file_get_contents($schemaPath));

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        };
    }

    public function schemaPath(): string
    {
        return $this->pluginDir . '/schema/schema.graphql';
    }

    public function apiVersion(): string
    {
        return '1.0.0';
    }
}
