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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a bearer token, or a panel session, into an Identity on the request.
 *
 * Every failure is the same 401 with the same body: a caller learning which
 * check failed learns nothing useful to them and something useful to an
 * attacker.
 */
final class AuthenticateMiddleware
{
    /** @var TokenService */
    private $tokens;

    /** @var callable fn(int $adminId): ?array */
    private $accountLoader;

    /** @var bool */
    private $allowSessionAuth;

    /** @var callable fn(int $adminId): bool */
    private $apiAccessChecker;

    /**
     * @param callable $accountLoader    fn(int $adminId): ?array, the admin row.
     * @param callable $apiAccessChecker fn(int $adminId): bool. Called
     *                                   unconditionally and denied on false: a
     *                                   security decision must never have a
     *                                   code path that fails open because a
     *                                   dependency happened not to be wired up.
     */
    public function __construct(
        TokenService $tokens,
        callable $accountLoader,
        bool $allowSessionAuth,
        callable $apiAccessChecker
    ) {
        $this->tokens = $tokens;
        $this->accountLoader = $accountLoader;
        $this->allowSessionAuth = $allowSessionAuth;
        $this->apiAccessChecker = $apiAccessChecker;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, callable $next
    ): ResponseInterface {
        // A CORS preflight carries no credential by definition.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $next($request, $response);
        }

        $identity = $this->fromBearerToken($request) ?? $this->fromSession($request);

        if ($identity === null) {
            return $this->unauthenticated($response);
        }

        // Called unconditionally: a security control whose failure mode is
        // "allow everything" is worse than no control. In production this is
        // wired to SGW_GraphQL::customerHasApiAccess(); in tests it is a
        // controllable stub, so this branch is exercised, not merely assumed.
        if (!call_user_func($this->apiAccessChecker, $identity->getAdminId())) {
            $response = $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(array('errors' => array(array(
                'message'    => 'API access has been withdrawn from this account.',
                'extensions' => array('code' => 'API_ACCESS_WITHDRAWN')
            )))));

            return $response;
        }

        IdentityShim::apply($identity);

        return $next($request->withAttribute('identity', $identity), $response);
    }

    private function fromBearerToken(ServerRequestInterface $request): ?Identity
    {
        $presented = TlsMiddleware::bearerToken($request);

        if ($presented === null) {
            return null;
        }

        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $token = $this->tokens->verify($presented, $clientIp);

        if ($token === null) {
            return null;
        }

        $account = $this->account($token->getAdminId());

        if ($account === null) {
            return null;
        }

        $this->tokens->stampLastUsed($token, $clientIp);

        return $this->identityFrom($account, $token->getScopes(), $token->getTokenId());
    }

    private function fromSession(ServerRequestInterface $request): ?Identity
    {
        if (!$this->allowSessionAuth || empty($_SESSION['user_id'])) {
            return null;
        }

        // A cookie is CSRF-exposed, so the session path additionally requires
        // a JSON content type and a header a cross-origin attacker cannot
        // read.
        if (stripos($request->getHeaderLine('Content-Type'), 'application/json') !== 0) {
            return null;
        }

        $presented = $request->getHeaderLine('X-iMSCP-CSRF');

        if ($presented === '' || empty($_SESSION['graphql_csrf'])
            || !hash_equals((string)$_SESSION['graphql_csrf'], $presented)
        ) {
            return null;
        }

        $account = $this->account((int)$_SESSION['user_id']);

        if ($account === null) {
            return null;
        }

        // A session records no scopes: it is limited by its role alone.
        return $this->identityFrom($account, array(), null);
    }

    private function account(int $adminId): ?array
    {
        $account = call_user_func($this->accountLoader, $adminId);

        if (!is_array($account) || ($account['admin_status'] ?? '') !== 'ok') {
            return null;
        }

        return $account;
    }

    private function identityFrom(array $account, array $scopes, ?int $tokenId): Identity
    {
        return new Identity(
            (int)$account['admin_id'],
            (string)$account['admin_name'],
            (string)$account['admin_type'],
            $account['created_by'] === null ? null : (int)$account['created_by'],
            $account['email'] ?? null,
            $scopes,
            $tokenId
        );
    }

    private function unauthenticated(ResponseInterface $response): ResponseInterface
    {
        $response = $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="i-MSCP"');
        $response->getBody()->write(json_encode(array('errors' => array(array(
            'message'    => 'Authentication is required.',
            'extensions' => array('code' => 'UNAUTHENTICATED')
        )))));

        return $response;
    }
}
