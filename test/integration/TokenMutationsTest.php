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
use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use iMSCP\Crypt;
use iMSCP\Event\EventAggregator;
use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\InMemoryApcuStore;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;
use iMSCP\Registry;
use PDO;
use ReflectionProperty;
use RuntimeException;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;
use Throwable;

/**
 * A stand-in for the panel's flash messenger, so that set_page_message() is
 * harmless in a process with no web session.
 *
 * login_checkDomainAccount() reports a disabled or expired account by calling
 * set_page_message(), which builds a Zend_Controller_Action_Helper_FlashMessenger
 * unless one is already registered - and that constructor calls
 * Zend_Session::start(), which throws Zend_Session_Exception once anything has
 * been written to stdout. Under PHP-FPM the panel has already started a
 * session before the API route runs, so the real one works and nothing throws;
 * under PHPUnit the printer has written its banner and nothing can. Registering
 * this instead is what makes the *panel's own* refusal path reachable from a
 * test at all - the alternative is not testing it.
 */
final class HarmlessFlashMessenger
{
    /** @var array<int, array{0: mixed, 1: string}> */
    public $messages = array();

    /**
     * @param mixed  $message
     * @param string $level
     */
    public function addMessage($message, $level = 'info'): void
    {
        $this->messages[] = array($message, $level);
    }
}

/**
 * Specification section 5.3: `tokenIssue`, the one field in the schema served
 * without a credential, and `tokenRevoke`.
 *
 * These cases are the specification of the two fields. Neither is in the
 * authorisation matrix, and test/authz/CatalogueCoverageTest says why.
 *
 * **On the error handler this class installs.** The panel's bootstrap turns
 * every PHP warning into an exception and renders a fatal-error page
 * (gui/src/Application.php:295), so a warning in the integration suite does
 * not fail a test, it kills the process mid-run. Two warnings are unavoidable
 * here and are artefacts of the process rather than of the code. PHPUnit's
 * printer has already written to stdout, so `headers_sent()` is true, and
 * PHP will then not regenerate a session id however the session was opened -
 * so AuthService::setIdentity()'s `session_regenerate_id()` cannot run. (The
 * session functions work here at all only because test/bootstrap.php turns
 * the session cookie and the cache limiter off before that first byte; see
 * the comment there.) The second is `session_destroy()` on a branch that
 * reached it with no session, which login_checkDomainAccount() can still
 * produce. Under PHP-FPM the panel starts a session in its own bootstrap and
 * the API route answers before any output, so neither happens. Those two,
 * from that one file, are allowed through; anything else raises, so this
 * class is not quietly deaf to a warning that would matter.
 */
class TokenMutationsTest extends IntegrationTestCase
{
    /** 2026-01-01T00:00:00Z, the first second of a minute and of an hour. */
    const CLOCK = 1767225600;

    /** Long enough for the panel's own checkPasswordSyntax(), not that this path asks. */
    const PASSWORD = 'Sgwt0kenPass!';

    /** The two CLI-only warnings described in the class docblock. */
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

    /** @var InMemoryApcuStore|null */
    private $store;

    /** @var int */
    private $now;

    /** @var array<string, mixed> */
    private $session;

    /** @var bool */
    private $apiAccessAllowed = true;

    /** @var string */
    private $clientIp = '203.0.113.7';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // api_rate is the limiter's fallback table. Created here, from the
        // plugin's own migration, exactly as RateLimitTest does: DDL commits
        // implicitly, so it cannot happen inside the fixture's transaction.
        $migration = include dirname(__DIR__, 2) . '/sql/002_create_rate_table.php';
        Db::fromPanel()->pdo()->exec($migration['up']);
    }

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->core = new RecordingCore(new PanelCore(false));
        $this->now = self::CLOCK;
        $this->limiter = null;
        $this->store = null;
        $this->apiAccessAllowed = true;
        $this->clientIp = '203.0.113.7';

        $_SESSION = array();
        $_POST = array();
        $this->session = $_SESSION;
        IdentityShim::reset();

        if (!Registry::isRegistered('flashMessenger')) {
            Registry::set('flashMessenger', new HarmlessFlashMessenger());
        }

        $this->setPassword($this->fixture->customerId(), self::PASSWORD);
        $this->setPassword($this->fixture->resellerId(), self::PASSWORD);

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

        // A test that opened a caller session leaves the process holding one.
        // Destroyed rather than closed, so the next test starts where every
        // other one does: with no session at all.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->fixture->rollBack();
    }

    // ---- the happy path -------------------------------------------------

    public function testTheRightCredentialsMintAToken(): void
    {
        $result = $this->issue();

        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        $issued = $result['data']['tokenIssue'];

        self::assertMatchesRegularExpression('/^imscp_[a-z2-7]{8}_[A-Za-z0-9_-]{43}$/', $issued['token']);
        self::assertSame('ci', $issued['apiToken']['name']);
        self::assertSame(array(Scope::DOMAINS_WRITE), $issued['apiToken']['scopes']);
        self::assertSame(substr($issued['token'], 6, 8), $issued['apiToken']['prefix']);
        self::assertNull($issued['apiToken']['revokedAt']);
        self::assertSame($issued['expiresAt'], $issued['apiToken']['expiresAt']);

        // The row exists, belongs to the account that proved who it was, and
        // stores a hash rather than the secret.
        $row = $this->db->row(
            'SELECT * FROM api_token WHERE token_prefix = ?',
            array($issued['apiToken']['prefix'])
        );

        self::assertNotNull($row);
        self::assertSame($this->fixture->customerId(), (int)$row['admin_id']);
        self::assertSame(
            hash('sha256', substr($issued['token'], 15)), (string)$row['token_hash']
        );

        // And the panel's log says so, beside the equivalent UI action.
        self::assertSame(
            array(array(
                'An API token (ci) has been issued for ' . Fixture::PREFIX . 'customer',
                E_USER_NOTICE
            )),
            $this->core->logs
        );
    }

    public function testTheMintedTokenActuallyAuthenticates(): void
    {
        // The other half of "mints a token": the secret handed back is one the
        // endpoint accepts. Without this the mutation could return any string
        // at all and every other case here would still pass.
        $token = $this->issue()['data']['tokenIssue']['token'];
        $response = $this->send('{ viewer { username } }', array(
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            Fixture::PREFIX . 'customer',
            $this->body($response)['data']['viewer']['username'] ?? null
        );
    }

    public function testTheTokenIsReturnedExactlyOnceAndNeverReadBack(): void
    {
        $issued = $this->issue()['data']['tokenIssue'];
        $encodedId = $issued['apiToken']['id'];

        // 1. It is not in the row it created: only a SHA-256 of it is.
        $row = $this->db->row(
            'SELECT * FROM api_token WHERE token_prefix = ?',
            array($issued['apiToken']['prefix'])
        );

        self::assertStringNotContainsString(
            substr($issued['token'], 15), json_encode($row),
            'the secret reached the database'
        );

        // 2. It is not in ApiToken, which is the only type that describes a
        //    token at all - so revoking one, the one other place a token is
        //    returned, cannot hand it back either.
        $revoked = $this->execute(
            'mutation($id: ID!) { tokenRevoke(id: $id) { id prefix name scopes revokedAt } }',
            array('id' => $encodedId),
            $this->identityFor('customer')
        );

        self::assertArrayNotHasKey('errors', $revoked, json_encode($revoked));
        self::assertStringNotContainsString(substr($issued['token'], 15), json_encode($revoked));

        // 3. And there is no field anywhere in the schema, outside the
        //    mutation that mints one, that could return a Secret at all.
        self::assertSame(
            array('TokenIssueResult.token'), $this->secretReturningFields()
        );
    }

    // ---- what it must not say -------------------------------------------

    public function testTheWrongPasswordIsRefusedWithoutSayingWhichPartWasWrong(): void
    {
        $wrongPassword = $this->issue(array('password' => 'not-the-password'));
        $unknownUser = $this->issue(array('username' => Fixture::PREFIX . 'nobody'));

        self::assertSame(
            ErrorCode::UNAUTHENTICATED, $this->codeOf($wrongPassword), json_encode($wrongPassword)
        );

        // Byte for byte, error for error: an attacker reading two responses
        // side by side must not be able to tell a username that exists from
        // one that does not.
        self::assertSame(
            json_encode($wrongPassword['errors']), json_encode($unknownUser['errors'])
        );
        self::assertSame(
            array('code' => ErrorCode::UNAUTHENTICATED),
            $wrongPassword['errors'][0]['extensions'],
            'an extension that told the two apart would undo the single message'
        );
    }

    public function testAnAccountThatIsNotOkCannotMintAToken(): void
    {
        // The panel's own check, inherited rather than reimplemented:
        // login_checkDomainAccount() stops the onBeforeSetIdentity event, so
        // no identity reaches the session even though the password was right.
        $this->db->execute(
            'UPDATE admin SET admin_status = ? WHERE admin_id = ?',
            array('disabled', $this->fixture->customerId())
        );

        $disabled = $this->issue();
        $wrongPassword = $this->issue(array('password' => 'not-the-password'));

        self::assertSame(ErrorCode::UNAUTHENTICATED, $this->codeOf($disabled), json_encode($disabled));
        self::assertSame(
            json_encode($wrongPassword['errors']), json_encode($disabled['errors']),
            'a disabled account must be indistinguishable from a wrong password'
        );
        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM api_token WHERE admin_id = ?',
                array($this->fixture->customerId())
            )
        );
    }

    public function testThePasswordIsNeverInAnError(): void
    {
        $secret = 'Sekrit-N3ver-Echoed';

        foreach (array(
            array('password' => $secret),
            array('password' => $secret, 'username' => Fixture::PREFIX . 'nobody'),
            array('password' => $secret, 'scopes' => array(Scope::RESELLERS_WRITE)),
            array('password' => $secret, 'name' => ''),
            array('password' => $secret, 'expiresInDays' => 0)
        ) as $input) {
            $result = $this->issue($input);

            self::assertArrayHasKey('errors', $result, json_encode($input));
            self::assertStringNotContainsString($secret, json_encode($result), json_encode($input));
        }

        // Nor in the panel's log, which the equivalent UI action writes to.
        self::assertStringNotContainsString($secret, json_encode($this->core->calls));
    }

    // ---- no session, no login row ---------------------------------------

    public function testNoLoginRowAndNoSessionSurvive(): void
    {
        // M21: AuthService::authenticate() sets an identity on success, which
        // regenerates the session and writes a `login` row. Spec section 5.3
        // wants neither, on any path - so this asks after a success and after
        // a failure, and after the failure that throws on its way out.
        $before = $_SESSION;

        self::assertArrayNotHasKey('errors', $this->issue(), 'the successful path');
        $this->assertNothingSurvived($before);

        self::assertArrayHasKey('errors', $this->issue(array('password' => 'wrong')));
        $this->assertNothingSurvived($before);

        self::assertArrayHasKey(
            'errors', $this->issue(array('username' => Fixture::PREFIX . 'nobody'))
        );
        $this->assertNothingSurvived($before);
    }

    /**
     * @param array<string, mixed> $before
     */
    private function assertNothingSurvived(array $before): void
    {
        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM login WHERE user_name = ?',
                array(Fixture::PREFIX . 'customer')
            ),
            'a `login` row survived the call'
        );
        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM login WHERE session_id = ?', array(session_id())
            ),
            'a `login` row for this request survived the call'
        );
        self::assertSame($before, $_SESSION, 'the session the caller arrived with changed');
        self::assertSame(array(), $_POST, '$_POST was not put back');
    }

    // ---- D34: the call borrows no session it did not make ----------------

    /**
     * Checkpoint D, finding D1. The panel addresses `login` by `session_id()`
     * and `login`'s primary key *is* `session_id`, so a call that runs under
     * the caller's session id deletes or replaces the caller's own row:
     * `AuthService::unsetIdentity()` deletes by it, `AuthService::authenticate()`
     * calls that itself before every `setIdentity()`, and `BruteForce` REPLACEs
     * by it. A browser with a panel session that posted `tokenIssue` was
     * silently logged out, right password or wrong.
     */
    public function testThePanelLoginRowTheRequestArrivedWithSurvivesEveryPath(): void
    {
        $sessionId = 'sgwt-caller-session';
        $this->openCallerSession($sessionId);

        self::assertArrayNotHasKey('errors', $this->issue(), 'the successful path');
        self::assertSame(1, $this->callerLoginRows($sessionId), 'after a success');

        self::assertArrayHasKey('errors', $this->issue(array('password' => 'wrong')));
        self::assertSame(1, $this->callerLoginRows($sessionId), 'after a wrong password');

        self::assertArrayHasKey('errors', $this->issueThatThrows());
        self::assertSame(1, $this->callerLoginRows($sessionId), 'after a throw');
    }

    /**
     * Finding D2. `BruteForce` looks its counter up by `ipaddr` but stores it
     * with `session_id`, so deleting by the session id the attempt ran under
     * erased the counter every time and it could never reach
     * BRUTEFORCE_MAX_LOGIN - on the one field in the schema served without a
     * credential.
     *
     * Each attempt is preceded by nextRequestsLoginListeners(), because
     * `BruteForce` is built once by `init_login()` and reads the row once, in
     * its constructor: in production that is once per request, and in this
     * process - one process for the whole suite - it would otherwise be once
     * ever, with `logAttempt()` taking the create branch for ever after
     * (gui/src/Plugin/BruteForce.php:250-258). What is under test is the row
     * surviving between attempts; the second attempt has to be a second
     * request for that to be visible at all.
     */
    public function testTwoFailedAttemptsLeaveBruteForcesCounterAtTwo(): void
    {
        $this->nextRequestsLoginListeners();
        self::assertArrayHasKey('errors', $this->issue(array('password' => 'wrong')));
        self::assertSame(1, $this->bruteForceCount(), 'the first attempt');

        $this->nextRequestsLoginListeners();
        self::assertArrayHasKey('errors', $this->issue(array('password' => 'wrong')));
        self::assertSame(2, $this->bruteForceCount(), 'the second attempt');
    }

    /**
     * The other half of D34: the identity row `setIdentity()` writes is this
     * call's own, and it goes. Spec section 5.3 wants no panel session out of
     * `tokenIssue`, only a token.
     */
    public function testASuccessfulIssueLeavesNoLoginRowForTheIdentity(): void
    {
        self::assertArrayNotHasKey('errors', $this->issue());

        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM login WHERE user_name = ?',
                array(Fixture::PREFIX . 'customer')
            ),
            'the identity row survived the call'
        );
    }

    public function testTheCallersSessionAndPostAreWhatTheyWere(): void
    {
        $this->openCallerSession('sgwt-caller-session');
        $_POST = array('uname' => 'the caller\'s own');
        $session = $_SESSION;
        $post = $_POST;

        self::assertArrayNotHasKey('errors', $this->issue());

        self::assertSame($session, $_SESSION, '$_SESSION was not put back');
        self::assertSame($post, $_POST, '$_POST was not put back');
        self::assertSame(
            PHP_SESSION_ACTIVE, session_status(), 'the caller\'s session was left closed'
        );
        self::assertSame('sgwt-caller-session', session_id(), 'another session id was left open');
    }

    /**
     * The session a request with a panel cookie arrives holding: an open
     * session, and the `login` row that is what `AuthService::hasIdentity()`
     * reads to decide the caller is still logged in.
     */
    private function openCallerSession(string $sessionId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_id($sessionId);
        session_start();

        $_SESSION = array('user_id' => $this->fixture->resellerId());
        $this->session = $_SESSION;

        $this->db->execute(
            'INSERT INTO login (session_id, ipaddr, lastaccess, user_name) VALUES (?, ?, ?, ?)',
            array($sessionId, $this->clientIp, time(), Fixture::PREFIX . 'reseller')
        );
    }

    private function callerLoginRows(string $sessionId): int
    {
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM login WHERE session_id = ?', array($sessionId)
        );
    }

    private function bruteForceCount(): int
    {
        return (int)$this->db->value(
            'SELECT login_count FROM login WHERE user_name = ? AND ipaddr = ?',
            array('__bruteforce__', $this->clientIp)
        );
    }

    /**
     * Put the process back where a fresh request would find it: the panel's
     * login listeners unregistered, and PanelCore's once-per-process flag
     * cleared so that its next call registers them again - which is what
     * builds the next `BruteForce`.
     *
     * The listeners are cleared rather than merely re-registered so that the
     * process is left holding exactly one set of them, as every other test
     * here assumes.
     */
    private function nextRequestsLoginListeners(): void
    {
        $aggregator = EventAggregator::getInstance();
        $events = array(
            Events::onBeforeAuthentication, Events::onAuthentication,
            Events::onAfterAuthentication, Events::onBeforeSetIdentity
        );

        foreach ($events as $event) {
            $aggregator->clearListeners($event);
        }

        $flag = new ReflectionProperty(PanelCore::class, 'loginInitialised');
        $flag->setAccessible(true);
        $flag->setValue(null, false);
    }

    /**
     * One `tokenIssue` whose authenticate() throws on its way out, so that
     * the `finally` that restores everything is exercised on the one path a
     * return cannot reach.
     *
     * The listener is registered on the aggregator's own manager rather than
     * through EventAggregator::registerListener(), which answers fluently and
     * hands back no EventListener to unregister afterwards. Both end up in
     * the same 'application' manager - the aggregator declares no event types
     * of its own - so this listener is dispatched exactly as init_login()'s
     * are, and is gone again before the next test.
     *
     * @return array<string, mixed>
     */
    private function issueThatThrows(): array
    {
        $manager = EventAggregator::getInstance()->getEventManager('application');
        $listener = $manager->registerListener(
            Events::onBeforeAuthentication,
            static function () {
                throw new RuntimeException('the panel threw');
            },
            1000
        );

        try {
            return $this->issue();
        } finally {
            $manager->unregisterListener($listener);
        }
    }

    // ---- section 6.2, after the credentials were right ------------------

    public function testAnAccountWithoutApiAccessCannotMintAToken(): void
    {
        $this->apiAccessAllowed = false;

        $result = $this->issue();

        // FORBIDDEN, and it says so: this answer is only ever given to a
        // caller that has already proved who it is, so there is nothing left
        // to withhold from it.
        self::assertSame(ErrorCode::FORBIDDEN, $this->codeOf($result), json_encode($result));
        self::assertSame(
            'API access has been withdrawn from this account.', $result['errors'][0]['message']
        );
        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM api_token WHERE admin_id = ?',
                array($this->fixture->customerId())
            )
        );
    }

    public function testTheFieldIsRefusedWhenAllowPasswordGrantIsFalse(): void
    {
        $result = $this->issue(array(), array('allow_password_grant' => false));

        self::assertSame(ErrorCode::FORBIDDEN, $this->codeOf($result), json_encode($result));

        // And it is refused before the credentials are even looked at, so an
        // installation with the grant off is not a slower oracle than one
        // with it on.
        self::assertSame(
            json_encode($result['errors']),
            json_encode($this->issue(
                array('password' => 'not-the-password'), array('allow_password_grant' => false)
            )['errors'])
        );
        self::assertSame(array(), $this->core->callsNamed('authenticate'));
    }

    // ---- scopes ----------------------------------------------------------

    public function testTheScopesAskedForAreTheScopesCarried(): void
    {
        $asked = array(Scope::DOMAINS_READ, Scope::MAIL_WRITE, Scope::SQL_READ);
        $issued = $this->issue(array('scopes' => $asked))['data']['tokenIssue'];

        self::assertSame($asked, $issued['apiToken']['scopes']);
        self::assertSame(
            implode(',', $asked),
            (string)$this->db->value(
                'SELECT scopes FROM api_token WHERE token_prefix = ?',
                array($issued['apiToken']['prefix'])
            )
        );

        // ...and they are the scopes the credential then carries, not merely
        // the ones recorded beside it.
        $response = $this->send('{ viewer { scopes } }', array(
            'HTTP_AUTHORIZATION' => 'Bearer ' . $issued['token']
        ));

        self::assertSame($asked, $this->body($response)['data']['viewer']['scopes'] ?? null);
    }

    public function testAScopeTheRoleCannotHoldIsRefused(): void
    {
        // A customer asking for RESELLERS_WRITE. Refused rather than dropped:
        // a client handed a token quietly missing the scope it asked for finds
        // out at the first request that needed it, and cannot tell that from a
        // revoked token.
        $result = $this->issue(array('scopes' => array(Scope::DOMAINS_READ, Scope::RESELLERS_WRITE)));

        self::assertSame(ErrorCode::FORBIDDEN, $this->codeOf($result), json_encode($result));
        self::assertSame(Scope::RESELLERS_WRITE, $result['errors'][0]['extensions']['scope']);
        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM api_token WHERE admin_id = ?',
                array($this->fixture->customerId())
            ),
            'nothing may be written when one of the scopes is refused'
        );

        // The same scope, for an account whose role can hold it.
        $reseller = $this->issue(array(
            'username' => Fixture::PREFIX . 'reseller',
            'scopes'   => array(Scope::CUSTOMERS_WRITE)
        ));

        self::assertArrayNotHasKey('errors', $reseller, json_encode($reseller));
    }

    public function testATokenWithNoScopesAtAllIsRefused(): void
    {
        // Identity::hasScope() reads a credential that records no scopes as a
        // full one, so an empty list would mint the widest token the account
        // can have - the opposite of what asking for nothing means.
        $result = $this->issue(array('scopes' => array()));

        self::assertSame(ErrorCode::BAD_USER_INPUT, $this->codeOf($result), json_encode($result));
        self::assertSame('input.scopes', $result['errors'][0]['extensions']['field']);
    }

    // ---- lifetime and count ---------------------------------------------

    public function testTheLifetimeIsCappedByTokenMaxTtlDays(): void
    {
        $issued = $this->issue(
            array('expiresInDays' => 3650), array('token_max_ttl_days' => 30)
        )['data']['tokenIssue'];

        $expiresAt = (int)$this->db->value(
            'SELECT expires_at FROM api_token WHERE token_prefix = ?',
            array($issued['apiToken']['prefix'])
        );

        // Thirty days, not ten years, and expiresAt says so rather than the
        // caller being left to believe what it asked for.
        self::assertEqualsWithDelta(time() + (30 * 86400), $expiresAt, 60);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $expiresAt), $issued['expiresAt']);

        // An absent expiresInDays is the configured default, capped the same way.
        $default = $this->issue(
            array(), array('token_default_ttl_days' => 7, 'token_max_ttl_days' => 30)
        )['data']['tokenIssue'];

        self::assertEqualsWithDelta(
            time() + (7 * 86400),
            (int)$this->db->value(
                'SELECT expires_at FROM api_token WHERE token_prefix = ?',
                array($default['apiToken']['prefix'])
            ),
            60
        );
    }

    public function testTheAccountsTokenLimitIsEnforced(): void
    {
        $config = array('token_max_per_account' => 2);

        self::assertArrayNotHasKey('errors', $this->issue(array(), $config));
        $second = $this->issue(array(), $config);
        self::assertArrayNotHasKey('errors', $second, json_encode($second));

        $third = $this->issue(array(), $config);

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $this->codeOf($third), json_encode($third));
        self::assertSame(
            array('quota' => 'tokens', 'limit' => 2, 'used' => 2, 'code' => ErrorCode::LIMIT_EXCEEDED),
            $third['errors'][0]['extensions']
        );

        // A revoked token is not one the account can present, so it does not
        // hold a place: otherwise an account that had rotated its tokens twice
        // could never mint another.
        $revoked = $this->execute(
            'mutation($id: ID!) { tokenRevoke(id: $id) { revokedAt } }',
            array('id' => $second['data']['tokenIssue']['apiToken']['id']),
            $this->identityFor('customer')
        );

        self::assertArrayNotHasKey('errors', $revoked, json_encode($revoked));
        self::assertArrayNotHasKey('errors', $this->issue(array(), $config));
    }

    // ---- the two buckets -------------------------------------------------

    public function testFiveAttemptsAMinuteFromOneAddressIsTheLimit(): void
    {
        // Five different usernames, so that only the per-address bucket can
        // be the one that answers.
        for ($i = 1; $i <= 5; $i++) {
            $result = $this->issue(array('username' => Fixture::PREFIX . 'nobody' . $i));

            self::assertSame(
                ErrorCode::UNAUTHENTICATED, $this->codeOf($result),
                'attempt ' . $i . ' should have been let through to fail on its credentials'
            );
        }

        $sixth = $this->issue(array('username' => Fixture::PREFIX . 'nobody6'));

        self::assertSame(ErrorCode::RATE_LIMITED, $this->codeOf($sixth), json_encode($sixth));
        self::assertGreaterThanOrEqual(1, $sixth['errors'][0]['extensions']['retryAfterSeconds']);
        self::assertLessThanOrEqual(60, $sixth['errors'][0]['extensions']['retryAfterSeconds']);

        // A minute later the same address is welcome again, and a different
        // address was never counted with it.
        $this->now += 60;
        self::assertSame(
            ErrorCode::UNAUTHENTICATED,
            $this->codeOf($this->issue(array('username' => Fixture::PREFIX . 'nobody7')))
        );
    }

    public function testADifferentAddressHasItsOwnCount(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->issue(array('username' => Fixture::PREFIX . 'nobody' . $i));
        }

        $this->clientIp = '198.51.100.9';

        self::assertSame(
            ErrorCode::UNAUTHENTICATED,
            $this->codeOf($this->issue(array('username' => Fixture::PREFIX . 'nobody9'))),
            'a second address was refused on the first address\'s count'
        );
    }

    public function testTenAttemptsAnHourForOneUsernameIsTheLimit(): void
    {
        // One username from ten addresses, so that only the per-username
        // bucket can be the one that answers - the per-address bucket allows
        // five a minute and would otherwise stop this at six.
        for ($i = 1; $i <= 10; $i++) {
            $this->clientIp = '198.51.100.' . $i;
            $result = $this->issue(array('password' => 'not-the-password'));

            self::assertSame(
                ErrorCode::UNAUTHENTICATED, $this->codeOf($result),
                'attempt ' . $i . ' should have been let through to fail on its credentials'
            );
        }

        $this->clientIp = '198.51.100.11';
        $eleventh = $this->issue(array('password' => 'not-the-password'));

        self::assertSame(ErrorCode::RATE_LIMITED, $this->codeOf($eleventh), json_encode($eleventh));
        self::assertLessThanOrEqual(3600, $eleventh['errors'][0]['extensions']['retryAfterSeconds']);

        // Case is not a way round it: the bucket is keyed by the lower-cased
        // username, or SGWTCUSTOMER would be a fresh ten attempts.
        $this->clientIp = '198.51.100.12';

        self::assertSame(
            ErrorCode::RATE_LIMITED,
            $this->codeOf($this->issue(array(
                'username' => strtoupper(Fixture::PREFIX . 'customer'),
                'password' => 'not-the-password'
            )))
        );

        // An hour later, and for a different username in the meantime.
        $this->clientIp = '198.51.100.13';
        self::assertSame(
            ErrorCode::UNAUTHENTICATED,
            $this->codeOf($this->issue(array(
                'username' => Fixture::PREFIX . 'reseller', 'password' => 'not-the-password'
            )))
        );
    }

    public function testAFailedAttemptCostsTheSameAsASuccessfulOne(): void
    {
        // The buckets are charged before authenticate(), so a wrong password
        // cannot be used to probe cheaply. Four successful mints and one
        // failure exhaust the same five-a-minute address bucket that five
        // failures would, and the sixth call is refused whichever way round
        // the five were spent - which is only true if the charge does not
        // depend on the outcome.
        for ($i = 0; $i < 4; $i++) {
            self::assertArrayNotHasKey('errors', $this->issue());
        }

        self::assertSame(
            ErrorCode::UNAUTHENTICATED,
            $this->codeOf($this->issue(array('password' => 'not-the-password')))
        );

        $sixth = $this->issue();

        self::assertSame(ErrorCode::RATE_LIMITED, $this->codeOf($sixth), json_encode($sixth));

        // Said the other way round, without counting on the outcome at all:
        // the address bucket holds exactly one entry and it counts six hits,
        // one per call, however each of them ended.
        // Said the other way round, without counting on the outcome at all:
        // the address bucket counts six hits, one for each of the six calls,
        // four of which succeeded and two of which did not. The username
        // bucket counts five, because the sixth call never got that far - the
        // address bucket is charged first and refuses before the second
        // charge, which is the order that keeps a flood from one address from
        // spending other people's usernames.
        self::assertSame(
            array(
                'tokenIssueIp:' . $this->clientIp . ':' . self::CLOCK   => 6,
                'tokenIssueUser:' . Fixture::PREFIX . 'customer:' . self::CLOCK => 5
            ),
            $this->store->values,
            'the counters saw something other than one hit per call'
        );
    }

    /**
     * D35: `trusted_clients` selects a bigger bucket for `queries` and
     * `mutations` and never for `tokenIssue` - the one unauthenticated field,
     * which on a shared box an IP-based exemption would turn into unlimited
     * credential guessing at the plugin layer. TokenMutations never reads
     * `trusted_clients` or the two `_trusted` keys at all (perAddressLimit()
     * and perUsernameLimit() read only rate_limit_token_issue), so this is
     * the address bucket's ordinary five-a-minute limit, unmoved by
     * `trusted_clients` naming this exact address alongside a trusted limit
     * large enough that a relaxed bucket would let all six calls through.
     */
    public function testTrustedClientsDoesNotRelaxTheTokenIssueLimit(): void
    {
        $config = array(
            'trusted_clients'              => array($this->clientIp),
            'rate_limit_queries_trusted'   => 1000,
            'rate_limit_mutations_trusted' => 1000
        );

        for ($i = 0; $i < 5; $i++) {
            self::assertArrayNotHasKey(
                'errors', $this->issue(array(), $config), 'attempt ' . ($i + 1)
            );
        }

        self::assertSame(
            ErrorCode::RATE_LIMITED,
            $this->codeOf($this->issue(array(), $config)),
            'a trusted address minted a sixth token within the minute'
        );
    }

    // ---- tokenRevoke -----------------------------------------------------

    public function testRevokingSomeoneElsesTokenIsNotFound(): void
    {
        $issued = $this->issue()['data']['tokenIssue'];
        $encodedId = $issued['apiToken']['id'];

        foreach (array('sibling', 'otherCustomer', 'reseller', 'admin') as $who) {
            $result = $this->execute(
                'mutation($id: ID!) { tokenRevoke(id: $id) { id } }',
                array('id' => $encodedId),
                $this->identityFor($who)
            );

            self::assertSame(
                ErrorCode::NOT_FOUND, $this->codeOf($result),
                $who . ' reached a token that is not theirs: ' . json_encode($result)
            );
        }

        // Not merely refused: still usable by the account it belongs to.
        self::assertNull(
            $this->db->value(
                'SELECT revoked_at FROM api_token WHERE token_prefix = ?',
                array($issued['apiToken']['prefix'])
            )
        );

        // And an identifier that names nothing at all is the same answer.
        $missing = $this->execute(
            'mutation($id: ID!) { tokenRevoke(id: $id) { id } }',
            array('id' => GlobalId::encode('ApiToken', 999999999)),
            $this->identityFor('customer')
        );

        self::assertSame(ErrorCode::NOT_FOUND, $this->codeOf($missing), json_encode($missing));
    }

    public function testRevokingIsIdempotent(): void
    {
        $encodedId = $this->issue()['data']['tokenIssue']['apiToken']['id'];

        $first = $this->revoke($encodedId);
        $second = $this->revoke($encodedId);

        self::assertArrayNotHasKey('errors', $first, json_encode($first));
        self::assertArrayNotHasKey('errors', $second, json_encode($second));
        self::assertNotNull($first['data']['tokenRevoke']['revokedAt']);
        self::assertSame(
            $first['data']['tokenRevoke'], $second['data']['tokenRevoke'],
            'the second revoke moved the revokedAt the first one wrote'
        );
    }

    public function testARevokedTokenNoLongerAuthenticates(): void
    {
        $token = $this->issue()['data']['tokenIssue'];

        self::assertSame(200, $this->send('{ viewer { username } }', array(
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token['token']
        ))->getStatusCode());

        $this->revoke($token['apiToken']['id']);

        self::assertSame(401, $this->send('{ viewer { username } }', array(
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token['token']
        ))->getStatusCode());
    }

    public function testANarrowCredentialMayStillRevoke(): void
    {
        // Deliberately no scope check: a credential that could not burn
        // itself, or the sibling token that has just leaked, would leave its
        // holder with nothing but the panel UI - which is the situation a
        // narrow token is issued to avoid. Revocation only ever removes
        // authority, so there is nothing for a scope to protect.
        $encodedId = $this->issue()['data']['tokenIssue']['apiToken']['id'];

        $result = $this->execute(
            'mutation($id: ID!) { tokenRevoke(id: $id) { revokedAt } }',
            array('id' => $encodedId),
            $this->identityFor('customer', array(Scope::MAIL_READ))
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertNotNull($result['data']['tokenRevoke']['revokedAt']);
    }

    // ---- what the middleware change must not have loosened ---------------

    public function testAnUnauthenticatedViewerQueryIsStillRefused(): void
    {
        // AuthenticateMiddleware lets a request with no credential through so
        // that tokenIssue can be reached. Everything else stays shut, one
        // field at a time, because TypeResolver::identity() refuses a null
        // identity - and the 401 the middleware used to give is still the
        // status a caller sees.
        $response = $this->send('{ viewer { username } }');
        $body = $this->body($response);

        self::assertSame(401, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(ErrorCode::UNAUTHENTICATED, $body['errors'][0]['extensions']['code']);
        self::assertNull($body['data']['viewer'] ?? null);
        self::assertSame('Bearer realm="i-MSCP"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAnUnauthenticatedMutationThatIsNotTokenIssueIsStillRefused(): void
    {
        $response = $this->send(
            'mutation { domainUpdate(id: "'
                . GlobalId::encode('Domain', $this->fixture->domainId())
                . '", input: { documentRoot: "/htdocs" }) { id } }'
        );

        self::assertSame(401, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            ErrorCode::UNAUTHENTICATED,
            $this->body($response)['errors'][0]['extensions']['code']
        );
    }

    /**
     * Checkpoint D, finding D3. `{ __schema { types { name } } }` touches no
     * resolver, so no error carried UNAUTHENTICATED and needsACredential()
     * had nothing to convert: the request was answered 200 with the whole
     * schema. Before this wave it was a flat 401.
     */
    public function testIntrospectionWithoutACredentialIsRefused(): void
    {
        $response = $this->send('{ __schema { types { name } } }');
        $body = $this->body($response);

        self::assertSame(401, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            ErrorCode::UNAUTHENTICATED, $body['errors'][0]['extensions']['code']
        );
        self::assertSame('Bearer realm="i-MSCP"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertArrayNotHasKey('data', $body, 'the schema was answered anyway');
    }

    public function testIntrospectionWithACredentialIsStillServed(): void
    {
        // So that turning introspection off remains a separate switch: a
        // credential is what the 401 above is about, not the meta-fields.
        $token = $this->issue()['data']['tokenIssue']['token'];

        $response = $this->send('{ __schema { types { name } } }', array(
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token
        ));
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotEmpty($body['data']['__schema']['types'] ?? array());
    }

    public function testADocumentThatAsksForMoreThanTokenIssueIsRefused(): void
    {
        // The exemption is for one field, so a document that hides another
        // beside it is not exempt - and it is refused before tokenIssue runs,
        // so nothing is minted for a caller that asked for more.
        $response = $this->send(
            'mutation($input: TokenIssueInput!) {'
                . ' tokenIssue(input: $input) { token } apiVersion: __typename }',
            array(),
            $this->issueVariables()
        );

        self::assertSame(401, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            0,
            (int)$this->db->value(
                'SELECT COUNT(*) FROM api_token WHERE admin_id = ?',
                array($this->fixture->customerId())
            )
        );
    }

    /**
     * Checkpoint D, finding D8. `login_credentials()` registers a closure on
     * onAfterAuthentication capturing the plaintext password and never
     * unregisters it, so a second `tokenIssue` in the same request rewrote
     * the *second* account's `admin_pass` with a hash of the *first*
     * account's password. One credential exchange per request.
     */
    public function testADocumentWithTwoTokenIssueFieldsIsRefused(): void
    {
        $response = $this->send(
            'mutation($a: TokenIssueInput!, $b: TokenIssueInput!) {'
                . ' first: tokenIssue(input: $a) { token }'
                . ' second: tokenIssue(input: $b) { token } }',
            array(),
            array(
                'a' => $this->issueVariables()['input'],
                'b' => $this->issueVariables(array(
                    'username' => Fixture::PREFIX . 'reseller'
                ))['input']
            )
        );

        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            ErrorCode::BAD_USER_INPUT,
            $this->body($response)['errors'][0]['extensions']['code'] ?? null
        );

        // Refused before anything authenticates, so neither account's
        // password was read at all - which is what stops the rehash listener
        // being reached a second time.
        self::assertSame(array(), $this->core->callsNamed('authenticate'));
    }

    /**
     * Finding D6. The bucket keyed on `mb_strtolower(trim($username))` while
     * `login_credentials()` looks the account up as
     * `encode_idna(clean_input($username))`, so an IDN account spelled in
     * Unicode and in punycode landed in two buckets and got double the hourly
     * allowance.
     */
    public function testTheTwoSpellingsOfAnIdnUsernameShareOneBucket(): void
    {
        // The per-address bucket out of the way, so that the only limit in
        // play is the per-username one - ten an hour (PER_USERNAME_HOURLY).
        $config = array('rate_limit_token_issue' => 100);

        for ($i = 1; $i <= 10; $i++) {
            self::assertSame(
                ErrorCode::UNAUTHENTICATED,
                $this->codeOf($this->issue(array('username' => 'bücher'), $config)),
                'attempt ' . $i
            );
        }

        self::assertSame(
            ErrorCode::RATE_LIMITED,
            $this->codeOf($this->issue(array('username' => 'xn--bcher-kva'), $config)),
            'the punycode spelling had a budget of its own'
        );
    }

    public function testTokenIssueIsServedThroughTheWholePipelineWithNoCredential(): void
    {
        // The wiring, end to end: the same document a client would send, over
        // the composed stack - TLS, CORS, authentication, the rate limit and
        // the handler - with nothing in the Authorization header.
        $response = $this->send(
            'mutation($input: TokenIssueInput!) { tokenIssue(input: $input) { token apiToken { name } } }',
            array(),
            $this->issueVariables()
        );
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertArrayNotHasKey('errors', $body, (string)$response->getBody());
        self::assertMatchesRegularExpression(
            '/^imscp_[a-z2-7]{8}_[A-Za-z0-9_-]{43}$/', $body['data']['tokenIssue']['token']
        );
    }

    // ---- the harness -----------------------------------------------------

    /**
     * Run `tokenIssue` and hand back the whole envelope.
     *
     * @param array<string, mixed> $input  Overrides for the input object.
     * @param array<string, mixed> $config Overrides for config.php.
     * @return array<string, mixed>
     */
    private function issue(array $input = array(), array $config = array()): array
    {
        return $this->execute(
            'mutation($input: TokenIssueInput!) {
                tokenIssue(input: $input) {
                    token
                    expiresAt
                    apiToken { id prefix name scopes ipAllowlist createdAt expiresAt lastUsedAt lastUsedIp revokedAt }
                }
            }',
            $this->issueVariables($input),
            null,
            $config
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function issueVariables(array $input = array()): array
    {
        return array('input' => array_merge(array(
            'username' => Fixture::PREFIX . 'customer',
            'password' => self::PASSWORD,
            'name'     => 'ci',
            'scopes'   => array(Scope::DOMAINS_WRITE)
        ), $input));
    }

    /**
     * @return array<string, mixed>
     */
    private function revoke(string $encodedId): array
    {
        return $this->execute(
            'mutation($id: ID!) { tokenRevoke(id: $id) { id prefix name revokedAt } }',
            array('id' => $encodedId),
            $this->identityFor('customer')
        );
    }

    /**
     * One document against the real schema, as the endpoint formats it.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function execute(
        string $document, array $variables, ?Identity $identity = null, array $config = array()
    ): array {
        $result = GraphQL::executeQuery(
            $this->container($config)->schemaFactory()->create(),
            $document,
            null,
            array('identity' => $identity),
            $variables
        );

        $result->setErrorFormatter(static function (Error $error) {
            $previous = $error->getPrevious();
            $formatted = $previous instanceof Throwable
                ? ErrorFactory::format($previous, true)
                : array('message' => $error->getMessage(), 'extensions' => array('code' => 'GRAPHQL'));

            if ($error->path !== null) {
                $formatted['path'] = $error->path;
            }

            return $formatted;
        });

        return $result->toArray();
    }

    /**
     * One request through the whole composed pipeline, exactly as
     * PluginRoutesInjector dispatches it.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $variables
     */
    private function send(string $document, array $headers = array(), array $variables = array()): Response
    {
        $env = Environment::mock(array_merge(array(
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE'   => 'application/json',
            'REMOTE_ADDR'    => $this->clientIp
        ), $headers));
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(array('query' => $document, 'variables' => $variables)));
        rewind($stream);

        return ($this->container()->routeHandler())(
            Request::createFromEnvironment($env)->withBody(new Stream($stream)),
            new Response(),
            array()
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config = array()): Container
    {
        // REMOTE_ADDR, because Container reads it once when it is built - the
        // request has not been made yet at that point, which is exactly the
        // production shape.
        $_SERVER['REMOTE_ADDR'] = $this->clientIp;

        return Container::forTesting(
            dirname(__DIR__, 2),
            array_merge(array(
                'debug' => true, 'introspection' => true,
                'max_query_depth' => 15, 'max_query_complexity' => 1000,
                'require_tls' => false, 'allowed_origins' => array(),
                'allow_session_auth' => false, 'allow_password_grant' => true,
                'token_default_ttl_days' => 365, 'token_max_ttl_days' => 730,
                'token_max_per_account' => 10, 'rate_limit_token_issue' => 5
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
                return $this->apiAccessAllowed;
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
     * One limiter for the whole test, counting in memory.
     *
     * In memory rather than in `api_rate` so that one test's counters cannot
     * reach the next: the fixture's transaction would roll the rows back, but
     * the buckets here are keyed by username and address, which two tests
     * share.
     */
    private function rateLimiter(): RateLimiter
    {
        if ($this->limiter === null) {
            // Never the real ApcuStore, even on a box that has one: it
            // outlives the fixture's transaction and the test, so one test's
            // attempts would be spent out of the next test's five-a-minute
            // budget. RateLimitTest is where the two real counters are
            // exercised; what is under test here is what the resolver charges,
            // not which backend answers.
            $this->store = new InMemoryApcuStore();
            $this->limiter = new RateLimiter(
                function () { return $this->now; }, $this->store, $this->db
            );
        }

        return $this->limiter;
    }

    private function identityFor(string $who, array $scopes = array()): Identity
    {
        return $this->fixture->identity($who, $scopes);
    }

    private function setPassword(int $adminId, string $password): void
    {
        $this->db->execute(
            'UPDATE admin SET admin_pass = ? WHERE admin_id = ?',
            array(Crypt::apr1MD5($password), $adminId)
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function codeOf(array $result): string
    {
        return (string)($result['errors'][0]['extensions']['code'] ?? 'NO_ERROR');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        return (array)json_decode((string)$response->getBody(), true);
    }

    /**
     * Every field in the schema whose type is Secret, in an output position.
     *
     * @return string[]
     */
    private function secretReturningFields(): array
    {
        $schema = $this->container()->schemaFactory()->create();
        $found = array();

        foreach ($schema->getTypeMap() as $typeName => $type) {
            if (!($type instanceof ObjectType) || strpos($typeName, '__') === 0) {
                continue;
            }

            foreach ($type->getFields() as $fieldName => $field) {
                if (Type::getNamedType($field->getType())->name === 'Secret') {
                    $found[] = $typeName . '.' . $fieldName;
                }
            }
        }

        sort($found);

        return $found;
    }
}
