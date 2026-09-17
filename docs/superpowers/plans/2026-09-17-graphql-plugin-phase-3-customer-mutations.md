# SGW_GraphQL — Phase 3 Implementation Plan: customer mutations

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` to implement this plan task-by-task, **in waves**: no per-task review; a `code-review` at `medium` at each of the five checkpoints instead. Inline execution is **not** permitted for this plan — see [Execution mandate](#execution-mandate). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A customer, the customer's reseller, and an administrator can create, change and delete everything a customer owns over `POST /api/graphql`: subdomains, domain aliases, the main domain's forwarding, mail accounts, catch-alls and autoresponders, FTP users, SQL databases and users, and custom DNS records. Every write follows spec §8.1 in order, dispatches the panel's own events, and is covered by the authorisation matrix of spec §17, which is written before the first mutation exists.

**Architecture:** One `Service\` class per entity carries the business rules, transcribed from the panel's page scripts with a `CORE-DEBT` marker at each rule. Every panel facility a service needs — events, the daemon, the log, the validators, the password hash, `PhpEditor` — goes through one port, `Service\Core`, so the only global functions the API path calls are listed in one test. `Security\Guard` asks spec §8.1's steps 2–7 in their order; `Service\Writer` owns step 8's transaction, which nests inside the panel's own transaction counter. Resolvers are thin: they reset the batch loader, call the service, and hand back the object through the read model plan 2 built.

**Tech Stack:** PHP 7.4, `webonyx/graphql-php ^15`, Slim 3 (supplied by the panel), PHPUnit ^9.6, MariaDB 10.11, i-MSCP plugin API 1.5.1. `saygoweb/anorm` is removed by Task 16.

**Spec:** [`docs/SPECIFICATION.md`](../../SPECIFICATION.md) — read §2.1–§2.4, §3.2, §6.3–§6.5, §7.11, §8 in full, §9, §11, §12, §14, §17, §19 and §21 before starting. This plan argues from that document and cites it throughout.

**Predecessors:** [`2026-09-04-graphql-plugin-phase-0-1.md`](2026-09-04-graphql-plugin-phase-0-1.md) and [`2026-09-05-graphql-plugin-phase-2-read-model.md`](2026-09-05-graphql-plugin-phase-2-read-model.md), both merged to `main`. The [Interfaces inherited](#interfaces-inherited-from-plans-1-and-2) section lists every signature this plan uses from them, **verified against the working tree on 2026-09-17**, not copied from those plans — both plans' own interface sections went stale during review (plan 2's F11).

---

## Scope

Specification §19 phase 3: *"Customer mutations. Subdomains, aliases, mail, FTP, SQL, DNS. The authorisation matrix is written first and stays green."*

This plan covers exactly that, plus two things the earlier plans handed it:

- **The authorisation matrix of spec §17** (`test/authz/`), which plan 2's self-review deferred here because it is a matrix over mutations. Task 4 writes it before any mutation exists; every later task turns rows of it on.
- **Plan 2's Anorm question.** Plan 2 ended: *"If phase 3's mutations do not use the models either, the right move is to drop the dependency then."* They do not — decision [D17](#d17--anorm-is-removed) says why, from measurements — so Task 16 removes it.

The 24 mutations, all from spec §7.11:

| Group | Mutations |
| --- | --- |
| The caller's main domain | `domainUpdate` |
| Subdomains | `subdomainCreate`, `subdomainUpdate`, `subdomainDelete` |
| Domain aliases | `domainAliasCreate`, `domainAliasUpdate`, `domainAliasDelete` |
| Mail | `mailAccountCreate`, `mailAccountUpdate`, `mailAccountDelete`, `mailAutoresponderSet`, `mailCatchallCreate`, `mailCatchallDelete` |
| FTP | `ftpUserCreate`, `ftpUserUpdate`, `ftpUserDelete` |
| SQL | `sqlDatabaseCreate`, `sqlDatabaseDelete`, `sqlUserCreate`, `sqlUserSetPassword`, `sqlUserDelete` |
| DNS | `dnsRecordCreate`, `dnsRecordUpdate`, `dnsRecordDelete` |

Follow-on plan, not covered here:

| Plan | Spec phases | Deliverable |
| --- | --- | --- |
| 4 | 4–7 | Reseller and administrator mutations (`customer*`, `hostingPlan*`, `domainAliasApprove`/`Reject`, `reseller*`), **`tokenIssue` and `tokenRevoke`** ([D16](#d16--tokenissue-and-tokenrevoke-move-to-plan-4)), rate limits, audit, complexity weights, the explorer, packaging and release |

Deliberately deferred, and to where, is recorded in the [Self-review](#self-review).

---

## Global Constraints

Copied from the specification and from plans 1 and 2, and updated where this plan changes them. **Every task's requirements implicitly include this section.**

- **PHP 7.4.x.** Typed properties, arrow functions and `??=` are available. Constructor promotion, `match`, enums, named arguments, nullsafe calls and union types are **not**. (Spec §2.5)
- **All plugin source must also lint under PHP 8.3.** `test/lint/all.sh` runs `php7.4 -l` and `php8.3 -l` over every file and must stay green. (Spec §2.5)
- **Namespace `iMSCP\Plugin\SGW_GraphQL`**, PSR-4 with the **plugin root as the namespace root**. There is no `src/` directory: `iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService` lives at `Service/SubdomainService.php`.
- **Tests run on the i-MSCP development server** through `tools/test.sh`, which re-enters itself there. The server is the docker container from `../imscp/docker/imscp`, which bind mounts this checkout, so tests run against the working tree. Arguments pass through to PHPUnit (`tools/test.sh --filter SubdomainService`). The container runs **PHP 7.4.33 and MariaDB 10.11.18** (measured). Never claim a test passes without having run it there.
- **Composer runs inside the container:** `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 /var/www/imscp/gui/bin/composer.phar <command>'`. `vendor/` is not committed.
- **Runtime dependencies:** `webonyx/graphql-php ^15`, and `saygoweb/anorm ^3.1` **until Task 16 removes it**. No new dependency.
- **Licence header on every PHP file**, GPL-2.0-or-later, copyright `2026 Cambell Prince <cambell.prince@gmail.com>`, copied from an existing file. `test/lint/all.sh` checks the address line.
- **British spelling** in comments and user-facing strings (`authorise`, `behaviour`, `normalise`, `licence` as a noun).
- **Unreachable objects return `NOT_FOUND`, never `FORBIDDEN`.** A malformed identifier, an identifier of the wrong kind, and an identifier of somebody else's object are the same `NOT_FOUND`. (Spec §6.3)
- **Spec §8.1's order is the order of every write:** ownership (`NOT_FOUND`) → scope (`FORBIDDEN`) → feature (`FEATURE_UNAVAILABLE`) → settled (`CONFLICT`) → validation (`BAD_USER_INPUT`, with `extensions.field`) → quota (`LIMIT_EXCEEDED`, with `extensions.limit` and `extensions.used`) → transaction → daemon → log. `Security\Guard` is the only place the first six are raised.
- **`send_request()` only after the commit.** Never inside the transaction. (Spec §8.1 step 9)
- **No global panel function is called from the API path except through `Service\PanelCore`**, and only those in `CoreCallsTest::ALLOWED`. See [D10](#d10--a-core-function-is-called-only-if-it-cannot-exit-and-takes-every-identity-explicitly).
- **Tables are named `api_token`, `api_perm`, `api_audit`.** (Plan 1's amendment to spec §15.)
- **Every `CORE-DEBT(Cn)` marker names an item in spec §21**, or `test/lint/all.sh` fails. This plan adds **C11**, *defects in the panel that the API does not reproduce*, in Task 6.
- **Commit messages:** short imperative subject, blank line, prose explaining *why* — not a bullet list of what changed. End each with the trailer

  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  ```

  and any further trailer the executing session's own harness instructions require. Every `git commit -m` in this plan shows subject and body only.

---

## Interfaces inherited from plans 1 and 2

Read out of the working tree at `0b5ebfb` on 2026-09-17. Use these exactly; where this plan changes one, the task that changes it says so in its **Interfaces** block.

```php
// Support\GlobalId
GlobalId::encode(string $type, int $id): string
GlobalId::encodeKey(string $type, string $key): string
GlobalId::decodeKey(string $encoded, ?string $expectedType = null): GlobalId   // throws InvalidArgumentException
GlobalId::getType(): string     GlobalId::getKey(): string
GlobalId::getId(): int          // throws InvalidArgumentException for a non-numeric key

// Support\NodeType — tags
CUSTOMER DOMAIN SUBDOMAIN ALIAS_SUBDOMAIN DOMAIN_ALIAS MAIL_ACCOUNT FTP_USER
SQL_DATABASE SQL_USER DNS_RECORD RESELLER HOSTING_PLAN IP_ADDRESS
NodeType::isKnown(string $tag): bool      NodeType::isStringKeyed(string $tag): bool

// Support\Provisioning
Provisioning::fromStatus(?string $status): Provisioning
->getState(): string   // STATE_OK | STATE_PENDING | STATE_DISABLED | STATE_ORDERED | STATE_ERROR
->isSettled(): bool    // false only for STATE_PENDING
Provisioning::PENDING_STATUSES

// Support\ErrorCode, Support\ApiException
new ApiException(string $code, string $message, array $extensions = array(), ?Throwable $previous = null)
ApiException::getErrorCode(): string     ApiException::getExtensions(): array

// Support\Quota
Quota::fromCustomerLimit(int $limit, int $used): Quota   // -1 withheld, 0 unlimited, n limit
->isEnabled(): bool  ->getLimit(): ?int  ->getUsed(): int  ->getRemaining(): ?int

// Support\MailType
MailType::KIND_MAILBOX | KIND_FORWARD | KIND_MAILBOX_AND_FORWARD | KIND_CATCHALL
MailType::HOST_DMN | HOST_SUB | HOST_ALS | HOST_ALSSUB           // 'dmn' 'sub' 'als' 'alssub'
MailType::toMailType(string $hostType, string $kind): string   // e.g. ('sub', 'FORWARD') => 'subdom_forward'
MailType::kindOf(string $mailType): string    MailType::hostTypeOf(string $mailType): string

// Support\CustomerFeatures
CustomerFeatures::CONFIG_KEYS   // NAMED_PACKAGE WEB_STATISTIC_PACKAGES BACKUP_DOMAINS ENABLE_SSL IMSCP_SUPPORT_SYSTEM
CustomerFeatures::fromDomainRow(array $domain, array $config, bool $resellerSupportSystem): CustomerFeatures
->has(string $name): bool      // php phpEditor cgi customDns externalMail backup ssl webStats supportSystem

// Auth\Identity, Auth\Scope
Identity::getAdminId(): int  getUsername(): string  getRole(): string  getCreatedBy(): ?int  hasScope(string): bool
Identity::ROLE_ADMIN | ROLE_RESELLER | ROLE_CUSTOMER
Scope::DOMAINS_WRITE  MAIL_WRITE  FTP_WRITE  SQL_WRITE  DNS_WRITE  (and the READ scopes)

// Security\OwnershipResolver
new OwnershipResolver(callable $query)   // fn(string $sql, array $bind): array of rows
->assertReachable(Identity $caller, GlobalId $id): int   // owner admin_id; throws ApiException NOT_FOUND

// Repository\Db
new Db(?PDO $pdo)    Db::fromPanel(): Db    Db::detached(): Db
->pdo(): PDO   ->rows(string $sql, array $bind = array()): array   ->row(...): ?array
->value(...)   ->placeholders(int $n): string   ->countQueries(callable $fn): int

// Repository\BatchLoader
->keyed(string $bucket, $key, callable $loader): Deferred   ->reset(): void   ->flushes(): int

// Repository\VirtualHosts
VirtualHosts::KIND_DMN | KIND_SUB | KIND_ALS | KIND_ALSSUB
VirtualHosts::kindFor(string $tag): string    VirtualHosts::tagFor(string $kind): string
->byKeys(array $keys): array   // array(array(kind, id), ...) => "kind:id" => normalised row:
// array('tag','kind','key'(int),'domainId','ownerId','name'(ascii),'label','parentTag','parentKey',
//       'mountPoint','documentRoot','urlForward','typeForward','hostForward','wildcard'(bool),'status','ipId')

// Repository\Counts
new Counts(Db $db, bool $countDefaultMailAccounts)
->subdomains(array $domainIds): array   ->domainAliases(...)   ->mailAccounts(...)
->sqlDatabases(...)   ->sqlUsers(...)   ->ftpUsers(array $adminIds): array   // id => int

// Resolver\TypeResolver
TypeResolver::identity($context): Identity
TypeResolver::node(string $tag, $key, array $fields): array

// Read-model references the mutations return through
VirtualHostResolver::reference(string $tag, $key): SyncPromise
VirtualHostResolver::shape(array $normalisedRow, callable $toUnicode): array
MailResolver::reference(int $mailId): SyncPromise
FtpSqlResolver::ftpReference(string $userid): SyncPromise
FtpSqlResolver::databaseReference(int $sqldId): SyncPromise
FtpSqlResolver::sqlUserReference(int $sqluId): SyncPromise
FtpSqlResolver::shapeDatabase(array $row): array   // sqld_id, domain_id, sqld_name, domain_admin_id
FtpSqlResolver::shapeSqlUser(array $row): array    // sqlu_id, sqld_id, sqlu_name, sqlu_host
DnsResolver::reference(int $dnsId): SyncPromise

// Api\Container
Container::forTesting(string $pluginDir, array $config, callable $query, callable $accountLoader,
                      callable $apiAccessChecker, ?Db $db = null, array $panelConfig = array()): Container
->resolverMaps(): array    ->schemaFactory(): SchemaFactory    ->apiVersion(): string

// test/integration — namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration
abstract class IntegrationTestCase extends TestCase          // skips off the box; requires imscp-lib.php
new Fixture(Db $db)  ->seed()  ->rollBack()
->customerId() siblingId() otherCustomerId() resellerId() otherResellerId() adminId()
->domainId() siblingDomainId() otherDomainId() subdomainId() aliasId() aliasSubdomainId() mailboxId() forwardId()
->ftpUserId(): string  sqlDatabaseId() sqlUserId() dnsRecordId() ipId()
->domainName() aliasName() subdomainName()
->identity(string $who, array $scopes = array()): Identity
// $who: customer | sibling | otherCustomer | reseller | otherReseller | admin
```

**What the fixture seeds, in the terms this plan's tests rely on.** The customer's domain `sgwtcustomer.test` is `ok` with limits: subdomains 10, aliases 5, mail 0 (unlimited), **FTP −1 (withheld)**, SQL databases 2, SQL users 2, `domain_dns = 'yes'`, `mail_quota` 1073741824 bytes, `domain_disk_limit` 5120 MiB. It owns: subdomain `shop` (`ok`), alias `sgwtalias.test` (`ok`, forwarded to `https://example.net/`), alias subdomain `blog` (**`toadd` — the fixture's one unsettled object**), mailbox `sales@sgwtcustomer.test` (`normal_mail`, `ok`, `mail_pass = '_no_'`), forward `hello@blog.sgwtalias.test` (`alssub_forward`, `ok`, autoresponder on), FTP user `sgwtftp@sgwtcustomer.test` (`ok`), SQL database `sgwt_shop` with user `sgwt_u1@localhost`, DNS record `A mail 203.0.113.7` (`ok`, `custom_dns_feature`). Its email is `sgwtcustomer@example.test`.

---

## What was measured before writing this plan

Every claim below was run, not read. The box is the docker container at `../imscp` commit `bf82c0053` (PHP 7.4.33, MariaDB 10.11.18); the panel source is `../imscp/gui` at the same commit.

| # | Claim | Method | Result |
| --- | --- | --- | --- |
| M1 | The panel's transaction helper nests with savepoints | Read `gui/src/Database/DatabaseMySQL.php:438-497` | `beginTransaction()` at depth 0 calls `PDO::beginTransaction()`, deeper issues `SAVEPOINT TRANSACTION{n}`; `commit()`/`rollBack()` mirror it with `RELEASE` / `ROLLBACK TO`. The depth is `protected $transactionCounter`. |
| M2 | Plan 2's fixture opens its transaction on the raw PDO | Read `test/integration/Fixture.php` | `$this->db->pdo()->beginTransaction()`. A core helper that then calls `DatabaseMySQL::beginTransaction()` sees depth 0, calls `PDO::beginTransaction()` again, and PDO refuses: "There is already an active transaction". `createDefaultMailAccounts()` is such a helper. |
| M3 | `deleteSubdomain()` and `deleteSubdomainAlias()` authorise on the session, not on an argument | Read `gui/include/Client.php:432-806` | Both select with `domain_admin_id = $_SESSION['user_id']` and call `showBadRequestErrorPage()` on no row. The identity shim puts the **caller** there, so a reseller deleting a customer's subdomain would hit the exit. `deleteSubdomainAlias()` also calls `redirectTo()` — another exit — on a database error. |
| M4 | `deleteDomainAlias()` swallows its own failure | Read `gui/include/Shared.php:1022-1232` | On any exception it rolls back, logs, calls `set_page_message()` and **returns normally**. A caller cannot tell success from failure. |
| M5 | `delete_sql_database()` and `sql_delete_user()` mix DDL with row writes | Read `gui/include/Shared.php:623-777` | `DROP DATABASE`, `DELETE FROM mysql.user`, `FLUSH PRIVILEGES` interleaved with `DELETE FROM sql_database`. Each DDL statement implicitly commits, so neither can run inside a test's rolled-back transaction. |
| M6 | `get_domain_default_props()` caches for the whole request, unkeyed | Read `gui/include/Shared.php:283-315` | `static $domainProperties`; a second customer in the same request reads the first customer's row. |
| M7 | A forward-only mail account cannot be created in the panel on this box | Ran the panel's `INSERT` (`mail_add.php:266-279`, quota `NULL`) under the panel's own `sql_mode = 'NO_AUTO_CREATE_USER'` | `ERROR 1048 (23000): Column 'quota' cannot be null`. `mail_add.php:289` reads SQLSTATE 23000 as "Mail account already exists." |
| M8 | SQLSTATE 23000 is not "duplicate" | Same as M7 | 1048 (NOT NULL) and 1062 (duplicate key) share SQLSTATE 23000. Only `errorInfo[1] === 1062` is a uniqueness clash. |
| M9 | Name uniqueness in the database | `SHOW CREATE TABLE` for 13 tables | Unique: `mail_users.mail_addr`, `ftp_users.userid`, `sql_database.sqld_name`, `ftp_group.groupname`, `php_ini (admin_id, domain_id, domain_type)`, `domain_dns (domain_id, alias_id, domain_dns, domain_class, domain_type, domain_text)`. **Not unique:** `subdomain.subdomain_name`, `subdomain_alias.subdomain_alias_name`, `domain_aliasses.alias_name`, `sql_user`. Spec §8.4's "retried create fails with CONFLICT" needs an explicit check for those four. |
| M10 | `mail_users.quota` is `bigint unsigned NOT NULL DEFAULT 0` | Same | Forward-only accounts must write `0`, not `NULL`. |
| M11 | `subdomain_edit.php` cannot edit an alias subdomain | Read `gui/public/client/subdomain_edit.php:380-388` | Its `UPDATE subdomain_alias` sets `subdomain_wildcard_alias`, a column that table does not have (M9: it is `subdomain_alias_wildcard_alias`). It also accepts the wildcard flag as `[0, 1]` (line 354) where the column is `enum('yes','no')`. |
| M12 | `sql_user_add.php` checks the SQL user limit after it has written | Read `gui/public/client/sql_user_add.php:44-67,375-385` | `checkSqlUserPermissions()` runs from `generatePage()`, which is reached only after `addSqlUser()` has already redirected. |
| M13 | `mail_delete.php` never cleans the addresses it means to | Read `gui/public/client/mail_delete.php:88-121` | The `while ($row = …)` loop overwrites the `$row` whose `mail_addr` it then removes; the selected columns have no `mail_addr`, so the pattern is `/^$/` and matches nothing. Its log call also has its arguments swapped (line 163). |
| M14 | `dns_edit.php`'s name check strips the first character of every name | Read `gui/public/client/dns_edit.php:157-163` | `strpos($name, '_') == 0` is true when `strpos` returns `false`. `dns_delete.php:39` dispatches `onBeforeDeleteCustomDNSrecord` with `$dnsRecordId` before assigning it. |
| M15 | `Events::onAfterDeleteSqlDb` is `'onAfterSqlDb'` | Read `gui/src/Event/Events.php:559` | Listeners register with the constant, so dispatching the literal `'onAfterDeleteSqlDb'` would reach none of them. Services use the `Events::` constants. |
| M16 | Deletes match names with unescaped `LIKE` and regexes | Read `Client.php:489,583`, `Shared.php:1062,1101` | `LIKE CONCAT('%@', ?)` with a domain name, and i-MSCP allows `_` in names (commit `e91444241`). `_` is a single-character wildcard, so deleting `a_b.test` schedules the FTP users of `axb.test` — another customer's — for deletion. |
| M17 | Panel configuration on the box | `Registry::get('config')` | `CREATE_DEFAULT_EMAIL_ADDRESSES` 1, `PROTECT_DEFAULT_EMAIL_ADDRESSES` 1, `COUNT_DEFAULT_EMAIL_ADDRESSES` 0, `PASSWD_CHARS` 6, `PASSWD_STRONG` 1, `MYSQL_PREFIX` `none`, `DATABASE_USER_HOST` `localhost`, `SERVER_HOSTNAME` `imscp.docker.local`, `USER_WEB_DIR` `/var/www/virtual`. |
| M18 | Forward URL normalisation, through the panel's own classes | `UriRedirect::fromString()` + the pages' normalisation | `HTTP://Example.COM/a/../b` → `http://example.com/b/`; `https://example.net` → `https://example.net/`; `http://example.net:8080/x?y=1#f` → `http://example.net:8080/x/?y=1#f` with `getPort()` `'8080'`; `getPort()` is `false` when absent; `javascript:alert(1)` and `http://bad host/` throw `UriException`. |
| M19 | The validators | Called in the box | `isValidDomainName('bad..name')` false with "Usage of dot in domain name labels is prohibited."; `'single'` false; `checkPasswordSyntax('abc', …, true)` false, `'Str0ngPassw0rd'` true; `chk_email('sales', true)` true; `validates_username('-bad')` false; `Crypt::sha512()` starts `$6$`; `utils_normalizePath('/htdocs/../x')` = `/x`. |
| M20 | TXT record formatting | Ran `client_validateAndFormat_TXT()` copied out of `dns_edit.php` over 13 inputs | The vectors in Task 14 are its outputs, byte for byte. |
| M21 | `DELETE FROM mysql.user` still works on MariaDB 10.11, where `mysql.user` is a view | `CREATE USER`, then the panel's `DELETE`, then `SELECT … FROM mysql.global_priv` | The row is gone. The core's statements are transcribed as they are. |
| M22 | What the docker box can provision | `systemctl is-active`, `SELECT … FROM admin JOIN domain` | `imscp_daemon` and `apache2` are **inactive**; `proftpd`, `dovecot`, `postfix` active. One customer, `cust1.test` (admin_id 3, reseller `res1`), every limit 0 (unlimited) and `domain_dns = 'no'`. Task 17 runs the request manager directly for that reason. |
| M23 | Anorm's write path | Read `vendor/saygoweb/anorm/src/DataMapper.php:147-247` | `write()` issues `UPDATE … SET` over **every** mapped column, with the key interpolated as `WHERE key='id'`, and `INSERT … SET` writes `NULL` for every unassigned property. See D17. |

---

## Deviations and decisions this plan records

Plan 2 recorded D1–D9. These continue the numbering.

### D10 — A core function is called only if it cannot exit and takes every identity explicitly

Spec §3.2's table says `deleteSubdomain()`, `deleteSubdomainAlias()`, `deleteDomainAlias()`, `delete_sql_database()` and `sql_delete_user()` are "called behind the identity shim". M3–M6 show that cannot work: the shim holds the *caller*, and those functions read the *customer* from it, or swallow their own failures, or issue DDL no test can roll back.

**Decision:** the API path calls a global panel function only when all four hold: (1) every identity it acts on is an argument; (2) it reports failure by return value or exception; (3) no path through it reaches `exit` (`showErrorPage()` and its wrappers, `redirectTo()`); (4) it issues no DDL. Everything else is transcribed, with a `CORE-DEBT` marker. The allowed functions are called in exactly one class, `Service\PanelCore`, and `CoreCallsTest` enforces the list by tokenising the API source. Task 2 amends spec §3.2's table.

What this buys beyond correctness: §6.5's layer 1 — "no core helper is reached in a state that would let it exit" — becomes a static property of the code rather than a comment at each call site. The identity shim stays in place, because nothing is gained by removing it in this phase, but no code this plan writes depends on it.

### D11 — Transactions go through the panel's counter

M1 and M2. A service's `beginTransaction()` must be the panel's, so that a core helper inside it nests as a savepoint, and so that a test fixture's outer transaction turns every service commit into a savepoint release that the fixture's rollback undoes. Task 1 routes `Db`'s transactions through `DatabaseMySQL` and moves the fixture onto them.

### D12 — Three side effects sit behind ports

`Service\Core` (events, the daemon, the log, the validators), `Service\DirectoryProbe` (the FTP-backed directory check of spec §3.2) and `Service\SqlServer` (`CREATE DATABASE`, `GRANT` and their inverses). Tests replace the daemon, the probe and the SQL server: the daemon would provision rows a rolled-back test never committed; the probe inserts a temporary `ftp_users` row the FTP server must see committed; and every DDL statement implicitly commits (M5), which would silently end a fixture's transaction and leave its rows on the box. `MariaDbSqlServer` is tested on its own, outside any transaction, in Task 12.

### D13 — Who creates an alias decides whether it is ordered

`alias_add.php:370,402` writes `toadd` when the session is a reseller logged in as the customer, `ordered` otherwise. The API has no "logged in as": a reseller or administrator acts *on* a customer (spec open question 4). **Decision:** a `CUSTOMER` caller's alias is `ordered`; a `RESELLER` or `ADMIN` caller's is `toadd`. The returned object's `provisioning.state` says which. This is spec open question 5's current answer, and `domainAliasApprove` (plan 4) completes it.

### D14 — Updates are partial

The panel's edit forms post every field every time. An API update that required every field would make a client read before every write and race whoever wrote in between. **Decision:** an absent input field keeps its current value; `forwarding: null` removes forwarding; the service builds the full row from the current row plus the input and then applies the panel's rules to the full row. **One exception:** `dnsRecordUpdate` takes `name`, `ttl` and `data` as required — a DNS record's data is one encoded string, and decoding it back into parts is `client_decodeDnsRecordData()`, a second transcription for no gain.

### D15 — Where the panel is wrong, the API is not

M7, M11–M14 and M16 are defects. **Decision:** the API implements the rule the page evidently intends, marks the site `CORE-DEBT(C11)`, and Task 6 adds **C11 — defects the API does not reproduce** to spec §21 so each is filed against `saygoweb/imscp`. Two where "intended" needed a judgement:

- **Mail delete (M13)** is meant to remove the deleted address from every forward and catch-all that lists it. The page scans *every customer's* accounts. Because of M13 it has never actually done so, so implementing the global scan would introduce a cross-tenant write the panel has never performed. The API removes the address from the **owning customer's** accounts only.
- **Unsettled rows the panel writes over.** The panel's edit and delete pages do not check status (except `dns_delete.php`); spec §8.3 requires the API to. The API refuses, which is stricter than the panel.

### D16 — `tokenIssue` and `tokenRevoke` move to plan 4

Plan 1 deferred `tokenIssue` to plan 3 "with the rate limiting it needs". The rate limiter is spec phase 6, which is plan 4. `tokenIssue` is the one unauthenticated field in the schema (spec §5.3), and shipping it before its bucket exists would ship a credential-stuffing oracle for as long as the gap lasts; building a one-off bucket here and a general limiter there would be the same code twice. `tokenRevoke` goes with it so that credentials arrive as one reviewable unit. Neither is in this plan's SDL.

### D17 — Anorm is removed

Plan 2 kept Anorm for "phase 3's writes". M23 is why writes do not use it: `DataMapper::write()` updates every mapped column, so updating a `domain` row's forwarding would also write back whatever `domain_disk_usage` the service last read — a lost update against the traffic cron, on a table the backend writes concurrently. It interpolates the key into the SQL. And its `INSERT` writes `NULL` for every property a service did not set, which M7 shows is an error on this box, not a default. A service would have to set every column of every table to use it safely, which is hand-written SQL with more steps.

Plan 2's own rule then applies. Task 16 removes the dependency, the thirteen models, `BatchLoader::related()`/`parent()`/`byColumn()`, and the one production call to `byColumn()`, and amends spec §3.3.

### D18 — A mutation resets the batch loader first

`BatchLoader` memoises for the request. A document of two mutations — update a subdomain, then update it again — would otherwise return the second mutation's object from the first one's cache. graphql-php executes a mutation's top-level fields serially, each completing before the next begins (spec §3.4's reason for flat mutations), so resetting at the start of each field cannot strand a sibling's pending load.

### D19 — A write scope admits the object it returns

A token holding `MAIL_WRITE` and not `MAIL_READ` creates a mailbox. **Decision:** the mutation's own return object is not gated by a read scope — its plain fields are what the caller just wrote. Every *edge* on it still asks its own scope, as the read model already does. So `mailAccountCreate { id address }` succeeds with `MAIL_WRITE` alone, and `mailAccountCreate { host { name } }` answers the `host` field `FORBIDDEN` without `DOMAINS_READ`. Mutation resolvers therefore return through the per-type `reference()` functions, not through `QueryResolver::nodeReference()`, which applies the read gate.

### D20 — Units and one config key

- **Mailbox quota** is input in bytes (plan 2's D3: every `BigInt` is bytes) and must be a whole number of MiB, because the panel stores and displays MiB. Anything else is `BAD_USER_INPUT` with `extensions.field = "input.quota"`.
- **`validate_ftp_home_dir`** (spec §14) switches every VFS directory check, not only the FTP home: document roots use the same mechanism, at the same cost. Task 2 adds the key to `config.php` and amends spec §14's comment.

---

## The shape of every mutation

Every service method in Tasks 6–15 is this skeleton, in this order. Reviewers: a method that asks these in any other order is wrong even if its tests pass.

```php
public function update(Identity $caller, string $id, array $input): ObjectRef
{
    // 2, 3. Ownership, then scope. NOT_FOUND before FORBIDDEN.
    $target = $this->kit->guard()->target($caller, $id, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id');
    $account = $this->kit->accounts()->customer($target->getOwnerId());

    // 4. Feature, for the owning customer - never the caller.
    Guard::requireFeature($this->quota($account)->isEnabled(), 'subdomains');

    // 5. Settled. Updates need OK; deletes accept OK or ERROR (spec 8.3).
    $row = $this->kit->vhost($target->getTag(), $target->getKey());
    Guard::requireState($row['status'], array(Provisioning::STATE_OK));

    // 6. Validation: BAD_USER_INPUT with extensions.field.
    // 7. Quota, creates only: LIMIT_EXCEEDED with limit and used.

    // 8. One transaction: before-event, rows with a to... status, after-event.
    $this->kit->writer()->run(function () use (...) { ... });

    // 9. The daemon, after the commit. 10. The panel's log, in the page's words.
    $this->kit->core()->sendRequest();
    $this->kit->core()->writeLog(sprintf('...', $caller->getUsername()), E_USER_NOTICE);

    // 11. What to return; the resolver reads it back through the read model.
    return new ObjectRef($target->getTag(), $target->getKey());
}
```

---

## Model assignment

The principle is plan 1's and plan 2's: **ambiguity and blast radius, not line count.**

| Model | Used for | Tasks |
| --- | --- | --- |
| `haiku` | Pure functions over values with every test vector written out and no i-MSCP surface. | 5, 14 |
| `sonnet` | i-MSCP conventions, SQL against tables it must not get wrong, the SDL, multi-file wiring, an authorisation decision. The default. | 1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 13, 15, 16, 18 |
| `opus` | Success depends on a live system behaving as measured rather than on following this plan. | 12, 17 |

Task 12 is `opus` because what it asserts — that a grant on `sgwt_a_c` does not also open `sgwtxaxc`, that `CREATE USER ?@? IDENTIFIED BY ?` binds on MariaDB 10.11 — is a claim about the server, and a wrong claim looks exactly like a passing test until someone connects as the user. Task 17 is `opus` because it drives the real backend.

Task 3 is `sonnet`, not `opus`, although every write goes through it: its inputs are fully determined by spec §8.1 and its tests enumerate the order.

---

## Execution mandate

Every task runs in a **fresh subagent** via `superpowers:subagent-driven-development`, at the model its heading names. Do not execute tasks inline in the orchestrating session.

Dispatch each subagent with: the task text; the [Global Constraints](#global-constraints); the [Interfaces inherited](#interfaces-inherited-from-plans-1-and-2); [The shape of every mutation](#the-shape-of-every-mutation); the [Interfaces this plan creates](#interfaces-this-plan-creates) section below; and the path of the spec. A subagent sees only its own task.

### Waves and checkpoints

The tasks are grouped into six waves. **There is no review subagent after each task.** Review happens at five checkpoints, each a `code-review` at level `medium` over everything the wave committed, placed where a defect would otherwise be copied into the tasks that follow.

| Wave | Tasks | Delivers | Checkpoint |
| --- | --- | --- | --- |
| 1 — The write path | 1, 2, 3 | Transactions, the core port and its enforcement, Guard, Writer, Toolkit | **A** |
| 2 — The matrix and the rules | 4, 5 | The authorisation matrix (all rows skipped), the pure vhost rules | — rolled into B |
| 3 — Virtual hosts | 6, 7, 8 | The first services, the `Mutation` type, the first 42 matrix rows live | **B** |
| 4 — Mail and FTP | 9, 10, 11 | Nine more mutations | **C** |
| 5 — SQL and DNS | 12, 13, 14, 15 | The last eight mutations; every matrix row live | **D** |
| 6 — Close | 16, 17, 18 | Anorm removed, the end-to-end run, the documents | **E** |

Why these places:

- **A** follows the three tasks every write in the plan goes through. A hole in `CoreCallsTest`'s scanner, in Guard's order or in `Writer`'s rollback is inherited by fifteen tasks; this is the cheapest point to find it.
- **B** follows the first complete vertical slice — service, schema, resolver, matrix rows — and the pattern Waves 4 and 5 copy nine more times. It also covers Wave 2: the matrix is only worth its rows running, which happens in Task 8.
- **C** and **D** each follow a family of services whose rules are independent of the previous family's, so each review reads one domain (mail and FTP; SQL DDL and DNS) rather than everything at once.
- **E** reviews the removal of a dependency, a script that commits on a real box, and documents that must match the code.

**Within a wave**, tasks run strictly in order, one at a time. None runs in parallel, even where two touch disjoint files (4 and 5, 12 and 14): `tools/test.sh` tests the checkout the docker container bind mounts, so a second worktree is invisible to the test server, and two agents in one checkout race on the index.

**Between tasks**, the orchestrator does not review code. It checks three things and moves on:

1. The subagent's report includes the tail of its last `tools/test.sh` run, showing lint `PASS` and both PHPUnit processes green.
2. The task's commit exists (`git log --oneline -1`).
3. Every name in the task's **Produces** block exists: `git grep -n` for each class and public method.

If any of the three fails, send the subagent back to its own task before dispatching the next.

**At each checkpoint**, in the orchestrating session:

1. Run `tools/test.sh` twice. Both runs must be green; the second catches a test that leaves state behind.
2. Invoke the `code-review` skill with arguments `medium phase3-wave-N..HEAD`, where `phase3-wave-N` is the tag set at the start of the wave (or waves) under review. Pass the checkpoint's **Focus** list with it.
3. For each finding, read the code and decide. Fix every finding that holds, in a commit whose subject begins `Fix checkpoint X:` and whose body says what was wrong and why the fix is the right one. Several findings may share a commit when they share a cause.
4. Run `tools/test.sh` again after the fixes.
5. Tag the start of the next wave with an **annotated** tag whose message lists the findings that were declined and why: `git tag -a phase3-wave-M -m "Checkpoint X: declined …"`. A checkpoint with nothing declined says so.

Do not start the next wave with a checkpoint's findings unresolved: a finding in a pattern is cheaper to fix before the next wave copies it than after.

Before Wave 1: `git tag -a phase3-wave-1 -m "Plan 3 committed; wave 1 starts here"`.

---

---

## Interfaces this plan creates

The signatures later tasks rely on, collected so a subagent does not have to find them in an earlier task. The task that creates each is named.

```php
// Task 1 — Repository\Db (additions)
new Db(?PDO $pdo, $transactions = null)        // $transactions: DatabaseMySQL-like, or null
->beginTransaction(): void   ->commit(): void   ->rollBack(): void
->execute(string $sql, array $bind = array()): int     // affected rows
->lastInsertId(): int

// Task 2 — Service\Core (interface), Service\PanelCore
->dispatch(string $event, array $params): void
->sendRequest(): void
->writeLog(string $message, int $level): void
->config(string $key, $default = null)
->toAscii(string $name): string          ->toUnicode(string $name): string
->domainNameError(string $name): ?string // null when valid
->isValidEmail(string $address): bool    ->isValidEmailLocalPart(string $localPart): bool
->isAcceptablePassword(string $password): bool
->isValidUsername(string $username): bool
->isValidSqlHost(string $asciiHost): bool
->normalisePath(string $path): string
->hashPassword(string $password): string
->domainExists(string $name, int $resellerId): bool
->createDefaultMailAccounts(int $mainDomainId, string $customerEmail, string $asciiHostName, string $forwardMailType, int $subId): void
->savePhpIni(int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId, string $vhostType): void
->normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string   // InvalidArgumentException
->sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
->pruneAutoreplyLog(): void
new PanelCore(bool $pokeDaemon = true)

// Task 2 — Service\DirectoryProbe (interface), Service\VfsDirectoryProbe, Service\UncheckedDirectoryProbe
->exists(string $customerUsername, string $root, string $path): bool

// Task 2 — Service\SqlServer (interface; MariaDbSqlServer is Task 12)
->databaseExists(string $name): bool   ->createDatabase(string $name): void   ->dropDatabase(string $name): void
->userExists(string $user, string $host): bool
->createUser(string $user, string $host, string $password): void
->grantDatabase(string $user, string $host, string $database): void
->revokeDatabase(string $user, string $host, string $database): void
->dropUser(string $user, string $host): void
->setPassword(string $user, string $host, string $password): void

// Task 2 — test doubles, namespace iMSCP\Plugin\SGW_GraphQL\Test\Double
new RecordingCore(Core $inner, array $config = array())
->events: array<int, array{0: string, 1: array}>   ->requests: int   ->logs: array<int, array{0: string, 1: int}>
->aliasOrders: array   ->prunes: int   ->eventNames(): string[]
new FakeDirectoryProbe(bool $answer = true)  ->asked: array
new FakeSqlServer()  ->operations: array<int, array>  ->databases: array<string, true>  ->users: array<string, string>

// Task 3 — Support\ObjectRef
new ObjectRef(string $tag, $key, ?array $snapshot = null)
->getTag(): string   ->getKey()   ->getSnapshot(): ?array

// Task 3 — Security\Target
->getId(): GlobalId  ->getTag(): string  ->getKey()  ->getOwnerId(): int

// Task 3 — Security\Guard
new Guard(OwnershipResolver $ownership)
->target(Identity $caller, $encodedId, array $tags, string $scope, string $field): Target
Guard::parse(string $encoded, array $tags): GlobalId               // NOT_FOUND
Guard::requireScope(Identity $caller, string $scope): void         // FORBIDDEN
Guard::requireFeature(bool $available, string $feature): void      // FEATURE_UNAVAILABLE
Guard::requireState(string $status, array $allowedStates): void    // CONFLICT (pending) | FORBIDDEN
Guard::requireQuota(Quota $quota, string $name): void              // LIMIT_EXCEEDED
Guard::badInput(string $field, string $message, array $extra = array()): ApiException
Guard::conflict(string $message): ApiException
Guard::forbidden(string $message, array $extra = array()): ApiException
Guard::notFound(): ApiException
Guard::RETRY_AFTER_SECONDS = 5

// Task 3 — Service\CustomerAccount, Repository\Accounts
new Accounts(Db $db)  ->customer(int $adminId): CustomerAccount    // NOT_FOUND when not a customer
CustomerAccount::getAdminId(): int getUsername(): string getResellerId(): int getEmail(): string
  getSysUid(): int getSysGid(): int getDomainId(): int getDomainName(): string getDomainIpId(): int
  domain(string $column)       // any column of the customer's `domain` row
  getDomainRow(): array

// Task 3 — Service\Writer
new Writer(Db $db)
->run(callable $work)     // one transaction; a 1062 anywhere in the chain becomes CONFLICT
Writer::isDuplicate(Throwable $e): bool

// Task 3 — Service\Toolkit
new Toolkit(Db $db, Core $core, Guard $guard, Accounts $accounts, VirtualHosts $vhosts,
            Counts $counts, Writer $writer, DirectoryProbe $probe)
->db() core() guard() accounts() vhosts() counts() writer() probe()
->vhost(string $tag, $key): array      // fresh normalised row; NOT_FOUND when gone
->panelConfig(array $keys): array      // key => Core::config(key)

// Task 3 — Api\Container::forTesting gains three optional trailing parameters
Container::forTesting(..., ?Db $db = null, array $panelConfig = array(),
                      ?Core $core = null, ?DirectoryProbe $probe = null, ?SqlServer $sqlServer = null)

// Task 4 — test/authz, namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz
MutationCatalogue::all(): array   // field => array('scope', 'document', 'prepare', 'variables')
abstract AuthzTestCase::execute(string $document, array $variables, Identity $identity): array

// Task 5 — Support\VhostRules
VhostRules::PANEL_FORWARD_TYPES    // 'PERMANENT_301' => '301', ..., 'PROXY' => 'proxy'
VhostRules::HTDOCS                 // '/htdocs'
VhostRules::noForwarding(): array  // url 'no', type null, host 'Off'
VhostRules::panelForwardType(string $forwardType): string
VhostRules::isReservedLabel(string $label): bool
VhostRules::subdomainMountPoint(string $parentKind, string $labelAscii, string $parentNameAscii): string
VhostRules::aliasMountPoint(string $aliasNameAscii): string
VhostRules::stripWww(string $name): string
VhostRules::documentRoot(string $input, callable $normalise): ?string
VhostRules::relativeToHtdocs(string $documentRoot): string
VhostRules::wildcard(bool $on): string
VhostRules::likeEscape(string $value): string

// Task 6 — Service\VhostInput (uses Core; integration-tested through Tasks 6 and 7)
new VhostInput(Toolkit $kit)
->forwarding(?array $input, string $selfAsciiName, string $field): array  // url, type, host columns
->documentRoot(CustomerAccount $account, string $mountPoint, string $input, string $field): string
->update(CustomerAccount $account, array $row, array $input): array       // documentRoot url type host wildcard
->sharedMountPoint(Identity $caller, $encoded, int $ownerId): string

// Task 6 — Repository\FtpGroups, VirtualHosts::nameInUse()
new FtpGroups(Db $db)  ->ofCustomer(string $username): ?array  ->withMemberOn(string $asciiHost): ?array
->removeMembers(array $group, callable $isRemoved): void  ->addMember(string $groupname, int $gid, string $member): void
VirtualHosts::nameInUse(string $asciiName): bool

// Tasks 6, 7, 9-11, 13, 15 — services
SubdomainService::create(Identity, array $input): ObjectRef
SubdomainService::update(Identity, string $id, array $input): ObjectRef
SubdomainService::delete(Identity, string $id): ObjectRef
DomainService::update(Identity, string $id, array $input): ObjectRef
DomainAliasService::create|update|delete        (same shapes)
MailService::create|update|delete, ::setAutoresponder(Identity, string $id, array $input): ObjectRef
MailService::createCatchall(Identity, array $input): ObjectRef   ::deleteCatchall(Identity, string $id): ObjectRef
FtpService::create|update|delete
SqlService::createDatabase(Identity, array $input)  ::deleteDatabase(Identity, string $id)
SqlService::createUser(Identity, array $input)  ::setUserPassword(Identity, string $id, string $password)
SqlService::deleteUser(Identity, string $id)
DnsService::create|update|delete

// Task 14 — Support\DnsRecordData
DnsRecordData::CREATABLE_TYPES
DnsRecordData::encode(string $type, array $input, string $zoneAscii, callable $toAscii, callable $domainNameError): array
  // => array('domain_dns' => string, 'domain_text' => string); ApiException BAD_USER_INPUT
DnsRecordData::formatTxt(string $data): string                     // InvalidArgumentException
DnsRecordData::quotedAndUnquoted(string $string): array
```

---

## File Structure

| File | Responsibility | Task |
| --- | --- | --- |
| `Repository/Db.php` | **Modified** — transactions through the panel's counter; `execute()`, `lastInsertId()` | 1 |
| `test/integration/Fixture.php` | **Modified** — opens and unwinds its transaction through `Db` | 1 |
| `Service/Core.php` | Every panel facility a service may use | 2 |
| `Service/PanelCore.php` | The one class that calls global panel functions | 2 |
| `Service/DirectoryProbe.php`, `Service/VfsDirectoryProbe.php`, `Service/UncheckedDirectoryProbe.php` | The directory existence check and its switch | 2 |
| `Service/SqlServer.php` | DDL port | 2 |
| `test/Double/RecordingCore.php`, `FakeDirectoryProbe.php`, `FakeSqlServer.php` | Test doubles shared by the integration and authz suites | 2 |
| `test/unit/Security/CoreCallsTest.php` | Enforces D10 | 2 |
| `config.php` | **Modified** — `validate_ftp_home_dir` | 2 |
| `Support/ObjectRef.php` | What a service hands back | 3 |
| `Security/Target.php`, `Security/Guard.php` | Spec §8.1 steps 2–7 | 3 |
| `Service/CustomerAccount.php`, `Repository/Accounts.php` | The owning customer, read fresh | 3 |
| `Service/Writer.php` | Spec §8.1 step 8 | 3 |
| `Service/Toolkit.php` | The fixed bundle every service takes | 3 |
| `Api/Container.php` | **Modified** — ports; later tasks add services and resolvers | 3, 8, 10, 11, 13, 15 |
| `Resolver/QueryResolver.php` | **Modified** — `decode()` delegates to `Guard::parse()` | 3 |
| `test/authz/*` | The authorisation matrix | 4, 18 |
| `test/phpunit.xml`, `tools/test.sh`, `composer.json` | **Modified** — the `authz` suite | 4 |
| `Support/VhostRules.php` | Pure vhost rules | 5 |
| `Service/VhostInput.php` | Forwarding, document-root and shared-mount-point input, shared by three services | 6 |
| `Repository/FtpGroups.php` | ProFTPD's per-customer group rows | 6 |
| `Resolver/MailResolver.php` | **Modified** — a catch-all's `forwardTo` | 10 |
| `test/integration/ServiceTestCase.php` | The base every service test extends | 6 |
| `Repository/VirtualHosts.php` | **Modified** — `nameInUse()` | 6 |
| `Service/SubdomainService.php` | Subdomains and alias subdomains | 6 |
| `Service/DomainService.php`, `Service/DomainAliasService.php` | The main domain; aliases | 7 |
| `schema/schema.graphql`, `test/schema/schema.printed.graphql` | **Modified** — `Mutation` and its inputs | 8, 10, 11, 13, 15 |
| `tools/print-schema.php` | Regenerates the schema snapshot | 8 |
| `Resolver/VirtualHostMutations.php` | Seven vhost mutations | 8 |
| `Service/MailService.php` | Mail accounts, autoresponders, catch-alls | 9, 10 |
| `Resolver/MailMutations.php` | Six mail mutations | 10 |
| `Service/FtpService.php`, `Resolver/FtpMutations.php` | FTP users | 11 |
| `Service/MariaDbSqlServer.php` | The DDL | 12 |
| `Service/SqlService.php`, `Resolver/SqlMutations.php` | SQL databases and users | 13 |
| `Support/DnsRecordData.php` | The DNS record codec | 14 |
| `Service/DnsService.php`, `Resolver/DnsMutations.php` | Custom DNS records | 15 |
| `Model/*`, `Repository/BatchLoader.php`, `Resolver/VirtualHostResolver.php` | **Removed / modified** — Anorm | 16 |
| `test/api/provision.php` | End-to-end provisioning against the box | 17 |
| `docs/SPECIFICATION.md` | **Modified** — §3.2, §3.3, §7.11, §14, §21 C11 | 2, 6, 8, 16, 18 |
| `CHANGELOG.md`, `docs/API.md`, `docs/DEVELOPMENT.md` | **Modified** | 16, 17, 18 |
| `test/unit/…`, `test/integration/…`, `test/authz/…`, `test/Double/…` | The tests and doubles, named in each task | all |

---
## Wave 1: The write path

Tasks 1–3. Everything a mutation goes through, before any mutation exists. Ends at Checkpoint A.

---

## Task 1: Transactions through the panel's counter — `sonnet`

Decision D11, measurements M1 and M2. After this task a service can open a transaction that a core helper nests inside, and a test fixture can wrap a service's whole transaction and still undo it.

**Files:**
- Modify: `Repository/Db.php`
- Modify: `test/integration/Fixture.php` (`seed()` and `rollBack()` only)
- Create: `test/unit/Repository/DbTransactionTest.php`
- Modify: `test/integration/DbTest.php` (append three methods)

**Interfaces:**
- Consumes: `Repository\Db` as inherited.
- Produces:
  ```php
  new Db(?PDO $pdo, $transactions = null)
  Db::fromPanel(): Db                   // now passes DatabaseMySQL::getInstance() as $transactions
  Db::beginTransaction(): void
  Db::commit(): void                    // RuntimeException when nothing is open (own counter only)
  Db::rollBack(): void                  // no-op when nothing is open (own counter only)
  Db::execute(string $sql, array $bind = array()): int
  Db::lastInsertId(): int
  ```
  Every later task writes through `execute()` and opens transactions only through `Service\Writer` (Task 3), which calls these.

- [ ] **Step 1: Write the failing unit test**

Create `test/unit/Repository/DbTransactionTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Repository;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DbTransactionTest extends TestCase
{
    public function testTheOutermostBeginOpensARealTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::never())->method('exec');

        (new Db($pdo))->beginTransaction();
    }

    public function testANestedBeginIsASavepoint(): void
    {
        // The same statements DatabaseMySQL issues (M1), so that a Db with no
        // panel behind it nests exactly as one with a panel does.
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::once())->method('exec')->with('SAVEPOINT TRANSACTION1');

        $db = new Db($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
    }

    public function testANestedCommitReleasesTheSavepointAndTheOuterOneCommits(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('exec')->withConsecutive(
            array('SAVEPOINT TRANSACTION1'),
            array('RELEASE SAVEPOINT TRANSACTION1')
        );
        $pdo->expects(self::once())->method('commit');

        $db = new Db($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
        $db->commit();
        $db->commit();
    }

    public function testANestedRollBackRollsBackToTheSavepointOnly(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::exactly(2))->method('exec')->withConsecutive(
            array('SAVEPOINT TRANSACTION1'),
            array('ROLLBACK TO SAVEPOINT TRANSACTION1')
        );
        $pdo->expects(self::once())->method('rollBack');

        $db = new Db($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
        $db->rollBack();
        $db->rollBack();
    }

    public function testCommitWithNothingOpenIsRefused(): void
    {
        // A commit with no begin is a bug in the caller. Saying so is better
        // than a PDOException about "no active transaction" from deep inside
        // whatever happened to call it.
        $this->expectException(RuntimeException::class);

        (new Db($this->createMock(PDO::class)))->commit();
    }

    public function testRollBackWithNothingOpenDoesNothing(): void
    {
        // Rollback runs on failure paths, which must never throw a second
        // exception over the first.
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('rollBack');

        (new Db($pdo))->rollBack();
    }

    public function testAnOwnerGivenAtConstructionOwnsTheCount(): void
    {
        // In the panel the owner is DatabaseMySQL. Db must not keep a second
        // count beside it: two counters that disagree about depth is the
        // failure M2 describes.
        $owner = new class {
            /** @var string[] */
            public $calls = array();
            public function beginTransaction(): void { $this->calls[] = 'begin'; }
            public function commit(): void { $this->calls[] = 'commit'; }
            public function rollBack(): void { $this->calls[] = 'rollBack'; }
        };
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('exec');

        $db = new Db($pdo, $owner);
        $db->beginTransaction();
        $db->commit();
        $db->beginTransaction();
        $db->rollBack();

        self::assertSame(array('begin', 'commit', 'begin', 'rollBack'), $owner->calls);
    }

    public function testExecuteReturnsTheAffectedRowCount(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(array('a', 3));
        $statement->method('rowCount')->willReturn(2);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->with('UPDATE t SET c = ? WHERE id = ?')->willReturn($statement);

        self::assertSame(
            2,
            (new Db($pdo))->execute('UPDATE t SET c = ? WHERE id = ?', array('x' => 'a', 'y' => 3))
        );
    }

    public function testLastInsertIdIsAnInteger(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('lastInsertId')->willReturn('42');

        self::assertSame(42, (new Db($pdo))->lastInsertId());
    }

    public function testADetachedHandleRefusesToOpenATransaction(): void
    {
        $this->expectException(RuntimeException::class);

        Db::detached()->beginTransaction();
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter DbTransactionTest`
Expected: FAIL — `Error: Call to undefined method iMSCP\Plugin\SGW_GraphQL\Repository\Db::beginTransaction()`. (Passing a second constructor argument to the current one-parameter constructor is not itself an error in PHP, so the undefined method is the first failure.)

- [ ] **Step 3: Implement**

In `Repository/Db.php`, replace the class docblock, the `$pdo` property block, the constructor and `fromPanel()` with:

```php
/**
 * The panel's own PDO handle, the small query helpers the plugin needs, and
 * the panel's transaction counter.
 *
 * It is the panel's connection, not a second one: spec section 3.2 requires
 * the same connection and the same transaction, and a read on another
 * connection would not see a mutation's uncommitted rows.
 *
 * Transactions go through the panel's DatabaseMySQL counter when there is a
 * panel (decision D11). DatabaseMySQL opens a real transaction at depth 0 and
 * a savepoint below it; a core helper such as createDefaultMailAccounts()
 * calls DatabaseMySQL::beginTransaction() itself, so if this class opened its
 * transaction on the PDO directly the panel's counter would still read 0 and
 * the helper would ask PDO for a second transaction, which PDO refuses. With
 * no panel - the unit suite - this class keeps the same count itself, with
 * the same statements.
 *
 * Not final, so that a unit test can subclass it and fake rows().
 */
class Db
{
    /** @var PDO|null Null only for a detached handle; see detached(). */
    private $pdo;

    /**
     * @var object|null DatabaseMySQL, or anything with beginTransaction(),
     *                  commit() and rollBack(). Null to count on the PDO.
     */
    private $transactions;

    /** @var int Depth, when this class is counting for itself. */
    private $depth = 0;

    /**
     * @param PDO|null    $pdo          Required, not defaulted: a Db that
     *                                  silently has no connection because
     *                                  nobody passed one is a bug that only
     *                                  shows up as an empty result set.
     *                                  Detachment is asked for by name.
     * @param object|null $transactions The owner of the transaction count.
     *                                  Untyped because DatabaseMySQL does not
     *                                  exist outside the panel.
     */
    public function __construct(?PDO $pdo, $transactions = null)
    {
        $this->pdo = $pdo;
        $this->transactions = $transactions;
    }

    public static function fromPanel(): self
    {
        return new self(DatabaseMySQL::getPDO(), DatabaseMySQL::getInstance());
    }
```

Then add these methods after `placeholders()`:

```php
    public function beginTransaction(): void
    {
        if ($this->transactions !== null) {
            $this->transactions->beginTransaction();

            return;
        }

        $pdo = $this->pdo();

        if ($this->depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT TRANSACTION' . $this->depth);
        }

        $this->depth++;
    }

    public function commit(): void
    {
        if ($this->transactions !== null) {
            $this->transactions->commit();

            return;
        }

        if ($this->depth === 0) {
            throw new RuntimeException('commit() was called with no transaction open.');
        }

        $this->depth--;

        if ($this->depth === 0) {
            $this->pdo()->commit();

            return;
        }

        $this->pdo()->exec('RELEASE SAVEPOINT TRANSACTION' . $this->depth);
    }

    public function rollBack(): void
    {
        if ($this->transactions !== null) {
            $this->transactions->rollBack();

            return;
        }

        if ($this->depth === 0) {
            return;
        }

        $this->depth--;

        if ($this->depth === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT TRANSACTION' . $this->depth);
    }

    /**
     * A statement with no result set.
     *
     * @return int The rows the server reports as affected. MySQL counts rows
     *             *changed*, not rows matched, so an UPDATE that writes the
     *             value already there reports 0: never use this to ask whether
     *             a row exists.
     */
    public function execute(string $sql, array $bind = array()): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(array_values($bind));

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo()->lastInsertId();
    }
```

- [ ] **Step 4: Run the unit test to verify it passes**

Run: `tools/test.sh --filter DbTransactionTest`
Expected: PASS, 10 tests.

- [ ] **Step 5: Move the fixture onto the panel's counter**

In `test/integration/Fixture.php`, in `seed()` replace

```php
        $this->db->pdo()->beginTransaction();
        $this->open = true;
```

with

```php
        // Through Db, not the PDO: a service under test opens its own
        // transaction through the same counter, and that one must become a
        // savepoint inside this one (decision D11). Opened on the PDO, the
        // panel's counter would read 0 and the service's begin would be
        // refused (M2).
        $this->db->beginTransaction();
        $this->open = true;
```

and replace the whole of `rollBack()` with

```php
    public function rollBack(): void
    {
        if (!$this->open) {
            return;
        }

        $this->open = false;

        // Unwound through reflection rather than by calling Db::rollBack()
        // once. A test that fails between a service's begin and its rollback
        // leaves DatabaseMySQL's counter above one; a single rollBack() would
        // then only roll back to a savepoint and leave the real transaction
        // open for the next test to seed into - which would pass, on rows a
        // previous test left behind. Zeroing the count and rolling the PDO
        // back undoes everything, whatever depth a failure left.
        $panel = \iMSCP\Database\DatabaseMySQL::getInstance();
        $counter = new \ReflectionProperty($panel, 'transactionCounter');
        $counter->setAccessible(true);
        $counter->setValue($panel, 0);

        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }

        $this->ids = array();
    }
```

- [ ] **Step 6: Write the integration tests**

Append to the class in `test/integration/DbTest.php`:

```php
    public function testAServiceTransactionNestsInsideTheFixturesAndIsUndoneWithIt(): void
    {
        // The arrangement every service test in this plan relies on: the
        // fixture's transaction is the real one, the service's is a savepoint
        // inside it, and the service's commit is a savepoint release that the
        // fixture's rollback still undoes.
        $db = $this->db();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $db->beginTransaction();
            $db->execute(
                "UPDATE domain SET domain_subd_limit = 77 WHERE domain_id = ?",
                array($fixture->domainId())
            );
            $db->commit();

            self::assertSame(
                '77',
                (string)$db->value(
                    'SELECT domain_subd_limit FROM domain WHERE domain_id = ?',
                    array($fixture->domainId())
                ),
                'the committed savepoint is visible inside the fixture'
            );

            $domainId = $fixture->domainId();
        } finally {
            $fixture->rollBack();
        }

        self::assertNull(
            $db->value('SELECT domain_id FROM domain WHERE domain_id = ?', array($domainId)),
            'the fixture rollback undid the service commit as well'
        );
    }

    public function testACoreHelperThatOpensItsOwnTransactionNestsToo(): void
    {
        // M2's failure, turned round: DatabaseMySQL::beginTransaction() inside
        // the fixture is a savepoint, not a refused second transaction.
        $db = $this->db();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $panel = \iMSCP\Database\DatabaseMySQL::getInstance();
            $panel->beginTransaction();
            $panel->commit();
            $this->addToAssertionCount(1);
        } finally {
            $fixture->rollBack();
        }
    }

    public function testTheFixtureUnwindsATransactionAFailingTestLeftOpen(): void
    {
        $db = $this->db();
        $fixture = new Fixture($db);
        $fixture->seed();
        $domainId = $fixture->domainId();

        // A service that began and then died before rolling back.
        $db->beginTransaction();
        $db->beginTransaction();

        $fixture->rollBack();

        self::assertFalse($db->pdo()->inTransaction());
        self::assertNull(
            $db->value('SELECT domain_id FROM domain WHERE domain_id = ?', array($domainId))
        );
    }
```

- [ ] **Step 7: Run the integration tests, then the whole suite**

Run: `tools/test.sh --filter DbTest`
Expected: PASS, the three new tests and the existing ones.

Run: `tools/test.sh`
Expected: lint PASS; both PHPUnit processes PASS. Every existing integration test uses the fixture, so this is the check that the move onto the panel's counter changed nothing for them.

- [ ] **Step 8: Commit**

```bash
git add Repository/Db.php test/integration/Fixture.php test/integration/DbTest.php test/unit/Repository/DbTransactionTest.php
git commit -m "Open transactions through the panel's counter

The panel's DatabaseMySQL nests transactions as savepoints, and core helpers
such as createDefaultMailAccounts() open their own through it. The fixture
opened its transaction on the raw PDO, so the panel's counter read zero and
any such helper called inside a test asked PDO for a second transaction,
which PDO refuses. Db now delegates to DatabaseMySQL when there is a panel
and keeps the same count itself when there is not, and the fixture opens and
unwinds through it.

The fixture's rollback zeroes the panel's counter before rolling back, so a
test that dies mid-transaction cannot leave the next test seeding into a
transaction that never really ended."
```

---

## Task 2: The core port, the directory probe, and the rule for calling core — `sonnet`

Decisions D10, D12 and D20. After this task there is exactly one class that calls global panel functions, a test that keeps it that way, and the test doubles every later task uses.

**Files:**
- Create: `Service/Core.php`, `Service/PanelCore.php`
- Create: `Service/DirectoryProbe.php`, `Service/VfsDirectoryProbe.php`, `Service/UncheckedDirectoryProbe.php`
- Create: `Service/SqlServer.php`
- Create: `test/Double/RecordingCore.php`, `test/Double/FakeDirectoryProbe.php`, `test/Double/FakeSqlServer.php`
- Create: `test/unit/Security/CoreCallsTest.php`
- Create: `test/integration/PanelCoreTest.php`
- Modify: `config.php` (one key)
- Modify: `docs/SPECIFICATION.md` §3.2 table, §6.5, §14

**Interfaces:**
- Consumes: `Repository\Db`, `Test\Integration\Fixture`, `Support\MailType`.
- Produces: `Service\Core`, `Service\PanelCore`, `Service\DirectoryProbe` (+ two implementations), `Service\SqlServer`, and the three doubles, with the signatures in [Interfaces this plan creates](#interfaces-this-plan-creates). `CoreCallsTest::ALLOWED` is the list later tasks add to when — and only when — they add a call in `PanelCore`.

- [ ] **Step 1: Write the failing static test**

Create `test/unit/Security/CoreCallsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Security;

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

use PHPUnit\Framework\TestCase;

/**
 * Decision D10, and spec section 6.5's first layer, as a property of the
 * source rather than a comment at each call site.
 *
 * Every call to a global function that PHP itself does not define is listed
 * here with the reason it cannot exit, or the test fails. A new panel
 * function reaching the API path is therefore a visible diff to this file,
 * with its justification beside it, and never an accident.
 */
class CoreCallsTest extends TestCase
{
    /** The directories the API request path is built from. */
    const SCANNED = array(
        'Api', 'Auth', 'Http', 'Repository', 'Resolver', 'Schema', 'Security',
        'Service', 'Support'
    );

    /**
     * Lower-case function name => why it may be called. Each takes every
     * identity it acts on as an argument, reports failure by return value or
     * exception, reaches no exit, and issues no DDL.
     */
    const ALLOWED = array(
        'exec_query'                    => 'Throws DatabaseException. Plan 1: Container, AccessService, TokenService.',
        'write_log'                     => 'Inserts a log row and may send mail; neither path exits.',
        'getfirstdayofmonth'            => 'Zend_Date arithmetic. Plan 2: Container::monthBounds().',
        'getlastdayofmonth'             => 'Zend_Date arithmetic. Plan 2: Container::monthBounds().',
        'parsemaildirsize'              => 'Reads one file. Plan 2: Container::mailboxUsage().',
        'send_request'                  => 'Opens a socket; logs and returns false on failure.',
        'encode_idna'                   => 'Pure.',
        'decode_idna'                   => 'Pure.',
        'isvaliddomainname'             => 'Pure; sets the $dmnNameValidationErrMsg global.',
        'chk_email'                     => 'Zend validation; pure.',
        'checkpasswordsyntax'           => 'Pure when its third argument is true (no page message).',
        'validates_username'            => 'Pure.',
        'utils_normalizepath'           => 'Pure.',
        'imscp_domain_exists'           => 'Explicit name and reseller id; returns bool.',
        'createdefaultmailaccounts'     => 'Explicit ids; throws DatabaseException; nests its transaction (D11).',
        'get_alias_order_email'         => 'Explicit reseller id; returns the template.',
        'send_mail'                     => 'Throws on bad input; returns bool.',
        'delete_autoreplies_log_entries' => 'One DELETE; no arguments, no session.'
    );

    /**
     * Named so that nobody adds one of these to ALLOWED without meeting this
     * list first. Each reads the session as the customer, exits, swallows its
     * own failure, or issues DDL (measurements M3-M6).
     */
    const FORBIDDEN = array(
        'showerrorpage', 'showbadrequesterrorpage', 'shownotfounderrorpage',
        'redirectto', 'set_page_message', 'check_login', 'filter_digits',
        'customerhasfeature', 'resellerhasfeature', 'customerhasdomain',
        'get_domain_default_props', 'get_user_domain_id',
        'customersqldblimitisreached', 'deletesubdomain', 'deletesubdomainalias',
        'deletedomainalias', 'delete_sql_database', 'sql_delete_user',
        'deletecustomer', 'change_domain_status'
    );

    public function testNoForbiddenFunctionIsAllowed(): void
    {
        self::assertSame(
            array(),
            array_values(array_intersect(self::FORBIDDEN, array_keys(self::ALLOWED)))
        );
    }

    public function testEveryGlobalCallOnTheApiPathIsAllowed(): void
    {
        $unexplained = array();

        foreach ($this->calls() as $name => $sites) {
            if (!isset(self::ALLOWED[$name])) {
                $unexplained[] = $name . ' at ' . implode(', ', $sites);
            }
        }

        self::assertSame(
            array(),
            $unexplained,
            'A global function reached the API path without an entry in '
                . 'CoreCallsTest::ALLOWED. Call it from Service\PanelCore, and '
                . 'say in ALLOWED why it cannot exit.'
        );
    }

    public function testOnlyPanelCoreCallsThePanelFunctionsThisPlanAdds(): void
    {
        // Plan 1 and 2's five calls predate Service\PanelCore and stay where
        // they are. Everything since goes through the port.
        $predating = array(
            'exec_query', 'write_log', 'getfirstdayofmonth', 'getlastdayofmonth',
            'parsemaildirsize'
        );
        $outside = array();

        foreach ($this->calls() as $name => $sites) {
            if (in_array($name, $predating, true)) {
                continue;
            }

            foreach ($sites as $site) {
                if (strpos($site, 'Service/PanelCore.php:') !== 0) {
                    $outside[] = $name . ' at ' . $site;
                }
            }
        }

        self::assertSame(array(), $outside);
    }

    public function testNoForbiddenNameIsUsedAsACallableString(): void
    {
        // 'deleteSubdomain' handed to call_user_func() would slip past a scan
        // for call syntax. Container::toUnicode() already returns
        // 'decode_idna' this way, so the check is not hypothetical.
        $found = array();

        foreach ($this->files() as $relative => $tokens) {
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array(strtolower(trim($token[1], '\'"')), self::FORBIDDEN, true)
                ) {
                    $found[] = $token[1] . ' at ' . $relative . ':' . $token[2];
                }
            }
        }

        self::assertSame(array(), $found);
    }

    /**
     * @return array<string, string[]> lower-case name => "path:line" sites
     */
    private function calls(): array
    {
        $internal = array_flip(get_defined_functions()['internal']);
        $skipBefore = array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST);

        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $skipBefore[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
        }

        $calls = array();

        foreach ($this->files() as $relative => $tokens) {
            $significant = array_values(array_filter($tokens, static function ($token) {
                return !is_array($token)
                    || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
            }));

            foreach ($significant as $i => $token) {
                $name = null;

                if (is_array($token) && $token[0] === T_STRING) {
                    $name = $token[1];
                } elseif (defined('T_NAME_FULLY_QUALIFIED') && is_array($token)
                    && $token[0] === constant('T_NAME_FULLY_QUALIFIED')
                ) {
                    // PHP 8 tokenises \write_log as one token.
                    $name = ltrim($token[1], '\\');

                    if (strpos($name, '\\') !== false) {
                        continue;
                    }
                }

                if ($name === null || ($significant[$i + 1] ?? null) !== '(') {
                    continue;
                }

                $previous = $significant[$i - 1] ?? null;

                if (is_array($previous) && in_array($previous[0], $skipBefore, true)) {
                    continue;
                }

                if (is_array($previous) && $previous[0] === T_NS_SEPARATOR) {
                    // "\foo(" is a global call; "Bar\foo(" and "new \Foo(" are not.
                    $before = $significant[$i - 2] ?? null;

                    if (is_array($before) && in_array($before[0], array(T_STRING, T_NEW), true)) {
                        continue;
                    }
                }

                $lower = strtolower($name);

                if (isset($internal[$lower])) {
                    continue;
                }

                $calls[$lower][] = $relative . ':' . $token[2];
            }
        }

        ksort($calls);

        return $calls;
    }

    /**
     * @return array<string, array> relative path => tokens
     */
    private function files(): array
    {
        $root = dirname(__DIR__, 3);
        $files = array();

        foreach (self::SCANNED as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory)
            );

            foreach ($iterator as $file) {
                if (substr((string)$file, -4) !== '.php') {
                    continue;
                }

                $relative = substr((string)$file, strlen($root) + 1);
                $files[$relative] = token_get_all(file_get_contents((string)$file));
            }
        }

        ksort($files);

        return $files;
    }
}
```

- [ ] **Step 2: Run it to see what it finds today**

Run: `tools/test.sh --filter CoreCallsTest`
Expected: PASS for all four tests on the current tree — the five calls plans 1 and 2 made are in `ALLOWED`. This is the baseline the rest of this task must keep. If `testEveryGlobalCallOnTheApiPathIsAllowed` fails naming a call not listed above, **stop and report it**: a call this plan did not know about is on the API path.

- [ ] **Step 3: Write the port interfaces**

Create `Service/Core.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use InvalidArgumentException;

/**
 * Every panel facility a service may use, and nothing else.
 *
 * Decision D10: a global panel function is called from the API path only if
 * it takes every identity explicitly, reports failure by value or exception,
 * cannot reach exit, and issues no DDL. PanelCore is the only implementation
 * that calls them, and CoreCallsTest holds the list.
 *
 * The first three methods are side effects a test must be able to observe
 * without performing (decision D12). The rest are the panel's own validators
 * and helpers, which tests run for real.
 */
interface Core
{
    /** EventAggregator::dispatch(), with the panel's own event name and parameters. */
    public function dispatch(string $event, array $params): void;

    /** send_request(): ask the daemon to process the queue. Never inside a transaction. */
    public function sendRequest(): void;

    /** write_log(), so API actions appear in the panel's log beside UI actions (spec section 11). */
    public function writeLog(string $message, int $level): void;

    /**
     * A panel configuration value, or $default when the key is absent.
     *
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null);

    /** encode_idna(). */
    public function toAscii(string $name): string;

    /** decode_idna(). */
    public function toUnicode(string $name): string;

    /** isValidDomainName(): null when valid, otherwise the panel's own reason. */
    public function domainNameError(string $name): ?string;

    /** chk_email() over a whole address. */
    public function isValidEmail(string $address): bool;

    /** chk_email() over a local part only. */
    public function isValidEmailLocalPart(string $localPart): bool;

    /** checkPasswordSyntax() with no page message: PASSWD_CHARS and PASSWD_STRONG. */
    public function isAcceptablePassword(string $password): bool;

    /** validates_username(). */
    public function isValidUsername(string $username): bool;

    /** The host rule of sql_user_add.php:174-180. */
    public function isValidSqlHost(string $asciiHost): bool;

    /** utils_normalizePath(). */
    public function normalisePath(string $path): string;

    /** Crypt::sha512(): how the backend expects mail and FTP passwords (spec section 12). */
    public function hashPassword(string $password): string;

    /** imscp_domain_exists(): taken, or a subzone of another reseller's domain. */
    public function domainExists(string $name, int $resellerId): bool;

    /** createDefaultMailAccounts(). Writes rows; opens and nests its own transaction. */
    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void;

    /** The php_ini row a new subdomain or alias needs, through PhpEditor. */
    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void;

    /**
     * A forward URL as the pages normalise it (subdomain_add.php:341-379).
     *
     * @throws InvalidArgumentException carrying the reason
     */
    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string;

    /** alias_add.php's send_alias_order_email(), for an explicit customer. */
    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void;

    /** delete_autoreplies_log_entries(). */
    public function pruneAutoreplyLog(): void;
}
```

Create `Service/DirectoryProbe.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

/**
 * Whether a directory exists inside a customer's web space.
 *
 * The panel asks through VirtualFileSystem, which creates a temporary FTP
 * account and logs in over FTP (spec section 3.2). That cannot run inside a
 * transaction - the FTP server must see the temporary row committed - so it
 * sits behind this port (decision D12), and validate_ftp_home_dir switches it
 * off (spec section 14, decision D20).
 */
interface DirectoryProbe
{
    /**
     * @param string $customerUsername admin.admin_name of the owning customer
     * @param string $root             The VFS root, relative to the customer's
     *                                 web space: '/' or '<mount>/htdocs'
     * @param string $path             The directory, relative to $root
     */
    public function exists(string $customerUsername, string $root, string $path): bool;
}
```

Create `Service/VfsDirectoryProbe.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\VirtualFileSystem;

/**
 * The panel's own check. Exercised end to end by Task 17, not by the
 * integration suite: it needs a committed row and a running FTP server.
 *
 * A failure to connect is VirtualFileSystem's RuntimeException, left to
 * propagate: ErrorFactory turns it into INTERNAL with a correlation id, which
 * is the honest answer when ProFTPD is restarting (spec section 20, risk 5).
 */
final class VfsDirectoryProbe implements DirectoryProbe
{
    public function exists(string $customerUsername, string $root, string $path): bool
    {
        $vfs = new VirtualFileSystem($customerUsername, $root);

        return $vfs->exists($path, VirtualFileSystem::VFS_TYPE_DIR);
    }
}
```

Create `Service/UncheckedDirectoryProbe.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

/**
 * validate_ftp_home_dir = false. Every directory is taken to exist; the
 * backend creates or fails on it, and says so in provisioning.message.
 */
final class UncheckedDirectoryProbe implements DirectoryProbe
{
    public function exists(string $customerUsername, string $root, string $path): bool
    {
        return true;
    }
}
```

Create `Service/SqlServer.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

/**
 * The statements the panel issues against MariaDB itself for a customer's
 * databases and users.
 *
 * Every one of them implicitly commits (measurement M5), so none may run
 * inside a transaction a caller expects to roll back, and none may run inside
 * a test's fixture. Behind this port for that reason (decision D12);
 * MariaDbSqlServer (Task 12) is the implementation and is tested on its own.
 */
interface SqlServer
{
    public function databaseExists(string $name): bool;

    public function createDatabase(string $name): void;

    public function dropDatabase(string $name): void;

    public function userExists(string $user, string $host): bool;

    public function createUser(string $user, string $host, string $password): void;

    /** ALL PRIVILEGES on one database, with its name's LIKE wildcards escaped. */
    public function grantDatabase(string $user, string $host, string $database): void;

    /** Removes the grant on one database and leaves the user. */
    public function revokeDatabase(string $user, string $host, string $database): void;

    public function dropUser(string $user, string $host): void;

    public function setPassword(string $user, string $host, string $password): void;
}
```

- [ ] **Step 4: Write `PanelCore`**

Create `Service/PanelCore.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use Exception;
use iMSCP\Crypt;
use iMSCP\Event\EventAggregator;
use iMSCP\PhpEditor;
use iMSCP\Registry;
use iMSCP\Uri\UriRedirect;
use iMSCP\Validate\CommonValidation;
use InvalidArgumentException;
use PDO;

/**
 * The one class on the API path that calls global panel functions (D10).
 *
 * Constructing it touches nothing, so the unit suite can build the whole
 * resolver map with one. Every method needs the panel bootstrapped.
 */
final class PanelCore implements Core
{
    /** @var bool */
    private $pokeDaemon;

    /**
     * @param bool $pokeDaemon False for a test harness that must not start
     *                         the backend on rows it will roll back.
     */
    public function __construct(bool $pokeDaemon = true)
    {
        $this->pokeDaemon = $pokeDaemon;
    }

    public function dispatch(string $event, array $params): void
    {
        EventAggregator::getInstance()->dispatch($event, $params);
    }

    public function sendRequest(): void
    {
        if ($this->pokeDaemon) {
            // Logs and returns false when the daemon is unreachable. The
            // rows are committed and a later request will pick them up, so a
            // failure here is not the caller's error.
            send_request();
        }
    }

    public function writeLog(string $message, int $level): void
    {
        write_log($message, $level);
    }

    public function config(string $key, $default = null)
    {
        $config = Registry::get('config');

        // isset() rather than [] alone: ArrayConfig::get() throws on a missing
        // key, and a missing optional key is a default, not an outage.
        return isset($config[$key]) ? $config[$key] : $default;
    }

    public function toAscii(string $name): string
    {
        return (string)encode_idna($name);
    }

    public function toUnicode(string $name): string
    {
        return (string)decode_idna($name);
    }

    public function domainNameError(string $name): ?string
    {
        global $dmnNameValidationErrMsg;

        $dmnNameValidationErrMsg = '';

        if (isValidDomainName($name)) {
            return null;
        }

        return $dmnNameValidationErrMsg === '' ? 'Invalid domain name.' : (string)$dmnNameValidationErrMsg;
    }

    public function isValidEmail(string $address): bool
    {
        return (bool)chk_email($address);
    }

    public function isValidEmailLocalPart(string $localPart): bool
    {
        return (bool)chk_email($localPart, true);
    }

    public function isAcceptablePassword(string $password): bool
    {
        // The third argument suppresses set_page_message(); the second is the
        // panel's own default character rule, restated because it precedes it.
        return (bool)checkPasswordSyntax($password, '/[^\x21-\x7e]/', true);
    }

    public function isValidUsername(string $username): bool
    {
        return (bool)validates_username($username);
    }

    public function isValidSqlHost(string $asciiHost): bool
    {
        // CORE-DEBT(C3): transcribed from gui/public/client/sql_user_add.php:174-180.
        //   Retire when SqlUserService lands in core.
        if (strpos($asciiHost, '%') !== false || strpos($asciiHost, '_') !== false
            || $asciiHost === 'localhost'
        ) {
            return true;
        }

        return (bool)CommonValidation::getInstance()->hostname($asciiHost, array(
            'allow' => \Zend_Validate_Hostname::ALLOW_DNS | \Zend_Validate_Hostname::ALLOW_IP
        ));
    }

    public function normalisePath(string $path): string
    {
        return (string)utils_normalizePath($path);
    }

    public function hashPassword(string $password): string
    {
        return (string)Crypt::sha512($password);
    }

    public function domainExists(string $name, int $resellerId): bool
    {
        return (bool)imscp_domain_exists($name, $resellerId);
    }

    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void {
        createDefaultMailAccounts($mainDomainId, $customerEmail, $asciiHostName, $forwardMailType, $subId);
    }

    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void {
        // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:438-452.
        //   The four calls are PhpEditor's own; the sequence is the page's.
        //   Retire when SubdomainService lands in core.
        $editor = PhpEditor::getInstance();
        $editor->loadResellerPermissions((string)$resellerId);
        $editor->loadClientPermissions((string)$customerAdminId);
        $editor->loadDomainIni((string)$customerAdminId, (string)$mainDomainId, 'dmn');
        $editor->saveDomainIni((string)$customerAdminId, (string)$vhostId, $vhostType);
    }

    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string
    {
        // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:341-379,
        //   repeated in alias_add.php, subdomain_edit.php, alias_edit.php and
        //   domain_edit.php. Retire when the vhost services land in core.
        try {
            $uri = UriRedirect::fromString($url);
            $uri->setHost(encode_idna(mb_strtolower($uri->getHost())));
            $uri->setPath(rtrim(utils_normalizePath($uri->getPath()), '/') . '/');
        } catch (Exception $e) {
            // UriException from fromString(), Zend_Uri_Exception from the
            // setters: both are "not a URL we accept".
            throw new InvalidArgumentException(sprintf('Forward URL %s is not valid.', $url), 0, $e);
        }

        // getPort() is false when there is none (M18); in_array()'s loose
        // comparison makes false equal '', which is the page's intent.
        if ($uri->getHost() == $selfAsciiName && $uri->getPath() == '/'
            && in_array($uri->getPort(), array('', 80, 443))
        ) {
            throw new InvalidArgumentException(sprintf(
                'Forward URL %s is not valid: a host cannot be forwarded to itself.', $url
            ));
        }

        if ($proxy) {
            $port = $uri->getPort();

            if ($port && $port < 1025) {
                throw new InvalidArgumentException(
                    'Only ports above 1024 are allowed in a proxied forward URL.'
                );
            }
        }

        return (string)$uri->getUri();
    }

    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
    {
        // CORE-DEBT(C1): transcribed from gui/public/client/alias_add.php:45-80,
        //   which reads the customer from $_SESSION['user_id'].
        // CORE-DEBT(C11): the page sends the reseller's "your customer is
        //   awaiting approval" template to the customer's own address. Kept,
        //   because a changed recipient is a behaviour change the panel should
        //   make first; filed with the other defects in C11.
        $row = exec_query(
            'SELECT admin_name, created_by, fname, lname, email FROM admin WHERE admin_id = ?',
            array($customerAdminId)
        )->fetchRow(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return;
        }

        $data = get_alias_order_email($row['created_by']);
        $sent = send_mail(array(
            'mail_id'      => 'alias-order-msg',
            'fname'        => $row['fname'],
            'lname'        => $row['lname'],
            'username'     => $row['admin_name'],
            'email'        => $row['email'],
            'subject'      => $data['subject'],
            'message'      => $data['message'],
            'placeholders' => array(
                '{CUSTOMER}' => decode_idna($row['admin_name']),
                '{ALIAS}'    => $aliasName
            )
        ));

        if (!$sent) {
            write_log(sprintf("Couldn't send alias order to %s", $row['admin_name']), E_USER_ERROR);
        }
    }

    public function pruneAutoreplyLog(): void
    {
        delete_autoreplies_log_entries();
    }
}
```

- [ ] **Step 5: Run the static test against the new class**

Run: `tools/test.sh --filter CoreCallsTest`
Expected: PASS. Every function `PanelCore` calls is in `ALLOWED`, and all of them are called only from `Service/PanelCore.php`.

- [ ] **Step 6: Write the test doubles**

Create `test/Double/RecordingCore.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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

use iMSCP\Plugin\SGW_GraphQL\Service\Core;

/**
 * A Core that records its side effects instead of performing them, and asks a
 * real Core for everything else.
 *
 * Recorded, not performed: dispatch() (other plugins' listeners are not this
 * suite's subject, and the transcription is asserted by name and parameters
 * instead), sendRequest() (the daemon would act on rows the test rolls back),
 * writeLog() and sendAliasOrderEmail() (a test must not send mail).
 *
 * Delegated: the validators, the IDN codec, the password hash, PhpEditor,
 * createDefaultMailAccounts() and domainExists() - the panel's own rules,
 * which are exactly what the integration suite is for.
 */
final class RecordingCore implements Core
{
    /** @var array<int, array{0: string, 1: array}> */
    public $events = array();

    /** @var int */
    public $requests = 0;

    /** @var array<int, array{0: string, 1: int}> */
    public $logs = array();

    /** @var array<int, array{0: int, 1: string}> */
    public $aliasOrders = array();

    /** @var int */
    public $prunes = 0;

    /** @var Core */
    private $inner;

    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed> $config Overrides for config(), so a test can
     *                                     state the setting it depends on
     *                                     rather than inherit the box's.
     */
    public function __construct(Core $inner, array $config = array())
    {
        $this->inner = $inner;
        $this->config = $config;
    }

    /** @return string[] */
    public function eventNames(): array
    {
        $names = array();

        foreach ($this->events as $event) {
            $names[] = $event[0];
        }

        return $names;
    }

    public function dispatch(string $event, array $params): void
    {
        $this->events[] = array($event, $params);
    }

    public function sendRequest(): void
    {
        $this->requests++;
    }

    public function writeLog(string $message, int $level): void
    {
        $this->logs[] = array($message, $level);
    }

    public function config(string $key, $default = null)
    {
        return array_key_exists($key, $this->config)
            ? $this->config[$key]
            : $this->inner->config($key, $default);
    }

    public function toAscii(string $name): string { return $this->inner->toAscii($name); }
    public function toUnicode(string $name): string { return $this->inner->toUnicode($name); }
    public function domainNameError(string $name): ?string { return $this->inner->domainNameError($name); }
    public function isValidEmail(string $address): bool { return $this->inner->isValidEmail($address); }
    public function isValidEmailLocalPart(string $localPart): bool { return $this->inner->isValidEmailLocalPart($localPart); }
    public function isAcceptablePassword(string $password): bool { return $this->inner->isAcceptablePassword($password); }
    public function isValidUsername(string $username): bool { return $this->inner->isValidUsername($username); }
    public function isValidSqlHost(string $asciiHost): bool { return $this->inner->isValidSqlHost($asciiHost); }
    public function normalisePath(string $path): string { return $this->inner->normalisePath($path); }
    public function hashPassword(string $password): string { return $this->inner->hashPassword($password); }
    public function domainExists(string $name, int $resellerId): bool { return $this->inner->domainExists($name, $resellerId); }

    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void {
        $this->inner->createDefaultMailAccounts($mainDomainId, $customerEmail, $asciiHostName, $forwardMailType, $subId);
    }

    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void {
        $this->inner->savePhpIni($resellerId, $customerAdminId, $mainDomainId, $vhostId, $vhostType);
    }

    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string
    {
        return $this->inner->normaliseForwardUrl($url, $selfAsciiName, $proxy);
    }

    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
    {
        $this->aliasOrders[] = array($customerAdminId, $aliasName);
    }

    public function pruneAutoreplyLog(): void
    {
        $this->prunes++;
    }
}
```

Create `test/Double/FakeDirectoryProbe.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DirectoryProbe;

final class FakeDirectoryProbe implements DirectoryProbe
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public $asked = array();

    /** @var bool */
    private $answer;

    public function __construct(bool $answer = true)
    {
        $this->answer = $answer;
    }

    public function exists(string $customerUsername, string $root, string $path): bool
    {
        $this->asked[] = array($customerUsername, $root, $path);

        return $this->answer;
    }
}
```

Create `test/Double/FakeSqlServer.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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

use iMSCP\Plugin\SGW_GraphQL\Service\SqlServer;

/**
 * No DDL: every statement MariaDbSqlServer would issue implicitly commits, and
 * a commit inside a test's fixture leaves the fixture's rows on the box
 * (decision D12). Remembers what exists so a service's existence checks see
 * the effect of its own earlier calls.
 */
final class FakeSqlServer implements SqlServer
{
    /** @var array<int, array> Every call, in order, e.g. array('createDatabase', 'x'). */
    public $operations = array();

    /** @var array<string, true> */
    public $databases = array('mysql' => true, 'information_schema' => true);

    /** @var array<string, string> "user@host" => password */
    public $users = array();

    public function databaseExists(string $name): bool
    {
        return isset($this->databases[$name]);
    }

    public function createDatabase(string $name): void
    {
        $this->operations[] = array('createDatabase', $name);
        $this->databases[$name] = true;
    }

    public function dropDatabase(string $name): void
    {
        $this->operations[] = array('dropDatabase', $name);
        unset($this->databases[$name]);
    }

    public function userExists(string $user, string $host): bool
    {
        return isset($this->users[$user . '@' . $host]);
    }

    public function createUser(string $user, string $host, string $password): void
    {
        $this->operations[] = array('createUser', $user, $host);
        $this->users[$user . '@' . $host] = $password;
    }

    public function grantDatabase(string $user, string $host, string $database): void
    {
        $this->operations[] = array('grantDatabase', $user, $host, $database);
    }

    public function revokeDatabase(string $user, string $host, string $database): void
    {
        $this->operations[] = array('revokeDatabase', $user, $host, $database);
    }

    public function dropUser(string $user, string $host): void
    {
        $this->operations[] = array('dropUser', $user, $host);
        unset($this->users[$user . '@' . $host]);
    }

    public function setPassword(string $user, string $host, string $password): void
    {
        $this->operations[] = array('setPassword', $user, $host);
        $this->users[$user . '@' . $host] = $password;
    }
}
```

- [ ] **Step 7: Write the failing integration test for `PanelCore`**

Create `test/integration/PanelCoreTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use InvalidArgumentException;

/**
 * The port against the real panel. Expected values are measurements M17-M19,
 * taken in the box before this plan was written.
 */
class PanelCoreTest extends IntegrationTestCase
{
    /** @var PanelCore */
    private $core;

    protected function setUp(): void
    {
        $this->core = new PanelCore(false);
    }

    public function testTheDomainNameValidatorGivesThePanelsReason(): void
    {
        self::assertNull($this->core->domainNameError('shop.example.test'));
        self::assertSame(
            'Usage of dot in domain name labels is prohibited.',
            $this->core->domainNameError('bad..name')
        );
        self::assertNotNull($this->core->domainNameError('single'), 'one label is not a domain');
    }

    public function testTheReasonDoesNotLeakFromOneCallToTheNext(): void
    {
        // isValidDomainName() reports through a global. A stale message from
        // an earlier failure must not be read as the reason for a later one.
        $this->core->domainNameError('bad..name');

        self::assertNull($this->core->domainNameError('good.example.test'));
    }

    public function testTheIdnCodecRoundTrips(): void
    {
        self::assertSame('xn--bcher-kva.test', $this->core->toAscii('bücher.test'));
        self::assertSame('bücher.test', $this->core->toUnicode('xn--bcher-kva.test'));
    }

    public function testTheEmailValidators(): void
    {
        self::assertTrue($this->core->isValidEmail('a@example.net'));
        self::assertFalse($this->core->isValidEmail('not an email'));
        self::assertTrue($this->core->isValidEmailLocalPart('sales'));
    }

    public function testThePasswordPolicyIsThePanels(): void
    {
        // PASSWD_CHARS 6 and PASSWD_STRONG 1 on the box (M17).
        self::assertFalse($this->core->isAcceptablePassword('abc'));
        self::assertFalse($this->core->isAcceptablePassword('lettersonly'));
        self::assertTrue($this->core->isAcceptablePassword('Str0ngPassw0rd'));
    }

    public function testTheUsernameValidator(): void
    {
        self::assertTrue($this->core->isValidUsername('ftpuser'));
        self::assertFalse($this->core->isValidUsername('-bad'));
    }

    public function testTheSqlHostRule(): void
    {
        self::assertTrue($this->core->isValidSqlHost('localhost'));
        self::assertTrue($this->core->isValidSqlHost('%'));
        self::assertTrue($this->core->isValidSqlHost('db.example.net'));
        self::assertTrue($this->core->isValidSqlHost('10.0.0.1'));
        self::assertFalse($this->core->isValidSqlHost('bad host'));
    }

    public function testPasswordsAreHashedAsTheBackendExpects(): void
    {
        self::assertStringStartsWith('$6$', $this->core->hashPassword('x'));
    }

    public function testPathNormalisation(): void
    {
        self::assertSame('/x', $this->core->normalisePath('/htdocs/../x'));
        self::assertSame('/htdocs/a/b', $this->core->normalisePath('/htdocs//a/./b/'));
    }

    public function testConfigFallsBackToTheDefault(): void
    {
        self::assertSame('/var/www/virtual', $this->core->config('USER_WEB_DIR'));
        self::assertSame('fallback', $this->core->config('SGWT_NO_SUCH_KEY', 'fallback'));
    }

    /**
     * @dataProvider forwardUrls
     */
    public function testForwardUrlsAreNormalisedAsThePagesDo(string $url, string $expected): void
    {
        self::assertSame($expected, $this->core->normaliseForwardUrl($url, 'self.example.test', false));
    }

    public function forwardUrls(): array
    {
        return array(
            'scheme and host lower-cased, path collapsed' => array('HTTP://Example.COM/a/../b', 'http://example.com/b/'),
            'a trailing slash added'                      => array('https://example.net', 'https://example.net/'),
            'port, query and fragment kept'               => array('http://example.net:8080/x?y=1#f', 'http://example.net:8080/x/?y=1#f'),
            'ftp is a scheme the panel accepts'           => array('ftp://files.example.net/pub', 'ftp://files.example.net/pub/')
        );
    }

    /**
     * @dataProvider refusedForwardUrls
     */
    public function testForwardUrlsThePagesRefuse(string $url, bool $proxy): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->core->normaliseForwardUrl($url, 'self.example.test', $proxy);
    }

    public function refusedForwardUrls(): array
    {
        return array(
            'not a scheme the panel accepts' => array('javascript:alert(1)', false),
            'no host'                        => array('http://', false),
            'a space in the host'            => array('http://bad host/', false),
            'forwarded to itself'            => array('https://self.example.test/', false),
            'forwarded to itself on 443'     => array('https://self.example.test:443', false),
            'a proxy to a privileged port'   => array('http://example.net:80/', true)
        );
    }

    public function testAForwardToItselfOnAnotherPathIsAllowed(): void
    {
        self::assertSame(
            'https://self.example.test/app/',
            $this->core->normaliseForwardUrl('https://self.example.test/app', 'self.example.test', false)
        );
    }

    public function testDomainExistsSeesTheFixturesDomain(): void
    {
        $db = Db::fromPanel();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            self::assertTrue($this->core->domainExists($fixture->domainName(), $fixture->resellerId()));
            self::assertFalse($this->core->domainExists('sgwt-nobody-owns-this.test', $fixture->resellerId()));
        } finally {
            $fixture->rollBack();
        }
    }

    public function testSavePhpIniWritesTheVhostsRow(): void
    {
        $db = Db::fromPanel();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $this->core->savePhpIni(
                $fixture->resellerId(), $fixture->customerId(), $fixture->domainId(),
                $fixture->subdomainId(), 'sub'
            );

            self::assertSame('1', (string)$db->value(
                "SELECT COUNT(*) FROM php_ini WHERE admin_id = ? AND domain_id = ? AND domain_type = 'sub'",
                array($fixture->customerId(), $fixture->subdomainId())
            ));
        } finally {
            $fixture->rollBack();
        }
    }

    public function testDefaultMailAccountsForASubdomainAreOneWebmaster(): void
    {
        // Inside the fixture's transaction: this is also D11 working, since
        // createDefaultMailAccounts() opens a transaction of its own.
        $db = Db::fromPanel();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $this->core->createDefaultMailAccounts(
                $fixture->domainId(), 'owner@example.test', $fixture->subdomainName(),
                MailType::toMailType(MailType::HOST_SUB, MailType::KIND_FORWARD),
                $fixture->subdomainId()
            );

            self::assertSame(
                array(array('mail_addr' => 'webmaster@' . $fixture->subdomainName(), 'mail_type' => 'subdom_forward', 'status' => 'toadd')),
                $db->rows(
                    'SELECT mail_addr, mail_type, status FROM mail_users WHERE domain_id = ? AND sub_id = ?',
                    array($fixture->domainId(), $fixture->subdomainId())
                )
            );
        } finally {
            $fixture->rollBack();
        }
    }
}
```

- [ ] **Step 8: Run the integration test**

Run: `tools/test.sh --filter PanelCoreTest`
Expected: PASS, 17 tests (the two data providers count once per row). A failure in a forward-URL case means `PanelCore::normaliseForwardUrl()` differs from the transcription; do not change the expected values, which are M18's measurements.

- [ ] **Step 9: Add the configuration key**

In `config.php`, after the `'debug'` entry, add a comma to `'debug' => false` and append:

```php

    // Whether directories named in a mutation - an FTP home directory, a new
    // document root - are checked to exist first. The panel checks by
    // creating a temporary FTP account and logging in (spec section 3.2),
    // which costs a connection per check and fails while ProFTPD restarts.
    // Off, the backend creates or refuses the directory itself and says so in
    // provisioning.message.
    'validate_ftp_home_dir'   => true
```

- [ ] **Step 10: Amend the specification**

In `docs/SPECIFICATION.md` §3.2's table, replace the two rows beginning `` | `customerHasFeature()`, `resellerHasFeature()` `` and `` | `deleteSubdomain()` `` with:

```markdown
| `customerHasFeature()`, `resellerHasFeature()`, `customerSqlDbLimitIsReached()`, `get_domain_default_props()` | **Not called.** Each reads the customer from `$_SESSION['user_id']` or caches for the request without a key, so it answers for the caller rather than for the customer a reseller is acting on. Transcribed with `CORE-DEBT(C1)` markers. |
| `deleteSubdomain()`, `deleteSubdomainAlias()`, `deleteDomainAlias()`, `delete_sql_database()`, `sql_delete_user()`, `change_domain_status()` | **Not called.** The first two authorise on `$_SESSION['user_id']` and exit on a miss; `deleteDomainAlias()` swallows its own failure; the SQL two interleave DDL with row writes. Transcribed with `CORE-DEBT` markers. |
| Everything the API path does call | Through one class, `Service\PanelCore`, and only functions that take every identity explicitly, report failure by value or exception, cannot reach `exit`, and issue no DDL. `test/unit/Security/CoreCallsTest.php` holds the list and fails on any other. |
```

In §6.5, replace the first list item with:

```markdown
1. **No exiting helper is called.** The API path calls a global panel function only through `Service\PanelCore`, and only functions that cannot reach `exit` (§3.2). `CoreCallsTest` enforces the list by tokenising the source, so a helper that can exit cannot be reached by accident.
```

In §14's configuration listing, replace the last two lines with:

```php
    // Directory existence checks - FTP home directories and document roots
    // alike - open an FTP connection per call (§3.2).
    'validate_ftp_home_dir'       => true
```

- [ ] **Step 11: Run everything**

Run: `tools/test.sh`
Expected: lint PASS (both PHP versions, `CORE-DEBT` inventory now includes C1 and C3 from `PanelCore`, licence headers PASS); unit, schema and integration PASS.

- [ ] **Step 12: Commit**

```bash
git add Service test/Double test/unit/Security/CoreCallsTest.php test/integration/PanelCoreTest.php config.php docs/SPECIFICATION.md
git commit -m "Call the panel through one port, and only what cannot exit

The spec said the core delete helpers would be called behind the identity
shim. Reading them showed that cannot work: the shim holds the caller, and
deleteSubdomain() authorises on it as though it were the customer, so a
reseller acting on a customer reaches showBadRequestErrorPage(). Others
swallow their own failures or issue DDL mid-way through row writes.

The rule is now stated once and enforced: a panel function is called only if
it takes every identity explicitly, reports failure by value or exception,
cannot exit and issues no DDL, and only from Service\PanelCore. CoreCallsTest
tokenises the API source and fails on any other call, which turns section
6.5's first layer from a convention into a test. The daemon, the directory
probe and the SQL server sit behind ports so tests can observe them without
provisioning rows they will roll back."
```

---
## Task 3: Guard, accounts and the writer — the order of every write — `sonnet`

Spec §8.1 steps 2–8 as code. After this task every service in Tasks 6–15 has one way to raise each of the six refusals, one way to read the owning customer, and one way to run its transaction.

**Files:**
- Create: `Support/ObjectRef.php`, `Security/Target.php`, `Security/Guard.php`
- Create: `Service/CustomerAccount.php`, `Repository/Accounts.php`, `Service/Writer.php`, `Service/Toolkit.php`
- Modify: `Resolver/QueryResolver.php` (`decode()` and `notFound()` only)
- Modify: `Api/Container.php`
- Create: `test/unit/Security/GuardTest.php`, `test/unit/Service/WriterTest.php`
- Create: `test/integration/AccountsTest.php`
- Modify: `test/unit/Api/ContainerTest.php` (append one method)

**Interfaces:**
- Consumes: `Security\OwnershipResolver::assertReachable()`, `Support\GlobalId`, `Support\NodeType`, `Support\Provisioning`, `Support\Quota`, `Repository\Db` (Task 1), `Repository\VirtualHosts::byKeys()`, `Repository\Counts`, `Service\Core`, `Service\DirectoryProbe`, `Service\SqlServer` (Task 2).
- Produces: `ObjectRef`, `Target`, `Guard`, `CustomerAccount`, `Accounts`, `Writer`, `Toolkit` and the `Container` changes, exactly as listed in [Interfaces this plan creates](#interfaces-this-plan-creates). Additionally `Container::toolkit(): Toolkit` and `Container::sqlServer(): ?SqlServer`, which Tasks 8–15 call from `resolverMaps()`.

- [ ] **Step 1: Write the failing Guard test**

Create `test/unit/Security/GuardTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use PHPUnit\Framework\TestCase;

class GuardTest extends TestCase
{
    /** @var string[] Every query the ownership resolver ran. */
    private $queries = array();

    /**
     * Reseller 5 created customers 7 and 8; customer 7 owns subdomain 3 and
     * FTP user a@b.test.
     */
    private function guard(): Guard
    {
        $this->queries = array();

        return new Guard(new OwnershipResolver(function (string $sql, array $bind) {
            $this->queries[] = $sql;

            if (strpos($sql, 'FROM subdomain AS s') !== false) {
                return $bind === array(3) ? array(array('owner_id' => 7)) : array();
            }

            if (strpos($sql, 'FROM ftp_users AS f') !== false) {
                return $bind === array('a@b.test') ? array(array('owner_id' => 7)) : array();
            }

            if (strpos($sql, 'SELECT created_by') !== false) {
                return array(array('created_by' => 5));
            }

            return array();
        }));
    }

    private function identity(int $adminId, string $type, ?int $createdBy, array $scopes = array()): Identity
    {
        return new Identity(
            $adminId, 'user' . $adminId, $type, $createdBy, null, $scopes,
            $scopes === array() ? null : 1
        );
    }

    private function codeOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ApiException $e) {
            return array($e->getErrorCode(), $e->getExtensions());
        }

        self::fail('expected an ApiException');
    }

    public function testTheOwnerReachesTheTarget(): void
    {
        $target = $this->guard()->target(
            $this->identity(7, 'user', 5),
            GlobalId::encode(NodeType::SUBDOMAIN, 3),
            array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN),
            Scope::DOMAINS_WRITE,
            'id'
        );

        self::assertSame(NodeType::SUBDOMAIN, $target->getTag());
        self::assertSame(3, $target->getKey());
        self::assertSame(7, $target->getOwnerId());
    }

    public function testTheOwningResellerAndAnAdministratorReachIt(): void
    {
        $id = GlobalId::encode(NodeType::SUBDOMAIN, 3);

        self::assertSame(7, $this->guard()->target(
            $this->identity(5, 'reseller', 1), $id, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
        )->getOwnerId());
        self::assertSame(7, $this->guard()->target(
            $this->identity(1, 'admin', null), $id, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
        )->getOwnerId());
    }

    public function testASiblingGetsNotFoundNamingTheField(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            $this->guard()->target(
                $this->identity(8, 'user', 5),
                GlobalId::encode(NodeType::SUBDOMAIN, 3),
                array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'input.parentId'
            );
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
        self::assertSame('input.parentId', $extensions['field']);
    }

    public function testOwnershipIsAskedBeforeScope(): void
    {
        // Spec section 8.1: a stranger with no scope learns NOT_FOUND, never
        // FORBIDDEN, because FORBIDDEN would confirm the object exists.
        $guard = $this->guard();

        list($code) = $this->codeOf(function () use ($guard) {
            $guard->target(
                $this->identity(8, 'user', 5, array(Scope::MAIL_READ)),
                GlobalId::encode(NodeType::SUBDOMAIN, 3),
                array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
            );
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
        self::assertNotEmpty($this->queries, 'ownership was resolved');
    }

    public function testTheOwnerWithoutTheScopeGetsForbidden(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            $this->guard()->target(
                $this->identity(7, 'user', 5, array(Scope::DOMAINS_READ)),
                GlobalId::encode(NodeType::SUBDOMAIN, 3),
                array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
            );
        });

        self::assertSame(ErrorCode::FORBIDDEN, $code);
        self::assertSame(Scope::DOMAINS_WRITE, $extensions['scope']);
    }

    /**
     * @dataProvider unparseable
     */
    public function testAnIdentifierThatIsNotOneOfTheTagsIsNotFound(string $encoded): void
    {
        list($code) = $this->codeOf(function () use ($encoded) {
            Guard::parse($encoded, array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN));
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
    }

    public function unparseable(): array
    {
        return array(
            'not base64'              => array('!!!'),
            'the wrong kind'          => array(GlobalId::encode(NodeType::MAIL_ACCOUNT, 3)),
            'a non-numeric int key'   => array(GlobalId::encodeKey(NodeType::SUBDOMAIN, 'abc')),
            'an unknown tag'          => array(GlobalId::encodeKey('Nonsense', '3')),
            'empty'                   => array('')
        );
    }

    public function testAStringKeyedTagParses(): void
    {
        $id = Guard::parse(GlobalId::encodeKey(NodeType::FTP_USER, 'a@b.test'), array(NodeType::FTP_USER));

        self::assertSame('a@b.test', $id->getKey());
    }

    public function testATargetOfAStringKeyedTagKeepsTheStringKey(): void
    {
        $target = $this->guard()->target(
            $this->identity(7, 'user', 5),
            GlobalId::encodeKey(NodeType::FTP_USER, 'a@b.test'),
            array(NodeType::FTP_USER), Scope::FTP_WRITE, 'id'
        );

        self::assertSame('a@b.test', $target->getKey());
    }

    public function testANonStringIdentifierIsNotFound(): void
    {
        // GraphQL's ID is always a string by the time it reaches a resolver,
        // but a service called from PHP could be handed anything.
        list($code) = $this->codeOf(function () {
            $this->guard()->target(
                $this->identity(7, 'user', 5), null, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
            );
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireFeature(false, 'subdomains');
        });

        self::assertSame(ErrorCode::FEATURE_UNAVAILABLE, $code);
        self::assertSame('subdomains', $extensions['feature']);

        Guard::requireFeature(true, 'subdomains');
    }

    public function testAPendingObjectIsAConflictWithARetryHint(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireState('tochange', array('OK'));
        });

        self::assertSame(ErrorCode::CONFLICT, $code);
        self::assertSame(Guard::RETRY_AFTER_SECONDS, $extensions['retryAfterSeconds']);
        self::assertSame('PENDING', $extensions['state']);
    }

    public function testASettledObjectInTheWrongStateIsForbiddenWithoutARetryHint(): void
    {
        // Retrying cannot help: a disabled account stays disabled until a
        // reseller acts, so a retry hint would send a client into a loop.
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireState('disabled', array('OK'));
        });

        self::assertSame(ErrorCode::FORBIDDEN, $code);
        self::assertSame('DISABLED', $extensions['state']);
        self::assertArrayNotHasKey('retryAfterSeconds', $extensions);
    }

    public function testAFailedObjectMayBeDeleted(): void
    {
        // Spec section 8.3's one exception.
        Guard::requireState('Could not create vhost: permission denied', array('OK', 'ERROR'));
        Guard::requireState('ok', array('OK', 'ERROR'));
        $this->addToAssertionCount(2);
    }

    public function testAQuotaThatIsReachedIsLimitExceeded(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireQuota(Quota::fromCustomerLimit(2, 2), 'sqlDatabases');
        });

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $code);
        self::assertSame(2, $extensions['limit']);
        self::assertSame(2, $extensions['used']);
        self::assertSame('sqlDatabases', $extensions['quota']);
    }

    public function testAQuotaOverItsLimitIsAlsoExceeded(): void
    {
        // A reseller can lower a limit below what a customer already has.
        list($code) = $this->codeOf(function () {
            Guard::requireQuota(Quota::fromCustomerLimit(2, 5), 'sqlDatabases');
        });

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $code);
    }

    public function testRoomLeftAndUnlimitedPass(): void
    {
        Guard::requireQuota(Quota::fromCustomerLimit(2, 1), 'x');
        Guard::requireQuota(Quota::fromCustomerLimit(0, 999), 'x');
        $this->addToAssertionCount(2);
    }

    public function testBadInputNamesTheField(): void
    {
        $e = Guard::badInput('input.label', 'Nope.', array('maximum' => 3));

        self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
        self::assertSame(array('field' => 'input.label', 'maximum' => 3), $e->getExtensions());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter GuardTest`
Expected: FAIL — `Error: Class "iMSCP\Plugin\SGW_GraphQL\Security\Guard" not found`.

- [ ] **Step 3: Write `ObjectRef`, `Target` and `Guard`**

Create `Support/ObjectRef.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

/**
 * What a service hands back: which object it wrote, and - when the object no
 * longer exists to be read back - what it looked like.
 *
 * A snapshot is only ever set for an object whose row the mutation removed
 * outright: an SQL database or user (spec section 2.1: synchronous), or an
 * alias order that never reached the backend. Everything else is still there
 * with a to... status, and is read back through the read model.
 */
final class ObjectRef
{
    /** @var string */
    private $tag;

    /** @var int|string */
    private $key;

    /** @var array|null */
    private $snapshot;

    /**
     * @param int|string $key
     * @param array|null $snapshot The row, in the shape the type's read-model
     *                             shape() function takes
     */
    public function __construct(string $tag, $key, ?array $snapshot = null)
    {
        $this->tag = $tag;
        $this->key = $key;
        $this->snapshot = $snapshot;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * @return int|string
     */
    public function getKey()
    {
        return $this->key;
    }

    public function getSnapshot(): ?array
    {
        return $this->snapshot;
    }
}
```

Create `Security/Target.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/** An object the caller has been shown to reach, and whose it is. */
final class Target
{
    /** @var GlobalId */
    private $id;

    /** @var int */
    private $ownerId;

    public function __construct(GlobalId $id, int $ownerId)
    {
        $this->id = $id;
        $this->ownerId = $ownerId;
    }

    public function getId(): GlobalId
    {
        return $this->id;
    }

    public function getTag(): string
    {
        return $this->id->getType();
    }

    /**
     * The integer key, or the string key for a string-keyed tag (FtpUser).
     *
     * @return int|string
     */
    public function getKey()
    {
        return NodeType::isStringKeyed($this->id->getType())
            ? $this->id->getKey()
            : $this->id->getId();
    }

    /** The owning customer's admin_id. */
    public function getOwnerId(): int
    {
        return $this->ownerId;
    }
}
```

Create `Security/Guard.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use InvalidArgumentException;

/**
 * Spec section 8.1, steps 2 to 7: the only place a write is refused.
 *
 *   2. ownership  NOT_FOUND            target()
 *   3. scope      FORBIDDEN            target()
 *   4. feature    FEATURE_UNAVAILABLE  requireFeature()
 *   5. settled    CONFLICT             requireState()
 *   6. input      BAD_USER_INPUT       badInput()
 *   7. quota      LIMIT_EXCEEDED       requireQuota()
 *
 * The order is the point, and it is the service's to keep: "the error a
 * caller gets leaks the least". A service that validated input before
 * resolving ownership would let a stranger learn which names are taken by
 * reading which validation error comes back.
 */
final class Guard
{
    /**
     * Spec section 8.3's suggested wait. Typical settle time on the reference
     * box is a few seconds (spec section 8.2).
     */
    const RETRY_AFTER_SECONDS = 5;

    /** @var OwnershipResolver */
    private $ownership;

    public function __construct(OwnershipResolver $ownership)
    {
        $this->ownership = $ownership;
    }

    /**
     * Steps 2 and 3, in that order.
     *
     * @param mixed    $encodedId The identifier as the caller sent it
     * @param string[] $tags      The NodeType tags this argument may name
     * @param string   $field     Where in the input it came from, for the
     *                            client: 'id', 'input.parentId', ...
     * @throws ApiException NOT_FOUND, then FORBIDDEN
     */
    public function target(Identity $caller, $encodedId, array $tags, string $scope, string $field): Target
    {
        try {
            $id = self::parse(is_string($encodedId) ? $encodedId : '', $tags);
            $ownerId = $this->ownership->assertReachable($caller, $id);
        } catch (ApiException $e) {
            // The field is added, not the reason: which argument was not found
            // tells a client which identifier to look at, and nothing about
            // whether the object exists.
            throw new ApiException(ErrorCode::NOT_FOUND, $e->getMessage(), array('field' => $field));
        }

        self::requireScope($caller, $scope);

        return new Target($id, $ownerId);
    }

    /**
     * An identifier of one of $tags, or NOT_FOUND.
     *
     * Malformed, the wrong kind, and a non-numeric key for an integer-keyed
     * kind are all NOT_FOUND: spec section 6.3 wants every way of naming
     * nothing to be indistinguishable. QueryResolver::decode() delegates here.
     *
     * @param string[] $tags
     * @throws ApiException NOT_FOUND
     */
    public static function parse(string $encoded, array $tags): GlobalId
    {
        try {
            $id = GlobalId::decodeKey($encoded);
        } catch (\Exception $e) {
            throw self::notFound();
        }

        if (!NodeType::isKnown($id->getType()) || !in_array($id->getType(), $tags, true)) {
            throw self::notFound();
        }

        if (!NodeType::isStringKeyed($id->getType())) {
            try {
                $id->getId();
            } catch (InvalidArgumentException $e) {
                throw self::notFound();
            }
        }

        return $id;
    }

    /**
     * @throws ApiException FORBIDDEN
     */
    public static function requireScope(Identity $caller, string $scope): void
    {
        if (!$caller->hasScope($scope)) {
            throw self::forbidden(
                'This credential does not carry the scope this mutation needs.',
                array('scope' => $scope)
            );
        }
    }

    /**
     * Step 4. Always asked of the owning customer's account, never the
     * caller's: a reseller's own allowances are irrelevant to what one of
     * their customers may have.
     *
     * @throws ApiException FEATURE_UNAVAILABLE
     */
    public static function requireFeature(bool $available, string $feature): void
    {
        if (!$available) {
            throw new ApiException(
                ErrorCode::FEATURE_UNAVAILABLE,
                'This feature is not available to the account.',
                array('feature' => $feature)
            );
        }
    }

    /**
     * Step 5, spec section 8.3.
     *
     * Pending is CONFLICT with a retry hint: the backend has not read the
     * object's last instruction, and a second write would overwrite it. Any
     * other state the operation does not accept - disabled, ordered, failed
     * for anything but a delete - is FORBIDDEN, because waiting will not
     * change it.
     *
     * @param string[] $allowedStates Provisioning::STATE_* values
     * @throws ApiException CONFLICT or FORBIDDEN
     */
    public static function requireState(string $status, array $allowedStates): void
    {
        $state = Provisioning::fromStatus($status)->getState();

        if (in_array($state, $allowedStates, true)) {
            return;
        }

        if ($state === Provisioning::STATE_PENDING) {
            throw new ApiException(
                ErrorCode::CONFLICT,
                'The backend has not finished with this object yet.',
                array('state' => $state, 'retryAfterSeconds' => self::RETRY_AFTER_SECONDS)
            );
        }

        throw self::forbidden('The object is not in a state this operation accepts.', array('state' => $state));
    }

    /**
     * Step 7, for creates.
     *
     * @throws ApiException LIMIT_EXCEEDED
     */
    public static function requireQuota(Quota $quota, string $name): void
    {
        if ($quota->isEnabled() && $quota->getLimit() === null) {
            return;
        }

        if ($quota->isEnabled() && $quota->getUsed() < $quota->getLimit()) {
            return;
        }

        throw new ApiException(
            ErrorCode::LIMIT_EXCEEDED,
            'The account has reached this limit.',
            array('quota' => $name, 'limit' => (int)$quota->getLimit(), 'used' => $quota->getUsed())
        );
    }

    /** Step 6. */
    public static function badInput(string $field, string $message, array $extra = array()): ApiException
    {
        return new ApiException(ErrorCode::BAD_USER_INPUT, $message, array_merge(array('field' => $field), $extra));
    }

    /** A uniqueness clash (spec section 8.4). */
    public static function conflict(string $message): ApiException
    {
        return new ApiException(ErrorCode::CONFLICT, $message);
    }

    public static function forbidden(string $message, array $extra = array()): ApiException
    {
        return new ApiException(ErrorCode::FORBIDDEN, $message, $extra);
    }

    public static function notFound(): ApiException
    {
        return new ApiException(ErrorCode::NOT_FOUND, 'No such object, or it is not yours to read.');
    }
}
```

- [ ] **Step 4: Run the Guard test**

Run: `tools/test.sh --filter GuardTest`
Expected: PASS, 20 tests (16 methods, one of them over five rows).

- [ ] **Step 5: Let `QueryResolver` use the same parser**

In `Resolver/QueryResolver.php`, add `use iMSCP\Plugin\SGW_GraphQL\Security\Guard;` to the imports and replace the whole of `private static function decode(...)` with:

```php
    private static function decode(string $encoded, ?string $expected = null): GlobalId
    {
        // One parser for reads and writes, so that the two cannot come to
        // disagree about what counts as naming nothing. See Guard::parse().
        return Guard::parse(
            $encoded,
            $expected !== null
                ? array($expected)
                : array_merge(NodeType::CUSTOMER_OWNED, NodeType::RESELLER_OWNED, NodeType::SERVER_OWNED)
        );
    }
```

Delete `private static function notFound()` from `QueryResolver` only if nothing else in the class calls it: run `grep -n "self::notFound" Resolver/QueryResolver.php` and keep the method if the grep finds a caller.

Run: `tools/test.sh --filter QueryResolverTest`
Expected: PASS — unchanged behaviour.

- [ ] **Step 6: Write the failing Writer test**

Create `test/unit/Service/WriterTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WriterTest extends TestCase
{
    private function pdoException(int $driverCode): PDOException
    {
        $e = new PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $e->errorInfo = array('23000', $driverCode, 'detail');

        return $e;
    }

    public function testTheWorkIsCommittedAndItsResultReturned(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::once())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        self::assertSame(42, (new Writer(new Db($pdo)))->run(static function () {
            return 42;
        }));
    }

    public function testAFailureRollsBackAndIsRethrown(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack');

        $this->expectException(RuntimeException::class);

        (new Writer(new Db($pdo)))->run(static function () {
            throw new RuntimeException('boom');
        });
    }

    public function testADuplicateKeyIsAConflict(): void
    {
        // Spec section 8.4: a retried create fails with CONFLICT.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $duplicate = $this->pdoException(1062);

        try {
            (new Writer(new Db($pdo)))->run(static function () use ($duplicate) {
                throw $duplicate;
            });
            self::fail('expected CONFLICT');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::CONFLICT, $e->getErrorCode());
            self::assertSame($duplicate, $e->getPrevious());
        }
    }

    public function testADuplicateWrappedByTheCoreIsStillAConflict(): void
    {
        // exec_query() wraps PDOException in DatabaseException, with the
        // PDOException as its previous. createDefaultMailAccounts() is where
        // that wrapping reaches a service.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $wrapped = new RuntimeException('wrapped', 23000, $this->pdoException(1062));

        $this->expectException(ApiException::class);

        (new Writer(new Db($pdo)))->run(static function () use ($wrapped) {
            throw $wrapped;
        });
    }

    public function testANotNullViolationIsNotAConflict(): void
    {
        // Measurement M8: 1048 shares SQLSTATE 23000 with 1062, and the panel
        // reads it as "already exists". It is a bug, not a clash, and must
        // surface as one.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $notNull = $this->pdoException(1048);

        try {
            (new Writer(new Db($pdo)))->run(static function () use ($notNull) {
                throw $notNull;
            });
            self::fail('expected the PDOException');
        } catch (PDOException $e) {
            self::assertSame($notNull, $e);
        }
    }
}
```

- [ ] **Step 7: Write `Writer`**

Create `Service/Writer.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use PDOException;
use Throwable;

/**
 * Spec section 8.1 step 8: one transaction per mutation.
 *
 * The caller does steps 9 and 10 - send_request() and write_log() - after
 * run() returns, which is after the commit. That is deliberately not folded
 * in here: a daemon that read the rows mid-transaction would see nothing, and
 * keeping the two calls visible in each service keeps that ordering
 * reviewable where the rows are written.
 */
final class Writer
{
    /** MariaDB's ER_DUP_ENTRY. */
    const DUPLICATE_KEY = 1062;

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @param callable $work fn(): mixed
     * @return mixed What $work returned
     * @throws ApiException CONFLICT on a duplicate key
     */
    public function run(callable $work)
    {
        $this->db->beginTransaction();

        try {
            $result = $work();
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();

            if (self::isDuplicate($e)) {
                throw new ApiException(
                    ErrorCode::CONFLICT, 'An object with that name already exists.', array(), $e
                );
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Whether a uniqueness clash is anywhere in the chain. Only 1062: a 1048
     * shares SQLSTATE 23000 and is a bug (measurement M8).
     */
    public static function isDuplicate(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && isset($current->errorInfo[1])
                && (int)$current->errorInfo[1] === self::DUPLICATE_KEY
            ) {
                return true;
            }
        }

        return false;
    }
}
```

Run: `tools/test.sh --filter WriterTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Write the failing Accounts test**

Create `test/integration/AccountsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;

class AccountsTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    public function testACustomerIsReadWithTheirDomain(): void
    {
        $account = (new Accounts($this->db))->customer($this->fixture->customerId());

        self::assertSame($this->fixture->customerId(), $account->getAdminId());
        self::assertSame('sgwtcustomer', $account->getUsername());
        self::assertSame($this->fixture->resellerId(), $account->getResellerId());
        self::assertSame('sgwtcustomer@example.test', $account->getEmail());
        self::assertSame($this->fixture->domainId(), $account->getDomainId());
        self::assertSame($this->fixture->domainName(), $account->getDomainName());
        self::assertSame($this->fixture->ipId(), $account->getDomainIpId());
        self::assertSame('10', (string)$account->domain('domain_subd_limit'));
    }

    public function testItIsReadFreshEveryTime(): void
    {
        // get_domain_default_props() caches for the request (M6); a service
        // that changed a limit and read it back would see the old one.
        $accounts = new Accounts($this->db);
        $accounts->customer($this->fixture->customerId());

        $this->db->execute(
            'UPDATE domain SET domain_subd_limit = 3 WHERE domain_id = ?',
            array($this->fixture->domainId())
        );

        self::assertSame('3', (string)$accounts->customer($this->fixture->customerId())->domain('domain_subd_limit'));
    }

    public function testAResellerIsNotACustomer(): void
    {
        try {
            (new Accounts($this->db))->customer($this->fixture->resellerId());
            self::fail('expected NOT_FOUND');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAnUnknownDomainColumnIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Accounts($this->db))->customer($this->fixture->customerId())->domain('no_such_column');
    }

    public function testTheToolkitReadsAVhostFresh(): void
    {
        $kit = new Toolkit(
            $this->db, new PanelCore(false),
            new Guard(new OwnershipResolver(function (string $sql, array $bind = array()) {
                return $this->db->rows($sql, $bind);
            })),
            new Accounts($this->db), new VirtualHosts($this->db), new Counts($this->db, true),
            new Writer($this->db), new FakeDirectoryProbe()
        );

        $row = $kit->vhost(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId());

        self::assertSame('toadd', $row['status']);
        self::assertSame('blog.' . $this->fixture->aliasName(), $row['name']);

        try {
            $kit->vhost(NodeType::SUBDOMAIN, 999999999);
            self::fail('expected NOT_FOUND');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }
}
```

- [ ] **Step 9: Write `CustomerAccount`, `Accounts` and `Toolkit`**

Create `Service/CustomerAccount.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use InvalidArgumentException;

/**
 * The customer a write is for: their admin row's identity columns and their
 * whole `domain` row, read once per service call.
 *
 * Not the caller. A reseller creating a subdomain for a customer is checked
 * against the customer's limits, hashed into the customer's FTP group, and
 * logged as the reseller.
 */
final class CustomerAccount
{
    /** @var array<string, mixed> */
    private $admin;

    /** @var array<string, mixed> */
    private $domain;

    /**
     * @param array<string, mixed> $admin  admin_id, admin_name, created_by,
     *                                     email, admin_sys_uid, admin_sys_gid
     * @param array<string, mixed> $domain A whole row of `domain`
     */
    public function __construct(array $admin, array $domain)
    {
        $this->admin = $admin;
        $this->domain = $domain;
    }

    public function getAdminId(): int
    {
        return (int)$this->admin['admin_id'];
    }

    /** admin.admin_name: the customer's login, and their FTP group's name. */
    public function getUsername(): string
    {
        return (string)$this->admin['admin_name'];
    }

    /** admin.created_by, or 0 for an account no reseller created (spec section 7.5). */
    public function getResellerId(): int
    {
        return (int)($this->admin['created_by'] ?? 0);
    }

    public function getEmail(): string
    {
        return (string)($this->admin['email'] ?? '');
    }

    public function getSysUid(): int
    {
        return (int)$this->admin['admin_sys_uid'];
    }

    public function getSysGid(): int
    {
        return (int)$this->admin['admin_sys_gid'];
    }

    public function getDomainId(): int
    {
        return (int)$this->domain['domain_id'];
    }

    /** ASCII, as stored. */
    public function getDomainName(): string
    {
        return (string)$this->domain['domain_name'];
    }

    public function getDomainIpId(): int
    {
        return (int)$this->domain['domain_ip_id'];
    }

    /**
     * One column of the customer's `domain` row.
     *
     * @return mixed
     * @throws InvalidArgumentException for a column the row does not have, so
     *                                  a typo is loud rather than null
     */
    public function domain(string $column)
    {
        if (!array_key_exists($column, $this->domain)) {
            throw new InvalidArgumentException(sprintf('The domain row has no column "%s".', $column));
        }

        return $this->domain[$column];
    }

    /** @return array<string, mixed> */
    public function getDomainRow(): array
    {
        return $this->domain;
    }
}
```

Create `Repository/Accounts.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

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

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Service\CustomerAccount;

/**
 * The owning customer, read fresh.
 *
 * CORE-DEBT(C1): stands in for get_domain_default_props()
 *   (gui/include/Shared.php:283), which caches its row in a static for the
 *   whole request with no key (measurement M6). Retire when C1 gives it an
 *   explicit $adminId and a keyed cache.
 */
final class Accounts
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND when the
     *         id is not a customer with a domain. Ownership has already been
     *         resolved by the time a service asks, so this only happens when
     *         the account vanished in between - and NOT_FOUND is still true.
     */
    public function customer(int $adminId): CustomerAccount
    {
        // The admin columns are listed rather than a.*: `admin` and `domain`
        // both carry domain_created, and d.* must win for it.
        $row = $this->db->row(
            "
                SELECT a.admin_id, a.admin_name, a.created_by, a.email,
                    a.admin_sys_uid, a.admin_sys_gid, d.*
                FROM admin AS a
                JOIN domain AS d ON d.domain_admin_id = a.admin_id
                WHERE a.admin_id = ? AND a.admin_type = 'user'
            ",
            array($adminId)
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        $admin = array();

        foreach (array('admin_id', 'admin_name', 'created_by', 'email', 'admin_sys_uid', 'admin_sys_gid') as $column) {
            $admin[$column] = $row[$column];
            unset($row[$column]);
        }

        return new CustomerAccount($admin, $row);
    }
}
```

Create `Service/Toolkit.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;

/**
 * The fixed set of collaborators every service takes.
 *
 * A bundle rather than eight constructor parameters per service: the set is
 * the same for all of them, and a service that needs something outside it
 * (SqlService's SqlServer) takes that as a second argument, so the exception
 * is visible in the constructor.
 */
final class Toolkit
{
    /** @var Db */
    private $db;
    /** @var Core */
    private $core;
    /** @var Guard */
    private $guard;
    /** @var Accounts */
    private $accounts;
    /** @var VirtualHosts */
    private $vhosts;
    /** @var Counts */
    private $counts;
    /** @var Writer */
    private $writer;
    /** @var DirectoryProbe */
    private $probe;

    public function __construct(
        Db $db, Core $core, Guard $guard, Accounts $accounts, VirtualHosts $vhosts,
        Counts $counts, Writer $writer, DirectoryProbe $probe
    ) {
        $this->db = $db;
        $this->core = $core;
        $this->guard = $guard;
        $this->accounts = $accounts;
        $this->vhosts = $vhosts;
        $this->counts = $counts;
        $this->writer = $writer;
        $this->probe = $probe;
    }

    public function db(): Db { return $this->db; }
    public function core(): Core { return $this->core; }
    public function guard(): Guard { return $this->guard; }
    public function accounts(): Accounts { return $this->accounts; }
    public function vhosts(): VirtualHosts { return $this->vhosts; }
    public function counts(): Counts { return $this->counts; }
    public function writer(): Writer { return $this->writer; }
    public function probe(): DirectoryProbe { return $this->probe; }

    /**
     * One vhost's normalised row, read now rather than from the batch loader:
     * a write decides on the state the row is in at this moment.
     *
     * @param int|string $key
     * @return array<string, mixed> See VirtualHosts::normalise()
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND
     */
    public function vhost(string $tag, $key): array
    {
        $kind = VirtualHosts::kindFor($tag);
        $rows = $this->vhosts->byKeys(array(array($kind, (int)$key)));

        if (!isset($rows[$kind . ':' . (int)$key])) {
            throw Guard::notFound();
        }

        return $rows[$kind . ':' . (int)$key];
    }

    /**
     * The panel configuration keys a Support\ class wants as an array.
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function panelConfig(array $keys): array
    {
        $config = array();

        foreach ($keys as $key) {
            $config[$key] = $this->core->config($key);
        }

        return $config;
    }
}
```

- [ ] **Step 10: Run the integration test**

Run: `tools/test.sh --filter AccountsTest`
Expected: PASS, 5 tests.

- [ ] **Step 11: Give the container its ports**

In `Api/Container.php`:

1. Add imports:

```php
use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Service\Core;
use iMSCP\Plugin\SGW_GraphQL\Service\DirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\SqlServer;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Service\UncheckedDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\VfsDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
```

2. Add properties after `$maps`:

```php
    /** @var Core */
    private $core;

    /** @var DirectoryProbe */
    private $probe;

    /** @var SqlServer|null Null until Task 12 wires MariaDbSqlServer. */
    private $sqlServer;

    /** @var Toolkit|null */
    private $toolkit;
```

3. Replace the private constructor with:

```php
    private function __construct(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, Db $db, array $panelConfig,
        bool $apiAccessByDefault, Core $core, DirectoryProbe $probe, ?SqlServer $sqlServer
    ) {
        $this->pluginDir = $pluginDir;
        $this->config = $config;
        $this->query = $query;
        $this->accountLoader = $accountLoader;
        $this->apiAccessChecker = $apiAccessChecker;
        $this->db = $db;
        $this->panelConfig = $panelConfig;
        $this->apiAccessByDefault = $apiAccessByDefault;
        $this->core = $core;
        $this->probe = $probe;
        $this->sqlServer = $sqlServer;
    }
```

4. In `fromPlugin()`, replace the `return new self(` call's last three arguments

```php
            Db::fromPanel(),
            self::panelConfig(),
            $allowedByDefault
        );
```

with

```php
            Db::fromPanel(),
            self::panelConfig(),
            $allowedByDefault,
            new PanelCore(true),
            // Spec section 14, decision D20.
            (bool)$plugin->getConfigParam('validate_ftp_home_dir', true)
                ? new VfsDirectoryProbe()
                : new UncheckedDirectoryProbe(),
            null
        );
```

5. Replace `forTesting()` with:

```php
    /**
     * @param callable $apiAccessChecker fn(int $adminId): bool. Required, not
     *                                   defaulted: a production factory that
     *                                   silently grants access when nobody
     *                                   asked it to is exactly the fail-open
     *                                   shape this plugin keeps having to
     *                                   close. A test that does not care about
     *                                   the access check must say so itself,
     *                                   at the call site.
     * @param Core|null           $core      Defaults to a PanelCore that never
     *                                       pokes the daemon. Constructing one
     *                                       touches nothing, so the unit suite
     *                                       can build the whole map.
     * @param DirectoryProbe|null $probe     Defaults to answering "exists".
     * @param SqlServer|null      $sqlServer Defaults to none; a test that runs
     *                                       an SQL mutation must pass a fake.
     */
    public static function forTesting(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, ?Db $db = null, array $panelConfig = array(),
        ?Core $core = null, ?DirectoryProbe $probe = null, ?SqlServer $sqlServer = null
    ): self {
        return new self(
            $pluginDir, $config, $query, $accountLoader, $apiAccessChecker,
            // A handle that throws on use rather than one that is null: the
            // unit suite builds the whole resolver map, and a resolver that
            // reached the database there should fail loudly rather than
            // silently work against whatever connection was lying about.
            $db ?? Db::detached(),
            $panelConfig,
            true,
            $core ?? new PanelCore(false),
            $probe ?? new UncheckedDirectoryProbe(),
            $sqlServer
        );
    }
```

6. Add after `tokens()`:

```php
    /**
     * The collaborators every write service takes, built once per request.
     *
     * Its OwnershipResolver, VirtualHosts and Counts are its own rather than
     * the read resolvers': a write must decide on the database as it is now,
     * and sharing the read side's memoised instances would let a mutation
     * decide on what an earlier field of the same document happened to load.
     */
    public function toolkit(): Toolkit
    {
        if ($this->toolkit === null) {
            $db = $this->db;

            $this->toolkit = new Toolkit(
                $db,
                $this->core,
                new Guard(new OwnershipResolver(static function (string $sql, array $bind = array()) use ($db) {
                    return $db->rows($sql, $bind);
                })),
                new Accounts($db),
                new VirtualHosts($db),
                new Counts($db, self::countsDefaultMailAccounts($this->panelConfig)),
                new Writer($db),
                $this->probe
            );
        }

        return $this->toolkit;
    }

    public function sqlServer(): ?SqlServer
    {
        return $this->sqlServer;
    }
```

- [ ] **Step 12: Assert the wiring**

Append to `test/unit/Api/ContainerTest.php`:

```php
    public function testTheToolkitCarriesTheInjectedPorts(): void
    {
        $core = new \iMSCP\Plugin\SGW_GraphQL\Service\PanelCore(false);
        $probe = new \iMSCP\Plugin\SGW_GraphQL\Service\UncheckedDirectoryProbe();

        $container = Container::forTesting(
            dirname(__DIR__, 3), array(),
            function (string $sql, array $bind = []) { return null; },
            function (int $adminId) { return null; },
            function (int $adminId) { return true; },
            null, array(), $core, $probe
        );

        self::assertSame($core, $container->toolkit()->core());
        self::assertSame($probe, $container->toolkit()->probe());
        self::assertSame($container->toolkit(), $container->toolkit(), 'built once');
        self::assertNull($container->sqlServer());
    }
```

- [ ] **Step 13: Run everything**

Run: `tools/test.sh`
Expected: all PASS. `CoreCallsTest` still passes: nothing added here calls a global panel function.

- [ ] **Step 14: Commit**

```bash
git add Support/ObjectRef.php Security/Target.php Security/Guard.php Service/CustomerAccount.php Service/Writer.php Service/Toolkit.php Repository/Accounts.php Resolver/QueryResolver.php Api/Container.php test/unit/Security/GuardTest.php test/unit/Service/WriterTest.php test/integration/AccountsTest.php test/unit/Api/ContainerTest.php
git commit -m "Put the six refusals of every write in one class

Spec 8.1 orders a write's checks so the error a caller gets leaks the least:
ownership before scope, both before feature, state, input and quota. Guard
is now the only place any of them is raised, and its tests pin the order
that matters most - a stranger without the scope still sees NOT_FOUND.

A pending object is CONFLICT with a retry hint, as 8.3 says; a settled one
in a state the operation does not accept is FORBIDDEN without one, because
a disabled account does not become enabled by waiting. Writer turns only a
1062 into CONFLICT: the panel reads every SQLSTATE 23000 as 'already
exists', and 1048 shares it.

The owning customer is read fresh by Accounts rather than through
get_domain_default_props(), which caches one row for the whole request."
```

---

## Checkpoint A: `code-review medium` over Wave 1

Run by the orchestrating session, as the [Execution mandate](#waves-and-checkpoints) sets out.

**Focus:**

- `CoreCallsTest`: can a global call slip past the tokeniser — a namespaced fallback call, a callable string, `call_user_func('name')`, a function imported with `use function`? Is `ALLOWED` any wider than `PanelCore` needs?
- `Guard::target()`: is ownership always resolved before scope, and does every refusal before ownership come out `NOT_FOUND`? Does `parse()` agree with `QueryResolver::decode()` on every malformed input?
- `Writer::run()`: a commit that throws, a rollback that throws, a duplicate hidden two exceptions deep.
- `Db`'s own counter against `DatabaseMySQL`'s: can they disagree about depth? Does `Fixture::rollBack()` leave the panel's counter consistent for the next test?
- `Container::forTesting()`: does any default fail open — a probe that says yes where production would ask, a core that pokes the daemon?

- [ ] **Step 1: The suite, twice**

Run: `tools/test.sh`, then again.
Expected: both green.

- [ ] **Step 2: Review**

Invoke the `code-review` skill with arguments `medium phase3-wave-1..HEAD`, passing the **Focus** list above.

- [ ] **Step 3: Resolve the findings**

Fix each finding that holds, in commits whose subjects begin `Fix checkpoint A:`, then run `tools/test.sh` until green.

- [ ] **Step 4: Open the next wave**

Run: `git tag -a phase3-wave-2 -m "Checkpoint A: <declined findings and why, or: none declined>"`

---

## Wave 2: The matrix and the rules

Tasks 4–5. Test infrastructure and pure functions. No checkpoint of its own: Checkpoint B reviews it with Wave 3, when the matrix's first rows run.

---

## Task 4: The authorisation matrix, before any mutation exists — `sonnet`

Spec §17: *"A matrix: for every mutation, and for each of {owner, another customer of the same reseller, another reseller's customer, the owning reseller, another reseller, administrator}, assert the exact outcome. This is the test that matters most, and it is written before the resolvers it tests."*

This task writes all 24 rows × 6 actors now. A row whose mutation is not yet in the schema is **skipped**, not failed, so the suite is green throughout; each later task that adds mutations to the SDL turns its rows on, and Task 18 adds the assertion that nothing is left skipped. The matrix drives complete GraphQL documents through the real schema, the real services and the real validators, so it tests the wiring as well as the rule.

It also carries spec §17's other clause — *"no core helper is reached in a state that would let it exit"* — dynamically: every row runs through `PanelCore`'s real validators, so a helper that exited would end the PHPUnit process mid-suite, which `tools/test.sh` reports as a failure. `CoreCallsTest` (Task 2) is the static half.

**Files:**
- Create: `test/authz/AuthzTestCase.php`, `test/authz/MutationCatalogue.php`
- Create: `test/authz/AuthorisationMatrixTest.php`, `test/authz/ScopeMatrixTest.php`, `test/authz/CatalogueCoverageTest.php`
- Modify: `test/phpunit.xml`, `tools/test.sh`, `composer.json`

**Interfaces:**
- Consumes: `Api\Container::forTesting()` with its Task 3 parameters; `Test\Double\RecordingCore`, `FakeDirectoryProbe`, `FakeSqlServer` (Task 2); `Test\Integration\Fixture`, `IntegrationTestCase`; `Support\ErrorFactory::format()`; `Schema\SchemaFactory`, `Schema\ResolverMap`, `Resolver\TypeResolver::resolveType`.
- Produces: `MutationCatalogue::all()` and `AuthzTestCase` as listed in [Interfaces this plan creates](#interfaces-this-plan-creates). Tasks 8, 10, 11, 13 and 15 change nothing here; they make rows run. Task 18 appends one test to `CatalogueCoverageTest`.

- [ ] **Step 1: Register the suite**

In `composer.json`, replace the `autoload-dev` block with:

```json
    "autoload-dev": {
        "psr-4": {
            "iMSCP\\Plugin\\SGW_GraphQL\\Test\\Integration\\": "test/integration",
            "iMSCP\\Plugin\\SGW_GraphQL\\Test\\Authz\\": "test/authz",
            "iMSCP\\Plugin\\SGW_GraphQL\\Test\\": "test/"
        }
    }
```

Regenerate the autoloader in the container:

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 /var/www/imscp/gui/bin/composer.phar dump-autoload'`
Expected: `Generated optimized autoload files`.

In `test/phpunit.xml`, add after the `integration` suite:

```xml
        <!--
            Spec section 17's authorisation matrix. Needs the panel and a
            database, like the integration suite, and runs in its process.
        -->
        <testsuite name="authz">
            <directory>authz</directory>
        </testsuite>
```

In `tools/test.sh`, replace

```sh
            php7.4 vendor/bin/phpunit --configuration test/phpunit.xml --testsuite integration
```

with

```sh
            php7.4 vendor/bin/phpunit --configuration test/phpunit.xml --testsuite integration,authz
```

- [ ] **Step 2: Write the harness**

Create `test/authz/AuthzTestCase.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeSqlServer;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;
use iMSCP\Plugin\SGW_GraphQL\Test\Integration\Fixture;
use iMSCP\Plugin\SGW_GraphQL\Test\Integration\IntegrationTestCase;
use Throwable;

/**
 * Runs whole documents against the real schema, the real services and the
 * seeded fixture, as one of the fixture's six accounts.
 *
 * Only the three side effects a rolled-back test must not perform are
 * replaced (decision D12): the daemon and the log (RecordingCore), the
 * FTP-backed directory check, and the SQL server's DDL.
 */
abstract class AuthzTestCase extends IntegrationTestCase
{
    /** @var Db */
    protected $db;

    /** @var Fixture */
    protected $fixture;

    /** @var RecordingCore */
    protected $core;

    /** @var FakeDirectoryProbe */
    protected $probe;

    /** @var FakeSqlServer */
    protected $sqlServer;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->core = new RecordingCore(new PanelCore(false));
        $this->probe = new FakeDirectoryProbe();
        $this->sqlServer = new FakeSqlServer();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * A fresh container per document, as production builds one per request:
     * the batch loader memoises for the container's life.
     */
    protected function schema(): Schema
    {
        return Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db,
            (array)\iMSCP\Registry::get('config'),
            $this->core,
            $this->probe,
            $this->sqlServer
        )->schemaFactory()->create();
    }

    /**
     * @return array The response envelope, errors formatted as the endpoint
     *               formats them, with debug on so a failing row says why.
     */
    protected function execute(string $document, array $variables, Identity $identity): array
    {
        $result = GraphQL::executeQuery(
            $this->schema(), $document, null, array('identity' => $identity), $variables
        );

        // As Http\GraphQLHandler formats them, path included, so a test
        // reads the same envelope a client does.
        $result->setErrorFormatter(static function (Error $error) {
            $previous = $error->getPrevious();
            $formatted = $previous instanceof Throwable
                ? ErrorFactory::format($previous, true)
                : array('message' => $error->getMessage(), 'extensions' => array('code' => 'GRAPHQL'));

            if ($error->path !== null) {
                $formatted['path'] = $error->path;
            }

            return $formatted;
        });

        return $result->toArray();
    }

    /**
     * 'OK', or the first error's code, or 'NULL' for a null field with no error.
     */
    protected static function outcome(array $result, string $field): string
    {
        if (!empty($result['errors'])) {
            return (string)($result['errors'][0]['extensions']['code'] ?? 'NO_CODE');
        }

        return isset($result['data'][$field]) ? 'OK' : 'NULL';
    }

    protected function skipUnlessInSchema(string $field): void
    {
        $mutation = $this->schema()->getMutationType();

        if ($mutation === null || !$mutation->hasField($field)) {
            self::markTestSkipped($field . ' is not in the schema yet.');
        }
    }

    /**
     * Run one catalogue entry as one account. Named runEntry(), not run():
     * TestCase::run() is PHPUnit's own.
     *
     * @param string[] $scopes
     */
    protected function runEntry(string $field, string $actor, array $scopes = array()): array
    {
        $entry = MutationCatalogue::all()[$field];
        $prepared = call_user_func($entry['prepare'], $this->fixture, $this->db);

        return $this->execute(
            $entry['document'],
            call_user_func($entry['variables'], $this->fixture, $prepared),
            $this->fixture->identity($actor, $scopes)
        );
    }
}
```

- [ ] **Step 3: Write the catalogue**

Create `test/authz/MutationCatalogue.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Integration\Fixture;

/**
 * Every customer-level mutation of spec section 7.11, each with a document
 * that succeeds for the owner against the seeded fixture.
 *
 * Each entry:
 *   scope     the write scope the mutation requires
 *   document  selects only `id`, so the row asserts the mutation and not a
 *             read edge's own scope (decision D19)
 *   prepare   fn(Fixture, Db): array - adjusts the fixture so the owner's
 *             call is valid, and returns anything variables() needs
 *   variables fn(Fixture, array $prepared): array
 */
final class MutationCatalogue
{
    /**
     * @return array<string, array{scope: string, document: string, variables: callable, prepare: callable}>
     */
    public static function all(): array
    {
        $nothing = static function (Fixture $f, Db $db): array {
            return array();
        };

        // The fixture withholds FTP (domain_ftpacc_limit = -1) so that a
        // FEATURE_UNAVAILABLE test has something to find. The matrix is about
        // who may act, so it grants FTP first.
        $grantFtp = static function (Fixture $f, Db $db): array {
            $db->execute('UPDATE domain SET domain_ftpacc_limit = 0 WHERE domain_id = ?', array($f->domainId()));

            return array();
        };

        $byId = static function (string $field): string {
            return 'mutation($id: ID!) { ' . $field . '(id: $id) { id } }';
        };

        $domain = static function (Fixture $f): string {
            return GlobalId::encode(NodeType::DOMAIN, $f->domainId());
        };

        return array(
            'domainUpdate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($id: ID!, $input: DomainUpdateInput!) { domainUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('id' => $domain($f), 'input' => array('wildcard' => true));
                }
            ),
            'subdomainCreate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($input: SubdomainCreateInput!) { subdomainCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array('parentId' => $domain($f), 'label' => 'authz'));
                }
            ),
            'subdomainUpdate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($id: ID!, $input: SubdomainUpdateInput!) { subdomainUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::SUBDOMAIN, $f->subdomainId()),
                        'input' => array('wildcard' => true)
                    );
                }
            ),
            'subdomainDelete' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => $byId('subdomainDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::SUBDOMAIN, $f->subdomainId()));
                }
            ),
            'domainAliasCreate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($input: DomainAliasCreateInput!) { domainAliasCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array('domainId' => $domain($f), 'name' => 'sgwtauthz.test'));
                }
            ),
            'domainAliasUpdate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($id: ID!, $input: DomainAliasUpdateInput!) { domainAliasUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::DOMAIN_ALIAS, $f->aliasId()),
                        'input' => array('wildcard' => true)
                    );
                }
            ),
            'domainAliasDelete' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => $byId('domainAliasDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $f->aliasId()));
                }
            ),
            'mailAccountCreate' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'localPart' => 'authz', 'kind' => 'FORWARD',
                        'forwardTo' => array('someone@example.net')
                    ));
                }
            ),
            'mailAccountUpdate' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($id: ID!, $input: MailAccountUpdateInput!) { mailAccountUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::MAIL_ACCOUNT, $f->mailboxId()),
                        'input' => array('kind' => 'FORWARD', 'forwardTo' => array('someone@example.net'))
                    );
                }
            ),
            'mailAccountDelete' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => $byId('mailAccountDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::MAIL_ACCOUNT, $f->mailboxId()));
                }
            ),
            'mailAutoresponderSet' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($id: ID!, $input: AutoresponderInput!) { mailAutoresponderSet(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::MAIL_ACCOUNT, $f->mailboxId()),
                        'input' => array('enabled' => true, 'message' => 'Away until Monday.')
                    );
                }
            ),
            'mailCatchallCreate' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($input: MailCatchallCreateInput!) { mailCatchallCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'addresses' => array('sales@' . $f->domainName())
                    ));
                }
            ),
            'mailCatchallDelete' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => $byId('mailCatchallDelete'),
                'prepare'   => static function (Fixture $f, Db $db): array {
                    $db->execute(
                        "
                            INSERT INTO mail_users (mail_acc, mail_pass, mail_forward, domain_id, mail_type,
                                sub_id, status, po_active, mail_auto_respond, quota, mail_addr)
                            VALUES (?, '_no_', '_no_', ?, 'normal_catchall', 0, 'ok', 'no', 0, 0, ?)
                        ",
                        array('sales@' . $f->domainName(), $f->domainId(), '@' . $f->domainName())
                    );

                    return array('catchall' => $db->lastInsertId());
                },
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::MAIL_ACCOUNT, $p['catchall']));
                }
            ),
            'ftpUserCreate' => array(
                'scope'     => Scope::FTP_WRITE,
                'document'  => 'mutation($input: FtpUserCreateInput!) { ftpUserCreate(input: $input) { id } }',
                'prepare'   => $grantFtp,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'username' => 'authz', 'password' => 'Authz0Pass'
                    ));
                }
            ),
            'ftpUserUpdate' => array(
                'scope'     => Scope::FTP_WRITE,
                'document'  => 'mutation($id: ID!, $input: FtpUserUpdateInput!) { ftpUserUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $grantFtp,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encodeKey(NodeType::FTP_USER, $f->ftpUserId()),
                        'input' => array('password' => 'Authz0Pass2')
                    );
                }
            ),
            'ftpUserDelete' => array(
                'scope'     => Scope::FTP_WRITE,
                'document'  => $byId('ftpUserDelete'),
                'prepare'   => $grantFtp,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encodeKey(NodeType::FTP_USER, $f->ftpUserId()));
                }
            ),
            'sqlDatabaseCreate' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => 'mutation($input: SqlDatabaseCreateInput!) { sqlDatabaseCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array('domainId' => $domain($f), 'name' => 'sgwt_authz'));
                }
            ),
            'sqlDatabaseDelete' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => $byId('sqlDatabaseDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::SQL_DATABASE, $f->sqlDatabaseId()));
                }
            ),
            'sqlUserCreate' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => 'mutation($input: SqlUserCreateInput!) { sqlUserCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('input' => array(
                        'databaseId' => GlobalId::encode(NodeType::SQL_DATABASE, $f->sqlDatabaseId()),
                        'name' => 'sgwt_authz', 'host' => 'localhost', 'password' => 'Authz0Pass'
                    ));
                }
            ),
            'sqlUserSetPassword' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => 'mutation($id: ID!, $password: Secret!) { sqlUserSetPassword(id: $id, password: $password) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'       => GlobalId::encode(NodeType::SQL_USER, $f->sqlUserId()),
                        'password' => 'Authz0Pass2'
                    );
                }
            ),
            'sqlUserDelete' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => $byId('sqlUserDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::SQL_USER, $f->sqlUserId()));
                }
            ),
            'dnsRecordCreate' => array(
                'scope'     => Scope::DNS_WRITE,
                'document'  => 'mutation($input: DnsRecordCreateInput!) { dnsRecordCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'name' => 'authz', 'type' => 'A',
                        'data' => array('address' => '203.0.113.9')
                    ));
                }
            ),
            'dnsRecordUpdate' => array(
                'scope'     => Scope::DNS_WRITE,
                'document'  => 'mutation($id: ID!, $input: DnsRecordUpdateInput!) { dnsRecordUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::DNS_RECORD, $f->dnsRecordId()),
                        'input' => array('name' => 'mail', 'ttl' => 3600, 'data' => array('address' => '203.0.113.10'))
                    );
                }
            ),
            'dnsRecordDelete' => array(
                'scope'     => Scope::DNS_WRITE,
                'document'  => $byId('dnsRecordDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::DNS_RECORD, $f->dnsRecordId()));
                }
            )
        );
    }
}
```

- [ ] **Step 4: Write the three tests**

Create `test/authz/AuthorisationMatrixTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

/**
 * Spec section 17's matrix: every mutation, as every one of the six accounts.
 *
 * The owner, the owner's reseller and an administrator succeed. Everybody
 * else - a sibling customer of the same reseller, another reseller's
 * customer, another reseller - gets NOT_FOUND, never FORBIDDEN (spec 6.3):
 * the object is not theirs to know about.
 */
class AuthorisationMatrixTest extends AuthzTestCase
{
    const EXPECTED = array(
        'customer'      => 'OK',
        'sibling'       => 'NOT_FOUND',
        'otherCustomer' => 'NOT_FOUND',
        'reseller'      => 'OK',
        'otherReseller' => 'NOT_FOUND',
        'admin'         => 'OK'
    );

    /**
     * @dataProvider cases
     */
    public function testTheOutcome(string $field, string $actor): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, $actor);

        self::assertSame(
            self::EXPECTED[$actor],
            self::outcome($result, $field),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function cases(): array
    {
        $cases = array();

        foreach (array_keys(MutationCatalogue::all()) as $field) {
            foreach (array_keys(self::EXPECTED) as $actor) {
                $cases[$field . ' as ' . $actor] = array($field, $actor);
            }
        }

        return $cases;
    }
}
```

Create `test/authz/ScopeMatrixTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;

/**
 * Spec section 8.1 steps 2 and 3 for every mutation: scope is asked after
 * ownership, and the write scope is all a write needs (decision D19).
 */
class ScopeMatrixTest extends AuthzTestCase
{
    /**
     * @dataProvider fields
     */
    public function testTheOwnerWithoutTheWriteScopeIsForbidden(string $field): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, 'customer', array(Scope::ACCOUNT_READ));

        self::assertSame('FORBIDDEN', self::outcome($result, $field), json_encode($result));
        self::assertSame(
            MutationCatalogue::all()[$field]['scope'],
            $result['errors'][0]['extensions']['scope']
        );
    }

    /**
     * @dataProvider fields
     */
    public function testAStrangerWithoutTheWriteScopeIsStillNotFound(string $field): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, 'otherCustomer', array(Scope::ACCOUNT_READ));

        self::assertSame('NOT_FOUND', self::outcome($result, $field), json_encode($result));
    }

    /**
     * @dataProvider fields
     */
    public function testTheWriteScopeAloneIsEnough(string $field): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, 'customer', array(MutationCatalogue::all()[$field]['scope']));

        self::assertSame('OK', self::outcome($result, $field), json_encode($result));
    }

    public function fields(): array
    {
        $fields = array();

        foreach (array_keys(MutationCatalogue::all()) as $field) {
            $fields[$field] = array($field);
        }

        return $fields;
    }
}
```

Create `test/authz/CatalogueCoverageTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use PHPUnit\Framework\TestCase;

/**
 * The matrix is only as complete as the catalogue. This keeps the two from
 * drifting apart in either direction. No database: it reads the SDL.
 */
class CatalogueCoverageTest extends TestCase
{
    /** Spec section 7.11's customer-level mutations, less D16's two. */
    const CUSTOMER_MUTATIONS = array(
        'dnsRecordCreate', 'dnsRecordDelete', 'dnsRecordUpdate', 'domainAliasCreate',
        'domainAliasDelete', 'domainAliasUpdate', 'domainUpdate', 'ftpUserCreate',
        'ftpUserDelete', 'ftpUserUpdate', 'mailAccountCreate', 'mailAccountDelete',
        'mailAccountUpdate', 'mailAutoresponderSet', 'mailCatchallCreate',
        'mailCatchallDelete', 'sqlDatabaseCreate', 'sqlDatabaseDelete', 'sqlUserCreate',
        'sqlUserDelete', 'sqlUserSetPassword', 'subdomainCreate', 'subdomainDelete',
        'subdomainUpdate'
    );

    public function testTheCatalogueIsExactlyTheCustomerMutations(): void
    {
        $fields = array_keys(MutationCatalogue::all());
        sort($fields);

        self::assertSame(self::CUSTOMER_MUTATIONS, $fields);
    }

    public function testEveryEntryIsWellFormed(): void
    {
        foreach (MutationCatalogue::all() as $field => $entry) {
            self::assertSame(array('scope', 'document', 'prepare', 'variables'), array_keys($entry), $field);
            self::assertStringContainsString($field . '(', $entry['document'], $field);
            self::assertIsCallable($entry['prepare'], $field);
            self::assertIsCallable($entry['variables'], $field);
        }
    }

    public function testEveryMutationInTheSchemaHasAnEntry(): void
    {
        $schema = (new SchemaFactory(
            dirname(__DIR__, 2) . '/schema/schema.graphql', null, new ResolverMap(array()),
            array(TypeResolver::class, 'resolveType')
        ))->create();
        $mutation = $schema->getMutationType();
        $fields = $mutation === null ? array() : array_keys($mutation->getFields());

        self::assertSame(
            array(),
            array_values(array_diff($fields, array_keys(MutationCatalogue::all()))),
            'A mutation reached the schema with no row in the authorisation matrix.'
        );
    }
}
```

`testEveryEntryIsWellFormed` asserts key order: write the catalogue's entries with the keys in the order `scope`, `document`, `prepare`, `variables`, as Step 3 does.

- [ ] **Step 5: Run the suite**

Run: `tools/test.sh --testsuite authz`
Expected: `CatalogueCoverageTest` PASS (3 tests). `AuthorisationMatrixTest` 144 **skipped**, `ScopeMatrixTest` 72 **skipped**, each with "is not in the schema yet". Zero failures, zero errors.

This is the one task in the plan whose tests cannot be seen to fail first: there is nothing yet for them to fail against. Task 8 is where the first rows run, and its Step 2 is where they are first seen to fail.

- [ ] **Step 6: Run everything**

Run: `tools/test.sh`
Expected: all PASS; the second PHPUnit process now reports the authz suite's skips.

- [ ] **Step 7: Commit**

```bash
git add test/authz test/phpunit.xml tools/test.sh composer.json
git commit -m "Write the authorisation matrix before the mutations it tests

Spec 17 asks for the matrix to exist before the resolvers, and it now does:
all 24 customer-level mutations, each as the six accounts the fixture seeds,
plus the scope half of spec 8.1 - an owner without the write scope is
FORBIDDEN, a stranger without it is still NOT_FOUND, and the write scope
alone is enough.

A row whose mutation is not yet in the schema is skipped rather than
failed, so the suite is green while the services arrive one by one; the
coverage test fails if a mutation reaches the schema with no row, and the
last task of the phase fails if any row is still skipped."
```

---
## Task 5: Virtual host rules — `haiku`

The pure half of the three vhost pages: forward types, mount points, the reserved label, document roots, and escaping a name for `LIKE`. No panel, no database; every case is written out.

**Files:**
- Create: `Support/VhostRules.php`
- Create: `test/unit/Support/VhostRulesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces (Tasks 6, 7):
  ```php
  VhostRules::PANEL_FORWARD_TYPES   // array('PERMANENT_301' => '301', 'FOUND_302' => '302', 'SEE_OTHER_303' => '303', 'TEMPORARY_307' => '307', 'PROXY' => 'proxy')
  VhostRules::HTDOCS                // '/htdocs'
  VhostRules::panelForwardType(string $forwardType): string                // InvalidArgumentException
  VhostRules::isReservedLabel(string $label): bool
  VhostRules::subdomainMountPoint(string $parentKind, string $labelAscii, string $parentNameAscii): string
  VhostRules::aliasMountPoint(string $aliasNameAscii): string
  VhostRules::stripWww(string $name): string
  VhostRules::documentRoot(string $input, callable $normalise): ?string    // null when outside /htdocs
  VhostRules::relativeToHtdocs(string $documentRoot): string
  VhostRules::wildcard(bool $on): string                                   // 'yes' | 'no'
  VhostRules::noForwarding(): array                                        // url 'no', type null, host 'Off'
  VhostRules::likeEscape(string $value): string
  ```

- [ ] **Step 1: Write the failing test**

Create `test/unit/Support/VhostRulesTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VhostRulesTest extends TestCase
{
    /**
     * A stand-in for utils_normalizePath() over the inputs these tests use:
     * collapse empty and '.' segments, resolve '..', keep the leading slash.
     */
    private function normalise(): callable
    {
        return static function (string $path): string {
            $segments = array();

            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }

                if ($segment === '..') {
                    array_pop($segments);
                    continue;
                }

                $segments[] = $segment;
            }

            return '/' . implode('/', $segments);
        };
    }

    public function testEveryForwardTypeMapsToThePanelsValue(): void
    {
        self::assertSame('301', VhostRules::panelForwardType('PERMANENT_301'));
        self::assertSame('302', VhostRules::panelForwardType('FOUND_302'));
        self::assertSame('303', VhostRules::panelForwardType('SEE_OTHER_303'));
        self::assertSame('307', VhostRules::panelForwardType('TEMPORARY_307'));
        self::assertSame('proxy', VhostRules::panelForwardType('PROXY'));
    }

    public function testTheMappingIsTheReadModelsInverse(): void
    {
        // VirtualHostResolver::FORWARD_TYPES reads the column; this writes it.
        // One is the other turned round, or a round trip changes the value.
        self::assertSame(
            \iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver::FORWARD_TYPES,
            array_flip(VhostRules::PANEL_FORWARD_TYPES)
        );
    }

    public function testAnUnknownForwardTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VhostRules::panelForwardType('301');
    }

    /**
     * @dataProvider labels
     */
    public function testTheReservedLabel(string $label, bool $reserved): void
    {
        self::assertSame($reserved, VhostRules::isReservedLabel($label));
    }

    public function labels(): array
    {
        return array(
            'www'             => array('www', true),
            'www.anything'    => array('www.shop', true),
            'www2'            => array('www2', false),
            'contains www'    => array('shopwww', false),
            'an ordinary one' => array('shop', false)
        );
    }

    /**
     * @dataProvider mountPoints
     */
    public function testSubdomainMountPoints(string $kind, string $label, string $parent, string $expected): void
    {
        self::assertSame($expected, VhostRules::subdomainMountPoint($kind, $label, $parent));
    }

    public function mountPoints(): array
    {
        // gui/public/client/subdomain_add.php:273-288.
        return array(
            'of the main domain'                 => array('dmn', 'shop', 'example.test', '/shop'),
            'a reserved directory, main domain'  => array('dmn', 'logs', 'example.test', '/sub_logs'),
            'backups'                            => array('dmn', 'backups', 'example.test', '/sub_backups'),
            'cgi-bin'                            => array('dmn', 'cgi-bin', 'example.test', '/sub_cgi-bin'),
            'errors'                             => array('dmn', 'errors', 'example.test', '/sub_errors'),
            'phptmp'                             => array('dmn', 'phptmp', 'example.test', '/sub_phptmp'),
            'of an alias'                        => array('als', 'shop', 'alias.test', '/alias.test/shop'),
            'logs is not reserved under an alias' => array('als', 'logs', 'alias.test', '/alias.test/logs'),
            'cgi-bin under an alias'             => array('als', 'cgi-bin', 'alias.test', '/alias.test/sub_cgi-bin')
        );
    }

    public function testASubdomainCannotHangOffASubdomain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VhostRules::subdomainMountPoint('sub', 'x', 'shop.example.test');
    }

    public function testAliasMountPoint(): void
    {
        self::assertSame('/xn--bcher-kva.test', VhostRules::aliasMountPoint('xn--bcher-kva.test'));
    }

    public function testWwwIsStrippedFromAnAliasAsOftenAsItAppears(): void
    {
        // alias_add.php:244: "www is considered as an alias of the domain alias".
        self::assertSame('example.test', VhostRules::stripWww('www.www.example.test'));
        self::assertSame('wwwexample.test', VhostRules::stripWww('wwwexample.test'));
    }

    /**
     * @dataProvider documentRoots
     */
    public function testDocumentRoots(string $input, ?string $expected): void
    {
        self::assertSame($expected, VhostRules::documentRoot($input, $this->normalise()));
    }

    public function documentRoots(): array
    {
        return array(
            'htdocs itself'             => array('/htdocs', '/htdocs'),
            'a directory inside'        => array('/htdocs/public', '/htdocs/public'),
            'untidy but inside'         => array('htdocs//public/./', '/htdocs/public'),
            'dot-dot back inside'       => array('/htdocs/a/../b', '/htdocs/b'),
            'dot-dot out of it'         => array('/htdocs/../etc', null),
            'a sibling of htdocs'       => array('/logs', null),
            'a prefix that is not it'   => array('/htdocsx', null),
            'the root'                  => array('/', null)
        );
    }

    public function testRelativeToHtdocs(): void
    {
        self::assertSame('/', VhostRules::relativeToHtdocs('/htdocs'));
        self::assertSame('/public/app', VhostRules::relativeToHtdocs('/htdocs/public/app'));
    }

    public function testWildcard(): void
    {
        self::assertSame('yes', VhostRules::wildcard(true));
        self::assertSame('no', VhostRules::wildcard(false));
    }

    public function testNoForwarding(): void
    {
        self::assertSame(array('url' => 'no', 'type' => null, 'host' => 'Off'), VhostRules::noForwarding());
    }

    public function testLikeEscape(): void
    {
        // Measurement M16: '_' is legal in a name and a wildcard in LIKE.
        self::assertSame('a\_b.test', VhostRules::likeEscape('a_b.test'));
        self::assertSame('100\%', VhostRules::likeEscape('100%'));
        self::assertSame('back\\\\slash', VhostRules::likeEscape('back\\slash'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter VhostRulesTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Support\VhostRules" not found`.

- [ ] **Step 3: Implement**

Create `Support/VhostRules.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

use InvalidArgumentException;

/**
 * The rules the subdomain, alias and domain pages share that need nothing but
 * their arguments.
 */
final class VhostRules
{
    /** The schema's ForwardType => i-MSCP's type_forward. */
    const PANEL_FORWARD_TYPES = array(
        'PERMANENT_301' => '301',
        'FOUND_302'     => '302',
        'SEE_OTHER_303' => '303',
        'TEMPORARY_307' => '307',
        'PROXY'         => 'proxy'
    );

    /** Where Apache serves from, under a mount point. */
    const HTDOCS = '/htdocs';

    /**
     * Directories the main domain's web space already uses.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:275.
     *   Retire when SubdomainService lands in core.
     */
    const RESERVED_DIRECTORIES = array('backups', 'cgi-bin', 'errors', 'logs', 'phptmp');

    /**
     * @throws InvalidArgumentException
     */
    public static function panelForwardType(string $forwardType): string
    {
        if (!isset(self::PANEL_FORWARD_TYPES[$forwardType])) {
            throw new InvalidArgumentException(sprintf('Unknown forward type "%s".', $forwardType));
        }

        return self::PANEL_FORWARD_TYPES[$forwardType];
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:229.
     */
    public static function isReservedLabel(string $label): bool
    {
        return $label === 'www' || strpos($label, 'www.') === 0;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:273-288.
     *
     * @param string $parentKind 'dmn' or 'als'
     * @throws InvalidArgumentException
     */
    public static function subdomainMountPoint(string $parentKind, string $labelAscii, string $parentNameAscii): string
    {
        if ($parentKind === 'dmn') {
            return in_array($labelAscii, self::RESERVED_DIRECTORIES, true)
                ? '/sub_' . $labelAscii
                : '/' . $labelAscii;
        }

        if ($parentKind === 'als') {
            return $labelAscii === 'cgi-bin'
                ? '/' . $parentNameAscii . '/sub_' . $labelAscii
                : '/' . $parentNameAscii . '/' . $labelAscii;
        }

        throw new InvalidArgumentException(sprintf(
            'A subdomain hangs off a domain or an alias, not a "%s".', $parentKind
        ));
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/alias_add.php:267.
     */
    public static function aliasMountPoint(string $aliasNameAscii): string
    {
        return '/' . $aliasNameAscii;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/alias_add.php:244-246.
     */
    public static function stripWww(string $name): string
    {
        while (strpos($name, 'www.') === 0) {
            $name = substr($name, 4);
        }

        return $name;
    }

    /**
     * A document root, normalised, or null when it is not /htdocs or inside it.
     *
     * The input is in the same terms VirtualHost.documentRoot reads back -
     * "/htdocs/public" - rather than the pages' form field, which is relative
     * to /htdocs. A client can write what it read.
     *
     * CORE-DEBT(C3): the rule is gui/public/client/domain_edit.php:265-287.
     *
     * @param callable $normalise fn(string): string - utils_normalizePath()
     */
    public static function documentRoot(string $input, callable $normalise): ?string
    {
        $path = (string)call_user_func($normalise, '/' . $input);

        if ($path === self::HTDOCS || strpos($path, self::HTDOCS . '/') === 0) {
            return $path;
        }

        return null;
    }

    /** '/htdocs/public' => '/public'; '/htdocs' => '/'. */
    public static function relativeToHtdocs(string $documentRoot): string
    {
        $relative = substr($documentRoot, strlen(self::HTDOCS));

        return $relative === '' || $relative === false ? '/' : $relative;
    }

    public static function wildcard(bool $on): string
    {
        return $on ? 'yes' : 'no';
    }

    /**
     * The three forwarding columns of a host that forwards nowhere.
     *
     * @return array{url: string, type: null, host: string}
     */
    public static function noForwarding(): array
    {
        return array('url' => 'no', 'type' => null, 'host' => 'Off');
    }

    /**
     * A value for use inside a LIKE pattern, with MySQL's default escape
     * character. Names may contain '_' (measurement M16).
     */
    public static function likeEscape(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `tools/test.sh --filter VhostRulesTest`
Expected: PASS, 32 tests (data provider rows counted individually).

- [ ] **Step 5: Commit**

```bash
git add Support/VhostRules.php test/unit/Support/VhostRulesTest.php
git commit -m "Transcribe the vhost pages' pure rules

Mount points, the www label, forward types and document roots are the same
few lines in five page scripts. Written once here with every case listed, so
the three services that need them cannot each get one slightly wrong.

A document root is taken in the terms the read model returns it -
/htdocs/public - rather than relative to /htdocs as the pages' form field is,
so that a client can write back what it read. likeEscape() exists because
names may contain '_', which LIKE reads as a wildcard."
```

---

## Wave 3: Virtual hosts

Tasks 6–8. The first vertical slice, and the pattern Waves 4 and 5 repeat. Ends at Checkpoint B.

---

## Task 6: Subdomains — `sonnet`

`subdomainCreate`, `subdomainUpdate` and `subdomainDelete` as a service, for both of i-MSCP's tables. The GraphQL surface is Task 8; this task tests the service directly. It also adds spec §21's **C11**, because this is the first task to write a `CORE-DEBT(C11)` marker.

**Files:**
- Create: `Service/VhostInput.php`, `Repository/FtpGroups.php`, `Service/SubdomainService.php`
- Modify: `Repository/VirtualHosts.php` (add `nameInUse()`)
- Create: `test/integration/ServiceTestCase.php`, `test/integration/SubdomainServiceTest.php`
- Modify: `test/integration/VirtualHostsTest.php` (append one method)
- Modify: `docs/SPECIFICATION.md` §21 (add C11)

**Interfaces:**
- Consumes: `Toolkit`, `Guard`, `ObjectRef`, `CustomerAccount` (Task 3); `VhostRules` (Task 5); `Core` (Task 2); `Support\MailType`, `Support\Quota`, `Support\Provisioning`, `Repository\VirtualHosts`; `Test\Double\*` (Task 2).
- Produces:
  ```php
  new VhostInput(Toolkit $kit)
  VhostInput::forwarding(?array $input, string $selfAsciiName, string $field): array  // url, type, host
  VhostInput::documentRoot(CustomerAccount $account, string $mountPoint, string $input, string $field): string
  VhostInput::update(CustomerAccount $account, array $row, array $input): array
      // => documentRoot, url, type, host, wildcard ('yes'|'no'); BAD_USER_INPUT field 'input' when empty
  VhostInput::sharedMountPoint(Identity $caller, $encoded, int $ownerId): string
      // NOT_FOUND (field input.sharedMountPointOf) for another customer's host; BAD_USER_INPUT when unsettled or forwarded
  new FtpGroups(Db $db)
  FtpGroups::ofCustomer(string $customerUsername): ?array    // groupname, members
  FtpGroups::withMemberOn(string $asciiHostName): ?array     // the group holding a member @host
  FtpGroups::removeMembers(array $group, callable $isRemoved): void
  FtpGroups::addMember(string $groupname, int $gid, string $member): void
  VirtualHosts::nameInUse(string $asciiName): bool
  new SubdomainService(Toolkit $kit)
  SubdomainService::create(Identity $caller, array $input): ObjectRef
  SubdomainService::update(Identity $caller, string $id, array $input): ObjectRef
  SubdomainService::delete(Identity $caller, string $id): ObjectRef
  abstract ServiceTestCase extends IntegrationTestCase   // test/integration
      $db $fixture $core $probe $kit;  config(): array;  reconfigure(array $overrides): void
      caller(string $who, array $scopes = array()): Identity
      refused(string $code, callable $fn): ApiException
      insert(string $table, array $row): int
  ```

- [ ] **Step 1: Add C11 to the specification**

In `docs/SPECIFICATION.md`, after the C10 item and before `### 21.2`, insert:

```markdown
---

**C11 — Defects in the page scripts that the API does not reproduce.**

*Numbering.* Like C7–C10, found by this plugin's work rather than by reading for duplication: phase 3 transcribed every customer write, and these are the places the transcription had to choose between copying a defect and implementing what the page evidently intends. The API does the latter, marks the site `CORE-DEBT(C11)`, and each item below is a separate issue for `saygoweb/imscp`.

*The list.*

1. `client/mail_add.php:266-279` inserts `quota = NULL` for a forward-only account; `mail_users.quota` is `NOT NULL`, so MariaDB refuses the row with error 1048, and line 289 reports that SQLSTATE 23000 as *"Mail account already exists."* Forward-only accounts cannot be created in the panel at all.
2. `client/subdomain_edit.php:380-388` updates `subdomain_alias.subdomain_wildcard_alias`, a column that table does not have, so a subdomain of an alias cannot be edited; line 354 also reads the wildcard flag as `0|1` where the column is `enum('yes','no')`.
3. `client/sql_user_add.php` checks the SQL user limit in `generatePage()`, which runs only after `addSqlUser()` has written and redirected. The limit is never enforced on a submit.
4. `client/mail_delete.php:88-121` overwrites `$row` in the loop that is meant to remove the deleted address from other accounts' forward and catch-all lists, so it removes nothing. Its log call (line 163) has its arguments swapped. When fixed, it should scan the owning customer's accounts, not every customer's.
5. `client/dns_edit.php:157-163` tests `strpos(...) == 0`, which is also true for `false`, so the first character of every record name is stripped before validation. `client/dns_delete.php:39` dispatches `onBeforeDeleteCustomDNSrecord` with an unassigned `$dnsRecordId`.
6. `deleteSubdomain()`, `deleteSubdomainAlias()` and `deleteDomainAlias()` (`include/Client.php`, `include/Shared.php`) match FTP users with `LIKE CONCAT('%@', name)` and FTP group members with a regex built from the name, unescaped. Names may contain `_`, which `LIKE` reads as a wildcard: deleting `a_b.test` schedules `axb.test`'s FTP users — another customer's — for deletion. Protected areas are matched with `LIKE mount%`, so deleting `/shop` also schedules `/shopping`.
7. `client/sql_database_add.php:57` asks `SHOW DATABASES LIKE ?` with the name unescaped, so a name containing `_` is refused whenever any database matches it as a pattern.
8. `client/alias_order_delete.php` deletes an ordered alias's row and leaves the `php_ini` row `alias_add.php` created for it.
9. `client/alias_add.php:45-80` sends the reseller's *"your customer is awaiting approval"* template to the customer's own address. (The API keeps this behaviour, because changing who receives mail is the panel's decision to make first.)
10. `include/Shared.php:63` `createDefaultMailAccounts()` catches only `PDOException`, but `DatabaseMySQL::execute()` throws `DatabaseException`, so on a database error its own savepoint is neither rolled back nor released and the caller's transaction depth is left one too high.

*Why i-MSCP wants it anyway.* Each is a user-visible failure or a cross-tenant write in the panel as shipped.

*What the plugin deletes.* Nothing directly: the API already behaves correctly. Each fix lets the corresponding C3 extraction be a straight move rather than a move plus a behaviour change.
```

- [ ] **Step 2: Write the shared service test base**

Create `test/integration/ServiceTestCase.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;

/**
 * A service against the seeded fixture, with the panel's real validators and
 * the three side effects recorded instead of performed (decision D12).
 */
abstract class ServiceTestCase extends IntegrationTestCase
{
    /** @var Db */
    protected $db;

    /** @var Fixture */
    protected $fixture;

    /** @var RecordingCore */
    protected $core;

    /** @var FakeDirectoryProbe */
    protected $probe;

    /** @var Toolkit */
    protected $kit;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->probe = new FakeDirectoryProbe();
        $this->reconfigure(array());
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * The panel settings the services read, stated rather than inherited from
     * the box (measurement M17 records the box's own values, which match).
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return array(
            'CREATE_DEFAULT_EMAIL_ADDRESSES'  => 1,
            'PROTECT_DEFAULT_EMAIL_ADDRESSES' => 1,
            'SERVER_HOSTNAME'                 => 'imscp.docker.local',
            'MYSQL_PREFIX'                    => 'none',
            'DATABASE_USER_HOST'              => 'localhost',
            'USER_WEB_DIR'                    => '/var/www/virtual',
            'NAMED_PACKAGE'                   => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES'          => 'Awstats',
            'BACKUP_DOMAINS'                  => 'yes',
            'ENABLE_SSL'                      => 1,
            'IMSCP_SUPPORT_SYSTEM'            => 1
        );
    }

    /**
     * Rebuild the core and the toolkit with some settings changed. Called by
     * setUp() with none; a test that depends on a setting calls it again.
     *
     * @param array<string, mixed> $overrides
     */
    protected function reconfigure(array $overrides): void
    {
        $db = $this->db;
        $this->core = new RecordingCore(new PanelCore(false), array_merge($this->config(), $overrides));
        $this->kit = new Toolkit(
            $db,
            $this->core,
            new Guard(new OwnershipResolver(static function (string $sql, array $bind = array()) use ($db) {
                return $db->rows($sql, $bind);
            })),
            new Accounts($db),
            new VirtualHosts($db),
            new Counts($db, true),
            new Writer($db),
            $this->probe
        );
    }

    /**
     * @param string $who customer|sibling|otherCustomer|reseller|otherReseller|admin
     */
    protected function caller(string $who, array $scopes = array()): Identity
    {
        return $this->fixture->identity($who, $scopes);
    }

    /**
     * Assert that $fn is refused with $code, and hand back the exception for
     * any further assertion on its extensions.
     */
    protected function refused(string $code, callable $fn): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            self::assertSame(
                $code, $e->getErrorCode(),
                $e->getMessage() . ' ' . json_encode($e->getExtensions())
            );

            return $e;
        }

        self::fail('Expected ' . $code . ', and the call succeeded.');
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function insert(string $table, array $row): int
    {
        $columns = array_keys($row);

        $this->db->execute(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ('
                . $this->db->placeholders(count($columns)) . ')',
            array_values($row)
        );

        return $this->db->lastInsertId();
    }
}
```

- [ ] **Step 3: Write the failing service test**

Create `test/integration/SubdomainServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;

class SubdomainServiceTest extends ServiceTestCase
{
    private function service(): SubdomainService
    {
        return new SubdomainService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function subdomainId(): string
    {
        return GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId());
    }

    private function subdomainRow(int $id): array
    {
        return $this->db->row('SELECT * FROM subdomain WHERE subdomain_id = ?', array($id));
    }

    // ---- create ---------------------------------------------------------

    public function testTheOwnerCreatesASubdomainOfTheirDomain(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'Blog2'
        ));

        self::assertSame(NodeType::SUBDOMAIN, $ref->getTag());

        $row = $this->subdomainRow($ref->getKey());
        self::assertSame($this->fixture->domainId(), (int)$row['domain_id']);
        self::assertSame('blog2', $row['subdomain_name']);
        self::assertSame('/blog2', $row['subdomain_mount']);
        self::assertSame('/htdocs', $row['subdomain_document_root']);
        self::assertSame('no', $row['subdomain_url_forward']);
        self::assertNull($row['subdomain_type_forward']);
        self::assertSame('Off', $row['subdomain_host_forward']);
        self::assertSame('no', $row['subdomain_wildcard_alias']);
        self::assertSame('toadd', $row['subdomain_status']);
    }

    public function testCreatingDispatchesThePagesEventsAndPokesTheDaemonOnce(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'blog2'
        ));

        self::assertSame(array('onBeforeAddSubdomain', 'onAfterAddSubdomain'), $this->core->eventNames());
        self::assertSame(array(
            'subdomainName'  => 'blog2.' . $this->fixture->domainName(),
            'subdomainType'  => 'dmn',
            'parentDomainId' => $this->fixture->domainId(),
            'mountPoint'     => '/blog2',
            'documentRoot'   => '/htdocs',
            'forwardUrl'     => 'no',
            'forwardType'    => null,
            'forwardHost'    => 'Off',
            'wildcardAlias'  => 'no',
            'customerId'     => $this->fixture->customerId()
        ), $this->core->events[0][1]);
        self::assertSame($ref->getKey(), $this->core->events[1][1]['subdomainId']);
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            array(array('A new subdomain (blog2.' . $this->fixture->domainName() . ') has been created by sgwtcustomer', E_USER_NOTICE)),
            $this->core->logs
        );
    }

    public function testCreatingWritesThePhpIniRowAndTheDefaultMailAccount(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'blog2'
        ));

        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM php_ini WHERE admin_id = ? AND domain_id = ? AND domain_type = 'sub'",
            array($this->fixture->customerId(), $ref->getKey())
        ));
        self::assertSame(
            array(array('mail_addr' => 'webmaster@blog2.' . $this->fixture->domainName(), 'mail_type' => 'subdom_forward', 'mail_forward' => 'sgwtcustomer@example.test')),
            $this->db->rows(
                'SELECT mail_addr, mail_type, mail_forward FROM mail_users WHERE sub_id = ? AND mail_type = ?',
                array($ref->getKey(), 'subdom_forward')
            )
        );
    }

    public function testNoDefaultMailAccountWhenThePanelIsSetNotToCreateOne(): void
    {
        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => 0));

        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'blog2'
        ));

        self::assertSame('0', (string)$this->db->value(
            'SELECT COUNT(*) FROM mail_users WHERE sub_id = ? AND mail_type = ?',
            array($ref->getKey(), 'subdom_forward')
        ));
    }

    public function testASubdomainOfAnAliasGoesInTheAliasTable(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
            'label'    => 'news'
        ));

        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $ref->getTag());

        $row = $this->db->row('SELECT * FROM subdomain_alias WHERE subdomain_alias_id = ?', array($ref->getKey()));
        self::assertSame($this->fixture->aliasId(), (int)$row['alias_id']);
        self::assertSame('news', $row['subdomain_alias_name']);
        self::assertSame('/' . $this->fixture->aliasName() . '/news', $row['subdomain_alias_mount']);
        self::assertSame('toadd', $row['subdomain_alias_status']);
        self::assertSame('als', $this->core->events[0][1]['subdomainType']);
        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'subals'",
            array($ref->getKey())
        ));
    }

    public function testAReservedDirectoryNameIsMountedUnderASubPrefix(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'logs'
        ));

        self::assertSame('/sub_logs', $this->subdomainRow($ref->getKey())['subdomain_mount']);
    }

    public function testForwardingIsNormalisedAndStoredInThePanelsTerms(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId'   => $this->domainId(),
            'label'      => 'go',
            'forwarding' => array('url' => 'HTTPS://Example.NET', 'type' => 'PERMANENT_301', 'keepHost' => false)
        ));

        $row = $this->subdomainRow($ref->getKey());
        self::assertSame('https://example.net/', $row['subdomain_url_forward']);
        self::assertSame('301', $row['subdomain_type_forward']);
        self::assertSame('Off', $row['subdomain_host_forward']);
    }

    public function testAProxyMayKeepTheHostHeader(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId'   => $this->domainId(),
            'label'      => 'app',
            'forwarding' => array('url' => 'http://127.0.0.1:8080/', 'type' => 'PROXY', 'keepHost' => true)
        ));

        $row = $this->subdomainRow($ref->getKey());
        self::assertSame('proxy', $row['subdomain_type_forward']);
        self::assertSame('On', $row['subdomain_host_forward']);
    }

    public function testKeepHostWithoutProxyIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'parentId'   => $this->domainId(),
                'label'      => 'go',
                'forwarding' => array('url' => 'https://example.net/', 'type' => 'FOUND_302', 'keepHost' => true)
            ));
        });

        self::assertSame('input.forwarding.keepHost', $e->getExtensions()['field']);
    }

    public function testAnInvalidForwardUrlIsRefusedOnItsField(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'parentId'   => $this->domainId(),
                'label'      => 'go',
                'forwarding' => array('url' => 'javascript:alert(1)', 'type' => 'FOUND_302')
            ));
        });

        self::assertSame('input.forwarding.url', $e->getExtensions()['field']);
    }

    public function testSharingAMountPointUsesTheOtherHostsDirectory(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId'           => $this->domainId(),
            'label'              => 'shop2',
            'sharedMountPointOf' => $this->subdomainId()
        ));

        self::assertSame('/shop', $this->subdomainRow($ref->getKey())['subdomain_mount']);
    }

    public function testAForwardedHostHasNoDirectoryToShare(): void
    {
        // The fixture's alias forwards to https://example.net/.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'parentId'           => $this->domainId(),
                'label'              => 'shop2',
                'sharedMountPointOf' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
            ));
        });

        self::assertSame('input.sharedMountPointOf', $e->getExtensions()['field']);
    }

    public function testAStrangersHostCannotBeShared(): void
    {
        $e = $this->refused(ErrorCode::NOT_FOUND, function () {
            $this->service()->create($this->caller('reseller'), array(
                'parentId'           => $this->domainId(),
                'label'              => 'shop2',
                // The sibling is the same reseller's customer: reachable by the
                // caller, but not the same customer as the parent.
                'sharedMountPointOf' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->siblingDomainId())
            ));
        });

        self::assertSame('input.sharedMountPointOf', $e->getExtensions()['field']);
    }

    public function testWwwIsNotALabel(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'www'));
        });

        self::assertSame('input.label', $e->getExtensions()['field']);
    }

    public function testAnInvalidNameIsRefusedWithThePanelsReason(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'bad..x'));
        });

        self::assertSame('Usage of dot in domain name labels is prohibited.', $e->getMessage());
    }

    public function testAnEmptyLabelIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => '  '));
        });
    }

    public function testANameAlreadyInUseIsAConflict(): void
    {
        // Measurement M9: nothing in the schema stops a second 'shop'.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'shop'));
        });

        self::assertSame(array(), $this->core->events, 'nothing was dispatched');
    }

    public function testAnUnsettledParentIsAConflict(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'blog2'));
        });

        self::assertSame(5, $e->getExtensions()['retryAfterSeconds']);
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_subd_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'blog2'));
        });
    }

    public function testTheLimitCountsSubdomainsOfAliasesToo(): void
    {
        // The fixture has 'shop' and the alias subdomain 'blog': two.
        $this->db->execute('UPDATE domain SET domain_subd_limit = 2 WHERE domain_id = ?', array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'blog2'));
        });

        self::assertSame(array('quota' => 'subdomains', 'limit' => 2, 'used' => 2), $e->getExtensions());
    }

    public function testTheFeatureIsAskedBeforeTheInput(): void
    {
        $this->db->execute('UPDATE domain SET domain_subd_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'www'));
        });
    }

    public function testTheInputIsAskedBeforeTheQuota(): void
    {
        $this->db->execute('UPDATE domain SET domain_subd_limit = 2 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'www'));
        });
    }

    public function testAResellerIsCheckedAgainstTheCustomersLimitsAndLoggedAsThemselves(): void
    {
        $this->service()->create($this->caller('reseller'), array('parentId' => $this->domainId(), 'label' => 'blog2'));

        self::assertStringEndsWith('has been created by sgwtreseller', $this->core->logs[0][0]);
        self::assertSame($this->fixture->customerId(), $this->core->events[0][1]['customerId']);
    }

    // ---- update ---------------------------------------------------------

    public function testAPartialUpdateChangesOnlyWhatItNames(): void
    {
        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('wildcard' => true));

        $row = $this->subdomainRow($this->fixture->subdomainId());
        self::assertSame('yes', $row['subdomain_wildcard_alias']);
        self::assertSame('/htdocs', $row['subdomain_document_root']);
        self::assertSame('no', $row['subdomain_url_forward']);
        self::assertSame('tochange', $row['subdomain_status']);
        self::assertSame(array('onBeforeEditSubdomain', 'onAfterEditSubdomain'), $this->core->eventNames());
        self::assertSame('dmn', $this->core->events[0][1]['subdomainType']);
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            'sgwtcustomer updated properties of the shop.' . $this->fixture->domainName() . ' subdomain',
            $this->core->logs[0][0]
        );
    }

    public function testASubdomainOfAnAliasIsUpdatedInItsOwnColumns(): void
    {
        // Measurement M11: the page cannot do this at all.
        $this->db->execute(
            "UPDATE subdomain_alias SET subdomain_alias_status = 'ok' WHERE subdomain_alias_id = ?",
            array($this->fixture->aliasSubdomainId())
        );

        $this->service()->update(
            $this->caller('customer'),
            GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()),
            array('wildcard' => false)
        );

        $row = $this->db->row(
            'SELECT subdomain_alias_wildcard_alias, subdomain_alias_status FROM subdomain_alias WHERE subdomain_alias_id = ?',
            array($this->fixture->aliasSubdomainId())
        );
        self::assertSame(array('subdomain_alias_wildcard_alias' => 'no', 'subdomain_alias_status' => 'tochange'), $row);
        self::assertSame('als', $this->core->events[0][1]['subdomainType']);
    }

    public function testAnUnsettledSubdomainCannotBeUpdated(): void
    {
        // The fixture's alias subdomain is 'toadd'.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update(
                $this->caller('customer'),
                GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()),
                array('wildcard' => true)
            );
        });
    }

    public function testAnEmptyUpdateIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array());
        });

        self::assertSame('input', $e->getExtensions()['field']);
    }

    public function testADocumentRootIsCheckedInsideTheMountsHtdocs(): void
    {
        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs/public'));

        self::assertSame(array(array('sgwtcustomer', '/shop/htdocs', '/public')), $this->probe->asked);
        self::assertSame('/htdocs/public', $this->subdomainRow($this->fixture->subdomainId())['subdomain_document_root']);
    }

    public function testHtdocsItselfNeedsNoCheck(): void
    {
        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs'));

        self::assertSame(array(), $this->probe->asked);
    }

    public function testADocumentRootOutsideHtdocsIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs/../../etc'));
        });

        self::assertSame('input.documentRoot', $e->getExtensions()['field']);
    }

    public function testAMissingDirectoryIsRefused(): void
    {
        $this->probe = new FakeDirectoryProbe(false);
        $this->reconfigure(array());

        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs/missing'));
        });
    }

    public function testAForwardedHostServesNoDocumentRoot(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array(
                'forwarding'   => array('url' => 'https://example.net/', 'type' => 'FOUND_302'),
                'documentRoot' => '/htdocs/public'
            ));
        });

        self::assertSame('input.documentRoot', $e->getExtensions()['field']);
    }

    public function testForwardingNullRemovesIt(): void
    {
        $this->db->execute(
            "UPDATE subdomain SET subdomain_url_forward = 'https://example.net/', subdomain_type_forward = '302' WHERE subdomain_id = ?",
            array($this->fixture->subdomainId())
        );

        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('forwarding' => null));

        $row = $this->subdomainRow($this->fixture->subdomainId());
        self::assertSame('no', $row['subdomain_url_forward']);
        self::assertNull($row['subdomain_type_forward']);
        self::assertSame('Off', $row['subdomain_host_forward']);
    }

    // ---- delete ---------------------------------------------------------

    public function testDeletingSchedulesTheSubdomainAndEverythingUnderIt(): void
    {
        $name = $this->fixture->subdomainName();
        $this->insert('ftp_users', array(
            'userid' => 'web@' . $name, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            // A gid no real customer on the box has: FtpGroups::withMemberOn()
            // joins ftp_group to ftp_users on gid, as the core does.
            'uid' => 64001, 'gid' => 64001, 'shell' => '/bin/sh', 'homedir' => '/var/www/virtual/x', 'status' => 'ok'
        ));
        $this->insert('ftp_group', array(
            'groupname' => 'sgwtcustomer', 'gid' => 64001,
            'members'   => 'web@' . $name . ',' . $this->fixture->ftpUserId()
        ));
        $mailId = $this->insert('mail_users', array(
            'mail_acc' => 'info', 'mail_pass' => '_no_', 'mail_forward' => 'a@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'subdom_forward',
            'sub_id' => $this->fixture->subdomainId(), 'status' => 'ok', 'po_active' => 'no',
            'quota' => 0, 'mail_addr' => 'info@' . $name
        ));
        $this->insert('ssl_certs', array(
            'domain_id' => $this->fixture->subdomainId(), 'domain_type' => 'sub',
            'private_key' => 'k', 'certificate' => 'c', 'status' => 'ok'
        ));
        $inside = $this->insert('htaccess', array(
            'dmn_id' => $this->fixture->domainId(), 'user_id' => '1', 'auth_type' => 'Basic',
            'auth_name' => 'x', 'path' => '/shop/private', 'status' => 'ok'
        ));
        $lookalike = $this->insert('htaccess', array(
            'dmn_id' => $this->fixture->domainId(), 'user_id' => '1', 'auth_type' => 'Basic',
            'auth_name' => 'x', 'path' => '/shopping', 'status' => 'ok'
        ));
        $this->insert('php_ini', array(
            'admin_id' => $this->fixture->customerId(), 'domain_id' => $this->fixture->subdomainId(), 'domain_type' => 'sub'
        ));

        $ref = $this->service()->delete($this->caller('customer'), $this->subdomainId());

        self::assertSame(NodeType::SUBDOMAIN, $ref->getTag());
        self::assertNull($ref->getSnapshot());
        self::assertSame('todelete', $this->subdomainRow($this->fixture->subdomainId())['subdomain_status']);
        self::assertSame('todelete', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array('web@' . $name)));
        self::assertSame('ok', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array($this->fixture->ftpUserId())));
        self::assertSame($this->fixture->ftpUserId(), $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertSame('todelete', $this->db->value('SELECT status FROM mail_users WHERE mail_id = ?', array($mailId)));
        self::assertSame('todelete', $this->db->value("SELECT status FROM ssl_certs WHERE domain_id = ? AND domain_type = 'sub'", array($this->fixture->subdomainId())));
        self::assertSame('todelete', $this->db->value('SELECT status FROM htaccess WHERE id = ?', array($inside)));
        self::assertSame('ok', $this->db->value('SELECT status FROM htaccess WHERE id = ?', array($lookalike)), 'C11 item 6');
        self::assertSame('0', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'sub'", array($this->fixture->subdomainId())));
        self::assertSame(array('onBeforeDeleteSubdomain', 'onAfterDeleteSubdomain'), $this->core->eventNames());
        self::assertSame(
            array('subdomainId' => $this->fixture->subdomainId(), 'subdomainName' => $name, 'subdomainType' => 'sub', 'type' => 'sub'),
            $this->core->events[0][1]
        );
        self::assertSame(1, $this->core->requests);
        self::assertSame('Deletion of the ' . $name . ' subdomain has been scheduled by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testTheLastMemberLeavingRemovesTheFtpGroupAndItsQuota(): void
    {
        $name = $this->fixture->subdomainName();
        $this->insert('ftp_users', array(
            'userid' => 'web@' . $name, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            'uid' => 64002, 'gid' => 64002, 'shell' => '/bin/sh', 'homedir' => '/var/www/virtual/x', 'status' => 'ok'
        ));
        $this->insert('ftp_group', array('groupname' => 'sgwtcustomer', 'gid' => 64002, 'members' => 'web@' . $name));
        $this->insert('quotalimits', array('name' => 'sgwtcustomer', 'quota_type' => 'group'));

        $this->service()->delete($this->caller('customer'), $this->subdomainId());

        self::assertNull($this->db->value("SELECT groupname FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertNull($this->db->value("SELECT name FROM quotalimits WHERE name = 'sgwtcustomer'"));
    }

    public function testAnUnderscoreInTheNameDoesNotReachAnotherCustomersFtpUsers(): void
    {
        // Measurement M16, C11 item 6.
        $id = $this->insert('subdomain', array(
            'domain_id' => $this->fixture->domainId(), 'subdomain_name' => 'a_b', 'subdomain_mount' => '/a_b',
            'subdomain_status' => 'ok'
        ));
        $this->insert('ftp_users', array(
            'userid' => 'x@axb.' . $this->fixture->domainName(), 'admin_id' => $this->fixture->siblingId(),
            'passwd' => 'x', 'uid' => 1, 'gid' => 1, 'shell' => '/bin/sh', 'homedir' => '/x', 'status' => 'ok'
        ));

        $this->service()->delete($this->caller('customer'), GlobalId::encode(NodeType::SUBDOMAIN, $id));

        self::assertSame('ok', $this->db->value(
            'SELECT status FROM ftp_users WHERE userid = ?', array('x@axb.' . $this->fixture->domainName())
        ));
    }

    public function testAFailedSubdomainMayBeDeleted(): void
    {
        $this->db->execute(
            "UPDATE subdomain SET subdomain_status = 'Could not create the vhost' WHERE subdomain_id = ?",
            array($this->fixture->subdomainId())
        );

        $this->service()->delete($this->caller('customer'), $this->subdomainId());

        self::assertSame('todelete', $this->subdomainRow($this->fixture->subdomainId())['subdomain_status']);
    }

    public function testAPendingSubdomainMayNotBeDeleted(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->delete(
                $this->caller('customer'),
                GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId())
            );
        });
    }

    public function testDeletingAnAliasSubdomainAsksTheAliasFeature(): void
    {
        // alssub_delete.php:36 asks 'domain_aliases', not 'subdomains'.
        $this->db->execute(
            "UPDATE subdomain_alias SET subdomain_alias_status = 'ok' WHERE subdomain_alias_id = ?",
            array($this->fixture->aliasSubdomainId())
        );
        $this->db->execute(
            'UPDATE domain SET domain_alias_limit = -1, domain_subd_limit = 0 WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        $aliasSubdomain = GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId());

        $e = $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () use ($aliasSubdomain) {
            $this->service()->delete($this->caller('customer'), $aliasSubdomain);
        });
        self::assertSame('domainAliases', $e->getExtensions()['feature']);

        $this->db->execute(
            'UPDATE domain SET domain_alias_limit = 0, domain_subd_limit = -1 WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        $this->service()->delete($this->caller('customer'), $aliasSubdomain);

        self::assertSame('alssub', $this->core->events[0][1]['subdomainType']);
    }
}
```

- [ ] **Step 4: Run it to verify it fails**

Run: `tools/test.sh --filter SubdomainServiceTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService" not found`.

- [ ] **Step 5: Add `nameInUse()` with its test**

Append to `test/integration/VirtualHostsTest.php`'s class:

```php
    public function testANameIsInUseAsAnyOfTheFourKinds(): void
    {
        $vhosts = new \iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts($this->db);

        self::assertTrue($vhosts->nameInUse($this->fixture->domainName()));
        self::assertTrue($vhosts->nameInUse($this->fixture->aliasName()));
        self::assertTrue($vhosts->nameInUse($this->fixture->subdomainName()));
        self::assertTrue($vhosts->nameInUse('blog.' . $this->fixture->aliasName()));
        self::assertFalse($vhosts->nameInUse('sgwt-nobody.test'));
    }
```

(If `VirtualHostsTest` names its handle and fixture differently from `$this->db` and `$this->fixture`, use its names; the method body is otherwise unchanged.)

In `Repository/VirtualHosts.php`, add after `byKeys()`:

```php
    /**
     * Whether a fully qualified ASCII name is already any vhost's, of any
     * kind and in any state.
     *
     * Spec section 8.4 relies on unique keys to turn a retried create into
     * CONFLICT, but none of subdomain, subdomain_alias or domain_aliasses has
     * one on its name (measurement M9). This is that check, asked explicitly.
     */
    public function nameInUse(string $asciiName): bool
    {
        return $this->db->row(
            "
                SELECT 1 FROM domain WHERE domain_name = ?
                UNION ALL
                SELECT 1 FROM domain_aliasses WHERE alias_name = ?
                UNION ALL
                SELECT 1 FROM subdomain AS s JOIN domain AS d ON d.domain_id = s.domain_id
                WHERE CONCAT(s.subdomain_name, '.', d.domain_name) = ?
                UNION ALL
                SELECT 1 FROM subdomain_alias AS sa JOIN domain_aliasses AS al ON al.alias_id = sa.alias_id
                WHERE CONCAT(sa.subdomain_alias_name, '.', al.alias_name) = ?
                LIMIT 1
            ",
            array($asciiName, $asciiName, $asciiName, $asciiName)
        ) !== null;
    }
```

Run: `tools/test.sh --filter VirtualHostsTest`
Expected: PASS.

- [ ] **Step 6: Write `FtpGroups` and `VhostInput`**

Create `Repository/FtpGroups.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

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

use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;

/**
 * ProFTPD's group table: one row per customer, named after the customer's
 * login, listing their FTP users. Removing the last member removes the group
 * and its quota rows, as every page that touches it does.
 */
final class FtpGroups
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{groupname: string, members: string}|null
     */
    public function ofCustomer(string $customerUsername): ?array
    {
        return $this->db->row('SELECT groupname, members FROM ftp_group WHERE groupname = ?', array($customerUsername));
    }

    /**
     * The group holding an FTP user whose login ends in @$asciiHostName.
     *
     * CORE-DEBT(C1): transcribed from gui/include/Client.php:478-487.
     * CORE-DEBT(C11): with the name escaped for LIKE (C11 item 6).
     *
     * @return array{groupname: string, members: string}|null
     */
    public function withMemberOn(string $asciiHostName): ?array
    {
        return $this->db->row(
            'SELECT groupname, members FROM ftp_group JOIN ftp_users USING(gid) WHERE userid LIKE ? LIMIT 1',
            array('%@' . VhostRules::likeEscape($asciiHostName))
        );
    }

    /**
     * CORE-DEBT(C1): transcribed from gui/include/Client.php:488-521.
     *
     * @param array{groupname: string, members: string} $group
     * @param callable $isRemoved fn(string $member): bool
     */
    public function removeMembers(array $group, callable $isRemoved): void
    {
        $members = array_values(array_filter(
            preg_split('/,/', (string)$group['members'], -1, PREG_SPLIT_NO_EMPTY),
            static function (string $member) use ($isRemoved) {
                return !$isRemoved($member);
            }
        ));

        if ($members === array()) {
            $this->db->execute('DELETE FROM ftp_group WHERE groupname = ?', array($group['groupname']));
            $this->db->execute('DELETE FROM quotalimits WHERE name = ?', array($group['groupname']));
            $this->db->execute('DELETE FROM quotatallies WHERE name = ?', array($group['groupname']));

            return;
        }

        $this->db->execute(
            'UPDATE ftp_group SET members = ? WHERE groupname = ?',
            array(implode(',', $members), $group['groupname'])
        );
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:289-299.
     */
    public function addMember(string $groupname, int $gid, string $member): void
    {
        $this->db->execute(
            'INSERT INTO ftp_group (groupname, gid, members) VALUES (?, ?, ?)'
                . " ON DUPLICATE KEY UPDATE members = CONCAT(members, ',', ?)",
            array($groupname, $gid, $member, $member)
        );
    }
}
```

Create `Service/VhostInput.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;
use InvalidArgumentException;

/**
 * Forwarding, document-root and shared-mount-point input, shared by the domain, subdomain and
 * alias services: the half of those pages' rules that needs the panel.
 */
final class VhostInput
{
    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    /**
     * A ForwardingInput as the three forwarding columns.
     *
     * @param array|null $input null for no forwarding
     * @param string     $field e.g. 'input.forwarding'
     * @return array{url: string, type: string|null, host: string}
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public function forwarding(?array $input, string $selfAsciiName, string $field): array
    {
        if ($input === null) {
            return VhostRules::noForwarding();
        }

        $type = (string)($input['type'] ?? '');

        if (!isset(VhostRules::PANEL_FORWARD_TYPES[$type])) {
            throw Guard::badInput($field . '.type', 'Unknown forward type.');
        }

        $proxy = $type === 'PROXY';
        $keepHost = (bool)($input['keepHost'] ?? false);

        if ($keepHost && !$proxy) {
            throw Guard::badInput($field . '.keepHost', 'keepHost applies to PROXY forwarding only.');
        }

        $url = trim((string)($input['url'] ?? ''));

        if ($url === '') {
            throw Guard::badInput($field . '.url', 'A forward URL is required.');
        }

        try {
            $normalised = $this->kit->core()->normaliseForwardUrl($url, $selfAsciiName, $proxy);
        } catch (InvalidArgumentException $e) {
            throw Guard::badInput($field . '.url', $e->getMessage());
        }

        return array(
            'url'  => $normalised,
            'type' => VhostRules::PANEL_FORWARD_TYPES[$type],
            'host' => $keepHost ? 'On' : 'Off'
        );
    }

    /**
     * A document root, normalised and checked to exist.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_edit.php:327-351
     *   and its twins in domain_edit.php and alias_edit.php.
     *
     * @param string $mountPoint The host's mount point: '/' for the main domain
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public function documentRoot(CustomerAccount $account, string $mountPoint, string $input, string $field): string
    {
        $root = VhostRules::documentRoot($input, array($this->kit->core(), 'normalisePath'));

        if ($root === null) {
            throw Guard::badInput($field, 'A document root must be /htdocs or a directory inside it.');
        }

        $relative = VhostRules::relativeToHtdocs($root);
        $vfsRoot = rtrim($mountPoint, '/') . VhostRules::HTDOCS;

        if ($relative !== '/' && !$this->kit->probe()->exists($account->getUsername(), $vfsRoot, $relative)) {
            throw Guard::badInput($field, 'The new document root must already exist inside /htdocs.');
        }

        return $root;
    }

    /**
     * The host's columns after a partial update (decision D14): the current
     * row, overlaid with what the input names, then the pages' rules applied
     * to the result.
     *
     * @param array<string, mixed> $row   A VirtualHosts normalised row
     * @param array<string, mixed> $input documentRoot, forwarding, wildcard - each optional
     * @return array{documentRoot: string, url: string, type: string|null, host: string, wildcard: string}
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public function update(CustomerAccount $account, array $row, array $input): array
    {
        $hasForwarding = array_key_exists('forwarding', $input);
        $hasRoot = isset($input['documentRoot']);
        $hasWildcard = isset($input['wildcard']);

        if (!$hasForwarding && !$hasRoot && !$hasWildcard) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $forwarding = $hasForwarding
            ? $this->forwarding($input['forwarding'], (string)$row['name'], 'input.forwarding')
            : array('url' => $row['urlForward'], 'type' => $row['typeForward'], 'host' => $row['hostForward']);

        $documentRoot = (string)$row['documentRoot'];

        if ($hasRoot) {
            // The pages offer a document root only when forwarding is off
            // (domain_edit.php:264: "elseif").
            if ($forwarding['url'] !== 'no') {
                throw Guard::badInput(
                    'input.documentRoot',
                    'A forwarded host serves no document root. Remove the forwarding in the same update to set one.'
                );
            }

            $documentRoot = $this->documentRoot($account, (string)$row['mountPoint'], (string)$input['documentRoot'], 'input.documentRoot');
        }

        return array(
            'documentRoot' => $documentRoot,
            'url'          => $forwarding['url'],
            'type'         => $forwarding['type'],
            'host'         => $forwarding['host'],
            'wildcard'     => $hasWildcard ? VhostRules::wildcard((bool)$input['wildcard']) : VhostRules::wildcard((bool)$row['wildcard'])
        );
    }

    /**
     * The mount point of another of the same customer's hosts, for
     * sharedMountPointOf (subdomain_add.php:290-310, alias_add.php:269-293).
     *
     * @param mixed $encoded
     * @throws ApiException NOT_FOUND, BAD_USER_INPUT
     */
    public function sharedMountPoint(Identity $caller, $encoded, int $ownerId): string
    {
        $shared = $this->kit->guard()->target(
            $caller, $encoded,
            array(NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN),
            Scope::DOMAINS_WRITE, 'input.sharedMountPointOf'
        );

        if ($shared->getOwnerId() !== $ownerId) {
            // Reachable by a reseller, but another customer's: from the point
            // of view of this subdomain, it does not exist.
            throw new ApiException(
                ErrorCode::NOT_FOUND, Guard::notFound()->getMessage(), array('field' => 'input.sharedMountPointOf')
            );
        }

        $row = $this->kit->vhost($shared->getTag(), $shared->getKey());

        if ($row['status'] !== 'ok' || $row['urlForward'] !== 'no') {
            throw Guard::badInput(
                'input.sharedMountPointOf',
                'Only a settled host that is not forwarded has a directory to share.'
            );
        }

        return (string)$row['mountPoint'];
    }
}
```

- [ ] **Step 7: Write the service**

Create `Service/SubdomainService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\FtpGroups;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;

/**
 * Subdomains of a main domain (`subdomain`) and of an alias
 * (`subdomain_alias`), which the schema calls one type (spec section 7.3).
 *
 * CORE-DEBT(C3): the rules are gui/public/client/subdomain_add.php,
 *   subdomain_edit.php, and the delete helpers in gui/include/Client.php.
 *   Retire when SubdomainService lands in core (spec section 21, C3 row 4).
 */
final class SubdomainService
{
    const TAGS = array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN);

    /** @var Toolkit */
    private $kit;

    /** @var VhostInput */
    private $vhostInput;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
        $this->vhostInput = new VhostInput($kit);
    }

    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $parent = $kit->guard()->target(
            $caller, $input['parentId'] ?? null, array(NodeType::DOMAIN, NodeType::DOMAIN_ALIAS),
            Scope::DOMAINS_WRITE, 'input.parentId'
        );
        $account = $kit->accounts()->customer($parent->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_subd_limit'),
            $kit->counts()->subdomains(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'subdomains');

        // 5. The pages list only settled parents (subdomain_add.php:72-85).
        $parentRow = $kit->vhost($parent->getTag(), $parent->getKey());
        Guard::requireState($parentRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $label = mb_strtolower(trim((string)($input['label'] ?? '')));

        if ($label === '') {
            throw Guard::badInput('input.label', 'A subdomain label is required.');
        }

        if (VhostRules::isReservedLabel($label)) {
            throw Guard::badInput('input.label', 'www is not allowed as a subdomain label.');
        }

        $labelAscii = $core->toAscii($label);
        $nameAscii = $labelAscii . '.' . $parentRow['name'];
        $reason = $core->domainNameError($nameAscii);

        if ($reason !== null) {
            throw Guard::badInput('input.label', $reason);
        }

        $kind = $parent->getTag() === NodeType::DOMAIN ? VirtualHosts::KIND_DMN : VirtualHosts::KIND_ALS;
        $forwarding = $this->vhostInput->forwarding($input['forwarding'] ?? null, $nameAscii, 'input.forwarding');
        $mountPoint = isset($input['sharedMountPointOf'])
            ? $this->vhostInput->sharedMountPoint($caller, $input['sharedMountPointOf'], $account->getAdminId())
            : VhostRules::subdomainMountPoint($kind, $labelAscii, (string)$parentRow['name']);
        $wildcard = VhostRules::wildcard((bool)($input['wildcard'] ?? false));

        // 7.
        Guard::requireQuota($quota, 'subdomains');

        if ($kit->vhosts()->nameInUse($nameAscii)) {
            throw Guard::conflict(sprintf('%s is already in use.', $core->toUnicode($nameAscii)));
        }

        // The name listeners are given is the page's: the label as typed,
        // lower-cased, on the parent's stored name (subdomain_add.php:234).
        $subdomainName = $label . '.' . $parentRow['name'];
        $params = array(
            'subdomainName'  => $subdomainName,
            'subdomainType'  => $kind,
            'parentDomainId' => (int)$parent->getKey(),
            'mountPoint'     => $mountPoint,
            'documentRoot'   => VhostRules::HTDOCS,
            'forwardUrl'     => $forwarding['url'],
            'forwardType'    => $forwarding['type'],
            'forwardHost'    => $forwarding['host'],
            'wildcardAlias'  => $wildcard,
            'customerId'     => $account->getAdminId()
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:386-483.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $kind, $parent, $labelAscii, $nameAscii, $params) {
            $core->dispatch(Events::onBeforeAddSubdomain, $params);

            $values = array(
                (int)$parent->getKey(), $labelAscii, $params['mountPoint'], VhostRules::HTDOCS,
                $params['forwardUrl'], $params['forwardType'], $params['forwardHost'],
                $params['wildcardAlias'], 'toadd'
            );

            if ($kind === VirtualHosts::KIND_ALS) {
                $kit->db()->execute(
                    '
                        INSERT INTO subdomain_alias (
                            alias_id, subdomain_alias_name, subdomain_alias_mount,
                            subdomain_alias_document_root, subdomain_alias_url_forward,
                            subdomain_alias_type_forward, subdomain_alias_host_forward,
                            subdomain_alias_wildcard_alias, subdomain_alias_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    $values
                );
            } else {
                $kit->db()->execute(
                    '
                        INSERT INTO subdomain (
                            domain_id, subdomain_name, subdomain_mount, subdomain_document_root,
                            subdomain_url_forward, subdomain_type_forward, subdomain_host_forward,
                            subdomain_wildcard_alias, subdomain_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    $values
                );
            }

            $id = $kit->db()->lastInsertId();

            $core->savePhpIni(
                $account->getResellerId(), $account->getAdminId(), $account->getDomainId(), $id,
                $kind === VirtualHosts::KIND_DMN ? 'sub' : 'subals'
            );

            if ($core->config('CREATE_DEFAULT_EMAIL_ADDRESSES', false)) {
                $core->createDefaultMailAccounts(
                    $account->getDomainId(), $account->getEmail(), $nameAscii,
                    MailType::toMailType(
                        $kind === VirtualHosts::KIND_DMN ? MailType::HOST_SUB : MailType::HOST_ALSSUB,
                        MailType::KIND_FORWARD
                    ),
                    $id
                );
            }

            $core->dispatch(Events::onAfterAddSubdomain, $params + array('subdomainId' => $id));

            return $id;
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(
            sprintf('A new subdomain (%s) has been created by %s', $subdomainName, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(
            $kind === VirtualHosts::KIND_DMN ? NodeType::SUBDOMAIN : NodeType::ALIAS_SUBDOMAIN,
            $id
        );
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, self::TAGS, Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());

        // subdomain_edit.php:426 asks 'subdomains' for both kinds.
        Guard::requireFeature((int)$account->domain('domain_subd_limit') >= 0, 'subdomains');

        $row = $kit->vhost($target->getTag(), $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK));

        $values = $this->vhostInput->update($account, $row, $input);
        $isAlias = $target->getTag() === NodeType::ALIAS_SUBDOMAIN;

        $params = array(
            'subdomainId'   => (int)$target->getKey(),
            'subdomainName' => $row['name'],
            // The page's GET 'type': 'dmn' for a subdomain, 'als' for an alias's.
            'subdomainType' => $isAlias ? VirtualHosts::KIND_ALS : VirtualHosts::KIND_DMN,
            'mountPoint'    => $row['mountPoint'],
            'documentRoot'  => $values['documentRoot'],
            'forwardUrl'    => $values['url'],
            'forwardType'   => $values['type'],
            'forwardHost'   => $values['host'],
            'wildcardAlias' => $values['wildcard']
        );

        // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_edit.php:357-409.
        // CORE-DEBT(C11): C11 item 2 - the alias table's own wildcard column,
        //   and the flag as yes|no.
        $kit->writer()->run(function () use ($kit, $core, $isAlias, $params, $values) {
            $core->dispatch(Events::onBeforeEditSubdomain, $params);

            $kit->db()->execute(
                $isAlias
                    ? '
                        UPDATE subdomain_alias
                        SET subdomain_alias_document_root = ?, subdomain_alias_url_forward = ?,
                            subdomain_alias_type_forward = ?, subdomain_alias_host_forward = ?,
                            subdomain_alias_wildcard_alias = ?, subdomain_alias_status = ?
                        WHERE subdomain_alias_id = ?
                    '
                    : '
                        UPDATE subdomain
                        SET subdomain_document_root = ?, subdomain_url_forward = ?,
                            subdomain_type_forward = ?, subdomain_host_forward = ?,
                            subdomain_wildcard_alias = ?, subdomain_status = ?
                        WHERE subdomain_id = ?
                    ',
                array(
                    $values['documentRoot'], $values['url'], $values['type'], $values['host'],
                    $values['wildcard'], 'tochange', $params['subdomainId']
                )
            );

            $core->dispatch(Events::onAfterEditSubdomain, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s updated properties of the %s subdomain', $caller->getUsername(), $core->toUnicode((string)$row['name'])),
            E_USER_NOTICE
        );

        return new ObjectRef($target->getTag(), $target->getKey());
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, self::TAGS, Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        $isAlias = $target->getTag() === NodeType::ALIAS_SUBDOMAIN;

        // subdomain_delete.php:36 asks 'subdomains'; alssub_delete.php:36 asks
        // 'domain_aliases'.
        if ($isAlias) {
            Guard::requireFeature((int)$account->domain('domain_alias_limit') >= 0, 'domainAliases');
        } else {
            Guard::requireFeature((int)$account->domain('domain_subd_limit') >= 0, 'subdomains');
        }

        $row = $kit->vhost($target->getTag(), $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $key = (int)$target->getKey();
        $name = (string)$row['name'];
        $type = $isAlias ? 'alssub' : 'sub';
        $params = array('subdomainId' => $key, 'subdomainName' => $name, 'subdomainType' => $type, 'type' => $type);

        // CORE-DEBT(C1): transcribed from gui/include/Client.php:432-613 and
        //   618-806, which authorise on $_SESSION['user_id'] as the customer
        //   and exit on a miss (measurement M3). Retire when C1 lands.
        // CORE-DEBT(C11): C11 item 6 - names escaped for LIKE and for the
        //   member regex, and protected areas matched by path segment.
        $kit->writer()->run(function () use ($kit, $core, $account, $isAlias, $key, $name, $type, $row, $params) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteSubdomain, $params);

            $groups = new FtpGroups($db);
            $group = $groups->withMemberOn($name);

            if ($group !== null) {
                $groups->removeMembers($group, static function (string $member) use ($name) {
                    return (bool)preg_match('/@' . preg_quote($name, '/') . '$/', $member);
                });
            }

            $db->execute(
                'DELETE FROM php_ini WHERE domain_id = ? AND domain_type = ?',
                array($key, $isAlias ? 'subals' : 'sub')
            );
            $db->execute(
                "UPDATE ftp_users SET status = 'todelete' WHERE userid LIKE ?",
                array('%@' . VhostRules::likeEscape($name))
            );
            $db->execute(
                "UPDATE mail_users SET status = 'todelete' WHERE sub_id = ? AND mail_type LIKE ?",
                array($key, $isAlias ? '%alssub\\_%' : '%subdom\\_%')
            );
            $db->execute(
                "UPDATE ssl_certs SET status = 'todelete' WHERE domain_id = ? AND domain_type = ?",
                array($key, $type)
            );

            $mount = rtrim($core->normalisePath((string)$row['mountPoint']), '/');
            $db->execute(
                "UPDATE htaccess SET status = 'todelete' WHERE dmn_id = ? AND (path = ? OR path LIKE ?)",
                array($account->getDomainId(), $mount, VhostRules::likeEscape($mount) . '/%')
            );

            $db->execute(
                $isAlias
                    ? "UPDATE subdomain_alias SET subdomain_alias_status = 'todelete' WHERE subdomain_alias_id = ?"
                    : "UPDATE subdomain SET subdomain_status = 'todelete' WHERE subdomain_id = ?",
                array($key)
            );

            $core->dispatch(Events::onAfterDeleteSubdomain, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('Deletion of the %s subdomain has been scheduled by %s', $core->toUnicode($name), $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef($target->getTag(), $target->getKey());
    }
}
```

- [ ] **Step 8: Run the service test**

Run: `tools/test.sh --filter SubdomainServiceTest`
Expected: PASS, 39 tests.

- [ ] **Step 9: Run everything**

Run: `tools/test.sh`
Expected: all PASS. The lint step's `CORE-DEBT` inventory now lists C11, and passes because Step 1 added the item.

- [ ] **Step 10: Commit**

```bash
git add Service/SubdomainService.php Service/VhostInput.php Repository/FtpGroups.php Repository/VirtualHosts.php test/integration/ServiceTestCase.php test/integration/SubdomainServiceTest.php test/integration/VirtualHostsTest.php docs/SPECIFICATION.md
git commit -m "Create, update and delete subdomains of both kinds

The rules are the subdomain pages' and the two delete helpers', transcribed
with a marker at each, in spec 8.1's order. The delete helpers cannot be
called: they authorise on the session as though the caller were the
customer, and exit when a reseller is not.

Four places the pages are wrong are not copied, and C11 in the spec now
lists them with the rest of phase 3's findings. The one that matters most:
the helpers match FTP users with LIKE '%@name', and '_' is legal in a name
and a wildcard in LIKE, so deleting a_b.test would schedule another
customer's FTP users on axb.test for deletion. A test pins that it does not.

A name in use is CONFLICT by an explicit check, because none of the vhost
tables has a unique key on its name."
```

---
## Task 7: The main domain and domain aliases — `sonnet`

`domainUpdate`, and `domainAliasCreate`, `domainAliasUpdate`, `domainAliasDelete`, as services. The GraphQL surface is Task 8.

**Files:**
- Create: `Service/DomainService.php`, `Service/DomainAliasService.php`
- Create: `test/integration/DomainServiceTest.php`, `test/integration/DomainAliasServiceTest.php`

**Interfaces:**
- Consumes: `Toolkit`, `Guard`, `ObjectRef`, `CustomerAccount` (Task 3); `VhostInput`, `FtpGroups`, `ServiceTestCase` (Task 6); `VhostRules` (Task 5); `Core::domainExists()`, `Core::sendAliasOrderEmail()`, `Core::savePhpIni()`, `Core::createDefaultMailAccounts()` (Task 2).
- Produces:
  ```php
  new DomainService(Toolkit $kit)
  DomainService::update(Identity $caller, string $id, array $input): ObjectRef          // tag DOMAIN
  new DomainAliasService(Toolkit $kit)
  DomainAliasService::create(Identity $caller, array $input): ObjectRef                 // tag DOMAIN_ALIAS
  DomainAliasService::update(Identity $caller, string $id, array $input): ObjectRef
  DomainAliasService::delete(Identity $caller, string $id): ObjectRef
      // an ordered alias is removed outright: ObjectRef carries the normalised row as its snapshot
  ```

- [ ] **Step 1: Write the failing tests**

Create `test/integration/DomainServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DomainService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DomainServiceTest extends ServiceTestCase
{
    private function service(): DomainService
    {
        return new DomainService($this->kit);
    }

    private function id(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function row(): array
    {
        return $this->db->row(
            'SELECT document_root, url_forward, type_forward, host_forward, wildcard_alias, domain_status FROM domain WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
    }

    public function testForwardingTheMainDomain(): void
    {
        $ref = $this->service()->update($this->caller('customer'), $this->id(), array(
            'forwarding' => array('url' => 'https://example.net/app', 'type' => 'TEMPORARY_307')
        ));

        self::assertSame(NodeType::DOMAIN, $ref->getTag());
        self::assertSame(array(
            'document_root'  => '/htdocs',
            'url_forward'    => 'https://example.net/app/',
            'type_forward'   => '307',
            'host_forward'   => 'Off',
            'wildcard_alias' => 'no',
            'domain_status'  => 'tochange'
        ), $this->row());
        self::assertSame(array('onBeforeEditDomain', 'onAfterEditDomain'), $this->core->eventNames());
        self::assertSame(array(
            'domainId'      => $this->fixture->domainId(),
            'domainName'    => $this->fixture->domainName(),
            'mountPoint'    => '/',
            'documentRoot'  => '/htdocs',
            'forwardUrl'    => 'https://example.net/app/',
            'forwardType'   => '307',
            'forwardHost'   => 'Off',
            'wildcardAlias' => 'no'
        ), $this->core->events[0][1]);
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            'The ' . $this->fixture->domainName() . ' domain properties were updated by sgwtcustomer',
            $this->core->logs[0][0]
        );
    }

    public function testTheMainDomainsDocumentRootIsCheckedUnderItsHtdocs(): void
    {
        $this->service()->update($this->caller('customer'), $this->id(), array('documentRoot' => '/htdocs/public'));

        self::assertSame(array(array('sgwtcustomer', '/htdocs', '/public')), $this->probe->asked);
        self::assertSame('/htdocs/public', $this->row()['document_root']);
    }

    public function testADomainCannotForwardToItself(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array(
                'forwarding' => array('url' => 'https://' . $this->fixture->domainName() . '/', 'type' => 'FOUND_302')
            ));
        });

        self::assertSame('input.forwarding.url', $e->getExtensions()['field']);
    }

    public function testAnUnsettledDomainIsAConflict(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('wildcard' => true));
        });
    }

    public function testADisabledDomainIsForbidden(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'disabled' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('wildcard' => true));
        });

        self::assertSame('DISABLED', $e->getExtensions()['state']);
    }

    public function testASubdomainIdentifierIsNotADomain(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function () {
            $this->service()->update(
                $this->caller('customer'),
                GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
                array('wildcard' => true)
            );
        });
    }

    public function testTheResellerIsLoggedAsThemselves(): void
    {
        $this->service()->update($this->caller('reseller'), $this->id(), array('wildcard' => true));

        self::assertStringEndsWith('were updated by sgwtreseller', $this->core->logs[0][0]);
    }
}
```

Create `test/integration/DomainAliasServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DomainAliasServiceTest extends ServiceTestCase
{
    private function service(): DomainAliasService
    {
        return new DomainAliasService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function aliasId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId());
    }

    private function aliasRow(int $id): ?array
    {
        return $this->db->row('SELECT * FROM domain_aliasses WHERE alias_id = ?', array($id));
    }

    // ---- create ---------------------------------------------------------

    public function testACustomersAliasIsOrderedAndTheResellerIsNotified(): void
    {
        // Decision D13.
        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
        ));

        self::assertSame(NodeType::DOMAIN_ALIAS, $ref->getTag());

        $row = $this->aliasRow($ref->getKey());
        self::assertSame($this->fixture->domainId(), (int)$row['domain_id']);
        self::assertSame('sgwtnew.test', $row['alias_name']);
        self::assertSame('/sgwtnew.test', $row['alias_mount']);
        self::assertSame('/htdocs', $row['alias_document_root']);
        self::assertSame($this->fixture->ipId(), (int)$row['alias_ip_id']);
        self::assertSame('ordered', $row['alias_status']);

        self::assertSame(0, $this->core->requests, 'an order is not provisioned');
        self::assertSame(array(array($this->fixture->customerId(), 'sgwtnew.test')), $this->core->aliasOrders);
        self::assertSame('A new domain alias (sgwtnew.test) has been ordered by sgwtcustomer', $this->core->logs[0][0]);
        self::assertSame('0', (string)$this->db->value('SELECT COUNT(*) FROM mail_users WHERE sub_id = ? AND mail_type = ?', array($ref->getKey(), 'alias_forward')));
        self::assertSame('1', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($ref->getKey())));
        self::assertSame(array('onBeforeAddDomainAlias', 'onAfterAddDomainAlias'), $this->core->eventNames());
        self::assertSame($ref->getKey(), $this->core->events[1][1]['domainAliasId']);
    }

    public function testAResellersAliasIsCreatedWithItsDefaultMailAccounts(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), array(
            'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
        ));

        self::assertSame('toadd', $this->aliasRow($ref->getKey())['alias_status']);
        self::assertSame(1, $this->core->requests);
        self::assertSame(array(), $this->core->aliasOrders);
        self::assertSame('A new domain alias (sgwtnew.test) has been created by sgwtreseller', $this->core->logs[0][0]);
        self::assertSame(
            array('abuse', 'hostmaster', 'postmaster', 'webmaster'),
            array_column($this->db->rows(
                'SELECT mail_acc FROM mail_users WHERE sub_id = ? AND mail_type = ? ORDER BY mail_acc',
                array($ref->getKey(), 'alias_forward')
            ), 'mail_acc')
        );
    }

    public function testWwwIsStrippedAsOftenAsItAppears(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'WWW.www.sgwtnew.test'
        ));

        self::assertSame('sgwtnew.test', $this->aliasRow($ref->getKey())['alias_name']);
    }

    public function testAUnicodeNameIsStoredAsPunycode(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'bücher-sgwt.test'
        ));

        self::assertSame(
            $this->core->toAscii('bücher-sgwt.test'),
            $this->aliasRow($ref->getKey())['alias_name']
        );
        self::assertStringStartsWith('xn--', $this->aliasRow($ref->getKey())['alias_name']);
    }

    public function testATakenNameIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => $this->fixture->aliasName()
            ));
        });
    }

    public function testASubzoneOfAnotherResellersDomainIsAConflict(): void
    {
        // imscp_domain_exists(): 'sgwtstranger.test' belongs to the other
        // reseller's customer.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'shop.sgwtstranger.test'
            ));
        });
    }

    public function testAnInvalidNameIsRefusedOnItsField(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'no-dot'
            ));
        });

        self::assertSame('input.name', $e->getExtensions()['field']);
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        $this->db->execute('UPDATE domain SET domain_alias_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
            ));
        });
    }

    public function testOrderedAliasesDoNotCountTowardsTheLimit(): void
    {
        // Counting.php:497.
        $this->db->execute('UPDATE domain SET domain_alias_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
        ));

        self::assertNotNull($this->aliasRow($ref->getKey()));
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_alias_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
            ));
        });
    }

    // ---- update ---------------------------------------------------------

    public function testAnAliasUpdateWritesThePanelsColumns(): void
    {
        $this->service()->update($this->caller('customer'), $this->aliasId(), array('wildcard' => true));

        $row = $this->aliasRow($this->fixture->aliasId());
        self::assertSame('yes', $row['wildcard_alias']);
        self::assertSame('https://example.net/', $row['url_forward'], 'forwarding kept');
        self::assertSame('tochange', $row['alias_status']);
        self::assertSame(array('onBeforeEditDomainAlias', 'onAfterEditDomainAlias'), $this->core->eventNames());
        self::assertSame($this->fixture->aliasId(), $this->core->events[0][1]['domainAliasId']);
        self::assertSame(
            'sgwtcustomer updated properties of the ' . $this->fixture->aliasName() . ' domain alias',
            $this->core->logs[0][0]
        );
    }

    public function testRemovingForwardingAndSettingADocumentRootInOneUpdate(): void
    {
        $this->service()->update($this->caller('customer'), $this->aliasId(), array(
            'forwarding' => null, 'documentRoot' => '/htdocs/site'
        ));

        $row = $this->aliasRow($this->fixture->aliasId());
        self::assertSame('no', $row['url_forward']);
        self::assertSame('/htdocs/site', $row['alias_document_root']);
        self::assertSame(array(array('sgwtcustomer', '/alias/htdocs', '/site')), $this->probe->asked);
    }

    public function testAnOrderedAliasCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $e = $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->update($this->caller('customer'), $this->aliasId(), array('wildcard' => true));
        });

        self::assertSame('ORDERED', $e->getExtensions()['state']);
    }

    // ---- delete ---------------------------------------------------------

    public function testDeletingSchedulesTheAliasAndEverythingUnderIt(): void
    {
        $alias = $this->fixture->aliasName();
        $blog = $this->fixture->aliasSubdomainId();
        $this->db->execute("UPDATE subdomain_alias SET subdomain_alias_status = 'ok' WHERE subdomain_alias_id = ?", array($blog));

        $this->insert('ftp_users', array(
            'userid' => 'a@' . $alias, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            'uid' => 64003, 'gid' => 64003, 'shell' => '/bin/sh', 'homedir' => '/x', 'status' => 'ok'
        ));
        $this->insert('ftp_users', array(
            'userid' => 'b@blog.' . $alias, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            'uid' => 64003, 'gid' => 64003, 'shell' => '/bin/sh', 'homedir' => '/x', 'status' => 'ok'
        ));
        $this->insert('ftp_group', array(
            'groupname' => 'sgwtcustomer', 'gid' => 64003,
            'members'   => 'a@' . $alias . ',b@blog.' . $alias . ',' . $this->fixture->ftpUserId()
        ));
        $aliasMail = $this->insert('mail_users', array(
            'mail_acc' => 'info', 'mail_pass' => '_no_', 'mail_forward' => 'x@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'alias_forward',
            'sub_id' => $this->fixture->aliasId(), 'status' => 'ok', 'po_active' => 'no', 'quota' => 0,
            'mail_addr' => 'info@' . $alias
        ));
        $dns = $this->insert('domain_dns', array(
            'domain_id' => $this->fixture->domainId(), 'alias_id' => $this->fixture->aliasId(),
            'domain_dns' => 'www', 'domain_class' => 'IN', 'domain_type' => 'CNAME',
            'domain_text' => $alias . '.', 'owned_by' => 'custom_dns_feature', 'domain_dns_status' => 'ok'
        ));
        $this->insert('php_ini', array('admin_id' => $this->fixture->customerId(), 'domain_id' => $this->fixture->aliasId(), 'domain_type' => 'als'));
        $this->insert('php_ini', array('admin_id' => $this->fixture->customerId(), 'domain_id' => $blog, 'domain_type' => 'subals'));
        $this->insert('ssl_certs', array('domain_id' => $this->fixture->aliasId(), 'domain_type' => 'als', 'private_key' => 'k', 'certificate' => 'c', 'status' => 'ok'));
        $this->insert('ssl_certs', array('domain_id' => $blog, 'domain_type' => 'alssub', 'private_key' => 'k', 'certificate' => 'c', 'status' => 'ok'));

        $ref = $this->service()->delete($this->caller('customer'), $this->aliasId());

        self::assertNull($ref->getSnapshot());
        self::assertSame('todelete', $this->aliasRow($this->fixture->aliasId())['alias_status']);
        self::assertSame('todelete', $this->db->value('SELECT subdomain_alias_status FROM subdomain_alias WHERE subdomain_alias_id = ?', array($blog)));
        self::assertSame('todelete', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array('a@' . $alias)));
        self::assertSame('todelete', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array('b@blog.' . $alias)));
        self::assertSame('ok', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array($this->fixture->ftpUserId())));
        self::assertSame($this->fixture->ftpUserId(), $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertSame('todelete', $this->db->value('SELECT status FROM mail_users WHERE mail_id = ?', array($aliasMail)));
        self::assertSame('todelete', $this->db->value('SELECT status FROM mail_users WHERE mail_id = ?', array($this->fixture->forwardId())), 'hello@blog.<alias>');
        self::assertNull($this->db->value('SELECT domain_dns_id FROM domain_dns WHERE domain_dns_id = ?', array($dns)));
        self::assertSame('0', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE (domain_id = ? AND domain_type = 'als') OR (domain_id = ? AND domain_type = 'subals')", array($this->fixture->aliasId(), $blog)));
        self::assertSame('2', (string)$this->db->value("SELECT COUNT(*) FROM ssl_certs WHERE status = 'todelete' AND ((domain_id = ? AND domain_type = 'als') OR (domain_id = ? AND domain_type = 'alssub'))", array($this->fixture->aliasId(), $blog)));
        self::assertSame(array('onBeforeDeleteDomainAlias', 'onAfterDeleteDomainAlias'), $this->core->eventNames());
        self::assertSame(array('domainAliasId' => $this->fixture->aliasId(), 'domainAliasName' => $alias), $this->core->events[0][1]);
        self::assertSame(1, $this->core->requests);
        self::assertSame('sgwtcustomer scheduled deletion of the ' . $alias . ' domain alias', $this->core->logs[0][0]);
    }

    public function testCancellingAnOrderRemovesTheRowAndItsPhpIni(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));
        $this->insert('php_ini', array('admin_id' => $this->fixture->customerId(), 'domain_id' => $this->fixture->aliasId(), 'domain_type' => 'als'));

        $ref = $this->service()->delete($this->caller('customer'), $this->aliasId());

        self::assertNull($this->aliasRow($this->fixture->aliasId()));
        self::assertSame('0', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($this->fixture->aliasId())), 'C11 item 8');
        self::assertSame(array(), $this->core->events, 'an order that never reached the backend dispatches nothing');
        self::assertSame(0, $this->core->requests);
        self::assertSame('ordered', $ref->getSnapshot()['status']);
        self::assertSame($this->fixture->aliasName(), $ref->getSnapshot()['name']);
    }

    public function testAPendingAliasCannotBeDeleted(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'toadd' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->delete($this->caller('customer'), $this->aliasId());
        });
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `tools/test.sh --filter 'DomainServiceTest|DomainAliasServiceTest'`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\DomainService" not found` and the same for `DomainAliasService`.

- [ ] **Step 3: Write `DomainService`**

Create `Service/DomainService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

/**
 * The customer's own main domain: forwarding, document root and wildcard
 * (spec section 1.1). Everything else about a domain is the reseller's, in
 * plan 4.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/domain_edit.php:179-344.
 *   Retire when DomainService lands in core (spec section 21, C3 row 7).
 */
final class DomainService
{
    /** @var Toolkit */
    private $kit;

    /** @var VhostInput */
    private $vhostInput;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
        $this->vhostInput = new VhostInput($kit);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN), Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());

        // domain_edit.php asks no feature: every customer may forward their
        // own domain.
        $row = $kit->vhost(NodeType::DOMAIN, $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK));

        $values = $this->vhostInput->update($account, $row, $input);

        $params = array(
            'domainId'      => (int)$target->getKey(),
            'domainName'    => $row['name'],
            'mountPoint'    => '/',
            'documentRoot'  => $values['documentRoot'],
            'forwardUrl'    => $values['url'],
            'forwardType'   => $values['type'],
            'forwardHost'   => $values['host'],
            'wildcardAlias' => $values['wildcard']
        );

        $kit->writer()->run(function () use ($kit, $core, $params, $values) {
            $core->dispatch(Events::onBeforeEditDomain, $params);

            $kit->db()->execute(
                '
                    UPDATE domain
                    SET document_root = ?, url_forward = ?, type_forward = ?, host_forward = ?,
                        wildcard_alias = ?, domain_status = ?
                    WHERE domain_id = ?
                ',
                array(
                    $values['documentRoot'], $values['url'], $values['type'], $values['host'],
                    $values['wildcard'], 'tochange', $params['domainId']
                )
            );

            $core->dispatch(Events::onAfterEditDomain, $params);
        });

        $core->sendRequest();

        // The page's format string takes the domain name first; it passes the
        // username twice (domain_edit.php:336-339), which is not worth an
        // issue of its own.
        $core->writeLog(
            sprintf('The %s domain properties were updated by %s', $core->toUnicode((string)$row['name']), $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN, $target->getKey());
    }
}
```

- [ ] **Step 4: Write `DomainAliasService`**

Create `Service/DomainAliasService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\FtpGroups;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;

/**
 * Domain aliases of a customer's main domain.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/alias_add.php, alias_edit.php,
 *   alias_delete.php, alias_order_delete.php and deleteDomainAlias() in
 *   gui/include/Shared.php. Retire when DomainAliasService lands in core
 *   (spec section 21, C3 row 5).
 */
final class DomainAliasService
{
    /** @var Toolkit */
    private $kit;

    /** @var VhostInput */
    private $vhostInput;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
        $this->vhostInput = new VhostInput($kit);
    }

    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $domain = $kit->guard()->target(
            $caller, $input['domainId'] ?? null, array(NodeType::DOMAIN), Scope::DOMAINS_WRITE, 'input.domainId'
        );
        $account = $kit->accounts()->customer($domain->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_alias_limit'),
            $kit->counts()->domainAliases(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'domainAliases');

        // 5. The page does not ask; an alias hung off a domain mid-change
        //    would be provisioned against half of that change.
        $domainRow = $kit->vhost(NodeType::DOMAIN, $domain->getKey());
        Guard::requireState($domainRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $name = VhostRules::stripWww(mb_strtolower(trim((string)($input['name'] ?? ''))));

        if ($name === '') {
            throw Guard::badInput('input.name', 'A domain alias name is required.');
        }

        $reason = $core->domainNameError($name);

        if ($reason !== null) {
            throw Guard::badInput('input.name', $reason);
        }

        $nameAscii = $core->toAscii($name);
        $forwarding = $this->vhostInput->forwarding($input['forwarding'] ?? null, $nameAscii, 'input.forwarding');
        $mountPoint = isset($input['sharedMountPointOf'])
            ? $this->vhostInput->sharedMountPoint($caller, $input['sharedMountPointOf'], $account->getAdminId())
            : VhostRules::aliasMountPoint($nameAscii);
        $wildcard = VhostRules::wildcard((bool)($input['wildcard'] ?? false));

        // 7.
        Guard::requireQuota($quota, 'domainAliases');

        if ($core->domainExists($nameAscii, $account->getResellerId())) {
            throw Guard::conflict(sprintf('Domain %s is unavailable.', $name));
        }

        // Decision D13: alias_add.php:370,402.
        $ordered = $caller->getRole() === Identity::ROLE_CUSTOMER;

        $params = array(
            'domainId'        => $account->getDomainId(),
            'domainAliasName' => $nameAscii,
            'mountPoint'      => $mountPoint,
            'documentRoot'    => VhostRules::HTDOCS,
            'forwardUrl'      => $forwarding['url'],
            'forwardType'     => $forwarding['type'],
            'forwardHost'     => $forwarding['host'],
            'wildcardAlias'   => $wildcard
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/alias_add.php:373-449.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $ordered, $nameAscii, $params) {
            $core->dispatch(Events::onBeforeAddDomainAlias, $params);

            $kit->db()->execute(
                '
                    INSERT INTO domain_aliasses (
                        domain_id, alias_name, alias_mount, alias_document_root, alias_status,
                        alias_ip_id, url_forward, type_forward, host_forward, wildcard_alias
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ',
                array(
                    $account->getDomainId(), $nameAscii, $params['mountPoint'], VhostRules::HTDOCS,
                    $ordered ? 'ordered' : 'toadd', $account->getDomainIpId(), $params['forwardUrl'],
                    $params['forwardType'], $params['forwardHost'], $params['wildcardAlias']
                )
            );

            $id = $kit->db()->lastInsertId();

            $core->savePhpIni($account->getResellerId(), $account->getAdminId(), $account->getDomainId(), $id, 'als');

            // An ordered alias gets its default accounts when it is approved
            // (plan 4), as it does in the panel.
            if (!$ordered && $core->config('CREATE_DEFAULT_EMAIL_ADDRESSES', false)) {
                $core->createDefaultMailAccounts(
                    $account->getDomainId(), $account->getEmail(), $nameAscii,
                    MailType::toMailType(MailType::HOST_ALS, MailType::KIND_FORWARD), $id
                );
            }

            $core->dispatch(Events::onAfterAddDomainAlias, array(
                'domainId'        => $params['domainId'],
                'domainAliasName' => $params['domainAliasName'],
                'domainAliasId'   => $id,
                'mountPoint'      => $params['mountPoint'],
                'documentRoot'    => $params['documentRoot'],
                'forwardUrl'      => $params['forwardUrl'],
                'forwardType'     => $params['forwardType'],
                'forwardHost'     => $params['forwardHost'],
                'wildcardAlias'   => $params['wildcardAlias']
            ));

            return $id;
        });

        // 9, 10. An order is not provisioned, so the daemon is not asked.
        if ($ordered) {
            $core->sendAliasOrderEmail($account->getAdminId(), $name);
            $core->writeLog(
                sprintf('A new domain alias (%s) has been ordered by %s', $name, $caller->getUsername()),
                E_USER_NOTICE
            );
        } else {
            $core->sendRequest();
            $core->writeLog(
                sprintf('A new domain alias (%s) has been created by %s', $name, $caller->getUsername()),
                E_USER_NOTICE
            );
        }

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $id);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_alias_limit') >= 0, 'domainAliases');

        $row = $kit->vhost(NodeType::DOMAIN_ALIAS, $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK));

        $values = $this->vhostInput->update($account, $row, $input);

        $params = array(
            'domainAliasId' => (int)$target->getKey(),
            'mountPoint'    => $row['mountPoint'],
            'documentRoot'  => $values['documentRoot'],
            'forwardUrl'    => $values['url'],
            'forwardType'   => $values['type'],
            'forwardHost'   => $values['host'],
            'wildcardAlias' => $values['wildcard']
        );

        // CORE-DEBT(C3): transcribed from gui/public/client/alias_edit.php:303-341.
        $kit->writer()->run(function () use ($kit, $core, $params, $values) {
            $core->dispatch(Events::onBeforeEditDomainAlias, $params);

            $kit->db()->execute(
                '
                    UPDATE domain_aliasses
                    SET alias_document_root = ?, url_forward = ?, type_forward = ?, host_forward = ?,
                        wildcard_alias = ?, alias_status = ?
                    WHERE alias_id = ?
                ',
                array(
                    $values['documentRoot'], $values['url'], $values['type'], $values['host'],
                    $values['wildcard'], 'tochange', $params['domainAliasId']
                )
            );

            $core->dispatch(Events::onAfterEditDomainAlias, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s updated properties of the %s domain alias', $caller->getUsername(), $core->toUnicode((string)$row['name'])),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $target->getKey());
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_alias_limit') >= 0, 'domainAliases');

        $row = $kit->vhost(NodeType::DOMAIN_ALIAS, $target->getKey());
        Guard::requireState(
            $row['status'],
            array(Provisioning::STATE_OK, Provisioning::STATE_ERROR, Provisioning::STATE_ORDERED)
        );

        if ($row['status'] === 'ordered') {
            return $this->cancelOrder($caller, $row);
        }

        $key = (int)$target->getKey();
        $name = (string)$row['name'];
        $params = array('domainAliasId' => $key, 'domainAliasName' => $name);

        // CORE-DEBT(C3): transcribed from deleteDomainAlias(),
        //   gui/include/Shared.php:1022-1232, which swallows its own failure
        //   (measurement M4) and so cannot be called.
        // CORE-DEBT(C11): C11 item 6 - names escaped for LIKE and the member
        //   regex; FTP users limited to the owning customer.
        $kit->writer()->run(function () use ($kit, $core, $account, $key, $name, $row, $params) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteDomainAlias, $params);

            $groups = new FtpGroups($db);
            $group = $groups->ofCustomer($account->getUsername());

            if ($group !== null) {
                $groups->removeMembers($group, static function (string $member) use ($name) {
                    return (bool)preg_match('/@(?:.+\.)*' . preg_quote($name, '/') . '$/', $member);
                });
            }

            $db->execute('DELETE FROM domain_dns WHERE alias_id = ?', array($key));
            $db->execute("DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($key));
            $db->execute(
                "
                    DELETE t1 FROM php_ini AS t1
                    JOIN subdomain_alias AS t2 ON (t2.subdomain_alias_id = t1.domain_id AND t1.domain_type = 'subals')
                    WHERE t2.alias_id = ?
                ",
                array($key)
            );
            $db->execute(
                "UPDATE ftp_users SET status = 'todelete' WHERE admin_id = ? AND (userid LIKE ? OR userid LIKE ?)",
                array(
                    $account->getAdminId(),
                    '%@' . VhostRules::likeEscape($name),
                    '%@%.' . VhostRules::likeEscape($name)
                )
            );
            $db->execute(
                "
                    UPDATE mail_users SET status = 'todelete'
                    WHERE domain_id = ? AND (
                        (sub_id = ? AND mail_type LIKE '%alias\\_%')
                        OR (sub_id IN (SELECT subdomain_alias_id FROM subdomain_alias WHERE alias_id = ?)
                            AND mail_type LIKE '%alssub\\_%')
                    )
                ",
                array($account->getDomainId(), $key, $key)
            );
            $db->execute(
                "
                    UPDATE ssl_certs SET status = 'todelete'
                    WHERE domain_type = 'alssub'
                    AND domain_id IN (SELECT subdomain_alias_id FROM subdomain_alias WHERE alias_id = ?)
                ",
                array($key)
            );
            $db->execute("UPDATE ssl_certs SET status = 'todelete' WHERE domain_id = ? AND domain_type = 'als'", array($key));

            $mount = rtrim($core->normalisePath((string)$row['mountPoint']), '/');
            $db->execute(
                "UPDATE htaccess SET status = 'todelete' WHERE dmn_id = ? AND (path = ? OR path LIKE ?)",
                array($account->getDomainId(), $mount, VhostRules::likeEscape($mount) . '/%')
            );

            $db->execute("UPDATE subdomain_alias SET subdomain_alias_status = 'todelete' WHERE alias_id = ?", array($key));
            $db->execute("UPDATE domain_aliasses SET alias_status = 'todelete' WHERE alias_id = ?", array($key));

            $core->dispatch(Events::onAfterDeleteDomainAlias, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s scheduled deletion of the %s domain alias', $caller->getUsername(), $core->toUnicode($name)),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $key);
    }

    /**
     * An order the reseller never approved: nothing was provisioned, so the
     * row goes, with no events and no daemon.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/alias_order_delete.php:40-47.
     * CORE-DEBT(C11): C11 item 8 - the php_ini row alias_add.php created goes too.
     *
     * @param array<string, mixed> $row The normalised row, returned as the snapshot
     */
    private function cancelOrder(Identity $caller, array $row): ObjectRef
    {
        $kit = $this->kit;
        $key = (int)$row['key'];

        $kit->writer()->run(function () use ($kit, $key) {
            $kit->db()->execute("DELETE FROM domain_aliasses WHERE alias_id = ? AND alias_status = 'ordered'", array($key));
            $kit->db()->execute("DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($key));
        });

        // The page logs nothing; spec section 11 wants every API write in the
        // panel's log.
        $kit->core()->writeLog(
            sprintf('%s cancelled the order for the %s domain alias', $caller->getUsername(), $kit->core()->toUnicode((string)$row['name'])),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $key, $row);
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `tools/test.sh --filter 'DomainServiceTest|DomainAliasServiceTest'`
Expected: PASS — `DomainServiceTest` 7 tests, `DomainAliasServiceTest` 16 tests.

- [ ] **Step 6: Run everything**

Run: `tools/test.sh`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add Service/DomainService.php Service/DomainAliasService.php test/integration/DomainServiceTest.php test/integration/DomainAliasServiceTest.php
git commit -m "Update the main domain, and create, update and delete aliases

A customer's alias is ordered and a reseller's is created outright, because
that is what the panel decides by asking whether a reseller is logged in as
the customer, and the API's nearest equivalent is who the caller is. An
order dispatches no provisioning and notifies the reseller; cancelling one
removes the row and, unlike the panel, the php_ini row created with it.

deleteDomainAlias() swallows its own failure, so it is transcribed rather
than called, with the FTP user match escaped and limited to the owning
customer."
```

---
## Task 8: The first mutations in the schema: virtual hosts — `sonnet`

The `Mutation` type, `ForwardingInput`, and the seven vhost mutations, wired to Tasks 6 and 7. This is where the authorisation matrix first runs: 7 mutations × 6 actors, and 7 × 3 scope rows.

**Files:**
- Modify: `schema/schema.graphql`
- Create: `tools/print-schema.php`
- Modify: `test/schema/schema.printed.graphql` (regenerated)
- Create: `Resolver/VirtualHostMutations.php`
- Modify: `Api/Container.php` (`resolverMaps()`)
- Create: `test/integration/VirtualHostMutationsTest.php`

**Interfaces:**
- Consumes: `SubdomainService`, `DomainService`, `DomainAliasService` (Tasks 6, 7); `Container::toolkit()` (Task 3); `VirtualHostResolver::reference()` and `::shape()`; `BatchLoader::reset()`; `TypeResolver::identity()`; `ObjectRef`; `Test\Authz\AuthzTestCase` (Task 4).
- Produces:
  ```php
  new VirtualHostMutations(BatchLoader $loader, SubdomainService $subdomains, DomainAliasService $aliases,
                           DomainService $domains, VirtualHostResolver $virtualHosts, callable $toUnicode)
  VirtualHostMutations::map(): array   // the seven 'Mutation.*' keys below
  ```
  and, in the SDL: `type Mutation`, `input ForwardingInput`, `DomainUpdateInput`, `SubdomainCreateInput`, `SubdomainUpdateInput`, `DomainAliasCreateInput`, `DomainAliasUpdateInput`. Tasks 10, 11, 13 and 15 add fields to `type Mutation` and regenerate the snapshot with `tools/print-schema.php`.

- [ ] **Step 1: Add the snapshot tool**

Create `tools/print-schema.php`:

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

/**
 * Prints the schema the way test/schema/SchemaTest compares it, so that a
 * schema change's snapshot is regenerated rather than hand-edited. Run it in
 * the container, redirecting there, so that the file keeps its owner and its
 * line endings:
 *
 *   ../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql \
 *       && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql'
 *
 * Then read the diff: it is the change a reviewer will see.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use GraphQL\Utils\SchemaPrinter;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;

echo SchemaPrinter::doPrint((new SchemaFactory(
    dirname(__DIR__) . '/schema/schema.graphql',
    null,
    new ResolverMap(array()),
    array(TypeResolver::class, 'resolveType')
))->create());
```

Run it once before changing the SDL, to prove it reproduces the committed snapshot:

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql' && git diff --stat test/schema/schema.printed.graphql`
Expected: no output from `git diff --stat` — the tool prints exactly what is committed.

- [ ] **Step 2: See the matrix fail**

Add the `Mutation` type to `schema/schema.graphql` **without resolvers**, so the rows run against a schema that has the fields but nothing behind them. Replace

```graphql
schema {
  query: Query
}
```

with

```graphql
schema {
  query: Query
  mutation: Mutation
}
```

and append to the end of the file:

```graphql
"""
Changes to what the caller may reach.

Each field is one object in one transaction (spec section 8.1). Fields in one
document run in the order they are written, and they are NOT one transaction:
each commits on its own, so a document whose third mutation fails leaves the
first two done. Send one mutation per request unless that is what you want.

A mutation returns as soon as its intent is recorded. The object it returns
has provisioning.state PENDING - or ORDERED, for a customer's new domain alias
- until the i-MSCP backend has done the work, which takes seconds. Poll
node(id:) or pending to watch it settle; a backend failure appears there as
state ERROR, not as an error here.

An object whose provisioning is not settled cannot be changed: CONFLICT, with
extensions.retryAfterSeconds. A failed (ERROR) object can still be deleted.
"""
type Mutation {
  "Forwarding, document root and wildcard of a customer's main domain."
  domainUpdate(id: ID!, input: DomainUpdateInput!): Domain!

  subdomainCreate(input: SubdomainCreateInput!): Subdomain!
  subdomainUpdate(id: ID!, input: SubdomainUpdateInput!): Subdomain!
  """
  Schedules the subdomain for deletion, with its FTP users, mail accounts,
  certificates and protected areas.
  """
  subdomainDelete(id: ID!): Subdomain!

  """
  A customer's new alias is ORDERED until their reseller approves it. One
  created by the reseller or an administrator is PENDING.
  """
  domainAliasCreate(input: DomainAliasCreateInput!): DomainAlias!
  domainAliasUpdate(id: ID!, input: DomainAliasUpdateInput!): DomainAlias!
  """
  An ORDERED alias is withdrawn at once. Any other is scheduled for deletion
  with everything under it.
  """
  domainAliasDelete(id: ID!): DomainAlias!
}

input ForwardingInput {
  """
  http, https or ftp. Normalised as the panel normalises it: host lower-cased,
  path given a trailing slash. A host may not forward to its own root.
  """
  url: String!
  type: ForwardType!
  "PROXY only: pass the original Host header through."
  keepHost: Boolean = false
}

"""
An absent field keeps its current value; forwarding: null removes forwarding.
A forwarded host serves no document root, so documentRoot is refused unless
forwarding is off after the update.
"""
input DomainUpdateInput {
  "/htdocs, or a directory inside it that already exists - the same terms VirtualHost.documentRoot reads in."
  documentRoot: String
  forwarding: ForwardingInput
  wildcard: Boolean
}

input SubdomainCreateInput {
  "The Domain or DomainAlias to hang it off."
  parentId: ID!
  "The label alone, e.g. 'shop'. Unicode or punycode. 'www' is not allowed."
  label: String!
  "Serve the directory of another of the same customer's hosts instead of creating one. That host must be settled and not forwarded."
  sharedMountPointOf: ID
  forwarding: ForwardingInput
  wildcard: Boolean = false
}

"As DomainUpdateInput."
input SubdomainUpdateInput {
  documentRoot: String
  forwarding: ForwardingInput
  wildcard: Boolean
}

input DomainAliasCreateInput {
  "The customer's main domain."
  domainId: ID!
  "Unicode or punycode. A leading www. is dropped."
  name: DomainName!
  sharedMountPointOf: ID
  forwarding: ForwardingInput
  wildcard: Boolean = false
}

"As DomainUpdateInput."
input DomainAliasUpdateInput {
  documentRoot: String
  forwarding: ForwardingInput
  wildcard: Boolean
}
```

Run: `tools/test.sh --testsuite authz --filter 'domainUpdate|subdomain|domainAlias'`
Expected: FAIL, every row. With no resolver the field's fallback returns null into a non-null field, which graphql-php reports as an error with no previous exception, so each row's outcome is `GRAPHQL` — the owner, reseller and administrator expected `OK`, and the three strangers expected `NOT_FOUND`. `ResolverCoverageTest` fails too, for the same missing entries. This is the matrix seen failing before the resolver exists.

- [ ] **Step 3: Write the resolver**

Create `Resolver/VirtualHostMutations.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainService;
use iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/**
 * The seven vhost mutations. Thin, as every resolver here is: reset the batch
 * loader (decision D18), call the service, and read the object back through
 * the read model without the read scope gate (decision D19).
 */
final class VirtualHostMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var SubdomainService */
    private $subdomains;

    /** @var DomainAliasService */
    private $aliases;

    /** @var DomainService */
    private $domains;

    /** @var VirtualHostResolver */
    private $virtualHosts;

    /** @var callable fn(string): string */
    private $toUnicode;

    public function __construct(
        BatchLoader $loader, SubdomainService $subdomains, DomainAliasService $aliases,
        DomainService $domains, VirtualHostResolver $virtualHosts, callable $toUnicode
    ) {
        $this->loader = $loader;
        $this->subdomains = $subdomains;
        $this->aliases = $aliases;
        $this->domains = $domains;
        $this->virtualHosts = $virtualHosts;
        $this->toUnicode = $toUnicode;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.domainUpdate'      => array($this, 'resolveDomainUpdate'),
            'Mutation.subdomainCreate'   => array($this, 'resolveSubdomainCreate'),
            'Mutation.subdomainUpdate'   => array($this, 'resolveSubdomainUpdate'),
            'Mutation.subdomainDelete'   => array($this, 'resolveSubdomainDelete'),
            'Mutation.domainAliasCreate' => array($this, 'resolveDomainAliasCreate'),
            'Mutation.domainAliasUpdate' => array($this, 'resolveDomainAliasUpdate'),
            'Mutation.domainAliasDelete' => array($this, 'resolveDomainAliasDelete')
        );
    }

    public function resolveDomainUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->domains->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveSubdomainCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->subdomains->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveSubdomainUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->subdomains->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveSubdomainDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->subdomains->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveDomainAliasCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->aliases->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveDomainAliasUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->aliases->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveDomainAliasDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->aliases->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    /**
     * The written object: its snapshot when the row is gone, otherwise read
     * back now, after the commit.
     *
     * @return \GraphQL\Executor\Promise\Adapter\SyncPromise|array
     */
    private function vhost(ObjectRef $ref)
    {
        $snapshot = $ref->getSnapshot();

        if ($snapshot !== null) {
            return VirtualHostResolver::shape($snapshot, $this->toUnicode);
        }

        return $this->virtualHosts->reference($ref->getTag(), $ref->getKey());
    }
}
```

- [ ] **Step 4: Wire it**

In `Api/Container.php` add imports:

```php
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostMutations;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainService;
use iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService;
```

In `resolverMaps()`, immediately before `$this->maps = array(`, add:

```php
        // The write side. Services share one Toolkit, and resolvers read back
        // through the same loader and read resolvers as the queries above.
        $kit = $this->toolkit();
```

and add this entry as the last element of the `$this->maps` array (after `'QueryResolver' => $query->map()`, with a comma after that entry):

```php
            'VirtualHostMutations' => (new VirtualHostMutations(
                $loader, new SubdomainService($kit), new DomainAliasService($kit),
                new DomainService($kit), $virtualHosts, $toUnicode
            ))->map()
```

- [ ] **Step 5: Run the matrix**

Run: `tools/test.sh --testsuite authz --filter 'domainUpdate|subdomain|domainAlias'`
Expected: PASS — 42 matrix rows and 21 scope rows for the seven vhost mutations. Every other row is still skipped.

A row that fails here names its mutation and actor in the data set name, and its message is the full response envelope. Read it before changing anything: the matrix is the specification, and a failure is a service that answers the wrong actor.

- [ ] **Step 6: Write the document-level tests**

Create `test/integration/VirtualHostMutationsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

/**
 * What only a whole document shows: the returned object, the serial and
 * separate commits of spec section 8.5, decision D18's loader reset and
 * decision D19's scope rule. The authorisation matrix owns who may act.
 */
class VirtualHostMutationsTest extends AuthzTestCase
{
    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    public function testACreatedSubdomainReadsBackPending(): void
    {
        $result = $this->execute(
            'mutation($input: SubdomainCreateInput!) {
                subdomainCreate(input: $input) {
                    name label mountPoint documentRoot wildcard
                    provisioning { state raw settled }
                }
            }',
            array('input' => array('parentId' => $this->domainId(), 'label' => 'blog2', 'wildcard' => true)),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'name'         => 'blog2.' . $this->fixture->domainName(),
            'label'        => 'blog2',
            'mountPoint'   => '/blog2',
            'documentRoot' => '/htdocs',
            'wildcard'     => true,
            'provisioning' => array('state' => 'PENDING', 'raw' => 'toadd', 'settled' => false)
        ), $result['data']['subdomainCreate']);
        self::assertSame(1, $this->core->requests);
    }

    public function testAMutationsObjectIsReadAfterItsWriteNotFromAnEarlierLoad(): void
    {
        // Decision D18. The first field loads the domain's subdomain list into
        // the batch loader; without the reset, the second field's identical
        // edge would be answered from that memo and miss 'second'.
        $result = $this->execute(
            'mutation($a: SubdomainCreateInput!, $b: SubdomainCreateInput!) {
                first: subdomainCreate(input: $a) { parent { ... on Domain { subdomains { label } } } }
                second: subdomainCreate(input: $b) { parent { ... on Domain { subdomains { label } } } }
            }',
            array(
                'a' => array('parentId' => $this->domainId(), 'label' => 'first'),
                'b' => array('parentId' => $this->domainId(), 'label' => 'second')
            ),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        $labels = array_column($result['data']['second']['parent']['subdomains'], 'label');
        sort($labels);
        self::assertSame(array('first', 'second', 'shop'), $labels);
    }

    public function testEachMutationInADocumentCommitsOnItsOwn(): void
    {
        // Spec section 8.5: the first is done even though the second fails.
        $result = $this->execute(
            'mutation($a: SubdomainCreateInput!, $b: SubdomainCreateInput!) {
                first: subdomainCreate(input: $a) { label }
                second: subdomainCreate(input: $b) { label }
            }',
            array(
                'a' => array('parentId' => $this->domainId(), 'label' => 'once'),
                'b' => array('parentId' => $this->domainId(), 'label' => 'once')
            ),
            $this->fixture->identity('customer')
        );

        self::assertSame('once', $result['data']['first']['label']);
        self::assertNull($result['data']['second']);
        self::assertSame('CONFLICT', $result['errors'][0]['extensions']['code']);
        self::assertSame(array('second'), $result['errors'][0]['path']);
    }

    public function testTheWriteScopeAdmitsTheObjectButNotItsEdges(): void
    {
        // Decision D19. Subdomain.customer is Customer!, so its FORBIDDEN nulls
        // the returned object in the response - the write itself happened.
        $result = $this->execute(
            'mutation($input: SubdomainCreateInput!) { subdomainCreate(input: $input) { label customer { username } } }',
            array('input' => array('parentId' => $this->domainId(), 'label' => 'scoped')),
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );

        self::assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code']);
        self::assertSame(array('subdomainCreate', 'customer'), $result['errors'][0]['path']);
        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM subdomain WHERE domain_id = ? AND subdomain_name = 'scoped'",
            array($this->fixture->domainId())
        ));
    }

    public function testACustomersAliasReadsBackOrdered(): void
    {
        $result = $this->execute(
            'mutation($input: DomainAliasCreateInput!) { domainAliasCreate(input: $input) { name provisioning { state settled } } }',
            array('input' => array('domainId' => $this->domainId(), 'name' => 'sgwtnew.test')),
            $this->fixture->identity('customer')
        );

        self::assertSame(
            array('name' => 'sgwtnew.test', 'provisioning' => array('state' => 'ORDERED', 'settled' => true)),
            $result['data']['domainAliasCreate']
        );
    }

    public function testAWithdrawnOrderReturnsTheAliasAsItWas(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $result = $this->execute(
            'mutation($id: ID!) { domainAliasDelete(id: $id) { id name provisioning { state } } }',
            array('id' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'id'           => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
            'name'         => $this->fixture->aliasName(),
            'provisioning' => array('state' => 'ORDERED')
        ), $result['data']['domainAliasDelete']);
    }

    public function testAnUnsettledObjectsConflictCarriesTheRetryHint(): void
    {
        $result = $this->execute(
            'mutation($id: ID!) { subdomainDelete(id: $id) { id } }',
            array('id' => GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId())),
            $this->fixture->identity('customer')
        );

        self::assertSame(
            array('state' => 'PENDING', 'retryAfterSeconds' => 5, 'code' => 'CONFLICT'),
            $result['errors'][0]['extensions']
        );
    }
}
```

Run: `tools/test.sh --filter VirtualHostMutationsTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: Regenerate the snapshot and run everything**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql' && git diff test/schema/schema.printed.graphql | head -40`
Expected: the diff adds `type Mutation` and the six input types, and nothing else.

Run: `tools/test.sh`
Expected: all PASS. `ResolverCoverageTest` passes because every `Mutation.*` field has a map entry; `CatalogueCoverageTest` passes because every one has a catalogue row.

- [ ] **Step 8: Commit**

```bash
git add schema/schema.graphql test/schema/schema.printed.graphql tools/print-schema.php Resolver/VirtualHostMutations.php Api/Container.php test/integration/VirtualHostMutationsTest.php
git commit -m "Open the first mutations: the main domain, subdomains and aliases

Seven fields on a new Mutation type, each a thin resolver over the services.
Before the resolvers existed the authorisation matrix's rows for them ran
and failed; they now pass for all six accounts.

The Mutation description says what a client would otherwise learn by
surprise: fields in one document commit separately, a returned object is
pending until the backend settles it, and a backend failure is a state to
poll for rather than an error. Every resolver resets the batch loader first,
so a second mutation in a document cannot read the first one's memo."
```

---

## Checkpoint B: `code-review medium` over Waves 2 and 3

Run by the orchestrating session, as the [Execution mandate](#waves-and-checkpoints) sets out.

**Focus:**

- The matrix: does any row pass without executing the mutation — a skip that should be a failure, an outcome of `OK` read off a `null` field, a `prepare` that makes every actor succeed?
- `SubdomainService`, `DomainAliasService`, `DomainService` against [The shape of every mutation](#the-shape-of-every-mutation): the order of the six refusals in every method.
- Cross-tenant reach: `sharedMountPointOf`, the FTP group and user matching in deletes (C11 item 6), every `LIKE` and regex built from a name.
- `VhostInput::update()`: can a partial update produce a row the pages could never write — a document root set on a forwarded host, a proxy without its port rule?
- The resolvers: the loader reset (D18) in every one, and no read-scope gate on the returned object (D19). Anything wrong here is copied nine times in Waves 4 and 5; fix the pattern now.
- Events: names from the `Events` constants and parameters matching the page, in every service.

- [ ] **Step 1: The suite, twice**

Run: `tools/test.sh`, then again.
Expected: both green.

- [ ] **Step 2: Review**

Invoke the `code-review` skill with arguments `medium phase3-wave-2..HEAD`, passing the **Focus** list above.

- [ ] **Step 3: Resolve the findings**

Fix each finding that holds, in commits whose subjects begin `Fix checkpoint B:`, then run `tools/test.sh` until green.

- [ ] **Step 4: Open the next wave**

Run: `git tag -a phase3-wave-4 -m "Checkpoint B: <declined findings and why, or: none declined>"`

---

## Wave 4: Mail and FTP

Tasks 9–11. Ends at Checkpoint C.

---

## Task 9: Mail accounts and autoresponders — `sonnet`

`mailAccountCreate`, `mailAccountUpdate`, `mailAccountDelete` and `mailAutoresponderSet` as a service. Catch-alls and the GraphQL surface are Task 10.

**Files:**
- Create: `Service/MailService.php`
- Create: `test/integration/MailServiceTest.php`

**Interfaces:**
- Consumes: `Toolkit`, `Guard`, `ObjectRef`, `CustomerAccount` (Task 3); `ServiceTestCase` (Task 6); `Support\MailType`, `Support\Quota`, `Repository\VirtualHosts::kindFor()`; `Core::isValidEmail()`, `isValidEmailLocalPart()`, `isAcceptablePassword()`, `hashPassword()`, `pruneAutoreplyLog()` (Task 2).
- Produces:
  ```php
  new MailService(Toolkit $kit)
  MailService::create(Identity $caller, array $input): ObjectRef             // tag MAIL_ACCOUNT
  MailService::update(Identity $caller, string $id, array $input): ObjectRef
  MailService::delete(Identity $caller, string $id): ObjectRef
  MailService::setAutoresponder(Identity $caller, string $id, array $input): ObjectRef
  MailService::HOSTS   // the four vhost tags a mail account may hang off
  MailService::MIB     // 1048576
  ```
  Task 10 adds `createCatchall()` and `deleteCatchall()` to the same class.

- [ ] **Step 1: Write the failing test**

Create `test/integration/MailServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\MailService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class MailServiceTest extends ServiceTestCase
{
    const MIB = 1048576;

    private function service(): MailService
    {
        return new MailService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function mailboxId(): string
    {
        return GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->mailboxId());
    }

    private function mail(int $id): array
    {
        return $this->db->row('SELECT * FROM mail_users WHERE mail_id = ?', array($id));
    }

    /**
     * The fixture's mailbox has mail_pass '_no_'. Give it a real one, so an
     * update that does not mention the password has one to keep.
     */
    private function giveTheMailboxAPassword(): void
    {
        $this->db->execute("UPDATE mail_users SET mail_pass = '\$6\$kept' WHERE mail_id = ?", array($this->fixture->mailboxId()));
    }

    // ---- create ---------------------------------------------------------

    public function testAMailboxIsCreated(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'Info', 'kind' => 'MAILBOX',
            'password' => 'Mailb0xPass', 'quota' => (string)(10 * self::MIB)
        ));

        self::assertSame(NodeType::MAIL_ACCOUNT, $ref->getTag());
        $row = $this->mail($ref->getKey());
        self::assertSame('info', $row['mail_acc']);
        self::assertSame('info@' . $this->fixture->domainName(), $row['mail_addr']);
        self::assertSame('normal_mail', $row['mail_type']);
        self::assertSame('0', (string)$row['sub_id']);
        self::assertStringStartsWith('$6$', $row['mail_pass']);
        self::assertSame('_no_', $row['mail_forward']);
        self::assertSame((string)(10 * self::MIB), (string)$row['quota']);
        self::assertSame('yes', $row['po_active']);
        self::assertSame('toadd', $row['status']);
        self::assertSame(array('onBeforeAddMail', 'onAfterAddMail'), $this->core->eventNames());
        self::assertSame(
            array('mailUsername' => 'info', 'MailAddress' => 'info@' . $this->fixture->domainName()),
            $this->core->events[0][1],
            'the page spells the before-event key MailAddress'
        );
        self::assertSame($ref->getKey(), $this->core->events[1][1]['mailId']);
        self::assertSame(1, $this->core->requests);
        self::assertSame('A mail account has been added by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testAForwardOnlyAccountIsCreatedWithQuotaZero(): void
    {
        // Measurement M7, C11 item 1: the panel cannot create one at all.
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'team', 'kind' => 'FORWARD',
            'forwardTo' => array('A@Example.NET', 'b@example.net', 'a@example.net')
        ));

        $row = $this->mail($ref->getKey());
        self::assertSame('normal_forward', $row['mail_type']);
        self::assertSame('a@example.net,b@example.net', $row['mail_forward'], 'lower-cased and de-duplicated');
        self::assertSame('_no_', $row['mail_pass']);
        self::assertSame('0', (string)$row['quota']);
        self::assertSame('no', $row['po_active']);
    }

    public function testAMailboxThatForwardsTooOnASubdomain(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId'    => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
            'localPart' => 'both', 'kind' => 'MAILBOX_AND_FORWARD',
            'password'  => 'Mailb0xPass', 'quota' => (string)self::MIB, 'forwardTo' => array('x@example.net')
        ));

        $row = $this->mail($ref->getKey());
        self::assertSame('subdom_mail,subdom_forward', $row['mail_type']);
        self::assertSame((string)$this->fixture->subdomainId(), (string)$row['sub_id']);
        self::assertSame('both@' . $this->fixture->subdomainName(), $row['mail_addr']);
    }

    public function testACatchallKindIsRefusedHere(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'CATCHALL'
            ));
        });

        self::assertSame('input.kind', $e->getExtensions()['field']);
    }

    public function testAMailboxNeedsAnAcceptablePassword(): void
    {
        $missing = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX', 'quota' => (string)self::MIB
            ));
        });
        self::assertSame('input.password', $missing->getExtensions()['field']);

        $weak = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'short', 'quota' => (string)self::MIB
            ));
        });
        self::assertSame('input.password', $weak->getExtensions()['field']);
        self::assertSame(6, $weak->getExtensions()['minLength']);
    }

    public function testAQuotaMustBeWholeMebibytes(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'Mailb0xPass', 'quota' => '1000'
            ));
        });

        self::assertSame('input.quota', $e->getExtensions()['field']);
    }

    public function testAQuotaMustFitWhatIsLeftOfTheAccountsMailQuota(): void
    {
        // mail_quota is 1 GiB and the fixture's mailbox holds 100 MiB of it.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'Mailb0xPass', 'quota' => (string)(1000 * self::MIB)
            ));
        });

        self::assertSame((string)(924 * self::MIB), $e->getExtensions()['maximum']);
    }

    public function testWithAnAccountMailQuotaEveryMailboxNeedsOne(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX', 'password' => 'Mailb0xPass'
            ));
        });
    }

    public function testWithoutAnAccountMailQuotaAMailboxMayBeUnlimited(): void
    {
        $this->db->execute('UPDATE domain SET mail_quota = 0 WHERE domain_id = ?', array($this->fixture->domainId()));

        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX', 'password' => 'Mailb0xPass'
        ));

        self::assertSame('0', (string)$this->mail($ref->getKey())['quota']);
    }

    public function testNoMailboxOnTheServersOwnHostname(): void
    {
        $this->reconfigure(array('SERVER_HOSTNAME' => $this->fixture->domainName()));

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'Mailb0xPass', 'quota' => (string)self::MIB
            ));
        });
        self::assertSame('input.kind', $e->getExtensions()['field']);

        // A forward is fine there.
        $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
        ));
    }

    public function testAForwardCannotForwardToItself(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'loop', 'kind' => 'FORWARD',
                'forwardTo' => array('ok@example.net', 'loop@' . $this->fixture->domainName())
            ));
        });

        self::assertSame(array('field' => 'input.forwardTo', 'index' => 1), $e->getExtensions());
    }

    public function testATakenAddressIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'sales', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
            ));
        });
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        // The fixture has two accounts.
        $this->db->execute('UPDATE domain SET domain_mailacc_limit = 2 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
            ));
        });
    }

    public function testAnUnsettledHostIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId'    => GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()),
                'localPart' => 'x', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
            ));
        });
    }

    // ---- update ---------------------------------------------------------

    public function testTurningAMailboxIntoAForward(): void
    {
        $this->service()->update($this->caller('customer'), $this->mailboxId(), array(
            'kind' => 'FORWARD', 'forwardTo' => array('elsewhere@example.net')
        ));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('normal_forward', $row['mail_type']);
        self::assertSame('_no_', $row['mail_pass']);
        self::assertSame('0', (string)$row['quota']);
        self::assertSame('no', $row['po_active']);
        self::assertSame('elsewhere@example.net', $row['mail_forward']);
        self::assertSame('tochange', $row['status']);
        self::assertSame(array('onBeforeEditMail', 'onAfterEditMail'), $this->core->eventNames());
        self::assertSame(array('mailId' => $this->fixture->mailboxId()), $this->core->events[0][1]);
        self::assertSame('A mail account (sales@' . $this->fixture->domainName() . ') has been edited by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testAPasswordLeftOutIsKept(): void
    {
        $this->giveTheMailboxAPassword();

        $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => (string)(50 * self::MIB)));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('$6$kept', $row['mail_pass']);
        self::assertSame((string)(50 * self::MIB), (string)$row['quota']);
    }

    public function testAMailboxWithNoPasswordYetMustBeGivenOne(): void
    {
        // mail_edit.php:115: a '_no_' password must be replaced.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => (string)(50 * self::MIB)));
        });

        self::assertSame('input.password', $e->getExtensions()['field']);
    }

    public function testAQuotaCheckOnUpdateLeavesTheAccountItselfOut(): void
    {
        // 1 GiB total; this mailbox's own 100 MiB is not "someone else's".
        $this->giveTheMailboxAPassword();

        $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => (string)(1024 * self::MIB)));

        self::assertSame((string)(1024 * self::MIB), (string)$this->mail($this->fixture->mailboxId())['quota']);
    }

    public function testAForwardAddingAMailboxNeedsAPasswordAndQuota(): void
    {
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());

        $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($forward) {
            $this->service()->update($this->caller('customer'), $forward, array('kind' => 'MAILBOX_AND_FORWARD'));
        });

        $this->service()->update($this->caller('customer'), $forward, array(
            'kind' => 'MAILBOX_AND_FORWARD', 'password' => 'Mailb0xPass', 'quota' => (string)self::MIB
        ));

        $row = $this->mail($this->fixture->forwardId());
        self::assertSame('alssub_mail,alssub_forward', $row['mail_type']);
        self::assertSame('a@example.net,b@example.net', $row['mail_forward'], 'the forwards were kept');
    }

    public function testAnEmptyUpdateIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array());
        });

        self::assertSame('input', $e->getExtensions()['field']);
    }

    public function testAnUnsettledAccountCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE mail_users SET status = 'toadd' WHERE mail_id = ?", array($this->fixture->mailboxId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array('kind' => 'FORWARD', 'forwardTo' => array('x@example.net')));
        });
    }

    // ---- delete ---------------------------------------------------------

    public function testDeletingRemovesTheAddressFromTheCustomersOtherAccounts(): void
    {
        // C11 item 4: the page never did this, and would have done it for
        // every customer on the box.
        $address = 'sales@' . $this->fixture->domainName();
        $shared = $this->insert('mail_users', array(
            'mail_acc' => 'team', 'mail_pass' => '_no_', 'mail_forward' => $address . ',x@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'team@' . $this->fixture->domainName()
        ));
        $only = $this->insert('mail_users', array(
            'mail_acc' => 'alias', 'mail_pass' => '_no_', 'mail_forward' => $address,
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'alias@' . $this->fixture->domainName()
        ));
        $catchall = $this->insert('mail_users', array(
            'mail_acc' => $address, 'mail_pass' => '_no_', 'mail_forward' => '_no_',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_catchall', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => '@' . $this->fixture->domainName()
        ));
        $strangers = $this->insert('mail_users', array(
            'mail_acc' => 'fan', 'mail_pass' => '_no_', 'mail_forward' => $address,
            'domain_id' => $this->fixture->otherDomainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'fan@sgwtstranger.test'
        ));

        $this->service()->delete($this->caller('customer'), $this->mailboxId());

        self::assertSame('todelete', $this->mail($this->fixture->mailboxId())['status']);
        self::assertSame('tochange', $this->mail($shared)['status']);
        self::assertSame('x@example.net', $this->mail($shared)['mail_forward']);
        self::assertSame('todelete', $this->mail($only)['status'], 'nothing left to forward to');
        self::assertSame('todelete', $this->mail($catchall)['status'], 'nothing left to catch for');
        self::assertSame('ok', $this->mail($strangers)['status'], 'another customer is untouched');
        self::assertSame($address, $this->mail($strangers)['mail_forward']);
        self::assertSame(1, $this->core->prunes);
        self::assertSame(array('onBeforeDeleteMail', 'onAfterDeleteMail'), $this->core->eventNames());
        self::assertSame('1 mail account(s) were deleted by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testADefaultAccountIsProtected(): void
    {
        $webmaster = $this->insert('mail_users', array(
            'mail_acc' => 'webmaster', 'mail_pass' => '_no_', 'mail_forward' => 'owner@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'webmaster@' . $this->fixture->domainName()
        ));
        $id = GlobalId::encode(NodeType::MAIL_ACCOUNT, $webmaster);

        $this->refused(ErrorCode::FORBIDDEN, function () use ($id) {
            $this->service()->delete($this->caller('customer'), $id);
        });

        $this->reconfigure(array('PROTECT_DEFAULT_EMAIL_ADDRESSES' => 0));
        $this->service()->delete($this->caller('customer'), $id);
        self::assertSame('todelete', $this->mail($webmaster)['status']);
    }

    public function testACatchallIsNotDeletedHere(): void
    {
        $catchall = $this->insert('mail_users', array(
            'mail_acc' => 'x@example.net', 'mail_pass' => '_no_', 'mail_forward' => '_no_',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_catchall', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => '@' . $this->fixture->domainName()
        ));

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($catchall) {
            $this->service()->delete($this->caller('customer'), GlobalId::encode(NodeType::MAIL_ACCOUNT, $catchall));
        });

        self::assertSame('id', $e->getExtensions()['field']);
    }

    // ---- autoresponder --------------------------------------------------

    public function testEnablingAnAutoresponder(): void
    {
        $this->service()->setAutoresponder($this->caller('customer'), $this->mailboxId(), array(
            'enabled' => true, 'message' => '  Away until Monday.  '
        ));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('1', (string)$row['mail_auto_respond']);
        self::assertSame('Away until Monday.', $row['mail_auto_respond_text']);
        self::assertSame('tochange', $row['status']);
        self::assertSame(1, $this->core->requests);
        self::assertSame('A mail autoresponder has been activated by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testEnablingWithoutAMessageUsesTheOneAlreadyStored(): void
    {
        // mail_autoresponder_enable.php:124: an existing text is reused.
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());
        $this->db->execute('UPDATE mail_users SET mail_auto_respond = 0 WHERE mail_id = ?', array($this->fixture->forwardId()));

        $this->service()->setAutoresponder($this->caller('customer'), $forward, array('enabled' => true));

        self::assertSame('On holiday.', $this->mail($this->fixture->forwardId())['mail_auto_respond_text']);
    }

    public function testEnablingWithNoMessageAnywhereIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->setAutoresponder($this->caller('customer'), $this->mailboxId(), array('enabled' => true));
        });

        self::assertSame('input.message', $e->getExtensions()['field']);
    }

    public function testDisablingAnAutoresponder(): void
    {
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());

        $this->service()->setAutoresponder($this->caller('customer'), $forward, array('enabled' => false));

        $row = $this->mail($this->fixture->forwardId());
        self::assertSame('0', (string)$row['mail_auto_respond']);
        self::assertSame('On holiday.', $row['mail_auto_respond_text'], 'the text is kept for next time');
        self::assertSame('tochange', $row['status']);
        self::assertSame('A mail autoresponder has been deactivated by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testEditingTheTextOfADisabledAutoresponderProvisionsNothing(): void
    {
        // mail_autoresponder_edit.php:78: status changes only when it is on.
        $this->service()->setAutoresponder($this->caller('customer'), $this->mailboxId(), array(
            'enabled' => false, 'message' => 'For later.'
        ));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('ok', $row['status']);
        self::assertSame('For later.', $row['mail_auto_respond_text']);
        self::assertSame(0, $this->core->requests);
        self::assertSame('A mail autoresponder has been edited by sgwtcustomer', $this->core->logs[0][0]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter MailServiceTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\MailService" not found`.

- [ ] **Step 3: Write the service**

Create `Service/MailService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;

/**
 * Mail accounts, their autoresponders, and catch-alls.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/mail_add.php, mail_edit.php,
 *   mail_delete.php, mail_autoresponder_*.php and mail_catchall_*.php.
 *   Retire when MailService lands in core (spec section 21, C3 row 3).
 */
final class MailService
{
    const HOSTS = array(NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN);

    const MIB = 1048576;

    /** What i-MSCP writes for "no password" and "no forwards". */
    const NOTHING = '_no_';

    /** The kinds a mail account mutation takes; a catch-all has its own. */
    const ACCOUNT_KINDS = array(MailType::KIND_MAILBOX, MailType::KIND_FORWARD, MailType::KIND_MAILBOX_AND_FORWARD);

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

        // 2, 3.
        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::MAIL_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_mailacc_limit'),
            $kit->counts()->mailAccounts(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'mail');

        // 5. mail_add.php lists only settled hosts (lines 53-71).
        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $kind = (string)($input['kind'] ?? '');

        if (!in_array($kind, self::ACCOUNT_KINDS, true)) {
            throw Guard::badInput(
                'input.kind',
                $kind === MailType::KIND_CATCHALL
                    ? 'A catch-all is created with mailCatchallCreate.'
                    : 'Unknown mail account kind.'
            );
        }

        $localPart = mb_strtolower(trim((string)($input['localPart'] ?? '')));

        if ($localPart === '' || !$core->isValidEmailLocalPart($localPart)) {
            throw Guard::badInput('input.localPart', 'Invalid email username.');
        }

        $hostName = (string)$hostRow['name'];
        $address = $localPart . '@' . $hostName;
        $values = $this->accountValues($account, $kind, $hostName, $address, $input, null, true);

        // 7.
        Guard::requireQuota($quota, 'mailAccounts');

        $hostType = VirtualHosts::kindFor($host->getTag());
        $mailType = MailType::toMailType($hostType, $kind);
        $subId = $hostType === VirtualHosts::KIND_DMN ? 0 : (int)$host->getKey();

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:259-284.
        // CORE-DEBT(C11): C11 item 1 - quota 0, not NULL, for an account with no mailbox.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $localPart, $address, $mailType, $subId, $values) {
            $core->dispatch(Events::onBeforeAddMail, array('mailUsername' => $localPart, 'MailAddress' => $address));

            $kit->db()->execute(
                '
                    INSERT INTO mail_users (
                        mail_acc, mail_pass, mail_forward, domain_id, mail_type, sub_id, status,
                        po_active, mail_auto_respond, mail_auto_respond_text, quota, mail_addr
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ',
                array(
                    $localPart, $values['password'], $values['forward'], $account->getDomainId(), $mailType,
                    $subId, 'toadd', $values['poActive'], 0, null, $values['quota'], $address
                )
            );

            $id = $kit->db()->lastInsertId();
            $core->dispatch(Events::onAfterAddMail, array('mailUsername' => $localPart, 'mailAddress' => $address, 'mailId' => $id));

            return $id;
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(sprintf('A mail account has been added by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $id);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);
        // mail_edit.php does not ask; spec section 8.3 does.
        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK));

        $current = MailType::kindOf((string)$row['mail_type']);

        if ($current === MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'A catch-all is changed with the catch-all mutations.');
        }

        $named = array_intersect(array_keys($input), array('kind', 'password', 'quota', 'forwardTo'));

        if ($named === array()) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $kind = isset($input['kind']) ? (string)$input['kind'] : $current;

        if (!in_array($kind, self::ACCOUNT_KINDS, true)) {
            throw Guard::badInput('input.kind', 'A mail account cannot become a catch-all.');
        }

        $address = (string)$row['mail_addr'];
        $hostName = substr($address, strpos($address, '@') + 1);
        $hadMailbox = $current !== MailType::KIND_FORWARD;
        $values = $this->accountValues(
            $account, $kind, $hostName, $address, $input, $row,
            isset($input['quota']) || !$hadMailbox
        );
        $mailType = MailType::toMailType(MailType::hostTypeOf((string)$row['mail_type']), $kind);
        $mailId = (int)$target->getKey();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_edit.php:227-251.
        $kit->writer()->run(function () use ($kit, $core, $mailId, $mailType, $values) {
            $core->dispatch(Events::onBeforeEditMail, array('mailId' => $mailId));

            $kit->db()->execute(
                '
                    UPDATE mail_users
                    SET mail_pass = ?, mail_forward = ?, mail_type = ?, status = ?, po_active = ?, quota = ?
                    WHERE mail_id = ?
                ',
                array(
                    $values['password'], $values['forward'], $mailType, 'tochange', $values['poActive'],
                    $values['quota'], $mailId
                )
            );

            $core->dispatch(Events::onAfterEditMail, array('mailId' => $mailId));
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('A mail account (%s) has been edited by %s', $core->toUnicode($address), $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);

        if (MailType::kindOf((string)$row['mail_type']) === MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'A catch-all is deleted with mailCatchallDelete.');
        }

        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        // mail_delete.php:56-65 skips these silently; the API says why.
        if ($core->config('PROTECT_DEFAULT_EMAIL_ADDRESSES', false) && self::isDefaultAccount($row)) {
            throw Guard::forbidden("The panel's settings protect this default mail account.");
        }

        $mailId = (int)$target->getKey();
        $address = (string)$row['mail_addr'];
        $domainId = $account->getDomainId();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_delete.php:44-126.
        // CORE-DEBT(C11): C11 item 4 - the address really is removed from the
        //   owning customer's other accounts, and only theirs.
        $kit->writer()->run(function () use ($kit, $core, $mailId, $address, $domainId) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteMail, array('mailId' => $mailId));

            $db->execute("UPDATE mail_users SET status = 'todelete' WHERE mail_id = ?", array($mailId));

            $pattern = '(,|^)' . preg_quote($address) . '(,|$)';
            $others = $db->rows(
                "
                    SELECT mail_id, mail_acc, mail_forward FROM mail_users
                    WHERE domain_id = ? AND mail_id <> ? AND status = 'ok'
                    AND (mail_acc RLIKE ? OR mail_forward RLIKE ?)
                ",
                array($domainId, $mailId, $pattern, $pattern)
            );

            foreach ($others as $other) {
                $isCatchall = $other['mail_forward'] === self::NOTHING;
                $acc = $isCatchall ? self::without((string)$other['mail_acc'], $address) : (string)$other['mail_acc'];
                $forward = $isCatchall ? (string)$other['mail_forward'] : self::without((string)$other['mail_forward'], $address);

                if (($isCatchall ? $acc : $forward) === '') {
                    $db->execute("UPDATE mail_users SET status = 'todelete' WHERE mail_id = ?", array($other['mail_id']));
                } else {
                    $db->execute(
                        "UPDATE mail_users SET status = 'tochange', mail_acc = ?, mail_forward = ? WHERE mail_id = ?",
                        array($acc, $forward, $other['mail_id'])
                    );
                }
            }

            $core->pruneAutoreplyLog();
            $core->dispatch(Events::onAfterDeleteMail, array('mailId' => $mailId));
        });

        $core->sendRequest();
        $core->writeLog(sprintf('%d mail account(s) were deleted by %s', 1, $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }

    public function setAutoresponder(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);

        if (MailType::kindOf((string)$row['mail_type']) === MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'A catch-all has no autoresponder.');
        }

        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK));

        $enabled = (bool)($input['enabled'] ?? false);
        $message = isset($input['message']) ? trim((string)$input['message']) : null;

        if ($message === '') {
            throw Guard::badInput('input.message', 'An autoresponder message cannot be empty.');
        }

        $text = $message ?? (string)($row['mail_auto_respond_text'] ?? '');

        if ($enabled && $text === '') {
            throw Guard::badInput('input.message', 'An autoresponder needs a message.');
        }

        $was = (int)$row['mail_auto_respond'] === 1;
        // mail_autoresponder_edit.php:78: the backend is involved only when
        // the responder is on, or is being turned off.
        $provision = $enabled || $was;
        $mailId = (int)$target->getKey();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_autoresponder_enable.php:63-79,
        //   mail_autoresponder_disable.php:62-68 and mail_autoresponder_edit.php:69-84.
        $kit->writer()->run(function () use ($kit, $mailId, $enabled, $text, $provision, $row) {
            $kit->db()->execute(
                'UPDATE mail_users SET mail_auto_respond = ?, mail_auto_respond_text = ?, status = ? WHERE mail_id = ?',
                array($enabled ? 1 : 0, $text === '' ? null : $text, $provision ? 'tochange' : $row['status'], $mailId)
            );
        });

        if ($provision) {
            $core->sendRequest();
        }

        if ($enabled && !$was) {
            $verb = 'activated';
        } elseif (!$enabled && $was) {
            $verb = 'deactivated';
        } else {
            $verb = 'edited';
        }

        $core->writeLog(sprintf('A mail autoresponder has been %s by %s', $verb, $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }

    /**
     * The password, forward list, quota and POP flag an account of $kind is
     * written with.
     *
     * @param array|null $current   The row being updated, or null on create
     * @param bool       $checkPool Whether the quota is measured against the
     *                              account's mail quota. An update that does
     *                              not touch the quota of an existing mailbox
     *                              is not refused because a reseller has since
     *                              lowered the account's total.
     * @return array{password: string, forward: string, quota: int, poActive: string}
     */
    private function accountValues(
        CustomerAccount $account, string $kind, string $hostName, string $address, array $input,
        ?array $current, bool $checkPool
    ): array {
        $core = $this->kit->core();
        $mailbox = $kind !== MailType::KIND_FORWARD;
        $forward = $kind !== MailType::KIND_MAILBOX;
        $values = array('password' => self::NOTHING, 'forward' => self::NOTHING, 'quota' => 0, 'poActive' => $mailbox ? 'yes' : 'no');

        if ($mailbox) {
            // CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:134-142.
            if ((string)$core->config('SERVER_HOSTNAME', '') === $hostName) {
                throw Guard::badInput(
                    'input.kind',
                    "The server's own hostname cannot have mailboxes; only forwards are allowed there."
                );
            }

            if (isset($input['password'])) {
                $password = trim((string)$input['password'], ' ');

                if ($password === '' || !$core->isAcceptablePassword($password)) {
                    throw Guard::badInput('input.password', "The password does not meet the panel's password policy.", array(
                        'minLength'               => max(6, (int)$core->config('PASSWD_CHARS', 6)),
                        'requiresLettersAndDigits' => (bool)$core->config('PASSWD_STRONG', false)
                    ));
                }

                $values['password'] = $core->hashPassword($password);
            } elseif ($current !== null && (string)$current['mail_pass'] !== self::NOTHING) {
                $values['password'] = (string)$current['mail_pass'];
            } else {
                throw Guard::badInput('input.password', 'A mailbox needs a password.');
            }

            $values['quota'] = $this->mailboxQuota($account, $input, $current, $checkPool);
        }

        if ($forward) {
            if (isset($input['forwardTo'])) {
                $values['forward'] = $this->forwardList((array)$input['forwardTo'], $address);
            } elseif ($current !== null && !in_array((string)$current['mail_forward'], array('', self::NOTHING), true)) {
                $values['forward'] = (string)$current['mail_forward'];
            } else {
                throw Guard::badInput('input.forwardTo', 'A forward needs at least one address.');
            }
        }

        return $values;
    }

    /**
     * Bytes, a whole number of MiB (decision D20), within what the other
     * mailboxes leave of the account's mail quota.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:170-193
     *   and mail_edit.php:140-164.
     */
    private function mailboxQuota(CustomerAccount $account, array $input, ?array $current, bool $checkPool): int
    {
        if (isset($input['quota'])) {
            $raw = (string)$input['quota'];

            if (!preg_match('/^[0-9]+$/', $raw)) {
                throw Guard::badInput('input.quota', 'A quota is a whole number of bytes.');
            }

            $bytes = (int)$raw;

            if ($bytes % self::MIB !== 0) {
                throw Guard::badInput('input.quota', 'A quota must be a whole number of MiB.', array('unit' => self::MIB));
            }
        } else {
            $bytes = $current === null ? 0 : (int)$current['quota'];
        }

        $limit = (int)$account->domain('mail_quota');

        if ($limit <= 0 || !$checkPool) {
            return $bytes;
        }

        if ($bytes < self::MIB) {
            throw Guard::badInput('input.quota', 'This account has a mail quota, so every mailbox needs one of at least 1 MiB.');
        }

        $bind = array($account->getDomainId());
        $sql = 'SELECT IFNULL(SUM(quota), 0) FROM mail_users WHERE domain_id = ?';

        if ($current !== null) {
            $sql .= ' AND mail_id <> ?';
            $bind[] = (int)$current['mail_id'];
        }

        $room = max(0, $limit - (int)$this->kit->db()->value($sql, $bind));

        if ($bytes > $room) {
            throw Guard::badInput(
                'input.quota',
                "The quota is larger than what is left of the account's mail quota.",
                array('maximum' => (string)$room)
            );
        }

        return $bytes;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:212-242.
     *
     * @param array<int, mixed> $list
     */
    private function forwardList(array $list, string $address): string
    {
        $core = $this->kit->core();
        $addresses = array();

        foreach (array_values($list) as $index => $one) {
            $ascii = $core->toAscii(mb_strtolower(trim((string)$one)));

            if ($ascii === '' || !$core->isValidEmail($ascii)) {
                throw Guard::badInput('input.forwardTo', 'Not an email address.', array('index' => $index));
            }

            if ($ascii === $address) {
                throw Guard::badInput('input.forwardTo', sprintf('%s cannot forward to itself.', $address), array('index' => $index));
            }

            $addresses[$ascii] = true;
        }

        if ($addresses === array()) {
            throw Guard::badInput('input.forwardTo', 'A forward needs at least one address.');
        }

        return implode(',', array_keys($addresses));
    }

    /**
     * @return array<string, mixed>
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND
     */
    private function mailRow(Target $target): array
    {
        $row = $this->kit->db()->row('SELECT * FROM mail_users WHERE mail_id = ?', array((int)$target->getKey()));

        if ($row === null) {
            throw Guard::notFound();
        }

        return $row;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/mail_delete.php:56-62.
     */
    private static function isDefaultAccount(array $row): bool
    {
        $type = (string)$row['mail_type'];
        $acc = (string)$row['mail_acc'];

        return (in_array($type, array('normal_forward', 'alias_forward'), true)
                && in_array($acc, array('abuse', 'hostmaster', 'postmaster', 'webmaster'), true))
            || ($acc === 'webmaster' && in_array($type, array('subdom_forward', 'alssub_forward'), true));
    }

    private static function without(string $list, string $address): string
    {
        return implode(',', array_values(array_filter(explode(',', $list), static function (string $one) use ($address) {
            return $one !== '' && $one !== $address;
        })));
    }
}
```

- [ ] **Step 4: Run the test**

Run: `tools/test.sh --filter MailServiceTest`
Expected: PASS, 29 tests.

- [ ] **Step 5: Run everything and commit**

Run: `tools/test.sh`
Expected: all PASS.

```bash
git add Service/MailService.php test/integration/MailServiceTest.php
git commit -m "Create, update and delete mail accounts, and set autoresponders

The pages' rules in spec 8.1's order, with two of their defects not copied.
A forward-only account is written with quota 0: the panel writes NULL into a
NOT NULL column and reports MariaDB's refusal as 'already exists', so it
cannot create one at all. And deleting an address really does remove it from
the customer's other forwards and catch-alls, which the page meant to do and
never did - limited to that customer, where the page's query would have
reached every account on the box.

Updates are partial: a password or forward list that is not mentioned is
kept, and a quota that is not mentioned is not re-checked against a total a
reseller may since have lowered."
```

---

## Task 10: Catch-alls, and mail in the schema — `sonnet`

`mailCatchallCreate` and `mailCatchallDelete` join `MailService`; the six mail mutations join the schema; and the read model learns to show a catch-all's addresses, which it has so far left out.

**Files:**
- Modify: `Service/MailService.php` (two methods)
- Modify: `test/integration/MailServiceTest.php` (append)
- Modify: `Resolver/MailResolver.php` (`shape()` only), `test/unit/Resolver/MailResolverTest.php` (append)
- Modify: `schema/schema.graphql`, `test/schema/schema.printed.graphql`
- Create: `Resolver/MailMutations.php`, `test/integration/MailMutationsTest.php`
- Modify: `Api/Container.php`

**Interfaces:**
- Consumes: `MailService` (Task 9); `MailResolver::reference()`; everything Task 8 consumed.
- Produces:
  ```php
  MailService::createCatchall(Identity $caller, array $input): ObjectRef
  MailService::deleteCatchall(Identity $caller, string $id): ObjectRef
  new MailMutations(BatchLoader $loader, MailService $mail, MailResolver $mailResolver)
  MailMutations::map(): array   // six 'Mutation.mail*' keys
  ```
  SDL: `MailAccountCreateInput`, `MailAccountUpdateInput`, `AutoresponderInput`, `MailCatchallCreateInput`. `MailAccount.forwardTo`'s description now covers catch-alls.

- [ ] **Step 1: Write the failing catch-all tests**

Append to `test/integration/MailServiceTest.php`'s class:

```php
    // ---- catch-alls -----------------------------------------------------

    public function testACatchallIsCreatedForAHost(): void
    {
        $ref = $this->service()->createCatchall($this->caller('customer'), array(
            'hostId'    => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
            'addresses' => array('Sales@' . $this->fixture->domainName(), 'x@example.net')
        ));

        $row = $this->mail($ref->getKey());
        self::assertSame('sales@' . $this->fixture->domainName() . ',x@example.net', $row['mail_acc']);
        self::assertSame('_no_', $row['mail_forward']);
        self::assertSame('alias_catchall', $row['mail_type']);
        self::assertSame((string)$this->fixture->aliasId(), (string)$row['sub_id']);
        self::assertSame('@' . $this->fixture->aliasName(), $row['mail_addr']);
        self::assertSame('toadd', $row['status']);
        self::assertSame(array('onBeforeAddMailCatchall', 'onAfterAddMailCatchall'), $this->core->eventNames());
        self::assertSame(array(
            'mailCatchallDomain'    => $this->fixture->aliasName(),
            'mailCatchallAddresses' => array('sales@' . $this->fixture->domainName(), 'x@example.net')
        ), $this->core->events[0][1]);
        self::assertSame($ref->getKey(), $this->core->events[1][1]['mailCatchallId']);
        self::assertSame('A catch-all account has been created by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testASecondCatchallOnTheSameHostIsAConflict(): void
    {
        $input = array('hostId' => $this->domainId(), 'addresses' => array('x@example.net'));
        $this->service()->createCatchall($this->caller('customer'), $input);

        $this->refused(ErrorCode::CONFLICT, function () use ($input) {
            $this->service()->createCatchall($this->caller('customer'), $input);
        });
    }

    public function testACatchallNeedsValidAddresses(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createCatchall($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'addresses' => array('x@example.net', 'not an address')
            ));
        });
        self::assertSame(array('field' => 'input.addresses', 'index' => 1), $e->getExtensions());

        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createCatchall($this->caller('customer'), array('hostId' => $this->domainId(), 'addresses' => array()));
        });
    }

    public function testACatchallIsDeleted(): void
    {
        $ref = $this->service()->createCatchall($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'addresses' => array('x@example.net')
        ));
        $this->db->execute("UPDATE mail_users SET status = 'ok' WHERE mail_id = ?", array($ref->getKey()));
        $this->reconfigure(array());

        $this->service()->deleteCatchall($this->caller('customer'), GlobalId::encode(NodeType::MAIL_ACCOUNT, $ref->getKey()));

        self::assertSame('todelete', $this->mail($ref->getKey())['status']);
        self::assertSame(array('onBeforeDeleteMailCatchall', 'onAfterDeleteMailCatchall'), $this->core->eventNames());
        self::assertSame(array('mailCatchallId' => $ref->getKey()), $this->core->events[0][1]);
        self::assertSame('A catch-all account has been deleted by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testAnOrdinaryAccountIsNotDeletedAsACatchall(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->deleteCatchall($this->caller('customer'), $this->mailboxId());
        });

        self::assertSame('id', $e->getExtensions()['field']);
    }
```

Run: `tools/test.sh --filter MailServiceTest`
Expected: FAIL — `Call to undefined method ...MailService::createCatchall()`.

- [ ] **Step 2: Add the two methods**

In `Service/MailService.php`, add before `private function accountValues(`:

```php
    public function createCatchall(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::MAIL_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_catchall_add.php:132-155
        //   (the manual list). The page asks no limit, and neither does this.
        $addresses = array();

        foreach (array_values((array)($input['addresses'] ?? array())) as $index => $one) {
            $ascii = $core->toAscii(mb_strtolower(trim((string)$one)));

            if ($ascii === '' || !$core->isValidEmail($ascii)) {
                throw Guard::badInput('input.addresses', 'Not an email address.', array('index' => $index));
            }

            $addresses[$ascii] = true;
        }

        if ($addresses === array()) {
            throw Guard::badInput('input.addresses', 'A catch-all needs at least one address.');
        }

        $list = array_keys($addresses);
        $hostName = (string)$hostRow['name'];
        $hostType = VirtualHosts::kindFor($host->getTag());
        $mailType = MailType::toMailType($hostType, MailType::KIND_CATCHALL);
        $subId = $hostType === VirtualHosts::KIND_DMN ? 0 : (int)$host->getKey();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_catchall_add.php:173-191.
        //   mail_addr '@host' is unique, so a second catch-all is CONFLICT.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $list, $hostName, $mailType, $subId) {
            $params = array('mailCatchallDomain' => $hostName, 'mailCatchallAddresses' => $list);
            $core->dispatch(Events::onBeforeAddMailCatchall, $params);

            $kit->db()->execute(
                "
                    INSERT INTO mail_users (mail_acc, mail_forward, domain_id, mail_type, sub_id, status, po_active, mail_addr)
                    VALUES (?, '_no_', ?, ?, ?, 'toadd', 'no', ?)
                ",
                array(implode(',', $list), $account->getDomainId(), $mailType, $subId, '@' . $hostName)
            );

            $id = $kit->db()->lastInsertId();
            $core->dispatch(Events::onAfterAddMailCatchall, array('mailCatchallId' => $id) + $params);

            return $id;
        });

        $core->sendRequest();
        $core->writeLog(sprintf('A catch-all account has been created by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $id);
    }

    public function deleteCatchall(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);

        if (MailType::kindOf((string)$row['mail_type']) !== MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'Not a catch-all; delete it with mailAccountDelete.');
        }

        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $mailId = (int)$target->getKey();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_catchall_delete.php:53-62.
        $kit->writer()->run(function () use ($kit, $core, $mailId) {
            $core->dispatch(Events::onBeforeDeleteMailCatchall, array('mailCatchallId' => $mailId));
            $kit->db()->execute("UPDATE mail_users SET status = 'todelete' WHERE mail_id = ?", array($mailId));
            $core->dispatch(Events::onAfterDeleteMailCatchall, array('mailCatchallId' => $mailId));
        });

        $core->sendRequest();
        $core->writeLog(sprintf('A catch-all account has been deleted by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }
```

The expected `mailCatchallId` key position in `testACatchallIsCreatedForAHost` is not asserted, so `array('mailCatchallId' => $id) + $params` putting it first is fine; the page puts it first too (`mail_catchall_add.php:187`).

Run: `tools/test.sh --filter MailServiceTest`
Expected: PASS, 34 tests.

- [ ] **Step 3: Show a catch-all's addresses in the read model**

Append to `test/unit/Resolver/MailResolverTest.php`'s class:

```php
    public function testACatchallForwardsToTheAddressesItCatchesFor(): void
    {
        // A catch-all keeps its targets in mail_acc and '_no_' in mail_forward
        // (mail_catchall_add.php:182). Reading mail_forward alone showed every
        // catch-all as forwarding nowhere.
        $shaped = MailResolver::shape($this->row(array(
            'mail_type'    => 'normal_catchall',
            'mail_acc'     => 'a@example.net,b@example.net',
            'mail_forward' => '_no_',
            'mail_addr'    => '@example.net',
            'quota'        => 0
        )), $this->toUnicode());

        self::assertSame('CATCHALL', $shaped['kind']);
        self::assertSame(array('a@example.net', 'b@example.net'), $shaped['forwardTo']);
    }
```

Run: `tools/test.sh --filter MailResolverTest`
Expected: FAIL — `forwardTo` is `array()`.

In `Resolver/MailResolver.php`'s `shape()`, replace

```php
            'forwardTo'     => self::forwards($row['mail_forward']),
```

with

```php
            // A catch-all's targets are in mail_acc; everything else's in
            // mail_forward (mail_catchall_add.php:182).
            'forwardTo'     => self::forwards(
                $kind === MailType::KIND_CATCHALL ? $row['mail_acc'] : $row['mail_forward']
            ),
```

Run: `tools/test.sh --filter MailResolverTest`
Expected: PASS.

- [ ] **Step 4: Add the mail mutations to the SDL**

In `schema/schema.graphql`, in `type MailAccount`, replace the line `  forwardTo: [EmailAddress!]!` with:

```graphql
  "Where a forward sends mail, or the addresses a catch-all delivers to. Empty for a mailbox that does not forward."
  forwardTo: [EmailAddress!]!
```

In `type Mutation`, after `domainAliasDelete`, add:

```graphql

  """
  A mailbox needs a password, and a quota when the account has a mail quota.
  A forward needs forwardTo. MAILBOX_AND_FORWARD needs both.
  """
  mailAccountCreate(input: MailAccountCreateInput!): MailAccount!
  "Absent fields are kept. Turning a forward into a mailbox needs a password and, where the account has a mail quota, a quota."
  mailAccountUpdate(id: ID!, input: MailAccountUpdateInput!): MailAccount!
  """
  Also removes the address from the customer's other forwards and catch-alls;
  one left with nothing to deliver to is deleted with it. The panel's
  settings may protect the default accounts (abuse, hostmaster, postmaster,
  webmaster), which is FORBIDDEN.
  """
  mailAccountDelete(id: ID!): MailAccount!
  mailAutoresponderSet(id: ID!, input: AutoresponderInput!): MailAccount!
  "One per host. A second is CONFLICT."
  mailCatchallCreate(input: MailCatchallCreateInput!): MailAccount!
  mailCatchallDelete(id: ID!): MailAccount!
```

and append at the end of the file:

```graphql
input MailAccountCreateInput {
  "The Domain, Subdomain or DomainAlias the address is on."
  hostId: ID!
  "The part before the @."
  localPart: String!
  "MAILBOX, FORWARD or MAILBOX_AND_FORWARD. CATCHALL has its own mutation."
  kind: MailAccountKind!
  password: Secret
  """
  Bytes, and a whole number of MiB. Null or absent for no quota, which is
  refused when the account has a mail quota: then every mailbox needs one,
  within what the others leave (extensions.maximum when it does not fit).
  """
  quota: BigInt
  forwardTo: [EmailAddress!]
}

"An absent field keeps its current value."
input MailAccountUpdateInput {
  kind: MailAccountKind
  password: Secret
  quota: BigInt
  forwardTo: [EmailAddress!]
}

input AutoresponderInput {
  enabled: Boolean!
  "Required to enable one that has never had a message. Absent keeps the stored message."
  message: String
}

input MailCatchallCreateInput {
  "The Domain, Subdomain or DomainAlias whose unmatched addresses are caught."
  hostId: ID!
  "Where caught mail is delivered."
  addresses: [EmailAddress!]!
}
```

- [ ] **Step 5: Write the resolver and wire it**

Create `Resolver/MailMutations.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\MailService;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/** The six mail mutations. See VirtualHostMutations for the pattern. */
final class MailMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var MailService */
    private $mail;

    /** @var MailResolver */
    private $mailResolver;

    public function __construct(BatchLoader $loader, MailService $mail, MailResolver $mailResolver)
    {
        $this->loader = $loader;
        $this->mail = $mail;
        $this->mailResolver = $mailResolver;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.mailAccountCreate'    => array($this, 'resolveMailAccountCreate'),
            'Mutation.mailAccountUpdate'    => array($this, 'resolveMailAccountUpdate'),
            'Mutation.mailAccountDelete'    => array($this, 'resolveMailAccountDelete'),
            'Mutation.mailAutoresponderSet' => array($this, 'resolveMailAutoresponderSet'),
            'Mutation.mailCatchallCreate'   => array($this, 'resolveMailCatchallCreate'),
            'Mutation.mailCatchallDelete'   => array($this, 'resolveMailCatchallDelete')
        );
    }

    public function resolveMailAccountCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveMailAccountUpdate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->update(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']));
    }

    public function resolveMailAccountDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveMailAutoresponderSet($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->setAutoresponder(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']));
    }

    public function resolveMailCatchallCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->createCatchall(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveMailCatchallDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->deleteCatchall(TypeResolver::identity($context), (string)$args['id']));
    }

    /** Every mail mutation leaves its row in place, so there is never a snapshot. */
    private function account(ObjectRef $ref): SyncPromise
    {
        return $this->mailResolver->reference((int)$ref->getKey());
    }
}
```

In `Api/Container.php` add `use iMSCP\Plugin\SGW_GraphQL\Resolver\MailMutations;` and `use iMSCP\Plugin\SGW_GraphQL\Service\MailService;`, and append to the `$this->maps` array (comma after the preceding entry):

```php
            'MailMutations' => (new MailMutations($loader, new MailService($kit), $mail))->map()
```

- [ ] **Step 6: Run the matrix**

Run: `tools/test.sh --testsuite authz --filter 'mail'`
Expected: PASS — 36 matrix rows and 18 scope rows for the six mail mutations.

- [ ] **Step 7: Write the document-level test**

Create `test/integration/MailMutationsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

class MailMutationsTest extends AuthzTestCase
{
    public function testAMailboxReadsBackWithItsQuotaInBytesAndNoPassword(): void
    {
        $result = $this->execute(
            'mutation($input: MailAccountCreateInput!) {
                mailAccountCreate(input: $input) { address kind quota forwardTo active provisioning { state } }
            }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'localPart' => 'info', 'kind' => 'MAILBOX', 'password' => 'Mailb0xPass', 'quota' => '10485760'
            )),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'address'      => 'info@' . $this->fixture->domainName(),
            'kind'         => 'MAILBOX',
            'quota'        => '10485760',
            'forwardTo'    => array(),
            'active'       => true,
            'provisioning' => array('state' => 'PENDING')
        ), $result['data']['mailAccountCreate']);
    }

    public function testASecretIsNeverEchoedInAnError(): void
    {
        // Spec section 11: a Secret is write-only. A validation error names
        // the field, never the value.
        $result = $this->execute(
            'mutation($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id } }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'localPart' => 'info', 'kind' => 'MAILBOX', 'password' => 'weak-sekrit', 'quota' => '10485760'
            )),
            $this->fixture->identity('customer')
        );

        self::assertSame('input.password', $result['errors'][0]['extensions']['field']);
        self::assertStringNotContainsString('weak-sekrit', json_encode($result));
    }

    public function testACatchallReadsBackWithItsAddresses(): void
    {
        $result = $this->execute(
            'mutation($input: MailCatchallCreateInput!) { mailCatchallCreate(input: $input) { address kind forwardTo } }',
            array('input' => array(
                'hostId'    => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'addresses' => array('a@example.net', 'b@example.net')
            )),
            $this->fixture->identity('customer')
        );

        self::assertSame(array(
            'address'   => '@' . $this->fixture->domainName(),
            'kind'      => 'CATCHALL',
            'forwardTo' => array('a@example.net', 'b@example.net')
        ), $result['data']['mailCatchallCreate']);
    }
}
```

Run: `tools/test.sh --filter MailMutationsTest`
Expected: PASS, 3 tests.

- [ ] **Step 8: Regenerate the snapshot, run everything, commit**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql' && git diff --stat test/schema/schema.printed.graphql`
Expected: one file changed.

Run: `tools/test.sh`
Expected: all PASS.

```bash
git add Service/MailService.php Resolver/MailResolver.php Resolver/MailMutations.php Api/Container.php schema/schema.graphql test/schema/schema.printed.graphql test/integration/MailServiceTest.php test/integration/MailMutationsTest.php test/unit/Resolver/MailResolverTest.php
git commit -m "Open the mail mutations, catch-alls included

A catch-all is one per host, enforced by the unique mail_addr '@host', and
asks no limit, as in the panel. Creating one showed a gap in the read model:
a catch-all keeps its targets in mail_acc, so MailAccount.forwardTo read
every catch-all as delivering nowhere. It now reads them."
```

---
## Task 11: FTP users — `sonnet`

`ftpUserCreate`, `ftpUserUpdate` and `ftpUserDelete`: the service, the schema and the resolver in one task, because the service is small enough that splitting it would leave a reviewer two halves of one rule.

**Files:**
- Create: `Service/FtpService.php`, `Resolver/FtpMutations.php`
- Create: `test/integration/FtpServiceTest.php`, `test/integration/FtpMutationsTest.php`
- Modify: `schema/schema.graphql`, `test/schema/schema.printed.graphql`, `Api/Container.php`

**Interfaces:**
- Consumes: `Toolkit`, `Guard`, `ObjectRef`, `CustomerAccount` (Task 3); `FtpGroups`, `ServiceTestCase` (Task 6); `Core::isValidUsername()`, `isAcceptablePassword()`, `hashPassword()`, `normalisePath()`, `config()`; `DirectoryProbe` (Task 2); `FtpSqlResolver::ftpReference()`.
- Produces:
  ```php
  new FtpService(Toolkit $kit)
  FtpService::create(Identity $caller, array $input): ObjectRef          // tag FTP_USER, string key user@host
  FtpService::update(Identity $caller, string $id, array $input): ObjectRef
  FtpService::delete(Identity $caller, string $id): ObjectRef
  new FtpMutations(BatchLoader $loader, FtpService $ftp, FtpSqlResolver $ftpSql)
  FtpMutations::map(): array
  ```
  SDL: `FtpUserCreateInput`, `FtpUserUpdateInput`.

- [ ] **Step 1: Write the failing service test**

Create `test/integration/FtpServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\FtpService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;

class FtpServiceTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The fixture withholds FTP; every test here but one wants it granted.
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = 0 WHERE domain_id = ?', array($this->fixture->domainId()));
    }

    private function service(): FtpService
    {
        return new FtpService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function ftpId(): string
    {
        return GlobalId::encodeKey(NodeType::FTP_USER, $this->fixture->ftpUserId());
    }

    private function ftp(string $userid): ?array
    {
        return $this->db->row('SELECT * FROM ftp_users WHERE userid = ?', array($userid));
    }

    private function create(string $username, array $extra = array())
    {
        return $this->service()->create($this->caller('customer'), array_merge(array(
            'hostId' => $this->domainId(), 'username' => $username, 'password' => 'Ftp0Password'
        ), $extra));
    }

    public function testAnFtpUserIsCreatedInTheCustomersWebRoot(): void
    {
        $ref = $this->create('web');

        $userid = 'web@' . $this->fixture->domainName();
        self::assertSame(NodeType::FTP_USER, $ref->getTag());
        self::assertSame($userid, $ref->getKey());

        $row = $this->ftp($userid);
        self::assertSame($this->fixture->customerId(), (int)$row['admin_id']);
        self::assertStringStartsWith('$6$', $row['passwd']);
        self::assertSame('/bin/sh', $row['shell']);
        self::assertSame('/var/www/virtual/' . $this->fixture->domainName(), $row['homedir']);
        self::assertSame('toadd', $row['status']);

        self::assertSame(array('groupname' => 'sgwtcustomer', 'gid' => '0', 'members' => $userid), array_map('strval', $this->db->row(
            "SELECT groupname, gid, members FROM ftp_group WHERE groupname = 'sgwtcustomer'"
        )));
        // bytes_in_avail is a FLOAT, which MariaDB prints to six significant
        // digits. 5 GiB is exact in a float, so a DECIMAL cast reads it whole.
        self::assertSame('5368709120', (string)$this->db->value(
            "SELECT CAST(bytes_in_avail AS DECIMAL(20,0)) FROM quotalimits WHERE name = 'sgwtcustomer'"
        ), 'the account disk limit, 5120 MiB, in bytes');

        self::assertSame(array('onBeforeAddFtp', 'onAfterAddFtp'), $this->core->eventNames());
        self::assertSame(array(
            'ftpUserId'    => $userid,
            'ftpPassword'  => 'Ftp0Password',
            'ftpUserUid'   => 0,
            'ftpUserGid'   => 0,
            'ftpUserShell' => '/bin/sh',
            'ftpUserHome'  => '/var/www/virtual/' . $this->fixture->domainName()
        ), $this->core->events[0][1], 'listeners are handed the password in clear, as the page hands it');
        self::assertSame(1, $this->core->requests);
        self::assertSame('A new FTP account (' . $userid . ') has been created by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testASecondUserJoinsTheGroupAndTheQuotaRowIsNotRepeated(): void
    {
        $this->create('one');
        $this->create('two');

        self::assertSame(
            'one@' . $this->fixture->domainName() . ',two@' . $this->fixture->domainName(),
            $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'")
        );
        self::assertSame('1', (string)$this->db->value("SELECT COUNT(*) FROM quotalimits WHERE name = 'sgwtcustomer'"));
    }

    public function testADirectoryIsCheckedAndBecomesTheHome(): void
    {
        $this->create('web', array('directory' => 'shop/../shop/'));

        self::assertSame(array(array('sgwtcustomer', '/', '/shop')), $this->probe->asked);
        self::assertSame(
            '/var/www/virtual/' . $this->fixture->domainName() . '/shop',
            $this->ftp('web@' . $this->fixture->domainName())['homedir']
        );
    }

    public function testAMissingDirectoryIsRefused(): void
    {
        $this->probe = new FakeDirectoryProbe(false);
        $this->reconfigure(array());

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->create('web', array('directory' => '/nowhere'));
        });

        self::assertSame('input.directory', $e->getExtensions()['field']);
    }

    public function testTheLoginIsOnTheChosenHost(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId'   => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
            'username' => 'web', 'password' => 'Ftp0Password'
        ));

        self::assertSame('web@' . $this->fixture->subdomainName(), $ref->getKey());
    }

    public function testAnInvalidUsernameIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->create('-bad');
        });

        self::assertSame('input.username', $e->getExtensions()['field']);
    }

    public function testAWeakPasswordIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->create('web', array('password' => 'short'));
        });

        self::assertSame('input.password', $e->getExtensions()['field']);
    }

    public function testATakenLoginIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->create('sgwtftp');
        });
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->create('web');
        });
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->create('web');
        });
    }

    public function testChangingThePasswordKeepsTheHome(): void
    {
        $before = $this->ftp($this->fixture->ftpUserId());

        $this->service()->update($this->caller('customer'), $this->ftpId(), array('password' => 'Ftp0Password2'));

        $row = $this->ftp($this->fixture->ftpUserId());
        self::assertNotSame($before['passwd'], $row['passwd']);
        self::assertStringStartsWith('$6$', $row['passwd']);
        self::assertSame($before['homedir'], $row['homedir']);
        self::assertSame('tochange', $row['status']);
        self::assertSame(array('onBeforeEditFtp', 'onAfterEditFtp'), $this->core->eventNames());
        self::assertSame('Ftp0Password2', $this->core->events[0][1]['ftpPassword']);
        self::assertSame(
            'An FTP account (' . $this->fixture->ftpUserId() . ') has been updated by: sgwtcustomer',
            $this->core->logs[0][0]
        );
    }

    public function testChangingTheDirectoryKeepsThePassword(): void
    {
        $before = $this->ftp($this->fixture->ftpUserId());

        $this->service()->update($this->caller('customer'), $this->ftpId(), array('directory' => '/logs'));

        $row = $this->ftp($this->fixture->ftpUserId());
        self::assertSame($before['passwd'], $row['passwd']);
        self::assertSame('/var/www/virtual/' . $this->fixture->domainName() . '/logs', $row['homedir']);
        self::assertSame('', $this->core->events[0][1]['ftpPassword'], 'the page passes an empty password when it is unchanged');
    }

    public function testAnEmptyUpdateIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->ftpId(), array());
        });
    }

    public function testAPendingUserCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE ftp_users SET status = 'tochange' WHERE userid = ?", array($this->fixture->ftpUserId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->ftpId(), array('password' => 'Ftp0Password2'));
        });
    }

    public function testDeletingTheLastMemberRemovesTheGroupAndItsQuota(): void
    {
        $this->insert('ftp_group', array('groupname' => 'sgwtcustomer', 'gid' => 0, 'members' => $this->fixture->ftpUserId()));
        $this->insert('quotalimits', array('name' => 'sgwtcustomer', 'quota_type' => 'group'));

        $ref = $this->service()->delete($this->caller('customer'), $this->ftpId());

        self::assertSame($this->fixture->ftpUserId(), $ref->getKey());
        self::assertSame('todelete', $this->ftp($this->fixture->ftpUserId())['status']);
        self::assertNull($this->db->value("SELECT groupname FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertNull($this->db->value("SELECT name FROM quotalimits WHERE name = 'sgwtcustomer'"));
        self::assertSame(array('onBeforeDeleteFtp', 'onAfterDeleteFtp'), $this->core->eventNames());
        self::assertSame(array('ftpUserId' => $this->fixture->ftpUserId()), $this->core->events[0][1]);
        self::assertSame('An FTP account has been deleted by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testDeletingOneMemberKeepsTheOthers(): void
    {
        $this->insert('ftp_group', array(
            'groupname' => 'sgwtcustomer', 'gid' => 0,
            'members'   => 'other@' . $this->fixture->domainName() . ',' . $this->fixture->ftpUserId()
        ));

        $this->service()->delete($this->caller('customer'), $this->ftpId());

        self::assertSame(
            'other@' . $this->fixture->domainName(),
            $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'")
        );
    }

    public function testAUserNotInTheGroupLeavesTheGroupAlone(): void
    {
        $this->insert('ftp_group', array('groupname' => 'sgwtcustomer', 'gid' => 0, 'members' => ''));

        $this->service()->delete($this->caller('customer'), $this->ftpId());

        self::assertSame('sgwtcustomer', $this->db->value("SELECT groupname FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter FtpServiceTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\FtpService" not found`.

- [ ] **Step 3: Write the service**

Create `Service/FtpService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\FtpGroups;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;

/**
 * FTP users. Owned by the customer, not the domain (spec section 2.2), and
 * named user@host for whichever of the customer's hosts the login is on.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/ftp_add.php, ftp_edit.php and
 *   ftp_delete.php. Retire when FtpUserService lands in core (spec section 21,
 *   C3 row 2).
 */
final class FtpService
{
    const HOSTS = array(NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN);

    const SHELL = '/bin/sh';

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

        // 2, 3.
        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::FTP_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_ftpacc_limit'),
            $kit->counts()->ftpUsers(array($account->getAdminId()))[$account->getAdminId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'ftp');

        // 5. ftp_add.php lists only settled hosts (lines 122-160).
        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // 6. CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:191-231.
        $username = trim((string)($input['username'] ?? ''), ' ');

        if (!$core->isValidUsername($username)) {
            throw Guard::badInput('input.username', 'Invalid FTP username.');
        }

        $password = $this->password($input);
        $directory = $this->directory($account, (string)($input['directory'] ?? '/'));

        // 7.
        Guard::requireQuota($quota, 'ftpUsers');

        $userid = $username . '@' . $hostRow['name'];
        $home = $this->home($account, $directory);
        $hasQuotaRow = $kit->db()->value('SELECT name FROM quotalimits WHERE name = ?', array($account->getUsername())) !== null;

        $params = array(
            'ftpUserId'    => $userid,
            'ftpPassword'  => $password,
            'ftpUserUid'   => $account->getSysUid(),
            'ftpUserGid'   => $account->getSysGid(),
            'ftpUserShell' => self::SHELL,
            'ftpUserHome'  => $home
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:256-330.
        $kit->writer()->run(function () use ($kit, $core, $account, $userid, $password, $home, $hasQuotaRow, $params) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeAddFtp, $params);

            $db->execute(
                "
                    INSERT INTO ftp_users (userid, admin_id, passwd, uid, gid, shell, homedir, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'toadd')
                ",
                array(
                    $userid, $account->getAdminId(), $core->hashPassword($password),
                    $account->getSysUid(), $account->getSysGid(), self::SHELL, $home
                )
            );

            (new FtpGroups($db))->addMember($account->getUsername(), $account->getSysGid(), $userid);

            if (!$hasQuotaRow) {
                $diskLimit = (int)$account->domain('domain_disk_limit');

                $db->execute(
                    "
                        INSERT INTO quotalimits (
                            name, quota_type, per_session, limit_type, bytes_in_avail, bytes_out_avail,
                            bytes_xfer_avail, files_in_avail, files_out_avail, files_xfer_avail
                        ) VALUES (?, 'group', 'false', 'hard', ?, 0, 0, 0, 0, 0)
                    ",
                    array($account->getUsername(), $diskLimit > 0 ? $diskLimit * 1048576 : 0)
                );
            }

            $core->dispatch(Events::onAfterAddFtp, $params);
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(
            sprintf('A new FTP account (%s) has been created by %s', $userid, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::FTP_USER, $userid);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::FTP_USER), Scope::FTP_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_ftpacc_limit') >= 0, 'ftp');

        $row = $this->ftpRow($target);
        // ftp_edit.php does not ask; spec section 8.3 does.
        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK));

        if (!isset($input['password']) && !isset($input['directory'])) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $password = isset($input['password']) ? $this->password($input) : '';
        $home = isset($input['directory'])
            ? $this->home($account, $this->directory($account, (string)$input['directory']))
            : (string)$row['homedir'];
        $userid = (string)$row['userid'];
        $params = array('ftpUserId' => $userid, 'ftpPassword' => $password, 'ftpUserHome' => $home);

        // CORE-DEBT(C3): transcribed from gui/public/client/ftp_edit.php:88-110.
        $kit->writer()->run(function () use ($kit, $core, $account, $userid, $password, $home, $params) {
            $core->dispatch(Events::onBeforeEditFtp, $params);

            if ($password !== '') {
                $kit->db()->execute(
                    "UPDATE ftp_users SET passwd = ?, homedir = ?, status = 'tochange' WHERE userid = ? AND admin_id = ?",
                    array($core->hashPassword($password), $home, $userid, $account->getAdminId())
                );
            } else {
                $kit->db()->execute(
                    "UPDATE ftp_users SET homedir = ?, status = 'tochange' WHERE userid = ? AND admin_id = ?",
                    array($home, $userid, $account->getAdminId())
                );
            }

            $core->dispatch(Events::onAfterEditFtp, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('An FTP account (%s) has been updated by: %s', $userid, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::FTP_USER, $userid);
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::FTP_USER), Scope::FTP_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_ftpacc_limit') >= 0, 'ftp');

        $row = $this->ftpRow($target);
        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $userid = (string)$row['userid'];

        // CORE-DEBT(C3): transcribed from gui/public/client/ftp_delete.php:53-91.
        $kit->writer()->run(function () use ($kit, $core, $account, $userid) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteFtp, array('ftpUserId' => $userid));

            $groups = new FtpGroups($db);
            $group = $groups->ofCustomer($account->getUsername());

            // The page changes the group only when the user is in it (line 67).
            if ($group !== null && in_array($userid, explode(',', (string)$group['members']), true)) {
                $groups->removeMembers($group, static function (string $member) use ($userid) {
                    return $member === $userid;
                });
            }

            $db->execute("UPDATE ftp_users SET status = 'todelete' WHERE userid = ?", array($userid));
            $core->dispatch(Events::onAfterDeleteFtp, array('ftpUserId' => $userid));
        });

        $core->sendRequest();
        $core->writeLog(sprintf('An FTP account has been deleted by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::FTP_USER, $userid);
    }

    /**
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    private function password(array $input): string
    {
        $core = $this->kit->core();
        $password = trim((string)($input['password'] ?? ''), ' ');

        if ($password === '' || !$core->isAcceptablePassword($password)) {
            throw Guard::badInput('input.password', "The password does not meet the panel's password policy.", array(
                'minLength'                => max(6, (int)$core->config('PASSWD_CHARS', 6)),
                'requiresLettersAndDigits' => (bool)$core->config('PASSWD_STRONG', false)
            ));
        }

        return $password;
    }

    /**
     * A directory relative to the customer's web root, normalised and checked.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:196,225-231.
     */
    private function directory(CustomerAccount $account, string $input): string
    {
        $directory = $this->kit->core()->normalisePath('/' . trim($input, ' '));

        if ($directory !== '/' && !$this->kit->probe()->exists($account->getUsername(), '/', $directory)) {
            throw Guard::badInput('input.directory', sprintf("The directory '%s' does not exist.", $directory));
        }

        return $directory;
    }

    /** CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:234-241. */
    private function home(CustomerAccount $account, string $directory): string
    {
        return $this->kit->core()->normalisePath(
            '/' . $this->kit->core()->config('USER_WEB_DIR', '/var/www/virtual')
                . '/' . $account->getDomainName() . '/' . $directory
        );
    }

    /**
     * @return array{userid: string, admin_id: string, homedir: string, status: string}
     */
    private function ftpRow(Target $target): array
    {
        $row = $this->kit->db()->row(
            'SELECT userid, admin_id, homedir, status FROM ftp_users WHERE userid = ?',
            array((string)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        return $row;
    }
}
```

- [ ] **Step 4: Run the service test**

Run: `tools/test.sh --filter FtpServiceTest`
Expected: PASS, 17 tests.

- [ ] **Step 5: Add the schema, resolver and wiring**

In `schema/schema.graphql`'s `type Mutation`, after `mailCatchallDelete`, add:

```graphql

  "The login is username@ the chosen host's name."
  ftpUserCreate(input: FtpUserCreateInput!): FtpUser!
  ftpUserUpdate(id: ID!, input: FtpUserUpdateInput!): FtpUser!
  ftpUserDelete(id: ID!): FtpUser!
```

Append at the end of the file:

```graphql
input FtpUserCreateInput {
  "The Domain, Subdomain or DomainAlias the login is named after."
  hostId: ID!
  "The part before the @."
  username: String!
  password: Secret!
  """
  Where the user lands, relative to the customer's web root, e.g. /shop. It
  must already exist. FtpUser.homeDirectory reads back the absolute path.
  """
  directory: String = "/"
}

"An absent field keeps its current value."
input FtpUserUpdateInput {
  password: Secret
  "As FtpUserCreateInput.directory."
  directory: String
}
```

Create `Resolver/FtpMutations.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\FtpService;

/** The three FTP mutations. See VirtualHostMutations for the pattern. */
final class FtpMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var FtpService */
    private $ftp;

    /** @var FtpSqlResolver */
    private $ftpSql;

    public function __construct(BatchLoader $loader, FtpService $ftp, FtpSqlResolver $ftpSql)
    {
        $this->loader = $loader;
        $this->ftp = $ftp;
        $this->ftpSql = $ftpSql;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.ftpUserCreate' => array($this, 'resolveFtpUserCreate'),
            'Mutation.ftpUserUpdate' => array($this, 'resolveFtpUserUpdate'),
            'Mutation.ftpUserDelete' => array($this, 'resolveFtpUserDelete')
        );
    }

    public function resolveFtpUserCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->ftp->create(TypeResolver::identity($context), (array)$args['input']);

        return $this->ftpSql->ftpReference((string)$ref->getKey());
    }

    public function resolveFtpUserUpdate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->ftp->update(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']);

        return $this->ftpSql->ftpReference((string)$ref->getKey());
    }

    public function resolveFtpUserDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->ftp->delete(TypeResolver::identity($context), (string)$args['id']);

        return $this->ftpSql->ftpReference((string)$ref->getKey());
    }
}
```

In `Api/Container.php` add `use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpMutations;` and `use iMSCP\Plugin\SGW_GraphQL\Service\FtpService;`, and append to `$this->maps`:

```php
            'FtpMutations' => (new FtpMutations($loader, new FtpService($kit), $ftpSql))->map()
```

- [ ] **Step 6: Run the matrix, write the document test**

Run: `tools/test.sh --testsuite authz --filter 'ftp'`
Expected: PASS — 18 matrix rows and 9 scope rows.

Create `test/integration/FtpMutationsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

class FtpMutationsTest extends AuthzTestCase
{
    public function testAnFtpUserReadsBackWithAStringKeyedIdentifier(): void
    {
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = 0 WHERE domain_id = ?', array($this->fixture->domainId()));

        $result = $this->execute(
            'mutation($input: FtpUserCreateInput!) { ftpUserCreate(input: $input) { id username homeDirectory provisioning { state } } }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'username' => 'web', 'password' => 'Ftp0Password', 'directory' => '/shop'
            )),
            $this->fixture->identity('customer')
        );

        $userid = 'web@' . $this->fixture->domainName();
        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'id'            => GlobalId::encodeKey(NodeType::FTP_USER, $userid),
            'username'      => $userid,
            'homeDirectory' => '/var/www/virtual/' . $this->fixture->domainName() . '/shop',
            'provisioning'  => array('state' => 'PENDING')
        ), $result['data']['ftpUserCreate']);
    }
}
```

Run: `tools/test.sh --filter FtpMutationsTest`
Expected: PASS.

- [ ] **Step 7: Regenerate the snapshot, run everything, commit**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql'`

Run: `tools/test.sh`
Expected: all PASS.

```bash
git add Service/FtpService.php Resolver/FtpMutations.php Api/Container.php schema/schema.graphql test/schema/schema.printed.graphql test/integration/FtpServiceTest.php test/integration/FtpMutationsTest.php
git commit -m "Create, update and delete FTP users

The login is named after whichever of the customer's hosts it is on, lives
in the customer's ProFTPD group, and the group's quota row is created with
the first user from the account's disk limit, as ftp_add.php does. The home
directory is taken relative to the customer's web root, like the page's
form, and checked through the directory probe; FtpUser.homeDirectory reads
back the absolute path."
```

---

## Checkpoint C: `code-review medium` over Wave 4

Run by the orchestrating session, as the [Execution mandate](#waves-and-checkpoints) sets out.

**Focus:**

- Mailbox quota arithmetic: bytes and MiB, the pool excluding the account itself, a lowered account total, overflow.
- `MailService::delete()`: the forward and catch-all clean-up confined to the owning customer (C11 item 4), the RLIKE pattern built from an address.
- Secrets: a password reaching a log line, an error message or `extensions` — events carry it in clear by the page's design; nothing else may.
- Autoresponder state: when the status is written, when the daemon is poked, when neither.
- `FtpService`: group membership on create and delete, the quota row, home directories that normalise outside the web root.

- [ ] **Step 1: The suite, twice**

Run: `tools/test.sh`, then again.
Expected: both green.

- [ ] **Step 2: Review**

Invoke the `code-review` skill with arguments `medium phase3-wave-4..HEAD`, passing the **Focus** list above.

- [ ] **Step 3: Resolve the findings**

Fix each finding that holds, in commits whose subjects begin `Fix checkpoint C:`, then run `tools/test.sh` until green.

- [ ] **Step 4: Open the next wave**

Run: `git tag -a phase3-wave-5 -m "Checkpoint C: <declined findings and why, or: none declined>"`

---

## Wave 5: SQL and DNS

Tasks 12–15. Ends at Checkpoint D, after which every matrix row runs.

---

## Task 12: The SQL server — `opus`

`MariaDbSqlServer`: the DDL behind SQL databases and users, tested against the live MariaDB **outside any transaction**, because every statement it issues implicitly commits (measurement M5).

The test that matters is the grant: `sql_user_add.php:286-293` escapes `_` and `%` in the database name before `GRANT … ON name.*`, because the grant's database part is a pattern — unescaped, a user granted `sgwt_ddl_a_c` could also open `sgwtxddlxaxc`. This task proves the escaping holds by connecting *as the user* and listing what it can see. A plan cannot establish that; only the server can, which is why this task is `opus`.

**Files:**
- Create: `Service/MariaDbSqlServer.php`
- Create: `test/integration/MariaDbSqlServerTest.php`
- Modify: `Api/Container.php` (`fromPlugin()` and `sqlServer()`)

**Interfaces:**
- Consumes: `Service\SqlServer` (Task 2); `Repository\Db` (Task 1); `Container::sqlServer()` (Task 3).
- Produces:
  ```php
  new MariaDbSqlServer(Db $db, string $serverType, string $serverVersion)
  MariaDbSqlServer::fromPanel(Db $db): MariaDbSqlServer      // reads CONF_DIR/mysql/mysql.data
  MariaDbSqlServer::grantPattern(string $database): string    // '_' and '%' escaped
  Container::sqlServer(): ?SqlServer                          // built lazily in production
  ```

- [ ] **Step 1: Write the failing test**

Create `test/integration/MariaDbSqlServerTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer;
use PDO;
use PDOException;

/**
 * Real DDL against the box's MariaDB. No fixture and no transaction: each of
 * these statements commits whatever is open, so a transaction here would be a
 * lie. Everything is named sgwt_ddl* and removed in setUp() and tearDown(), so
 * a run killed half way is cleaned up by the next.
 */
class MariaDbSqlServerTest extends IntegrationTestCase
{
    const DATABASE  = 'sgwt_ddl_a_c';
    /** What the grant's pattern would match if '_' were left a wildcard. */
    const LOOKALIKE = 'sgwtxddlxaxc';
    const USER      = 'sgwt_ddl_u';
    const HOST      = 'localhost';
    const PASSWORD  = 'Ddl0Password';

    /** @var Db */
    private $db;

    /** @var MariaDbSqlServer */
    private $server;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();

        self::assertFalse(
            $this->db->pdo()->inTransaction(),
            'A transaction is open: the DDL below would commit it. A previous test left it behind.'
        );

        $this->server = MariaDbSqlServer::fromPanel($this->db);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->execute('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->db->execute('DROP DATABASE IF EXISTS `' . self::LOOKALIKE . '`');
        $this->db->execute("DROP USER IF EXISTS '" . self::USER . "'@'" . self::HOST . "'");
    }

    /**
     * A connection as the SQL user, over the server's socket: a user whose host
     * is 'localhost' matches a socket connection, not TCP to 127.0.0.1.
     */
    private function connectAs(string $password): PDO
    {
        $socket = (string)$this->db->value('SELECT @@socket');

        return new PDO('mysql:unix_socket=' . $socket, self::USER, $password, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ));
    }

    /** @return string[] */
    private function databasesVisibleTo(PDO $pdo): array
    {
        return $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testADatabaseIsCreatedFoundAndDropped(): void
    {
        self::assertFalse($this->server->databaseExists(self::DATABASE));

        $this->server->createDatabase(self::DATABASE);
        self::assertTrue($this->server->databaseExists(self::DATABASE));

        $this->server->dropDatabase(self::DATABASE);
        self::assertFalse($this->server->databaseExists(self::DATABASE));
    }

    public function testExistenceIsAnExactNameNotAPattern(): void
    {
        // C11 item 7: SHOW DATABASES LIKE 'sgwt_ddl_a_c' matches this.
        $this->server->createDatabase(self::LOOKALIKE);

        self::assertFalse($this->server->databaseExists(self::DATABASE));
    }

    public function testAUserIsCreatedFoundAndDropped(): void
    {
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);
        self::assertTrue($this->server->userExists(self::USER, self::HOST));
        $this->connectAs(self::PASSWORD);

        $this->server->dropUser(self::USER, self::HOST);
        self::assertFalse($this->server->userExists(self::USER, self::HOST));
    }

    public function testAGrantOpensTheDatabaseAndNotALookalike(): void
    {
        $this->server->createDatabase(self::DATABASE);
        $this->server->createDatabase(self::LOOKALIKE);
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);

        $this->server->grantDatabase(self::USER, self::HOST, self::DATABASE);

        $visible = $this->databasesVisibleTo($this->connectAs(self::PASSWORD));
        self::assertContains(self::DATABASE, $visible);
        self::assertNotContains(self::LOOKALIKE, $visible, 'the grant pattern was not escaped');
    }

    public function testRevokingOneGrantLeavesTheUser(): void
    {
        $this->server->createDatabase(self::DATABASE);
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);
        $this->server->grantDatabase(self::USER, self::HOST, self::DATABASE);

        $this->server->revokeDatabase(self::USER, self::HOST, self::DATABASE);

        self::assertTrue($this->server->userExists(self::USER, self::HOST));
        self::assertNotContains(self::DATABASE, $this->databasesVisibleTo($this->connectAs(self::PASSWORD)));
    }

    public function testSettingThePasswordChangesTheLogin(): void
    {
        $this->server->createUser(self::USER, self::HOST, self::PASSWORD);

        $this->server->setPassword(self::USER, self::HOST, 'Ddl0Changed');

        $this->connectAs('Ddl0Changed');

        $this->expectException(PDOException::class);
        $this->connectAs(self::PASSWORD);
    }

    public function testTheGrantPatternEscapesBothWildcards(): void
    {
        self::assertSame('a\_b\%c', MariaDbSqlServer::grantPattern('a_b%c'));
    }

    public function testAnIdentifierWithABacktickIsQuotedNotInjected(): void
    {
        // The panel refuses no character in a database name but length. A
        // backtick must stay inside the identifier.
        $name = 'sgwt_ddl`x';

        try {
            $this->server->createDatabase($name);
            self::assertTrue($this->server->databaseExists($name));
        } finally {
            $this->server->dropDatabase($name);
        }

        self::assertFalse($this->server->databaseExists($name));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter MariaDbSqlServerTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer" not found`.

- [ ] **Step 3: Write the implementation**

Create `Service/MariaDbSqlServer.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Config\FileConfig;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Registry;

/**
 * The statements i-MSCP issues against the SQL server itself.
 *
 * CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:57-62,
 *   sql_user_add.php:276-298, sql_change_password.php:75-84, and
 *   delete_sql_database() / sql_delete_user() in gui/include/Shared.php:623-777,
 *   which cannot be called because they interleave this DDL with row writes
 *   (measurement M5). Retire when SqlDatabaseService and SqlUserService land in
 *   core (spec section 21, C3 row 1).
 */
final class MariaDbSqlServer implements SqlServer
{
    /** @var Db */
    private $db;

    /**
     * MariaDB, and MySQL before 5.7.6, take the older password syntax
     * (sql_user_add.php:277).
     *
     * @var bool
     */
    private $legacyPasswordSyntax;

    public function __construct(Db $db, string $serverType, string $serverVersion)
    {
        $this->db = $db;
        $this->legacyPasswordSyntax = $serverType === 'mariadb' || version_compare($serverVersion, '5.7.6', '<');
    }

    public static function fromPanel(Db $db): self
    {
        $config = new FileConfig(Registry::get('config')['CONF_DIR'] . '/mysql/mysql.data');

        return new self($db, (string)$config['SQLD_TYPE'], (string)$config['SQLD_VERSION']);
    }

    public function databaseExists(string $name): bool
    {
        // CORE-DEBT(C11): C11 item 7 - an exact name, not SHOW DATABASES LIKE.
        return (int)$this->db->value(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            array($name)
        ) > 0;
    }

    public function createDatabase(string $name): void
    {
        $this->db->execute('CREATE DATABASE IF NOT EXISTS ' . self::identifier($name));
    }

    public function dropDatabase(string $name): void
    {
        $this->db->execute('DROP DATABASE IF EXISTS ' . self::identifier($name));
    }

    public function userExists(string $user, string $host): bool
    {
        return (int)$this->db->value(
            'SELECT COUNT(User) FROM mysql.user WHERE User = ? AND Host = ?',
            array($user, $host)
        ) > 0;
    }

    public function createUser(string $user, string $host, string $password): void
    {
        $this->db->execute(
            $this->legacyPasswordSyntax
                ? 'CREATE USER ?@? IDENTIFIED BY ?'
                : 'CREATE USER ?@? IDENTIFIED BY ? PASSWORD EXPIRE NEVER',
            array($user, $host, $password)
        );
    }

    public function grantDatabase(string $user, string $host, string $database): void
    {
        $this->db->execute(
            sprintf('GRANT ALL PRIVILEGES ON %s.* TO ?@?', self::identifier(self::grantPattern($database))),
            array($user, $host)
        );
    }

    public function revokeDatabase(string $user, string $host, string $database): void
    {
        // sql_delete_user()'s own statements (Shared.php:680-684). Measurement
        // M21: they still work on MariaDB 10.11.
        $this->db->execute(
            'DELETE FROM mysql.db WHERE Host = ? AND Db = ? AND User = ?',
            array($host, self::grantPattern($database), $user)
        );
        $this->db->execute('FLUSH PRIVILEGES');
    }

    public function dropUser(string $user, string $host): void
    {
        // Shared.php:672-677.
        $this->db->execute('DELETE FROM mysql.user WHERE User = ? AND Host = ?', array($user, $host));
        $this->db->execute('DELETE FROM mysql.db WHERE Host = ? AND User = ?', array($host, $user));
        $this->db->execute('FLUSH PRIVILEGES');
    }

    public function setPassword(string $user, string $host, string $password): void
    {
        $this->db->execute(
            $this->legacyPasswordSyntax
                ? 'SET PASSWORD FOR ?@? = PASSWORD(?)'
                : 'ALTER USER ?@? IDENTIFIED BY ? PASSWORD EXPIRE NEVER',
            array($user, $host, $password)
        );
    }

    /**
     * A database name as a GRANT pattern: '_' and '%' are wildcards there, so
     * without this a grant on a_c also opens abc (sql_user_add.php:286-293).
     */
    public static function grantPattern(string $database): string
    {
        return (string)preg_replace('/([%_])/', '\\\\$1', $database);
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
```

- [ ] **Step 4: Run it**

Run: `tools/test.sh --filter MariaDbSqlServerTest`
Expected: PASS, 8 tests.

If `testAGrantOpensTheDatabaseAndNotALookalike` fails on the `assertNotContains`, **do not weaken the assertion.** Find out what the server stored: `SELECT Db FROM mysql.db WHERE User = 'sgwt_ddl_u'` should read `sgwt\_ddl\_a\_c`. If the backslashes were lost, the fault is in how the pattern reaches the statement, and the panel's own page has the same fault.

If `connectAs()` cannot connect at all, check `SELECT @@socket` and whether the user's host should be `localhost` for a socket login on this server. Adjust the test's connection, not the implementation, and record what was measured in the commit message.

- [ ] **Step 5: Build it lazily in production**

In `Api/Container.php`:

1. Add `use iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer;`.
2. Add a property after `$sqlServer`:

```php
    /**
     * @var callable|null fn(Db): SqlServer - production only. Built on first
     *                    use: reading mysql.data on every request, queries
     *                    included, would be a file read nobody asked for.
     */
    private $sqlServerFactory;
```

3. In `fromPlugin()`, replace `return new self(` with `$container = new self(`, and after that statement's closing `);` add:

```php
        $container->sqlServerFactory = static function (Db $db) {
            return MariaDbSqlServer::fromPanel($db);
        };

        return $container;
```

4. Replace `sqlServer()` with:

```php
    public function sqlServer(): ?SqlServer
    {
        if ($this->sqlServer === null && $this->sqlServerFactory !== null) {
            $this->sqlServer = call_user_func($this->sqlServerFactory, $this->db);
        }

        return $this->sqlServer;
    }
```

Run: `tools/test.sh --testsuite unit --filter ContainerTest`
Expected: PASS — `forTesting()` still has no SQL server.

- [ ] **Step 6: Run everything and commit**

Run: `tools/test.sh`
Expected: all PASS. Run it twice: the second run proves the first left nothing behind.

```bash
git add Service/MariaDbSqlServer.php test/integration/MariaDbSqlServerTest.php Api/Container.php
git commit -m "Issue the SQL server's DDL behind a port, tested as the user it creates

Every statement here implicitly commits, so none of it can run inside a test
fixture; the test runs outside any transaction and cleans up by name.

The assertion that matters connects as the created SQL user and lists what it
can see. A GRANT's database name is a pattern, and without the escaping the
page applies, a user granted sgwt_ddl_a_c would also see sgwtxddlxaxc.
Existence is asked by exact name, where the page's SHOW DATABASES LIKE
refuses a name whenever another database matches it as a pattern."
```

---
## Task 13: SQL databases and users — `sonnet`

The five SQL mutations: the service, the schema and the resolver. The service is tested against `FakeSqlServer`, which records the DDL instead of issuing it; Task 12 tested the DDL itself.

SQL objects differ from everything else in this plan in one way the code must show (spec §2.1): they are created by the panel, synchronously. There is no status column, no `send_request()`, nothing to settle — and the DDL implicitly commits, so a create cannot be one transaction with its row. The services issue the DDL first, write the row in a transaction second, and undo the DDL if the row fails.

**Files:**
- Create: `Service/SqlService.php`, `Resolver/SqlMutations.php`
- Create: `test/integration/SqlServiceTest.php`, `test/integration/SqlMutationsTest.php`
- Modify: `schema/schema.graphql`, `test/schema/schema.printed.graphql`, `Api/Container.php`

**Interfaces:**
- Consumes: `Toolkit`, `Guard`, `ObjectRef` (Task 3); `SqlServer`, `FakeSqlServer` (Task 2); `ServiceTestCase` (Task 6); `Container::sqlServer()` (Task 12); `FtpSqlResolver::databaseReference()`, `sqlUserReference()`, `shapeDatabase()`, `shapeSqlUser()`.
- Produces:
  ```php
  new SqlService(Toolkit $kit, callable $sqlServer)      // fn(): SqlServer, asked on first use
  SqlService::createDatabase(Identity $caller, array $input): ObjectRef
  SqlService::deleteDatabase(Identity $caller, string $id): ObjectRef     // snapshot: sqld_id, domain_id, sqld_name, domain_admin_id
  SqlService::createUser(Identity $caller, array $input): ObjectRef
  SqlService::setUserPassword(Identity $caller, string $id, string $password): ObjectRef
  SqlService::deleteUser(Identity $caller, string $id): ObjectRef         // snapshot: sqlu_id, sqld_id, sqlu_name, sqlu_host, sqld_name
  new SqlMutations(BatchLoader $loader, SqlService $sql, FtpSqlResolver $ftpSql)
  ```
  SDL: `enum SqlNamePrefix`, `SqlDatabaseCreateInput`, `SqlUserCreateInput`.

- [ ] **Step 1: Write the failing service test**

Create `test/integration/SqlServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\SqlService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeSqlServer;

class SqlServiceTest extends ServiceTestCase
{
    /** @var FakeSqlServer */
    private $sqlServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqlServer = new FakeSqlServer();
    }

    private function service(): SqlService
    {
        return new SqlService($this->kit, function () {
            return $this->sqlServer;
        });
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function databaseId(): string
    {
        return GlobalId::encode(NodeType::SQL_DATABASE, $this->fixture->sqlDatabaseId());
    }

    private function sqlUserId(): string
    {
        return GlobalId::encode(NodeType::SQL_USER, $this->fixture->sqlUserId());
    }

    private function newUser(array $extra = array()): array
    {
        return array_merge(array(
            'databaseId' => $this->databaseId(), 'name' => 'sgwt_new', 'host' => 'localhost', 'password' => 'Sql0Password'
        ), $extra);
    }

    /** A second database of the customer's, with no users. */
    private function secondDatabase(): int
    {
        return $this->insert('sql_database', array('domain_id' => $this->fixture->domainId(), 'sqld_name' => 'sgwt_two'));
    }

    // ---- databases ------------------------------------------------------

    public function testADatabaseIsCreatedAndItsRowWritten(): void
    {
        $ref = $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));

        self::assertSame(NodeType::SQL_DATABASE, $ref->getTag());
        self::assertSame(array(array('createDatabase', 'sgwt_new')), $this->sqlServer->operations);
        self::assertSame(
            array('domain_id' => (string)$this->fixture->domainId(), 'sqld_name' => 'sgwt_new'),
            array_map('strval', $this->db->row('SELECT domain_id, sqld_name FROM sql_database WHERE sqld_id = ?', array($ref->getKey())))
        );
        self::assertSame(array('onBeforeAddSqlDb', 'onAfterAddSqlDb'), $this->core->eventNames());
        self::assertSame(array('dbName' => 'sgwt_new'), $this->core->events[0][1]);
        self::assertSame(array('dbId' => $ref->getKey(), 'dbName' => 'sgwt_new'), $this->core->events[1][1]);
        self::assertSame(0, $this->core->requests, 'synchronous: nothing for the daemon to do');
        self::assertSame('A new database (sgwt_new) has been created by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testTheDomainIdMayBePrefixedOrSuffixed(): void
    {
        $domainId = $this->fixture->domainId();

        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'a', 'prefix' => 'START'));
        self::assertTrue($this->sqlServer->databaseExists($domainId . '_a'));

        $this->db->execute('UPDATE domain SET domain_sqld_limit = 0 WHERE domain_id = ?', array($domainId));
        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'b', 'prefix' => 'END'));
        self::assertTrue($this->sqlServer->databaseExists('b_' . $domainId));
    }

    public function testAPanelThatPlacesTheDomainIdAllowsOnlyThatPlacement(): void
    {
        $this->reconfigure(array('MYSQL_PREFIX' => 'infront'));

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'a', 'prefix' => 'END'));
        });
        self::assertSame(array('field' => 'input.prefix', 'required' => 'START'), $e->getExtensions());

        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'a'));
        self::assertTrue($this->sqlServer->databaseExists($this->fixture->domainId() . '_a'));
    }

    public function testATooLongOrReservedNameIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => str_repeat('x', 65)));
        });
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'mysql'));
        });

        self::assertSame(array(), $this->sqlServer->operations);
    }

    public function testADatabaseTheServerAlreadyHasIsAConflict(): void
    {
        $this->sqlServer->databases['sgwt_new'] = true;

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));
        });

        self::assertSame(array(), $this->sqlServer->operations);
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        // Limit 2; the fixture has one.
        $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_a'));

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_b'));
        });

        self::assertSame(array('quota' => 'sqlDatabases', 'limit' => 2, 'used' => 2), $e->getExtensions());
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_sqld_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_new'));
        });
    }

    public function testADatabaseWhoseRowCannotBeWrittenIsDroppedAgain(): void
    {
        // sqld_name is unique. The fake does not know the fixture's
        // sgwt_shop exists, so the server step succeeds and the row fails.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createDatabase($this->caller('customer'), array('domainId' => $this->domainId(), 'name' => 'sgwt_shop'));
        });

        self::assertSame(
            array(array('createDatabase', 'sgwt_shop'), array('dropDatabase', 'sgwt_shop')),
            $this->sqlServer->operations
        );
    }

    public function testDeletingADatabaseDropsItsOnlyGrantedUsersFirst(): void
    {
        $ref = $this->service()->deleteDatabase($this->caller('customer'), $this->databaseId());

        self::assertSame(
            array(array('dropUser', 'sgwt_u1', 'localhost'), array('dropDatabase', 'sgwt_shop')),
            $this->sqlServer->operations
        );
        self::assertNull($this->db->value('SELECT sqld_id FROM sql_database WHERE sqld_id = ?', array($this->fixture->sqlDatabaseId())));
        self::assertNull($this->db->value('SELECT sqlu_id FROM sql_user WHERE sqlu_id = ?', array($this->fixture->sqlUserId())));
        self::assertSame(
            array('onBeforeDeleteSqlDb', 'onBeforeDeleteSqlUser', 'onAfterDeleteSqlUser', 'onAfterSqlDb'),
            $this->core->eventNames(),
            'Events::onAfterDeleteSqlDb is the string onAfterSqlDb (M15)'
        );
        self::assertSame('sgwt_shop', $ref->getSnapshot()['sqld_name']);
        self::assertSame($this->fixture->customerId(), (int)$ref->getSnapshot()['domain_admin_id']);
        self::assertSame(
            'sgwtcustomer deleted SQL database with ID ' . $this->fixture->sqlDatabaseId(),
            $this->core->logs[0][0]
        );
    }

    public function testAUserGrantedElsewhereTooOnlyLosesThisGrant(): void
    {
        $two = $this->secondDatabase();
        $this->insert('sql_user', array('sqld_id' => $two, 'sqlu_name' => 'sgwt_u1', 'sqlu_host' => 'localhost'));

        $this->service()->deleteDatabase($this->caller('customer'), $this->databaseId());

        self::assertSame(
            array(array('revokeDatabase', 'sgwt_u1', 'localhost', 'sgwt_shop'), array('dropDatabase', 'sgwt_shop')),
            $this->sqlServer->operations
        );
    }

    // ---- users ----------------------------------------------------------

    public function testANewUserIsCreatedAndGranted(): void
    {
        $ref = $this->service()->createUser($this->caller('customer'), $this->newUser());

        self::assertSame(NodeType::SQL_USER, $ref->getTag());
        self::assertSame(
            array(array('createUser', 'sgwt_new', 'localhost'), array('grantDatabase', 'sgwt_new', 'localhost', 'sgwt_shop')),
            $this->sqlServer->operations
        );
        self::assertSame(
            array('sqld_id' => (string)$this->fixture->sqlDatabaseId(), 'sqlu_name' => 'sgwt_new', 'sqlu_host' => 'localhost'),
            array_map('strval', $this->db->row('SELECT sqld_id, sqlu_name, sqlu_host FROM sql_user WHERE sqlu_id = ?', array($ref->getKey())))
        );
        self::assertSame(array('onBeforeAddSqlUser', 'onAfterAddSqlUser'), $this->core->eventNames());
        self::assertSame(array(
            'SqlUserId'       => $ref->getKey(),
            'SqlUsername'     => 'sgwt_new',
            'SqlUserHost'     => 'localhost',
            'SqlUserPassword' => 'Sql0Password',
            'SqlDatabaseId'   => $this->fixture->sqlDatabaseId()
        ), $this->core->events[1][1]);
        self::assertSame('A SQL user has been added by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testTheHostDefaultsToThePanelsSetting(): void
    {
        $input = $this->newUser();
        unset($input['host']);

        $this->reconfigure(array('DATABASE_USER_HOST' => '127.0.0.1'));
        $this->service()->createUser($this->caller('customer'), $input);
        self::assertTrue($this->sqlServer->userExists('sgwt_new', 'localhost'), '127.0.0.1 is offered as localhost');
    }

    public function testAnInvalidHostIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('host' => 'bad host')));
        });

        self::assertSame('input.host', $e->getExtensions()['field']);
    }

    public function testATooLongOrReservedUserNameIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('name' => str_repeat('u', 17))));
        });
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('name' => 'root')));
        });
    }

    public function testExactlyOneOfNameOrExistingUserIsGiven(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), array('databaseId' => $this->databaseId()));
        });
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser(array('existingUserId' => $this->sqlUserId())));
        });
    }

    public function testAUserTheServerAlreadyHasIsAConflict(): void
    {
        $this->sqlServer->users['sgwt_new@localhost'] = 'x';

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser());
        });
    }

    public function testTheUserLimitIsAskedBeforeTheUserIsCreated(): void
    {
        // C11 item 3. Limit 1, and the fixture has one distinct user name.
        $this->db->execute('UPDATE domain SET domain_sqlu_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->createUser($this->caller('customer'), $this->newUser());
        });

        self::assertSame(array(), $this->sqlServer->operations);
    }

    public function testANameTheCustomerAlreadyHasDoesNotCountAgain(): void
    {
        // Counting.php:586 counts DISTINCT sqlu_name.
        $this->db->execute('UPDATE domain SET domain_sqlu_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->service()->createUser($this->caller('customer'), $this->newUser(array('name' => 'sgwt_u1', 'host' => '%')));

        self::assertTrue($this->sqlServer->userExists('sgwt_u1', '%'));
    }

    public function testAnExistingUserIsGrantedAnotherDatabase(): void
    {
        $two = $this->secondDatabase();

        $ref = $this->service()->createUser($this->caller('customer'), array(
            'databaseId' => GlobalId::encode(NodeType::SQL_DATABASE, $two), 'existingUserId' => $this->sqlUserId()
        ));

        self::assertSame(array(array('grantDatabase', 'sgwt_u1', 'localhost', 'sgwt_two')), $this->sqlServer->operations);
        self::assertSame((string)$two, (string)$this->db->value('SELECT sqld_id FROM sql_user WHERE sqlu_id = ?', array($ref->getKey())));
        self::assertSame('', $this->core->events[0][1]['SqlUserPassword']);
    }

    public function testGrantingADatabaseTheUserAlreadyHasIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->createUser($this->caller('customer'), array(
                'databaseId' => $this->databaseId(), 'existingUserId' => $this->sqlUserId()
            ));
        });
    }

    public function testAnotherCustomersUserCannotBeGranted(): void
    {
        // The reseller reaches both customers; the user is still not this
        // database's customer's.
        $siblingDb = $this->insert('sql_database', array('domain_id' => $this->fixture->siblingDomainId(), 'sqld_name' => 'sgwt_sib'));
        $siblingUser = $this->insert('sql_user', array('sqld_id' => $siblingDb, 'sqlu_name' => 'sgwt_sib', 'sqlu_host' => 'localhost'));

        $e = $this->refused(ErrorCode::NOT_FOUND, function () use ($siblingUser) {
            $this->service()->createUser($this->caller('reseller'), array(
                'databaseId' => $this->databaseId(), 'existingUserId' => GlobalId::encode(NodeType::SQL_USER, $siblingUser)
            ));
        });

        self::assertSame('input.existingUserId', $e->getExtensions()['field']);
    }

    public function testAPasswordIsSetOnTheServer(): void
    {
        $this->service()->setUserPassword($this->caller('customer'), $this->sqlUserId(), 'Sql0Password2');

        self::assertSame(array(array('setPassword', 'sgwt_u1', 'localhost')), $this->sqlServer->operations);
        self::assertSame(array('onBeforeEditSqlUser', 'onAfterEditSqlUser'), $this->core->eventNames());
        self::assertSame('sgwtcustomer updated sgwt_u1@localhost SQL user password.', $this->core->logs[0][0]);
    }

    public function testAWeakPasswordIsRefusedOnTheArgument(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->setUserPassword($this->caller('customer'), $this->sqlUserId(), 'short');
        });

        self::assertSame('password', $e->getExtensions()['field']);
    }

    public function testDeletingAUsersOnlyGrantDropsTheUser(): void
    {
        $ref = $this->service()->deleteUser($this->caller('customer'), $this->sqlUserId());

        self::assertSame(array(array('dropUser', 'sgwt_u1', 'localhost')), $this->sqlServer->operations);
        self::assertNull($this->db->value('SELECT sqlu_id FROM sql_user WHERE sqlu_id = ?', array($this->fixture->sqlUserId())));
        self::assertSame('sgwt_u1', $ref->getSnapshot()['sqlu_name']);
        self::assertSame(array('onBeforeDeleteSqlUser', 'onAfterDeleteSqlUser'), $this->core->eventNames());
        self::assertSame(
            array('sqlUserId' => $this->fixture->sqlUserId(), 'sqlUsername' => 'sgwt_u1', 'sqlUserHost' => 'localhost'),
            $this->core->events[0][1]
        );
        self::assertSame('sgwtcustomer deleted SQL user with ID ' . $this->fixture->sqlUserId(), $this->core->logs[0][0]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter SqlServiceTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\SqlService" not found`.

- [ ] **Step 3: Write the service**

Create `Service/SqlService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use LogicException;
use Throwable;

/**
 * SQL databases and SQL users: the synchronous exception (spec section 2.1).
 *
 * No status, no daemon, nothing to settle, so spec section 8.1's steps 5 and
 * 9 do not apply. And the server's DDL implicitly commits, so step 8's single
 * transaction cannot hold both the DDL and the row: the DDL goes first, the
 * row in a transaction second, and a row that fails undoes the DDL.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/sql_database_add.php,
 *   sql_user_add.php, sql_change_password.php and delete_sql_database() /
 *   sql_delete_user() in gui/include/Shared.php. Retire when
 *   SqlDatabaseService and SqlUserService land in core (spec section 21, C3
 *   row 1).
 */
final class SqlService
{
    /** sql_database_add.php:50. */
    const RESERVED_DATABASES = array('information_schema', 'mysql', 'performance_schema', 'sys', 'test');

    /** sql_user_add.php:223. */
    const RESERVED_USERS = array('debian-sys-maint', 'mysql.user', 'root');

    const PREFIXES = array('NONE', 'START', 'END');

    /** @var Toolkit */
    private $kit;

    /** @var callable fn(): SqlServer */
    private $sqlServer;

    public function __construct(Toolkit $kit, callable $sqlServer)
    {
        $this->kit = $kit;
        $this->sqlServer = $sqlServer;
    }

    public function createDatabase(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $domain = $kit->guard()->target($caller, $input['domainId'] ?? null, array(NodeType::DOMAIN), Scope::SQL_WRITE, 'input.domainId');
        $account = $kit->accounts()->customer($domain->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_sqld_limit'),
            $kit->counts()->sqlDatabases(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'sql');

        // 6. CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:36-55.
        $name = trim((string)($input['name'] ?? ''), ' ');

        if ($name === '') {
            throw Guard::badInput('input.name', 'A database name is required.');
        }

        $name = $this->prefixed($name, $input, $account->getDomainId());

        if (strlen($name) > 64) {
            throw Guard::badInput('input.name', 'The database name is too long.', array('maximum' => 64));
        }

        if (in_array($name, self::RESERVED_DATABASES, true)) {
            throw Guard::badInput('input.name', 'That database name is reserved.');
        }

        // 7.
        Guard::requireQuota($quota, 'sqlDatabases');

        $server = $this->server();

        if ($server->databaseExists($name)) {
            throw Guard::conflict(sprintf('The database %s already exists.', $name));
        }

        // CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:57-68.
        $core->dispatch(Events::onBeforeAddSqlDb, array('dbName' => $name));
        $server->createDatabase($name);

        try {
            $id = $kit->writer()->run(function () use ($kit, $core, $account, $name) {
                $kit->db()->execute(
                    'INSERT INTO sql_database (domain_id, sqld_name) VALUES (?, ?)',
                    array($account->getDomainId(), $name)
                );
                $id = $kit->db()->lastInsertId();
                $core->dispatch(Events::onAfterAddSqlDb, array('dbId' => $id, 'dbName' => $name));

                return $id;
            });
        } catch (Throwable $e) {
            $server->dropDatabase($name);

            throw $e;
        }

        $core->writeLog(sprintf('A new database (%s) has been created by %s', $name, $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::SQL_DATABASE, $id);
    }

    public function deleteDatabase(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::SQL_DATABASE), Scope::SQL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0, 'sql');

        $row = $kit->db()->row(
            '
                SELECT sd.sqld_id, sd.domain_id, sd.sqld_name, d.domain_admin_id
                FROM sql_database AS sd JOIN domain AS d ON d.domain_id = sd.domain_id
                WHERE sd.sqld_id = ?
            ',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        $server = $this->server();
        $databaseId = (int)$row['sqld_id'];
        $name = (string)$row['sqld_name'];
        $params = array('sqlDbId' => $databaseId, 'sqlDatabaseName' => $name);

        // CORE-DEBT(C3): transcribed from delete_sql_database(), gui/include/Shared.php:706-777.
        $core->dispatch(Events::onBeforeDeleteSqlDb, $params);

        foreach ($kit->db()->rows(
            'SELECT sqlu_id, sqld_id, sqlu_name, sqlu_host FROM sql_user WHERE sqld_id = ?',
            array($databaseId)
        ) as $user) {
            $this->removeUser($server, $user + array('sqld_name' => $name));
        }

        $server->dropDatabase($name);
        $kit->db()->execute('DELETE FROM sql_database WHERE domain_id = ? AND sqld_id = ?', array($row['domain_id'], $databaseId));

        // Measurement M15: this constant's value is 'onAfterSqlDb'.
        $core->dispatch(Events::onAfterDeleteSqlDb, $params);

        $core->writeLog(sprintf('%s deleted SQL database with ID %s', $caller->getUsername(), $databaseId), E_USER_NOTICE);

        return new ObjectRef(NodeType::SQL_DATABASE, $databaseId, $row);
    }

    public function createUser(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $database = $kit->guard()->target($caller, $input['databaseId'] ?? null, array(NodeType::SQL_DATABASE), Scope::SQL_WRITE, 'input.databaseId');
        $account = $kit->accounts()->customer($database->getOwnerId());

        // 4. sql_user_add.php:379 asks 'sql'; a withheld user limit leaves
        //    nothing to add either.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_sqlu_limit'),
            $kit->counts()->sqlUsers(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0 && $quota->isEnabled(), 'sql');

        $databaseRow = $kit->db()->row('SELECT sqld_id, sqld_name FROM sql_database WHERE sqld_id = ?', array((int)$database->getKey()));

        if ($databaseRow === null) {
            throw Guard::notFound();
        }

        // 6.
        $isNew = isset($input['name']);

        if ($isNew === isset($input['existingUserId'])) {
            throw Guard::badInput('input.name', 'Give either name, for a new user, or existingUserId, to grant an existing one.');
        }

        $server = $this->server();
        $password = null;

        if ($isNew) {
            // CORE-DEBT(C3): transcribed from gui/public/client/sql_user_add.php:156-229.
            $name = trim((string)$input['name'], ' ');

            if ($name === '') {
                throw Guard::badInput('input.name', 'A user name is required.');
            }

            $host = isset($input['host'])
                ? trim((string)$input['host'], ' ')
                : (string)$core->config('DATABASE_USER_HOST', 'localhost');

            if ($host === '127.0.0.1') {
                // What the page offers as the default (sql_user_add.php:364).
                $host = 'localhost';
            }

            $host = $core->toAscii($host);

            if ($host === '' || !$core->isValidSqlHost($host)) {
                throw Guard::badInput('input.host', 'Invalid SQL user host.');
            }

            $password = $this->password($input['password'] ?? null, 'input.password');
            $name = $this->prefixed($name, $input, $account->getDomainId());

            if (strlen($name) > 16) {
                throw Guard::badInput('input.name', 'The SQL user name is too long.', array('maximum' => 16));
            }

            if (in_array($name, self::RESERVED_USERS, true)) {
                throw Guard::badInput('input.name', 'That SQL user name is reserved.');
            }

            // 7. CORE-DEBT(C11): C11 item 3 - asked before the user exists.
            //    Counting.php:586 counts DISTINCT names, so a name the customer
            //    already has on another host costs nothing more.
            $known = (int)$kit->db()->value(
                '
                    SELECT COUNT(*) FROM sql_user AS su JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                    WHERE sd.domain_id = ? AND su.sqlu_name = ?
                ',
                array($account->getDomainId(), $name)
            ) > 0;

            if (!$known) {
                Guard::requireQuota($quota, 'sqlUsers');
            }

            if ($server->userExists($name, $host)) {
                throw Guard::conflict(sprintf('The SQL user %s@%s is not available.', $name, $host));
            }
        } else {
            $user = $kit->guard()->target($caller, $input['existingUserId'], array(NodeType::SQL_USER), Scope::SQL_WRITE, 'input.existingUserId');

            if ($user->getOwnerId() !== $database->getOwnerId()) {
                throw new ApiException(ErrorCode::NOT_FOUND, Guard::notFound()->getMessage(), array('field' => 'input.existingUserId'));
            }

            $userRow = $this->userRow($user);
            $name = (string)$userRow['sqlu_name'];
            $host = (string)$userRow['sqlu_host'];

            if ((int)$kit->db()->value(
                'SELECT COUNT(*) FROM sql_user WHERE sqld_id = ? AND sqlu_name = ? AND sqlu_host = ?',
                array($databaseRow['sqld_id'], $name, $host)
            ) > 0) {
                throw Guard::conflict('That SQL user is already granted this database.');
            }
        }

        $databaseId = (int)$databaseRow['sqld_id'];
        $databaseName = (string)$databaseRow['sqld_name'];

        // CORE-DEBT(C3): transcribed from gui/public/client/sql_user_add.php:266-307.
        $core->dispatch(Events::onBeforeAddSqlUser, array(
            'SqlUsername' => $name, 'SqlUserHost' => $host, 'SqlUserPassword' => $password ?? ''
        ));

        if ($isNew) {
            $server->createUser($name, $host, $password);
        }

        $server->grantDatabase($name, $host, $databaseName);

        try {
            $id = $kit->writer()->run(function () use ($kit, $core, $databaseId, $name, $host, $password) {
                $kit->db()->execute(
                    'INSERT INTO sql_user (sqld_id, sqlu_name, sqlu_host) VALUES (?, ?, ?)',
                    array($databaseId, $name, $host)
                );
                $id = $kit->db()->lastInsertId();

                $core->dispatch(Events::onAfterAddSqlUser, array(
                    'SqlUserId'       => $id,
                    'SqlUsername'     => $name,
                    'SqlUserHost'     => $host,
                    'SqlUserPassword' => $password ?? '',
                    'SqlDatabaseId'   => $databaseId
                ));

                return $id;
            });
        } catch (Throwable $e) {
            if ($isNew) {
                $server->dropUser($name, $host);
            } else {
                $server->revokeDatabase($name, $host, $databaseName);
            }

            throw $e;
        }

        $core->writeLog(sprintf('A SQL user has been added by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::SQL_USER, $id);
    }

    public function setUserPassword(Identity $caller, string $id, string $password): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::SQL_USER), Scope::SQL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0, 'sql');

        $row = $this->userRow($target);
        $password = $this->password($password, 'password');
        $userId = (int)$row['sqlu_id'];
        $params = array('sqlUserId' => $userId, 'sqlUserPassword' => $password);

        // CORE-DEBT(C3): transcribed from gui/public/client/sql_change_password.php:69-94.
        $core->dispatch(Events::onBeforeEditSqlUser, $params);
        $this->server()->setPassword((string)$row['sqlu_name'], (string)$row['sqlu_host'], $password);
        $core->writeLog(
            sprintf('%s updated %s@%s SQL user password.', $caller->getUsername(), $row['sqlu_name'], $row['sqlu_host']),
            E_USER_NOTICE
        );
        $core->dispatch(Events::onAfterEditSqlUser, $params);

        return new ObjectRef(NodeType::SQL_USER, $userId);
    }

    public function deleteUser(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;

        $target = $kit->guard()->target($caller, $id, array(NodeType::SQL_USER), Scope::SQL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_sqld_limit') >= 0, 'sql');

        $row = $kit->db()->row(
            '
                SELECT su.sqlu_id, su.sqld_id, su.sqlu_name, su.sqlu_host, sd.sqld_name
                FROM sql_user AS su JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                WHERE su.sqlu_id = ?
            ',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        $this->removeUser($this->server(), $row);
        $kit->core()->writeLog(
            sprintf('%s deleted SQL user with ID %d', $caller->getUsername(), $row['sqlu_id']),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::SQL_USER, (int)$row['sqlu_id'], $row);
    }

    /**
     * One sql_user row: its grant, and the account itself when this was its
     * last grant anywhere on the server.
     *
     * CORE-DEBT(C3): transcribed from sql_delete_user(), gui/include/Shared.php:623-700.
     *
     * @param array<string, mixed> $row sqlu_id, sqlu_name, sqlu_host, sqld_name
     */
    private function removeUser(SqlServer $server, array $row): void
    {
        $kit = $this->kit;
        $params = array(
            'sqlUserId'   => (int)$row['sqlu_id'],
            'sqlUsername' => (string)$row['sqlu_name'],
            'sqlUserHost' => (string)$row['sqlu_host']
        );

        $kit->core()->dispatch(Events::onBeforeDeleteSqlUser, $params);

        $grants = (int)$kit->db()->value(
            'SELECT COUNT(sqlu_id) FROM sql_user WHERE sqlu_name = ? AND sqlu_host = ?',
            array($params['sqlUsername'], $params['sqlUserHost'])
        );

        if ($grants < 2) {
            $server->dropUser($params['sqlUsername'], $params['sqlUserHost']);
        } else {
            $server->revokeDatabase($params['sqlUsername'], $params['sqlUserHost'], (string)$row['sqld_name']);
        }

        $kit->db()->execute('DELETE FROM sql_user WHERE sqlu_id = ?', array($params['sqlUserId']));
        $kit->core()->dispatch(Events::onAfterDeleteSqlUser, $params);
    }

    /**
     * The domain id placed as the panel's settings allow.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/sql_database_add.php:33-43,
     *   and the template's handling of MYSQL_PREFIX (sql_database_add.php:83-106):
     *   'none' lets the customer choose, 'infront' and 'behind' decide for them.
     */
    private function prefixed(string $name, array $input, int $domainId): string
    {
        $mode = (string)$this->kit->core()->config('MYSQL_PREFIX', 'none');
        $requested = isset($input['prefix']) ? (string)$input['prefix'] : null;

        if ($mode === 'infront' || $mode === 'behind') {
            $forced = $mode === 'infront' ? 'START' : 'END';

            if ($requested !== null && $requested !== $forced) {
                throw Guard::badInput(
                    'input.prefix',
                    "The panel's settings decide where the domain id goes.",
                    array('required' => $forced)
                );
            }

            $position = $forced;
        } else {
            $position = $requested ?? 'NONE';
        }

        if (!in_array($position, self::PREFIXES, true)) {
            throw Guard::badInput('input.prefix', 'Unknown prefix position.');
        }

        if ($position === 'START') {
            return $domainId . '_' . $name;
        }

        return $position === 'END' ? $name . '_' . $domainId : $name;
    }

    /**
     * @param mixed $value
     */
    private function password($value, string $field): string
    {
        $core = $this->kit->core();
        $password = trim((string)$value, ' ');

        if ($password === '' || !$core->isAcceptablePassword($password)) {
            throw Guard::badInput($field, "The password does not meet the panel's password policy.", array(
                'minLength'                => max(6, (int)$core->config('PASSWD_CHARS', 6)),
                'requiresLettersAndDigits' => (bool)$core->config('PASSWD_STRONG', false)
            ));
        }

        return $password;
    }

    /**
     * @return array{sqlu_id: string, sqlu_name: string, sqlu_host: string}
     */
    private function userRow(Target $target): array
    {
        $row = $this->kit->db()->row(
            'SELECT sqlu_id, sqlu_name, sqlu_host FROM sql_user WHERE sqlu_id = ?',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        return $row;
    }

    private function server(): SqlServer
    {
        $server = call_user_func($this->sqlServer);

        if (!$server instanceof SqlServer) {
            throw new LogicException('No SQL server is configured for this request.');
        }

        return $server;
    }
}
```

- [ ] **Step 4: Run the service test**

Run: `tools/test.sh --filter SqlServiceTest`
Expected: PASS, 24 tests.

- [ ] **Step 5: Add the schema, resolver and wiring**

In `schema/schema.graphql`'s `type Mutation`, after `ftpUserDelete`, add:

```graphql

  """
  SQL databases and users are created by the panel at once, not by the
  backend: there is no provisioning state to wait for.
  """
  sqlDatabaseCreate(input: SqlDatabaseCreateInput!): SqlDatabase!
  """
  Drops the database. A user granted only this database is dropped with it;
  a user granted others too loses this grant only.
  """
  sqlDatabaseDelete(id: ID!): SqlDatabase!
  "Creates a user and grants it the database, or grants the database to one of the same customer's existing users."
  sqlUserCreate(input: SqlUserCreateInput!): SqlUser!
  "The password of the SQL account itself, which is the same account on every database it is granted."
  sqlUserSetPassword(id: ID!, password: Secret!): SqlUser!
  "Removes this grant. The SQL account goes with its last grant."
  sqlUserDelete(id: ID!): SqlUser!
```

Append at the end of the file:

```graphql
"Where the customer's domain id goes in a new SQL name, as the panel's MYSQL_PREFIX setting allows."
enum SqlNamePrefix {
  "The name as given."
  NONE
  "The domain id, an underscore, then the name."
  START
  "The name, an underscore, then the domain id."
  END
}

input SqlDatabaseCreateInput {
  "The customer's main domain."
  domainId: ID!
  "At most 64 characters, prefix included."
  name: String!
  "Where the panel is set to place the domain id itself, only that placement is accepted, and absent means it."
  prefix: SqlNamePrefix
}

"""
Either name (with password, and optionally host and prefix) for a new user,
or existingUserId to grant this database to a user the customer already has.
"""
input SqlUserCreateInput {
  databaseId: ID!
  "At most 16 characters, prefix included."
  name: String
  "Where the user may connect from. Defaults to the panel's setting."
  host: String
  password: Secret
  prefix: SqlNamePrefix
  existingUserId: ID
}
```

Create `Resolver/SqlMutations.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\SqlService;

/**
 * The five SQL mutations. See VirtualHostMutations for the pattern. A delete
 * removes its row, so it answers from the service's snapshot.
 */
final class SqlMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var SqlService */
    private $sql;

    /** @var FtpSqlResolver */
    private $ftpSql;

    public function __construct(BatchLoader $loader, SqlService $sql, FtpSqlResolver $ftpSql)
    {
        $this->loader = $loader;
        $this->sql = $sql;
        $this->ftpSql = $ftpSql;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.sqlDatabaseCreate'  => array($this, 'resolveSqlDatabaseCreate'),
            'Mutation.sqlDatabaseDelete'  => array($this, 'resolveSqlDatabaseDelete'),
            'Mutation.sqlUserCreate'      => array($this, 'resolveSqlUserCreate'),
            'Mutation.sqlUserSetPassword' => array($this, 'resolveSqlUserSetPassword'),
            'Mutation.sqlUserDelete'      => array($this, 'resolveSqlUserDelete')
        );
    }

    public function resolveSqlDatabaseCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->createDatabase(TypeResolver::identity($context), (array)$args['input']);

        return $this->ftpSql->databaseReference((int)$ref->getKey());
    }

    public function resolveSqlDatabaseDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->deleteDatabase(TypeResolver::identity($context), (string)$args['id']);

        return FtpSqlResolver::shapeDatabase($ref->getSnapshot());
    }

    public function resolveSqlUserCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->createUser(TypeResolver::identity($context), (array)$args['input']);

        return $this->ftpSql->sqlUserReference((int)$ref->getKey());
    }

    public function resolveSqlUserSetPassword($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->setUserPassword(TypeResolver::identity($context), (string)$args['id'], (string)$args['password']);

        return $this->ftpSql->sqlUserReference((int)$ref->getKey());
    }

    public function resolveSqlUserDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->deleteUser(TypeResolver::identity($context), (string)$args['id']);

        return FtpSqlResolver::shapeSqlUser($ref->getSnapshot());
    }
}
```

In `Api/Container.php` add `use iMSCP\Plugin\SGW_GraphQL\Resolver\SqlMutations;` and `use iMSCP\Plugin\SGW_GraphQL\Service\SqlService;`, and append to `$this->maps`:

```php
            'SqlMutations' => (new SqlMutations($loader, new SqlService($kit, function () {
                // Asked on first use, so that a request with no SQL mutation
                // never reads mysql.data.
                return $this->sqlServer();
            }), $ftpSql))->map()
```

- [ ] **Step 6: Run the matrix, write the document test**

Run: `tools/test.sh --testsuite authz --filter 'sql'`
Expected: PASS — 30 matrix rows and 15 scope rows.

Create `test/integration/SqlMutationsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

class SqlMutationsTest extends AuthzTestCase
{
    public function testADeletedDatabaseAnswersAsItWas(): void
    {
        $id = GlobalId::encode(NodeType::SQL_DATABASE, $this->fixture->sqlDatabaseId());

        $result = $this->execute(
            'mutation($id: ID!) { sqlDatabaseDelete(id: $id) { id name customer { username } } }',
            array('id' => $id),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(
            array('id' => $id, 'name' => 'sgwt_shop', 'customer' => array('username' => 'sgwtcustomer')),
            $result['data']['sqlDatabaseDelete']
        );
    }

    public function testACreatedUserReadsBackWithItsHost(): void
    {
        $result = $this->execute(
            'mutation($input: SqlUserCreateInput!) { sqlUserCreate(input: $input) { name host databases { name } } }',
            array('input' => array(
                'databaseId' => GlobalId::encode(NodeType::SQL_DATABASE, $this->fixture->sqlDatabaseId()),
                'name' => 'sgwt_new', 'host' => '%', 'password' => 'Sql0Password'
            )),
            $this->fixture->identity('customer')
        );

        self::assertSame(
            array('name' => 'sgwt_new', 'host' => '%', 'databases' => array(array('name' => 'sgwt_shop'))),
            $result['data']['sqlUserCreate']
        );
    }
}
```

Run: `tools/test.sh --filter SqlMutationsTest`
Expected: PASS, 2 tests.

- [ ] **Step 7: Regenerate the snapshot, run everything, commit**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql'`

Run: `tools/test.sh`
Expected: all PASS.

```bash
git add Service/SqlService.php Resolver/SqlMutations.php Api/Container.php schema/schema.graphql test/schema/schema.printed.graphql test/integration/SqlServiceTest.php test/integration/SqlMutationsTest.php
git commit -m "Create and delete SQL databases and users

The one synchronous family: no status, no daemon, and DDL that commits
whatever is open. So the DDL goes first, the row is written in a transaction
after it, and a row that cannot be written drops the database or user again.
A delete answers from a snapshot of the row it removed.

Two page defects are not copied: the SQL user limit is asked before the user
is created rather than after, and a user granted on another database loses
only this grant. Events::onAfterDeleteSqlDb is dispatched by its constant,
whose value is onAfterSqlDb - the literal name would reach no listener."
```

---
## Task 14: The DNS record codec — `haiku`

`DnsRecordData`: a `DnsRecordCreateInput`'s name, TTL and typed data encoded into the two columns `dns_edit.php` writes, `domain_dns` and `domain_text`. Pure; the panel's two functions it needs are passed in as callables. The TXT vectors are measurement M20: the page's own function's output, byte for byte.

**Files:**
- Create: `Support/DnsRecordData.php`
- Create: `test/unit/Support/DnsRecordDataTest.php`

**Interfaces:**
- Consumes: `Security\Guard::badInput()` (Task 3).
- Produces (Task 15):
  ```php
  DnsRecordData::CREATABLE_TYPES    // A AAAA CNAME MX NS SPF SRV TXT
  DnsRecordData::SRV_PROTOCOLS      // 'TCP' => 'tcp', 'UDP' => 'udp', 'TLS' => 'tls'
  DnsRecordData::DEFAULT_TTL        // 3600
  DnsRecordData::encode(string $type, array $input, string $zoneAscii, callable $toAscii, callable $domainNameError): array
      // array('domain_dns' => "name.\tttl", 'domain_text' => string); ApiException BAD_USER_INPUT with field
  DnsRecordData::formatTxt(string $data): string        // InvalidArgumentException
  DnsRecordData::quotedAndUnquoted(string $string): array   // array(quoted[], unquoted[])
  ```

- [ ] **Step 1: Write the failing test**

Create `test/unit/Support/DnsRecordDataTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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
use iMSCP\Plugin\SGW_GraphQL\Support\DnsRecordData;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DnsRecordDataTest extends TestCase
{
    const ZONE = 'zone.test';

    /** encode_idna() stand-in: every name in these tests is already ASCII. */
    private function toAscii(): callable
    {
        return static function (string $name): string {
            return $name;
        };
    }

    /** isValidDomainName() stand-in: dotted labels of [a-z0-9-], at least two. */
    private function domainNameError(): callable
    {
        return static function (string $name): ?string {
            return preg_match('/^([a-z0-9-]+\.)+[a-z0-9-]+$/', $name) ? null : 'Invalid domain name.';
        };
    }

    private function encode(string $type, array $input): array
    {
        return DnsRecordData::encode($type, $input, self::ZONE, $this->toAscii(), $this->domainNameError());
    }

    private function fieldOf(string $type, array $input): string
    {
        try {
            $this->encode($type, $input);
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());

            return $e->getExtensions()['field'];
        }

        self::fail('expected BAD_USER_INPUT');
    }

    // ---- names and TTLs -------------------------------------------------

    /**
     * @dataProvider names
     */
    public function testNamesAreCompletedInTheZone(string $name, string $expected): void
    {
        self::assertSame(
            $expected . ".\t3600",
            $this->encode('A', array('name' => $name, 'data' => array('address' => '203.0.113.9')))['domain_dns']
        );
    }

    public function names(): array
    {
        // dns_edit.php:601-607.
        return array(
            'empty is the zone'           => array('', 'zone.test'),
            '@ is the zone'               => array('@', 'zone.test'),
            'a relative name'             => array('www', 'www.zone.test'),
            'lower-cased'                 => array('WWW', 'www.zone.test'),
            'fully qualified in the zone' => array('mail.zone.test.', 'mail.zone.test'),
            'a leading underscore'        => array('_dmarc', '_dmarc.zone.test'),
            'a wildcard'                  => array('*', '*.zone.test')
        );
    }

    public function testTheTtlDefaultsAndIsKept(): void
    {
        self::assertSame(
            "www.zone.test.\t7200",
            $this->encode('A', array('name' => 'www', 'ttl' => 7200, 'data' => array('address' => '203.0.113.9')))['domain_dns']
        );
    }

    /**
     * @dataProvider refusedNames
     */
    public function testNamesThatAreRefused(array $input, string $field): void
    {
        self::assertSame($field, $this->fieldOf('A', $input + array('data' => array('address' => '203.0.113.9'))));
    }

    public function refusedNames(): array
    {
        return array(
            'another zone'                       => array(array('name' => 'other.test.'), 'input.name'),
            'a zone that merely ends like this'  => array(array('name' => 'evilzone.test.'), 'input.name'),
            'an invalid label'                   => array(array('name' => 'a..b'), 'input.name'),
            'a TTL below a minute'               => array(array('name' => 'www', 'ttl' => 59), 'input.ttl'),
            'a TTL that is not a number'         => array(array('name' => 'www', 'ttl' => '3600'), 'input.ttl')
        );
    }

    public function testAnUnderscoreIsStrippedOnlyWhenItLeads(): void
    {
        // C11 item 5: the page strips the first character of every name, so
        // 'xa..b' would be validated as 'a..b' there and 'x..b' would pass.
        self::assertSame('input.name', $this->fieldOf('A', array('name' => 'x..b', 'data' => array('address' => '203.0.113.9'))));
    }

    public function testATypeThatCannotBeManagedHereIsRefused(): void
    {
        self::assertSame('input.type', $this->fieldOf('PTR', array('name' => 'www', 'data' => array())));
    }

    // ---- data -----------------------------------------------------------

    public function testAddresses(): void
    {
        self::assertSame('203.0.113.9', $this->encode('A', array('data' => array('address' => ' 203.0.113.9 ')))['domain_text']);
        self::assertSame('2001:db8::1', $this->encode('AAAA', array('data' => array('address' => '2001:db8::1')))['domain_text']);
        self::assertSame('input.data.address', $this->fieldOf('A', array('data' => array('address' => '2001:db8::1'))));
        self::assertSame('input.data.address', $this->fieldOf('AAAA', array('data' => array('address' => '203.0.113.9'))));
        self::assertSame('input.data.address', $this->fieldOf('A', array('data' => array())));
    }

    public function testCanonicalNames(): void
    {
        self::assertSame('www.zone.test.', $this->encode('CNAME', array('name' => 'w', 'data' => array('target' => 'www')))['domain_text']);
        self::assertSame('example.net.', $this->encode('CNAME', array('name' => 'w', 'data' => array('target' => 'Example.NET.')))['domain_text']);
        // dns_edit.php:190: underscores are removed for validation only.
        self::assertSame('_x.example.net.', $this->encode('CNAME', array('name' => 'w', 'data' => array('target' => '_x.example.net.')))['domain_text']);
        self::assertSame('input.data.target', $this->fieldOf('CNAME', array('name' => 'w', 'data' => array())));
    }

    public function testMailExchangers(): void
    {
        self::assertSame(
            '10 mail.zone.test.',
            $this->encode('MX', array('data' => array('priority' => 10, 'target' => 'mail')))['domain_text']
        );
        self::assertSame('0 mx.example.net.', $this->encode('MX', array('data' => array('target' => 'mx.example.net.')))['domain_text']);
        self::assertSame('input.data.priority', $this->fieldOf('MX', array('data' => array('priority' => 70000, 'target' => 'mail'))));
        self::assertSame('input.data.target', $this->fieldOf('MX', array('data' => array('priority' => 10))));
    }

    public function testNameServersDelegateASubzoneOnly(): void
    {
        $encoded = $this->encode('NS', array('name' => 'sub', 'data' => array('target' => 'ns1.example.net.')));

        self::assertSame("sub.zone.test.\t3600", $encoded['domain_dns']);
        self::assertSame('ns1.example.net.', $encoded['domain_text']);
        self::assertSame('input.name', $this->fieldOf('NS', array('name' => '@', 'data' => array('target' => 'ns1.example.net.'))));
    }

    public function testServiceRecords(): void
    {
        $encoded = $this->encode('SRV', array('name' => '@', 'data' => array(
            'service' => '_SIP', 'protocol' => 'TCP', 'priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip'
        )));

        self::assertSame("_sip._tcp.zone.test.\t3600", $encoded['domain_dns']);
        self::assertSame('10 5 5060 sip.zone.test.', $encoded['domain_text']);
    }

    /**
     * @dataProvider refusedServices
     */
    public function testServiceRecordsThatAreRefused(array $data, string $field): void
    {
        self::assertSame($field, $this->fieldOf('SRV', array('name' => '@', 'data' => $data + array(
            'service' => '_sip', 'protocol' => 'TCP', 'port' => 5060, 'target' => 'sip'
        ))));
    }

    public function refusedServices(): array
    {
        return array(
            'no leading underscore' => array(array('service' => 'sip'), 'input.data.service'),
            'an unknown protocol'   => array(array('protocol' => 'SCTP'), 'input.data.protocol'),
            'a port out of range'   => array(array('port' => 70000), 'input.data.port'),
            'a negative weight'     => array(array('weight' => -1), 'input.data.weight')
        );
    }

    public function testAServiceRecordNeedsAPort(): void
    {
        self::assertSame('input.data.port', $this->fieldOf('SRV', array('name' => '@', 'data' => array(
            'service' => '_sip', 'protocol' => 'TCP', 'target' => 'sip'
        ))));
    }

    public function testTextRecords(): void
    {
        self::assertSame('"v=spf1 -all"', $this->encode('TXT', array('data' => array('text' => 'v=spf1 -all')))['domain_text']);
        self::assertSame('"v=spf1 -all"', $this->encode('SPF', array('data' => array('text' => 'v=spf1 -all')))['domain_text']);
        self::assertSame('input.data.text', $this->fieldOf('TXT', array('data' => array('text' => ''))));
    }

    // ---- the TXT formatter, against the page's own output (M20) ---------

    /**
     * @dataProvider txtVectors
     */
    public function testTxtFormattingMatchesThePage(string $input, string $expected): void
    {
        self::assertSame($expected, DnsRecordData::formatTxt($input));
    }

    public function txtVectors(): array
    {
        return array(
            'unquoted'                       => array('v=spf1 a mx -all', '"v=spf1 a mx -all"'),
            'already quoted'                 => array('"v=spf1 a mx -all"', '"v=spf1 a mx -all"'),
            'several quoted strings joined'  => array('"part one" "part two"', '"part onepart two"'),
            'an escaped quote'               => array('escaped \" quote', '"escaped \" quote"'),
            'parentheses trimmed'            => array('(v=spf1 -all)', '"v=spf1 -all"'),
            'a line break becomes a space'   => array("  line\nbreak  ", '"line break"'),
            'split at 255'                   => array(str_repeat('a', 300), '"' . str_repeat('a', 255) . '" "' . str_repeat('a', 45) . '"'),
            'a quoted string split at 255'   => array('"' . str_repeat('b', 256) . '"', '"' . str_repeat('b', 255) . '" "b"')
        );
    }

    /**
     * @dataProvider refusedTxt
     */
    public function testTxtThePageRefuses(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        DnsRecordData::formatTxt($input);
    }

    public function refusedTxt(): array
    {
        return array(
            'empty'                    => array(''),
            'not printable ASCII'      => array("caf\xc3\xa9"),
            'an unescaped quote'       => array('unquoted "quote'),
            'quoted and unquoted mixed' => array('"quoted" unquoted'),
            'an unterminated quote'    => array('"unterminated')
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter DnsRecordDataTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Support\DnsRecordData" not found`.

- [ ] **Step 3: Implement**

Create `Support/DnsRecordData.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use InvalidArgumentException;

/**
 * A custom DNS record's input, encoded as the two columns the page writes.
 *
 * CORE-DEBT(C3): transcribed from gui/public/client/dns_edit.php:42-425 and
 *   597-767. Retire when DnsRecordService lands in core (spec section 21, C3
 *   row 6).
 */
final class DnsRecordData
{
    /** dns_edit.php:546: the types a customer may manage. */
    const CREATABLE_TYPES = array('A', 'AAAA', 'CNAME', 'MX', 'NS', 'SPF', 'SRV', 'TXT');

    const SRV_PROTOCOLS = array('TCP' => 'tcp', 'UDP' => 'udp', 'TLS' => 'tls');

    const DEFAULT_TTL = 3600;
    const MIN_TTL = 60;
    const MAX_TTL = 2147483647;

    /**
     * @param array    $input           name?, ttl?, data
     * @param callable $toAscii         fn(string): string - encode_idna()
     * @param callable $domainNameError fn(string): ?string - null when valid
     * @return array{domain_dns: string, domain_text: string}
     * @throws ApiException BAD_USER_INPUT
     */
    public static function encode(
        string $type, array $input, string $zoneAscii, callable $toAscii, callable $domainNameError
    ): array {
        if (!in_array($type, self::CREATABLE_TYPES, true)) {
            throw Guard::badInput('input.type', 'Only A, AAAA, CNAME, MX, NS, SPF, SRV and TXT records can be managed here.');
        }

        $ttl = $input['ttl'] ?? self::DEFAULT_TTL;

        if (!is_int($ttl) || $ttl < self::MIN_TTL || $ttl > self::MAX_TTL) {
            throw Guard::badInput('input.ttl', 'A TTL is a whole number of seconds from 60 to 2147483647.');
        }

        $name = self::name((string)($input['name'] ?? ''), $zoneAscii, $toAscii, $domainNameError);
        $data = isset($input['data']) ? (array)$input['data'] : array();

        switch ($type) {
            case 'A':
                $text = self::address($data, FILTER_FLAG_IPV4, 'Not an IPv4 address.');
                break;
            case 'AAAA':
                $text = self::address($data, FILTER_FLAG_IPV6, 'Not an IPv6 address.');
                break;
            case 'CNAME':
                $text = self::host($data, $zoneAscii, $toAscii, $domainNameError, true) . '.';
                break;
            case 'MX':
                $priority = self::number($data, 'priority', false);
                $text = sprintf('%d %s.', $priority, self::host($data, $zoneAscii, $toAscii, $domainNameError, false));
                break;
            case 'NS':
                $text = self::host($data, $zoneAscii, $toAscii, $domainNameError, false) . '.';

                if ($name === $zoneAscii) {
                    throw Guard::badInput('input.name', 'NS records are only allowed for subzone delegation.');
                }
                break;
            case 'SRV':
                $service = mb_strtolower(trim((string)($data['service'] ?? '')));

                if (!preg_match('/^_[a-z0-9]+/i', $service)) {
                    throw Guard::badInput('input.data.service', 'A service name starts with an underscore, e.g. _sip.');
                }

                $protocol = self::SRV_PROTOCOLS[(string)($data['protocol'] ?? '')] ?? null;

                if ($protocol === null) {
                    throw Guard::badInput('input.data.protocol', 'The protocol is TCP, UDP or TLS.');
                }

                $priority = self::number($data, 'priority', false);
                $weight = self::number($data, 'weight', false);
                $port = self::number($data, 'port', true);
                $target = self::host($data, $zoneAscii, $toAscii, $domainNameError, false);
                $name = sprintf('%s._%s.%s', $service, $protocol, $name);
                $text = sprintf('%d %d %d %s.', $priority, $weight, $port, $target);
                break;
            default:
                try {
                    $text = self::formatTxt((string)($data['text'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    throw Guard::badInput('input.data.text', $e->getMessage());
                }
        }

        return array('domain_dns' => $name . ".\t" . $ttl, 'domain_text' => $text);
    }

    /**
     * dns_edit.php:298-351.
     *
     * @throws InvalidArgumentException
     */
    public static function formatTxt(string $data): string
    {
        $data = trim($data, "\t\n\r\0\x0B\x28\x29");

        if ($data === '') {
            throw new InvalidArgumentException('The text cannot be empty.');
        }

        if (!preg_match('/^[[:print:]\s]+$/', $data)) {
            throw new InvalidArgumentException('Only printable ASCII characters and line breaks are allowed.');
        }

        list($quoted, $unquoted) = self::quotedAndUnquoted($data);

        if (!empty($quoted) && !empty($unquoted)) {
            throw new InvalidArgumentException('The text cannot have both quoted and unquoted strings.');
        }

        foreach ($unquoted as $string) {
            if (preg_match('/(?<!\\\\)(?:\\\\{2})*\K"/', $string)) {
                throw new InvalidArgumentException('A quote that is not a string delimiter must be escaped.');
            }
        }

        $data = implode('', empty($quoted) ? $unquoted : $quoted);

        // RFC 4408 section 3.1.3: a character-string is at most 255 bytes.
        if (strlen($data) > 255) {
            $chunks = array();

            for ($i = 0, $length = strlen($data); $i < $length; $i += 255) {
                $chunks[] = '"' . substr($data, $i, 255) . '"';
            }

            return implode(' ', $chunks);
        }

        return '"' . $data . '"';
    }

    /**
     * dns_edit.php:42-87, including the page's own "TODO: to be improved".
     *
     * @return array{0: string[], 1: string[]} quoted strings, unquoted strings
     */
    public static function quotedAndUnquoted(string $string): array
    {
        $string = trim(str_replace(array("\r\n", "\n", "\r"), ' ', $string));
        $quoted = $unquoted = array();
        $unquotedIndex = 0;
        $escaped = $afterQuoted = false;

        for ($i = 0, $length = strlen($string); $i < $length; $i++) {
            if ($afterQuoted && $string[$i] == ' ') {
                continue;
            }

            if (!$escaped && $string[$i] == '"') {
                $quotedString = '';

                while (isset($string[++$i]) && ($escaped || $string[$i] != '"')) {
                    $quotedString .= $string[$i];
                    $escaped = $string[$i] == '\\';
                }

                if (isset($string[$i])) {
                    if ($quotedString != '') {
                        $quoted[] = $quotedString;
                    }

                    $afterQuoted = true;
                } else {
                    $afterQuoted = false;
                    $unquoted[] = '"' . $quotedString;
                }

                $unquotedIndex++;
                $escaped = false;
                continue;
            }

            $afterQuoted = false;
            $escaped = $string[$i] == '\\';

            if (isset($unquoted[$unquotedIndex])) {
                $unquoted[$unquotedIndex] .= $string[$i];
            } else {
                $unquoted[$unquotedIndex] = $string[$i];
            }
        }

        return array($quoted, $unquoted);
    }

    /**
     * The record's owner name, completed in the zone, without its trailing dot.
     *
     * dns_edit.php:598-623.
     */
    private static function name(string $input, string $zone, callable $toAscii, callable $domainNameError): string
    {
        $name = mb_strtolower(trim($input));

        if ($name === '@' || $name === '') {
            $name = $zone . '.';
        } elseif (substr($name, -1) !== '.') {
            $name .= '.' . $zone . '.';
        }

        $name = (string)call_user_func($toAscii, $name);

        // CORE-DEBT(C11): C11 item 5 - anchored, and the zone quoted: the page's
        //   /(?:.*?\.)?zone\.test\.$/ also accepts evilzone.test.
        if (!preg_match('/^(?:.+\.)?' . preg_quote($zone, '/') . '\.$/', $name)) {
            throw Guard::badInput('input.name', 'The name is outside the zone.');
        }

        $name = rtrim($name, '.');
        $check = $name;

        // CORE-DEBT(C11): C11 item 5 - "=== 0"; the page's "== 0" is true for false.
        if (strpos($check, '_') === 0) {
            $check = substr($check, 1);
        }

        if (strpos($check, '*.') === 0) {
            $check = substr($check, 2);
        }

        $reason = call_user_func($domainNameError, $check);

        if ($reason !== null) {
            throw Guard::badInput('input.name', (string)$reason);
        }

        return $name;
    }

    /**
     * A target host, completed in the zone, without its trailing dot.
     *
     * @param bool $ignoreUnderscores dns_edit.php:190 - a CNAME may point at a
     *                                name with underscores (DKIM, RFC 4871)
     */
    private static function host(
        array $data, string $zone, callable $toAscii, callable $domainNameError, bool $ignoreUnderscores
    ): string {
        $host = mb_strtolower(trim((string)($data['target'] ?? '')));

        if ($host === '') {
            throw Guard::badInput('input.data.target', 'A target host is required.');
        }

        if (substr($host, -1) !== '.') {
            $host .= '.' . $zone;
        }

        $host = (string)call_user_func($toAscii, rtrim($host, '.'));
        $reason = call_user_func($domainNameError, $ignoreUnderscores ? str_replace('_', '', $host) : $host);

        if ($reason !== null) {
            throw Guard::badInput('input.data.target', (string)$reason);
        }

        return $host;
    }

    private static function address(array $data, int $flag, string $message): string
    {
        $address = trim((string)($data['address'] ?? ''));

        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP, $flag) === false) {
            throw Guard::badInput('input.data.address', $message);
        }

        return $address;
    }

    /**
     * A 16-bit unsigned field: a priority, a weight or a port.
     */
    private static function number(array $data, string $key, bool $required): int
    {
        if (!isset($data[$key])) {
            if ($required) {
                throw Guard::badInput('input.data.' . $key, sprintf('%s is required.', ucfirst($key)));
            }

            return 0;
        }

        $value = $data[$key];

        if (!is_int($value) || $value < 0 || $value > 65535) {
            throw Guard::badInput('input.data.' . $key, sprintf('%s is a whole number from 0 to 65535.', ucfirst($key)));
        }

        return $value;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `tools/test.sh --filter DnsRecordDataTest`
Expected: PASS, 39 tests (data provider rows counted individually).

If a TXT vector fails, the transcription of `quotedAndUnquoted()` or `formatTxt()` differs from the page. Compare it line by line with `gui/public/client/dns_edit.php:42-87` and `298-351`; do not change the expected strings, which are the page's measured output.

- [ ] **Step 5: Commit**

```bash
git add Support/DnsRecordData.php test/unit/Support/DnsRecordDataTest.php
git commit -m "Encode custom DNS records as the panel stores them

A record is two columns: the owner name with its TTL, and the type's data as
one string. The rules are dns_edit.php's, transcribed into one pure class so
every type can be tested without a database. The TXT vectors are that page's
own output, measured by running its function in the box, so the quoting and
255-byte splitting cannot drift.

The page's zone check is unanchored - zone.test's records could be named
evilzone.test - and its underscore check strips the first character of every
name. Neither is copied."
```

---
## Task 15: Custom DNS records — `sonnet`

`dnsRecordCreate`, `dnsRecordUpdate` and `dnsRecordDelete`: the service, the schema and the resolver. After this task every row of the authorisation matrix runs.

**Files:**
- Create: `Service/DnsService.php`, `Resolver/DnsMutations.php`
- Create: `test/integration/DnsServiceTest.php`
- Modify: `schema/schema.graphql`, `test/schema/schema.printed.graphql`, `Api/Container.php`

**Interfaces:**
- Consumes: `DnsRecordData` (Task 14); `Toolkit::panelConfig()`, `Guard`, `ObjectRef`, `CustomerAccount::getDomainRow()` (Task 3); `Support\CustomerFeatures`; `ServiceTestCase` (Task 6); `DnsResolver::reference()`.
- Produces:
  ```php
  new DnsService(Toolkit $kit)
  DnsService::create(Identity $caller, array $input): ObjectRef          // tag DNS_RECORD
  DnsService::update(Identity $caller, string $id, array $input): ObjectRef
  DnsService::delete(Identity $caller, string $id): ObjectRef
  new DnsMutations(BatchLoader $loader, DnsService $dns, DnsResolver $dnsResolver)
  ```
  SDL: `enum SrvProtocol`, `DnsRecordDataInput`, `DnsRecordCreateInput`, `DnsRecordUpdateInput`.

- [ ] **Step 1: Write the failing service test**

Create `test/integration/DnsServiceTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DnsService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DnsServiceTest extends ServiceTestCase
{
    private function service(): DnsService
    {
        return new DnsService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function recordId(): string
    {
        return GlobalId::encode(NodeType::DNS_RECORD, $this->fixture->dnsRecordId());
    }

    private function record(int $id): array
    {
        return $this->db->row('SELECT * FROM domain_dns WHERE domain_dns_id = ?', array($id));
    }

    private function aRecord(array $extra = array()): array
    {
        return array_merge(array(
            'hostId' => $this->domainId(), 'name' => 'www', 'type' => 'A', 'data' => array('address' => '203.0.113.9')
        ), $extra);
    }

    public function testARecordIsCreatedInTheDomainsZone(): void
    {
        $ref = $this->service()->create($this->caller('customer'), $this->aRecord());

        $row = $this->record($ref->getKey());
        self::assertSame(array(
            'domain_id'         => (string)$this->fixture->domainId(),
            'alias_id'          => '0',
            'domain_dns'        => "www.sgwtcustomer.test.\t3600",
            'domain_class'      => 'IN',
            'domain_type'       => 'A',
            'domain_text'       => '203.0.113.9',
            'owned_by'          => 'custom_dns_feature',
            'domain_dns_status' => 'toadd'
        ), array_map('strval', array_diff_key($row, array('domain_dns_id' => 1))));
        self::assertSame(array('onBeforeAddCustomDNSrecord', 'onAfterAddCustomDNSrecord'), $this->core->eventNames());
        self::assertSame(array(
            'domainId' => $this->fixture->domainId(),
            'aliasId'  => 0,
            'name'     => "www.sgwtcustomer.test.\t3600",
            'class'    => 'IN',
            'type'     => 'A',
            'data'     => '203.0.113.9'
        ), $this->core->events[0][1]);
        self::assertSame($ref->getKey(), $this->core->events[1][1]['id']);
        self::assertSame(1, $this->core->requests);
        self::assertSame('DNS resource record has been scheduled for addition by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testARecordOnAnAliasIsInTheAliasesZone(): void
    {
        $ref = $this->service()->create($this->caller('customer'), $this->aRecord(array(
            'hostId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
        )));

        $row = $this->record($ref->getKey());
        self::assertSame((string)$this->fixture->aliasId(), (string)$row['alias_id']);
        self::assertSame('www.' . $this->fixture->aliasName() . ".\t3600", $row['domain_dns']);
    }

    public function testTheSameRecordTwiceIsAConflict(): void
    {
        $this->service()->create($this->caller('customer'), $this->aRecord());

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord());
        });
    }

    public function testTheFeatureFollowsTheAccountAndTheServer(): void
    {
        $this->db->execute("UPDATE domain SET domain_dns = 'no' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord());
        });
        self::assertSame('customDns', $e->getExtensions()['feature']);

        $this->db->execute("UPDATE domain SET domain_dns = 'yes' WHERE domain_id = ?", array($this->fixture->domainId()));
        $this->reconfigure(array('NAMED_PACKAGE' => 'Servers::noserver'));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord());
        });
    }

    public function testAnOrderedAliasHasNoZoneYet(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord(array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
            )));
        });
    }

    public function testInvalidDataIsRefusedOnItsField(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord(array('data' => array('address' => 'nope'))));
        });

        self::assertSame('input.data.address', $e->getExtensions()['field']);
    }

    public function testARecordIsReplacedButKeepsItsType(): void
    {
        $this->service()->update($this->caller('customer'), $this->recordId(), array(
            'name' => 'mail', 'ttl' => 600, 'data' => array('address' => '203.0.113.10')
        ));

        $row = $this->record($this->fixture->dnsRecordId());
        self::assertSame("mail.sgwtcustomer.test.\t600", $row['domain_dns']);
        self::assertSame('203.0.113.10', $row['domain_text']);
        self::assertSame('A', $row['domain_type']);
        self::assertSame('tochange', $row['domain_dns_status']);
        self::assertSame(array('onBeforeEditCustomDNSrecord', 'onAfterEditCustomDNSrecord'), $this->core->eventNames());
        self::assertSame($this->fixture->dnsRecordId(), $this->core->events[0][1]['id']);
        self::assertSame('DNS resource record has been scheduled for update by sgwtcustomer', $this->core->logs[0][0]);

        // A target does not make it a CNAME: the A record still needs an address.
        $this->db->execute("UPDATE domain_dns SET domain_dns_status = 'ok' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->recordId(), array(
                'name' => 'mail', 'ttl' => 600, 'data' => array('target' => 'example.net.')
            ));
        });
    }

    public function testAPluginsRecordIsReadOnlyHere(): void
    {
        $this->db->execute("UPDATE domain_dns SET owned_by = 'SGW_LetsEncrypt' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));

        $e = $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->update($this->caller('customer'), $this->recordId(), array(
                'name' => 'mail', 'ttl' => 600, 'data' => array('address' => '203.0.113.10')
            ));
        });
        self::assertSame('SGW_LetsEncrypt', $e->getExtensions()['ownedBy']);

        $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->delete($this->caller('customer'), $this->recordId());
        });
    }

    public function testAPendingRecordCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE domain_dns SET domain_dns_status = 'toadd' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->recordId(), array(
                'name' => 'mail', 'ttl' => 600, 'data' => array('address' => '203.0.113.10')
            ));
        });
    }

    public function testDeletingSchedulesTheRecordAndNamesItInTheEvent(): void
    {
        $this->service()->delete($this->caller('customer'), $this->recordId());

        self::assertSame('todelete', $this->record($this->fixture->dnsRecordId())['domain_dns_status']);
        self::assertSame(array('onBeforeDeleteCustomDNSrecord', 'onAfterDeleteCustomDNSrecord'), $this->core->eventNames());
        self::assertSame(array('id' => $this->fixture->dnsRecordId()), $this->core->events[0][1], 'C11 item 5');
        self::assertSame('sgwtcustomer scheduled deletion of a custom DNS record', $this->core->logs[0][0]);
    }

    public function testAFailedRecordMayBeDeleted(): void
    {
        $this->db->execute("UPDATE domain_dns SET domain_dns_status = 'Invalid zone' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));

        $this->service()->delete($this->caller('customer'), $this->recordId());

        self::assertSame('todelete', $this->record($this->fixture->dnsRecordId())['domain_dns_status']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `tools/test.sh --filter DnsServiceTest`
Expected: FAIL — `Class "iMSCP\Plugin\SGW_GraphQL\Service\DnsService" not found`.

- [ ] **Step 3: Write the service**

Create `Service/DnsService.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\CustomerFeatures;
use iMSCP\Plugin\SGW_GraphQL\Support\DnsRecordData;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

/**
 * A customer's own DNS records in the zone of their domain or an alias.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/dns_edit.php and
 *   dns_delete.php. Retire when DnsRecordService lands in core (spec section
 *   21, C3 row 6).
 */
final class DnsService
{
    const HOSTS = array(NodeType::DOMAIN, NodeType::DOMAIN_ALIAS);

    /** The owned_by of a record the customer made; anything else is a plugin's. */
    const CUSTOMER_OWNED = 'custom_dns_feature';

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

        // 2, 3.
        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::DNS_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());

        // 4.
        Guard::requireFeature($this->customDns($account), 'customDns');

        // 5. dns_edit.php:907-915 offers no ordered alias's zone.
        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $type = (string)($input['type'] ?? '');
        $encoded = DnsRecordData::encode(
            $type, $input, (string)$hostRow['name'], array($core, 'toAscii'), array($core, 'domainNameError')
        );

        $params = array(
            'domainId' => $account->getDomainId(),
            'aliasId'  => $host->getTag() === NodeType::DOMAIN_ALIAS ? (int)$host->getKey() : 0,
            'name'     => $encoded['domain_dns'],
            'class'    => 'IN',
            'type'     => $type,
            'data'     => $encoded['domain_text']
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/dns_edit.php:788-825.
        //    The unique key on (domain_id, alias_id, name, class, type, data)
        //    makes a repeat CONFLICT.
        $id = $kit->writer()->run(function () use ($kit, $core, $params) {
            $core->dispatch(Events::onBeforeAddCustomDNSrecord, $params);

            $kit->db()->execute(
                "
                    INSERT INTO domain_dns (
                        domain_id, alias_id, domain_dns, domain_class, domain_type, domain_text,
                        owned_by, domain_dns_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'toadd')
                ",
                array(
                    $params['domainId'], $params['aliasId'], $params['name'], $params['class'],
                    $params['type'], $params['data'], self::CUSTOMER_OWNED
                )
            );

            $id = $kit->db()->lastInsertId();
            $core->dispatch(Events::onAfterAddCustomDNSrecord, array('id' => $id) + $params);

            return $id;
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(
            sprintf('DNS resource record has been scheduled for addition by %s', $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DNS_RECORD, $id);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DNS_RECORD), Scope::DNS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature($this->customDns($account), 'customDns');

        $row = $this->recordRow($target);
        // dns_edit.php does not ask; spec section 8.3 does.
        Guard::requireState((string)$row['domain_dns_status'], array(Provisioning::STATE_OK));

        // The type is the row's: the page disables the field on edit (line 956).
        $encoded = DnsRecordData::encode(
            (string)$row['domain_type'], $input, (string)$row['zone_name'],
            array($core, 'toAscii'), array($core, 'domainNameError')
        );

        $recordId = (int)$row['domain_dns_id'];
        $params = array(
            'id'       => $recordId,
            'domainId' => (int)$row['domain_id'],
            'aliasId'  => (int)$row['alias_id'],
            'name'     => $encoded['domain_dns'],
            'class'    => (string)$row['domain_class'],
            'type'     => (string)$row['domain_type'],
            'data'     => $encoded['domain_text']
        );

        // CORE-DEBT(C3): transcribed from gui/public/client/dns_edit.php:826-869.
        $kit->writer()->run(function () use ($kit, $core, $params) {
            $core->dispatch(Events::onBeforeEditCustomDNSrecord, $params);

            $kit->db()->execute(
                "
                    UPDATE domain_dns
                    SET domain_dns = ?, domain_class = ?, domain_type = ?, domain_text = ?, domain_dns_status = 'tochange'
                    WHERE domain_dns_id = ?
                ",
                array($params['name'], $params['class'], $params['type'], $params['data'], $params['id'])
            );

            $core->dispatch(Events::onAfterEditCustomDNSrecord, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('DNS resource record has been scheduled for update by %s', $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DNS_RECORD, $recordId);
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DNS_RECORD), Scope::DNS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature($this->customDns($account), 'customDns');

        $row = $this->recordRow($target);
        Guard::requireState((string)$row['domain_dns_status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $recordId = (int)$row['domain_dns_id'];

        // CORE-DEBT(C3): transcribed from gui/public/client/dns_delete.php:38-62.
        // CORE-DEBT(C11): C11 item 5 - the before-event carries the record's id.
        $kit->writer()->run(function () use ($kit, $core, $recordId) {
            $core->dispatch(Events::onBeforeDeleteCustomDNSrecord, array('id' => $recordId));
            $kit->db()->execute(
                "UPDATE domain_dns SET domain_dns_status = 'todelete' WHERE domain_dns_id = ?",
                array($recordId)
            );
            $core->dispatch(Events::onAfterDeleteCustomDNSrecord, array('id' => $recordId));
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s scheduled deletion of a custom DNS record', $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DNS_RECORD, $recordId);
    }

    /** customerHasFeature('custom_dns_records'), through the plan 2 transcription. */
    private function customDns(CustomerAccount $account): bool
    {
        return CustomerFeatures::fromDomainRow(
            $account->getDomainRow(),
            $this->kit->panelConfig(CustomerFeatures::CONFIG_KEYS),
            false
        )->has('customDns');
    }

    /**
     * The record, its zone's ASCII name, and FORBIDDEN when a plugin owns it.
     *
     * @return array<string, mixed>
     */
    private function recordRow(Target $target): array
    {
        $row = $this->kit->db()->row(
            '
                SELECT dd.*, IFNULL(al.alias_name, d.domain_name) AS zone_name
                FROM domain_dns AS dd
                JOIN domain AS d ON d.domain_id = dd.domain_id
                LEFT JOIN domain_aliasses AS al ON al.alias_id = dd.alias_id
                WHERE dd.domain_dns_id = ?
            ',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        // dns_edit.php:588 and dns_delete.php:49. FORBIDDEN, not NOT_FOUND: the
        // caller can already read this record (spec section 7.8, ownedBy).
        if ($row['owned_by'] !== self::CUSTOMER_OWNED) {
            throw Guard::forbidden(
                'This record belongs to a plugin and is read-only here.',
                array('ownedBy' => (string)$row['owned_by'])
            );
        }

        return $row;
    }
}
```

- [ ] **Step 4: Run the service test**

Run: `tools/test.sh --filter DnsServiceTest`
Expected: PASS, 11 tests.

- [ ] **Step 5: Add the schema, resolver and wiring**

In `schema/schema.graphql`'s `type Mutation`, after `sqlUserDelete`, add:

```graphql

  "A record of one of the types a customer may manage: A, AAAA, CNAME, MX, NS, SPF, SRV or TXT."
  dnsRecordCreate(input: DnsRecordCreateInput!): DnsRecord!
  """
  Replaces the record's name, TTL and data; its type cannot change. A record
  a plugin owns (ownedBy other than custom_dns_feature) is FORBIDDEN.
  """
  dnsRecordUpdate(id: ID!, input: DnsRecordUpdateInput!): DnsRecord!
  dnsRecordDelete(id: ID!): DnsRecord!
```

Append at the end of the file:

```graphql
"The transport of an SRV record."
enum SrvProtocol {
  TCP
  UDP
  TLS
}

"Each record type reads the fields it needs and ignores the rest."
input DnsRecordDataInput {
  "A: an IPv4 address. AAAA: an IPv6 address."
  address: String
  """
  CNAME, MX, NS, SRV: a host name. A name without a trailing dot is completed
  in the zone; end it with a dot for a fully qualified name.
  """
  target: String
  "MX, SRV: 0 to 65535. Default 0."
  priority: Int
  "SRV: 0 to 65535. Default 0."
  weight: Int
  "SRV: 0 to 65535. Required."
  port: Int
  "SRV: the service, starting with an underscore, e.g. _sip."
  service: String
  "SRV."
  protocol: SrvProtocol
  "TXT, SPF: the text. Quoted, and split into 255-byte strings, as the panel does."
  text: String
}

input DnsRecordCreateInput {
  "The Domain or DomainAlias whose zone the record is in."
  hostId: ID!
  """
  Relative to the zone, or @ for the zone itself. A name ending in a dot is
  fully qualified and must be in the zone.
  """
  name: String = "@"
  "Seconds, 60 to 2147483647."
  ttl: Int = 3600
  type: DnsRecordType!
  data: DnsRecordDataInput!
}

"A whole replacement: every field is required, and the type stays as it is."
input DnsRecordUpdateInput {
  name: String!
  ttl: Int!
  data: DnsRecordDataInput!
}
```

Create `Resolver/DnsMutations.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\DnsService;

/** The three DNS mutations. See VirtualHostMutations for the pattern. */
final class DnsMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var DnsService */
    private $dns;

    /** @var DnsResolver */
    private $dnsResolver;

    public function __construct(BatchLoader $loader, DnsService $dns, DnsResolver $dnsResolver)
    {
        $this->loader = $loader;
        $this->dns = $dns;
        $this->dnsResolver = $dnsResolver;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.dnsRecordCreate' => array($this, 'resolveDnsRecordCreate'),
            'Mutation.dnsRecordUpdate' => array($this, 'resolveDnsRecordUpdate'),
            'Mutation.dnsRecordDelete' => array($this, 'resolveDnsRecordDelete')
        );
    }

    public function resolveDnsRecordCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->dns->create(TypeResolver::identity($context), (array)$args['input']);

        return $this->dnsResolver->reference((int)$ref->getKey());
    }

    public function resolveDnsRecordUpdate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->dns->update(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']);

        return $this->dnsResolver->reference((int)$ref->getKey());
    }

    public function resolveDnsRecordDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->dns->delete(TypeResolver::identity($context), (string)$args['id']);

        return $this->dnsResolver->reference((int)$ref->getKey());
    }
}
```

In `Api/Container.php` add `use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsMutations;` and `use iMSCP\Plugin\SGW_GraphQL\Service\DnsService;`, and append to `$this->maps`:

```php
            'DnsMutations' => (new DnsMutations($loader, new DnsService($kit), $dns))->map()
```

- [ ] **Step 6: Run the whole matrix**

Run: `tools/test.sh --testsuite authz`
Expected: PASS with **no skips**: 144 matrix rows, 72 scope rows, 3 coverage tests. A skip now means a mutation the catalogue lists is missing from the schema.

- [ ] **Step 7: Regenerate the snapshot, run everything, commit**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 tools/print-schema.php > test/schema/schema.printed.graphql'`

Run: `tools/test.sh`
Expected: all PASS.

```bash
git add Service/DnsService.php Resolver/DnsMutations.php Api/Container.php schema/schema.graphql test/schema/schema.printed.graphql test/integration/DnsServiceTest.php
git commit -m "Create, replace and delete custom DNS records

The last three mutations of the phase, and with them every row of the
authorisation matrix runs: 24 mutations as six accounts, and the scope half
of spec 8.1 for each.

A DNS update is a whole replacement rather than a partial one, because a
record's data is a single encoded string and a partial update would mean
decoding it first. The type cannot change, as in the panel. A record a
plugin owns is FORBIDDEN rather than NOT_FOUND: the caller can already read
it, and ownedBy says whose it is."
```

---

## Checkpoint D: `code-review medium` over Wave 5

Run by the orchestrating session, as the [Execution mandate](#waves-and-checkpoints) sets out.

**Focus:**

- `MariaDbSqlServer`: identifier quoting, the grant pattern's escaping, statements bound versus interpolated.
- `SqlService`: compensation when the row fails after the DDL succeeded; a user's last grant versus one of several; the limit asked before `CREATE USER` (C11 item 3); `existingUserId` confined to the database's customer.
- `DnsRecordData`: the zone check anchored (C11 item 5), TXT quoting and splitting against M20's vectors, 16-bit fields.
- `DnsService`: a plugin-owned record refused on every path, and the before-delete event carrying the id.
- The matrix: 144 rows and 72 scope rows run, none skipped.

- [ ] **Step 1: The suite, twice**

Run: `tools/test.sh`, then again.
Expected: both green.

- [ ] **Step 2: Review**

Invoke the `code-review` skill with arguments `medium phase3-wave-5..HEAD`, passing the **Focus** list above.

- [ ] **Step 3: Resolve the findings**

Fix each finding that holds, in commits whose subjects begin `Fix checkpoint D:`, then run `tools/test.sh` until green.

- [ ] **Step 4: Open the next wave**

Run: `git tag -a phase3-wave-6 -m "Checkpoint D: <declined findings and why, or: none declined>"`

---

## Wave 6: Close

Tasks 16–18. Ends at Checkpoint E, the last.

---

## Task 16: Remove Anorm — `sonnet`

Decision D17, and plan 2's own rule: *"If phase 3's mutations do not use the models either, the right move is to drop the dependency then, not to keep finding uses for it."* No write in Tasks 6–15 uses a model, for the reasons measurement M23 records. The read path's one Anorm load becomes a `keyed()` load of the same shape and the same cost.

This task changes no behaviour. The evidence is that the full suite — `QueryCountTest`'s measured 31 queries included — passes unchanged apart from one bucket's name.

**Files:**
- Delete: `Model/*.php` (13 files), `test/unit/Model/ModelMappingTest.php`, `test/integration/BatchLoaderTest.php`
- Modify: `Repository/BatchLoader.php` (rewritten), `test/unit/Repository/BatchLoaderTest.php`
- Modify: `Resolver/VirtualHostResolver.php`, `test/integration/QueryCountTest.php`, `test/unit/VendorTest.php`
- Modify: `Repository/Db.php`, `Repository/VirtualHosts.php` (a comment each)
- Modify: `composer.json`, `composer.lock`
- Modify: `docs/SPECIFICATION.md` §3.3 and §20, `docs/DEVELOPMENT.md`

**Interfaces:**
- Consumes: nothing new.
- Produces: `BatchLoader` keeps exactly `__construct(Db $db)`, `keyed()`, `flushes()`, `reset()`. `related()`, `parent()` and `byColumn()` are gone, with no replacement, because nothing calls them.

- [ ] **Step 1: Confirm nothing but the read path's one load and the tests uses Anorm**

Run: `grep -rln "Anorm\\\\\|Model\\\\\|byColumn\|->related(\|->parent(" --include=*.php Api Auth Http Repository Resolver Schema Security Service Support SGW_GraphQL.php`
Expected: exactly `Repository/BatchLoader.php` and `Resolver/VirtualHostResolver.php`. **If anything else is listed, stop and report it**: a later change has started using a model, and this task's premise no longer holds.

- [ ] **Step 2: Move `Domain.createdAt` and `.expiresAt` off `byColumn()`**

In `Resolver/VirtualHostResolver.php`:

1. Remove `use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;`.
2. In `resolveCreatedAt()`, replace `TypeResolver::dateTime($domain->domain_created)` with `TypeResolver::dateTime($domain['domain_created'])`.
3. In `resolveExpiresAt()`, replace `TypeResolver::dateTime($domain->domain_expires)` with `TypeResolver::dateTime($domain['domain_expires'])`.
4. Replace the whole of `domainRow()`, docblock included, with:

```php
    /**
     * The two columns of the `domain` row behind a vhost that the normalised
     * vhost row does not carry: domain_created and domain_expires.
     *
     * One IN-clause query per level, whatever the number of vhosts asking,
     * and one for createdAt and expiresAt together.
     *
     * @return SyncPromise Resolves to array{domain_created: string, domain_expires: string}|null
     */
    private function domainRow(int $domainId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'domain:dates',
            $domainId,
            static function (array $keys) use ($db) {
                $byId = array();

                foreach ($db->rows(
                    'SELECT domain_id, domain_created, domain_expires FROM domain WHERE domain_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                ) as $row) {
                    $byId[(int)$row['domain_id']] = $row;
                }

                return $byId;
            }
        );
    }
```

In `test/integration/QueryCountTest.php`, replace the `COST` entry

```php
        'col:DomainModel.domain_id'      => array(1, 'Domain.createdAt and .expiresAt together - the one Anorm load in the read path'),
```

with

```php
        'domain:dates'                   => array(1, 'Domain.createdAt and .expiresAt together'),
```

and in the docblock above `const DOCUMENT`, replace the phrase that begins `Domain.createdAt - the one load in this phase` and ends `Anorm's byColumn().` (it runs across a line break) with `Domain.createdAt.`

Run: `tools/test.sh --filter 'VirtualHostResolverTest|QueryCountTest'`
Expected: PASS, and `QueryCountTest` still measures 31.

- [ ] **Step 3: Rewrite `BatchLoader` without Anorm**

Replace `Repository/BatchLoader.php` with:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

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

use GraphQL\Deferred;

/**
 * One query per edge per level, whatever the number of parents.
 *
 * Spec section 10.1 asks for the DataLoader pattern and names the edges:
 * customer->domain, domain->subdomains, domain->aliases, alias->subdomains,
 * domain->mail accounts, customer->ftp users, database->sql users, and the
 * reverse of each. GraphQL\Deferred supplies the timing - it queues a callback
 * that runs only when the executor can make no further progress, which is
 * exactly the end of a level - and each caller's loader supplies one
 * IN-clause query for every key asked for in that level.
 *
 * Plan 2 built this over Anorm's IN-clause loaders as well; plan 3 removed
 * Anorm (decision D17), and keyed() was already carrying every edge but one.
 */
final class BatchLoader
{
    /** @var Db */
    private $db;

    /**
     * Keys waiting on a keyed() load.
     *
     * bucket => array(stringified key => original key).
     *
     * @var array<string, array<string, mixed>>
     */
    private $pendingKeys = array();

    /** @var array<string, callable> bucket => loader */
    private $loaders = array();

    /**
     * Answers already loaded, kept for the life of the request - or until a
     * mutation resets them (decision D18).
     *
     * bucket => array(stringified key => value). A parent resolved at three
     * depths of one document is asked for once.
     *
     * @var array<string, array<string, mixed>>
     */
    private $results = array();

    /** @var int */
    private $flushes = 0;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @param string   $bucket One namespace per distinct query shape. Two
     *                         edges that share a bucket would hand each other's
     *                         answers back, so a bucket name carries the table
     *                         and the column, never just the column.
     * @param mixed    $key
     * @param callable $loader fn(array $keys): array - a key => value map.
     *                         A key the loader omits resolves to null.
     */
    public function keyed(string $bucket, $key, callable $loader): Deferred
    {
        if ($key === null) {
            // A null key matches nothing - SQL's IN (NULL) is never true - so
            // it is answered here instead of being enqueued. Enqueuing it
            // costs a round trip to learn nothing, and worse: the pending
            // entry's *value* is the key itself, so a null one is invisible to
            // an isset() guard, never flushes, and is dragged along by the
            // next flush of that bucket as a spurious NULL in the IN list.
            // domain.domain_ip_id is the caller that makes this reachable.
            return new Deferred(static function () {
                return null;
            });
        }

        $index = (string)$key;

        if (!isset($this->results[$bucket])
            || !array_key_exists($index, $this->results[$bucket])
        ) {
            $this->pendingKeys[$bucket][$index] = $key;
            $this->loaders[$bucket] = $loader;
        }

        return new Deferred(function () use ($bucket, $index) {
            // array_key_exists, not isset, for the same reason the memo check
            // above uses it: the guard asks "is this key still pending", and
            // isset() would answer no for a pending entry whose value is null.
            if (isset($this->pendingKeys[$bucket])
                && array_key_exists($index, $this->pendingKeys[$bucket])
            ) {
                $this->flushKeys($bucket);
            }

            return $this->results[$bucket][$index] ?? null;
        });
    }

    /**
     * How many batch queries have run. QueryCountTest measures the real count
     * with Db::countQueries(); this is the cheaper in-process view of the same
     * thing, for a unit test that has no database.
     */
    public function flushes(): int
    {
        return $this->flushes;
    }

    public function reset(): void
    {
        $this->pendingKeys = array();
        $this->loaders = array();
        $this->results = array();
        $this->flushes = 0;
    }

    private function flushKeys(string $bucket): void
    {
        $keys = array_values($this->pendingKeys[$bucket]);
        $loader = $this->loaders[$bucket];

        // Called before the pending list is cleared. GraphQL\Deferred catches
        // its executor's throw per promise, so a failed batch rejects only the
        // promise that happened to trigger the flush; clearing first would
        // leave every sibling finding nothing pending and falling through to
        // "no answer for this key" - null - so one dropped connection would
        // read as "this customer has nothing", inside a 200 response. Leaving
        // the keys pending makes the next sibling retry and fail the same way.
        $loaded = call_user_func($loader, $keys);

        unset($this->pendingKeys[$bucket], $this->loaders[$bucket]);

        if (!isset($this->results[$bucket])) {
            $this->results[$bucket] = array();
        }

        foreach ($loaded as $key => $value) {
            $this->results[$bucket][(string)$key] = $value;
        }

        // Every key that was asked for gets an entry, so a second ask for a
        // key with no rows does not run the query again.
        foreach ($keys as $key) {
            if (!array_key_exists((string)$key, $this->results[$bucket])) {
                $this->results[$bucket][(string)$key] = null;
            }
        }

        $this->flushes++;
    }
}
```

- [ ] **Step 4: Remove the Anorm tests**

In `test/unit/Repository/BatchLoaderTest.php`, delete these five methods entirely, with their doc comments: `testAFailedByColumnFlushDoesNotReadAsNoRowsForSiblings`, `testASiblingOfAFailedEdgeFlushDoesNotResolveToAnEmptyList`, `testAColumnNameThatIsNotAnIdentifierIsRefused`, `testAColumnNameIsCheckedBeforeAnythingIsEnqueued`, `testAnAnormStrategyClassIsNeverLoaded`. Then delete the imports `use iMSCP\Plugin\SGW_GraphQL\Model\AdminModel;`, `use InvalidArgumentException;` and `use PDO;`, which nothing left in the file uses. In `testASiblingOfAFailedKeyFlushDoesNotResolveToNull`'s comment, replace `which keyed() spells null and byColumn() turns into an empty list` with `which keyed() spells null`.

In `test/unit/VendorTest.php`, delete `testAnormIsAvailable()`.

Delete the rest:

```bash
git rm Model/AdminModel.php Model/AliasSubdomainModel.php Model/DnsRecordModel.php Model/DomainAliasModel.php \
    Model/DomainModel.php Model/FtpUserModel.php Model/HostingPlanModel.php Model/MailAccountModel.php \
    Model/ResellerPropsModel.php Model/ServerIpModel.php Model/SqlDatabaseModel.php Model/SqlUserModel.php \
    Model/SubdomainModel.php test/unit/Model/ModelMappingTest.php test/integration/BatchLoaderTest.php
```

`test/integration/BatchLoaderTest.php` tested only `related()`, `parent()` and `byColumn()` against the database. `keyed()`'s batching and memoisation against the database are asserted by `QueryCountTest` and by every `*ResolverTest` that counts queries.

In `Repository/Db.php`'s `questions()` docblock, replace `step with a library the way a hand-written wrapper would: Anorm's queries, the plugin's own and exec_query()'s are all on this connection` with `step with the code the way a hand-written wrapper would: the plugin's own queries and exec_query()'s are all on this connection`.

In `Repository/VirtualHosts.php`'s class docblock, replace `This is where subdomains come from rather than from an Anorm relationship:` with `This is where subdomains come from rather than from a single-table query:`.

- [ ] **Step 5: Remove the dependency**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 /var/www/imscp/gui/bin/composer.phar remove saygoweb/anorm'`
Expected: `Removing saygoweb/anorm`, and `composer.json` and `composer.lock` changed.

Run: `grep -rn "Anorm" --include=*.php . | grep -v "^./vendor/" | grep -v "^./docs/"`
Expected: no output other than comments that name Anorm as history (`BatchLoader.php`'s class docblock).

- [ ] **Step 6: Amend the documentation**

In `docs/SPECIFICATION.md` §3.3, in the decision table, delete the row beginning `` | `saygoweb/anorm ^3.1` ``, and replace the text from `**Anorm, on the read side only.**` up to and including the paragraph ending `is the start of phase 2.` with:

```markdown
**Anorm was chosen for the read side, and removed in phase 3.** It was taken
on for its IN-clause batch loaders and its field-selection-aware orchestrator,
and on the expectation that phase 3's writes would use its models. Neither held.
Phase 2 measured that the orchestrator's default strategy chooses individual
loading — the N+1 — below ten source models, and that its join loader is a stub,
so the plugin called the two IN-clause loaders directly and kept the rest out of
reach; by the end of phase 2 one read edge used them. Phase 3 measured that
`DataMapper::write()` updates every mapped column (a lost update against the
backend's own writes to the same row), interpolates the key, and inserts `NULL`
for every unset property, which the panel's tables refuse. No write used it, and
the dependency went. The batch loading §10.1 requires is `Repository\BatchLoader`,
over graphql-php's `Deferred`, one IN-clause query per edge per level.
```

In §20, replace open question 2's text with:

```markdown
2. ~~Should Anorm be used?~~ **Answered, then reversed.** Used for the read
   side in phase 2, removed in phase 3; §3.3 says why.
```

In `docs/DEVELOPMENT.md`, replace `Two runtime dependencies — `webonyx/graphql-php ^15` and `saygoweb/anorm ^3.1`` with `One runtime dependency — `webonyx/graphql-php ^15``, and in the same paragraph change any following "them"/"Both" that refers to the pair to the singular.

- [ ] **Step 7: Run everything**

Run: `tools/test.sh`
Expected: all PASS. `QueryCountTest` measures 31 queries, as before. `CoreCallsTest` passes. The lint inventory no longer lists Anorm files (they were never linted: vendor is excluded), and the licence check passes over fewer files.

- [ ] **Step 8: Commit**

```bash
git add -A Model Repository/BatchLoader.php Repository/Db.php Repository/VirtualHosts.php Resolver/VirtualHostResolver.php test/unit/Repository/BatchLoaderTest.php test/unit/VendorTest.php test/unit/Model test/integration/BatchLoaderTest.php test/integration/QueryCountTest.php composer.json composer.lock docs/SPECIFICATION.md docs/DEVELOPMENT.md
git commit -m "Remove Anorm

Plan 2 kept it for phase 3's writes and said to drop it if they did not use
it. They do not: DataMapper::write() updates every mapped column, so an
update to a domain's forwarding would also write back a stale disk usage
over the traffic cron's; it interpolates the key; and it inserts NULL for
every property left unset, which the panel's tables refuse.

The read path's one model load, Domain.createdAt and .expiresAt, becomes a
keyed() load of the same shape. The query count is unchanged at 31, which is
the evidence that nothing else moved. The thirteen models, the two unused
edge loaders and their tests go with it."
```

---
## Task 17: Provisioning, end to end, on the box — `opus`

Spec §17's integration layer: *"add a subdomain, a mail account, an FTP user, a database and a SQL user; assert each reaches `ok`; … delete everything; assert the database is back to where it started and no system user, maildir or vhost file is left behind. This is the test that would have caught the event-dispatch problem of §3.1."*

Everything before this task rolled back. This one commits, runs i-MSCP's real backend over what the API wrote, and checks the filesystem. It is the only evidence in the plan that the backend **accepts** what the services write — a transcription can be faithful to the page and still produce a row the Perl modules refuse.

It runs the documents in-process against the box's database rather than over HTTP: the transport is `test/api/smoke.sh`'s subject, and the docker box serves plain HTTP (see `docs/DEVELOPMENT.md`). And it runs the request manager itself: measurement M22 found `imscp_daemon` inactive in the container, so `send_request()` would reach nothing.

**Files:**
- Create: `test/api/provision.php`
- Modify: `docs/DEVELOPMENT.md` (how to run it)

**Interfaces:**
- Consumes: `Api\Container::forTesting()` (Task 3); `Service\PanelCore`, `VfsDirectoryProbe` (Task 2); `Service\MariaDbSqlServer` (Task 12); every mutation (Tasks 8–15); `Query.pending` and `Query.node` (plan 2).
- Produces: nothing other tasks use. A script that exits 0 when every step passed.

- [ ] **Step 1: Measure the box before writing assertions about it**

Run: `../imscp/docker/imscp exec sh -c 'systemctl is-active apache2 proftpd dovecot postfix mariadb; ls /etc/apache2/sites-available; ls /var/www/virtual; grep -E "^(MTA_VIRTUAL_MAIL_DIR)" /etc/imscp/postfix/postfix.data'`

Record what it prints. Measurement M22 found `apache2` inactive. If it still is, start it — `../imscp/docker/imscp exec systemctl start apache2` — because the backend reloads Apache after writing a vhost and marks the object ERROR when it cannot. If it will not start, stop and report: the box cannot provision a vhost, and nothing this task asserts about subdomains would mean anything.

Note where a vhost's file lands (M22: `/etc/apache2/sites-available/<name>.conf`) and the mail root (`MTA_VIRTUAL_MAIL_DIR`, `/var/mail/virtual`). The script below reads the mail root from `postfix.data` and assumes the Apache path; if Step 1 shows a different layout, change `VHOST_DIR` and say so in the commit.

- [ ] **Step 2: Write the script**

Create `test/api/provision.php`:

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

/**
 * Provision real objects through the API's services, run i-MSCP's backend over
 * them, check the result on disk and in MariaDB, then delete them and check
 * again. Spec section 17's integration layer.
 *
 * Run inside the container, as root:
 *
 *   ../imscp/docker/imscp exec sh -c \
 *     'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php [customer-login]'
 *
 * It commits. Everything it creates is named sgwe2e*, and a run that dies
 * part way is cleaned up by the next one before it starts.
 */

require '/var/www/imscp/gui/include/imscp-lib.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GraphQL\Error\Error;
use GraphQL\GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\VfsDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

const REQUEST_MANAGER = '/var/www/imscp/engine/imscp-rqst-mngr';
const VHOST_DIR = '/etc/apache2/sites-available';
const SETTLE_SECONDS = 180;
const PREFIX = 'sgwe2e';

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  ok    %s\n", $what);
    } else {
        $failed++;
        printf("  FAIL  %s%s\n", $what, $detail === '' ? '' : ' (' . $detail . ')');
    }
}

function die2(string $message): void
{
    fwrite(STDERR, 'provision.php: ' . $message . "\n");
    exit(2);
}

$db = Db::fromPanel();

// ---- the customer ---------------------------------------------------------

$login = $argv[1] ?? null;
$account = $db->row(
    "
        SELECT a.admin_id, a.admin_name, a.admin_type, a.created_by, a.email, d.domain_id, d.domain_name,
            d.domain_status, d.domain_subd_limit, d.domain_mailacc_limit, d.domain_ftpacc_limit,
            d.domain_sqld_limit, d.domain_sqlu_limit, d.domain_dns, d.mail_quota
        FROM admin AS a JOIN domain AS d ON d.domain_admin_id = a.admin_id
        WHERE a.admin_type = 'user' AND d.domain_status = 'ok'" . ($login === null ? '' : ' AND a.admin_name = ?') . "
        ORDER BY a.admin_id LIMIT 1
    ",
    $login === null ? array() : array($login)
);

if ($account === null) {
    die2('no customer with a settled domain on this box' . ($login === null ? '' : ' named ' . $login));
}

foreach (array('apache2', 'proftpd', 'dovecot', 'postfix') as $service) {
    if (trim((string)shell_exec('systemctl is-active ' . escapeshellarg($service))) !== 'active') {
        die2($service . ' is not active; start it before running this (see docs/DEVELOPMENT.md)');
    }
}

$identity = new Identity(
    (int)$account['admin_id'], (string)$account['admin_name'], 'user',
    $account['created_by'] === null ? null : (int)$account['created_by'], $account['email'], array(), null
);
$domain = (string)$account['domain_name'];
$domainId = GlobalId::encode(NodeType::DOMAIN, (int)$account['domain_id']);
$mailRoot = (string)(new \iMSCP\Config\FileConfig(
    \iMSCP\Registry::get('config')['CONF_DIR'] . '/postfix/postfix.data'
))['MTA_VIRTUAL_MAIL_DIR'];

printf("Customer %s (%s)\n\n", $account['admin_name'], $domain);

// ---- the API, as a request would build it ---------------------------------

function run(string $document, array $variables = array()): array
{
    global $db, $identity;

    // A fresh container per document, as production builds one per request.
    $schema = Container::forTesting(
        dirname(__DIR__, 2), array(),
        static function (string $sql, array $bind = array()) { return null; },
        static function (int $adminId) { return null; },
        static function (int $adminId) { return true; },
        $db, (array)\iMSCP\Registry::get('config'),
        new PanelCore(false), new VfsDirectoryProbe(), MariaDbSqlServer::fromPanel($db)
    )->schemaFactory()->create();

    $result = GraphQL::executeQuery($schema, $document, null, array('identity' => $identity), $variables);
    $result->setErrorFormatter(static function (Error $error) {
        return $error->getPrevious() instanceof Throwable
            ? ErrorFactory::format($error->getPrevious(), true)
            : array('message' => $error->getMessage());
    });

    return $result->toArray();
}

function backend(): void
{
    exec(REQUEST_MANAGER . ' 2>&1', $output, $status);

    if ($status !== 0) {
        printf("  note  the request manager exited %d: %s\n", $status, implode(' / ', array_slice($output, -3)));
    }
}

/**
 * Run the backend until nothing of the customer's is pending, or give up.
 *
 * @return array<int, array> what was still pending
 */
function settle(): array
{
    $deadline = time() + SETTLE_SECONDS;

    do {
        backend();
        $pending = run('{ pending { id __typename ... on Provisioned { provisioning { state raw } } } }');
        $items = $pending['data']['pending'] ?? array();

        if ($items === array()) {
            return array();
        }

        sleep(2);
    } while (time() < $deadline);

    return $items;
}

function state(string $id): ?array
{
    $result = run('query($id: ID!) { node(id: $id) { ... on Provisioned { provisioning { state message } } } }', array('id' => $id));

    return $result['data']['node']['provisioning'] ?? null;
}

function counts(): array
{
    global $db, $account;

    $domainId = (int)$account['domain_id'];

    return array(
        'subdomains' => (int)$db->value('SELECT COUNT(*) FROM subdomain WHERE domain_id = ?', array($domainId)),
        'mail'       => (int)$db->value('SELECT COUNT(*) FROM mail_users WHERE domain_id = ?', array($domainId)),
        'ftp'        => (int)$db->value('SELECT COUNT(*) FROM ftp_users WHERE admin_id = ?', array((int)$account['admin_id'])),
        'sqlDbs'     => (int)$db->value('SELECT COUNT(*) FROM sql_database WHERE domain_id = ?', array($domainId)),
        'sqlUsers'   => (int)$db->value('SELECT COUNT(*) FROM sql_user AS u JOIN sql_database AS d USING (sqld_id) WHERE d.domain_id = ?', array($domainId)),
        'dns'        => (int)$db->value('SELECT COUNT(*) FROM domain_dns WHERE domain_id = ?', array($domainId))
    );
}

/** Delete anything a previous, interrupted run left behind. */
function sweep(): void
{
    global $db, $account;

    $domainId = (int)$account['domain_id'];
    $leftovers = array(
        array('subdomainDelete', NodeType::SUBDOMAIN, $db->rows("SELECT subdomain_id AS k, subdomain_status AS s FROM subdomain WHERE domain_id = ? AND subdomain_name LIKE 'sgwe2e%'", array($domainId))),
        array('mailAccountDelete', NodeType::MAIL_ACCOUNT, $db->rows("SELECT mail_id AS k, status AS s FROM mail_users WHERE domain_id = ? AND mail_acc LIKE 'sgwe2e%'", array($domainId))),
        array('ftpUserDelete', NodeType::FTP_USER, $db->rows("SELECT userid AS k, status AS s FROM ftp_users WHERE admin_id = ? AND userid LIKE 'sgwe2e%'", array((int)$account['admin_id']))),
        array('sqlDatabaseDelete', NodeType::SQL_DATABASE, $db->rows("SELECT sqld_id AS k, 'ok' AS s FROM sql_database WHERE domain_id = ? AND sqld_name LIKE 'sgwe2e%'", array($domainId))),
        array('dnsRecordDelete', NodeType::DNS_RECORD, $db->rows("SELECT domain_dns_id AS k, domain_dns_status AS s FROM domain_dns WHERE domain_id = ? AND domain_dns LIKE 'sgwe2e%'", array($domainId)))
    );

    foreach ($leftovers as list($mutation, $tag, $rows)) {
        foreach ($rows as $row) {
            if ($row['s'] === 'todelete') {
                continue;
            }

            $id = NodeType::isStringKeyed($tag) ? GlobalId::encodeKey($tag, (string)$row['k']) : GlobalId::encode($tag, (int)$row['k']);
            run('mutation($id: ID!) { ' . $mutation . '(id: $id) { id } }', array('id' => $id));
            printf("  swept %s %s\n", $mutation, $row['k']);
        }
    }

    settle();
}

// ---- the run ---------------------------------------------------------------

echo "Sweep:\n";
sweep();

$before = counts();
$created = array();

echo "\nCreate:\n";

if ((int)$account['domain_subd_limit'] >= 0) {
    $result = run(
        'mutation($input: SubdomainCreateInput!) { subdomainCreate(input: $input) { id name provisioning { state } } }',
        array('input' => array('parentId' => $domainId, 'label' => PREFIX))
    );
    check('subdomainCreate returns PENDING', ($result['data']['subdomainCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['subdomain'] = $result['data']['subdomainCreate']['id'] ?? null;
} else {
    echo "  skip  subdomains are withheld from this customer\n";
}

if ((int)$account['domain_mailacc_limit'] >= 0) {
    $result = run(
        'mutation($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id provisioning { state } } }',
        array('input' => array(
            'hostId' => $domainId, 'localPart' => PREFIX, 'kind' => 'MAILBOX', 'password' => 'E2e0Password',
            'quota' => (int)$account['mail_quota'] > 0 ? '10485760' : null
        ))
    );
    check('mailAccountCreate returns PENDING', ($result['data']['mailAccountCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['mail'] = $result['data']['mailAccountCreate']['id'] ?? null;
} else {
    echo "  skip  mail is withheld from this customer\n";
}

if ((int)$account['domain_ftpacc_limit'] >= 0) {
    // directory '/htdocs' exists for any provisioned customer, and takes the
    // real VFS probe - the only place in the plan it runs.
    $result = run(
        'mutation($input: FtpUserCreateInput!) { ftpUserCreate(input: $input) { id homeDirectory provisioning { state } } }',
        array('input' => array('hostId' => $domainId, 'username' => PREFIX, 'password' => 'E2e0Password', 'directory' => '/htdocs'))
    );
    check('ftpUserCreate passes the VFS directory check and returns PENDING', ($result['data']['ftpUserCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['ftp'] = $result['data']['ftpUserCreate']['id'] ?? null;
} else {
    echo "  skip  FTP is withheld from this customer\n";
}

if ((int)$account['domain_sqld_limit'] >= 0 && (int)$account['domain_sqlu_limit'] >= 0) {
    $result = run(
        'mutation($input: SqlDatabaseCreateInput!) { sqlDatabaseCreate(input: $input) { id name } }',
        array('input' => array('domainId' => $domainId, 'name' => PREFIX . '_db'))
    );
    $created['sqlDatabase'] = $result['data']['sqlDatabaseCreate']['id'] ?? null;
    check('sqlDatabaseCreate creates the database at once',
        (int)$db->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', array(PREFIX . '_db')) === 1,
        json_encode($result['errors'] ?? null));

    $result = run(
        'mutation($input: SqlUserCreateInput!) { sqlUserCreate(input: $input) { id name host } }',
        array('input' => array('databaseId' => $created['sqlDatabase'], 'name' => PREFIX . '_u', 'host' => 'localhost', 'password' => 'E2e0Password'))
    );
    $created['sqlUser'] = $result['data']['sqlUserCreate']['id'] ?? null;

    try {
        $pdo = new PDO('mysql:unix_socket=' . $db->value('SELECT @@socket'), PREFIX . '_u', 'E2e0Password');
        check('the SQL user can log in and see its database', in_array(PREFIX . '_db', $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true));
    } catch (PDOException $e) {
        check('the SQL user can log in and see its database', false, $e->getMessage() . ' ' . json_encode($result['errors'] ?? null));
    }
} else {
    echo "  skip  SQL is withheld from this customer\n";
}

if ($account['domain_dns'] !== 'no') {
    $result = run(
        'mutation($input: DnsRecordCreateInput!) { dnsRecordCreate(input: $input) { id provisioning { state } } }',
        array('input' => array('hostId' => $domainId, 'name' => PREFIX, 'type' => 'TXT', 'data' => array('text' => 'sgw end to end')))
    );
    check('dnsRecordCreate returns PENDING', ($result['data']['dnsRecordCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['dns'] = $result['data']['dnsRecordCreate']['id'] ?? null;
} else {
    echo "  skip  custom DNS is withheld from this customer (measurement M22: it is on cust1.test)\n";
}

echo "\nSettle:\n";
$stuck = settle();
check('the backend settled everything within ' . SETTLE_SECONDS . 's', $stuck === array(), json_encode($stuck));

foreach ($created as $what => $id) {
    if ($id === null || in_array($what, array('sqlDatabase', 'sqlUser'), true)) {
        continue;
    }

    $state = state($id);
    check($what . ' reached OK', ($state['state'] ?? null) === 'OK', json_encode($state));
}

if (isset($created['subdomain'])) {
    check('the subdomain has a vhost file', is_file(VHOST_DIR . '/' . PREFIX . '.' . $domain . '.conf'));
}

if (isset($created['mail'])) {
    check('the mailbox has a maildir', is_dir($mailRoot . '/' . $domain . '/' . PREFIX));
}

if (isset($created['ftp'])) {
    check('the FTP user is in the customer\'s group', strpos((string)$db->value('SELECT members FROM ftp_group WHERE groupname = ?', array($account['admin_name'])), PREFIX . '@' . $domain) !== false);
}

echo "\nDelete:\n";

foreach (array('dns' => 'dnsRecordDelete', 'sqlUser' => 'sqlUserDelete', 'sqlDatabase' => 'sqlDatabaseDelete', 'ftp' => 'ftpUserDelete', 'mail' => 'mailAccountDelete', 'subdomain' => 'subdomainDelete') as $what => $mutation) {
    if (!isset($created[$what])) {
        continue;
    }

    $result = run('mutation($id: ID!) { ' . $mutation . '(id: $id) { id } }', array('id' => $created[$what]));
    check($mutation . ' accepted', !isset($result['errors']), json_encode($result['errors'] ?? null));
}

$stuck = settle();
check('the backend settled every deletion within ' . SETTLE_SECONDS . 's', $stuck === array(), json_encode($stuck));

check('the database is back where it started', counts() === $before, json_encode(array('before' => $before, 'after' => counts())));
check('no vhost file is left', !is_file(VHOST_DIR . '/' . PREFIX . '.' . $domain . '.conf'));
check('no maildir is left', !is_dir($mailRoot . '/' . $domain . '/' . PREFIX));
check('no SQL database is left', (int)$db->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', array(PREFIX . '_db')) === 0);
check('no SQL user is left', (int)$db->value("SELECT COUNT(*) FROM mysql.user WHERE User = ?", array(PREFIX . '_u')) === 0);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
```

- [ ] **Step 3: Run it**

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php'`
Expected: `N passed, 0 failed` and exit status 0. On `cust1.test` (M22) the DNS step is skipped.

**When a step fails, the backend's own words are the evidence.** `state()` prints `provisioning.message`, which is the text the Perl module wrote into the status column. Read it, then read the module under `/var/www/imscp/engine/PerlLib/Modules/` that wrote it, before changing any service. The possible outcomes, and what each means:

- The backend refused a row the service wrote → the transcription is wrong. Fix the service, add a failing integration test that pins the corrected row first, and record the fix in the commit message.
- The backend failed for an environmental reason (a service down, a missing directory the installer should have made) → not the plugin's fault. Fix the box, and record what was wrong in `docs/DEVELOPMENT.md`'s docker section.
- `the database is back where it started` fails with a count one higher → a delete left a row the backend did not remove. That is exactly the class of failure spec §17 says this test exists for; treat it as a service bug.

Run it a second time. Expected: the sweep finds nothing, and the run passes again.

- [ ] **Step 4: Document it**

In `docs/DEVELOPMENT.md`, after the `test/api/smoke.sh` section, add:

````markdown
### Provisioning end to end

`test/api/provision.php` creates a subdomain, a mailbox, an FTP user, a SQL
database and user, and (where the customer has it) a DNS record through the
API's services; runs i-MSCP's request manager over them; checks the vhost file,
the maildir and the SQL login; deletes everything; and checks that the database
and the filesystem are back where they started.

```shell
../imscp/docker/imscp exec systemctl start apache2    # the container does not start it
../imscp/docker/imscp exec sh -c \
  'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php [customer-login]'
```

It needs a customer with a settled domain (the docker install has `cust1.test`)
and commits real objects, named `sgwe2e*`; a run that dies part way is swept up
by the next. It runs the request manager itself, because the container's
`imscp_daemon` is not running.
````

- [ ] **Step 5: Commit**

```bash
git add test/api/provision.php docs/DEVELOPMENT.md
git commit -m "Provision through the API and let the real backend judge it

Every other test in the phase rolls back, so none of them can show that
i-MSCP's backend accepts what the services write - a transcription can match
the page and still produce a row the Perl modules refuse. This script
commits, runs the request manager, checks the vhost file, the maildir and a
real SQL login, then deletes everything and checks that the database and the
filesystem are as they were.

It is also the one place the VFS directory probe runs for real, against
ProFTPD."
```

---
## Task 18: Close out the phase — `sonnet`

The matrix's last assertion, the schema version, the changelog, the client guide, and the specification brought up to what shipped.

**Files:**
- Modify: `test/authz/CatalogueCoverageTest.php` (append one test)
- Modify: `Api/Container.php` (`apiVersion()`), `test/unit/Api/ContainerTest.php` (one assertion)
- Modify: `CHANGELOG.md`, `docs/API.md`, `docs/SPECIFICATION.md` (§7.11, §8.1, §8.3, §9)

**Interfaces:**
- Consumes: everything.
- Produces: `Container::apiVersion()` returns `'1.2.0'`.

- [ ] **Step 1: Nothing may stay skipped**

Append to `test/authz/CatalogueCoverageTest.php`'s class:

```php
    public function testEveryCatalogueEntryIsInTheSchema(): void
    {
        // Until phase 3's last task the matrix skipped rows whose mutation had
        // not yet reached the schema. From here a skipped row is a mutation
        // that went missing, so it fails instead.
        $schema = (new SchemaFactory(
            dirname(__DIR__, 2) . '/schema/schema.graphql', null, new ResolverMap(array()),
            array(TypeResolver::class, 'resolveType')
        ))->create();
        $mutation = $schema->getMutationType();
        $fields = $mutation === null ? array() : array_keys($mutation->getFields());

        self::assertSame(
            array(),
            array_values(array_diff(array_keys(MutationCatalogue::all()), $fields)),
            'A mutation the authorisation matrix covers is not in the schema.'
        );
    }
```

Run: `tools/test.sh --testsuite authz`
Expected: PASS, no skips.

- [ ] **Step 2: Bump the schema version**

Spec §18: adding a `Mutation` type and input types is additive, which is a minor version.

In `Api/Container.php`'s `apiVersion()`, replace the body with:

```php
        // Spec section 18: this is the schema's version, not the plugin's.
        // Phase 2 added the read model (1.1.0); phase 3 added Mutation and its
        // inputs, additive again, so a minor bump.
        return '1.2.0';
```

In `test/unit/Api/ContainerTest.php`, replace `self::assertSame('1.1.0', $body['data']['apiVersion']);` with `self::assertSame('1.2.0', $body['data']['apiVersion']);`.

Run: `tools/test.sh --filter ContainerTest`
Expected: PASS.

- [ ] **Step 3: The changelog**

In `CHANGELOG.md`, replace the two lines under `## [Unreleased]`

```markdown
The read model. `apiVersion` answers `1.1.0`: types and fields within the same
major version.
```

with

```markdown
The read model and customer mutations. `apiVersion` answers `1.2.0`: types,
fields and a `Mutation` type within the same major version.
```

and add to the existing `### Added` list:

```markdown
- **Mutations.** Everything a customer owns can be created, changed and
  deleted: `domainUpdate`; `subdomainCreate`, `subdomainUpdate`,
  `subdomainDelete`; `domainAliasCreate`, `domainAliasUpdate`,
  `domainAliasDelete`; `mailAccountCreate`, `mailAccountUpdate`,
  `mailAccountDelete`, `mailAutoresponderSet`, `mailCatchallCreate`,
  `mailCatchallDelete`; `ftpUserCreate`, `ftpUserUpdate`, `ftpUserDelete`;
  `sqlDatabaseCreate`, `sqlDatabaseDelete`, `sqlUserCreate`,
  `sqlUserSetPassword`, `sqlUserDelete`; `dnsRecordCreate`, `dnsRecordUpdate`,
  `dnsRecordDelete`. A customer, their reseller and an administrator may each
  call them; nobody else sees the object (`NOT_FOUND`).
- Every write asks, in this order: ownership, scope, feature, settled state,
  input, quota — so the error a caller gets is the one that leaks least. An
  object the backend has not finished with is `CONFLICT` with
  `extensions.retryAfterSeconds`; a failed one can still be deleted.
- Mutations dispatch the panel's own events with the panel's own parameters,
  poke the daemon after the commit, and write the panel's log in the panel's
  words, so other plugins and the admin log see API writes as UI writes.
- A mutation returns its object with `provisioning.state` `PENDING` (or
  `ORDERED`, for a customer's new domain alias) until the backend settles it.
  SQL databases and users are created at once.
- The authorisation matrix of the specification's §17: all 24 mutations as
  each of six accounts, and the scope rule for each, against a seeded database.
- `validate_ftp_home_dir` in `config.php`: whether FTP home directories and
  new document roots are checked to exist before writing. On by default.
- `test/api/provision.php`: provisions real objects through the API, runs
  i-MSCP's backend over them, checks the vhost, the maildir and the SQL login,
  then deletes everything and checks it is gone.
```

add this line at the end of the existing `### Changed` list:

```markdown
- `MailAccount.forwardTo` now lists a catch-all's addresses. It read every
  catch-all as delivering nowhere.
```

and add this section after `### Changed`:

```markdown
### Removed

- The `saygoweb/anorm` dependency. No write used it, and the one read that did
  is now a plain batched query with the same cost. The plugin has one runtime
  dependency, `webonyx/graphql-php`.
```

Under `### Fixed`, add nothing: the panel defects this phase does not reproduce are not defects of this plugin. They are listed in `docs/SPECIFICATION.md` §21, C11.

- [ ] **Step 4: The client guide**

In `docs/API.md`:

1. Replace the opening paragraph's first sentence, `A client-facing guide to what phase 0–1 shipped: an authenticated endpoint serving `viewer` and `apiVersion`.`, with `A client-facing guide to what the API does today: the whole customer graph to read, and every customer-level object to write.`
2. Replace the paragraph beginning `**Not yet implemented:** mutations of any kind,` with:

```markdown
**Not yet implemented:** reseller and administrator mutations (customers,
hosting plans, resellers, alias approval), `tokenIssue` and `tokenRevoke`, the
browser explorer, rate limiting, and the audit log. If you have found
documentation elsewhere describing them, it is the specification describing
where this API is going, not what it does today.
```

3. Insert this section before `## Errors`:

````markdown
## Mutations

Every mutation writes one object in one transaction, and returns as soon as
the intent is recorded — not when the server has done the work. The i-MSCP
backend carries a change out afterwards, usually within seconds. So a created
object comes back like this:

```graphql
mutation {
  subdomainCreate(input: { parentId: "RG9tYWluOjEy", label: "shop" }) {
    id
    provisioning { state settled }
  }
}
```

```json
{ "data": { "subdomainCreate": { "id": "U3ViZG9tYWluOjQ0", "provisioning": { "state": "PENDING", "settled": false } } } }
```

and you poll until it settles, either for the one object or for everything of
yours at once:

```graphql
query($id: ID!) { node(id: $id) { ... on Provisioned { provisioning { state message } } } }
query { pending { id __typename } }
```

A backend failure is not an error in the mutation's response — it happens after
the response was sent. It appears as `provisioning.state` `ERROR`, with the
backend's own text in `provisioning.message`. An object in `ERROR` can be
deleted, which is how you clean it up.

Four things to know:

- **Several mutations in one document are not one transaction.** They run in
  the order written and each commits on its own, so if the third fails the
  first two are done. Send one mutation per request unless that is what you
  want.
- **An object that has not settled cannot be changed.** You get `CONFLICT` with
  `extensions.retryAfterSeconds`. An object in a state no waiting will change —
  a disabled account, an alias awaiting its reseller's approval — is
  `FORBIDDEN`, with `extensions.state`.
- **Updates are partial.** A field you leave out keeps its value; `forwarding:
  null` removes forwarding. The exception is `dnsRecordUpdate`, which replaces
  the record's name, TTL and data together.
- **SQL databases and users are immediate.** They have no provisioning state:
  when the mutation returns, the database exists.

A customer's new domain alias comes back `ORDERED`, because their reseller
approves it. The same mutation called by the reseller or an administrator
comes back `PENDING`.

A token needs the write scope for the kind of object — `DOMAINS_WRITE` for
domains, subdomains and aliases, `MAIL_WRITE`, `FTP_WRITE`, `SQL_WRITE`,
`DNS_WRITE` — and that is enough to read back the object the mutation returns.
Fields that lead to *other* objects (`Subdomain.customer`, `MailAccount.host`)
still need their own read scopes.

Passwords are type `Secret`: they are never returned, and a validation error
names the field, never the value. Storage figures, mailbox quotas included, are
bytes.
````

4. In the errors table, replace the four "not currently exercised" phrases:
   - `LIMIT_EXCEEDED` row's last cell: `Do not retry until the quota has room. extensions.quota names which allowance`
   - `FEATURE_UNAVAILABLE` row's last cell: `Not actionable by the client: the account's reseller withholds it. extensions.feature names it`
   - `CONFLICT` row's middle cell: `The object has not settled (carries extensions.retryAfterSeconds), or the name is taken`; last cell: `Retry after that many seconds, or pick a different name`
   - `FORBIDDEN` row's middle cell: `The object is visible but this operation on it is not permitted: a scope the credential lacks (extensions.scope), a state the operation does not accept (extensions.state), or an object the panel protects`
5. Replace the paragraph beginning `Four of those rows — `LIMIT_EXCEEDED`, `FEATURE_UNAVAILABLE`, `CONFLICT` and the rate limiter` with:

```markdown
`RATE_LIMITED` is part of the closed vocabulary the schema commits to, but
nothing triggers it yet: rate limiting arrives in a later phase. It is listed
because a code is a stable part of the contract from the moment it can appear
at all, and a client written against this table today needs no change when it
starts firing.
```

- [ ] **Step 5: The specification**

In `docs/SPECIFICATION.md`:

1. §7.11 — in the `type Mutation` block, delete the two lines under `# ---- credentials (see §5)` (`tokenIssue` with its description, and `tokenRevoke`), replacing them with the comment line `# ---- credentials (see §5): tokenIssue and tokenRevoke arrive with rate limiting, in phase 6`. After the paragraph ending `applying the same validation each step applied.`, add:

```markdown
**Phase 3's inputs as shipped** are in `schema/schema.graphql`, and differ from
the sketch above in three ways worth knowing. Every create names the object it
hangs off — `SubdomainCreateInput.parentId`, `DomainAliasCreateInput.domainId`,
`SqlDatabaseCreateInput.domainId`, `MailAccountCreateInput.hostId` — so a
reseller acting for a customer never needs a separate customer argument.
Updates are partial: an absent field keeps its value, and `forwarding: null`
removes forwarding; `DnsRecordUpdateInput` is the exception and replaces the
record whole. And a document root is written in the same terms
`VirtualHost.documentRoot` reads (`/htdocs/public`), so a client can write back
what it read.
```

2. §8.1 — after the numbered list, before `Steps 2–7 are ordered`, add:

```markdown
SQL databases and users (§2.1) skip steps 5 and 9: there is no status to be
settled and nothing for the daemon to do. Their DDL also implicitly commits, so
step 8 cannot hold it: the DDL runs first, the row is written in a transaction
after it, and a row that cannot be written undoes the DDL.
```

3. §8.3 — append to the section:

```markdown
Only *pending* is `CONFLICT`. An object in a settled state the operation does
not accept — `DISABLED`, `ORDERED` for anything but a delete, `ERROR` for
anything but a delete — is `FORBIDDEN` with `extensions.state` and no retry
hint, because waiting will not change it.
```

4. §9 — in the `FORBIDDEN` row, replace `Visible object, disallowed operation, or missing scope` with `Visible object, disallowed operation, missing scope (extensions.scope), or a state the operation does not accept (extensions.state)`; in the `CONFLICT` row, replace `Object not settled (§8.3), or a uniqueness clash` with `Object not settled (§8.3, extensions.retryAfterSeconds), or a uniqueness clash`; in the `FEATURE_UNAVAILABLE` row, append ` Carries extensions.feature`.

- [ ] **Step 6: The whole suite, twice, and the end-to-end run**

Run: `tools/test.sh`
Expected: lint PASS on both PHP versions; the `CORE-DEBT` inventory lists C1, C3, C10 and C11; unit, schema, integration and authz PASS with no skips.

Run it again. Expected: the same. (A second run catches a test that leaves state behind.)

Run: `../imscp/docker/imscp exec sh -c 'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php'`
Expected: `0 failed`.

- [ ] **Step 7: Commit**

```bash
git add test/authz/CatalogueCoverageTest.php Api/Container.php test/unit/Api/ContainerTest.php CHANGELOG.md docs/API.md docs/SPECIFICATION.md
git commit -m "Close phase 3: schema 1.2.0, and the documents caught up

The authorisation matrix no longer tolerates a skipped row: every mutation
it covers must be in the schema. apiVersion moves to 1.2.0 for the additive
Mutation type.

The client guide gains the part a client most needs and would otherwise
learn by surprise: a mutation returns intent, not completion; several in one
document are not one transaction; and an unsettled object is CONFLICT with a
retry hint while an object in a state no wait will change is FORBIDDEN. The
specification records what phase 3 decided that it had left open."
```

---

## Checkpoint E: `code-review medium` over Wave 6

Run by the orchestrating session, as the [Execution mandate](#waves-and-checkpoints) sets out.

**Focus:**

- The Anorm removal: nothing left that names a model, `byColumn()`, `related()` or `parent()`; `QueryCountTest` still 31; composer files consistent.
- `test/api/provision.php`: it commits on a real box — does its sweep touch only `sgwe2e*`, and can a failure leave anything it will not sweep next time?
- `CHANGELOG.md`, `docs/API.md` and the specification's amendments against the code as it now is: every mutation named, every error extension described, nothing promised that is not built.

- [ ] **Step 1: The suite, twice**

Run: `tools/test.sh`, then again.
Expected: both green.

- [ ] **Step 2: Review**

Invoke the `code-review` skill with arguments `medium phase3-wave-6..HEAD`, passing the **Focus** list above.

- [ ] **Step 3: Resolve the findings**

Fix each finding that holds, in commits whose subjects begin `Fix checkpoint E:`, then run `tools/test.sh` until green.

- [ ] **Step 4: Open the next wave**

Run: `git tag -a phase3-done -m "Checkpoint E: <declined findings and why, or: none declined>"`

---

## Self-review

Written after the eighteen tasks, reading the specification again against them. Section 2 records defects found while checking the tasks' code and tests against the box and the vendored libraries, **all fixed in the tasks above**; each entry says what the defect was, because the fix is only obvious once the failure is.

### 1. Spec coverage

| Spec | Requirement | Where |
| --- | --- | --- |
| §1.1 | A customer creates, updates and deletes subdomains, alias subdomains, domain aliases, mail accounts, catch-alls, autoresponders, FTP users, SQL databases and users, custom DNS records, and edits their main domain's forwarding and document root | Tasks 6–15, one row each in `MutationCatalogue` |
| §1.1 | A reseller, and an administrator, may do everything a customer may, on their customers | `OwnershipResolver::mayReach()` via `Guard::target()`; the matrix's `reseller` and `admin` columns |
| §2.1 | Provisioning is asynchronous; SQL is the synchronous exception | D12, `SqlService`'s docblock, Task 18's §8.1 amendment |
| §2.3 | Feature, limit and state are three separate questions with three errors | `Guard::requireFeature()`, `requireQuota()`, `requireState()` |
| §3.1 | Every write dispatches the panel's events with the panel's parameters | Every service; every service test asserts event names and parameters |
| §3.2 | Rules transcribed, citing page and line, with `CORE-DEBT` markers | Every service; Task 2 amends §3.2's table where the spec's plan could not work (D10) |
| §3.4 | Flat `entityVerb` mutations | Task 8 onwards |
| §6.3 | Ownership before anything; `NOT_FOUND`, never `FORBIDDEN`, for the unreachable | `Guard::target()`; `ScopeMatrixTest::testAStrangerWithoutTheWriteScopeIsStillNotFound` |
| §6.4 | One identity per request; the shim | Unchanged, and no longer depended on by any write (D10) |
| §6.5 | Layer 1: no core helper reached in a state that would let it exit | `CoreCallsTest` (static), the matrix running the real validators (dynamic) |
| §6.5 | Layer 2: the shutdown guard | Plan 1's, unchanged |
| §7.11 | The 24 customer-level mutations | Tasks 8, 10, 11, 13, 15; `CatalogueCoverageTest` pins the list |
| §7.11 | `tokenIssue`, `tokenRevoke` | **Moved to plan 4** (D16) |
| §8.1 | The order of every write | [The shape of every mutation](#the-shape-of-every-mutation); `GuardTest::testOwnershipIsAskedBeforeScope`; `SubdomainServiceTest::testTheFeatureIsAskedBeforeTheInput` and `testTheInputIsAskedBeforeTheQuota` |
| §8.1 step 9 | `send_request()` after the commit | Every service calls `sendRequest()` after `Writer::run()` returns |
| §8.1 step 10 | `write_log()` in the page's words | Every service test asserts the log line |
| §8.1 step 10 | The audit row | **Plan 4** (§19 phase 6) |
| §8.2 | Mutations return intent | `VirtualHostMutationsTest::testACreatedSubdomainReadsBackPending` |
| §8.3 | Unsettled is `CONFLICT` with `retryAfterSeconds`; delete allowed in `ERROR` | `Guard::requireState()`; `testAFailedSubdomainMayBeDeleted` and its siblings |
| §8.4 | A retried create is `CONFLICT` | `Writer::isDuplicate()` for the unique keys; `VirtualHosts::nameInUse()` where there is none (M9) |
| §8.5 | Several mutations are not one transaction; serial order | `VirtualHostMutationsTest::testEachMutationInADocumentCommitsOnItsOwn`; the `Mutation` description |
| §9 | Every code emitted is in the closed set | Only codes `ErrorCode` already defines; plan 1's `ErrorFactoryTest` |
| §11 | `write_log()` for every mutation | As §8.1 step 10 |
| §11 | Secrets never appear | `MailMutationsTest::testASecretIsNeverEchoedInAnError`; audit redaction is plan 4 |
| §12 | Passwords hashed as the panel hashes them; SQL name limits, reserved names, host rule | `Core::hashPassword()`; `SqlService`; `MariaDbSqlServerTest` |
| §14 | `validate_ftp_home_dir` | Task 2, broadened to document roots (D20) |
| §17 | Authorisation matrix, written first, green throughout | Task 4; rows enabled by 8, 10, 11, 13, 15; no skips from Task 18 |
| §17 | Integration against the running box | Task 17 |
| §19 phase 3 | "Subdomains, aliases, mail, FTP, SQL, DNS. The authorisation matrix is written first and stays green." | All of the above |
| §21 | Every duplicate marked; new items filed | C11 added in Task 6; C1 and C3 markers throughout |

### 2. Defects found in review, and what was changed

**F1 — `AuthzTestCase::run()` collided with PHPUnit's own `TestCase::run()`.** A `protected function run(string …)` is an incompatible override of a public method and a fatal error when the class loads. Renamed `runEntry()` in Task 4, with a comment saying why.

**F2 — `quotalimits.bytes_in_avail` is a `FLOAT`.** Task 11's first draft compared it as a string and would have read `5368710000`: MariaDB prints a `FLOAT` to six significant digits. Measured in the box, then asserted through `CAST(… AS DECIMAL(20,0))`, which reads 5 GiB exactly.

**F3 — The authz harness formatted errors without their `path`.** `Http\GraphQLHandler` adds `path`; the harness did not, so Task 8's assertion on `errors[0].path` could not pass. The harness now formats as the handler does.

**F4 — `FtpGroups::withMemberOn()` joins on `gid`, as the core does.** Task 6's delete test used gid 2000, which a real customer on a developer's box may well have; the join would then have found that customer's group. The test rows use gids no customer has (64001–64003), and the comment says why.

**F5 — Key order in `array_intersect_key()`.** Task 9's delete test compared `array_values(array_intersect_key($row, …))` against a literal in the wrong column order. Replaced with one assertion per column.

**F6 — `sharedMountPoint()` was written twice.** Task 6 had it in `SubdomainService`; Task 7 needed the same rule for aliases. It now lives once, in `VhostInput`.

**F7 — Test counts.** Several "Expected: PASS, N tests" lines were wrong by one to five. Each was recounted against its code block, data provider rows included.

### 3. Decisions this plan makes that the specification did not

D10–D20, at the top of the plan. Four of them change the specification, and the task that makes the change says so: D10 (§3.2's table and §6.5, Task 2), D16 (§7.11, Task 18), D17 (§3.3 and §20, Task 16), D20 (§14, Task 2). The others are recorded in §7.11, §8.1, §8.3 and §9 by Task 18.

### 4. Schema shapes invented here, for the specification to catch up with

§7.11 gives two representative inputs and says "the rest follow the same shape". These are the rest, as Tasks 8–15 define them, and Task 18's §7.11 paragraph points at the SDL for them:

- `DomainUpdateInput`, `SubdomainUpdateInput`, `DomainAliasUpdateInput` — identical, partial.
- `DomainAliasCreateInput` — `domainId`, `name`, `sharedMountPointOf`, `forwarding`, `wildcard`.
- `MailAccountCreateInput`, `MailAccountUpdateInput`, `AutoresponderInput`, `MailCatchallCreateInput`.
- `FtpUserCreateInput.directory` — relative to the web root, where `FtpUser.homeDirectory` reads absolute. Named differently on purpose.
- `enum SqlNamePrefix`, `SqlDatabaseCreateInput`, `SqlUserCreateInput` (new user or `existingUserId`).
- `enum SrvProtocol`, `DnsRecordDataInput`, `DnsRecordCreateInput`, `DnsRecordUpdateInput`.
- Error extensions this plan adds within existing codes: `field` on `NOT_FOUND` (which argument), `quota` on `LIMIT_EXCEEDED`, `feature` on `FEATURE_UNAVAILABLE`, `state` and `retryAfterSeconds` on `CONFLICT`, `state`, `scope` and `ownedBy` on `FORBIDDEN`, `index`, `maximum`, `minLength`, `requiresLettersAndDigits`, `unit` and `required` on `BAD_USER_INPUT`. Adding an extension is compatible under §18.

### 5. Deliberately deferred, and to where

- **`tokenIssue` and `tokenRevoke`** → plan 4, with the rate limiter (D16).
- **`domainAliasApprove` and `domainAliasReject`** → plan 4. An approved alias must also get the default mail accounts `DomainAliasService::create()` withholds from an order.
- **The audit row of §8.1 step 10 and §11**, including redaction of `Secret` variables by type → plan 4. Mutations write the panel's log now; `api_audit` stays empty until then.
- **Mutation rate limits** → plan 4.
- **Complexity weights for list fields** (plan 2's deferral) → plan 4, unchanged.
- **Mutations over HTTP in `test/api/smoke.sh`** → plan 4. Task 17 drives the services in process; the transport is already covered for queries, and nothing in a mutation's path through the middleware differs.
- **Removing the identity shim** → core track, C1. Nothing plan 3 writes needs it, but `AuthenticateMiddleware` still applies it and plan 1's tests still assert it.
- **C11 item 10**, `createDefaultMailAccounts()`'s transaction leak on a database error → core track. `Writer::run()` does not defend against it: doing so means reaching into `DatabaseMySQL`'s protected counter in production, which is a worse trade than the rare failure. The fixture does reach in, in tests, where it is the right trade.
- **The alias order email's recipient** (C11 item 9) → the panel, first.

### 6. Review strategy

Per-task review subagents are replaced by five `code-review medium` checkpoints ([Waves and checkpoints](#waves-and-checkpoints)). The trade is deliberate: each task already carries its own tests, the matrix gates every mutation, and a checkpoint reviewing a coherent slice sees cross-task problems — a pattern repeated wrongly, an interface two tasks read differently — that a review of one task cannot. What it gives up is the early catch of a defect *inside* a wave; the checkpoints are placed so no wave is longer than four tasks, and the two waves whose output everything else copies (1 and 3) end in one.

### 7. What would make this plan fail

In the order they are likely:

1. **The backend refuses a row a service writes.** Everything before Task 17 rolls back, so nothing before it can see this. Task 17's Step 3 says how to tell a transcription error from an environmental one; expect at least one of each on first contact.
2. **graphql-php does not finish a mutation field's whole subtree before starting the next.** D18's reset depends on it. `VirtualHostMutationsTest::testAMutationsObjectIsReadAfterItsWriteNotFromAnEarlierLoad` is the test; if it fails with `second` missing, the reset is racing a sibling, and the fix is a per-field loader rather than a shared one.
3. **The panel's functions behave differently from M17–M21 on another box.** `PanelCoreTest` pins them; a failure there is a panel difference to understand before any service test is believed.
4. **`DatabaseMySQL::$transactionCounter` is renamed.** The fixture's reflection (Task 1) fails loudly by name. Nothing in production depends on it.
5. **`validate_ftp_home_dir` on in production.** The VFS probe opens an FTP connection per checked directory and inserts a temporary `ftp_users` row outside the service's transaction (validation precedes step 8, so it is never inside one). Task 17 is its only automated exercise. Spec §20's risk 5 stands: if ProFTPD restarts are frequent, the default may need to flip.

---
