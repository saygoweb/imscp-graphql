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

use PDO;

/**
 * The reseller/customer relationships specification §6.2 describes: which
 * customers belong to which reseller, and granting or withdrawing API access
 * (which also revokes the affected account's tokens).
 *
 * Takes a callable $query exactly as TokenService does, and for the same
 * reason: frontend/common.php used to call the global exec_query() directly,
 * which left two of §6.2's contracts - a reseller may act only on their own
 * customers, and withdrawing access revokes that account's tokens -
 * verifiable only by a manual run against the box. See TokenService's own
 * constructor and fromPanel() for the shape this follows.
 *
 * @internal Deliberately not final: matches TokenService's own reasoning -
 *           a later task's tests may need to mock this with createMock().
 */
class AccessService
{
    /** @var callable */
    private $query;

    /** @var TokenService */
    private $tokens;

    /** @var bool */
    private $allowedByDefault;

    /**
     * @param callable $query Signature of exec_query($sql, $bind). Injected
     *                        so the unit suite need not bootstrap the panel.
     * @param TokenService $tokens Revokes tokens when access is withdrawn.
     * @param bool $allowedByDefault The plugin's configured
     *                               'allowed_by_default' (§6.2), resolved
     *                               once by the caller (see fromPanel()) -
     *                               not read from Registry on every call, the
     *                               same reasoning as
     *                               Container::fromPlugin()'s access-checker
     *                               closure.
     */
    public function __construct(callable $query, TokenService $tokens, bool $allowedByDefault)
    {
        $this->query = $query;
        $this->tokens = $tokens;
        $this->allowedByDefault = $allowedByDefault;
    }

    public static function fromPanel(): self
    {
        $plugin = \iMSCP\Registry::get('pluginManager')->pluginGet('SGW_GraphQL');

        return new self(
            function (string $sql, array $bind = array()) {
                return exec_query($sql, $bind);
            },
            TokenService::fromPanel(),
            (bool)$plugin->getConfigParam('allowed_by_default', true)
        );
    }

    /**
     * Every customer of a reseller, with their API access and live token
     * count.
     *
     * A customer with no api_perm row falls back to $allowedByDefault - the
     * same default SGW_GraphQL::customerHasApiAccess() applies when actually
     * enforcing access, so the grid never shows "Allowed" for a customer the
     * API would refuse, or vice versa.
     *
     * @return array Rows of admin_id, admin_name, allowed, live_tokens
     */
    public function customersOf(int $resellerId): array
    {
        $stmt = call_user_func(
            $this->query,
            '
                SELECT a.admin_id, a.admin_name,
                    COALESCE(p.allowed, ?) AS allowed,
                    (
                        SELECT COUNT(*) FROM api_token AS t
                        WHERE t.admin_id = a.admin_id
                          AND t.revoked_at IS NULL
                          AND (t.expires_at IS NULL OR t.expires_at > UNIX_TIMESTAMP())
                    ) AS live_tokens
                FROM admin AS a
                LEFT JOIN api_perm AS p ON p.admin_id = a.admin_id
                WHERE a.admin_type = \'user\' AND a.created_by = ?
                ORDER BY a.admin_name
            ',
            array($this->allowedByDefault ? 1 : 0, $resellerId)
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Whether $adminId is among $resellerId's own customers.
     *
     * The cross-tenant guard (§6.2): a reseller may act only on their own
     * customers, and anything else must be indistinguishable from a customer
     * that does not exist at all.
     */
    public function isCustomerOf(int $resellerId, int $adminId): bool
    {
        foreach ($this->customersOf($resellerId) as $customer) {
            if (intval($customer['admin_id']) === $adminId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grant or withdraw API access for an account.
     *
     * Withdrawing revokes that account's tokens too (§6.2): a feature and its
     * effects should go away together.
     */
    public function setApiAccess(int $adminId, bool $allowed): void
    {
        call_user_func(
            $this->query,
            '
                INSERT INTO api_perm (admin_id, allowed) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)
            ',
            array($adminId, $allowed ? 1 : 0)
        );

        if (!$allowed) {
            $this->tokens->revokeAllFor($adminId);
        }
    }
}
