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
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\Audit;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
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
 * A stand-in for the panel's flash messenger.
 *
 * `tokenIssue` reaches the panel's own login listeners, one of which reports
 * a refused account through set_page_message(); outside a web session that
 * builds a Zend flash messenger, whose constructor calls Zend_Session::start(),
 * which throws once anything has been written to stdout - and PHPUnit's
 * printer always has. TokenMutationsTest carries the same stand-in and the
 * long version of this note; this is a second one rather than a shared one
 * because each is registered only if nothing else has claimed the key, and
 * which test class runs first is not something either may depend on.
 */
final class AuditFlashMessenger
{
    /**
     * @param mixed  $message
     * @param string $level
     *
     * @return void
     */
    public function addMessage($message, $level = 'info'): void
    {
        unset($message, $level);
    }
}

/**
 * Specification section 11 through the real request pipeline: Container's own
 * middleware stack, Container's own handler, and the real `api_audit` table
 * underneath.
 *
 * The cases here are about the row - which requests get one, what it says,
 * and what it must never say. The mechanism that decides the last of those,
 * redaction by type, is specified by test/unit/Service/AuditRedactionTest.
 *
 * `tokenIssue` is the sharpest of them, and it is here rather than there:
 * it is the one field in the schema that takes a password from an
 * unauthenticated caller, so it is simultaneously the row that most needs an
 * account named in it and the row that most needs a password kept out of it.
 */
class AuditTest extends IntegrationTestCase
{
    /** 2026-01-01T00:00:00Z, the first second of a minute and of an hour. */
    const CLOCK = 1767225600;

    const DAY = 86400;

    /** Long enough for the panel's own checkPasswordSyntax(). */
    const PASSWORD = 'Sgw4udit-Pass!';

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

    /** @var int */
    private $now;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // api_audit, from the plugin's own migration: this test fails if 001
        // stops producing a table the recorder can write. DDL commits
        // implicitly, which is why it happens here - before the fixture opens
        // its transaction - rather than in setUp(). api_rate comes with it
        // because the limiter in the pipeline needs somewhere to fall back to.
        $pdo = Db::fromPanel()->pdo();
        $root = dirname(__DIR__, 2);
        $tables = include $root . '/sql/001_create_api_tables.php';
        $rate = include $root . '/sql/002_create_rate_table.php';
        $pdo->exec($tables['up']);
        $pdo->exec($rate['up']);
    }

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->core = new RecordingCore(new PanelCore(false));
        $this->limiter = null;
        $this->now = self::CLOCK;

        // Inside the fixture's transaction, so it is rolled back with
        // everything else. The table is the plugin's own and is empty on a
        // test box, but a row left by a previous run of the endpoint would
        // make every count here wrong, and a count that is wrong for a reason
        // outside the test is worse than no count.
        $this->db->execute('DELETE FROM api_audit');

        $_SESSION = array();
        $_POST = array();
        IdentityShim::reset();

        if (!Registry::isRegistered('flashMessenger')) {
            Registry::set('flashMessenger', new AuditFlashMessenger());
        }

        $this->setPassword($this->fixture->customerId(), self::PASSWORD);

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
    }

    // ---- which requests get a row ---------------------------------------

    public function testAMutationWritesExactlyOneRowSayingWhatWasDone(): void
    {
        $token = $this->mintAToken();
        $tokenId = (int)$this->db->value(
            'SELECT token_id FROM api_token WHERE admin_id = ?',
            array($this->fixture->customerId())
        );
        $this->db->execute('DELETE FROM api_audit');

        $response = $this->send(
            'mutation Revoke($id: ID!) { tokenRevoke(id: $id) { id revokedAt } }',
            array('id' => GlobalId::encode('ApiToken', $tokenId)),
            array('HTTP_AUTHORIZATION' => 'Bearer ' . $token)
        );

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $rows = $this->rows();

        self::assertCount(1, $rows, 'one request, one row');

        $row = $rows[0];

        self::assertSame($this->fixture->customerId(), (int)$row['admin_id']);
        self::assertSame($tokenId, (int)$row['token_id'], 'the credential that was presented');
        self::assertSame('203.0.113.7', (string)$row['ip']);
        self::assertSame('Revoke', (string)$row['operation'], 'the operation name');
        self::assertSame('tokenRevoke', (string)$row['fields'], 'the field that was invoked');
        self::assertSame('ok', (string)$row['outcome']);
        self::assertNull($row['error_code']);
        self::assertGreaterThanOrEqual(0, (int)$row['duration_ms']);
        self::assertSame(
            array('id' => GlobalId::encode('ApiToken', $tokenId)),
            json_decode((string)$row['variables'], true),
            'an ID is not a Secret, so it is what the row is for: saying which object'
        );
        self::assertGreaterThanOrEqual(self::CLOCK, (int)$row['at']);
    }

    public function testAQueryIsNotRecordedInMutationsModeAndIsInAll(): void
    {
        $identity = $this->fixture->identity('customer', array(Scope::DOMAINS_READ));

        self::assertSame(
            200,
            $this->send('query V { apiVersion }', array(), array(), array(), $identity)
                ->getStatusCode()
        );
        self::assertSame(
            array(), $this->rows(), 'a query is not a mutation and `mutations` says so'
        );

        self::assertSame(
            200,
            $this->send(
                'query V { apiVersion }', array(), array(),
                array('audit' => Audit::MODE_ALL), $identity
            )->getStatusCode()
        );

        $rows = $this->rows();

        self::assertCount(1, $rows);
        self::assertSame('V', (string)$rows[0]['operation']);
        self::assertSame('apiVersion', (string)$rows[0]['fields']);
        self::assertSame('ok', (string)$rows[0]['outcome']);
    }

    public function testNoneRecordsNothingAtAll(): void
    {
        $this->send(
            $this->failingMutation(), array(), array(),
            array('audit' => Audit::MODE_NONE),
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );

        self::assertSame(array(), $this->rows());
    }

    public function testAFailedMutationRecordsTheCodeItFailedWith(): void
    {
        $response = $this->send(
            $this->failingMutation(), array(), array(), array(),
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );

        self::assertSame(
            ErrorCode::NOT_FOUND,
            $this->body($response)['errors'][0]['extensions']['code'] ?? null,
            (string)$response->getBody()
        );

        $rows = $this->rows();

        self::assertCount(1, $rows);
        self::assertSame('error', (string)$rows[0]['outcome']);
        self::assertSame(ErrorCode::NOT_FOUND, (string)$rows[0]['error_code']);
        self::assertSame('subdomainDelete', (string)$rows[0]['fields']);
        self::assertSame(
            $this->fixture->customerId(), (int)$rows[0]['admin_id'],
            'a request that failed is still a request somebody made'
        );
    }

    public function testADocumentThatWillNotParseIsRecordedWithEverythingRedacted(): void
    {
        // `all`, because a document that did not parse contains no mutation
        // that `mutations` could recognise - there is nothing in it at all.
        $response = $this->send(
            'mutation Smuggle($p: Secret! { customerCreate',
            array('p' => 'hunter2'),
            array(),
            array('audit' => Audit::MODE_ALL),
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );

        self::assertSame(400, $response->getStatusCode());

        $rows = $this->rows();

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['operation'], 'nothing parsed, so nothing is named');
        self::assertNull($rows[0]['fields']);
        self::assertSame('error', (string)$rows[0]['outcome']);
        self::assertSame(
            array('p' => '***'), json_decode((string)$rows[0]['variables'], true),
            'with no types to walk, no value can be shown to be safe'
        );
        self::assertStringNotContainsString('hunter2', json_encode($rows[0]));
    }

    // ---- the sharpest case ----------------------------------------------

    public function testATokenIssueNamesTheAccountItAuthenticatedAndCarriesNoPassword(): void
    {
        $response = $this->issue();

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $rows = $this->rows();

        self::assertCount(1, $rows);

        $row = $rows[0];

        // The request presented no credential at all - tokenIssue is the one
        // field in the schema served without one - and the row still names
        // the account, because the resolver that authenticated it said so.
        self::assertSame(
            $this->fixture->customerId(), (int)$row['admin_id'],
            'the row must name the account that was authenticated'
        );
        self::assertNull($row['token_id'], 'there was no token to present yet');
        self::assertSame('Issue', (string)$row['operation']);
        self::assertSame('tokenIssue', (string)$row['fields']);
        self::assertSame('ok', (string)$row['outcome']);

        // The whole point. Not the password, and not the token it minted
        // either - both are typed Secret in the SDL, and the second is only
        // ever returned once.
        self::assertStringNotContainsString(
            self::PASSWORD, json_encode($row), 'the password reached the audit row'
        );
        self::assertSame(
            array(
                'username' => Fixture::PREFIX . 'customer',
                'password' => '***',
                'name'     => 'ci',
                'scopes'   => array(Scope::DOMAINS_WRITE)
            ),
            json_decode((string)$row['variables'], true)['input']
        );
    }

    public function testATokenIssueThatFailsRecordsNoPasswordEither(): void
    {
        // The failure paths are the ones where a password is most likely to
        // be carried along by something written to explain what went wrong.
        $this->issue(array('password' => 'not-the-password'));
        $this->issue(array('username' => Fixture::PREFIX . 'nobody'));
        $this->issue(array('scopes' => array(Scope::RESELLERS_WRITE)));

        $rows = $this->rows();

        self::assertCount(3, $rows);

        foreach ($rows as $row) {
            self::assertSame('error', (string)$row['outcome'], json_encode($row));
            self::assertStringNotContainsString(self::PASSWORD, json_encode($row));
            self::assertStringNotContainsString('not-the-password', json_encode($row));
            self::assertSame(
                '***', json_decode((string)$row['variables'], true)['input']['password']
            );
        }

        // A credential that was never accepted names no account; one that was
        // accepted and then refused on its own merits does.
        self::assertSame(0, (int)$rows[0]['admin_id'], 'the wrong password');
        self::assertSame(0, (int)$rows[1]['admin_id'], 'a username that does not exist');
        self::assertSame(
            $this->fixture->customerId(), (int)$rows[2]['admin_id'],
            'the credentials were right; the scope it asked for was not'
        );
        self::assertSame(ErrorCode::FORBIDDEN, (string)$rows[2]['error_code']);
    }

    // ---- decision D27 ---------------------------------------------------

    public function testTheRequestIsAnsweredEvenWhenTheAuditTableIsNotThere(): void
    {
        // The table renamed away, without the DDL that would commit the
        // fixture's transaction out from under this test: the recorder's own
        // handle rewrites the one table name it uses, so the INSERT reaches
        // the server and comes back "table doesn't exist" exactly as it would
        // on an installation whose migration never ran.
        $missing = new class($this->db->pdo()) extends Db {
            public function execute(string $sql, array $bind = array()): int
            {
                return parent::execute(
                    str_replace('api_audit', 'api_audit_is_not_here', $sql), $bind
                );
            }
        };

        $audit = new Audit(
            $missing, $this->container()->schemaFactory()->create(),
            Audit::MODE_ALL, 90,
            function () { return $this->now; },
            $this->core
        );

        // `tokenIssue`, because it is the request whose row matters most and
        // therefore the one whose row is worst to fail over: it is a mutation,
        // it authenticates, and it answers 200 with a real token.
        $response = $this->issue(array(), $audit);

        // The request is answered on its own merits, not on the audit's.
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $issued = $this->body($response);

        self::assertArrayNotHasKey('errors', $issued, json_encode($issued));
        self::assertMatchesRegularExpression(
            '/^imscp_[a-z2-7]{8}_[A-Za-z0-9_-]{43}$/',
            (string)$issued['data']['tokenIssue']['token'],
            'the answer is whole, not a casualty of the missing table'
        );
        self::assertSame(array(), $this->rows(), 'and no row was written anywhere');

        // ...and it did not happen quietly. An audit trail that can go
        // missing without a word is not one.
        $logs = array_filter($this->core->logs, static function (array $log) {
            return strpos($log[0], 'could not be audited') !== false;
        });

        self::assertCount(1, $logs, json_encode($this->core->logs));
    }

    // ---- retention ------------------------------------------------------

    public function testPruningRemovesWhatItShouldAndKeepsWhatItShould(): void
    {
        $audit = $this->auditWithRetention(30);

        $this->insertRow(self::CLOCK - (31 * self::DAY));   // past keeping
        $this->insertRow(self::CLOCK - (30 * self::DAY) - 1);
        $this->insertRow(self::CLOCK - (30 * self::DAY));   // exactly the cut-off
        $this->insertRow(self::CLOCK - self::DAY);
        $this->insertRow(self::CLOCK);                      // this second

        self::assertSame(2, $audit->prune());
        self::assertSame(
            array(
                self::CLOCK - (30 * self::DAY), self::CLOCK - self::DAY, self::CLOCK
            ),
            array_map(static function (array $row) {
                return (int)$row['at'];
            }, $this->rows())
        );
    }

    public function testARetentionOfZeroKeepsEverythingRatherThanEmptyingTheTable(): void
    {
        // The shape of bug Task 11 found in the rate table's prune: a cut-off
        // computed without a guard lands on *now*, and the first request
        // after it deletes the whole table - including the row it has just
        // written. Zero days means "keep everything" here, and says so.
        $this->insertRow(self::CLOCK - (400 * self::DAY));
        $this->insertRow(self::CLOCK);

        self::assertSame(0, $this->auditWithRetention(0)->prune());
        self::assertSame(0, $this->auditWithRetention(-1)->prune());
        self::assertCount(2, $this->rows());
    }

    public function testAPrunedTableStillHoldsTheRowTheRequestJustWrote(): void
    {
        // prune() runs opportunistically from record(), so the one row it can
        // never be allowed to take is the one record() has just inserted.
        // Driven directly rather than waiting for the one-in-a-hundred.
        $audit = $this->auditWithRetention(1);

        $this->insertRow(self::CLOCK - (2 * self::DAY));
        $this->insertRow(self::CLOCK);

        self::assertSame(1, $audit->prune());
        self::assertCount(1, $this->rows());
        self::assertSame(self::CLOCK, (int)$this->rows()[0]['at']);
    }

    // ---- helpers --------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->db->rows('SELECT * FROM api_audit ORDER BY audit_id');
    }

    /**
     * @return void
     */
    private function insertRow(int $at): void
    {
        $this->db->execute(
            'INSERT INTO api_audit'
                . ' (at, admin_id, token_id, ip, operation, fields, variables,'
                . ' outcome, error_code, duration_ms)'
                . ' VALUES (?, ?, NULL, ?, NULL, NULL, ?, ?, NULL, 1)',
            array($at, $this->fixture->customerId(), '203.0.113.7', '{}', 'ok')
        );
    }

    private function auditWithRetention(int $days): Audit
    {
        return new Audit(
            $this->db, $this->container()->schemaFactory()->create(),
            Audit::MODE_MUTATIONS, $days,
            function () { return $this->now; },
            $this->core
        );
    }

    /**
     * A mutation that is refused on its own merits and reaches no daemon:
     * there is no such subdomain, and an object the caller cannot reach is
     * NOT_FOUND.
     */
    private function failingMutation(): string
    {
        return 'mutation Del { subdomainDelete(id: "'
            . GlobalId::encode(NodeType::SUBDOMAIN, 999999999) . '") { id } }';
    }

    /**
     * `tokenIssue` through the pipeline, with no credential presented.
     *
     * @param array<string, mixed> $input
     */
    private function issue(array $input = array(), ?Audit $audit = null): Response
    {
        return $this->send(
            'mutation Issue($input: TokenIssueInput!) {'
                . ' tokenIssue(input: $input) { token apiToken { id prefix } } }',
            array('input' => array_merge(array(
                'username' => Fixture::PREFIX . 'customer',
                'password' => self::PASSWORD,
                'name'     => 'ci',
                'scopes'   => array(Scope::DOMAINS_WRITE)
            ), $input)),
            array(), array(), null, $audit
        );
    }

    /**
     * A bearer token for the fixture's customer, minted the way a client
     * would mint one, with the row it wrote cleared afterwards.
     */
    private function mintAToken(): string
    {
        $issued = $this->body($this->issue());

        self::assertArrayNotHasKey('errors', $issued, json_encode($issued));

        return (string)$issued['data']['tokenIssue']['token'];
    }

    /**
     * One request through the whole composed pipeline, exactly as
     * PluginRoutesInjector dispatches it.
     *
     * @param array<string, mixed>  $variables
     * @param array<string, string> $headers
     * @param array<string, mixed>  $config
     */
    private function send(
        string $document, array $variables = array(), array $headers = array(),
        array $config = array(), $identity = null, ?Audit $audit = null
    ): Response {
        $env = Environment::mock(array_merge(array(
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'   => 'application/json',
            'REMOTE_ADDR'    => '203.0.113.7'
        ), $headers));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(array('query' => $document, 'variables' => $variables)));
        rewind($stream);

        $request = Request::createFromEnvironment($env)->withBody(new Stream($stream));

        $container = $this->container($config, $audit);

        if ($identity === null) {
            return ($container->routeHandler())($request, new Response(), array());
        }

        // An identity set directly, and therefore Container's own handler
        // rather than its whole pipeline: AuthenticateMiddleware always sets
        // the attribute itself, so a pipeline run would replace this one with
        // the null it reads from a request carrying no credential. What is
        // under test here is the row, not how a credential is read - and the
        // two cases that really do present one, tokenRevoke with a bearer
        // token and tokenIssue with none, go through the whole pipeline
        // above. This is the same division RateLimitTest draws, for the same
        // reason.
        $handler = $container->handler();

        return $handler(
            $request->withAttribute('identity', $identity), new Response(), array()
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config = array(), ?Audit $audit = null): Container
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

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
            static function (int $adminId) { return true; },
            $this->db,
            (array)Registry::get('config'),
            $this->core,
            null,
            null,
            $this->rateLimiter(),
            $audit
        );
    }

    /**
     * One limiter for the whole test, counting in memory.
     *
     * In memory for the reason TokenMutationsTest gives: the buckets
     * `tokenIssue` charges are keyed by username and by address, which every
     * test here shares, and a real APCu store outlives the fixture's
     * transaction.
     */
    private function rateLimiter(): RateLimiter
    {
        if ($this->limiter === null) {
            $this->limiter = new RateLimiter(
                function () { return $this->now; }, new InMemoryApcuStore(), $this->db
            );
        }

        return $this->limiter;
    }

    /**
     * @return void
     */
    private function setPassword(int $adminId, string $password): void
    {
        $this->db->execute(
            'UPDATE admin SET admin_pass = ? WHERE admin_id = ?',
            array(Crypt::apr1MD5($password), $adminId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        return (array)json_decode((string)$response->getBody(), true);
    }
}
