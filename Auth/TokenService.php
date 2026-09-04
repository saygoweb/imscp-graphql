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
        $name = trim($name);

        if ($name === '') {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'A token needs a name.',
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
        $expiresAt = $ttlDays === null ? null : $now + ($ttlDays * 86400);

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
