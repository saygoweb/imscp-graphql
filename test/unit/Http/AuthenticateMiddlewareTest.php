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

    public function testNoCredentialIsA401(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false,
            $this->accessCheckerMustNotBeCalled()
        );

        $response = $mw($this->request(), new Response(), function ($rq, $rs) {
            self::fail('the handler must not run');
        });

        self::assertSame(401, $response->getStatusCode());
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

        $noCredential = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false, $checker
        );
        $bodyNoCredential = (string)$noCredential(
            $this->request(), new Response(), function ($rq, $rs) { return $rs; }
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
