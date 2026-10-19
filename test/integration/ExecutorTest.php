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

use ErrorException;
use iMSCP\Crypt;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Api\Executor;
use iMSCP\Plugin\SGW_GraphQL\Api\ExecutorAuthException;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\Audit;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\InMemoryApcuStore;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;
use iMSCP\Registry;
use PDO;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;

/**
 * Api\Executor against the real tables: the token it mints is the token the
 * pipeline accepts, the row it audits is a real `api_audit` row, and the
 * rate-limit buckets are the ones POST /api/graphql charges - shown here by
 * charging them from both entry points and watching one run out.
 *
 * The same pipeline is driven over HTTP alongside it (http()), through
 * Container::routeHandler(), so that "exactly as for HTTP" is a comparison
 * the test makes rather than a claim it repeats.
 */
class ExecutorTest extends IntegrationTestCase
{
    /** 2026-01-01T00:00:00Z: the limiter's clock, so a window never rolls over mid-test. */
    const CLOCK = 1767225600;

    const CLIENT_IP = '203.0.113.7';

    /** Long enough for the panel's own checkPasswordSyntax(). */
    const PASSWORD = 'Sgw3xec-Pass!';

    /** The two CLI-only warnings TokenMutationsTest documents in full. */
    const TOLERATED = array(
        'session_regenerate_id(): Cannot regenerate session id',
        'session_destroy(): Trying to destroy uninitialized session'
    );

    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var RecordingCore */
    private $core;

    /** @var RateLimiter|null */
    private $limiter;

    /** @var bool What the API-access checker answers. */
    private $apiAccess;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The plugin's own migrations, before the fixture's transaction: DDL
        // commits implicitly. 003 is the `via` column this test reads.
        $pdo = Db::fromPanel()->pdo();
        $root = dirname(__DIR__, 2);

        foreach (array('001_create_api_tables', '002_create_rate_table', '003_add_audit_via') as $name) {
            $migration = include $root . '/sql/' . $name . '.php';
            $pdo->exec($migration['up']);
        }
    }

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->core = new RecordingCore(new PanelCore(false));
        $this->limiter = null;
        $this->apiAccess = true;

        $this->db->execute('DELETE FROM api_audit');
        $this->db->execute('DELETE FROM api_token');

        $_SESSION = array();
        $_POST = array();
        IdentityShim::reset();

        if (!Registry::isRegistered('flashMessenger')) {
            Registry::set('flashMessenger', new AuditFlashMessenger());
        }

        set_error_handler(static function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                return true;
            }

            foreach (self::TOLERATED as $tolerated) {
                if (strpos($message, $tolerated) === 0) {
                    return true;
                }
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $this->fixture->rollBack();
        IdentityShim::reset();
    }

    // ---- a token Executor mints is a token the pipeline accepts ----------

    public function testAnIssuedTokenRunsADocumentAsItsAccount(): void
    {
        $issued = $this->issue();

        $result = $this->executor()->execute(
            $issued['token'], '{ viewer { username role scopes } }', array(), self::CLIENT_IP
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame('CUSTOMER', $result['data']['viewer']['role']);
        self::assertSame(array(Scope::DOMAINS_READ), $result['data']['viewer']['scopes']);
    }

    public function testIssueTokenAnswersTheTokenItsIdAndItsExpiry(): void
    {
        $before = time();
        $issued = $this->issue(array(Scope::DOMAINS_READ), 3600);

        $row = $this->db->row('SELECT * FROM api_token WHERE token_id = ?', array($issued['tokenId']));

        self::assertSame($this->fixture->customerId(), (int)$row['admin_id']);
        self::assertSame((int)$row['expires_at'], $issued['expiresAt']);
        self::assertGreaterThanOrEqual($before + 3600, $issued['expiresAt']);
        self::assertLessThanOrEqual(time() + 3600, $issued['expiresAt']);
        self::assertSame(Scope::DOMAINS_READ, (string)$row['scopes']);
        self::assertNull($row['ip_allowlist']);
        self::assertSame(hash('sha256', explode('_', $issued['token'], 3)[2]), (string)$row['token_hash']);
    }

    public function testALifetimeBeyondTheCeilingIsCappedNotRefused(): void
    {
        $issued = $this->issue(array(Scope::DOMAINS_READ), 10 * 365 * 86400, array('token_max_ttl_days' => 2));

        self::assertLessThanOrEqual(time() + 2 * 86400, $issued['expiresAt']);
        self::assertGreaterThan(time() + 86400, $issued['expiresAt']);
    }

    public function testIssuingIsWrittenToThePanelsLog(): void
    {
        $this->issue();

        $messages = array_map(static function (array $call) {
            return (string)$call[0];
        }, $this->core->callsNamed('writeLog'));

        self::assertContains(
            'An API token (mcp) has been issued for ' . Fixture::PREFIX . 'customer', $messages
        );
    }

    // ---- issueToken is held to what tokenIssue is held to ----------------

    public function testAnEmptyScopeListIsRefused(): void
    {
        self::assertSame(ErrorCode::BAD_USER_INPUT, $this->issueRefusal(array())->getErrorCode());
        self::assertSame(0, $this->tokenCount());
    }

    public function testAScopeTheRoleCannotHoldIsRefusedNotDropped(): void
    {
        $e = $this->issueRefusal(array(Scope::DOMAINS_READ, Scope::RESELLERS_READ));

        self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        self::assertSame(Scope::RESELLERS_READ, $e->getExtensions()['scope']);
        self::assertSame(0, $this->tokenCount());
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        self::assertSame(ErrorCode::BAD_USER_INPUT, $this->issueRefusal(array('EVERYTHING'))->getErrorCode());
    }

    public function testAnAccountWithoutApiAccessIsRefusedAToken(): void
    {
        $this->apiAccess = false;

        self::assertSame(ErrorCode::FORBIDDEN, $this->issueRefusal(array(Scope::DOMAINS_READ))->getErrorCode());
        self::assertSame(0, $this->tokenCount());
    }

    public function testNoSuchAccountIsNotFound(): void
    {
        try {
            $this->executor()->issueToken(999999999, 'mcp', array(Scope::DOMAINS_READ), 3600);
            self::fail('Expected NOT_FOUND.');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testTheTokenCountCeilingApplies(): void
    {
        $this->issue(array(Scope::DOMAINS_READ), 3600, array('token_max_per_account' => 1));

        $e = $this->issueRefusal(array(Scope::DOMAINS_READ), array('token_max_per_account' => 1));

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $e->getErrorCode());
        self::assertSame(1, $this->tokenCount());
    }

    public function testAResellerMayHoldCustomerScopes(): void
    {
        $issued = $this->executor()->issueToken(
            $this->fixture->resellerId(), 'mcp', array(Scope::CUSTOMERS_WRITE), 3600
        );

        self::assertGreaterThan(0, $issued['tokenId']);
        self::assertSame(Scope::grantableTo('reseller'), $this->executor()->grantableScopes($this->fixture->resellerId()));
        self::assertSame(Scope::all(), $this->executor()->grantableScopes($this->fixture->adminId()));
    }

    /**
     * One Executor, two documents, a change in between: the second document
     * must read the change. A Container's batch loader caches a request's
     * reads, and when one Executor reused one Container a caller polling an
     * object for its provisioning to settle never saw it settle (found by
     * SGW_Mcp's integration suite).
     */
    public function testEachExecuteReadsTheDatabaseAfresh(): void
    {
        $issued = $this->issue(array(Scope::ACCOUNT_READ));
        $executor = $this->executor();
        $id = GlobalId::encode(
            NodeType::CUSTOMER, $this->fixture->customerId()
        );
        $document = 'query C($id: ID!) { customer(id: $id) { reference } }';

        $executor->execute($issued['token'], $document, array('id' => $id), self::CLIENT_IP);
        $this->db->execute(
            'UPDATE admin SET customer_id = ? WHERE admin_id = ?',
            array('changed-between-calls', $this->fixture->customerId())
        );
        $second = $executor->execute($issued['token'], $document, array('id' => $id), self::CLIENT_IP);

        self::assertArrayNotHasKey('errors', $second, json_encode($second));
        self::assertSame('changed-between-calls', $second['data']['customer']['reference']);
    }

    // ---- revocation, liveness, and the refresh check ---------------------

    public function testARevokedTokenIsRefusedByTheNextExecute(): void
    {
        $issued = $this->issue();
        $executor = $this->executor();

        self::assertTrue($executor->revokeToken($this->fixture->customerId(), $issued['tokenId']));
        self::assertFalse($executor->revokeToken($this->fixture->customerId(), $issued['tokenId']), 'already revoked');

        IdentityShim::reset();
        $e = $this->authRefusal(function () use ($executor, $issued): void {
            $executor->execute($issued['token'], '{ apiVersion }', array(), self::CLIENT_IP);
        });

        self::assertSame(ExecutorAuthException::UNAUTHENTICATED, $e->getReason());
    }

    public function testAnotherAccountCannotRevokeTheToken(): void
    {
        $issued = $this->issue();

        self::assertFalse($this->executor()->revokeToken($this->fixture->siblingId(), $issued['tokenId']));
        self::assertTrue($this->executor()->tokenIsLive($this->fixture->customerId(), $issued['tokenId']));
    }

    public function testTokenIsLiveAndTokenIsRevokedOnALiveToken(): void
    {
        $issued = $this->issue();
        $executor = $this->executor();
        $customer = $this->fixture->customerId();

        self::assertTrue($executor->tokenIsLive($customer, $issued['tokenId']));
        self::assertFalse($executor->tokenIsRevoked($customer, $issued['tokenId']));
    }

    /**
     * The amendment's case: an hour-long access token has normally expired
     * by the time it is refreshed, and the refresh must go on working.
     */
    public function testAnExpiredButUnrevokedTokenIsNotLiveAndNotRevoked(): void
    {
        $issued = $this->issue();
        $this->db->execute(
            'UPDATE api_token SET expires_at = ? WHERE token_id = ?',
            array(time() - 60, $issued['tokenId'])
        );
        $executor = $this->executor();
        $customer = $this->fixture->customerId();

        self::assertFalse($executor->tokenIsLive($customer, $issued['tokenId']));
        self::assertFalse($executor->tokenIsRevoked($customer, $issued['tokenId']));
    }

    public function testARevokedTokenIsRevokedAndNotLive(): void
    {
        $issued = $this->issue();
        $executor = $this->executor();
        $customer = $this->fixture->customerId();
        $executor->revokeToken($customer, $issued['tokenId']);

        self::assertFalse($executor->tokenIsLive($customer, $issued['tokenId']));
        self::assertTrue($executor->tokenIsRevoked($customer, $issued['tokenId']));
    }

    public function testAnotherAccountsOrAMissingTokenIsRevokedAndNotLive(): void
    {
        $issued = $this->issue();
        $executor = $this->executor();

        self::assertFalse($executor->tokenIsLive($this->fixture->siblingId(), $issued['tokenId']));
        self::assertTrue($executor->tokenIsRevoked($this->fixture->siblingId(), $issued['tokenId']));
        self::assertTrue($executor->tokenIsRevoked($this->fixture->customerId(), 999999999));
    }

    // ---- the API switch --------------------------------------------------

    public function testWithdrawnAccessRefusesExecuteWithItsOwnReason(): void
    {
        $issued = $this->issue();
        $this->apiAccess = false;

        $e = $this->authRefusal(function () use ($issued): void {
            $this->executor()->execute($issued['token'], '{ apiVersion }', array(), self::CLIENT_IP);
        });

        self::assertSame(ExecutorAuthException::API_ACCESS_WITHDRAWN, $e->getReason());
        self::assertFalse($this->executor()->account($this->fixture->customerId())['apiAccess']);
    }

    // ---- the audit row ---------------------------------------------------

    public function testTheAuditRowSaysMcpAndNamesTheClientIp(): void
    {
        $issued = $this->issue();

        $this->executor(array('audit' => Audit::MODE_ALL))->execute(
            $issued['token'], 'query Who { viewer { username } }', array(), '198.51.100.23'
        );

        $rows = $this->db->rows('SELECT * FROM api_audit ORDER BY audit_id');

        self::assertCount(1, $rows);
        self::assertSame('mcp', (string)$rows[0]['via']);
        self::assertSame('198.51.100.23', (string)$rows[0]['ip']);
        self::assertSame($this->fixture->customerId(), (int)$rows[0]['admin_id']);
        self::assertSame($issued['tokenId'], (int)$rows[0]['token_id']);
        self::assertSame('Who', (string)$rows[0]['operation']);
        self::assertSame('ok', (string)$rows[0]['outcome']);
    }

    public function testTheSameRequestOverHttpSaysHttp(): void
    {
        $issued = $this->issue();

        $response = $this->http(
            $issued['token'], 'query Who { viewer { username } }', array('audit' => Audit::MODE_ALL)
        );

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            array('http'), array_column($this->db->rows('SELECT via FROM api_audit'), 'via')
        );
    }

    /**
     * Secrets are redacted by type, so a password sent through Executor is
     * no more recorded than one sent over HTTP.
     */
    public function testASecretInTheVariablesIsRedacted(): void
    {
        $issued = $this->issue(array(Scope::MAIL_WRITE));

        $this->executor()->execute(
            $issued['token'],
            'mutation M($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id } }',
            array('input' => array(
                'hostId' => 'nope', 'localPart' => 'x', 'kind' => 'MAILBOX', 'password' => 'Hunter2-Secret!'
            )),
            self::CLIENT_IP
        );

        $variables = (string)$this->db->value('SELECT variables FROM api_audit ORDER BY audit_id DESC LIMIT 1');

        self::assertStringContainsString('"password":"***"', $variables, 'the row was written, redacted');
        self::assertStringNotContainsString('Hunter2-Secret!', $variables);
    }

    // ---- the rate limit is the endpoint's own ----------------------------

    public function testTheQueriesBucketIsSharedWithHttp(): void
    {
        $issued = $this->issue();
        $config = array('rate_limit_queries' => 2);

        $this->http($issued['token'], '{ apiVersion }', $config);
        $second = $this->executor($config)->execute($issued['token'], '{ apiVersion }', array(), self::CLIENT_IP);
        IdentityShim::reset();
        $third = $this->executor($config)->execute($issued['token'], '{ apiVersion }', array(), self::CLIENT_IP);

        self::assertSame('2.0.0', $second['data']['apiVersion']);
        self::assertArrayNotHasKey('data', $third);
        self::assertSame(ErrorCode::RATE_LIMITED, $third['errors'][0]['extensions']['code']);
    }

    public function testTheMutationsBucketIsSharedWithHttp(): void
    {
        $issued = $this->issue();
        $config = array('rate_limit_mutations' => 1);
        $mutation = 'mutation { tokenRevoke(id: "QXBpVG9rZW46OTk5OTk5OTk5") { id } }';

        $this->http($issued['token'], $mutation, $config);
        $second = $this->executor($config)->execute($issued['token'], $mutation, array(), self::CLIENT_IP);

        self::assertSame(ErrorCode::RATE_LIMITED, $second['errors'][0]['extensions']['code']);
    }

    /**
     * D35: `trusted_clients` is judged against the clientIp Executor is
     * given, so a trusted end client gets the bigger bucket - and an
     * untrusted one does not, whatever address the caller itself has.
     */
    public function testTrustedClientsIsJudgedAgainstTheClientIpGiven(): void
    {
        $issued = $this->issue();
        $config = array(
            'rate_limit_queries' => 1, 'rate_limit_queries_trusted' => 5,
            'trusted_clients' => array('192.0.2.0/24')
        );
        $executor = $this->executor($config);

        $executor->execute($issued['token'], '{ apiVersion }', array(), '192.0.2.10');
        $trusted = $executor->execute($issued['token'], '{ apiVersion }', array(), '192.0.2.10');

        self::assertSame('2.0.0', $trusted['data']['apiVersion']);

        $this->limiter = null;
        $executor = $this->executor($config);
        $executor->execute($issued['token'], '{ apiVersion }', array(), self::CLIENT_IP);
        $untrusted = $executor->execute($issued['token'], '{ apiVersion }', array(), self::CLIENT_IP);

        self::assertSame(ErrorCode::RATE_LIMITED, $untrusted['errors'][0]['extensions']['code']);
    }

    // ---- account() and authenticate() ------------------------------------

    public function testAccountSummarisesTheRealRow(): void
    {
        self::assertSame(
            array(
                'adminId' => $this->fixture->resellerId(), 'username' => Fixture::PREFIX . 'reseller',
                'role' => 'RESELLER', 'apiAccess' => true
            ),
            $this->executor()->account($this->fixture->resellerId())
        );
        self::assertNull($this->executor()->account(999999999));
    }

    public function testAuthenticateIsThePanelsLogin(): void
    {
        $this->db->execute(
            'UPDATE admin SET admin_pass = ? WHERE admin_id = ?',
            array(Crypt::apr1MD5(self::PASSWORD), $this->fixture->customerId())
        );

        self::assertSame(
            $this->fixture->customerId(),
            $this->executor()->authenticate(Fixture::PREFIX . 'customer', self::PASSWORD)
        );
        self::assertNull($this->executor()->authenticate(Fixture::PREFIX . 'customer', 'wrong-Password-1'));
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param string[]             $scopes
     * @param array<string, mixed> $config
     * @return array{token: string, tokenId: int, expiresAt: int}
     */
    private function issue(
        array $scopes = array(Scope::DOMAINS_READ), int $ttlSeconds = 3600, array $config = array()
    ): array {
        return $this->executor($config)->issueToken(
            $this->fixture->customerId(), 'mcp', $scopes, $ttlSeconds
        );
    }

    /**
     * @param array<int, mixed>    $scopes
     * @param array<string, mixed> $config
     */
    private function issueRefusal(array $scopes, array $config = array()): ApiException
    {
        try {
            $this->issue($scopes, 3600, $config);
        } catch (ApiException $e) {
            return $e;
        }

        self::fail('Expected an ApiException.');
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

    private function tokenCount(): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM api_token WHERE admin_id = ?', array($this->fixture->customerId())
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function executor(array $config = array()): Executor
    {
        // A Container per execute(), as fromPanel() builds them.
        return Executor::forContainers(function () use ($config) {
            return $this->container($config);
        });
    }

    /**
     * The same document through POST /api/graphql's whole pipeline.
     *
     * @param array<string, mixed> $config
     */
    private function http(string $token, string $document, array $config = array()): Response
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(array('query' => $document)));
        rewind($stream);

        $request = Request::createFromEnvironment(Environment::mock(array(
            'REQUEST_METHOD'     => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'       => 'application/json',
            'REMOTE_ADDR'        => self::CLIENT_IP,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        )))->withBody(new Stream($stream));

        $response = ($this->container($config)->routeHandler())($request, new Response(), array());
        IdentityShim::reset();

        return $response;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config = array()): Container
    {
        return Container::forTesting(
            dirname(__DIR__, 2),
            array_merge(array(
                'debug' => false, 'introspection' => true,
                'max_query_depth' => 15, 'max_query_complexity' => 1000,
                'require_tls' => false, 'allowed_origins' => array(),
                'allow_session_auth' => false, 'allow_password_grant' => true,
                'token_default_ttl_days' => 365, 'token_max_ttl_days' => 730,
                'token_max_per_account' => 10, 'rate_limit_token_issue' => 50,
                'rate_limit_queries' => 200, 'rate_limit_mutations' => 200,
                'audit' => Audit::MODE_MUTATIONS, 'audit_retention_days' => 90
            ), $config),
            static function (string $sql, array $bind = array()) {
                return exec_query($sql, $bind);
            },
            static function (int $adminId) {
                $stmt = exec_query(
                    'SELECT admin_id, admin_name, admin_type, created_by, email, admin_status'
                        . ' FROM admin WHERE admin_id = ?',
                    array($adminId)
                );

                return $stmt->rowCount() ? $stmt->fetchRow(PDO::FETCH_ASSOC) : null;
            },
            function (int $adminId) {
                return $this->apiAccess;
            },
            $this->db,
            (array)Registry::get('config'),
            $this->core,
            null,
            null,
            $this->rateLimiter()
        );
    }

    /**
     * One limiter per test, counting in memory, shared by every Container a
     * test builds - which is what lets the HTTP and in-process charges meet
     * in one bucket.
     */
    private function rateLimiter(): RateLimiter
    {
        if ($this->limiter === null) {
            $this->limiter = new RateLimiter(
                static function () { return self::CLOCK; }, new InMemoryApcuStore(), $this->db
            );
        }

        return $this->limiter;
    }
}
