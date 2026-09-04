<?php
namespace SGW_GraphQL\Frontend;
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

/**
 * The token service, wired to the panel's database connection.
 */
function tokenService(): TokenService
{
    static $service = null;

    if ($service === null) {
        \iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL::loadVendor();
        $service = TokenService::fromPanel();
    }

    return $service;
}

/**
 * The token the API's session-authentication path requires.
 *
 * A cookie is CSRF-exposed, so a session-authenticated API call must also
 * carry a header a cross-origin attacker cannot read.
 */
function csrfToken(): string
{
    if (empty($_SESSION['graphql_csrf'])) {
        $_SESSION['graphql_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['graphql_csrf'];
}

/**
 * A timestamp as the panel shows dates, or a dash.
 */
function formatWhen(?int $timestamp): string
{
    return $timestamp === null ? '—' : date('Y-m-d H:i', $timestamp);
}

/**
 * A user-controlled string, safe to assign into a template.
 *
 * tohtml() escapes the HTML metacharacters but leaves braces alone, and
 * TemplateEngine::substitute_dynamic() rescans its own output from before the
 * text it just inserted (see its "new value may also begin with '{'" comment),
 * so a value containing a placeholder is expanded again — and a value that
 * substitutes to itself never terminates.
 */
function safeText(string $value): string
{
    return str_replace(array('{', '}'), '', tohtml($value));
}

/**
 * As safeText(), for a value assigned into an HTML attribute.
 */
function safeAttr(string $value): string
{
    return str_replace(array('{', '}'), '', tohtml($value, 'htmlAttr'));
}

/**
 * Resolve a create request's ttl_days field against the panel's configured
 * default and cap.
 *
 * A missing or empty field means "use the account's configured default", not
 * "never expires" — treating it as null would let a crafted request that
 * simply omits the field mint a never-expiring token, bypassing the cap
 * entirely. The bound is enforced unconditionally on the resolved value, so a
 * default that itself exceeds a cap since lowered is also caught.
 *
 * @param mixed $raw The submitted value, or null/absent.
 * @return int|null The resolved TTL in days, or NULL when it falls outside
 *                   [1, $maxDays].
 */
function resolveTtlDays($raw, int $defaultDays, int $maxDays): ?int
{
    $raw = is_string($raw) ? trim($raw) : '';
    $ttlDays = $raw === '' ? $defaultDays : intval($raw);

    return ($ttlDays >= 1 && $ttlDays <= $maxDays) ? $ttlDays : null;
}

/**
 * Human-readable state for one token.
 *
 * @return array{label: string, icon: string}
 */
function tokenState(\iMSCP\Plugin\SGW_GraphQL\Auth\Token $token): array
{
    if ($token->getRevokedAt() !== null) {
        return array('label' => tr('Revoked'), 'icon' => 'disabled');
    }

    if ($token->getExpiresAt() !== null && $token->getExpiresAt() <= time()) {
        return array('label' => tr('Expired'), 'icon' => 'disabled');
    }

    return array('label' => tr('Active'), 'icon' => 'ok');
}
