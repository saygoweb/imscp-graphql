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

use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\RateLimitMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\ApcuStore;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\InMemoryApcuStore;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;

/**
 * Specification section 10.3 through the real request pipeline: Container's
 * own middleware stack, Container's own handler, and the real `api_rate`
 * table underneath.
 *
 * Every counting assertion runs twice, once per backend. Measured on the
 * reference box (task 11, step 1): `function_exists('apcu_fetch')` is true,
 * `apc.enabled` is `1` and `apc.enable_cli` is `0` - so under PHPUnit
 * `apcu_enabled()` is false and the counter that answers by default is the
 * database one. The fallback is therefore the path this box exercises without
 * being asked, and the APCu path is the one that has to be asked for. It is,
 * by name, rather than left uncovered: the provider below names both, and the
 * exact counter is the real ApcuStore wherever the process can use one and a
 * double with APCu's measured semantics where it cannot.
 */
class RateLimitTest extends IntegrationTestCase
{
    /** 2026-01-01T00:00:00Z, the first second of a minute. */
    const CLOCK = 1767225600;

    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var int */
    private $now;

    /**
     * Distinct token identifiers per test, so that a box which *does* enable
     * APCu on the CLI cannot carry one test's counter into the next: the real
     * store outlives a PHPUnit transaction, unlike a row in api_rate.
     *
     * @var int
     */
    private $nonce;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The table the plugin's own migration creates, from that migration,
        // so this test fails if 002 stops producing a table the limiter can
        // use. DDL commits implicitly, which is why it happens here - before
        // the fixture opens its transaction - rather than in setUp().
        $migration = include dirname(__DIR__, 2) . '/sql/002_create_rate_table.php';
        Db::fromPanel()->pdo()->exec($migration['up']);
    }

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->now = self::CLOCK;
        $this->nonce = random_int(1, 2000000000);
        $_SESSION = array();
        IdentityShim::reset();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function backends(): array
    {
        return array('apcu' => array('apcu'), 'database' => array('database'));
    }

    /**
     * The exact counter, wherever this process can have one.
     *
     * A test that silently ran the approximate counter twice and called it
     * coverage of both would be worse than one that ran it once, so the
     * substitution is named here and asserted against the extension in
     * testThisBoxCountsInTheDatabaseAndTheLimiterSaysSo().
     */
    private function exactStore(): ApcuStore
    {
        $real = new ApcuStore();

        return $real->available() ? $real : new InMemoryApcuStore();
    }

    private function limiter(string $backend): RateLimiter
    {
        return new RateLimiter(
            function () { return $this->now; },
            $backend === 'apcu' ? $this->exactStore() : new InMemoryApcuStore(false),
            $this->db
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config, ?RateLimiter $limiter = null): Container
    {
        return Container::forTesting(
            dirname(__DIR__, 2),
            array_merge(array(
                'debug' => false, 'introspection' => true,
                'max_query_depth' => 15, 'max_query_complexity' => 1000,
                'require_tls' => false, 'allowed_origins' => array(),
                'allow_session_auth' => true
            ), $config),
            static function (string $sql, array $bind = array()) {
                return exec_query($sql, $bind);
            },
            function (int $adminId) {
                return array(
                    'admin_id'   => $this->fixture->customerId(),
                    'admin_name' => Fixture::PREFIX . 'customer',
                    'admin_type' => 'user',
                    'created_by' => $this->fixture->resellerId(),
                    'email'      => null,
                    'admin_status' => 'ok'
                );
            },
            static function (int $adminId) { return true; },
            $this->db,
            array(),
            null,
            null,
            null,
            $limiter
        );
    }

    private function identity(int $tokenId): Identity
    {
        return new Identity(
            $this->fixture->customerId(), Fixture::PREFIX . 'customer', 'user',
            $this->fixture->resellerId(), null, array(), $tokenId
        );
    }

    private function request(string $document, ?Identity $identity = null): Request
    {
        $env = Environment::mock(array(
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'   => 'application/json',
            'REMOTE_ADDR'    => '203.0.113.7'
        ));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(array('query' => $document)));
        rewind($stream);

        $request = Request::createFromEnvironment($env)->withBody(new Stream($stream));

        return $identity === null
            ? $request
            : $request->withAttribute('identity', $identity);
    }

    /**
     * One request through the part of the stack this task owns: Container's
     * own RateLimitMiddleware wrapped around Container's own handler.
     *
     * Both come out of the Container rather than being constructed here, so
     * the wiring under test is the production wiring - including the
     * `chargeMutation` closure, which is the only place the `mutations` bucket
     * is charged. The identity is set directly instead of being earned through
     * AuthenticateMiddleware because the bucket is keyed by *token*, and
     * minting real bearer tokens to tell two counters apart would be testing
     * TokenService. The whole stack, authentication included, is driven in
     * testTheLimitIsInTheRealStackAfterAuthentication().
     */
    private function send(Container $container, Request $request): ResponseInterface
    {
        $middleware = $this->rateLimitMiddleware($container);
        $handler = $container->handler();

        return $middleware($request, new Response(), static function ($req, $res) use ($handler) {
            return $handler($req, $res, array());
        });
    }

    private function rateLimitMiddleware(Container $container): RateLimitMiddleware
    {
        foreach ($container->middleware() as $middleware) {
            if ($middleware instanceof RateLimitMiddleware) {
                return $middleware;
            }
        }

        self::fail('Container::middleware() no longer contains a RateLimitMiddleware');
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        return (array)json_decode((string)$response->getBody(), true);
    }

    private function query(): string
    {
        return '{ apiVersion }';
    }

    /**
     * A mutation that is refused on its own merits, so that nothing this test
     * runs can reach the DetachedCore. The bucket is charged before the
     * document executes, so the outcome of the mutation is beside the point -
     * what matters is that the document is a mutation.
     */
    private function mutation(): string
    {
        return 'mutation { subdomainDelete(id: "'
            . GlobalId::encode(NodeType::SUBDOMAIN, 999999999) . '") { id } }';
    }

    // ---- The four assertions, on both counters ----

    /**
     * @dataProvider backends
     */
    public function testAThirdQueryInTheSameWindowIsRefused(string $backend): void
    {
        $container = $this->container(
            array('rate_limit_queries' => 2), $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        self::assertSame(200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode());
        self::assertSame(200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode());

        $third = $this->send($container, $this->request($this->query(), $identity));
        $body = $this->body($third);

        self::assertSame(429, $third->getStatusCode());
        self::assertSame(
            ErrorCode::RATE_LIMITED, $body['errors'][0]['extensions']['code'] ?? null
        );
        self::assertArrayNotHasKey(
            'data', $body, 'the document never executed, so there is no result envelope'
        );

        $retryAfter = (int)$third->getHeaderLine('Retry-After');

        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
        self::assertSame(
            $retryAfter, $body['errors'][0]['extensions']['retryAfterSeconds'] ?? null
        );
        self::assertSame('application/json', $third->getHeaderLine('Content-Type'));
    }

    /**
     * @dataProvider backends
     */
    public function testAMutationChargesTheMutationsBucketAsWellAsTheQueriesOne(string $backend): void
    {
        // Mutations 1, queries 10: the first mutation passes, the second is
        // refused by a bucket the queries budget still has room in - which is
        // only possible if the mutation was charged to a second bucket.
        $container = $this->container(
            array('rate_limit_queries' => 10, 'rate_limit_mutations' => 1),
            $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        // The first mutation is allowed through and runs: it is refused by the
        // resolver (there is no such subdomain), not by the limiter, which is
        // what NOT_FOUND here says. A mutation field is non-null, so its
        // failure nulls the whole envelope and GraphQLHandler answers 400 -
        // and that, unlike a 429, means the document executed.
        $first = $this->send($container, $this->request($this->mutation(), $identity));

        self::assertSame(
            ErrorCode::NOT_FOUND,
            $this->body($first)['errors'][0]['extensions']['code'] ?? null,
            (string)$first->getBody()
        );

        $second = $this->send($container, $this->request($this->mutation(), $identity));

        self::assertSame(429, $second->getStatusCode());
        self::assertSame(
            ErrorCode::RATE_LIMITED,
            $this->body($second)['errors'][0]['extensions']['code'] ?? null
        );

        // ...and the queries bucket is genuinely a different one, with room
        // left in it. Without this the test above would pass just as well
        // against a limiter that had simply refused everything after the first
        // request.
        self::assertSame(
            200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode()
        );
    }

    /**
     * @dataProvider backends
     */
    public function testAMutationAlsoSpendsTheQueriesBudget(string $backend): void
    {
        // The other half of "both buckets": section 10.3's thirty mutations a
        // minute come out of the hundred and twenty queries a minute, they are
        // not additional to them. Queries 2, mutations 10: two mutations
        // exhaust the queries bucket, and an ordinary query is then refused.
        $container = $this->container(
            array('rate_limit_queries' => 2, 'rate_limit_mutations' => 10),
            $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        $this->send($container, $this->request($this->mutation(), $identity));
        $this->send($container, $this->request($this->mutation(), $identity));

        self::assertSame(
            429, $this->send($container, $this->request($this->query(), $identity))->getStatusCode()
        );
    }

    /**
     * @dataProvider backends
     */
    public function testTwoTokensDoNotShareACount(string $backend): void
    {
        $container = $this->container(
            array('rate_limit_queries' => 1), $this->limiter($backend)
        );
        $first = $this->identity($this->nonce);
        $second = $this->identity($this->nonce + 1);

        self::assertSame(200, $this->send($container, $this->request($this->query(), $first))->getStatusCode());
        self::assertSame(429, $this->send($container, $this->request($this->query(), $first))->getStatusCode());

        self::assertSame(
            200, $this->send($container, $this->request($this->query(), $second))->getStatusCode(),
            'a second token was refused on the first token\'s count'
        );
    }

    /**
     * @dataProvider backends
     */
    public function testANewWindowLetsTheSameTokenBackIn(string $backend): void
    {
        $container = $this->container(
            array('rate_limit_queries' => 1), $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        $this->send($container, $this->request($this->query(), $identity));
        $refused = $this->send($container, $this->request($this->query(), $identity));

        self::assertSame(429, $refused->getStatusCode());

        // Exactly as long as the response said to wait.
        $this->now += (int)$refused->getHeaderLine('Retry-After');

        self::assertSame(
            200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode()
        );
    }

    /**
     * @dataProvider backends
     */
    public function testANegativeLimitIsUnlimitedRatherThanNoRequestsAtAll(string $backend): void
    {
        $container = $this->container(
            array('rate_limit_queries' => -1), $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(
                200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode()
            );
        }
    }

    // ---- D35: trusted_clients gets a bigger bucket, never no bucket ----

    /**
     * @dataProvider backends
     */
    public function testATrustedClientGetsTheTrustedQueriesLimitButIsEventuallyRefused(string $backend): void
    {
        // The ordinary limit is 1: if `trusted_clients` were being ignored,
        // or read as an exemption, the second request would either be
        // refused (ignored) or the loop below would never see a 429 at all
        // (exemption). Matching request()'s hard-coded REMOTE_ADDR is what
        // makes this address "trusted" rather than any address at all.
        $container = $this->container(
            array(
                'rate_limit_queries'         => 1,
                'rate_limit_queries_trusted' => 2,
                'trusted_clients'            => array('203.0.113.7')
            ),
            $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        self::assertSame(
            200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode(),
            'the first request'
        );
        self::assertSame(
            200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode(),
            'the second request - refused already if trusted_clients were not raising the limit'
        );

        // Still a limit: the third request is refused, exactly as an
        // ordinary caller would be at its own limit. A "trusted" client that
        // was never refused would be the exemption D35 explicitly rules out.
        self::assertSame(
            429, $this->send($container, $this->request($this->query(), $identity))->getStatusCode(),
            'the third request'
        );
    }

    /**
     * @dataProvider backends
     */
    public function testATrustedClientsMutationsBucketUsesTheTrustedLimitButIsEventuallyRefused(string $backend): void
    {
        // Queries left wide open, so the only bucket in play is the one
        // GraphQLHandler's `chargeMutation` closure charges - the mutations
        // bucket has its own trusted limit, wired independently of the
        // queries one in Container::handler().
        $container = $this->container(
            array(
                'rate_limit_queries'           => 100,
                'rate_limit_mutations'         => 1,
                'rate_limit_mutations_trusted' => 2,
                'trusted_clients'               => array('203.0.113.7')
            ),
            $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        $first = $this->send($container, $this->request($this->mutation(), $identity));
        $second = $this->send($container, $this->request($this->mutation(), $identity));

        // Refused by the resolver (no such subdomain), not by the limiter -
        // see mutation()'s own docblock. Two mutations getting this far at
        // all is only possible with the trusted limit in effect; the ordinary
        // one is 1.
        self::assertSame(
            ErrorCode::NOT_FOUND,
            $this->body($first)['errors'][0]['extensions']['code'] ?? null,
            (string)$first->getBody()
        );
        self::assertSame(
            ErrorCode::NOT_FOUND,
            $this->body($second)['errors'][0]['extensions']['code'] ?? null,
            (string)$second->getBody()
        );

        $third = $this->send($container, $this->request($this->mutation(), $identity));

        self::assertSame(429, $third->getStatusCode(), 'still a limit, not an exemption');
    }

    /**
     * @dataProvider backends
     */
    public function testTrustedClientsIsEmptyByDefaultAndTheOrdinaryLimitStillApplies(string $backend): void
    {
        // 'trusted_clients' is left out entirely - the shape an operator's
        // config.php has today, before this key existed. request()'s address
        // would match it if it were set to anything that covered
        // 203.0.113.7, so a huge trusted limit configured alongside an unset
        // 'trusted_clients' proves the two are independent: nothing raises
        // the ordinary limit just because a trusted figure exists somewhere
        // in config.php.
        $container = $this->container(
            array(
                'rate_limit_queries'         => 1,
                'rate_limit_queries_trusted' => 1000
            ),
            $this->limiter($backend)
        );
        $identity = $this->identity($this->nonce);

        self::assertSame(
            200, $this->send($container, $this->request($this->query(), $identity))->getStatusCode()
        );
        self::assertSame(
            429, $this->send($container, $this->request($this->query(), $identity))->getStatusCode(),
            'an operator who set nothing should be exactly as limited as before trusted_clients existed'
        );
    }

    // ---- The fallback really is the database ----

    public function testTheFallbackWritesTheRowsItCounts(): void
    {
        $container = $this->container(
            array('rate_limit_queries' => 10), $this->limiter('database')
        );
        $identity = $this->identity($this->nonce);

        $this->send($container, $this->request($this->query(), $identity));
        $this->send($container, $this->request($this->query(), $identity));

        self::assertSame(
            2,
            (int)$this->db->value(
                'SELECT hits FROM api_rate WHERE bucket = ? AND rate_key = ? AND window_at = ?',
                array('queries', 'token:' . $this->nonce, self::CLOCK)
            )
        );
    }

    public function testTheFallbackSaysItIsApproximateAndApcuDoesNot(): void
    {
        self::assertTrue($this->limiter('database')->isApproximate());
        self::assertFalse($this->limiter('apcu')->isApproximate());
    }

    public function testThisBoxCountsInTheDatabaseAndTheLimiterSaysSo(): void
    {
        // The measurement of step 1, as an assertion rather than a note: if a
        // later change to the box turns apc.enable_cli on, this says so
        // instead of quietly changing which path the suite covers.
        $container = $this->container(array());
        $store = new ApcuStore();

        self::assertSame(
            !$store->available(), $container->rateLimiter()->isApproximate()
        );
        self::assertSame(
            function_exists('apcu_enabled') && apcu_enabled(), $store->available()
        );
    }

    // ---- The wiring, through everything ----

    /**
     * The limit is a property of the deployed stack, not of a middleware
     * somebody remembered to call: this drives the whole composed pipeline -
     * TLS, CORS, the limit, then authentication - exactly as
     * PluginRoutesInjector will.
     *
     * A session, not a token, because that is the credential
     * AuthenticateMiddleware can be given without minting one. Since
     * checkpoint D's D4 the limiter runs *before* authentication, so there is
     * no Identity to key on and a request with no bearer is keyed to its
     * source address - keyFor()'s third shape. A session's own bucket has not
     * gone: GraphQLHandler's `mutations` charge still keys by Identity, which
     * by then is set.
     */
    public function testTheLimitIsInTheRealStackBeforeAuthentication(): void
    {
        $_SESSION['user_id'] = $this->fixture->customerId();
        $_SESSION['graphql_csrf'] = 'sekrit';

        $container = $this->container(
            array('rate_limit_queries' => 1), $this->limiter('database')
        );
        $pipeline = $container->routeHandler();

        $send = function () use ($pipeline) {
            $env = Environment::mock(array(
                'REQUEST_METHOD'    => 'POST', 'REQUEST_URI' => '/api/graphql',
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_X_IMSCP_CSRF' => 'sekrit',
                'REMOTE_ADDR'       => '203.0.113.7'
            ));
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, json_encode(array('query' => '{ apiVersion }')));
            rewind($stream);

            return $pipeline(
                Request::createFromEnvironment($env)->withBody(new Stream($stream)),
                new Response(),
                array()
            );
        };

        self::assertSame(200, $send()->getStatusCode());

        $refused = $send();

        self::assertSame(429, $refused->getStatusCode());
        self::assertSame(
            ErrorCode::RATE_LIMITED,
            $this->body($refused)['errors'][0]['extensions']['code'] ?? null
        );

        // Keyed to the source address, which is what proves the limiter ran
        // before authentication rather than after it: a cookie session
        // presents no bearer, so there is no prefix and no Identity yet.
        self::assertSame(
            2,
            (int)$this->db->value(
                'SELECT hits FROM api_rate WHERE bucket = ? AND rate_key = ? AND window_at = ?',
                array('queries', 'ip:203.0.113.7', self::CLOCK)
            )
        );
        self::assertNull(
            $this->db->value(
                'SELECT hits FROM api_rate WHERE bucket = ? AND rate_key = ?',
                array('queries', 'session:' . $this->fixture->customerId())
            ),
            'the queries bucket was still keyed after authentication'
        );
    }

    /**
     * Checkpoint D, finding D4. Wired inside AuthenticateMiddleware, a request
     * carrying a revoked bearer was answered 401 before any bucket was
     * charged - while still costing a TokenService::verify() lookup and a
     * hash. Unlimited attempts, uncounted.
     */
    public function testARefusedCredentialIsCountedBeforeItIsRefused(): void
    {
        $container = $this->container(
            array('rate_limit_queries' => 9, 'allow_session_auth' => false),
            $this->limiter('database')
        );

        $issued = $container->tokens()->issue(
            $this->fixture->customerId(), 'revoked', array(Scope::DOMAINS_READ), 30, null
        );
        $container->tokens()->revoke($this->fixture->customerId(), (int)$issued['tokenId']);

        $pipeline = $container->routeHandler();
        $send = function () use ($pipeline, $issued) {
            $env = Environment::mock(array(
                'REQUEST_METHOD'     => 'POST', 'REQUEST_URI' => '/api/graphql',
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $issued['token'],
                'REMOTE_ADDR'        => '203.0.113.7'
            ));
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, json_encode(array('query' => '{ apiVersion }')));
            rewind($stream);

            return $pipeline(
                Request::createFromEnvironment($env)->withBody(new Stream($stream)),
                new Response(),
                array()
            );
        };

        for ($i = 1; $i <= 9; $i++) {
            self::assertSame(401, $send()->getStatusCode(), 'attempt ' . $i);
        }

        // The tenth never reaches authentication at all.
        self::assertSame(429, $send()->getStatusCode(), 'the tenth attempt');

        // Counted under the presented token's prefix, which
        // TokenService::splitPresented() reads without verifying anything -
        // so a token that has just been revoked spends the bucket its holder
        // was always spending, rather than an unlimited one.
        $parts = TokenService::splitPresented($issued['token']);

        self::assertSame(
            10,
            (int)$this->db->value(
                'SELECT hits FROM api_rate WHERE bucket = ? AND rate_key = ? AND window_at = ?',
                array('queries', 'prefix:' . $parts['prefix'], self::CLOCK)
            )
        );
    }

    public function testAnUnauthenticatedRequestIsCountedAgainstItsSourceAddress(): void
    {
        // Task 12 moved the line this test used to draw. AuthenticateMiddleware
        // no longer refuses a request that presents no credential - spec
        // section 5.3's tokenIssue is served without one, and no middleware
        // can tell which field a document asks for - so such a request now
        // does reach the limiter, and the only key there is for it is its
        // source address (RateLimitMiddleware::keyFor()'s third shape, which
        // until now nothing produced).
        //
        // That it is counted at all is the point: a 401 used to be the thing
        // that made an unauthenticated flood cheap to refuse, and with the
        // 401 moved into the handler the bucket is what takes its place.
        $container = $this->container(
            array('rate_limit_queries' => 1, 'allow_session_auth' => false),
            $this->limiter('database')
        );
        $send = function () use ($container) {
            $env = Environment::mock(array(
                'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
                'CONTENT_TYPE'   => 'application/json',
                'REMOTE_ADDR'    => '203.0.113.7'
            ));
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, json_encode(array('query' => '{ apiVersion }')));
            rewind($stream);

            return ($container->routeHandler())(
                Request::createFromEnvironment($env)->withBody(new Stream($stream)),
                new Response(),
                array()
            );
        };

        $first = $send();

        // Refused, but by the field rather than by the transport: every
        // resolver but tokenIssue still needs an identity, and the handler
        // answers spec section 9's status for the code they raise.
        self::assertSame(401, $first->getStatusCode());
        self::assertSame(
            ErrorCode::UNAUTHENTICATED,
            $this->body($first)['errors'][0]['extensions']['code'] ?? null,
            (string)$first->getBody()
        );
        self::assertSame('Bearer realm="i-MSCP"', $first->getHeaderLine('WWW-Authenticate'));

        self::assertSame(
            1,
            (int)$this->db->value(
                'SELECT hits FROM api_rate WHERE bucket = ? AND rate_key = ? AND window_at = ?',
                array('queries', 'ip:203.0.113.7', self::CLOCK)
            )
        );

        // ...and the count is a limit, not a tally: the second one is refused
        // before it executes at all.
        self::assertSame(429, $send()->getStatusCode());
    }
}
