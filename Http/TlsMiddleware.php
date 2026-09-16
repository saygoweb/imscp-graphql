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
 *
 * Which makes deciding *whether* it arrived over TLS the whole of this class's
 * risk, in both directions. Call a proxied HTTPS request insecure and the
 * first correctly configured client destroys its own credential; believe a
 * client that merely says it was proxied and the requirement is gone
 * altogether. See isSecure().
 */
final class TlsMiddleware
{
    /** @var bool */
    private $requireTls;

    /** @var TokenService */
    private $tokens;

    /** @var string[] Addresses and CIDR ranges, already trimmed of blanks. */
    private $trustedProxies;

    /**
     * @param string[] $trustedProxies Addresses, or CIDR ranges, of the reverse
     *                                 proxies whose forwarding headers may be
     *                                 believed. Empty by default, and an older
     *                                 config.php with no 'trusted_proxies' key
     *                                 arrives here as empty too: both trust
     *                                 nothing and ask the connection itself,
     *                                 which is exactly the behaviour before
     *                                 the key existed.
     */
    public function __construct(
        bool $requireTls, TokenService $tokens, array $trustedProxies = array()
    ) {
        $this->requireTls = $requireTls;
        $this->tokens = $tokens;
        $this->trustedProxies = array();

        // A blank entry would be worse than no entry: implode()d into an
        // allow-list it becomes the empty list, which addressIsAllowed() reads
        // as "no restriction" — every client a trusted proxy. So
        // 'trusted_proxies' => '' and array('') are normalised away here,
        // where there is one place to do it, rather than guarded at the point
        // of use.
        foreach ($trustedProxies as $entry) {
            $entry = trim((string)$entry);

            if ($entry !== '') {
                $this->trustedProxies[] = $entry;
            }
        }
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

    /**
     * Whether the client's own leg of this request was encrypted.
     *
     * Three questions, in falling order of certainty, and the order is the
     * design.
     *
     * The first two ask the connection PHP actually accepted: a request this
     * process terminated TLS for has scheme https, and one the web server in
     * front of FPM terminated arrives with HTTPS set in the FastCGI
     * parameters. Neither is anything a client can say for itself.
     *
     * The third asks a proxy — and only a proxy this configuration names.
     * X-Forwarded-Proto is a request header like any other, so any client can
     * send one; believing it unconditionally would not fix the detection, it
     * would delete the control, since a plaintext request announcing
     * "X-Forwarded-Proto: https" would then walk straight past the TLS
     * requirement. It is consulted only when REMOTE_ADDR — the one address in
     * a request the client does not choose — matches 'trusted_proxies', which
     * is empty by default.
     *
     * Without that third question the ordinary deployment was the broken one:
     * nginx or Apache terminating TLS and proxying to PHP-FPM leaves the
     * backend seeing scheme http, no HTTPS and port 80, so every correctly
     * configured client took the insecure branch — and that branch does not
     * merely answer 403, it revokes the token it was handed. The first valid
     * request destroyed the credential that made it.
     *
     * SERVER_PORT is no longer asked at all. It used to be, and 443 alone
     * counted as proof: a plaintext listener bound to 443 was called secure
     * and the whole check passed. A port number says what was listened on,
     * never what was spoken on it.
     */
    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        $server = $request->getServerParams();

        if (!empty($server['HTTPS'])
            && strcasecmp((string)$server['HTTPS'], 'off') !== 0
        ) {
            return true;
        }

        if (!$this->fromTrustedProxy($server)) {
            return false;
        }

        $proto = $request->getHeaderLine('X-Forwarded-Proto');

        if ($proto !== '') {
            // Each proxy in a chain appends, so the client's own protocol is
            // the first entry rather than the last.
            $hops = explode(',', $proto);

            // Definitive either way: a trusted proxy that says "http" has told
            // us the client's leg was in the clear, and X-Forwarded-SSL does
            // not get a second chance to overturn it.
            return strcasecmp(trim($hops[0]), 'https') === 0;
        }

        // mod_ssl's vocabulary, which some front ends send instead.
        return strcasecmp(
            trim($request->getHeaderLine('X-Forwarded-SSL')), 'on'
        ) === 0;
    }

    /**
     * Whether this request reached PHP from a proxy the operator has named.
     *
     * TokenService::addressIsAllowed() is the CIDR matcher the token
     * allow-list already uses, fail-closed prefix parser and all. A second
     * implementation here would be one more place for two definitions of
     * "/0" or "/abc" to drift apart, and this one decides whether the TLS
     * requirement applies.
     *
     * The one thing it does differently is the empty list, which it reads as
     * "no restriction" — right for an allow-list, catastrophic here, where it
     * would make every client a trusted proxy. That case is answered before it
     * is asked.
     *
     * @param array<string, mixed> $server
     */
    private function fromTrustedProxy(array $server): bool
    {
        if ($this->trustedProxies === array()) {
            return false;
        }

        $remoteAddr = trim((string)($server['REMOTE_ADDR'] ?? ''));

        if ($remoteAddr === '') {
            return false;
        }

        return TokenService::addressIsAllowed(
            implode(',', $this->trustedProxies), $remoteAddr
        );
    }
}
