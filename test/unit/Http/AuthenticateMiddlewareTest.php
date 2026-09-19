<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Http;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Token;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class AuthenticateMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        IdentityShim::reset();
    }

    private function request(array $headers = []): Request
    {
        $env = Environment::mock(array_merge(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
             'REMOTE_ADDR' => '127.0.0.1'],
            $headers
        ));

        return Request::createFromEnvironment($env);
    }

    private function tokenServiceReturning(?Token $token): TokenService
    {
        $service = $this->createMock(TokenService::class);
        $service->method('verify')->willReturn($token);

        return $service;
    }

    private function customerRow(): array
    {
        return [
            'admin_id' => 7, 'admin_name' => 'wpcache.test', 'admin_type' => 'user',
            'created_by' => 3, 'email' => 'c@example.com', 'admin_status' => 'ok',
        ];
    }

    private function token(): Token
    {
        return new Token([
            'token_id' => 42, 'admin_id' => 7, 'name' => 'ci',
            'token_prefix' => 'abcdefgh', 'token_hash' => str_repeat('0', 64),
            'scopes' => 'DOMAINS_READ', 'ip_allowlist' => null,
            'created_at' => time(), 'expires_at' => null, 'last_used_at' => null,
            'last_used_ip' => null, 'revoked_at' => null,
        ]);
    }

    /** An access checker that always grants, for tests exercising other paths. */
    private function alwaysAllowed(): callable
    {
        return function () { return true; };
    }

    /**
     * An access checker that fails the test if it runs at all. Authentication
     * must fail before this check is ever reached, so a call to it means the
     * ordering has regressed.
     */
    private function accessCheckerMustNotBeCalled(): callable
    {
        return function () {
            self::fail('the API access check must not run before authentication succeeds');
        };
    }

    public function testAValidBearerTokenYieldsAnIdentity(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return $this->customerRow(); },
            false,
            $this->alwaysAllowed()
        );

        $seen = null;
        $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($req, $res) use (&$seen) { $seen = $req->getAttribute('identity'); return $res; }
        );

        self::assertInstanceOf(Identity::class, $seen);
        self::assertSame(7, $seen->getAdminId());
        self::assertSame(Identity::ROLE_CUSTOMER, $seen->getRole());
        self::assertSame(['DOMAINS_READ'], $seen->getScopes());
        self::assertSame(7, $_SESSION['user_id'], 'the shim must be applied');
    }

    public function testNoCredentialReachesTheHandlerWithANullIdentity(): void
    {
        // Spec section 5.3: tokenIssue is served without a credential, and
        // this middleware cannot tell which field a document asks for. So a
        // request that presents nothing goes through - with the attribute
        // set, and set to null, which is what keeps every other resolver
        // refused (TypeResolver::identity()) and what tells GraphQLHandler
        // this request did pass through here.
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false,
            $this->accessCheckerMustNotBeCalled()
        );

        $reached = false;
        $attributes = array();
        $response = $mw($this->request(), new Response(), function ($rq, $rs) use (&$reached, &$attributes) {
            $reached = true;
            $attributes = $rq->getAttributes();

            return $rs;
        });

        self::assertTrue($reached, 'an unauthenticated request must reach the handler');
        self::assertArrayHasKey('identity', $attributes);
        self::assertNull($attributes['identity']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $_SESSION, 'no shim is applied for a caller with no identity');
    }

    public function testAPresentedSessionThatIsRefusedIsStillA401(): void
    {
        // The line the change above must not cross: "presented" is decided by
        // what the request carries, not by whether it turned out to be any
        // good. A caller must not be able to convert a refused credential
        // into an unauthenticated request by presenting a broken one - here,
        // a session cookie with no CSRF header to go with it.
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request(['CONTENT_TYPE' => 'application/json']),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testABadTokenIsA401(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            false,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A bearer that was presented and refused must not become a session.
     *
     * fromBearerToken() answers null both for "no Authorization header" and
     * for "one that failed to verify", and the ?? chain that used to join it
     * to the session could not tell the two apart. So a token that had been
     * revoked, had expired, or had been refused by its own address allow-list
     * quietly authenticated from the panel session instead — which records no
     * scopes, and an empty scope list is a *full* credential. The client whose
     * narrowly scoped token had just been revoked carried on working, with
     * more authority than the token ever granted, and never saw a 401.
     *
     * Every session precondition is satisfied here, so the only thing that can
     * refuse this request is the rule under test.
     */
    public function testARefusedBearerTokenDoesNotFallBackToTheSession(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request([
                'HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43),
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_X_IMSCP_CSRF'  => 'sekrit',
            ]),
            new Response(),
            function ($rq, $rs) {
                self::fail('a refused bearer must not authenticate from the session');
            }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The same rule for a bearer that never had a chance of verifying: the
     * header was presented, so the session is not consulted.
     */
    public function testAMalformedBearerTokenDoesNotFallBackToTheSessionEither(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request([
                'HTTP_AUTHORIZATION' => 'Bearer not-even-the-right-shape',
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_X_IMSCP_CSRF'  => 'sekrit',
            ]),
            new Response(),
            function ($rq, $rs) {
                self::fail('a refused bearer must not authenticate from the session');
            }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * And the boundary on the other side, which the fix must not cross: a
     * request carrying no bearer at all is still free to use the session.
     * That is what allow_session_auth exists for and what the panel's own
     * pages do, so breaking it would break the panel.
     */
    public function testARequestWithNoBearerAtAllStillUsesTheSession(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->alwaysAllowed()
        );

        $seen = null;
        $mw(
            $this->request([
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_X_IMSCP_CSRF' => 'sekrit',
            ]),
            new Response(),
            function ($req, $res) use (&$seen) {
                $seen = $req->getAttribute('identity');

                return $res;
            }
        );

        self::assertInstanceOf(Identity::class, $seen);
        self::assertSame(7, $seen->getAdminId());
    }

    public function testAnAccountWhoseStatusIsNotOkIsRefused(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return ['admin_status' => 'todelete'] + $this->customerRow(); },
            false,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Every failure funnels through one unauthenticated() call site, which is
     * why the bodies are identical. This drives four distinct reasons a
     * caller might fail — no credential, a malformed one, one that simply
     * does not verify, and a verified token whose account is not "ok" — and
     * proves the bodies are byte-for-byte the same, not merely free of a
     * fixed word-list. A future edit that added a second call site with a
     * different message would fail this even if it happened to avoid every
     * word on the leak list below.
     */
    public function testTheFailureBodySaysNothingAboutWhichCheckFailed(): void
    {
        $checker = $this->accessCheckerMustNotBeCalled();

        // A session cookie with no CSRF header: presented, and refused. The
        // "no credential at all" case is no longer one of these - it is not a
        // failure any more, it is an unauthenticated request going through
        // (see testNoCredentialReachesTheHandlerWithANullIdentity) - so this
        // takes its place as the baseline every other refusal must match.
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $session = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $checker
        );
        $bodyNoCredential = (string)$session(
            $this->request(['CONTENT_TYPE' => 'application/json']),
            new Response(),
            function ($rq, $rs) { return $rs; }
        )->getBody();

        $rejected = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            false,
            $checker
        );

        $bodyMalformed = (string)$rejected(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer not-even-the-right-shape']),
            new Response(),
            function ($rq, $rs) { return $rs; }
        )->getBody();

        $bodyDoesNotVerify = (string)$rejected(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { return $rs; }
        )->getBody();

        $statusNotOk = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return ['admin_status' => 'todelete'] + $this->customerRow(); },
            false,
            $checker
        );
        $bodyStatusNotOk = (string)$statusNotOk(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { return $rs; }
        )->getBody();

        self::assertSame($bodyNoCredential, $bodyMalformed);
        self::assertSame($bodyNoCredential, $bodyDoesNotVerify);
        self::assertSame($bodyNoCredential, $bodyStatusNotOk);

        foreach (['expired', 'revoked', 'prefix', 'hash', 'allowlist', 'status'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $bodyNoCredential);
        }
    }

    public function testAnOptionsRequestPassesStraightThrough(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false,
            $this->accessCheckerMustNotBeCalled()
        );

        $env = Environment::mock([
            'REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '/api/graphql',
        ]);

        $reached = false;
        $mw(Request::createFromEnvironment($env), new Response(),
            function ($rq, $rs) use (&$reached) { $reached = true; return $rs; });

        self::assertTrue($reached, 'CORS preflight must not need a credential');
    }

    public function testAccessWithdrawnIsA403AndTheHandlerIsNotCalled(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return $this->customerRow(); },
            false,
            function () { return false; }
        );

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = json_decode((string)$response->getBody(), true);
        self::assertSame('API_ACCESS_WITHDRAWN', $body['errors'][0]['extensions']['code']);
    }

    public function testSessionAuthSucceedsWithMatchingCsrfAndJsonContentType(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->alwaysAllowed()
        );

        $seen = null;
        $mw(
            $this->request([
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_X_IMSCP_CSRF' => 'sekrit',
            ]),
            new Response(),
            function ($req, $res) use (&$seen) { $seen = $req->getAttribute('identity'); return $res; }
        );

        self::assertInstanceOf(Identity::class, $seen);
        self::assertSame(7, $seen->getAdminId());
        self::assertSame([], $seen->getScopes(), 'a session records no scopes: bounded by role alone');
    }

    public function testSessionAuthIsRefusedWithTheWrongContentType(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request([
                'CONTENT_TYPE'       => 'text/plain',
                'HTTP_X_IMSCP_CSRF' => 'sekrit',
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSessionAuthIsRefusedWithNoCsrfHeader(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request(['CONTENT_TYPE' => 'application/json']),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSessionAuthIsRefusedWithTheWrongCsrfValue(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            true,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request([
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_X_IMSCP_CSRF' => 'not-the-right-value',
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testSessionAuthIsRefusedWhenNotAllowedEvenIfEverythingElseIsCorrect(): void
    {
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            false,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw(
            $this->request([
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_X_IMSCP_CSRF' => 'sekrit',
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }
}
