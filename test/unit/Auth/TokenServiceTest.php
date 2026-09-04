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
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
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

        // The plaintext must not be anywhere in what was written. Limit to 3
        // parts: the secret's own base64url alphabet includes '_', so an
        // unlimited explode() would truncate a secret that happens to
        // contain one, at the first such split rather than the one after
        // the prefix.
        $secret = explode('_', $result['token'], 3)[2];

        // Not just "no bound value equals the secret exactly" — the secret
        // must not appear anywhere, including as a substring of another
        // bound field, which exact-equality per value would not catch.
        self::assertStringNotContainsString(
            $secret, implode('|', array_map('strval', $insert['bind']))
        );
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

    /**
     * A name containing '{' or '}' is rendered forever by i-MSCP's template
     * engine: substitute_dynamic() rescans its own substituted output from
     * before the text it just inserted, looking for a further placeholder,
     * and tohtml() does not escape braces. A name that substitutes to itself
     * never terminates, hanging the customer's own token page.
     *
     * @dataProvider namesContainingABrace
     */
    public function testIssueRejectsANameContainingABrace(string $name): void
    {
        try {
            $this->service()->issue(7, $name, ['DOMAINS_READ'], 30, null);
            self::fail('Expected an ApiException for name "' . $name . '".');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertSame('name', $e->getExtensions()['field']);
        }
    }

    public function namesContainingABrace(): array
    {
        return [
            'opening brace' => ['my{token'],
            'closing brace' => ['my}token'],
            'placeholder'   => ['{NAME}'],
        ];
    }

    public function testIssueRejectsANameLongerThan255Characters(): void
    {
        try {
            $this->service()->issue(7, str_repeat('a', 256), ['DOMAINS_READ'], 30, null);
            self::fail('Expected an ApiException for a 256-character name.');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertSame('name', $e->getExtensions()['field']);
        }
    }

    public function testIssueAcceptsANameOf255Characters(): void
    {
        $result = $this->service()->issue(7, str_repeat('a', 255), ['DOMAINS_READ'], 30, null);

        self::assertMatchesRegularExpression(
            '/^imscp_[a-z2-7]{8}_[A-Za-z0-9_-]{43}$/', $result['token']
        );
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

    public function testVerifyAcceptsAnExactBareAddress(): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['ip_allowlist' => '127.0.0.1'])];

        self::assertNotNull($this->service()->verify('imscp_abcdefgh_' . $secret, '127.0.0.1'));
    }

    public function testVerifyAcceptsASlashZeroAllowlist(): void
    {
        // /0 is well-formed and means "any address" — only malformed
        // suffixes must reject, not this one.
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['ip_allowlist' => '10.0.0.0/0'])];

        self::assertNotNull($this->service()->verify('imscp_abcdefgh_' . $secret, '8.8.8.8'));
    }

    /**
     * A malformed CIDR must fail closed, not open. The naive (int) cast this
     * replaces turned '-1', '', 'abc' and '1e2' into 0 — an unrestricted /0
     * that admitted every address, which is worse than no allow-list at all.
     *
     * @dataProvider malformedAllowlistEntries
     */
    public function testVerifyRejectsEveryAddressWhenTheAllowlistEntryIsMalformed(string $entry): void
    {
        $secret = str_repeat('a', 43);
        $this->rows = [$this->row($secret, ['ip_allowlist' => $entry])];

        // 10.0.0.0 is the literal subnet address these entries name, so any
        // correctly parsed CIDR built on it — even /32 — would also match
        // it. That is deliberate: it is the address a fail-open bug is most
        // likely to admit by accident (the naive (int) cast in the code this
        // replaces made several of these evaluate to an unrestricted /0, and
        // the /33 and /40 cases matched at exactly this address through an
        // out-of-bounds string offset), so a null here proves the fix closes
        // the hole rather than merely failing to match an unrelated address.
        self::assertNull($this->service()->verify('imscp_abcdefgh_' . $secret, '10.0.0.0'));
    }

    /**
     * @dataProvider malformedAllowlistEntries
     */
    public function testIssueRejectsAMalformedAllowlistEntry(string $entry): void
    {
        try {
            $this->service()->issue(7, 'ci', ['DOMAINS_READ'], 30, $entry);
            self::fail('Expected an ApiException for allow-list entry "' . $entry . '".');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
        }
    }

    public function malformedAllowlistEntries(): array
    {
        return [
            'negative prefix length'    => ['10.0.0.0/-1'],
            'trailing slash, no digits' => ['10.0.0.0/'],
            'non-numeric prefix'        => ['10.0.0.0/abc'],
            'out of range for IPv4'     => ['10.0.0.0/33'],
            'far out of range for IPv4' => ['10.0.0.0/40'],
            'exponent form'             => ['10.0.0.0/1e2'],
            'leading space'             => ['10.0.0.0/ 8'],
            'not an address at all'     => ['not-an-ip'],
        ];
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
