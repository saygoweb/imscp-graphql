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
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Service\RateLimiter;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Charges specification section 10.3's `queries` bucket, once per request.
 *
 * **It sits outside AuthenticateMiddleware**, and that is the whole of
 * checkpoint D's D4. Wired inside it, a request carrying a bogus, expired or
 * revoked bearer was refused 401 before any bucket was charged - while still
 * costing a TokenService::verify() lookup and a hash. Unlimited attempts,
 * uncounted, against the one thing an attacker with a stolen-and-rotated
 * token would be trying.
 *
 * Outside it there is no Identity yet, so keyFor() keys the presented token's
 * *prefix* instead. The prefix is the public lookup key rather than a secret -
 * `api_token`.`token_prefix` is what verify() selects on - and
 * TokenService::splitPresented() extracts it without verifying anything, so a
 * refused credential is counted under the same key the accepted one would be.
 * A request that then authenticates is additionally charged to its own token
 * bucket by GraphQLHandler's `mutations` charge, exactly as before, so a valid
 * caller's limits are unchanged.
 *
 * Only `queries` is charged here, and every request is charged it, a mutation
 * included. `mutations` is the second bucket for a mutation, not the
 * substitute for the first: section 10.3's "120 queries a minute" and "30
 * mutations a minute" are two limits, and thirty mutations have to come out of
 * the hundred and twenty. That second charge cannot happen here at all,
 * because whether the document is a mutation is not known until it is parsed,
 * which is GraphQLHandler's job - so the handler makes it, through the
 * `chargeMutation` option Container gives it, and refuses with this class's
 * own refuse() so that both refusals are one shape defined in one place.
 *
 * D35 adds a second limit per bucket: a request from config.php's
 * `trusted_clients` is charged against `rate_limit_queries_trusted` here and
 * `rate_limit_mutations_trusted` in GraphQLHandler's charge, in place of the
 * ordinary limits - never in place of a limit. See isTrustedClient().
 */
final class RateLimitMiddleware
{
    /** @var RateLimiter */
    private $limiter;

    /** @var array<string, mixed> */
    private $options;

    /**
     * Addresses and CIDR ranges a trusted client's request may come from,
     * comma-separated - TokenService::addressIsAllowed()'s own format -
     * normalised once here the way TlsMiddleware normalises trusted_proxies:
     * trimmed of blanks, and '' (never null) when there is nothing to trust,
     * so isTrustedClient() never has to ask addressIsAllowed() the one
     * question it answers the wrong way for this purpose - see there.
     *
     * @var string
     */
    private $trustedClients;

    /**
     * @param array<string, mixed> $options 'queries' => the ordinary hits
     *                                      allowed per minute. Negative is
     *                                      unlimited; see
     *                                      RateLimiter::charge().
     *                                      'queriesTrusted' => the hits
     *                                      allowed per minute for a request
     *                                      from 'trustedClients' instead.
     *                                      'trustedClients' => CIDRs/addresses
     *                                      (array), D35. Empty matches no
     *                                      client.
     */
    public function __construct(RateLimiter $limiter, array $options)
    {
        $this->limiter = $limiter;
        $this->options = $options;
        $this->trustedClients = self::normalizeAddressList(
            (array)($options['trustedClients'] ?? array())
        );
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, callable $next
    ): ResponseInterface {
        // A CORS preflight carries no credential and runs no operation, so it
        // has nothing to charge and no bucket to charge it to. Consistent with
        // AuthenticateMiddleware, which lets the same request through.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $next($request, $response);
        }

        $limit = self::isTrustedClient($request, $this->trustedClients)
            ? (int)($this->options['queriesTrusted'] ?? 1200)
            : (int)($this->options['queries'] ?? 120);

        $retryAfter = $this->limiter->charge(
            RateLimiter::BUCKET_QUERIES,
            self::keyFor($request),
            $limit,
            RateLimiter::WINDOW_MINUTE
        );

        if ($retryAfter > 0) {
            return self::refuse($response, $retryAfter);
        }

        return $next($request, $response);
    }

    /**
     * Who this request is limited as.
     *
     * Three answers, in falling order of how precisely the request names
     * itself:
     *
     * - an Identity, when authentication has already run. A token is its own
     *   bucket, so one compromised or runaway token cannot spend an account's
     *   whole allowance; a panel session is keyed to the account, there being
     *   nothing narrower. This is the branch GraphQLHandler's `mutations`
     *   charge takes, because by then the attribute is set;
     * - the presented token's prefix, when the Authorization header carries
     *   one that is shaped like a token. This is the branch this middleware
     *   takes, since it now runs before authentication - and it is one bucket
     *   per token whether or not the token turns out to verify, which is the
     *   point (D4). A prefix is one-to-one with a token, so a valid caller is
     *   limited exactly as `token:` limited it;
     * - the source address, for a request that names no credential at all -
     *   the shape specification section 10.3 gives `tokenIssue`, which is the
     *   one unauthenticated field.
     *
     * Public and static because GraphQLHandler's mutation charge has to key
     * the same request the same way; two implementations of "who is this" would
     * be two limits.
     */
    public static function keyFor(ServerRequestInterface $request): string
    {
        $identity = $request->getAttribute('identity');

        if ($identity instanceof Identity) {
            $tokenId = $identity->getTokenId();

            return $tokenId !== null
                ? 'token:' . $tokenId
                : 'session:' . $identity->getAdminId();
        }

        $presented = TlsMiddleware::bearerToken($request);
        $parts = $presented === null ? null : TokenService::splitPresented($presented);

        if ($parts !== null) {
            return 'prefix:' . $parts['prefix'];
        }

        return 'ip:' . (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    /**
     * D35: whether this request's source address is one config.php's
     * `trusted_clients` names - the mirroring integration `queries` and
     * `mutations` get a bigger bucket for, never no bucket (see the class
     * docblock's decision D35 and Service/RateLimiter.php's negative-limit
     * note, which this is not: a trusted client is still charged, just
     * against a higher limit).
     *
     * Public and static for the same reason keyFor() is: GraphQLHandler's
     * `mutations` charge is built in Api\Container, not here, and has to
     * decide the identical question about the identical request rather than
     * grow a second definition of "trusted" that could drift from this one.
     *
     * $trustedClients is already-normalised (normalizeAddressList()), not raw
     * config, so this never has to. An empty string is answered here, before
     * TokenService::addressIsAllowed() ever sees it: that method reads a
     * blank allow-list as "no restriction", right for an allow-list guarding
     * who *may* do something, catastrophic for a list of who gets a bigger
     * bucket - it would make every client trusted the moment the operator set
     * nothing, exactly the "empty by default" promise D35 exists to keep.
     * TlsMiddleware::fromTrustedProxy() answers the same question the same
     * way for 'trusted_proxies'.
     *
     * Reads REMOTE_ADDR only, never a forwarded header: this plugin's one
     * definition of "the client's address" (Api\Container::clientIp(),
     * AuthenticateMiddleware, this class's own keyFor()) is the connection
     * PHP actually accepted, and 'trusted_clients' is no exception - an
     * X-Forwarded-For here would let an untrusted caller simply claim the
     * bigger bucket for itself.
     */
    public static function isTrustedClient(
        ServerRequestInterface $request, string $trustedClients
    ): bool {
        if ($trustedClients === '') {
            return false;
        }

        $remoteAddr = trim((string)($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($remoteAddr === '') {
            return false;
        }

        return TokenService::addressIsAllowed($trustedClients, $remoteAddr);
    }

    /**
     * `trusted_clients` as config.php gives it (an array of CIDRs/addresses)
     * into the comma-separated string TokenService::addressIsAllowed() reads,
     * trimmed of blanks - the same normalisation TlsMiddleware's constructor
     * does for 'trusted_proxies', for the same reason: a blank entry
     * implode()d in would otherwise make the list empty, which
     * addressIsAllowed() reads as "no restriction".
     *
     * Public so that Api\Container can normalise the same config value once
     * for both charge sites - this middleware's own 'queries' bucket and the
     * `mutations` bucket GraphQLHandler charges - without either duplicating
     * this loop or trusting the other to have already run it.
     *
     * @param array<int, mixed> $entries
     */
    public static function normalizeAddressList(array $entries): string
    {
        $clean = array();

        foreach ($entries as $entry) {
            $entry = trim((string)$entry);

            if ($entry !== '') {
                $clean[] = $entry;
            }
        }

        return implode(',', $clean);
    }

    /**
     * The 429.
     *
     * No `data` key, deliberately: the request never reached execution, so
     * there is no result envelope to speak of, and a client that keys off
     * `data` being present must not be told a document ran when none did.
     * `Retry-After` is in seconds, which RateLimiter guarantees is at least 1
     * and at most the window.
     */
    public static function refuse(ResponseInterface $response, int $retryAfterSeconds): ResponseInterface
    {
        $response = $response
            ->withStatus(429)
            ->withHeader('Retry-After', (string)$retryAfterSeconds)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write(json_encode(array('errors' => array(array(
            'message'    => sprintf(
                'Too many requests. Retry in %d second%s.',
                $retryAfterSeconds, $retryAfterSeconds === 1 ? '' : 's'
            ),
            'extensions' => array(
                'code'              => ErrorCode::RATE_LIMITED,
                'retryAfterSeconds' => $retryAfterSeconds
            )
        )))));

        return $response;
    }
}
