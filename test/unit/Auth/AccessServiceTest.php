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

use iMSCP\Plugin\SGW_GraphQL\Auth\AccessService;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * Two of specification §6.2's contracts were verifiable only by a manual box
 * run, because frontend/common.php called the global exec_query() directly:
 *
 *   - a reseller may act only on their own customers (isCustomerOf())
 *   - withdrawing access revokes that account's tokens (setApiAccess())
 *
 * AccessService takes a callable $query exactly as TokenService does (see
 * that class's constructor and fromPanel()), so both are exercised here
 * without a panel. FakeStatement is TokenServiceTest.php's, in this same
 * namespace.
 */
class AccessServiceTest extends TestCase
{
    /** @var array Rows a fake query will answer with */
    private $rows = [];

    /** @var array Every statement the service issued */
    private $statements = [];

    private function service(bool $allowedByDefault = true, ?TokenService $tokens = null): AccessService
    {
        $this->statements = [];

        return new AccessService(
            function (string $sql, array $bind = []) {
                $this->statements[] = ['sql' => $sql, 'bind' => $bind];
                return new FakeStatement($this->rows);
            },
            $tokens ?? new TokenService(function (string $sql, array $bind = []) {
                return new FakeStatement([]);
            }),
            $allowedByDefault
        );
    }

    private function customerRow(int $adminId, int $allowed = 1, int $liveTokens = 0): array
    {
        return [
            'admin_id'    => $adminId,
            'admin_name'  => 'customer' . $adminId,
            'allowed'     => $allowed,
            'live_tokens' => $liveTokens,
        ];
    }

    public function testCustomersOfReturnsTheQueriedRowsAndBindsTheResolvedDefault(): void
    {
        $this->rows = [$this->customerRow(42)];
        $service = $this->service(false);

        self::assertSame($this->rows, $service->customersOf(8));

        $bind = $this->statements[0]['bind'];
        // The default is resolved once by the caller (fromPanel()) and
        // passed to the constructor, not read from Registry here — the same
        // fix item 1 makes for the API request path.
        self::assertSame([0, 8], $bind);
    }

    /**
     * The cross-tenant guard: a reseller may act only on their own
     * customers. Before this fix, the loop that enforced this lived inline
     * in frontend/reseller/api_access.php and called exec_query() through
     * customersOf(), so nothing could drive it without a panel — this is the
     * single most valuable untested branch on the branch.
     */
    public function testIsCustomerOfAcceptsAGenuineCustomer(): void
    {
        $this->rows = [$this->customerRow(42)];

        self::assertTrue($this->service()->isCustomerOf(8, 42));
    }

    public function testIsCustomerOfRejectsACustomerOfADifferentReseller(): void
    {
        // customersOf(8) here stands in for "reseller 8's own customers",
        // which never includes 999 - simulating a customer that belongs to
        // a different reseller (or does not exist at all; the two must be
        // indistinguishable per api_access.php's own comment).
        $this->rows = [$this->customerRow(42)];

        self::assertFalse($this->service()->isCustomerOf(8, 999));
    }

    /**
     * A test that only checked the row was written would pass against a
     * regression that silently dropped the revocation - exactly the half of
     * §6.2 a reader is most likely to assume rather than verify. This one
     * fails if revokeAllFor() is not called, not only if the api_perm write
     * is wrong.
     */
    public function testSetApiAccessWithdrawingWritesAllowedFalseAndRevokesTokens(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::once())->method('revokeAllFor')->with(42);

        $this->service(true, $tokens)->setApiAccess(42, false);

        self::assertStringContainsString('INSERT INTO api_perm', $this->statements[0]['sql']);
        self::assertSame([42, 0], $this->statements[0]['bind']);
    }

    public function testSetApiAccessGrantingWritesAllowedTrueWithoutRevokingAnything(): void
    {
        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('revokeAllFor');

        $this->service(true, $tokens)->setApiAccess(42, true);

        self::assertSame([42, 1], $this->statements[0]['bind']);
    }
}
