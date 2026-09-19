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
use iMSCP\Plugin\SGW_GraphQL\Auth\AccessService;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\CorsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Http\RateLimitMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\QueryResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\SqlMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TokenMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostMutations;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ViewerResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\ApcuStore;
use iMSCP\Plugin\SGW_GraphQL\Service\Audit;
use iMSCP\Plugin\SGW_GraphQL\Service\Core;
use iMSCP\Plugin\SGW_GraphQL\Service\CustomerService;
use iMSCP\Plugin\SGW_GraphQL\Service\DetachedCore;
use iMSCP\Plugin\SGW_GraphQL\Service\DetachedDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\DirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\DnsService;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainService;
use iMSCP\Plugin\SGW_GraphQL\Service\FtpService;
use iMSCP\Plugin\SGW_GraphQL\Service\HostingPlanService;
use iMSCP\Plugin\SGW_GraphQL\Service\MailService;
use iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Service\ResellerService;
use iMSCP\Plugin\SGW_GraphQL\Service\SqlServer;
use iMSCP\Plugin\SGW_GraphQL\Service\SqlService;
use iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Service\UncheckedDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\VfsDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
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

    /** @var Core */
    private $core;

    /** @var DirectoryProbe */
    private $probe;

    /** @var SqlServer|null */
    private $sqlServer;

    /**
     * @var callable|null fn(Db): SqlServer - production only. Built on first
     *                    use: reading mysql.data on every request, queries
     *                    included, would be a file read nobody asked for.
     */
    private $sqlServerFactory;

    /** @var Toolkit|null */
    private $toolkit;

    /** @var RateLimiter|null */
    private $rateLimiter;

    /** @var Audit|null */
    private $audit;

    /** @var SchemaFactory|null */
    private $schemaFactory;

    private function __construct(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, Db $db, array $panelConfig,
        bool $apiAccessByDefault, Core $core, DirectoryProbe $probe, ?SqlServer $sqlServer
    ) {
        $this->pluginDir = $pluginDir;
        $this->config = $config;
        $this->query = $query;
        $this->accountLoader = $accountLoader;
        $this->apiAccessChecker = $apiAccessChecker;
        $this->db = $db;
        $this->panelConfig = $panelConfig;
        $this->apiAccessByDefault = $apiAccessByDefault;
        $this->core = $core;
        $this->probe = $probe;
        $this->sqlServer = $sqlServer;
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

        $container = new self(
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
            $allowedByDefault,
            new PanelCore(true),
            // Spec section 14, decision D20.
            (bool)$plugin->getConfigParam('validate_ftp_home_dir', true)
                ? new VfsDirectoryProbe()
                : new UncheckedDirectoryProbe(),
            null
        );

        $container->sqlServerFactory = static function (Db $db) {
            return MariaDbSqlServer::fromPanel($db);
        };

        return $container;
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
     * @param Core|null           $core      Defaults to a DetachedCore that
     *                                       throws on every method. A real
     *                                       PanelCore(false) still dispatches
     *                                       events to whatever listeners the
     *                                       test process happens to have
     *                                       registered, writes the panel's
     *                                       log (which may mail) and sends
     *                                       the alias-order mail - side
     *                                       effects a test that never asked
     *                                       for a mutation must not trigger
     *                                       by accident. Constructing the
     *                                       default touches nothing, so the
     *                                       unit suite can still build the
     *                                       whole map with it; a test that
     *                                       runs a mutation must pass a Core.
     * @param DirectoryProbe|null $probe     Defaults to a
     *                                       DetachedDirectoryProbe that
     *                                       throws on use, rather than
     *                                       silently agreeing "exists" the
     *                                       way production's VFS check might
     *                                       not. A test that runs a mutation
     *                                       must pass a DirectoryProbe.
     * @param SqlServer|null      $sqlServer Defaults to none; a test that runs
     *                                       an SQL mutation must pass a fake.
     * @param RateLimiter|null    $rateLimiter Defaults to the one
     *                                       rateLimiter() builds, which is
     *                                       what production gets. A test
     *                                       passes one to drive a clock, or to
     *                                       choose which of the two counters
     *                                       answers - the reference box
     *                                       disables APCu on the CLI, so a
     *                                       suite that took whatever the
     *                                       process happened to offer would
     *                                       only ever exercise one of them.
     * @param Audit|null          $audit     Defaults to the one audit()
     *                                       builds, which is what production
     *                                       gets. A test passes one to drive
     *                                       a clock, to choose a mode without
     *                                       a whole config.php, or to point
     *                                       the row at a table that is not
     *                                       there - decision D27's failure,
     *                                       which cannot be staged with DDL
     *                                       because DDL commits the fixture's
     *                                       transaction out from under it.
     */
    public static function forTesting(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, ?Db $db = null, array $panelConfig = array(),
        ?Core $core = null, ?DirectoryProbe $probe = null, ?SqlServer $sqlServer = null,
        ?RateLimiter $rateLimiter = null, ?Audit $audit = null
    ): self {
        $container = new self(
            $pluginDir, $config, $query, $accountLoader, $apiAccessChecker,
            // A handle that throws on use rather than one that is null: the
            // unit suite builds the whole resolver map, and a resolver that
            // reached the database there should fail loudly rather than
            // silently work against whatever connection was lying about.
            $db ?? Db::detached(),
            $panelConfig,
            true,
            $core ?? new DetachedCore(),
            $probe ?? new DetachedDirectoryProbe(),
            $sqlServer
        );
        $container->rateLimiter = $rateLimiter;
        $container->audit = $audit;

        return $container;
    }

    public function tokens(): TokenService
    {
        if ($this->tokens === null) {
            $this->tokens = new TokenService($this->query);
        }

        return $this->tokens;
    }

    /**
     * The collaborators every write service takes, built once per request.
     *
     * Its OwnershipResolver, VirtualHosts and Counts are its own rather than
     * the read resolvers': a write must decide on the database as it is now,
     * and sharing the read side's memoised instances would let a mutation
     * decide on what an earlier field of the same document happened to load.
     */
    public function toolkit(): Toolkit
    {
        if ($this->toolkit === null) {
            $db = $this->db;

            $this->toolkit = new Toolkit(
                $db,
                $this->core,
                new Guard(new OwnershipResolver(static function (string $sql, array $bind = array()) use ($db) {
                    return $db->rows($sql, $bind);
                })),
                new Accounts($db),
                new VirtualHosts($db),
                new Counts($db, self::countsDefaultMailAccounts($this->panelConfig)),
                new Writer($db),
                $this->probe,
                new AccessService($this->query, $this->tokens(), $this->apiAccessByDefault)
            );
        }

        return $this->toolkit;
    }

    /**
     * The one limiter the request uses, shared by RateLimitMiddleware's
     * `queries` charge and the handler's `mutations` charge.
     *
     * One instance rather than two because isApproximate() is a property of
     * the request - which counter actually answered - and a second limiter
     * would have its own answer to that, and its own first-charge decision
     * about whether APCu is usable.
     *
     * Both backends are always offered. ApcuStore decides for itself whether
     * it can count (it asks apcu_enabled(), not function_exists()), and the
     * database is there for when it cannot; there is no configuration switch
     * between them, because an operator choosing the wrong one would be an
     * operator switching the limiter off by accident.
     */
    public function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = new RateLimiter(
                static function () {
                    return time();
                },
                new ApcuStore(),
                $this->db
            );
        }

        return $this->rateLimiter;
    }

    public function sqlServer(): ?SqlServer
    {
        if ($this->sqlServer === null && $this->sqlServerFactory !== null) {
            $this->sqlServer = call_user_func($this->sqlServerFactory, $this->db);
        }

        return $this->sqlServer;
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

        // The write side. Services share one Toolkit, and resolvers read back
        // through the same loader and read resolvers as the queries above.
        $kit = $this->toolkit();

        $this->maps = array(
            'ViewerResolver'      => (new ViewerResolver($this->apiVersion()))->map(),
            'TypeResolver'        => (new TypeResolver())->map(),
            'VirtualHostResolver' => $virtualHosts->map(),
            'CustomerResolver'    => $customers->map(),
            'MailResolver'        => $mail->map(),
            'DnsResolver'         => $dns->map(),
            'FtpSqlResolver'      => $ftpSql->map(),
            'ResellerResolver'    => $resellers->map(),
            'QueryResolver'       => $query->map(),
            'VirtualHostMutations' => (new VirtualHostMutations(
                $loader, new SubdomainService($kit), new DomainAliasService($kit),
                new DomainService($kit), $virtualHosts, $toUnicode
            ))->map(),
            'MailMutations' => (new MailMutations($loader, new MailService($kit), $mail))->map(),
            'FtpMutations' => (new FtpMutations($loader, new FtpService($kit), $ftpSql))->map(),
            'SqlMutations' => (new SqlMutations($loader, new SqlService($kit, function () {
                // Asked on first use, so that a request with no SQL mutation
                // never reads mysql.data.
                return $this->sqlServer();
            }), $ftpSql))->map(),
            'DnsMutations' => (new DnsMutations($loader, new DnsService($kit), $dns))->map(),
            'CustomerMutations' => (new CustomerMutations(
                $loader, new CustomerService($kit), new HostingPlanService($kit),
                new DomainAliasService($kit), $customers, $resellers, $virtualHosts, $toUnicode
            ))->map(),
            'ResellerMutations' => (new ResellerMutations(
                $loader, new ResellerService($kit), $resellers, $toUnicode
            ))->map(),
            // The one unauthenticated field, and the only resolver that takes
            // the limiter directly: its two buckets are charged inside the
            // resolver rather than by RateLimitMiddleware, because they are
            // keyed by the username in the input, which no middleware has.
            'TokenMutations' => (new TokenMutations(
                $this->core, $this->tokens(), $this->rateLimiter(),
                $this->apiAccessChecker, $this->config, self::clientIp()
            ))->map()
        );

        return $this->maps;
    }

    /**
     * Memoised, so that the handler and the audit share one schema.
     *
     * SchemaFactory::create() validates the whole SDL on every build, and
     * spec section 11's redaction needs the same types the executor uses.
     * Two Containers are still two schemas; one Container is one request.
     */
    public function schemaFactory(): SchemaFactory
    {
        if ($this->schemaFactory !== null) {
            return $this->schemaFactory;
        }

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

        $this->schemaFactory = new SchemaFactory(
            $this->pluginDir . '/schema/schema.graphql',
            defined('CACHE_PATH') ? CACHE_PATH : null,
            new ResolverMap($merged),
            array(TypeResolver::class, 'resolveType')
        );

        $this->schemaFactory->withComplexity($this->complexity());

        return $this->schemaFactory;
    }

    /**
     * config.php's `max_page_size`, the ceiling a paged list is both capped
     * at and charged for.
     *
     * TypeResolver::PAGE_MAX is the default rather than the value: before
     * checkpoint E's finding E8 nothing read the key at all, so the audit
     * page displayed a number that changed nothing. One method so that the
     * two readers - handler(), where the page is applied, and complexity(),
     * where it is charged - cannot drift apart.
     */
    private function maxPageSize(): int
    {
        return max(1, (int)($this->config['max_page_size'] ?? TypeResolver::PAGE_MAX));
    }

    /**
     * Spec section 10.2: every list field's cost is proportional to what it
     * will actually return.
     *
     * A connection is charged for the page it will fetch - capped, because a
     * client asking for 5000 rows gets TypeResolver::PAGE_MAX back regardless
     * (spec section 7.9) and must be charged for that many, not for 5000 and
     * not for TypeResolver::PAGE_DEFAULT.
     *
     * A plain list takes no `page` argument to read a limit from, so it is
     * charged TypeResolver::FLAT_LIST_COST - an explicit estimate for a list
     * i-MSCP bounds by the customer's own allowance, not an upper bound on
     * rows. That constant's docblock says why, and why the paged cap was the
     * wrong figure to reuse here.
     *
     * @return array<string, callable> 'Type.field' => fn(int $childComplexity, array $args): int
     */
    private function complexity(): array
    {
        $page = static function (int $cap): callable {
            return static function (int $childComplexity, array $args) use ($cap): int {
                $limit = (int)($args['page']['limit'] ?? TypeResolver::PAGE_DEFAULT);

                return max(1, min($limit, $cap)) * $childComplexity;
            };
        };

        $flat = static function (int $estimate): callable {
            return static function (int $childComplexity) use ($estimate): int {
                return $estimate * $childComplexity;
            };
        };

        $paged = $page($this->maxPageSize());
        $bounded = $flat(TypeResolver::FLAT_LIST_COST);

        return array(
            // Every *Connection field in the SDL.
            'Query.customers'       => $paged,
            'Query.resellers'       => $paged,
            'Reseller.customers'    => $paged,
            'Customer.mailAccounts' => $paged,
            'Customer.ftpUsers'     => $paged,

            // Plain lists with no `page` argument, bounded by i-MSCP's own
            // limits rather than by anything the caller asked for.
            'Query.pending'          => $bounded,
            'Query.ipAddresses'      => $bounded,
            'Domain.subdomains'      => $bounded,
            'Domain.aliases'         => $bounded,
            'DomainAlias.subdomains' => $bounded,
            'Customer.subdomains'    => $bounded,
            'Customer.domainAliases' => $bounded,
            'Customer.sqlDatabases'  => $bounded,
            'Customer.sqlUsers'      => $bounded,
            'Customer.dnsRecords'    => $bounded,
            'SqlDatabase.users'      => $bounded,
            'SqlUser.databases'      => $bounded,
            'Reseller.ipAddresses'   => $bounded,
            'Reseller.hostingPlans'  => $bounded
        );
    }

    /**
     * Specification section 11's recorder, built once per request.
     *
     * The Core is passed because decision D27 says a failed audit write is
     * reported to the panel's log and swallowed; an Audit built without one
     * would swallow it with nothing said anywhere, which is the failure this
     * whole table exists to prevent a version of.
     */
    public function audit(): Audit
    {
        if ($this->audit === null) {
            $this->audit = new Audit(
                $this->db,
                $this->schemaFactory()->create(),
                (string)($this->config['audit'] ?? Audit::MODE_MUTATIONS),
                (int)($this->config['audit_retention_days'] ?? 90),
                static function () {
                    return time();
                },
                $this->core
            );
        }

        return $this->audit;
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

    /**
     * The address this request came from, for `tokenIssue`'s per-address
     * bucket.
     *
     * Read from $_SERVER rather than passed down from the request, because a
     * resolver map is built once per Container and a Container is built once
     * per request - the same reason panelConfig() reads the Registry here.
     * RateLimitMiddleware reads the identical value off the PSR-7 request's
     * server parameters, which Slim populates from this superglobal, so the
     * two agree about who a caller is.
     *
     * X-Forwarded-For is deliberately not consulted: a header anyone can send
     * would let one attacker spread its attempts over as many buckets as it
     * cared to invent. TlsMiddleware's trusted_proxies list exists for a
     * header whose absence would break the endpoint outright; a rate limit
     * that counted a little too coarsely behind a proxy is the safe failure.
     */
    private static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function handler(): GraphQLHandler
    {
        $limiter = $this->rateLimiter();
        $limit = (int)($this->config['rate_limit_mutations'] ?? 30);
        // D35. Normalised once here, the same way it is normalised once for
        // the 'queries' bucket's own RateLimitMiddleware instance in
        // middleware() below - two normalisations of the same config value
        // rather than one middleware reaching into another's state.
        $limitTrusted = (int)($this->config['rate_limit_mutations_trusted'] ?? 600);
        $trustedClients = RateLimitMiddleware::normalizeAddressList(
            (array)($this->config['trusted_clients'] ?? array())
        );

        return new GraphQLHandler($this->schemaFactory(), array(
            'debug'               => (bool)($this->config['debug'] ?? false),
            'introspection'       => (bool)($this->config['introspection'] ?? true),
            'maxQueryDepth'       => (int)($this->config['max_query_depth'] ?? 15),
            // 50000, matching config.php's shipped default: see the
            // Query cost comment there for the measurements behind it.
            'maxQueryComplexity'  => (int)($this->config['max_query_complexity'] ?? 50000),
            // Spec section 7.9's ceiling, from config.php rather than from
            // TypeResolver::PAGE_MAX, which is now only the default. It is
            // read in two places that must agree - here, where the page is
            // applied, and in complexity() above, where the page is charged -
            // and until finding E8 it was read in neither.
            'maxPageSize'         => $this->maxPageSize(),
            // Spec section 10.3's second bucket. The handler is the first
            // place that knows the document is a mutation, so it is the only
            // place this charge can be made; the key comes from
            // RateLimitMiddleware so that both buckets are charged to the same
            // caller. See RateLimitMiddleware's docblock.
            //
            // D35: a request from `trusted_clients` is charged against
            // `rate_limit_mutations_trusted` instead of the ordinary limit -
            // still charged, never exempt. isTrustedClient() is
            // RateLimitMiddleware's own static method so that this and the
            // 'queries' bucket agree about who is trusted without a second
            // definition of it here.
            'chargeMutation'      => static function ($request) use (
                $limiter, $limit, $limitTrusted, $trustedClients
            ) {
                $effectiveLimit = RateLimitMiddleware::isTrustedClient($request, $trustedClients)
                    ? $limitTrusted
                    : $limit;

                return $limiter->charge(
                    RateLimiter::BUCKET_MUTATIONS,
                    RateLimitMiddleware::keyFor($request),
                    $effectiveLimit,
                    RateLimiter::WINDOW_MINUTE
                );
            },
            // Spec section 11. The handler is where the row is written from,
            // because it is the only layer that has the document, the
            // variables and the result envelope at once - and its `finally`
            // is what makes a request that threw on its way out audited too.
            'audit'               => $this->audit()
        ));
    }

    /**
     * Outermost first: TLS, then CORS, then the rate limit, then
     * authentication.
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
                (bool)($this->config['require_tls'] ?? true),
                $this->tokens(),
                // (array) so that a single address written as a bare string
                // still configures one proxy rather than being read as a list
                // of its characters, and an absent key is the empty list —
                // trusting nothing, which is the safe default.
                (array)($this->config['trusted_proxies'] ?? array())
            ),
            new CorsMiddleware((array)($this->config['allowed_origins'] ?? array())),
            // Before authentication, so that a bogus, expired or revoked
            // bearer is counted rather than refused for free (checkpoint D,
            // D4). It keys on the presented token's prefix, which
            // TokenService::splitPresented() reads without verifying anything.
            // See RateLimitMiddleware.
            new RateLimitMiddleware($this->rateLimiter(), array(
                'queries'        => (int)($this->config['rate_limit_queries'] ?? 120),
                // D35. 'trustedClients' takes the array (array) so that a
                // single address written as a bare string still configures
                // one client rather than being read as a list of its
                // characters, the same reason 'trusted_proxies' is cast the
                // same way just above - and an absent key is the empty list,
                // trusting nothing.
                'queriesTrusted' => (int)($this->config['rate_limit_queries_trusted'] ?? 1200),
                'trustedClients' => (array)($this->config['trusted_clients'] ?? array())
            )),
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
     * transport checks, then the rate limit, then authentication, then the
     * GraphQL handler.
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
        // Spec section 18: this is the schema's version, not the plugin's
        // (info.php). Phase 2 added the read model (1.1.0); phase 3 added
        // Mutation and its inputs (1.2.0). Phase 4 (this release) doubles the
        // mutation surface again - reseller and administrator writes,
        // tokenIssue and tokenRevoke, the ipAddresses query - marked here as
        // 2.0.0 rather than a further minor bump, plan 4's call on the scale
        // of the addition rather than a break in compatibility: everything
        // 1.2.0 promised still holds (section 18's additive rule).
        return '2.0.0';
    }
}
