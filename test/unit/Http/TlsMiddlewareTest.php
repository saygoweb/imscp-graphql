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
