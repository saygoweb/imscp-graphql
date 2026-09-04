<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Auth;

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
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase
{
    /** @var array The rows a fake exec_query() will answer with */
    private $rows = [];

    /** @var array Every statement the service issued */
    private $statements = [];

    private function service(): TokenService
    {
        $this->statements = [];

        return new TokenService(function (string $sql, array $bind = []) {
            $this->statements[] = ['sql' => $sql, 'bind' => $bind];
            return new FakeStatement($this->rows);
        });
    }

    public function testIssueReturnsAPrefixedTokenAndStoresOnlyItsHash(): void
    {
        $result = $this->service()->issue(7, 'ci', ['DOMAINS_READ'], 30, null);

        self::assertMatchesRegularExpression(
            '/^imscp_[a-z2-7]{8}_[A-Za-z0-9_-]{43}$/', $result['token']
        );

        $insert = $this->statements[0];
        self::assertStringContainsString('INSERT INTO api_token', $insert['sql']);

        // The plaintext must not be anywhere in what was written.
        $secret = explode('_', $result['token'])[2];
        foreach ($insert['bind'] as $bound) {
            self::assertNotSame($secret, $bound);
        }
        self::assertContains(hash('sha256', $secret), $insert['bind']);
    }

    public function testIssueRejectsAnUnknownScope(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->issue(7, 'ci', ['EVERYTHING'], 30, null);
    }

    public function testIssueRejectsAnEmptyName(): void
    {
        $this->expectException(ApiException::class);

        $this->service()->issue(7, '   ', ['DOMAINS_READ'], 30, null);
    }

    public function testANullTtlMeansNoExpiry(): void
    {
        self::assertNull($this->service()->issue(7, 'ci', [], null, null)['expiresAt']);
    }

    public function testSplitPresentedAcceptsAWellFormedToken(): void
    {
        $parts = TokenService::splitPresented(
            'imscp_abcdefgh_' . str_repeat('a', 43)
        );

        self::assertSame('abcdefgh', $parts['prefix']);
        self::assertSame(str_repeat('a', 43), $parts['secret']);
    }

    /**
     * @dataProvider malformedTokens
     */
    public function testSplitPresentedRejectsMalformedTokens(string $presented): void
    {
        self::assertNull(TokenService::splitPresented($presented));
    }

    public function malformedTokens(): array
    {
        return [
            'empty'         => [''],
            'wrong prefix'  => ['bearer_abcdefgh_' . str_repeat('a', 43)],
            'short prefix'  => ['imscp_abc_' . str_repeat('a', 43)],
            'short secret'  => ['imscp_abcdefgh_short'],
            'no separators' => ['imscpabcdefgh' . str_repeat('a', 43)],
        ];
    }

    public function testVerifyReturnsNullWhenNoRowMatchesThePrefix(): void
    {
        $this->rows = [];

        self::assertNull($this->service()->verify(
            'imscp_abcdefgh_' . str_repeat('a', 43), '127.0.0.1'
        ));
    }

    public function testVerifyRejectsARevokedToken(): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['revoked_at' => time() - 1])];

        self::assertNull($this->service()->verify('imscp_abcdefgh_' . $secret, '127.0.0.1'));
    }

    public function testVerifyRejectsAnExpiredToken(): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['expires_at' => time() - 1])];

        self::assertNull($this->service()->verify('imscp_abcdefgh_' . $secret, '127.0.0.1'));
    }

    public function testVerifyRejectsTheWrongSecretForARealPrefix(): void
    {
        $this->rows = [$this->row(str_repeat('a', 43))];

        self::assertNull($this->service()->verify(
            'imscp_abcdefgh_' . str_repeat('b', 43), '127.0.0.1'
        ));
    }

    public function testVerifyRejectsAnAddressOutsideTheAllowlist(): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['ip_allowlist' => '10.0.0.0/8'])];

        self::assertNull($this->service()->verify('imscp_abcdefgh_' . $secret, '192.168.1.5'));
    }

    public function testVerifyAcceptsAnAddressInsideTheAllowlist(): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['ip_allowlist' => '10.0.0.0/8,192.168.1.0/24'])];

        $token = $this->service()->verify('imscp_abcdefgh_' . $secret, '192.168.1.5');

        self::assertNotNull($token);
        self::assertSame(42, $token->getTokenId());
    }

    public function testVerifyAcceptsAGoodToken(): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret)];

        $token = $this->service()->verify('imscp_abcdefgh_' . $secret, '127.0.0.1');

        self::assertNotNull($token);
        self::assertSame(7, $token->getAdminId());
        self::assertSame(['DOMAINS_READ'], $token->getScopes());
    }

    private function row(string $secret, array $overrides = []): array
    {
        return array_merge([
            'token_id'     => 42,
            'admin_id'     => 7,
            'name'         => 'ci',
            'token_prefix' => 'abcdefgh',
            'token_hash'   => hash('sha256', $secret),
            'scopes'       => 'DOMAINS_READ',
            'ip_allowlist' => null,
            'created_at'   => time() - 100,
            'expires_at'   => null,
            'last_used_at' => null,
            'last_used_ip' => null,
            'revoked_at'   => null,
        ], $overrides);
    }
}

/**
 * The narrowest thing that behaves like the panel's PDO statement wrapper.
 */
class FakeStatement
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function fetchRow($mode = null)
    {
        return $this->rows === [] ? false : $this->rows[0];
    }

    public function fetchAll($mode = null): array
    {
        return $this->rows;
    }
}
