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

use iMSCP\Plugin\SGW_GraphQL\Auth\AccessService;
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
 * The reseller/customer access service, wired to the panel's database
 * connection.
 */
function accessService(): AccessService
{
    static $service = null;

    if ($service === null) {
        \iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL::loadVendor();
        $service = AccessService::fromPanel();
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
 * Which switch keeps the in-panel explorer off, or null when it is on.
 *
 * Two keys have to be true for the explorer to work, and they are true for
 * different reasons. `explorer` is the operator's decision about whether a
 * developer tool belongs on this production panel, and it is off by default.
 * `introspection` is about the API itself; an explorer without it is a broken
 * tool rather than a reduced one, because GraphiQL's documentation pane, its
 * completions and its validation are all the introspection query.
 *
 * Before this existed the pages gated on `introspection` alone, which
 * defaults to true - so a stock install shipped the explorer *enabled* while
 * `config.php`, `CHANGELOG.md`, `docs/API.md` and the specification all said
 * it was off by default, and an operator who followed them and set
 * `'explorer' => false` changed nothing at all (checkpoint E, finding E4).
 *
 * `explorer` is named first when both are off: it is the switch an operator
 * reaches for, and naming `introspection` there would send them to turn on
 * something they may deliberately have turned off.
 *
 * @return string|null The config key that is off, or null when neither is.
 */
function explorerDisabledBy(bool $explorerEnabled, bool $introspectionEnabled): ?string
{
    if (!$explorerEnabled) {
        return 'explorer';
    }

    if (!$introspectionEnabled) {
        return 'introspection';
    }

    return null;
}

/**
 * Every key `config.php` declares, in the order it declares them, so an
 * administrator reading the audit page's "Effective configuration" sees
 * exactly what the plugin would read if it consulted `config.php` right now -
 * including any override an installation has layered over the shipped
 * default.
 *
 * The promise in that first sentence is the whole point of the list, and it
 * was quietly broken once: the wave that added `trusted_clients`,
 * `rate_limit_queries_trusted` and `rate_limit_mutations_trusted` did not add
 * them here, so the one page an administrator uses to see who is exempt from
 * the ordinary rate limits did not show the exemption list (checkpoint E,
 * finding E6). CommonTest asserts this list against config.php's own keys, so
 * the next key added without a line here fails the suite.
 *
 * @return string[]
 */
function configKeys(): array
{
    return array(
        'endpoint', 'schema_endpoint', 'allowed_by_default', 'require_tls',
        'trusted_proxies', 'allowed_origins', 'allow_session_auth',
        'allow_password_grant', 'explorer', 'token_default_ttl_days',
        'token_max_ttl_days', 'token_max_per_account', 'introspection',
        'max_query_depth', 'max_query_complexity', 'max_page_size',
        'rate_limit_queries', 'rate_limit_mutations', 'rate_limit_token_issue',
        'trusted_clients', 'rate_limit_queries_trusted',
        'rate_limit_mutations_trusted', 'audit', 'audit_retention_days',
        'debug', 'validate_ftp_home_dir'
    );
}

/**
 * A config value, formatted for display - not for any consumer that would
 * have to parse it back.
 *
 * The is_array() branch is what renders the address lists - 'trusted_proxies',
 * 'allowed_origins' and 'trusted_clients' - and '(none)' is the honest
 * rendering of the empty default each of them ships with.
 *
 * @param mixed $value
 * @return string
 */
function formatConfigValue($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_array($value)) {
        return $value === array() ? '(none)' : implode(', ', $value);
    }

    return (string)$value;
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
 * As safeText(), for a value assigned into an inline `<script>` block's
 * single-quoted string literal - the API explorer's endpoint and CSRF token
 * (see api_explorer.php in each role directory).
 *
 * tojs() (Zend Escaper's escapeJs()) escapes the characters that would break
 * out of the string literal; the brace strip is the same defence safeText()
 * and safeAttr() carry, for the same reason.
 */
function safeJs(string $value): string
{
    return str_replace(array('{', '}'), '', tojs($value));
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

/**
 * Every customer of a reseller, with their API access and live token count.
 *
 * A customer with no api_perm row falls back to the plugin's configured
 * 'allowed_by_default' (§6.2) — the same default
 * SGW_GraphQL::customerHasApiAccess() applies when actually enforcing
 * access, so the grid never shows "Allowed" for a customer the API would
 * refuse, or vice versa.
 *
 * A thin wrapper over AccessService, exactly as tokenService()'s callers use
 * TokenService directly — kept as a function here because every existing
 * caller already does `use function ... \customersOf;`.
 *
 * @return array Rows of admin_id, admin_name, allowed, live_tokens
 */
function customersOf(int $resellerId): array
{
    return accessService()->customersOf($resellerId);
}

/**
 * Whether $adminId is among $resellerId's own customers.
 *
 * The cross-tenant guard (§6.2): a reseller may act only on their own
 * customers, and anything else must be indistinguishable from a customer
 * that does not exist at all. See AccessServiceTest.php for the cover this
 * used to have only as a manual box run.
 */
function isCustomerOf(int $resellerId, int $adminId): bool
{
    return accessService()->isCustomerOf($resellerId, $adminId);
}

/**
 * Grant or withdraw API access for an account.
 *
 * Withdrawing revokes that account's tokens too: a feature and its effects
 * should go away together.
 */
function setApiAccess(int $adminId, bool $allowed): void
{
    accessService()->setApiAccess($adminId, $allowed);
}
