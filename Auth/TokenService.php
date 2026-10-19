<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;

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

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use PDO;

/**
 * Issues, verifies and revokes the opaque bearer tokens the API accepts.
 *
 * A token is imscp_<prefix>_<secret>. The prefix is stored in the clear and is
 * what the lookup is done on; only a SHA-256 of the secret is stored, and the
 * secret itself exists in plaintext exactly once, in the creation response.
 *
 * Why not a JWT: a token that can create system users and SQL grants must be
 * revocable now, which means a database lookup on every request — at which
 * point the token does not need to carry signed claims.
 *
 * @internal Deliberately not final: a later task's tests mock this with
 *           PHPUnit's createMock(), which cannot mock a final class.
 */
class TokenService
{
    /** Base32 without the characters that are easy to confuse when read aloud. */
    const PREFIX_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    const PREFIX_LENGTH = 8;
    const SECRET_BYTES = 32;

    /** @var callable */
    private $query;

    /**
     * @param callable $query Signature of exec_query($sql, $bind). Injected so
     *                        the unit suite need not bootstrap the panel.
     */
    public function __construct(callable $query)
    {
        $this->query = $query;
    }

    public static function fromPanel(): self
    {
        return new self(function (string $sql, array $bind = array()) {
            return exec_query($sql, $bind);
        });
    }

    /**
     * @param string[] $scopes
     * @return array{token: string, tokenId: int, expiresAt: int|null}
     * @throws ApiException
     */
    public function issue(
        int $adminId,
        string $name,
        array $scopes,
        ?int $ttlDays,
        ?string $ipAllowlist
    ): array {
        return $this->mint(
            $adminId, $name, $scopes,
            $ttlDays === null ? null : $ttlDays * 86400,
            $ipAllowlist
        );
    }

    /**
     * issue(), with the lifetime in seconds rather than days.
     *
     * The panel's token page and `tokenIssue` think in days; an OAuth access
     * token minted through Api\Executor::issueToken() lives for an hour. One
     * method that validates and writes a token, two ways of saying how long
     * it lasts - so the second does not grow a second copy of everything
     * issue() checks. There is no never-expires form here: a caller that
     * thinks in seconds has a lifetime in mind.
     *
     * @param string[] $scopes
     * @return array{token: string, tokenId: int, expiresAt: int}
     * @throws ApiException BAD_USER_INPUT for a lifetime under one second,
     *                      and everything issue() refuses.
     */
    public function issueForSeconds(
        int $adminId,
        string $name,
        array $scopes,
        int $ttlSeconds,
        ?string $ipAllowlist
    ): array {
        if ($ttlSeconds < 1) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'A token lasts at least one second.',
                array('field' => 'ttlSeconds')
            );
        }

        return $this->mint($adminId, $name, $scopes, $ttlSeconds, $ipAllowlist);
    }

    /**
     * @param string[] $scopes
     * @return array{token: string, tokenId: int, expiresAt: int|null}
     * @throws ApiException
     */
    private function mint(
        int $adminId,
        string $name,
        array $scopes,
        ?int $ttlSeconds,
        ?string $ipAllowlist
    ): array {
        $name = trim($name);

        if ($name === '') {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'A token needs a name.',
                array('field' => 'name')
            );
        }

        // api_token.name is varchar(255); only the panel form's maxlength
        // enforced this before, so a longer name reached MariaDB and, under
        // strict mode, surfaced a raw driver error to the customer.
        if (mb_strlen($name) > 255) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT,
                'A token name may not be longer than 255 characters.',
                array('field' => 'name')
            );
        }

        // A name containing '{' or '}' is rendered forever: i-MSCP's template
        // engine rescans its own substituted output looking for a further
        // placeholder (TemplateEngine::substitute_dynamic()'s "new value may
        // also begin with '{'"), and tohtml() does not escape braces. A name
        // that substitutes to itself never terminates, hanging the customer's
        // own token page. Rejecting the character here, at the only place a
        // name is created, covers every future path that mints a token, not
        // only this one page.
        if (strpbrk($name, '{}') !== false) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT,
                'A token name may not contain a curly brace.',
                array('field' => 'name')
            );
        }

        foreach ($scopes as $scope) {
            if (!Scope::isValid($scope)) {
                throw new ApiException(
                    ErrorCode::BAD_USER_INPUT,
                    sprintf('Unknown scope "%s".', $scope),
                    array('field' => 'scopes')
                );
            }
        }

        // A malformed entry must never reach the database: verify()'s CIDR
        // check fails closed, but a restriction that was never stored
        // correctly in the first place is not a restriction at all.
        if ($ipAllowlist !== null && trim($ipAllowlist) !== '') {
            foreach (explode(',', $ipAllowlist) as $entry) {
                $entry = trim($entry);

                if (!self::isValidAllowlistEntry($entry)) {
                    throw new ApiException(
                        ErrorCode::BAD_USER_INPUT,
                        sprintf('"%s" is not an address or CIDR range.', $entry),
                        array('field' => 'ipAllowlist')
                    );
                }
            }
        }

        $prefix = self::randomPrefix();
        $secret = self::randomSecret();
        $now = time();
        $expiresAt = $ttlSeconds === null ? null : $now + $ttlSeconds;

        call_user_func(
            $this->query,
            '
                INSERT INTO api_token (
                    admin_id, name, token_prefix, token_hash, scopes,
                    ip_allowlist, created_at, expires_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ',
            array(
                $adminId, $name, $prefix, hash('sha256', $secret),
                implode(',', $scopes), $ipAllowlist, $now, $expiresAt
            )
        );

        $tokenId = function_exists('exec_query')
            ? (int)\iMSCP\Database\DatabaseMySQL::getInstance()->insertId()
            : 0;

        return array(
            'token'     => 'imscp_' . $prefix . '_' . $secret,
            'tokenId'   => $tokenId,
            'expiresAt' => $expiresAt
        );
    }

    /**
     * @return array{prefix: string, secret: string}|null
     */
    public static function splitPresented(string $presented): ?array
    {
        if (!preg_match(
            '/^imscp_([a-z2-7]{8})_([A-Za-z0-9_-]{43})$/', $presented, $m
        )) {
            return null;
        }

        return array('prefix' => $m[1], 'secret' => $m[2]);
    }

    /**
     * Null on any failure, without saying which: a caller learning that a
     * prefix exists but the secret is wrong learns something worth nothing to
     * them and something to an attacker.
     */
    public function verify(string $presented, string $clientIp): ?Token
    {
        $parts = self::splitPresented($presented);

        if ($parts === null) {
            return null;
        }

        $stmt = call_user_func(
            $this->query,
            'SELECT * FROM api_token WHERE token_prefix = ?',
            array($parts['prefix'])
        );

        if (!$stmt->rowCount()) {
            return null;
        }

        $row = $stmt->fetchRow(PDO::FETCH_ASSOC);
        $token = new Token($row);

        if (!hash_equals((string)$row['token_hash'], hash('sha256', $parts['secret']))) {
            return null;
        }

        if ($token->getRevokedAt() !== null) {
            return null;
        }

        if ($token->getExpiresAt() !== null && $token->getExpiresAt() <= time()) {
            return null;
        }

        if (!self::addressIsAllowed($token->getIpAllowlist(), $clientIp)) {
            return null;
        }

        return $token;
    }

    /**
     * Stamp the last use, at most once a minute per token.
     *
     * Every request would otherwise be a write, for a field nobody reads more
     * precisely than "recently".
     */
    public function stampLastUsed(Token $token, string $clientIp): void
    {
        $now = time();

        if ($token->getLastUsedAt() !== null && $now - $token->getLastUsedAt() < 60) {
            return;
        }

        call_user_func(
            $this->query,
            'UPDATE api_token SET last_used_at = ?, last_used_ip = ? WHERE token_id = ?',
            array($now, $clientIp, $token->getTokenId())
        );
    }

    public function revoke(int $adminId, int $tokenId): bool
    {
        $stmt = call_user_func(
            $this->query,
            '
                UPDATE api_token SET revoked_at = ?
                WHERE token_id = ? AND admin_id = ? AND revoked_at IS NULL
            ',
            array(time(), $tokenId, $adminId)
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether this account's token could still be presented: it exists, it
     * belongs to $adminId, it has not been revoked and it has not expired.
     *
     * verify() asks the same three questions of a presented secret, plus the
     * address; this asks them of a token id, for a holder that kept the id
     * and not the secret - Api\Executor::tokenIsLive(). Revocation keeps the
     * row and stamps revoked_at, so "the row exists" alone would count a
     * revoked token as live. An OAuth refresh asks isRevoked() instead.
     */
    public function isLive(int $adminId, int $tokenId): bool
    {
        $stmt = call_user_func(
            $this->query,
            '
                SELECT token_id FROM api_token
                WHERE token_id = ? AND admin_id = ? AND revoked_at IS NULL
                    AND (expires_at IS NULL OR expires_at > ?)
            ',
            array($tokenId, $adminId, time())
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether this account's token is gone: there is no such row, it belongs
     * to another account, or it has been revoked.
     *
     * Expiry is deliberately not asked. This is the check an OAuth refresh
     * makes on the access token it is about to replace, and an access token
     * that lives for an hour has almost always expired by then - so isLive()
     * would end every connection at its first refresh. What a refresh must
     * not survive is the user revoking the token on the panel's token page,
     * and that is revoked_at.
     */
    public function isRevoked(int $adminId, int $tokenId): bool
    {
        $stmt = call_user_func(
            $this->query,
            '
                SELECT token_id FROM api_token
                WHERE token_id = ? AND admin_id = ? AND revoked_at IS NULL
            ',
            array($tokenId, $adminId)
        );

        return $stmt->rowCount() === 0;
    }

    /**
     * Spec section 5.3's `token_max_per_account`, counted over the tokens that
     * are still usable.
     *
     * A revoked or expired token is not a token the account can present, so
     * counting it would mean an account that had rotated its tokens ten times
     * could never mint an eleventh.
     *
     * Here rather than in `tokenIssue`'s resolver because both places that
     * mint a token for a caller - that resolver and
     * Api\Executor::issueToken() - are held to it.
     *
     * @param int $limit Less than one is no limit.
     * @throws ApiException LIMIT_EXCEEDED
     */
    public function requireRoomForAnother(int $adminId, int $limit): void
    {
        if ($limit < 1) {
            return;
        }

        $live = 0;
        $now = time();

        foreach ($this->listFor($adminId) as $token) {
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

        throw new ApiException(
            ErrorCode::LIMIT_EXCEEDED,
            'This account already holds as many API tokens as it may.',
            array('quota' => 'tokens', 'limit' => $limit, 'used' => $live)
        );
    }

    public function revokeAllFor(int $adminId): void
    {
        call_user_func(
            $this->query,
            'UPDATE api_token SET revoked_at = ? WHERE admin_id = ? AND revoked_at IS NULL',
            array(time(), $adminId)
        );
    }

    /**
     * @return Token[]
     */
    public function listFor(int $adminId): array
    {
        $stmt = call_user_func(
            $this->query,
            'SELECT * FROM api_token WHERE admin_id = ? ORDER BY created_at DESC',
            array($adminId)
        );

        $tokens = array();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tokens[] = new Token($row);
        }

        return $tokens;
    }

    private static function randomPrefix(): string
    {
        $alphabet = self::PREFIX_ALPHABET;
        $prefix = '';

        for ($i = 0; $i < self::PREFIX_LENGTH; $i++) {
            $prefix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $prefix;
    }

    private static function randomSecret(): string
    {
        return rtrim(
            strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '='
        );
    }

    /**
     * @param string|null $allowlist Comma-separated CIDRs, or null for no
     *                               restriction.
     */
    public static function addressIsAllowed(?string $allowlist, string $clientIp): bool
    {
        if ($allowlist === null || trim($allowlist) === '') {
            return true;
        }

        foreach (explode(',', $allowlist) as $cidr) {
            if (self::addressMatchesCidr(trim($cidr), $clientIp)) {
                return true;
            }
        }

        return false;
    }

    private static function addressMatchesCidr(string $cidr, string $clientIp): bool
    {
        if ($cidr === '') {
            return false;
        }

        if (strpos($cidr, '/') === false) {
            return $cidr === $clientIp;
        }

        list($subnet, $bitsRaw) = explode('/', $cidr, 2);

        $ip = inet_pton($clientIp);
        $net = inet_pton($subnet);

        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }

        $bits = self::parsePrefixLength($bitsRaw, strlen($ip) * 8);

        if ($bits === null) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ip, $net, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ip[$wholeBytes]) & $mask) === (ord($net[$wholeBytes]) & $mask);
    }

    /**
     * Whether one comma-separated allow-list entry is a bare address or a
     * well-formed CIDR range. The single implementation issue() validates
     * against, so a malformed entry is rejected the same way it would fail
     * to match in verify() — there is one definition of "well-formed" here,
     * not two that could drift apart.
     */
    public static function isValidAllowlistEntry(string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if (strpos($entry, '/') === false) {
            return inet_pton($entry) !== false;
        }

        list($subnet, $bitsRaw) = explode('/', $entry, 2);
        $net = inet_pton($subnet);

        if ($net === false) {
            return false;
        }

        return self::parsePrefixLength($bitsRaw, strlen($net) * 8) !== null;
    }

    /**
     * Fails closed: only a bare non-negative integer string, in range for the
     * address family, is a valid prefix length. A cast such as (int) would
     * silently turn '-1', '', 'abc' or '1e2' into 0 — an unrestricted /0 that
     * admits every address, which is worse than no allow-list at all.
     */
    private static function parsePrefixLength(string $raw, int $maxBits): ?int
    {
        if (!ctype_digit($raw)) {
            return null;
        }

        $bits = (int)$raw;

        return $bits <= $maxBits ? $bits : null;
    }
}
