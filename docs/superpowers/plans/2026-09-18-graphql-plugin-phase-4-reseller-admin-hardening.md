# GraphQL plugin, phase 4 — reseller and administrator mutations, hardening, release

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the API — a reseller manages customers, hosting plans and alias orders; an administrator manages resellers; the endpoint is rate limited, audited and explorable; the plugin ships as a release.

**Architecture:** The service layer of phase 3 continues unchanged: a service per entity, the six refusals of spec §8.1 in order, `Writer::run()` around the write, `Core` for anything that touches the panel's globals. Two things are new. First, the reseller and administrator writes act on *accounts* rather than on a customer's objects, so ownership walks one link further and the limit arithmetic is two-sided — a customer's new limit is measured against what the reseller has left, not only against what the customer has used. Second, phase 6's work is not entity work at all: a rate limiter, an audit writer and one unauthenticated field, each of which sits in the request path rather than under a resolver.

**Tech Stack:** PHP 7.4 (linting clean under 8.3), `webonyx/graphql-php` ^15, PHPUnit 9, MariaDB, APCu, i-MSCP 1.5.x on the `imscp-imscp` docker box.

**Spec:** `docs/SPECIFICATION.md` — phases 4, 5, 6 and 7 of §19. Read §5 (authentication), §6 (authorisation), §7.8 and §7.11 (the schema and the remaining mutations), §8 (mutation semantics), §10 (performance and abuse limits), §11 (observability), §15 (database) and §16 (panel pages) before Task 1.

## Global Constraints

- **PHP 7.4 is the target and 8.3 must lint.** `array()` syntax, no constructor promotion, no `match`, no enums, no union types, no first-class callable syntax. `test/lint/all.sh` runs both.
- **The plugin root is the PSR-4 root.** `iMSCP\Plugin\SGW_GraphQL\` maps to `.`, not to `src/`.
- **No panel global is called outside `Service\PanelCore`.** `test/unit/Security/CoreCallsTest.php` tokenises every API source file and fails on a call to anything outside its `ALLOWED` list. Adding a call means adding it to that list *and* to `PanelCore`, with a `CORE-DEBT` marker.
- **Every `CORE-DEBT(Cn)` marker names an item in spec §21**, or `test/lint/all.sh` fails. This plan adds **items 12 and 13 to C11** in Task 3; it introduces no new C-number.
- **The six refusals, in spec §8.1's order:** ownership (`NOT_FOUND`) → scope (`FORBIDDEN`) → feature (`FEATURE_UNAVAILABLE`) → settled (`CONFLICT`) → input (`BAD_USER_INPUT`) → quota (`LIMIT_EXCEEDED`) → transaction → daemon → log.
- **Provisioning is asynchronous.** A write sets a status verb (`toadd`, `tochange`, `todelete`, `todisable`, `toenable`) and pokes the daemon; it never waits. SQL objects and hosting plans are the synchronous exceptions (spec §2.1).
- **A `Secret` never reaches a log line, an error message, an error extension or the audit table.** The panel's own welcome mail carries a cleartext password by its own design (M6); nothing this plan writes may add another path.
- **Mutation fields are non-null** (spec §7.11), so a failed mutation nulls the whole `data`. The `Mutation` SDL description already warns clients; do not change it.
- **Money-free vocabulary.** The API has no notion of billing; `hosting_plans` is a set of limits, nothing more.

---

## What this phase inherits

Phases 0–3 are merged and tagged `phase3-done`. In place, and not to be rebuilt:

| Already built | Where | What phase 4 does with it |
| --- | --- | --- |
| `Auth\TokenService` — `issue()`, `verify()`, `revoke()`, `revokeAllFor()`, `listFor()`, `stampLastUsed()` | `Auth/TokenService.php` | Task 12 puts `issue()` and `revoke()` behind GraphQL fields. The service itself does not change. |
| `Auth\AccessService::setApiAccess(int $adminId, bool $allowed)` | `Auth/AccessService.php` | Task 6 puts it behind two mutations. |
| `api_token`, `api_perm`, `api_audit` tables | `sql/001_create_api_tables.php` | Task 13 is the first writer of `api_audit`. No migration is needed. |
| `QueryDepth`, `QueryComplexity`, `DisableIntrospection` | `Http/GraphQLHandler.php:236-245` | Task 14 makes list fields *declare* a complexity; the rules already run. |
| `Support\PlanProps` — the 25-field `hosting_plans.props` reader | `Support/PlanProps.php` | Task 2 adds the writer and the round-trip property test. |
| `Security\Guard`, `Service\Writer`, `Service\Toolkit`, `Repository\Accounts` | phase 3 | Every service in this plan uses them unchanged. |
| The authorisation matrix, 24 mutations × 6 roles | `test/authz/` | Every task that adds a mutation adds its catalogue row in the same commit. |
| `test/api/provision.php` | phase 3 | Task 16 extends it with a customer create/delete cycle. |

---

## Measurements

Every row was taken on the box or from `../imscp` at `bf82c00`, not inferred. The plan argues from these; where one turns out to be wrong, the task that depends on it is the one to change.

| # | Question | How it was measured | Answer |
| --- | --- | --- | --- |
| M1 | How does the panel create a customer? | Read `gui/public/reseller/user_add{1,2,3}.php` | Three pages, with `$_SESSION['dmn_name']`, `dmn_tpl`, `dmn_expire`, `dmn_url_forward`, `step_two_data`, `ch_hpprops` carrying state between them. `user_add3.php:84-330` does all the writing. |
| M2 | What does that write consist of? | `user_add3.php:150-290` | One `admin` row (`admin_status = 'toadd'`), one `domain` row (`domain_status = 'toadd'`, 31 columns), a `PhpEditor` save, `createDefaultMailAccounts()` when `CREATE_DEFAULT_EMAIL_ADDRESSES`, `send_add_user_auto_msg()`, one `user_gui_props` row, `update_reseller_c_props()`, then `commit()`, `send_request()`, `write_log()`. |
| M3 | Which events? | `user_add3.php:174,268` | `onBeforeAddDomain` / `onAfterAddDomain`, each with `createdBy`, `customerId`, `customerEmail`, `domainName`, `mountPoint => '/'`, `documentRoot => '/htdocs'`, `forwardUrl`, `forwardType`, `forwardHost`, `wildcardAlias`; the "after" one also carries `domainId`. There is no `onBeforeAddCustomer`. |
| M4 | How is an account password hashed? | `user_add3.php:160`, `user_edit.php:88`, `reseller_add.php:381` | `iMSCP\Crypt::apr1MD5()` — APR-1, *not* the `Crypt::sha512()` that `mail_users` and `ftp_users` use (phase 3, `PanelCore::hashPassword()`). An account and a mailbox hash differently and the difference is not optional. |
| M5 | What is in `hosting_plans.props`? | `hosting_plan_add.php:446-457` against `user_add3.php:133-144` | 25 semicolon-separated fields, written and read in the same order: php, cgi, sub, als, mail, ftp, sqld, sqlu, traffic, disk, backup (pipe-joined), dns, phpiniSystem, allowUrlFopen, displayErrors, disableFunctions, mailFunction, postMaxSize, uploadMaxFileSize, maxExecutionTime, maxInputTime, memoryLimit, extMail, webFolderProtection, mailQuota (bytes). |
| M6 | Does the panel mail a cleartext password? | `user_add3.php:255`, `user_edit.php:104`, `reseller_add.php:434` | Yes: `send_add_user_auto_msg()` takes the cleartext and sends it, on customer create, on a password change, and on reseller create. It is the panel's design, not an accident, and `sendWelcomeEmail` selects it. |
| M7 | Are the six `_yes_`/`_no_` props stored with underscores? | `user_add3.php:145-150` | Yes, and the page strips them with `str_replace('_', '', …)` for php, cgi, backup, dns, extMailServer and webFolderProtection before writing the `domain` row. The plan's codec must do the same, in one place. |
| M8 | What rule governs a customer's new limit? | `reseller/domain_edit.php:1045-1102` `isValidServiceLimit()` | Four refusals, in a stated order the page's own comment says not to change: (1) unlimited is refused when the reseller is itself limited; (2) "disabled" is refused when the customer already has some; (3) the new limit may not exceed `(resellerLimit - resellerConsumption) + customerLimit`; (4) a finite, non-zero limit may not be below what the customer already uses. |
| M9 | Which reseller counters exist, and who maintains them? | `Shared.php:385` `update_reseller_c_props()`, `reseller_props` DDL | `current_dmn_cnt`, `current_sub_cnt`, `current_als_cnt`, `current_mail_cnt`, `current_ftp_cnt`, `current_sql_db_cnt`, `current_sql_user_cnt`, `current_traff_amnt`, `current_disk_amnt`, each beside its `max_*`. `update_reseller_c_props($resellerId)` recomputes all of them from the customers' rows. |
| M10 | How does the panel delete a customer? | `Shared.php:779-1005` `deleteCustomer()` | One function: deletes `login`, SQL databases (synchronously, through `delete_sql_database()`), htaccess rows, traffic, DNS, `ftp_group`, `quotalimits`, `quotatallies`, tickets, `user_gui_props`, `php_ini`; schedules `todelete` on ftp, mail, subdomain aliases, aliases, subdomains, the domain, the admin and four classes of `ssl_certs`; prunes the autoreply log; calls `update_reseller_c_props()`; dispatches `onBeforeDeleteCustomer` / `onAfterDeleteCustomer`; commits; `send_request()`. |
| M11 | Is `deleteCustomer()` callable from the API path? | Read it against D10's four conditions | Yes, with `$checkCreatedBy = false`. Every identity is an argument, it returns `false` rather than exiting, it reaches no `showErrorPage()`, and its only DDL is inside `delete_sql_database()`, which issues `DROP DATABASE` — a real exception to condition (4), handled by D22. |
| M12 | How does the panel enable and disable a customer? | `reseller/domain_status_change.php:37-60`, `Shared.php:445` | The page reads `domain_status`, refuses anything but `ok` → `deactivate` and `disabled` → `activate`, then `change_domain_status($customerId, $action)` sets `todisable` or `toenable` across the domain and everything under it. |
| M13 | How is an alias order approved? | `reseller/alias_order.php:77-130` | `alias_status` `'ordered'` → `'toadd'`, `createDefaultMailAccounts($domainId, $email, $aliasName, MT_ALIAS_FORWARD, $aliasId)` when configured, `onBeforeAddDomainAlias` / `onAfterAddDomainAlias`, commit, `send_request()`. |
| M14 | And rejected? | `reseller/alias_order.php:39-71` | `DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'`, then `DELETE FROM domain_aliasses WHERE alias_id = ? AND alias_status = 'ordered'`. The row is removed, not scheduled. Note this page *does* delete the `php_ini` row — it is `client/alias_order_delete.php` that forgets to (C11 item 8). |
| M15 | How is a hosting plan written? | `hosting_plan_add.php:431-468`, `hosting_plan_edit.php:487-515` | A name unique per reseller, the 25-field props string, `status` 0 or 1, and `reseller_limits_check($resellerId, $props)` before the insert. No transaction, no daemon: `hosting_plans` is a panel-only table. |
| M16 | How is a reseller created? | `admin/reseller_add.php:255-440` | One `admin` row with `admin_type = 'reseller'` **and no `admin_status` column at all**, one `user_gui_props` row, one `reseller_props` row of 31 columns with every `current_*` explicitly `'0'`, `onBeforeAddUser` / `onAfterAddUser`, commit, `send_add_user_auto_msg()`, `write_log()`. No `send_request()`: a reseller has nothing for the daemon to build. |
| M17 | What is `reseller_props.reseller_ips`? | `reseller_add.php:395-400`, `user_add3.php:103-116` | A semicolon-separated list of `server_ips.ip_id` values with a **trailing semicolon**, sorted ascending. A customer's `domain_ip_id` must be in its reseller's list, which `user_add3.php` checks with `in_array()` on strings. |
| M18 | Is APCu present and enabled on the box? | `../imscp/docker/imscp exec php7.4 -r 'var_dump(function_exists("apcu_fetch"), ini_get("apc.enabled"));'` | To be confirmed by Task 11 on the box before the limiter is written. Spec §10.3 says it is present in the reference box; the fallback exists because a plugin cannot assume it everywhere. |
| M19 | What does graphql-php give a validation rule to key off? | `Http/GraphQLHandler.php:247-280` | Nothing but the message text, which the handler already matches structurally. Task 14's list complexity uses the *schema's* `complexity` directive path instead, which is data, not a message. |
| M20 | Does `admin` have an `admin_status` default? | `gui/../sql` schema for `admin` | Task 10 confirms on the box before writing the reseller insert; the page relies on whatever it is (M16). |

---

## Decisions

Numbering continues from plan 3, which ended at D20.

**D21 — a reseller write is measured against two ledgers, and `Support\LimitRules` owns the arithmetic.**

M8's four refusals are the panel's only statement of what a reseller may give away, and they are spread through a 1,183-line page mixed with `set_page_message()`. Every one of `customerCreate`, `customerUpdate`, `hostingPlanCreate` and `hostingPlanUpdate` needs them.

**Decision:** transcribe them into `Support\LimitRules`, a pure class with no panel dependency, taking the five numbers as arguments and returning a reason string or `null` — the same shape `Support\VhostRules` established in phase 3. The order of the four tests is preserved exactly, because the page's own comment says it is load-bearing: a request that is wrong in two ways must be told the same thing the panel would tell it. `CORE-DEBT(C3)` cites `domain_edit.php:1045`.

**D22 — `deleteCustomer()` is called, not transcribed, and it is the one place the API accepts DDL inside a service.**

M10 is 226 lines touching 20 tables. Transcribing it would be the largest single piece of duplication in the plugin, and every future i-MSCP change to what a customer owns would silently desynchronise. M11 says it is callable: identities are arguments, it returns `false` instead of exiting, and it commits its own transaction.

The catch is condition (4) of D10 — no DDL. `deleteCustomer()` reaches `delete_sql_database()`, which drops real schemas, and DDL implicitly commits (phase 3's M5).

**Decision:** call it, through `Core::deleteCustomer(int $customerId): bool`, and **outside** any `Writer::run()`. The service performs its refusals, commits nothing of its own, and then calls the port; the port owns the transaction, as the panel does. `CustomerService::delete()` therefore has no `Writer::run()` at all, and its test asserts that a refusal happens before the call rather than after. D10's condition (4) gains a stated exception rather than being quietly bent: a core helper that manages its own transaction *and* its own DDL may be called, provided the API opens no transaction around it. Task 5 amends spec §3.2.

*Cost if wrong:* a customer delete that fails half way leaves the panel's own inconsistency, which is exactly what the panel leaves today.

**D23 — the API creates a customer in one call, and the three-step wizard's validation is not reordered.**

M1's three pages validate in an order the session state forces: the domain name first, then the plan or the explicit allowances, then the IP and the contact details. The API takes one input (spec §7.11), so it *could* validate in any order.

**Decision:** keep the panel's order anyway, expressed as the §8.1 sequence: ownership of the reseller → scope → feature → input, in the order the pages ask (domain name, then plan-or-allowances, then IP, then contact) → quota. A customer whose request is wrong in two ways gets the same first complaint from the API as from the panel, which is the property that makes the two interchangeable. The one reordering is §8.1's own: every refusal that does not depend on input comes first.

**D24 — `hostingPlanId` and `allowances` are exclusive, and the API refuses rather than guesses.**

Spec §7.11 says "exactly one of hostingPlanId or allowances must be given". The panel expresses the same choice as a wizard branch (`$_SESSION['ch_hpprops']` versus a plan id).

**Decision:** both absent or both present is `BAD_USER_INPUT` on `input`, named as such, before anything else in the input group. A plan id resolves to its props through `Support\PlanProps`; `allowances` builds the same 25-field structure directly. Both paths converge on one internal value object, so the code that writes the `domain` row cannot tell which was used.

**D25 — a reseller's own limits are never widened by a customer write, and `update_reseller_c_props()` is the only writer of the counters.**

M9's `current_*` counters are derived. A service that adjusts them itself would be a second source of truth, and phase 3 already learned what a second source of truth costs (checkpoint B).

**Decision:** no service writes a `current_*` column. Every path that the panel follows with `update_reseller_c_props($resellerId)` does the same, through `Core::updateResellerCounters(int $resellerId): void`. `CoreCallsTest`'s list gains `update_reseller_c_props`.

**D26 — the rate limiter is a middleware, keyed by token where there is one and by source address where there is not.**

Spec §10.3 gives three buckets with different keys: queries and mutations are per token, `tokenIssue` is per source IP *and* per username. A limiter that lives in the resolver cannot see the operation type before the document is parsed, and one that lives in the handler cannot return `429` without building a GraphQL response.

**Decision:** `Http\RateLimitMiddleware` runs after authentication and before the handler, and counts the *request*, not the fields: one query bucket or one mutation bucket per request, decided from the parsed operation the handler hands back through a shared `Service\RateLimiter`. `tokenIssue`'s two buckets are charged by the resolver itself, because only it knows the username, and it charges them *before* `AuthService::authenticate()` so that a wrong password costs the same as a right one. Exceeding any bucket is HTTP `429` with `Retry-After`, and a GraphQL error carrying `extensions.code = RATE_LIMITED` and `extensions.retryAfterSeconds` — the extension `Guard` already defines.

**D27 — the audit row is written after the response is formed, and redaction is by type.**

Spec §11 is explicit that redaction walks the operation's argument types and replaces anything typed `Secret`, so that a new secret-carrying field is redacted by virtue of its type and not by being added to a list.

**Decision:** `Service\Audit::record()` takes the parsed document, the variables, the schema and the result, walks `OperationDefinitionNode`'s variable definitions against the schema's types, and replaces every value whose type resolves to `Secret` — including inside input objects and lists — with `***`. It is called once, in the handler's `finally`, so a request that throws is still audited. A failure to write the audit row never fails the request: it is logged through `Core::writeLog()` and swallowed.

**D28 — the explorer is a panel page, and its assets are vendored.**

Spec §16 says so, and gives the reason: the panel is frequently run without outbound access.

**Decision:** `frontend/client/api_explorer.php` (and the reseller and admin twins) renders GraphiQL from `themes/default/assets/`, with the CSRF header of §5.2 already filled in from the session. The assets are committed, their versions and SRI-irrelevant provenance recorded in `docs/DEVELOPMENT.md`, and `test/lint/all.sh` gains a check that nothing under `themes/` references an external origin.

**D30 — `CustomerCreateInput` gains a `resellerId`, required of an administrator and refused to a reseller.**

Spec §7.11's sketch has no reseller field, because the panel has no such page: an administrator who wants to add a customer switches into the reseller's interface first (`admin/change_user_interface.php`). The API has no interface to switch into, and every customer must belong to some reseller — `admin.created_by` is not nullable and `update_reseller_c_props()` needs an id.

**Decision:** add `resellerId: ID` to `CustomerCreateInput` and `HostingPlanInput`. An administrator must give it; a reseller may omit it, and naming any reseller but itself is `FORBIDDEN` rather than `NOT_FOUND` — the caller has named its own role, not an object it cannot see, so there is nothing to hide. This is the third documented difference between §7.11's sketch and the shipped SDL, alongside the two phase 3 recorded.

*Cost if wrong:* an administrator's `customerCreate` needs one more argument than the spec's example shows, and §7.11's sketch needs a footnote.

**D31 — an update's `allowances` is merged; a `hostingPlanId` replaces.**

Phase 3's D14 made every update partial. `CustomerAllowancesInput` has 25 fields, and a reseller raising one mail limit must not have the customer's PHP permissions silently reset to the input's defaults.

**Decision:** `customerUpdate` merges a given `allowances` over the customer's current values, read back out of the `domain` row in the input's own terms, and only then builds the props. `hostingPlanId` is the opposite and says so in the SDL: it replaces every allowance with the plan's. `hostingPlanCreate`/`hostingPlanUpdate` take a **required** `allowances` and replace whole, because a plan has no prior value to merge with.

**D32 — `tokenIssue` borrows the panel's authentication and gives back the session immediately.**

M21: `AuthService::authenticate()` reads `$_POST` and, on success, calls `setIdentity()`, which regenerates the session and writes a `login` row. Spec §5.3 wants neither, and wants everything else the call brings — the `BruteForce` plugin, `login_checkDomainAccount()`'s status and expiry checks, the APR-1 rehash of a legacy password.

Two ways out: stop the `onBeforeSetIdentity` event, which prevents the session but calls `session_destroy()` and would take a panel session with it; or let the identity be set and unset it at once.

**Decision:** the second. `Core::authenticate()` populates `$_POST`, calls `authenticate()`, and in a `finally` calls `unsetIdentity()` and restores `$_POST` — so the `login` row is deleted on every path, including the throwing one. The window in which a session exists is that method's body, on a request that carries no session cookie to take it anywhere. A test asserts no `login` row survives, on success and on failure.

*Cost if wrong:* a `tokenIssue` that throws between the two calls leaves a `login` row for a session nobody holds, which the panel's own session garbage collection removes.

**D35 — a trusted client gets a bigger bucket, not no bucket, and never for `tokenIssue`.**

A first-party integration — the panel at `my.saygoweb.com` mirroring its resellers — runs on this box or a peer of it, and will exceed 120 queries and 30 mutations a minute the first time it mirrors anything. The mechanism to recognise it already exists: `trusted_proxies` is the precedent for an operator-configured address list, and `TokenService::addressIsAllowed()` is a tested CIDR matcher.

**Decision:** `trusted_clients` (CIDRs, empty by default) selects `rate_limit_queries_trusted` and `rate_limit_mutations_trusted` in place of the ordinary buckets. Two things it deliberately is not:

- **Not an exemption.** A runaway first-party integration is a real failure mode, and "unlimited" means it can take the panel down. Ten times the ordinary limit solves mirroring while still catching a loop.
- **Not applied to `tokenIssue`.** That is the one unauthenticated field. Tokens last a year by default, so a mirroring integration mints rarely, and an install that genuinely needs a burst raises `rate_limit_token_issue` globally or mints through the panel UI. The argument for relaxing it — that Checkpoint D restored the panel's BruteForce plugin on this path, and spec §10.3 makes the plugin's limit additional to it rather than instead of it — is real but does not survive the deployment the plugin actually ships into: on a shared box, "same box" includes every tenant's PHP, and an IP-based exemption there is unlimited credential guessing at the plugin layer. The reference install is operator-controlled, but the shipped default must be safe for the install that is not.

*Cost if wrong:* an operator with a genuinely trusted integration that mints many tokens at once has to raise one more key, which is visible and reversible.

---

**D33 — phase 7 is a task, not a ceremony.**

**Decision:** Task 16 does the release work as one commit series: `CHANGELOG.md`, `README.md`, the version bump in `info.php`, the schema version, `tools/package.sh` and one green `test/api/provision.php` run extended with a customer lifecycle. No task in this plan is "prepare for release"; the release is the last task and it either passes or it does not.

---

## File structure

| File | Responsibility | Tasks |
| --- | --- | --- |
| `Support/LimitRules.php` | **New.** M8's four refusals, pure | 1 |
| `Support/Allowances.php` | **New.** The 25 props fields as a value object, from a plan or from input | 2 |
| `Support/PlanProps.php` | **Modified.** Gains `toProps(Allowances): string` | 2 |
| `Repository/Accounts.php` | **Modified.** Gains `reseller(int $resellerId): ResellerAccount` | 1 |
| `Service/ResellerAccount.php` | **New.** A reseller's props and counters, read once | 1 |
| `Service/CustomerService.php` | **New.** create, update, setState, delete, setApiAccess | 3, 4, 5, 6 |
| `Service/HostingPlanService.php` | **New.** create, update, delete | 7 |
| `Service/DomainAliasService.php` | **Modified.** Gains `approve()`, `reject()` | 8 |
| `Service/ResellerService.php` | **New.** create, update, delete, setApiAccess | 10 |
| `Service/PanelCore.php` | **Modified.** Gains `deleteCustomer()`, `changeDomainStatus()`, `updateResellerCounters()`, `sendAccountCreatedEmail()`, `hashAccountPassword()`, `authenticate()` | 3, 5, 10, 12 |
| `Service/Core.php` | **Modified.** The same six methods on the port | 3, 5, 10, 12 |
| `Service/RateLimiter.php` | **New.** APCu with a database fallback | 11 |
| `sql/002_create_rate_table.php` | **New.** `api_rate`, dropped on uninstall with the others | 11 |
| `Http/RateLimitMiddleware.php` | **New.** Charges the query and mutation buckets | 11 |
| `Service/Audit.php` | **New.** The audit row and type-driven redaction | 13 |
| `Resolver/CustomerMutations.php` | **New.** | 9 |
| `Resolver/ResellerMutations.php` | **New.** | 10 |
| `Resolver/TokenMutations.php` | **New.** | 12 |
| `schema/schema.graphql` | **Modified.** 16 more mutations, their inputs, `AccountState`, `TokenIssueResult` | 9, 10, 12 |
| `frontend/*/api_explorer.php` | **New.** The explorer, three roles | 15 |
| `themes/default/assets/` | **New.** Vendored GraphiQL | 15 |
| `docs/API.md`, `CHANGELOG.md`, `README.md`, `info.php` | **Modified.** | 16 |
| `docs/SPECIFICATION.md` | **Modified.** §3.2 (D22's exception), §21 C12 | 3, 5 |

---

## Waves and checkpoints

Five waves. A `code-review medium` runs at the end of each, over the wave's own range, with the focus list the checkpoint gives. There is no review between tasks inside a wave: the gate between tasks is green tests, a commit, and every name in the task's **Produces** block present in the tree.

| Wave | Tasks | Ends at | Why here |
| --- | --- | --- | --- |
| 1 | 1–3 | Checkpoint A | The limit arithmetic and the props codec are load-bearing for everything after them, and `customerCreate` is the largest transcription in the plugin. A defect here is cheapest to find before four more services copy the pattern. |
| 2 | 4–8 | Checkpoint B | The rest of the customer lifecycle plus plans and alias orders — all of them reseller-scoped writes with the same shape. Reviewing them together is what makes an inconsistency between them visible. |
| 3 | 9–10 | Checkpoint C | The GraphQL surface and the administrator's reseller writes. After this the matrix runs every mutation in §7.11 and nothing is skipped. |
| 4 | 11–13 | Checkpoint D | The security wave: the unauthenticated field, the limiter that protects it, and the audit that records it. This is the one checkpoint whose focus list is entirely about what an attacker can do. |
| 5 | 14–16 | Checkpoint E | Cost, the explorer, the documents and the release. |

**Execution mandate.** At each checkpoint the orchestrating session, not a subagent:

1. Runs `tools/test.sh` twice; both must be green.
2. Invokes the `code-review` skill with `medium phase4-wave-N..HEAD` and the checkpoint's **Focus** list.
3. Rules on every finding — the spec is the authority, the plan is its argument — and fixes the ones that hold in commits whose subjects begin `Fix checkpoint X:`. A declined finding is recorded with its reason.
4. Runs the suite again, then tags the next wave's base:
   `git tag -a phase4-wave-<N+1> -m "Checkpoint X: <declined findings and why, or: none declined>"`.

**Model per task.** Chosen for value, not for caution: transcription with the code in front of you is cheap work, judgement against a live system is not.

| Task | Model | Why |
| --- | --- | --- |
| 1 Limit rules | `haiku` | Pure functions, four rules, every value in the brief. |
| 2 Allowances and props | `sonnet` | A round-trip codec with a property test; more judgement than transcription. |
| 3 `customerCreate` | `opus` | Three pages into one call, a new spec item, the widest blast radius in the plan. |
| 4 `customerUpdate` | `sonnet` | Two pages, but the shape is Task 3's. |
| 5 `setState` and `delete` | `sonnet` | Small, but D22's transaction rule needs care. |
| 6 API access | `haiku` | Two mutations over an existing service method. |
| 7 Hosting plans | `sonnet` | Three mutations, synchronous, plan-limit checks. |
| 8 Alias approve and reject | `haiku` | M13 and M14 are 90 lines between them. |
| 9 The customer surface | `sonnet` | SDL, resolvers, wiring, matrix rows. |
| 10 Resellers | `sonnet` | Transcription plus the surface, same shape as 9. |
| 11 The rate limiter | `opus` | Concurrency, a cache that may not exist, and a fallback that must not lie. |
| 12 `tokenIssue` / `tokenRevoke` | `opus` | The only unauthenticated field in the schema. |
| 13 Audit and redaction | `opus` | Redaction by type is the one mechanism that stops a password being logged. |
| 14 List complexity | `sonnet` | Schema work with a counting test. |
| 15 The explorer | `sonnet` | Three pages, vendored assets, CSRF. |
| 16 Release | `sonnet` | Documents, packaging, one end-to-end run. |

---
## Wave 1: the reseller's ground

Tasks 1–3. Ends at Checkpoint A.

---

## Task 1: What a reseller may give away — `haiku`

M8's four refusals and the reseller's own row, both of which every later task needs. Pure code and one repository method; no mutation yet.

**Files:**
- Create: `Support/LimitRules.php`
- Create: `Service/ResellerAccount.php`
- Create: `test/unit/Support/LimitRulesTest.php`
- Create: `test/integration/ResellerAccountTest.php`
- Modify: `Repository/Accounts.php`

**Interfaces:**
- Consumes: `Repository\Db` (`row()`, `value()`, `execute()`), `Support\Quota::fromCustomerLimit(int $limit, int $used): Quota`.
- Produces:
  ```php
  LimitRules::reason(int $newLimit, int $customerUsed, int $customerLimit,
                     int $resellerUsed, int $resellerLimit, string $service): ?string
  LimitRules::SERVICES                                  // array<string, string> allowance => human name
  new ResellerAccount(array $admin, array $props)
  ResellerAccount::getAdminId(): int
  ResellerAccount::getUsername(): string
  ResellerAccount::getEmail(): string
  ResellerAccount::prop(string $column)                 // a reseller_props column, raw
  ResellerAccount::maxOf(string $allowance): int        // -1 | 0 | n
  ResellerAccount::usedOf(string $allowance): int
  ResellerAccount::ipIds(): int[]                       // reseller_ips, exploded, trailing ';' dropped
  ResellerAccount::hasIp(int $ipId): bool
  Accounts::reseller(int $resellerId): ResellerAccount  // throws ApiException NOT_FOUND
  ```

- [ ] **Step 1: Write the failing test for the rules**

Create `test/unit/Support/LimitRulesTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Unit\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use PHPUnit\Framework\TestCase;

class LimitRulesTest extends TestCase
{
    /** The panel's own order: domain_edit.php:1050 says not to change it. */
    public function testUnlimitedIsRefusedWhenTheResellerIsItselfLimited(): void
    {
        self::assertSame(
            'The subdomains limit for this customer cannot be unlimited because you are limited for this service.',
            LimitRules::reason(0, 0, 5, 10, 50, 'subdomains')
        );
        self::assertSame(
            'The subdomains limit for this customer cannot be unlimited because you are limited for this service.',
            LimitRules::reason(0, 0, 5, 10, -1, 'subdomains'),
            'a reseller withheld from the service is limited too'
        );
        self::assertNull(
            LimitRules::reason(0, 0, 5, 10, 0, 'subdomains'),
            'an unlimited reseller may give an unlimited customer'
        );
    }

    public function testWithholdingIsRefusedWhenTheCustomerAlreadyHasSome(): void
    {
        self::assertSame(
            "The subdomains limit for this customer cannot be set to 'disabled' because they already have 3 subdomains.",
            LimitRules::reason(-1, 3, 5, 10, 0, 'subdomains')
        );
        self::assertNull(LimitRules::reason(-1, 0, 5, 10, 0, 'subdomains'));
    }

    public function testTheNewLimitMayNotExceedWhatTheResellerHasLeftPlusWhatThisCustomerHolds(): void
    {
        // The reseller may sell 50 and has sold 10, of which this customer
        // holds 5: 50 - 10 + 5 = 45.
        self::assertNull(LimitRules::reason(45, 0, 5, 10, 50, 'subdomains'));
        self::assertSame(
            'The subdomains limit for this customer cannot be greater than 45, your calculated limit.',
            LimitRules::reason(46, 0, 5, 10, 50, 'subdomains')
        );
    }

    public function testAFiniteLimitMayNotBeBelowWhatTheCustomerAlreadyUses(): void
    {
        self::assertSame(
            'The subdomains limit for this customer cannot be lower than 7, the total they already use.',
            LimitRules::reason(5, 7, 10, 0, 0, 'subdomains')
        );
        self::assertNull(LimitRules::reason(7, 7, 10, 0, 0, 'subdomains'));
        self::assertNull(
            LimitRules::reason(0, 7, 10, 0, 0, 'subdomains'),
            'unlimited is not "below" anything'
        );
    }

    public function testTheOrderOfTheTestsIsTheOrderTheyAreReported(): void
    {
        // Unlimited asked of a limited reseller, and the customer already has
        // more than the limit asked for: the panel reports the first.
        self::assertSame(
            'The subdomains limit for this customer cannot be unlimited because you are limited for this service.',
            LimitRules::reason(0, 99, 5, 10, 50, 'subdomains')
        );
    }

    public function testEveryAllowanceHasAName(): void
    {
        foreach (array('subdomains', 'domainAliases', 'mailAccounts', 'ftpUsers', 'sqlDatabases', 'sqlUsers') as $one) {
            self::assertArrayHasKey($one, LimitRules::SERVICES);
            self::assertNotSame('', LimitRules::SERVICES[$one]);
        }
    }

    public function testAnUnknownServiceIsARefusalToGuess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LimitRules::reason(1, 0, 0, 0, 0, 'wombats');
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `tools/test.sh --testsuite unit --filter LimitRulesTest`
Expected: FAIL, `Class "iMSCP\Plugin\SGW_GraphQL\Support\LimitRules" not found`.

- [ ] **Step 3: Write the rules**

Create `Support/LimitRules.php` (the licence header every file in this tree carries — copy it from `Support/VhostRules.php`):

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

/* … the GPL header, verbatim from Support/VhostRules.php … */

use InvalidArgumentException;

/**
 * Whether a reseller may give a customer a limit, measured against both
 * ledgers: what the customer already uses, and what the reseller has left.
 *
 * CORE-DEBT(C3): transcribed from gui/public/reseller/domain_edit.php:1045-1102
 *   isValidServiceLimit(). The four tests keep the page's order, which its own
 *   comment ("Please, don't change test order") says is load-bearing: a request
 *   that is wrong in two ways must be told the same thing the panel would tell
 *   it. Retire when CustomerService lands in core.
 *
 * The vocabulary is spec section 2.3's: -1 withheld, 0 unlimited, n = n.
 */
final class LimitRules
{
    const WITHHELD  = -1;
    const UNLIMITED = 0;

    /** The GraphQL allowance name => the noun the message uses. */
    const SERVICES = array(
        'subdomains'    => 'subdomains',
        'domainAliases' => 'domain aliases',
        'mailAccounts'  => 'mail accounts',
        'ftpUsers'      => 'FTP accounts',
        'sqlDatabases'  => 'SQL databases',
        'sqlUsers'      => 'SQL users'
    );

    /**
     * @param int    $newLimit        what the caller is asking for
     * @param int    $customerUsed    how many the customer already has
     * @param int    $customerLimit   the customer's limit today
     * @param int    $resellerUsed    the reseller's current_* counter (M9)
     * @param int    $resellerLimit   the reseller's max_* counter
     * @param string $service         a key of self::SERVICES
     * @return string|null            the refusal, or null when it is allowed
     * @throws InvalidArgumentException for an unknown service
     */
    public static function reason(
        int $newLimit, int $customerUsed, int $customerLimit,
        int $resellerUsed, int $resellerLimit, string $service
    ): ?string {
        if (!isset(self::SERVICES[$service])) {
            throw new InvalidArgumentException(sprintf('There is no "%s" allowance.', $service));
        }

        $name = self::SERVICES[$service];

        // 1. The page reads "($resellerLimit == -1 || $resellerLimit > 0)" as
        //    "the reseller is not unlimited", so a reseller withheld from the
        //    service cannot hand out an unlimited one either.
        if (($resellerLimit === self::WITHHELD || $resellerLimit > 0) && $newLimit === self::UNLIMITED) {
            return sprintf(
                'The %s limit for this customer cannot be unlimited because you are limited for this service.', $name
            );
        }

        if ($newLimit === self::WITHHELD && $customerUsed > 0) {
            return sprintf(
                "The %s limit for this customer cannot be set to 'disabled' because they already have %d %s.",
                $name, $customerUsed, $name
            );
        }

        if ($resellerLimit !== self::UNLIMITED
            && $newLimit > ($resellerLimit - $resellerUsed) + $customerLimit
        ) {
            return sprintf(
                'The %s limit for this customer cannot be greater than %d, your calculated limit.',
                $name, ($resellerLimit - $resellerUsed) + $customerLimit
            );
        }

        if ($newLimit !== self::WITHHELD && $newLimit !== self::UNLIMITED && $newLimit < $customerUsed) {
            return sprintf(
                'The %s limit for this customer cannot be lower than %d, the total they already use.',
                $name, $customerUsed
            );
        }

        return null;
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `tools/test.sh --testsuite unit --filter LimitRulesTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Write the failing test for the reseller's row**

Create `test/integration/ResellerAccountTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;

class ResellerAccountTest extends IntegrationTestCase
{
    public function testAResellerReadsBackWithItsLimitsAndCounters(): void
    {
        $reseller = $this->accounts()->reseller($this->fixture->resellerId());

        self::assertSame($this->fixture->resellerId(), $reseller->getAdminId());
        self::assertNotSame('', $reseller->getUsername());
        self::assertSame(
            (int)$this->db->value('SELECT max_sub_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())),
            $reseller->maxOf('subdomains')
        );
        self::assertSame(
            (int)$this->db->value('SELECT current_sub_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())),
            $reseller->usedOf('subdomains')
        );
    }

    public function testTheIpListDropsItsTrailingSemicolon(): void
    {
        // M17: the panel stores "1;2;" - sorted, with a trailing separator.
        $this->db->execute(
            'UPDATE reseller_props SET reseller_ips = ? WHERE reseller_id = ?',
            array('1;7;', $this->fixture->resellerId())
        );

        $reseller = $this->accounts()->reseller($this->fixture->resellerId());

        self::assertSame(array(1, 7), $reseller->ipIds());
        self::assertTrue($reseller->hasIp(7));
        self::assertFalse($reseller->hasIp(2));
    }

    public function testAnEmptyIpListIsNoIpsRatherThanOneEmptyOne(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET reseller_ips = ? WHERE reseller_id = ?',
            array('', $this->fixture->resellerId())
        );

        self::assertSame(array(), $this->accounts()->reseller($this->fixture->resellerId())->ipIds());
    }

    public function testAnAccountThatIsNotAResellerIsNotFound(): void
    {
        try {
            $this->accounts()->reseller($this->fixture->customerAdminId());
            self::fail('a customer is not a reseller');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testEveryAllowanceMapsToAColumnThatExists(): void
    {
        $reseller = $this->accounts()->reseller($this->fixture->resellerId());

        foreach (array_keys(\iMSCP\Plugin\SGW_GraphQL\Support\LimitRules::SERVICES) as $allowance) {
            self::assertIsInt($reseller->maxOf($allowance), $allowance);
            self::assertIsInt($reseller->usedOf($allowance), $allowance);
        }
    }
}
```

> `IntegrationTestCase` already gives `$this->db` and `$this->fixture`. Add an `accounts()` helper to the test if the base class has none — one line returning `new Accounts($this->db)`.

- [ ] **Step 6: Run it and watch it fail**

Run: `tools/test.sh --testsuite integration --filter ResellerAccountTest`
Expected: FAIL, `Call to undefined method … Accounts::reseller()`.

- [ ] **Step 7: Write the reseller account**

Create `Service/ResellerAccount.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

/* … the GPL header … */

use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use InvalidArgumentException;

/**
 * A reseller's `admin` row and its `reseller_props` row, read once and passed
 * down. The counters are the panel's (M9) and this class never writes them:
 * update_reseller_c_props() is their only writer (decision D25).
 */
final class ResellerAccount
{
    /** Allowance name => the pair of reseller_props columns holding it. */
    const COLUMNS = array(
        'subdomains'    => array('max_sub_cnt', 'current_sub_cnt'),
        'domainAliases' => array('max_als_cnt', 'current_als_cnt'),
        'mailAccounts'  => array('max_mail_cnt', 'current_mail_cnt'),
        'ftpUsers'      => array('max_ftp_cnt', 'current_ftp_cnt'),
        'sqlDatabases'  => array('max_sql_db_cnt', 'current_sql_db_cnt'),
        'sqlUsers'      => array('max_sql_user_cnt', 'current_sql_user_cnt'),
        'customers'     => array('max_dmn_cnt', 'current_dmn_cnt'),
        'disk'          => array('max_disk_amnt', 'current_disk_amnt'),
        'traffic'       => array('max_traff_amnt', 'current_traff_amnt')
    );

    /** @var array */
    private $admin;

    /** @var array */
    private $props;

    public function __construct(array $admin, array $props)
    {
        $this->admin = $admin;
        $this->props = $props;
    }

    public function getAdminId(): int
    {
        return (int)$this->admin['admin_id'];
    }

    public function getUsername(): string
    {
        return (string)$this->admin['admin_name'];
    }

    public function getEmail(): string
    {
        return (string)$this->admin['email'];
    }

    /** A reseller_props column, exactly as stored. */
    public function prop(string $column)
    {
        if (!array_key_exists($column, $this->props)) {
            throw new InvalidArgumentException(sprintf('reseller_props has no "%s".', $column));
        }

        return $this->props[$column];
    }

    public function maxOf(string $allowance): int
    {
        return (int)$this->prop(self::column($allowance, 0));
    }

    public function usedOf(string $allowance): int
    {
        return (int)$this->prop(self::column($allowance, 1));
    }

    /**
     * M17: "1;7;" - sorted ids, trailing separator. An empty list is no ids,
     * not one empty one, which is why the filter is not optional.
     *
     * @return int[]
     */
    public function ipIds(): array
    {
        $raw = array_filter(explode(';', (string)$this->prop('reseller_ips')), static function (string $one): bool {
            return $one !== '';
        });

        return array_map('intval', array_values($raw));
    }

    public function hasIp(int $ipId): bool
    {
        return in_array($ipId, $this->ipIds(), true);
    }

    private static function column(string $allowance, int $which): string
    {
        if (!isset(self::COLUMNS[$allowance])) {
            throw new InvalidArgumentException(sprintf('There is no "%s" allowance.', $allowance));
        }

        return self::COLUMNS[$allowance][$which];
    }
}
```

- [ ] **Step 8: Add the repository method**

In `Repository/Accounts.php`, beside `customer()`:

```php
    /**
     * @throws ApiException NOT_FOUND when the id is not a reseller's, which is
     *         the only thing a caller may learn about an account that is not
     *         theirs to see (spec section 6.3).
     */
    public function reseller(int $resellerId): ResellerAccount
    {
        $admin = $this->db->row(
            "SELECT admin_id, admin_name, email, created_by FROM admin WHERE admin_id = ? AND admin_type = 'reseller'",
            array($resellerId)
        );

        if ($admin === null) {
            throw Guard::notFound();
        }

        $props = $this->db->row('SELECT * FROM reseller_props WHERE reseller_id = ?', array($resellerId));

        if ($props === null) {
            // The panel writes admin and reseller_props in one transaction
            // (M16), so a missing props row is a broken account, not a
            // missing one - but the caller is still told the same thing.
            throw Guard::notFound();
        }

        return new ResellerAccount($admin, $props);
    }
```

- [ ] **Step 9: Run both tests**

Run: `tools/test.sh --testsuite unit --filter LimitRulesTest` then `tools/test.sh --testsuite integration --filter ResellerAccountTest`
Expected: PASS, 7 and 5 tests.

- [ ] **Step 10: Run the whole suite and commit**

Run: `tools/test.sh`
Expected: green.

```bash
git add Support/LimitRules.php Service/ResellerAccount.php Repository/Accounts.php \
        test/unit/Support/LimitRulesTest.php test/integration/ResellerAccountTest.php
git commit -m "What a reseller may give away, and what it has left"
```

---

## Task 2: Allowances, and the props string that carries them — `sonnet`

`hosting_plans.props` is the one format both directions of this phase pass through: a plan is read into allowances, allowances are written back into a plan, and a customer is created from either. Spec §17 requires a property test for the round trip and §20 lists it as a risk.

**Files:**
- Create: `Support/Allowances.php`
- Create: `test/unit/Support/AllowancesTest.php`
- Modify: `Support/PlanProps.php`
- Modify: `test/unit/Support/PlanPropsTest.php`

**Interfaces:**
- Consumes: `Support\PlanProps::parse(string): PlanProps`, `PlanProps::raw(string $field): string`, `PlanProps::FIELDS`, `PlanProps::toString(): string`.
- Produces:
  ```php
  Allowances::fromPlanProps(PlanProps $props): Allowances
  Allowances::fromInput(array $input): Allowances            // throws ApiException BAD_USER_INPUT
  Allowances::limit(string $allowance): int                  // -1 | 0 | n
  Allowances::storage(string $which): int                    // 'disk' | 'traffic' | 'mailQuota', MiB for the first two
  Allowances::feature(string $name): string                  // the props spelling, e.g. '_yes_'
  Allowances::phpIni(string $name): string
  Allowances::backupTargets(): string                        // pipe-joined, e.g. '_dmn_|_sql_'
  Allowances::toProps(): PlanProps
  Allowances::domainColumns(): array                         // column => value, for the `domain` row (M7 applied)
  PlanProps::fromFields(array $fields): PlanProps
  ```

- [ ] **Step 1: Write the round-trip property test**

Add to `test/unit/Support/PlanPropsTest.php`:

```php
    /**
     * Spec section 17 asks for this as a property test: whatever the panel
     * stored, parsing and re-emitting it must give back the same 25 fields in
     * the same order, byte for byte. A props string that does not round-trip
     * silently rewrites a reseller's plan.
     */
    public function testEveryPropsStringRoundTrips(): void
    {
        foreach ($this->propsVectors() as $label => $props) {
            self::assertSame($props, PlanProps::parse($props)->toString(), $label);
        }
    }

    public function testAllowancesRoundTripThroughPropsAndBack(): void
    {
        foreach ($this->propsVectors() as $label => $props) {
            $once = Allowances::fromPlanProps(PlanProps::parse($props));

            self::assertSame($props, $once->toProps()->toString(), $label);
        }
    }

    /** @return array<string, string> */
    public function propsVectors(): array
    {
        // The box's own plan, then the shapes the pages can write:
        // hosting_plan_add.php:446-457 builds exactly this string.
        return array(
            'the panel default' =>
                '_no_;_no_;0;0;0;0;0;0;0;0;_no_;_no_;no;no;no;no;no;0;0;0;0;0;_no_;_yes_;0',
            'everything on, unlimited' =>
                '_yes_;_yes_;0;0;0;0;0;0;0;0;_dmn_|_sql_|_mail_;_yes_;yes;yes;yes;yes;yes;10;10;30;60;128;_yes_;_yes_;0',
            'everything withheld' =>
                '_no_;_no_;-1;-1;-1;-1;-1;-1;-1;-1;_no_;_no_;no;no;no;no;no;0;0;0;0;0;_no_;_yes_;0',
            'finite limits' =>
                '_yes_;_no_;5;3;20;10;4;4;10240;5120;_dmn_;_yes_;yes;no;no;no;yes;8;8;30;60;64;_no_;_yes_;104857600',
            'a single backup target' =>
                '_yes_;_no_;1;1;1;1;1;1;1;1;_sql_;_no_;no;no;no;no;no;0;0;0;0;0;_no_;_no_;1048576'
        );
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `tools/test.sh --testsuite unit --filter PlanPropsTest`
Expected: FAIL, `Class "…Support\Allowances" not found`. If `testEveryPropsStringRoundTrips` also fails, that is a real defect in the phase-2 reader: fix it here and say so in the report.

- [ ] **Step 3: Write the allowances value object**

Create `Support/Allowances.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

/* … the GPL header … */

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;

/**
 * The 25 fields of hosting_plans.props (M5), whichever end they came from: a
 * hosting plan the reseller already has, or an explicit CustomerAllowancesInput.
 * Everything downstream takes one of these, so the code that writes a `domain`
 * row cannot tell which the caller sent (decision D24).
 */
final class Allowances
{
    /** The six countable allowances, GraphQL name => props field. */
    const LIMITS = PlanProps::ALLOWANCES;

    /** Feature name => props field. The six that M7 stores with underscores. */
    const UNDERSCORED = array(
        'php'                 => 'php',
        'cgi'                 => 'cgi',
        'backup'              => 'backup',
        'customDns'           => 'dns',
        'externalMail'        => 'extMailServer',
        'webFolderProtection' => 'webFolderProtection'
    );

    /** @var array<string, string> props field => raw value */
    private $fields;

    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    public static function fromPlanProps(PlanProps $props): self
    {
        $fields = array();

        foreach (PlanProps::FIELDS as $field) {
            $fields[$field] = $props->raw($field);
        }

        return new self($fields);
    }

    /**
     * CustomerAllowancesInput. Every limit is -1, 0 or a positive integer;
     * disk and traffic are MiB, as the props store them; the mail quota is
     * bytes, because every BigInt in this schema is bytes (D3) and
     * hosting_plan_add.php:457 already converts at the boundary.
     *
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public static function fromInput(array $input): self
    {
        $fields = array();

        foreach (self::LIMITS as $name => $field) {
            $fields[$field] = (string)self::limitValue($input, $name);
        }

        $fields['traffic'] = (string)self::limitValue($input, 'traffic');
        $fields['disk'] = (string)self::limitValue($input, 'disk');
        $fields['mailQuota'] = (string)self::mailQuota($input);

        foreach (array('php', 'cgi', 'customDns', 'externalMail') as $name) {
            $fields[self::UNDERSCORED[$name]] = self::flag($input, $name, false);
        }

        // The panel's own default is protection on (hosting_plan_add.php:481).
        $fields['webFolderProtection'] = self::flag($input, 'webFolderProtection', true);
        $fields['backup'] = self::backup($input);
        $fields['phpEditor'] = !empty($input['phpEditor']) ? 'yes' : 'no';

        foreach (array('phpiniAllowUrlFopen', 'phpiniDisplayErrors', 'phpiniDisableFunctions', 'phpMailFunction') as $name) {
            $fields[$name] = !empty($input[$name]) ? 'yes' : 'no';
        }

        foreach (array('phpiniPostMaxSize', 'phpiniUploadMaxFileSize', 'phpiniMaxExecutionTime',
                       'phpiniMaxInputTime', 'phpiniMemoryLimit') as $name) {
            $fields[$name] = (string)max(0, (int)($input[$name] ?? 0));
        }

        return new self($fields);
    }

    public function limit(string $allowance): int
    {
        return (int)$this->fields[self::LIMITS[$allowance]];
    }

    public function storage(string $which): int
    {
        return (int)$this->fields[$which];
    }

    public function feature(string $name): string
    {
        return $this->fields[self::UNDERSCORED[$name]];
    }

    public function phpIni(string $name): string
    {
        return $this->fields[$name];
    }

    public function backupTargets(): string
    {
        return $this->fields['backup'];
    }

    public function toProps(): PlanProps
    {
        return PlanProps::fromFields($this->fields);
    }

    /**
     * The `domain` columns these allowances become, with M7 applied: the six
     * underscored fields are stored in `domain` without their underscores,
     * which user_add3.php:145-150 does with str_replace and this does once.
     *
     * @return array<string, string|int>
     */
    public function domainColumns(): array
    {
        return array(
            'domain_subd_limit'             => $this->limit('subdomains'),
            'domain_alias_limit'            => $this->limit('domainAliases'),
            'domain_mailacc_limit'          => $this->limit('mailAccounts'),
            'domain_ftpacc_limit'           => $this->limit('ftpUsers'),
            'domain_sqld_limit'             => $this->limit('sqlDatabases'),
            'domain_sqlu_limit'             => $this->limit('sqlUsers'),
            'domain_traffic_limit'          => $this->storage('traffic'),
            'domain_disk_limit'             => $this->storage('disk'),
            'mail_quota'                    => $this->storage('mailQuota'),
            'domain_php'                    => self::stripped($this->feature('php')),
            'domain_cgi'                    => self::stripped($this->feature('cgi')),
            'allowbackup'                   => self::stripped($this->backupTargets()),
            'domain_dns'                    => self::stripped($this->feature('customDns')),
            'domain_external_mail'          => self::stripped($this->feature('externalMail')),
            'web_folder_protection'         => self::stripped($this->feature('webFolderProtection')),
            'phpini_perm_system'            => $this->phpIni('phpEditor'),
            'phpini_perm_allow_url_fopen'   => $this->phpIni('phpiniAllowUrlFopen'),
            'phpini_perm_display_errors'    => $this->phpIni('phpiniDisplayErrors'),
            'phpini_perm_disable_functions' => $this->phpIni('phpiniDisableFunctions'),
            'phpini_perm_mail_function'     => $this->phpIni('phpMailFunction')
        );
    }

    /** M7: '_yes_' => 'yes', '_dmn_|_sql_' => 'dmn|sql'. */
    private static function stripped(string $value): string
    {
        return str_replace('_', '', $value);
    }

    private static function limitValue(array $input, string $name): int
    {
        $value = $input[$name] ?? 0;

        if (!is_int($value) || $value < -1) {
            throw Guard::badInput(
                'input.allowances.' . $name,
                'A limit is -1 to withhold the feature, 0 for unlimited, or a positive number.'
            );
        }

        return $value;
    }

    private static function mailQuota(array $input): int
    {
        $value = $input['mailQuota'] ?? 0;

        if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 0) {
            throw Guard::badInput('input.allowances.mailQuota', 'A mail quota is a whole number of bytes, or 0 for none.');
        }

        return $value;
    }

    private static function flag(array $input, string $name, bool $default): string
    {
        $on = array_key_exists($name, $input) && $input[$name] !== null ? (bool)$input[$name] : $default;

        return $on ? '_yes_' : '_no_';
    }

    private static function backup(array $input): string
    {
        $targets = array();

        foreach (array_values((array)($input['backup'] ?? array())) as $index => $one) {
            $key = array_search((string)$one, PlanProps::BACKUP_TARGETS, true);

            if ($key === false) {
                throw Guard::badInput('input.allowances.backup', 'A backup target is DOMAIN, SQL or MAIL.', array('index' => $index));
            }

            $targets[$key] = true;
        }

        return $targets === array() ? '_no_' : implode('|', array_keys($targets));
    }
}
```

- [ ] **Step 4: Give `PlanProps` its writer**

Add to `Support/PlanProps.php`:

```php
    /**
     * The inverse of parse(): the same 25 fields, in FIELDS order, joined with
     * semicolons. Every field must be present, because a props string with a
     * gap is one the panel's own list() unpack would fill with the wrong
     * value (M5).
     *
     * @param array<string, string> $fields
     * @throws InvalidArgumentException
     */
    public static function fromFields(array $fields): self
    {
        $ordered = array();

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                throw new InvalidArgumentException(sprintf('A hosting plan needs a "%s".', $field));
            }

            $value = (string)$fields[$field];

            if (strpos($value, ';') !== false) {
                throw new InvalidArgumentException(sprintf('"%s" cannot contain a semicolon.', $field));
            }

            $ordered[$field] = $value;
        }

        return new self($ordered);
    }
```

- [ ] **Step 5: Write the allowances test**

Create `test/unit/Support/AllowancesTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Unit\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\Allowances;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use PHPUnit\Framework\TestCase;

class AllowancesTest extends TestCase
{
    public function testAnInputBecomesTheColumnsTheDomainRowWants(): void
    {
        $allowances = Allowances::fromInput(array(
            'subdomains' => 5, 'domainAliases' => 3, 'mailAccounts' => 20, 'ftpUsers' => 10,
            'sqlDatabases' => 4, 'sqlUsers' => 4, 'traffic' => 10240, 'disk' => 5120,
            'mailQuota' => 104857600, 'php' => true, 'cgi' => false, 'customDns' => true,
            'externalMail' => false, 'backup' => array('DOMAIN'), 'phpEditor' => true
        ));

        $columns = $allowances->domainColumns();

        self::assertSame(5, $columns['domain_subd_limit']);
        self::assertSame(104857600, $columns['mail_quota']);
        self::assertSame('yes', $columns['domain_php'], 'M7: the domain row has no underscores');
        self::assertSame('no', $columns['domain_cgi']);
        self::assertSame('yes', $columns['domain_dns']);
        self::assertSame('dmn', $columns['allowbackup']);
        self::assertSame('yes', $columns['web_folder_protection'], 'the panel default is on');
        self::assertSame('yes', $columns['phpini_perm_system']);
    }

    public function testNoBackupTargetIsTheStringTheColumnExpects(): void
    {
        $columns = Allowances::fromInput(array('backup' => array()))->domainColumns();

        self::assertSame('no', $columns['allowbackup']);
    }

    public function testAnUnknownBackupTargetIsRefused(): void
    {
        $this->expectRefusal('input.allowances.backup', function (): void {
            Allowances::fromInput(array('backup' => array('TAPE')));
        });
    }

    public function testALimitBelowMinusOneIsRefused(): void
    {
        $this->expectRefusal('input.allowances.subdomains', function (): void {
            Allowances::fromInput(array('subdomains' => -2));
        });
    }

    public function testANegativeMailQuotaIsRefused(): void
    {
        $this->expectRefusal('input.allowances.mailQuota', function (): void {
            Allowances::fromInput(array('mailQuota' => -1));
        });
    }

    public function testAPlanAndAnInputProduceTheSameProps(): void
    {
        $props = '_yes_;_no_;5;3;20;10;4;4;10240;5120;_dmn_;_yes_;yes;no;no;no;no;0;0;0;0;0;_no_;_yes_;104857600';
        $fromPlan = Allowances::fromPlanProps(PlanProps::parse($props));

        self::assertSame($props, $fromPlan->toProps()->toString());
        self::assertSame($fromPlan->domainColumns(), Allowances::fromInput(array(
            'subdomains' => 5, 'domainAliases' => 3, 'mailAccounts' => 20, 'ftpUsers' => 10,
            'sqlDatabases' => 4, 'sqlUsers' => 4, 'traffic' => 10240, 'disk' => 5120,
            'mailQuota' => 104857600, 'php' => true, 'cgi' => false, 'customDns' => true,
            'externalMail' => false, 'backup' => array('DOMAIN'), 'phpEditor' => true
        ))->domainColumns());
    }

    private function expectRefusal(string $field, callable $call): void
    {
        try {
            $call();
            self::fail('expected a refusal on ' . $field);
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertSame($field, $e->getExtensions()['field'] ?? null);
        }
    }
}
```

- [ ] **Step 6: Run both unit tests**

Run: `tools/test.sh --testsuite unit --filter 'AllowancesTest|PlanPropsTest'`
Expected: PASS.

- [ ] **Step 7: Run the whole suite and commit**

Run: `tools/test.sh`
Expected: green.

```bash
git add Support/Allowances.php Support/PlanProps.php \
        test/unit/Support/AllowancesTest.php test/unit/Support/PlanPropsTest.php
git commit -m "Allowances, and the props string that carries them both ways"
```

---
## Task 3: Creating a customer — `opus`

M1's three pages in one call. This is the widest transcription in the plugin: one `admin` row, one `domain` row of 31 columns, the PHP editor, the default mail accounts, the welcome message, the GUI properties, the reseller's counters, and two events. It also adds two items to spec §21's C11.

The GraphQL surface is Task 9; this task tests the service directly, the way phase 3's Tasks 6–7 did.

**Files:**
- Create: `Service/CustomerService.php`
- Create: `test/integration/CustomerServiceTest.php`
- Modify: `Service/Core.php`, `Service/PanelCore.php`, `test/Double/RecordingCore.php`
- Modify: `test/unit/Security/CoreCallsTest.php` (the `ALLOWED` list)
- Modify: `docs/SPECIFICATION.md` (§21, C11 items 12 and 13)

**Interfaces:**
- Consumes: `Service\Toolkit`, `Security\Guard`, `Service\Writer::run(callable): mixed`, `Support\Allowances`, `Support\LimitRules`, `Service\ResellerAccount`, `Repository\Accounts::reseller()`, `Support\PlanProps::parse()`.
- Produces:
  ```php
  new CustomerService(Toolkit $kit)
  CustomerService::create(Identity $caller, array $input): ObjectRef   // NodeType::CUSTOMER
  Core::hashAccountPassword(string $password): string                  // apr1MD5 (M4)
  Core::sendAccountCreatedEmail(int $createdBy, string $username, string $password,
                                string $email, string $firstName, string $lastName,
                                string $role): bool
  Core::updateResellerCounters(int $resellerId): void
  Core::savePhpIniForNewDomain(int $resellerId, int $customerAdminId, int $domainId, array $values): void
  ```

- [ ] **Step 1: Add the two C11 items to the specification**

In `docs/SPECIFICATION.md` §21, append to the C11 *list*:

```markdown
12. `reseller/user_add3.php:255` sends the welcome message — which carries the new customer's password in clear — *inside* the transaction, before line 287's `commit()`. A failure after it, and the rollback that follows, leaves the customer holding credentials for an account that was never created, and the reseller with no record that anything was sent.
13. `reseller/user_add3.php:110-116` explodes `reseller_props.reseller_ips` after `rtrim(…, ';')`. For a reseller with no IPs that yields `array('')`, and the check `in_array($domainIp, $resellerIps)` is loose, so under PHP 7 `0 == ''` is true and a posted `domain_ip` of `0` — which is what `intval()` gives for any non-numeric value — passes. Under PHP 8 the same comparison is false, so the page's behaviour depends on the interpreter.
```

Then: the API sends the welcome message **after** the commit, and matches IP identity strictly.

- [ ] **Step 2: Write the failing test**

Create `test/integration/CustomerServiceTest.php`. `ServiceTestCase` gives `$this->kit`, `$this->core` (a `RecordingCore`), `caller()`, `refused()`, `insert()` and `reconfigure()`.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Service\CustomerService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class CustomerServiceTest extends ServiceTestCase
{
    private function service(): CustomerService
    {
        return new CustomerService($this->kit);
    }

    /** A complete, valid input for the fixture's reseller. */
    private function input(array $overrides = array()): array
    {
        return $overrides + array(
            'username'   => 'sgwnew.test',
            'password'   => 'N3wCustomer!',
            'domainName' => 'sgwnew.test',
            'ipAddressId' => GlobalId::encode(NodeType::IP_ADDRESS, $this->fixture->ipId()),
            'contact'    => array(
                'email' => 'owner@sgwnew.test', 'firstName' => 'Ada', 'lastName' => 'Lovelace'
            ),
            'allowances' => array(
                'subdomains' => 2, 'domainAliases' => 1, 'mailAccounts' => 5, 'ftpUsers' => 2,
                'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024, 'disk' => 512,
                'mailQuota' => 0, 'php' => true, 'cgi' => false, 'customDns' => false,
                'externalMail' => false, 'backup' => array(), 'phpEditor' => false
            ),
            'sendWelcomeEmail' => false
        );
    }

    public function testACustomerIsScheduledWithItsDomain(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwnew.test', $admin['admin_name']);
        self::assertSame('user', $admin['admin_type']);
        self::assertSame('toadd', $admin['admin_status']);
        self::assertSame($this->fixture->resellerId(), (int)$admin['created_by']);

        $domain = $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwnew.test', $domain['domain_name']);
        self::assertSame('toadd', $domain['domain_status']);
        self::assertSame(2, (int)$domain['domain_subd_limit']);
        self::assertSame('yes', $domain['domain_php'], 'M7: no underscores in the domain row');
        self::assertSame('no', $domain['allowbackup']);
    }

    public function testThePasswordIsHashedTheWayAnAccountIsHashedNotTheWayAMailboxIs(): void
    {
        // M4: apr1MD5, not sha512. The prefix is the whole assertion.
        $ref = $this->service()->create($this->caller('reseller'), $this->input());
        $hash = (string)$this->db->value('SELECT admin_pass FROM admin WHERE admin_id = ?', array($ref->getKey()));

        self::assertStringStartsWith('$apr1$', $hash);
    }

    public function testTheGuiPropertiesRowIsCreated(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        self::assertSame(
            1, (int)$this->db->value('SELECT COUNT(*) FROM user_gui_props WHERE user_id = ?', array($ref->getKey()))
        );
    }

    public function testTheResellerCountersAreRecalculatedAndTheDaemonIsPoked(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        self::assertContains(
            array('updateResellerCounters', $this->fixture->resellerId()), $this->core->calls
        );
        self::assertContains(array('sendRequest'), $this->core->calls);
    }

    public function testTheEventsCarryThePanelsOwnParameters(): void
    {
        // M3: onBeforeAddDomain has no domainId; onAfterAddDomain has one.
        $ref = $this->service()->create($this->caller('reseller'), $this->input());
        $before = $this->core->eventNamed('onBeforeAddDomain');
        $after = $this->core->eventNamed('onAfterAddDomain');

        self::assertSame($this->fixture->resellerId(), $before['createdBy']);
        self::assertSame('sgwnew.test', $before['domainName']);
        self::assertSame('/', $before['mountPoint']);
        self::assertSame('/htdocs', $before['documentRoot']);
        self::assertArrayNotHasKey('domainId', $before);
        self::assertSame((int)$ref->getKey(), $after['customerId']);
        self::assertArrayHasKey('domainId', $after);
    }

    public function testTheWelcomeMessageIsSentAfterTheCommitAndOnlyWhenAsked(): void
    {
        // C11 item 12: the page sends it inside the transaction.
        $this->service()->create($this->caller('reseller'), $this->input());
        self::assertSame(array(), $this->core->callsNamed('sendAccountCreatedEmail'));

        $this->service()->create($this->caller('reseller'), $this->input(array(
            'username' => 'sgwnew2.test', 'domainName' => 'sgwnew2.test', 'sendWelcomeEmail' => true
        )));

        $sent = $this->core->callsNamed('sendAccountCreatedEmail');
        self::assertCount(1, $sent);
        self::assertGreaterThan(
            array_search(array('commit'), $this->core->calls, true) === false ? -1 : 0,
            1, 'the mail is sent after the writer has committed'
        );
    }

    public function testDefaultMailAccountsFollowThePanelsSetting(): void
    {
        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => false));
        $this->service()->create($this->caller('reseller'), $this->input());
        self::assertSame(array(), $this->core->callsNamed('createDefaultMailAccounts'));

        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => true));
        $this->service()->create($this->caller('reseller'), $this->input(array(
            'username' => 'sgwnew3.test', 'domainName' => 'sgwnew3.test'
        )));
        self::assertCount(1, $this->core->callsNamed('createDefaultMailAccounts'));
    }

    // ---- the refusals, in spec section 8.1's order ----------------------

    public function testACustomerMayNotCreateACustomer(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->create($this->caller('customer'), $this->input());
        });
    }

    public function testAnotherResellersIpIsNotFound(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET reseller_ips = ? WHERE reseller_id = ?',
            array('', $this->fixture->resellerId())
        );

        // C11 item 13: under PHP 7 the page's loose in_array() lets this pass.
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input());
        });
    }

    public function testNeitherAPlanNorAllowancesIsRefused(): void
    {
        $input = $this->input();
        unset($input['allowances']);

        $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($input): void {
            $this->service()->create($this->caller('reseller'), $input);
        });
    }

    public function testBothAPlanAndAllowancesIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'hostingPlanId' => GlobalId::encode(NodeType::HOSTING_PLAN, $this->fixture->hostingPlanId())
            )));
        });
    }

    public function testADomainNameThatExistsIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'domainName' => $this->fixture->domainName(), 'username' => 'sgwdup.test'
            )));
        });
    }

    public function testAnInvalidDomainNameIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array('domainName' => 'not a domain')));
        });
    }

    public function testAWeakPasswordIsRefusedWithoutEchoingIt(): void
    {
        try {
            $this->service()->create($this->caller('reseller'), $this->input(array('password' => 'x')));
            self::fail('expected a refusal');
        } catch (\iMSCP\Plugin\SGW_GraphQL\Support\ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertStringNotContainsString('x', $e->getMessage() . json_encode($e->getExtensions()));
        }
    }

    public function testALimitBeyondWhatTheResellerHasLeftIsRefused(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET max_sub_cnt = 4, current_sub_cnt = 3 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('subdomains' => 2) + $this->input()['allowances']
            )));
        });
    }

    public function testNothingIsWrittenWhenARefusalHappens(): void
    {
        $before = (int)$this->db->value('SELECT COUNT(*) FROM admin');

        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array('domainName' => 'not a domain')));
        });

        self::assertSame($before, (int)$this->db->value('SELECT COUNT(*) FROM admin'));
    }
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `tools/test.sh --testsuite integration --filter CustomerServiceTest`
Expected: FAIL, `Class "…Service\CustomerService" not found`.

- [ ] **Step 4: Extend the core port**

In `Service/Core.php`, add the four methods; in `Service/PanelCore.php`, implement them:

```php
    /**
     * M4: an account's password is APR-1, not the sha512 a mailbox or an FTP
     * user gets. The difference is not a style choice - the panel's own login
     * compares against this.
     */
    public function hashAccountPassword(string $password): string
    {
        return (string)Crypt::apr1MD5($password);
    }

    /**
     * CORE-DEBT(C1): send_add_user_auto_msg() reads nothing from the session,
     *   but its first argument is the reseller the message is "from".
     *
     * The cleartext password is the message's whole point (M6); it travels no
     * further than this call.
     */
    public function sendAccountCreatedEmail(
        int $createdBy, string $username, string $password, string $email,
        string $firstName, string $lastName, string $role
    ): bool {
        return (bool)send_add_user_auto_msg(
            $createdBy, $username, $password, $email, $firstName, $lastName, $role
        );
    }

    /** M9: the current_* counters are derived, and this is their only writer (D25). */
    public function updateResellerCounters(int $resellerId): void
    {
        update_reseller_c_props($resellerId);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/user_add3.php:226-245.
     *   The four loads and the five setters are PhpEditor's own; the order is
     *   the page's, and it matters: memory limit before post max size, post max
     *   size before upload max filesize. Retire when CustomerService lands in
     *   core.
     *
     * @param array<string, string> $values phpiniMemoryLimit, phpiniPostMaxSize,
     *        phpiniUploadMaxFileSize, phpiniMaxExecutionTime, phpiniMaxInputTime
     */
    public function savePhpIniForNewDomain(int $resellerId, int $customerAdminId, int $domainId, array $values): void
    {
        $editor = PhpEditor::getInstance();
        $editor->loadResellerPermissions((string)$resellerId);
        $editor->loadClientPermissions();
        $editor->loadDomainIni();

        $editor->setDomainIni('phpiniMemoryLimit', $values['phpiniMemoryLimit']);
        $editor->setDomainIni('phpiniPostMaxSize', $values['phpiniPostMaxSize']);
        $editor->setDomainIni('phpiniUploadMaxFileSize', $values['phpiniUploadMaxFileSize']);
        $editor->setDomainIni('phpiniMaxExecutionTime', $values['phpiniMaxExecutionTime']);
        $editor->setDomainIni('phpiniMaxInputTime', $values['phpiniMaxInputTime']);
        $editor->saveDomainIni((string)$customerAdminId, (string)$domainId, 'dmn');
    }
```

Add `send_add_user_auto_msg` and `update_reseller_c_props` to `CoreCallsTest`'s `ALLOWED` list, and record each call in `test/Double/RecordingCore.php` the way the existing methods do.

- [ ] **Step 5: Write the service**

Create `Service/CustomerService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

/* … the GPL header … */

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\Allowances;
use iMSCP\Plugin\SGW_GraphQL\Support\Events;
use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;

/**
 * The customer lifecycle, as a reseller sees it.
 *
 * The panel spreads creation across three pages with the half-built customer
 * in $_SESSION (M1); spec section 7.11 takes it in one call, applying the same
 * validation each step applied, in the same order (decision D23).
 */
final class CustomerService
{
    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3. A reseller creates under itself; an administrator must say
        // for whom (decision D30).
        Guard::requireScope($caller, Scope::CUSTOMERS_WRITE);
        $reseller = $kit->accounts()->reseller($this->resellerFor($caller, $input));

        // 4. There is no "customers" feature flag; the reseller's own domain
        // allowance is the nearest thing, and it is a quota, not a feature.

        // 6. The panel's order: the name, then the allowances, then the IP,
        // then the contact details.
        $name = mb_strtolower(trim((string)($input['domainName'] ?? '')));
        $ascii = $core->toAscii($name);

        if ($ascii === '' || ($reason = $core->domainNameError($ascii)) !== null) {
            throw Guard::badInput('input.domainName', (string)$reason ?: 'Invalid domain name.');
        }

        if ($core->domainExists($ascii, $reseller->getAdminId())) {
            throw Guard::conflict(sprintf('The domain %s already exists.', $name));
        }

        $username = trim((string)($input['username'] ?? ''), ' ');

        if ($username === '' || !$core->isValidUsername($username)) {
            throw Guard::badInput('input.username', 'Invalid username.');
        }

        if ($this->usernameTaken($username)) {
            throw Guard::conflict(sprintf('The username %s is already taken.', $username));
        }

        $password = (string)($input['password'] ?? '');

        if ($password === '' || !$core->isAcceptablePassword($password)) {
            throw Guard::badInput('input.password', "The password does not meet the panel's password policy.");
        }

        $allowances = $this->allowancesFor($reseller, $input);
        $ipId = $this->ipFor($reseller, $input);
        $contact = $this->contactFor($input, $core);
        $expiresAt = $this->expiryFor($input);

        // 7. Every allowance against both ledgers (D21). A create has no
        // customer consumption and no customer limit yet, so both are 0.
        foreach (array_keys(LimitRules::SERVICES) as $service) {
            $reason = LimitRules::reason(
                $allowances->limit($service), 0, 0,
                $reseller->usedOf($service), $reseller->maxOf($service), $service
            );

            if ($reason !== null) {
                throw Guard::limitExceeded($reason, array('quota' => $service));
            }
        }

        $this->requireRoomForOneMoreCustomer($reseller);

        $columns = $allowances->domainColumns();
        $eventParams = array(
            'createdBy'     => $reseller->getAdminId(),
            'customerEmail' => $contact['email'],
            'domainName'    => $ascii,
            'mountPoint'    => '/',
            'documentRoot'  => '/htdocs',
            'forwardUrl'    => 'no',
            'forwardType'   => null,
            'forwardHost'   => 'no',
            'wildcardAlias' => 'no'
        );
        $panel = $kit->panelConfig(array(
            'CREATE_DEFAULT_EMAIL_ADDRESSES', 'USER_INITIAL_LANG', 'USER_INITIAL_THEME'
        ));

        // 8. CORE-DEBT(C3): transcribed from gui/public/reseller/user_add3.php:150-290.
        // CORE-DEBT(C11): C11 item 12 - the welcome message is sent after the
        //   commit, not inside the transaction as the page sends it.
        $written = $kit->writer()->run(function () use (
            $kit, $core, $reseller, $username, $password, $ascii, $contact, $expiresAt,
            $columns, $ipId, $allowances, $eventParams, $panel
        ) {
            $db = $kit->db();

            $db->execute(
                "
                    INSERT INTO admin (
                        admin_name, admin_pass, admin_type, domain_created, created_by, fname, lname, firm,
                        zip, city, state, country, email, phone, fax, street1, street2, gender, admin_status
                    ) VALUES (?, ?, 'user', unix_timestamp(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'toadd')
                ",
                array(
                    $username, $core->hashAccountPassword($password), $reseller->getAdminId(),
                    $contact['firstName'], $contact['lastName'], $contact['company'], $contact['postcode'],
                    $contact['city'], $contact['state'], $contact['country'], $contact['email'],
                    $contact['phone'], $contact['fax'], $contact['street1'], $contact['street2'], $contact['gender']
                )
            );

            $adminId = $db->lastInsertId();
            $core->dispatch(Events::onBeforeAddDomain, array('customerId' => $adminId) + $eventParams);

            $db->execute(
                '
                    INSERT INTO domain (
                        domain_name, domain_admin_id, domain_created, domain_expires, domain_status,
                        domain_ip_id, domain_disk_usage, url_forward, type_forward, host_forward, wildcard_alias,
                        domain_mailacc_limit, domain_ftpacc_limit, domain_traffic_limit, domain_sqld_limit,
                        domain_sqlu_limit, domain_alias_limit, domain_subd_limit, domain_disk_limit,
                        domain_php, domain_cgi, allowbackup, domain_dns, phpini_perm_system,
                        phpini_perm_allow_url_fopen, phpini_perm_display_errors, phpini_perm_disable_functions,
                        phpini_perm_mail_function, domain_external_mail, web_folder_protection, mail_quota
                    ) VALUES (
                        ?, ?, unix_timestamp(), ?, ?, ?, 0, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ',
                array(
                    $ascii, $adminId, $expiresAt, 'toadd', $ipId, 'no', null, 'no', 'no',
                    $columns['domain_mailacc_limit'], $columns['domain_ftpacc_limit'],
                    $columns['domain_traffic_limit'], $columns['domain_sqld_limit'],
                    $columns['domain_sqlu_limit'], $columns['domain_alias_limit'],
                    $columns['domain_subd_limit'], $columns['domain_disk_limit'],
                    $columns['domain_php'], $columns['domain_cgi'], $columns['allowbackup'],
                    $columns['domain_dns'], $columns['phpini_perm_system'],
                    $columns['phpini_perm_allow_url_fopen'], $columns['phpini_perm_display_errors'],
                    $columns['phpini_perm_disable_functions'], $columns['phpini_perm_mail_function'],
                    $columns['domain_external_mail'], $columns['web_folder_protection'], $columns['mail_quota']
                )
            );

            $domainId = $db->lastInsertId();

            $core->savePhpIniForNewDomain($reseller->getAdminId(), $adminId, $domainId, array(
                'phpiniMemoryLimit'       => $allowances->phpIni('phpiniMemoryLimit'),
                'phpiniPostMaxSize'       => $allowances->phpIni('phpiniPostMaxSize'),
                'phpiniUploadMaxFileSize' => $allowances->phpIni('phpiniUploadMaxFileSize'),
                'phpiniMaxExecutionTime'  => $allowances->phpIni('phpiniMaxExecutionTime'),
                'phpiniMaxInputTime'      => $allowances->phpIni('phpiniMaxInputTime')
            ));

            if ($panel['CREATE_DEFAULT_EMAIL_ADDRESSES']) {
                $core->createDefaultMailAccounts($domainId, $contact['email'], $ascii, '', 0);
            }

            $db->execute(
                'INSERT INTO user_gui_props (user_id, lang, layout) VALUES (?, ?, ?)',
                array($adminId, $panel['USER_INITIAL_LANG'], $panel['USER_INITIAL_THEME'])
            );

            $core->updateResellerCounters($reseller->getAdminId());
            $core->dispatch(
                Events::onAfterAddDomain,
                array('customerId' => $adminId, 'domainId' => $domainId) + $eventParams
            );

            return array('adminId' => $adminId, 'domainId' => $domainId);
        });

        // 9, 10. C11 item 12: after the commit, so a rollback cannot leave a
        // customer holding credentials for an account that does not exist.
        if (!empty($input['sendWelcomeEmail'])) {
            $core->sendAccountCreatedEmail(
                $reseller->getAdminId(), $username, $password, $contact['email'],
                $contact['firstName'], $contact['lastName'], 'Customer'
            );
        }

        $core->sendRequest();
        $core->writeLog(
            sprintf('A new customer (%s) has been created by: %s', $username, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $written['adminId']);
    }
}
```

The five private helpers the method leans on, in the same class:

```php
    /**
     * D30: a reseller creates under itself and may not name another; an
     * administrator has no customers of its own, so it must name one.
     */
    private function resellerFor(Identity $caller, array $input): int
    {
        $named = isset($input['resellerId'])
            ? Guard::parse((string)$input['resellerId'], array(NodeType::RESELLER))->getId()
            : null;

        if ($caller->isAdministrator()) {
            if ($named === null) {
                throw Guard::badInput('input.resellerId', 'An administrator must say which reseller the customer belongs to.');
            }

            return $named;
        }

        if (!$caller->isReseller()) {
            throw Guard::forbidden('Only a reseller or an administrator may create a customer.');
        }

        if ($named !== null && $named !== $caller->getAdminId()) {
            // Not NOT_FOUND: the caller named an account it can see - its own
            // role - and asked for something its role does not permit.
            throw Guard::forbidden('A reseller may only create customers of its own.');
        }

        return $caller->getAdminId();
    }

    /** D24: exactly one of hostingPlanId and allowances. */
    private function allowancesFor(ResellerAccount $reseller, array $input): Allowances
    {
        $planId = isset($input['hostingPlanId']) ? (string)$input['hostingPlanId'] : null;
        $explicit = isset($input['allowances']) ? (array)$input['allowances'] : null;

        if (($planId === null) === ($explicit === null)) {
            throw Guard::badInput('input', 'Give exactly one of hostingPlanId and allowances.');
        }

        if ($explicit !== null) {
            return Allowances::fromInput($explicit);
        }

        $key = Guard::parse($planId, array(NodeType::HOSTING_PLAN))->getId();
        $props = $this->kit->db()->value(
            'SELECT props FROM hosting_plans WHERE id = ? AND reseller_id = ?',
            array($key, $reseller->getAdminId())
        );

        if ($props === null) {
            throw Guard::notFound();
        }

        return Allowances::fromPlanProps(PlanProps::parse((string)$props));
    }

    /** M17, and C11 item 13: identity, not a loose comparison. */
    private function ipFor(ResellerAccount $reseller, array $input): int
    {
        $ipId = Guard::parse((string)($input['ipAddressId'] ?? ''), array(NodeType::IP_ADDRESS))->getId();

        if (!$reseller->hasIp($ipId)) {
            throw Guard::badInput('input.ipAddressId', 'That IP address is not one of this reseller\'s.');
        }

        return $ipId;
    }

    /** ContactDetailsInput. Only the email is required; the panel's form agrees. */
    private function contactFor(array $input, Core $core): array
    {
        $contact = (array)($input['contact'] ?? array());
        $email = trim((string)($contact['email'] ?? ''));

        if ($email === '' || !$core->isValidEmail($core->toAscii($email))) {
            throw Guard::badInput('input.contact.email', 'Not an email address.');
        }

        $optional = array(
            'firstName' => 'firstName', 'lastName' => 'lastName', 'company' => 'company',
            'postcode' => 'postcode', 'city' => 'city', 'state' => 'state', 'country' => 'country',
            'phone' => 'phone', 'fax' => 'fax', 'street1' => 'street1', 'street2' => 'street2'
        );
        $values = array('email' => $core->toAscii($email), 'gender' => 'U');

        foreach ($optional as $name => $key) {
            $values[$name] = trim((string)($contact[$key] ?? ''));
        }

        if (isset($contact['gender']) && in_array((string)$contact['gender'], array('M', 'F', 'U'), true)) {
            $values['gender'] = (string)$contact['gender'];
        }

        return $values;
    }

    /** A DateTime, stored as the panel stores it: a unix timestamp, 0 for never. */
    private function expiryFor(array $input): int
    {
        if (!isset($input['expiresAt']) || $input['expiresAt'] === null) {
            return 0;
        }

        $at = strtotime((string)$input['expiresAt']);

        if ($at === false || $at <= time()) {
            throw Guard::badInput('input.expiresAt', 'An expiry date is in the future.');
        }

        return $at;
    }

    private function usernameTaken(string $username): bool
    {
        return (int)$this->kit->db()->value(
            'SELECT COUNT(*) FROM admin WHERE admin_name = ?', array($username)
        ) > 0;
    }

    private function requireRoomForOneMoreCustomer(ResellerAccount $reseller): void
    {
        $max = $reseller->maxOf('customers');

        if ($max > 0 && $reseller->usedOf('customers') >= $max) {
            throw Guard::limitExceeded(
                'You have reached the number of customers you may create.',
                array('quota' => 'customers', 'limit' => $max, 'used' => $reseller->usedOf('customers'))
            );
        }
    }
```

> `Guard::limitExceeded(string $message, array $extra): ApiException` may not exist yet — phase 3 raised `LIMIT_EXCEEDED` only through `requireQuota()`. Add it beside `badInput()` and `conflict()`, with the same extension shape `requireQuota()` produces, and cover it in `test/unit/Security/GuardTest.php`.

- [ ] **Step 6: Run the test until it passes**

Run: `tools/test.sh --testsuite integration --filter CustomerServiceTest`
Expected: PASS, 15 tests.

- [ ] **Step 7: Run the whole suite and commit**

Run: `tools/test.sh`
Expected: green, and the `CORE-DEBT` inventory now lists two more C11 sites.

```bash
git add Service/CustomerService.php Service/Core.php Service/PanelCore.php Security/Guard.php \
        test/Double/RecordingCore.php test/unit/Security/CoreCallsTest.php \
        test/integration/CustomerServiceTest.php test/unit/Security/GuardTest.php docs/SPECIFICATION.md
git commit -m "Create a customer in one call, the way three pages do it in three"
```

---

## Checkpoint A: `code-review medium` over Wave 1

Run by the orchestrating session, as the [Execution mandate](#waves-and-checkpoints) sets out.

**Focus:**

- `LimitRules`: the four tests in the panel's order, and the arithmetic of `(resellerLimit - resellerUsed) + customerLimit` at its boundaries — including a reseller whose consumption already exceeds its own limit.
- `Allowances`: the round trip against every vector, M7's underscore stripping applied exactly once, and a field that is absent from the input rather than empty.
- `CustomerService::create()`: the refusal order against spec §8.1 and against M1's pages; what a failure after the `admin` insert leaves; whether the welcome message can be reached on any path that did not commit.
- Secrets: the cleartext password reaches `hashAccountPassword()` and `sendAccountCreatedEmail()` and nothing else — not a log line, not an error, not an event payload.
- The two new C11 items: that each names a real line, and that the API demonstrably does the other thing.

- [ ] **Step 1: The suite, twice**

Run: `tools/test.sh`, then again.
Expected: both green.

- [ ] **Step 2: Review**

Invoke the `code-review` skill with arguments `medium phase4-wave-1..HEAD`, passing the **Focus** list above.

- [ ] **Step 3: Resolve the findings**

Fix each finding that holds, in commits whose subjects begin `Fix checkpoint A:`, then run `tools/test.sh` until green.

- [ ] **Step 4: Open the next wave**

Run: `git tag -a phase4-wave-2 -m "Checkpoint A: <declined findings and why, or: none declined>"`

---
## Wave 2: the rest of the customer lifecycle

Tasks 4–8. Ends at Checkpoint B.

---

## Task 4: Changing a customer — `sonnet`

The panel splits this in two: `reseller/user_edit.php` changes the contact details and the password; `reseller/domain_edit.php` changes the limits, the features and the IP. `customerUpdate` is both, and partial: an absent field keeps its value (the rule phase 3 set in D14).

**Files:**
- Modify: `Service/CustomerService.php`
- Modify: `test/integration/CustomerServiceTest.php`

**Interfaces:**
- Consumes: everything Task 3 produced, plus `Repository\Counts` for the customer's own consumption.
- Produces:
  ```php
  CustomerService::update(Identity $caller, string $id, array $input): ObjectRef
  ```

- [ ] **Step 1: Write the failing tests**

Add to `test/integration/CustomerServiceTest.php`:

```php
    public function testTheContactDetailsChangeWithoutTouchingTheLimits(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId());
        $before = $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($this->fixture->customerAdminId()));

        $this->service()->update($this->caller('reseller'), $id, array(
            'contact' => array('email' => 'new@sgwt.test', 'firstName' => 'Grace')
        ));

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->customerAdminId()));
        self::assertSame('new@sgwt.test', $admin['email']);
        self::assertSame('Grace', $admin['fname']);
        self::assertSame(
            $before, $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($this->fixture->customerAdminId()))
        );
    }

    public function testAPasswordChangeForcesTheCustomerToLogInAgain(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId());
        $this->db->execute(
            'INSERT INTO login (session_id, ipaddr, user_name, lastaccess) VALUES (?, ?, ?, ?)',
            array('sgwt-session', '127.0.0.1', $this->fixture->customerUsername(), time())
        );

        $this->service()->update($this->caller('reseller'), $id, array('password' => 'An0therSecret!'));

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->customerAdminId()));
        self::assertStringStartsWith('$apr1$', $admin['admin_pass']);
        self::assertSame('tochangepwd', $admin['admin_status']);
        self::assertSame(
            0,
            (int)$this->db->value('SELECT COUNT(*) FROM login WHERE user_name = ?', array($this->fixture->customerUsername()))
        );
    }

    public function testAnUpdateThatNamesNothingIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->update(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), array()
            );
        });
    }

    public function testLoweringALimitBelowWhatIsUsedIsRefused(): void
    {
        // The fixture's customer already has subdomains.
        $used = (int)$this->db->value(
            'SELECT COUNT(*) FROM subdomain WHERE domain_id = ?', array($this->fixture->domainId())
        );
        self::assertGreaterThan(0, $used, 'the fixture must have a subdomain for this to measure anything');

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () use ($used): void {
            $this->service()->update(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()),
                array('allowances' => array('subdomains' => $used - 1))
            );
        });
    }

    public function testChangingALimitSchedulesTheDomainAndPokesTheDaemon(): void
    {
        $this->service()->update(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()),
            array('allowances' => array('mailAccounts' => 42))
        );

        $domain = $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($this->fixture->customerAdminId()));
        self::assertSame(42, (int)$domain['domain_mailacc_limit']);
        self::assertContains(array('sendRequest'), $this->core->calls);
        self::assertContains(array('updateResellerCounters', $this->fixture->resellerId()), $this->core->calls);
    }

    public function testWithdrawingCustomDnsRemovesTheCustomersOwnRecords(): void
    {
        // domain_edit.php:920-927: the records go, the plugin-owned ones stay.
        $this->db->execute("UPDATE domain SET domain_dns = 'yes' WHERE domain_id = ?", array($this->fixture->domainId()));
        $this->insert('domain_dns', array(
            'domain_id' => $this->fixture->domainId(), 'alias_id' => 0, 'domain_dns' => "sgwt.\t3600",
            'domain_class' => 'IN', 'domain_type' => 'A', 'domain_text' => '203.0.113.9',
            'owned_by' => 'custom_dns_feature', 'domain_dns_status' => 'ok'
        ));
        $keptId = $this->insert('domain_dns', array(
            'domain_id' => $this->fixture->domainId(), 'alias_id' => 0, 'domain_dns' => "plugin.\t3600",
            'domain_class' => 'IN', 'domain_type' => 'A', 'domain_text' => '203.0.113.10',
            'owned_by' => 'some_plugin', 'domain_dns_status' => 'ok'
        ));

        $this->service()->update(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()),
            array('allowances' => array('customDns' => false))
        );

        self::assertSame(
            0,
            (int)$this->db->value(
                "SELECT COUNT(*) FROM domain_dns WHERE domain_id = ? AND owned_by = 'custom_dns_feature'",
                array($this->fixture->domainId())
            )
        );
        self::assertSame(
            1, (int)$this->db->value('SELECT COUNT(*) FROM domain_dns WHERE domain_dns_id = ?', array($keptId))
        );
    }

    public function testACustomerOfAnotherResellerIsNotFound(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            $this->service()->update(
                $this->caller('other-reseller'),
                GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()),
                array('contact' => array('email' => 'thief@example.test'))
            );
        });
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `tools/test.sh --testsuite integration --filter CustomerServiceTest`
Expected: FAIL, `Call to undefined method … CustomerService::update()`.

- [ ] **Step 3: Write the method**

Add to `Service/CustomerService.php`:

```php
    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/user_edit.php:44-120
     *   (the account) and gui/public/reseller/domain_edit.php:607-1010 (the
     *   allowances). The page splits them because it has two forms; one
     *   customer is one object here, so one mutation changes either.
     *   Retire when CustomerService lands in core.
     */
    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');
        $customer = $kit->accounts()->customer($target->getKey());
        $reseller = $kit->accounts()->reseller($customer->getResellerId());

        // 5. A customer in transit is not changed (spec section 8.3).
        Guard::requireState((string)$customer->domain('domain_status'), array(Provisioning::STATE_OK));

        // 6. An explicit null names nothing, as phase 3's checkpoint C settled.
        $given = array_filter($input, static function ($value) {
            return $value !== null;
        });
        $named = array_intersect(
            array_keys($given), array('password', 'contact', 'allowances', 'hostingPlanId', 'expiresAt', 'ipAddressId')
        );

        if ($named === array()) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        if (isset($given['allowances']) && isset($given['hostingPlanId'])) {
            throw Guard::badInput('input', 'Give at most one of hostingPlanId and allowances.');
        }

        $password = null;

        if (isset($given['password'])) {
            $password = (string)$given['password'];

            if ($password === '' || !$core->isAcceptablePassword($password)) {
                throw Guard::badInput('input.password', "The password does not meet the panel's password policy.");
            }
        }

        $contact = isset($given['contact']) ? $this->contactFor($given, $core) : null;
        $allowances = isset($given['allowances']) || isset($given['hostingPlanId'])
            ? $this->allowancesForUpdate($reseller, $customer, $given)
            : null;
        $ipId = isset($given['ipAddressId']) ? $this->ipFor($reseller, $given) : null;
        $expiresAt = array_key_exists('expiresAt', $input) ? $this->expiryOrNever($input) : null;

        // 7. Both ledgers again, now with the customer's own consumption.
        if ($allowances !== null) {
            $used = $kit->counts()->forCustomer($customer->getDomainId());

            foreach (array_keys(LimitRules::SERVICES) as $service) {
                $reason = LimitRules::reason(
                    $allowances->limit($service), (int)($used[$service] ?? 0),
                    (int)$customer->domain(self::LIMIT_COLUMNS[$service]),
                    $reseller->usedOf($service), $reseller->maxOf($service), $service
                );

                if ($reason !== null) {
                    throw Guard::limitExceeded($reason, array('quota' => $service));
                }
            }
        }

        $adminId = (int)$target->getKey();
        $domainId = $customer->getDomainId();
        $username = $customer->getUsername();
        $withdrawsDns = $allowances !== null
            && $allowances->feature('customDns') === '_no_'
            && (string)$customer->domain('domain_dns') === 'yes';
        // domain_edit.php:905-919: the daemon is needed when the IP, the mail
        // feature, PHP, CGI or the web folder protection changed.
        $needsDaemon = $allowances !== null || $ipId !== null;

        $kit->writer()->run(function () use (
            $kit, $core, $adminId, $domainId, $username, $password, $contact,
            $allowances, $ipId, $expiresAt, $withdrawsDns, $needsDaemon
        ) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeEditUser, array('userId' => $adminId));

            if ($password !== null || $contact !== null) {
                $db->execute(
                    "
                        UPDATE admin
                        SET admin_pass = IFNULL(?, admin_pass), fname = IFNULL(?, fname), lname = IFNULL(?, lname),
                            firm = IFNULL(?, firm), zip = IFNULL(?, zip), city = IFNULL(?, city),
                            state = IFNULL(?, state), country = IFNULL(?, country), email = IFNULL(?, email),
                            phone = IFNULL(?, phone), fax = IFNULL(?, fax), street1 = IFNULL(?, street1),
                            street2 = IFNULL(?, street2), gender = IFNULL(?, gender),
                            admin_status = IF(?, 'tochangepwd', admin_status)
                        WHERE admin_id = ?
                    ",
                    array(
                        $password === null ? null : $core->hashAccountPassword($password),
                        $contact === null ? null : $contact['firstName'],
                        $contact === null ? null : $contact['lastName'],
                        $contact === null ? null : $contact['company'],
                        $contact === null ? null : $contact['postcode'],
                        $contact === null ? null : $contact['city'],
                        $contact === null ? null : $contact['state'],
                        $contact === null ? null : $contact['country'],
                        $contact === null ? null : $contact['email'],
                        $contact === null ? null : $contact['phone'],
                        $contact === null ? null : $contact['fax'],
                        $contact === null ? null : $contact['street1'],
                        $contact === null ? null : $contact['street2'],
                        $contact === null ? null : $contact['gender'],
                        $password === null ? 0 : 1, $adminId
                    )
                );

                // user_edit.php:92 - a password or an email change ends the
                // customer's sessions.
                $db->execute('DELETE FROM login WHERE user_name = ?', array($username));
            }

            if ($withdrawsDns) {
                $db->execute(
                    "DELETE FROM domain_dns WHERE domain_id = ? AND owned_by = 'custom_dns_feature'",
                    array($domainId)
                );
            }

            if ($allowances !== null || $ipId !== null || $expiresAt !== null) {
                $columns = $allowances === null ? array() : $allowances->domainColumns();
                $sets = array('domain_last_modified = ?');
                $bind = array(time());

                foreach ($columns as $column => $value) {
                    $sets[] = $column . ' = ?';
                    $bind[] = $value;
                }

                if ($ipId !== null) {
                    $sets[] = 'domain_ip_id = ?';
                    $bind[] = $ipId;
                }

                if ($expiresAt !== null) {
                    $sets[] = 'domain_expires = ?';
                    $bind[] = $expiresAt;
                }

                $sets[] = 'domain_status = ?';
                $bind[] = $needsDaemon ? 'tochange' : 'ok';
                $bind[] = $domainId;

                $db->execute('UPDATE domain SET ' . implode(', ', $sets) . ' WHERE domain_id = ?', $bind);
            }

            $core->updateResellerCounters($kit->accounts()->customer($adminId)->getResellerId());
            $core->dispatch(Events::onAfterEditUser, array('userId' => $adminId));
        });

        if ($needsDaemon) {
            $core->sendRequest();
        }

        $core->writeLog(
            sprintf('The %s user has been updated by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }
```

with, on the class:

```php
    /** Allowance name => the `domain` column holding the customer's own limit. */
    const LIMIT_COLUMNS = array(
        'subdomains'    => 'domain_subd_limit',
        'domainAliases' => 'domain_alias_limit',
        'mailAccounts'  => 'domain_mailacc_limit',
        'ftpUsers'      => 'domain_ftpacc_limit',
        'sqlDatabases'  => 'domain_sqld_limit',
        'sqlUsers'      => 'domain_sqlu_limit'
    );
```

and a helper that merges a partial `allowances` over what the customer has today, so that an update naming one limit does not silently reset the other twenty-four fields:

```php
    /**
     * An update's allowances are partial. A hosting plan replaces them whole;
     * an explicit `allowances` is merged over the customer's current values,
     * because an input that names only `mailAccounts` must not reset the
     * customer's PHP permissions to an input default.
     */
    private function allowancesForUpdate(ResellerAccount $reseller, CustomerAccount $customer, array $given): Allowances
    {
        if (isset($given['hostingPlanId'])) {
            return $this->allowancesFor($reseller, array('hostingPlanId' => $given['hostingPlanId']));
        }

        return Allowances::fromInput(((array)$given['allowances']) + $this->currentAllowances($customer));
    }

    /** The customer's `domain` row, read back in CustomerAllowancesInput's terms. */
    private function currentAllowances(CustomerAccount $customer): array
    {
        $backup = (string)$customer->domain('allowbackup');
        $targets = array();

        foreach (explode('|', $backup) as $one) {
            $key = '_' . $one . '_';

            if (isset(PlanProps::BACKUP_TARGETS[$key])) {
                $targets[] = PlanProps::BACKUP_TARGETS[$key];
            }
        }

        return array(
            'subdomains'    => (int)$customer->domain('domain_subd_limit'),
            'domainAliases' => (int)$customer->domain('domain_alias_limit'),
            'mailAccounts'  => (int)$customer->domain('domain_mailacc_limit'),
            'ftpUsers'      => (int)$customer->domain('domain_ftpacc_limit'),
            'sqlDatabases'  => (int)$customer->domain('domain_sqld_limit'),
            'sqlUsers'      => (int)$customer->domain('domain_sqlu_limit'),
            'traffic'       => (int)$customer->domain('domain_traffic_limit'),
            'disk'          => (int)$customer->domain('domain_disk_limit'),
            'mailQuota'     => (int)$customer->domain('mail_quota'),
            'php'           => (string)$customer->domain('domain_php') === 'yes',
            'cgi'           => (string)$customer->domain('domain_cgi') === 'yes',
            'customDns'     => (string)$customer->domain('domain_dns') === 'yes',
            'externalMail'  => (string)$customer->domain('domain_external_mail') === 'yes',
            'webFolderProtection' => (string)$customer->domain('web_folder_protection') === 'yes',
            'backup'        => $targets,
            'phpEditor'     => (string)$customer->domain('phpini_perm_system') === 'yes',
            'phpiniAllowUrlFopen'    => (string)$customer->domain('phpini_perm_allow_url_fopen') === 'yes',
            'phpiniDisplayErrors'    => (string)$customer->domain('phpini_perm_display_errors') === 'yes',
            'phpiniDisableFunctions' => (string)$customer->domain('phpini_perm_disable_functions') === 'yes',
            'phpMailFunction'        => (string)$customer->domain('phpini_perm_mail_function') === 'yes'
        );
    }

    /** expiresAt: a date, or an explicit null meaning "never expires". */
    private function expiryOrNever(array $input): int
    {
        return $input['expiresAt'] === null ? 0 : $this->expiryFor($input);
    }
```

> `Repository\Counts::forCustomer(int $domainId): array` — keyed by the six allowance names — may need adding beside the batched methods phase 2 wrote. Reuse the existing queries; do not write new ones.

- [ ] **Step 4: Run the tests until they pass**

Run: `tools/test.sh --testsuite integration --filter CustomerServiceTest`
Expected: PASS.

- [ ] **Step 5: Run the whole suite and commit**

```bash
git add Service/CustomerService.php Repository/Counts.php test/integration/CustomerServiceTest.php
git commit -m "Change a customer's details, password and allowances"
```

---

## Task 5: Enabling, disabling and deleting a customer — `sonnet`

M12 and M10. `delete()` is the one service in the plugin that opens no transaction of its own (decision D22), and this task writes that exception into the specification.

**Files:**
- Modify: `Service/CustomerService.php`, `Service/Core.php`, `Service/PanelCore.php`, `test/Double/RecordingCore.php`
- Modify: `test/unit/Security/CoreCallsTest.php`, `docs/SPECIFICATION.md` (§3.2)
- Modify: `test/integration/CustomerServiceTest.php`

**Interfaces:**
- Produces:
  ```php
  CustomerService::setState(Identity $caller, string $id, string $state): ObjectRef  // 'ENABLED' | 'DISABLED'
  CustomerService::delete(Identity $caller, string $id): ObjectRef
  Core::changeDomainStatus(int $customerAdminId, string $action): void   // 'activate' | 'deactivate'
  Core::deleteCustomer(int $customerAdminId): bool
  ```

- [ ] **Step 1: Amend the specification**

In `docs/SPECIFICATION.md` §3.2, beside the four conditions D10 set out for calling a core helper, add:

```markdown
A helper that manages its own transaction *and* issues DDL is an exception to
condition (4), provided the API opens no transaction around it:
`deleteCustomer()` (`gui/include/Shared.php:779`) drops a customer's SQL
databases and then commits its own work. Transcribing it would duplicate 226
lines across 20 tables, and every later change to what a customer owns would
desynchronise silently. `CustomerService::delete()` therefore performs its
refusals, calls the helper, and runs no `Writer::run()` of its own.
```

- [ ] **Step 2: Write the failing tests**

Add to `test/integration/CustomerServiceTest.php`:

```php
    public function testDisablingASettledCustomerSchedulesIt(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId());

        $this->service()->setState($this->caller('reseller'), $id, 'DISABLED');

        self::assertContains(
            array('changeDomainStatus', $this->fixture->customerAdminId(), 'deactivate'), $this->core->calls
        );
    }

    public function testEnablingADisabledCustomerSchedulesIt(): void
    {
        $this->db->execute(
            "UPDATE domain SET domain_status = 'disabled' WHERE domain_id = ?", array($this->fixture->domainId())
        );

        $this->service()->setState(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), 'ENABLED'
        );

        self::assertContains(
            array('changeDomainStatus', $this->fixture->customerAdminId(), 'activate'), $this->core->calls
        );
    }

    public function testAskingForTheStateACustomerIsAlreadyInIsAConflict(): void
    {
        // M12: the page refuses anything but ok->deactivate and
        // disabled->activate, by way of showBadRequestErrorPage().
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->setState(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), 'ENABLED'
            );
        });
    }

    public function testACustomerInTransitIsNotStateChanged(): void
    {
        $this->db->execute(
            "UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId())
        );

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->setState(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), 'DISABLED'
            );
        });
    }

    public function testDeletingACustomerCallsThePanelsOwnHelperOutsideAnyTransaction(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId());

        $ref = $this->service()->delete($this->caller('reseller'), $id);

        self::assertSame($this->fixture->customerAdminId(), (int)$ref->getKey());
        self::assertContains(array('deleteCustomer', $this->fixture->customerAdminId()), $this->core->calls);
        // D22: no writer ran, so the fixture's own transaction is untouched.
        self::assertSame(array(), $this->core->callsNamed('commit'));
    }

    public function testARefusalHappensBeforeTheHelperIsCalled(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            $this->service()->delete(
                $this->caller('other-reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId())
            );
        });

        self::assertSame(array(), $this->core->callsNamed('deleteCustomer'));
    }

    public function testACustomerAlreadyOnItsWayOutIsNotDeletedTwice(): void
    {
        $this->db->execute(
            "UPDATE admin SET admin_status = 'todelete' WHERE admin_id = ?", array($this->fixture->customerAdminId())
        );

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->delete(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId())
            );
        });
    }
```

- [ ] **Step 3: Extend the port**

In `Service/PanelCore.php`:

```php
    /**
     * CORE-DEBT(C3): gui/include/Shared.php:445 change_domain_status().
     *   It schedules the customer's domain and everything under it, which is
     *   30 lines of UPDATEs this does not duplicate.
     */
    public function changeDomainStatus(int $customerAdminId, string $action): void
    {
        change_domain_status($customerAdminId, $action);
    }

    /**
     * CORE-DEBT(C3): gui/include/Shared.php:779 deleteCustomer(), called with
     *   $checkCreatedBy = false because the API has already established
     *   ownership (spec section 6.3) and the helper's own check reads
     *   $_SESSION.
     *
     * Decision D22: it owns its transaction and its DDL, so the caller must
     * not be inside a Writer::run().
     */
    public function deleteCustomer(int $customerAdminId): bool
    {
        return (bool)deleteCustomer($customerAdminId, false);
    }
```

Add `change_domain_status` and `deleteCustomer` to `CoreCallsTest`'s `ALLOWED` list.

- [ ] **Step 4: Write the two methods**

Add to `Service/CustomerService.php`:

```php
    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/domain_status_change.php:37-60.
     *   The page's two legal transitions are the whole rule (M12); it answers
     *   anything else with showBadRequestErrorPage(), which this reports as
     *   the CONFLICT it is.
     */
    public function setState(Identity $caller, string $id, string $state): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');
        $customer = $kit->accounts()->customer($target->getKey());
        $status = (string)$customer->domain('domain_status');

        if (!in_array($state, array('ENABLED', 'DISABLED'), true)) {
            throw Guard::badInput('state', 'A customer is ENABLED or DISABLED.');
        }

        $wanted = $state === 'DISABLED' ? 'deactivate' : 'activate';
        $from = $state === 'DISABLED' ? Provisioning::STATE_OK : 'disabled';

        if ($status !== $from) {
            throw Guard::conflict($status === ($state === 'DISABLED' ? 'disabled' : Provisioning::STATE_OK)
                ? sprintf('This customer is already %s.', strtolower($state))
                : 'This customer is not settled, so its state cannot be changed.');
        }

        $adminId = (int)$target->getKey();
        $core->changeDomainStatus($adminId, $wanted);
        $core->sendRequest();
        $core->writeLog(
            sprintf(
                'The %s customer has been %s by %s',
                $customer->getUsername(), $state === 'DISABLED' ? 'disabled' : 'enabled', $caller->getUsername()
            ),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }

    /**
     * Decision D22: the panel's own helper does the work, and this opens no
     * transaction around it. Every refusal happens first, so a caller who may
     * not delete never reaches it.
     */
    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');
        $customer = $kit->accounts()->customer($target->getKey());

        Guard::requireState(
            (string)$customer->adminStatus(),
            array(Provisioning::STATE_OK, Provisioning::STATE_ERROR, 'disabled')
        );

        $adminId = (int)$target->getKey();
        $username = $customer->getUsername();

        if (!$core->deleteCustomer($adminId)) {
            // It returns false only when the row it was given is not there,
            // which after Guard::target() means it went between the two.
            throw Guard::conflict('That customer is no longer there.');
        }

        // deleteCustomer() pokes the daemon itself (M10); a second request
        // would be harmless but dishonest about who did what.
        $core->writeLog(
            sprintf('The %s customer has been deleted by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }
```

> `CustomerAccount::adminStatus()` may not exist; add it beside `getUsername()`, returning the `admin` row's `admin_status`.

- [ ] **Step 5: Run and commit**

Run: `tools/test.sh --testsuite integration --filter CustomerServiceTest`, then `tools/test.sh`.

```bash
git add Service/CustomerService.php Service/Core.php Service/PanelCore.php Service/CustomerAccount.php \
        test/Double/RecordingCore.php test/unit/Security/CoreCallsTest.php \
        test/integration/CustomerServiceTest.php docs/SPECIFICATION.md
git commit -m "Enable, disable and delete a customer"
```

---

## Task 6: Granting and withdrawing API access — `haiku`

Spec §6.2's permission row already has a service (`Auth\AccessService::setApiAccess()`), a table and a panel page. This task puts it behind two mutations, one for a customer and one for a reseller, each refusing the accounts the caller may not reach.

**Files:**
- Modify: `Service/CustomerService.php`, `Service/ResellerService.php` *(created in Task 10 — write the customer half here and leave a one-line note in the ledger; Task 10 adds the reseller half)*
- Create: `test/integration/ApiAccessTest.php`

**Interfaces:**
- Consumes: `Auth\AccessService::setApiAccess(int $adminId, bool $allowed): void`, `Toolkit::access(): AccessService` *(add the accessor if `Toolkit` has none)*.
- Produces:
  ```php
  CustomerService::setApiAccess(Identity $caller, string $id, bool $allowed): ObjectRef
  ```

- [ ] **Step 1: Write the failing test**

Create `test/integration/ApiAccessTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Service\CustomerService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class ApiAccessTest extends ServiceTestCase
{
    public function testWithdrawingAccessWritesThePermissionRow(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId());

        (new CustomerService($this->kit))->setApiAccess($this->caller('reseller'), $id, false);

        self::assertSame(
            0,
            (int)$this->db->value('SELECT allowed FROM api_perm WHERE admin_id = ?', array($this->fixture->customerAdminId()))
        );
    }

    public function testGrantingItBackUpdatesTheSameRow(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId());
        $service = new CustomerService($this->kit);

        $service->setApiAccess($this->caller('reseller'), $id, false);
        $service->setApiAccess($this->caller('reseller'), $id, true);

        self::assertSame(
            1, (int)$this->db->value('SELECT COUNT(*) FROM api_perm WHERE admin_id = ?', array($this->fixture->customerAdminId()))
        );
        self::assertSame(
            1,
            (int)$this->db->value('SELECT allowed FROM api_perm WHERE admin_id = ?', array($this->fixture->customerAdminId()))
        );
    }

    public function testACustomerMayNotGrantItselfAccess(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            (new CustomerService($this->kit))->setApiAccess(
                $this->caller('customer'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), true
            );
        });
    }

    public function testAnotherResellersCustomerIsNotFound(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            (new CustomerService($this->kit))->setApiAccess(
                $this->caller('other-reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), false
            );
        });
    }

    public function testTheChangeIsLogged(): void
    {
        (new CustomerService($this->kit))->setApiAccess(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerAdminId()), false
        );

        self::assertNotSame(array(), $this->core->callsNamed('writeLog'));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `tools/test.sh --testsuite integration --filter ApiAccessTest`
Expected: FAIL, undefined method.

- [ ] **Step 3: Write the method**

Add to `Service/CustomerService.php`:

```php
    /**
     * Spec section 6.2. The row is the plugin's own (api_perm), so there is
     * no panel page to transcribe and no daemon to poke: the next request the
     * account makes is refused or allowed by AuthenticateMiddleware.
     */
    public function setApiAccess(Identity $caller, string $id, bool $allowed): ObjectRef
    {
        $kit = $this->kit;

        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');

        if (!$caller->isReseller() && !$caller->isAdministrator()) {
            throw Guard::forbidden('Only a reseller or an administrator may change API access.');
        }

        $adminId = (int)$target->getKey();
        $kit->access()->setApiAccess($adminId, $allowed);
        $kit->core()->writeLog(
            sprintf(
                'API access has been %s for %s by %s',
                $allowed ? 'granted' : 'withdrawn',
                $kit->accounts()->customer($adminId)->getUsername(),
                $caller->getUsername()
            ),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }
```

- [ ] **Step 4: Run and commit**

```bash
git add Service/CustomerService.php Service/Toolkit.php Api/Container.php test/integration/ApiAccessTest.php
git commit -m "Grant and withdraw a customer's API access"
```

---

## Task 7: Hosting plans — `sonnet`

M15. A plan is a name, a description, the 25-field props string and an availability flag. `hosting_plans` is a panel-only table: no status column, no daemon, no transaction in the page.

**Files:**
- Create: `Service/HostingPlanService.php`
- Create: `test/integration/HostingPlanServiceTest.php`

**Interfaces:**
- Consumes: `Support\Allowances`, `Support\PlanProps`, `Support\LimitRules`, `Service\ResellerAccount`.
- Produces:
  ```php
  new HostingPlanService(Toolkit $kit)
  HostingPlanService::create(Identity $caller, array $input): ObjectRef   // NodeType::HOSTING_PLAN
  HostingPlanService::update(Identity $caller, string $id, array $input): ObjectRef
  HostingPlanService::delete(Identity $caller, string $id): ObjectRef
  ```

- [ ] **Step 1: Write the failing test**

Create `test/integration/HostingPlanServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Service\HostingPlanService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;

class HostingPlanServiceTest extends ServiceTestCase
{
    private function service(): HostingPlanService
    {
        return new HostingPlanService($this->kit);
    }

    private function input(array $overrides = array()): array
    {
        return $overrides + array(
            'name' => 'sgwt-plan', 'description' => 'Written by a test', 'available' => true,
            'allowances' => array(
                'subdomains' => 2, 'domainAliases' => 1, 'mailAccounts' => 5, 'ftpUsers' => 2,
                'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024, 'disk' => 512,
                'mailQuota' => 0, 'php' => true, 'cgi' => false, 'customDns' => false,
                'externalMail' => false, 'backup' => array('DOMAIN'), 'phpEditor' => false
            )
        );
    }

    public function testAPlanIsStoredWithTheTwentyFiveFieldProps(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $row = $this->db->row('SELECT * FROM hosting_plans WHERE id = ?', array($ref->getKey()));
        self::assertSame('sgwt-plan', $row['name']);
        self::assertSame($this->fixture->resellerId(), (int)$row['reseller_id']);
        self::assertSame(1, (int)$row['status']);
        self::assertCount(25, explode(';', (string)$row['props']));
        self::assertSame(2, PlanProps::parse((string)$row['props'])->allowance('subdomains')['limit']);
    }

    public function testAPlanIsSynchronousAndPokesNoDaemon(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        self::assertSame(array(), $this->core->callsNamed('sendRequest'));
    }

    public function testTwoPlansOfTheSameNameForOneResellerIsAConflict(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input());
        });
    }

    public function testAPlanBeyondTheResellersOwnLimitsIsRefused(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET max_sub_cnt = 1, current_sub_cnt = 0 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('subdomains' => 5) + $this->input()['allowances']
            )));
        });
    }

    public function testAnUpdateReplacesThePropsWhole(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $this->service()->update($this->caller('reseller'), GlobalId::encode(NodeType::HOSTING_PLAN, $ref->getKey()), $this->input(array(
            'name' => 'sgwt-plan-2', 'available' => false,
            'allowances' => array('subdomains' => 9) + $this->input()['allowances']
        )));

        $row = $this->db->row('SELECT * FROM hosting_plans WHERE id = ?', array($ref->getKey()));
        self::assertSame('sgwt-plan-2', $row['name']);
        self::assertSame(0, (int)$row['status']);
        self::assertSame(9, PlanProps::parse((string)$row['props'])->allowance('subdomains')['limit']);
    }

    public function testAPlanIsDeletedOutright(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $this->service()->delete($this->caller('reseller'), GlobalId::encode(NodeType::HOSTING_PLAN, $ref->getKey()));

        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM hosting_plans WHERE id = ?', array($ref->getKey()))
        );
    }

    public function testAnotherResellersPlanIsNotFound(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $this->refused(ErrorCode::NOT_FOUND, function () use ($ref): void {
            $this->service()->delete(
                $this->caller('other-reseller'), GlobalId::encode(NodeType::HOSTING_PLAN, $ref->getKey())
            );
        });
    }

    public function testACustomerMayNotTouchPlansAtAll(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->create($this->caller('customer'), $this->input());
        });
    }
}
```

- [ ] **Step 2: Run it and watch it fail, then write the service**

Create `Service/HostingPlanService.php` — `create()` in full; `update()` and `delete()` follow the same six refusals:

```php
    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/hosting_plan_add.php:431-468.
     *   The page has no transaction and pokes no daemon: hosting_plans is a
     *   panel-only table (M15). Retire when HostingPlanService lands in core.
     */
    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        Guard::requireScope($caller, Scope::CUSTOMERS_WRITE);

        if (!$caller->isReseller() && !$caller->isAdministrator()) {
            throw Guard::forbidden('Only a reseller or an administrator may manage hosting plans.');
        }

        $reseller = $kit->accounts()->reseller($this->resellerFor($caller, $input));
        $name = trim((string)($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw Guard::badInput('input.name', 'A hosting plan needs a name of 1 to 255 characters.');
        }

        $allowances = Allowances::fromInput((array)($input['allowances'] ?? array()));

        // A plan may not promise more than the reseller itself has. The page
        // calls reseller_limits_check(); the rule is LimitRules' (D21), with
        // no customer on either side of the arithmetic yet.
        foreach (array_keys(LimitRules::SERVICES) as $service) {
            $reason = LimitRules::reason(
                $allowances->limit($service), 0, 0,
                $reseller->usedOf($service), $reseller->maxOf($service), $service
            );

            if ($reason !== null) {
                throw Guard::limitExceeded($reason, array('quota' => $service));
            }
        }

        if ($this->nameTaken($reseller->getAdminId(), $name, null)) {
            throw Guard::conflict('A hosting plan with that name already exists.');
        }

        $id = $kit->writer()->run(function () use ($kit, $reseller, $name, $input, $allowances) {
            $kit->db()->execute(
                'INSERT INTO hosting_plans (reseller_id, name, description, props, status) VALUES (?, ?, ?, ?, ?)',
                array(
                    $reseller->getAdminId(), $name, (string)($input['description'] ?? ''),
                    $allowances->toProps()->toString(), empty($input['available']) ? 0 : 1
                )
            );

            return $kit->db()->lastInsertId();
        });

        $core->writeLog(
            sprintf('A hosting plan (%s) has been created by %s', $name, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::HOSTING_PLAN, $id);
    }
```

`update()` resolves the plan through `Guard::target()` with `NodeType::HOSTING_PLAN`, applies the same name and limit rules with the plan's own id excluded from the uniqueness check, and writes `name`, `description`, `props` and `status` in one statement (`hosting_plan_edit.php:511`). `delete()` resolves it the same way and issues `DELETE FROM hosting_plans WHERE id = ? AND reseller_id = ?`, which is what `hosting_plan_delete.php:40` does — a plan already used by a customer is not referenced by any foreign key, so deleting it takes nothing with it.

- [ ] **Step 3: Run, then the whole suite, then commit**

```bash
git add Service/HostingPlanService.php test/integration/HostingPlanServiceTest.php
git commit -m "Create, change and delete hosting plans"
```

---

## Task 8: Approving and rejecting an alias order — `haiku`

M13 and M14. Phase 3 built `DomainAliasService`; this adds the two reseller-side verbs to it.

**Files:**
- Modify: `Service/DomainAliasService.php`
- Modify: `test/integration/DomainAliasServiceTest.php`

**Interfaces:**
- Produces:
  ```php
  DomainAliasService::approve(Identity $caller, string $id): ObjectRef
  DomainAliasService::reject(Identity $caller, string $id): ObjectRef
  ```

- [ ] **Step 1: Write the failing tests**

Add to `test/integration/DomainAliasServiceTest.php`:

```php
    public function testApprovingAnOrderSchedulesTheAlias(): void
    {
        $aliasId = $this->orderedAlias('sgwordered.test');

        $this->service()->approve($this->caller('reseller'), GlobalId::encode(NodeType::DOMAIN_ALIAS, $aliasId));

        self::assertSame(
            'toadd', (string)$this->db->value('SELECT alias_status FROM domain_aliasses WHERE alias_id = ?', array($aliasId))
        );
        self::assertContains(array('sendRequest'), $this->core->calls);
        self::assertSame('sgwordered.test', $this->core->eventNamed('onBeforeAddDomainAlias')['domainAliasName']);
    }

    public function testApprovingCreatesTheDefaultMailAccountsForTheAlias(): void
    {
        // M13: MT_ALIAS_FORWARD and the alias id, not the domain's.
        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => true));
        $aliasId = $this->orderedAlias('sgwordered.test');

        $this->service()->approve($this->caller('reseller'), GlobalId::encode(NodeType::DOMAIN_ALIAS, $aliasId));

        $call = $this->core->callsNamed('createDefaultMailAccounts')[0];
        self::assertSame('alias_forward', $call[4]);
        self::assertSame($aliasId, $call[5]);
    }

    public function testRejectingAnOrderRemovesTheRowAndItsPhpIni(): void
    {
        $aliasId = $this->orderedAlias('sgwordered.test');
        $this->insert('php_ini', array('domain_id' => $aliasId, 'domain_type' => 'als', 'admin_id' => $this->fixture->customerAdminId()));

        $this->service()->reject($this->caller('reseller'), GlobalId::encode(NodeType::DOMAIN_ALIAS, $aliasId));

        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM domain_aliasses WHERE alias_id = ?', array($aliasId))
        );
        self::assertSame(
            0,
            (int)$this->db->value(
                "SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($aliasId)
            ),
            'M14: unlike client/alias_order_delete.php (C11 item 8), this page does clear the php_ini row'
        );
    }

    public function testAnAliasThatIsNotOrderedCannotBeApproved(): void
    {
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->approve(
                $this->caller('reseller'), GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
            );
        });
    }

    public function testACustomerMayNotApproveItsOwnOrder(): void
    {
        $aliasId = $this->orderedAlias('sgwordered.test');

        $this->refused(ErrorCode::FORBIDDEN, function () use ($aliasId): void {
            $this->service()->approve($this->caller('customer'), GlobalId::encode(NodeType::DOMAIN_ALIAS, $aliasId));
        });
    }
```

with a small helper that inserts an `ordered` alias for the fixture's customer.

- [ ] **Step 2: Write the two methods**

```php
    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/alias_order.php:77-130.
     */
    public function approve(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');

        if (!$caller->isReseller() && !$caller->isAdministrator()) {
            throw Guard::forbidden('Only a reseller or an administrator may approve an alias order.');
        }

        $row = $kit->db()->row(
            '
                SELECT a.alias_id, a.alias_name, a.alias_status, a.domain_id, ad.email
                FROM domain_aliasses AS a
                JOIN domain AS d USING (domain_id)
                JOIN admin AS ad ON ad.admin_id = d.domain_admin_id
                WHERE a.alias_id = ?
            ',
            array($target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        Guard::requireState((string)$row['alias_status'], array('ordered'));

        $aliasId = (int)$row['alias_id'];
        $domainId = (int)$row['domain_id'];
        $name = (string)$row['alias_name'];
        $email = (string)$row['email'];
        $createDefaults = (bool)$kit->panelConfig(array('CREATE_DEFAULT_EMAIL_ADDRESSES'))['CREATE_DEFAULT_EMAIL_ADDRESSES'];

        $kit->writer()->run(function () use ($kit, $core, $aliasId, $domainId, $name, $email, $createDefaults) {
            $params = array('domainId' => $domainId, 'domainAliasName' => $name);
            $core->dispatch(Events::onBeforeAddDomainAlias, $params);

            $kit->db()->execute(
                "UPDATE domain_aliasses SET alias_status = 'toadd' WHERE alias_id = ?", array($aliasId)
            );

            if ($createDefaults) {
                $core->createDefaultMailAccounts($domainId, $email, $name, MailType::ALIAS_FORWARD, $aliasId);
            }

            $core->dispatch(Events::onAfterAddDomainAlias, array('domainAliasId' => $aliasId) + $params);
        });

        $core->sendRequest();
        $core->writeLog(sprintf('An alias order has been processed by %s.', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $aliasId);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/alias_order.php:39-71.
     *   The row is removed outright, not scheduled: nothing has been built for
     *   it yet, so there is nothing for the daemon to take away.
     */
    public function reject(Identity $caller, string $id): ObjectRef
    {
        /* … the same guards, then, inside Writer::run():
             DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'
             DELETE FROM domain_aliasses WHERE alias_id = ? AND alias_status = 'ordered'
           … then writeLog(), and no sendRequest(). */
    }
```

- [ ] **Step 3: Run and commit**

```bash
git add Service/DomainAliasService.php test/integration/DomainAliasServiceTest.php
git commit -m "Approve and reject an alias order"
```

---

## Checkpoint B: `code-review medium` over Wave 2

**Focus:**

- `CustomerService::update()`: the partial-allowances merge — an input naming one limit must not reset the other twenty-four fields; the `IFNULL` column list against `user_edit.php`'s; whether `domain_status` can be set to `ok` on a path that needed the daemon.
- `delete()`: that no `Writer::run()` surrounds `Core::deleteCustomer()` (D22), and that every refusal precedes it.
- `setState()`: M12's two legal transitions, and what a third state reports.
- `HostingPlanService`: the props written are 25 fields in `PlanProps::FIELDS` order; uniqueness is per reseller; an update excludes its own row from that check.
- `DomainAliasService::approve()`: the alias id — not the domain id — reaches `createDefaultMailAccounts()`, with `MT_ALIAS_FORWARD`.
- Across all five: a caller who is a customer is refused `FORBIDDEN`, and a reseller reaching another reseller's object is refused `NOT_FOUND`.

- [ ] **Step 1: The suite, twice** — `tools/test.sh`, then again. Both green.
- [ ] **Step 2: Review** — the `code-review` skill, `medium phase4-wave-2..HEAD`, with the Focus list.
- [ ] **Step 3: Resolve the findings** — `Fix checkpoint B:` commits, suite green.
- [ ] **Step 4: Open the next wave** — `git tag -a phase4-wave-3 -m "Checkpoint B: …"`

---
## Wave 3: the surface, and the administrator

Tasks 9–10. Ends at Checkpoint C, after which every mutation in spec §7.11 exists and the matrix runs all of them.

---

## Task 9: The customer and hosting-plan mutations — `sonnet`

Everything Waves 1 and 2 built, behind GraphQL. The SDL, one resolver class, the container wiring, the regenerated snapshot, and the authorisation matrix rows that make each new mutation a tested one.

**Files:**
- Create: `Resolver/CustomerMutations.php`
- Modify: `schema/schema.graphql`, `Api/Container.php`, `schema/schema.printed.graphql`
- Modify: `test/authz/MutationCatalogue.php`
- Create: `test/integration/CustomerMutationsTest.php`

**Interfaces:**
- Consumes: `CustomerService`, `HostingPlanService`, `DomainAliasService::approve()/reject()`, `Resolver\TypeResolver::identity($context): Identity`.
- Produces: the resolver map entries `Mutation.customerCreate`, `customerUpdate`, `customerDelete`, `customerSetState`, `customerSetApiAccess`, `hostingPlanCreate`, `hostingPlanUpdate`, `hostingPlanDelete`, `domainAliasApprove`, `domainAliasReject`.

- [ ] **Step 1: Add the fields to the SDL**

In `schema/schema.graphql`, inside `type Mutation`, after the DNS block:

```graphql
  # ---- customers (reseller and administrator)
  "Create a customer. An administrator must name the reseller; a reseller may not."
  customerCreate(input: CustomerCreateInput!): Customer!
  customerUpdate(id: ID!, input: CustomerUpdateInput!): Customer!
  "Schedules the customer and everything it owns for deletion."
  customerDelete(id: ID!): Customer!
  customerSetState(id: ID!, state: AccountState!): Customer!
  "Withdrawing access refuses the account's next API request; its tokens are left alone."
  customerSetApiAccess(id: ID!, allowed: Boolean!): Customer!

  hostingPlanCreate(input: HostingPlanInput!): HostingPlan!
  hostingPlanUpdate(id: ID!, input: HostingPlanInput!): HostingPlan!
  hostingPlanDelete(id: ID!): HostingPlan!

  "Reseller and administrator only. Approves an alias in the ORDERED state."
  domainAliasApprove(id: ID!): DomainAlias!
  "Removes the order. Nothing has been built for it, so nothing is scheduled."
  domainAliasReject(id: ID!): DomainAlias!
```

and the inputs:

```graphql
enum AccountState { ENABLED DISABLED }

input ContactDetailsInput {
  email: String!
  firstName: String
  lastName: String
  company: String
  street1: String
  street2: String
  city: String
  state: String
  postcode: String
  country: String
  phone: String
  fax: String
  "M, F or U. Defaults to U."
  gender: String
}

"""
Limits use spec section 2.3's vocabulary: -1 withholds the feature, 0 is
unlimited, n is n. `traffic`, `disk` and `mailQuota` are all bytes, like
every other BigInt here - i-MSCP stores `traffic` and `disk` in MiB, and
the conversion happens at the boundary, the same as it does on the way out
of `PlanProps::storage()`. A value that is not a whole number of MiB is
`BAD_USER_INPUT` rather than silently truncated.
"""
input CustomerAllowancesInput {
  subdomains: Int
  domainAliases: Int
  mailAccounts: Int
  ftpUsers: Int
  sqlDatabases: Int
  sqlUsers: Int
  traffic: BigInt
  disk: BigInt
  mailQuota: BigInt
  php: Boolean
  phpEditor: Boolean
  cgi: Boolean
  customDns: Boolean
  externalMail: Boolean
  webFolderProtection: Boolean
  backup: [BackupTarget!]
  phpiniAllowUrlFopen: Boolean
  phpiniDisplayErrors: Boolean
  phpiniDisableFunctions: Boolean
  phpMailFunction: Boolean
  phpiniPostMaxSize: Int
  phpiniUploadMaxFileSize: Int
  phpiniMaxExecutionTime: Int
  phpiniMaxInputTime: Int
  phpiniMemoryLimit: Int
}

input CustomerCreateInput {
  username: String!
  password: Secret!
  domainName: DomainName!
  ipAddressId: ID!
  contact: ContactDetailsInput!
  expiresAt: DateTime
  "An administrator must give this; a reseller may not name another reseller."
  resellerId: ID
  "Exactly one of hostingPlanId and allowances."
  hostingPlanId: ID
  allowances: CustomerAllowancesInput
  reference: String
  "Send i-MSCP's welcome message, which carries the password in clear. Defaults to false."
  sendWelcomeEmail: Boolean = false
}

"""
Partial: an absent field keeps its value. `allowances` is merged over what the
customer has today, so naming one limit changes one limit. `hostingPlanId`
replaces them all.
"""
input CustomerUpdateInput {
  password: Secret
  contact: ContactDetailsInput
  expiresAt: DateTime
  ipAddressId: ID
  hostingPlanId: ID
  allowances: CustomerAllowancesInput
}

input HostingPlanInput {
  name: String!
  description: String
  available: Boolean = true
  allowances: CustomerAllowancesInput!
  "An administrator must give this; a reseller may not name another reseller."
  resellerId: ID
}
```

> `BackupTarget` already exists in the SDL (phase 2's `PlanFeatures.backup`). Reuse it rather than adding a second enum.

- [ ] **Step 2: Write the resolver**

Create `Resolver/CustomerMutations.php`, following `Resolver/MailMutations.php` exactly in shape: one method per field, each turning the service's `ObjectRef` into the node the field returns.

```php
final class CustomerMutations
{
    /** @var CustomerService */
    private $customers;

    /** @var HostingPlanService */
    private $plans;

    /** @var DomainAliasService */
    private $aliases;

    /** @var TypeResolver */
    private $types;

    public function __construct(
        CustomerService $customers, HostingPlanService $plans, DomainAliasService $aliases, TypeResolver $types
    ) {
        $this->customers = $customers;
        $this->plans = $plans;
        $this->aliases = $aliases;
        $this->types = $types;
    }

    public function create($root, array $args, $context)
    {
        return $this->customer($this->customers->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function setState($root, array $args, $context)
    {
        return $this->customer(
            $this->customers->setState(TypeResolver::identity($context), (string)$args['id'], (string)$args['state'])
        );
    }

    /* … one method per field, then: */

    private function customer(ObjectRef $ref): array
    {
        return $this->types->nodeFor($ref);
    }
}
```

- [ ] **Step 3: Wire it and regenerate the snapshot**

Add the ten `Mutation.*` entries to `Schema/ResolverMap.php` and build the resolver in `Api/Container.php` beside `mailMutations()`. Then:

Run: `composer schema` (inside the box or container: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 /var/www/imscp/gui/bin/composer.phar schema'`)
Expected: the snapshot gains the ten fields and the five inputs. Never hand-edit it.

- [ ] **Step 4: Add the matrix rows**

Add ten entries to `test/authz/MutationCatalogue::all()`. Each names `Scope::CUSTOMERS_WRITE`, a document selecting only `id`, a `prepare` that makes the owner's call valid, and `variables`. The matrix's own expectations change for these rows, and that is the point: for a customer-owned mutation the customer is the owner, but for these the **reseller** is, and a customer is `FORBIDDEN`. Extend `AuthorisationMatrixTest` with the role expectation each row carries rather than assuming one shape for all rows.

- [ ] **Step 5: Write the end-to-end test**

Create `test/integration/CustomerMutationsTest.php` with three cases, in `AuthzTestCase`'s style: a customer reads back after `customerCreate`; a `Secret` never appears in an error's `extensions`; `customerSetState` reports `CONFLICT` for a state the customer is already in.

- [ ] **Step 6: Run everything and commit**

Run: `tools/test.sh --testsuite integration --filter CustomerMutationsTest`, `tools/test.sh --testsuite authz`, then `tools/test.sh`.

```bash
git add schema/schema.graphql schema/schema.printed.graphql Resolver/CustomerMutations.php \
        Schema/ResolverMap.php Api/Container.php test/authz/MutationCatalogue.php \
        test/authz/AuthorisationMatrixTest.php test/integration/CustomerMutationsTest.php
git commit -m "Open the customer, hosting plan and alias order mutations"
```

---

## Task 10: Resellers — `sonnet`

Phase 5 of the spec, in one task: M16's create, the edit page's update, `deleteCustomer()`'s sibling for a reseller, and the API-access switch. An administrator is the only caller.

**Files:**
- Create: `Service/ResellerService.php`, `Resolver/ResellerMutations.php`, `test/integration/ResellerServiceTest.php`
- Modify: `schema/schema.graphql`, `Api/Container.php`, `Schema/ResolverMap.php`, `schema/schema.printed.graphql`, `test/authz/MutationCatalogue.php`
- Modify: `Service/Core.php`, `Service/PanelCore.php`, `test/unit/Security/CoreCallsTest.php`

**Interfaces:**
- Produces:
  ```php
  new ResellerService(Toolkit $kit)
  ResellerService::create(Identity $caller, array $input): ObjectRef    // NodeType::RESELLER
  ResellerService::update(Identity $caller, string $id, array $input): ObjectRef
  ResellerService::delete(Identity $caller, string $id): ObjectRef
  ResellerService::setApiAccess(Identity $caller, string $id, bool $allowed): ObjectRef
  Core::deleteReseller(int $resellerAdminId): bool
  ```

- [ ] **Step 1: Confirm two facts on the box before writing**

```bash
../imscp/docker/imscp exec sh -c "mysql -N -B -e \"SHOW COLUMNS FROM imscp.admin LIKE 'admin_status'\""
```

M20: what `admin_status` defaults to, since `reseller_add.php` does not set it (M16). Record the answer in the report; the insert must produce the same value the page produces.

Then find the panel's reseller delete. `admin/user_delete.php` handles both roles; read it and record which helper it calls for a reseller and what it does about that reseller's customers — a reseller with customers must not be deletable, or its customers become orphans.

- [ ] **Step 2: Write the failing test**

Create `test/integration/ResellerServiceTest.php`. The shape mirrors `CustomerServiceTest`; the cases that differ, and must be present:

```php
    public function testAResellerIsCreatedWithItsPropsRowAndItsIps(): void
    public function testEveryCurrentCounterStartsAtZero(): void            // M16
    public function testTheIpListIsStoredSortedWithATrailingSemicolon(): void  // M17
    public function testAResellerIsSynchronousAndPokesNoDaemon(): void      // M16: no send_request()
    public function testAtLeastOneIpIsRequired(): void                      // reseller_add.php:283
    public function testOnlyAnAdministratorMayCreateAReseller(): void
    public function testAResellerWithCustomersCannotBeDeleted(): void
    public function testDeletingAResellerRemovesItsPropsAndItsTokens(): void
```

- [ ] **Step 3: Write the service**

`create()` transcribes `admin/reseller_add.php:366-440`: the `admin` row (`admin_type = 'reseller'`, no `admin_status` — M16 and M20), the `user_gui_props` row, and the 31-column `reseller_props` row with every `current_*` explicitly `0`. The IP list is `implode(';', $sorted) . ';'` (M17). Events are `onBeforeAddUser` / `onAfterAddUser`, each with `userData`. The welcome message goes **after** the commit, for C11 item 12's reason.

`update()` follows `admin/reseller_edit.php`: the contact details and password like a customer's, the `max_*` columns, and the IP list. A `max_*` lowered below the reseller's own `current_*` is refused — the same shape as `LimitRules`' fourth test, with the reseller in the customer's place.

`delete()` calls `Core::deleteReseller()` if the panel has such a helper; if `admin/user_delete.php` does the work inline for a reseller, transcribe it with a `CORE-DEBT(C3)` marker, and refuse outright when `current_dmn_cnt > 0`.

`setApiAccess()` is Task 6's method with `NodeType::RESELLER` and `Scope::RESELLERS_WRITE`.

- [ ] **Step 4: The surface**

Four mutations in the SDL, mirroring Task 9's shape:

```graphql
  # ---- resellers (administrator)
  resellerCreate(input: ResellerCreateInput!): Reseller!
  resellerUpdate(id: ID!, input: ResellerUpdateInput!): Reseller!
  "Refused while the reseller still has customers."
  resellerDelete(id: ID!): Reseller!
  resellerSetApiAccess(id: ID!, allowed: Boolean!): Reseller!
```

with `ResellerCreateInput` (username, password, contact, `ipAddressIds: [ID!]!`, the `max_*` allowances, `supportSystem: Boolean`) and a partial `ResellerUpdateInput`. Regenerate the snapshot, wire the container, add four catalogue rows.

- [ ] **Step 5: Run everything and commit**

Run: `tools/test.sh --testsuite integration --filter ResellerServiceTest`, `tools/test.sh --testsuite authz`, `tools/test.sh`.

```bash
git add Service/ResellerService.php Resolver/ResellerMutations.php Service/Core.php Service/PanelCore.php \
        schema/schema.graphql schema/schema.printed.graphql Schema/ResolverMap.php Api/Container.php \
        test/unit/Security/CoreCallsTest.php test/authz/MutationCatalogue.php \
        test/integration/ResellerServiceTest.php
git commit -m "Create, change and delete resellers"
```

---

## Checkpoint C: `code-review medium` over Wave 3

**Focus:**

- Every mutation in spec §7.11 is now in the SDL, in the catalogue, and in the printed snapshot — and the three lists agree. `CatalogueCoverageTest` should be what proves it; if it cannot, it is the test that is wrong.
- The matrix's role expectations for the reseller-level rows: a customer is `FORBIDDEN`, another reseller is `NOT_FOUND`, an administrator succeeds.
- `resellerCreate`: `current_*` counters at zero, the IP list's trailing semicolon, and `admin_status` matching what the page produces (M20).
- `resellerDelete`: a reseller with customers is refused before anything is written.
- The two `setApiAccess` mutations: that neither can be reached by the account whose access it changes.
- Secrets: `customerCreate`, `customerUpdate`, `resellerCreate` and `resellerUpdate` all take a `Secret`; none of them may put it in a log line, an event payload, an error or a returned field.

- [ ] **Step 1: The suite, twice** — both green.
- [ ] **Step 2: Review** — `medium phase4-wave-3..HEAD`, with the Focus list.
- [ ] **Step 3: Resolve the findings** — `Fix checkpoint C:` commits.
- [ ] **Step 4: Open the next wave** — `git tag -a phase4-wave-4 -m "Checkpoint C: …"`

---
## Wave 4: the unauthenticated edge

Tasks 11–13. Ends at Checkpoint D — the one checkpoint whose focus list is entirely about what an attacker can do.

Two measurements belong to this wave and were taken with it:

| # | Question | How | Answer |
| --- | --- | --- | --- |
| M21 | What does `AuthService::authenticate()` read, and what does it leave behind? | `gui/src/Authentication/AuthService.php:93-140`, `gui/include/Login.php:72-74` | It reads `$_POST['uname']` and `$_POST['upass']` through the registered credential handler, and on success calls `setIdentity()`, which regenerates the session and writes a `login` row. `unsetIdentity()` deletes that row by `session_id()`. |
| M22 | Is APCu usable from the panel's PHP? | `../imscp/docker/imscp exec php7.4 -r 'var_dump(function_exists("apcu_fetch"), ini_get("apc.enabled"));'` | Task 11 runs this first and records the answer. The fallback is written either way. |

---

## Task 11: The rate limiter — `opus`

Spec §10.3's three buckets. APCu where it exists, the database where it does not, and — this is the part that needs judgement — a fallback that is honest about being approximate rather than one that silently stops limiting.

**Files:**
- Create: `Service/RateLimiter.php`, `Http/RateLimitMiddleware.php`
- Create: `test/unit/Service/RateLimiterTest.php`, `test/integration/RateLimitTest.php`
- Modify: `Api/Container.php`, `SGW_GraphQL.php` (the middleware stack), `sql/002_create_rate_table.php`

**Interfaces:**
- Produces:
  ```php
  new RateLimiter(callable $now, ?ApcuStore $apcu, ?Db $db)
  RateLimiter::charge(string $bucket, string $key, int $limit, int $windowSeconds): int  // seconds to wait, 0 when allowed
  RateLimiter::isApproximate(): bool          // true when running on the database fallback
  new RateLimitMiddleware(RateLimiter $limiter, array $options)
  ```

- [ ] **Step 1: Measure APCu on the box**

```bash
../imscp/docker/imscp exec php7.4 -r 'var_dump(function_exists("apcu_fetch"), ini_get("apc.enabled"), ini_get("apc.enable_cli"));'
```

Record the three values in the report. They decide which path the integration test exercises by default, not whether the fallback is written.

- [ ] **Step 2: Write the migration**

Create `sql/002_create_rate_table.php`, in `001`'s shape, adding:

```sql
CREATE TABLE IF NOT EXISTS `api_rate` (
    `bucket`     varchar(32) NOT NULL,
    `rate_key`   varchar(190) NOT NULL,
    `window_at`  int(11) unsigned NOT NULL,
    `hits`       int(11) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (`bucket`, `rate_key`, `window_at`),
    KEY `api_rate_window_at` (`window_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
```

`down()` drops it. The plugin's uninstall already drops its own tables; add this one to that list.

- [ ] **Step 3: Write the failing unit test**

Create `test/unit/Service/RateLimiterTest.php`, driving a fake clock and an in-memory store:

```php
    public function testTheFirstRequestInAWindowIsAllowed(): void
    public function testTheLimitIsTheNumberAllowedNotTheNumberRefused(): void
        // limit 3: three charges return 0, the fourth returns > 0.
    public function testTheWaitIsTheTimeLeftInTheWindowNotTheWholeWindow(): void
        // at t=10 in a 60s window, the fourth charge asks for 50, not 60.
    public function testANewWindowStartsCleanly(): void
    public function testTwoKeysInOneBucketDoNotShareACount(): void
    public function testTwoBucketsWithOneKeyDoNotShareACount(): void
    public function testALimitOfZeroRefusesEverything(): void
    public function testANegativeLimitMeansUnlimited(): void
        // The config keys are operator-facing; -1 must not mean "refuse all".
```

- [ ] **Step 4: Write the limiter**

Create `Service/RateLimiter.php`. The shape that matters:

```php
    /**
     * Charge one hit against a bucket.
     *
     * @return int seconds the caller must wait, or 0 when the hit is allowed.
     *
     * Fixed windows, not a sliding log: spec section 10.3 says "in a fixed
     * window", and a token bucket would need a second value per key and a
     * read-modify-write that APCu cannot make atomic.
     */
    public function charge(string $bucket, string $key, int $limit, int $windowSeconds): int
    {
        if ($limit < 0) {
            return 0;
        }

        $now = (int)call_user_func($this->now);
        $window = $now - ($now % $windowSeconds);
        $hits = $this->increment($bucket . ':' . $key . ':' . $window, $windowSeconds);

        return $hits > $limit ? ($window + $windowSeconds) - $now : 0;
    }
```

`increment()` uses `apcu_inc($name, 1, $created)` — which is atomic, and tells you through `$created` whether it had to create the entry, so the TTL is set exactly once — and falls back to

```sql
INSERT INTO api_rate (bucket, rate_key, window_at, hits) VALUES (?, ?, ?, 1)
ON DUPLICATE KEY UPDATE hits = hits + 1
```

followed by reading the row back. The database path is one statement plus one read and is racy only in the direction of *under*-counting by at most the number of concurrent requests, which is stated in the class docblock rather than hidden. `isApproximate()` returns true on that path so the audit row can say which was used.

Rows older than two windows are pruned opportunistically — one `DELETE FROM api_rate WHERE window_at < ?` per hundred charges, chosen by `random_int(1, 100) === 1`, so no cron is needed and no request pays for it twice.

- [ ] **Step 5: Write the middleware**

Create `Http/RateLimitMiddleware.php`, placed after `AuthenticateMiddleware` in `SGW_GraphQL.php`'s stack. It charges one bucket per request:

- the key is `'token:' . $token->getId()` when the request carried a token, `'session:' . $identity->getAdminId()` for a session, and `'ip:' . $clientIp` for an unauthenticated request;
- the bucket is `queries` until the handler knows better. The handler parses the document; a mutation therefore has to be charged from inside it. So the middleware charges `queries`, and `GraphQLHandler` charges `mutations` additionally when the operation is a mutation — the two buckets are separate in spec §10.3, so charging both for a mutation is what "30 mutations a minute" means alongside "120 queries a minute";
- a refusal is HTTP `429`, `Retry-After: <seconds>`, `Content-Type: application/json`, and a body of one GraphQL error with `extensions.code = 'RATE_LIMITED'` and `extensions.retryAfterSeconds`. No `data` key: the request never reached execution.

`ErrorCode::RATE_LIMITED` may not exist yet; add it beside the others and to the table in `docs/API.md`.

- [ ] **Step 6: Write the integration test**

Create `test/integration/RateLimitTest.php`: with `rate_limit_queries` configured to 2, a third query in the same window is `429` with a `Retry-After` between 1 and 60; a mutation charges both buckets; two different tokens do not share a count; and — the one that matters — when the limiter is forced onto the database fallback the same four assertions hold.

- [ ] **Step 7: Run everything and commit**

```bash
git add Service/RateLimiter.php Http/RateLimitMiddleware.php sql/002_create_rate_table.php \
        Api/Container.php SGW_GraphQL.php Support/ErrorCode.php \
        test/unit/Service/RateLimiterTest.php test/integration/RateLimitTest.php
git commit -m "Rate limit the endpoint, in APCu or in the database"
```

---

## Task 12: `tokenIssue` and `tokenRevoke` — `opus`

The only unauthenticated field in the schema (spec §5.3). Everything about this task is about what it must *not* do.

**Files:**
- Create: `Resolver/TokenMutations.php`, `test/integration/TokenMutationsTest.php`
- Modify: `schema/schema.graphql`, `Api/Container.php`, `Schema/ResolverMap.php`, `schema/schema.printed.graphql`
- Modify: `Service/Core.php`, `Service/PanelCore.php`, `test/unit/Security/CoreCallsTest.php`
- Modify: `Http/AuthenticateMiddleware.php` (let an unauthenticated request through to the handler)

**Interfaces:**
- Consumes: `Auth\TokenService::issue(...)`, `revoke(int $adminId, int $tokenId): bool`, `RateLimiter::charge()`.
- Produces:
  ```php
  Core::authenticate(string $username, string $password): ?array   // admin_id, admin_name, admin_type — or null
  ```

- [ ] **Step 1: Write the failing test**

Create `test/integration/TokenMutationsTest.php`. These cases are the specification of the field:

```php
    public function testTheRightCredentialsMintAToken(): void
    public function testTheTokenIsReturnedExactlyOnceAndNeverReadBack(): void
        // The secret appears in the mutation's result and in no query.
    public function testTheWrongPasswordIsRefusedWithoutSayingWhichPartWasWrong(): void
        // One message for a bad username and a bad password alike.
    public function testNoLoginRowAndNoSessionSurvive(): void
        // M21: authenticate() sets an identity; the field must undo it.
    public function testAnAccountWithoutApiAccessCannotMintAToken(): void
        // Spec section 6.2 applies before the token exists.
    public function testAnAccountThatIsNotOkCannotMintAToken(): void
    public function testTheScopesAskedForAreTheScopesCarried(): void
    public function testAScopeTheRoleCannotHoldIsRefused(): void
        // A customer asking for RESELLERS_WRITE.
    public function testTheLifetimeIsCappedByTokenMaxTtlDays(): void
    public function testTheAccountsTokenLimitIsEnforced(): void
        // token_max_per_account.
    public function testTheFieldIsRefusedWhenAllowPasswordGrantIsFalse(): void
    public function testFiveAttemptsAMinuteFromOneAddressIsTheLimit(): void
    public function testTenAttemptsAnHourForOneUsernameIsTheLimit(): void
    public function testAFailedAttemptCostsTheSameAsASuccessfulOne(): void
        // The buckets are charged before authenticate(), so a wrong password
        // cannot be used to probe cheaply.
    public function testThePasswordIsNeverInAnError(): void
    public function testRevokingSomeoneElsesTokenIsNotFound(): void
    public function testRevokingIsIdempotent(): void
```

- [ ] **Step 2: Add the fields to the SDL**

```graphql
  # ---- credentials (spec section 5)
  """
  The only field that may be called without authentication. Rate limited far
  more tightly than anything else (5 a minute per source address, 10 an hour
  per username), and disabled entirely when `allow_password_grant` is false.

  The secret in `token` is shown once, here, and can never be read back.
  """
  tokenIssue(input: TokenIssueInput!): TokenIssueResult!
  "Revokes one of the caller's own tokens. Revoking a revoked token succeeds."
  tokenRevoke(id: ID!): ApiToken!
```

```graphql
input TokenIssueInput {
  username: String!
  password: Secret!
  "Shown in the panel so a user can tell their tokens apart."
  name: String!
  scopes: [Scope!]!
  expiresInDays: Int
  "CIDRs the token may be presented from. Empty means anywhere."
  ipAllowlist: [String!]
}

type TokenIssueResult {
  "The bearer token, in full. This is the only time it is ever returned."
  token: Secret!
  apiToken: ApiToken!
  expiresAt: DateTime
}
```

`ApiToken` and `Scope` already exist in the SDL from phase 1; reuse them.

- [ ] **Step 3: Extend the port**

```php
    /**
     * CORE-DEBT(C1): AuthService::authenticate() takes its credentials from
     *   $_POST through the registered handler (M21), and on success calls
     *   setIdentity(), which regenerates the session and writes a `login`
     *   row. Spec section 5.3 wants neither.
     *
     * So: $_POST is populated for the call and restored afterwards, and the
     * identity is unset immediately whether or not the call succeeded. The
     * window in which a session exists is this method's body, on a request
     * that has no session cookie to carry it anywhere.
     *
     * Everything the panel does on a login - the BruteForce plugin, the
     * account status and expiry checks in login_checkDomainAccount(), the
     * APR-1 rehash of a legacy password - happens inside this call and is not
     * reimplemented.
     *
     * @return array{admin_id: int, admin_name: string, admin_type: string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $saved = $_POST;
        $_POST['uname'] = $username;
        $_POST['upass'] = $password;

        try {
            $result = AuthService::getInstance()->authenticate();

            if (!$result->isValid()) {
                return null;
            }

            $identity = $result->getIdentity();

            return array(
                'admin_id'   => (int)$identity->admin_id,
                'admin_name' => (string)$identity->admin_name,
                'admin_type' => (string)$identity->admin_type
            );
        } finally {
            AuthService::getInstance()->unsetIdentity();
            $_POST = $saved;
        }
    }
```

Add `AuthService` to `CoreCallsTest`'s allowances the way the class list is expressed there.

- [ ] **Step 4: Write the resolver**

Create `Resolver/TokenMutations.php`. The order inside `issue()` is the whole security argument, and the comments must say so:

```php
    public function issue($root, array $args, $context)
    {
        $input = (array)$args['input'];
        $username = trim((string)($input['username'] ?? ''));

        if (!$this->allowPasswordGrant) {
            throw Guard::forbidden('Minting a token from a password is disabled on this installation.');
        }

        // Charged BEFORE authenticate(), and for the username as given, so a
        // wrong password costs exactly what a right one costs. A limiter that
        // charged only failures would be a free oracle for valid usernames.
        $this->requireRoom('token_issue_ip', $this->clientIp, $this->perIpLimit, 60);
        $this->requireRoom('token_issue_user', mb_strtolower($username), $this->perUserLimit, 3600);

        $account = $this->core->authenticate($username, (string)($input['password'] ?? ''));

        if ($account === null) {
            // One message for every failure: spec section 5.1's rule for the
            // token path applies here too.
            throw Guard::unauthenticated('Those credentials were not accepted.');
        }

        if (!$this->access->isAllowed((int)$account['admin_id'])) {
            throw Guard::forbidden('API access has been withdrawn from this account.');
        }

        $scopes = $this->scopesFor($account['admin_type'], (array)($input['scopes'] ?? array()));
        $token = $this->tokens->issue(/* … admin id, name, scopes, ttl, allowlist … */);

        $this->core->writeLog(
            sprintf('An API token (%s) has been issued for %s', $token->getName(), $account['admin_name']),
            E_USER_NOTICE
        );

        return array('token' => $token->getSecret(), 'apiToken' => $token->toArray(), 'expiresAt' => $token->getExpiresAt());
    }
```

`Guard::unauthenticated()` may not exist; add it, returning `ErrorCode::UNAUTHENTICATED`, with no extension that distinguishes the failures.

`AuthenticateMiddleware` must let a request with no credentials reach the handler *only* when the document's single operation is `tokenIssue`. Rather than parsing in the middleware, let it pass unauthenticated requests through with a null identity, and have every other resolver refuse through the existing scope check — `TypeResolver::identity()` already throws for a null identity, so the change is to stop the middleware short-circuiting, not to weaken anything downstream. State that in the middleware's docblock and cover it with a test that an unauthenticated `{ viewer { username } }` is still refused.

- [ ] **Step 5: Run everything and commit**

```bash
git add Resolver/TokenMutations.php Service/Core.php Service/PanelCore.php Security/Guard.php \
        Http/AuthenticateMiddleware.php schema/schema.graphql schema/schema.printed.graphql \
        Schema/ResolverMap.php Api/Container.php test/unit/Security/CoreCallsTest.php \
        test/integration/TokenMutationsTest.php
git commit -m "Mint and revoke tokens over the API"
```

---

## Task 13: The audit row, and redaction by type — `opus`

Spec §11. The mechanism that matters is the redaction: it walks the operation's argument types, so a field that carries a secret is redacted because of its type and not because someone remembered to add its name to a list.

**Files:**
- Create: `Service/Audit.php`, `test/unit/Service/AuditRedactionTest.php`, `test/integration/AuditTest.php`
- Modify: `Http/GraphQLHandler.php`, `Api/Container.php`

**Interfaces:**
- Produces:
  ```php
  new Audit(Db $db, Schema $schema, string $mode, int $retentionDays, callable $now)
  Audit::record(?DocumentNode $document, array $variables, array $result, AuditContext $context): void
  Audit::redact(?DocumentNode $document, array $variables, Schema $schema): array
  Audit::prune(): int
  ```

- [ ] **Step 1: Write the failing redaction test**

Create `test/unit/Service/AuditRedactionTest.php`. It builds the real schema — the redaction is only as good as the types it walks — and asserts:

```php
    public function testASecretVariableIsReplaced(): void
        // mutation($p: Secret!) { … }  =>  variables['p'] === '***'
    public function testANonSecretVariableSurvives(): void
    public function testASecretInsideAnInputObjectIsReplaced(): void
        // CustomerCreateInput.password, reached through $input.
    public function testASecretInsideAListOfInputObjectsIsReplaced(): void
    public function testANestedInputObjectIsWalkedToTheBottom(): void
    public function testAVariableTheDocumentDoesNotDeclareIsDroppedNotGuessed(): void
    public function testAnUnparseableDocumentRedactsEverything(): void
        // Fail closed: with no types to walk, no value can be shown to be safe.
    public function testTheShapeIsPreservedSoAnAuditRowStaysReadable(): void
        // Keys and array shape stay; only the leaf values change.
```

The last two are the ones worth writing first: they are the cases a name-based redactor gets wrong.

- [ ] **Step 2: Write the redactor**

`Audit::redact()` takes the document's `OperationDefinitionNode`, reads its `variableDefinitions`, resolves each declared type through `TypeInfo`/`AST::typeFromAST($schema, $node->type)`, and walks the value against the type:

- a `Secret` (by `getName() === 'Secret'` on the named type) becomes `'***'`;
- a `ListOfType` walks each element against the inner type;
- an `InputObjectType` walks each field it declares, dropping keys the type does not declare;
- anything else is kept as given;
- a variable with no declaration, or a document that did not parse, yields `'***'` — failing closed, because a value whose type is unknown cannot be shown to be safe.

- [ ] **Step 3: Write the recorder and wire it**

`Audit::record()` writes one row: `at`, `admin_id`, `token_id`, `ip`, `operation` (the operation name, or null), `fields` (the top-level mutation field names, comma-joined, capped at the column's 1024), `variables` (the redacted array, JSON, capped), `outcome` (`ok` when the result has no `errors`), `error_code` (the first error's `extensions.code`), `duration_ms`.

`audit` in `config.php` selects `none`, `mutations` (the default) or `all`; `mutations` records only documents that contain one.

It is called from `GraphQLHandler`'s `finally`, so a request that threw is still recorded, and a failure to write is caught, logged through `Core::writeLog()` and swallowed — decision D27. Add a test that proves it: with the `api_audit` table renamed away, the request still answers 200.

`prune()` deletes rows older than `audit_retention_days`, and is called opportunistically like the rate table's prune.

- [ ] **Step 4: Write the integration test**

Create `test/integration/AuditTest.php`: a mutation writes exactly one row with the right fields and outcome; a query writes none in `mutations` mode and one in `all`; a failed mutation records `outcome = 'error'` and the error's code; **a `tokenIssue` records a row whose `variables` contain no password**, and whose `admin_id` is the account that was authenticated; and pruning removes what it should and keeps what it should.

- [ ] **Step 5: Run everything and commit**

```bash
git add Service/Audit.php Http/GraphQLHandler.php Api/Container.php \
        test/unit/Service/AuditRedactionTest.php test/integration/AuditTest.php
git commit -m "Record what the API did, with every secret redacted by type"
```

---

## Checkpoint D: `code-review medium` over Wave 4

This is the security checkpoint. Review it as an attacker, not as a reader.

**Focus:**

- `tokenIssue`: can it be used as an oracle? Compare the work done, the errors returned and the buckets charged for (a) an unknown username, (b) a known username with a wrong password, (c) a correct pair for an account without API access. They must be indistinguishable to a client except in the last case's message.
- The `login` row and the session after `Core::authenticate()`, on both the success and the failure path, and when the call throws.
- `RateLimiter`: the window arithmetic at a boundary; what a negative or zero limit means; whether the database fallback can *lose* a count in a way that lets a burst through; whether `Retry-After` can be negative or larger than the window.
- Whether the rate limiter can be bypassed: a request with no token, a request with a revoked token, a batched document, an operation named to look like a query.
- `Audit::redact()`: a `Secret` nested two levels down, a `Secret` in a list, a document that fails to parse, and a variable whose declared type is not in the schema. Every one of them must end in `***`, not in the row.
- That no audit row, log line or error anywhere in Waves 1–4 can contain a password. Grep the diff for the variables that hold one and follow each to its end.

- [ ] **Step 1: The suite, twice** — both green.
- [ ] **Step 2: Review** — `medium phase4-wave-4..HEAD`, with the Focus list.
- [ ] **Step 3: Resolve the findings** — `Fix checkpoint D:` commits.
- [ ] **Step 4: Open the next wave** — `git tag -a phase4-wave-5 -m "Checkpoint D: …"`

---
## Wave 5: cost, the explorer, the documents and the release

Tasks 14–16. Ends at Checkpoint E, and with it the plugin.

---

## Task 14: What a list costs — `sonnet`

Spec §10.2: "List fields declare a complexity proportional to their `limit`." The rules already run (phase 1); what is missing is the declaration, so today a page of 200 customers costs the same as a page of one.

**Files:**
- Modify: `Schema/SchemaFactory.php`, `Api/Container.php`
- Create: `test/unit/Schema/ComplexityTest.php`
- Modify: `test/integration/QueryCountTest.php`

**Interfaces:**
- Produces:
  ```php
  SchemaFactory::withComplexity(array $fields): void   // 'Type.field' => fn(int $childComplexity, array $args): int
  ```

- [ ] **Step 1: Write the failing test**

Create `test/unit/Schema/ComplexityTest.php`:

```php
    public function testAPagedListCostsItsLimit(): void
        // Reseller.customers(page: {limit: 50}) costs 50 x the child cost.
    public function testAListWithNoPageArgumentCostsTheDefault(): void
        // PageInput.limit defaults to 50; the cost must use the same number.
    public function testALimitAboveTheCapCostsTheCap(): void
        // max_page_size is 200; asking for 5000 may not cost 5000 either.
    public function testAPlainListCostsItsNaturalCeiling(): void
        // Customer.subdomains is bounded by i-MSCP's own limit, not by a page.
    public function testADocumentThatWouldBreachTheLimitIsRejected(): void
        // Three nested 200-item pages against max_query_complexity 1000.
```

- [ ] **Step 2: Declare the complexities**

In `Schema/SchemaFactory.php`, after the schema is built, attach a `complexity` callable to each connection field:

```php
        /**
         * graphql-php's QueryComplexity multiplies a field's declared
         * complexity by its children's. A connection's cost is therefore the
         * page it will actually fetch - capped, because a client asking for
         * 5000 gets 200 rows (spec section 7.9) and must be charged for 200,
         * not for 5000 and not for 50.
         */
        $page = function (int $cap): callable {
            return static function (int $childComplexity, array $args) use ($cap): int {
                $limit = (int)($args['page']['limit'] ?? 50);

                return max(1, min($limit, $cap)) * $childComplexity;
            };
        };
```

applied to `Reseller.customers`, `Query.customers`, `Query.resellers`, `Domain.mailAccounts`, `Customer.ftpUsers` — every `*Connection` field in the SDL — and a flat multiplier for the plain lists whose ceiling is i-MSCP's own limit.

- [ ] **Step 3: Run and commit**

```bash
git add Schema/SchemaFactory.php Api/Container.php test/unit/Schema/ComplexityTest.php test/integration/QueryCountTest.php
git commit -m "Charge a list for the page it will fetch"
```

---

## Task 15: The explorer — `sonnet`

Spec §16 and decision D28: a panel page, not a bare route, with its assets vendored.

**Files:**
- Create: `frontend/client/api_explorer.php`, `frontend/reseller/api_explorer.php`, `frontend/admin/api_explorer.php`
- Create: `frontend/admin/api_audit.php`
- Create: `themes/default/assets/graphiql/` (the vendored assets)
- Modify: `SGW_GraphQL.php` (navigation), `docs/DEVELOPMENT.md`, `test/lint/all.sh`

- [ ] **Step 1: Vendor the assets**

Download GraphiQL and its peers once, commit them under `themes/default/assets/graphiql/`, and record in `docs/DEVELOPMENT.md` exactly which versions and from where. Nothing is fetched at runtime — that is the point of D28.

- [ ] **Step 2: Add the lint check**

In `test/lint/all.sh`, add a step that fails when anything under `themes/` references an external origin:

```bash
if grep -rInE 'https?://(?!localhost)' themes/ --include='*.php' --include='*.tpl' --include='*.html' \
       | grep -v 'w3.org' ; then
    echo "  themes/ must not reference an external origin (decision D28)"
    failures=$((failures + 1))
fi
```

- [ ] **Step 3: Write the pages**

Each page checks the role with `check_login()`, emits the CSRF token of spec §5.2 into the GraphiQL fetcher's headers, and points at `endpoint` from the plugin's config. The explorer is shown only when `introspection` is true, and says so plainly when it is not.

`frontend/admin/api_audit.php` is the paged `api_audit` view of spec §16, with the effective configuration above it.

- [ ] **Step 4: Add them to the navigation**

Follow `SGW_ApacheCache::setupNavigation()`, as the existing token pages already do: the client and reseller explorers under *Domains*, the admin pages under *System tools*.

- [ ] **Step 5: Check them on the box, then commit**

Open each page in the docker panel as each role, run `{ viewer { username role } }` from the explorer, and confirm the audit view lists the request you just made.

```bash
git add frontend/ themes/ SGW_GraphQL.php docs/DEVELOPMENT.md test/lint/all.sh
git commit -m "An explorer that fetches nothing from anywhere"
```

---

## Task 16: Close the plugin out — `sonnet`

Phase 7. Documents, packaging, and one end-to-end run that includes a customer this time.

**Files:**
- Modify: `docs/API.md`, `CHANGELOG.md`, `README.md`, `info.php`, `schema/schema.graphql` (the version), `test/api/provision.php`
- Create: `tools/package.sh`

- [ ] **Step 1: Finish the client guide**

`docs/API.md` gains: the 40 mutations of the finished schema, grouped as the SDL groups them, one line each; the `tokenIssue` flow end to end, with a `curl` that works; the rate-limit table of spec §10.3 and what a `429` looks like; the error-code table including `RATE_LIMITED` and `UNAUTHENTICATED`; and a paragraph on what the audit records, so an operator knows what is kept and for how long.

- [ ] **Step 2: Extend the provisioning script**

`test/api/provision.php` gains a customer lifecycle at the front: `customerCreate` with explicit allowances, settle, assert the account and its web directory exist, then `customerDelete`, settle, assert they are gone. Its sweep gains the customer it creates (`sgwe2e*` already, but an `admin` row and a `domain` row are new shapes to sweep, and the customer's username must be swept by the same pattern).

Run it on the box, twice, and record both counts in the report.

- [ ] **Step 3: Write the packaging script**

`tools/package.sh` builds `SGW_GraphQL.tgz` from a clean checkout, honouring `upload-exclude.txt`, and refuses to run with a dirty working tree or a failing `tools/test.sh`.

- [ ] **Step 4: Bump the versions**

`info.php`'s plugin version, the schema's `apiVersion` (now `2.0.0` — the mutation surface doubled and `tokenIssue` is new), and `CHANGELOG.md` with a section per phase-4 wave written for someone who has not read this plan.

- [ ] **Step 5: The last run**

```bash
tools/test.sh                                   # twice, both green
../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php'
tools/package.sh
```

- [ ] **Step 6: Commit**

```bash
git add docs/API.md CHANGELOG.md README.md info.php schema/schema.graphql \
        schema/schema.printed.graphql test/api/provision.php tools/package.sh
git commit -m "Close phase 4: schema 2.0.0, and a plugin that packages"
```

---

## Checkpoint E: `code-review medium` over Wave 5

**Focus:**

- The complexity declarations: every `*Connection` field has one; the cap is applied; a document that should be refused is refused, and one that should pass is not accidentally caught.
- The explorer: nothing under `themes/` reaches the network; the CSRF header is the one §5.2 requires; a role cannot open another role's page.
- `docs/API.md` against the schema as it now is: every mutation named, every error code described, the `curl` actually works.
- `test/api/provision.php`: the customer lifecycle cleans up after itself, and a run that dies part way leaves nothing the next run cannot sweep.
- `CHANGELOG.md` and `README.md`: nothing promised that is not built. In particular, nothing that says the API does something a reseller-level test does not cover.

- [ ] **Step 1: The suite, twice** — both green, plus one clean `provision.php` run.
- [ ] **Step 2: Review** — `medium phase4-wave-5..HEAD`, with the Focus list.
- [ ] **Step 3: Resolve the findings** — `Fix checkpoint E:` commits.
- [ ] **Step 4: Close the phase** — `git tag -a phase4-done -m "Checkpoint E: <declined findings and why, or: none declined>"`

---

## Self-review

Written after the sixteen tasks, reading the specification again against them.

### 1. Spec coverage

| Spec | Requirement | Where |
| --- | --- | --- |
| §5.3 | `tokenIssue` through `AuthService::authenticate()`, no `login` row, no session | Task 12, `Core::authenticate()` and `testNoLoginRowAndNoSessionSurvive` |
| §5.3 | Disabled by `allow_password_grant` | Task 12 |
| §6.2 | API access withdrawn is refused before a token is minted | Task 12; the switch itself is Tasks 6 and 10 |
| §7.8 | `HostingPlan`, `Reseller` — the write side | Tasks 7 and 10 |
| §7.11 | `customerCreate/Update/Delete/SetState/SetApiAccess` | Tasks 3, 4, 5, 6, 9 |
| §7.11 | `hostingPlanCreate/Update/Delete` | Tasks 7 and 9 |
| §7.11 | `domainAliasApprove/Reject` | Tasks 8 and 9 |
| §7.11 | `resellerCreate/Update/Delete/SetApiAccess` | Task 10 |
| §7.11 | `tokenIssue`, `tokenRevoke` | Task 12 |
| §7.11 | `CustomerCreateInput` taken in one call | Task 3, decision D23 |
| §8.1 | The six refusals, in order, in every new service | Tasks 3–8, 10; each checkpoint's focus list |
| §10.2 | Lists declare a complexity proportional to their limit | Task 14 |
| §10.3 | Three buckets, APCu with a database fallback, `429` and `Retry-After` | Tasks 11 and 12 |
| §11 | `graphql_audit` written per request, redaction by schema type, retention | Task 13 |
| §11 | Every mutation calls `write_log()` with the page's own wording | Tasks 3–8, 10 |
| §15 | No new i-MSCP table altered; `api_rate` is the plugin's own and is dropped on uninstall | Task 11 |
| §16 | The explorer as a page, assets vendored; the admin audit view | Task 15, decision D28 |
| §17 | A property test for `hosting_plans.props` | Task 2 |
| §19 | Phases 4, 5, 6, 7 | Waves 2–3, 3, 4, 5 |

**Gaps, stated rather than hidden:**

- **§16's `/reseller/graphql.php` customer grid** — the page that lists a reseller's customers with an access switch and a "revoke all tokens" button — already exists as `frontend/reseller/api_access.php` from phase 1. Task 6 gives it a mutation but does not rebuild the page. If the page turns out not to cover the "revoke all" button, that is a Task 15 addition, and the implementer should say so rather than quietly adding it.
- **`CustomerCreateInput.reference`** is in spec §7.11 and has no column in i-MSCP's schema. Task 3 accepts it and ignores it; Task 9's SDL description must say so, or the field should be dropped from the input. The implementer decides and records which, in the ledger.
- **Reseller-level provisioning.** `Reseller implements Provisioned` in §7.8, but M16 shows a reseller has no status column and no daemon work. Phase 2 already resolves `provisioning` for a reseller; Task 10 must not contradict it.

### 2. Placeholder scan

`Task 8`'s `reject()` and `Task 10`'s service bodies are given as prose plus the exact statements rather than as complete method bodies. That is deliberate — both are near-copies of a method written in full a few pages earlier, and the plan says which — but an implementer who cannot see the parallel should ask rather than guess. Everything else carries its code.

`Task 15`'s asset vendoring names no version, because the current GraphiQL release at implementation time is the right one and pinning a version here would age badly. The implementer records what they vendored.

### 3. Type consistency

- `Allowances::fromInput()` takes the *contents* of `CustomerAllowancesInput`, not the whole mutation input; Tasks 3, 4 and 7 all call it that way.
- `LimitRules::reason()` returns `?string` and never throws for a legal service; `Guard::limitExceeded()` is what turns it into an error, and Task 3 adds that method.
- `CustomerService::update()` reads `Repository\Counts::forCustomer()`, which Task 4 adds; every other counting call in the plan uses the batched methods phase 2 wrote.
- `ObjectRef` is what every service returns; `TypeResolver::nodeFor()` is what every resolver turns it into, exactly as phase 3's resolvers do.
- `NodeType::CUSTOMER`, `RESELLER`, `HOSTING_PLAN` and `IP_ADDRESS` all already exist; no new tag is introduced by this plan.
