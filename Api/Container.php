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

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\CorsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\QueryResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ViewerResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
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

    /** @var Db */
    private $db;

    /** @var array The panel configuration, for CustomerFeatures. */
    private $panelConfig;

    /** @var bool */
    private $apiAccessByDefault;

    /** @var array<string, array<string, callable>>|null */
    private $maps;

    private function __construct(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, Db $db, array $panelConfig,
        bool $apiAccessByDefault
    ) {
        $this->pluginDir = $pluginDir;
        $this->config = $config;
        $this->query = $query;
        $this->accountLoader = $accountLoader;
        $this->apiAccessChecker = $apiAccessChecker;
        $this->db = $db;
        $this->panelConfig = $panelConfig;
        $this->apiAccessByDefault = $apiAccessByDefault;
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
            },
            Db::fromPanel(),
            self::panelConfig(),
            $allowedByDefault
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
        callable $apiAccessChecker, ?Db $db = null, array $panelConfig = array()
    ): self {
        return new self(
            $pluginDir, $config, $query, $accountLoader, $apiAccessChecker,
            // A handle that throws on use rather than one that is null: the
            // unit suite builds the whole resolver map, and a resolver that
            // reached the database there should fail loudly rather than
            // silently work against whatever connection was lying about.
            $db ?? Db::detached(),
            $panelConfig,
            true
        );
    }

    public function tokens(): TokenService
    {
        if ($this->tokens === null) {
            $this->tokens = new TokenService($this->query);
        }

        return $this->tokens;
    }

    /**
     * The panel configuration CustomerFeatures and Counts read.
     *
     * Registry::get('config') is an ArrayObject in the panel and absent
     * outside it, so this is the one place that has to cope with both.
     *
     * @return array<string, mixed>
     */
    private static function panelConfig(): array
    {
        if (!class_exists('iMSCP_Registry') || !\iMSCP_Registry::isRegistered('config')) {
            return array();
        }

        return (array)\iMSCP_Registry::get('config');
    }

    /**
     * Every resolver in phase 2, built once, in the one order that works.
     *
     * The graph has a cycle - Domain.customer needs CustomerResolver,
     * Customer.domain needs VirtualHostResolver, Customer.reseller needs
     * ResellerResolver and Reseller.customers needs CustomerResolver again -
     * which constructor injection cannot express. Each resolver therefore
     * takes a closure for its cross-type edges and this method closes the
     * cycle by reference. Nothing calls a resolver during construction, so
     * every variable is assigned long before any closure runs.
     *
     * @return array<string, array<string, callable>> class short name => map
     */
    public function resolverMaps(): array
    {
        if ($this->maps !== null) {
            return $this->maps;
        }

        $db = $this->db;
        $loader = new BatchLoader($db);
        $vhosts = new VirtualHosts($db);
        $counts = new Counts($db, self::countsDefaultMailAccounts($this->panelConfig));
        $toUnicode = self::toUnicode();
        $monthBounds = self::monthBounds();
        $mailboxUsage = self::mailboxUsage($this->panelConfig);
        $apiAccessByDefault = $this->apiAccessByDefault;

        // OwnershipResolver wants rows, and $this->query is exec_query(),
        // which returns a statement. Handing it the wrong one would make
        // every ownership check see nothing and answer NOT_FOUND for
        // everything - fail-closed, and completely.
        $ownership = new OwnershipResolver(
            static function (string $sql, array $bind = array()) use ($db) {
                return $db->rows($sql, $bind);
            }
        );

        $customers = null;
        $resellers = null;
        $dns = null;
        $virtualHosts = null;

        $virtualHosts = new VirtualHostResolver(
            $loader, $vhosts, $db, $toUnicode,
            static function (int $adminId) use (&$customers) {
                return $customers->reference($adminId);
            },
            static function (int $ipId) use (&$dns) {
                return $dns->ipReference($ipId);
            }
        );
        $hostRef = static function (string $tag, $key) use (&$virtualHosts) {
            return $virtualHosts->reference($tag, $key);
        };
        $customerRef = static function (int $adminId) use (&$customers) {
            return $customers->reference($adminId);
        };

        $dns = new DnsResolver($db, $loader, $hostRef);
        $customers = new CustomerResolver(
            $db, $loader, $counts, $vhosts, $this->panelConfig,
            $apiAccessByDefault, $monthBounds, $toUnicode,
            static function (int $resellerId) use (&$resellers) {
                return $resellers->reference($resellerId);
            }
        );
        $mail = new MailResolver($db, $loader, $toUnicode, $mailboxUsage, $hostRef);
        $ftpSql = new FtpSqlResolver($db, $loader, $customerRef);
        $resellers = new ResellerResolver(
            $db, $loader, $apiAccessByDefault, $monthBounds, $toUnicode,
            $customerRef,
            static function (array $adminIds) use (&$customers) {
                return $customers->references($adminIds);
            },
            static function (array $ipIds) use (&$dns) {
                return $dns->ipReferences($ipIds);
            }
        );
        $query = new QueryResolver(
            $db, $ownership, $vhosts, $virtualHosts, $customers, $mail, $dns,
            $ftpSql, $resellers
        );

        $this->maps = array(
            'ViewerResolver'      => (new ViewerResolver($this->apiVersion()))->map(),
            'TypeResolver'        => (new TypeResolver())->map(),
            'VirtualHostResolver' => $virtualHosts->map(),
            'CustomerResolver'    => $customers->map(),
            'MailResolver'        => $mail->map(),
            'DnsResolver'         => $dns->map(),
            'FtpSqlResolver'      => $ftpSql->map(),
            'ResellerResolver'    => $resellers->map(),
            'QueryResolver'       => $query->map()
        );

        return $this->maps;
    }

    public function schemaFactory(): SchemaFactory
    {
        $merged = array();

        foreach ($this->resolverMaps() as $owner => $map) {
            foreach ($map as $key => $resolver) {
                if (isset($merged[$key])) {
                    // array_merge() would have taken the last one and nobody
                    // would have noticed until a field returned the wrong
                    // shape for one caller in production.
                    throw new \LogicException(sprintf(
                        'Two resolvers claim "%s"; %s is the second.', $key, $owner
                    ));
                }

                $merged[$key] = $resolver;
            }
        }

        return new SchemaFactory(
            $this->pluginDir . '/schema/schema.graphql',
            defined('CACHE_PATH') ? CACHE_PATH : null,
            new ResolverMap($merged),
            array(TypeResolver::class, 'resolveType')
        );
    }

    /**
     * decode_idna(), or identity when there is no panel.
     *
     * @return callable fn(string): string
     */
    private static function toUnicode(): callable
    {
        if (function_exists('decode_idna')) {
            return 'decode_idna';
        }

        return static function (string $value) {
            return $value;
        };
    }

    /**
     * The first and last second of the current calendar month.
     *
     * The panel's getFirstDayOfMonth()/getLastDayOfMonth() build Zend_Date
     * objects and are what its own traffic pages use, so the API agrees with
     * the panel about where a month ends. The fallback is only ever reached
     * in the unit suite, where nothing queries traffic.
     *
     * @return callable fn(): array{0: int, 1: int}
     */
    private static function monthBounds(): callable
    {
        if (function_exists('getFirstDayOfMonth') && function_exists('getLastDayOfMonth')) {
            return static function () {
                return array(
                    (int)getFirstDayOfMonth(), (int)getLastDayOfMonth()
                );
            };
        }

        return static function () {
            $now = time();

            return array(
                (int)mktime(0, 0, 0, (int)date('n', $now), 1, (int)date('Y', $now)),
                (int)mktime(23, 59, 59, (int)date('n', $now),
                    (int)date('t', $now), (int)date('Y', $now))
            );
        };
    }

    /**
     * Bytes held in one mailbox, from the maildirsize file the mail server
     * maintains.
     *
     * MailAccount.quotaUsed is not in the database at all: the panel reads it
     * from MTA_VIRTUAL_MAIL_DIR/<domain>/<user>/maildirsize
     * (gui/public/client/mail_accounts.php:218,251, parsed by
     * parseMaildirsize() in gui/include/Client.php:363). Null for anything
     * that cannot be read, which the schema allows and which is the only
     * honest answer when the file is absent.
     *
     * @param array<string, mixed> $panelConfig
     * @return callable fn(string $address): ?int
     */
    private static function mailboxUsage(array $panelConfig): callable
    {
        return static function (string $address) use ($panelConfig) {
            if (!function_exists('parseMaildirsize') || strpos($address, '@') === false) {
                return null;
            }

            $confDir = $panelConfig['CONF_DIR'] ?? null;

            if ($confDir === null || !is_readable($confDir . '/postfix/postfix.data')) {
                return null;
            }

            $postfix = new \iMSCP_Config_Handler_File($confDir . '/postfix/postfix.data');
            $root = $postfix['MTA_VIRTUAL_MAIL_DIR'] ?? null;

            if ($root === null) {
                return null;
            }

            list($user, $domain) = explode('@', $address, 2);
            $file = $root . '/' . $domain . '/' . $user . '/maildirsize';

            if (!is_readable($file)) {
                return null;
            }

            $parsed = parseMaildirsize($file);

            return isset($parsed['byte_count']) ? (int)$parsed['byte_count'] : null;
        };
    }

    /**
     * @param array<string, mixed> $panelConfig
     */
    private static function countsDefaultMailAccounts(array $panelConfig): bool
    {
        return (bool)($panelConfig['COUNT_DEFAULT_EMAIL_ADDRESSES'] ?? true);
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
        // Spec section 18: this is the schema's version, not the plugin's.
        // Phase 2 adds types and fields within the same major version, which
        // is a minor bump.
        return '1.1.0';
    }
}
