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
use iMSCP\Plugin\SGW_GraphQL\Api\Executor;
use iMSCP\Plugin\SGW_GraphQL\Api\ExecutorAuthException;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\InMemoryApcuStore;
use PHPUnit\Framework\TestCase;

/**
 * Api\Executor against a Container with no database: a stub `api_token` and a
 * stub `admin`, so that what is under test is the path a document takes, not
 * what is stored. test/integration/ExecutorTest.php runs the same entry point
 * against the real tables, the real limiter and the real audit row.
 */
class ExecutorTest extends TestCase
{
    /** imscp_<8 base32>_<43 base64url>: the shape splitPresented() accepts. */
    const TOKEN = 'imscp_abcdefgh_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** @var array<int, array{sql: string, bind: array}> */
    private $statements = array();

    /** @var array<string, mixed>|null The api_token row the stub answers with. */
    private $tokenRow;

    /** @var array<string, mixed>|null The admin row the stub answers with. */
    private $account;

    /** @var bool */
    private $apiAccess = true;

    protected function setUp(): void
    {
        $_SESSION = array();
        IdentityShim::reset();
        $this->statements = array();
        $this->tokenRow = $this->row();
        $this->account = array(
            'admin_id' => 7, 'admin_name' => 'customer.test', 'admin_type' => 'user',
            'created_by' => 3, 'email' => 'c@example.com', 'admin_status' => 'ok'
        );
        $this->apiAccess = true;
    }

    protected function tearDown(): void
    {
        $_SESSION = array();
        IdentityShim::reset();
    }

    // ---- refused before anything runs ------------------------------------

    /**
     * @dataProvider noToken
     */
    public function testNoTokenIsUnauthenticatedAndTouchesNothing(string $token): void
    {
        $e = $this->authRefusal(function () use ($token): void {
            $this->executor()->execute($token, '{ apiVersion }', array(), '203.0.113.7');
        });

        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());
        self::assertSame(array(), $this->statements, 'nothing was looked up');
    }

    public function noToken(): array
    {
        return array('empty' => array(''), 'blank' => array("  \t"));
    }

    /**
     * Over HTTP a request with no bearer may fall back to a panel session.
     * In process there is no browser, so a session that happens to be open in
     * this PHP request is never the credential.
     */
    public function testAnOpenPanelSessionIsNeverTheCredential(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $e = $this->authRefusal(function (): void {
            $this->executor()->execute('', '{ apiVersion }', array(), '203.0.113.7');
        });

        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());
    }

    public function testAMalformedTokenIsUnauthenticated(): void
    {
        $e = $this->authRefusal(function (): void {
            $this->executor()->execute('not-a-token', '{ apiVersion }', array(), '203.0.113.7');
        });

        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());
    }

    public function testARevokedTokenIsUnauthenticated(): void
    {
        $this->tokenRow = $this->row(array('revoked_at' => time() - 60));

        $e = $this->authRefusal(function (): void {
            $this->executor()->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');
        });

        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());
    }

    public function testATokenOfAnAccountThatIsNotActiveIsUnauthenticated(): void
    {
        $this->account['admin_status'] = 'disabled';

        $e = $this->authRefusal(function (): void {
            $this->executor()->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');
        });

        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());
    }

    public function testWithdrawnApiAccessIsItsOwnReason(): void
    {
        $this->apiAccess = false;

        $e = $this->authRefusal(function (): void {
            $this->executor()->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');
        });

        self::assertSame(ExecutorAuthException::API_ACCESS_WITHDRAWN, $e->getReason());
    }

    // ---- the client's address is the one the pipeline judges --------------

    public function testTheAllowListJudgesTheClientIpGiven(): void
    {
        $this->tokenRow = $this->row(array('ip_allowlist' => '203.0.113.0/24'));

        $e = $this->authRefusal(function (): void {
            $this->executor()->execute(self::TOKEN, '{ apiVersion }', array(), '198.51.100.1');
        });
        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());

        IdentityShim::reset();
        $result = $this->executor()->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.9');

        self::assertSame('2.0.0', $result['data']['apiVersion']);
    }

    public function testTheLastUseIsStampedWithTheClientIp(): void
    {
        $this->executor()->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.9');

        $stamp = $this->statementsLike('UPDATE api_token SET last_used_at');

        self::assertCount(1, $stamp);
        self::assertSame('203.0.113.9', $stamp[0]['bind'][1]);
    }

    // ---- an envelope, as POST /api/graphql answers ------------------------

    public function testADocumentRunsAndAnswersTheEndpointsEnvelope(): void
    {
        $result = $this->executor()->execute(
            self::TOKEN, "query V { apiVersion }\nquery W { apiVersion } # müller.de", array(),
            '203.0.113.7', 'V'
        );

        self::assertSame(array('data' => array('apiVersion' => '2.0.0')), $result);
    }

    public function testADocumentThatDoesNotParseIsAnEnvelopeNotAThrow(): void
    {
        $result = $this->executor()->execute(self::TOKEN, '{ apiVersion', array(), '203.0.113.7');

        self::assertArrayNotHasKey('data', $result);
        self::assertSame(ErrorCode::BAD_USER_INPUT, $result['errors'][0]['extensions']['code']);
    }

    public function testTheDocumentRunsAsTheTokenWithItsScopes(): void
    {
        $this->tokenRow = $this->row(array('scopes' => Scope::DNS_READ));

        $result = $this->executor()->execute(
            self::TOKEN, '{ viewer { username role scopes } }', array(), '203.0.113.7'
        );

        self::assertSame(
            array('username' => 'customer.test', 'role' => 'CUSTOMER', 'scopes' => array(Scope::DNS_READ)),
            $result['data']['viewer']
        );
    }

    public function testADocumentTooDeepIsRefusedAsOverHttp(): void
    {
        $result = $this->executor(array('max_query_depth' => 2))->execute(
            self::TOKEN, '{ __schema { types { fields { type { ofType { name } } } } } }', array(), '203.0.113.7'
        );

        self::assertSame(ErrorCode::QUERY_TOO_COMPLEX, $result['errors'][0]['extensions']['code']);
    }

    public function testVariablesThatCannotBeEncodedAreBadInputAndRunNothing(): void
    {
        $result = $this->executor()->execute(
            self::TOKEN, '{ apiVersion }', array('x' => "\xB1\x31"), '203.0.113.7'
        );

        self::assertSame(ErrorCode::BAD_USER_INPUT, $result['errors'][0]['extensions']['code']);
        self::assertSame(array(), $this->statements);
    }

    /**
     * RATE_LIMITED is returned, not thrown: it is not about the credential,
     * and the caller needs `retryAfterSeconds` from it.
     */
    public function testARateLimitRefusalIsReturnedWithItsRetryAfter(): void
    {
        $executor = $this->executor(array('rate_limit_queries' => 1), $this->limiter());

        $executor->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');
        $refused = $executor->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');

        self::assertArrayNotHasKey('data', $refused);
        self::assertSame(ErrorCode::RATE_LIMITED, $refused['errors'][0]['extensions']['code']);
        self::assertGreaterThan(0, $refused['errors'][0]['extensions']['retryAfterSeconds']);
    }

    /**
     * The `queries` bucket is keyed on the token's prefix, before the token
     * is verified - so a refused token is counted too (checkpoint D, D4).
     */
    public function testARefusedTokenIsStillCharged(): void
    {
        $this->tokenRow = null;
        $executor = $this->executor(array('rate_limit_queries' => 1), $this->limiter());

        $this->authRefusal(function () use ($executor): void {
            $executor->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');
        });
        $refused = $executor->execute(self::TOKEN, '{ apiVersion }', array(), '203.0.113.7');

        self::assertSame(ErrorCode::RATE_LIMITED, $refused['errors'][0]['extensions']['code']);
    }

    public function testTheMutationsBucketIsChargedForAMutation(): void
    {
        $executor = $this->executor(array('rate_limit_mutations' => 1), $this->limiter());
        $mutation = 'mutation { tokenRevoke(id: "QXBpVG9rZW46OTk5") { id } }';

        $executor->execute(self::TOKEN, $mutation, array(), '203.0.113.7');
        $refused = $executor->execute(self::TOKEN, $mutation, array(), '203.0.113.7');

        self::assertSame(ErrorCode::RATE_LIMITED, $refused['errors'][0]['extensions']['code']);
    }

    // ---- what an OAuth server asks without a document ---------------------

    public function testAccountSummarisesAnActiveAccount(): void
    {
        self::assertSame(
            array('adminId' => 7, 'username' => 'customer.test', 'role' => 'CUSTOMER', 'apiAccess' => true),
            $this->executor()->account(7)
        );
    }

    public function testAccountReportsWithdrawnApiAccess(): void
    {
        $this->apiAccess = false;

        self::assertFalse($this->executor()->account(7)['apiAccess']);
    }

    public function testAccountIsNullForNoSuchOrNoActiveAccount(): void
    {
        $this->account['admin_status'] = 'todelete';
        self::assertNull($this->executor()->account(7));

        $this->account = null;
        self::assertNull($this->executor()->account(7));
    }

    public function testGrantableScopesAreTheRolesScopes(): void
    {
        self::assertSame(Scope::grantableTo('user'), $this->executor()->grantableScopes(7));

        $this->account['admin_type'] = 'reseller';
        self::assertSame(Scope::grantableTo('reseller'), $this->executor()->grantableScopes(7));

        $this->account = null;
        self::assertSame(array(), $this->executor()->grantableScopes(7));
    }

    public function testTheSchemaIsTheEffectiveSdl(): void
    {
        $sdl = $this->executor()->schemaSdl();

        self::assertStringContainsString('apiVersion: String!', $sdl);
        self::assertStringContainsString('type Mutation', $sdl);
    }

    // ---- ExecutorAuthException -------------------------------------------

    public function testAnUnknownReasonIsReadAsUnauthenticated(): void
    {
        self::assertSame(
            ExecutorAuthException::UNAUTHENTICATED,
            (new ExecutorAuthException('SOMETHING_ELSE', 'x'))->getReason()
        );
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function executor(array $config = array(), ?RateLimiter $limiter = null): Executor
    {
        return Executor::forContainer(Container::forTesting(
            dirname(__DIR__, 3),
            array_merge(array(
                'debug' => false, 'introspection' => true,
                'max_query_depth' => 15, 'max_query_complexity' => 1000,
                'require_tls' => true, 'allowed_origins' => array(),
                'allow_session_auth' => true,
                // Unlimited unless a test is about the limit, as in
                // ContainerTest: a detached Db gives a limiter nowhere to
                // count, and a negative limit is the way to turn one off.
                'rate_limit_queries' => -1, 'rate_limit_mutations' => -1,
                'audit' => 'none'
            ), $config),
            function (string $sql, array $bind = array()) {
                $this->statements[] = array('sql' => $sql, 'bind' => $bind);

                return new ExecutorTestStatement(
                    strpos($sql, 'FROM api_token WHERE token_prefix') !== false && $this->tokenRow !== null
                        ? array($this->tokenRow)
                        : array()
                );
            },
            function (int $adminId) {
                return $this->account !== null && $adminId === (int)$this->account['admin_id']
                    ? $this->account : null;
            },
            function (int $adminId) {
                return $this->apiAccess;
            },
            null, array(), null, null, null, $limiter
        ));
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter(static function () { return 1767225600; }, new InMemoryApcuStore(), null);
    }

    /**
     * The api_token row for self::TOKEN.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = array()): array
    {
        $secret = explode('_', self::TOKEN, 3)[2];

        return array_merge(array(
            'token_id' => 42, 'admin_id' => 7, 'name' => 'mcp',
            'token_prefix' => 'abcdefgh', 'token_hash' => hash('sha256', $secret),
            'scopes' => implode(',', Scope::grantableTo('user')), 'ip_allowlist' => null,
            'created_at' => time() - 100, 'expires_at' => null,
            'last_used_at' => null, 'last_used_ip' => null, 'revoked_at' => null
        ), $overrides);
    }

    /**
     * @return array<int, array{sql: string, bind: array}>
     */
    private function statementsLike(string $fragment): array
    {
        return array_values(array_filter($this->statements, static function (array $s) use ($fragment) {
            return strpos($s['sql'], $fragment) !== false;
        }));
    }

    private function authRefusal(callable $call): ExecutorAuthException
    {
        try {
            $call();
        } catch (ExecutorAuthException $e) {
            return $e;
        }

        self::fail('Expected an ExecutorAuthException.');
    }
}

/**
 * The part of a panel statement TokenService reads.
 */
final class ExecutorTestStatement
{
    /** @var array<int, array<string, mixed>> */
    private $rows;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @param mixed $mode
     * @return array<string, mixed>|false
     */
    public function fetchRow($mode = null)
    {
        unset($mode);

        return $this->rows === array() ? false : $this->rows[0];
    }

    /**
     * @param mixed $mode
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($mode = null): array
    {
        unset($mode);

        return $this->rows;
    }
}
