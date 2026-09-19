<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Auth\Token;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Service\AuditContext;
use iMSCP\Plugin\SGW_GraphQL\Service\Core;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use InvalidArgumentException;

/**
 * `tokenIssue` and `tokenRevoke` (spec section 5.3).
 *
 * `tokenIssue` is the only field in the schema that may be called without a
 * credential, so every line of issue() below is about what it must not do.
 * The order in that method is the whole security argument and the comments
 * there say so.
 *
 * `tokenRevoke` acts on the caller's own tokens and on nothing else. It is
 * not in the authorisation matrix for that reason: the matrix hands one
 * object between six accounts and asks who reaches it, and a token has no
 * reader but its owner - see test/authz/CatalogueCoverageTest.
 */
final class TokenMutations
{
    /**
     * The identifier type tag for a token.
     *
     * Not a NodeType: a token is not a Node - it is reachable from no query,
     * it has no ownership rule for OwnershipResolver to answer, and giving it
     * a tag NodeType knows would let it be handed to node(id:) and to every
     * Guard::target() call in the tree. GlobalId is only an encoding, so this
     * uses that directly and keeps the tag out of the vocabulary that grants
     * reachability.
     */
    const TAG = 'ApiToken';

    /**
     * Specification section 10.3's second `tokenIssue` limit: ten attempts an
     * hour for one username. config.php has a key for the per-address limit
     * and none for this one, so this is the default, and an operator who
     * switches the per-address bucket off with a negative limit switches this
     * one off with it - see perUsernameLimit(). A bucket that stayed on after
     * the operator said "do not limit this" would be a surprise in the one
     * place a surprise costs an installation its API.
     */
    const PER_USERNAME_HOURLY = 10;

    /** @var Core */
    private $core;

    /** @var TokenService */
    private $tokens;

    /** @var RateLimiter */
    private $limiter;

    /** @var callable fn(int $adminId): bool - spec section 6.2. */
    private $apiAccessChecker;

    /** @var array<string, mixed> The plugin's own config.php. */
    private $config;

    /** @var string The address the request came from, or '' when there is none. */
    private $clientIp;

    /**
     * @param callable $apiAccessChecker fn(int $adminId): bool. The same
     *                                   callable AuthenticateMiddleware is
     *                                   given, so that "API access has been
     *                                   withdrawn" means one thing on both
     *                                   paths.
     * @param array    $config           The plugin configuration:
     *                                   allow_password_grant,
     *                                   token_default_ttl_days,
     *                                   token_max_ttl_days,
     *                                   token_max_per_account and
     *                                   rate_limit_token_issue.
     * @param string   $clientIp         REMOTE_ADDR. Resolved by Container,
     *                                   which is built once per request, for
     *                                   the same reason RateLimitMiddleware
     *                                   reads it off the request: a limit per
     *                                   source address needs the address.
     */
    public function __construct(
        Core $core, TokenService $tokens, RateLimiter $limiter,
        callable $apiAccessChecker, array $config, string $clientIp
    ) {
        $this->core = $core;
        $this->tokens = $tokens;
        $this->limiter = $limiter;
        $this->apiAccessChecker = $apiAccessChecker;
        $this->config = $config;
        $this->clientIp = $clientIp;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.tokenIssue'  => array($this, 'resolveTokenIssue'),
            'Mutation.tokenRevoke' => array($this, 'resolveTokenRevoke')
        );
    }

    /**
     * @param mixed $source
     * @param mixed $context
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function resolveTokenIssue($source, array $args, $context, ResolveInfo $info): array
    {
        $input = (array)$args['input'];
        $username = trim((string)($input['username'] ?? ''));

        // Before anything else, including the counters: an installation that
        // has switched the password grant off is not spending a bucket on a
        // field it does not serve.
        if (!$this->allowPasswordGrant()) {
            throw Guard::forbidden(
                'Minting a token from a password is disabled on this installation.'
            );
        }

        // Charged BEFORE authenticate(), and for the username as given, so a
        // wrong password costs exactly what a right one costs. A limiter that
        // charged only failures would be a free oracle for valid usernames:
        // an attacker would read "still allowed" as "that username does not
        // exist" and "refused" as "it does", which is the one thing the
        // single error message below exists to withhold.
        //
        // Two buckets rather than one because they have two window lengths,
        // and RateLimiter refuses a bucket charged with both (see its
        // charge()). The address bucket is charged first so that a flood from
        // one address is stopped before it can spend other people's usernames.
        $this->requireRoom(
            RateLimiter::BUCKET_TOKEN_ISSUE_IP, $this->clientIp,
            $this->perAddressLimit(), RateLimiter::WINDOW_MINUTE
        );
        $this->requireRoom(
            RateLimiter::BUCKET_TOKEN_ISSUE_USER, $this->usernameBucketKey($username),
            $this->perUsernameLimit(), RateLimiter::WINDOW_HOUR
        );

        $account = $this->core->authenticate($username, (string)($input['password'] ?? ''));

        if ($account === null) {
            // One message for every credential failure: an unknown username,
            // a known username with a wrong password, and an account the
            // panel refuses for its status or its expiry date are one answer
            // with no extension to tell them apart. Spec section 5.1's rule
            // for the token path applies here too.
            throw Guard::unauthenticated('Those credentials were not accepted.');
        }

        $adminId = (int)$account['admin_id'];

        // This field is served without a credential, so no middleware and no
        // layer above this one can know whose request it turned out to be -
        // and an audit row that named nobody for the one field that mints
        // credentials would be the one row worth having and the one row
        // missing (spec section 11). Said here, before the checks below, so
        // that a refusal an authenticated account earns on its own merits is
        // still recorded against that account.
        //
        // Nothing about the credentials is disclosed by saying it: the row is
        // written to the audit table, never to the answer, and an account
        // that failed to authenticate reached a throw above and never gets
        // here.
        self::noteTheAccount($context, $adminId);

        // Only now, with the credentials already correct, may an answer
        // differ: this one tells an account that has proved who it is that
        // its own reseller has withdrawn the feature, which is something it
        // is entitled to know and something nobody else can ask.
        if (!call_user_func($this->apiAccessChecker, $adminId)) {
            throw Guard::forbidden('API access has been withdrawn from this account.');
        }

        $scopes = $this->scopesFor($account['admin_type'], (array)($input['scopes'] ?? array()));
        $this->requireRoomForAnotherToken($adminId);

        $issued = $this->tokens->issue(
            $adminId,
            (string)($input['name'] ?? ''),
            $scopes,
            $this->ttlDays($input),
            $this->allowlist($input)
        );

        $token = $this->tokenOf($adminId, (int)$issued['tokenId']);

        $this->core->writeLog(
            sprintf(
                'An API token (%s) has been issued for %s',
                $token === null ? (string)($input['name'] ?? '') : $token->getName(),
                $account['admin_name']
            ),
            E_USER_NOTICE
        );

        return array(
            // The one time the secret is ever returned. It was never stored:
            // api_token holds a SHA-256 of it and nothing else.
            'token'     => $issued['token'],
            'apiToken'  => $token === null
                ? $this->shapeOfJustIssued($issued, $adminId, $scopes, $input)
                : $this->shape($token),
            'expiresAt' => $this->dateTime($issued['expiresAt'])
        );
    }

    /**
     * @param mixed $source
     * @param mixed $context
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function resolveTokenRevoke($source, array $args, $context, ResolveInfo $info): array
    {
        $caller = TypeResolver::identity($context);
        $token = $this->tokenOf($caller->getAdminId(), $this->tokenIdFrom((string)$args['id']));

        // A token of another account, and a token that never existed, are the
        // same answer: tokenOf() only ever looks inside the caller's own
        // tokens, so there is no path here that could tell them apart even if
        // it wanted to (spec section 6.3).
        if ($token === null) {
            throw Guard::notFound();
        }

        // No scope is required, and that is deliberate rather than an
        // omission. Scopes narrow what a credential may do; a credential that
        // could not burn itself - or the sibling token that has just leaked -
        // would leave its holder with no way to stop it but the panel UI,
        // which is exactly the situation a narrow token is issued to avoid.
        // Revocation only ever removes authority, so there is nothing for a
        // scope to protect.
        $this->tokens->revoke($caller->getAdminId(), $token->getTokenId());

        $this->core->writeLog(
            sprintf(
                'An API token (%s) has been revoked for %s',
                $token->getName(), $caller->getUsername()
            ),
            E_USER_NOTICE
        );

        // Read back rather than patched in memory, so that a second revoke of
        // the same token returns the revokedAt the first one wrote instead of
        // moving it - which is what "revoking a revoked token succeeds" has
        // to mean if it is to be idempotent and not merely non-fatal.
        $current = $this->tokenOf($caller->getAdminId(), $token->getTokenId());

        return $this->shape($current === null ? $token : $current);
    }

    /**
     * Tell this request's audit row which account authenticated.
     *
     * Optional by design: the context is whatever the caller of the schema
     * built, and every test that executes a document against it without
     * GraphQLHandler passes only an identity. A missing AuditContext means
     * nobody is recording, not that something is wrong.
     *
     * @param mixed $context
     *
     * @return void
     */
    private static function noteTheAccount($context, int $adminId): void
    {
        $audit = is_array($context) && isset($context['audit']) ? $context['audit'] : null;

        if ($audit instanceof AuditContext) {
            $audit->actedAs($adminId);
        }
    }

    /**
     * The bucket one username's attempts are counted in.
     *
     * Put through `encode_idna()` as well as lower-cased, because the lookup
     * this limit protects is `login_credentials()`, which finds the account as
     * `encode_idna(clean_input($username))`. Without that an IDN account
     * spelled in Unicode and spelled in punycode reached the same row through
     * two buckets and got double the hourly allowance (checkpoint D, D6).
     *
     * It is not claimed to match `clean_input()` exactly: that also strips
     * tags and slashes, and reproducing it here would be a second copy of a
     * panel rule that is free to change. Two spellings of the same name
     * meeting in one bucket is what this is for; a name mangled far enough
     * that `clean_input()` changes it is one that will not find an account
     * either way.
     */
    private function usernameBucketKey(string $username): string
    {
        return $this->core->toAscii(mb_strtolower(trim($username)));
    }

    /**
     * Charge one bucket and refuse when it is full.
     *
     * @throws ApiException RATE_LIMITED
     */
    private function requireRoom(string $bucket, string $key, int $limit, int $window): void
    {
        $retryAfter = $this->limiter->charge($bucket, $key, $limit, $window);

        if ($retryAfter <= 0) {
            return;
        }

        // The same shape RateLimitMiddleware::refuse() uses, as a field error
        // rather than a transport refusal: by the time a resolver runs, the
        // document is already executing and the envelope is already a GraphQL
        // result.
        throw new ApiException(
            ErrorCode::RATE_LIMITED,
            sprintf(
                'Too many requests. Retry in %d second%s.',
                $retryAfter, $retryAfter === 1 ? '' : 's'
            ),
            array('retryAfterSeconds' => $retryAfter)
        );
    }

    /**
     * The scopes the new token will carry.
     *
     * A scope the caller's role could never hold is refused rather than
     * dropped: a client that asked for RESELLERS_WRITE and was quietly handed
     * a token without it would find out at the first request that needed it,
     * and would have no way to tell that from a revoked token. FORBIDDEN here
     * names the caller's own role, which it already knows.
     *
     * @param string[] $asked
     * @return string[]
     * @throws ApiException
     */
    private function scopesFor(string $adminType, array $asked): array
    {
        $available = self::scopesForRole($adminType);
        $scopes = array();

        foreach ($asked as $scope) {
            $scope = (string)$scope;

            if (!in_array($scope, $available, true)) {
                throw Guard::forbidden(
                    'This account\'s role cannot hold the scope this token asks for.',
                    array('scope' => $scope)
                );
            }

            if (!in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        if ($scopes === array()) {
            // Not clutter, and not a harmless default: Identity::hasScope()
            // reads a credential that records no scopes at all as a full one
            // (a token issued before scopes existed). A token minted with an
            // empty list would therefore be the widest token the account can
            // have, which is the opposite of what asking for nothing means.
            throw Guard::badInput(
                'input.scopes', 'A token needs at least one scope.'
            );
        }

        return $scopes;
    }

    /**
     * Which scopes a role can hold at all.
     *
     * Identity::hasScope() reads an empty scope list as a full credential, so
     * a scope a role cannot use is not harmless clutter on a token - it is a
     * claim the token makes that nothing downstream re-checks against the
     * role. The role check is where it belongs: at the one place a token is
     * minted.
     *
     * @return string[]
     */
    private static function scopesForRole(string $adminType): array
    {
        $role = Identity::ROLE_BY_ADMIN_TYPE[$adminType] ?? Identity::ROLE_CUSTOMER;

        if ($role === Identity::ROLE_ADMIN) {
            return Scope::all();
        }

        if ($role === Identity::ROLE_RESELLER) {
            // A reseller administers its own customers, not other resellers.
            return array_values(array_diff(
                Scope::all(),
                array(Scope::RESELLERS_READ, Scope::RESELLERS_WRITE)
            ));
        }

        // A customer administers nothing but its own account's objects.
        return array_values(array_diff(
            Scope::all(),
            array(
                Scope::CUSTOMERS_READ, Scope::CUSTOMERS_WRITE,
                Scope::RESELLERS_READ, Scope::RESELLERS_WRITE
            )
        ));
    }

    /**
     * Spec section 5.3's `token_max_per_account`, counted over the tokens that
     * are still usable.
     *
     * A revoked or expired token is not a token the account can present, so
     * counting it would mean an account that had rotated its tokens ten times
     * could never mint an eleventh.
     *
     * @throws ApiException LIMIT_EXCEEDED
     */
    private function requireRoomForAnotherToken(int $adminId): void
    {
        $limit = (int)($this->config['token_max_per_account'] ?? 10);

        if ($limit < 1) {
            return;
        }

        $live = 0;
        $now = time();

        foreach ($this->tokens->listFor($adminId) as $token) {
            if ($token->getRevokedAt() !== null) {
                continue;
            }

            if ($token->getExpiresAt() !== null && $token->getExpiresAt() <= $now) {
                continue;
            }

            $live++;
        }

        if ($live < $limit) {
            return;
        }

        throw Guard::limitExceeded(
            'This account already holds as many API tokens as it may.',
            array('quota' => 'tokens', 'limit' => $limit, 'used' => $live)
        );
    }

    /**
     * The lifetime, capped.
     *
     * Capped rather than refused: `token_max_ttl_days` is the operator's
     * ceiling, not a value the caller is expected to know, and a client
     * asking for two years on an installation that allows one gets one - with
     * expiresAt in the answer saying so. An absent expiresInDays is
     * `token_default_ttl_days`; a token that never expires cannot be asked
     * for over the API at all.
     *
     * @param array<string, mixed> $input
     * @throws ApiException BAD_USER_INPUT
     */
    private function ttlDays(array $input): int
    {
        $max = max(1, (int)($this->config['token_max_ttl_days'] ?? 730));
        $default = (int)($this->config['token_default_ttl_days'] ?? 365);

        if (!isset($input['expiresInDays']) || $input['expiresInDays'] === null) {
            return min($max, max(1, $default));
        }

        $asked = (int)$input['expiresInDays'];

        if ($asked < 1) {
            throw Guard::badInput(
                'input.expiresInDays', 'A token lasts at least one day.'
            );
        }

        return min($max, $asked);
    }

    /**
     * TokenService stores the allow-list as one comma-separated column, and
     * validates every entry in it; an empty list is null, which is "anywhere".
     *
     * @param array<string, mixed> $input
     */
    private function allowlist(array $input): ?string
    {
        $entries = array();

        foreach ((array)($input['ipAllowlist'] ?? array()) as $entry) {
            $entry = trim((string)$entry);

            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries === array() ? null : implode(',', $entries);
    }

    /**
     * One of the account's own tokens, by identifier, or null.
     *
     * Scoped to the account by construction: listFor() takes an admin id, so
     * there is no query here that could return somebody else's row.
     */
    private function tokenOf(int $adminId, ?int $tokenId): ?Token
    {
        if ($tokenId === null) {
            return null;
        }

        foreach ($this->tokens->listFor($adminId) as $token) {
            if ($token->getTokenId() === $tokenId) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The row identifier inside an ApiToken identifier, or null for anything
     * that is not one.
     *
     * Null rather than an exception: every way of naming nothing is the same
     * NOT_FOUND (spec section 6.3), and the caller above turns this into
     * exactly that.
     */
    private function tokenIdFrom(string $encoded): ?int
    {
        try {
            return GlobalId::decode($encoded, self::TAG)->getId();
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * The ApiToken shape, which carries the SDL's own field names so that
     * ResolverMap's fallback resolves every one of them.
     *
     * @return array<string, mixed>
     */
    private function shape(Token $token): array
    {
        $allowlist = (string)$token->getIpAllowlist();

        return array(
            'id'          => GlobalId::encode(self::TAG, $token->getTokenId()),
            'prefix'      => $token->getPrefix(),
            'name'        => $token->getName(),
            'scopes'      => $token->getScopes(),
            'ipAllowlist' => trim($allowlist) === ''
                ? array()
                : array_map('trim', explode(',', $allowlist)),
            'createdAt'   => $this->dateTime($token->getCreatedAt()),
            'expiresAt'   => $this->dateTime($token->getExpiresAt()),
            'lastUsedAt'  => $this->dateTime($token->getLastUsedAt()),
            'lastUsedIp'  => $token->getLastUsedIp(),
            'revokedAt'   => $this->dateTime($token->getRevokedAt())
        );
    }

    /**
     * The same shape, from what issue() was given rather than from the row.
     *
     * TokenService::issue() reports the new row's identifier as 0 when it
     * cannot ask the panel's connection for one, and a read-back keyed on 0
     * finds nothing. The token itself is in the database either way - the
     * INSERT is the part that matters - so this answers from the values that
     * produced it rather than nulling the whole mutation over a field nobody
     * asked for. createdAt is the only value it has to take on trust, and it
     * is this second.
     *
     * @param array{token: string, tokenId: int, expiresAt: int|null} $issued
     * @param string[] $scopes
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function shapeOfJustIssued(array $issued, int $adminId, array $scopes, array $input): array
    {
        $prefix = '';
        $parts = TokenService::splitPresented($issued['token']);

        if ($parts !== null) {
            $prefix = $parts['prefix'];
        }

        $allowlist = (string)$this->allowlist($input);

        return array(
            'id'          => GlobalId::encode(self::TAG, max(1, (int)$issued['tokenId'])),
            'prefix'      => $prefix,
            'name'        => trim((string)($input['name'] ?? '')),
            'scopes'      => $scopes,
            'ipAllowlist' => trim($allowlist) === ''
                ? array()
                : array_map('trim', explode(',', $allowlist)),
            'createdAt'   => $this->dateTime(time()),
            'expiresAt'   => $this->dateTime($issued['expiresAt']),
            'lastUsedAt'  => null,
            'lastUsedIp'  => null,
            'revokedAt'   => null
        );
    }

    /**
     * @param mixed $timestamp
     */
    private function dateTime($timestamp): ?string
    {
        return TypeResolver::dateTime($timestamp);
    }

    private function allowPasswordGrant(): bool
    {
        return (bool)($this->config['allow_password_grant'] ?? true);
    }

    private function perAddressLimit(): int
    {
        return (int)($this->config['rate_limit_token_issue'] ?? 5);
    }

    /**
     * Ten an hour, unless the operator has switched the per-address bucket
     * off - see PER_USERNAME_HOURLY.
     */
    private function perUsernameLimit(): int
    {
        return $this->perAddressLimit() < 0 ? -1 : self::PER_USERNAME_HOURLY;
    }
}
