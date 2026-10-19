<?php
namespace iMSCP\Plugin\SGW_GraphQL\Api;

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
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Service\AuditContext;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Registry;
use RuntimeException;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;
use Slim\Http\Stream;

/**
 * The API, called from inside the panel's own PHP process rather than over
 * HTTP: the supported entry point for another plugin - SGW_Mcp - that serves
 * the same operations through a protocol of its own. See docs/EXTENSIONS.md,
 * "In-process execution".
 *
 * **execute() is the endpoint, minus the transport.** It builds the request
 * POST /api/graphql would have received and runs it through
 * Container::inProcessHandler(): the same RateLimitMiddleware, the same
 * AuthenticateMiddleware and the same GraphQLHandler, built from the same
 * configuration, that Container::routeHandler() runs. So a token, its scopes,
 * its address allow-list, the per-account API switch, both rate-limit
 * buckets, the depth and complexity limits and the audit row apply exactly as
 * they do over HTTP, because they are the same code, not a second copy of it
 * that could drift. Left out are TlsMiddleware and CorsMiddleware, which are
 * about an HTTP connection that, for an in-process call, belongs to the
 * caller - which has already answered for it. The audit row says `via` =
 * `mcp`.
 *
 * The rest - token minting and revocation, the account summary, the panel's
 * login check - are what an OAuth server in front of the API needs and has no
 * GraphQL field for, because a field would need a credential the OAuth
 * server is in the middle of creating.
 *
 * **One account per PHP request.** IdentityShim mirrors the authenticated
 * account into the panel's session globals for the core helpers that read
 * them, and refuses a second, different account in the same request. A
 * caller serving one HTTP request per MCP call never meets that; a caller
 * that tried to run two accounts' documents in one request would get an
 * INTERNAL error for the second.
 */
final class Executor
{
    /**
     * @var callable fn(): Container. Called once per execute(): a Container
     *      holds the request's batch loader and resolvers, whose caches are
     *      a request's worth of reads, so a second document run through the
     *      same one would read the first one's answers - a caller polling an
     *      object for its provisioning to settle would never see it settle.
     */
    private $containers;

    /** @var Container|null For everything but execute(); built on first use. */
    private $container;

    private function __construct(callable $containers)
    {
        $this->containers = $containers;
    }

    /**
     * The Executor for the installed, loaded SGW_GraphQL plugin.
     *
     * Cheap: nothing is built until a method needs it, the schema included.
     *
     * @throws RuntimeException when SGW_GraphQL is not enabled on this panel.
     */
    public static function fromPanel(): self
    {
        $plugins = Registry::get('pluginManager');

        if (!$plugins->pluginIsEnabled('SGW_GraphQL')) {
            throw new RuntimeException('The SGW_GraphQL plugin is not enabled.');
        }

        /** @var SGW_GraphQL $plugin */
        $plugin = $plugins->pluginGet('SGW_GraphQL');
        SGW_GraphQL::loadVendor();

        return new self(static function () use ($plugin) {
            return Container::fromPlugin($plugin);
        });
    }

    /**
     * An Executor over one Container a test has built, reused for every call.
     *
     * @internal Tests only. A plugin calls fromPanel().
     */
    public static function forContainer(Container $container): self
    {
        return new self(static function () use ($container) {
            return $container;
        });
    }

    /**
     * An Executor that builds its Containers the way a test says, one per
     * execute() as fromPanel()'s does.
     *
     * @param callable $containers fn(): Container
     * @internal Tests only. A plugin calls fromPanel().
     */
    public static function forContainers(callable $containers): self
    {
        return new self($containers);
    }

    /**
     * Run one GraphQL document as the holder of $bearerToken.
     *
     * In order, as for POST /api/graphql: the `queries` rate-limit bucket;
     * token verification against $clientIp's address allow-list; the
     * per-account API switch; the `mutations` bucket for a mutation; depth and
     * complexity limits; execution; the audit row.
     *
     * @param string               $bearerToken The token alone, without
     *                                          "Bearer ".
     * @param array<string, mixed> $variables
     * @param string               $clientIp    The end client's address, not
     *                                          the caller's own connection:
     *                                          the token's allow-list, the
     *                                          `trusted_clients` bucket and
     *                                          the audit row all judge it.
     * @return array{data?: array|null, errors?: array} The envelope
     *         POST /api/graphql answers with, including a refusal of either
     *         rate-limit bucket, which is `errors` with RATE_LIMITED,
     *         `retryAfterSeconds` and no `data`.
     * @throws ExecutorAuthException UNAUTHENTICATED when the token is empty,
     *         malformed, unknown, revoked, expired or refused by its
     *         allow-list, or its account is not active;
     *         API_ACCESS_WITHDRAWN when the account's API access is off.
     */
    public function execute(
        string $bearerToken, string $document, array $variables, string $clientIp,
        ?string $operationName = null
    ): array {
        $bearerToken = trim($bearerToken);

        // Refused here rather than handed on: over HTTP, a request with no
        // credential is let through for `tokenIssue`'s sake (see
        // AuthenticateMiddleware), and an in-process caller is never
        // exchanging a password for its first token.
        if ($bearerToken === '') {
            throw ExecutorAuthException::unauthenticated();
        }

        // (object) so that the variables are a JSON object even when there
        // are none, or when their keys happen to be 0, 1, 2 - the shape a
        // client would send, and the one GraphQLHandler::parseBody() reads.
        $body = json_encode(
            array(
                'query'         => $document,
                'variables'     => (object)$variables,
                'operationName' => $operationName
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($body === false) {
            // Text that is not UTF-8, almost always. Over HTTP it could not
            // have been sent as JSON at all, so nothing ran and nothing is
            // charged; neither happens here either.
            return array('errors' => array(array(
                'message'    => 'The document or its variables could not be encoded as JSON.',
                'extensions' => array('code' => ErrorCode::BAD_USER_INPUT)
            )));
        }

        $response = $this->newContainer()->inProcessHandler()(
            $this->request($bearerToken, $body, $clientIp), new Response(), array()
        );
        $status = $response->getStatusCode();
        $payload = json_decode((string)$response->getBody(), true);

        // The only two answers the pipeline gives before a document is
        // parsed that are about the credential. Everything else - a 400 for
        // a document that does not parse, a 429, a field error - is an
        // envelope, and is returned as one.
        if ($status === 401) {
            throw ExecutorAuthException::unauthenticated();
        }

        if ($status === 403
            && (($payload['errors'][0]['extensions']['code'] ?? null) === ErrorCode::API_ACCESS_WITHDRAWN)
        ) {
            throw ExecutorAuthException::apiAccessWithdrawn();
        }

        if (!is_array($payload)) {
            return array('errors' => array(array(
                'message'    => 'An internal error occurred.',
                'extensions' => array('code' => ErrorCode::INTERNAL)
            )));
        }

        return $payload;
    }

    /**
     * The effective SDL: this plugin's schema, then each active extension's,
     * as GET /api/graphql/schema serves it.
     */
    public function schemaSdl(): string
    {
        return $this->container()->schemaSdl();
    }

    /**
     * The scopes a token for this account could be issued with: what its
     * role can hold, the rule issueToken() and `tokenIssue` both enforce.
     *
     * Empty for an account that does not exist or is not active. The
     * account's API switch is not consulted here - account() reports it.
     *
     * @return string[]
     */
    public function grantableScopes(int $adminId): array
    {
        $row = $this->activeAccount($adminId);

        return $row === null ? array() : Scope::grantableTo((string)$row['admin_type']);
    }

    /**
     * Mint a token for an account without its password: the caller - an
     * OAuth server - has already authenticated the user.
     *
     * Held to what `tokenIssue` is held to, past the password: the account is
     * active and has API access; every scope is one its role can hold, and
     * there is at least one (an empty list would be a full credential to
     * Identity::hasScope()); `token_max_per_account`; the name rules of
     * TokenService::issue(). The lifetime is capped at `token_max_ttl_days`
     * rather than refused, as `tokenIssue` caps it. The issue is written to
     * the panel's log as `tokenIssue`'s is. No address allow-list is set.
     *
     * @param string[] $scopes
     * @return array{token: string, tokenId: int, expiresAt: int}
     * @throws ApiException NOT_FOUND for no such active account; FORBIDDEN
     *         when its API access is off or for a scope its role cannot hold;
     *         BAD_USER_INPUT for an unknown or empty scope list, a bad name or
     *         a lifetime under one second; LIMIT_EXCEEDED at
     *         `token_max_per_account`.
     */
    public function issueToken(int $adminId, string $name, array $scopes, int $ttlSeconds): array
    {
        $row = $this->activeAccount($adminId);

        if ($row === null) {
            throw new ApiException(ErrorCode::NOT_FOUND, 'No such account.');
        }

        if (!$this->container()->hasApiAccess($adminId)) {
            throw new ApiException(
                ErrorCode::FORBIDDEN, 'API access has been withdrawn from this account.'
            );
        }

        $scopes = Scope::requireGrantable((string)$row['admin_type'], $scopes, 'scopes');
        $tokens = $this->container()->tokens();
        $tokens->requireRoomForAnother(
            $adminId, (int)$this->container()->configValue('token_max_per_account', 10)
        );

        $maxSeconds = max(1, (int)$this->container()->configValue('token_max_ttl_days', 730)) * 86400;
        $issued = $tokens->issueForSeconds(
            $adminId, $name, $scopes, min($ttlSeconds, $maxSeconds), null
        );

        $this->container()->core()->writeLog(
            sprintf(
                'An API token (%s) has been issued for %s', trim($name), $row['admin_name']
            ),
            E_USER_NOTICE
        );

        return array(
            'token'     => $issued['token'],
            'tokenId'   => (int)$issued['tokenId'],
            'expiresAt' => (int)$issued['expiresAt']
        );
    }

    /**
     * Revoke one of this account's tokens.
     *
     * @return bool true when this call revoked it; false when it is not this
     *              account's, does not exist, or was already revoked.
     */
    public function revokeToken(int $adminId, int $tokenId): bool
    {
        $tokens = $this->container()->tokens();
        $name = null;

        foreach ($tokens->listFor($adminId) as $token) {
            if ($token->getTokenId() === $tokenId) {
                $name = $token->getName();
            }
        }

        if ($name === null || !$tokens->revoke($adminId, $tokenId)) {
            return false;
        }

        $row = $this->container()->accountRow($adminId);
        $this->container()->core()->writeLog(
            sprintf(
                'An API token (%s) has been revoked for %s',
                $name, $row === null ? '#' . $adminId : $row['admin_name']
            ),
            E_USER_NOTICE
        );

        return true;
    }

    /**
     * Whether this account's token could still be presented: it is this
     * account's, it has not been revoked and it has not expired. For the
     * check a refresh makes, see tokenIsRevoked(), which ignores expiry.
     */
    public function tokenIsLive(int $adminId, int $tokenId): bool
    {
        return $this->container()->tokens()->isLive($adminId, $tokenId);
    }

    /**
     * Whether this account's token has been taken away: there is no such
     * token, it is another account's, or it has been revoked. Expiry is not
     * asked - the check an OAuth refresh makes on the access token it is
     * replacing, which has normally expired by then (TokenService::isRevoked()).
     */
    public function tokenIsRevoked(int $adminId, int $tokenId): bool
    {
        return $this->container()->tokens()->isRevoked($adminId, $tokenId);
    }

    /**
     * Who an account is, for a consent page and a refresh check.
     *
     * Null for an account that does not exist or is not active - the same
     * accounts AuthenticateMiddleware refuses a token for. `username` is
     * `admin_name` as the panel stores it, which for a customer is its
     * domain name in ASCII (punycode) form.
     *
     * @return array{adminId: int, username: string, role: string, apiAccess: bool}|null
     *         role is Identity::ROLE_ADMIN, ROLE_RESELLER or ROLE_CUSTOMER
     */
    public function account(int $adminId): ?array
    {
        $row = $this->activeAccount($adminId);

        if ($row === null) {
            return null;
        }

        $role = Identity::ROLE_BY_ADMIN_TYPE[(string)$row['admin_type']] ?? null;

        if ($role === null) {
            return null;
        }

        return array(
            'adminId'   => (int)$row['admin_id'],
            'username'  => (string)$row['admin_name'],
            'role'      => $role,
            'apiAccess' => $this->container()->hasApiAccess($adminId)
        );
    }

    /**
     * The panel's own login check, for an OAuth login form: the panel's
     * authentication events, its brute-force protection and its refusal of a
     * disabled or expired account all apply, exactly as on its login page
     * (Service\PanelCore::authenticate()).
     *
     * @return int|null the admin id, or null when the panel refuses
     */
    public function authenticate(string $username, string $password): ?int
    {
        $account = $this->container()->core()->authenticate($username, $password);

        return $account === null ? null : (int)$account['admin_id'];
    }

    private function newContainer(): Container
    {
        return call_user_func($this->containers);
    }

    /**
     * The Container for everything but execute(). None of it reads through
     * the batch loader, so one is enough for the Executor's lifetime.
     */
    private function container(): Container
    {
        if ($this->container === null) {
            $this->container = $this->newContainer();
        }

        return $this->container;
    }

    /**
     * The request POST /api/graphql would have received.
     *
     * Every value the pipeline reads off a request is set here and nowhere
     * else: REMOTE_ADDR (the allow-list, the `trusted_clients` bucket, the
     * audit row and `last_used_ip`), the Authorization header (the `queries`
     * bucket's key and the credential), the JSON content type and body, and
     * the `via` attribute (the audit row).
     */
    private function request(string $bearerToken, string $body, string $clientIp): Request
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        return Request::createFromEnvironment(Environment::mock(array(
            'REQUEST_METHOD'     => 'POST',
            'REQUEST_URI'        => '/api/graphql',
            'CONTENT_TYPE'       => 'application/json',
            'REMOTE_ADDR'        => $clientIp,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $bearerToken
        )))
            ->withBody(new Stream($stream))
            ->withAttribute(AuditContext::VIA_ATTRIBUTE, AuditContext::VIA_MCP);
    }

    /**
     * The account's `admin` row, when it exists and its status is `ok`.
     *
     * @return array<string, mixed>|null
     */
    private function activeAccount(int $adminId): ?array
    {
        $row = $this->container()->accountRow($adminId);

        if ($row === null || ($row['admin_status'] ?? '') !== 'ok') {
            return null;
        }

        return $row;
    }
}
