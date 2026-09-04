# SGW_GraphQL — Phase 0–1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` to implement this plan task-by-task. Inline execution is **not** permitted for this plan — see [Execution mandate](#execution-mandate). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A deployed i-MSCP plugin whose `POST /api/graphql` answers `{ viewer { username role } }` for a bearer token, with the token table, the middleware stack and the token-management UI in place.

**Architecture:** An i-MSCP plugin (`SGW_GraphQL`) that registers a Slim 3 route through the plugin API's `getRoutes()`. Requests carry an opaque bearer token, looked up by prefix and verified by SHA-256 against a plugin-owned table. The schema is SDL, loaded with `BuildSchema::build()` and cached as a parsed AST on disk; resolvers attach through a map keyed by `Type.field`. The authenticated identity is threaded explicitly through the plugin's own code, and additionally mirrored into the four `$_SESSION` keys that i-MSCP's own helpers read.

**Tech Stack:** PHP 7.4, `webonyx/graphql-php ^15`, Slim 3 (supplied by the panel), PHPUnit ^9.6, MariaDB, i-MSCP plugin API 1.5.1.

**Spec:** [`docs/SPECIFICATION.md`](../../SPECIFICATION.md) — read §2, §3, §4, §5, §6, §9, §13, §14, §15, §17 before starting. This plan argues from that document and cites it throughout.

---

## Scope

Specification A defines eight phases. This plan covers **phase 0 and phase 1 only**, which together produce working, testable software: a plugin that installs, a live authenticated endpoint, and a UI for managing the credentials it accepts. Phase 1 is deliberately disproportionate in the spec (§19) because it resolves every integration unknown on the smallest possible query; this plan preserves that.

Follow-on plans, not covered here:

| Plan | Spec phase | Deliverable |
| --- | --- | --- |
| 2 | 2 | Read model — the whole customer graph, Anorm models, batch loading |
| 3 | 3 | Customer mutations — subdomain, alias, mail, FTP, SQL, DNS |
| 4 | 4–7 | Reseller and administrator mutations, hardening, release |

The core-improvement backlog (spec §21, items C1–C6) is separate work in `saygoweb/imscp` and is not planned here.

---

## Global Constraints

Copied verbatim from the specification. **Every task's requirements implicitly include this section.**

- **PHP 7.4.x.** Typed properties, arrow functions and `??=` are available. Constructor promotion, `match`, enums, named arguments, nullsafe calls and union types are **not**. (Spec §2.5)
- **All plugin source must also lint under PHP 8.3.** Nothing removed in PHP 8.0 (curly-brace string offsets, `each()`, `create_function()`, `money_format()`). Task 3 enforces this and every later task must keep it green. (Spec §2.5)
- **Namespace `iMSCP\Plugin\SGW_GraphQL`**, PSR-4 under `plugins/SGW_GraphQL/`. See [Deviation from spec §13](#deviation-from-spec-13) below.
- **Plugin API `1.5.1`**, declared in `info.php` as `require_api`.
- **Two runtime dependencies only:** `webonyx/graphql-php ^15`, `saygoweb/anorm ^3.1`. Anorm is unused until plan 2 but is vendored now so the archive shape is settled. No other runtime dependency without amending the spec. (Spec §3.3)
- **Composer resolves with `config.platform.php = "7.4.33"`.** The archive must never be built against a newer PHP than the panel runs. (Spec §3.3)
- **Licence header on every PHP file**, GPL-2.0-or-later, copyright `2026 Cambell Prince <cambell.prince@gmail.com>`, matching `SGW_ApacheCache` exactly.
- **British spelling** in all user-facing strings and comments (`authorise`, `behaviour`, `licence` as noun).
- **`GET` is never accepted** for any API operation, including queries. (Spec §4)
- **Unreachable objects return `NOT_FOUND`, never `FORBIDDEN`.** (Spec §6.3)
- **Passwords and token secrets are never logged, echoed, or returned** after the single creation response. (Spec §11, §12)
- **Tables are named `api_token`, `api_perm`, `api_audit`** — not `graphql_*`. (Amended from spec §15 for consistency with the endpoint name.)

### Deviation from spec §13

Spec §13 shows plugin classes under `src/`. The panel's autoloader maps `iMSCP\Plugin\ => plugins/` (`gui/composer.json`), so `iMSCP\Plugin\SGW_GraphQL\Auth\TokenService` resolves to `plugins/SGW_GraphQL/Auth/TokenService.php` — **not** `plugins/SGW_GraphQL/src/Auth/TokenService.php`. Using `src/` would require the bundled autoloader to shadow a prefix the panel already claims, which works only because Composer chains on a miss and is fragile.

**Decision: drop the `src/` level.** Classes sit directly under the plugin directory in directories matching their namespace. This is the layout `SGW_ApacheCache` uses. Update spec §13 when this plan lands.

### Model assignment

Each task names the model to run it under. The principle is **ambiguity and blast radius, not line count**: this plan specifies the code, so most tasks are transcription that a small model does reliably. The tasks needing the strongest model are the two that interact with an environment this plan cannot fully predict.

| Model | Used for | Tasks |
| --- | --- | --- |
| `haiku` | Pure functions with the tests written out, config files, docs. No i-MSCP API surface, no security decisions. | 3, 5, 6, 7, 17 |
| `sonnet` | Everything touching i-MSCP conventions, authentication, templates, or multi-file wiring. The default. | 2, 4, 8, 9, 10, 11, 12, 13, 14, 15 |
| `opus` | Tasks whose success depends on a live system behaving as measured rather than on following this plan. | 1, 16 |

### Execution mandate

Every task runs in a **fresh subagent** via `superpowers:subagent-driven-development`, at the model named in the task. Do not execute tasks inline in the orchestrating session. Between tasks the orchestrator reviews the diff against the task's Interfaces block before dispatching the next.

Dispatch each subagent with: the task text, the Global Constraints section, and the file path of the spec. A subagent sees only its own task, which is why every task carries an Interfaces block naming the exact signatures its neighbours rely on.

---

## File Structure

Created by this plan, in `saygoweb/imscp-graphql` unless noted.

| File | Responsibility |
| --- | --- |
| `info.php` | Plugin metadata, version, `require_api`, `db_schema_version` |
| `config.php` | Configuration defaults (spec §14) |
| `SGW_GraphQL.php` | Plugin class: install/uninstall/update, routes, navigation, event listeners |
| `composer.json` | Runtime and dev dependencies, platform pin |
| `sql/001_create_api_tables.php` | `api_token`, `api_perm`, `api_audit` |
| `schema/schema.graphql` | The SDL. Phase 1 carries the `viewer` slice only |
| `Support/GlobalId.php` | Opaque type-tagged identifier codec (spec §3.5) |
| `Support/Provisioning.php` | i-MSCP status string → `ProvisioningState` (spec §7.1) |
| `Support/ApiException.php` | Base exception carrying a spec §9 error code |
| `Support/ErrorFactory.php` | Exception → GraphQL error `extensions` |
| `Auth/Identity.php` | Authenticated identity value object |
| `Auth/IdentityShim.php` | Mirrors an Identity into the `$_SESSION` keys core reads (spec §6.4) |
| `Auth/Token.php` | One `api_token` row as an object |
| `Auth/TokenService.php` | Issue, verify, revoke, list, stamp last-used |
| `Auth/Scope.php` | The scope vocabulary (spec §7.2) |
| `Schema/SchemaFactory.php` | SDL load, AST cache, resolver map attachment |
| `Schema/ResolverMap.php` | `Type.field` → callable |
| `Resolver/ViewerResolver.php` | `Query.viewer` |
| `Http/TlsMiddleware.php` | Refuse plaintext; revoke a token sighted on one (spec §4) |
| `Http/CorsMiddleware.php` | Origin allow-list, preflight |
| `Http/AuthenticateMiddleware.php` | Bearer token or panel session → Identity |
| `Http/GraphQLHandler.php` | Parse, execute, serialise, shutdown guard |
| `frontend/common.php` | Shared helpers for the plugin's panel pages |
| `frontend/client/api_tokens.php` | Customer token management |
| `frontend/reseller/api_tokens.php` | Reseller token management |
| `frontend/reseller/api_access.php` | Grant/withdraw API access per customer |
| `themes/default/view/client/api_tokens.tpl` | |
| `themes/default/view/reseller/api_tokens.tpl` | |
| `themes/default/view/reseller/api_access.tpl` | |
| `l10n/en_GB.php` | Translations |
| `test/lint/all.sh` | Dual-version lint + CORE-DEBT marker check |
| `test/unit/…` | PHPUnit unit tests |
| `test/api/smoke.sh` | Integration smoke test against the box |
| `tools/version.php` | Version bump (copied from `SGW_ApacheCache`) |
| `makefile.json`, `upload-exclude.txt` | Packaging |
| **`../imscp/configs/debian/default/frontend/frontend.data.dist`** | PHP 7.4 (Task 1) |
| **`../imscp/engine/PerlLib/iMSCP/Requirements.pm`** | PHP 7.4 gate (Task 1) |
| **`../imscp/test/panel-sweep.sh`** | Panel page sweep regression test (Task 1) |

---

## Task 1: Move the panel to PHP 7.4 — `opus`

Repository: **`saygoweb/imscp`**, not this one. Branch from `main`.

The measurement behind this task: the unmodified panel with its unmodified 7.3-era dependency tree renders 21 of 21 pages on PHP 7.4 with zero deprecations (spec Appendix A). If reality disagrees with that measurement, **stop and report** rather than patching around it — the plan's dependency decisions rest on it.

**Files:**
- Modify: `configs/debian/default/frontend/frontend.data.dist:26`
- Modify: `engine/PerlLib/iMSCP/Requirements.pm:129-130`
- Create: `test/panel-sweep.sh`

**Interfaces:**
- Consumes: nothing.
- Produces: a panel running PHP 7.4 in the Vagrant box, and `test/panel-sweep.sh` as a committed regression test. Every later task assumes `php7.4` is the panel's PHP.

- [ ] **Step 1: Write the failing sweep test**

Create `test/panel-sweep.sh`. It logs in as each account type, renders every significant page, and fails on any panel error page, PHP fatal or deprecation.

```bash
#!/bin/sh
# Render every significant panel page as each account type and fail on any
# error page, fatal or deprecation. This is the regression test for a PHP
# version change: it is what catches a deprecation that the panel's exception
# handler turns into a fatal error page.
#
# Run inside the box:  sudo test/panel-sweep.sh [php-binary]
set -e

PHP=${1:-php7.4}
GUI=/var/www/imscp/gui
PORT=8099
LOG=/tmp/panel-sweep.log
FAILED=0

[ "$(id -u)" -eq 0 ] || { echo "$0: must be run as root" >&2; exit 1; }

# A test-only entry point that adopts an existing identity, so the sweep does
# not need anybody's password. Removed again at the end.
cat > "$GUI/public/_sweep_login.php" <<'PHP'
<?php
require_once 'imscp-lib.php';
use iMSCP\Authentication\AuthService;
$type = isset($_GET['as']) ? $_GET['as'] : 'admin';
$i = exec_query(
    'SELECT admin_id, admin_name, admin_pass, admin_type, email, created_by
     FROM admin WHERE admin_type = ? ORDER BY admin_id LIMIT 1',
    [$type]
)->fetchRow(PDO::FETCH_OBJ);
if (!$i) { http_response_code(500); die("no $type in database"); }
AuthService::getInstance()->setIdentity($i);
$g = exec_query('SELECT lang, layout FROM user_gui_props WHERE user_id = ?', [$i->admin_id])
    ->fetchRow(PDO::FETCH_ASSOC);
$_SESSION['user_def_lang']    = isset($g['lang']) ? $g['lang'] : 'en_GB';
$_SESSION['user_theme']       = isset($g['layout']) ? $g['layout'] : 'default';
$_SESSION['user_theme_color'] = 'black';
echo "SESSION OK {$i->admin_name}";
PHP

cat > /tmp/sweep-router.php <<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = '/var/www/imscp/gui/public' . $p;
if ($p !== '/' && file_exists($f) && !is_dir($f)) {
    if (substr($f, -4) === '.php') { require $f; return true; }
    return false;
}
require '/var/www/imscp/gui/public/' . ($p === '/' ? 'index.php' : 'plugins.php');
return true;
PHP

cleanup() {
    kill "$SRV" 2>/dev/null || true
    rm -f "$GUI/public/_sweep_login.php" /tmp/sweep-router.php /tmp/sweep-ck.*
    mysql -e "DELETE FROM imscp.login WHERE ipaddr = '127.0.0.1';" 2>/dev/null || true
}
trap cleanup EXIT

rm -rf "$GUI/data/cache"; mkdir -p "$GUI/data/cache"; chmod 777 "$GUI/data/cache"

$PHP -d error_reporting=E_ALL -d display_errors=1 \
     -d include_path=".:$GUI/library:/usr/share/php" \
     -S 127.0.0.1:$PORT -t "$GUI/public" /tmp/sweep-router.php > "$LOG" 2>&1 &
SRV=$!
sleep 3

sweep() {
    role=$1; shift
    ck=/tmp/sweep-ck.$role
    curl -sf -c "$ck" "http://127.0.0.1:$PORT/_sweep_login.php?as=$role" >/dev/null \
        || { echo "  FAIL  could not establish a $role session"; FAILED=1; return; }
    for p in "$@"; do
        title=$(curl -s -b "$ck" -c "$ck" "http://127.0.0.1:$PORT/$p" \
                | grep -oE '<title>[^<]*</title>' | head -1 | sed 's/<[^>]*>//g')
        case "$title" in
            *"Fatal Error"*|"") echo "  FAIL  $role $p"; FAILED=1 ;;
            *)                  echo "  ok    $role $p" ;;
        esac
    done
}

echo "Panel sweep under $($PHP -r 'echo PHP_VERSION;')"
sweep admin admin/index.php admin/users.php admin/settings.php \
             admin/system_info.php admin/server_ips.php
sweep reseller reseller/index.php reseller/users.php reseller/user_add1.php \
                reseller/hosting_plan.php
sweep user client/index.php client/domains_manage.php client/subdomain_add.php \
            client/alias_add.php client/mail_accounts.php client/mail_add.php \
            client/sql_manage.php client/sql_database_add.php \
            client/ftp_accounts.php client/ftp_add.php client/profile.php

echo
echo "Deprecations and warnings:"
if grep -aoE "(Deprecated|Warning|Fatal error):.{0,110}" "$LOG" | sort -u | grep .; then
    FAILED=1
else
    echo "  none"
fi

[ "$FAILED" -eq 0 ] && echo "PASS" || echo "FAIL"
exit "$FAILED"
```

- [ ] **Step 2: Run the sweep on the current PHP 7.3 panel to establish the baseline**

```bash
cd ../imscp/Vagrant && vagrant ssh imscp_debian_trixie -c \
  'sudo /usr/local/src/imscp/test/panel-sweep.sh php7.3'
```

Expected: `PASS`, 20 lines of `ok`. If it does not pass on 7.3, the test is wrong, not the panel — fix the test before going further.

- [ ] **Step 3: Run the sweep on PHP 7.4, unmodified**

```bash
vagrant ssh imscp_debian_trixie -c \
  'sudo /usr/local/src/imscp/test/panel-sweep.sh php7.4'
```

Expected: `PASS`, identical output. This is the measurement this task exists to confirm. **If it fails, stop and report** — do not patch the panel to make it pass; the plan's premise needs revisiting.

- [ ] **Step 4: Switch the panel's PHP version**

In `configs/debian/default/frontend/frontend.data.dist`, line 26:

```
PHP_FPM_BIN_PATH = /usr/sbin/php-fpm7.4
```

In `engine/PerlLib/iMSCP/Requirements.pm`, in the `php` entry of `$self->{'programs'}`:

```perl
            min_version     => '7.4.0',
            max_version     => '7.4.999',
```

Leave the `modules` list alone. `apc` stays required: it exists for PHP 7.4, and removing it is PHP 8.3 work, not this task.

- [ ] **Step 5: Apply it to the box and verify the running panel**

```bash
vagrant ssh imscp_debian_trixie -c 'sudo perl /usr/local/src/imscp/imscp-autoinstall --debug --verbose --preseed /tmp/preseed.pl'
vagrant ssh imscp_debian_trixie -c 'sudo /usr/local/sbin/imscp_panel -v | head -1'
```

Expected: `PHP 7.4.x (fpm-fcgi)`.

Then browse the panel at `https://panel.<hostname>:8443` and log in as the administrator. Expected: the dashboard renders.

- [ ] **Step 6: Re-run the sweep against the now-7.4 panel**

```bash
vagrant ssh imscp_debian_trixie -c 'sudo /usr/local/src/imscp/test/panel-sweep.sh'
```

Expected: `PASS` with no argument, since `php7.4` is now the default the script uses.

- [ ] **Step 7: Commit**

```bash
cd ../imscp
git add test/panel-sweep.sh configs/debian/default/frontend/frontend.data.dist \
        engine/PerlLib/iMSCP/Requirements.pm
git commit -m "frontEnd: run the panel on PHP 7.4

The panel has run on PHP 7.3 since Debian 9. 7.3 has been end of life since
December 2021 and reaches Debian 13 only through packages.sury.org.

7.4 needs no other change: the unmodified frontEnd and its unmodified
dependency tree render all 21 pages of the new sweep with no deprecation and
no fatal. That sweep is committed here as the regression test, because a PHP
version change surfaces as a deprecation that the panel's exception handler
turns into a fatal error page, which no syntax check would find.

7.4 is an interim. 8.3 is the destination and is separate work."
```

---

## Task 2: Plugin skeleton that installs and uninstalls — `sonnet`

**Files:**
- Create: `info.php`, `config.php`, `SGW_GraphQL.php`, `sql/001_create_api_tables.php`, `l10n/en_GB.php`, `upload-exclude.txt`, `makefile.json`
- Create: `tools/version.php` (copy verbatim from `../imscp-apache-cache/tools/version.php`, changing only the plugin name in its docblock)

**Interfaces:**
- Consumes: a panel on PHP 7.4 (Task 1).
- Produces:
  - class `iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL extends AbstractPlugin`
  - `SGW_GraphQL::customerHasApiAccess(int $adminId): bool` — static, cached per request
  - tables `api_token`, `api_perm`, `api_audit` with the columns given in Step 3
  - `config.php` keys, read via `$plugin->getConfigParam('key', $default)`

- [ ] **Step 1: Write `info.php`**

```php
<?php
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

return array(
    'author'      => 'Cambell Prince',
    'email'       => 'cambell.prince@gmail.com',
    'version'     => '0.1.0',
    'require_api' => '1.5.1',
    'date'        => '2026-09-04',
    'name'        => 'SGW_GraphQL',
    'desc'        => 'A GraphQL API for i-MSCP, authenticated as the panel user.',
    'url'         => 'https://github.com/saygoweb/imscp-graphql'
);
```

Use this licence header, verbatim, at the top of every PHP file this plan creates. It is not repeated in later steps.

- [ ] **Step 2: Write `config.php`**

Spec §14, with the key names amended to drop the `graphql_` prefix where redundant.

```php
<?php
// ... licence header ...

return array(
    'endpoint'                => '/api/graphql',
    'schema_endpoint'         => '/api/graphql/schema',

    // Customers and resellers may use the API unless a reseller or an
    // administrator withdraws it. Mirrors SGW_ApacheCache's permission model.
    'allowed_by_default'      => true,

    // Transport
    'require_tls'             => true,
    'allowed_origins'         => array(),   // never '*'
    'allow_session_auth'      => true,
    'allow_password_grant'    => true,

    // Tokens
    'token_default_ttl_days'  => 365,
    'token_max_ttl_days'      => 730,
    'token_max_per_account'   => 10,

    // Query cost
    'introspection'           => true,
    'max_query_depth'         => 15,
    'max_query_complexity'    => 1000,
    'max_page_size'           => 200,

    // Rate limits, per minute. Enforced from plan 4; the keys exist now so
    // that operators who set them early are not surprised later.
    'rate_limit_queries'      => 120,
    'rate_limit_mutations'    => 30,
    'rate_limit_token_issue'  => 5,

    // Observability
    'audit'                   => 'mutations',   // none | mutations | all
    'audit_retention_days'    => 90,

    // Error detail in responses. Never enable on a production panel.
    'debug'                   => false
);
```

- [ ] **Step 3: Write `sql/001_create_api_tables.php`**

```php
<?php
// ... licence header ...

// Three tables, all owned by the plugin, all dropped on uninstall. No i-MSCP
// table is altered: a plugin that alters core tables cannot be uninstalled
// cleanly.
return array(
    'up'   => "
        CREATE TABLE IF NOT EXISTS `api_token` (
            `token_id`     int(11) unsigned NOT NULL AUTO_INCREMENT,
            `admin_id`     int(11) unsigned NOT NULL,
            `name`         varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            -- Shown in the panel so a user can tell their tokens apart, and
            -- the key the lookup is done on. Not a secret.
            `token_prefix` char(8) COLLATE utf8_unicode_ci NOT NULL,
            `token_hash`   char(64) COLLATE utf8_unicode_ci NOT NULL,
            `scopes`       varchar(1024) COLLATE utf8_unicode_ci NOT NULL,
            -- Comma-separated CIDRs, or NULL for no restriction.
            `ip_allowlist` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
            `created_at`   int(11) unsigned NOT NULL,
            `expires_at`   int(11) unsigned DEFAULT NULL,
            `last_used_at` int(11) unsigned DEFAULT NULL,
            `last_used_ip` varchar(45) COLLATE utf8_unicode_ci DEFAULT NULL,
            `revoked_at`   int(11) unsigned DEFAULT NULL,
            PRIMARY KEY (`token_id`),
            UNIQUE KEY `api_token_prefix` (`token_prefix`),
            KEY `api_token_admin_id` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

        CREATE TABLE IF NOT EXISTS `api_perm` (
            `admin_id` int(11) unsigned NOT NULL,
            `allowed`  tinyint(1) NOT NULL DEFAULT '1',
            PRIMARY KEY (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

        CREATE TABLE IF NOT EXISTS `api_audit` (
            `audit_id`    bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `at`          int(11) unsigned NOT NULL,
            `admin_id`    int(11) unsigned NOT NULL,
            `token_id`    int(11) unsigned DEFAULT NULL,
            `ip`          varchar(45) COLLATE utf8_unicode_ci NOT NULL,
            `operation`   varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `fields`      varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
            -- Variables with every Secret-typed value already replaced.
            `variables`   mediumtext COLLATE utf8_unicode_ci,
            `outcome`     enum('ok','error') COLLATE utf8_unicode_ci NOT NULL,
            `error_code`  varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
            `duration_ms` int(11) unsigned NOT NULL,
            PRIMARY KEY (`audit_id`),
            KEY `api_audit_at` (`at`),
            KEY `api_audit_admin_id` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ",
    'down' => "
        DROP TABLE IF EXISTS `api_audit`;
        DROP TABLE IF EXISTS `api_perm`;
        DROP TABLE IF EXISTS `api_token`;
    "
);
```

- [ ] **Step 4: Write `SGW_GraphQL.php`**

`getRoutes()` returns an empty array for now; Task 13 fills it. The class must install, uninstall and appear in the panel before anything else is built on it.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL;
// ... licence header ...

use iMSCP\Event\Event;
use iMSCP\Event\EventManagerInterface;
use iMSCP\Event\Events;
use iMSCP\Plugin\AbstractPlugin;
use iMSCP\Plugin\PluginException;
use iMSCP\Plugin\PluginManager;
use iMSCP\Registry;
use PDO;

/**
 * A GraphQL API for i-MSCP.
 *
 * The plugin owns an HTTP endpoint rather than a feature: see docs/SPECIFICATION.md.
 */
class SGW_GraphQL extends AbstractPlugin
{
    /**
     * Plugin initialisation
     *
     * @return void
     */
    public function init()
    {
        l10n_addTranslations(__DIR__ . '/l10n', 'Array', $this->getName());
    }

    /**
     * Register event listeners
     *
     * @param EventManagerInterface $eventsManager
     * @return void
     */
    public function register(EventManagerInterface $eventsManager)
    {
        $eventsManager->registerListener(
            array(
                Events::onClientScriptStart,
                Events::onResellerScriptStart,
                // An account that goes away must take its credentials with it.
                Events::onAfterDeleteCustomer,
                Events::onAfterDeleteUser
            ),
            $this
        );
    }

    /**
     * Plugin installation
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @return void
     */
    public function install(PluginManager $pluginManager)
    {
        try {
            $this->migrateDb('up');
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Plugin update
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @param string $fromVersion
     * @param string $toVersion
     * @return void
     */
    public function update(PluginManager $pluginManager, $fromVersion, $toVersion)
    {
        try {
            $this->migrateDb('up');
            $this->clearTranslations();
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Plugin uninstallation
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @return void
     */
    public function uninstall(PluginManager $pluginManager)
    {
        try {
            $this->migrateDb('down');
            $this->clearTranslations();
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * onClientScriptStart event listener
     *
     * @return void
     */
    public function onClientScriptStart()
    {
        if (self::customerHasApiAccess(intval($_SESSION['user_id']))) {
            $this->setupNavigation('client');
        }
    }

    /**
     * onResellerScriptStart event listener
     *
     * @return void
     */
    public function onResellerScriptStart()
    {
        $this->setupNavigation('reseller');
    }

    /**
     * onAfterDeleteCustomer event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteCustomer(Event $event)
    {
        self::forgetAccount(intval($event->getParam('customerId')));
    }

    /**
     * onAfterDeleteUser event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteUser(Event $event)
    {
        self::forgetAccount(intval($event->getParam('userId')));
    }

    /**
     * Get routes
     *
     * Filled in once the endpoint exists.
     *
     * @return array
     */
    public function getRoutes()
    {
        return array();
    }

    /**
     * May the given account use the API at all?
     *
     * Available to everyone unless a reseller or an administrator has
     * explicitly withdrawn it, which is recorded as a row in api_perm.
     *
     * @param int $adminId Account unique identifier
     * @return bool
     */
    public static function customerHasApiAccess($adminId)
    {
        static $hasAccess = array();

        if (!array_key_exists($adminId, $hasAccess)) {
            $stmt = exec_query(
                'SELECT allowed FROM api_perm WHERE admin_id = ?', array($adminId)
            );
            $row = $stmt->fetchRow(PDO::FETCH_ASSOC);
            $hasAccess[$adminId] = ($row === false) ? true : (bool)$row['allowed'];
        }

        return $hasAccess[$adminId];
    }

    /**
     * Drop everything the plugin holds for an account that has been deleted.
     *
     * A withdrawn or deleted account's tokens go with it: a credential and the
     * account it acts as should never outlive one another.
     *
     * @param int $adminId
     * @return void
     */
    protected static function forgetAccount($adminId)
    {
        exec_query('DELETE FROM api_token WHERE admin_id = ?', array($adminId));
        exec_query('DELETE FROM api_perm WHERE admin_id = ?', array($adminId));
    }

    /**
     * Inject links into the navigation object
     *
     * @param string $level UI level (reseller|client)
     * @return void
     */
    protected function setupNavigation($level)
    {
        if (!Registry::isRegistered('navigation')) {
            return;
        }

        /** @var \Zend_Navigation $navigation */
        $navigation = Registry::get('navigation');

        if ($level == 'client') {
            if (($page = $navigation->findOneBy('uri', '/client/profile.php'))) {
                $page->addPage(array(
                    'label'       => tr('API tokens'),
                    'uri'         => '/client/api_tokens.php',
                    'title_class' => 'profile'
                ));
            }
            return;
        }

        if (($page = $navigation->findOneBy('uri', '/reseller/users.php'))) {
            $page->addPage(array(
                'label'              => tr('API access'),
                'uri'                => '/reseller/api_access.php',
                'title_class'        => 'users',
                'privilege_callback' => array('name' => 'resellerHasCustomers')
            ));
        }
        if (($page = $navigation->findOneBy('uri', '/reseller/profile.php'))) {
            $page->addPage(array(
                'label'       => tr('API tokens'),
                'uri'         => '/reseller/api_tokens.php',
                'title_class' => 'profile'
            ));
        }
    }

    /**
     * Clear translations if any
     *
     * @return void
     */
    protected function clearTranslations()
    {
        /** @var \Zend_Translate $translator */
        $translator = Registry::get('translator');

        if ($translator->hasCache()) {
            $translator->clearCache($this->getName());
        }
    }
}
```

- [ ] **Step 5: Write `l10n/en_GB.php`, `upload-exclude.txt` and `makefile.json`**

`l10n/en_GB.php`:

```php
<?php
// ... licence header ...

// The source strings are already British English, so this file exists to give
// Zend_Translate a registered en_GB catalogue rather than to translate.
return array();
```

`upload-exclude.txt`:

```
./.git
./.github
./.gitignore
./.ssh-config
./docs
./test
./tools
./composer.lock
```

`makefile.json`:

```json
{
    "variables": {
        "name": "SGW_GraphQL",
        "sudo": "sudo",
        "bump": "patch"
    },
    "clean": {
        "by": [
            "rm -f ./*.tgz"
        ]
    },
    "test": {
        "by": [
            "sh test/lint/all.sh",
            "vendor/bin/phpunit --configuration test/phpunit.xml"
        ]
    },
    "version": {
        "by": [
            "php tools/version.php {{bump}}"
        ]
    },
    "package": {
        "depends": [
            "clean"
        ],
        "by": [
            "tar -cvzf ./{{name}}.tgz --transform 's|./|{{name}}/|' -X upload-exclude.txt ./*"
        ]
    }
}
```

- [ ] **Step 6: Deploy and install through the panel**

```bash
tools/deploy.sh
```

Then in the panel: *System tools / Plugin management*, **Update plugin list**, then install `SGW_GraphQL`.

Expected: the plugin appears, installs without error, and its status becomes `enabled`.

- [ ] **Step 7: Verify the tables exist and that uninstall removes them**

```bash
cd ../imscp/Vagrant
vagrant ssh imscp_debian_trixie -c \
  "sudo mysql imscp -e 'SHOW TABLES LIKE \"api_%\";'"
```

Expected: `api_audit`, `api_perm`, `api_token`.

Then uninstall the plugin through the panel and re-run the query. Expected: no rows. Then install it again, leaving it installed.

- [ ] **Step 8: Commit**

```bash
git add info.php config.php SGW_GraphQL.php sql l10n makefile.json \
        upload-exclude.txt tools/version.php
git commit -m "Plugin skeleton: install, uninstall, navigation, tables

Three tables owned by the plugin and dropped with it, the permission model
SGW_ApacheCache established, and navigation entries under Profile and
Customers. No endpoint yet: getRoutes() is deliberately empty until there is
something behind it."
```

---

## Task 3: Dual-version lint and CORE-DEBT inventory — `haiku`

**Files:**
- Create: `test/lint/all.sh`

**Interfaces:**
- Consumes: the plugin skeleton (Task 2).
- Produces: `sh test/lint/all.sh`, exit 0 on success. Every later task runs it before committing.

- [ ] **Step 1: Write the lint script**

```bash
#!/bin/sh
# Lint every plugin source file under both PHP 7.4 and PHP 8.3, and report the
# CORE-DEBT inventory.
#
# The dual-version rule is what keeps the panel's later 8.3 migration free for
# this plugin: a construct removed in PHP 8 fails here on the day it is
# written, rather than during the migration.
#
# Run from the plugin root, inside the box:  sh test/lint/all.sh
set -e

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILED=0

lint_one() {
    php=$1
    command -v "$php" >/dev/null 2>&1 || {
        echo "  SKIP  $php not installed"; return
    }
    n=0; bad=0
    for f in $(find "$ROOT" -name '*.php' -not -path '*/vendor/*' -not -path '*/.git/*'); do
        n=$((n + 1))
        if ! "$php" -l "$f" >/dev/null 2>&1; then
            bad=$((bad + 1)); FAILED=1
            echo "  FAIL  $php $(echo "$f" | sed "s|$ROOT/||")"
            "$php" -l "$f" 2>&1 | head -1 | sed 's/^/          /'
        fi
    done
    printf "  %-8s %s files, %s failures\n" "$php" "$n" "$bad"
}

echo "Syntax:"
lint_one php7.4
lint_one php8.3

# Every CORE-DEBT marker must name an item that exists in the specification, so
# that the debt inventory cannot drift from the backlog it points at.
echo
echo "CORE-DEBT inventory:"
markers=$(grep -rhoE 'CORE-DEBT\(C[0-9]+\)' "$ROOT" --include='*.php' 2>/dev/null | sort | uniq -c || true)
if [ -z "$markers" ]; then
    echo "  none"
else
    echo "$markers" | sed 's/^/  /'
    for item in $(grep -rhoE 'CORE-DEBT\(C[0-9]+\)' "$ROOT" --include='*.php' 2>/dev/null \
                  | sed 's/CORE-DEBT(\(C[0-9]*\))/\1/' | sort -u); do
        if ! grep -q "^\*\*$item — " "$ROOT/docs/SPECIFICATION.md" 2>/dev/null; then
            echo "  FAIL  $item is not an item in docs/SPECIFICATION.md section 21"
            FAILED=1
        fi
    done
fi

echo
[ "$FAILED" -eq 0 ] && echo "PASS" || echo "FAIL"
exit "$FAILED"
```

- [ ] **Step 2: Run it and verify it passes on the skeleton**

```bash
tools/deploy.sh && cd ../imscp/Vagrant && \
  vagrant ssh imscp_debian_trixie -c 'cd /var/www/imscp/gui/plugins/SGW_GraphQL && sh test/lint/all.sh'
```

Note `test/` is excluded from deployment, so run it from a working copy instead — push the tree with `rsync` or run it on the host if both `php7.4` and `php8.3` are installed there. Expected: `PASS`, with both PHP versions reporting 0 failures.

- [ ] **Step 3: Verify it catches a PHP 8 regression**

Temporarily add to `SGW_GraphQL.php`, inside `init()`:

```php
        $s = 'abc'; $c = $s{0};   // removed in PHP 8.0
```

Run the script. Expected: `FAIL`, with `php8.3` reporting one failure and `php7.4` reporting zero. Then remove the line and confirm `PASS` returns.

- [ ] **Step 4: Verify it catches an unknown CORE-DEBT item**

Temporarily add a comment `// CORE-DEBT(C99): nonsense` to `SGW_GraphQL.php`. Run the script. Expected: `FAIL` naming `C99`. Remove it.

- [ ] **Step 5: Commit**

```bash
git add test/lint/all.sh
git commit -m "test: lint under both PHP 7.4 and 8.3, and inventory CORE-DEBT

The dual-version rule keeps the panel's later 8.3 migration free for this
plugin. The CORE-DEBT check keeps the duplicated-rule inventory honest: a
marker that names an item not in specification section 21 fails the build."
```

---

## Task 4: Composer dependencies and autoloading — `sonnet`

The subtle part is the autoloader. The panel maps `iMSCP\Plugin\ => plugins/`, which already resolves this plugin's own classes; the bundled `vendor/autoload.php` is needed only for `webonyx/graphql-php` and Anorm, and must be registered exactly once.

**Files:**
- Create: `composer.json`, `test/phpunit.xml`, `test/bootstrap.php`

**Interfaces:**
- Consumes: the plugin skeleton (Task 2).
- Produces:
  - `SGW_GraphQL::loadVendor(): void` — idempotent; registers the bundled autoloader
  - `GraphQL\GraphQL`, `GraphQL\Utils\BuildSchema` available to every later task
  - `vendor/bin/phpunit --configuration test/phpunit.xml` runs

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "saygoweb/imscp-graphql",
    "description": "A GraphQL API for i-MSCP, delivered as a plugin",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "authors": [
        {
            "name": "Cambell Prince",
            "email": "cambell.prince@gmail.com"
        }
    ],
    "config": {
        "platform": {
            "php": "7.4.33"
        },
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require": {
        "php": ">=7.4 <8.0",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-pdo": "*",
        "webonyx/graphql-php": "^15.0",
        "saygoweb/anorm": "^3.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6"
    },
    "autoload": {
        "psr-4": {
            "iMSCP\\Plugin\\SGW_GraphQL\\": ""
        },
        "exclude-from-classmap": [
            "/test/",
            "/tools/",
            "/frontend/"
        ]
    }
}
```

The `psr-4` entry maps the plugin's own namespace to its root, which duplicates what the panel's autoloader already does. That is intentional: it lets PHPUnit resolve the classes without the panel bootstrapped.

- [ ] **Step 2: Install the dependencies inside the box**

```bash
cd ../imscp/Vagrant
vagrant ssh imscp_debian_trixie -c 'sudo sh -c "
  cd /tmp && rm -rf gqlvendor && mkdir gqlvendor && cd gqlvendor"'
```

Then, from the plugin root on the host, push the manifest and resolve inside the box so the platform matches:

```bash
rsync -a -e "ssh -F .ssh-config" composer.json imscp_debian_trixie:/tmp/gqlvendor/
ssh -F .ssh-config imscp_debian_trixie \
  'cd /tmp/gqlvendor && COMPOSER_HOME=/tmp/gqlvendor/.composer \
   php7.4 /var/www/imscp/gui/bin/composer.phar update --no-interaction --no-progress'
rsync -a -e "ssh -F .ssh-config" imscp_debian_trixie:/tmp/gqlvendor/vendor/ ./vendor/
rsync -a -e "ssh -F .ssh-config" imscp_debian_trixie:/tmp/gqlvendor/composer.lock ./
```

Expected: `webonyx/graphql-php` at v15.x and `saygoweb/anorm` at v3.1.x. `vendor/` is gitignored; `composer.lock` is committed.

- [ ] **Step 3: Write the failing test for `loadVendor()`**

Create `test/unit/VendorTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test;

use PHPUnit\Framework\TestCase;

class VendorTest extends TestCase
{
    public function testGraphQlLibraryIsAvailable(): void
    {
        self::assertTrue(
            class_exists(\GraphQL\GraphQL::class),
            'webonyx/graphql-php must be reachable through the bundled autoloader'
        );
    }

    public function testAnormIsAvailable(): void
    {
        self::assertTrue(class_exists(\Anorm\Model::class));
    }

    public function testPluginNamespaceResolves(): void
    {
        self::assertTrue(
            class_exists(\iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL::class, false)
                || file_exists(dirname(__DIR__, 2) . '/SGW_GraphQL.php'),
            'the plugin root must map to iMSCP\\Plugin\\SGW_GraphQL\\'
        );
    }
}
```

- [ ] **Step 4: Write `test/phpunit.xml` and `test/bootstrap.php`**

`test/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="unit">
            <directory>unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`test/bootstrap.php`:

```php
<?php
// ... licence header ...

// The unit suite runs without the panel bootstrapped, so only the plugin's own
// autoloader is registered here. Anything needing exec_query() or $_SESSION
// belongs in the integration suite, not this one.
require_once dirname(__DIR__) . '/vendor/autoload.php';
```

- [ ] **Step 5: Run the tests to verify they fail**

```bash
ssh -F .ssh-config imscp_debian_trixie 'cd /tmp/gqlvendor && php7.4 -v'
vendor/bin/phpunit --configuration test/phpunit.xml
```

Expected: FAIL, `Anorm\Model` not found — because `vendor/` has not been copied yet if Step 2 was skipped. If Step 2 ran, all three pass and you may proceed; the point of this step is to confirm the suite actually executes.

- [ ] **Step 6: Add `loadVendor()` to the plugin class**

In `SGW_GraphQL.php`, add the method and call it from `init()`:

```php
    /**
     * Register the bundled Composer autoloader.
     *
     * The panel's own autoloader already resolves this plugin's classes, since
     * it maps iMSCP\Plugin\ onto the plugins directory. This exists only for
     * the two vendored libraries, and must not run more than once per request.
     *
     * @return void
     */
    public static function loadVendor()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $autoload = __DIR__ . '/vendor/autoload.php';

        if (!@is_readable($autoload)) {
            throw new PluginException(
                'The SGW_GraphQL plugin is missing its vendor directory. '
                . 'It must be installed from a release archive, not from a Git checkout.'
            );
        }

        require_once $autoload;
        $loaded = true;
    }
```

And in `init()`, before `l10n_addTranslations`:

```php
        self::loadVendor();
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
vendor/bin/phpunit --configuration test/phpunit.xml
```

Expected: PASS, 3 tests.

- [ ] **Step 8: Verify the panel still loads the plugin**

```bash
tools/deploy.sh
```

Note `tools/deploy.sh` excludes `test/` and `tools/` but **not** `vendor/` — confirm `vendor/` reaches the box, then load the panel dashboard. Expected: no error, the plugin still shows as enabled.

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock test/phpunit.xml test/bootstrap.php \
        test/unit/VendorTest.php SGW_GraphQL.php
git commit -m "Add graphql-php and Anorm, and the unit suite

The panel's autoloader already resolves this plugin's own classes, so the
bundled autoloader exists only for the two vendored libraries and is
registered exactly once per request.

Composer resolves against platform php 7.4.33 so the archive can never be
built against a newer PHP than the panel runs."
```

---

## Task 5: The global identifier codec — `haiku`

Spec §3.5. i-MSCP's identifiers are per-table integers, and `subdomain_id = 3`, `alias_id = 3` and `mail_id = 3` all exist and belong to different customers. Encoding the type into the identifier makes the wrong kind a parse error at the edge, before any resolver runs.

**Files:**
- Create: `Support/GlobalId.php`
- Create: `test/unit/Support/GlobalIdTest.php`

**Interfaces:**
- Consumes: the autoloader (Task 4).
- Produces:
  - `GlobalId::encode(string $type, int $id): string`
  - `GlobalId::decode(string $encoded, ?string $expectedType = null): GlobalId` — throws `InvalidArgumentException`
  - `GlobalId::getType(): string`, `GlobalId::getId(): int`

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GlobalIdTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $encoded = GlobalId::encode('Subdomain', 3);
        $decoded = GlobalId::decode($encoded);

        self::assertSame('Subdomain', $decoded->getType());
        self::assertSame(3, $decoded->getId());
    }

    public function testEncodingIsUrlSafeAndUnpadded(): void
    {
        // Base64url, so it survives a query string and a JSON body untouched.
        $encoded = GlobalId::encode('DomainAlias', 1234567);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
    }

    public function testDifferentTypesWithTheSameIdDoNotCollide(): void
    {
        self::assertNotSame(
            GlobalId::encode('Subdomain', 3),
            GlobalId::encode('DomainAlias', 3)
        );
    }

    public function testDecodeAcceptsTheExpectedType(): void
    {
        $decoded = GlobalId::decode(GlobalId::encode('MailAccount', 9), 'MailAccount');

        self::assertSame(9, $decoded->getId());
    }

    public function testDecodeRejectsTheWrongType(): void
    {
        // The whole point of the codec: a subdomain id supplied where an alias
        // id is expected fails at the edge, not in a resolver.
        $this->expectException(InvalidArgumentException::class);

        GlobalId::decode(GlobalId::encode('Subdomain', 3), 'DomainAlias');
    }

    /**
     * @dataProvider malformedIdentifiers
     */
    public function testDecodeRejectsMalformedInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::decode($input);
    }

    public function malformedIdentifiers(): array
    {
        return [
            'empty'            => [''],
            'not base64'       => ['!!!!'],
            'no separator'     => [self::raw('Subdomain')],
            'empty type'       => [self::raw(':3')],
            'non-numeric id'   => [self::raw('Subdomain:abc')],
            'negative id'      => [self::raw('Subdomain:-1')],
            'zero id'          => [self::raw('Subdomain:0')],
            'bad type chars'   => [self::raw('Sub domain:3')],
            'extra separator'  => [self::raw('Subdomain:3:4')],
            'leading zero'     => [self::raw('Subdomain:03')],
        ];
    }

    private static function raw(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public function testEncodeRejectsANonPositiveId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::encode('Subdomain', 0);
    }

    public function testEncodeRejectsATypeWithASeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::encode('Sub:domain', 1);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter GlobalIdTest`
Expected: FAIL with `Class "iMSCP\Plugin\SGW_GraphQL\Support\GlobalId" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use InvalidArgumentException;

/**
 * An opaque, type-tagged identifier.
 *
 * i-MSCP's identifiers are per-table integers, so subdomain 3, alias 3 and
 * mail account 3 all exist and belong to different customers. Carrying the
 * type inside the identifier turns "the wrong kind of id" into a parse error
 * at the edge of the request, before any resolver has a chance to authorise
 * against the wrong table.
 *
 * The encoding is not a secret and is not claimed to be one. It is a type tag,
 * not a capability.
 */
final class GlobalId
{
    /** @var string */
    private $type;

    /** @var int */
    private $id;

    private function __construct(string $type, int $id)
    {
        $this->type = $type;
        $this->id = $id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function encode(string $type, int $id): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type)) {
            throw new InvalidArgumentException(sprintf(
                'A global identifier type must be an alphanumeric name; got "%s".', $type
            ));
        }

        if ($id < 1) {
            throw new InvalidArgumentException(
                'A global identifier wraps a positive row identifier.'
            );
        }

        return rtrim(strtr(base64_encode($type . ':' . $id), '+/', '-_'), '=');
    }

    /**
     * @param string|null $expectedType Reject any identifier of another type.
     * @throws InvalidArgumentException
     */
    public static function decode(string $encoded, ?string $expectedType = null): self
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($payload === false || substr_count($payload, ':') !== 1) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        list($type, $id) = explode(':', $payload, 2);

        // '03' and '3' must not both decode to 3, or one row would have two
        // identifiers and any cache keyed on the identifier would be wrong.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type)
            || !preg_match('/^[1-9][0-9]*$/', $id)
        ) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        if ($expectedType !== null && $type !== $expectedType) {
            throw new InvalidArgumentException(sprintf(
                'Expected a %s identifier.', $expectedType
            ));
        }

        return new self($type, (int)$id);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter GlobalIdTest`
Expected: PASS, 18 assertions across 9 test methods.

- [ ] **Step 5: Run the lint script**

Run: `sh test/lint/all.sh`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add Support/GlobalId.php test/unit/Support/GlobalIdTest.php
git commit -m "Add the opaque global identifier codec

base64url of Type:id. Decoding validates the type tag, so supplying a
subdomain identifier where an alias is expected fails at the edge rather than
inside a resolver that would then authorise against the wrong table.

Leading zeros are rejected so that one row cannot have two identifiers."
```

---

## Task 6: Provisioning status mapping — `haiku`

Spec §2.1 and §7.1. i-MSCP records intent in a status column and a daemon carries it out afterwards; the API must report that faithfully rather than pretending a mutation completed.

**Files:**
- Create: `Support/Provisioning.php`
- Create: `test/unit/Support/ProvisioningTest.php`

**Interfaces:**
- Consumes: the autoloader (Task 4).
- Produces:
  - `Provisioning::fromStatus(?string $status): Provisioning`
  - `Provisioning::getState(): string` — one of `OK`, `PENDING`, `DISABLED`, `ORDERED`, `ERROR`
  - `Provisioning::getRaw(): string`, `isSettled(): bool`, `getMessage(): ?string`
  - constants `Provisioning::STATE_OK`, `STATE_PENDING`, `STATE_DISABLED`, `STATE_ORDERED`, `STATE_ERROR`

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use PHPUnit\Framework\TestCase;

class ProvisioningTest extends TestCase
{
    /**
     * @dataProvider knownStatuses
     */
    public function testMapsKnownStatuses(?string $status, string $state, bool $settled): void
    {
        $p = Provisioning::fromStatus($status);

        self::assertSame($state, $p->getState());
        self::assertSame($settled, $p->isSettled());
    }

    public function knownStatuses(): array
    {
        return [
            ['ok',          Provisioning::STATE_OK,       true],
            ['disabled',    Provisioning::STATE_DISABLED, true],
            ['ordered',     Provisioning::STATE_ORDERED,  true],
            ['toadd',       Provisioning::STATE_PENDING,  false],
            ['tochange',    Provisioning::STATE_PENDING,  false],
            ['todelete',    Provisioning::STATE_PENDING,  false],
            ['toenable',    Provisioning::STATE_PENDING,  false],
            ['todisable',   Provisioning::STATE_PENDING,  false],
            ['torestore',   Provisioning::STATE_PENDING,  false],
            ['tochangepwd', Provisioning::STATE_PENDING,  false],
        ];
    }

    public function testAnUnknownStatusIsAnErrorCarryingItsText(): void
    {
        // i-MSCP stores the backend's failure text in the status column, so
        // anything outside the vocabulary is a failure report, not a state.
        $p = Provisioning::fromStatus('Could not create the mail directory: disc full');

        self::assertSame(Provisioning::STATE_ERROR, $p->getState());
        self::assertTrue($p->isSettled(), 'a failed item is settled: the backend has stopped');
        self::assertSame('Could not create the mail directory: disc full', $p->getMessage());
    }

    public function testNullIsTreatedAsDisabled(): void
    {
        // A row that has never been configured has no status.
        $p = Provisioning::fromStatus(null);

        self::assertSame(Provisioning::STATE_DISABLED, $p->getState());
        self::assertTrue($p->isSettled());
    }

    public function testRawIsAlwaysThePanelsOwnString(): void
    {
        // The vocabulary is open: plugins and future versions add verbs, and a
        // client that meets an unknown one is better served by the string.
        self::assertSame('toadd', Provisioning::fromStatus('toadd')->getRaw());
        self::assertSame('', Provisioning::fromStatus(null)->getRaw());
    }

    public function testASuccessfulStateCarriesNoMessage(): void
    {
        self::assertNull(Provisioning::fromStatus('ok')->getMessage());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter ProvisioningTest`
Expected: FAIL with `Class "…\Support\Provisioning" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

/**
 * Whether the i-MSCP backend has caught up with the panel's intent.
 *
 * The panel never provisions anything: a write sets a status column to a verb
 * and pokes the daemon, which does the work and then sets the status to 'ok'
 * or to the text of whatever went wrong. So a mutation returns an intent, and
 * this type is how the API says so.
 */
final class Provisioning
{
    const STATE_OK       = 'OK';
    const STATE_PENDING  = 'PENDING';
    const STATE_DISABLED = 'DISABLED';
    const STATE_ORDERED  = 'ORDERED';
    const STATE_ERROR    = 'ERROR';

    /**
     * Statuses the backend consumes as a work queue. An item in one of these
     * is mid-flight and must not be written again.
     */
    const PENDING_STATUSES = array(
        'toadd', 'tochange', 'todelete', 'toenable', 'todisable',
        'torestore', 'tochangepwd', 'topurge'
    );

    /** @var string */
    private $state;

    /** @var string */
    private $raw;

    /** @var string|null */
    private $message;

    private function __construct(string $state, string $raw, ?string $message)
    {
        $this->state = $state;
        $this->raw = $raw;
        $this->message = $message;
    }

    public static function fromStatus(?string $status): self
    {
        $raw = $status === null ? '' : $status;

        if ($status === null || $status === 'disabled') {
            return new self(self::STATE_DISABLED, $raw, null);
        }

        if ($status === 'ok') {
            return new self(self::STATE_OK, $raw, null);
        }

        if ($status === 'ordered') {
            return new self(self::STATE_ORDERED, $raw, null);
        }

        if (in_array($status, self::PENDING_STATUSES, true)) {
            return new self(self::STATE_PENDING, $raw, null);
        }

        // Anything else is the backend's failure text, stored where the status
        // used to be.
        return new self(self::STATE_ERROR, $raw, $status);
    }

    public function getState(): string
    {
        return $this->state;
    }

    /**
     * The literal i-MSCP status string, for support and forward compatibility.
     */
    public function getRaw(): string
    {
        return $this->raw;
    }

    /**
     * Is the backend done with this item?
     *
     * A failed item is settled: the backend has stopped working on it, and a
     * delete is the way out.
     */
    public function isSettled(): bool
    {
        return $this->state !== self::STATE_PENDING;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter ProvisioningTest`
Expected: PASS, 15 test cases.

- [ ] **Step 5: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add Support/Provisioning.php test/unit/Support/ProvisioningTest.php
git commit -m "Map i-MSCP status strings onto a provisioning state

The status vocabulary is open, so an unrecognised value is treated as the
backend's failure text rather than as an unknown state, and the literal string
is always carried alongside so a client can cope with a verb this version has
never seen."
```

---

## Task 7: The error model — `haiku`

Spec §9. Every error carries `extensions.code` from a closed set that is part of the compatibility contract.

**Files:**
- Create: `Support/ApiException.php`, `Support/ErrorCode.php`, `Support/ErrorFactory.php`
- Create: `test/unit/Support/ErrorFactoryTest.php`

**Interfaces:**
- Consumes: the autoloader (Task 4).
- Produces:
  - `ErrorCode::UNAUTHENTICATED|API_ACCESS_WITHDRAWN|FORBIDDEN|NOT_FOUND|BAD_USER_INPUT|LIMIT_EXCEEDED|FEATURE_UNAVAILABLE|CONFLICT|RATE_LIMITED|QUERY_TOO_COMPLEX|INTERNAL` — string constants
  - `ErrorCode::all(): array`, `ErrorCode::httpStatus(string $code): int`
  - `ApiException::__construct(string $code, string $message, array $extensions = [], ?Throwable $previous = null)`
  - `ApiException::getCode(): string` … named `getErrorCode()` to avoid clashing with `Exception::getCode()`
  - `ApiException::getExtensions(): array`
  - `ErrorFactory::format(Throwable $e, bool $debug): array` — returns `['message' => …, 'extensions' => ['code' => …, …]]`

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorFactoryTest extends TestCase
{
    public function testAnApiExceptionKeepsItsCodeMessageAndExtensions(): void
    {
        $e = new ApiException(
            ErrorCode::LIMIT_EXCEEDED, 'Subdomain limit reached.',
            ['limit' => 10, 'used' => 10]
        );

        $formatted = ErrorFactory::format($e, false);

        self::assertSame('Subdomain limit reached.', $formatted['message']);
        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $formatted['extensions']['code']);
        self::assertSame(10, $formatted['extensions']['limit']);
        self::assertSame(10, $formatted['extensions']['used']);
    }

    public function testAnUnexpectedThrowableBecomesInternalWithoutDetail(): void
    {
        // A stack trace or a SQL fragment reaching a client is a disclosure, so
        // the message is fixed and the detail is reachable only by correlation.
        $formatted = ErrorFactory::format(
            new RuntimeException('SQLSTATE[42S02]: table imscp.secret missing'), false
        );

        self::assertSame(ErrorCode::INTERNAL, $formatted['extensions']['code']);
        self::assertStringNotContainsString('SQLSTATE', $formatted['message']);
        self::assertStringNotContainsString('secret', $formatted['message']);
        self::assertArrayHasKey('correlationId', $formatted['extensions']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{16}$/', $formatted['extensions']['correlationId']
        );
    }

    public function testDebugModeRevealsTheUnderlyingMessage(): void
    {
        $formatted = ErrorFactory::format(new RuntimeException('boom'), true);

        self::assertStringContainsString('boom', $formatted['message']);
    }

    public function testDebugModeDoesNotChangeAnApiExceptionsMessage(): void
    {
        // An ApiException's message was written for the caller already.
        $e = new ApiException(ErrorCode::NOT_FOUND, 'No such subdomain.');

        self::assertSame('No such subdomain.', ErrorFactory::format($e, true)['message']);
    }

    public function testEveryCodeHasAnHttpStatus(): void
    {
        foreach (ErrorCode::all() as $code) {
            self::assertIsInt(ErrorCode::httpStatus($code), $code . ' has no HTTP status');
        }
    }

    public function testTheCodesThatAreTransportLevelUseTheirOwnStatus(): void
    {
        self::assertSame(401, ErrorCode::httpStatus(ErrorCode::UNAUTHENTICATED));
        self::assertSame(403, ErrorCode::httpStatus(ErrorCode::API_ACCESS_WITHDRAWN));
        self::assertSame(429, ErrorCode::httpStatus(ErrorCode::RATE_LIMITED));
        self::assertSame(400, ErrorCode::httpStatus(ErrorCode::QUERY_TOO_COMPLEX));
    }

    public function testFieldLevelCodesAreCarriedInsideATwoHundred(): void
    {
        // A query may legitimately be allowed to read some fields and not
        // others, so an authorisation failure is a field error in a 200.
        foreach ([
            ErrorCode::FORBIDDEN, ErrorCode::NOT_FOUND, ErrorCode::BAD_USER_INPUT,
            ErrorCode::LIMIT_EXCEEDED, ErrorCode::FEATURE_UNAVAILABLE,
            ErrorCode::CONFLICT, ErrorCode::INTERNAL
        ] as $code) {
            self::assertSame(200, ErrorCode::httpStatus($code), $code);
        }
    }

    public function testAnUnknownCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiException('NOT_A_REAL_CODE', 'nope');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter ErrorFactoryTest`
Expected: FAIL, classes not found.

- [ ] **Step 3: Write `ErrorCode.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use InvalidArgumentException;

/**
 * The closed set of error codes the API emits.
 *
 * These are part of the compatibility contract: a code is never removed within
 * a major version. See docs/SPECIFICATION.md section 9.
 */
final class ErrorCode
{
    const UNAUTHENTICATED      = 'UNAUTHENTICATED';
    const API_ACCESS_WITHDRAWN = 'API_ACCESS_WITHDRAWN';
    const FORBIDDEN            = 'FORBIDDEN';
    const NOT_FOUND            = 'NOT_FOUND';
    const BAD_USER_INPUT       = 'BAD_USER_INPUT';
    const LIMIT_EXCEEDED       = 'LIMIT_EXCEEDED';
    const FEATURE_UNAVAILABLE  = 'FEATURE_UNAVAILABLE';
    const CONFLICT             = 'CONFLICT';
    const RATE_LIMITED         = 'RATE_LIMITED';
    const QUERY_TOO_COMPLEX    = 'QUERY_TOO_COMPLEX';
    const INTERNAL             = 'INTERNAL';

    /**
     * Codes that end the request before there is a GraphQL result worth
     * returning. Everything else is a field error inside a 200, because a
     * query may be allowed to read some fields and not others.
     */
    const TRANSPORT_STATUS = array(
        self::UNAUTHENTICATED      => 401,
        self::API_ACCESS_WITHDRAWN => 403,
        self::RATE_LIMITED         => 429,
        self::QUERY_TOO_COMPLEX    => 400
    );

    public static function all(): array
    {
        return array(
            self::UNAUTHENTICATED, self::API_ACCESS_WITHDRAWN, self::FORBIDDEN,
            self::NOT_FOUND, self::BAD_USER_INPUT, self::LIMIT_EXCEEDED,
            self::FEATURE_UNAVAILABLE, self::CONFLICT, self::RATE_LIMITED,
            self::QUERY_TOO_COMPLEX, self::INTERNAL
        );
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    public static function httpStatus(string $code): int
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException(sprintf('Unknown error code "%s".', $code));
        }

        return self::TRANSPORT_STATUS[$code] ?? 200;
    }
}
```

- [ ] **Step 4: Write `ApiException.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * An error the caller is meant to see, carrying a specification section 9 code.
 *
 * Anything that is not one of these is an INTERNAL error and its message is not
 * shown to the caller.
 */
class ApiException extends Exception
{
    /** @var string */
    private $errorCode;

    /** @var array */
    private $extensions;

    public function __construct(
        string $code,
        string $message,
        array $extensions = array(),
        ?Throwable $previous = null
    ) {
        if (!ErrorCode::isValid($code)) {
            throw new InvalidArgumentException(sprintf('Unknown error code "%s".', $code));
        }

        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
        $this->extensions = $extensions;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getHttpStatus(): int
    {
        return ErrorCode::httpStatus($this->errorCode);
    }
}
```

- [ ] **Step 5: Write `ErrorFactory.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use Throwable;

/**
 * Turns a throwable into the GraphQL error entry a client receives.
 */
final class ErrorFactory
{
    /**
     * @return array{message: string, extensions: array}
     */
    public static function format(Throwable $e, bool $debug): array
    {
        if ($e instanceof ApiException) {
            return array(
                'message'    => $e->getMessage(),
                'extensions' => array_merge(
                    $e->getExtensions(), array('code' => $e->getErrorCode())
                )
            );
        }

        // An unexpected throwable may carry a SQL fragment, a file path or a
        // stack trace. The caller gets a correlation id; the detail goes to the
        // panel's log, where support can join the two.
        $correlationId = bin2hex(random_bytes(8));

        if (function_exists('write_log')) {
            write_log(
                sprintf(
                    'SGW_GraphQL internal error %s: %s in %s:%d',
                    $correlationId, $e->getMessage(), $e->getFile(), $e->getLine()
                ),
                E_USER_ERROR
            );
        }

        return array(
            'message'    => $debug
                ? sprintf('An internal error occurred: %s', $e->getMessage())
                : 'An internal error occurred.',
            'extensions' => array(
                'code'          => ErrorCode::INTERNAL,
                'correlationId' => $correlationId
            )
        );
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter ErrorFactoryTest`
Expected: PASS, 8 test methods.

- [ ] **Step 7: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add Support/ErrorCode.php Support/ApiException.php Support/ErrorFactory.php \
        test/unit/Support/ErrorFactoryTest.php
git commit -m "Add the error model

A closed set of codes, an exception that carries one, and a formatter that
refuses to let an unexpected throwable's message reach a client. An internal
error yields a correlation id; the detail goes to the panel's log."
```

---

## Task 8: Identity and the session shim — `sonnet`

Spec §6.4. This is the design's named compromise, and the two rules below are what keep it safe. Read §6.4 in full before starting.

**Files:**
- Create: `Auth/Identity.php`, `Auth/IdentityShim.php`
- Create: `test/unit/Auth/IdentityTest.php`

**Interfaces:**
- Consumes: `ApiException`, `ErrorCode` (Task 7).
- Produces:
  - `Identity::__construct(int $adminId, string $username, string $type, ?int $createdBy, ?string $email, array $scopes, ?int $tokenId)`
  - `Identity::getAdminId(): int`, `getUsername(): string`, `getRole(): string`, `getCreatedBy(): ?int`, `getEmail(): ?string`, `getScopes(): array`, `getTokenId(): ?int`
  - `Identity::ROLE_ADMIN|ROLE_RESELLER|ROLE_CUSTOMER` — `'ADMIN'`, `'RESELLER'`, `'CUSTOMER'`
  - `Identity::hasScope(string $scope): bool` — true for every scope when the token carries none recorded
  - `IdentityShim::apply(Identity $identity): void` — throws `ApiException(INTERNAL)` if called twice with different identities
  - `IdentityShim::reset(): void` — test-only

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Auth;

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use PHPUnit\Framework\TestCase;

class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        IdentityShim::reset();
    }

    private function customer(int $id = 7): Identity
    {
        return new Identity($id, 'wpcache.test', 'user', 3, 'c@example.com',
                            ['DOMAINS_READ'], 42);
    }

    public function testMapsTheAdminTypeOntoARole(): void
    {
        self::assertSame(Identity::ROLE_CUSTOMER, $this->customer()->getRole());
        self::assertSame(
            Identity::ROLE_RESELLER,
            (new Identity(3, 'r', 'reseller', 1, null, [], null))->getRole()
        );
        self::assertSame(
            Identity::ROLE_ADMIN,
            (new Identity(1, 'a', 'admin', null, null, [], null))->getRole()
        );
    }

    public function testAnUnknownAdminTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Identity(1, 'x', 'wizard', null, null, [], null);
    }

    public function testScopesNarrowButAnEmptySetDoesNot(): void
    {
        // A token records the scopes it was issued with. A session-authenticated
        // identity records none, and is limited by its role alone.
        self::assertTrue($this->customer()->hasScope('DOMAINS_READ'));
        self::assertFalse($this->customer()->hasScope('MAIL_WRITE'));

        $session = new Identity(7, 'wpcache.test', 'user', 3, null, [], null);
        self::assertTrue($session->hasScope('MAIL_WRITE'));
    }

    public function testTheShimPopulatesExactlyTheKeysCoreReads(): void
    {
        IdentityShim::apply($this->customer());

        self::assertSame(7, $_SESSION['user_id']);
        self::assertSame('user', $_SESSION['user_type']);
        self::assertSame('wpcache.test', $_SESSION['user_logged']);
        self::assertSame(3, $_SESSION['user_created_by']);
        self::assertSame(7, $_SESSION['user_identity']->admin_id);
    }

    public function testTheShimIsIdempotentForTheSameIdentity(): void
    {
        IdentityShim::apply($this->customer());
        IdentityShim::apply($this->customer());

        self::assertSame(7, $_SESSION['user_id']);
    }

    public function testTheShimRefusesToChangeIdentityMidRequest(): void
    {
        // customerHasFeature() caches its answer in a static that is not keyed
        // by user, so a request that switched identity would read the first
        // identity's features for the second.
        IdentityShim::apply($this->customer(7));

        $this->expectException(ApiException::class);

        IdentityShim::apply($this->customer(8));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter IdentityTest`
Expected: FAIL, classes not found.

- [ ] **Step 3: Write `Auth/Identity.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;
// ... licence header ...

use InvalidArgumentException;

/**
 * The account a request acts as, and the authority the presented credential
 * carries.
 *
 * Constructed once per request and threaded explicitly. The role is read from
 * the database on every request rather than carried in the token, so that a
 * demotion takes effect at once.
 */
final class Identity
{
    const ROLE_ADMIN    = 'ADMIN';
    const ROLE_RESELLER = 'RESELLER';
    const ROLE_CUSTOMER = 'CUSTOMER';

    const ROLE_BY_ADMIN_TYPE = array(
        'admin'    => self::ROLE_ADMIN,
        'reseller' => self::ROLE_RESELLER,
        'user'     => self::ROLE_CUSTOMER
    );

    /** @var int */
    private $adminId;

    /** @var string */
    private $username;

    /** @var string */
    private $adminType;

    /** @var int|null */
    private $createdBy;

    /** @var string|null */
    private $email;

    /** @var string[] */
    private $scopes;

    /** @var int|null */
    private $tokenId;

    public function __construct(
        int $adminId,
        string $username,
        string $adminType,
        ?int $createdBy,
        ?string $email,
        array $scopes,
        ?int $tokenId
    ) {
        if (!isset(self::ROLE_BY_ADMIN_TYPE[$adminType])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown admin_type "%s".', $adminType
            ));
        }

        $this->adminId = $adminId;
        $this->username = $username;
        $this->adminType = $adminType;
        $this->createdBy = $createdBy;
        $this->email = $email;
        $this->scopes = $scopes;
        $this->tokenId = $tokenId;
    }

    public function getAdminId(): int
    {
        return $this->adminId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getAdminType(): string
    {
        return $this->adminType;
    }

    public function getRole(): string
    {
        return self::ROLE_BY_ADMIN_TYPE[$this->adminType];
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getTokenId(): ?int
    {
        return $this->tokenId;
    }

    /**
     * Scopes only ever narrow. A credential that records none — a panel
     * session, for instance — is limited by its role alone.
     */
    public function hasScope(string $scope): bool
    {
        return $this->scopes === array() || in_array($scope, $this->scopes, true);
    }
}
```

- [ ] **Step 4: Write `Auth/IdentityShim.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use stdClass;

/**
 * Mirrors an Identity into the four $_SESSION keys i-MSCP's own helpers read.
 *
 * CORE-DEBT(C1): customerHasFeature(), resellerHasFeature() and the delete
 *   helpers read $_SESSION['user_id'] directly, so an API request that has no
 *   session has to fake one. Retire this class when those helpers take an
 *   explicit $adminId. See docs/SPECIFICATION.md section 21, item C1.
 *
 * No 'login' row is written and no browser session is started: this is the
 * shape core expects, not a real sign-in.
 */
final class IdentityShim
{
    /** @var int|null */
    private static $appliedFor = null;

    /**
     * @throws ApiException if a second, different identity is applied.
     */
    public static function apply(Identity $identity): void
    {
        // customerHasFeature() caches its answer in a static that is not keyed
        // by user (gui/include/Client.php:84), so a request that switched
        // identity would read the first identity's features for the second.
        // One identity per request, enforced rather than documented.
        if (self::$appliedFor !== null && self::$appliedFor !== $identity->getAdminId()) {
            throw new ApiException(
                ErrorCode::INTERNAL,
                'An API request may act as one account only.'
            );
        }

        if (self::$appliedFor === $identity->getAdminId()) {
            return;
        }

        $identityObject = new stdClass();
        $identityObject->admin_id = $identity->getAdminId();
        $identityObject->admin_name = $identity->getUsername();
        $identityObject->admin_type = $identity->getAdminType();
        $identityObject->email = $identity->getEmail();
        $identityObject->created_by = $identity->getCreatedBy();

        $_SESSION['user_id']         = $identity->getAdminId();
        $_SESSION['user_type']       = $identity->getAdminType();
        $_SESSION['user_logged']     = $identity->getUsername();
        $_SESSION['user_created_by'] = $identity->getCreatedBy();
        $_SESSION['user_email']      = $identity->getEmail();
        $_SESSION['user_identity']   = $identityObject;

        self::$appliedFor = $identity->getAdminId();
    }

    /**
     * Test-only. There is no production path that unsets an identity.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$appliedFor = null;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter IdentityTest`
Expected: PASS, 6 test methods.

- [ ] **Step 6: Verify the CORE-DEBT marker is recognised**

Run: `sh test/lint/all.sh`
Expected: PASS, and the CORE-DEBT inventory reports one `CORE-DEBT(C1)`.

- [ ] **Step 7: Commit**

```bash
git add Auth/Identity.php Auth/IdentityShim.php test/unit/Auth/IdentityTest.php
git commit -m "Add the request identity and the session shim

The identity is threaded explicitly through the plugin's own code. It is also
mirrored into the four session keys i-MSCP's helpers read, because those
helpers cannot be changed from a plugin — marked CORE-DEBT(C1) so the
duplicate is greppable and retirable.

Changing identity mid-request is refused rather than documented as forbidden:
customerHasFeature() caches in a static that is not keyed by user."
```

---

## Task 9: The token service — `sonnet`

Spec §5.1. Security-critical: read §5.1 and §12 before starting.

**Files:**
- Create: `Auth/Scope.php`, `Auth/Token.php`, `Auth/TokenService.php`
- Create: `test/unit/Auth/ScopeTest.php`, `test/unit/Auth/TokenServiceTest.php`

**Interfaces:**
- Consumes: `Identity` (Task 8), `ApiException`/`ErrorCode` (Task 7).
- Produces:
  - `Scope::all(): array`, `Scope::isValid(string $scope): bool`
  - `TokenService::__construct(callable $query)` — `$query` has the signature of `exec_query($sql, $bind)` and is injected so the unit suite need not bootstrap the panel
  - `TokenService::issue(int $adminId, string $name, array $scopes, ?int $ttlDays, ?string $ipAllowlist): array` — returns `['token' => 'imscp_…', 'tokenId' => int, 'expiresAt' => ?int]`. The plaintext token appears in this return value and nowhere else, ever.
  - `TokenService::verify(string $presented, string $clientIp): ?Token` — null on any failure, without saying which
  - `TokenService::revoke(int $adminId, int $tokenId): bool`
  - `TokenService::listFor(int $adminId): Token[]`
  - `TokenService::splitPresented(string $presented): ?array` — `['prefix' => …, 'secret' => …]`, static
  - `Token::getTokenId(): int`, `getAdminId(): int`, `getScopes(): array`

- [ ] **Step 1: Write the failing tests for `Scope`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Auth;

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    public function testTheVocabularyMatchesTheSpecification(): void
    {
        self::assertSame([
            'ACCOUNT_READ',
            'DOMAINS_READ', 'DOMAINS_WRITE',
            'MAIL_READ', 'MAIL_WRITE',
            'FTP_READ', 'FTP_WRITE',
            'SQL_READ', 'SQL_WRITE',
            'DNS_READ', 'DNS_WRITE',
            'CUSTOMERS_READ', 'CUSTOMERS_WRITE',
            'RESELLERS_READ', 'RESELLERS_WRITE',
        ], Scope::all());
    }

    public function testValidationIsExact(): void
    {
        self::assertTrue(Scope::isValid('DOMAINS_WRITE'));
        self::assertFalse(Scope::isValid('domains_write'));
        self::assertFalse(Scope::isValid('EVERYTHING'));
    }
}
```

- [ ] **Step 2: Write the failing tests for `TokenService`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Auth;

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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter 'ScopeTest|TokenServiceTest'`
Expected: FAIL, classes not found.

- [ ] **Step 4: Write `Auth/Scope.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;
// ... licence header ...

/**
 * The scopes a token may carry. Scopes only ever narrow what the identity
 * could already do; they never widen it.
 */
final class Scope
{
    const ACCOUNT_READ    = 'ACCOUNT_READ';
    const DOMAINS_READ    = 'DOMAINS_READ';
    const DOMAINS_WRITE   = 'DOMAINS_WRITE';
    const MAIL_READ       = 'MAIL_READ';
    const MAIL_WRITE      = 'MAIL_WRITE';
    const FTP_READ        = 'FTP_READ';
    const FTP_WRITE       = 'FTP_WRITE';
    const SQL_READ        = 'SQL_READ';
    const SQL_WRITE       = 'SQL_WRITE';
    const DNS_READ        = 'DNS_READ';
    const DNS_WRITE       = 'DNS_WRITE';
    const CUSTOMERS_READ  = 'CUSTOMERS_READ';
    const CUSTOMERS_WRITE = 'CUSTOMERS_WRITE';
    const RESELLERS_READ  = 'RESELLERS_READ';
    const RESELLERS_WRITE = 'RESELLERS_WRITE';

    public static function all(): array
    {
        return array(
            self::ACCOUNT_READ,
            self::DOMAINS_READ, self::DOMAINS_WRITE,
            self::MAIL_READ, self::MAIL_WRITE,
            self::FTP_READ, self::FTP_WRITE,
            self::SQL_READ, self::SQL_WRITE,
            self::DNS_READ, self::DNS_WRITE,
            self::CUSTOMERS_READ, self::CUSTOMERS_WRITE,
            self::RESELLERS_READ, self::RESELLERS_WRITE
        );
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }
}
```

- [ ] **Step 5: Write `Auth/Token.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;
// ... licence header ...

/**
 * One api_token row. Never carries the secret: only its hash was ever stored.
 */
final class Token
{
    /** @var array */
    private $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function getTokenId(): int
    {
        return (int)$this->row['token_id'];
    }

    public function getAdminId(): int
    {
        return (int)$this->row['admin_id'];
    }

    public function getName(): string
    {
        return (string)$this->row['name'];
    }

    public function getPrefix(): string
    {
        return (string)$this->row['token_prefix'];
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        $scopes = trim((string)$this->row['scopes']);

        return $scopes === '' ? array() : explode(',', $scopes);
    }

    public function getCreatedAt(): int
    {
        return (int)$this->row['created_at'];
    }

    public function getExpiresAt(): ?int
    {
        return $this->row['expires_at'] === null ? null : (int)$this->row['expires_at'];
    }

    public function getLastUsedAt(): ?int
    {
        return $this->row['last_used_at'] === null ? null : (int)$this->row['last_used_at'];
    }

    public function getLastUsedIp(): ?string
    {
        return $this->row['last_used_ip'];
    }

    public function getRevokedAt(): ?int
    {
        return $this->row['revoked_at'] === null ? null : (int)$this->row['revoked_at'];
    }

    public function getIpAllowlist(): ?string
    {
        return $this->row['ip_allowlist'];
    }
}
```

- [ ] **Step 6: Write `Auth/TokenService.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;
// ... licence header ...

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
 */
final class TokenService
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

        list($subnet, $bits) = explode('/', $cidr, 2);
        $bits = (int)$bits;

        $ip = inet_pton($clientIp);
        $net = inet_pton($subnet);

        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
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
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter 'ScopeTest|TokenServiceTest'`
Expected: PASS, 2 + 12 test methods.

- [ ] **Step 8: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add Auth/Scope.php Auth/Token.php Auth/TokenService.php \
        test/unit/Auth/ScopeTest.php test/unit/Auth/TokenServiceTest.php
git commit -m "Add opaque bearer tokens

imscp_<prefix>_<secret>: the prefix is indexed and stored in the clear, only a
SHA-256 of the secret is stored, and the plaintext exists exactly once, in the
creation response.

verify() returns null on every failure without saying which. last_used is
stamped at most once a minute so a read-heavy client does not turn every
request into a write."
```

---

## Task 10: The SDL and the schema factory — `sonnet`

Spec §3.4 and §7. Phase 1 carries only the `viewer` slice; plan 2 grows it.

**Files:**
- Create: `schema/schema.graphql`, `Schema/ResolverMap.php`, `Schema/SchemaFactory.php`
- Create: `test/unit/Schema/SchemaFactoryTest.php`

**Interfaces:**
- Consumes: graphql-php (Task 4).
- Produces:
  - `ResolverMap::__construct(array $map)` where `$map` is `['Query.viewer' => callable, …]`
  - `ResolverMap::for(string $type, string $field): ?callable`
  - `SchemaFactory::__construct(string $sdlPath, ?string $cacheDir, ResolverMap $resolvers)`
  - `SchemaFactory::create(): \GraphQL\Type\Schema`
  - The AST cache file is `<cacheDir>/sgw_graphql_schema.php` and is invalidated by the SDL's mtime.

- [ ] **Step 1: Write `schema/schema.graphql`**

```graphql
# SGW_GraphQL — the i-MSCP API schema.
#
# Phase 1 carries the viewer slice only. See docs/SPECIFICATION.md section 7
# for the full schema this grows into.

schema {
  query: Query
}

"ISO-8601 instant in UTC, e.g. 2026-09-04T11:00:00Z."
scalar DateTime

"An email address."
scalar EmailAddress

"""
A write-only value. Never appears in a response, and is redacted from the audit
log. Passwords are of this type.
"""
scalar Secret

enum Role {
  ADMIN
  RESELLER
  CUSTOMER
}

"""
A scope narrows what a credential may do; it never widens it. New values are
added over time, so a client must tolerate one it has not seen.
"""
enum Scope {
  ACCOUNT_READ
  DOMAINS_READ
  DOMAINS_WRITE
  MAIL_READ
  MAIL_WRITE
  FTP_READ
  FTP_WRITE
  SQL_READ
  SQL_WRITE
  DNS_READ
  DNS_WRITE
  CUSTOMERS_READ
  CUSTOMERS_WRITE
  RESELLERS_READ
  RESELLERS_WRITE
}

"The account this request is acting as."
type Viewer {
  id: ID!
  username: String!
  role: Role!
  email: EmailAddress
  "The scopes the presented credential carries, which may be narrower than the role."
  scopes: [Scope!]!
}

type Query {
  "The schema version this endpoint serves. See docs/SPECIFICATION.md section 18."
  apiVersion: String!
  viewer: Viewer!
}
```

- [ ] **Step 2: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

use GraphQL\GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use PHPUnit\Framework\TestCase;

class SchemaFactoryTest extends TestCase
{
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/sgw-graphql-test-' . getmypid();
        @mkdir($this->cacheDir, 0700, true);
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
    }

    private function sdlPath(): string
    {
        return dirname(__DIR__, 3) . '/schema/schema.graphql';
    }

    private function factory(?string $cacheDir = null): SchemaFactory
    {
        return new SchemaFactory($this->sdlPath(), $cacheDir, new ResolverMap([
            'Query.apiVersion' => static function () { return '1.0.0'; },
            'Query.viewer'     => static function () {
                return [
                    'id' => 'Vmlld2VyOjc', 'username' => 'wpcache.test',
                    'role' => 'CUSTOMER', 'email' => 'c@example.com',
                    'scopes' => ['DOMAINS_READ'],
                ];
            },
        ]));
    }

    public function testTheSchemaIsValid(): void
    {
        $this->factory()->create()->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testAQueryResolvesThroughTheMap(): void
    {
        $result = GraphQL::executeQuery(
            $this->factory()->create(),
            '{ apiVersion viewer { id username role scopes } }'
        )->toArray();

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame('1.0.0', $result['data']['apiVersion']);
        self::assertSame('wpcache.test', $result['data']['viewer']['username']);
        self::assertSame('CUSTOMER', $result['data']['viewer']['role']);
        self::assertSame(['DOMAINS_READ'], $result['data']['viewer']['scopes']);
    }

    public function testAFieldWithNoResolverFallsBackToTheSourceArray(): void
    {
        $result = GraphQL::executeQuery(
            $this->factory()->create(), '{ viewer { email } }'
        )->toArray();

        self::assertSame('c@example.com', $result['data']['viewer']['email']);
    }

    public function testTheAstIsCachedAndReused(): void
    {
        $this->factory($this->cacheDir)->create();

        $cached = glob($this->cacheDir . '/sgw_graphql_schema.php');
        self::assertCount(1, $cached, 'the parsed AST should be written to the cache dir');

        // A second build must not re-parse: prove it by making the cache
        // authoritative, then checking the schema still builds from it.
        $this->factory($this->cacheDir)->create()->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testTheCacheIsInvalidatedWhenTheSdlChanges(): void
    {
        $this->factory($this->cacheDir)->create();
        $before = file_get_contents($this->cacheDir . '/sgw_graphql_schema.php');

        // A stale cache after an upgrade would serve the previous schema
        // silently, which is the worst possible failure for this cache.
        touch($this->sdlPath(), time() + 10);
        clearstatcache();
        $this->factory($this->cacheDir)->create();
        $after = file_get_contents($this->cacheDir . '/sgw_graphql_schema.php');

        touch($this->sdlPath(), time() - 10);

        self::assertNotSame($before, $after, 'the cache must carry the SDL mtime');
    }

    public function testItWorksWithNoCacheDirectory(): void
    {
        $this->factory(null)->create()->assertValid();
        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter SchemaFactoryTest`
Expected: FAIL, classes not found.

- [ ] **Step 4: Write `Schema/ResolverMap.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Schema;
// ... licence header ...

use InvalidArgumentException;

/**
 * Type.field -> callable.
 *
 * Schema-first means the SDL is the source of truth and resolvers attach to it
 * by name. A field with no entry here falls back to reading the key of the same
 * name off the source array, which covers every plain data field.
 */
final class ResolverMap
{
    /** @var array<string, callable> */
    private $map;

    public function __construct(array $map)
    {
        foreach ($map as $key => $resolver) {
            if (!is_string($key) || substr_count($key, '.') !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'A resolver key must be "Type.field"; got "%s".', (string)$key
                ));
            }

            if (!is_callable($resolver)) {
                throw new InvalidArgumentException(sprintf(
                    'The resolver for "%s" is not callable.', $key
                ));
            }
        }

        $this->map = $map;
    }

    public function for(string $type, string $field): ?callable
    {
        return $this->map[$type . '.' . $field] ?? null;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->map);
    }
}
```

- [ ] **Step 5: Write `Schema/SchemaFactory.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Schema;
// ... licence header ...

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use RuntimeException;

/**
 * Builds the executable schema from the SDL, with the parsed AST cached to
 * disk so that BuildSchema does not re-parse on every request.
 *
 * The cache carries the SDL's mtime: a stale cache after an upgrade would
 * serve the previous schema silently, which is the worst failure this cache
 * could have.
 */
final class SchemaFactory
{
    const CACHE_FILE = 'sgw_graphql_schema.php';

    /** @var string */
    private $sdlPath;

    /** @var string|null */
    private $cacheDir;

    /** @var ResolverMap */
    private $resolvers;

    public function __construct(string $sdlPath, ?string $cacheDir, ResolverMap $resolvers)
    {
        $this->sdlPath = $sdlPath;
        $this->cacheDir = $cacheDir;
        $this->resolvers = $resolvers;
    }

    public function create(): Schema
    {
        $resolvers = $this->resolvers;

        return BuildSchema::build(
            $this->document(),
            static function (array $typeConfig, $typeDefinitionNode) use ($resolvers) {
                $typeName = $typeConfig['name'];

                $typeConfig['resolveField'] = static function (
                    $source, $args, $context, ResolveInfo $info
                ) use ($resolvers, $typeName) {
                    $resolver = $resolvers->for($typeName, $info->fieldName);

                    if ($resolver !== null) {
                        return $resolver($source, $args, $context, $info);
                    }

                    // Plain data fields read straight off the source.
                    if (is_array($source)) {
                        return $source[$info->fieldName] ?? null;
                    }

                    if (is_object($source) && isset($source->{$info->fieldName})) {
                        return $source->{$info->fieldName};
                    }

                    return null;
                };

                return $typeConfig;
            }
        );
    }

    private function document(): DocumentNode
    {
        if (!@is_readable($this->sdlPath)) {
            throw new RuntimeException(sprintf(
                'The schema file %s is missing or unreadable.', $this->sdlPath
            ));
        }

        $mtime = filemtime($this->sdlPath);

        if ($this->cacheDir === null) {
            return Parser::parse(file_get_contents($this->sdlPath), array('noLocation' => true));
        }

        $cacheFile = rtrim($this->cacheDir, '/') . '/' . self::CACHE_FILE;

        if (@is_readable($cacheFile)) {
            $cached = @include $cacheFile;

            if (is_array($cached)
                && isset($cached['mtime'], $cached['ast'])
                && $cached['mtime'] === $mtime
            ) {
                return AST::fromArray($cached['ast']);
            }
        }

        $document = Parser::parse(
            file_get_contents($this->sdlPath), array('noLocation' => true)
        );

        $this->writeCache($cacheFile, $mtime, AST::toArray($document));

        return $document;
    }

    private function writeCache(string $cacheFile, int $mtime, array $ast): void
    {
        if (!@is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0750, true)
            && !@is_dir($this->cacheDir)
        ) {
            return;   // an uncacheable schema is slow, not broken
        }

        $payload = "<?php\nreturn " . var_export(
            array('mtime' => $mtime, 'ast' => $ast), true
        ) . ";\n";

        // Write and rename, so a concurrent request never reads a half-written
        // cache and rebuilds the schema from a truncated AST.
        $temp = $cacheFile . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) !== false) {
            @rename($temp, $cacheFile);
        }
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter SchemaFactoryTest`
Expected: PASS, 6 test methods.

- [ ] **Step 7: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add schema/schema.graphql Schema/ResolverMap.php Schema/SchemaFactory.php \
        test/unit/Schema/SchemaFactoryTest.php
git commit -m "Add the SDL and the schema factory

Schema-first: one SDL file is the source of truth and resolvers attach by
Type.field name, with a fallback that reads plain data fields off the source.

The parsed AST is cached to disk and keyed on the SDL's mtime, because a stale
cache after an upgrade would serve the previous schema silently. The cache is
written and renamed so a concurrent request never reads a truncated AST."
```

---

## Task 11: The middleware chain — `sonnet`

Spec §4, §5, §6.2. Security-critical.

**Files:**
- Create: `Http/TlsMiddleware.php`, `Http/CorsMiddleware.php`, `Http/AuthenticateMiddleware.php`
- Create: `test/unit/Http/AuthenticateMiddlewareTest.php`

**Interfaces:**
- Consumes: `TokenService`, `Token` (Task 9); `Identity`, `IdentityShim` (Task 8); `ApiException`, `ErrorCode` (Task 7).
- Produces, each a Slim 3 callable middleware `__invoke($request, $response, $next)`:
  - `TlsMiddleware::__construct(bool $requireTls, TokenService $tokens)`
  - `CorsMiddleware::__construct(array $allowedOrigins)`
  - `AuthenticateMiddleware::__construct(TokenService $tokens, callable $accountLoader, bool $allowSessionAuth)` where `$accountLoader(int $adminId): ?array` returns the `admin` row
  - On success the request carries attribute `identity` (an `Identity`).

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Http;

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Token;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class AuthenticateMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        IdentityShim::reset();
    }

    private function request(array $headers = []): Request
    {
        $env = Environment::mock(array_merge(
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
             'REMOTE_ADDR' => '127.0.0.1'],
            $headers
        ));

        return Request::createFromEnvironment($env);
    }

    private function tokenServiceReturning(?Token $token): TokenService
    {
        $service = $this->createMock(TokenService::class);
        $service->method('verify')->willReturn($token);

        return $service;
    }

    private function customerRow(): array
    {
        return [
            'admin_id' => 7, 'admin_name' => 'wpcache.test', 'admin_type' => 'user',
            'created_by' => 3, 'email' => 'c@example.com', 'admin_status' => 'ok',
        ];
    }

    private function token(): Token
    {
        return new Token([
            'token_id' => 42, 'admin_id' => 7, 'name' => 'ci',
            'token_prefix' => 'abcdefgh', 'token_hash' => str_repeat('0', 64),
            'scopes' => 'DOMAINS_READ', 'ip_allowlist' => null,
            'created_at' => time(), 'expires_at' => null, 'last_used_at' => null,
            'last_used_ip' => null, 'revoked_at' => null,
        ]);
    }

    public function testAValidBearerTokenYieldsAnIdentity(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return $this->customerRow(); },
            false
        );

        $seen = null;
        $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($req, $res) use (&$seen) { $seen = $req->getAttribute('identity'); return $res; }
        );

        self::assertInstanceOf(Identity::class, $seen);
        self::assertSame(7, $seen->getAdminId());
        self::assertSame(Identity::ROLE_CUSTOMER, $seen->getRole());
        self::assertSame(['DOMAINS_READ'], $seen->getScopes());
        self::assertSame(7, $_SESSION['user_id'], 'the shim must be applied');
    }

    public function testNoCredentialIsA401(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false
        );

        $response = $mw($this->request(), new Response(), function ($rq, $rs) {
            self::fail('the handler must not run');
        });

        self::assertSame(401, $response->getStatusCode());
    }

    public function testABadTokenIsA401(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null),
            function () { return $this->customerRow(); },
            false
        );

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnAccountWhoseStatusIsNotOkIsRefused(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning($this->token()),
            function () { return ['admin_status' => 'todelete'] + $this->customerRow(); },
            false
        );

        $response = $mw(
            $this->request(['HTTP_AUTHORIZATION' => 'Bearer imscp_abcdefgh_' . str_repeat('a', 43)]),
            new Response(),
            function ($rq, $rs) { self::fail('the handler must not run'); }
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testTheFailureBodySaysNothingAboutWhichCheckFailed(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false
        );

        $body = (string)$mw($this->request(), new Response(), function ($rq, $rs) {
            return $rs;
        })->getBody();

        foreach (['expired', 'revoked', 'prefix', 'hash', 'allowlist', 'status'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body);
        }
    }

    public function testAnOptionsRequestPassesStraightThrough(): void
    {
        $mw = new AuthenticateMiddleware(
            $this->tokenServiceReturning(null), function () { return null; }, false
        );

        $env = Environment::mock([
            'REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '/api/graphql',
        ]);

        $reached = false;
        $mw(Request::createFromEnvironment($env), new Response(),
            function ($rq, $rs) use (&$reached) { $reached = true; return $rs; });

        self::assertTrue($reached, 'CORS preflight must not need a credential');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter AuthenticateMiddlewareTest`
Expected: FAIL, `Slim\Http\Environment` not found — Slim is supplied by the panel, not by this plugin.

Add Slim to `require-dev` so the unit suite can construct requests, then re-resolve:

```json
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "slim/slim": "^3.12"
    }
```

Re-run Step 2 of Task 4 to refresh `vendor/`, then re-run this step. Expected: FAIL, `AuthenticateMiddleware` not found.

- [ ] **Step 3: Write `Http/TlsMiddleware.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Http;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Refuses a request that did not arrive over TLS, and revokes any bearer token
 * presented on one.
 *
 * That is harsh and it is correct: by the time the request arrives, the
 * credential has already been transmitted in the clear and seen by every hop in
 * between. The alternative is a token that is compromised and still valid.
 */
final class TlsMiddleware
{
    /** @var bool */
    private $requireTls;

    /** @var TokenService */
    private $tokens;

    public function __construct(bool $requireTls, TokenService $tokens)
    {
        $this->requireTls = $requireTls;
        $this->tokens = $tokens;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, callable $next
    ): ResponseInterface {
        if (!$this->requireTls || $this->isSecure($request)) {
            return $next($request, $response);
        }

        $presented = self::bearerToken($request);

        if ($presented !== null) {
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

        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json')
            ->write(json_encode(array(
                'errors' => array(array(
                    'message'    => 'This API is available over HTTPS only. '
                        . 'Any token presented on this request has been revoked.',
                    'extensions' => array('code' => 'FORBIDDEN')
                ))
            )));
    }

    public static function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        return trim(substr($header, 7));
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        $server = $request->getServerParams();

        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (int)($server['SERVER_PORT'] ?? 0) === 443;
    }
}
```

- [ ] **Step 4: Write `Http/CorsMiddleware.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Http;
// ... licence header ...

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An explicit origin allow-list. Never '*', and credentials are never allowed
 * for a cross-origin request, so the cookie path of specification section 5.2
 * cannot be driven from another site.
 */
final class CorsMiddleware
{
    /** @var string[] */
    private $allowedOrigins;

    public function __construct(array $allowedOrigins)
    {
        $this->allowedOrigins = $allowedOrigins;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, callable $next
    ): ResponseInterface {
        $origin = $request->getHeaderLine('Origin');
        $allowed = $origin !== '' && in_array($origin, $this->allowedOrigins, true);

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = $response->withStatus($allowed ? 204 : 403);

            return $allowed ? $this->decorate($response, $origin) : $response;
        }

        $response = $next($request, $response);

        return $allowed ? $this->decorate($response, $origin) : $response;
    }

    private function decorate(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-iMSCP-CSRF')
            ->withHeader('Vary', 'Origin');
    }
}
```

- [ ] **Step 5: Write `Http/AuthenticateMiddleware.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Http;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Auth\Token;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a bearer token, or a panel session, into an Identity on the request.
 *
 * Every failure is the same 401 with the same body: a caller learning which
 * check failed learns nothing useful to them and something useful to an
 * attacker.
 */
final class AuthenticateMiddleware
{
    /** @var TokenService */
    private $tokens;

    /** @var callable fn(int $adminId): ?array */
    private $accountLoader;

    /** @var bool */
    private $allowSessionAuth;

    public function __construct(
        TokenService $tokens, callable $accountLoader, bool $allowSessionAuth
    ) {
        $this->tokens = $tokens;
        $this->accountLoader = $accountLoader;
        $this->allowSessionAuth = $allowSessionAuth;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, callable $next
    ): ResponseInterface {
        // A CORS preflight carries no credential by definition.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $next($request, $response);
        }

        $identity = $this->fromBearerToken($request) ?? $this->fromSession($request);

        if ($identity === null) {
            return $this->unauthenticated($response);
        }

        if (!SGW_GraphQL::customerHasApiAccess($identity->getAdminId())) {
            return $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json')
                ->write(json_encode(array('errors' => array(array(
                    'message'    => 'API access has been withdrawn from this account.',
                    'extensions' => array('code' => 'API_ACCESS_WITHDRAWN')
                )))));
        }

        IdentityShim::apply($identity);

        return $next($request->withAttribute('identity', $identity), $response);
    }

    private function fromBearerToken(ServerRequestInterface $request): ?Identity
    {
        $presented = TlsMiddleware::bearerToken($request);

        if ($presented === null) {
            return null;
        }

        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $token = $this->tokens->verify($presented, $clientIp);

        if ($token === null) {
            return null;
        }

        $account = $this->account($token->getAdminId());

        if ($account === null) {
            return null;
        }

        $this->tokens->stampLastUsed($token, $clientIp);

        return $this->identityFrom($account, $token->getScopes(), $token->getTokenId());
    }

    private function fromSession(ServerRequestInterface $request): ?Identity
    {
        if (!$this->allowSessionAuth || empty($_SESSION['user_id'])) {
            return null;
        }

        // A cookie is CSRF-exposed, so the session path additionally requires a
        // JSON content type and a header a cross-origin attacker cannot read.
        if (stripos($request->getHeaderLine('Content-Type'), 'application/json') !== 0) {
            return null;
        }

        $presented = $request->getHeaderLine('X-iMSCP-CSRF');

        if ($presented === '' || empty($_SESSION['graphql_csrf'])
            || !hash_equals((string)$_SESSION['graphql_csrf'], $presented)
        ) {
            return null;
        }

        $account = $this->account((int)$_SESSION['user_id']);

        if ($account === null) {
            return null;
        }

        // A session records no scopes: it is limited by its role alone.
        return $this->identityFrom($account, array(), null);
    }

    private function account(int $adminId): ?array
    {
        $account = call_user_func($this->accountLoader, $adminId);

        if (!is_array($account) || ($account['admin_status'] ?? '') !== 'ok') {
            return null;
        }

        return $account;
    }

    private function identityFrom(array $account, array $scopes, ?int $tokenId): Identity
    {
        return new Identity(
            (int)$account['admin_id'],
            (string)$account['admin_name'],
            (string)$account['admin_type'],
            $account['created_by'] === null ? null : (int)$account['created_by'],
            $account['email'] ?? null,
            $scopes,
            $tokenId
        );
    }

    private function unauthenticated(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="i-MSCP"')
            ->write(json_encode(array('errors' => array(array(
                'message'    => 'Authentication is required.',
                'extensions' => array('code' => 'UNAUTHENTICATED')
            )))));
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter AuthenticateMiddlewareTest`
Expected: PASS, 6 test methods.

Note the test doubles `TokenService` with `createMock()`, which requires the class not be `final`. If PHPUnit complains, remove `final` from `TokenService` and add `@internal` to its docblock rather than weakening the test.

- [ ] **Step 7: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add Http/TlsMiddleware.php Http/CorsMiddleware.php Http/AuthenticateMiddleware.php \
        test/unit/Http/AuthenticateMiddlewareTest.php composer.json composer.lock
git commit -m "Add the TLS, CORS and authentication middleware

A token presented over plain HTTP is revoked, because by then it has been seen
by every hop in between and the alternative is a compromised token that still
works.

Every authentication failure is the same 401 with the same body. The session
path additionally requires a JSON content type and a CSRF header, and records
no scopes: it is limited by its role alone."
```

---

## Task 12: The endpoint handler and the viewer resolver — `sonnet`

Spec §4, §6.5, §9. The shutdown guard is the layer that survives a core helper calling `exit()` mid-request.

**Files:**
- Create: `Resolver/ViewerResolver.php`, `Http/GraphQLHandler.php`
- Create: `test/unit/Http/GraphQLHandlerTest.php`

**Interfaces:**
- Consumes: `SchemaFactory`, `ResolverMap` (Task 10); `Identity` (Task 8); `ErrorFactory`, `ErrorCode`, `ApiException` (Task 7); `GlobalId` (Task 5).
- Produces:
  - `ViewerResolver::__construct(string $apiVersion)`
  - `ViewerResolver::map(): array` — the `ResolverMap` entries this resolver owns
  - `GraphQLHandler::__construct(SchemaFactory $schemaFactory, array $options)` where `$options` has keys `debug`, `introspection`, `maxQueryDepth`, `maxQueryComplexity`
  - `GraphQLHandler::__invoke($request, $response, array $args): ResponseInterface`

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Http;

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ViewerResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class GraphQLHandlerTest extends TestCase
{
    private function identity(): Identity
    {
        return new Identity(7, 'wpcache.test', 'user', 3, 'c@example.com',
                            ['DOMAINS_READ'], 42);
    }

    private function handler(array $options = []): GraphQLHandler
    {
        $viewer = new ViewerResolver('1.0.0');

        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql',
            null,
            new ResolverMap($viewer->map())
        );

        return new GraphQLHandler($factory, array_merge([
            'debug' => false, 'introspection' => true,
            'maxQueryDepth' => 15, 'maxQueryComplexity' => 1000,
        ], $options));
    }

    private function post(string $query, ?array $variables = null, bool $withIdentity = true): Response
    {
        $body = json_encode(array_filter(
            ['query' => $query, 'variables' => $variables],
            static function ($v) { return $v !== null; }
        ));

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $request = Request::createFromEnvironment($env);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        $request = $request->withBody(new \Slim\Http\Stream($stream));

        if ($withIdentity) {
            $request = $request->withAttribute('identity', $this->identity());
        }

        return ($this->handler())($request, new Response(), []);
    }

    public function testItAnswersTheViewerQuery(): void
    {
        $response = $this->post('{ viewer { id username role scopes } }');
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('errors', $body, (string)$response->getBody());
        self::assertSame('wpcache.test', $body['data']['viewer']['username']);
        self::assertSame('CUSTOMER', $body['data']['viewer']['role']);
        self::assertSame(['DOMAINS_READ'], $body['data']['viewer']['scopes']);
    }

    public function testTheViewerIdIsAnOpaqueGlobalIdentifier(): void
    {
        $body = json_decode((string)$this->post('{ viewer { id } }')->getBody(), true);

        self::assertSame(
            \iMSCP\Plugin\SGW_GraphQL\Support\GlobalId::encode('Viewer', 7),
            $body['data']['viewer']['id']
        );
    }

    public function testItAnswersApiVersion(): void
    {
        $body = json_decode((string)$this->post('{ apiVersion }')->getBody(), true);

        self::assertSame('1.0.0', $body['data']['apiVersion']);
    }

    public function testTheResponseIsAlwaysJson(): void
    {
        $response = $this->post('{ apiVersion }');

        self::assertStringStartsWith(
            'application/json', $response->getHeaderLine('Content-Type')
        );
    }

    public function testAMalformedBodyIsA400(): void
    {
        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $request = Request::createFromEnvironment($env)
            ->withAttribute('identity', $this->identity());

        $response = ($this->handler())($request, new Response(), []);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('BAD_USER_INPUT', $body['errors'][0]['extensions']['code']);
    }

    public function testASyntaxErrorIsA400WithAGraphQlEnvelope(): void
    {
        $response = $this->post('{ viewer { ');
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('errors', $body);
    }

    public function testAnOverDeepQueryIsRefused(): void
    {
        $viewer = new ViewerResolver('1.0.0');
        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql', null,
            new ResolverMap($viewer->map())
        );
        $handler = new GraphQLHandler($factory, [
            'debug' => false, 'introspection' => true,
            'maxQueryDepth' => 1, 'maxQueryComplexity' => 1000,
        ]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ viewer { username } }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $response = $handler($request, new Response(), []);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testIntrospectionCanBeTurnedOff(): void
    {
        $viewer = new ViewerResolver('1.0.0');
        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql', null,
            new ResolverMap($viewer->map())
        );
        $handler = new GraphQLHandler($factory, [
            'debug' => false, 'introspection' => false,
            'maxQueryDepth' => 15, 'maxQueryComplexity' => 1000,
        ]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ __schema { types { name } } }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $body = json_decode((string)$handler($request, new Response(), [])->getBody(), true);

        self::assertArrayHasKey('errors', $body);
    }

    public function testAMissingIdentityIsAnInternalErrorNotACrash(): void
    {
        // Authentication is the middleware's job; if the handler is reached
        // without one, that is a wiring bug and must not leak a stack trace.
        $response = $this->post('{ apiVersion }', null, false);
        $body = json_decode((string)$response->getBody(), true);

        self::assertArrayHasKey('errors', $body);
        self::assertSame('INTERNAL', $body['errors'][0]['extensions']['code']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter GraphQLHandlerTest`
Expected: FAIL, `ViewerResolver` not found.

- [ ] **Step 3: Write `Resolver/ViewerResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;

/**
 * Query.apiVersion and Query.viewer.
 *
 * The viewer is read entirely off the Identity the middleware put on the
 * request: it needs no database access at all, which is what makes it the
 * right query to prove the whole path with.
 */
final class ViewerResolver
{
    /** @var string */
    private $apiVersion;

    public function __construct(string $apiVersion)
    {
        $this->apiVersion = $apiVersion;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Query.apiVersion' => array($this, 'resolveApiVersion'),
            'Query.viewer'     => array($this, 'resolveViewer')
        );
    }

    public function resolveApiVersion($source, array $args, $context, ResolveInfo $info): string
    {
        return $this->apiVersion;
    }

    public function resolveViewer($source, array $args, $context, ResolveInfo $info): array
    {
        $identity = self::identityFrom($context);

        return array(
            'id'       => GlobalId::encode('Viewer', $identity->getAdminId()),
            'username' => $identity->getUsername(),
            'role'     => $identity->getRole(),
            'email'    => $identity->getEmail(),
            'scopes'   => $identity->getScopes()
        );
    }

    /**
     * @param mixed $context
     * @throws ApiException
     */
    public static function identityFrom($context): Identity
    {
        if (is_array($context) && ($context['identity'] ?? null) instanceof Identity) {
            return $context['identity'];
        }

        // Reaching a resolver without an identity means the middleware chain is
        // mis-wired. It is not the caller's fault and must not leak detail.
        throw new ApiException(
            ErrorCode::INTERNAL, 'The request reached a resolver with no identity.'
        );
    }
}
```

- [ ] **Step 4: Write `Http/GraphQLHandler.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Http;
// ... licence header ...

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Parses the request, executes the operation and serialises the result.
 *
 * The shutdown guard exists because a core helper can still terminate the
 * request: showErrorPage() (gui/include/View.php:932) checks the Accept header
 * and, for application/json, exits with a body of its own. A GraphQL client
 * sends exactly that header. Pre-checks are meant to make that unreachable;
 * this is what happens when one has a hole.
 */
final class GraphQLHandler
{
    /** @var SchemaFactory */
    private $schemaFactory;

    /** @var array */
    private $options;

    /** @var bool */
    private $completed = false;

    public function __construct(SchemaFactory $schemaFactory, array $options)
    {
        $this->schemaFactory = $schemaFactory;
        $this->options = $options;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, array $args
    ): ResponseInterface {
        $this->completed = false;
        register_shutdown_function(array($this, 'guardAgainstAbruptExit'));

        try {
            $input = $this->parseBody($request);
        } catch (ApiException $e) {
            $this->completed = true;

            return $this->json($response, 400, array(
                'errors' => array(ErrorFactory::format($e, (bool)$this->options['debug']))
            ));
        }

        $debugFlags = $this->options['debug']
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        try {
            $this->applyValidationRules();

            $result = GraphQL::executeQuery(
                $this->schemaFactory->create(),
                $input['query'],
                null,
                array('identity' => $request->getAttribute('identity')),
                $input['variables'],
                $input['operationName']
            );

            $result->setErrorFormatter(function ($error) {
                $previous = $error->getPrevious();

                if ($previous instanceof Throwable) {
                    $formatted = ErrorFactory::format($previous, (bool)$this->options['debug']);
                } else {
                    $formatted = array(
                        'message'    => $error->getMessage(),
                        'extensions' => array('code' => ErrorCode::BAD_USER_INPUT)
                    );
                }

                if ($error->path !== null) {
                    $formatted['path'] = $error->path;
                }

                return $formatted;
            });

            $output = $result->toArray($debugFlags);
            $status = $this->statusFor($output, $input['query']);
        } catch (Throwable $e) {
            $output = array(
                'errors' => array(ErrorFactory::format($e, (bool)$this->options['debug']))
            );
            $status = 400;
        }

        $this->completed = true;

        return $this->json($response, $status, $output);
    }

    /**
     * Emit a parseable GraphQL envelope if the script is about to die with an
     * operation still in flight. Public only because it is a shutdown callback.
     *
     * @return void
     */
    public function guardAgainstAbruptExit(): void
    {
        if ($this->completed) {
            return;
        }

        if (function_exists('write_log')) {
            write_log(
                'SGW_GraphQL: the request terminated before the operation completed. '
                . 'A core helper very likely called exit(). '
                . 'See docs/SPECIFICATION.md section 6.5.',
                E_USER_ERROR
            );
        }

        if (!headers_sent()) {
            header('Content-Type: application/json', true, 500);
        }

        echo json_encode(array('errors' => array(array(
            'message'    => 'An internal error occurred.',
            'extensions' => array('code' => ErrorCode::INTERNAL)
        ))));
    }

    /**
     * @return array{query: string, variables: array|null, operationName: string|null}
     * @throws ApiException
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody();

        if (trim($raw) === '') {
            throw new ApiException(ErrorCode::BAD_USER_INPUT, 'The request body is empty.');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'The request body is not a JSON object.'
            );
        }

        if (!isset($decoded['query']) || !is_string($decoded['query'])) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'The request has no "query".',
                array('field' => 'query')
            );
        }

        $variables = $decoded['variables'] ?? null;

        if ($variables !== null && !is_array($variables)) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, '"variables" must be an object.',
                array('field' => 'variables')
            );
        }

        return array(
            'query'         => $decoded['query'],
            'variables'     => $variables,
            'operationName' => isset($decoded['operationName']) && is_string($decoded['operationName'])
                ? $decoded['operationName'] : null
        );
    }

    private function applyValidationRules(): void
    {
        DocumentValidator::addRule(new QueryDepth((int)$this->options['maxQueryDepth']));
        DocumentValidator::addRule(new QueryComplexity((int)$this->options['maxQueryComplexity']));

        if (!$this->options['introspection']) {
            DocumentValidator::addRule(new DisableIntrospection(DisableIntrospection::ENABLED));
        }
    }

    /**
     * 200 whenever the envelope is a GraphQL result, whatever is in it. 400
     * only when the document itself could not be run.
     */
    private function statusFor(array $output, string $query): int
    {
        if (!isset($output['errors'])) {
            return 200;
        }

        // A document that produced no data at all never executed: that is a
        // validation or syntax failure, which is a transport-level 400.
        if (!array_key_exists('data', $output)) {
            return 400;
        }

        return 200;
    }

    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $body = json_encode(array('errors' => array(array(
                'message'    => 'The response could not be encoded.',
                'extensions' => array('code' => ErrorCode::INTERNAL)
            ))));
        }

        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            // A response keyed to a bearer token must never be cached anywhere.
            ->withHeader('Cache-Control', 'no-store');

        $response->getBody()->write($body);

        return $response;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter GraphQLHandlerTest`
Expected: PASS, 9 test methods.

- [ ] **Step 6: Run the whole suite and the lint script**

```bash
vendor/bin/phpunit --configuration test/phpunit.xml
sh test/lint/all.sh
```

Expected: PASS on both. `DocumentValidator::addRule()` is global state, so if adding the depth rule in one test leaks into another, reset it between tests with `DocumentValidator::initRules()` in `setUp()` rather than reordering the tests.

- [ ] **Step 7: Commit**

```bash
git add Resolver/ViewerResolver.php Http/GraphQLHandler.php \
        test/unit/Http/GraphQLHandlerTest.php
git commit -m "Add the endpoint handler and the viewer resolver

200 whenever the envelope is a GraphQL result, whatever is in it; 400 only
when the document never executed. Responses are no-store, since they are keyed
to a bearer token.

The shutdown guard emits a parseable envelope if the script dies with an
operation in flight, which is what happens when a core helper calls exit() on
a request whose Accept header says application/json."
```

---

## Task 13: Wire the endpoint into the panel — `sonnet`

The end-to-end task. Everything built so far is assembled behind a route and proved with `curl` against the running box.

**Files:**
- Create: `Api/Container.php`
- Modify: `SGW_GraphQL.php` — `getRoutes()`
- Create: `test/unit/Api/ContainerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 5–12.
- Produces:
  - `Container::__construct(SGW_GraphQL $plugin)`
  - `Container::handler(): GraphQLHandler`, `middleware(): array`, `tokens(): TokenService`
  - `SGW_GraphQL::getRoutes()` returning the API route and the schema route

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Api;

use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\CorsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    private function container(): Container
    {
        return Container::forTesting(
            dirname(__DIR__, 3),
            [
                'debug' => false, 'introspection' => true,
                'max_query_depth' => 15, 'max_query_complexity' => 1000,
                'require_tls' => true, 'allowed_origins' => [],
                'allow_session_auth' => true,
            ],
            function (string $sql, array $bind = []) { return null; },
            function (int $adminId) { return null; }
        );
    }

    public function testItBuildsAHandler(): void
    {
        self::assertInstanceOf(GraphQLHandler::class, $this->container()->handler());
    }

    public function testTheMiddlewareIsOrderedOutermostFirst(): void
    {
        // Slim applies middleware in reverse order of addition, so the array is
        // written innermost-last and the transport checks must come before any
        // credential is looked at.
        $middleware = $this->container()->middleware();

        self::assertInstanceOf(TlsMiddleware::class, $middleware[0]);
        self::assertInstanceOf(CorsMiddleware::class, $middleware[1]);
        self::assertInstanceOf(AuthenticateMiddleware::class, $middleware[2]);
    }

    public function testTheSchemaBuildsThroughTheContainer(): void
    {
        $this->container()->schemaFactory()->create()->assertValid();
        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter ContainerTest`
Expected: FAIL, `Container` not found.

- [ ] **Step 3: Write `Api/Container.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Api;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Http\AuthenticateMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\CorsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Http\TlsMiddleware;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ViewerResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use PDO;

/**
 * Assembles the endpoint from its parts.
 *
 * Small enough not to want a DI library, and explicit enough that the wiring
 * is readable in one place.
 */
final class Container
{
    /** @var string */
    private $pluginDir;

    /** @var array */
    private $config;

    /** @var callable */
    private $query;

    /** @var callable */
    private $accountLoader;

    /** @var TokenService|null */
    private $tokens;

    private function __construct(
        string $pluginDir, array $config, callable $query, callable $accountLoader
    ) {
        $this->pluginDir = $pluginDir;
        $this->config = $config;
        $this->query = $query;
        $this->accountLoader = $accountLoader;
    }

    public static function fromPlugin(SGW_GraphQL $plugin): self
    {
        $pluginDir = $plugin->getPluginManager()->pluginGetRootDir()
            . '/' . $plugin->getName();

        return new self(
            $pluginDir,
            $plugin->getConfig(),
            function (string $sql, array $bind = array()) {
                return exec_query($sql, $bind);
            },
            static function (int $adminId) {
                $stmt = exec_query(
                    '
                        SELECT admin_id, admin_name, admin_type, created_by, email,
                            admin_status
                        FROM admin WHERE admin_id = ?
                    ',
                    array($adminId)
                );

                return $stmt->rowCount() ? $stmt->fetchRow(PDO::FETCH_ASSOC) : null;
            }
        );
    }

    public static function forTesting(
        string $pluginDir, array $config, callable $query, callable $accountLoader
    ): self {
        return new self($pluginDir, $config, $query, $accountLoader);
    }

    public function tokens(): TokenService
    {
        if ($this->tokens === null) {
            $this->tokens = new TokenService($this->query);
        }

        return $this->tokens;
    }

    public function schemaFactory(): SchemaFactory
    {
        $viewer = new ViewerResolver($this->apiVersion());

        return new SchemaFactory(
            $this->pluginDir . '/schema/schema.graphql',
            defined('CACHE_PATH') ? CACHE_PATH : null,
            new ResolverMap($viewer->map())
        );
    }

    public function handler(): GraphQLHandler
    {
        return new GraphQLHandler($this->schemaFactory(), array(
            'debug'               => (bool)($this->config['debug'] ?? false),
            'introspection'       => (bool)($this->config['introspection'] ?? true),
            'maxQueryDepth'       => (int)($this->config['max_query_depth'] ?? 15),
            'maxQueryComplexity'  => (int)($this->config['max_query_complexity'] ?? 1000)
        ));
    }

    /**
     * Outermost first. Slim applies route middleware in reverse order of
     * addition, so this array is passed to the route in reverse.
     *
     * @return callable[]
     */
    public function middleware(): array
    {
        return array(
            new TlsMiddleware(
                (bool)($this->config['require_tls'] ?? true), $this->tokens()
            ),
            new CorsMiddleware((array)($this->config['allowed_origins'] ?? array())),
            new AuthenticateMiddleware(
                $this->tokens(),
                $this->accountLoader,
                (bool)($this->config['allow_session_auth'] ?? true)
            )
        );
    }

    public function schemaPath(): string
    {
        return $this->pluginDir . '/schema/schema.graphql';
    }

    public function apiVersion(): string
    {
        return '1.0.0';
    }
}
```

- [ ] **Step 4: Fill in `getRoutes()` in `SGW_GraphQL.php`**

```php
    /**
     * Get routes
     *
     * Any URL that is not a real file already reaches the plugin router
     * (gui/public/plugins.php), so the endpoint needs no web server change.
     *
     * GET is not accepted, for any operation: it is the only way a GraphQL
     * endpoint can be driven from a link or an image tag.
     *
     * @return array
     */
    public function getRoutes()
    {
        self::loadVendor();

        $container = Container::fromPlugin($this);
        $pluginDir = $this->getPluginManager()->pluginGetRootDir() . '/' . $this->getName();

        return array(
            array(
                'name'       => 'sgw_graphql_endpoint',
                'pattern'    => $this->getConfigParam('endpoint', '/api/graphql'),
                'methods'    => array('POST', 'OPTIONS'),
                'handler'    => $container->handler(),
                // Slim adds route middleware in reverse, so the transport
                // checks run before any credential is looked at.
                'middleware' => array_reverse($container->middleware())
            ),
            array(
                'name'    => 'sgw_graphql_schema',
                'pattern' => $this->getConfigParam('schema_endpoint', '/api/graphql/schema'),
                'methods' => array('GET'),
                'handler' => static function ($request, $response) use ($container) {
                    $response->getBody()->write(file_get_contents($container->schemaPath()));

                    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
                }
            ),
            '/client/api_tokens.php'    => $pluginDir . '/frontend/client/api_tokens.php',
            '/reseller/api_tokens.php'  => $pluginDir . '/frontend/reseller/api_tokens.php',
            '/reseller/api_access.php'  => $pluginDir . '/frontend/reseller/api_access.php'
        );
    }
```

Add `use iMSCP\Plugin\SGW_GraphQL\Api\Container;` to the file's imports.

The three page routes point at files that do not exist until Tasks 14 and 15. Create them now as one-line stubs so the router does not fatal:

```php
<?php
// Replaced in Task 14.
showNotFoundErrorPage();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --configuration test/phpunit.xml --filter ContainerTest`
Expected: PASS, 3 test methods.

- [ ] **Step 6: Deploy and issue a token by hand**

```bash
tools/deploy.sh
```

Then, inside the box, mint a token for the customer account directly, since the UI does not exist yet:

```bash
ssh -F .ssh-config imscp_debian_trixie 'sudo php7.4 -r "
  define(\"IMSCP_CONF\", \"/etc/imscp/imscp.conf\");
  require \"/var/www/imscp/gui/library/imscp-lib.php\";
  require \"/var/www/imscp/gui/plugins/SGW_GraphQL/vendor/autoload.php\";
  \\\$s = iMSCP\\Plugin\\SGW_GraphQL\\Auth\\TokenService::fromPanel();
  \\\$row = exec_query(\"SELECT admin_id FROM admin WHERE admin_type = ? LIMIT 1\", [\"user\"])->fetchRow();
  \\\$r = \\\$s->issue((int)\\\$row[\"admin_id\"], \"smoke\", [\"DOMAINS_READ\"], 1, null);
  echo \\\$r[\"token\"], PHP_EOL;
"'
```

Expected: a token of the form `imscp_<8>_<43>`. Keep it for the next step.

- [ ] **Step 7: Call the endpoint**

```bash
TOKEN=<the token from step 6>
curl -sk -X POST "https://panel.$(hostname -f):8443/api/graphql" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ apiVersion viewer { id username role scopes } }"}'
```

Expected:

```json
{"data":{"apiVersion":"1.0.0","viewer":{"id":"Vmlld2VyOjc","username":"…","role":"CUSTOMER","scopes":["DOMAINS_READ"]}}}
```

Then check the negative cases:

```bash
# No credential
curl -sk -o /dev/null -w '%{http_code}\n' -X POST "https://panel.$(hostname -f):8443/api/graphql" \
  -H 'Content-Type: application/json' -d '{"query":"{ apiVersion }"}'
# Expected: 401

# GET is refused
curl -sk -o /dev/null -w '%{http_code}\n' "https://panel.$(hostname -f):8443/api/graphql?query=%7BapiVersion%7D"
# Expected: 405

# Plain HTTP revokes the token
curl -s -o /dev/null -w '%{http_code}\n' -X POST "http://panel.$(hostname -f):8880/api/graphql" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"query":"{ apiVersion }"}'
# Expected: 403, and the token no longer works over HTTPS either
```

The last check consumes the token. Mint another for later work.

- [ ] **Step 8: Commit**

```bash
git add Api/Container.php SGW_GraphQL.php frontend test/unit/Api/ContainerTest.php
git commit -m "Wire the endpoint into the panel

POST /api/graphql, behind TLS, CORS and authentication middleware, plus a
plain-text schema endpoint. Any URL that is not a real file already reaches
the plugin router, so no web server change is needed.

Slim applies route middleware in reverse order of addition, so the array is
reversed on the way in and the transport checks run before any credential is
looked at."
```

---

## Task 14: The customer token page — `sonnet`

Spec §16. Follows `SGW_ApacheCache`'s page conventions exactly: a plugin page is reached through the plugin router, which has already required `imscp-lib.php`, so the page does not.

**Files:**
- Create: `frontend/common.php`
- Replace the stub: `frontend/client/api_tokens.php`
- Create: `themes/default/view/client/api_tokens.tpl`
- Modify: `l10n/en_GB.php` (no change needed; the strings are already British English)

**Interfaces:**
- Consumes: `TokenService`, `Token`, `Scope` (Task 9).
- Produces:
  - `SGW_GraphQL\Frontend\tokenService(): TokenService`
  - `SGW_GraphQL\Frontend\csrfToken(): string` — creates `$_SESSION['graphql_csrf']` on first call
  - `SGW_GraphQL\Frontend\formatWhen(?int $timestamp): string`

- [ ] **Step 1: Write `frontend/common.php`**

```php
<?php
namespace SGW_GraphQL\Frontend;
// ... licence header ...

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
```

- [ ] **Step 2: Write `themes/default/view/client/api_tokens.tpl`**

```html
<div class="info">{TR_INTRO}</div>

<!-- BDP: new_token_block -->
<div class="success">
    <p>{TR_NEW_TOKEN_INTRO}</p>
    <pre style="user-select:all;padding:.6em;overflow-x:auto">{NEW_TOKEN}</pre>
    <p><strong>{TR_NEW_TOKEN_WARNING}</strong></p>
</div>
<!-- EDP: new_token_block -->

<!-- BDP: no_tokens_block -->
<div class="static_info">{TR_NO_TOKENS}</div>
<!-- EDP: no_tokens_block -->

<!-- BDP: token_list -->
<table class="firstColFixed datatable">
    <thead>
    <tr>
        <th>{TR_STATE}</th>
        <th>{TR_NAME}</th>
        <th>{TR_PREFIX}</th>
        <th>{TR_SCOPES}</th>
        <th>{TR_CREATED}</th>
        <th>{TR_EXPIRES}</th>
        <th>{TR_LAST_USED}</th>
        <th>{TR_ACTION}</th>
    </tr>
    </thead>
    <tbody>
    <!-- BDP: token_item -->
    <tr>
        <td><div class="icon i_{STATE_ICON}">{STATE}</div></td>
        <td>{NAME}</td>
        <td><code>{PREFIX}</code></td>
        <td>{SCOPES}</td>
        <td>{CREATED}</td>
        <td>{EXPIRES}</td>
        <td>{LAST_USED}</td>
        <td>
            <!-- BDP: revoke_action -->
            <a href="api_tokens.php?action=revoke&amp;id={TOKEN_ID}"
               class="icon i_delete"
               onclick="return confirm('{TR_REVOKE_CONFIRM}');">{TR_REVOKE}</a>
            <!-- EDP: revoke_action -->
        </td>
    </tr>
    <!-- EDP: token_item -->
    </tbody>
</table>
<!-- EDP: token_list -->

<form method="post" action="api_tokens.php">
    <table class="firstColFixed">
        <thead>
        <tr><th colspan="2">{TR_CREATE}</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><label for="name">{TR_NAME}</label></td>
            <td><input type="text" name="name" id="name" value="{NAME_VALUE}" maxlength="255"></td>
        </tr>
        <tr>
            <td><label for="ttl_days">{TR_LIFETIME}</label></td>
            <td>
                <input type="number" name="ttl_days" id="ttl_days" min="1" max="{MAX_TTL}"
                       value="{TTL_VALUE}"> {TR_DAYS}
            </td>
        </tr>
        <tr>
            <td>{TR_SCOPES}</td>
            <td>
                <!-- BDP: scope_item -->
                <label style="display:inline-block;min-width:14em">
                    <input type="checkbox" name="scopes[]" value="{SCOPE}"{SCOPE_CHECKED}>
                    {SCOPE}
                </label>
                <!-- EDP: scope_item -->
                <p class="hint">{TR_SCOPES_HINT}</p>
            </td>
        </tr>
        <tr>
            <td><label for="ip_allowlist">{TR_IP_ALLOWLIST}</label></td>
            <td>
                <input type="text" name="ip_allowlist" id="ip_allowlist"
                       value="{IP_ALLOWLIST_VALUE}" placeholder="10.0.0.0/8, 203.0.113.5">
                <p class="hint">{TR_IP_ALLOWLIST_HINT}</p>
            </td>
        </tr>
        </tbody>
    </table>
    <div class="buttons">
        <input name="submit" type="submit" value="{TR_CREATE}">
    </div>
</form>

<div class="static_info">
    <p>{TR_ENDPOINT}: <code>{ENDPOINT}</code></p>
    <p>{TR_SCHEMA}: <code>{SCHEMA_ENDPOINT}</code></p>
</div>
```

- [ ] **Step 3: Write `frontend/client/api_tokens.php`**

```php
<?php
namespace SGW_GraphQL;
// ... licence header ...

use iMSCP\Event\EventAggregator;
use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use iMSCP\Registry;
use iMSCP\TemplateEngine;

use function SGW_GraphQL\Frontend\formatWhen;
use function SGW_GraphQL\Frontend\tokenService;
use function SGW_GraphQL\Frontend\tokenState;

require_once __DIR__ . '/../common.php';

/**
 * Revoke a token, when asked from the list.
 *
 * @param int $adminId
 * @return void
 */
function handleRevoke($adminId)
{
    if (!isset($_GET['action'], $_GET['id']) || $_GET['action'] !== 'revoke') {
        return;
    }

    if (tokenService()->revoke($adminId, intval($_GET['id']))) {
        write_log(sprintf(
            'An API token was revoked by %s', $_SESSION['user_logged']
        ), E_USER_NOTICE);
        set_page_message(tr('Token revoked.'), 'success');
    } else {
        set_page_message(tr('That token could not be revoked.'), 'error');
    }

    redirectTo('api_tokens.php');
}

/**
 * Create a token, returning the plaintext exactly once.
 *
 * @param int $adminId
 * @return string|null The new token, or NULL when nothing was created
 */
function handleCreate($adminId)
{
    if (empty($_POST)) {
        return NULL;
    }

    $plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');
    $maxPerAccount = intval($plugin->getConfigParam('token_max_per_account', 10));
    $maxTtl = intval($plugin->getConfigParam('token_max_ttl_days', 730));

    $live = 0;
    foreach (tokenService()->listFor($adminId) as $token) {
        if ($token->getRevokedAt() === NULL
            && ($token->getExpiresAt() === NULL || $token->getExpiresAt() > time())
        ) {
            $live++;
        }
    }

    if ($live >= $maxPerAccount) {
        set_page_message(tr(
            'You already have %d tokens. Revoke one before creating another.',
            $maxPerAccount
        ), 'error');
        return NULL;
    }

    $name = isset($_POST['name']) ? clean_input($_POST['name']) : '';
    $scopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
        ? array_map('clean_input', $_POST['scopes']) : array();
    $ttlDays = isset($_POST['ttl_days']) ? intval($_POST['ttl_days']) : NULL;
    $ipAllowlist = isset($_POST['ip_allowlist'])
        ? trim(clean_input($_POST['ip_allowlist'])) : '';

    if ($ttlDays !== NULL && ($ttlDays < 1 || $ttlDays > $maxTtl)) {
        set_page_message(tr('A token may live for 1 to %d days.', $maxTtl), 'error');
        return NULL;
    }

    try {
        $result = tokenService()->issue(
            $adminId, $name, $scopes, $ttlDays, $ipAllowlist === '' ? NULL : $ipAllowlist
        );
    } catch (\Exception $e) {
        set_page_message(tohtml($e->getMessage()), 'error');
        return NULL;
    }

    write_log(sprintf(
        'A new API token (%s) was created by %s', $name, $_SESSION['user_logged']
    ), E_USER_NOTICE);

    return $result['token'];
}

/**
 * @param TemplateEngine $tpl
 * @param int $adminId
 * @param string|null $newToken
 * @return void
 */
function generatePage(TemplateEngine $tpl, $adminId, $newToken)
{
    $plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');

    if ($newToken === NULL) {
        $tpl->assign('NEW_TOKEN_BLOCK', '');
    } else {
        $tpl->assign('NEW_TOKEN', tohtml($newToken));
        $tpl->parse('NEW_TOKEN_BLOCK', 'new_token_block');
    }

    $tokens = tokenService()->listFor($adminId);

    if ($tokens === array()) {
        $tpl->assign('TOKEN_LIST', '');
        $tpl->parse('NO_TOKENS_BLOCK', 'no_tokens_block');
    } else {
        $tpl->assign('NO_TOKENS_BLOCK', '');

        foreach ($tokens as $token) {
            $state = tokenState($token);
            $scopes = $token->getScopes();

            $tpl->assign(array(
                'STATE'      => tohtml($state['label']),
                'STATE_ICON' => $state['icon'],
                'NAME'       => tohtml($token->getName()),
                'PREFIX'     => tohtml($token->getPrefix()),
                'SCOPES'     => tohtml($scopes === array() ? tr('All') : implode(', ', $scopes)),
                'CREATED'    => tohtml(formatWhen($token->getCreatedAt())),
                'EXPIRES'    => tohtml(formatWhen($token->getExpiresAt())),
                'LAST_USED'  => tohtml(
                    $token->getLastUsedAt() === NULL
                        ? tr('Never')
                        : formatWhen($token->getLastUsedAt())
                            . ' (' . $token->getLastUsedIp() . ')'
                ),
                'TOKEN_ID'   => $token->getTokenId()
            ));

            if ($token->getRevokedAt() === NULL) {
                $tpl->parse('REVOKE_ACTION', 'revoke_action');
            } else {
                $tpl->assign('REVOKE_ACTION', '');
            }

            $tpl->parse('TOKEN_ITEM', '.token_item');
        }

        $tpl->parse('TOKEN_LIST', 'token_list');
    }

    foreach (Scope::all() as $scope) {
        $tpl->assign(array(
            'SCOPE'         => tohtml($scope),
            'SCOPE_CHECKED' => isset($_POST['scopes']) && in_array($scope, (array)$_POST['scopes'], true)
                ? ' checked' : ''
        ));
        $tpl->parse('SCOPE_ITEM', '.scope_item');
    }

    $tpl->assign(array(
        'NAME_VALUE'         => isset($_POST['name']) ? tohtml($_POST['name'], 'htmlAttr') : '',
        'TTL_VALUE'          => intval($plugin->getConfigParam('token_default_ttl_days', 365)),
        'MAX_TTL'            => intval($plugin->getConfigParam('token_max_ttl_days', 730)),
        'IP_ALLOWLIST_VALUE' => isset($_POST['ip_allowlist'])
            ? tohtml($_POST['ip_allowlist'], 'htmlAttr') : '',
        'ENDPOINT'           => tohtml($plugin->getConfigParam('endpoint', '/api/graphql')),
        'SCHEMA_ENDPOINT'    => tohtml($plugin->getConfigParam('schema_endpoint', '/api/graphql/schema'))
    ));
}

check_login('user');
EventAggregator::getInstance()->dispatch(Events::onClientScriptStart);

SGW_GraphQL::customerHasApiAccess(intval($_SESSION['user_id'])) or showBadRequestErrorPage();

$adminId = intval($_SESSION['user_id']);
handleRevoke($adminId);
$newToken = handleCreate($adminId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'          => 'shared/layouts/ui.tpl',
    'page'            => '../../plugins/SGW_GraphQL/themes/default/view/client/api_tokens.tpl',
    'page_message'    => 'layout',
    'new_token_block' => 'page',
    'no_tokens_block' => 'page',
    'token_list'      => 'page',
    'token_item'      => 'token_list',
    'revoke_action'   => 'token_item',
    'scope_item'      => 'page'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'        => tohtml(tr('Client / Profile / API tokens')),
    'TR_INTRO'             => tohtml(tr('Tokens let a script act as your account through the API. Give each one only the scopes it needs, and revoke any you no longer use.')),
    'TR_NEW_TOKEN_INTRO'   => tohtml(tr('Your new token:')),
    'TR_NEW_TOKEN_WARNING' => tohtml(tr('Copy it now. It will not be shown again.')),
    'TR_NO_TOKENS'         => tohtml(tr('You have no tokens.')),
    'TR_STATE'             => tohtml(tr('State')),
    'TR_NAME'              => tohtml(tr('Name')),
    'TR_PREFIX'            => tohtml(tr('Prefix')),
    'TR_SCOPES'            => tohtml(tr('Scopes')),
    'TR_SCOPES_HINT'       => tohtml(tr('Selecting none gives the token everything your account can do.')),
    'TR_CREATED'           => tohtml(tr('Created')),
    'TR_EXPIRES'           => tohtml(tr('Expires')),
    'TR_LAST_USED'         => tohtml(tr('Last used')),
    'TR_ACTION'            => tohtml(tr('Actions')),
    'TR_REVOKE'            => tohtml(tr('Revoke')),
    'TR_REVOKE_CONFIRM'    => tojs(tr('Revoke this token? Anything using it will stop working immediately.')),
    'TR_CREATE'            => tohtml(tr('Create a token')),
    'TR_LIFETIME'          => tohtml(tr('Lifetime')),
    'TR_DAYS'              => tohtml(tr('days')),
    'TR_IP_ALLOWLIST'      => tohtml(tr('Restrict to addresses')),
    'TR_IP_ALLOWLIST_HINT' => tohtml(tr('Comma-separated addresses or CIDR ranges. Leave empty for no restriction.')),
    'TR_ENDPOINT'          => tohtml(tr('Endpoint')),
    'TR_SCHEMA'            => tohtml(tr('Schema'))
));

generateNavigation($tpl);
generatePage($tpl, $adminId, $newToken);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onClientScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
```

- [ ] **Step 4: Deploy and exercise the page**

```bash
tools/deploy.sh
```

In the panel, as the customer: *Profile / API tokens*.

Check each of these by hand:
1. The page renders and lists the token minted in Task 13.
2. Creating a token shows the plaintext once, in a copyable block.
3. Reloading the page does **not** show the plaintext again.
4. Revoking a token moves it to `Revoked` and removes its revoke link.
5. Requesting more than `token_max_per_account` live tokens is refused.
6. A lifetime of `0` or above `token_max_ttl_days` is refused.

- [ ] **Step 5: Verify the revoked token no longer authenticates**

```bash
curl -sk -o /dev/null -w '%{http_code}\n' -X POST "https://panel.$(hostname -f):8443/api/graphql" \
  -H "Authorization: Bearer <the revoked token>" \
  -H 'Content-Type: application/json' -d '{"query":"{ apiVersion }"}'
```

Expected: `401`.

- [ ] **Step 6: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add frontend/common.php frontend/client/api_tokens.php \
        themes/default/view/client/api_tokens.tpl
git commit -m "Add the customer token page

One row per token with its prefix, scopes, expiry and last use, so an
unexpected use is visible. The plaintext is shown once, on creation, and never
again — the page says so rather than leaving the reader to find out."
```

---

## Task 15: The reseller pages — `sonnet`

Spec §6.2 and §16. Two pages: the reseller's own tokens, and the grid that grants or withdraws API access per customer.

**Files:**
- Replace the stubs: `frontend/reseller/api_tokens.php`, `frontend/reseller/api_access.php`
- Create: `themes/default/view/reseller/api_tokens.tpl`, `themes/default/view/reseller/api_access.tpl`
- Modify: `frontend/common.php` — add `customersOf()`

**Interfaces:**
- Consumes: `TokenService`, `Scope` (Task 9); `SGW_GraphQL::customerHasApiAccess()` (Task 2).
- Produces: `SGW_GraphQL\Frontend\customersOf(int $resellerId): array` — rows of `admin_id`, `admin_name`, `allowed`, `live_tokens`

- [ ] **Step 1: Add `customersOf()` to `frontend/common.php`**

```php
/**
 * Every customer of a reseller, with their API access and live token count.
 *
 * @param int $resellerId
 * @return array
 */
function customersOf(int $resellerId): array
{
    $stmt = exec_query(
        "
            SELECT a.admin_id, a.admin_name,
                COALESCE(p.allowed, 1) AS allowed,
                (
                    SELECT COUNT(*) FROM api_token AS t
                    WHERE t.admin_id = a.admin_id
                      AND t.revoked_at IS NULL
                      AND (t.expires_at IS NULL OR t.expires_at > UNIX_TIMESTAMP())
                ) AS live_tokens
            FROM admin AS a
            LEFT JOIN api_perm AS p ON p.admin_id = a.admin_id
            WHERE a.admin_type = 'user' AND a.created_by = ?
            ORDER BY a.admin_name
        ",
        array($resellerId)
    );

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Grant or withdraw API access for an account.
 *
 * Withdrawing revokes that account's tokens too: a feature and its effects
 * should go away together.
 *
 * @param int $adminId
 * @param bool $allowed
 * @return void
 */
function setApiAccess(int $adminId, bool $allowed): void
{
    exec_query(
        '
            INSERT INTO api_perm (admin_id, allowed) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE allowed = VALUES(allowed)
        ',
        array($adminId, $allowed ? 1 : 0)
    );

    if (!$allowed) {
        tokenService()->revokeAllFor($adminId);
    }
}
```

- [ ] **Step 2: Write `themes/default/view/reseller/api_access.tpl`**

```html
<div class="info">{TR_INTRO}</div>

<!-- BDP: no_customers_block -->
<div class="static_info">{TR_NO_CUSTOMERS}</div>
<!-- EDP: no_customers_block -->

<!-- BDP: customer_list -->
<table class="firstColFixed datatable">
    <thead>
    <tr>
        <th>{TR_ACCESS}</th>
        <th>{TR_CUSTOMER}</th>
        <th>{TR_LIVE_TOKENS}</th>
        <th>{TR_ACTION}</th>
    </tr>
    </thead>
    <tbody>
    <!-- BDP: customer_item -->
    <tr>
        <td><div class="icon i_{ACCESS_ICON}">{ACCESS}</div></td>
        <td>{CUSTOMER_NAME}</td>
        <td>{LIVE_TOKENS}</td>
        <td>
            <a href="api_access.php?action={TOGGLE_ACTION}&amp;id={ADMIN_ID}"
               class="icon i_{TOGGLE_ICON}"
               onclick="return confirm('{TOGGLE_CONFIRM}');">{TOGGLE_LABEL}</a>
        </td>
    </tr>
    <!-- EDP: customer_item -->
    </tbody>
</table>
<!-- EDP: customer_list -->
```

- [ ] **Step 3: Write `frontend/reseller/api_access.php`**

```php
<?php
namespace SGW_GraphQL;
// ... licence header ...

use iMSCP\Event\EventAggregator;
use iMSCP\Event\Events;
use iMSCP\TemplateEngine;

use function SGW_GraphQL\Frontend\customersOf;
use function SGW_GraphQL\Frontend\setApiAccess;

require_once __DIR__ . '/../common.php';

/**
 * @param int $resellerId
 * @return void
 */
function handleAction($resellerId)
{
    if (!isset($_GET['action'], $_GET['id'])) {
        return;
    }

    $action = clean_input($_GET['action']);
    $adminId = intval($_GET['id']);

    // A reseller may only reach their own customers. Anything else is
    // indistinguishable from a customer that does not exist.
    $found = false;
    foreach (customersOf($resellerId) as $customer) {
        if (intval($customer['admin_id']) === $adminId) {
            $found = true;
            break;
        }
    }

    if (!$found || !in_array($action, array('grant', 'withdraw'), true)) {
        showBadRequestErrorPage();
    }

    setApiAccess($adminId, $action === 'grant');

    write_log(sprintf(
        'API access was %s for customer %d by %s',
        $action === 'grant' ? 'granted' : 'withdrawn',
        $adminId,
        $_SESSION['user_logged']
    ), E_USER_NOTICE);

    set_page_message(
        $action === 'grant'
            ? tr('API access granted.')
            : tr('API access withdrawn, and that customer\'s tokens revoked.'),
        'success'
    );
    redirectTo('api_access.php');
}

/**
 * @param TemplateEngine $tpl
 * @param int $resellerId
 * @return void
 */
function generatePage(TemplateEngine $tpl, $resellerId)
{
    $customers = customersOf($resellerId);

    if ($customers === array()) {
        $tpl->assign('CUSTOMER_LIST', '');
        $tpl->parse('NO_CUSTOMERS_BLOCK', 'no_customers_block');
        return;
    }

    $tpl->assign('NO_CUSTOMERS_BLOCK', '');

    foreach ($customers as $customer) {
        $allowed = (bool)$customer['allowed'];

        $tpl->assign(array(
            'ACCESS'         => tohtml($allowed ? tr('Allowed') : tr('Withdrawn')),
            'ACCESS_ICON'    => $allowed ? 'ok' : 'disabled',
            'CUSTOMER_NAME'  => tohtml(decode_idna($customer['admin_name'])),
            'LIVE_TOKENS'    => intval($customer['live_tokens']),
            'ADMIN_ID'       => intval($customer['admin_id']),
            'TOGGLE_ACTION'  => $allowed ? 'withdraw' : 'grant',
            'TOGGLE_ICON'    => $allowed ? 'delete' : 'ok',
            'TOGGLE_LABEL'   => tohtml($allowed ? tr('Withdraw') : tr('Grant')),
            'TOGGLE_CONFIRM' => tojs($allowed
                ? tr('Withdraw API access? This revokes every token that customer holds.')
                : tr('Grant API access to this customer?'))
        ));
        $tpl->parse('CUSTOMER_ITEM', '.customer_item');
    }

    $tpl->parse('CUSTOMER_LIST', 'customer_list');
}

check_login('reseller');
EventAggregator::getInstance()->dispatch(Events::onResellerScriptStart);

$resellerId = intval($_SESSION['user_id']);
handleAction($resellerId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'              => 'shared/layouts/ui.tpl',
    'page'                => '../../plugins/SGW_GraphQL/themes/default/view/reseller/api_access.tpl',
    'page_message'        => 'layout',
    'no_customers_block'  => 'page',
    'customer_list'       => 'page',
    'customer_item'       => 'customer_list'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'   => tohtml(tr('Reseller / Customers / API access')),
    'TR_INTRO'        => tohtml(tr('Customers may use the API unless you withdraw it here. Withdrawing also revokes every token that customer holds.')),
    'TR_NO_CUSTOMERS' => tohtml(tr('You have no customers.')),
    'TR_ACCESS'       => tohtml(tr('Access')),
    'TR_CUSTOMER'     => tohtml(tr('Customer')),
    'TR_LIVE_TOKENS'  => tohtml(tr('Live tokens')),
    'TR_ACTION'       => tohtml(tr('Actions'))
));

generateNavigation($tpl);
generatePage($tpl, $resellerId);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onResellerScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
```

- [ ] **Step 4: Write the reseller token page**

`frontend/reseller/api_tokens.php` and `themes/default/view/reseller/api_tokens.tpl` are the customer versions of Task 14 with four differences, and nothing else:

1. `check_login('reseller')` instead of `check_login('user')`.
2. `Events::onResellerScriptStart` / `onResellerScriptEnd`.
3. The page title is `tr('Reseller / Profile / API tokens')`.
4. The `customerHasApiAccess()` gate is dropped: a reseller's access is an administrator's decision and there is no administrator UI for it in this phase.

Copy both files and make exactly those changes. Do not factor the two pages into one shared file: they diverge in plan 4 when the reseller page gains customer-scoped tokens, and a premature abstraction here would have to be unpicked.

- [ ] **Step 5: Deploy and exercise both pages**

```bash
tools/deploy.sh
```

As the reseller:
1. *Customers / API access* lists the customer, showing `Allowed` and a live token count.
2. Withdrawing access flips the row to `Withdrawn` and drops the token count to `0`.
3. The customer's *Profile / API tokens* page now returns a bad request.
4. A previously working token now returns `403` with `API_ACCESS_WITHDRAWN`:

```bash
curl -sk -X POST "https://panel.$(hostname -f):8443/api/graphql" \
  -H "Authorization: Bearer <a token of that customer>" \
  -H 'Content-Type: application/json' -d '{"query":"{ apiVersion }"}'
```

5. Granting access again restores the page. The revoked tokens stay revoked, which is correct.
6. *Profile / API tokens* as the reseller creates and revokes a reseller-owned token.

- [ ] **Step 6: Run the lint script, then commit**

```bash
sh test/lint/all.sh
git add frontend/reseller themes/default/view/reseller frontend/common.php
git commit -m "Add the reseller token and API access pages

The access grid mirrors SGW_ApacheCache's permission model. Withdrawing access
revokes that customer's tokens as well as closing the endpoint to them: a
feature and its effects should go away together.

A reseller may only reach their own customers, and anything else is refused
the same way a customer that does not exist would be."
```

---

## Task 16: The integration smoke test — `opus`

Everything so far has been proved by hand. This makes it repeatable, and it is the test that will catch a regression in plan 2.

**Files:**
- Create: `test/api/smoke.sh`

**Interfaces:**
- Consumes: the deployed plugin (Tasks 13–15).
- Produces: `test/api/smoke.sh`, exit 0 on success. Referenced by `makefile.json`'s `test` target from here on.

- [ ] **Step 1: Write the smoke test**

```bash
#!/bin/sh
# End-to-end check of the API against the running box.
#
# Everything the phase-1 endpoint promises, in the order a client would meet
# it. Run from the plugin root on the host:
#
#   test/api/smoke.sh [box]
set -e

BOX=${1:-imscp_debian_trixie}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SSH_CONFIG=$ROOT/.ssh-config
FAILED=0

pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; FAILED=1; }

check() {
    # $1 description, $2 expected, $3 actual
    if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected $2, got $3)"; fi
}

(cd "$ROOT/../imscp/Vagrant" && vagrant ssh-config "$BOX") > "$SSH_CONFIG"
HOST=$(awk '/HostName/ {print $2}' "$SSH_CONFIG")
VHOST=$(ssh -F "$SSH_CONFIG" "$BOX" \
    'sudo grep "^BASE_SERVER_VHOST " /etc/imscp/imscp.conf | cut -d= -f2 | tr -d " "')
HTTPS_PORT=$(ssh -F "$SSH_CONFIG" "$BOX" \
    'sudo grep "^BASE_SERVER_VHOST_HTTPS_PORT " /etc/imscp/imscp.conf | cut -d= -f2 | tr -d " "')
HTTP_PORT=$(ssh -F "$SSH_CONFIG" "$BOX" \
    'sudo grep "^BASE_SERVER_VHOST_HTTP_PORT " /etc/imscp/imscp.conf | cut -d= -f2 | tr -d " "')

BASE="https://$VHOST:$HTTPS_PORT"
CURL="curl -sk --resolve $VHOST:$HTTPS_PORT:$HOST"

echo "Endpoint: $BASE/api/graphql"

# --- mint a token for the first customer -----------------------------------
mint() {
    ssh -F "$SSH_CONFIG" "$BOX" "sudo php7.4 -r '
        define(\"IMSCP_CONF\", \"/etc/imscp/imscp.conf\");
        require \"/var/www/imscp/gui/library/imscp-lib.php\";
        require \"/var/www/imscp/gui/plugins/SGW_GraphQL/vendor/autoload.php\";
        \$s = iMSCP\\Plugin\\SGW_GraphQL\\Auth\\TokenService::fromPanel();
        \$row = exec_query(\"SELECT admin_id FROM admin WHERE admin_type = ? ORDER BY admin_id LIMIT 1\", [\"user\"])->fetchRow();
        \$r = \$s->issue((int)\$row[\"admin_id\"], \"smoke-$1\", $2, 1, null);
        echo \$r[\"token\"];
    '"
}

TOKEN=$(mint full '[]')
[ -n "$TOKEN" ] || { echo "could not mint a token"; exit 1; }

post() {
    # $1 token, $2 query -> body on stdout
    $CURL -X POST "$BASE/api/graphql" \
        -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
        -d "{\"query\":$(printf '%s' "$2" | sed 's/"/\\"/g; s/^/"/; s/$/"/')}"
}

status() {
    # $1 token, $2 query -> HTTP status on stdout
    $CURL -o /dev/null -w '%{http_code}' -X POST "$BASE/api/graphql" \
        -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
        -d "{\"query\":$(printf '%s' "$2" | sed 's/"/\\"/g; s/^/"/; s/$/"/')}"
}

echo
echo "Happy path:"
BODY=$(post "$TOKEN" '{ apiVersion viewer { id username role scopes } }')
check "apiVersion is served" "1.0.0" \
      "$(printf '%s' "$BODY" | sed -n 's/.*"apiVersion":"\([^"]*\)".*/\1/p')"
check "viewer role is CUSTOMER" "CUSTOMER" \
      "$(printf '%s' "$BODY" | sed -n 's/.*"role":"\([^"]*\)".*/\1/p')"
case "$BODY" in *'"errors"'*) fail "the happy path returned errors: $BODY" ;;
                *) pass "no errors in the envelope" ;; esac

echo
echo "Transport:"
check "no credential is 401" "401" "$(status '' '{ apiVersion }')"
check "a nonsense token is 401" "401" "$(status 'imscp_aaaaaaaa_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' '{ apiVersion }')"
check "GET is refused" "405" \
      "$($CURL -o /dev/null -w '%{http_code}' "$BASE/api/graphql?query=%7BapiVersion%7D")"
check "the schema endpoint serves SDL" "200" \
      "$($CURL -o /dev/null -w '%{http_code}' "$BASE/api/graphql/schema")"
check "responses are not cacheable" "no-store" \
      "$($CURL -sD - -o /dev/null -X POST "$BASE/api/graphql" \
          -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
          -d '{"query":"{ apiVersion }"}' | awk 'tolower($1)=="cache-control:" {print $2}' | tr -d '\r')"

echo
echo "Malformed input:"
check "an empty body is 400" "400" \
      "$($CURL -o /dev/null -w '%{http_code}' -X POST "$BASE/api/graphql" \
          -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '')"
check "a syntax error is 400" "400" "$(status "$TOKEN" '{ viewer { ')"

echo
echo "Scopes:"
SCOPED=$(mint scoped '["MAIL_READ"]')
BODY=$(post "$SCOPED" '{ viewer { scopes } }')
case "$BODY" in *'MAIL_READ'*) pass "a scoped token reports its scopes" ;;
                *) fail "a scoped token did not report its scopes: $BODY" ;; esac

echo
echo "Revocation:"
ssh -F "$SSH_CONFIG" "$BOX" \
    "sudo mysql imscp -e \"UPDATE api_token SET revoked_at = UNIX_TIMESTAMP() WHERE name = 'smoke-scoped';\""
check "a revoked token is 401" "401" "$(status "$SCOPED" '{ apiVersion }')"

echo
echo "TLS:"
PLAIN_STATUS=$(curl -s -o /dev/null -w '%{http_code}' --resolve "$VHOST:$HTTP_PORT:$HOST" \
    -X POST "http://$VHOST:$HTTP_PORT/api/graphql" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d '{"query":"{ apiVersion }"}')
check "plain HTTP is refused" "403" "$PLAIN_STATUS"
check "and the token it carried is now revoked" "401" "$(status "$TOKEN" '{ apiVersion }')"

echo
echo "Clean up:"
ssh -F "$SSH_CONFIG" "$BOX" \
    "sudo mysql imscp -e \"DELETE FROM api_token WHERE name LIKE 'smoke-%';\""
pass "smoke tokens removed"

echo
[ "$FAILED" -eq 0 ] && echo "PASS" || echo "FAIL"
exit "$FAILED"
```

- [ ] **Step 2: Run it**

```bash
chmod +x test/api/smoke.sh
test/api/smoke.sh
```

Expected: `PASS`, with every check `ok`.

If the TLS check fails because the panel's HTTP port redirects to HTTPS before PHP runs, that is nginx doing its job and the check is testing the wrong layer. In that case change it to assert the redirect, and move the token-revocation-on-plaintext assertion into a unit test of `TlsMiddleware` instead. Note the change in the commit message — it is a real finding about where that control actually lives.

- [ ] **Step 3: Add it to the test target**

In `makefile.json`, change the `test` target:

```json
    "test": {
        "by": [
            "sh test/lint/all.sh",
            "vendor/bin/phpunit --configuration test/phpunit.xml",
            "sh test/api/smoke.sh"
        ]
    },
```

- [ ] **Step 4: Commit**

```bash
git add test/api/smoke.sh makefile.json
git commit -m "test: end-to-end smoke test against the box

Everything phase 1 promises, in the order a client meets it: the happy path,
the transport rules, malformed input, scopes, revocation and the plaintext-HTTP
token revocation.

It mints its own tokens and deletes them again, so it can be run repeatedly
against a working box without leaving anything behind."
```

---

## Task 17: Documentation and the first release — `haiku`

**Files:**
- Create: `CHANGELOG.md`, `docs/API.md`
- Modify: `README.md`, `docs/DEVELOPMENT.md`

**Interfaces:**
- Consumes: everything.
- Produces: `SGW_GraphQL.tgz`, built by `make.phar package`.

- [ ] **Step 1: Write `CHANGELOG.md`**

```markdown
# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [0.1.0] — 2026-09-04

The walking skeleton: an authenticated endpoint and the credentials to reach it.

### Added

- `POST /api/graphql`, answering `apiVersion` and `viewer`.
- `GET /api/graphql/schema`, serving the SDL as plain text.
- Opaque bearer tokens with scopes, expiry, per-token revocation, an optional
  address allow-list, and last-used tracking.
- Token management for customers and resellers, under *Profile / API tokens*.
- API access granted or withdrawn per customer, under
  *Reseller / Customers / API access*. Withdrawing revokes that customer's
  tokens.
- TLS enforcement: a request over plain HTTP is refused, and any token it
  carried is revoked.
- Depth and complexity limits, and an introspection switch.

### Requires

- i-MSCP running on **PHP 7.4**. The panel ships on 7.3; moving it needs no
  code changes. See `docs/DEVELOPMENT.md`.
```

- [ ] **Step 2: Write `docs/API.md`**

A client-facing guide, not a restatement of the specification. It must contain, in this order: getting a token from the panel UI; the endpoint and the required headers; a complete working `curl` example with its real response; the error codes from specification §9 as a table with what a client should do about each; the fact that `GET` is refused and why; the fact that responses are `no-store`; the schema endpoint; and a note that the schema grows additively and that enums gain values, so a client must tolerate a value it has not seen.

Keep it to one page. Anything longer belongs in the specification.

- [ ] **Step 3: Update `README.md`**

Change the status line to:

```markdown
**Status: phase 1 complete.** An authenticated endpoint serving `viewer`.
Read and write operations follow — see
[the plan](docs/superpowers/plans/2026-09-04-graphql-plugin-phase-0-1.md) for
what is next.
```

Add `docs/API.md` to the document list.

- [ ] **Step 4: Build the release archive**

```bash
make.phar version bump=minor    # 0.1.0 stays; confirm info.php's date is today
make.phar package
tar -tzf SGW_GraphQL.tgz | head -30
```

Expected: the archive contains `SGW_GraphQL/info.php`, `config.php`, `SGW_GraphQL.php`, `schema/`, `vendor/`, `frontend/`, `themes/`, `l10n/`, `sql/` — and **not** `docs/`, `test/`, `tools/`, `.git/`.

- [ ] **Step 5: Verify the archive installs from clean**

Uninstall and delete the plugin through the panel, then upload `SGW_GraphQL.tgz` through *System tools / Plugin management*, install it, and re-run:

```bash
test/api/smoke.sh
```

Expected: `PASS`. This is the only check that the release archive is complete — a missing `vendor/` would pass every other test in this plan.

- [ ] **Step 6: Commit and tag**

```bash
git add CHANGELOG.md docs/API.md README.md docs/DEVELOPMENT.md info.php
git commit -m "Release 0.1.0

An authenticated GraphQL endpoint serving viewer, with token management and
the permission model. Read and write operations follow."
git tag -a v0.1.0 -m "0.1.0 — the walking skeleton"
```

---

## Self-review

Run against the specification with fresh eyes after writing.

**Spec coverage for phases 0–1.** §2.5 PHP 7.4 → Task 1. §3.3 dependencies → Task 4. §3.4 SDL-first → Task 10. §3.5 global identifiers → Task 5. §3.6 errors as GraphQL errors → Task 7. §4 transport → Tasks 11, 12, 13. §5.1 bearer tokens → Task 9. §5.2 session auth and CSRF → Task 11 plus `csrfToken()` in Task 14. §6.2 API access withdrawal → Tasks 2, 11, 15. §6.4 identity shim → Task 8. §6.5 the `showErrorPage()` hazard → Task 12's shutdown guard. §7.1 provisioning → Task 6 (built now, used in plan 2). §7.2 viewer and scopes → Tasks 9, 10, 12. §9 error model → Task 7. §10.2 query cost → Task 12. §13 layout → the File Structure section, with the `src/` deviation recorded. §14 configuration → Task 2. §15 database → Task 2. §16 panel pages → Tasks 14, 15. §17 testing → Tasks 3, 16 and the per-task unit tests. §18 `apiVersion` → Task 12.

**Deliberately deferred, and where to.** §5.3 `tokenIssue` mutation → plan 3, with the rate limiting it needs. §10.1 batching and §10.3 rate limits → plans 2 and 4. §11 audit → plan 4; the `api_audit` table exists now so the schema does not change later. §12's rate-limit and audit rows of the threat table → plan 4. §21 CORE-DEBT retirement → separate work in `saygoweb/imscp`; the marker convention and its test land here, in Tasks 8 and 3.

**Gap found and closed.** §6.3's `OwnershipResolver` has no task. That is correct for this phase — `viewer` resolves entirely off the `Identity` and reaches no owned object — but the omission would read as an oversight. Recorded here explicitly: **`OwnershipResolver` is Task 1 of plan 2**, and no mutation may ship before it.

**Placeholder scan.** No `TBD`, no "add error handling", no "similar to Task N", no test described without its code. Task 15 Step 4 says "copy the Task 14 files and make exactly these four changes" rather than repeating 200 lines — the four changes are enumerated exactly, and the reason not to factor them is given.

**Type consistency.** `Identity::getRole()` returns the `ROLE_*` constants used by `ViewerResolver` and asserted in `IdentityTest` and `GraphQLHandlerTest`. `Token::getScopes()` returns `string[]`, consumed by `AuthenticateMiddleware` and passed to `Identity`. `TokenService::verify()` returns `?Token` in every caller. `GlobalId::encode()` is called with `'Viewer'` in `ViewerResolver` and asserted with the same literal in `GraphQLHandlerTest`. `ResolverMap::for()` is the only lookup method and is used only by `SchemaFactory`. `Provisioning` is built in Task 6 and has no consumer until plan 2 — intentional, and noted in that task.

**One risk worth stating.** Task 11's test uses `createMock(TokenService::class)`, which fails on a `final` class. Task 11 Step 6 says to drop `final` rather than weaken the test. If a reviewer prefers to keep `final`, extract a `TokenVerifier` interface instead — but decide it in Task 11, not later, because Task 13 wires the concrete class.

---

## Execution

Per the instruction that produced this plan, **implementation is sub-agentic**. Inline execution is not an option here.

**REQUIRED SUB-SKILL:** `superpowers:subagent-driven-development`.

One fresh subagent per task, at the model named in the task heading. Between tasks, the orchestrator reviews the diff against that task's **Interfaces** block before dispatching the next. Dispatch each subagent with the task text, the [Global Constraints](#global-constraints) section, and the path to `docs/SPECIFICATION.md`.

Tasks run in order. Task 1 is in a different repository and must land before any other task begins.
