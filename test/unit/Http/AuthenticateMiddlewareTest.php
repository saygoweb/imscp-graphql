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

    public function testAValidBearerTokenYieldsAnIdentity(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return $this->customerRow(); },
            false
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
            $this->tokenServiceReturning(null), function () { return null; }, false
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
            false
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
            false
        );

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheFailureBodySaysNothingAboutWhichCheckFailed(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false
        );

        $body = (string)$mw($this->request(), new Response(), function ($rq, $rs) {
            return $rs;
        })->getBody();

        foreach (['expired', 'revoked', 'prefix', 'hash', 'allowlist', 'status'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body);
        }
    }

    public function testAnOptionsRequestPassesStraightThrough(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false
        );

        $env = Environment::mock([
            'REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '/api/graphql',
        ]);

        $reached = false;
        $mw(Request::createFromEnvironment($env), new Response(),
            function ($rq, $rs) use (&$reached) { $reached = true; return $rs; });

        self::assertTrue($reached, 'CORS preflight must not need a credential');
    }
}
