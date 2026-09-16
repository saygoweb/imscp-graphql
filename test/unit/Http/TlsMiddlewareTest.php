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

use iMSCP\Plugin\SGW_GraphQL\Auth\Token;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class TlsMiddlewareTest extends TestCase
{
    private function request(array $headers = []): Request
    {
        $env = Environment::mock(array_merge(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
             'REMOTE_ADDR' => '127.0.0.1'],
            $headers
        ));

        return Request::createFromEnvironment($env);
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

    private function bearer(): string
    {
        return 'Bearer imscp_abcdefgh_' . str_repeat('a', 43);
    }

    public function testAHttpsRequestPassesThroughUntouched(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('verify');
        $tokens->expects(self::never())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens);

        $reached = false;
        $response = $mw(
            $this->request(['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => $this->bearer()]),
            new Response(),
            function ($rq, $rs) use (&$reached) { $reached = true; return $rs; }
        );

        self::assertTrue($reached, 'a secure request must reach the handler');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPlainHttpWithNoTokenIsA403AndNothingIsRevoked(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('verify');
        $tokens->expects(self::never())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens);

        $response = $mw(
            $this->request(),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run over plain HTTP'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPlainHttpWithAVerifyingTokenRevokesIt(): void
    {
        $token = $this->token();

        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn($token);
        $tokens->expects(self::once())
            ->method('revoke')
            ->with($token->getAdminId(), $token->getTokenId());

        $mw = new TlsMiddleware(true, $tokens);

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => $this->bearer()]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run over plain HTTP'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The defect that made the ordinary deployment the broken one.
     *
     * nginx or Apache terminates TLS and proxies to PHP-FPM, so the backend
     * sees scheme http, no HTTPS and port 80. The insecure branch does not
     * merely answer 403 — it revokes the token — so a correctly configured
     * client destroyed its own credential on its first valid request. The
     * assertion that matters here is revoke(), not the status code.
     */
    public function testAProxiedHttpsRequestFromATrustedProxyReachesTheHandler(): void
    {
        // verify() is left free to answer a real token, so that the refusal
        // this test is really about is revoke(): a middleware that merely
        // stopped short of the 403 while still destroying the credential
        // would pass a status-code assertion and fail this one.
        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn($this->token());
        $tokens->expects(self::never())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens, ['10.0.0.0/8']);

        $reached = false;
        $response = $mw(
            $this->request([
                'REMOTE_ADDR'            => '10.1.2.3',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_AUTHORIZATION'     => $this->bearer(),
            ]),
            new Response(),
            function ($rq, $rs) use (&$reached) { $reached = true; return $rs; }
        );

        self::assertTrue($reached, 'a proxied HTTPS request must reach the handler');
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * And the reason the obvious fix is not the fix: X-Forwarded-Proto is a
     * request header, so any client can send one. Honoured unconditionally it
     * would not repair the detection, it would delete the TLS requirement.
     */
    public function testAForwardedProtoFromAnUntrustedAddressIsNotBelieved(): void
    {
        $token = $this->token();

        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn($token);
        $tokens->expects(self::once())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens, ['10.0.0.0/8']);

        $response = $mw(
            $this->request([
                'REMOTE_ADDR'            => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_AUTHORIZATION'     => $this->bearer(),
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('a client-asserted scheme must not pass'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The default, and the whole point of it: an operator who configures no
     * proxy is exactly as safe as before the key existed.
     */
    public function testWithNoTrustedProxyConfiguredNoForwardingHeaderIsBelieved(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('verify');

        $mw = new TlsMiddleware(true, $tokens);

        $response = $mw(
            $this->request([
                'REMOTE_ADDR'            => '127.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('nothing is trusted by default'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * A blank entry must not read as "everybody". Imploded into the allow-list
     * the matcher shares with token addresses, an empty string is the empty
     * list — which that matcher reads as "no restriction", and which here
     * would make every client on the internet a trusted proxy.
     */
    public function testABlankTrustedProxyEntryTrustsNobody(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $mw = new TlsMiddleware(true, $tokens, ['', '   ']);

        $response = $mw(
            $this->request([
                'REMOTE_ADDR'            => '203.0.113.9',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('a blank entry trusts nobody'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testATrustedProxyReportingPlainHttpIsStillInsecure(): void
    {
        $token = $this->token();

        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn($token);
        $tokens->expects(self::once())
            ->method('revoke')
            ->with($token->getAdminId(), $token->getTokenId());

        $mw = new TlsMiddleware(true, $tokens, ['10.0.0.0/8']);

        $response = $mw(
            $this->request([
                'REMOTE_ADDR'            => '10.1.2.3',
                'HTTP_X_FORWARDED_PROTO' => 'http',
                'HTTP_AUTHORIZATION'     => $this->bearer(),
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('the proxy said the client leg was plaintext'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Each proxy in a chain appends, so the client's own leg is the first
     * entry. Reading the last one would call a plaintext client secure the
     * moment a second proxy joined the path.
     */
    public function testTheFirstHopOfAForwardedChainIsTheOneThatCounts(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn(null);

        $mw = new TlsMiddleware(true, $tokens, ['10.0.0.0/8']);

        $response = $mw(
            $this->request([
                'REMOTE_ADDR'            => '10.1.2.3',
                'HTTP_X_FORWARDED_PROTO' => 'http, https',
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('the client hop was plaintext'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testXForwardedSslFromATrustedProxyIsHonouredToo(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens, ['10.1.2.3']);

        $reached = false;
        $mw(
            $this->request([
                'REMOTE_ADDR'          => '10.1.2.3',
                'HTTP_X_FORWARDED_SSL' => 'on',
                'HTTP_AUTHORIZATION'   => $this->bearer(),
            ]),
            new Response(),
            function ($rq, $rs) use (&$reached) { $reached = true; return $rs; }
        );

        self::assertTrue($reached, 'mod_ssl\'s vocabulary is a proxy statement too');
    }

    /**
     * The converse defect: a port number says what was listened on, never what
     * was spoken on it. Plaintext bound to 443 was called secure.
     */
    public function testPlainHttpArrivingOnPort443IsNotSecure(): void
    {
        $token = $this->token();

        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn($token);
        $tokens->expects(self::once())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens);

        $response = $mw(
            $this->request([
                'SERVER_PORT'        => 443,
                'HTTP_AUTHORIZATION' => $this->bearer(),
            ]),
            new Response(),
            function ($rq, $rs) { self::fail('port 443 is not proof of TLS'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The case that matters: an unverifiable string must never trigger
     * revoke(), or anyone who merely guessed at a token could force a real
     * one to be revoked — turning a confidentiality control into a
     * denial-of-service primitive.
     */
    public function testPlainHttpWithANonVerifyingTokenDoesNotRevokeAnything(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->method('verify')->willReturn(null);
        $tokens->expects(self::never())->method('revoke');

        $mw = new TlsMiddleware(true, $tokens);

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => $this->bearer()]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run over plain HTTP'); }
        );

        self::assertSame(403, $response->getStatusCode());
    }
}
