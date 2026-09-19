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
 *
 * The two credentials are alternatives, not a chain: a request that presents a
 * bearer is answered by that bearer, whether it verifies or not. See
 * __invoke().
 *
 * **A request that presents no credential at all is not refused here.** Spec
 * section 5.3's `tokenIssue` is the one field in the schema that may be
 * called without one, and a middleware cannot tell whether that is the field
 * a document asks for without parsing the document - which is the handler's
 * job, and which would mean two parsers disagreeing about what an operation
 * is. So the request goes through with the identity attribute set to null,
 * and nothing downstream is loosened to let it: GraphQLHandler names the one
 * exempt field on the parsed document and answers UNAUTHENTICATED for a
 * credential-less request that asks for anything else - the introspection
 * meta-fields included, which touch no resolver and so would otherwise have
 * been answered in full (checkpoint D, D3). Beneath that,
 * TypeResolver::identity() still throws UNAUTHENTICATED for a null identity,
 * one field at a time. The attribute is always *set*, null or not, so that
 * GraphQLHandler can still tell "no credential" from "this request never
 * passed through this middleware", which is a wiring bug and still a 500.
 *
 * A credential that was *presented* and refused is still refused here, with
 * the same 401 it always had. "Presented" means an Authorization header, or a
 * panel session cookie whose user_id is set - not whether that credential
 * turned out to be any good, and not whether allow_session_auth happens to be
 * on. Anything else would let a caller convert a refused credential into an
 * unauthenticated request simply by presenting a broken one.
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

        // Which credential was *presented* decides which one is asked, and
        // the two cases bearerToken() folds into one null - no Authorization
        // header at all, and one that failed to verify - are told apart here
        // before either is consulted.
        //
        // They used to share an outcome: a bearer that did not verify fell
        // through to the session, silently. That is not a fallback, it is an
        // escalation. A session records no scopes and Identity::hasScope()
        // reads an empty list as a full credential, so the holder of a token
        // that had just been revoked, had expired, or had been refused by its
        // own address allow-list carried on working - with strictly more
        // authority than the token ever granted, and with no 401 to tell them
        // anything had changed.
        //
        // A credential that was presented and refused is refused. Only a
        // request carrying no bearer at all may look at the session, which is
        // what allow_session_auth is for and what the panel's own pages use.
        $presented = TlsMiddleware::bearerToken($request);
        $identity = $presented !== null
            ? $this->fromBearerToken($request, $presented)
            : $this->fromSession($request);

        if ($identity === null) {
            // Nothing was presented, so nothing was refused: through, with a
            // null identity, for `tokenIssue` alone to make use of. See the
            // class docblock.
            if ($presented === null && empty($_SESSION['user_id'])) {
                return $next($request->withAttribute('identity', null), $response);
            }

            return $this->unauthenticated($response);
        }

        // Called unconditionally: a security control whose failure mode is
        // "allow everything" is worse than no control. In production this is
        // wired to SGW_GraphQL::customerHasApiAccess(); in tests it is a
        // controllable stub, so this branch is exercised, not merely assumed.
        if (!call_user_func($this->apiAccessChecker, $identity->getAdminId())) {
            $response = $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write(json_encode(array('errors' => array(array(
                'message'    => 'API access has been withdrawn from this account.',
                'extensions' => array('code' => 'API_ACCESS_WITHDRAWN')
            )))));

            return $response;
        }

        IdentityShim::apply($identity);

        return $next($request->withAttribute('identity', $identity), $response);
    }

    /**
     * @param string $presented The bearer the caller sent, already parsed out
     *                          of the header by the one place that parses it.
     *                          Passed in rather than re-read so that null here
     *                          can only ever mean "presented and refused".
     */
    private function fromBearerToken(
        ServerRequestInterface $request, string $presented
    ): ?Identity {
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
            ->withHeader('WWW-Authenticate', 'Bearer realm="i-MSCP"')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write(json_encode(array('errors' => array(array(
            'message'    => 'Authentication is required.',
            'extensions' => array('code' => 'UNAUTHENTICATED')
        )))));

        return $response;
    }
}
