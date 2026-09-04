<?php
namespace iMSCP\Plugin\SGW_GraphQL\Http;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Refuses a request that did not arrive over TLS, and revokes any bearer token
 * presented on one.
 *
 * That is harsh and it is correct: by the time the request arrives, the
 * credential has already been transmitted in the clear and seen by every hop
 * in between. The alternative is a token that is compromised and still valid.
 */
final class TlsMiddleware
{
    /** @var bool */
    private $requireTls;

    /** @var TokenService */
    private $tokens;

    public function __construct(bool $requireTls, TokenService $tokens)
    {
        $this->requireTls = $requireTls;
        $this->tokens = $tokens;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, callable $next
    ): ResponseInterface {
        if (!$this->requireTls || $this->isSecure($request)) {
            return $next($request, $response);
        }

        $presented = self::bearerToken($request);

        if ($presented !== null) {
            // Only a token that verifies is revoked here: verify() returns
            // non-null only once the secret's hash has matched, so this can
            // never be driven by a guessed prefix alone.
            $token = $this->tokens->verify(
                $presented, $request->getServerParams()['REMOTE_ADDR'] ?? ''
            );

            if ($token !== null) {
                $this->tokens->revoke($token->getAdminId(), $token->getTokenId());

                if (function_exists('write_log')) {
                    write_log(sprintf(
                        'SGW_GraphQL revoked token %s (%s): presented over plain HTTP',
                        $token->getTokenId(), $token->getName()
                    ), E_USER_WARNING);
                }
            }
        }

        $response = $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(array(
            'errors' => array(array(
                'message'    => 'This API is available over HTTPS only. '
                    . 'Any token presented on this request has been revoked.',
                'extensions' => array('code' => 'FORBIDDEN')
            ))
        )));

        return $response;
    }

    public static function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        return trim(substr($header, 7));
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        $server = $request->getServerParams();

        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (int)($server['SERVER_PORT'] ?? 0) === 443;
    }
}
