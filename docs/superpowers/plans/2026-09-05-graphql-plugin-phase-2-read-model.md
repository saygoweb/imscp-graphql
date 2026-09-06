# SGW_GraphQL — Phase 2 Implementation Plan: the read model

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` to implement this plan task-by-task. Inline execution is **not** permitted for this plan — see [Execution mandate](#execution-mandate). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The whole customer graph reads over `POST /api/graphql` — domains, subdomains, aliases, mail, FTP, SQL, DNS, quotas, provisioning — with ownership resolved before every read, batch loaders on every edge, and a query-count test that turns an N+1 into a failing test rather than a customer's slow page.

**Architecture:** Ownership is resolved first, in one class, from the chain in spec §2.2, and an unreachable object is `NOT_FOUND`. The SDL grows from the phase-1 viewer slice to the full read schema of spec §7. Reads go through Anorm models — one model per i-MSCP table — and a `BatchLoader` that pairs graphql-php's `Deferred` with Anorm's IN-clause batch loaders, so every one-to-many and many-to-one edge costs one query per level regardless of how many parents asked for it. Resolvers are thin: they shape a model into an array and hand any edge to the loader.

**Tech Stack:** PHP 7.4, `webonyx/graphql-php ^15`, `saygoweb/anorm ^3.1`, Slim 3 (supplied by the panel), PHPUnit ^9.6, MariaDB, i-MSCP plugin API 1.5.1.

**Spec:** [`docs/SPECIFICATION.md`](../../SPECIFICATION.md) — read §2.1, §2.2, §2.3, §3.3, §3.5, §6.3, §7 in full, §9, §10.1, §10.2, §17 and §19 before starting. This plan argues from that document and cites it throughout.

**Predecessor:** [`2026-09-04-graphql-plugin-phase-0-1.md`](2026-09-04-graphql-plugin-phase-0-1.md). Everything it built is assumed present and its signatures are used verbatim; the [Interfaces inherited from plan 1](#interfaces-inherited-from-plan-1) section lists them so no task has to go looking.

---

## Scope

Specification §19 phase 2: *"The whole customer graph reads: domains, subdomains, aliases, mail, FTP, SQL, DNS, quotas, provisioning. Batch loaders and the query-count test with it."*

This plan covers exactly that, and one thing the spec puts in phase 3 but that must land first: **`OwnershipResolver` is Task 1**, because plan 1's self-review recorded that no mutation may ship before it and because every read resolver in this plan goes through it.

Follow-on plans, not covered here:

| Plan | Spec phase | Deliverable |
| --- | --- | --- |
| 3 | 3 | Customer mutations — subdomain, alias, mail, FTP, SQL, DNS; the authorisation matrix of §17 against a seeded database |
| 4 | 4–7 | Reseller and administrator mutations, rate limits, audit, the explorer, packaging and release |

Deliberately deferred, and to where, is recorded in the [Self-review](#self-review).

---

## Global Constraints

Copied verbatim from the specification and from plan 1. **Every task's requirements implicitly include this section.**

- **PHP 7.4.x.** Typed properties, arrow functions and `??=` are available. Constructor promotion, `match`, enums, named arguments, nullsafe calls and union types are **not**. (Spec §2.5)
- **All plugin source must also lint under PHP 8.3.** `test/lint/all.sh` runs `php7.4 -l` and `php8.3 -l` over every file and must stay green. (Spec §2.5)
- **Namespace `iMSCP\Plugin\SGW_GraphQL`**, PSR-4 with the **plugin root as the namespace root**. There is no `src/` directory: `iMSCP\Plugin\SGW_GraphQL\Model\DomainModel` lives at `Model/DomainModel.php`. (Plan 1's deviation from spec §13, still in force.)
- **Tests and lint run inside the Vagrant box**, via `tools/test.sh`. The host has no `php7.4`. Never claim a test passes without having run it in the box.
- **Two runtime dependencies only:** `webonyx/graphql-php ^15`, `saygoweb/anorm ^3.1`. Both are vendored and verified working on PHP 7.4. No third.
- **Licence header on every PHP file**, GPL-2.0-or-later, copyright `2026 Cambell Prince <cambell.prince@gmail.com>`, matching `SGW_ApacheCache` exactly.
- **British spelling** in all comments and user-facing strings (`authorise`, `behaviour`, `licence` as noun, `normalise`).
- **Unreachable objects return `NOT_FOUND`, never `FORBIDDEN`.** (Spec §6.3)
- **`GET` is never accepted** for any API operation, including queries. (Spec §4)
- **Ownership is resolved before anything else** — before feature checks, before quota checks, before validation. (Spec §6.3, §8.1)
- **Tables are named `api_token`, `api_perm`, `api_audit`** — not `graphql_*`. (Plan 1's amendment to spec §15.)
- **Commit messages:** short imperative subject, blank line, prose explaining *why* — not a bullet list of what changed — ending with the trailers

  ```
  Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01D9fYduDiB3P2ScxcQpvcPa
  ```

  Every `git commit -m` in this plan shows the subject and body; append both trailers to each.

---

## Interfaces inherited from plan 1

Use these exactly. Do not invent variants; where phase 2 needs more, this plan says so in the task that adds it.

```php
// Support\GlobalId
GlobalId::encode(string $type, int $id): string          // base64url of "Type:id"
GlobalId::decode(string $encoded, ?string $expectedType = null): GlobalId
GlobalId::getType(): string
GlobalId::getId(): int
// Rejects leading zeros, non-positive ids and a mismatched type tag.

// Support\Provisioning
Provisioning::fromStatus(?string $status): Provisioning
Provisioning::getState(): string        // OK | PENDING | DISABLED | ORDERED | ERROR
Provisioning::getRaw(): string
Provisioning::isSettled(): bool
Provisioning::getMessage(): ?string
Provisioning::PENDING_STATUSES          // toadd, tochange, todelete, toenable, todisable,
                                        // torestore, tochangepwd, topurge
Provisioning::STATE_OK | STATE_PENDING | STATE_DISABLED | STATE_ORDERED | STATE_ERROR

// Support\ErrorCode  (string constants)
UNAUTHENTICATED  API_ACCESS_WITHDRAWN  FORBIDDEN  NOT_FOUND  BAD_USER_INPUT
LIMIT_EXCEEDED   FEATURE_UNAVAILABLE   CONFLICT   RATE_LIMITED  QUERY_TOO_COMPLEX  INTERNAL
ErrorCode::httpStatus(string $code): int

// Support\ApiException
new ApiException(string $code, string $message, array $extensions = [], ?Throwable $previous = null)
ApiException::getErrorCode(): string
ApiException::getExtensions(): array

// Auth\Identity
new Identity(int $adminId, string $username, string $adminType, ?int $createdBy,
             ?string $email, array $scopes, ?int $tokenId)
Identity::getAdminId(): int          Identity::getUsername(): string
Identity::getAdminType(): string     Identity::getRole(): string   // ADMIN|RESELLER|CUSTOMER
Identity::getCreatedBy(): ?int       Identity::getEmail(): ?string
Identity::getScopes(): array         Identity::getTokenId(): ?int
Identity::hasScope(string $scope): bool      // true for every scope when none are recorded
Identity::ROLE_ADMIN | ROLE_RESELLER | ROLE_CUSTOMER

// Auth\Scope
Scope::all(): array      Scope::isValid(string $scope): bool
Scope::ACCOUNT_READ  DOMAINS_READ  DOMAINS_WRITE  MAIL_READ  MAIL_WRITE  FTP_READ  FTP_WRITE
Scope::SQL_READ  SQL_WRITE  DNS_READ  DNS_WRITE  CUSTOMERS_READ  CUSTOMERS_WRITE
Scope::RESELLERS_READ  RESELLERS_WRITE

// Schema\ResolverMap
new ResolverMap(array $map)          // keyed 'Type.field' => callable
ResolverMap::for(string $type, string $field): ?callable
ResolverMap::keys(): string[]
// A field with no entry falls back to reading that key off the source array.

// Schema\SchemaFactory
new SchemaFactory(string $sdlPath, ?string $cacheDir, ResolverMap $resolvers)
SchemaFactory::create(): \GraphQL\Type\Schema     // AST cached, keyed on the SDL mtime

// Api\Container
Container::fromPlugin(SGW_GraphQL $plugin): self
Container::forTesting(string $pluginDir, array $config, callable $query, callable $accountLoader): self
Container::handler(): GraphQLHandler      Container::middleware(): array
Container::tokens(): TokenService         Container::schemaFactory(): SchemaFactory
Container::schemaPath(): string           Container::apiVersion(): string

// Resolver\ViewerResolver
new ViewerResolver(string $apiVersion)
ViewerResolver::map(): array                       // Query.apiVersion, Query.viewer
ViewerResolver::identityFrom($context): Identity   // reads $context['identity']

// Plugin class
SGW_GraphQL::customerHasApiAccess(int $adminId): bool
SGW_GraphQL::loadVendor(): void
```

Tooling that exists: `tools/deploy.sh`, `tools/plugin-ctl.sh`, `tools/test.sh`, `test/lint/all.sh`, `test/api/smoke.sh`, `test/phpunit.xml`, `test/bootstrap.php`. `schema/schema.graphql` currently carries only the viewer slice — scalars `DateTime`, `EmailAddress`, `Secret`; enums `Role`, `Scope`; `type Viewer`; `type Query { apiVersion viewer }`.

---

## Deviations and decisions this plan records

Five things the specification does not settle. Each is decided here, with the reason, so a reviewer can disagree with the decision rather than discover it in the code.

### D1 — Alias subdomains need their own identifier tag

Spec §7.3 makes an alias subdomain a `Subdomain` whose `parent` is a `DomainAlias`, which is right. But `subdomain` and `subdomain_alias` are separate tables with separate id spaces, so `subdomain_id = 3` and `subdomain_alias_id = 3` would both encode as `Subdomain:3` under spec §3.5 and address two different customers' objects.

**Decision:** the *identifier tag* and the *GraphQL type* are allowed to differ. An alias subdomain's global id is `GlobalId::encode('AliasSubdomain', $id)`; its `__typename` is `Subdomain`. A resolver taking a subdomain id accepts either tag, using `GlobalId::decode($id)` with no expected type and then checking the tag against a list. `Support\NodeType` holds that vocabulary in one place, so the mapping from tag to table and to GraphQL type exists once.

### D2 — `ftp_users` has no integer primary key

`ftp_users`' primary key is `userid varchar(255)` — `user@domain`. Plan 1's `GlobalId::getId(): int` cannot carry it. This is the one place plan 1's interface is insufficient for phase 2.

**Decision:** extend `GlobalId` **additively** in Task 2. `encode()`, `decode()`, `getType()` and `getId()` keep their exact current signatures and behaviour, and every plan 1 test keeps passing unchanged. Three members are added: `encodeKey(string $type, string $key): string`, `decodeKey(string $encoded, ?string $expectedType = null): self` and `getKey(): string`. `getId()` continues to reject a non-numeric key, so a caller that expects an integer still cannot be handed a string one by accident.

### D3 — Storage figures are bytes, everywhere

i-MSCP mixes units: `domain.domain_disk_limit` and `domain.domain_traffic_limit` are in **MiB** (`gui/public/client/index.php:336-337` multiplies both by 1048576), while `domain_disk_usage`, `domain_disk_file`, `domain_disk_mail`, `domain_disk_sql`, `domain.mail_quota` and `mail_users.quota` are in **bytes**. Spec §7.4 types all of them `BigInt` and says nothing about units.

**Decision:** every `BigInt` in the schema is a count of **bytes**. Limits are multiplied by 1048576 on read, in exactly one place, and the SDL says so in each field's description. A client that compares `diskUsed` to `diskLimit` must not be off by a factor of a million; that is precisely the class of mistake `Quota.remaining` exists to prevent, and the same care is owed here.

### D4 — `CustomerFeatures` is transcribed, not called

Spec §7.5 says `features` is `customerHasFeature()`'s answer "verbatim and per name". But `customerHasFeature()` reads `$_SESSION['user_id']` and caches its answer in a `static` that is not keyed by user (`gui/include/Client.php:84`) — so a reseller reading three customers' features would get the first customer's answers for all three. Spec §6.4's one-identity-per-request rule makes it unusable for anything but the viewer's own account.

**Decision:** transcribe the expression from `Client.php:82-135` into `Support\CustomerFeatures`, taking an explicit domain-properties row, with a `CORE-DEBT(C1)` marker citing the line it came from. When spec §21's C1 lands — an explicit `$adminId` and a cache keyed by it — the transcription is deleted and the core function called. This is the same trade §6.4 already made, applied one level out.

### D5 — Counting is batched, and that adds C7 to the core backlog

Spec §7.4's `Quota.used` values come from `gui/include/Counting.php` (spec §3.2: "used directly, pure counting, no session, no exits"). But those functions take one customer at a time, so `Query.customers { nodes { quotas { … } } }` would be six queries per customer — the N+1 that spec §10.1 calls a defect.

**Decision:** `Repository\Counts` carries batched forms of the six customer counting queries, keyed by domain id, transcribed line for line from `Counting.php` with `CORE-DEBT(C7)` markers. Task 4 adds **C7 — batched counting functions** to spec §21, because the panel has the identical N+1 today: `gui/public/reseller/user_statistics.php:87` loops over every customer of a reseller calling `getClientItemCountsAndLimits()`, which is eight queries a row. `test/lint/all.sh` fails on a `CORE-DEBT` marker naming an item that does not exist in §21, so the spec edit is part of that task and not optional.

---

## Anorm: what was measured, and what this plan does about it

Spec §3.3 chose Anorm for the read side on the strength of `Relationship\BatchLoadingOrchestrator::loadRelationshipsForModels()` taking field-selection specs shaped like a GraphQL `ResolveInfo`. Reading `/home/cambell/src/sgw/anorm/src` at v3.1.1 before writing this plan turned up four facts that change how it must be used, and writing the resolvers turned up a fifth. None of them reverses the decision; all of them constrain it, and Task 10 is the task that encodes the constraints.

1. **`Strategy\FieldSelectionParser` and `Strategy\DataSizeEstimator` call `str_contains()`, `str_starts_with()` and `str_ends_with()`** — PHP 8.0 functions. They lint clean on 7.4, because a call to an undefined function is a run-time error, not a syntax error. Spec Appendix A measured `php7.4 -l` over Anorm's 31 files and recorded "0 failures", which is true and does not cover this. Any code path reaching those two classes is a fatal error on the panel's PHP.
2. **`Strategy\QueryStrategySelector` returns `STRATEGY_INDIVIDUAL_LOADING` when the source count is 10 or fewer** (`individual_loading_threshold => 10`). A customer has one domain; a reseller has tens of customers. The default configuration therefore chooses the N+1 for exactly the sizes this API sees.
3. **`Strategy\JoinWithSelectionLoader::getTableName()` returns the literal string `'users'` for the source side** and guesses the related table by string substitution — it is a stub, marked "simplified implementation". `QueryStrategySelector` will select it whenever a field selection is supplied and the estimator likes the numbers.
4. **`OneHasMany::batchLoad()` constructs its own `new QueryStrategySelector()` with default configuration**, ignoring whatever the orchestrator was configured with. So configuring the orchestrator cannot fix (2) or (3) for one-to-many edges.

A fifth fact, found while writing the resolvers rather than while reading the
library, decides how far Anorm reaches into the read path:

5. **`Anorm\Model::__construct(\PDO $pdo, DataMapper $mapper)` calls
   `$pdo->setAttribute()` in its first statement.** A model cannot be
   constructed without a live connection. So any function that takes a model as
   a parameter cannot be unit-tested without a database, and every `shape()`
   function in Tasks 12–16 is a pure function the unit suite tests exhaustively
   with array literals. Model-typed shapes would move all of that into the
   integration suite.

**What this plan does, and where Anorm earns its place.** It selects the
strategy itself: `Repository\BatchLoader` calls
`Relationship\BatchLoader\OneHasManyBatchLoader` and `ManyHasOneBatchLoader`
**directly**, and never touches `BatchLoadingOrchestrator`,
`QueryStrategySelector`, `FieldSelectionParser`, `DataSizeEstimator` or
`JoinWithSelectionLoader`. Task 10 has a test that asserts the untouched
classes stay untouched.

Beyond that containment, Anorm is used where it pays and not where it does not.
The honest division, arrived at by writing every resolver first:

| Where | Anorm? | Why |
| --- | --- | --- |
| **Phase 3's writes** | **Yes, and this is the case for the dependency.** | A mutation already has a PDO, and `Model` + `DataMapper` give insert, update and delete over declared properties with no hand-written SQL. Fact 5 does not bite: a write resolver is integration-tested by definition. |
| **Developer experience** | **Yes.** | Task 9's thirteen models are the IDE's map of i-MSCP's column names. `const COLUMNS` and the declared properties give VS Code completion and make a column rename a rename rather than a grep for a string literal, across a schema whose names include `domain_aliasses`, `dtraff_pop` and `subdomain_alias_wildcard_alias`. That is worth having even where the read path does not instantiate them. |
| **A read of one table by one column** | **Yes** — `BatchLoader::byColumn()`. | Task 12's `domainRow()` is the caller: `byColumn(DomainModel::class, 'domain_id', $id)` batches exactly as a hand-written IN clause would, and hands back a `DomainModel` so the resolver reads `$domain->domain_created`. |
| **Every other read edge** | **No.** | Not one of them is a foreign key over `SELECT *`. `Domain.subdomains` is a four-table normalised union; `Customer.quotas` is six aggregates; `Reseller.storage` is a sum; `Customer.mailAccounts` is filtered and paged; `SqlDatabase.customer` needs a join to carry the owner. Both IN-clause loaders set `$selectClause = '*'` unconditionally, and neither expresses a filter, a join or an aggregate. |
| **`related()` and `parent()` from a resolver** | **No, structurally.** | Both take a `Model` instance, and a resolver's source is a shaped **array** — the schema-first design of §3.4 has no object per type. Getting a model in order to ask it for its edge would cost a query to fetch the model and a second to fetch the edge, where `keyed()` does it in one. |

The batching pattern is then graphql-php's own: `GraphQL\Deferred` accumulates
keys across a level and `Repository\BatchLoader` flushes one IN-clause query,
which is what spec §10.1 describes in its own words. `Deferred` supplies the
timing; the query comes from Anorm where the edge is a plain column read, and
from this plugin where it is not.

**This answers spec §20's open question 2**, which put the decision point at
the start of phase 2 — and the answer is narrower than §3.3 assumed when it
chose the dependency. Anorm stays, for the models, for the DX those models
give, for `byColumn()`, and above all for phase 3's writes. Its strategy layer
is unused and contained by a test. Its field-selection story, which is the
thing §3.3 actually chose it for, does not exist in v3.1.1 — both IN-clause
loaders set `$selectClause = '*'` with the comment "field selection
optimization will be implemented in Phase 3" — so no part of this plan depends
on it. **If phase 3's mutations do not use the models either, the right move is
to drop the dependency then**, not to keep finding uses for it.

---

## Model assignment

Each task names the model to run it under. The principle is plan 1's, unchanged: **ambiguity and blast radius, not line count**. This plan writes out every test and every implementation, so a task whose inputs and outputs are fully pinned down is transcription, and a small model does transcription reliably.

| Model | Used for | Tasks |
| --- | --- | --- |
| `haiku` | Pure functions over values, with the tests written out and no i-MSCP API surface: identifier codec, quota arithmetic, feature expression, mail-type table. | 2, 4, 5, 6 |
| `sonnet` | Anything touching i-MSCP conventions, SQL against tables it must not get wrong, the SDL, multi-file resolver wiring, or an authorisation decision. The default. | 1, 3, 7, 8, 9, 11, 12, 13, 14, 15, 16 |
| `opus` | Tasks whose success depends on a live system behaving as measured rather than on following this plan. | 10, 17 |

**Where phase 2 differs from phase 1.** Plan 1's two opus tasks were both about the box — a PHP version change and an end-to-end smoke test. Phase 2 touches the box far less, so opus is spent differently: Task 10 is opus because its correctness depends on a *vendored library* behaving as measured, and the four findings above are precisely the kind of thing that is wrong in a plan and right in the source. Task 17 is opus for plan 1's original reason — it measures a running system and its threshold is a claim about reality.

Task 1 is `sonnet` rather than `opus` despite being the most security-critical task in the plan, because its inputs are completely determined: the ownership chain is drawn in spec §2.2, every query is written out in the task, and the tests enumerate the whole caller matrix. It is careful transcription, not discovery.

---

## Execution mandate

Every task runs in a **fresh subagent** via `superpowers:subagent-driven-development`, at the model named in the task heading. Do not execute tasks inline in the orchestrating session. Between tasks the orchestrator reviews the diff against the task's **Interfaces** block before dispatching the next.

Dispatch each subagent with: the task text, the [Global Constraints](#global-constraints) section, the [Interfaces inherited from plan 1](#interfaces-inherited-from-plan-1) section, and the file path of the spec. A subagent sees only its own task, which is why every task carries an Interfaces block naming the exact signatures its neighbours rely on.

Tasks run in order. Task 1 must land before any resolver task, and Tasks 8–10 before any of Tasks 12–16.

---

## File Structure

Created or modified by this plan.

| File | Responsibility |
| --- | --- |
| `Security/OwnershipResolver.php` | Resolve any `GlobalId` to its owning customer; answer whether a caller may reach it (spec §6.3) |
| `Support/NodeType.php` | The identifier-tag vocabulary: tag → table, tag → GraphQL type (decision D1) |
| `Support/GlobalId.php` | **Modified** — additive string-key support (decision D2) |
| `Support/Quota.php` | i-MSCP's three-valued limit columns normalised into spec §7.4's `Quota` |
| `Support/CustomerFeatures.php` | `customerHasFeature()` transcribed against an explicit domain row (decision D4) |
| `Support/MailType.php` | `mail_users.mail_type` ↔ (`host kind`, `MailAccountKind`), both ways |
| `Support/PlanProps.php` | The 25-field `hosting_plans.props` codec (spec §7.8, §20 risk 4) |
| `Repository/Db.php` | The PDO handle Anorm and the plugin's own queries share, plus the query counter |
| `Repository/Counts.php` | Batched forms of `Counting.php`'s six customer counts (decision D5) |
| `Repository/BatchLoader.php` | `Deferred` + Anorm IN-clause loaders: one query per edge per level (spec §10.1) |
| `Repository/VirtualHosts.php` | The four-way `dmn`/`sub`/`als`/`alssub` union, and `Query.pending` |
| `Model/AdminModel.php` | `admin` |
| `Model/DomainModel.php` | `domain` |
| `Model/SubdomainModel.php` | `subdomain` |
| `Model/DomainAliasModel.php` | `domain_aliasses` |
| `Model/AliasSubdomainModel.php` | `subdomain_alias` |
| `Model/ServerIpModel.php` | `server_ips` |
| `Model/MailAccountModel.php` | `mail_users` |
| `Model/FtpUserModel.php` | `ftp_users` |
| `Model/SqlDatabaseModel.php` | `sql_database` |
| `Model/SqlUserModel.php` | `sql_user` |
| `Model/DnsRecordModel.php` | `domain_dns` |
| `Model/ResellerPropsModel.php` | `reseller_props` |
| `Model/HostingPlanModel.php` | `hosting_plans` |
| `Schema/SchemaFactory.php` | **Modified** — attach `resolveType` for the three interfaces |
| `schema/schema.graphql` | **Modified** — grown from the viewer slice to the full read schema of spec §7 |
| `Resolver/TypeResolver.php` | `__resolveType` for `Node`, `Provisioned`, `VirtualHost` |
| `Resolver/VirtualHostResolver.php` | `Domain`, `Subdomain`, `DomainAlias` |
| `Resolver/CustomerResolver.php` | `Customer`, `CustomerQuotas`, `Storage`, `ContactDetails` |
| `Resolver/MailResolver.php` | `MailAccount`, `Autoresponder` |
| `Resolver/FtpSqlResolver.php` | `FtpUser`, `SqlDatabase`, `SqlUser` |
| `Resolver/DnsResolver.php` | `DnsRecord`, `IpAddress` |
| `Resolver/ResellerResolver.php` | `Reseller`, `HostingPlan`, and `Viewer.customer`/`Viewer.reseller`/`Viewer.contact` |
| `Resolver/QueryResolver.php` | `Query.node`, `customer`, `customers`, `reseller`, `resellers`, `pending` |
| `Api/Container.php` | **Modified** — build the read stack and merge every resolver map |
| `docs/SPECIFICATION.md` | **Modified** — §21 gains C7 (decision D5) |
| `test/phpunit.xml` | **Modified** — an `integration` suite alongside `unit` |
| `test/integration/bootstrap.php` | Bootstraps the panel so integration tests can reach the database |
| `test/integration/Fixture.php` | Seeds a reseller, customers and their whole graph inside a rolled-back transaction |
| `test/unit/…`, `test/schema/…`, `test/integration/…` | The tests, per task |

---

## Task 1: Ownership resolution — `sonnet`

Spec §6.3, and the chain in §2.2. This is the task plan 1's self-review named: *"`OwnershipResolver` is Task 1 of plan 2, and no mutation may ship before it."* Every read resolver in this plan goes through it, and every write resolver in plan 3 will.

It carries two pieces of scaffolding it cannot exist without: `Support\NodeType`, the identifier-tag vocabulary (decision D1), and the additive string-key support in `Support\GlobalId` that `FtpUser` needs (decision D2).

**Files:**
- Create: `Support/NodeType.php`, `Security/OwnershipResolver.php`
- Modify: `Support/GlobalId.php`
- Create: `test/unit/Support/NodeTypeTest.php`, `test/unit/Security/OwnershipResolverTest.php`
- Modify: `test/unit/Support/GlobalIdTest.php` (add cases; change none)

**Interfaces:**
- Consumes: `GlobalId`, `Identity`, `ApiException`, `ErrorCode` (plan 1).
- Produces:
  - `GlobalId::encodeKey(string $type, string $key): string`
  - `GlobalId::decodeKey(string $encoded, ?string $expectedType = null): GlobalId`
  - `GlobalId::getKey(): string` — the raw id portion, integer or not
  - `NodeType::graphqlType(string $tag): string`, `NodeType::isKnown(string $tag): bool`,
    `NodeType::isStringKeyed(string $tag): bool`, `NodeType::subdomainTags(): array`
  - `NodeType` tag constants: `CUSTOMER DOMAIN SUBDOMAIN ALIAS_SUBDOMAIN DOMAIN_ALIAS MAIL_ACCOUNT FTP_USER SQL_DATABASE SQL_USER DNS_RECORD RESELLER HOSTING_PLAN IP_ADDRESS`
  - `new OwnershipResolver(callable $query)` where `$query(string $sql, array $bind): array` returns a list of associative rows
  - `OwnershipResolver::ownerOf(GlobalId $id): ?int` — the owning customer's `admin_id`
  - `OwnershipResolver::resellerOf(GlobalId $id): ?int`
  - `OwnershipResolver::mayReach(Identity $caller, int $ownerId): bool`
  - `OwnershipResolver::mayReachReseller(Identity $caller, int $resellerId): bool`
  - `OwnershipResolver::assertReachable(Identity $caller, GlobalId $id): int` — throws `ApiException(NOT_FOUND)`
  - `OwnershipResolver::resellerIdOf(int $customerAdminId): ?int`

- [ ] **Step 1: Write the failing `NodeType` test**

`test/unit/Support/NodeTypeTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NodeTypeTest extends TestCase
{
    public function testAnAliasSubdomainHasItsOwnTagButIsASubdomainToClients(): void
    {
        // subdomain_id = 3 and subdomain_alias_id = 3 both exist and belong to
        // different customers, so they cannot share an identifier tag. They do
        // share a GraphQL type, because the storage split is not the client's
        // business.
        self::assertSame('Subdomain', NodeType::graphqlType(NodeType::ALIAS_SUBDOMAIN));
        self::assertSame('Subdomain', NodeType::graphqlType(NodeType::SUBDOMAIN));
        self::assertNotSame(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN);
    }

    public function testEveryOtherTagIsItsOwnGraphqlType(): void
    {
        foreach (array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::DOMAIN_ALIAS,
            NodeType::MAIL_ACCOUNT, NodeType::FTP_USER, NodeType::SQL_DATABASE,
            NodeType::SQL_USER, NodeType::DNS_RECORD, NodeType::RESELLER,
            NodeType::HOSTING_PLAN, NodeType::IP_ADDRESS
        ) as $tag) {
            self::assertSame($tag, NodeType::graphqlType($tag), $tag);
        }
    }

    public function testOnlyFtpUsersAreStringKeyed(): void
    {
        // ftp_users' primary key is userid varchar(255) - 'user@domain'.
        self::assertTrue(NodeType::isStringKeyed(NodeType::FTP_USER));
        self::assertFalse(NodeType::isStringKeyed(NodeType::MAIL_ACCOUNT));
    }

    public function testSubdomainTagsCoverBothTables(): void
    {
        self::assertSame(
            array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN),
            NodeType::subdomainTags()
        );
    }

    public function testAnUnknownTagIsRejected(): void
    {
        self::assertFalse(NodeType::isKnown('Htaccess'));

        $this->expectException(InvalidArgumentException::class);
        NodeType::graphqlType('Htaccess');
    }
}
```

- [ ] **Step 2: Write the failing `GlobalId` additions**

Append to `test/unit/Support/GlobalIdTest.php`. Change nothing that is already there — the point of the change is that it is additive.

```php
    public function testAStringKeyRoundTrips(): void
    {
        // ftp_users has no integer primary key: it is userid varchar(255).
        $encoded = GlobalId::encodeKey('FtpUser', 'shop@example.com');
        $decoded = GlobalId::decodeKey($encoded);

        self::assertSame('FtpUser', $decoded->getType());
        self::assertSame('shop@example.com', $decoded->getKey());
    }

    public function testGetKeyWorksForAnIntegerIdentifierToo(): void
    {
        self::assertSame('3', GlobalId::decode(GlobalId::encode('Subdomain', 3))->getKey());
    }

    public function testGetIdStillRefusesANonNumericKey(): void
    {
        // A caller that expects an integer must not be handed a string one.
        $decoded = GlobalId::decodeKey(GlobalId::encodeKey('FtpUser', 'shop@example.com'));

        $this->expectException(InvalidArgumentException::class);
        $decoded->getId();
    }

    public function testDecodeKeyEnforcesTheExpectedType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::decodeKey(GlobalId::encodeKey('FtpUser', 'a@b.c'), 'MailAccount');
    }

    public function testAKeyMayNotContainTheSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::encodeKey('FtpUser', 'a:b');
    }

    public function testAnEmptyKeyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::encodeKey('FtpUser', '');
    }
```

- [ ] **Step 3: Write the failing `OwnershipResolver` test**

`test/unit/Security/OwnershipResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Security;

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OwnershipResolverTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private $log = array();

    /** @var array<string, array> */
    private $rows = array();

    protected function setUp(): void
    {
        $this->log = array();

        // Keyed by a distinctive fragment of the query. The fixture is one
        // reseller (5) with two customers (7 and 8), and customer 7 owns
        // everything below.
        $this->rows = array(
            'FROM admin WHERE admin_id = ? AND admin_type'  => array(array('owner_id' => 7)),
            'FROM domain WHERE domain_id'                    => array(array('owner_id' => 7)),
            'FROM subdomain AS s'                            => array(array('owner_id' => 7)),
            'FROM subdomain_alias AS sa'                     => array(array('owner_id' => 7)),
            'FROM domain_aliasses AS a'                      => array(array('owner_id' => 7)),
            'FROM mail_users AS m'                           => array(array('owner_id' => 7)),
            'FROM ftp_users AS f'                            => array(array('owner_id' => 7)),
            'FROM sql_database AS sd'                        => array(array('owner_id' => 7)),
            'FROM sql_user AS su'                            => array(array('owner_id' => 7)),
            'FROM domain_dns AS dd'                          => array(array('owner_id' => 7)),
            'FROM hosting_plans'                             => array(array('owner_id' => 5)),
            'SELECT created_by'                              => array(array('created_by' => 5))
        );
    }

    private function resolver(): OwnershipResolver
    {
        return new OwnershipResolver(function (string $sql, array $bind) {
            $this->log[] = array($sql, $bind);

            foreach ($this->rows as $needle => $result) {
                if (strpos($sql, $needle) !== false) {
                    return $result;
                }
            }

            return array();
        });
    }

    private function identity(int $adminId, string $type, ?int $createdBy): Identity
    {
        return new Identity($adminId, 'account' . $adminId, $type, $createdBy, null, array(), null);
    }

    private function owner(): Identity      { return $this->identity(7, 'user', 5); }
    private function sibling(): Identity    { return $this->identity(8, 'user', 5); }
    private function reseller(): Identity   { return $this->identity(5, 'reseller', 1); }
    private function stranger(): Identity   { return $this->identity(6, 'reseller', 1); }
    private function admin(): Identity      { return $this->identity(1, 'admin', null); }

    public function testADomainResolvesToItsCustomer(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::DOMAIN, 12));

        self::assertSame(7, $this->resolver()->ownerOf($id));
        self::assertCount(1, $this->log, 'one query per object type, per spec section 6.3');
        self::assertSame(array(12), $this->log[0][1]);
    }

    /**
     * @dataProvider customerOwnedIdentifiers
     */
    public function testEveryCustomerOwnedTypeResolvesToItsCustomer(string $tag): void
    {
        $id = GlobalId::decode(GlobalId::encode($tag, 3));

        self::assertSame(7, $this->resolver()->ownerOf($id), $tag);
    }

    public function customerOwnedIdentifiers(): array
    {
        return array(
            'customer'        => array(NodeType::CUSTOMER),
            'domain'          => array(NodeType::DOMAIN),
            'subdomain'       => array(NodeType::SUBDOMAIN),
            'alias subdomain' => array(NodeType::ALIAS_SUBDOMAIN),
            'domain alias'    => array(NodeType::DOMAIN_ALIAS),
            'mail account'    => array(NodeType::MAIL_ACCOUNT),
            'sql database'    => array(NodeType::SQL_DATABASE),
            'sql user'        => array(NodeType::SQL_USER),
            'dns record'      => array(NodeType::DNS_RECORD)
        );
    }

    public function testAnFtpUserResolvesFromItsStringKey(): void
    {
        $id = GlobalId::decodeKey(GlobalId::encodeKey(NodeType::FTP_USER, 'shop@example.com'));

        self::assertSame(7, $this->resolver()->ownerOf($id));
        self::assertSame(array('shop@example.com'), $this->log[0][1]);
    }

    public function testAMissingRowHasNoOwner(): void
    {
        $this->rows = array();

        self::assertNull($this->resolver()->ownerOf(
            GlobalId::decode(GlobalId::encode(NodeType::DOMAIN, 12))
        ));
    }

    public function testTheAnswerIsMemoisedWithinOneRequest(): void
    {
        // A deep document resolves the same parent many times; asking the
        // database once per object per request is the whole point.
        $resolver = $this->resolver();
        $id = GlobalId::decode(GlobalId::encode(NodeType::DOMAIN, 12));

        $resolver->ownerOf($id);
        $resolver->ownerOf($id);

        self::assertCount(1, $this->log);
    }

    public function testTheOwnerMayReachTheirOwnObject(): void
    {
        self::assertTrue($this->resolver()->mayReach($this->owner(), 7));
    }

    public function testTheOwningResellerMayReachIt(): void
    {
        self::assertTrue($this->resolver()->mayReach($this->reseller(), 7));
    }

    public function testAnotherResellerMayNot(): void
    {
        self::assertFalse($this->resolver()->mayReach($this->stranger(), 7));
    }

    public function testAnotherCustomerOfTheSameResellerMayNot(): void
    {
        self::assertFalse($this->resolver()->mayReach($this->sibling(), 7));
    }

    public function testAnAdministratorMayReachAnything(): void
    {
        self::assertTrue($this->resolver()->mayReach($this->admin(), 7));
        self::assertCount(0, $this->log, 'an administrator needs no lookup');
    }

    public function testAssertReachableReturnsTheOwner(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::SUBDOMAIN, 3));

        self::assertSame(7, $this->resolver()->assertReachable($this->owner(), $id));
    }

    public function testAnUnreachableObjectIsNotFoundNotForbidden(): void
    {
        // Spec section 6.3: the API must not confirm the existence of other
        // people's objects. FORBIDDEN is reserved for objects the caller can
        // see but may not change.
        $id = GlobalId::decode(GlobalId::encode(NodeType::SUBDOMAIN, 3));

        try {
            $this->resolver()->assertReachable($this->sibling(), $id);
            self::fail('an unreachable object must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAMissingObjectIsAlsoNotFound(): void
    {
        $this->rows = array();
        $id = GlobalId::decode(GlobalId::encode(NodeType::SUBDOMAIN, 3));

        try {
            $this->resolver()->assertReachable($this->owner(), $id);
            self::fail('a missing object must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAHostingPlanIsReachedThroughItsReseller(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::HOSTING_PLAN, 2));

        self::assertSame(5, $this->resolver()->resellerOf($id));
        self::assertSame(5, $this->resolver()->assertReachable($this->reseller(), $id));
    }

    public function testACustomerMayNotReachAHostingPlan(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::HOSTING_PLAN, 2));

        $this->expectException(ApiException::class);
        $this->resolver()->assertReachable($this->owner(), $id);
    }

    public function testAResellerMayReachAServerIpAddressAndACustomerMayNot(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::IP_ADDRESS, 1));

        self::assertSame(0, $this->resolver()->assertReachable($this->reseller(), $id));
        self::assertSame(0, $this->resolver()->assertReachable($this->admin(), $id));

        $this->expectException(ApiException::class);
        $this->resolver()->assertReachable($this->owner(), $id);
    }

    public function testAnUnknownTagIsRejectedBeforeAnyQuery(): void
    {
        $encoded = GlobalId::encode('Htaccess', 1);

        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->ownerOf(GlobalId::decode($encoded));
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

```bash
tools/test.sh
```

Expected: FAIL — `NodeType` and `OwnershipResolver` not found, and the six new `GlobalIdTest` methods fail on `encodeKey()`.

- [ ] **Step 5: Write `Support/NodeType.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use InvalidArgumentException;

/**
 * The identifier-tag vocabulary.
 *
 * A tag is what goes inside a GlobalId. It is usually the same string as the
 * GraphQL type, and there is exactly one place where it is not: an alias
 * subdomain. Spec section 7.3 makes it a Subdomain whose parent is a
 * DomainAlias, which is right for a client, but `subdomain` and
 * `subdomain_alias` are separate tables with separate id spaces, so
 * subdomain_alias 3 and subdomain 3 must not share an identifier.
 */
final class NodeType
{
    const CUSTOMER        = 'Customer';
    const DOMAIN          = 'Domain';
    const SUBDOMAIN       = 'Subdomain';
    const ALIAS_SUBDOMAIN = 'AliasSubdomain';
    const DOMAIN_ALIAS    = 'DomainAlias';
    const MAIL_ACCOUNT    = 'MailAccount';
    const FTP_USER        = 'FtpUser';
    const SQL_DATABASE    = 'SqlDatabase';
    const SQL_USER        = 'SqlUser';
    const DNS_RECORD      = 'DnsRecord';
    const RESELLER        = 'Reseller';
    const HOSTING_PLAN    = 'HostingPlan';
    const IP_ADDRESS      = 'IpAddress';

    /** Tags that resolve to an owning customer's admin_id. */
    const CUSTOMER_OWNED = array(
        self::CUSTOMER, self::DOMAIN, self::SUBDOMAIN, self::ALIAS_SUBDOMAIN,
        self::DOMAIN_ALIAS, self::MAIL_ACCOUNT, self::FTP_USER,
        self::SQL_DATABASE, self::SQL_USER, self::DNS_RECORD
    );

    /** Tags that resolve to an owning reseller's admin_id. */
    const RESELLER_OWNED = array(self::RESELLER, self::HOSTING_PLAN);

    /** Tags that belong to the server rather than to any account. */
    const SERVER_OWNED = array(self::IP_ADDRESS);

    /** Tags whose primary key is not an integer. */
    const STRING_KEYED = array(self::FTP_USER);

    /** Tag => GraphQL type, for the tags where the two differ. */
    const GRAPHQL_TYPE = array(self::ALIAS_SUBDOMAIN => self::SUBDOMAIN);

    public static function isKnown(string $tag): bool
    {
        return in_array($tag, self::CUSTOMER_OWNED, true)
            || in_array($tag, self::RESELLER_OWNED, true)
            || in_array($tag, self::SERVER_OWNED, true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function graphqlType(string $tag): string
    {
        if (!self::isKnown($tag)) {
            throw new InvalidArgumentException(sprintf('Unknown node type "%s".', $tag));
        }

        return self::GRAPHQL_TYPE[$tag] ?? $tag;
    }

    public static function isStringKeyed(string $tag): bool
    {
        return in_array($tag, self::STRING_KEYED, true);
    }

    /**
     * Both tables a Subdomain can come from. A resolver that takes a subdomain
     * identifier accepts either.
     *
     * @return string[]
     */
    public static function subdomainTags(): array
    {
        return array(self::SUBDOMAIN, self::ALIAS_SUBDOMAIN);
    }
}
```

- [ ] **Step 6: Extend `Support/GlobalId.php`**

Three changes, all additive. Keep `encode()`, `decode()`, `getType()` and `getId()` behaving exactly as they do.

Replace the constructor and the two accessors:

```php
    /** @var string */
    private $type;

    /** @var string The raw key, which is not always an integer. */
    private $key;

    private function __construct(string $type, string $key)
    {
        $this->type = $type;
        $this->key = $key;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * The raw identifier portion, integer or not.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @throws InvalidArgumentException when the key is not a positive integer
     */
    public function getId(): int
    {
        if (!preg_match('/^[1-9][0-9]*$/', $this->key)) {
            throw new InvalidArgumentException(sprintf(
                'The identifier for %s is not an integer.', $this->type
            ));
        }

        return (int)$this->key;
    }
```

Add the two string-key methods, and make the integer ones delegate:

```php
    /**
     * @throws InvalidArgumentException
     */
    public static function encode(string $type, int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException(
                'A global identifier wraps a positive row identifier.'
            );
        }

        return self::encodeKey($type, (string)$id);
    }

    /**
     * Encode an identifier whose primary key is not an integer.
     *
     * ftp_users is the only such table in i-MSCP: its primary key is
     * userid varchar(255), which holds 'user@domain'.
     *
     * @throws InvalidArgumentException
     */
    public static function encodeKey(string $type, string $key): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type)) {
            throw new InvalidArgumentException(sprintf(
                'A global identifier type must be an alphanumeric name; got "%s".', $type
            ));
        }

        if ($key === '' || strpos($key, ':') !== false) {
            throw new InvalidArgumentException(
                'A global identifier key must be non-empty and must not contain ":".'
            );
        }

        return rtrim(strtr(base64_encode($type . ':' . $key), '+/', '-_'), '=');
    }

    /**
     * @param string|null $expectedType Reject any identifier of another type.
     * @throws InvalidArgumentException
     */
    public static function decode(string $encoded, ?string $expectedType = null): self
    {
        $decoded = self::decodeKey($encoded, $expectedType);

        // '03' and '3' must not both decode to 3, or one row would have two
        // identifiers and any cache keyed on the identifier would be wrong.
        if (!preg_match('/^[1-9][0-9]*$/', $decoded->getKey())) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        return $decoded;
    }

    /**
     * @param string|null $expectedType Reject any identifier of another type.
     * @throws InvalidArgumentException
     */
    public static function decodeKey(string $encoded, ?string $expectedType = null): self
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($payload === false || substr_count($payload, ':') !== 1) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        list($type, $key) = explode(':', $payload, 2);

        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type) || $key === '') {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        if ($expectedType !== null && $type !== $expectedType) {
            throw new InvalidArgumentException(sprintf(
                'Expected a %s identifier.', $expectedType
            ));
        }

        return new self($type, $key);
    }
```

- [ ] **Step 7: Write `Security/OwnershipResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Security;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;

/**
 * Resolves any global identifier to the account that owns it, and answers
 * whether a caller may reach it.
 *
 * Spec section 2.2 draws the chain; this class is one query per object type
 * derived from it, and spec section 6.3 requires it to be asked before
 * anything else - before feature checks, before quota checks, before
 * validation - so that the error a caller gets leaks the least.
 *
 * A caller who may not reach an object gets NOT_FOUND, never FORBIDDEN, so the
 * API does not confirm the existence of other people's objects.
 */
final class OwnershipResolver
{
    /**
     * One query per object type, each returning a single `owner_id` column
     * holding the owning customer's admin_id.
     */
    const OWNER_QUERIES = array(
        NodeType::CUSTOMER => "
            SELECT admin_id AS owner_id
            FROM admin WHERE admin_id = ? AND admin_type = 'user'
        ",
        NodeType::DOMAIN => '
            SELECT domain_admin_id AS owner_id
            FROM domain WHERE domain_id = ?
        ',
        NodeType::SUBDOMAIN => '
            SELECT d.domain_admin_id AS owner_id
            FROM subdomain AS s
            JOIN domain AS d ON d.domain_id = s.domain_id
            WHERE s.subdomain_id = ?
        ',
        NodeType::ALIAS_SUBDOMAIN => '
            SELECT d.domain_admin_id AS owner_id
            FROM subdomain_alias AS sa
            JOIN domain_aliasses AS a ON a.alias_id = sa.alias_id
            JOIN domain AS d ON d.domain_id = a.domain_id
            WHERE sa.subdomain_alias_id = ?
        ',
        NodeType::DOMAIN_ALIAS => '
            SELECT d.domain_admin_id AS owner_id
            FROM domain_aliasses AS a
            JOIN domain AS d ON d.domain_id = a.domain_id
            WHERE a.alias_id = ?
        ',
        NodeType::MAIL_ACCOUNT => '
            SELECT d.domain_admin_id AS owner_id
            FROM mail_users AS m
            JOIN domain AS d ON d.domain_id = m.domain_id
            WHERE m.mail_id = ?
        ',
        // ftp_users hangs off the customer, not off the domain. Spec section 2.2.
        NodeType::FTP_USER => '
            SELECT f.admin_id AS owner_id
            FROM ftp_users AS f
            WHERE f.userid = ?
        ',
        NodeType::SQL_DATABASE => '
            SELECT d.domain_admin_id AS owner_id
            FROM sql_database AS sd
            JOIN domain AS d ON d.domain_id = sd.domain_id
            WHERE sd.sqld_id = ?
        ',
        NodeType::SQL_USER => '
            SELECT d.domain_admin_id AS owner_id
            FROM sql_user AS su
            JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
            JOIN domain AS d ON d.domain_id = sd.domain_id
            WHERE su.sqlu_id = ?
        ',
        NodeType::DNS_RECORD => '
            SELECT d.domain_admin_id AS owner_id
            FROM domain_dns AS dd
            JOIN domain AS d ON d.domain_id = dd.domain_id
            WHERE dd.domain_dns_id = ?
        '
    );

    /** Reseller-owned objects, resolved one level up the same chain. */
    const RESELLER_QUERIES = array(
        NodeType::RESELLER => "
            SELECT admin_id AS owner_id
            FROM admin WHERE admin_id = ? AND admin_type = 'reseller'
        ",
        NodeType::HOSTING_PLAN => '
            SELECT reseller_id AS owner_id
            FROM hosting_plans WHERE id = ?
        '
    );

    /** @var callable fn(string $sql, array $bind): array<int, array<string, mixed>> */
    private $query;

    /** @var array<string, int|null> */
    private $owners = array();

    /** @var array<int, int|null> */
    private $resellers = array();

    public function __construct(callable $query)
    {
        $this->query = $query;
    }

    /**
     * The owning customer's admin_id, or null when there is no such object.
     *
     * @throws InvalidArgumentException for a type tag that is not customer-owned
     */
    public function ownerOf(GlobalId $id): ?int
    {
        $tag = $id->getType();

        if (!isset(self::OWNER_QUERIES[$tag])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a customer-owned type.', $tag
            ));
        }

        $memo = $tag . ':' . $id->getKey();

        if (!array_key_exists($memo, $this->owners)) {
            $rows = $this->run(self::OWNER_QUERIES[$tag], array($id->getKey()));
            $this->owners[$memo] = isset($rows[0]['owner_id'])
                ? (int)$rows[0]['owner_id'] : null;
        }

        return $this->owners[$memo];
    }

    /**
     * The owning reseller's admin_id, or null when there is no such object.
     *
     * @throws InvalidArgumentException for a type tag that is not reseller-owned
     */
    public function resellerOf(GlobalId $id): ?int
    {
        $tag = $id->getType();

        if (!isset(self::RESELLER_QUERIES[$tag])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a reseller-owned type.', $tag
            ));
        }

        $memo = $tag . ':' . $id->getKey();

        if (!array_key_exists($memo, $this->owners)) {
            $rows = $this->run(self::RESELLER_QUERIES[$tag], array($id->getKey()));
            $this->owners[$memo] = isset($rows[0]['owner_id'])
                ? (int)$rows[0]['owner_id'] : null;
        }

        return $this->owners[$memo];
    }

    /**
     * The reseller who created the given customer, or null.
     */
    public function resellerIdOf(int $customerAdminId): ?int
    {
        if (!array_key_exists($customerAdminId, $this->resellers)) {
            $rows = $this->run(
                'SELECT created_by FROM admin WHERE admin_id = ?',
                array($customerAdminId)
            );
            $this->resellers[$customerAdminId] = isset($rows[0]['created_by'])
                ? (int)$rows[0]['created_by'] : null;
        }

        return $this->resellers[$customerAdminId];
    }

    /**
     * True when the caller is the owner, the owner's reseller, or an
     * administrator. Spec section 6.3.
     */
    public function mayReach(Identity $caller, int $ownerId): bool
    {
        if ($caller->getRole() === Identity::ROLE_ADMIN) {
            return true;
        }

        if ($caller->getAdminId() === $ownerId) {
            return true;
        }

        if ($caller->getRole() === Identity::ROLE_RESELLER) {
            return $this->resellerIdOf($ownerId) === $caller->getAdminId();
        }

        return false;
    }

    /**
     * Reseller-owned objects: the reseller itself, or an administrator.
     */
    public function mayReachReseller(Identity $caller, int $resellerId): bool
    {
        return $caller->getRole() === Identity::ROLE_ADMIN
            || $caller->getAdminId() === $resellerId;
    }

    /**
     * Resolve and authorise in one call. Returns the owning account's admin_id
     * - 0 for a server-owned object, which has no owning account.
     *
     * @throws ApiException NOT_FOUND, never FORBIDDEN, for anything the caller
     *                      may not reach or that does not exist
     * @throws InvalidArgumentException for an unknown type tag
     */
    public function assertReachable(Identity $caller, GlobalId $id): int
    {
        $tag = $id->getType();

        if (in_array($tag, NodeType::SERVER_OWNED, true)) {
            // Server IP addresses belong to no account. A customer sees one
            // only as a field of their own domain, never by identifier.
            if ($caller->getRole() === Identity::ROLE_CUSTOMER) {
                throw self::notFound();
            }

            return 0;
        }

        if (in_array($tag, NodeType::RESELLER_OWNED, true)) {
            $ownerId = $this->resellerOf($id);

            if ($ownerId === null || !$this->mayReachReseller($caller, $ownerId)) {
                throw self::notFound();
            }

            return $ownerId;
        }

        $ownerId = $this->ownerOf($id);

        if ($ownerId === null || !$this->mayReach($caller, $ownerId)) {
            throw self::notFound();
        }

        return $ownerId;
    }

    private static function notFound(): ApiException
    {
        return new ApiException(ErrorCode::NOT_FOUND, 'No such object.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function run(string $sql, array $bind): array
    {
        return call_user_func($this->query, $sql, $bind);
    }
}
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
tools/test.sh
```

Expected: PASS. `NodeTypeTest` 5 methods, `OwnershipResolverTest` 17 methods (9 of them through the data provider), `GlobalIdTest` 15 methods.

- [ ] **Step 9: Commit**

```bash
git add Support/NodeType.php Support/GlobalId.php Security/OwnershipResolver.php \
        test/unit/Support/NodeTypeTest.php test/unit/Support/GlobalIdTest.php \
        test/unit/Security/OwnershipResolverTest.php
git commit -m "Resolve ownership before anything else

Every authorisation question in this API is a walk up the chain in
specification section 2.2, and doing it in one place is what keeps horizontal
privilege escalation to a single reviewable function. An object the caller
cannot reach answers NOT_FOUND rather than FORBIDDEN, so the API never
confirms that somebody else's object exists.

Alias subdomains get their own identifier tag while keeping the Subdomain
type, because subdomain and subdomain_alias have separate id spaces and a
shared tag would let one customer's identifier address another's row. FTP
users get a string-keyed identifier because ftp_users has no integer primary
key; the integer path is unchanged and still refuses a non-numeric key."
```

---

## Task 2: The database handle, the query counter and the integration suite — `sonnet`

Anorm needs a raw `\PDO`, and it must be the panel's own — same connection, same transaction (spec §3.2). This task provides it, provides the plugin's own small query helpers for the reads that are not model reads, provides the query counter that Task 17's N+1 test measures with, and adds the `integration` PHPUnit suite that every later database-backed test runs in.

The query counter is the panel's connection asking MySQL how many statements it has run: `SHOW SESSION STATUS LIKE 'Questions'`. That counts every statement on the connection — Anorm's, the plugin's and `exec_query()`'s alike — with no wrapper to keep in step with the library. The counter query costs a statement itself, so `countQueries()` calibrates that cost at run time rather than assuming it.

**Files:**
- Create: `Repository/Db.php`
- Modify: `test/phpunit.xml`
- Create: `test/integration/bootstrap.php`, `test/integration/DbTest.php`

**Interfaces:**
- Consumes: nothing beyond the panel.
- Produces:
  - `new Db(\PDO $pdo)`; `Db::fromPanel(): Db`
  - `Db::pdo(): \PDO`
  - `Db::rows(string $sql, array $bind = array()): array` — list of associative rows
  - `Db::row(string $sql, array $bind = array()): ?array`
  - `Db::value(string $sql, array $bind = array())` — the first column of the first row, or null
  - `Db::questions(): int`
  - `Db::countQueries(callable $fn): int`
  - `Db::placeholders(int $n): string` — `?,?,?`, for IN clauses
  - The class is **not** `final`, so tests may subclass it to fake `rows()`.

- [ ] **Step 1: Add the integration suite to `test/phpunit.xml`**

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
        <testsuite name="schema">
            <directory>schema</directory>
        </testsuite>
        <!--
            Needs the panel bootstrapped and a database. It is skipped rather
            than failed off the box, so the unit suite stays runnable anywhere.
        -->
        <testsuite name="integration">
            <directory>integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Write `test/integration/bootstrap.php`**

```php
<?php
// ... licence header ...

namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need the panel and its database.
 *
 * The panel is bootstrapped once per process. Off the box there is no panel,
 * so every such test is skipped rather than failed: the unit suite must stay
 * runnable on a machine that has only PHP.
 */
abstract class IntegrationTestCase extends TestCase
{
    const IMSCP_LIB = '/var/www/imscp/gui/include/imscp-lib.php';

    /** @var bool */
    private static $bootstrapped = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        if (!@is_readable(self::IMSCP_LIB)) {
            self::markTestSkipped('the panel is not installed on this machine');
        }

        require_once self::IMSCP_LIB;
        self::$bootstrapped = true;
    }
}
```

Note that this file declares a class and is loaded by the autoloader through the `iMSCP\Plugin\SGW_GraphQL\Test\Integration\` prefix that `composer.json`'s `autoload-dev` already maps onto `test/`; it is not referenced from `phpunit.xml`'s `bootstrap` attribute, which stays `bootstrap.php`. Rename the file to `test/integration/IntegrationTestCase.php` so PSR-4 finds it.

- [ ] **Step 3: Write the failing `Db` test**

`test/integration/DbTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;

class DbTest extends IntegrationTestCase
{
    private function db(): Db
    {
        return Db::fromPanel();
    }

    public function testItSharesThePanelsConnection(): void
    {
        // Same connection, same transaction: spec section 3.2. A second
        // connection would not see a mutation's uncommitted rows.
        self::assertSame(
            \iMSCP\Database\DatabaseMySQL::getPDO(),
            $this->db()->pdo()
        );
    }

    public function testRowsReturnsAListOfAssociativeRows(): void
    {
        $rows = $this->db()->rows(
            "SELECT admin_id, admin_name FROM admin WHERE admin_type = 'admin' LIMIT 2"
        );

        self::assertNotEmpty($rows, 'the box always has an administrator');
        self::assertArrayHasKey('admin_name', $rows[0]);
        self::assertArrayNotHasKey(0, $rows[0], 'associative, not numeric');
    }

    public function testRowReturnsNullWhenThereIsNoRow(): void
    {
        self::assertNull($this->db()->row('SELECT admin_id FROM admin WHERE admin_id = ?', array(-1)));
    }

    public function testValueReturnsTheFirstColumn(): void
    {
        self::assertSame('1', (string)$this->db()->value('SELECT 1'));
    }

    public function testValueReturnsNullWhenThereIsNoRow(): void
    {
        self::assertNull($this->db()->value('SELECT admin_id FROM admin WHERE admin_id = ?', array(-1)));
    }

    public function testPlaceholdersBuildsAnInClause(): void
    {
        self::assertSame('?,?,?', $this->db()->placeholders(3));
        self::assertSame('?', $this->db()->placeholders(1));
    }

    public function testCountQueriesCountsExactlyTheStatementsRun(): void
    {
        $db = $this->db();

        $count = $db->countQueries(function () use ($db) {
            $db->value('SELECT 1');
            $db->value('SELECT 2');
            $db->value('SELECT 3');
        });

        self::assertSame(3, $count);
    }

    public function testCountQueriesReturnsZeroForNoQueries(): void
    {
        self::assertSame(0, $this->db()->countQueries(function () {
        }));
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Repository\Db` not found.

- [ ] **Step 5: Write `Repository/Db.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;
// ... licence header ...

use iMSCP\Database\DatabaseMySQL;
use PDO;

/**
 * The panel's own PDO handle, and the small query helpers the read side needs
 * for the things that are not model reads.
 *
 * It is the panel's connection, not a second one: spec section 3.2 requires
 * the same connection and the same transaction, and a read on another
 * connection would not see a mutation's uncommitted rows.
 *
 * Not final, so that a unit test can subclass it and fake rows().
 */
class Db
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function fromPanel(): self
    {
        return new self(DatabaseMySQL::getPDO());
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sql, array $bind = array()): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($bind));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $bind = array()): ?array
    {
        $rows = $this->rows($sql, $bind);

        return $rows === array() ? null : $rows[0];
    }

    /**
     * @return mixed|null
     */
    public function value(string $sql, array $bind = array())
    {
        $row = $this->row($sql, $bind);

        if ($row === null) {
            return null;
        }

        $values = array_values($row);

        return $values === array() ? null : $values[0];
    }

    /**
     * A run of bound placeholders for an IN clause.
     */
    public function placeholders(int $n): string
    {
        return $n < 1 ? '' : rtrim(str_repeat('?,', $n), ',');
    }

    /**
     * The number of statements this connection has sent to the server.
     *
     * MySQL counts them for us, which means the counter cannot drift out of
     * step with a library the way a hand-written wrapper would: Anorm's
     * queries, the plugin's own and exec_query()'s are all on this connection
     * and all counted.
     */
    public function questions(): int
    {
        $row = $this->row("SHOW SESSION STATUS LIKE 'Questions'");

        return $row === null ? 0 : (int)$row['Value'];
    }

    /**
     * The number of statements $fn causes.
     *
     * questions() costs a statement itself, so its cost is measured here
     * rather than assumed - the exact overhead depends on the server version
     * and is not worth encoding as a magic number.
     */
    public function countQueries(callable $fn): int
    {
        $probe = $this->questions();
        $overhead = $this->questions() - $probe;

        $before = $this->questions();
        $fn();

        return $this->questions() - $before - $overhead;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
tools/test.sh
```

Expected: PASS, `DbTest` 8 methods.

- [ ] **Step 7: Commit**

```bash
git add Repository/Db.php test/phpunit.xml test/integration/IntegrationTestCase.php \
        test/integration/DbTest.php
git commit -m "Share the panel's connection, and let a test count its queries

The read side runs on the panel's own PDO handle rather than a connection of
its own, because a second connection could not see a mutation's uncommitted
rows and would double the panel's connection budget for no gain.

Query counting asks MySQL rather than wrapping PDO, so it cannot fall out of
step with Anorm's internals; the counter's own cost is measured at run time
rather than assumed, since it varies by server version."
```

---

## Task 3: Quota normalisation — `haiku`

Spec §7.4 and the trap named in §2.3: on a customer, `-1` means *the feature is withheld*, `0` means *unlimited*, and `n > 0` means *n*. On a reseller (`reseller_props.max_*_cnt`), `0` means unlimited and there is no withheld state. One `Quota` type normalises both so that no client ever has to know this.

**Files:**
- Create: `Support/Quota.php`
- Create: `test/unit/Support/QuotaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Quota::fromCustomerLimit(int $limit, int $used): Quota`
  - `Quota::fromResellerLimit(int $limit, int $used): Quota`
  - `Quota::isEnabled(): bool`, `getLimit(): ?int`, `getUsed(): int`, `getRemaining(): ?int`
  - `Quota::toArray(): array` with keys `enabled`, `limit`, `used`, `remaining` — the shape a resolver returns for the SDL's `Quota` type

- [ ] **Step 1: Write the failing test**

`test/unit/Support/QuotaTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use PHPUnit\Framework\TestCase;

class QuotaTest extends TestCase
{
    public function testACustomerLimitOfMinusOneIsAWithheldFeature(): void
    {
        // Spec section 2.3: -1 on a customer means the feature is withheld,
        // which is not the same as a limit of zero and not the same as
        // unlimited. Getting this wrong grants or withholds a feature.
        $quota = Quota::fromCustomerLimit(-1, 0);

        self::assertFalse($quota->isEnabled());
        self::assertSame(0, $quota->getLimit());
        self::assertSame(0, $quota->getRemaining());
    }

    public function testACustomerLimitOfZeroIsUnlimited(): void
    {
        $quota = Quota::fromCustomerLimit(0, 17);

        self::assertTrue($quota->isEnabled());
        self::assertNull($quota->getLimit(), 'null means unlimited');
        self::assertSame(17, $quota->getUsed());
        self::assertNull($quota->getRemaining());
    }

    public function testAPositiveCustomerLimitIsItself(): void
    {
        $quota = Quota::fromCustomerLimit(10, 4);

        self::assertTrue($quota->isEnabled());
        self::assertSame(10, $quota->getLimit());
        self::assertSame(4, $quota->getUsed());
        self::assertSame(6, $quota->getRemaining());
    }

    public function testRemainingNeverGoesNegative(): void
    {
        // A limit lowered below current usage is ordinary in this panel.
        self::assertSame(0, Quota::fromCustomerLimit(2, 5)->getRemaining());
    }

    public function testAWithheldFeatureStillReportsWhatIsUsed(): void
    {
        // A feature can be withdrawn while objects created under it remain.
        $quota = Quota::fromCustomerLimit(-1, 3);

        self::assertFalse($quota->isEnabled());
        self::assertSame(3, $quota->getUsed());
    }

    public function testAResellerLimitOfZeroIsUnlimited(): void
    {
        $quota = Quota::fromResellerLimit(0, 42);

        self::assertTrue($quota->isEnabled());
        self::assertNull($quota->getLimit());
        self::assertSame(42, $quota->getUsed());
    }

    public function testAPositiveResellerLimitIsItself(): void
    {
        self::assertSame(25, Quota::fromResellerLimit(25, 3)->getLimit());
    }

    public function testANegativeResellerLimitIsTreatedAsWithheld(): void
    {
        // reseller_props has no withheld state, so a negative value is a data
        // fault. Withheld is the safe direction to fail in.
        self::assertFalse(Quota::fromResellerLimit(-1, 0)->isEnabled());
    }

    public function testToArrayIsTheShapeTheSchemaExpects(): void
    {
        self::assertSame(
            array('enabled' => true, 'limit' => 10, 'used' => 4, 'remaining' => 6),
            Quota::fromCustomerLimit(10, 4)->toArray()
        );

        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 4, 'remaining' => null),
            Quota::fromCustomerLimit(0, 4)->toArray()
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL, `Quota` not found.

- [ ] **Step 3: Write `Support/Quota.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

/**
 * One countable allowance, normalised.
 *
 * i-MSCP encodes a limit in three different ways depending on which table it
 * is in (spec section 2.3):
 *
 *   customer (domain.domain_*_limit)  -1 withheld,  0 unlimited,  n = n
 *   reseller (reseller_props.max_*)                 0 unlimited,  n = n
 *
 * Every client would otherwise have to know that, and any client that got it
 * wrong would either offer a feature the customer does not have or hide one
 * they do. Spec section 7.4 therefore has exactly one Quota type and this is
 * the only place the encoding is read.
 */
final class Quota
{
    /** @var bool */
    private $enabled;

    /** @var int|null Null means unlimited. */
    private $limit;

    /** @var int */
    private $used;

    private function __construct(bool $enabled, ?int $limit, int $used)
    {
        $this->enabled = $enabled;
        $this->limit = $limit;
        $this->used = $used;
    }

    public static function fromCustomerLimit(int $limit, int $used): self
    {
        if ($limit < 0) {
            // Withheld. Not unlimited, and not a limit of zero: the feature is
            // not available to this account at all.
            return new self(false, 0, $used);
        }

        return new self(true, $limit === 0 ? null : $limit, $used);
    }

    public static function fromResellerLimit(int $limit, int $used): self
    {
        if ($limit < 0) {
            // reseller_props has no withheld state, so this cannot occur in
            // sound data. Treating it as withheld is the safe direction.
            return new self(false, 0, $used);
        }

        return new self(true, $limit === 0 ? null : $limit, $used);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getUsed(): int
    {
        return $this->used;
    }

    /**
     * Null when unlimited. Never negative: a limit lowered below current usage
     * is ordinary in this panel, and a negative "remaining" reads as a bug to
     * every client that sees it.
     */
    public function getRemaining(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        return max(0, $this->limit - $this->used);
    }

    /**
     * @return array{enabled: bool, limit: int|null, used: int, remaining: int|null}
     */
    public function toArray(): array
    {
        return array(
            'enabled'   => $this->enabled,
            'limit'     => $this->limit,
            'used'      => $this->used,
            'remaining' => $this->getRemaining()
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `tools/test.sh`
Expected: PASS, 9 test methods.

- [ ] **Step 5: Commit**

```bash
git add Support/Quota.php test/unit/Support/QuotaTest.php
git commit -m "Normalise i-MSCP's three-valued limit columns

A customer's -1 means the feature is withheld, 0 means unlimited and n means
n; a reseller's 0 means unlimited and there is no withheld state. Every client
that had to know that would eventually get it wrong in the direction of
offering a feature the account does not have, so the encoding is read here and
nowhere else."
```

---

## Task 4: Customer features — `haiku`

Spec §7.5: `features` is `customerHasFeature()`'s answer, verbatim and per name, so the API cannot disagree with the panel about what a customer is allowed. Decision D4 explains why it is transcribed rather than called: the core function reads `$_SESSION['user_id']` and caches in a `static` that is not keyed by user (`gui/include/Client.php:84`), so a reseller reading three customers would get the first one's answers for all three.

Transcribe the expression exactly, including its operator precedence, from `gui/include/Client.php:82-135`.

**Files:**
- Create: `Support/CustomerFeatures.php`
- Create: `test/unit/Support/CustomerFeaturesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `CustomerFeatures::fromDomainRow(array $domain, array $config, bool $resellerSupportSystem): CustomerFeatures`
  - `CustomerFeatures::toArray(): array` with exactly the keys `php`, `phpEditor`, `cgi`, `customDns`, `externalMail`, `backup`, `ssl`, `webStats`, `supportSystem` — the shape a resolver returns for the SDL's `CustomerFeatures` type
  - `CustomerFeatures::has(string $name): bool`
  - `CustomerFeatures::CONFIG_KEYS` — the panel configuration keys `fromDomainRow()` reads

- [ ] **Step 1: Write the failing test**

`test/unit/Support/CustomerFeaturesTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\CustomerFeatures;
use PHPUnit\Framework\TestCase;

class CustomerFeaturesTest extends TestCase
{
    /**
     * A customer with everything switched on, and a server with everything
     * available.
     */
    private function domain(array $overrides = array()): array
    {
        return array_merge(array(
            'domain_php'                    => 'yes',
            'domain_cgi'                    => 'yes',
            'domain_dns'                    => 'yes',
            'domain_external_mail'          => 'yes',
            'allowbackup'                   => 'dmn|sql|mail',
            'phpini_perm_system'            => 'yes',
            'phpini_perm_allow_url_fopen'   => 'yes',
            'phpini_perm_display_errors'    => 'no',
            'phpini_perm_disable_functions' => 'no'
        ), $overrides);
    }

    private function config(array $overrides = array()): array
    {
        return array_merge(array(
            'NAMED_PACKAGE'          => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES' => 'AWStats',
            'BACKUP_DOMAINS'         => 'yes',
            'ENABLE_SSL'             => 1,
            'IMSCP_SUPPORT_SYSTEM'   => 1
        ), $overrides);
    }

    private function features(array $domain = array(), array $config = array(), bool $support = true): CustomerFeatures
    {
        return CustomerFeatures::fromDomainRow(
            $this->domain($domain), $this->config($config), $support
        );
    }

    public function testEverythingOnIsEverythingTrue(): void
    {
        self::assertSame(
            array(
                'php' => true, 'phpEditor' => true, 'cgi' => true,
                'customDns' => true, 'externalMail' => true, 'backup' => true,
                'ssl' => true, 'webStats' => true, 'supportSystem' => true
            ),
            $this->features()->toArray()
        );
    }

    public function testPhpAndCgiComeStraightFromTheDomainRow(): void
    {
        self::assertFalse($this->features(array('domain_php' => 'no'))->has('php'));
        self::assertFalse($this->features(array('domain_cgi' => 'no'))->has('cgi'));
    }

    public function testCustomDnsNeedsBothTheCustomerAndANameServer(): void
    {
        self::assertFalse($this->features(array('domain_dns' => 'no'))->has('customDns'));
        self::assertFalse(
            $this->features(array(), array('NAMED_PACKAGE' => 'Servers::noserver'))
                ->has('customDns')
        );
    }

    public function testBackupNeedsBothTheServerAndANonEmptyAllowBackup(): void
    {
        self::assertFalse($this->features(array('allowbackup' => ''))->has('backup'));
        self::assertFalse(
            $this->features(array(), array('BACKUP_DOMAINS' => 'no'))->has('backup')
        );
    }

    public function testWebStatsAndSslAreServerWide(): void
    {
        self::assertFalse(
            $this->features(array(), array('WEB_STATISTIC_PACKAGES' => 'no'))->has('webStats')
        );
        self::assertFalse($this->features(array(), array('ENABLE_SSL' => 0))->has('ssl'));
    }

    public function testSupportNeedsTheServerSwitchAndTheReseller(): void
    {
        self::assertFalse($this->features(array(), array(), false)->has('supportSystem'));
        self::assertFalse(
            $this->features(array(), array('IMSCP_SUPPORT_SYSTEM' => 0), true)
                ->has('supportSystem')
        );
    }

    /**
     * The php editor expression in gui/include/Client.php:99 is
     *   (system AND allow_url_fopen) OR display_errors OR disable_functions
     * because && binds tighter than ||. That precedence is transcribed rather
     * than corrected: the API must agree with the panel, bug for bug.
     *
     * @dataProvider phpEditorCases
     */
    public function testPhpEditorTranscribesCoresPrecedence(array $perms, bool $expected): void
    {
        self::assertSame($expected, $this->features($perms)->has('phpEditor'));
    }

    public function phpEditorCases(): array
    {
        return array(
            'system and fopen' => array(
                array('phpini_perm_system' => 'yes', 'phpini_perm_allow_url_fopen' => 'yes'),
                true
            ),
            'system alone is not enough' => array(
                array('phpini_perm_system' => 'yes', 'phpini_perm_allow_url_fopen' => 'no'),
                false
            ),
            'display errors alone is enough' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_display_errors' => 'yes'
                ),
                true
            ),
            'disable functions yes is enough' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_disable_functions' => 'yes'
                ),
                true
            ),
            'disable functions exec is enough' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_disable_functions' => 'exec'
                ),
                true
            ),
            'nothing' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_display_errors' => 'no',
                    'phpini_perm_disable_functions' => 'no'
                ),
                false
            )
        );
    }

    public function testAnUnknownFeatureNameIsFalseRatherThanAWarning(): void
    {
        self::assertFalse($this->features()->has('protectedAreas'));
    }

    public function testTheConfigKeysAreDeclared(): void
    {
        // The resolver reads exactly these out of the panel's registry, so the
        // list must not drift from what fromDomainRow() actually uses.
        self::assertSame(
            array(
                'NAMED_PACKAGE', 'WEB_STATISTIC_PACKAGES', 'BACKUP_DOMAINS',
                'ENABLE_SSL', 'IMSCP_SUPPORT_SYSTEM'
            ),
            CustomerFeatures::CONFIG_KEYS
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL, `CustomerFeatures` not found.

- [ ] **Step 3: Write `Support/CustomerFeatures.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

/**
 * What a customer is allowed, in the panel's own terms.
 *
 * CORE-DEBT(C1): transcribed from gui/include/Client.php:82-135
 *   (customerHasFeature()). That function reads $_SESSION['user_id'] and
 *   caches its answer in a static that is not keyed by user, so it can only
 *   ever answer for the account the request is acting as - which is no use to
 *   a reseller reading three customers in one document. Retire this copy when
 *   C1 threads an explicit $adminId through it and keys the cache by that.
 *
 * Spec section 7.5 requires this answer to be the panel's, verbatim and per
 * name, so that the API cannot disagree with the panel about what a customer
 * may do.
 */
final class CustomerFeatures
{
    /** Panel configuration keys this class reads. */
    const CONFIG_KEYS = array(
        'NAMED_PACKAGE', 'WEB_STATISTIC_PACKAGES', 'BACKUP_DOMAINS',
        'ENABLE_SSL', 'IMSCP_SUPPORT_SYSTEM'
    );

    /** @var array<string, bool> */
    private $features;

    private function __construct(array $features)
    {
        $this->features = $features;
    }

    /**
     * @param array $domain                 A row of the `domain` table
     * @param array $config                 The panel configuration, at least CONFIG_KEYS
     * @param bool  $resellerSupportSystem  reseller_props.support_system == 'yes'
     */
    public static function fromDomainRow(
        array $domain, array $config, bool $resellerSupportSystem
    ): self {
        return new self(array(
            'php'       => $domain['domain_php'] == 'yes',

            // The precedence here is core's, and is transcribed rather than
            // corrected: && binds tighter than ||, so this reads as
            // (system AND fopen) OR display_errors OR disable_functions.
            // The API must agree with the panel, bug for bug.
            'phpEditor' => $domain['phpini_perm_system'] == 'yes'
                && $domain['phpini_perm_allow_url_fopen'] == 'yes'
                || $domain['phpini_perm_display_errors'] == 'yes'
                || in_array(
                    $domain['phpini_perm_disable_functions'], array('yes', 'exec')
                ),

            'cgi'           => $domain['domain_cgi'] == 'yes',
            'customDns'     => $domain['domain_dns'] != 'no'
                && $config['NAMED_PACKAGE'] != 'Servers::noserver',
            'externalMail'  => $domain['domain_external_mail'] == 'yes',
            'backup'        => $config['BACKUP_DOMAINS'] != 'no'
                && $domain['allowbackup'] != '',
            'ssl'           => $config['ENABLE_SSL'] == 1,
            'webStats'      => $config['WEB_STATISTIC_PACKAGES'] != 'no',
            'supportSystem' => $config['IMSCP_SUPPORT_SYSTEM']
                ? $resellerSupportSystem : false
        ));
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->features;
    }

    public function has(string $name): bool
    {
        return $this->features[$name] ?? false;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `tools/test.sh`
Expected: PASS, 14 test methods (6 of them through the data provider).

- [ ] **Step 5: Check the CORE-DEBT inventory reports the new marker**

Run: `sh test/lint/all.sh`
Expected: PASS, with `CORE-DEBT(C1)` in the printed inventory. C1 already exists in spec §21, so the marker check is satisfied.

- [ ] **Step 6: Commit**

```bash
git add Support/CustomerFeatures.php test/unit/Support/CustomerFeaturesTest.php
git commit -m "Transcribe customerHasFeature for an explicit customer

The panel's own function answers only for the account the request is acting
as: it reads the session and caches in a static that is not keyed by user, so
a reseller reading three customers would get the first one's answers for all
three. The expression is copied here against an explicit domain row, operator
precedence and all, so the API cannot disagree with the panel about what a
customer may do. The CORE-DEBT(C1) marker is how the copy gets retired."
```

---

## Task 5: Batched counting — `sonnet`

Spec §7.4's `Quota.used` values come from `gui/include/Counting.php`, which spec §3.2 says to use directly. Those functions take one customer at a time, so a reseller's customer list would cost six queries per customer — the N+1 that spec §10.1 calls a defect. Decision D5 is to transcribe them in batched form and to file the batching as a core backlog item, because the panel has the identical N+1 today.

**Files:**
- Create: `Repository/Counts.php`
- Create: `test/unit/Repository/CountsTest.php`
- Modify: `docs/SPECIFICATION.md` — add item C7 to §21.1

**Interfaces:**
- Consumes: `Db` (Task 2).
- Produces:
  - `new Counts(Db $db, bool $countDefaultMailAccounts)`
  - `Counts::subdomains(array $domainIds): array` — `domainId => int`, every requested id present
  - `Counts::domainAliases(array $domainIds): array`
  - `Counts::mailAccounts(array $domainIds): array`
  - `Counts::sqlDatabases(array $domainIds): array`
  - `Counts::sqlUsers(array $domainIds): array`
  - `Counts::ftpUsers(array $adminIds): array` — `adminId => int`
  - The class is **not** `final`, so a resolver test can subclass it.

- [ ] **Step 1: Add C7 to `docs/SPECIFICATION.md` §21.1**

`test/lint/all.sh` fails on a `CORE-DEBT(Cn)` marker naming an item that does not exist in §21, so this comes first. Insert after the **C6** block, before the `### 21.2` heading, in exactly the shape of its neighbours:

```markdown
---

**C7 — Batched counting functions.**

*Change.* Add a batched form of each per-customer counting function in
`gui/include/Counting.php` — `get_customers_subdomains_count(array $domainIds)`
and its five siblings — each returning a map keyed by the identifier it was
given. Reimplement the existing singular functions in terms of them so there is
one query per counting rule rather than two.

*Why i-MSCP wants it anyway.* `gui/public/reseller/user_statistics.php:87`
loops over every customer of a reseller and calls
`getClientItemCountsAndLimits()` and `getClientTrafficAndDiskStats()` for each,
which is eight queries a row; `admin/manage_users.php` and the reseller's
customer list do the same shape of thing. A reseller with two hundred customers
renders that page with well over a thousand queries. The counting rules are
already in one file, which is what makes the batched form cheap to add and
cheap to keep correct.

*What the plugin deletes.* `Repository\Counts` in its entirety.

*Shape.* Additive. The existing functions keep their signatures and their
behaviour.
```

- [ ] **Step 2: Write the failing test**

`test/unit/Repository/CountsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Repository;

use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use PHPUnit\Framework\TestCase;

/**
 * A Db that answers from a canned table rather than a database, and records
 * every query it was asked for.
 */
class FakeDb extends Db
{
    /** @var array<string, array> Keyed by a distinctive fragment of the query. */
    public $canned = array();

    /** @var array<int, array{0: string, 1: array}> */
    public $log = array();

    public function __construct()
    {
        // No connection: every method used here is overridden.
    }

    public function rows(string $sql, array $bind = array()): array
    {
        $this->log[] = array($sql, $bind);

        foreach ($this->canned as $needle => $rows) {
            if (strpos($sql, $needle) !== false) {
                return $rows;
            }
        }

        return array();
    }

    public function placeholders(int $n): string
    {
        return $n < 1 ? '' : rtrim(str_repeat('?,', $n), ',');
    }
}

class CountsTest extends TestCase
{
    /** @var FakeDb */
    private $db;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
    }

    private function counts(bool $countDefaults = true): Counts
    {
        return new Counts($this->db, $countDefaults);
    }

    public function testSubdomainsSumsBothTables(): void
    {
        // A customer's subdomain allowance covers subdomains of the main
        // domain and subdomains of its aliases alike: Counting.php:467.
        $this->db->canned = array(
            'FROM subdomain WHERE'      => array(array('k' => 4, 'n' => 2)),
            'FROM subdomain_alias AS s' => array(array('k' => 4, 'n' => 3))
        );

        self::assertSame(array(4 => 5), $this->counts()->subdomains(array(4)));
    }

    public function testEveryRequestedIdIsPresentEvenWithNoRows(): void
    {
        // A resolver must not have to distinguish "no rows" from "not asked".
        self::assertSame(
            array(4 => 0, 9 => 0),
            $this->counts()->subdomains(array(4, 9))
        );
    }

    public function testAnEmptyIdListIssuesNoQuery(): void
    {
        self::assertSame(array(), $this->counts()->subdomains(array()));
        self::assertSame(array(), $this->db->log);
    }

    public function testDomainAliasesExcludeOrderedAndDeleting(): void
    {
        // Counting.php:497 - an ordered alias is not yet the customer's.
        $this->counts()->domainAliases(array(4));

        self::assertStringContainsString(
            "alias_status NOT IN('ordered', 'todelete')", $this->db->log[0][0]
        );
    }

    public function testMailAccountsCountDefaultsWhenTheServerSaysSo(): void
    {
        $this->counts(true)->mailAccounts(array(4));

        self::assertStringNotContainsString('hostmaster', $this->db->log[0][0]);
    }

    public function testMailAccountsExcludeDefaultsWhenTheServerSaysSo(): void
    {
        // Counting.php:518 - COUNT_DEFAULT_EMAIL_ADDRESSES decides whether the
        // abuse/hostmaster/postmaster/webmaster forwards count against a limit.
        $this->counts(false)->mailAccounts(array(4));

        $sql = $this->db->log[0][0];
        self::assertStringContainsString('hostmaster', $sql);
        self::assertStringContainsString('normal_forward', $sql);
        self::assertStringContainsString('alssub_forward', $sql);
    }

    public function testSqlUsersCountDistinctNames(): void
    {
        // Counting.php:586 - one SQL user may be granted on several databases
        // and still counts once against the limit.
        $this->counts()->sqlUsers(array(4));

        self::assertStringContainsString('COUNT(DISTINCT sqlu_name)', $this->db->log[0][0]);
    }

    public function testSqlDatabasesHaveNoStatusFilter(): void
    {
        // sql_database has no status column: it is created synchronously by
        // the panel. Spec section 2.1.
        $this->counts()->sqlDatabases(array(4));

        self::assertStringNotContainsString('todelete', $this->db->log[0][0]);
    }

    public function testFtpUsersAreKeyedByCustomerNotDomain(): void
    {
        // ftp_users.admin_id is the customer, not the domain. Spec section 2.2.
        $this->db->canned = array('FROM ftp_users' => array(array('k' => 7, 'n' => 3)));

        self::assertSame(array(7 => 3), $this->counts()->ftpUsers(array(7)));
        self::assertStringContainsString('admin_id IN', $this->db->log[0][0]);
    }

    public function testOneQueryPerRuleWhateverTheNumberOfCustomers(): void
    {
        // The whole point of this class.
        $this->counts()->mailAccounts(array(1, 2, 3, 4, 5, 6, 7, 8));

        self::assertCount(1, $this->db->log);
        self::assertSame(array(1, 2, 3, 4, 5, 6, 7, 8), $this->db->log[0][1]);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL, `Counts` not found.

- [ ] **Step 4: Write `Repository/Counts.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;
// ... licence header ...

/**
 * The counts behind spec section 7.4's Quota.used, one query per rule
 * regardless of how many customers were asked about.
 *
 * CORE-DEBT(C7): transcribed from gui/include/Counting.php:467-600. The core
 *   functions take one customer at a time, so a reseller's customer list would
 *   be six queries per customer here - the N+1 that spec section 10.1 calls a
 *   defect. Retire this copy when C7 lands the batched forms in core.
 *
 * Not final, so that a resolver test can subclass it.
 */
class Counts
{
    /** @var Db */
    private $db;

    /** @var bool Registry config COUNT_DEFAULT_EMAIL_ADDRESSES. */
    private $countDefaultMailAccounts;

    public function __construct(Db $db, bool $countDefaultMailAccounts)
    {
        $this->db = $db;
        $this->countDefaultMailAccounts = $countDefaultMailAccounts;
    }

    /**
     * Counting.php:467. A customer's subdomain allowance covers subdomains of
     * the main domain and subdomains of its aliases alike, so both tables are
     * counted and the two maps are added.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function subdomains(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        $direct = $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(subdomain_id) AS n
            FROM subdomain
            WHERE domain_id IN ($in) AND subdomain_status <> 'todelete'
            GROUP BY domain_id
        ", $domainIds);

        $viaAliases = $this->map($domainIds, "
            SELECT a.domain_id AS k, COUNT(sa.subdomain_alias_id) AS n
            FROM subdomain_alias AS sa
            JOIN domain_aliasses AS a ON a.alias_id = sa.alias_id
            WHERE a.domain_id IN ($in) AND sa.subdomain_alias_status <> 'todelete'
            GROUP BY a.domain_id
        ", $domainIds);

        $totals = array();

        foreach ($domainIds as $domainId) {
            $totals[$domainId] = $direct[$domainId] + $viaAliases[$domainId];
        }

        return $totals;
    }

    /**
     * Counting.php:497. An ordered alias is awaiting the reseller's approval
     * and is not yet the customer's.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function domainAliases(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        return $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(alias_id) AS n
            FROM domain_aliasses
            WHERE domain_id IN ($in) AND alias_status NOT IN('ordered', 'todelete')
            GROUP BY domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:518. Whether the default forwards - abuse, hostmaster,
     * postmaster and webmaster - count against the limit is an administrator
     * setting, and a customer who turns one into a normal account makes it
     * count from then on.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function mailAccounts(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));
        $excludeDefaults = '';

        if (!$this->countDefaultMailAccounts) {
            $excludeDefaults = "
                AND !(
                    mail_acc IN('abuse', 'hostmaster', 'postmaster', 'webmaster')
                    AND mail_type IN('normal_forward', 'alias_forward')
                )
                AND !(
                    mail_acc = 'webmaster'
                    AND mail_type IN('subdom_forward', 'alssub_forward')
                )
            ";
        }

        return $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(mail_id) AS n
            FROM mail_users
            WHERE domain_id IN ($in) $excludeDefaults AND status <> 'todelete'
            GROUP BY domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:572. sql_database has no status column - it is created
     * synchronously by the panel (spec section 2.1) - so there is nothing to
     * exclude.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function sqlDatabases(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        return $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(sqld_id) AS n
            FROM sql_database
            WHERE domain_id IN ($in)
            GROUP BY domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:586. One SQL user may be granted on several databases and
     * still counts once against the limit, hence DISTINCT.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function sqlUsers(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        return $this->map($domainIds, "
            SELECT sd.domain_id AS k, COUNT(DISTINCT su.sqlu_name) AS n
            FROM sql_user AS su
            JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
            WHERE sd.domain_id IN ($in)
            GROUP BY sd.domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:553. Keyed by the customer's admin_id, because ftp_users
     * hangs off the customer and not off the domain (spec section 2.2).
     *
     * @param int[] $adminIds
     * @return array<int, int>
     */
    public function ftpUsers(array $adminIds): array
    {
        if ($adminIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($adminIds));

        return $this->map($adminIds, "
            SELECT admin_id AS k, COUNT(userid) AS n
            FROM ftp_users
            WHERE admin_id IN ($in) AND status <> 'todelete'
            GROUP BY admin_id
        ", $adminIds);
    }

    /**
     * Run one grouped count and return a map with every requested key present,
     * so a resolver never has to tell "no rows" from "not asked".
     *
     * @param int[] $keys
     * @return array<int, int>
     */
    private function map(array $keys, string $sql, array $bind): array
    {
        $counts = array_fill_keys($keys, 0);

        foreach ($this->db->rows($sql, $bind) as $row) {
            $counts[(int)$row['k']] = (int)$row['n'];
        }

        return $counts;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `tools/test.sh`
Expected: PASS, `CountsTest` 10 methods, and `test/lint/all.sh` prints `CORE-DEBT(C1)` and `CORE-DEBT(C7)` with no failure — C7 now exists in §21.

- [ ] **Step 6: Commit**

```bash
git add Repository/Counts.php test/unit/Repository/CountsTest.php docs/SPECIFICATION.md
git commit -m "Count a customer's objects in one query per rule

Counting.php answers for one customer at a time, so a reseller's customer list
would cost six queries a customer here. The rules are transcribed in batched
form, keyed by the identifier they were given, so every requested customer is
present in the answer whether or not it had rows.

The panel has this same N+1 today - user_statistics.php renders one customer a
row at eight queries each - so the batched form is worth adding to core on its
own merits, and section 21 gains C7 saying so."
```

---

## Task 6: The mail type codec — `haiku`

Spec §7.6. i-MSCP stores twelve `mail_type` values — the cross product of `{normal, alias, subdom, alssub}` and `{mail, forward, catchall}` — and comma-joins two of them for a mailbox that also forwards. The vhost half of that product is already carried by `MailAccount.host`, so the schema keeps only the second half. Spec §17 requires the mapping to be tested both ways over all twelve values.

The constants are transcribed from `gui/include/Shared.php:38-49` rather than referenced, because the unit suite runs without the panel and therefore without those `define()`s.

**Files:**
- Create: `Support/MailType.php`
- Create: `test/unit/Support/MailTypeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `MailType::kindOf(string $mailType): string` — one of `MAILBOX`, `FORWARD`, `MAILBOX_AND_FORWARD`, `CATCHALL`
  - `MailType::hostTypeOf(string $mailType): string` — one of `dmn`, `sub`, `als`, `alssub`
  - `MailType::toMailType(string $hostType, string $kind): string` — the inverse
  - `MailType::all(): array` — every stored value, the twelve plus the four combined
  - Constants `MailType::KIND_MAILBOX`, `KIND_FORWARD`, `KIND_MAILBOX_AND_FORWARD`, `KIND_CATCHALL`
  - Constants `MailType::HOST_DMN`, `HOST_SUB`, `HOST_ALS`, `HOST_ALSSUB`

- [ ] **Step 1: Write the failing test**

`test/unit/Support/MailTypeTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MailTypeTest extends TestCase
{
    /**
     * @dataProvider storedValues
     */
    public function testEveryStoredValueMapsToAKindAndAHost(
        string $stored, string $host, string $kind
    ): void {
        self::assertSame($kind, MailType::kindOf($stored), $stored);
        self::assertSame($host, MailType::hostTypeOf($stored), $stored);
    }

    /**
     * @dataProvider storedValues
     */
    public function testTheMappingIsReversible(
        string $stored, string $host, string $kind
    ): void {
        self::assertSame($stored, MailType::toMailType($host, $kind), $stored);
    }

    public function storedValues(): array
    {
        return array(
            // The twelve values of gui/include/Shared.php:38-49 ...
            array('normal_mail',     'dmn',    'MAILBOX'),
            array('normal_forward',  'dmn',    'FORWARD'),
            array('normal_catchall', 'dmn',    'CATCHALL'),
            array('alias_mail',      'als',    'MAILBOX'),
            array('alias_forward',   'als',    'FORWARD'),
            array('alias_catchall',  'als',    'CATCHALL'),
            array('subdom_mail',     'sub',    'MAILBOX'),
            array('subdom_forward',  'sub',    'FORWARD'),
            array('subdom_catchall', 'sub',    'CATCHALL'),
            array('alssub_mail',     'alssub', 'MAILBOX'),
            array('alssub_forward',  'alssub', 'FORWARD'),
            array('alssub_catchall', 'alssub', 'CATCHALL'),
            // ... and the four comma-joined pairs mail_add.php writes for a
            // mailbox that also forwards.
            array('normal_mail,normal_forward', 'dmn',    'MAILBOX_AND_FORWARD'),
            array('alias_mail,alias_forward',   'als',    'MAILBOX_AND_FORWARD'),
            array('subdom_mail,subdom_forward', 'sub',    'MAILBOX_AND_FORWARD'),
            array('alssub_mail,alssub_forward', 'alssub', 'MAILBOX_AND_FORWARD')
        );
    }

    public function testAllListsEverySixteenStoredValues(): void
    {
        $all = MailType::all();

        self::assertCount(16, $all);
        self::assertContains('normal_mail', $all);
        self::assertContains('alssub_mail,alssub_forward', $all);
    }

    public function testEveryValueInAllRoundTrips(): void
    {
        // The guard against adding a value to one table and not the other.
        foreach (MailType::all() as $stored) {
            self::assertSame(
                $stored,
                MailType::toMailType(
                    MailType::hostTypeOf($stored), MailType::kindOf($stored)
                ),
                $stored
            );
        }
    }

    public function testAnUnknownStoredValueIsRejected(): void
    {
        // i-MSCP's vocabulary here is closed, unlike the status vocabulary of
        // spec section 7.1: a mail_type this codec has not seen is data
        // corruption, not forward compatibility.
        $this->expectException(InvalidArgumentException::class);

        MailType::kindOf('normal_bounce');
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MailType::toMailType('dmn', 'ARCHIVE');
    }

    public function testAnUnknownHostTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MailType::toMailType('wildcard', 'MAILBOX');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL, `MailType` not found.

- [ ] **Step 3: Write `Support/MailType.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use InvalidArgumentException;

/**
 * mail_users.mail_type, both ways.
 *
 * i-MSCP stores the cross product of the vhost kind and the account kind in
 * one column, and comma-joins two of them for a mailbox that also forwards
 * (gui/public/client/mail_add.php:243-256). The vhost half is already carried
 * by MailAccount.host, so spec section 7.6 keeps only the second half and this
 * is the one place the product is taken apart and put back together.
 *
 * The constants are copied from gui/include/Shared.php:38-49 rather than
 * referenced: the unit suite runs without the panel, and therefore without
 * those define()s.
 */
final class MailType
{
    const KIND_MAILBOX             = 'MAILBOX';
    const KIND_FORWARD             = 'FORWARD';
    const KIND_MAILBOX_AND_FORWARD = 'MAILBOX_AND_FORWARD';
    const KIND_CATCHALL            = 'CATCHALL';

    const HOST_DMN    = 'dmn';
    const HOST_SUB    = 'sub';
    const HOST_ALS    = 'als';
    const HOST_ALSSUB = 'alssub';

    /** Vhost kind => the prefix i-MSCP uses for it in mail_type. */
    const PREFIX = array(
        self::HOST_DMN    => 'normal',
        self::HOST_ALS    => 'alias',
        self::HOST_SUB    => 'subdom',
        self::HOST_ALSSUB => 'alssub'
    );

    /** Account kind => the suffix, or the pair of suffixes. */
    const SUFFIX = array(
        self::KIND_MAILBOX             => array('mail'),
        self::KIND_FORWARD             => array('forward'),
        self::KIND_MAILBOX_AND_FORWARD => array('mail', 'forward'),
        self::KIND_CATCHALL            => array('catchall')
    );

    /**
     * Every value that can appear in the column.
     *
     * @return string[]
     */
    public static function all(): array
    {
        $values = array();

        foreach (array_keys(self::PREFIX) as $hostType) {
            foreach (array_keys(self::SUFFIX) as $kind) {
                $values[] = self::toMailType($hostType, $kind);
            }
        }

        return $values;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function toMailType(string $hostType, string $kind): string
    {
        if (!isset(self::PREFIX[$hostType])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown vhost kind "%s".', $hostType
            ));
        }

        if (!isset(self::SUFFIX[$kind])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown mail account kind "%s".', $kind
            ));
        }

        $prefix = self::PREFIX[$hostType];
        $parts = array();

        foreach (self::SUFFIX[$kind] as $suffix) {
            $parts[] = $prefix . '_' . $suffix;
        }

        return implode(',', $parts);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function kindOf(string $mailType): string
    {
        return self::split($mailType)[1];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function hostTypeOf(string $mailType): string
    {
        return self::split($mailType)[0];
    }

    /**
     * @return array{0: string, 1: string} host kind, account kind
     * @throws InvalidArgumentException
     */
    private static function split(string $mailType): array
    {
        $suffixes = array();
        $hostType = null;

        foreach (explode(',', $mailType) as $part) {
            $underscore = strrpos($part, '_');

            if ($underscore === false) {
                throw self::unknown($mailType);
            }

            $prefix = substr($part, 0, $underscore);
            $suffix = substr($part, $underscore + 1);
            $found = array_search($prefix, self::PREFIX, true);

            // Every part must name the same vhost, or the row is corrupt: a
            // single address cannot belong to two vhosts.
            if ($found === false || ($hostType !== null && $hostType !== $found)) {
                throw self::unknown($mailType);
            }

            $hostType = $found;
            $suffixes[] = $suffix;
        }

        foreach (self::SUFFIX as $kind => $expected) {
            if ($suffixes === $expected) {
                return array($hostType, $kind);
            }
        }

        throw self::unknown($mailType);
    }

    private static function unknown(string $mailType): InvalidArgumentException
    {
        // i-MSCP's mail_type vocabulary is closed, unlike the status vocabulary
        // of spec section 7.1, so an unrecognised value is data corruption and
        // not something to flatten into a default.
        return new InvalidArgumentException(sprintf(
            'Unknown mail_type "%s".', $mailType
        ));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `tools/test.sh`
Expected: PASS, 37 test methods (32 through the two data providers).

- [ ] **Step 5: Commit**

```bash
git add Support/MailType.php test/unit/Support/MailTypeTest.php
git commit -m "Split mail_type into a vhost and an account kind

The column stores the cross product of the two, sometimes comma-joined, and
the vhost half is already carried by MailAccount.host. Keeping only the second
half in the schema means the product is taken apart in exactly one place, and
the test walks every one of the sixteen stored values in both directions so a
value added to one table and not the other fails immediately.

An unrecognised value raises rather than defaulting: unlike the provisioning
status vocabulary, this one is closed, so a surprise here is corruption."
```

---

## Task 7: The hosting plan props codec — `sonnet`

Spec §7.8: `hosting_plans.props` is a positional, semicolon-delimited string of 25 fields. Spec §20 names getting it wrong as risk 4 — *"creates a customer with the wrong limits"* — and spec §17 requires a round-trip property test. Spec §21's C4 is the core item that retires this copy.

The field order is transcribed from `gui/public/reseller/user_add3.php:133-139` (the `list()` that reads it) and the emit form from `gui/public/reseller/hosting_plan_add.php:446-457` (the concatenation that writes it). Booleans are written `_yes_` and `_no_`; the backup field is a `|`-joined subset of `_dmn_`, `_sql_`, `_mail_`, possibly empty.

**Files:**
- Create: `Support/PlanProps.php`
- Create: `test/unit/Support/PlanPropsTest.php`

**Interfaces:**
- Consumes: `Quota` (Task 3).
- Produces:
  - `PlanProps::parse(string $props): PlanProps` — throws `InvalidArgumentException` on the wrong field count
  - `PlanProps::toString(): string` — byte-identical to what was parsed
  - `PlanProps::allowance(string $name): array` — `array('enabled' => bool, 'limit' => ?int)`, for `subdomains`, `domainAliases`, `mailAccounts`, `ftpUsers`, `sqlDatabases`, `sqlUsers`
  - `PlanProps::storage(): array` — `array('disk' => ?int, 'traffic' => ?int, 'mailQuota' => ?int)`, all in **bytes** (decision D3)
  - `PlanProps::features(): array` — `php`, `phpEditor`, `cgi`, `customDns`, `externalMail`, `webFolderProtection` as booleans, and `backup` as a list of `DOMAIN`, `SQL`, `MAIL`
  - `PlanProps::FIELDS` — the 25 field names in order

- [ ] **Step 1: Write the failing test**

`test/unit/Support/PlanPropsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PlanPropsTest extends TestCase
{
    /**
     * A plan as hosting_plan_add.php:446 writes it: PHP and CGI on, DNS off,
     * ten subdomains, five aliases, unlimited mail, no FTP, two databases,
     * two SQL users, 10 GiB of traffic, 5 GiB of disk, domain and SQL backup,
     * the PHP editor on, external mail off, web folder protection on and a
     * 100 MiB default mailbox.
     */
    const SAMPLE = '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
        . 'no;no;no;yes;10;5;30;60;128;_no_;_yes_;104857600';

    public function testItParsesTwentyFiveFields(): void
    {
        self::assertCount(25, PlanProps::FIELDS);

        $props = PlanProps::parse(self::SAMPLE);

        self::assertSame(
            array('enabled' => true, 'limit' => 10), $props->allowance('subdomains')
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 5), $props->allowance('domainAliases')
        );
    }

    public function testZeroIsUnlimitedAndMinusOneIsWithheld(): void
    {
        // The same three-valued encoding as a customer's own limits: spec
        // section 2.3. A plan that says -1 for FTP creates a customer with the
        // FTP feature withheld, not with a limit of minus one.
        $props = PlanProps::parse(self::SAMPLE);

        self::assertSame(
            array('enabled' => true, 'limit' => null), $props->allowance('mailAccounts')
        );
        self::assertSame(
            array('enabled' => false, 'limit' => 0), $props->allowance('ftpUsers')
        );
    }

    public function testStorageIsReportedInBytes(): void
    {
        // The props file holds MiB for disk and traffic and bytes for the mail
        // quota. Everything BigInt in this schema is bytes: decision D3.
        self::assertSame(
            array(
                'disk'      => 5120 * 1048576,
                'traffic'   => 10240 * 1048576,
                'mailQuota' => 104857600
            ),
            PlanProps::parse(self::SAMPLE)->storage()
        );
    }

    public function testAZeroStorageLimitIsUnlimited(): void
    {
        $props = PlanProps::parse(str_replace(';10240;5120;', ';0;0;', self::SAMPLE));

        self::assertNull($props->storage()['disk']);
        self::assertNull($props->storage()['traffic']);
    }

    public function testFeaturesReadTheUnderscoredBooleans(): void
    {
        self::assertSame(
            array(
                'php'                 => true,
                'phpEditor'           => true,
                'cgi'                 => true,
                'customDns'           => false,
                'externalMail'        => false,
                'webFolderProtection' => true,
                'backup'              => array('DOMAIN', 'SQL')
            ),
            PlanProps::parse(self::SAMPLE)->features()
        );
    }

    public function testAnEmptyBackupFieldIsNoBackupTargets(): void
    {
        $props = PlanProps::parse(str_replace(';_dmn_|_sql_;', ';;', self::SAMPLE));

        self::assertSame(array(), $props->features()['backup']);
    }

    public function testItRoundTripsByteForByte(): void
    {
        self::assertSame(self::SAMPLE, PlanProps::parse(self::SAMPLE)->toString());
    }

    /**
     * Spec section 17 requires this as a property test: parse then re-emit
     * must be byte-identical for generated inputs as well as for real plans.
     */
    public function testItRoundTripsForGeneratedInputs(): void
    {
        mt_srand(20260905);

        for ($i = 0; $i < 500; $i++) {
            $props = implode(';', array(
                $this->bool(), $this->bool(),
                $this->limit(), $this->limit(), $this->limit(), $this->limit(),
                $this->limit(), $this->limit(), $this->limit(), $this->limit(),
                $this->backup(), $this->bool(),
                $this->perm(), $this->perm(), $this->perm(),
                $this->disableFunctions(), $this->perm(),
                (string)mt_rand(1, 1024), (string)mt_rand(1, 1024),
                (string)mt_rand(1, 600), (string)mt_rand(1, 600),
                (string)mt_rand(16, 4096),
                $this->bool(), $this->bool(),
                (string)(mt_rand(0, 1024) * 1048576)
            ));

            self::assertSame($props, PlanProps::parse($props)->toString(), $props);
        }
    }

    private function bool(): string
    {
        return mt_rand(0, 1) ? '_yes_' : '_no_';
    }

    private function perm(): string
    {
        return mt_rand(0, 1) ? 'yes' : 'no';
    }

    private function disableFunctions(): string
    {
        $values = array('yes', 'no', 'exec');

        return $values[mt_rand(0, 2)];
    }

    private function limit(): string
    {
        return (string)mt_rand(-1, 500);
    }

    private function backup(): string
    {
        $chosen = array();

        foreach (array('_dmn_', '_sql_', '_mail_') as $target) {
            if (mt_rand(0, 1)) {
                $chosen[] = $target;
            }
        }

        return implode('|', $chosen);
    }

    public function testTooFewFieldsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanProps::parse('_yes_;_no_;1;2;3');
    }

    public function testTooManyFieldsIsRejected(): void
    {
        // A 26th field would silently shift every reader by one, which is how
        // a customer ends up with somebody else's limits.
        $this->expectException(InvalidArgumentException::class);

        PlanProps::parse(self::SAMPLE . ';extra');
    }

    public function testAnUnknownAllowanceNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanProps::parse(self::SAMPLE)->allowance('htaccessUsers');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL, `PlanProps` not found.

- [ ] **Step 3: Write `Support/PlanProps.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

use InvalidArgumentException;

/**
 * The 25 positional, semicolon-delimited fields of hosting_plans.props.
 *
 * CORE-DEBT(C4): transcribed from gui/public/reseller/user_add3.php:133-139
 *   (the list() that reads it) and gui/public/reseller/hosting_plan_add.php:446-457
 *   (the concatenation that writes it). Retire this copy when C4 lands a codec
 *   in core.
 *
 * Spec section 20 names getting this wrong as risk 4: a misread field creates
 * a customer with the wrong limits, silently. The re-emitted string is
 * therefore built from the fields as parsed, and the round-trip is a property
 * test rather than a couple of examples.
 */
final class PlanProps
{
    /** In the order user_add3.php:133 destructures them. */
    const FIELDS = array(
        'php', 'cgi', 'sub', 'als', 'mail', 'ftp', 'sqlDb', 'sqlUser',
        'traffic', 'disk', 'backup', 'dns', 'phpEditor', 'phpiniAllowUrlFopen',
        'phpiniDisplayErrors', 'phpiniDisableFunctions', 'phpMailFunction',
        'phpiniPostMaxSize', 'phpiniUploadMaxFileSize', 'phpiniMaxExecutionTime',
        'phpiniMaxInputTime', 'phpiniMemoryLimit', 'extMailServer',
        'webFolderProtection', 'mailQuota'
    );

    /** GraphQL allowance name => the props field holding its limit. */
    const ALLOWANCES = array(
        'subdomains'    => 'sub',
        'domainAliases' => 'als',
        'mailAccounts'  => 'mail',
        'ftpUsers'      => 'ftp',
        'sqlDatabases'  => 'sqlDb',
        'sqlUsers'      => 'sqlUser'
    );

    /** The props spelling of a backup target => the schema's. */
    const BACKUP_TARGETS = array(
        '_dmn_'  => 'DOMAIN',
        '_sql_'  => 'SQL',
        '_mail_' => 'MAIL'
    );

    /** @var array<string, string> Field name => raw value, exactly as stored. */
    private $fields;

    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $props): self
    {
        $values = explode(';', $props);

        // A 26th field would silently shift every reader by one, which is how
        // a customer ends up with somebody else's limits.
        if (count($values) !== count(self::FIELDS)) {
            throw new InvalidArgumentException(sprintf(
                'A hosting plan has %d properties; this one has %d.',
                count(self::FIELDS), count($values)
            ));
        }

        return new self(array_combine(self::FIELDS, $values));
    }

    /**
     * Byte-identical to what was parsed.
     */
    public function toString(): string
    {
        return implode(';', array_values($this->fields));
    }

    /**
     * The raw stored value of one field.
     */
    public function raw(string $field): string
    {
        if (!array_key_exists($field, $this->fields)) {
            throw new InvalidArgumentException(sprintf(
                'A hosting plan has no "%s" property.', $field
            ));
        }

        return $this->fields[$field];
    }

    /**
     * A countable allowance, in the same three-valued encoding a customer's
     * own limit columns use: -1 withheld, 0 unlimited, n = n. Spec section 2.3.
     *
     * @return array{enabled: bool, limit: int|null}
     * @throws InvalidArgumentException
     */
    public function allowance(string $name): array
    {
        if (!isset(self::ALLOWANCES[$name])) {
            throw new InvalidArgumentException(sprintf(
                'A hosting plan has no "%s" allowance.', $name
            ));
        }

        $quota = Quota::fromCustomerLimit((int)$this->raw(self::ALLOWANCES[$name]), 0);

        return array('enabled' => $quota->isEnabled(), 'limit' => $quota->getLimit());
    }

    /**
     * Disk and traffic are stored in MiB and the mail quota in bytes. Every
     * BigInt in this schema is bytes, so the first two are converted here.
     * Null means unlimited.
     *
     * @return array{disk: int|null, traffic: int|null, mailQuota: int|null}
     */
    public function storage(): array
    {
        return array(
            'disk'      => self::mibToBytes((int)$this->raw('disk')),
            'traffic'   => self::mibToBytes((int)$this->raw('traffic')),
            'mailQuota' => (int)$this->raw('mailQuota') === 0
                ? null : (int)$this->raw('mailQuota')
        );
    }

    /**
     * @return array{php: bool, phpEditor: bool, cgi: bool, customDns: bool,
     *               externalMail: bool, webFolderProtection: bool, backup: string[]}
     */
    public function features(): array
    {
        return array(
            'php'                 => $this->raw('php') === '_yes_',
            'phpEditor'           => $this->raw('phpEditor') === 'yes',
            'cgi'                 => $this->raw('cgi') === '_yes_',
            'customDns'           => $this->raw('dns') === '_yes_',
            'externalMail'        => $this->raw('extMailServer') === '_yes_',
            'webFolderProtection' => $this->raw('webFolderProtection') === '_yes_',
            'backup'              => $this->backupTargets()
        );
    }

    /**
     * @return string[]
     */
    private function backupTargets(): array
    {
        $raw = $this->raw('backup');

        if ($raw === '') {
            return array();
        }

        $targets = array();

        foreach (explode('|', $raw) as $target) {
            if (isset(self::BACKUP_TARGETS[$target])) {
                $targets[] = self::BACKUP_TARGETS[$target];
            }
        }

        return $targets;
    }

    private static function mibToBytes(int $mib): ?int
    {
        return $mib === 0 ? null : $mib * 1048576;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `tools/test.sh`
Expected: PASS, 11 test methods, one of them 500 generated round trips.

- [ ] **Step 5: Check the round trip against every plan on the box**

Spec §17 asks for the property test to cover *"every plan on the reference box"* as well as generated inputs. Prove it once by hand here; Task 17's integration suite keeps it honest afterwards.

```bash
tools/deploy.sh
ssh -F .ssh-config imscp_debian_trixie \
  "cd /var/www/imscp/gui/plugins/SGW_GraphQL && php7.4 -r '
    require \"vendor/autoload.php\";
    require \"/var/www/imscp/gui/include/imscp-lib.php\";
    \$n = 0;
    foreach (exec_query(\"SELECT id, props FROM hosting_plans\")->fetchAll(PDO::FETCH_ASSOC) as \$r) {
        \$out = iMSCP\\Plugin\\SGW_GraphQL\\Support\\PlanProps::parse(\$r[\"props\"])->toString();
        if (\$out !== \$r[\"props\"]) { echo \"MISMATCH plan {\$r[\"id\"]}\n\"; exit(1); }
        \$n++;
    }
    echo \"round-tripped \$n plans\n\";
  '"
```

Expected: `round-tripped N plans` and exit 0. If the box has no hosting plans, create one through the reseller UI first — a codec verified only against generated input is not verified.

- [ ] **Step 6: Commit**

```bash
git add Support/PlanProps.php test/unit/Support/PlanPropsTest.php
git commit -m "Read hosting plan properties without shifting a field

Twenty-five positional semicolon-delimited values, with underscore-wrapped
booleans and a pipe-joined backup list. A misread field here creates a
customer with somebody else's limits and says nothing, so the field count is
checked before anything is read and the re-emitted string is built from the
fields as parsed. The round trip is a property test over generated inputs
rather than a couple of examples."
```

---

## Task 8: The seeded graph every database test reads — `sonnet`

Spec §17 puts the authorisation matrix and the query-count test "against a seeded
database". Both need the same thing: one reseller with two customers, a second
reseller with a customer of its own, and one of every object hanging off the
first customer, so that "the owner may reach it" and "a sibling may not" are
both answerable without a second fixture.

The seed runs inside a transaction that is rolled back in `tearDown()`. That is
the only way a test suite may touch the reference box's database: a fixture that
commits leaves rows behind, and the next run either collides on a unique key or
— worse — passes because of the previous run's rows.

This task also fixes an autoloading gap. `composer.json` has no `autoload-dev`
section, so `test/integration/IntegrationTestCase.php` and this task's
`Fixture.php` are found by nothing: PHPUnit loads `*Test.php` files by path, but
a base class or a helper class in another file needs the autoloader. Task 2's
note assumed a mapping that is not there. The fix is one four-line addition to
`composer.json` and a `composer dump-autoload`.

**Files:**
- Create: `test/integration/Fixture.php`
- Modify: `composer.json` (add `autoload-dev`)
- Create: `test/integration/FixtureTest.php`

**Interfaces:**
- Consumes:
  - `Db::rows(string $sql, array $bind = array()): array`, `Db::row()`, `Db::value()`,
    `Db::pdo(): \PDO`, `Db::placeholders(int $n): string` (Task 2)
  - `IntegrationTestCase` — the base class that bootstraps the panel and skips
    when it is absent (Task 2)
  - `Identity`, `GlobalId`, `NodeType`, `OwnershipResolver` (plan 1, Task 1)
- Produces:
  - `new Fixture(Db $db)`
  - `Fixture::seed(): void` — begins a transaction and inserts the whole graph
  - `Fixture::rollBack(): void` — rolls it back; safe to call twice
  - `Fixture::PREFIX` — `'sgwt'`, the prefix on every name this fixture writes
  - Account ids: `adminId(): int`, `resellerId(): int`, `otherResellerId(): int`,
    `customerId(): int`, `siblingId(): int`, `otherCustomerId(): int`
  - Object ids: `domainId(): int`, `siblingDomainId(): int`,
    `otherDomainId(): int`, `subdomainId(): int`, `aliasId(): int`,
    `aliasSubdomainId(): int`, `mailboxId(): int`, `forwardId(): int`,
    `ftpUserId(): string`, `sqlDatabaseId(): int`, `sqlUserId(): int`,
    `dnsRecordId(): int`, `hostingPlanId(): int`, `ipId(): int`
  - Names: `domainName(): string`, `aliasName(): string`, `subdomainName(): string`
  - `Fixture::identity(string $who): Identity` — `'customer'`, `'sibling'`,
    `'otherCustomer'`, `'reseller'`, `'otherReseller'`, `'admin'`

- [ ] **Step 1: Add the test autoload mapping to `composer.json`**

Insert an `autoload-dev` block immediately after the existing `autoload` block:

```json
    "autoload-dev": {
        "psr-4": {
            "iMSCP\\Plugin\\SGW_GraphQL\\Test\\": "test/"
        }
    }
```

The existing `"exclude-from-classmap": ["/test/", ...]` stays. It only excludes
those paths from the generated classmap; PSR-4 resolution is unaffected, and the
`optimize-autoloader` setting does not make the classmap authoritative.

- [ ] **Step 2: Regenerate the autoloader**

```bash
composer dump-autoload
```

Expected: `Generated autoload files`. Confirm the mapping landed:

```bash
grep -n "SGW_GraphQL\\\\\\\\Test" vendor/composer/autoload_psr4.php
```

Expected: one line mapping the test namespace at `$baseDir . '/test'`.

- [ ] **Step 3: Write the failing `Fixture` test**

`test/integration/FixtureTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class FixtureTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    public function testTheSeededGraphHangsOffTheOneCustomer(): void
    {
        // Every ownership query in this plugin walks up to domain_admin_id or
        // to ftp_users.admin_id. A fixture that wired any of these to the
        // wrong parent would make the authorisation tests pass for the wrong
        // reason, so the chain is asserted here rather than assumed.
        $this->fixture->seed();

        $resolver = new OwnershipResolver(function (string $sql, array $bind) {
            return $this->db->rows($sql, $bind);
        });

        $customerId = $this->fixture->customerId();

        $cases = array(
            NodeType::DOMAIN          => $this->fixture->domainId(),
            NodeType::SUBDOMAIN       => $this->fixture->subdomainId(),
            NodeType::DOMAIN_ALIAS    => $this->fixture->aliasId(),
            NodeType::ALIAS_SUBDOMAIN => $this->fixture->aliasSubdomainId(),
            NodeType::MAIL_ACCOUNT    => $this->fixture->mailboxId(),
            NodeType::SQL_DATABASE    => $this->fixture->sqlDatabaseId(),
            NodeType::SQL_USER        => $this->fixture->sqlUserId(),
            NodeType::DNS_RECORD      => $this->fixture->dnsRecordId()
        );

        foreach ($cases as $tag => $id) {
            self::assertSame(
                $customerId,
                $resolver->ownerOf(GlobalId::decode(GlobalId::encode($tag, $id))),
                $tag
            );
        }

        self::assertSame($customerId, $resolver->ownerOf(GlobalId::decodeKey(
            GlobalId::encodeKey(NodeType::FTP_USER, $this->fixture->ftpUserId())
        )));
    }

    public function testTheSiblingAndTheOtherResellerAreWiredForNegativeCases(): void
    {
        $this->fixture->seed();

        self::assertSame(
            $this->fixture->resellerId(),
            (int)$this->db->value(
                'SELECT created_by FROM admin WHERE admin_id = ?',
                array($this->fixture->siblingId())
            )
        );
        self::assertSame(
            $this->fixture->otherResellerId(),
            (int)$this->db->value(
                'SELECT created_by FROM admin WHERE admin_id = ?',
                array($this->fixture->otherCustomerId())
            )
        );
        self::assertNotSame(
            $this->fixture->resellerId(), $this->fixture->otherResellerId()
        );
    }

    public function testTheHostingPlanBelongsToTheReseller(): void
    {
        $this->fixture->seed();

        self::assertSame(
            $this->fixture->resellerId(),
            (int)$this->db->value(
                'SELECT reseller_id FROM hosting_plans WHERE id = ?',
                array($this->fixture->hostingPlanId())
            )
        );
    }

    public function testRollingBackLeavesNoRowBehind(): void
    {
        // The test that matters. A fixture that commits would leave the box's
        // database dirty and the next run would either collide on
        // admin.admin_name or pass because of the previous run's rows.
        $before = $this->counts();

        $this->fixture->seed();
        $seeded = $this->counts();

        self::assertGreaterThan($before['admin'], $seeded['admin'], 'the seed did nothing');

        $this->fixture->rollBack();

        self::assertSame($before, $this->counts());
    }

    public function testRollingBackTwiceIsHarmless(): void
    {
        $this->fixture->seed();
        $this->fixture->rollBack();
        $this->fixture->rollBack();

        self::assertSame(0, (int)$this->db->value(
            'SELECT COUNT(*) FROM admin WHERE admin_name LIKE ?',
            array(Fixture::PREFIX . '%')
        ));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = array();

        foreach (array(
            'admin', 'domain', 'subdomain', 'domain_aliasses', 'subdomain_alias',
            'mail_users', 'ftp_users', 'sql_database', 'sql_user', 'domain_dns',
            'hosting_plans', 'reseller_props', 'server_ips'
        ) as $table) {
            $counts[$table] = (int)$this->db->value('SELECT COUNT(*) FROM `' . $table . '`');
        }

        return $counts;
    }
}
```

- [ ] **Step 4: Run the tests to verify they fail**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Test\Integration\Fixture` not found.

- [ ] **Step 5: Write `test/integration/Fixture.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use InvalidArgumentException;

/**
 * One reseller, two of its customers, a second reseller with a customer of its
 * own, and one of every object hanging off the first customer.
 *
 * That shape is what spec section 17's authorisation matrix needs: "the owner
 * may reach it", "a sibling of the same reseller may not", "another reseller's
 * customer may not" and "the owning reseller may" are all answerable against
 * this one seed.
 *
 * Everything happens inside a transaction that rollBack() undoes. A fixture
 * that committed would leave rows on the reference box, and the next run would
 * either collide on admin.admin_name or pass because of the previous run.
 */
final class Fixture
{
    /** Every name this fixture writes starts with this. */
    const PREFIX = 'sgwt';

    /** @var Db */
    private $db;

    /** @var bool */
    private $open = false;

    /** @var array<string, int|string> */
    private $ids = array();

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function seed(): void
    {
        if ($this->open) {
            return;
        }

        $this->db->pdo()->beginTransaction();
        $this->open = true;

        $now = 1767225600;   // 2026-01-01T00:00:00Z, fixed so dates are assertable

        $this->ids['admin'] = (int)$this->db->value(
            "SELECT admin_id FROM admin WHERE admin_type = 'admin' ORDER BY admin_id LIMIT 1"
        );

        $this->ids['ip'] = $this->insert('server_ips', array(
            'ip_number'      => '203.0.113.7',   // TEST-NET-3, never routable
            'ip_netmask'     => 24,
            'ip_card'        => 'eth0',
            'ip_config_mode' => 'manual',
            'ip_status'      => 'ok'
        ));

        $this->ids['reseller'] = $this->account(
            self::PREFIX . 'reseller', 'reseller', $this->ids['admin'], $now
        );
        $this->ids['otherReseller'] = $this->account(
            self::PREFIX . 'other', 'reseller', $this->ids['admin'], $now
        );

        $this->resellerProps((int)$this->ids['reseller'], (int)$this->ids['ip']);
        $this->resellerProps((int)$this->ids['otherReseller'], (int)$this->ids['ip']);

        $this->ids['customer'] = $this->account(
            self::PREFIX . 'customer', 'user', $this->ids['reseller'], $now
        );
        $this->ids['sibling'] = $this->account(
            self::PREFIX . 'sibling', 'user', $this->ids['reseller'], $now
        );
        $this->ids['otherCustomer'] = $this->account(
            self::PREFIX . 'stranger', 'user', $this->ids['otherReseller'], $now
        );

        $this->ids['domain'] = $this->domain(
            (int)$this->ids['customer'], $this->domainName(), $now
        );
        $this->ids['siblingDomain'] = $this->domain(
            (int)$this->ids['sibling'], self::PREFIX . 'sibling.test', $now
        );
        $this->ids['otherDomain'] = $this->domain(
            (int)$this->ids['otherCustomer'], self::PREFIX . 'stranger.test', $now
        );

        $this->ids['subdomain'] = $this->insert('subdomain', array(
            'domain_id'               => $this->ids['domain'],
            'subdomain_name'          => 'shop',
            'subdomain_mount'         => '/shop',
            'subdomain_document_root' => '/htdocs',
            'subdomain_url_forward'   => 'no',
            'subdomain_host_forward'  => 'Off',
            'subdomain_wildcard_alias'=> 'no',
            'subdomain_status'        => 'ok'
        ));

        $this->ids['alias'] = $this->insert('domain_aliasses', array(
            'domain_id'           => $this->ids['domain'],
            'alias_name'          => $this->aliasName(),
            'alias_status'        => 'ok',
            'alias_mount'         => '/alias',
            'alias_document_root' => '/htdocs',
            'alias_ip_id'         => $this->ids['ip'],
            'url_forward'         => 'https://example.net/',
            'type_forward'        => '301',
            'host_forward'        => 'Off',
            'wildcard_alias'      => 'no',
            'external_mail'       => 'off'
        ));

        $this->ids['aliasSubdomain'] = $this->insert('subdomain_alias', array(
            'alias_id'                      => $this->ids['alias'],
            'subdomain_alias_name'          => 'blog',
            'subdomain_alias_mount'         => '/blog',
            'subdomain_alias_document_root' => '/htdocs',
            'subdomain_alias_url_forward'   => 'no',
            'subdomain_alias_host_forward'  => 'Off',
            'subdomain_alias_wildcard_alias'=> 'yes',
            'subdomain_alias_status'        => 'toadd'   // one unsettled object
        ));

        $this->ids['mailbox'] = $this->insert('mail_users', array(
            'mail_acc'               => 'sales',
            'mail_pass'              => '_no_',
            'mail_forward'           => null,
            'domain_id'              => $this->ids['domain'],
            'mail_type'              => 'normal_mail',
            'sub_id'                 => 0,
            'status'                 => 'ok',
            'po_active'              => 'yes',
            'mail_auto_respond'      => 0,
            'mail_auto_respond_text' => null,
            'quota'                  => 104857600,
            'mail_addr'              => 'sales@' . $this->domainName()
        ));

        $this->ids['forward'] = $this->insert('mail_users', array(
            'mail_acc'               => 'hello',
            'mail_pass'              => '_no_',
            'mail_forward'           => 'a@example.net,b@example.net',
            'domain_id'              => $this->ids['domain'],
            'mail_type'              => 'alssub_forward',
            'sub_id'                 => $this->ids['aliasSubdomain'],
            'status'                 => 'ok',
            'po_active'              => 'no',
            'mail_auto_respond'      => 1,
            'mail_auto_respond_text' => 'On holiday.',
            'quota'                  => 0,
            'mail_addr'              => 'hello@blog.' . $this->aliasName()
        ));

        $this->ids['ftpUser'] = self::PREFIX . 'ftp@' . $this->domainName();
        $this->insert('ftp_users', array(
            'userid'   => $this->ids['ftpUser'],
            'admin_id' => $this->ids['customer'],
            'passwd'   => 'x',
            'uid'      => 2000,
            'gid'      => 2000,
            'shell'    => '/bin/sh',
            'homedir'  => '/var/www/virtual/' . $this->domainName(),
            'status'   => 'ok'
        ));

        $this->ids['sqlDatabase'] = $this->insert('sql_database', array(
            'domain_id' => $this->ids['domain'],
            'sqld_name' => self::PREFIX . '_shop'
        ));

        $this->ids['sqlUser'] = $this->insert('sql_user', array(
            'sqld_id'   => $this->ids['sqlDatabase'],
            'sqlu_name' => self::PREFIX . '_u1',
            'sqlu_host' => 'localhost'
        ));

        $this->ids['dnsRecord'] = $this->insert('domain_dns', array(
            'domain_id'         => $this->ids['domain'],
            'alias_id'          => 0,
            'domain_dns'        => 'mail',
            'domain_class'      => 'IN',
            'domain_type'       => 'A',
            'domain_text'       => '203.0.113.7',
            'owned_by'          => 'custom_dns_feature',
            'domain_dns_status' => 'ok'
        ));

        $this->ids['hostingPlan'] = $this->insert('hosting_plans', array(
            'reseller_id' => $this->ids['reseller'],
            'name'        => self::PREFIX . ' plan',
            'description' => 'A plan for the tests.',
            'props'       => '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
                . 'no;no;no;yes;10;5;30;60;128;_no_;_yes_;104857600',
            'status'      => 1
        ));
    }

    public function rollBack(): void
    {
        if (!$this->open) {
            return;
        }

        $this->open = false;
        $this->db->pdo()->rollBack();
        $this->ids = array();
    }

    public function adminId(): int         { return (int)$this->id('admin'); }
    public function resellerId(): int      { return (int)$this->id('reseller'); }
    public function otherResellerId(): int { return (int)$this->id('otherReseller'); }
    public function customerId(): int      { return (int)$this->id('customer'); }
    public function siblingId(): int       { return (int)$this->id('sibling'); }
    public function otherCustomerId(): int { return (int)$this->id('otherCustomer'); }
    public function domainId(): int        { return (int)$this->id('domain'); }
    // The sibling's and the stranger's domains, so that a batching test can
    // ask about several distinct parents. Without them the only assertion
    // available is that one key asked for twice costs one query, which is
    // de-duplication rather than batching.
    public function siblingDomainId(): int { return (int)$this->id('siblingDomain'); }
    public function otherDomainId(): int   { return (int)$this->id('otherDomain'); }
    public function subdomainId(): int     { return (int)$this->id('subdomain'); }
    public function aliasId(): int         { return (int)$this->id('alias'); }
    public function aliasSubdomainId(): int{ return (int)$this->id('aliasSubdomain'); }
    public function mailboxId(): int       { return (int)$this->id('mailbox'); }
    public function forwardId(): int       { return (int)$this->id('forward'); }
    public function ftpUserId(): string    { return (string)$this->id('ftpUser'); }
    public function sqlDatabaseId(): int   { return (int)$this->id('sqlDatabase'); }
    public function sqlUserId(): int       { return (int)$this->id('sqlUser'); }
    public function dnsRecordId(): int     { return (int)$this->id('dnsRecord'); }
    public function hostingPlanId(): int   { return (int)$this->id('hostingPlan'); }
    public function ipId(): int            { return (int)$this->id('ip'); }

    public function domainName(): string    { return self::PREFIX . 'customer.test'; }
    public function aliasName(): string     { return self::PREFIX . 'alias.test'; }
    public function subdomainName(): string { return 'shop.' . $this->domainName(); }

    /**
     * @param string $who customer|sibling|otherCustomer|reseller|otherReseller|admin
     * @throws InvalidArgumentException
     */
    public function identity(string $who): Identity
    {
        $accounts = array(
            'customer'      => array('customer', 'user', 'reseller'),
            'sibling'       => array('sibling', 'user', 'reseller'),
            'otherCustomer' => array('otherCustomer', 'user', 'otherReseller'),
            'reseller'      => array('reseller', 'reseller', 'admin'),
            'otherReseller' => array('otherReseller', 'reseller', 'admin'),
            'admin'         => array('admin', 'admin', null)
        );

        if (!isset($accounts[$who])) {
            throw new InvalidArgumentException(sprintf('No fixture account "%s".', $who));
        }

        list($key, $type, $parent) = $accounts[$who];

        return new Identity(
            (int)$this->id($key),
            self::PREFIX . $key,
            $type,
            $parent === null ? null : (int)$this->id($parent),
            self::PREFIX . $key . '@example.test',
            array(),
            null
        );
    }

    /**
     * @return int|string
     */
    private function id(string $key)
    {
        if (!array_key_exists($key, $this->ids)) {
            throw new InvalidArgumentException(sprintf(
                'The fixture has no "%s"; call seed() first.', $key
            ));
        }

        return $this->ids[$key];
    }

    private function account(string $name, string $type, int $createdBy, int $now): int
    {
        return $this->insert('admin', array(
            'admin_name'    => $name,
            'admin_pass'    => 'x',
            'admin_type'    => $type,
            'admin_sys_uid' => 0,
            'admin_sys_gid' => 0,
            'domain_created'=> $now,
            'customer_id'   => 'REF-' . $name,
            'created_by'    => $createdBy,
            'fname'         => 'Test',
            'lname'         => ucfirst($type),
            'gender'        => 'U',
            'firm'          => 'Test Ltd',
            'zip'           => '1234',
            'city'          => 'Testville',
            'state'         => 'Testshire',
            'country'       => 'NZ',
            'email'         => $name . '@example.test',
            'phone'         => '+64 3 000 0000',
            'fax'           => null,
            'street1'       => '1 Test Street',
            'street2'       => null,
            'admin_status'  => 'ok'
        ));
    }

    private function resellerProps(int $resellerId, int $ipId): void
    {
        $this->insert('reseller_props', array(
            'reseller_id'          => $resellerId,
            'current_dmn_cnt'      => 0,
            'max_dmn_cnt'          => 20,
            'current_sub_cnt'      => 0,
            'max_sub_cnt'          => 100,
            'current_als_cnt'      => 0,
            'max_als_cnt'          => 50,
            'current_mail_cnt'     => 0,
            'max_mail_cnt'         => 0,          // unlimited
            'current_ftp_cnt'      => 0,
            'max_ftp_cnt'          => 40,
            'current_sql_db_cnt'   => 0,
            'max_sql_db_cnt'       => 30,
            'current_sql_user_cnt' => 0,
            'max_sql_user_cnt'     => 30,
            'current_disk_amnt'    => 0,
            'max_disk_amnt'        => 51200,      // MiB
            'current_traff_amnt'   => 0,
            'max_traff_amnt'       => 102400,     // MiB
            'support_system'       => 'yes',
            'reseller_ips'         => $ipId . ';'
        ));
    }

    private function domain(int $adminId, string $name, int $now): int
    {
        return $this->insert('domain', array(
            'domain_name'          => $name,
            'domain_admin_id'      => $adminId,
            'domain_created'       => $now,
            'domain_expires'       => $now + 31536000,
            'domain_last_modified' => $now,
            'domain_mailacc_limit' => 0,          // unlimited
            'domain_ftpacc_limit'  => -1,         // withheld
            'domain_traffic_limit' => 10240,      // MiB
            'domain_sqld_limit'    => 2,
            'domain_sqlu_limit'    => 2,
            'domain_status'        => 'ok',
            'domain_alias_limit'   => 5,
            'domain_subd_limit'    => 10,
            'domain_ip_id'         => $this->ids['ip'],
            'domain_disk_limit'    => 5120,       // MiB
            'domain_disk_usage'    => 1048576,    // bytes
            'domain_disk_file'     => 524288,
            'domain_disk_mail'     => 262144,
            'domain_disk_sql'      => 262144,
            'domain_php'           => 'yes',
            'domain_cgi'           => 'yes',
            'allowbackup'          => 'dmn|sql|mail',
            'domain_dns'           => 'yes',
            'phpini_perm_system'   => 'yes',
            'phpini_perm_allow_url_fopen'   => 'yes',
            'phpini_perm_display_errors'    => 'no',
            'phpini_perm_disable_functions' => 'no',
            'phpini_perm_mail_function'     => 'yes',
            'domain_external_mail' => 'yes',
            'external_mail'        => 'off',
            'web_folder_protection' => 'yes',
            'mail_quota'           => 1073741824,  // bytes
            'document_root'        => '/htdocs',
            'url_forward'          => 'no',
            'host_forward'         => 'Off',
            'wildcard_alias'       => 'no'
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return int The auto-increment identifier, or 0 for a table without one.
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (' . $this->db->placeholders(count($columns)) . ')';

        // Not Db::rows(): fetchAll() on a statement with no result set is not
        // something every PDO driver tolerates.
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute(array_values($row));

        return (int)$this->db->pdo()->lastInsertId();
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
tools/test.sh
```

Expected: PASS, `FixtureTest` 5 methods. If the whole integration suite reports
"skipped", the panel is not installed where `IntegrationTestCase::IMSCP_LIB`
expects it and the tests have proved nothing — fix that before continuing.

- [ ] **Step 7: Commit**

```bash
git add composer.json test/integration/Fixture.php test/integration/FixtureTest.php
git commit -m "Seed the whole customer graph inside a rolled-back transaction

Every database-backed test in this phase needs the same shape: one reseller
with two customers, a second reseller with a customer of its own, and one of
every object hanging off the first customer, so that the owner, a sibling, a
stranger and the owning reseller are all answerable against one seed.

The seed lives inside a transaction that is rolled back afterwards, because a
fixture that commits leaves rows on the reference box and the next run either
collides on a unique key or passes because of the previous run. The ownership
chain the fixture writes is asserted rather than assumed: a fixture wired to
the wrong parent would make every authorisation test pass for the wrong reason.

composer.json gains an autoload-dev mapping for the test namespace, which was
missing: PHPUnit finds test files by path, but a shared base class or helper
needs the autoloader to find it."
```

---

## Task 9: The Anorm models and the virtual-host repository — `sonnet`

Thirteen models, one per i-MSCP table the read schema touches, plus the one
repository that Anorm cannot express: the four-way `dmn`/`sub`/`als`/`alssub`
union behind spec §7.3's `VirtualHost` interface.

**The property names are the column names, verbatim.** That is not a style
choice, it is what makes Anorm's batch loaders correct. Both IN-clause loaders
use the same string as a PHP property on the source model *and* as a SQL column
on the related table:
`OneHasManyBatchLoader::batchLoad()` reads `$model->{$relationship->getPrimaryKey()}`
and then emits ``WHERE `{$relationship->getForeignKey()}` IN (…)``, and
`ManyHasOneBatchLoader` does the mirror image. A camelCase property with a
snake_case column would make one of the two wrong, silently, at run time. So
every model declares `const COLUMNS` and builds its mapper's map with
`array_combine(self::COLUMNS, self::COLUMNS)`, which makes an identity map the
only map that can exist.

Two more constraints the library imposes, both measured in
`vendor/saygoweb/anorm/src`:

- Both loaders instantiate the related model with `new $relatedClass($pdo)` — one
  argument. `Anorm\Model::__construct()` takes `(\PDO, DataMapper)`. Every model
  here therefore declares `__construct(\PDO $pdo)` and builds its own mapper.
- Properties are assigned straight from `PDO::FETCH_ASSOC`, so they hold
  strings. They are declared **untyped**; a typed property would throw on the
  first row. Casting is the resolver's job.

Relationship property names (`subdomains`, `customer`, …) are declared on the
model too, because `distributeBatchResults()` assigns to them and an undeclared
one is a deprecated dynamic property on PHP 8.2 and later.

Only the columns the read schema needs are mapped. `SELECT *` fetches the rest
and `DataMapper::readArray()` ignores anything not in the map.

**Files:**
- Create: `Model/AdminModel.php`, `Model/DomainModel.php`, `Model/SubdomainModel.php`,
  `Model/DomainAliasModel.php`, `Model/AliasSubdomainModel.php`,
  `Model/ServerIpModel.php`, `Model/MailAccountModel.php`, `Model/FtpUserModel.php`,
  `Model/SqlDatabaseModel.php`, `Model/SqlUserModel.php`, `Model/DnsRecordModel.php`,
  `Model/ResellerPropsModel.php`, `Model/HostingPlanModel.php`
- Create: `Repository/VirtualHosts.php`
- Create: `test/unit/Model/ModelMappingTest.php`,
  `test/integration/VirtualHostsTest.php`

**Interfaces:**
- Consumes:
  - `Db` (Task 2), `Fixture` (Task 8), `IntegrationTestCase` (Task 2)
  - `NodeType` tag constants and `NodeType::graphqlType()` (Task 1)
  - `Provisioning::PENDING_STATUSES` (plan 1)
  - `Anorm\Model`, `Anorm\DataMapper::create(\PDO, string $table, array $map)`
- Produces:
  - Every model: `const TABLE`, `const PRIMARY_KEY`, `const COLUMNS`,
    `__construct(\PDO $pdo)`, one untyped public property per column, and one
    untyped public property per relationship.
  - Relationship names, which later tasks pass to `BatchLoader`:
    - `AdminModel`: `reseller` (many-has-one), `domains`, `ftpUsers` (one-has-many)
    - `DomainModel`: `customer`, `ipAddress` (many-has-one);
      `mailAccounts`, `sqlDatabases`, `dnsRecords` (one-has-many)
    - `DomainAliasModel`: `domain` (many-has-one)
    - `SubdomainModel`: `domain` (many-has-one)
    - `AliasSubdomainModel`: `alias` (many-has-one)
    - `MailAccountModel`: `domain` (many-has-one)
    - `FtpUserModel`: `customer` (many-has-one)
    - `SqlDatabaseModel`: `domain` (many-has-one), `users` (one-has-many)
    - `SqlUserModel`: `database` (many-has-one)
    - `DnsRecordModel`: `domain` (many-has-one)
    - `HostingPlanModel`: `reseller` (many-has-one)
    - `ResellerPropsModel`: `reseller` (many-has-one)
    - `ServerIpModel`: none
  - `new VirtualHosts(Db $db)`
  - `VirtualHosts::KIND_DMN|KIND_SUB|KIND_ALS|KIND_ALSSUB` — `'dmn'|'sub'|'als'|'alssub'`
  - `VirtualHosts::tagFor(string $kind): string`, `VirtualHosts::kindFor(string $tag): string`
  - `VirtualHosts::ofKind(string $kind, array $domainIds): array` — `domainId => row[]`
  - `VirtualHosts::aliasSubdomainsOf(array $aliasIds): array` — `aliasId => row[]`
  - `VirtualHosts::forDomains(array $domainIds): array` — `domainId => row[]`, all four kinds
  - `VirtualHosts::byKeys(array $keys): array` — `"kind:id" => row`, `$keys` a list of `array($kind, $id)`
  - `VirtualHosts::pendingFor(array $customerAdminIds): array` — a list of
    `array('tag' => string, 'key' => int|string, 'status' => string)`
  - The normalised vhost row, which every later resolver shapes:
    `tag`, `kind`, `key`, `domainId`, `ownerId`, `name`, `label`, `parentTag`,
    `parentKey`, `mountPoint`, `documentRoot`, `urlForward`, `typeForward`,
    `hostForward`, `wildcard`, `status`, `ipId`

- [ ] **Step 1: Write the failing model mapping test**

`test/unit/Model/ModelMappingTest.php`. It needs no database: every assertion is
about the class constants, which is exactly where the identity-map rule lives.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Model;

use iMSCP\Plugin\SGW_GraphQL\Model\AdminModel;
use iMSCP\Plugin\SGW_GraphQL\Model\AliasSubdomainModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DnsRecordModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainAliasModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;
use iMSCP\Plugin\SGW_GraphQL\Model\FtpUserModel;
use iMSCP\Plugin\SGW_GraphQL\Model\HostingPlanModel;
use iMSCP\Plugin\SGW_GraphQL\Model\MailAccountModel;
use iMSCP\Plugin\SGW_GraphQL\Model\ResellerPropsModel;
use iMSCP\Plugin\SGW_GraphQL\Model\ServerIpModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SqlDatabaseModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SqlUserModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SubdomainModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ModelMappingTest extends TestCase
{
    public function models(): array
    {
        return array(
            'admin'           => array(AdminModel::class, 'admin', 'admin_id'),
            'domain'          => array(DomainModel::class, 'domain', 'domain_id'),
            'subdomain'       => array(SubdomainModel::class, 'subdomain', 'subdomain_id'),
            'domain alias'    => array(DomainAliasModel::class, 'domain_aliasses', 'alias_id'),
            'alias subdomain' => array(
                AliasSubdomainModel::class, 'subdomain_alias', 'subdomain_alias_id'
            ),
            'server ip'       => array(ServerIpModel::class, 'server_ips', 'ip_id'),
            'mail account'    => array(MailAccountModel::class, 'mail_users', 'mail_id'),
            'ftp user'        => array(FtpUserModel::class, 'ftp_users', 'userid'),
            'sql database'    => array(SqlDatabaseModel::class, 'sql_database', 'sqld_id'),
            'sql user'        => array(SqlUserModel::class, 'sql_user', 'sqlu_id'),
            'dns record'      => array(DnsRecordModel::class, 'domain_dns', 'domain_dns_id'),
            'reseller props'  => array(ResellerPropsModel::class, 'reseller_props', 'id'),
            'hosting plan'    => array(HostingPlanModel::class, 'hosting_plans', 'id')
        );
    }

    /**
     * @dataProvider models
     */
    public function testTheTableAndPrimaryKeyAreTheImscpOnes(
        string $class, string $table, string $primaryKey
    ): void {
        self::assertSame($table, constant($class . '::TABLE'));
        self::assertSame($primaryKey, constant($class . '::PRIMARY_KEY'));
        self::assertContains($primaryKey, constant($class . '::COLUMNS'));
    }

    /**
     * @dataProvider models
     */
    public function testEveryColumnIsADeclaredPublicProperty(
        string $class, string $table, string $primaryKey
    ): void {
        // Anorm's DataMapper::readArray() assigns straight to $model->$property.
        // A column with no declared property is a dynamic property, which PHP
        // 8.2 deprecates and 8.3 still warns about - and this plugin must lint
        // and run clean under 8.3.
        $declared = array();

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                $declared[] = $property->getName();
            }
        }

        foreach (constant($class . '::COLUMNS') as $column) {
            self::assertContains($column, $declared, $class . '::$' . $column);
        }
    }

    /**
     * @dataProvider models
     */
    public function testNoPropertyIsTyped(
        string $class, string $table, string $primaryKey
    ): void {
        // PDO::FETCH_ASSOC hands back strings. A typed property would throw a
        // TypeError on the first row read, and nothing in the unit suite would
        // see it because the unit suite never reads a row.
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                self::assertFalse(
                    $property->hasType(),
                    $class . '::$' . $property->getName() . ' must be untyped'
                );
            }
        }
    }

    /**
     * @dataProvider models
     */
    public function testColumnNamesAreUniqueAndSnakeCase(
        string $class, string $table, string $primaryKey
    ): void {
        // The map Anorm is given is array_combine(COLUMNS, COLUMNS), so a
        // duplicate would silently shorten it, and a camelCase entry would name
        // a column that does not exist in i-MSCP.
        $columns = constant($class . '::COLUMNS');

        self::assertSame(array_values(array_unique($columns)), array_values($columns));

        foreach ($columns as $column) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $column, $class);
        }
    }

    public function testEveryRelationshipTargetIsDeclaredToo(): void
    {
        // Anorm's distributeBatchResults() assigns the loaded relation to
        // $model->{$relationshipName}. Same dynamic-property problem, one level
        // out, and this is the list later tasks pass to BatchLoader by name.
        $expected = array(
            AdminModel::class          => array('reseller', 'domains', 'ftpUsers'),
            DomainModel::class         => array(
                'customer', 'ipAddress', 'mailAccounts', 'sqlDatabases', 'dnsRecords'
            ),
            SubdomainModel::class      => array('domain'),
            DomainAliasModel::class    => array('domain'),
            AliasSubdomainModel::class => array('alias'),
            MailAccountModel::class    => array('domain'),
            FtpUserModel::class        => array('customer'),
            SqlDatabaseModel::class    => array('domain', 'users'),
            SqlUserModel::class        => array('database'),
            DnsRecordModel::class      => array('domain'),
            HostingPlanModel::class    => array('reseller'),
            ResellerPropsModel::class  => array('reseller')
        );

        foreach ($expected as $class => $relationships) {
            $declared = array();

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if ($property->isPublic() && !$property->isStatic()) {
                    $declared[] = $property->getName();
                }
            }

            foreach ($relationships as $relationship) {
                self::assertContains($relationship, $declared, $class . '::$' . $relationship);
            }
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL — none of the `Model\*` classes exist.

- [ ] **Step 3: Write `Model/AdminModel.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `admin` table: administrators, resellers and customers alike, told
 * apart by admin_type.
 *
 * Property names are the column names verbatim. Anorm's IN-clause batch loaders
 * use the same string as a PHP property on one side and a SQL column on the
 * other, so any translation between the two would be wrong on one side.
 */
class AdminModel extends Model
{
    const TABLE = 'admin';
    const PRIMARY_KEY = 'admin_id';

    const COLUMNS = array(
        'admin_id', 'admin_name', 'admin_type', 'admin_sys_name', 'domain_created',
        'customer_id', 'created_by', 'fname', 'lname', 'gender', 'firm', 'zip',
        'city', 'state', 'country', 'email', 'phone', 'fax', 'street1', 'street2',
        'admin_status'
    );

    // Untyped on purpose: PDO::FETCH_ASSOC hands back strings.
    public $admin_id;
    public $admin_name;
    public $admin_type;
    public $admin_sys_name;
    public $domain_created;
    public $customer_id;
    public $created_by;
    public $fname;
    public $lname;
    public $gender;
    public $firm;
    public $zip;
    public $city;
    public $state;
    public $country;
    public $email;
    public $phone;
    public $fax;
    public $street1;
    public $street2;
    public $admin_status;

    /** @var AdminModel|null */
    public $reseller;

    /** @var DomainModel[]|null */
    public $domains;

    /** @var FtpUserModel[]|null */
    public $ftpUsers;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        // admin.created_by is the account that created this one: the reseller
        // for a customer, the administrator for a reseller. Spec section 2.2.
        $this->belongsTo(AdminModel::class, 'created_by', 'admin_id', 'reseller');
        $this->hasMany(DomainModel::class, 'domain_admin_id', 'admin_id', 'domains');
        // ftp_users hangs off the customer, not off the domain.
        $this->hasMany(FtpUserModel::class, 'admin_id', 'admin_id', 'ftpUsers');
    }
}
```

- [ ] **Step 4: Write `Model/DomainModel.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `domain` table: one row per customer, carrying the customer's
 * limits, disk figures and feature flags as well as the main vhost.
 */
class DomainModel extends Model
{
    const TABLE = 'domain';
    const PRIMARY_KEY = 'domain_id';

    const COLUMNS = array(
        'domain_id', 'domain_name', 'domain_admin_id', 'domain_created',
        'domain_expires', 'domain_mailacc_limit', 'domain_ftpacc_limit',
        'domain_traffic_limit', 'domain_sqld_limit', 'domain_sqlu_limit',
        'domain_status', 'domain_alias_limit', 'domain_subd_limit', 'domain_ip_id',
        'domain_disk_limit', 'domain_disk_usage', 'domain_disk_file',
        'domain_disk_mail', 'domain_disk_sql', 'domain_php', 'domain_cgi',
        'allowbackup', 'domain_dns', 'phpini_perm_system',
        'phpini_perm_allow_url_fopen', 'phpini_perm_display_errors',
        'phpini_perm_disable_functions', 'domain_external_mail', 'mail_quota',
        'document_root', 'url_forward', 'type_forward', 'host_forward',
        'wildcard_alias'
    );

    public $domain_id;
    public $domain_name;
    public $domain_admin_id;
    public $domain_created;
    public $domain_expires;
    public $domain_mailacc_limit;
    public $domain_ftpacc_limit;
    public $domain_traffic_limit;
    public $domain_sqld_limit;
    public $domain_sqlu_limit;
    public $domain_status;
    public $domain_alias_limit;
    public $domain_subd_limit;
    public $domain_ip_id;
    public $domain_disk_limit;
    public $domain_disk_usage;
    public $domain_disk_file;
    public $domain_disk_mail;
    public $domain_disk_sql;
    public $domain_php;
    public $domain_cgi;
    public $allowbackup;
    public $domain_dns;
    public $phpini_perm_system;
    public $phpini_perm_allow_url_fopen;
    public $phpini_perm_display_errors;
    public $phpini_perm_disable_functions;
    public $domain_external_mail;
    public $mail_quota;
    public $document_root;
    public $url_forward;
    public $type_forward;
    public $host_forward;
    public $wildcard_alias;

    /** @var AdminModel|null */
    public $customer;

    /** @var ServerIpModel|null */
    public $ipAddress;

    /** @var MailAccountModel[]|null */
    public $mailAccounts;

    /** @var SqlDatabaseModel[]|null */
    public $sqlDatabases;

    /** @var DnsRecordModel[]|null */
    public $dnsRecords;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'domain_admin_id', 'admin_id', 'customer');
        $this->belongsTo(ServerIpModel::class, 'domain_ip_id', 'ip_id', 'ipAddress');
        $this->hasMany(MailAccountModel::class, 'domain_id', 'domain_id', 'mailAccounts');
        $this->hasMany(SqlDatabaseModel::class, 'domain_id', 'domain_id', 'sqlDatabases');
        $this->hasMany(DnsRecordModel::class, 'domain_id', 'domain_id', 'dnsRecords');

        // Subdomains and aliases are deliberately not relationships here: a
        // Subdomain's name is its label joined to the parent's name, which the
        // IN-clause loader's single-table SELECT cannot produce. They come from
        // Repository\VirtualHosts instead.
    }
}
```

- [ ] **Step 5: Write the four vhost-table models**

`Model/SubdomainModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/** i-MSCP's `subdomain` table: subdomains of a customer's main domain. */
class SubdomainModel extends Model
{
    const TABLE = 'subdomain';
    const PRIMARY_KEY = 'subdomain_id';

    const COLUMNS = array(
        'subdomain_id', 'domain_id', 'subdomain_name', 'subdomain_mount',
        'subdomain_document_root', 'subdomain_url_forward', 'subdomain_type_forward',
        'subdomain_host_forward', 'subdomain_wildcard_alias', 'subdomain_status'
    );

    public $subdomain_id;
    public $domain_id;
    public $subdomain_name;
    public $subdomain_mount;
    public $subdomain_document_root;
    public $subdomain_url_forward;
    public $subdomain_type_forward;
    public $subdomain_host_forward;
    public $subdomain_wildcard_alias;
    public $subdomain_status;

    /** @var DomainModel|null */
    public $domain;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
    }
}
```

`Model/DomainAliasModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/** i-MSCP's `domain_aliasses` table. The spelling is i-MSCP's. */
class DomainAliasModel extends Model
{
    const TABLE = 'domain_aliasses';
    const PRIMARY_KEY = 'alias_id';

    const COLUMNS = array(
        'alias_id', 'domain_id', 'alias_name', 'alias_status', 'alias_mount',
        'alias_document_root', 'alias_ip_id', 'url_forward', 'type_forward',
        'host_forward', 'wildcard_alias'
    );

    public $alias_id;
    public $domain_id;
    public $alias_name;
    public $alias_status;
    public $alias_mount;
    public $alias_document_root;
    public $alias_ip_id;
    public $url_forward;
    public $type_forward;
    public $host_forward;
    public $wildcard_alias;

    /** @var DomainModel|null */
    public $domain;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
    }
}
```

`Model/AliasSubdomainModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `subdomain_alias` table: subdomains of a domain alias.
 *
 * A separate table from `subdomain` only because `subdomain` has a domain_id
 * column. To a client this is still a Subdomain (spec section 7.3), but its
 * identifier tag is AliasSubdomain, because the two tables have separate id
 * spaces (decision D1).
 */
class AliasSubdomainModel extends Model
{
    const TABLE = 'subdomain_alias';
    const PRIMARY_KEY = 'subdomain_alias_id';

    const COLUMNS = array(
        'subdomain_alias_id', 'alias_id', 'subdomain_alias_name',
        'subdomain_alias_mount', 'subdomain_alias_document_root',
        'subdomain_alias_url_forward', 'subdomain_alias_type_forward',
        'subdomain_alias_host_forward', 'subdomain_alias_wildcard_alias',
        'subdomain_alias_status'
    );

    public $subdomain_alias_id;
    public $alias_id;
    public $subdomain_alias_name;
    public $subdomain_alias_mount;
    public $subdomain_alias_document_root;
    public $subdomain_alias_url_forward;
    public $subdomain_alias_type_forward;
    public $subdomain_alias_host_forward;
    public $subdomain_alias_wildcard_alias;
    public $subdomain_alias_status;

    /** @var DomainAliasModel|null */
    public $alias;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainAliasModel::class, 'alias_id', 'alias_id', 'alias');
    }
}
```

`Model/ServerIpModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/** i-MSCP's `server_ips` table. */
class ServerIpModel extends Model
{
    const TABLE = 'server_ips';
    const PRIMARY_KEY = 'ip_id';

    const COLUMNS = array(
        'ip_id', 'ip_number', 'ip_netmask', 'ip_card', 'ip_config_mode', 'ip_status'
    );

    public $ip_id;
    public $ip_number;
    public $ip_netmask;
    public $ip_card;
    public $ip_config_mode;
    public $ip_status;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);
    }
}
```

- [ ] **Step 6: Write the mail, FTP, SQL and DNS models**

`Model/MailAccountModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `mail_users` table.
 *
 * sub_id names the vhost the address belongs to - a subdomain_id, alias_id or
 * subdomain_alias_id depending on mail_type, and 0 for the main domain. Which
 * of the three it is comes from Support\MailType::hostTypeOf().
 */
class MailAccountModel extends Model
{
    const TABLE = 'mail_users';
    const PRIMARY_KEY = 'mail_id';

    const COLUMNS = array(
        'mail_id', 'mail_acc', 'mail_forward', 'domain_id', 'mail_type', 'sub_id',
        'status', 'po_active', 'mail_auto_respond', 'mail_auto_respond_text',
        'quota', 'mail_addr'
    );

    public $mail_id;
    public $mail_acc;
    public $mail_forward;
    public $domain_id;
    public $mail_type;
    public $sub_id;
    public $status;
    public $po_active;
    public $mail_auto_respond;
    public $mail_auto_respond_text;
    public $quota;
    public $mail_addr;

    /** @var DomainModel|null */
    public $domain;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
    }
}
```

`Model/FtpUserModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `ftp_users` table.
 *
 * Its primary key is userid varchar(255) - 'user@domain' - which is why
 * Support\GlobalId grew encodeKey()/decodeKey() (decision D2). It is also the
 * one object that hangs off the customer rather than off the domain.
 */
class FtpUserModel extends Model
{
    const TABLE = 'ftp_users';
    const PRIMARY_KEY = 'userid';

    const COLUMNS = array('userid', 'admin_id', 'uid', 'gid', 'shell', 'homedir', 'status');

    public $userid;
    public $admin_id;
    public $uid;
    public $gid;
    public $shell;
    public $homedir;
    public $status;

    /** @var AdminModel|null */
    public $customer;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'admin_id', 'admin_id', 'customer');
    }
}
```

`passwd` is deliberately not mapped. Nothing in the read schema exposes it, and
an unmapped column cannot be read into a model and then leaked by a resolver
that returns the model wholesale.

`Model/SqlDatabaseModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `sql_database` table.
 *
 * There is no status column: SQL databases are created synchronously by the
 * panel rather than by the backend (spec section 2.1), which is why
 * SqlDatabase does not implement Provisioned.
 */
class SqlDatabaseModel extends Model
{
    const TABLE = 'sql_database';
    const PRIMARY_KEY = 'sqld_id';

    const COLUMNS = array('sqld_id', 'domain_id', 'sqld_name');

    public $sqld_id;
    public $domain_id;
    public $sqld_name;

    /** @var DomainModel|null */
    public $domain;

    /** @var SqlUserModel[]|null */
    public $users;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
        $this->hasMany(SqlUserModel::class, 'sqld_id', 'sqld_id', 'users');
    }
}
```

`Model/SqlUserModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `sql_user` table.
 *
 * One row per (user, database) grant, so the same sqlu_name appears once per
 * database it may reach. Spec section 7.7's SqlUser.databases is therefore a
 * list, and Counting.php counts DISTINCT sqlu_name against the limit.
 */
class SqlUserModel extends Model
{
    const TABLE = 'sql_user';
    const PRIMARY_KEY = 'sqlu_id';

    const COLUMNS = array('sqlu_id', 'sqld_id', 'sqlu_name', 'sqlu_host');

    public $sqlu_id;
    public $sqld_id;
    public $sqlu_name;
    public $sqlu_host;

    /** @var SqlDatabaseModel|null */
    public $database;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(SqlDatabaseModel::class, 'sqld_id', 'sqld_id', 'database');
    }
}
```

`Model/DnsRecordModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `domain_dns` table.
 *
 * alias_id is 0 for a record on the main domain and the alias_id otherwise
 * (gui/public/client/dns_edit.php:592), which is how DnsRecord.host picks
 * between a Domain and a DomainAlias.
 */
class DnsRecordModel extends Model
{
    const TABLE = 'domain_dns';
    const PRIMARY_KEY = 'domain_dns_id';

    const COLUMNS = array(
        'domain_dns_id', 'domain_id', 'alias_id', 'domain_dns', 'domain_class',
        'domain_type', 'domain_text', 'owned_by', 'domain_dns_status'
    );

    public $domain_dns_id;
    public $domain_id;
    public $alias_id;
    public $domain_dns;
    public $domain_class;
    public $domain_type;
    public $domain_text;
    public $owned_by;
    public $domain_dns_status;

    /** @var DomainModel|null */
    public $domain;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
    }
}
```

- [ ] **Step 7: Write the reseller-side models**

`Model/ResellerPropsModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `reseller_props` table: a reseller's limits and current counts.
 *
 * Its limit encoding is not the customer one: 0 means unlimited and there is no
 * withheld state (spec section 2.3), which is why Support\Quota has a separate
 * fromResellerLimit(). max_disk_amnt and max_traff_amnt are in MiB
 * (gui/public/reseller/index.php:158,177 multiply both by 1048576).
 */
class ResellerPropsModel extends Model
{
    const TABLE = 'reseller_props';
    const PRIMARY_KEY = 'id';

    const COLUMNS = array(
        'id', 'reseller_id', 'current_dmn_cnt', 'max_dmn_cnt', 'current_sub_cnt',
        'max_sub_cnt', 'current_als_cnt', 'max_als_cnt', 'current_mail_cnt',
        'max_mail_cnt', 'current_ftp_cnt', 'max_ftp_cnt', 'current_sql_db_cnt',
        'max_sql_db_cnt', 'current_sql_user_cnt', 'max_sql_user_cnt',
        'current_disk_amnt', 'max_disk_amnt', 'current_traff_amnt',
        'max_traff_amnt', 'support_system', 'reseller_ips'
    );

    public $id;
    public $reseller_id;
    public $current_dmn_cnt;
    public $max_dmn_cnt;
    public $current_sub_cnt;
    public $max_sub_cnt;
    public $current_als_cnt;
    public $max_als_cnt;
    public $current_mail_cnt;
    public $max_mail_cnt;
    public $current_ftp_cnt;
    public $max_ftp_cnt;
    public $current_sql_db_cnt;
    public $max_sql_db_cnt;
    public $current_sql_user_cnt;
    public $max_sql_user_cnt;
    public $current_disk_amnt;
    public $max_disk_amnt;
    public $current_traff_amnt;
    public $max_traff_amnt;
    public $support_system;
    public $reseller_ips;

    /** @var AdminModel|null */
    public $reseller;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'reseller_id', 'admin_id', 'reseller');
    }
}
```

`Model/HostingPlanModel.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;
// ... licence header ...

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `hosting_plans` table.
 *
 * `props` is the 25-field positional string Support\PlanProps decodes, and
 * `status` is a boolean in an int column: truthy means the plan is offered
 * (gui/public/reseller/hosting_plan.php:69).
 */
class HostingPlanModel extends Model
{
    const TABLE = 'hosting_plans';
    const PRIMARY_KEY = 'id';

    const COLUMNS = array('id', 'reseller_id', 'name', 'props', 'description', 'status');

    public $id;
    public $reseller_id;
    public $name;
    public $props;
    public $description;
    public $status;

    /** @var AdminModel|null */
    public $reseller;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'reseller_id', 'admin_id', 'reseller');
    }
}
```

- [ ] **Step 8: Run the model test to verify it passes**

Run: `tools/test.sh`
Expected: PASS, `ModelMappingTest` 53 methods (13 through each of the four data
provider cases, plus the relationship test).

- [ ] **Step 9: Write the failing `VirtualHosts` test**

`test/integration/VirtualHostsTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class VirtualHostsTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var VirtualHosts */
    private $vhosts;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->vhosts = new VirtualHosts($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    public function testForDomainsReturnsAllFourKinds(): void
    {
        $rows = $this->vhosts->forDomains(array($this->fixture->domainId()));
        $tags = array();

        foreach ($rows[$this->fixture->domainId()] as $row) {
            $tags[] = $row['tag'];
        }

        sort($tags);
        self::assertSame(
            array(
                NodeType::ALIAS_SUBDOMAIN, NodeType::DOMAIN,
                NodeType::DOMAIN_ALIAS, NodeType::SUBDOMAIN
            ),
            $tags
        );
    }

    public function testASubdomainNameIsTheLabelJoinedToItsParent(): void
    {
        // The reason subdomains do not come through an Anorm relationship: the
        // subdomain table stores 'shop', not 'shop.example.test', and a
        // single-table IN-clause SELECT cannot produce the difference.
        $rows = $this->vhosts->ofKind(
            VirtualHosts::KIND_SUB, array($this->fixture->domainId())
        );
        $row = $rows[$this->fixture->domainId()][0];

        self::assertSame('shop', $row['label']);
        self::assertSame($this->fixture->subdomainName(), $row['name']);
        self::assertSame(NodeType::DOMAIN, $row['parentTag']);
        self::assertSame($this->fixture->domainId(), $row['parentKey']);
    }

    public function testAnAliasSubdomainNameIsJoinedToItsAliasNotItsDomain(): void
    {
        $rows = $this->vhosts->aliasSubdomainsOf(array($this->fixture->aliasId()));
        $row = $rows[$this->fixture->aliasId()][0];

        self::assertSame('blog.' . $this->fixture->aliasName(), $row['name']);
        self::assertSame(NodeType::DOMAIN_ALIAS, $row['parentTag']);
        self::assertSame($this->fixture->aliasId(), $row['parentKey']);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $row['tag']);
    }

    public function testEveryRowCarriesTheOwningCustomer(): void
    {
        // Without this the resolvers would have to re-resolve ownership per
        // vhost, which is a query per row.
        foreach ($this->vhosts->forDomains(array($this->fixture->domainId())) as $rows) {
            foreach ($rows as $row) {
                self::assertSame($this->fixture->customerId(), $row['ownerId'], $row['tag']);
                self::assertSame($this->fixture->domainId(), $row['domainId'], $row['tag']);
            }
        }
    }

    public function testTheMainDomainIsMountedAtTheRoot(): void
    {
        $rows = $this->vhosts->ofKind(
            VirtualHosts::KIND_DMN, array($this->fixture->domainId())
        );
        $row = $rows[$this->fixture->domainId()][0];

        self::assertSame('/', $row['mountPoint']);
        self::assertSame('/htdocs', $row['documentRoot']);
        self::assertSame($this->fixture->ipId(), $row['ipId']);
        self::assertFalse($row['wildcard']);
    }

    public function testForwardingColumnsComeThroughUntouched(): void
    {
        $rows = $this->vhosts->ofKind(
            VirtualHosts::KIND_ALS, array($this->fixture->domainId())
        );
        $row = $rows[$this->fixture->domainId()][0];

        self::assertSame('https://example.net/', $row['urlForward']);
        self::assertSame('301', $row['typeForward']);
        self::assertSame('Off', $row['hostForward']);
    }

    public function testWildcardIsABooleanNotTheEnumString(): void
    {
        $rows = $this->vhosts->aliasSubdomainsOf(array($this->fixture->aliasId()));

        self::assertTrue($rows[$this->fixture->aliasId()][0]['wildcard']);
    }

    public function testByKeysLooksUpOneVhostOfEachKind(): void
    {
        $found = $this->vhosts->byKeys(array(
            array(VirtualHosts::KIND_DMN, $this->fixture->domainId()),
            array(VirtualHosts::KIND_SUB, $this->fixture->subdomainId()),
            array(VirtualHosts::KIND_ALS, $this->fixture->aliasId()),
            array(VirtualHosts::KIND_ALSSUB, $this->fixture->aliasSubdomainId())
        ));

        self::assertCount(4, $found);
        self::assertSame(
            $this->fixture->domainName(),
            $found[VirtualHosts::KIND_DMN . ':' . $this->fixture->domainId()]['name']
        );
        self::assertSame(
            'blog.' . $this->fixture->aliasName(),
            $found[VirtualHosts::KIND_ALSSUB . ':' . $this->fixture->aliasSubdomainId()]['name']
        );
    }

    public function testByKeysIssuesOneQueryPerKindAndNoMore(): void
    {
        // Four kinds, four queries, whatever the number of identifiers. A
        // per-identifier lookup here would be the N+1 behind MailAccount.host.
        $keys = array();

        for ($i = 0; $i < 6; $i++) {
            $keys[] = array(VirtualHosts::KIND_DMN, $this->fixture->domainId());
            $keys[] = array(VirtualHosts::KIND_SUB, $this->fixture->subdomainId());
        }

        $vhosts = $this->vhosts;
        $count = $this->db->countQueries(function () use ($vhosts, $keys) {
            $vhosts->byKeys($keys);
        });

        self::assertSame(2, $count);
    }

    public function testPendingFindsOnlyTheUnsettledObject(): void
    {
        // The fixture seeds exactly one object with a pending status: the alias
        // subdomain, at 'toadd'.
        $pending = $this->vhosts->pendingFor(array($this->fixture->customerId()));

        self::assertCount(1, $pending);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $pending[0]['tag']);
        self::assertSame($this->fixture->aliasSubdomainId(), (int)$pending[0]['key']);
        self::assertSame('toadd', $pending[0]['status']);
    }

    public function testPendingSeesAMailAccountTurningPending(): void
    {
        $this->db->pdo()
            ->prepare('UPDATE mail_users SET status = ? WHERE mail_id = ?')
            ->execute(array('tochange', $this->fixture->mailboxId()));

        $tags = array();

        foreach ($this->vhosts->pendingFor(array($this->fixture->customerId())) as $row) {
            $tags[] = $row['tag'];
        }

        sort($tags);
        self::assertSame(array(NodeType::ALIAS_SUBDOMAIN, NodeType::MAIL_ACCOUNT), $tags);
    }

    public function testAnEmptyIdentifierListIssuesNoQuery(): void
    {
        $vhosts = $this->vhosts;
        $count = $this->db->countQueries(function () use ($vhosts) {
            $vhosts->forDomains(array());
            $vhosts->byKeys(array());
            $vhosts->aliasSubdomainsOf(array());
            $vhosts->pendingFor(array());
        });

        self::assertSame(0, $count);
    }
}
```

- [ ] **Step 10: Run the test to verify it fails**

Run: `tools/test.sh`
Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts` not found.

- [ ] **Step 11: Write `Repository/VirtualHosts.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;
// ... licence header ...

use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use InvalidArgumentException;

/**
 * The four-way dmn/sub/als/alssub union behind spec section 7.3's VirtualHost
 * interface, and the unsettled-object sweep behind Query.pending.
 *
 * i-MSCP keys a vhost by the pair (type, id) with type in dmn|sub|als|alssub,
 * and the four tables differ in almost nothing but their column prefixes. The
 * union is written out here, once, so that no resolver has four code paths.
 *
 * This is where subdomains come from rather than from an Anorm relationship:
 * `subdomain` stores the label alone ('shop'), and the fully qualified name is
 * that label joined to the parent's name, which a single-table IN-clause SELECT
 * cannot produce.
 *
 * Not final, so that a resolver test can subclass it.
 */
class VirtualHosts
{
    const KIND_DMN    = 'dmn';
    const KIND_SUB    = 'sub';
    const KIND_ALS    = 'als';
    const KIND_ALSSUB = 'alssub';

    /** i-MSCP's vhost kind => this API's identifier tag. */
    const TAGS = array(
        self::KIND_DMN    => NodeType::DOMAIN,
        self::KIND_SUB    => NodeType::SUBDOMAIN,
        self::KIND_ALS    => NodeType::DOMAIN_ALIAS,
        self::KIND_ALSSUB => NodeType::ALIAS_SUBDOMAIN
    );

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function tagFor(string $kind): string
    {
        if (!isset(self::TAGS[$kind])) {
            throw new InvalidArgumentException(sprintf('Unknown vhost kind "%s".', $kind));
        }

        return self::TAGS[$kind];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function kindFor(string $tag): string
    {
        $kind = array_search($tag, self::TAGS, true);

        if ($kind === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a vhost type.', $tag));
        }

        return $kind;
    }

    /**
     * Every vhost of one kind belonging to the given customers' main domains.
     *
     * @param int[] $domainIds
     * @return array<int, array<int, array>> domainId => rows, ordered by name
     */
    public function ofKind(string $kind, array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));
        $grouped = array_fill_keys($domainIds, array());

        foreach ($this->db->rows($this->sqlFor($kind, "d.domain_id IN ($in)"), $domainIds) as $row) {
            $grouped[(int)$row['domain_id']][] = $this->normalise($kind, $row);
        }

        return $grouped;
    }

    /**
     * The subdomains of the given domain aliases.
     *
     * Keyed by alias_id rather than by domain_id, because that is the edge
     * DomainAlias.subdomains needs.
     *
     * @param int[] $aliasIds
     * @return array<int, array<int, array>> aliasId => rows
     */
    public function aliasSubdomainsOf(array $aliasIds): array
    {
        if ($aliasIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($aliasIds));
        $grouped = array_fill_keys($aliasIds, array());
        $sql = $this->sqlFor(self::KIND_ALSSUB, "sa.alias_id IN ($in)");

        foreach ($this->db->rows($sql, $aliasIds) as $row) {
            $grouped[(int)$row['parent_key']][] = $this->normalise(self::KIND_ALSSUB, $row);
        }

        return $grouped;
    }

    /**
     * Everything Apache serves for the given customers: four queries, whatever
     * the number of customers.
     *
     * @param int[] $domainIds
     * @return array<int, array<int, array>> domainId => rows
     */
    public function forDomains(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $grouped = array_fill_keys($domainIds, array());

        foreach (array_keys(self::TAGS) as $kind) {
            foreach ($this->ofKind($kind, $domainIds) as $domainId => $rows) {
                foreach ($rows as $row) {
                    $grouped[$domainId][] = $row;
                }
            }
        }

        return $grouped;
    }

    /**
     * Look up individual vhosts by (kind, id): one query per kind present,
     * never one per identifier.
     *
     * @param array<int, array{0: string, 1: int}> $keys
     * @return array<string, array> "kind:id" => row
     */
    public function byKeys(array $keys): array
    {
        $wanted = array();

        foreach ($keys as $key) {
            $wanted[$key[0]][(int)$key[1]] = true;
        }

        $found = array();

        foreach ($wanted as $kind => $ids) {
            $ids = array_keys($ids);
            $in = $this->db->placeholders(count($ids));
            $sql = $this->sqlFor($kind, $this->keyColumn($kind) . " IN ($in)");

            foreach ($this->db->rows($sql, $ids) as $row) {
                $normalised = $this->normalise($kind, $row);
                $found[$kind . ':' . $normalised['key']] = $normalised;
            }
        }

        return $found;
    }

    /**
     * Every object of the given customers that the backend has not finished
     * with. Spec section 8.2, and Query.pending.
     *
     * Eight queries, fixed, whatever the number of customers. The status
     * vocabulary is Provisioning::PENDING_STATUSES rather than a list written
     * here, so that the SQL and Provisioning::isSettled() cannot disagree.
     *
     * @param int[] $customerAdminIds
     * @return array<int, array{tag: string, key: int|string, status: string}>
     */
    public function pendingFor(array $customerAdminIds): array
    {
        if ($customerAdminIds === array()) {
            return array();
        }

        $customers = $this->db->placeholders(count($customerAdminIds));
        $states = $this->db->placeholders(count(Provisioning::PENDING_STATUSES));
        $bind = array_merge($customerAdminIds, Provisioning::PENDING_STATUSES);

        $queries = array(
            NodeType::CUSTOMER => "
                SELECT a.admin_id AS k, a.admin_status AS s
                FROM admin AS a
                WHERE a.admin_id IN ($customers) AND a.admin_status IN ($states)
            ",
            NodeType::DOMAIN => "
                SELECT d.domain_id AS k, d.domain_status AS s
                FROM domain AS d
                WHERE d.domain_admin_id IN ($customers) AND d.domain_status IN ($states)
            ",
            NodeType::SUBDOMAIN => "
                SELECT s.subdomain_id AS k, s.subdomain_status AS s
                FROM subdomain AS s
                JOIN domain AS d ON d.domain_id = s.domain_id
                WHERE d.domain_admin_id IN ($customers)
                    AND s.subdomain_status IN ($states)
            ",
            NodeType::DOMAIN_ALIAS => "
                SELECT al.alias_id AS k, al.alias_status AS s
                FROM domain_aliasses AS al
                JOIN domain AS d ON d.domain_id = al.domain_id
                WHERE d.domain_admin_id IN ($customers) AND al.alias_status IN ($states)
            ",
            NodeType::ALIAS_SUBDOMAIN => "
                SELECT sa.subdomain_alias_id AS k, sa.subdomain_alias_status AS s
                FROM subdomain_alias AS sa
                JOIN domain_aliasses AS al ON al.alias_id = sa.alias_id
                JOIN domain AS d ON d.domain_id = al.domain_id
                WHERE d.domain_admin_id IN ($customers)
                    AND sa.subdomain_alias_status IN ($states)
            ",
            NodeType::MAIL_ACCOUNT => "
                SELECT m.mail_id AS k, m.status AS s
                FROM mail_users AS m
                JOIN domain AS d ON d.domain_id = m.domain_id
                WHERE d.domain_admin_id IN ($customers) AND m.status IN ($states)
            ",
            NodeType::FTP_USER => "
                SELECT f.userid AS k, f.status AS s
                FROM ftp_users AS f
                WHERE f.admin_id IN ($customers) AND f.status IN ($states)
            ",
            NodeType::DNS_RECORD => "
                SELECT dd.domain_dns_id AS k, dd.domain_dns_status AS s
                FROM domain_dns AS dd
                JOIN domain AS d ON d.domain_id = dd.domain_id
                WHERE d.domain_admin_id IN ($customers)
                    AND dd.domain_dns_status IN ($states)
            "
        );

        $pending = array();

        foreach ($queries as $tag => $sql) {
            foreach ($this->db->rows($sql, $bind) as $row) {
                $pending[] = array(
                    'tag'    => $tag,
                    'key'    => $row['k'],
                    'status' => (string)$row['s']
                );
            }
        }

        return $pending;
    }

    /**
     * The primary key column of one vhost kind, qualified with the alias the
     * SELECT for that kind uses.
     *
     * @throws InvalidArgumentException
     */
    private function keyColumn(string $kind): string
    {
        $columns = array(
            self::KIND_DMN    => 'd.domain_id',
            self::KIND_SUB    => 's.subdomain_id',
            self::KIND_ALS    => 'al.alias_id',
            self::KIND_ALSSUB => 'sa.subdomain_alias_id'
        );

        if (!isset($columns[$kind])) {
            throw new InvalidArgumentException(sprintf('Unknown vhost kind "%s".', $kind));
        }

        return $columns[$kind];
    }

    /**
     * One SELECT per vhost kind, every one producing the same column names, so
     * that normalise() has a single shape to work on.
     *
     * @throws InvalidArgumentException
     */
    private function sqlFor(string $kind, string $where): string
    {
        switch ($kind) {
            case self::KIND_DMN:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        d.domain_id AS vhost_key, d.domain_name AS name,
                        NULL AS label, NULL AS parent_key,
                        '/' AS mount_point, d.document_root AS document_root,
                        d.url_forward AS url_forward, d.type_forward AS type_forward,
                        d.host_forward AS host_forward,
                        d.wildcard_alias AS wildcard, d.domain_status AS status,
                        d.domain_ip_id AS ip_id
                    FROM domain AS d
                    WHERE $where
                    ORDER BY d.domain_name
                ";
            case self::KIND_SUB:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        s.subdomain_id AS vhost_key,
                        CONCAT(s.subdomain_name, '.', d.domain_name) AS name,
                        s.subdomain_name AS label, d.domain_id AS parent_key,
                        s.subdomain_mount AS mount_point,
                        s.subdomain_document_root AS document_root,
                        s.subdomain_url_forward AS url_forward,
                        s.subdomain_type_forward AS type_forward,
                        s.subdomain_host_forward AS host_forward,
                        s.subdomain_wildcard_alias AS wildcard,
                        s.subdomain_status AS status, NULL AS ip_id
                    FROM subdomain AS s
                    JOIN domain AS d ON d.domain_id = s.domain_id
                    WHERE $where
                    ORDER BY name
                ";
            case self::KIND_ALS:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        al.alias_id AS vhost_key, al.alias_name AS name,
                        NULL AS label, d.domain_id AS parent_key,
                        al.alias_mount AS mount_point,
                        al.alias_document_root AS document_root,
                        al.url_forward AS url_forward, al.type_forward AS type_forward,
                        al.host_forward AS host_forward,
                        al.wildcard_alias AS wildcard, al.alias_status AS status,
                        al.alias_ip_id AS ip_id
                    FROM domain_aliasses AS al
                    JOIN domain AS d ON d.domain_id = al.domain_id
                    WHERE $where
                    ORDER BY al.alias_name
                ";
            case self::KIND_ALSSUB:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        sa.subdomain_alias_id AS vhost_key,
                        CONCAT(sa.subdomain_alias_name, '.', al.alias_name) AS name,
                        sa.subdomain_alias_name AS label, al.alias_id AS parent_key,
                        sa.subdomain_alias_mount AS mount_point,
                        sa.subdomain_alias_document_root AS document_root,
                        sa.subdomain_alias_url_forward AS url_forward,
                        sa.subdomain_alias_type_forward AS type_forward,
                        sa.subdomain_alias_host_forward AS host_forward,
                        sa.subdomain_alias_wildcard_alias AS wildcard,
                        sa.subdomain_alias_status AS status, NULL AS ip_id
                    FROM subdomain_alias AS sa
                    JOIN domain_aliasses AS al ON al.alias_id = sa.alias_id
                    JOIN domain AS d ON d.domain_id = al.domain_id
                    WHERE $where
                    ORDER BY name
                ";
            default:
                throw new InvalidArgumentException(sprintf(
                    'Unknown vhost kind "%s".', $kind
                ));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalise(string $kind, array $row): array
    {
        $parentTag = null;

        if ($kind === self::KIND_SUB || $kind === self::KIND_ALS) {
            $parentTag = NodeType::DOMAIN;
        } elseif ($kind === self::KIND_ALSSUB) {
            $parentTag = NodeType::DOMAIN_ALIAS;
        }

        return array(
            'tag'          => self::TAGS[$kind],
            'kind'         => $kind,
            'key'          => (int)$row['vhost_key'],
            'domainId'     => (int)$row['domain_id'],
            'ownerId'      => (int)$row['owner_id'],
            'name'         => (string)$row['name'],
            'label'        => $row['label'] === null ? null : (string)$row['label'],
            'parentTag'    => $parentTag,
            'parentKey'    => $row['parent_key'] === null ? null : (int)$row['parent_key'],
            'mountPoint'   => (string)$row['mount_point'],
            'documentRoot' => (string)$row['document_root'],
            'urlForward'   => (string)$row['url_forward'],
            'typeForward'  => $row['type_forward'] === null ? null : (string)$row['type_forward'],
            'hostForward'  => (string)$row['host_forward'],
            'wildcard'     => $row['wildcard'] === 'yes',
            'status'       => (string)$row['status'],
            'ipId'         => $row['ip_id'] === null ? null : (int)$row['ip_id']
        );
    }
}
```

- [ ] **Step 12: Run the tests to verify they pass**

Run: `tools/test.sh`
Expected: PASS, `VirtualHostsTest` 12 methods.

- [ ] **Step 13: Commit**

```bash
git add Model/ Repository/VirtualHosts.php test/unit/Model/ModelMappingTest.php \
        test/integration/VirtualHostsTest.php
git commit -m "Map every table the read schema touches, and union the vhosts

Anorm's two IN-clause batch loaders use one string as a PHP property on the
source model and as a SQL column on the related table, so a model whose
property names differed from its column names would be right on one side and
wrong on the other with nothing to say so. Every model here therefore builds
its map with array_combine(COLUMNS, COLUMNS), which makes an identity map the
only map it can have, and declares its properties untyped because PDO hands
back strings.

Subdomains do not come through a relationship. The table stores the label
alone, and the fully qualified name is that label joined to its parent's, which
a single-table IN-clause SELECT cannot produce - so the four-way union lives in
one repository that also answers Query.pending in a fixed eight queries."
```

---

## Task 10: The batch loader — `opus`

Spec §10.1: *"the plugin ships a small `BatchLoader` used by every one-to-many
and many-to-one edge … A resolver that queries per parent is a defect."* This
is that class, and it is the task the [Anorm section](#anorm-what-was-measured-and-what-this-plan-does-about-it)
above exists for.

**Precondition — Anorm must run on PHP 7.4 before this task can land.**

Anorm v3.1.1 does **not** run on PHP 7.4, and it is broken in exactly the
subsystem this task depends on. Eight call sites in two files of
`vendor/saygoweb/anorm/src/Relationship/Strategy/` call PHP 8.0 functions that
do not exist on 7.4 and for which the package ships no polyfill:

| File | Lines | Call |
| --- | --- | --- |
| `Relationship/Strategy/FieldSelectionParser.php` | 33 | `str_contains()` |
| `Relationship/Strategy/FieldSelectionParser.php` | 114, 161 | `str_starts_with()` |
| `Relationship/Strategy/DataSizeEstimator.php` | 157 | `str_ends_with()` |
| `Relationship/Strategy/DataSizeEstimator.php` | 161, 165, 169, 173 | `str_contains()` |

Measured on the reference box, not inferred:

```
PHP 7.4.33 -> parseFieldSelection FATAL: Call to undefined function
              Anorm\Relationship\Strategy\str_contains()
PHP 8.3.33 -> parseFieldSelection OK
```

Two things make this invisible until it fires. Anorm's own `composer.json`
declares no `php` constraint at all, so Composer installs it on 7.4 without a
word. And `php -l` cannot see it: a call to an undefined function is a run-time
error, not a syntax error, so `test/lint/all.sh` passing under `php7.4` proves
nothing about this package. Phase 0–1 vendored Anorm and never executed it.

This task's design — call `OneHasManyBatchLoader` and `ManyHasOneBatchLoader`
directly and never enter `Relationship/Strategy/` — keeps the plugin off every
one of those eight call sites, which is why the design was chosen before the
breakage was proven. It is a containment, not a fix: nothing stops a later
task, a later Anorm release, or a maintenance edit from reaching the strategy
layer, and Step 4 below is the test that would catch it. **Do not add a
polyfill and do not patch `vendor/`.** The three ways out — an Anorm release
that runs on 7.4, a polyfill vendored by this plugin, or the panel moved to
PHP 8.3 — are the owner's decision, and one of them must be taken before this
plan ships. The [Self-review](#self-review) records this as a risk to the whole
plan.

**What this task does about the strategy layer, concretely.** `BatchLoader`
calls `Relationship\BatchLoader\OneHasManyBatchLoader` and
`ManyHasOneBatchLoader` — the two IN-clause loaders — and nothing else from
Anorm's relationship subsystem. It never calls `Model::loadRelated()`,
`Model::loadAllRelated()`, `RelationshipManager::loadRelated()` or
`BatchLoadingOrchestrator::loadRelationshipsForModels()`, all four of which
route into `QueryStrategySelector` and from there into the two broken files.

This task also renumbers the core-backlog item this plan adds. See Step 1.

**Files:**
- Modify: `docs/SPECIFICATION.md` — the phase-2 §21 item becomes **C10**
- Modify: `Repository/Counts.php` — its `CORE-DEBT` marker follows
- Modify: `Repository/Db.php` — additive `Db::detached()`
- Create: `Repository/BatchLoader.php`
- Create: `test/unit/Repository/BatchLoaderTest.php`
- Create: `test/integration/BatchLoaderTest.php`

**Interfaces:**
- Consumes:
  - `Db::rows(string $sql, array $bind = array()): array`, `Db::pdo(): \PDO`,
    `Db::placeholders(int $n): string`, `Db::countQueries(callable $fn): int` (Task 2)
  - `Fixture` (Task 8), `IntegrationTestCase` (Task 2)
  - Every `Model\*` class, its `const TABLE`, `const PRIMARY_KEY`, `const COLUMNS`,
    its `__construct(\PDO $pdo)` and its relationship property names (Task 9)
  - `Anorm\Model::getPdo(): \PDO`, `Anorm\Model::getRelationshipManager()`,
    `Anorm\Model::$_mapper`, `Anorm\DataMapper::$table`,
    `Anorm\DataMapper::readArray(&$model, array $row)`
  - `Anorm\Relationship\BatchLoader\OneHasManyBatchLoader::batchLoad(array $sourceModels, string $relationshipName, ?array $fieldSelection = null): array`
    and `::distributeBatchResults(array $sourceModels, array $batchResults, string $relationshipName): void`
  - `Anorm\Relationship\BatchLoader\ManyHasOneBatchLoader`, the same two methods
  - `GraphQL\Deferred::__construct(callable $executor)` — extends
    `GraphQL\Executor\Promise\Adapter\SyncPromise`, so `->then()` returns a
    promise `GraphQL::executeQuery()`'s default `SyncPromiseAdapter` accepts
- Produces:
  - `Db::detached(): Db` — a handle with no connection. `pdo()`, `rows()`,
    `row()`, `value()`, `questions()` and `countQueries()` all throw
    `RuntimeException`; `placeholders()` still works. `Db::__construct(?\PDO $pdo)`
    keeps its required parameter — a `Db` with no argument is not constructible.
  - `new BatchLoader(Db $db)`
  - `BatchLoader::related(Model $parent, string $relationship): Deferred` —
    resolves to `Model[]`, empty array when there are none
  - `BatchLoader::parent(Model $child, string $relationship): Deferred` —
    resolves to `Model|null`
  - `BatchLoader::byColumn(string $modelClass, string $column, $value): SyncPromise` —
    resolves to `Model[]` whose `$column` equals `$value`. **`SyncPromise`, not
    `Deferred`:** it ends in `->then()`, and `SyncPromise::then()` does
    `$child = new self()` — `self` is `SyncPromise`. Declaring `Deferred` here
    is a `TypeError` on the first call. `related()` and `parent()` return a
    `Deferred` they constructed themselves and keep that type.
  - `BatchLoader::keyed(string $bucket, $key, callable $loader): Deferred` —
    `$loader(array $keys): array` returns a `key => value` map; a key the loader
    omits resolves to `null`
  - `BatchLoader::flushes(): int` — how many batch queries have been run
  - `BatchLoader::reset(): void` — drop every buffer and every memoised result

- [ ] **Step 1: Renumber this plan's core-backlog item from C7 to C10**

Task 5 Step 1 inserted a §21 item headed `**C7 — Batched counting functions.**`.
Since this plan was drafted, phase 0–1 landed three items of its own in §21 and
took C7, C8 and C9:

- **C7** — plugin route middleware cannot be attached in either shape the core offers
- **C8** — `TemplateEngine` re-scans its own substituted output, so any user-controlled string can hang a request
- **C9** — a server-wide `add_header Cache-Control public` contradicting the session cache limiter

So §21 now has two items numbered C7 and the batched-counting one is the
duplicate. Renumber it. In `docs/SPECIFICATION.md`, find the block that begins

```markdown
**C7 — Batched counting functions.**
```

Move that whole block — its `---` separator, its heading and its four
paragraphs — to sit after the **C9** block and immediately before the
`### 21.2 Keeping the debt visible` heading, and change its heading to

```markdown
**C10 — Batched counting functions.**
```

Nothing else in the block changes. Then follow the marker:

```bash
sed -i 's/CORE-DEBT(C7)/CORE-DEBT(C10)/g' Repository/Counts.php
grep -n 'CORE-DEBT' Repository/Counts.php
```

Expected: one line, `CORE-DEBT(C10)`. Confirm §21 has exactly one C7 and one C10:

```bash
grep -n '^\*\*C[0-9]* —' docs/SPECIFICATION.md
```

Expected: `C1` … `C10`, each exactly once, in order.

This is done here rather than in Task 5 because Task 5's text was written before
the renumbering happened and this plan does not edit already-shipped task text.
The [Self-review](#self-review) records the discrepancy, including that
decision D5's prose still says "Task 4 adds C7 to spec §21" and is wrong in
both numbers.

- [ ] **Step 2: Run the core-debt lint to verify the renumbering holds**

```bash
sh test/lint/all.sh
```

Expected: PASS, and the printed inventory shows `CORE-DEBT(C1)`, `CORE-DEBT(C4)`
and `CORE-DEBT(C10)`. If it fails naming C10 as missing from §21, the block was
moved but not renamed.

- [ ] **Step 3: Add `Db::detached()`**

The unit suite has no database, and `Api\Container` must be able to build every
resolver without one — plan 1's `ContainerTest::testTheSchemaBuildsThroughTheContainer()`
calls `schemaFactory()->create()` in the unit suite, and Task 16 grows that
container until it constructs `BatchLoader`, `Counts` and `VirtualHosts`. A
detached handle lets the wiring be exercised while making any actual query a
loud failure rather than a silent one.

In `Repository/Db.php`, change the property annotation and the constructor
signature, and add the factory and the guard. Every other method stays as it is
except that they now go through `pdo()`:

```php
    /** @var PDO|null Null only for a detached handle; see detached(). */
    private $pdo;

    /**
     * @param PDO|null $pdo Required, not defaulted: a Db that silently has no
     *                      connection because nobody passed one is a bug that
     *                      only shows up as an empty result set. Detachment is
     *                      something a caller asks for by name.
     */
    public function __construct(?PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * A handle with no connection.
     *
     * Every method that would talk to the database throws instead. This exists
     * so the unit suite can build the whole resolver map — which is the test
     * that a schema field has lost its resolver — without a database, while
     * still failing loudly rather than returning nothing if a unit test ever
     * reaches a query.
     */
    public static function detached(): self
    {
        return new self(null);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException(
                'This Db is detached: it has no connection and cannot run a query.'
            );
        }

        return $this->pdo;
    }
```

`rows()` already calls `$this->pdo->prepare(...)`; change that one line to
`$this->pdo()->prepare(...)` so the detached guard applies to it, and add
`use RuntimeException;` to the file's imports. `row()`, `value()`, `questions()`
and `countQueries()` all reach the database through `rows()`, so they are
covered by that one change. `placeholders()` touches no connection and keeps
working.

- [ ] **Step 4: Write the failing unit test**

`test/unit/Repository/BatchLoaderTest.php`. It uses `keyed()` only, so it needs
no database at all — which is the point of `Db::detached()`.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Repository;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BatchLoaderTest extends TestCase
{
    /** @var array<int, array> Every key list the loader was handed. */
    private $calls = array();

    protected function setUp(): void
    {
        $this->calls = array();
    }

    private function loader(): BatchLoader
    {
        return new BatchLoader(Db::detached());
    }

    /**
     * A loader that squares its keys and records what it was asked for.
     */
    private function squares(): callable
    {
        return function (array $keys) {
            $this->calls[] = $keys;
            $out = array();

            foreach ($keys as $key) {
                $out[$key] = $key * $key;
            }

            return $out;
        };
    }

    /**
     * Drain the deferred queue the way GraphQL's SyncPromiseAdapter does.
     */
    private function drain(): void
    {
        SyncPromise::runQueue();
    }

    private function valueOf(Deferred $deferred)
    {
        $this->drain();

        self::assertSame(SyncPromise::FULFILLED, $deferred->state);

        return $deferred->result;
    }

    public function testTenKeysCostOneCallNotTen(): void
    {
        // The whole reason this class exists. A resolver that asked per parent
        // would produce ten calls here, which is the N+1 spec section 10.1
        // calls a defect.
        $loader = $this->loader();
        $deferreds = array();

        for ($i = 1; $i <= 10; $i++) {
            $deferreds[$i] = $loader->keyed('squares', $i, $this->squares());
        }

        $this->drain();

        self::assertCount(1, $this->calls);
        self::assertSame(range(1, 10), $this->calls[0]);
        self::assertSame(1, $loader->flushes());
        self::assertSame(49, $deferreds[7]->result);
    }

    public function testTheSameKeyTwiceIsAskedForOnce(): void
    {
        $loader = $this->loader();

        $a = $loader->keyed('squares', 4, $this->squares());
        $b = $loader->keyed('squares', 4, $this->squares());

        $this->drain();

        self::assertSame(array(array(4)), $this->calls);
        self::assertSame(16, $a->result);
        self::assertSame(16, $b->result);
    }

    public function testAKeyAskedForAgainAfterAFlushIsNotAskedForAgain(): void
    {
        // Memoisation across levels. A deep document resolves the same parent
        // at several depths; the second one must be free.
        $loader = $this->loader();

        $first = $loader->keyed('squares', 3, $this->squares());
        $this->drain();

        $second = $loader->keyed('squares', 3, $this->squares());
        $this->drain();

        self::assertCount(1, $this->calls);
        self::assertSame(9, $second->result);
        self::assertSame(1, $loader->flushes());
    }

    public function testDifferentBucketsDoNotShareKeys(): void
    {
        // Two edges both keyed by domain_id would otherwise hand each other's
        // answers back — a cross-object data leak, not merely a wrong count.
        $loader = $this->loader();

        $square = $loader->keyed('squares', 5, $this->squares());
        $double = $loader->keyed('doubles', 5, function (array $keys) {
            $this->calls[] = $keys;

            return array(5 => 10);
        });

        $this->drain();

        self::assertSame(25, $square->result);
        self::assertSame(10, $double->result);
        self::assertSame(2, $loader->flushes());
    }

    public function testAKeyTheLoaderOmitsResolvesToNull(): void
    {
        // "No rows" and "not asked" must not be told apart by the resolver:
        // it gets null and renders an absent object, rather than a warning.
        $loader = $this->loader();

        $deferred = $loader->keyed('sparse', 9, function (array $keys) {
            $this->calls[] = $keys;

            return array();
        });

        self::assertNull($this->valueOf($deferred));
    }

    public function testStringKeysWork(): void
    {
        // ftp_users is keyed by 'user@domain'.
        $loader = $this->loader();

        $deferred = $loader->keyed('ftp', 'shop@example.test', function (array $keys) {
            $this->calls[] = $keys;

            return array('shop@example.test' => 'ok');
        });

        self::assertSame('ok', $this->valueOf($deferred));
        self::assertSame(array(array('shop@example.test')), $this->calls);
    }

    public function testResetForgetsEverything(): void
    {
        $loader = $this->loader();

        $loader->keyed('squares', 2, $this->squares());
        $this->drain();
        $loader->reset();
        $loader->keyed('squares', 2, $this->squares());
        $this->drain();

        self::assertCount(2, $this->calls, 'reset must drop the memo, not keep it');
        // One, not zero: reset() zeroes the counter and the flush that follows
        // it increments the counter again. Asserting zero here would only pass
        // if reset() had also broken the memo's own bookkeeping.
        self::assertSame(1, $loader->flushes(), 'reset must zero the counter, and the next flush counts');
    }

    public function testResetZeroesTheCounterOnItsOwn(): void
    {
        $loader = $this->loader();

        $loader->keyed('squares', 2, $this->squares());
        $this->drain();

        self::assertSame(1, $loader->flushes());

        $loader->reset();

        self::assertSame(0, $loader->flushes());
    }

    public function testADetachedHandleRefusesToQuery(): void
    {
        // If a unit test ever reaches a real query it must say so, not return
        // an empty result set that looks like "this customer has nothing".
        $this->expectException(RuntimeException::class);

        Db::detached()->rows('SELECT 1');
    }

    public function testAnAnormStrategyClassIsNeverLoaded(): void
    {
        // The containment this whole design exists for. Anorm v3.1.1's
        // Strategy classes call str_contains(), str_starts_with() and
        // str_ends_with() - PHP 8.0 functions with no polyfill - so any code
        // path that reaches them is a fatal error on the panel's PHP 7.4.
        // class_exists(..., false) does not autoload, so this asserts what has
        // actually been loaded rather than what could be.
        $loader = $this->loader();
        $loader->keyed('squares', 1, $this->squares());
        $this->drain();

        foreach (array(
            'Anorm\Relationship\BatchLoadingOrchestrator',
            'Anorm\Relationship\Strategy\QueryStrategySelector',
            'Anorm\Relationship\Strategy\FieldSelectionParser',
            'Anorm\Relationship\Strategy\DataSizeEstimator',
            'Anorm\Relationship\Strategy\JoinWithSelectionLoader'
        ) as $class) {
            self::assertFalse(class_exists($class, false), $class . ' was loaded');
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader` not found, and
`Db::detached()` not found if Step 3 was skipped.

- [ ] **Step 6: Write `Repository/BatchLoader.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;
// ... licence header ...

use Anorm\Model;
use Anorm\Relationship\BatchLoader\ManyHasOneBatchLoader;
use Anorm\Relationship\BatchLoader\OneHasManyBatchLoader;
use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use InvalidArgumentException;

/**
 * One query per edge per level, whatever the number of parents.
 *
 * Spec section 10.1 asks for the DataLoader pattern and names the edges:
 * customer->domain, domain->subdomains, domain->aliases, alias->subdomains,
 * domain->mail accounts, customer->ftp users, database->sql users, and the
 * reverse of each. GraphQL\Deferred supplies the timing - it queues a callback
 * that runs only when the executor can make no further progress, which is
 * exactly the end of a level - and Anorm's two IN-clause loaders supply the
 * query.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT TOUCH
 *
 * Anorm v3.1.1's Relationship\Strategy\FieldSelectionParser and
 * Relationship\Strategy\DataSizeEstimator call str_contains(),
 * str_starts_with() and str_ends_with(): PHP 8.0 functions, no polyfill, and
 * the package declares no php constraint, so Composer installs it on 7.4 and
 * it fatals at run time. Measured on the reference box:
 *
 *   PHP 7.4.33 -> FATAL: Call to undefined function
 *                 Anorm\Relationship\Strategy\str_contains()
 *   PHP 8.3.33 -> OK
 *
 * php -l cannot see this - an undefined function is a run-time error - so the
 * dual-version lint passing proves nothing here.
 *
 * Every route into those two files goes through QueryStrategySelector:
 * BatchLoadingOrchestrator::loadRelationshipsForModels(),
 * Model::loadRelated(), Model::loadAllRelated(),
 * RelationshipManager::loadRelated() and OneHasMany::batchLoad(). This class
 * calls none of them. It calls OneHasManyBatchLoader and ManyHasOneBatchLoader
 * directly, which take no strategy decision at all: both emit one IN-clause
 * SELECT unconditionally, which is precisely the hand-written batch loader
 * spec section 10.1 would otherwise have the plugin write.
 *
 * That is also the right shape on its own merits. QueryStrategySelector picks
 * STRATEGY_INDIVIDUAL_LOADING at ten source models or fewer, and a customer
 * has one domain while a reseller has tens of customers - so its default
 * configuration would choose the N+1 for exactly the sizes this API sees.
 *
 * There is a unit test asserting those five classes are never loaded. Keep it
 * passing.
 */
final class BatchLoader
{
    /** @var Db */
    private $db;

    /**
     * Models waiting on an Anorm edge.
     *
     * bucket => array(spl_object_hash => Model). A bucket is one
     * (model class, relationship name) pair, because the loaders read the
     * relationship definition off the first model in the batch.
     *
     * @var array<string, array<string, Model>>
     */
    private $pendingModels = array();

    /** @var array<string, string> bucket => relationship name */
    private $relationships = array();

    /** @var array<string, bool> bucket => true when the edge is many-has-one */
    private $isParentEdge = array();

    /**
     * Edges Anorm has already assigned, so a second ask is free.
     *
     * bucket => spl_object_hash => the model itself. The model is held rather
     * than a bare flag because spl_object_hash() reuses the hash of a
     * collected object, and a reused hash would read as "already loaded" for a
     * different model.
     *
     * @var array<string, array<string, Model>>
     */
    private $loadedEdges = array();

    /**
     * Keys waiting on a keyed() or byColumn() load.
     *
     * bucket => array(stringified key => original key).
     *
     * @var array<string, array<string, mixed>>
     */
    private $pendingKeys = array();

    /** @var array<string, callable> bucket => loader */
    private $loaders = array();

    /**
     * Answers already loaded, kept for the life of the request.
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
     * A one-has-many edge: every child of this parent.
     *
     * @param string $relationship The property name declared with hasMany() on
     *                             the model, e.g. 'mailAccounts'
     * @return Deferred Resolves to Model[], empty when there are none
     */
    public function related(Model $parent, string $relationship): Deferred
    {
        return $this->edge($parent, $relationship, false);
    }

    /**
     * A many-has-one edge: this child's parent.
     *
     * @param string $relationship The property name declared with belongsTo()
     *                             on the model, e.g. 'customer'
     * @return Deferred Resolves to Model|null
     */
    public function parent(Model $child, string $relationship): Deferred
    {
        return $this->edge($child, $relationship, true);
    }

    /**
     * Every row of one model class whose $column holds $value.
     *
     * The read path's one Anorm entry point. A resolver that wants typed
     * property access to a single table - $domain->domain_created rather than
     * $row['domain_created'] - loads it through here and gets a Model back;
     * Task 12's Domain.createdAt is the first caller. One IN-clause query per
     * (class, column) pair per level, memoised by keyed() underneath.
     *
     * Returns a SyncPromise rather than a Deferred because it ends in
     * ->then(): SyncPromise::then() does `$child = new self()`, and `self` is
     * SyncPromise. Declaring Deferred here is a TypeError on the first call.
     *
     * @param string $modelClass A Model\* class name
     * @param string $column     A column of that model's table
     * @param mixed  $value      The value to match
     * @return SyncPromise Resolves to Model[]
     */
    public function byColumn(string $modelClass, string $column, $value): SyncPromise
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an Anorm model.', $modelClass
            ));
        }

        $db = $this->db;
        $bucket = 'col:' . $modelClass . '.' . $column;

        return $this->keyed($bucket, $value, static function (array $keys) use (
            $db, $modelClass, $column
        ) {
            $probe = new $modelClass($db->pdo());
            $table = $probe->_mapper->table;
            $in = $db->placeholders(count($keys));

            $grouped = array();

            foreach ($db->rows(
                'SELECT * FROM `' . $table . '` WHERE `' . $column . '` IN (' . $in . ')',
                $keys
            ) as $row) {
                $model = new $modelClass($db->pdo());
                $model->_mapper->readArray($model, $row);
                $grouped[(string)$row[$column]][] = $model;
            }

            return $grouped;
        })->then(static function ($models) {
            // A key with no rows resolves to null from keyed(); an edge that
            // returns a list must return a list.
            return $models === null ? array() : $models;
        });
    }

    /**
     * Anything Anorm cannot express: the vhost union, the batched counts, the
     * api_perm lookup, a filtered and paged list.
     *
     * @param string   $bucket One namespace per distinct query shape. Two
     *                         edges that share a bucket would hand each other's
     *                         answers back, so a bucket name carries the class
     *                         and the column, never just the column.
     * @param mixed    $key
     * @param callable $loader fn(array $keys): array — a key => value map.
     *                         A key the loader omits resolves to null.
     */
    public function keyed(string $bucket, $key, callable $loader): Deferred
    {
        $index = (string)$key;

        if (!isset($this->results[$bucket])
            || !array_key_exists($index, $this->results[$bucket])
        ) {
            $this->pendingKeys[$bucket][$index] = $key;
            $this->loaders[$bucket] = $loader;
        }

        return new Deferred(function () use ($bucket, $index) {
            if (isset($this->pendingKeys[$bucket][$index])) {
                $this->flushKeys($bucket);
            }

            return $this->results[$bucket][$index] ?? null;
        });
    }

    /**
     * How many batch queries have run. Task 17's threshold is measured with
     * Db::countQueries(); this is the cheaper in-process view of the same
     * thing, for a unit test that has no database.
     */
    public function flushes(): int
    {
        return $this->flushes;
    }

    public function reset(): void
    {
        $this->pendingModels = array();
        $this->relationships = array();
        $this->isParentEdge = array();
        $this->loadedEdges = array();
        $this->pendingKeys = array();
        $this->loaders = array();
        $this->results = array();
        $this->flushes = 0;
    }

    private function edge(Model $model, string $relationship, bool $isParent): Deferred
    {
        $bucket = 'rel:' . get_class($model) . '::' . $relationship;
        $index = spl_object_hash($model);

        // Enqueue only if this model's edge has not been loaded already.
        // Without this, asking the same parent twice - which a deep document
        // does whenever two children share a parent - re-enqueues it and the
        // second Deferred opens a second query for an answer the model is
        // already holding.
        //
        // The property cannot be the test: both Anorm loaders'
        // distributeBatchResults() assign unconditionally, [] for
        // one-has-many and null for many-has-one, so isset() cannot tell
        // "loaded and empty" from "never loaded". The model itself is held
        // rather than just its hash, because spl_object_hash() reuses the
        // hash of a collected object and a reused hash would read as loaded.
        if (!isset($this->loadedEdges[$bucket][$index])) {
            $this->pendingModels[$bucket][$index] = $model;
            $this->relationships[$bucket] = $relationship;
            $this->isParentEdge[$bucket] = $isParent;
        }

        return new Deferred(function () use ($bucket, $index, $model, $relationship, $isParent) {
            // Flush only if this model is still waiting. A sibling deferred in
            // the same level may already have flushed the whole bucket, and
            // flushing twice would be the second query this class exists to
            // prevent.
            if (isset($this->pendingModels[$bucket][$index])) {
                $this->flushEdge($bucket);
            }

            $value = $model->{$relationship};

            if ($isParent) {
                return $value;
            }

            return is_array($value) ? $value : array();
        });
    }

    private function flushEdge(string $bucket): void
    {
        $indexes = array_keys($this->pendingModels[$bucket]);
        $models = array_values($this->pendingModels[$bucket]);
        $relationship = $this->relationships[$bucket];
        $isParent = $this->isParentEdge[$bucket];

        unset(
            $this->pendingModels[$bucket],
            $this->relationships[$bucket],
            $this->isParentEdge[$bucket]
        );

        // Constructed per flush rather than held as a field: both loaders are
        // stateless, and holding one would be one more thing to reason about
        // when asking whether the strategy layer can be reached.
        $loader = $isParent ? new ManyHasOneBatchLoader() : new OneHasManyBatchLoader();

        $results = $loader->batchLoad($models, $relationship);
        $loader->distributeBatchResults($models, $results, $relationship);

        // Every model in this flush now carries its edge. Recording that is
        // what makes a second ask free; see edge()'s note on why the model is
        // held rather than the hash alone.
        foreach ($indexes as $position => $index) {
            $this->loadedEdges[$bucket][$index] = $models[$position];
        }

        $this->flushes++;
    }

    private function flushKeys(string $bucket): void
    {
        $keys = array_values($this->pendingKeys[$bucket]);
        $loader = $this->loaders[$bucket];

        unset($this->pendingKeys[$bucket], $this->loaders[$bucket]);

        if (!isset($this->results[$bucket])) {
            $this->results[$bucket] = array();
        }

        foreach (call_user_func($loader, $keys) as $key => $value) {
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

- [ ] **Step 7: Run the unit test to verify it passes**

```bash
tools/test.sh
```

Expected: PASS, `BatchLoaderTest` 10 methods.

- [ ] **Step 8: Write the failing integration test**

`test/integration/BatchLoaderTest.php`. This is the half the unit suite cannot
reach: whether Anorm's two IN-clause loaders actually work against i-MSCP's
tables and against Task 9's models, on the panel's PHP.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Model\AdminModel;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;
use iMSCP\Plugin\SGW_GraphQL\Model\MailAccountModel;
use iMSCP\Plugin\SGW_GraphQL\Model\SqlDatabaseModel;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;

class BatchLoaderTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var BatchLoader */
    private $loader;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->loader = new BatchLoader($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function drain(): void
    {
        SyncPromise::runQueue();
    }

    /**
     * @return AdminModel[]
     */
    private function customers(): array
    {
        $deferred = $this->loader->byColumn(
            AdminModel::class, 'created_by', $this->fixture->resellerId()
        );
        $this->drain();

        return $deferred->result;
    }

    public function testByColumnHydratesModelsFromTheDatabase(): void
    {
        $names = array();

        foreach ($this->customers() as $customer) {
            $names[] = $customer->admin_name;
        }

        sort($names);
        self::assertSame(
            array(Fixture::PREFIX . 'customer', Fixture::PREFIX . 'sibling'), $names
        );
    }

    public function testAOneHasManyEdgeCostsOneQueryForBothParents(): void
    {
        // Two customers, one query for their domains. Asking per customer is
        // the N+1 spec section 10.1 calls a defect, and this is the assertion
        // that would fail if related() flushed per model.
        $customers = $this->customers();
        $loader = $this->loader;

        $count = $this->db->countQueries(function () use ($loader, $customers) {
            foreach ($customers as $customer) {
                $loader->related($customer, 'domains');
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testAOneHasManyEdgeReturnsTheRightChildrenPerParent(): void
    {
        // One query is worthless if the rows are handed to the wrong parent.
        $customers = $this->customers();
        $deferreds = array();

        foreach ($customers as $customer) {
            $deferreds[(int)$customer->admin_id] = $this->loader->related($customer, 'domains');
        }

        $this->drain();

        $domains = $deferreds[$this->fixture->customerId()]->result;

        self::assertCount(1, $domains);
        self::assertSame($this->fixture->domainName(), $domains[0]->domain_name);
        self::assertSame(
            $this->fixture->domainId(), (int)$domains[0]->domain_id
        );
    }

    public function testAParentWithNoChildrenGetsAnEmptyArrayNotNull(): void
    {
        // The sibling customer has a domain but no mail accounts. A resolver
        // returning null for a non-null list field would null the whole
        // object it hangs off.
        $sibling = null;

        foreach ($this->customers() as $customer) {
            if ((int)$customer->admin_id === $this->fixture->siblingId()) {
                $sibling = $customer;
            }
        }

        self::assertNotNull($sibling, 'the fixture seeds a sibling customer');

        $domains = $this->loader->related($sibling, 'domains');
        $this->drain();

        $mail = $this->loader->related($domains->result[0], 'mailAccounts');
        $this->drain();

        self::assertSame(array(), $mail->result);
    }

    public function testAManyHasOneEdgeCostsOneQueryForEveryChild(): void
    {
        $rows = $this->db->rows(
            'SELECT * FROM mail_users WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        $mailboxes = array();

        foreach ($rows as $row) {
            $model = new MailAccountModel($this->db->pdo());
            $model->_mapper->readArray($model, $row);
            $mailboxes[] = $model;
        }

        self::assertCount(2, $mailboxes, 'the fixture seeds a mailbox and a forward');

        $loader = $this->loader;
        $deferreds = array();

        $count = $this->db->countQueries(function () use ($loader, $mailboxes, &$deferreds) {
            foreach ($mailboxes as $mailbox) {
                $deferreds[] = $loader->parent($mailbox, 'domain');
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
        self::assertInstanceOf(DomainModel::class, $deferreds[0]->result);
        self::assertSame(
            $this->fixture->domainName(), $deferreds[0]->result->domain_name
        );
    }

    public function testTheSameParentAskedForTwiceCostsOneQuery(): void
    {
        // A deep document reaches the same domain from the mailbox and from
        // the forward. Both mail accounts share a parent, so the IN clause
        // de-duplicates and one row comes back - but the assertion that
        // matters is that the second ask does not open a second query.
        $customers = $this->customers();
        $loader = $this->loader;

        $count = $this->db->countQueries(function () use ($loader, $customers) {
            $loader->related($customers[0], 'domains');
            SyncPromise::runQueue();
            $loader->related($customers[0], 'domains');
            SyncPromise::runQueue();
        });

        self::assertSame(1, $count, 'the second ask must be served from the model');
    }

    public function testByColumnMemoisesAcrossLevels(): void
    {
        $loader = $this->loader;
        $resellerId = $this->fixture->resellerId();

        $count = $this->db->countQueries(function () use ($loader, $resellerId) {
            $loader->byColumn(AdminModel::class, 'created_by', $resellerId);
            SyncPromise::runQueue();
            $loader->byColumn(AdminModel::class, 'created_by', $resellerId);
            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testTheDatabaseToSqlUsersEdgeLoadsThroughAnorm(): void
    {
        // Named in spec section 10.1's list of edges the loader must cover.
        $rows = $this->db->rows(
            'SELECT * FROM sql_database WHERE sqld_id = ?',
            array($this->fixture->sqlDatabaseId())
        );
        $database = new SqlDatabaseModel($this->db->pdo());
        $database->_mapper->readArray($database, $rows[0]);

        $users = $this->loader->related($database, 'users');
        $this->drain();

        self::assertCount(1, $users->result);
        self::assertSame(Fixture::PREFIX . '_u1', $users->result[0]->sqlu_name);
    }

    public function testNoAnormStrategyClassIsLoadedByARealBatchLoad(): void
    {
        // The unit suite asserts this for keyed(). This asserts it for the
        // path that actually calls into Anorm, which is the one that would
        // fatal on the panel's PHP 7.4 if it ever reached the strategy layer.
        $customers = $this->customers();

        foreach ($customers as $customer) {
            $this->loader->related($customer, 'domains');
            $this->loader->parent($customer, 'reseller');
        }

        $this->drain();

        foreach (array(
            'Anorm\Relationship\BatchLoadingOrchestrator',
            'Anorm\Relationship\Strategy\QueryStrategySelector',
            'Anorm\Relationship\Strategy\FieldSelectionParser',
            'Anorm\Relationship\Strategy\DataSizeEstimator',
            'Anorm\Relationship\Strategy\JoinWithSelectionLoader'
        ) as $class) {
            self::assertFalse(class_exists($class, false), $class . ' was loaded');
        }
    }
}
```

- [ ] **Step 9: Run the integration test to verify it fails, then passes**

```bash
tools/test.sh
```

Expected first: FAIL — the integration suite could not find `BatchLoader` before
Step 6, or, if Step 6 is already in place, a genuine failure to fix. There is no
new implementation for this step: `BatchLoader` as written in Step 6 is what
these tests measure. Run it, and if a test fails, the failure is a fact about
Anorm or about Task 9's models and it must be understood before it is changed.

Expected finally: PASS, `BatchLoaderTest` (integration) 9 methods.

If `testAOneHasManyEdgeCostsOneQueryForBothParents` reports a fatal naming
`str_contains`, `str_starts_with` or `str_ends_with`, a code path has reached
`Relationship/Strategy/` — stop, do not polyfill, and report it against this
task's precondition.

- [ ] **Step 10: Commit**

```bash
git add docs/SPECIFICATION.md Repository/Counts.php Repository/Db.php \
        Repository/BatchLoader.php test/unit/Repository/BatchLoaderTest.php \
        test/integration/BatchLoaderTest.php
git commit -m "Batch every edge, and stay out of Anorm's strategy layer

GraphQL's obvious shape - list the customers, and for each their domain and
its subdomains - is an N+1 unless something accumulates the keys of a level
and asks once. Deferred supplies the timing and Anorm's two IN-clause loaders
supply the query, so every edge costs one query per level however many parents
asked for it.

Anorm's strategy layer is not used, for two independent reasons. It picks
individual loading at ten source models or fewer, which is exactly the size
this API sees. And two of its classes call PHP 8.0 string functions with no
polyfill, so any path that reaches them is a fatal error on the panel's PHP
7.4 - which php -l cannot see, because an undefined function is a run-time
error. Two tests assert those classes are never loaded.

The core-backlog item this plan adds is renumbered C10: phase 0-1 took C7, C8
and C9 while this plan was being written."
```

---

## Task 11: The full read schema, and the conventions every resolver shares — `sonnet`

The SDL grows from plan 1's viewer slice to the whole read schema of spec §7,
`SchemaFactory` learns to attach a `resolveType` to the three interfaces, and
`Resolver\TypeResolver` carries the handful of conventions the five resolver
tasks that follow all depend on.

**The shape every resolver produces.** A resolver returns a **plain array**,
never a model. The array carries the SDL's own field names for everything that
is a value, so `ResolverMap`'s fallback — "a field with no entry reads the key
of the same name off the source array" — covers most of the schema and only
edges and computed fields need a map entry. Alongside the field names it
carries three kinds of private key, all underscore-prefixed so that they can
never collide with an SDL field:

- `__tag` — the `NodeType` tag, which is what `resolveType` reads
- `__key` — the row identifier the tag applies to
- `__model`, `__domainId`, `__ownerId`, … — whatever the type's own edges need

This is the plan's Architecture paragraph made concrete: *"Resolvers are thin:
they shape a model into an array and hand any edge to the loader."*

**Scopes are checked, and a missing scope is a refusal.** Spec §7.2 says a
credential's scopes "may be narrower than the role", and plan 1's
`Identity::hasScope()` answers true when a token records no scopes at all — a
sensible default for a full-rights token and a dangerous one if nothing ever
asks. `TypeResolver::requireScope()` is the one place that asks, and every
collection field in Tasks 12–16 goes through it. It throws rather than
returning an empty list, because an empty list is indistinguishable from "you
have none" and would tell a client its token works when it does not.

**Files:**
- Modify: `schema/schema.graphql`
- Modify: `Schema/SchemaFactory.php`
- Create: `Resolver/TypeResolver.php`
- Create: `test/schema/SchemaTest.php`, `test/schema/schema.printed.graphql`
- Create: `test/unit/Resolver/TypeResolverTest.php`

**Interfaces:**
- Consumes:
  - `Provisioning::fromStatus(?string $status): Provisioning`, `getState()`,
    `getRaw()`, `isSettled()`, `getMessage()` (plan 1)
  - `GlobalId::encode(string $type, int $id): string`,
    `GlobalId::encodeKey(string $type, string $key): string` (plan 1, Task 1)
  - `NodeType::graphqlType(string $tag): string`,
    `NodeType::isStringKeyed(string $tag): bool`, the tag constants (Task 1)
  - `ViewerResolver::identityFrom($context): Identity` (plan 1)
  - `Identity::hasScope(string $scope): bool` (plan 1)
  - `ApiException`, `ErrorCode::FORBIDDEN`, `ErrorCode::INTERNAL` (plan 1)
  - `new SchemaFactory(string $sdlPath, ?string $cacheDir, ResolverMap $resolvers)` (plan 1)
- Produces:
  - `SchemaFactory::__construct(string $sdlPath, ?string $cacheDir, ResolverMap $resolvers, ?callable $resolveType = null)`
    — **additive**; the three-argument form keeps working and every plan 1 test
    keeps passing unchanged
  - `TypeResolver::TAG` — the string `'__tag'`
  - `TypeResolver::resolveType($value, $context, ResolveInfo $info): string`
  - `TypeResolver::node(string $tag, $key, array $fields): array` — merges
    `__tag`, `__key` and an encoded `id` into `$fields`
  - `TypeResolver::provisioning(?string $status): array` — the `Provisioning`
    shape: `state`, `raw`, `settled`, `message`
  - `TypeResolver::dateTime($timestamp): ?string` — `gmdate('Y-m-d\TH:i:s\Z')`;
    null for null, `''`, `'0'` and `0`
  - `TypeResolver::bigInt($value): ?string` — a decimal string, or null
  - `TypeResolver::gender(?string $raw): ?string` — `M|F|U` → `MALE|FEMALE|UNSPECIFIED`
  - `TypeResolver::contact(array $adminRow, callable $toUnicode): array` — the
    `ContactDetails` shape
  - `TypeResolver::identity($context): Identity`
  - `TypeResolver::requireScope($context, string $scope): Identity` — throws
    `ApiException(FORBIDDEN)`
  - `TypeResolver::page(?array $pageInput): array` — `array('limit' => int, 'offset' => int)`,
    limit clamped to 1–200, offset floored at 0
  - `TypeResolver::map(): array` — the resolver-map entries this class owns
  - `schema/schema.graphql` — the full read schema below

- [ ] **Step 1: Write the failing `TypeResolver` test**

`test/unit/Resolver/TypeResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TypeResolverTest extends TestCase
{
    private function context(array $scopes = array()): array
    {
        return array('identity' => new Identity(
            7, 'customer', 'user', 5, 'c@example.test', $scopes, 11
        ));
    }

    private function info(): ResolveInfo
    {
        // resolveType never reads it; the executor's signature requires it.
        return $this->createMock(ResolveInfo::class);
    }

    public function testAnAliasSubdomainResolvesToTheSubdomainType(): void
    {
        // Decision D1: the tag and the GraphQL type differ in exactly one
        // place, and getting it wrong here would make every alias subdomain
        // unrenderable - "abstract type Subdomain must resolve to an Object
        // type at runtime".
        self::assertSame('Subdomain', TypeResolver::resolveType(
            array(TypeResolver::TAG => NodeType::ALIAS_SUBDOMAIN),
            $this->context(),
            $this->info()
        ));
    }

    public function testEachTagResolvesToItsOwnType(): void
    {
        foreach (array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::SUBDOMAIN,
            NodeType::DOMAIN_ALIAS, NodeType::MAIL_ACCOUNT, NodeType::FTP_USER,
            NodeType::SQL_DATABASE, NodeType::SQL_USER, NodeType::DNS_RECORD,
            NodeType::RESELLER, NodeType::HOSTING_PLAN, NodeType::IP_ADDRESS
        ) as $tag) {
            self::assertSame($tag, TypeResolver::resolveType(
                array(TypeResolver::TAG => $tag), $this->context(), $this->info()
            ), $tag);
        }
    }

    public function testAValueWithNoTagIsAnInternalError(): void
    {
        // A resolver that forgot to tag its array would otherwise produce
        // "abstract type must resolve to an Object type" from deep inside the
        // executor, with no clue which resolver was at fault.
        try {
            TypeResolver::resolveType(array('id' => 'x'), $this->context(), $this->info());
            self::fail('an untagged value must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::INTERNAL, $e->getErrorCode());
        }
    }

    public function testNodeEncodesAnIntegerIdentifier(): void
    {
        $node = TypeResolver::node(NodeType::DOMAIN, 12, array('name' => 'a.test'));

        self::assertSame(GlobalId::encode(NodeType::DOMAIN, 12), $node['id']);
        self::assertSame(NodeType::DOMAIN, $node[TypeResolver::TAG]);
        self::assertSame(12, $node['__key']);
        self::assertSame('a.test', $node['name']);
    }

    public function testNodeEncodesAStringIdentifierForAnFtpUser(): void
    {
        // ftp_users' primary key is userid varchar(255). Encoding it with
        // encode() would raise; encoding it as an integer would collide.
        $node = TypeResolver::node(NodeType::FTP_USER, 'shop@a.test', array());

        self::assertSame(
            GlobalId::encodeKey(NodeType::FTP_USER, 'shop@a.test'), $node['id']
        );
    }

    public function testProvisioningCarriesTheRawStatus(): void
    {
        $p = TypeResolver::provisioning('toadd');

        self::assertSame('PENDING', $p['state']);
        self::assertSame('toadd', $p['raw']);
        self::assertFalse($p['settled']);
        self::assertNull($p['message']);
    }

    public function testProvisioningCarriesTheBackendsFailureText(): void
    {
        $p = TypeResolver::provisioning('Failed to add domain: no such user');

        self::assertSame('ERROR', $p['state']);
        self::assertSame('Failed to add domain: no such user', $p['message']);
    }

    public function testDateTimeIsUtcAndIsoEightSixHundredOne(): void
    {
        self::assertSame('2026-01-01T00:00:00Z', TypeResolver::dateTime(1767225600));
        self::assertSame('2026-01-01T00:00:00Z', TypeResolver::dateTime('1767225600'));
    }

    public function testDateTimeTreatsImscpsZeroAsUnset(): void
    {
        // i-MSCP writes 0 for "no expiry", not NULL. Rendering that as
        // 1970-01-01 would tell every client the account expired in 1970.
        self::assertNull(TypeResolver::dateTime(0));
        self::assertNull(TypeResolver::dateTime('0'));
        self::assertNull(TypeResolver::dateTime(''));
        self::assertNull(TypeResolver::dateTime(null));
    }

    public function testBigIntIsADecimalString(): void
    {
        // Spec section 7.1: a JSON number is a double, and disk figures exceed
        // 2^53. A BigInt resolver that returned an int would be serialised by
        // the identity custom scalar as a number and silently lose precision.
        self::assertSame('1099511627776', TypeResolver::bigInt(1099511627776));
        self::assertSame('0', TypeResolver::bigInt(0));
        self::assertSame('0', TypeResolver::bigInt('0'));
        self::assertNull(TypeResolver::bigInt(null));
    }

    public function testGenderMapsImscpsSingleLetters(): void
    {
        self::assertSame('MALE', TypeResolver::gender('M'));
        self::assertSame('FEMALE', TypeResolver::gender('F'));
        self::assertSame('UNSPECIFIED', TypeResolver::gender('U'));
        self::assertNull(TypeResolver::gender(null));
        self::assertNull(TypeResolver::gender(''));
    }

    public function testContactDecodesPunycodeInTheEmailAddress(): void
    {
        $contact = TypeResolver::contact(array(
            'fname' => 'Ada', 'lname' => 'Lovelace', 'gender' => 'F',
            'firm' => 'Analytical Ltd', 'street1' => '1 Test Street',
            'street2' => null, 'city' => 'Testville', 'state' => 'Testshire',
            'zip' => '1234', 'country' => 'NZ',
            'email' => 'ada@xn--bcher-kva.test', 'phone' => '+64 3 000 0000',
            'fax' => null
        ), static function (string $value) {
            return $value === 'ada@xn--bcher-kva.test' ? 'ada@bücher.test' : $value;
        });

        self::assertSame('Ada', $contact['firstName']);
        self::assertSame('Lovelace', $contact['lastName']);
        self::assertSame('FEMALE', $contact['gender']);
        self::assertSame('Analytical Ltd', $contact['company']);
        self::assertSame('1234', $contact['postCode']);
        self::assertSame('ada@bücher.test', $contact['email']);
        self::assertNull($contact['fax']);
    }

    public function testRequireScopeAllowsAScopeTheTokenCarries(): void
    {
        $identity = TypeResolver::requireScope(
            $this->context(array(Scope::MAIL_READ)), Scope::MAIL_READ
        );

        self::assertSame(7, $identity->getAdminId());
    }

    public function testRequireScopeRefusesAScopeTheTokenLacks(): void
    {
        // The fail-closed half. A token scoped to MAIL_READ asking for SQL
        // must be refused, not quietly handed an empty list that reads as
        // "this customer has no databases".
        try {
            TypeResolver::requireScope(
                $this->context(array(Scope::MAIL_READ)), Scope::SQL_READ
            );
            self::fail('a missing scope must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
            self::assertSame(
                array('scope' => Scope::SQL_READ), $e->getExtensions()
            );
        }
    }

    public function testATokenWithNoRecordedScopesCarriesThemAll(): void
    {
        // Plan 1's rule, restated here because requireScope() is where it now
        // has consequences: a token issued before scopes existed is a full
        // token, not a token with nothing.
        self::assertInstanceOf(Identity::class, TypeResolver::requireScope(
            $this->context(), Scope::SQL_READ
        ));
    }

    public function testPageInputIsClampedToTheServersCeiling(): void
    {
        // Spec section 7.9: limit is capped at 200 by the server regardless of
        // what is asked for. A client asking for a million rows is the cheapest
        // denial of service this API has.
        self::assertSame(
            array('limit' => 200, 'offset' => 0),
            TypeResolver::page(array('limit' => 100000, 'offset' => 0))
        );
        self::assertSame(
            array('limit' => 50, 'offset' => 0), TypeResolver::page(null)
        );
        self::assertSame(
            array('limit' => 1, 'offset' => 0),
            TypeResolver::page(array('limit' => 0, 'offset' => -5))
        );
        self::assertSame(
            array('limit' => 25, 'offset' => 75),
            TypeResolver::page(array('limit' => 25, 'offset' => 75))
        );
    }

    public function testAnUnknownTagRaisesRatherThanGuessing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TypeResolver::resolveType(
            array(TypeResolver::TAG => 'Htaccess'), $this->context(), $this->info()
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver` not found.

- [ ] **Step 3: Write `Resolver/TypeResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

/**
 * The conventions every resolver in this phase shares.
 *
 * A resolver returns a plain array carrying the SDL's own field names, so that
 * ResolverMap's fallback - read the key of the same name off the source -
 * covers every value field and only edges need a map entry. Alongside those it
 * carries underscore-prefixed private keys, which cannot collide with an SDL
 * field because the SDL has none.
 *
 * __tag is the one every abstract type depends on: spec section 3.5's opaque
 * identifiers mean the executor cannot tell a Domain array from a MailAccount
 * array by inspection, so the type is carried explicitly.
 */
final class TypeResolver
{
    /** The private key carrying a value's NodeType tag. */
    const TAG = '__tag';

    /** i-MSCP's admin.gender vocabulary => the schema's. */
    const GENDERS = array(
        'M' => 'MALE',
        'F' => 'FEMALE',
        'U' => 'UNSPECIFIED'
    );

    /** Spec section 7.9's default and ceiling. */
    const PAGE_DEFAULT = 50;
    const PAGE_MAX = 200;

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        // Provisioning, ContactDetails, Quota, Storage and the connections are
        // all plain arrays whose keys are their field names, so they need no
        // entries at all. This map exists so that Container has one uniform
        // way to merge every resolver, including the ones that contribute
        // nothing but a resolveType.
        return array();
    }

    /**
     * Which object type an abstract value actually is.
     *
     * Returns a type name rather than a Type instance, which
     * ReferenceExecutor::ensureValidRuntimeType() accepts and resolves against
     * the schema - so this needs no access to the schema it is attached to.
     *
     * @param mixed $value
     * @throws ApiException when a resolver forgot to tag its array
     * @throws \InvalidArgumentException for a tag NodeType does not know
     */
    public static function resolveType($value, $context, ResolveInfo $info): string
    {
        if (!is_array($value) || !isset($value[self::TAG])) {
            // Left to the executor this would surface as "abstract type must
            // resolve to an Object type at runtime" with no clue which
            // resolver was at fault, which is a bad half-hour for whoever
            // reads the log.
            throw new ApiException(
                ErrorCode::INTERNAL,
                'A value reached an interface field without a type tag.'
            );
        }

        return NodeType::graphqlType($value[self::TAG]);
    }

    /**
     * The array shape of one Node: its tag, its raw key and its opaque id.
     *
     * @param int|string $key
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function node(string $tag, $key, array $fields): array
    {
        $id = NodeType::isStringKeyed($tag)
            ? GlobalId::encodeKey($tag, (string)$key)
            : GlobalId::encode($tag, (int)$key);

        return array_merge($fields, array(
            self::TAG => $tag,
            '__key'   => $key,
            'id'      => $id
        ));
    }

    /**
     * The Provisioning shape of spec section 7.1.
     *
     * @return array{state: string, raw: string, settled: bool, message: string|null}
     */
    public static function provisioning(?string $status): array
    {
        $provisioning = Provisioning::fromStatus($status);

        return array(
            'state'   => $provisioning->getState(),
            'raw'     => $provisioning->getRaw(),
            'settled' => $provisioning->isSettled(),
            'message' => $provisioning->getMessage()
        );
    }

    /**
     * An ISO-8601 instant in UTC, or null.
     *
     * i-MSCP writes 0 rather than NULL for "no expiry" in
     * domain.domain_expires and elsewhere. Rendering that as 1970-01-01 would
     * tell every client the account expired fifty-six years ago.
     *
     * @param mixed $timestamp
     */
    public static function dateTime($timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '' || (int)$timestamp === 0) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp);
    }

    /**
     * A decimal string, because spec section 7.1's BigInt is a string.
     *
     * The SDL's custom scalars are built from the SDL and therefore serialise
     * by identity, so a resolver returning an int would put a JSON number in
     * the response and lose precision above 2^53 - which disk and traffic
     * figures reach.
     *
     * @param mixed $value
     */
    public static function bigInt($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string)(int)$value;
    }

    public static function gender(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::GENDERS[$raw] ?? null;
    }

    /**
     * The ContactDetails shape, shared by Viewer, Customer and Reseller.
     *
     * @param array<string, mixed> $adminRow A row of the `admin` table
     * @param callable $toUnicode fn(string): string - decode_idna, so a
     *                            punycode email address is returned as Unicode
     * @return array<string, mixed>
     */
    public static function contact(array $adminRow, callable $toUnicode): array
    {
        $email = $adminRow['email'] ?? null;

        return array(
            'firstName' => self::text($adminRow, 'fname'),
            'lastName'  => self::text($adminRow, 'lname'),
            'gender'    => self::gender(
                isset($adminRow['gender']) ? (string)$adminRow['gender'] : null
            ),
            'company'   => self::text($adminRow, 'firm'),
            'street1'   => self::text($adminRow, 'street1'),
            'street2'   => self::text($adminRow, 'street2'),
            'city'      => self::text($adminRow, 'city'),
            'state'     => self::text($adminRow, 'state'),
            'postCode'  => self::text($adminRow, 'zip'),
            'country'   => self::text($adminRow, 'country'),
            'email'     => ($email === null || $email === '')
                ? null : (string)call_user_func($toUnicode, (string)$email),
            'phone'     => self::text($adminRow, 'phone'),
            'fax'       => self::text($adminRow, 'fax')
        );
    }

    /**
     * @param mixed $context
     * @throws ApiException
     */
    public static function identity($context): Identity
    {
        return ViewerResolver::identityFrom($context);
    }

    /**
     * The scope gate.
     *
     * Spec section 7.2: a credential's scopes may be narrower than its role.
     * Identity::hasScope() answers true when a token records none at all - a
     * token issued before scopes existed is a full token - so this is the only
     * place that has to ask, and it must ask everywhere a scope applies.
     *
     * It raises rather than returning an empty list. An empty list is
     * indistinguishable from "this customer has none", which would tell a
     * client its token works when it does not.
     *
     * @param mixed $context
     * @throws ApiException FORBIDDEN
     */
    public static function requireScope($context, string $scope): Identity
    {
        $identity = self::identity($context);

        if (!$identity->hasScope($scope)) {
            throw new ApiException(
                ErrorCode::FORBIDDEN,
                'This credential does not carry the scope this field needs.',
                array('scope' => $scope)
            );
        }

        return $identity;
    }

    /**
     * Spec section 7.9's PageInput, normalised and capped.
     *
     * @param array<string, mixed>|null $pageInput
     * @return array{limit: int, offset: int}
     */
    public static function page(?array $pageInput): array
    {
        $limit = (int)($pageInput['limit'] ?? self::PAGE_DEFAULT);
        $offset = (int)($pageInput['offset'] ?? 0);

        return array(
            'limit'  => max(1, min(self::PAGE_MAX, $limit)),
            'offset' => max(0, $offset)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): ?string
    {
        if (!isset($row[$column]) || $row[$column] === '') {
            return null;
        }

        return (string)$row[$column];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
tools/test.sh
```

Expected: PASS, `TypeResolverTest` 17 methods.

- [ ] **Step 5: Grow `schema/schema.graphql` to the full read schema**

Replace the whole file. Everything plan 1 shipped is still here, unchanged in
meaning: the `DateTime`, `EmailAddress` and `Secret` scalars, `Role`, `Scope`,
`Viewer` — which gains the three fields spec §7.2 gives it — and `Query`, which
gains the rest of spec §7.10.

```graphql
# SGW_GraphQL — the i-MSCP API schema.
#
# The read schema of docs/SPECIFICATION.md section 7. Mutations arrive in
# phase 3; nothing here is provisional on their behalf.
#
# Every BigInt in this schema is a count of BYTES. i-MSCP mixes units -
# domain.domain_disk_limit and domain.domain_traffic_limit are in MiB while
# every usage figure is in bytes - and the conversion happens once, on read,
# so that a client comparing diskUsed to diskLimit cannot be out by a factor
# of a million.

schema {
  query: Query
}

"ISO-8601 instant in UTC, e.g. 2026-09-04T11:00:00Z."
scalar DateTime

"""
A 64-bit unsigned integer serialised as a decimal string, because a JSON
number is a double and disk and traffic figures exceed 2^53. Always bytes.
"""
scalar BigInt

"A domain name. Accepted as Unicode or as punycode; always returned as Unicode."
scalar DomainName

"An email address."
scalar EmailAddress

"""
A write-only value. Never appears in a response, and is redacted from the audit
log. Passwords are of this type.
"""
scalar Secret

interface Node {
  id: ID!
}

"""
Whether the i-MSCP backend has caught up with the panel's intent. A mutation
records an intent and the backend carries it out afterwards.
"""
type Provisioning {
  state: ProvisioningState!
  "The literal i-MSCP status string, for support and for forward compatibility."
  raw: String!
  "False while the backend still has work to do for this object."
  settled: Boolean!
  "The backend's failure text, when state is ERROR."
  message: String
}

enum ProvisioningState {
  "ok"
  OK
  "toadd, tochange, todelete, toenable, todisable, torestore, tochangepwd, topurge"
  PENDING
  "disabled"
  DISABLED
  "ordered — a domain alias awaiting the reseller's approval"
  ORDERED
  "Anything else. i-MSCP stores the backend's failure text in the status column."
  ERROR
}

interface Provisioned {
  provisioning: Provisioning!
}

enum Role {
  ADMIN
  RESELLER
  CUSTOMER
}

"""
A scope narrows what a credential may do; it never widens it. New values are
added over time, so a client must tolerate one it has not seen. A field whose
scope the credential does not carry answers FORBIDDEN rather than an empty
list, so that a narrowed token is never mistaken for an empty account.
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

enum Gender {
  MALE
  FEMALE
  UNSPECIFIED
}

type ContactDetails {
  firstName: String
  lastName: String
  gender: Gender
  company: String
  street1: String
  street2: String
  city: String
  state: String
  postCode: String
  country: String
  email: EmailAddress
  phone: String
  fax: String
}

"The account this request is acting as."
type Viewer {
  id: ID!
  username: String!
  role: Role!
  email: EmailAddress
  contact: ContactDetails!
  "The scopes the presented credential carries, which may be narrower than the role."
  scopes: [Scope!]!
  "Set when role is CUSTOMER."
  customer: Customer
  "Set when role is RESELLER."
  reseller: Reseller
}

"""
Anything Apache serves: a main domain, a subdomain, a domain alias or a
subdomain of an alias. i-MSCP keys these by the pair (type, id) with type in
dmn|sub|als|alssub; this interface is the same idea without four code paths.
"""
interface VirtualHost {
  id: ID!
  name: DomainName!
  "Path under the customer's web root, e.g. /shop."
  mountPoint: String!
  "Path under the mount point that Apache serves, e.g. /htdocs."
  documentRoot: String!
  forwarding: Forwarding
  "Whether *.name is served by this host as well."
  wildcard: Boolean!
  provisioning: Provisioning!
}

type Forwarding {
  url: String!
  type: ForwardType!
  "For PROXY only: whether the original Host header is preserved."
  keepHost: Boolean!
}

enum ForwardType {
  PERMANENT_301
  FOUND_302
  SEE_OTHER_303
  TEMPORARY_307
  PROXY
}

type Domain implements Node & Provisioned & VirtualHost {
  id: ID!
  name: DomainName!
  mountPoint: String!
  documentRoot: String!
  forwarding: Forwarding
  wildcard: Boolean!
  provisioning: Provisioning!
  customer: Customer!
  ipAddress: IpAddress!
  createdAt: DateTime!
  "Null when the account does not expire."
  expiresAt: DateTime
  subdomains: [Subdomain!]!
  aliases: [DomainAlias!]!
}

type Subdomain implements Node & Provisioned & VirtualHost {
  id: ID!
  name: DomainName!
  mountPoint: String!
  documentRoot: String!
  forwarding: Forwarding
  wildcard: Boolean!
  provisioning: Provisioning!
  "The label alone, without the parent's name."
  label: String!
  "A Domain, or a DomainAlias when this is a subdomain of an alias."
  parent: VirtualHost!
  customer: Customer!
}

type DomainAlias implements Node & Provisioned & VirtualHost {
  id: ID!
  name: DomainName!
  mountPoint: String!
  documentRoot: String!
  forwarding: Forwarding
  wildcard: Boolean!
  provisioning: Provisioning!
  domain: Domain!
  customer: Customer!
  subdomains: [Subdomain!]!
}

"""
One countable allowance. Normalises i-MSCP's three-valued limit columns:
-1 (feature withheld), 0 (unlimited), n (n).
"""
type Quota {
  "False when the feature is withheld from the account entirely."
  enabled: Boolean!
  "Null when unlimited."
  limit: Int
  used: Int!
  "limit - used, or null when unlimited. Never negative."
  remaining: Int
}

"All figures in bytes."
type Storage {
  "Null when unlimited."
  diskLimit: BigInt
  diskUsed: BigInt!
  diskFiles: BigInt!
  diskMail: BigInt!
  diskSql: BigInt!
  "Null when unlimited."
  trafficLimit: BigInt
  "This calendar month's web, FTP, mail and POP traffic added together."
  trafficUsed: BigInt!
  "Default per-mailbox quota. Null when unlimited."
  mailQuota: BigInt
}

type CustomerQuotas {
  subdomains: Quota!
  domainAliases: Quota!
  mailAccounts: Quota!
  ftpUsers: Quota!
  sqlDatabases: Quota!
  sqlUsers: Quota!
}

"What the panel itself would answer for this customer, verbatim and per name."
type CustomerFeatures {
  php: Boolean!
  phpEditor: Boolean!
  cgi: Boolean!
  customDns: Boolean!
  externalMail: Boolean!
  backup: Boolean!
  ssl: Boolean!
  webStats: Boolean!
  supportSystem: Boolean!
}

type Customer implements Node & Provisioned {
  id: ID!
  username: String!
  "The reseller's own reference for this customer (admin.customer_id)."
  reference: String
  contact: ContactDetails!
  reseller: Reseller!
  createdAt: DateTime!
  "Null when the account does not expire."
  expiresAt: DateTime
  domain: Domain!
  quotas: CustomerQuotas!
  storage: Storage!
  features: CustomerFeatures!
  "Whether this account may use this API at all."
  apiAccess: Boolean!
  provisioning: Provisioning!

  "Subdomains of the main domain and of every alias alike."
  subdomains: [Subdomain!]!
  domainAliases: [DomainAlias!]!
  mailAccounts(filter: MailAccountFilter, page: PageInput): MailAccountConnection!
  ftpUsers(page: PageInput): FtpUserConnection!
  sqlDatabases: [SqlDatabase!]!
  sqlUsers: [SqlUser!]!
  dnsRecords: [DnsRecord!]!
}

type MailAccount implements Node & Provisioned {
  id: ID!
  address: EmailAddress!
  kind: MailAccountKind!
  "The vhost this address belongs to."
  host: VirtualHost!
  forwardTo: [EmailAddress!]!
  "Null when unlimited. Bytes."
  quota: BigInt
  """
  Bytes currently held in the mailbox, from the maildirsize file the mail
  server maintains. Null when there is no mailbox, when no quota is set, or
  when the file cannot be read.
  """
  quotaUsed: BigInt
  "Whether POP/IMAP access is enabled (mail_users.po_active)."
  active: Boolean!
  autoresponder: Autoresponder
  provisioning: Provisioning!
}

enum MailAccountKind {
  MAILBOX
  FORWARD
  MAILBOX_AND_FORWARD
  CATCHALL
}

type Autoresponder {
  enabled: Boolean!
  message: String!
}

type FtpUser implements Node & Provisioned {
  id: ID!
  "user@domain — the FTP login."
  username: String!
  "Absolute path on the server."
  homeDirectory: String!
  customer: Customer!
  provisioning: Provisioning!
}

"""
Note the absence of `provisioning`. SQL databases and users are created
synchronously by the panel itself, not by the backend.
"""
type SqlDatabase implements Node {
  id: ID!
  name: String!
  customer: Customer!
  users: [SqlUser!]!
}

type SqlUser implements Node {
  id: ID!
  name: String!
  "The host the SQL user may connect from."
  host: String!
  "One row per grant, so a user granted on three databases appears in three."
  databases: [SqlDatabase!]!
}

type DnsRecord implements Node & Provisioned {
  id: ID!
  "The domain or alias this record hangs off."
  host: VirtualHost!
  name: String!
  class: DnsClass!
  type: DnsRecordType!
  value: String!
  """
  'custom_dns_feature' for a customer's own record; otherwise the name of the
  plugin that owns it. A record owned by a plugin is read-only here.
  """
  ownedBy: String!
  provisioning: Provisioning!
}

enum DnsClass {
  IN
  CH
  HS
}

enum DnsRecordType {
  A
  AAAA
  CERT
  CNAME
  DNAME
  GPOS
  KEY
  KX
  MX
  NAPTR
  NSAP
  NS
  NXT
  PTR
  PX
  SIG
  SRV
  TXT
  SPF
}

type IpAddress implements Node {
  id: ID!
  address: String!
  netmask: Int
  card: String
}

"One allowance a hosting plan grants, before any customer has used any of it."
type PlanAllowance {
  "False when the plan withholds the feature entirely."
  enabled: Boolean!
  "Null when unlimited."
  limit: Int
}

type PlanQuotas {
  subdomains: PlanAllowance!
  domainAliases: PlanAllowance!
  mailAccounts: PlanAllowance!
  ftpUsers: PlanAllowance!
  sqlDatabases: PlanAllowance!
  sqlUsers: PlanAllowance!
}

"All figures in bytes. Null means unlimited."
type PlanStorage {
  disk: BigInt
  traffic: BigInt
  mailQuota: BigInt
}

enum BackupTarget {
  DOMAIN
  SQL
  MAIL
}

type PlanFeatures {
  php: Boolean!
  phpEditor: Boolean!
  cgi: Boolean!
  customDns: Boolean!
  externalMail: Boolean!
  webFolderProtection: Boolean!
  "Empty when the plan grants no backup at all."
  backup: [BackupTarget!]!
}

type HostingPlan implements Node {
  id: ID!
  name: String!
  description: String
  reseller: Reseller!
  available: Boolean!
  quotas: PlanQuotas!
  storage: PlanStorage!
  features: PlanFeatures!
}

"""
A reseller's own allowances. Unlike a customer's, these have no withheld
state: 0 means unlimited.
"""
type ResellerQuotas {
  customers: Quota!
  subdomains: Quota!
  domainAliases: Quota!
  mailAccounts: Quota!
  ftpUsers: Quota!
  sqlDatabases: Quota!
  sqlUsers: Quota!
}

"""
A reseller. A customer may read the identity of their own reseller — id,
username and contact details — and nothing else: every other field answers
FORBIDDEN for a caller who is not that reseller or an administrator.
"""
type Reseller implements Node & Provisioned {
  id: ID!
  username: String!
  contact: ContactDetails!
  createdAt: DateTime!
  provisioning: Provisioning!
  "Reseller or administrator only."
  quotas: ResellerQuotas!
  "Reseller or administrator only. All figures in bytes."
  storage: Storage!
  "Reseller or administrator only."
  ipAddresses: [IpAddress!]!
  "Reseller or administrator only."
  apiAccess: Boolean!
  """
  The customers of this reseller that the caller may reach. A customer asking
  sees only themselves.
  """
  customers(filter: CustomerFilter, page: PageInput): CustomerConnection!
  "Reseller or administrator only."
  hostingPlans: [HostingPlan!]!
}

"limit is capped at 200 by the server regardless of what is asked for."
input PageInput {
  limit: Int = 50
  offset: Int = 0
}

input CustomerFilter {
  username: String
  domainName: DomainName
  state: ProvisioningState
  "Administrators only; ignored for a reseller, who only ever sees their own."
  resellerId: ID
}

input ResellerFilter {
  username: String
  state: ProvisioningState
}

input MailAccountFilter {
  kind: MailAccountKind
  "Substring match on the address, as the panel's own list does."
  address: String
  state: ProvisioningState
}

type CustomerConnection {
  totalCount: Int!
  nodes: [Customer!]!
}

type MailAccountConnection {
  totalCount: Int!
  nodes: [MailAccount!]!
}

type FtpUserConnection {
  totalCount: Int!
  nodes: [FtpUser!]!
}

type ResellerConnection {
  totalCount: Int!
  nodes: [Reseller!]!
}

type Query {
  "The schema version this endpoint serves. See docs/SPECIFICATION.md section 18."
  apiVersion: String!
  viewer: Viewer!
  "Any object by its opaque identifier. Null when the caller cannot reach it."
  node(id: ID!): Node

  customer(id: ID!): Customer
  customers(filter: CustomerFilter, page: PageInput): CustomerConnection!
  reseller(id: ID!): Reseller
  resellers(filter: ResellerFilter, page: PageInput): ResellerConnection!

  "Everything the caller owns that the backend has not finished with."
  pending: [Node!]!
}
```

Four things in that file are not in spec §7 and are invented here, because §7
uses them without defining them: `ResellerFilter`, `MailAccountFilter`, the
plan shapes (`PlanQuotas`, `PlanStorage`, `PlanFeatures`, `PlanAllowance`,
`BackupTarget`) and `ResellerQuotas`. Each is derived from the thing it
describes — `PlanAllowance` is `Quota` without `used`, because a plan has no
usage; `ResellerQuotas` gains a `customers` allowance because
`reseller_props.max_dmn_cnt` is the customer limit and has no other home. The
[Self-review](#self-review) lists them so the spec can be brought level.

- [ ] **Step 6: Add `resolveType` to `SchemaFactory`**

The change is additive: a fourth constructor parameter with a default, so plan
1's three-argument construction and every plan 1 test keep working unchanged.

In `Schema/SchemaFactory.php`, add the import

```php
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
```

add the property and widen the constructor:

```php
    /** @var callable|null */
    private $resolveType;

    /**
     * @param callable|null $resolveType fn($value, $context, ResolveInfo): string
     *                                   Attached to every interface in the SDL.
     *                                   Optional so that plan 1's three-argument
     *                                   construction still builds the viewer
     *                                   slice, which has no interfaces.
     */
    public function __construct(
        string $sdlPath, ?string $cacheDir, ResolverMap $resolvers,
        ?callable $resolveType = null
    ) {
        $this->sdlPath = $sdlPath;
        $this->cacheDir = $cacheDir;
        $this->resolvers = $resolvers;
        $this->resolveType = $resolveType;
    }
```

and, inside `create()`, capture it and attach it to interfaces. The decorator
closure gains one block; nothing else in `create()` changes:

```php
    public function create(): Schema
    {
        $resolvers = $this->resolvers;
        $resolveType = $this->resolveType;

        return BuildSchema::build(
            $this->document(),
            static function (array $typeConfig, $typeDefinitionNode) use (
                $resolvers, $resolveType
            ) {
                $typeName = $typeConfig['name'];

                // Node, Provisioned and VirtualHost. Without this, the first
                // query selecting an interface field fails with "abstract type
                // must resolve to an Object type at runtime" - and it fails at
                // execution, not at build, so a schema test alone would not
                // catch it.
                if ($resolveType !== null
                    && $typeDefinitionNode instanceof InterfaceTypeDefinitionNode
                ) {
                    $typeConfig['resolveType'] = $resolveType;
                }

                $typeConfig['resolveField'] = static function (
                    $source, $args, $context, ResolveInfo $info
                ) use ($resolvers, $typeName) {
                    // ... unchanged ...
                };

                return $typeConfig;
            }
        );
    }
```

- [ ] **Step 7: Write the failing schema test**

`test/schema/SchemaTest.php`. Spec §17 asks for four things of this suite: the
SDL parses and `assertValid()` passes; every field has a resolver or a working
default; a committed snapshot matches; and the error codes line up. The middle
one needs the whole resolver map, which does not exist until Task 16, so it
lands there. This task covers the rest.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Utils\SchemaPrinter;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    private function sdlPath(): string
    {
        return dirname(__DIR__, 2) . '/schema/schema.graphql';
    }

    private function factory(): SchemaFactory
    {
        // No cache directory: a schema test must read the file it is testing,
        // not whatever a previous run left in the cache.
        return new SchemaFactory(
            $this->sdlPath(), null, new ResolverMap(array()),
            array(TypeResolver::class, 'resolveType')
        );
    }

    public function testTheSchemaBuildsAndIsValid(): void
    {
        $schema = $this->factory()->create();
        $schema->assertValid();

        self::assertInstanceOf(ObjectType::class, $schema->getQueryType());
    }

    public function testEveryNodeTypeTagNamesATypeInTheSchema(): void
    {
        // The failure this catches: a tag added to NodeType with no type in
        // the SDL, which resolveType would turn into "abstract type must
        // resolve to an Object type" at run time, for one customer, in
        // production.
        $schema = $this->factory()->create();

        foreach (array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::SUBDOMAIN,
            NodeType::ALIAS_SUBDOMAIN, NodeType::DOMAIN_ALIAS,
            NodeType::MAIL_ACCOUNT, NodeType::FTP_USER, NodeType::SQL_DATABASE,
            NodeType::SQL_USER, NodeType::DNS_RECORD, NodeType::RESELLER,
            NodeType::HOSTING_PLAN, NodeType::IP_ADDRESS
        ) as $tag) {
            $type = $schema->getType(NodeType::graphqlType($tag));

            self::assertInstanceOf(ObjectType::class, $type, $tag);
        }
    }

    public function testTheThreeInterfacesHaveARuntimeTypeResolver(): void
    {
        // An interface built from SDL has no resolveType of its own. Missing
        // it is not a build error and not a validation error - it fails only
        // when a query first selects an interface field.
        $schema = $this->factory()->create();

        foreach (array('Node', 'Provisioned', 'VirtualHost') as $name) {
            $type = $schema->getType($name);

            self::assertInstanceOf(InterfaceType::class, $type, $name);
            self::assertIsCallable($type->config['resolveType'] ?? null, $name);
        }
    }

    public function testEveryVirtualHostTypeImplementsAllThreeInterfaces(): void
    {
        $schema = $this->factory()->create();

        foreach (array('Domain', 'Subdomain', 'DomainAlias') as $name) {
            /** @var ObjectType $type */
            $type = $schema->getType($name);
            $interfaces = array();

            foreach ($type->getInterfaces() as $interface) {
                $interfaces[] = $interface->name;
            }

            sort($interfaces);
            self::assertSame(array('Node', 'Provisioned', 'VirtualHost'), $interfaces, $name);
        }
    }

    public function testSqlDatabaseAndSqlUserAreNotProvisioned(): void
    {
        // Spec section 2.1: the panel creates these synchronously. Giving them
        // a provisioning field would promise a settled/pending distinction
        // that the database cannot answer, because there is no status column.
        $schema = $this->factory()->create();

        foreach (array('SqlDatabase', 'SqlUser') as $name) {
            /** @var ObjectType $type */
            $type = $schema->getType($name);

            self::assertFalse($type->hasField('provisioning'), $name);
        }
    }

    public function testThePrintedSchemaMatchesTheCommittedSnapshot(): void
    {
        // Spec section 17: "a committed snapshot of the SDL must match, so a
        // schema change is always a visible diff in review". The snapshot is
        // the printed schema rather than the file, so that a change made by
        // reordering or by a comment does not produce a diff and a change to
        // an actual type always does.
        $printed = SchemaPrinter::doPrint($this->factory()->create());
        $snapshot = dirname(__DIR__) . '/schema/schema.printed.graphql';

        self::assertFileExists($snapshot);
        self::assertSame(
            file_get_contents($snapshot),
            $printed,
            'The schema changed. Review the diff, then run: '
                . 'php -r "..." > test/schema/schema.printed.graphql'
        );
    }
}
```

- [ ] **Step 8: Run the test to verify it fails**

```bash
tools/test.sh
```

Expected: FAIL — `testThePrintedSchemaMatchesTheCommittedSnapshot` fails on the
missing snapshot file, and `testTheThreeInterfacesHaveARuntimeTypeResolver`
fails if Step 6 was skipped. If the `schema` suite does not run at all, Task 2's
`test/phpunit.xml` edit did not land.

- [ ] **Step 9: Generate the committed snapshot**

```bash
tools/deploy.sh
ssh -F .ssh-config imscp_debian_trixie \
  "cd /var/www/imscp/gui/plugins/SGW_GraphQL && php7.4 -r '
    require \"vendor/autoload.php\";
    \$f = new iMSCP\\Plugin\\SGW_GraphQL\\Schema\\SchemaFactory(
        \"schema/schema.graphql\", null,
        new iMSCP\\Plugin\\SGW_GraphQL\\Schema\\ResolverMap(array()),
        array(iMSCP\\Plugin\\SGW_GraphQL\\Resolver\\TypeResolver::class, \"resolveType\")
    );
    echo GraphQL\\Utils\\SchemaPrinter::doPrint(\$f->create());
  '" > test/schema/schema.printed.graphql
```

Expected: a file of roughly 450 lines beginning with `type Query {`. Read the
first twenty lines before committing it — a snapshot generated from a schema
that does not build is an empty file, and an empty file would make the test
pass for the wrong reason.

- [ ] **Step 10: Run the tests to verify they pass**

```bash
tools/test.sh
sh test/lint/all.sh
```

Expected: PASS. `SchemaTest` 6 methods, `TypeResolverTest` 17 methods, and every
plan 1 test still green — in particular `SchemaFactoryTest`, which constructs
`SchemaFactory` with three arguments and must be untouched.

- [ ] **Step 11: Commit**

```bash
git add schema/schema.graphql Schema/SchemaFactory.php Resolver/TypeResolver.php \
        test/schema/SchemaTest.php test/schema/schema.printed.graphql \
        test/unit/Resolver/TypeResolverTest.php
git commit -m "Grow the schema to the whole read model

The viewer slice becomes specification section 7 in full: the four vhost kinds
behind one interface, the customer graph, mail, FTP, SQL, DNS, hosting plans
and resellers. Four shapes section 7 uses without defining are invented here -
ResellerFilter, MailAccountFilter, the hosting-plan shapes and ResellerQuotas -
each derived from the thing it describes.

An interface built from SDL has no runtime type resolver, and its absence is
neither a build error nor a validation error: it fails the first time a query
selects an interface field. SchemaFactory therefore takes one additively, and
a test asserts all three interfaces have it.

Scopes are now checked. Identity::hasScope answers true for a token that
records none, which is right for a token issued before scopes existed and
useless if nothing ever asks - so requireScope() is the one place that asks,
and it refuses rather than returning an empty list a client would read as an
empty account."
```

---

## Task 12: The virtual-host resolvers — `sonnet`

Spec §7.3: `Domain`, `Subdomain` and `DomainAlias`, all three implementing
`VirtualHost`, all three coming from the same normalised row that Task 9's
`Repository\VirtualHosts` produces.

**Cross-type edges are injected, not imported.** `Domain.customer` has to
produce a `Customer`, which Task 13 shapes; `Domain.ipAddress` has to produce an
`IpAddress`, which Task 14 shapes. Importing those resolvers here would make
Tasks 12, 13 and 14 mutually dependent and unbuildable in order. Instead this
class takes two closures, and `Api\Container` (Task 16) supplies them from the
resolvers that own those shapes. The contracts are fixed here, in the Interfaces
block, and Tasks 13 and 14 satisfy them.

**Files:**
- Create: `Resolver/VirtualHostResolver.php`
- Create: `test/unit/Resolver/VirtualHostResolverTest.php`
- Create: `test/integration/VirtualHostResolverTest.php`

**Interfaces:**
- Consumes:
  - `new BatchLoader(Db $db)`, `BatchLoader::keyed(string $bucket, $key, callable $loader): Deferred`,
    `BatchLoader::byColumn(string $modelClass, string $column, $value): SyncPromise`,
    `BatchLoader::flushes(): int` (Task 10)
  - `Model\DomainModel`, its `__construct(\PDO $pdo)` and its `domain_created`
    and `domain_expires` properties (Task 9)
  - `Db::rows()`, `Db::placeholders()`, `Db::detached()`, `Db::countQueries()` (Tasks 2, 10)
  - `new VirtualHosts(Db $db)`, `VirtualHosts::KIND_DMN|KIND_SUB|KIND_ALS|KIND_ALSSUB`,
    `VirtualHosts::ofKind(string $kind, array $domainIds): array`,
    `VirtualHosts::aliasSubdomainsOf(array $aliasIds): array`,
    `VirtualHosts::byKeys(array $keys): array`,
    `VirtualHosts::kindFor(string $tag): string`, `VirtualHosts::tagFor(string $kind): string`,
    and the normalised row keys `tag kind key domainId ownerId name label parentTag
    parentKey mountPoint documentRoot urlForward typeForward hostForward wildcard
    status ipId` (Task 9)
  - `TypeResolver::node()`, `::provisioning()`, `::dateTime()`, `::requireScope()`,
    `::TAG` (Task 11)
  - `NodeType` tag constants (Task 1), `Scope::DOMAINS_READ` (plan 1)
  - `callable $customerRef` — **`fn(int $adminId): SyncPromise`** resolving to a
    shaped `Customer` array. Supplied by `Container` from
    `CustomerResolver::reference()` (Task 13).
  - `callable $ipRef` — **`fn(int $ipId): SyncPromise`** resolving to a shaped
    `IpAddress` array. Supplied by `Container` from `DnsResolver::ipReference()`
    (Task 14).
  - `callable $toUnicode` — `fn(string $name): string`, the panel's
    `decode_idna()`. Injected because `decode_idna()` needs a `Registry`.
- Produces:
  - `new VirtualHostResolver(BatchLoader $loader, VirtualHosts $vhosts, Db $db, callable $toUnicode, callable $customerRef, callable $ipRef)`
  - `VirtualHostResolver::map(): array` — the `Domain.*`, `Subdomain.*` and
    `DomainAlias.*` entries
  - `VirtualHostResolver::shape(array $vhostRow, callable $toUnicode): array` —
    **static**; a normalised vhost row becomes the array every one of the three
    types resolves against. Keys: `id`, `name`, `mountPoint`, `documentRoot`,
    `wildcard`, `label`, `forwarding`, `provisioning`, plus the private
    `__tag`, `__key`, `__kind`, `__domainId`, `__ownerId`, `__parentTag`,
    `__parentKey`, `__ipId`.
  - `VirtualHostResolver::forwarding(array $vhostRow): ?array` — **static**;
    `array('url' => string, 'type' => string, 'keepHost' => bool)` or null
  - `VirtualHostResolver::FORWARD_TYPES` — `'301' => 'PERMANENT_301'`,
    `'302' => 'FOUND_302'`, `'303' => 'SEE_OTHER_303'`, `'307' => 'TEMPORARY_307'`,
    `'proxy' => 'PROXY'`
  - `VirtualHostResolver::reference(string $tag, $key): SyncPromise` — resolves to
    a shaped vhost array, or null. One query per vhost kind per level, never one
    per identifier. Tasks 14 and 16 use it for `MailAccount.host`,
    `DnsRecord.host` and `Query.node`.
  - `VirtualHostResolver::forDomain(int $domainId): SyncPromise` — resolves to the
    shaped `Domain` of that main domain. Task 13 uses it for `Customer.domain`.

- [ ] **Step 1: Write the failing unit test**

`test/unit/Resolver/VirtualHostResolverTest.php`. The shaping is pure, so it is
tested without a database; the edges are tested against the fixture in Step 4.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class VirtualHostResolverTest extends TestCase
{
    /**
     * A normalised row exactly as Repository\VirtualHosts produces one.
     */
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'tag'          => NodeType::DOMAIN,
            'kind'         => VirtualHosts::KIND_DMN,
            'key'          => 12,
            'domainId'     => 12,
            'ownerId'      => 7,
            'name'         => 'xn--bcher-kva.test',
            'label'        => null,
            'parentTag'    => null,
            'parentKey'    => null,
            'mountPoint'   => '/',
            'documentRoot' => '/htdocs',
            'urlForward'   => 'no',
            'typeForward'  => null,
            'hostForward'  => 'Off',
            'wildcard'     => false,
            'status'       => 'ok',
            'ipId'         => 3
        ), $overrides);
    }

    private function toUnicode(): callable
    {
        return static function (string $name) {
            return $name === 'xn--bcher-kva.test' ? 'bücher.test' : $name;
        };
    }

    public function testANameIsReturnedAsUnicode(): void
    {
        // Spec section 7.1: DomainName is "accepted as Unicode or as punycode;
        // always returned as Unicode". i-MSCP stores punycode, so a resolver
        // that passed the column through would return the wrong string for
        // every internationalised domain on the box.
        $shape = VirtualHostResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('bücher.test', $shape['name']);
    }

    public function testTheIdentifierCarriesTheTagNotTheGraphqlType(): void
    {
        // Decision D1. subdomain 3 and subdomain_alias 3 belong to different
        // customers; if both encoded as Subdomain:3, one customer's identifier
        // would address the other's row.
        $shape = VirtualHostResolver::shape($this->row(array(
            'tag' => NodeType::ALIAS_SUBDOMAIN, 'kind' => VirtualHosts::KIND_ALSSUB,
            'key' => 3, 'label' => 'blog', 'parentTag' => NodeType::DOMAIN_ALIAS,
            'parentKey' => 4
        )), $this->toUnicode());

        self::assertSame(
            GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, 3), $shape['id']
        );
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $shape[TypeResolver::TAG]);
        self::assertNotSame(GlobalId::encode(NodeType::SUBDOMAIN, 3), $shape['id']);
    }

    public function testNoForwardingWhenTheColumnHoldsTheStringNo(): void
    {
        // i-MSCP stores the literal 'no' rather than NULL. Returning it as a
        // Forwarding would tell every client that every unforwarded host
        // redirects to a URL called "no".
        self::assertNull(VirtualHostResolver::forwarding($this->row()));
    }

    public function testNoForwardingWhenTheColumnIsEmpty(): void
    {
        self::assertNull(
            VirtualHostResolver::forwarding($this->row(array('urlForward' => '')))
        );
    }

    /**
     * @dataProvider forwardTypes
     */
    public function testEveryForwardTypeMaps(string $stored, string $expected): void
    {
        $forwarding = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => $stored
        )));

        self::assertSame('https://example.net/', $forwarding['url']);
        self::assertSame($expected, $forwarding['type']);
    }

    public function forwardTypes(): array
    {
        return array(
            '301'   => array('301', 'PERMANENT_301'),
            '302'   => array('302', 'FOUND_302'),
            '303'   => array('303', 'SEE_OTHER_303'),
            '307'   => array('307', 'TEMPORARY_307'),
            'proxy' => array('proxy', 'PROXY')
        );
    }

    public function testKeepHostIsTrueOnlyWhenTheColumnSaysOn(): void
    {
        $on = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => 'proxy',
            'hostForward' => 'On'
        )));
        $off = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => 'proxy',
            'hostForward' => 'Off'
        )));

        self::assertTrue($on['keepHost']);
        self::assertFalse($off['keepHost']);
    }

    public function testAForwardWithNoTypeDefaultsToAPermanentRedirect(): void
    {
        // type_forward is nullable and url_forward is not, so a row can carry
        // a URL and no type. The panel's own edit form defaults the radio to
        // 301, so this agrees with it rather than nulling a non-null field.
        $forwarding = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => null
        )));

        self::assertSame('PERMANENT_301', $forwarding['type']);
    }

    public function testTheShapeCarriesTheOwnerAndTheDomainForItsEdges(): void
    {
        // Without these every edge below a vhost would have to re-resolve
        // ownership, which is one query per row.
        $shape = VirtualHostResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(7, $shape['__ownerId']);
        self::assertSame(12, $shape['__domainId']);
        self::assertSame(3, $shape['__ipId']);
        self::assertSame(VirtualHosts::KIND_DMN, $shape['__kind']);
    }

    public function testASubdomainCarriesItsLabelAndItsParent(): void
    {
        $shape = VirtualHostResolver::shape($this->row(array(
            'tag' => NodeType::SUBDOMAIN, 'kind' => VirtualHosts::KIND_SUB,
            'key' => 5, 'name' => 'shop.bücher.test', 'label' => 'shop',
            'parentTag' => NodeType::DOMAIN, 'parentKey' => 12, 'ipId' => null
        )), $this->toUnicode());

        self::assertSame('shop', $shape['label']);
        self::assertSame(NodeType::DOMAIN, $shape['__parentTag']);
        self::assertSame(12, $shape['__parentKey']);
        self::assertNull($shape['__ipId']);
    }

    public function testProvisioningComesFromTheStatusColumn(): void
    {
        $shape = VirtualHostResolver::shape(
            $this->row(array('status' => 'toadd')), $this->toUnicode()
        );

        self::assertSame('PENDING', $shape['provisioning']['state']);
        self::assertSame('toadd', $shape['provisioning']['raw']);
        self::assertFalse($shape['provisioning']['settled']);
    }

    public function testTheMapCoversEveryEdgeOfAllThreeTypes(): void
    {
        // The failure this catches: an edge added to the SDL and not to the
        // map, which would fall back to reading a key off the source array,
        // find nothing and return null - silently, for a non-null field, so
        // the whole object nulls.
        $resolver = new VirtualHostResolver(
            new \iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader(
                \iMSCP\Plugin\SGW_GraphQL\Repository\Db::detached()
            ),
            new VirtualHosts(\iMSCP\Plugin\SGW_GraphQL\Repository\Db::detached()),
            \iMSCP\Plugin\SGW_GraphQL\Repository\Db::detached(),
            $this->toUnicode(),
            static function (int $adminId) { return null; },
            static function (int $ipId) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        // In this order, and it is worth knowing why: sort() is byte order,
        // '.' is 0x2E and 'A' is 0x41, so every 'Domain.' key sorts before
        // every 'DomainAlias.' key.
        self::assertSame(array(
            'Domain.aliases', 'Domain.createdAt', 'Domain.customer',
            'Domain.expiresAt', 'Domain.ipAddress', 'Domain.subdomains',
            'DomainAlias.customer', 'DomainAlias.domain', 'DomainAlias.subdomains',
            'Subdomain.customer', 'Subdomain.parent'
        ), $keys);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver` not found.

- [ ] **Step 3: Write `Resolver/VirtualHostResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * Domain, Subdomain and DomainAlias - spec section 7.3's three virtual-host
 * types, all shaped from the one normalised row Repository\VirtualHosts
 * produces, so that the four-way dmn/sub/als/alssub split exists in exactly
 * one place.
 *
 * Every edge goes through BatchLoader. A resolver that queried per row would
 * cost one query per subdomain on a page that lists them, which is the N+1
 * spec section 10.1 calls a defect.
 */
final class VirtualHostResolver
{
    /** i-MSCP's type_forward vocabulary => the schema's ForwardType. */
    const FORWARD_TYPES = array(
        '301'   => 'PERMANENT_301',
        '302'   => 'FOUND_302',
        '303'   => 'SEE_OTHER_303',
        '307'   => 'TEMPORARY_307',
        'proxy' => 'PROXY'
    );

    /** @var BatchLoader */
    private $loader;

    /** @var VirtualHosts */
    private $vhosts;

    /** @var Db */
    private $db;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    /** @var callable fn(int $ipId): SyncPromise */
    private $ipRef;

    public function __construct(
        BatchLoader $loader, VirtualHosts $vhosts, Db $db, callable $toUnicode,
        callable $customerRef, callable $ipRef
    ) {
        $this->loader = $loader;
        $this->vhosts = $vhosts;
        $this->db = $db;
        $this->toUnicode = $toUnicode;
        $this->customerRef = $customerRef;
        $this->ipRef = $ipRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Domain.customer'         => array($this, 'resolveCustomer'),
            'Domain.ipAddress'        => array($this, 'resolveIpAddress'),
            'Domain.createdAt'        => array($this, 'resolveCreatedAt'),
            'Domain.expiresAt'        => array($this, 'resolveExpiresAt'),
            'Domain.subdomains'       => array($this, 'resolveDomainSubdomains'),
            'Domain.aliases'          => array($this, 'resolveDomainAliases'),
            'Subdomain.parent'        => array($this, 'resolveParent'),
            'Subdomain.customer'      => array($this, 'resolveCustomer'),
            'DomainAlias.domain'      => array($this, 'resolveAliasDomain'),
            'DomainAlias.customer'    => array($this, 'resolveCustomer'),
            'DomainAlias.subdomains'  => array($this, 'resolveAliasSubdomains')
        );
    }

    /**
     * One normalised vhost row becomes the array all three types resolve
     * against. Everything that is a value is here under its SDL field name, so
     * ResolverMap's fallback covers it; everything an edge needs is here under
     * an underscore-prefixed key, which no SDL field can collide with.
     *
     * @param array<string, mixed> $row A Repository\VirtualHosts normalised row
     * @param callable $toUnicode fn(string): string
     * @return array<string, mixed>
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        return TypeResolver::node($row['tag'], $row['key'], array(
            // i-MSCP stores punycode; spec section 7.1 says DomainName is
            // always returned as Unicode.
            'name'         => (string)call_user_func($toUnicode, $row['name']),
            'mountPoint'   => $row['mountPoint'],
            'documentRoot' => $row['documentRoot'],
            'wildcard'     => $row['wildcard'],
            'label'        => $row['label'],
            'forwarding'   => self::forwarding($row),
            'provisioning' => TypeResolver::provisioning($row['status']),
            '__kind'       => $row['kind'],
            '__domainId'   => $row['domainId'],
            '__ownerId'    => $row['ownerId'],
            '__parentTag'  => $row['parentTag'],
            '__parentKey'  => $row['parentKey'],
            '__ipId'       => $row['ipId']
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{url: string, type: string, keepHost: bool}|null
     */
    public static function forwarding(array $row): ?array
    {
        $url = (string)$row['urlForward'];

        // url_forward is NOT NULL DEFAULT 'no' in all four tables: the literal
        // string 'no' is how i-MSCP says "no forwarding".
        if ($url === '' || $url === 'no') {
            return null;
        }

        $type = $row['typeForward'] === null ? '' : (string)$row['typeForward'];

        return array(
            'url'  => $url,
            // type_forward is nullable while url_forward is not, so a row can
            // carry a URL and no type. The panel's own edit form defaults the
            // radio to 301, and this agrees with it rather than nulling a
            // non-null schema field.
            'type' => self::FORWARD_TYPES[$type] ?? 'PERMANENT_301',
            // 'On' only for a proxy; every other row stores 'Off'.
            'keepHost' => $row['hostForward'] === 'On'
        );
    }

    /**
     * One vhost by tag and key, batched: one query per kind per level, never
     * one per identifier.
     *
     * @param int|string $key
     */
    public function reference(string $tag, $key): SyncPromise
    {
        $kind = VirtualHosts::kindFor($tag);
        $vhosts = $this->vhosts;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'vhost:' . $kind,
            (int)$key,
            static function (array $keys) use ($vhosts, $kind) {
                $pairs = array();

                foreach ($keys as $one) {
                    $pairs[] = array($kind, $one);
                }

                $found = array();

                foreach ($vhosts->byKeys($pairs) as $composite => $row) {
                    $found[$row['key']] = $row;
                }

                return $found;
            }
        )->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    /**
     * The shaped Domain of one main domain. Task 13's Customer.domain uses it.
     */
    public function forDomain(int $domainId): SyncPromise
    {
        return $this->reference(NodeType::DOMAIN, $domainId);
    }

    public function resolveCustomer($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return call_user_func($this->customerRef, (int)$source['__ownerId']);
    }

    public function resolveIpAddress($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return call_user_func($this->ipRef, (int)$source['__ipId']);
    }

    public function resolveCreatedAt($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        // Gated like every other field on this type. A MAIL_READ-only token
        // reaches a Domain through MailAccount.host, and without this it could
        // read the account's dates through a credential scoped to mail.
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        $domainId = (int)$source['__domainId'];

        return $this->domainRow($domainId)
            ->then(static function ($domain) use ($domainId) {
                if ($domain === null) {
                    // Domain.createdAt is DateTime! - non-null. Returning null
                    // would null the whole Domain with graphql-php's bare
                    // "cannot return null for non-nullable field", which names
                    // the field and not the reason. A vhost row whose `domain`
                    // row has vanished is broken data, and saying so is more
                    // use than a nulled object.
                    throw new ApiException(
                        ErrorCode::INTERNAL,
                        'This virtual host has no domain row.',
                        array('domainId' => $domainId)
                    );
                }

                return TypeResolver::dateTime($domain->domain_created);
            });
    }

    public function resolveExpiresAt($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->domainRow((int)$source['__domainId'])
            ->then(static function ($domain) {
                // expiresAt is nullable, so a missing row is null here rather
                // than the error createdAt has to raise. i-MSCP also writes 0,
                // not NULL, for an account that never expires;
                // TypeResolver::dateTime() turns that into null rather than
                // 1970.
                return $domain === null
                    ? null : TypeResolver::dateTime($domain->domain_expires);
            });
    }

    public function resolveDomainSubdomains(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->ofKind(VirtualHosts::KIND_SUB, (int)$source['__domainId']);
    }

    public function resolveDomainAliases(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->ofKind(VirtualHosts::KIND_ALS, (int)$source['__domainId']);
    }

    public function resolveAliasDomain($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->reference(NodeType::DOMAIN, (int)$source['__domainId']);
    }

    public function resolveParent($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        // A Domain for a subdomain of the main domain, a DomainAlias for a
        // subdomain of an alias. Spec section 7.3 makes both a Subdomain, and
        // the parent is where the difference shows.
        return $this->reference($source['__parentTag'], $source['__parentKey']);
    }

    public function resolveAliasSubdomains(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        $vhosts = $this->vhosts;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'vhost:alssub-by-alias',
            (int)$source['__key'],
            static function (array $keys) use ($vhosts) {
                return $vhosts->aliasSubdomainsOf($keys);
            }
        )->then(static function ($rows) use ($toUnicode) {
            return self::shapeAll($rows, $toUnicode);
        });
    }

    private function ofKind(string $kind, int $domainId): SyncPromise
    {
        $vhosts = $this->vhosts;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'vhost:by-domain:' . $kind,
            $domainId,
            static function (array $keys) use ($vhosts, $kind) {
                return $vhosts->ofKind($kind, $keys);
            }
        )->then(static function ($rows) use ($toUnicode) {
            return self::shapeAll($rows, $toUnicode);
        });
    }

    /**
     * The `domain` model behind a vhost, for the two fields the normalised
     * vhost row does not carry: domain_created and domain_expires.
     *
     * The one load in this phase that goes through Anorm rather than through
     * hand-written SQL, and it is here because it is the only one that fits:
     * one table, one column, no join, no filter, no aggregate.
     * BatchLoader::byColumn() batches it exactly as keyed() would - it is
     * built on keyed() - and hands back a DomainModel, so the two resolvers
     * above read $domain->domain_created rather than $row['domain_created'].
     * That is the DX case for Task 9's models stated in code: the column names
     * are declared on the class, so an IDE completes them and a rename is a
     * rename rather than a search for a string.
     *
     * @return SyncPromise Resolves to DomainModel|null
     */
    private function domainRow(int $domainId): SyncPromise
    {
        return $this->loader
            ->byColumn(DomainModel::class, 'domain_id', $domainId)
            ->then(static function (array $models) {
                // byColumn() answers a list because a column need not be
                // unique; domain_id is the primary key, so this one is never
                // longer than one.
                return $models === array() ? null : $models[0];
            });
    }

    /**
     * @param array<int, array>|null $rows
     * @return array<int, array>
     */
    private static function shapeAll(?array $rows, callable $toUnicode): array
    {
        // A list field is non-null in the SDL, so "no rows" must be an empty
        // list. Returning null here would null the object the list hangs off.
        if ($rows === null) {
            return array();
        }

        $shaped = array();

        foreach ($rows as $row) {
            $shaped[] = self::shape($row, $toUnicode);
        }

        return $shaped;
    }
}
```

- [ ] **Step 4: Write the failing integration test**

`test/integration/VirtualHostResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class VirtualHostResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var BatchLoader */
    private $loader;

    /** @var VirtualHostResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->loader = new BatchLoader($this->db);
        $this->resolver = new VirtualHostResolver(
            $this->loader,
            new VirtualHosts($this->db),
            $this->db,
            'decode_idna',
            static function (int $adminId) {
                return new \GraphQL\Deferred(static function () use ($adminId) {
                    return array('__tag' => NodeType::CUSTOMER, '__key' => $adminId);
                });
            },
            static function (int $ipId) {
                return new \GraphQL\Deferred(static function () use ($ipId) {
                    return array('__tag' => NodeType::IP_ADDRESS, '__key' => $ipId);
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        // The fixture's identities record no scopes, and Identity::hasScope()
        // answers true for all of them when none are recorded. narrowContext()
        // below is how this file tests a narrowed token.
        return array('identity' => $this->fixture->identity('customer'));
    }

    private function narrowContext(): array
    {
        $customer = $this->fixture->identity('customer');

        return array('identity' => new Identity(
            $customer->getAdminId(), $customer->getUsername(), 'user',
            $customer->getCreatedBy(), $customer->getEmail(),
            array(\iMSCP\Plugin\SGW_GraphQL\Auth\Scope::MAIL_READ), 1
        ));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    private function domain(): array
    {
        return $this->value($this->resolver->forDomain($this->fixture->domainId()));
    }

    public function testTheMainDomainShapesFromTheDatabase(): void
    {
        $domain = $this->domain();

        self::assertSame($this->fixture->domainName(), $domain['name']);
        self::assertSame('/', $domain['mountPoint']);
        self::assertSame('/htdocs', $domain['documentRoot']);
        self::assertFalse($domain['wildcard']);
        self::assertNull($domain['forwarding']);
        self::assertSame('OK', $domain['provisioning']['state']);
    }

    public function testCreatedAtComesFromTheDomainRow(): void
    {
        $domain = $this->domain();

        self::assertSame('2026-01-01T00:00:00Z', $this->value(
            $this->resolver->resolveCreatedAt($domain, array(), $this->context(), $this->info())
        ));
    }

    public function testSubdomainsAndAliasesAreSeparateLists(): void
    {
        $domain = $this->domain();

        $subdomains = $this->value($this->resolver->resolveDomainSubdomains(
            $domain, array(), $this->context(), $this->info()
        ));
        $aliases = $this->value($this->resolver->resolveDomainAliases(
            $domain, array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $subdomains);
        self::assertSame($this->fixture->subdomainName(), $subdomains[0]['name']);
        self::assertCount(1, $aliases);
        self::assertSame($this->fixture->aliasName(), $aliases[0]['name']);
        self::assertSame('https://example.net/', $aliases[0]['forwarding']['url']);
        self::assertSame('PERMANENT_301', $aliases[0]['forwarding']['type']);
        self::assertFalse($aliases[0]['forwarding']['keepHost']);
    }

    public function testAnAliasSubdomainsParentIsTheAliasNotTheDomain(): void
    {
        // The whole point of decision D1: an alias subdomain is a Subdomain
        // whose parent is a DomainAlias. Resolving its parent to the main
        // domain would put it in the wrong place in every client's tree.
        $domain = $this->domain();
        $aliases = $this->value($this->resolver->resolveDomainAliases(
            $domain, array(), $this->context(), $this->info()
        ));
        $subdomains = $this->value($this->resolver->resolveAliasSubdomains(
            $aliases[0], array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $subdomains);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $subdomains[0]['__tag']);

        $parent = $this->value($this->resolver->resolveParent(
            $subdomains[0], array(), $this->context(), $this->info()
        ));

        self::assertSame(NodeType::DOMAIN_ALIAS, $parent['__tag']);
        self::assertSame($this->fixture->aliasName(), $parent['name']);
    }

    public function testTheSubdomainsOfThreeDistinctDomainsCostOneQuery(): void
    {
        // The N+1 this class exists to prevent, and it has to be three
        // *different* domains: six copies of one id would only prove that
        // BatchLoader de-duplicates a repeated key, which is a weaker and
        // much easier property than batching. A resolver that queried per
        // parent costs three here and cannot pass.
        $ids = array(
            $this->fixture->domainId(),
            $this->fixture->siblingDomainId(),
            $this->fixture->otherDomainId()
        );
        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(function () use ($resolver, $ids, $context, $info) {
            foreach ($ids as $id) {
                $resolver->resolveDomainSubdomains(
                    array('__domainId' => $id), array(), $context, $info
                );
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testAReferenceToFourKindsCostsFourQueriesRegardlessOfCount(): void
    {
        // One query per vhost kind, not one per identifier: the three distinct
        // domains go into a single IN clause, and asking for each of them
        // twice adds nothing on top because the loader memoises what it has
        // already answered.
        $resolver = $this->resolver;
        $fixture = $this->fixture;
        $domains = array(
            $fixture->domainId(),
            $fixture->siblingDomainId(),
            $fixture->otherDomainId()
        );

        $count = $this->db->countQueries(function () use ($resolver, $fixture, $domains) {
            for ($i = 0; $i < 2; $i++) {
                foreach ($domains as $domainId) {
                    $resolver->reference(NodeType::DOMAIN, $domainId);
                }

                $resolver->reference(NodeType::SUBDOMAIN, $fixture->subdomainId());
                $resolver->reference(NodeType::DOMAIN_ALIAS, $fixture->aliasId());
                $resolver->reference(
                    NodeType::ALIAS_SUBDOMAIN, $fixture->aliasSubdomainId()
                );
            }

            SyncPromise::runQueue();
        });

        self::assertSame(4, $count);
    }

    public function testANarrowedTokenIsRefusedRatherThanGivenAnEmptyList(): void
    {
        // The fail-closed half. A MAIL_READ-only token asking for subdomains
        // must be told no, not handed an empty list it would render as "this
        // customer has no subdomains".
        $domain = $this->domain();

        try {
            $this->resolver->resolveDomainSubdomains(
                $domain, array(), $this->narrowContext(), $this->info()
            );
            self::fail('a token without DOMAINS_READ must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }
}
```

- [ ] **Step 5: Run the tests to verify they fail, then pass**

```bash
tools/test.sh
```

Expected first: FAIL. Then, with Step 3's implementation in place: PASS,
`VirtualHostResolverTest` 11 unit methods (5 through the data provider) and 7
integration methods.

- [ ] **Step 6: Commit**

```bash
git add Resolver/VirtualHostResolver.php \
        test/unit/Resolver/VirtualHostResolverTest.php \
        test/integration/VirtualHostResolverTest.php
git commit -m "Resolve the three virtual-host types from one shaped row

Domain, Subdomain and DomainAlias differ in their tables and almost nowhere
else, so all three resolve against the same normalised row and the four-way
split stays in the repository. Everything that is a value is in the shape
under its schema field name; everything an edge needs is under an
underscore-prefixed key no schema field can collide with.

Forwarding is where i-MSCP's storage is easiest to get wrong: url_forward is
NOT NULL and holds the literal string 'no' for no forwarding, and type_forward
is nullable beside it, so a row can carry a URL and no type. Both are handled
here and asserted, because a client told that every unforwarded host redirects
to a URL called 'no' would be right to complain."
```

---

## Task 13: The customer resolvers — `sonnet`

Spec §7.5: `Customer` and the three value objects that hang off it —
`CustomerQuotas`, `Storage` and `CustomerFeatures` — plus `ContactDetails`,
which `TypeResolver::contact()` already shapes.

**Which resolver owns a `Customer.*` field.** `Customer` is the type with the
most edges in the schema, and they are owned by whichever class shapes the
*other* end, not by this one:

| Field | Owner |
| --- | --- |
| `reseller`, `domain`, `quotas`, `storage`, `features`, `apiAccess`, `subdomains`, `domainAliases` | this task |
| `mailAccounts` | Task 14, `MailResolver` |
| `dnsRecords` | Task 14, `DnsResolver` |
| `ftpUsers`, `sqlDatabases`, `sqlUsers` | Task 15, `FtpSqlResolver` |

Task 16 asserts that no two resolver maps share a key, so the split above is
checked rather than remembered.

**One bucket serves `domain`, `subdomains` and `domainAliases`.**
`VirtualHosts::forDomains()` returns all four vhost kinds for a set of domain
ids in one pass, so the three fields share the loader bucket
`vhost:all-by-domain` and a document selecting all three costs the same as one
selecting any one of them. This is also why `Customer.domain` does not go
through `VirtualHostResolver::forDomain()`: that would be a second bucket
loading rows this one already has.

**`Customer.subdomains` includes alias subdomains.** Spec §7.5's comment says
so — *"Subdomains of the main domain and of every alias alike"* — and decision
D1 is what makes it possible to put both in one list without their identifiers
colliding.

**The joined row is an INNER JOIN, deliberately.** A customer with no `domain`
row cannot answer `domain: Domain!`, `quotas`, `storage` or `features`, all of
which are non-null, so such a customer would serialise as a null `Customer`
inside a non-null list and null the whole query. i-MSCP creates the `admin` and
`domain` rows together, so the row cannot go missing in sound data; joining
inwardly means an unsound row is absent from a list rather than poisoning it.

**Files:**
- Create: `Resolver/CustomerResolver.php`
- Create: `test/unit/Resolver/CustomerResolverTest.php`
- Create: `test/integration/CustomerResolverTest.php`

**Interfaces:**
- Consumes:
  - `new BatchLoader(Db $db)`, `BatchLoader::keyed(string $bucket, $key, callable $loader): SyncPromise`,
    `BatchLoader::flushes(): int` (Task 10)
  - `Db::rows()`, `Db::placeholders()`, `Db::detached()`, `Db::countQueries()` (Tasks 2, 10)
  - `new Counts(Db $db, bool $countDefaultMailAccounts)`, `Counts::subdomains(array $domainIds): array`,
    `::domainAliases()`, `::mailAccounts()`, `::sqlDatabases()`, `::sqlUsers()`
    (all `domainId => int`), `::ftpUsers(array $adminIds): array` (`adminId => int`) (Task 5)
  - `new VirtualHosts(Db $db)`, `VirtualHosts::forDomains(array $domainIds): array`,
    `VirtualHosts::KIND_DMN|KIND_SUB|KIND_ALS|KIND_ALSSUB`, and the normalised
    row keys (Task 9)
  - `VirtualHostResolver::shape(array $vhostRow, callable $toUnicode): array` — **static** (Task 12)
  - `Quota::fromCustomerLimit(int $limit, int $used): Quota`, `Quota::toArray(): array` (Task 3)
  - `CustomerFeatures::fromDomainRow(array $domain, array $config, bool $resellerSupportSystem): CustomerFeatures`,
    `CustomerFeatures::toArray(): array`, `CustomerFeatures::CONFIG_KEYS` (Task 4)
  - `TypeResolver::node()`, `::provisioning()`, `::dateTime()`, `::bigInt()`,
    `::contact()`, `::requireScope()`, `::TAG` (Task 11)
  - `NodeType::CUSTOMER` (Task 1), `Scope::ACCOUNT_READ`, `Scope::DOMAINS_READ` (plan 1)
  - `callable $resellerRef` — **`fn(int $resellerAdminId): SyncPromise`** resolving
    to a shaped `Reseller` array. Supplied by `Container` from
    `ResellerResolver::reference()` (Task 15).
  - `callable $monthBounds` — **`fn(): array`** returning
    `array(int $firstTimestamp, int $lastTimestamp)` for the current calendar
    month. Injected because the core's `getFirstDayOfMonth()` /
    `getLastDayOfMonth()` build `Zend_Date` objects and need a booted panel.
  - `callable $toUnicode` — `fn(string $value): string`, the panel's `decode_idna()`.
- Produces:
  - `new CustomerResolver(Db $db, BatchLoader $loader, Counts $counts, VirtualHosts $vhosts, array $config, bool $apiAccessByDefault, callable $monthBounds, callable $toUnicode, callable $resellerRef)`
  - `CustomerResolver::map(): array` — the eight `Customer.*` entries above
  - `CustomerResolver::SELECT` — the joined `admin`/`domain` select list, so
    that Task 16's `Query.customers` filters over the same columns this shapes
  - `CustomerResolver::shape(array $joinedRow, callable $toUnicode): array` —
    **static**. Keys: `id`, `username`, `reference`, `contact`, `createdAt`,
    `expiresAt`, `provisioning`, plus the private `__tag`, `__key`,
    `__domainId`, `__resellerId`, `__row`.
  - `CustomerResolver::reference(int $adminId): SyncPromise` — resolves to a
    shaped `Customer` array, or null. One query per level whatever the number
    of identifiers. Tasks 12, 15 and 16 use it.
  - `CustomerResolver::references(array $adminIds): SyncPromise` — resolves to a
    list of shaped `Customer` arrays in the order asked for, skipping any the
    join did not return. Task 15's `Reseller.customers` and Task 16's
    `Query.customers` use it.

- [ ] **Step 1: Write the failing unit test**

`test/unit/Resolver/CustomerResolverTest.php`. The shaping is pure; everything
that reaches the database is tested against the fixture in Step 4.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class CustomerResolverTest extends TestCase
{
    /**
     * A joined admin/domain row exactly as CustomerResolver::SELECT produces
     * one.
     */
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'admin_id'        => 7,
            'admin_name'      => 'customer',
            'admin_type'      => 'user',
            'created_by'      => 5,
            'customer_id'     => 'REF-7',
            'admin_status'    => 'ok',
            'domain_created'  => 1767225600,
            'fname'           => 'Ada',
            'lname'           => 'Lovelace',
            'gender'          => 'F',
            'firm'            => 'Analytical Ltd',
            'street1'         => '1 Test Street',
            'street2'         => null,
            'city'            => 'Testville',
            'state'           => 'Testshire',
            'zip'             => '1234',
            'country'         => 'NZ',
            'email'           => 'ada@xn--bcher-kva.test',
            'phone'           => '+64 3 000 0000',
            'fax'             => null,
            'domain_id'       => 12,
            'domain_expires'  => 0,
            'domain_status'   => 'ok'
        ), $overrides);
    }

    private function toUnicode(): callable
    {
        return static function (string $value) {
            return $value === 'ada@xn--bcher-kva.test' ? 'ada@bücher.test' : $value;
        };
    }

    public function testTheIdentifierIsTheAdminIdNotTheDomainId(): void
    {
        // admin_id and domain_id are different id spaces and both are in the
        // row. Encoding the wrong one would hand every client an identifier
        // that addresses a different customer.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(GlobalId::encode(NodeType::CUSTOMER, 7), $shape['id']);
        self::assertSame(7, $shape['__key']);
        self::assertSame(NodeType::CUSTOMER, $shape[TypeResolver::TAG]);
        self::assertSame(12, $shape['__domainId']);
    }

    public function testTheResellerIsCreatedByNotTheAdministrator(): void
    {
        // admin.created_by is the ownership chain of spec section 2.2. Reading
        // any other column here would put the customer under the wrong
        // reseller, which is an authorisation bug wearing a display bug's
        // clothes.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(5, $shape['__resellerId']);
    }

    public function testCreatedAtComesFromTheAdminRowNotTheDomainRow(): void
    {
        // Both tables have a domain_created column. The customer's account
        // creation is admin.domain_created; SELECT lists only that one, and
        // this asserts the shape reads it.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('2026-01-01T00:00:00Z', $shape['createdAt']);
    }

    public function testAnAccountThatNeverExpiresHasNoExpiryDate(): void
    {
        // i-MSCP writes 0, not NULL. Rendering that as 1970-01-01 would tell
        // every client the account expired fifty-six years ago.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertNull($shape['expiresAt']);
        self::assertSame('2027-01-01T00:00:00Z', CustomerResolver::shape(
            $this->row(array('domain_expires' => 1798761600)), $this->toUnicode()
        )['expiresAt']);
    }

    public function testTheResellersOwnReferenceIsCarriedThrough(): void
    {
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('REF-7', $shape['reference']);
        self::assertNull(CustomerResolver::shape(
            $this->row(array('customer_id' => '')), $this->toUnicode()
        )['reference']);
    }

    public function testContactDetailsComeBackDecoded(): void
    {
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('Ada', $shape['contact']['firstName']);
        self::assertSame('FEMALE', $shape['contact']['gender']);
        self::assertSame('ada@bücher.test', $shape['contact']['email']);
    }

    public function testProvisioningComesFromTheAdminStatusNotTheDomainStatus(): void
    {
        // A Customer is the account. Its domain has its own Provisioning, on
        // the Domain type; conflating the two would report a settled account
        // as pending every time one of its vhosts was being rebuilt.
        $shape = CustomerResolver::shape($this->row(array(
            'admin_status' => 'toadd', 'domain_status' => 'ok'
        )), $this->toUnicode());

        self::assertSame('PENDING', $shape['provisioning']['state']);
        self::assertSame('toadd', $shape['provisioning']['raw']);
    }

    public function testTheWholeRowIsCarriedForTheFieldsThatNeedIt(): void
    {
        // quotas, storage and features all read domain columns. Carrying the
        // row is what keeps them from costing a query each.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(12, $shape['__row']['domain_id']);
    }

    public function testTheMapOwnsExactlyTheCustomerFieldsThisTaskShapes(): void
    {
        // The failure this catches, in both directions: a field added to the
        // SDL and not to a map returns null for a non-null field and nulls the
        // whole Customer; a field claimed here as well as in Task 14 or 15
        // means one of the two silently never runs.
        $resolver = new CustomerResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            new Counts(Db::detached(), true),
            new VirtualHosts(Db::detached()),
            array(),
            true,
            static function () { return array(0, 0); },
            $this->toUnicode(),
            static function (int $resellerId) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        self::assertSame(array(
            'Customer.apiAccess', 'Customer.domain', 'Customer.domainAliases',
            'Customer.features', 'Customer.quotas', 'Customer.reseller',
            'Customer.storage', 'Customer.subdomains'
        ), $keys);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver` not found.

- [ ] **Step 3: Write `Resolver/CustomerResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Support\CustomerFeatures;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;

/**
 * Customer, and the three value objects spec section 7.5 hangs off it.
 *
 * The Customer type has the most edges in the schema, and this class owns only
 * the ones whose other end it shapes. Mail belongs to MailResolver, DNS to
 * DnsResolver, FTP and SQL to FtpSqlResolver; Task 16's container test asserts
 * that no two maps claim the same key.
 */
final class CustomerResolver
{
    /**
     * The joined admin/domain select list.
     *
     * Both tables carry a domain_created column - the account's creation date
     * in `admin`, the domain's in `domain` - so `a.*, d.*` would silently
     * resolve one of them to the other depending on driver order. Every column
     * is therefore named, and only admin's domain_created is taken.
     *
     * The join is inward on purpose. A customer with no domain row cannot
     * answer domain, quotas, storage or features, every one of which is
     * non-null, so it would serialise as a null Customer inside a non-null
     * list and null the entire query. i-MSCP writes both rows together, so in
     * sound data this excludes nothing.
     */
    const SELECT = '
        SELECT
            a.admin_id, a.admin_name, a.admin_type, a.created_by, a.customer_id,
            a.admin_status, a.domain_created, a.fname, a.lname, a.gender,
            a.firm, a.street1, a.street2, a.city, a.state, a.zip, a.country,
            a.email, a.phone, a.fax,
            d.domain_id, d.domain_expires, d.domain_status,
            d.domain_subd_limit, d.domain_alias_limit, d.domain_mailacc_limit,
            d.domain_ftpacc_limit, d.domain_sqld_limit, d.domain_sqlu_limit,
            d.domain_disk_limit, d.domain_disk_usage, d.domain_disk_file,
            d.domain_disk_mail, d.domain_disk_sql, d.domain_traffic_limit,
            d.mail_quota,
            d.domain_php, d.domain_cgi, d.domain_dns, d.domain_external_mail,
            d.allowbackup, d.phpini_perm_system, d.phpini_perm_allow_url_fopen,
            d.phpini_perm_display_errors, d.phpini_perm_disable_functions
        FROM admin AS a
        JOIN domain AS d ON d.domain_admin_id = a.admin_id
    ';

    /** MiB to bytes. Decision D3: every BigInt in this schema is bytes. */
    const MIB = 1048576;

    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var Counts */
    private $counts;

    /** @var VirtualHosts */
    private $vhosts;

    /** @var array The panel configuration, at least CustomerFeatures::CONFIG_KEYS. */
    private $config;

    /** @var bool The plugin's allowed_by_default, for accounts with no api_perm row. */
    private $apiAccessByDefault;

    /** @var callable fn(): array{0: int, 1: int} */
    private $monthBounds;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(int $resellerAdminId): SyncPromise */
    private $resellerRef;

    public function __construct(
        Db $db, BatchLoader $loader, Counts $counts, VirtualHosts $vhosts,
        array $config, bool $apiAccessByDefault, callable $monthBounds,
        callable $toUnicode, callable $resellerRef
    ) {
        $this->db = $db;
        $this->loader = $loader;
        $this->counts = $counts;
        $this->vhosts = $vhosts;
        $this->config = $config;
        $this->apiAccessByDefault = $apiAccessByDefault;
        $this->monthBounds = $monthBounds;
        $this->toUnicode = $toUnicode;
        $this->resellerRef = $resellerRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Customer.reseller'      => array($this, 'resolveReseller'),
            'Customer.domain'        => array($this, 'resolveDomain'),
            'Customer.subdomains'    => array($this, 'resolveSubdomains'),
            'Customer.domainAliases' => array($this, 'resolveDomainAliases'),
            'Customer.quotas'        => array($this, 'resolveQuotas'),
            'Customer.storage'       => array($this, 'resolveStorage'),
            'Customer.features'      => array($this, 'resolveFeatures'),
            'Customer.apiAccess'     => array($this, 'resolveApiAccess')
        );
    }

    /**
     * One joined row becomes the array the Customer type resolves against.
     *
     * @param array<string, mixed> $row A row of self::SELECT
     * @param callable $toUnicode fn(string): string
     * @return array<string, mixed>
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        $reference = isset($row['customer_id']) ? (string)$row['customer_id'] : '';

        return TypeResolver::node(NodeType::CUSTOMER, (int)$row['admin_id'], array(
            'username'     => (string)$row['admin_name'],
            'reference'    => $reference === '' ? null : $reference,
            'contact'      => TypeResolver::contact($row, $toUnicode),
            // admin.domain_created, not domain.domain_created: this is when
            // the account was created.
            'createdAt'    => TypeResolver::dateTime($row['domain_created']),
            'expiresAt'    => TypeResolver::dateTime($row['domain_expires']),
            // The account's own status. The domain has its own Provisioning,
            // on the Domain type.
            'provisioning' => TypeResolver::provisioning(
                $row['admin_status'] === null ? null : (string)$row['admin_status']
            ),
            '__domainId'   => (int)$row['domain_id'],
            '__resellerId' => $row['created_by'] === null
                ? null : (int)$row['created_by'],
            // quotas, storage and features all read domain columns. Carrying
            // the row is what keeps each of them from costing a query.
            '__row'        => $row
        ));
    }

    /**
     * One shaped Customer, batched.
     */
    public function reference(int $adminId): SyncPromise
    {
        $toUnicode = $this->toUnicode;

        return $this->row($adminId)->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    /**
     * Several shaped Customers, in the order asked for.
     *
     * Every identifier joins the same buffer, so a list of two hundred
     * customers is one query. An identifier the join did not return is
     * dropped rather than becoming a null inside a non-null list.
     *
     * It gathers the raw row promises rather than reference()'s shaped ones,
     * and the reason is queue order. BatchLoader::keyed() enqueues a Deferred
     * immediately, so the Deferred created below sits behind every one of
     * them and finds each result already set. A ->then() child is enqueued
     * only once its parent resolves, which is after that - so gathering
     * reference()'s results here would read a queue of nulls.
     *
     * @param int[] $adminIds
     */
    public function references(array $adminIds): SyncPromise
    {
        $rows = array();

        foreach ($adminIds as $adminId) {
            $rows[] = $this->row((int)$adminId);
        }

        $toUnicode = $this->toUnicode;

        return new Deferred(static function () use ($rows, $toUnicode) {
            $customers = array();

            foreach ($rows as $row) {
                if ($row->result !== null) {
                    $customers[] = self::shape($row->result, $toUnicode);
                }
            }

            return $customers;
        });
    }

    /**
     * The joined row behind one customer, unshaped and batched.
     */
    private function row(int $adminId): Deferred
    {
        $db = $this->db;

        return $this->loader->keyed(
            'customer:row',
            $adminId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    self::SELECT . ' WHERE a.admin_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = $row;
                }

                return $byId;
            }
        );
    }

    public function resolveReseller($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return call_user_func($this->resellerRef, (int)$source['__resellerId']);
    }

    public function resolveDomain($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        $toUnicode = $this->toUnicode;

        return $this->vhostRows((int)$source['__domainId'])
            ->then(static function ($rows) use ($toUnicode) {
                foreach (($rows === null ? array() : $rows) as $row) {
                    if ($row['kind'] === VirtualHosts::KIND_DMN) {
                        return VirtualHostResolver::shape($row, $toUnicode);
                    }
                }

                return null;
            });
    }

    public function resolveSubdomains($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        // Spec section 7.5: "Subdomains of the main domain and of every alias
        // alike". Decision D1 is what lets both sit in one list without their
        // identifiers colliding.
        return $this->vhostsOfKinds(
            (int)$source['__domainId'],
            array(VirtualHosts::KIND_SUB, VirtualHosts::KIND_ALSSUB)
        );
    }

    public function resolveDomainAliases($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->vhostsOfKinds(
            (int)$source['__domainId'], array(VirtualHosts::KIND_ALS)
        );
    }

    public function resolveQuotas($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $row = $source['__row'];
        $domainId = (int)$source['__domainId'];
        $adminId = (int)$source['__key'];
        $counts = $this->counts;

        // Six buckets, six queries per level however many customers asked -
        // decision D5. Counting.php's own functions take one customer at a
        // time, which is six queries per row of a reseller's customer list.
        $subdomains = $this->count('counts:subdomains', $domainId, static function (array $ids) use ($counts) {
            return $counts->subdomains($ids);
        });
        $aliases = $this->count('counts:aliases', $domainId, static function (array $ids) use ($counts) {
            return $counts->domainAliases($ids);
        });
        $mail = $this->count('counts:mail', $domainId, static function (array $ids) use ($counts) {
            return $counts->mailAccounts($ids);
        });
        $databases = $this->count('counts:sqldb', $domainId, static function (array $ids) use ($counts) {
            return $counts->sqlDatabases($ids);
        });
        $sqlUsers = $this->count('counts:sqlusers', $domainId, static function (array $ids) use ($counts) {
            return $counts->sqlUsers($ids);
        });
        // Keyed by admin_id: ftp_users has no domain_id column.
        $ftpUsers = $this->count('counts:ftp', $adminId, static function (array $ids) use ($counts) {
            return $counts->ftpUsers($ids);
        });

        return new Deferred(static function () use (
            $row, $subdomains, $aliases, $mail, $ftpUsers, $databases, $sqlUsers
        ) {
            return array(
                'subdomains'    => self::quota($row['domain_subd_limit'], $subdomains->result),
                'domainAliases' => self::quota($row['domain_alias_limit'], $aliases->result),
                'mailAccounts'  => self::quota($row['domain_mailacc_limit'], $mail->result),
                'ftpUsers'      => self::quota($row['domain_ftpacc_limit'], $ftpUsers->result),
                'sqlDatabases'  => self::quota($row['domain_sqld_limit'], $databases->result),
                'sqlUsers'      => self::quota($row['domain_sqlu_limit'], $sqlUsers->result)
            );
        });
    }

    public function resolveStorage($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $row = $source['__row'];

        return $this->traffic((int)$source['__domainId'])
            ->then(static function ($used) use ($row) {
                return array(
                    // Decision D3. domain_disk_limit and domain_traffic_limit
                    // are the only two figures i-MSCP keeps in MiB; every
                    // usage column beside them is bytes. A client comparing
                    // diskUsed to diskLimit must not be out by a factor of a
                    // million.
                    'diskLimit'    => self::mibToBytes($row['domain_disk_limit']),
                    'diskUsed'     => TypeResolver::bigInt($row['domain_disk_usage']),
                    'diskFiles'    => TypeResolver::bigInt($row['domain_disk_file']),
                    'diskMail'     => TypeResolver::bigInt($row['domain_disk_mail']),
                    'diskSql'      => TypeResolver::bigInt($row['domain_disk_sql']),
                    'trafficLimit' => self::mibToBytes($row['domain_traffic_limit']),
                    'trafficUsed'  => TypeResolver::bigInt($used === null ? 0 : $used),
                    'mailQuota'    => (int)$row['mail_quota'] === 0
                        ? null : TypeResolver::bigInt($row['mail_quota'])
                );
            });
    }

    public function resolveFeatures($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $row = $source['__row'];
        $config = $this->config;

        // support_system is the reseller's, not the customer's: a reseller who
        // does not offer the ticket system withholds it from every customer.
        return $this->resellerSupport((int)$source['__resellerId'])
            ->then(static function ($supportSystem) use ($row, $config) {
                return CustomerFeatures::fromDomainRow(
                    $row, $config, $supportSystem === 'yes'
                )->toArray();
            });
    }

    public function resolveApiAccess($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $db = $this->db;
        $byDefault = $this->apiAccessByDefault;

        // SGW_GraphQL::customerHasApiAccess() answers for one account and
        // memoises in a static, which is right for the one account a request
        // authenticates as and wrong for a reseller reading fifty. The rule it
        // encodes is reproduced here: an api_perm row wins, and its absence
        // means the plugin's allowed_by_default.
        return $this->loader->keyed(
            'customer:api-perm',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT admin_id, allowed FROM api_perm WHERE admin_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = (bool)$row['allowed'];
                }

                return $byId;
            }
        )->then(static function ($allowed) use ($byDefault) {
            return $allowed === null ? $byDefault : $allowed;
        });
    }

    /**
     * All four vhost kinds of one domain, in one bucket.
     *
     * domain, subdomains and domainAliases share it, so a document selecting
     * all three costs what a document selecting one of them costs.
     */
    private function vhostRows(int $domainId): SyncPromise
    {
        $vhosts = $this->vhosts;

        return $this->loader->keyed(
            'vhost:all-by-domain',
            $domainId,
            static function (array $keys) use ($vhosts) {
                return $vhosts->forDomains($keys);
            }
        );
    }

    /**
     * @param string[] $kinds
     */
    private function vhostsOfKinds(int $domainId, array $kinds): SyncPromise
    {
        $toUnicode = $this->toUnicode;

        return $this->vhostRows($domainId)
            ->then(static function ($rows) use ($kinds, $toUnicode) {
                $shaped = array();

                // A list field is non-null in the SDL, so "no rows" is an
                // empty list; returning null would null the Customer.
                foreach (($rows === null ? array() : $rows) as $row) {
                    if (in_array($row['kind'], $kinds, true)) {
                        $shaped[] = VirtualHostResolver::shape($row, $toUnicode);
                    }
                }

                return $shaped;
            });
    }

    /**
     * @param int|string $key
     */
    private function count(string $bucket, $key, callable $counter): SyncPromise
    {
        return $this->loader->keyed($bucket, $key, $counter);
    }

    /**
     * This calendar month's web, FTP, mail and POP traffic added together.
     *
     * domain_traffic holds one row per accounting interval, so the figure is a
     * sum rather than a column. The month bounds come from the panel, because
     * the core's own getFirstDayOfMonth()/getLastDayOfMonth() build Zend_Date
     * objects and the API must agree with the panel about where a month ends.
     */
    private function traffic(int $domainId): SyncPromise
    {
        $db = $this->db;
        $bounds = call_user_func($this->monthBounds);

        return $this->loader->keyed(
            'domain:traffic',
            $domainId,
            static function (array $keys) use ($db, $bounds) {
                $rows = $db->rows(
                    '
                        SELECT domain_id AS k,
                            IFNULL(SUM(dtraff_web + dtraff_ftp + dtraff_mail
                                + dtraff_pop), 0) AS n
                        FROM domain_traffic
                        WHERE domain_id IN (' . $db->placeholders(count($keys)) . ')
                            AND dtraff_time BETWEEN ? AND ?
                        GROUP BY domain_id
                    ',
                    array_merge($keys, array($bounds[0], $bounds[1]))
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['k']] = (int)$row['n'];
                }

                return $byId;
            }
        );
    }

    private function resellerSupport(int $resellerId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:support-system',
            $resellerId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT reseller_id, support_system FROM reseller_props'
                        . ' WHERE reseller_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['reseller_id']] = (string)$row['support_system'];
                }

                return $byId;
            }
        );
    }

    /**
     * @param mixed $limit
     * @param mixed $used
     * @return array<string, mixed>
     */
    private static function quota($limit, $used): array
    {
        return Quota::fromCustomerLimit((int)$limit, (int)$used)->toArray();
    }

    /**
     * @param mixed $mib
     */
    private static function mibToBytes($mib): ?string
    {
        // 0 is unlimited, and -1 - which domain_traffic_limit never carries
        // but domain_disk_limit can on a withheld account - is not a size at
        // all. Both are "no ceiling to report".
        return (int)$mib <= 0 ? null : TypeResolver::bigInt((int)$mib * self::MIB);
    }
}
```

- [ ] **Step 4: Write the failing integration test**

`test/integration/CustomerResolverTest.php`. Every figure asserted here is one
Task 8's fixture writes, so a wrong column shows up as a wrong number rather
than as an empty result.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class CustomerResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var CustomerResolver */
    private $resolver;

    /**
     * A fixed panel configuration, not the box's. Every feature this asserts
     * is then a statement about the customer's own row rather than about
     * whatever the reference box happens to be configured with today.
     */
    private function config(): array
    {
        return array(
            'NAMED_PACKAGE'          => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES' => 'Awstats',
            'BACKUP_DOMAINS'         => 'yes',
            'ENABLE_SSL'             => 1,
            'IMSCP_SUPPORT_SYSTEM'   => 1
        );
    }

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new CustomerResolver(
            $this->db,
            new BatchLoader($this->db),
            new Counts($this->db, true),
            new VirtualHosts($this->db),
            $this->config(),
            true,
            static function () {
                // A window wide enough to hold anything the fixture writes,
                // and narrow enough that it is still a window.
                return array(0, 4102444800);
            },
            'decode_idna',
            static function (int $resellerId) {
                return new \GraphQL\Deferred(static function () use ($resellerId) {
                    return array(
                        '__tag' => NodeType::RESELLER, '__key' => $resellerId
                    );
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        return array('identity' => $this->fixture->identity('customer'));
    }

    private function narrowContext(): array
    {
        $customer = $this->fixture->identity('customer');

        return array('identity' => new Identity(
            $customer->getAdminId(), $customer->getUsername(), 'user',
            $customer->getCreatedBy(), $customer->getEmail(),
            array(Scope::MAIL_READ), 1
        ));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    private function customer(): array
    {
        return $this->value($this->resolver->reference($this->fixture->customerId()));
    }

    public function testTheCustomerShapesFromTheJoinedRow(): void
    {
        $customer = $this->customer();

        self::assertSame('sgwtcustomer', $customer['username']);
        self::assertSame('REF-sgwtcustomer', $customer['reference']);
        self::assertSame('OK', $customer['provisioning']['state']);
        self::assertSame($this->fixture->domainId(), $customer['__domainId']);
        self::assertSame($this->fixture->resellerId(), $customer['__resellerId']);
    }

    public function testAnAccountThatIsNotACustomerIsNotReturned(): void
    {
        // The reseller has no domain row, so the inward join drops it. A
        // reference() that returned a half-populated Customer here would null
        // whatever list it appeared in.
        self::assertNull($this->value(
            $this->resolver->reference($this->fixture->resellerId())
        ));
    }

    public function testQuotasNormaliseAllThreeOfImscpsLimitEncodings(): void
    {
        // The fixture is written so that all three appear at once: 10 is a
        // limit, 0 is unlimited, -1 is withheld. A client that read them raw
        // would offer a feature the customer does not have.
        $quotas = $this->value($this->resolver->resolveQuotas(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        // shop, plus the alias subdomain blog: Counts::subdomains sums both
        // tables, and so must this.
        self::assertSame(
            array('enabled' => true, 'limit' => 10, 'used' => 2, 'remaining' => 8),
            $quotas['subdomains']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 5, 'used' => 1, 'remaining' => 4),
            $quotas['domainAliases']
        );
        // domain_mailacc_limit is 0: unlimited, not "none allowed".
        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 2, 'remaining' => null),
            $quotas['mailAccounts']
        );
        // domain_ftpacc_limit is -1: withheld. The one FTP user the fixture
        // writes is still counted, because it exists.
        self::assertSame(
            array('enabled' => false, 'limit' => 0, 'used' => 1, 'remaining' => 0),
            $quotas['ftpUsers']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 2, 'used' => 1, 'remaining' => 1),
            $quotas['sqlDatabases']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 2, 'used' => 1, 'remaining' => 1),
            $quotas['sqlUsers']
        );
    }

    public function testStorageIsBytesEverywhereIncludingTheLimits(): void
    {
        // Decision D3, and the reason it exists: the fixture's disk limit is
        // 5120 MiB and its disk usage 1048576 bytes. Passing the limit through
        // unconverted would tell a client the customer had used a fifth of
        // their allowance when they have used two ten-thousandths of it.
        $storage = $this->value($this->resolver->resolveStorage(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame('5368709120', $storage['diskLimit']);
        self::assertSame('1048576', $storage['diskUsed']);
        self::assertSame('524288', $storage['diskFiles']);
        self::assertSame('262144', $storage['diskMail']);
        self::assertSame('262144', $storage['diskSql']);
        self::assertSame('10737418240', $storage['trafficLimit']);
        // The fixture writes no domain_traffic rows, and a customer with no
        // traffic has used none - not null, which is not what the SDL allows.
        self::assertSame('0', $storage['trafficUsed']);
        self::assertSame('1073741824', $storage['mailQuota']);
    }

    public function testFeaturesReadTheCustomersRowAndTheResellersSupportSetting(): void
    {
        $features = $this->value($this->resolver->resolveFeatures(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertTrue($features['php']);
        self::assertTrue($features['phpEditor']);
        self::assertTrue($features['cgi']);
        self::assertTrue($features['customDns']);
        self::assertTrue($features['externalMail']);
        self::assertTrue($features['backup']);
        self::assertTrue($features['ssl']);
        self::assertTrue($features['webStats']);
        // reseller_props.support_system is 'yes' in the fixture, and
        // IMSCP_SUPPORT_SYSTEM is on in config() above.
        self::assertTrue($features['supportSystem']);
    }

    public function testApiAccessFallsBackToThePluginsDefaultWithNoPermRow(): void
    {
        // api_perm is empty in production, so this is the branch every real
        // request takes.
        self::assertTrue($this->value($this->resolver->resolveApiAccess(
            $this->customer(), array(), $this->context(), $this->info()
        )));
    }

    public function testAnApiPermRowWinsOverTheDefault(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO api_perm (admin_id, allowed) VALUES (?, 0)'
        )->execute(array($this->fixture->customerId()));

        self::assertFalse($this->value($this->resolver->resolveApiAccess(
            $this->customer(), array(), $this->context(), $this->info()
        )));
    }

    public function testTheMainDomainSubdomainsAndAliasesComeFromOneQuery(): void
    {
        // The bucket this class shares between three fields. Two queries would
        // mean the bucket was not shared; seven would mean it was per row.
        $customer = $this->customer();
        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();
        $results = array();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info, &$results) {
                $results['domain'] = $resolver->resolveDomain($customer, array(), $context, $info);
                $results['subs'] = $resolver->resolveSubdomains($customer, array(), $context, $info);
                $results['aliases'] = $resolver->resolveDomainAliases($customer, array(), $context, $info);

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
        self::assertSame($this->fixture->domainName(), $results['domain']->result['name']);
        // shop on the main domain and blog on the alias, in one list.
        self::assertCount(2, $results['subs']->result);
        self::assertCount(1, $results['aliases']->result);

        $tags = array(
            $results['subs']->result[0]['__tag'], $results['subs']->result[1]['__tag']
        );
        sort($tags);
        self::assertSame(
            array(NodeType::ALIAS_SUBDOMAIN, NodeType::SUBDOMAIN), $tags
        );
    }

    public function testSixCustomersQuotasCostSixQueriesNotThirtySix(): void
    {
        // Decision D5. Six counting rules, one query each, whatever the number
        // of customers - the shape Counting.php cannot produce and the panel's
        // own reseller statistics page pays for.
        $customer = $this->customer();
        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveQuotas($customer, array(), $context, $info);
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(6, $count);
    }

    public function testANarrowedTokenIsRefusedRatherThanGivenAnEmptyAccount(): void
    {
        // A MAIL_READ-only token asking for storage must be told no. Zeroes
        // would read as "this customer uses nothing", which is a worse answer
        // than an error.
        try {
            $this->resolver->resolveStorage(
                $this->customer(), array(), $this->narrowContext(), $this->info()
            );
            self::fail('a token without ACCOUNT_READ must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }
}
```

- [ ] **Step 5: Run the tests to verify they fail, then pass**

```bash
tools/test.sh
```

Expected first: FAIL. Then, with Step 3's implementation in place: PASS,
`CustomerResolverTest` 9 unit methods and 10 integration methods.

- [ ] **Step 6: Commit**

```bash
git add Resolver/CustomerResolver.php \
        test/unit/Resolver/CustomerResolverTest.php \
        test/integration/CustomerResolverTest.php
git commit -m "Resolve a customer, its allowances and its usage

Customer is the type the rest of the graph hangs off, and almost everything it
answers is a normalisation rather than a column: three encodings of a limit
collapse into one Quota, two units of storage collapse into bytes, and the
panel's own feature expression is evaluated against this customer's row rather
than the session's.

Its edges are owned by whichever class shapes the other end - mail by Task
14's resolver, FTP and SQL by Task 15's - so that each type is shaped in one
place. The three edges this class does own share a single loader bucket,
because VirtualHosts returns all four vhost kinds at once and asking for the
domain, the subdomains and the aliases should not cost three passes over the
same rows.

The counting is batched for the reason decision D5 gives: Counting.php answers
for one customer at a time, so a reseller's customer list would be six queries
per row. Here it is six queries per level, and a test asserts it."
```

---

## Task 14: The mail and DNS resolvers — `sonnet`

Spec §7.6 and §7.8: `MailAccount`, `Autoresponder`, `DnsRecord` and
`IpAddress`. Two classes, because mail and DNS share nothing but the shape of
their edges, and one shared piece of vocabulary — the `ProvisioningState`
filter — which both this task and Task 16 need and which therefore lands here
as its own class rather than twice.

**Decision D6 — a connection's page is applied in PHP, not in SQL.**
`Customer.mailAccounts` and `Customer.ftpUsers` are `PageInput`-paged
connections carrying a `totalCount`, and they hang off `Customer` — so a
document listing a reseller's customers asks for them once per customer.
Applying `LIMIT` per parent inside one batched query needs a window function
that MariaDB 10.1 (the reference box) does not have, and issuing one query per
parent is the N+1 spec §10.1 forbids. So the batched query fetches every
matching row for every parent in the batch with no `LIMIT`, and the page is
sliced per parent afterwards. `totalCount` then costs nothing extra — it is the
length of the parent's slice before slicing — and both fields come out of one
query.

What this trades is memory for query count, and i-MSCP's own limits bound the
trade: a customer's mail accounts are capped by `domain_mailacc_limit`, and a
customer list is capped at 200 by `TypeResolver::page()`. The alternative —
correct `LIMIT` per parent — would cost one query per customer, which is the
defect this plan exists to prevent. Recorded in the [Self-review](#self-review)
alongside the five decisions the plan opened with.

**`MailAccount.quotaUsed` is not in the database.** i-MSCP reads it from
`MTA_VIRTUAL_MAIL_DIR/<domain>/<user>/maildirsize`, which the mail server
maintains and the panel parses in `gui/include/Client.php:363`
(`parseMaildirsize()`); the directory comes from
`new FileConfig(CONF_DIR . '/postfix/postfix.data')['MTA_VIRTUAL_MAIL_DIR']`
(`gui/public/client/mail_accounts.php:218,251`). None of that is reachable from
a unit test and all of it is filesystem work, so it is injected as
`callable $mailboxUsage` and `Container` supplies the core-backed closure in
Task 16.

**Files:**
- Create: `Support/ProvisioningFilter.php`
- Create: `Resolver/MailResolver.php`
- Create: `Resolver/DnsResolver.php`
- Create: `test/unit/Support/ProvisioningFilterTest.php`
- Create: `test/unit/Resolver/MailResolverTest.php`
- Create: `test/integration/MailResolverTest.php`
- Create: `test/integration/DnsResolverTest.php`

**Interfaces:**
- Consumes:
  - `Db::rows()`, `Db::placeholders()`, `Db::detached()`, `Db::countQueries()` (Tasks 2, 10)
  - `new BatchLoader(Db $db)`, `BatchLoader::keyed(string $bucket, $key, callable $loader): SyncPromise` (Task 10)
  - `MailType::kindOf(string $mailType): string`, `MailType::hostTypeOf(string $mailType): string`,
    `MailType::HOST_DMN|HOST_SUB|HOST_ALS|HOST_ALSSUB`, `MailType::all(): array`,
    `MailType::KIND_MAILBOX`, `KIND_FORWARD`, `KIND_MAILBOX_AND_FORWARD`, `KIND_CATCHALL` (Task 6)
  - `TypeResolver::node()`, `::provisioning()`, `::bigInt()`, `::requireScope()`,
    `::page()`, `::TAG` (Task 11)
  - `Provisioning::PENDING_STATUSES`, `Provisioning::STATE_OK|STATE_PENDING|STATE_DISABLED|STATE_ORDERED|STATE_ERROR` (plan 1)
  - `NodeType` tag constants (Task 1), `Scope::MAIL_READ`, `Scope::DNS_READ` (plan 1)
  - `ApiException`, `ErrorCode::INTERNAL` (plan 1)
  - `callable $hostRef` — **`fn(string $tag, $key): SyncPromise`** resolving to a
    shaped `VirtualHost` array. Supplied by `Container` from
    `VirtualHostResolver::reference()` (Task 12).
  - `callable $toUnicode` — `fn(string $value): string`, the panel's `decode_idna()`
  - `callable $mailboxUsage` — **`fn(string $address): ?int`** returning the
    bytes a mailbox currently holds, or null when there is no mailbox or the
    file cannot be read.
- Produces:
  - `ProvisioningFilter::clause(string $column, ?string $state): array` —
    `array(string $sqlFragment, array $bind)`; `array('', array())` for null or
    an unknown state. The fragment always begins with `AND ` when it is not empty.
  - `ProvisioningFilter::STATES` — the five `ProvisioningState` names
  - `new MailResolver(Db $db, BatchLoader $loader, callable $toUnicode, callable $mailboxUsage, callable $hostRef)`
  - `MailResolver::map(): array` — `MailAccount.host`, `MailAccount.quotaUsed`,
    `Customer.mailAccounts`
  - `MailResolver::shape(array $mailRow, callable $toUnicode): array` — **static**.
    Keys: `id`, `address`, `kind`, `forwardTo`, `quota`, `active`,
    `autoresponder`, `provisioning`, plus `__tag`, `__key`, `__domainId`,
    `__hostTag`, `__hostKey`.
  - `MailResolver::reference(int $mailId): SyncPromise` — a shaped `MailAccount`, or null
  - `new DnsResolver(Db $db, BatchLoader $loader, callable $hostRef)`
  - `DnsResolver::map(): array` — `DnsRecord.host`, `Customer.dnsRecords`
  - `DnsResolver::shape(array $dnsRow): array` — **static**. Keys: `id`, `name`,
    `class`, `type`, `value`, `ownedBy`, `provisioning`, plus `__tag`, `__key`,
    `__hostTag`, `__hostKey`.
  - `DnsResolver::reference(int $dnsId): SyncPromise`
  - `DnsResolver::ipReference(int $ipId): SyncPromise` — a shaped `IpAddress`, or
    null. Task 12's `$ipRef` is this.
  - `DnsResolver::ipReferences(array $ipIds): SyncPromise` — a list of shaped
    `IpAddress` arrays. Task 15's `Reseller.ipAddresses` uses it.
  - `DnsResolver::shapeIp(array $serverIpRow): array` — **static**

- [ ] **Step 1: Write the failing `ProvisioningFilter` test**

`test/unit/Support/ProvisioningFilterTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use PHPUnit\Framework\TestCase;

class ProvisioningFilterTest extends TestCase
{
    public function testNoStateIsNoFilterAtAll(): void
    {
        // Not "AND 1=1" and not "AND status IS NOT NULL": an absent filter
        // must add nothing, so that the caller can concatenate it blindly.
        self::assertSame(array('', array()), ProvisioningFilter::clause('s', null));
    }

    public function testOkIsTheOneLiteralStatus(): void
    {
        list($sql, $bind) = ProvisioningFilter::clause('m.status', 'OK');

        self::assertSame(' AND m.status = ?', $sql);
        self::assertSame(array('ok'), $bind);
    }

    public function testPendingIsEveryPendingStatus(): void
    {
        // Provisioning::PENDING_STATUSES is the list plan 1 established; this
        // asserts the filter uses that list rather than a second copy of it
        // that will drift.
        list($sql, $bind) = ProvisioningFilter::clause('m.status', 'PENDING');

        self::assertSame(
            ' AND m.status IN ('
                . implode(', ', array_fill(0, count(Provisioning::PENDING_STATUSES), '?'))
                . ')',
            $sql
        );
        self::assertSame(Provisioning::PENDING_STATUSES, $bind);
    }

    public function testErrorIsEverythingThatIsNotAKnownStatus(): void
    {
        // i-MSCP stores the backend's failure text in the status column, so
        // ERROR cannot be matched by value - only by exclusion. A filter that
        // listed error strings would match none of them.
        list($sql, $bind) = ProvisioningFilter::clause('m.status', 'ERROR');

        self::assertStringStartsWith(' AND m.status NOT IN (', $sql);
        self::assertContains('ok', $bind);
        self::assertContains('disabled', $bind);
        self::assertContains('ordered', $bind);
        self::assertContains('toadd', $bind);
    }

    public function testDisabledAndOrderedAreLiterals(): void
    {
        self::assertSame(
            array(' AND a.alias_status = ?', array('disabled')),
            ProvisioningFilter::clause('a.alias_status', 'DISABLED')
        );
        self::assertSame(
            array(' AND a.alias_status = ?', array('ordered')),
            ProvisioningFilter::clause('a.alias_status', 'ORDERED')
        );
    }

    public function testAnUnknownStateFiltersNothingRatherThanEverything(): void
    {
        // The schema's enum makes this unreachable through a query, but a
        // resolver calling this with a value from somewhere else must not
        // silently produce an empty list.
        self::assertSame(
            array('', array()), ProvisioningFilter::clause('s', 'SOMETHING_NEW')
        );
    }

    public function testEveryStateInTheSchemaIsCovered(): void
    {
        foreach (ProvisioningFilter::STATES as $state) {
            list($sql,) = ProvisioningFilter::clause('s', $state);

            self::assertNotSame('', $sql, $state);
        }

        self::assertSame(array(
            Provisioning::STATE_OK, Provisioning::STATE_PENDING,
            Provisioning::STATE_DISABLED, Provisioning::STATE_ORDERED,
            Provisioning::STATE_ERROR
        ), ProvisioningFilter::STATES);
    }
}
```

- [ ] **Step 2: Write the failing `MailResolver` unit test**

`test/unit/Resolver/MailResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class MailResolverTest extends TestCase
{
    /**
     * A mail_users row, exactly as the column names come back.
     */
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'mail_id'                => 21,
            'mail_acc'               => 'sales',
            'mail_addr'              => 'sales@xn--bcher-kva.test',
            'mail_forward'           => null,
            'domain_id'              => 12,
            'mail_type'              => 'normal_mail',
            'sub_id'                 => 0,
            'status'                 => 'ok',
            'po_active'              => 'yes',
            'mail_auto_respond'      => 0,
            'mail_auto_respond_text' => null,
            'quota'                  => 104857600
        ), $overrides);
    }

    private function toUnicode(): callable
    {
        return static function (string $value) {
            return $value === 'sales@xn--bcher-kva.test'
                ? 'sales@bücher.test' : $value;
        };
    }

    private function resolver(): MailResolver
    {
        return new MailResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            $this->toUnicode(),
            static function (string $address) { return null; },
            static function (string $tag, $key) { return null; }
        );
    }

    public function testTheAddressIsReturnedAsUnicode(): void
    {
        $shape = MailResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('sales@bücher.test', $shape['address']);
        self::assertSame(GlobalId::encode(NodeType::MAIL_ACCOUNT, 21), $shape['id']);
    }

    public function testTheKindDropsTheVhostHalfOfMailType(): void
    {
        // Spec section 7.6: the vhost half of i-MSCP's twelve mail_type values
        // is carried by `host`, so the enum keeps only the second half. A
        // resolver exposing 'normal_mail' would leak a storage detail into
        // every client.
        self::assertSame('MAILBOX', MailResolver::shape(
            $this->row(), $this->toUnicode()
        )['kind']);
        self::assertSame('FORWARD', MailResolver::shape(
            $this->row(array('mail_type' => 'alssub_forward')), $this->toUnicode()
        )['kind']);
        self::assertSame('MAILBOX_AND_FORWARD', MailResolver::shape(
            $this->row(array('mail_type' => 'subdom_mail,subdom_forward')),
            $this->toUnicode()
        )['kind']);
        self::assertSame('CATCHALL', MailResolver::shape(
            $this->row(array('mail_type' => 'alias_catchall')), $this->toUnicode()
        )['kind']);
    }

    public function testTheHostOfAMainDomainAddressIsTheDomainNotSubIdZero(): void
    {
        // sub_id is 0 for the main domain, and 0 is not an identifier. A
        // resolver that passed it through would ask for Subdomain:0 and get
        // nothing, nulling a non-null field.
        $shape = MailResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(NodeType::DOMAIN, $shape['__hostTag']);
        self::assertSame(12, $shape['__hostKey']);
    }

    public function testTheHostOfAnAliasSubdomainAddressIsTheAliasSubdomain(): void
    {
        $shape = MailResolver::shape(
            $this->row(array('mail_type' => 'alssub_forward', 'sub_id' => 9)),
            $this->toUnicode()
        );

        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $shape['__hostTag']);
        self::assertSame(9, $shape['__hostKey']);
    }

    public function testForwardsAreSplitOnTheComma(): void
    {
        $shape = MailResolver::shape($this->row(array(
            'mail_type'    => 'normal_forward',
            'mail_forward' => 'a@example.net,b@example.net'
        )), $this->toUnicode());

        self::assertSame(array('a@example.net', 'b@example.net'), $shape['forwardTo']);
    }

    public function testAMailboxWithNoForwardsHasAnEmptyListNotNull(): void
    {
        // forwardTo is [EmailAddress!]! - non-null. Null here would null the
        // whole MailAccount.
        self::assertSame(array(), MailResolver::shape(
            $this->row(array('mail_forward' => '_no_')), $this->toUnicode()
        )['forwardTo']);
        self::assertSame(array(), MailResolver::shape(
            $this->row(), $this->toUnicode()
        )['forwardTo']);
    }

    public function testAZeroQuotaIsUnlimitedNotZeroBytes(): void
    {
        self::assertSame('104857600', MailResolver::shape(
            $this->row(), $this->toUnicode()
        )['quota']);
        self::assertNull(MailResolver::shape(
            $this->row(array('quota' => 0)), $this->toUnicode()
        )['quota']);
    }

    public function testTheAutoresponderIsNullWhenItIsOff(): void
    {
        // Autoresponder.message is String! - so an off autoresponder must be
        // an absent object, not an object with an empty message.
        self::assertNull(MailResolver::shape($this->row(), $this->toUnicode())['autoresponder']);

        $on = MailResolver::shape($this->row(array(
            'mail_auto_respond' => 1, 'mail_auto_respond_text' => 'On holiday.'
        )), $this->toUnicode());

        self::assertSame(
            array('enabled' => true, 'message' => 'On holiday.'), $on['autoresponder']
        );
    }

    public function testAnAutoresponderWithNoTextStillHasAMessage(): void
    {
        $on = MailResolver::shape($this->row(array(
            'mail_auto_respond' => 1, 'mail_auto_respond_text' => null
        )), $this->toUnicode());

        self::assertSame('', $on['autoresponder']['message']);
    }

    public function testPopAccessIsOnlyTrueWhenTheColumnSaysYes(): void
    {
        self::assertTrue(MailResolver::shape($this->row(), $this->toUnicode())['active']);
        self::assertFalse(MailResolver::shape(
            $this->row(array('po_active' => 'no')), $this->toUnicode()
        )['active']);
    }

    public function testAMailTypeTheCodecDoesNotKnowIsAnInternalError(): void
    {
        // Left alone this would be an uncaught InvalidArgumentException and a
        // 500 with a stack trace. Spec section 9 wants a structured code.
        try {
            MailResolver::shape(
                $this->row(array('mail_type' => 'gopher_mail')), $this->toUnicode()
            );
            self::fail('an unknown mail_type must raise an ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::INTERNAL, $e->getErrorCode());
            self::assertSame('gopher_mail', $e->getExtensions()['mailType']);
        }
    }

    public function testTheMapOwnsTheMailFieldsAndCustomerMailAccounts(): void
    {
        $keys = array_keys($this->resolver()->map());
        sort($keys);

        self::assertSame(array(
            'Customer.mailAccounts', 'MailAccount.host', 'MailAccount.quotaUsed'
        ), $keys);
    }

    public function testTheDnsMapOwnsTheDnsFieldsAndCustomerDnsRecords(): void
    {
        $dns = new DnsResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            static function (string $tag, $key) { return null; }
        );

        $keys = array_keys($dns->map());
        sort($keys);

        self::assertSame(array('Customer.dnsRecords', 'DnsRecord.host'), $keys);
    }

    public function testADnsRecordOnTheMainDomainHasAliasIdZero(): void
    {
        // domain_dns.alias_id = 0 means "on the main domain"
        // (gui/public/client/dns_edit.php:592). Reading it as an alias id
        // would ask for DomainAlias:0 and null a non-null field.
        $onDomain = DnsResolver::shape(array(
            'domain_dns_id'     => 31,
            'domain_id'         => 12,
            'alias_id'          => 0,
            'domain_dns'        => 'mail',
            'domain_class'      => 'IN',
            'domain_type'       => 'A',
            'domain_text'       => '203.0.113.7',
            'owned_by'          => 'custom_dns_feature',
            'domain_dns_status' => 'ok'
        ));

        self::assertSame(NodeType::DOMAIN, $onDomain['__hostTag']);
        self::assertSame(12, $onDomain['__hostKey']);
        self::assertSame('mail', $onDomain['name']);
        self::assertSame('IN', $onDomain['class']);
        self::assertSame('A', $onDomain['type']);
        self::assertSame('203.0.113.7', $onDomain['value']);
        self::assertSame('custom_dns_feature', $onDomain['ownedBy']);
        self::assertSame(NodeType::DNS_RECORD, $onDomain[TypeResolver::TAG]);
    }

    public function testADnsRecordOnAnAliasHangsOffTheAlias(): void
    {
        $onAlias = DnsResolver::shape(array(
            'domain_dns_id'     => 32,
            'domain_id'         => 12,
            'alias_id'          => 4,
            'domain_dns'        => 'www',
            'domain_class'      => 'IN',
            'domain_type'       => 'CNAME',
            'domain_text'       => 'example.net.',
            'owned_by'          => 'SGW_LetsEncrypt',
            'domain_dns_status' => 'ok'
        ));

        self::assertSame(NodeType::DOMAIN_ALIAS, $onAlias['__hostTag']);
        self::assertSame(4, $onAlias['__hostKey']);
    }

    public function testAnIpAddressShapesFromServerIps(): void
    {
        $ip = DnsResolver::shapeIp(array(
            'ip_id'      => 3,
            'ip_number'  => '203.0.113.7',
            'ip_netmask' => 24,
            'ip_card'    => 'eth0'
        ));

        self::assertSame(GlobalId::encode(NodeType::IP_ADDRESS, 3), $ip['id']);
        self::assertSame('203.0.113.7', $ip['address']);
        self::assertSame(24, $ip['netmask']);
        self::assertSame('eth0', $ip['card']);
    }

    public function testAnIpAddressWithNoNetmaskOrCardIsStillAnIpAddress(): void
    {
        // Both columns are nullable in i-MSCP, and both are nullable in the
        // schema. Casting a null netmask to 0 would be a lie about the network.
        $ip = DnsResolver::shapeIp(array(
            'ip_id' => 3, 'ip_number' => '203.0.113.7',
            'ip_netmask' => null, 'ip_card' => null
        ));

        self::assertNull($ip['netmask']);
        self::assertNull($ip['card']);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
tools/test.sh
```

Expected: FAIL — `ProvisioningFilter`, `MailResolver` and `DnsResolver` not found.

- [ ] **Step 4: Write `Support/ProvisioningFilter.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;
// ... licence header ...

/**
 * A ProvisioningState turned into a SQL predicate over a status column.
 *
 * The inverse of Provisioning: that class reads a stored status and says what
 * state it is, this one takes a state and says which stored statuses are in
 * it. ERROR is the interesting one - i-MSCP writes the backend's failure text
 * into the status column, so the set of error statuses is open and can only be
 * matched by excluding the closed set of everything else.
 */
final class ProvisioningFilter
{
    /** Every ProvisioningState the schema declares. */
    const STATES = array(
        Provisioning::STATE_OK,
        Provisioning::STATE_PENDING,
        Provisioning::STATE_DISABLED,
        Provisioning::STATE_ORDERED,
        Provisioning::STATE_ERROR
    );

    /** The states that are exactly one stored status. */
    const LITERALS = array(
        Provisioning::STATE_OK       => 'ok',
        Provisioning::STATE_DISABLED => 'disabled',
        Provisioning::STATE_ORDERED  => 'ordered'
    );

    /**
     * @param string $column A column reference, already qualified by the caller
     * @return array{0: string, 1: array} SQL fragment and its bindings
     */
    public static function clause(string $column, ?string $state): array
    {
        if ($state === null || !in_array($state, self::STATES, true)) {
            // An absent or unrecognised filter adds nothing, so that a caller
            // can concatenate the fragment without testing it first. It must
            // not narrow to nothing: a client asking for a state this server
            // has never heard of should see everything, not an empty list it
            // would read as "you have none".
            return array('', array());
        }

        if (isset(self::LITERALS[$state])) {
            return array(' AND ' . $column . ' = ?', array(self::LITERALS[$state]));
        }

        if ($state === Provisioning::STATE_PENDING) {
            return array(
                ' AND ' . $column . ' IN (' . self::questions(
                    count(Provisioning::PENDING_STATUSES)
                ) . ')',
                Provisioning::PENDING_STATUSES
            );
        }

        // ERROR. Everything that is not a status i-MSCP writes deliberately.
        $known = array_merge(
            array_values(self::LITERALS), Provisioning::PENDING_STATUSES
        );

        return array(
            ' AND ' . $column . ' NOT IN (' . self::questions(count($known)) . ')',
            $known
        );
    }

    private static function questions(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }
}
```

- [ ] **Step 5: Write `Resolver/MailResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use InvalidArgumentException;

/**
 * MailAccount and Autoresponder - spec section 7.6.
 *
 * The one interesting mapping here is mail_type. i-MSCP stores the cross
 * product of {normal, alias, subdom, alssub} and {mail, forward, catchall},
 * sometimes comma-joined; the schema splits that in two, putting the vhost
 * half behind `host` and keeping only the account kind. Support\MailType is
 * the table, and this class is the only caller that turns a failure to read it
 * into a structured error rather than a fatal.
 */
final class MailResolver
{
    /** The vhost half of mail_type => the NodeType tag of that host. */
    const HOST_TAGS = array(
        MailType::HOST_DMN    => NodeType::DOMAIN,
        MailType::HOST_SUB    => NodeType::SUBDOMAIN,
        MailType::HOST_ALS    => NodeType::DOMAIN_ALIAS,
        MailType::HOST_ALSSUB => NodeType::ALIAS_SUBDOMAIN
    );

    /** i-MSCP's "this column holds nothing" spelling, used across the schema. */
    const NOTHING = '_no_';

    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(string $address): ?int */
    private $mailboxUsage;

    /** @var callable fn(string $tag, $key): SyncPromise */
    private $hostRef;

    public function __construct(
        Db $db, BatchLoader $loader, callable $toUnicode, callable $mailboxUsage,
        callable $hostRef
    ) {
        $this->db = $db;
        $this->loader = $loader;
        $this->toUnicode = $toUnicode;
        $this->mailboxUsage = $mailboxUsage;
        $this->hostRef = $hostRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'MailAccount.host'      => array($this, 'resolveHost'),
            'MailAccount.quotaUsed' => array($this, 'resolveQuotaUsed'),
            'Customer.mailAccounts' => array($this, 'resolveCustomerMailAccounts')
        );
    }

    /**
     * @param array<string, mixed> $row A row of mail_users
     * @param callable $toUnicode fn(string): string
     * @return array<string, mixed>
     * @throws ApiException when mail_type is a value the codec does not know
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        $mailType = (string)$row['mail_type'];

        try {
            $kind = MailType::kindOf($mailType);
            $hostType = MailType::hostTypeOf($mailType);
        } catch (InvalidArgumentException $e) {
            // Uncaught this is a 500 with a stack trace and no clue which row
            // caused it. Spec section 9 wants a code and an extension.
            throw new ApiException(
                ErrorCode::INTERNAL,
                'This mail account has a type the API does not recognise.',
                array('mailType' => $mailType),
                $e
            );
        }

        // sub_id is 0 for an address on the main domain, and 0 is not an
        // identifier: the host is the domain itself.
        $hostKey = $hostType === MailType::HOST_DMN
            ? (int)$row['domain_id'] : (int)$row['sub_id'];

        return TypeResolver::node(NodeType::MAIL_ACCOUNT, (int)$row['mail_id'], array(
            'address'       => (string)call_user_func($toUnicode, (string)$row['mail_addr']),
            'kind'          => $kind,
            'forwardTo'     => self::forwards($row['mail_forward']),
            // 0 is unlimited, as everywhere else in i-MSCP.
            'quota'         => (int)$row['quota'] === 0
                ? null : TypeResolver::bigInt($row['quota']),
            'active'        => $row['po_active'] === 'yes',
            'autoresponder' => self::autoresponder($row),
            'provisioning'  => TypeResolver::provisioning(
                $row['status'] === null ? null : (string)$row['status']
            ),
            '__domainId'    => (int)$row['domain_id'],
            '__hostTag'     => self::HOST_TAGS[$hostType],
            '__hostKey'     => $hostKey
        ));
    }

    public function reference(int $mailId): SyncPromise
    {
        $db = $this->db;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'mail:row',
            $mailId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT * FROM mail_users WHERE mail_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['mail_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    public function resolveHost($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::MAIL_READ);

        return call_user_func($this->hostRef, $source['__hostTag'], $source['__hostKey']);
    }

    public function resolveQuotaUsed($source, array $args, $context, ResolveInfo $info): ?string
    {
        TypeResolver::requireScope($context, Scope::MAIL_READ);

        // Spec section 7.6: null when there is no mailbox, when no quota is
        // set, or when the file cannot be read. A forward-only address has no
        // maildir at all, so asking the filesystem about it would be a stat
        // per row for an answer that is always null.
        if ($source['quota'] === null
            || !in_array($source['kind'], array(
                MailType::KIND_MAILBOX, MailType::KIND_MAILBOX_AND_FORWARD
            ), true)
        ) {
            return null;
        }

        $bytes = call_user_func($this->mailboxUsage, $source['address']);

        return $bytes === null ? null : TypeResolver::bigInt($bytes);
    }

    /**
     * Customer.mailAccounts - a filtered, paged connection.
     *
     * Decision D6: the batched query has no LIMIT and the page is sliced per
     * parent afterwards, because a per-parent LIMIT inside one query needs a
     * window function MariaDB 10.1 does not have and the alternative is a
     * query per customer. totalCount is then free: it is the length of the
     * parent's rows before the slice.
     */
    public function resolveCustomerMailAccounts(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::MAIL_READ);

        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $page = TypeResolver::page($args['page'] ?? null);
        $db = $this->db;
        $toUnicode = $this->toUnicode;

        // Every customer in the same selection set passes the same filter, so
        // the bucket name carries it and they all share one query. Two
        // different filters in one document are two buckets, which is right:
        // they are two different questions.
        return $this->loader->keyed(
            'mail:by-domain:' . self::bucket($filter),
            (int)$source['__domainId'],
            static function (array $keys) use ($db, $filter) {
                return self::loadByDomain($db, $keys, $filter);
            }
        )->then(static function ($rows) use ($page, $toUnicode) {
            $rows = $rows === null ? array() : $rows;
            $nodes = array();

            foreach (array_slice($rows, $page['offset'], $page['limit']) as $row) {
                $nodes[] = self::shape($row, $toUnicode);
            }

            return array('totalCount' => count($rows), 'nodes' => $nodes);
        });
    }

    /**
     * @param int[] $domainIds
     * @param array<string, mixed> $filter
     * @return array<int, array> domainId => rows
     */
    private static function loadByDomain(Db $db, array $domainIds, array $filter): array
    {
        $sql = 'SELECT * FROM mail_users WHERE domain_id IN ('
            . $db->placeholders(count($domainIds)) . ')';
        $bind = $domainIds;

        if (isset($filter['kind'])) {
            // The schema's kind is half of mail_type, so filtering on it means
            // listing every stored value whose second half matches - all four
            // vhost flavours of that kind.
            $types = array();

            foreach (MailType::all() as $stored) {
                if (MailType::kindOf($stored) === $filter['kind']) {
                    $types[] = $stored;
                }
            }

            $sql .= ' AND mail_type IN (' . $db->placeholders(count($types)) . ')';
            $bind = array_merge($bind, $types);
        }

        if (isset($filter['address']) && $filter['address'] !== '') {
            // Substring, as the panel's own mail list does. The wildcards are
            // added here rather than taken from the client, so a client cannot
            // smuggle a leading % past an index it does not know about.
            $sql .= ' AND mail_addr LIKE ?';
            $bind[] = '%' . self::escapeLike((string)$filter['address']) . '%';
        }

        list($stateSql, $stateBind) = ProvisioningFilter::clause(
            'status', $filter['state'] ?? null
        );
        $sql .= $stateSql;
        $bind = array_merge($bind, $stateBind);

        // Ordered so that a page is stable between requests; mail_id is the
        // only column guaranteed unique.
        $sql .= ' ORDER BY mail_id';

        $byDomain = array();

        foreach ($domainIds as $domainId) {
            // Every requested key present, so that a customer with no mail
            // accounts gets an empty connection rather than a null one.
            $byDomain[(int)$domainId] = array();
        }

        foreach ($db->rows($sql, $bind) as $row) {
            $byDomain[(int)$row['domain_id']][] = $row;
        }

        return $byDomain;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function bucket(array $filter): string
    {
        ksort($filter);

        return md5(serialize($filter));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }

    /**
     * @param mixed $stored
     * @return string[]
     */
    private static function forwards($stored): array
    {
        // forwardTo is [EmailAddress!]! - non-null - so "none" is an empty
        // list. i-MSCP writes NULL for a mailbox and the literal '_no_' for a
        // row that once had forwards and no longer does.
        if ($stored === null || $stored === '' || $stored === self::NOTHING) {
            return array();
        }

        $addresses = array();

        foreach (explode(',', (string)$stored) as $address) {
            $address = trim($address);

            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{enabled: bool, message: string}|null
     */
    private static function autoresponder(array $row): ?array
    {
        if (!(int)$row['mail_auto_respond']) {
            // Autoresponder.message is String!, so an autoresponder that is
            // off must be an absent object rather than an object with nothing
            // in it.
            return null;
        }

        return array(
            'enabled' => true,
            'message' => $row['mail_auto_respond_text'] === null
                ? '' : (string)$row['mail_auto_respond_text']
        );
    }
}
```

- [ ] **Step 6: Write `Resolver/DnsResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * DnsRecord and IpAddress - spec section 7.8.
 *
 * IpAddress lives here rather than with the vhosts because server_ips is the
 * only table it comes from and DNS is the only other thing that talks about
 * addresses. Domain.ipAddress and Reseller.ipAddresses both reach it through
 * the two reference methods below, injected as closures so that neither
 * VirtualHostResolver nor ResellerResolver has to depend on this class.
 */
final class DnsResolver
{
    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(string $tag, $key): SyncPromise */
    private $hostRef;

    public function __construct(Db $db, BatchLoader $loader, callable $hostRef)
    {
        $this->db = $db;
        $this->loader = $loader;
        $this->hostRef = $hostRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'DnsRecord.host'       => array($this, 'resolveHost'),
            'Customer.dnsRecords'  => array($this, 'resolveCustomerDnsRecords')
        );
    }

    /**
     * @param array<string, mixed> $row A row of domain_dns
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        $aliasId = (int)$row['alias_id'];

        return TypeResolver::node(
            NodeType::DNS_RECORD, (int)$row['domain_dns_id'], array(
                'name'         => (string)$row['domain_dns'],
                'class'        => (string)$row['domain_class'],
                'type'         => (string)$row['domain_type'],
                'value'        => (string)$row['domain_text'],
                'ownedBy'      => (string)$row['owned_by'],
                'provisioning' => TypeResolver::provisioning(
                    $row['domain_dns_status'] === null
                        ? null : (string)$row['domain_dns_status']
                ),
                // alias_id = 0 means the record is on the main domain
                // (gui/public/client/dns_edit.php:592). Reading 0 as an alias
                // id would ask for DomainAlias:0 and null a non-null field.
                '__hostTag'    => $aliasId === 0
                    ? NodeType::DOMAIN : NodeType::DOMAIN_ALIAS,
                '__hostKey'    => $aliasId === 0 ? (int)$row['domain_id'] : $aliasId,
                '__domainId'   => (int)$row['domain_id']
            )
        );
    }

    /**
     * @param array<string, mixed> $row A row of server_ips
     * @return array<string, mixed>
     */
    public static function shapeIp(array $row): array
    {
        return TypeResolver::node(NodeType::IP_ADDRESS, (int)$row['ip_id'], array(
            'address' => (string)$row['ip_number'],
            // Both nullable in i-MSCP and both nullable in the schema. Casting
            // a null netmask to 0 would be a statement about the network that
            // the database never made.
            'netmask' => $row['ip_netmask'] === null ? null : (int)$row['ip_netmask'],
            'card'    => $row['ip_card'] === null || $row['ip_card'] === ''
                ? null : (string)$row['ip_card']
        ));
    }

    public function reference(int $dnsId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'dns:row',
            $dnsId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT * FROM domain_dns WHERE domain_dns_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['domain_dns_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shape($row);
        });
    }

    public function ipReference(int $ipId): SyncPromise
    {
        return $this->ipRow($ipId)->then(static function ($row) {
            return $row === null ? null : self::shapeIp($row);
        });
    }

    /**
     * @param int[] $ipIds
     */
    public function ipReferences(array $ipIds): SyncPromise
    {
        $deferreds = array();

        foreach ($ipIds as $ipId) {
            $deferreds[] = $this->ipRow((int)$ipId);
        }

        return new Deferred(static function () use ($deferreds) {
            $addresses = array();

            foreach ($deferreds as $deferred) {
                if ($deferred->result !== null) {
                    $addresses[] = self::shapeIp($deferred->result);
                }
            }

            return $addresses;
        });
    }

    public function resolveHost($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DNS_READ);

        return call_user_func($this->hostRef, $source['__hostTag'], $source['__hostKey']);
    }

    public function resolveCustomerDnsRecords(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DNS_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'dns:by-domain',
            (int)$source['__domainId'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT * FROM domain_dns WHERE domain_id IN ('
                        . $db->placeholders(count($keys))
                        . ') ORDER BY domain_dns_id',
                    $keys
                );
                $byDomain = array();

                foreach ($keys as $key) {
                    // Present even when empty: dnsRecords is [DnsRecord!]!, so
                    // a customer with none needs a list, not a null.
                    $byDomain[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDomain[(int)$row['domain_id']][] = $row;
                }

                return $byDomain;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shape($row);
            }

            return $shaped;
        });
    }

    private function ipRow(int $ipId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'ip:row',
            $ipId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT ip_id, ip_number, ip_netmask, ip_card FROM server_ips'
                        . ' WHERE ip_id IN (' . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['ip_id']] = $row;
                }

                return $byId;
            }
        );
    }
}
```

- [ ] **Step 7: Write the failing integration tests**

`test/integration/MailResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class MailResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var MailResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new MailResolver(
            $this->db,
            new BatchLoader($this->db),
            'decode_idna',
            static function (string $address) {
                // The maildir does not exist for a fixture account, and this
                // test is not about the filesystem: it is about which
                // addresses are asked at all.
                return $address === 'sales@sgwtcustomer.test' ? 4096 : null;
            },
            static function (string $tag, $key) {
                return new Deferred(static function () use ($tag, $key) {
                    return array('__tag' => $tag, '__key' => $key);
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        return array('identity' => $this->fixture->identity('customer'));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    private function customer(): array
    {
        return array('__domainId' => $this->fixture->domainId());
    }

    public function testTheConnectionCarriesBothSeededAccounts(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
        self::assertCount(2, $connection['nodes']);
        self::assertSame(
            'sales@' . $this->fixture->domainName(), $connection['nodes'][0]['address']
        );
        self::assertSame('MAILBOX', $connection['nodes'][0]['kind']);
        self::assertSame('FORWARD', $connection['nodes'][1]['kind']);
        self::assertSame(
            array('a@example.net', 'b@example.net'), $connection['nodes'][1]['forwardTo']
        );
    }

    public function testFilteringByKindMatchesEveryVhostFlavourOfThatKind(): void
    {
        // The fixture's forward is stored as 'alssub_forward'. A filter that
        // compared the schema's FORWARD to the column would match nothing.
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('filter' => array('kind' => 'FORWARD')),
            $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame('hello@blog.' . $this->fixture->aliasName(),
            $connection['nodes'][0]['address']);
    }

    public function testFilteringByAddressIsASubstringMatch(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('filter' => array('address' => 'sale')),
            $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
    }

    public function testAPageNarrowsTheNodesAndNotTheTotal(): void
    {
        // totalCount is what the filter matched, not what the page returned -
        // otherwise a client can never tell it has more to fetch.
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array('page' => array('limit' => 1, 'offset' => 1)),
            $this->context(), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
        self::assertCount(1, $connection['nodes']);
        self::assertSame('FORWARD', $connection['nodes'][0]['kind']);
    }

    public function testAMainDomainAddressHangsOffTheDomainAndAnAliasSubdomainAddressOffTheAliasSubdomain(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        $mailbox = $this->value($this->resolver->resolveHost(
            $connection['nodes'][0], array(), $this->context(), $this->info()
        ));
        $forward = $this->value($this->resolver->resolveHost(
            $connection['nodes'][1], array(), $this->context(), $this->info()
        ));

        self::assertSame(NodeType::DOMAIN, $mailbox['__tag']);
        self::assertSame($this->fixture->domainId(), $mailbox['__key']);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $forward['__tag']);
        self::assertSame($this->fixture->aliasSubdomainId(), $forward['__key']);
    }

    public function testQuotaUsedIsOnlyAskedForAnAccountThatHasAMailbox(): void
    {
        // The forward has quota 0 - unlimited - and no mailbox, so the
        // filesystem is not consulted at all. Asking would be a stat per row
        // for an answer that is always null.
        $connection = $this->value($this->resolver->resolveCustomerMailAccounts(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame('4096', $this->resolver->resolveQuotaUsed(
            $connection['nodes'][0], array(), $this->context(), $this->info()
        ));
        self::assertNull($this->resolver->resolveQuotaUsed(
            $connection['nodes'][1], array(), $this->context(), $this->info()
        ));
    }

    public function testSixCustomersMailAccountsCostOneQuery(): void
    {
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveCustomerMailAccounts(
                        $customer, array(), $context, $info
                    );
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
    }

    public function testTwoDifferentFiltersAreTwoQueriesNotOneWrongOne(): void
    {
        // The bucket carries the filter. Sharing one bucket between two
        // filters would serve one client the other's rows, which is worse
        // than a second query.
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                $resolver->resolveCustomerMailAccounts($customer, array(), $context, $info);
                $resolver->resolveCustomerMailAccounts(
                    $customer, array('filter' => array('kind' => 'FORWARD')),
                    $context, $info
                );

                SyncPromise::runQueue();
            }
        );

        self::assertSame(2, $count);
    }

    public function testANarrowedTokenIsRefusedRatherThanGivenAnEmptyMailbox(): void
    {
        $customer = $this->fixture->identity('customer');
        $context = array('identity' => new Identity(
            $customer->getAdminId(), $customer->getUsername(), 'user',
            $customer->getCreatedBy(), $customer->getEmail(),
            array(Scope::DNS_READ), 1
        ));

        try {
            $this->resolver->resolveCustomerMailAccounts(
                $this->customer(), array(), $context, $this->info()
            );
            self::fail('a token without MAIL_READ must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }
}
```

`test/integration/DnsResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DnsResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var DnsResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new DnsResolver(
            $this->db,
            new BatchLoader($this->db),
            static function (string $tag, $key) {
                return new Deferred(static function () use ($tag, $key) {
                    return array('__tag' => $tag, '__key' => $key);
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        return array('identity' => $this->fixture->identity('customer'));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    public function testTheSeededRecordComesBackWhole(): void
    {
        $records = $this->value($this->resolver->resolveCustomerDnsRecords(
            array('__domainId' => $this->fixture->domainId()), array(),
            $this->context(), $this->info()
        ));

        self::assertCount(1, $records);
        self::assertSame('mail', $records[0]['name']);
        self::assertSame('IN', $records[0]['class']);
        self::assertSame('A', $records[0]['type']);
        self::assertSame('203.0.113.7', $records[0]['value']);
        self::assertSame('custom_dns_feature', $records[0]['ownedBy']);
        self::assertSame('OK', $records[0]['provisioning']['state']);
    }

    public function testARecordWithAliasIdZeroHangsOffTheMainDomain(): void
    {
        $records = $this->value($this->resolver->resolveCustomerDnsRecords(
            array('__domainId' => $this->fixture->domainId()), array(),
            $this->context(), $this->info()
        ));

        $host = $this->value($this->resolver->resolveHost(
            $records[0], array(), $this->context(), $this->info()
        ));

        self::assertSame(NodeType::DOMAIN, $host['__tag']);
        self::assertSame($this->fixture->domainId(), $host['__key']);
    }

    public function testACustomerWithNoRecordsGetsAnEmptyListNotANull(): void
    {
        // dnsRecords is [DnsRecord!]!. A null here nulls the Customer, and a
        // reseller listing customers would lose the whole list to one
        // customer with no DNS records.
        $records = $this->value($this->resolver->resolveCustomerDnsRecords(
            array('__domainId' => -1), array(), $this->context(), $this->info()
        ));

        self::assertSame(array(), $records);
    }

    public function testTheSeededIpAddressShapesFromServerIps(): void
    {
        $ip = $this->value($this->resolver->ipReference($this->fixture->ipId()));

        self::assertSame('203.0.113.7', $ip['address']);
        self::assertSame(24, $ip['netmask']);
        self::assertSame('eth0', $ip['card']);
    }

    public function testTenIpReferencesCostOneQuery(): void
    {
        $resolver = $this->resolver;
        $ipId = $this->fixture->ipId();

        $count = $this->db->countQueries(function () use ($resolver, $ipId) {
            for ($i = 0; $i < 10; $i++) {
                $resolver->ipReference($ipId);
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testSixCustomersDnsRecordsCostOneQuery(): void
    {
        $resolver = $this->resolver;
        $domainId = $this->fixture->domainId();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $domainId, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveCustomerDnsRecords(
                        array('__domainId' => $domainId), array(), $context, $info
                    );
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
    }
}
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
tools/test.sh
sh test/lint/all.sh
```

Expected: PASS — `ProvisioningFilterTest` 7 methods, `MailResolverTest` 17 unit
and 9 integration methods, `DnsResolverTest` 6 integration methods.

- [ ] **Step 9: Commit**

```bash
git add Support/ProvisioningFilter.php Resolver/MailResolver.php \
        Resolver/DnsResolver.php test/unit/Support/ProvisioningFilterTest.php \
        test/unit/Resolver/MailResolverTest.php \
        test/integration/MailResolverTest.php test/integration/DnsResolverTest.php
git commit -m "Resolve mail accounts, DNS records and IP addresses

mail_type is the cross product of four vhost kinds and three account kinds,
and the schema splits it: the vhost half becomes the host edge and only the
account kind is exposed. That makes filtering by kind a lookup rather than a
comparison - FORWARD matches four stored values, not one - and it makes an
unrecognised mail_type a structured error instead of an uncaught
InvalidArgumentException and a stack trace.

domain_dns.alias_id carries 0 for a record on the main domain, so the host
edge is a two-way choice rather than a column read; getting it wrong would ask
for DomainAlias:0 and null a non-null field.

Connections are paged in PHP rather than in SQL. A per-parent LIMIT inside one
batched query needs a window function MariaDB 10.1 does not have, and the
alternative is a query per customer - the N+1 this whole phase exists to
prevent. The trade is bounded by i-MSCP's own limits and totalCount comes out
of the same query."
```

---

## Task 15: The FTP, SQL and reseller resolvers — `sonnet`

Spec §7.7 and §7.8: `FtpUser`, `SqlDatabase`, `SqlUser`, `Reseller` and
`HostingPlan`, plus the three `Viewer` fields the SDL gained in Task 11.

**`Reseller` is the one type with field-level authorisation.** The SDL says so
in its own description: a customer may read the identity of their own reseller
— `id`, `username`, `contact` — *"and nothing else: every other field answers
FORBIDDEN for a caller who is not that reseller or an administrator."* That is
not ownership, which `OwnershipResolver` already answers, but a second rule on
top of it, and `ResellerResolver::requireReseller()` is the only place it lives.

**Where a reseller's numbers come from, and why they come from two places.**
`ResellerQuotas.used` reads `reseller_props.current_*` — i-MSCP's own
maintained counters, the same ones the panel's reseller pages display — so the
API and the panel cannot disagree about how many subdomains a reseller has
sold. `Storage` cannot use the matching `current_disk_amnt`, because the schema
wants the file/mail/SQL split beside the total and no counter carries it;
taking the total from a counter and the parts from a sum would let the parts
disagree with the whole. So the whole `Storage` block is summed from the
reseller's customers' `domain` rows in one query, and the limits alone come
from `max_disk_amnt` and `max_traff_amnt`.

**`Customer.sqlUsers` counts differently from how it lists, on purpose.**
`Counts::sqlUsers()` is `COUNT(DISTINCT sqlu_name)`, transcribed from
`Counting.php`, because that is the rule i-MSCP enforces the quota against. The
list is one node per `sql_user` row, because the SDL says so — *"One row per
grant, so a user granted on three databases appears in three"* — and because a
client needs the grant to address it. A customer with one user granted on two
databases therefore shows `quotas.sqlUsers.used = 1` and two `sqlUsers` nodes.
Both are right; the [Self-review](#self-review) records it so a reader does not
have to rediscover it.

**Files:**
- Create: `Resolver/FtpSqlResolver.php`
- Create: `Resolver/ResellerResolver.php`
- Create: `test/unit/Resolver/FtpSqlResolverTest.php`
- Create: `test/unit/Resolver/ResellerResolverTest.php`
- Create: `test/integration/FtpSqlResolverTest.php`
- Create: `test/integration/ResellerResolverTest.php`

**Interfaces:**
- Consumes:
  - `Db::rows()`, `Db::placeholders()`, `Db::detached()`, `Db::countQueries()` (Tasks 2, 10)
  - `new BatchLoader(Db $db)`, `BatchLoader::keyed()` (Task 10)
  - `PlanProps::parse(string $props): PlanProps`, `::allowance(string $name): array`,
    `::storage(): array`, `::features(): array`, `::ALLOWANCES` (Task 7)
  - `Quota::fromResellerLimit(int $limit, int $used): Quota`, `Quota::toArray()` (Task 3)
  - `ProvisioningFilter::clause(string $column, ?string $state): array` (Task 14)
  - `TypeResolver::node()`, `::provisioning()`, `::dateTime()`, `::bigInt()`,
    `::contact()`, `::identity()`, `::requireScope()`, `::page()`, `::TAG` (Task 11)
  - `Identity::getAdminId()`, `::getRole()`, `::ROLE_ADMIN|ROLE_RESELLER|ROLE_CUSTOMER` (plan 1)
  - `NodeType` tag constants (Task 1); `Scope::ACCOUNT_READ`, `CUSTOMERS_READ`,
    `RESELLERS_READ`, `FTP_READ`, `SQL_READ` (plan 1)
  - `ApiException`, `ErrorCode::FORBIDDEN`, `ErrorCode::INTERNAL` (plan 1)
  - `callable $customerRef` — `fn(int $adminId): SyncPromise` (Task 13)
  - `callable $customerRefs` — **`fn(array $adminIds): SyncPromise`** resolving to
    a list of shaped `Customer` arrays. `CustomerResolver::references()` (Task 13).
  - `callable $ipsRef` — **`fn(array $ipIds): SyncPromise`** resolving to a list of
    shaped `IpAddress` arrays. `DnsResolver::ipReferences()` (Task 14).
  - `callable $monthBounds`, `callable $toUnicode` (Task 13's contracts, unchanged)
- Produces:
  - `new FtpSqlResolver(Db $db, BatchLoader $loader, callable $customerRef)`
  - `FtpSqlResolver::map(): array` — `Customer.ftpUsers`, `Customer.sqlDatabases`,
    `Customer.sqlUsers`, `FtpUser.customer`, `SqlDatabase.customer`,
    `SqlDatabase.users`, `SqlUser.databases`
  - `FtpSqlResolver::shapeFtp(array $row): array`, `::shapeDatabase(array $row): array`,
    `::shapeSqlUser(array $row): array` — **static**
  - `FtpSqlResolver::ftpReference(string $userid): SyncPromise`,
    `::databaseReference(int $sqldId): SyncPromise`, `::sqlUserReference(int $sqluId): SyncPromise`
  - `new ResellerResolver(Db $db, BatchLoader $loader, bool $apiAccessByDefault, callable $monthBounds, callable $toUnicode, callable $customerRef, callable $customerRefs, callable $ipsRef)`
  - `ResellerResolver::map(): array` — the ten `Reseller.*`/`HostingPlan.*`
    entries and the three `Viewer.*` entries
  - `ResellerResolver::SELECT` — the joined `admin`/`reseller_props` select list
  - `ResellerResolver::shape(array $joinedRow, callable $toUnicode): array` — **static**
  - `ResellerResolver::reference(int $adminId): SyncPromise`,
    `::references(array $adminIds): SyncPromise`
  - `ResellerResolver::planShape(array $planRow): array` — **static**
  - `ResellerResolver::planReference(int $planId): SyncPromise`
  - `ResellerResolver::requireReseller($context, int $resellerId): Identity` —
    **static**; throws `ApiException(FORBIDDEN)` for a caller who is neither
    that reseller nor an administrator
  - `ResellerResolver::customerFilter(Db $db, array $filter): array` —
    **static**; `array(string $sqlFragment, array $bind)` for `CustomerFilter`.
    Task 16's `Query.customers` uses the same builder, so the two lists cannot
    disagree about what a filter means.

- [ ] **Step 1: Write the failing `FtpSqlResolver` unit test**

`test/unit/Resolver/FtpSqlResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class FtpSqlResolverTest extends TestCase
{
    public function testAnFtpUsersIdentifierCarriesItsStringPrimaryKey(): void
    {
        // Decision D2. ftp_users' primary key is userid varchar(255); encoding
        // it as an integer would turn every FTP login into FtpUser:0.
        $shape = FtpSqlResolver::shapeFtp(array(
            'userid'   => 'shop@sgwtcustomer.test',
            'admin_id' => 7,
            'homedir'  => '/var/www/virtual/sgwtcustomer.test',
            'status'   => 'ok'
        ));

        self::assertSame(
            GlobalId::encodeKey(NodeType::FTP_USER, 'shop@sgwtcustomer.test'),
            $shape['id']
        );
        self::assertSame('shop@sgwtcustomer.test', $shape['username']);
        self::assertSame('/var/www/virtual/sgwtcustomer.test', $shape['homeDirectory']);
        self::assertSame(7, $shape['__ownerId']);
        self::assertSame('OK', $shape['provisioning']['state']);
    }

    public function testASqlDatabaseCarriesItsOwnerForTheCustomerEdge(): void
    {
        // sql_database has only a domain_id, so the loader joins `domain` to
        // get the owner. Without it, SqlDatabase.customer would be a query per
        // database.
        $shape = FtpSqlResolver::shapeDatabase(array(
            'sqld_id'         => 41,
            'domain_id'       => 12,
            'sqld_name'       => 'sgwt_shop',
            'domain_admin_id' => 7
        ));

        self::assertSame(GlobalId::encode(NodeType::SQL_DATABASE, 41), $shape['id']);
        self::assertSame('sgwt_shop', $shape['name']);
        self::assertSame(7, $shape['__ownerId']);
        self::assertSame(12, $shape['__domainId']);
    }

    public function testASqlUserCarriesItsNameAndTheHostItMayConnectFrom(): void
    {
        $shape = FtpSqlResolver::shapeSqlUser(array(
            'sqlu_id'   => 51,
            'sqld_id'   => 41,
            'sqlu_name' => 'sgwt_u1',
            'sqlu_host' => 'localhost'
        ));

        self::assertSame(GlobalId::encode(NodeType::SQL_USER, 51), $shape['id']);
        self::assertSame('sgwt_u1', $shape['name']);
        self::assertSame('localhost', $shape['host']);
        self::assertSame('sgwt_u1', $shape['__name']);
        self::assertSame(41, $shape['__sqldId']);
    }

    public function testNeitherSqlTypeHasProvisioning(): void
    {
        // Spec section 2.1: the panel creates these synchronously. A
        // provisioning key in the shape would be dead weight that a reader
        // would reasonably take for a schema field.
        self::assertArrayNotHasKey('provisioning', FtpSqlResolver::shapeDatabase(array(
            'sqld_id' => 41, 'domain_id' => 12, 'sqld_name' => 'x',
            'domain_admin_id' => 7
        )));
        self::assertArrayNotHasKey('provisioning', FtpSqlResolver::shapeSqlUser(array(
            'sqlu_id' => 51, 'sqld_id' => 41, 'sqlu_name' => 'u', 'sqlu_host' => '%'
        )));
    }

    public function testTheMapOwnsTheFtpAndSqlFieldsOfEveryTypeThatHasThem(): void
    {
        $resolver = new FtpSqlResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            static function (int $adminId) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        self::assertSame(array(
            'Customer.ftpUsers', 'Customer.sqlDatabases', 'Customer.sqlUsers',
            'FtpUser.customer', 'SqlDatabase.customer', 'SqlDatabase.users',
            'SqlUser.databases'
        ), $keys);
    }
}
```

- [ ] **Step 2: Write the failing `ResellerResolver` unit test**

`test/unit/Resolver/ResellerResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class ResellerResolverTest extends TestCase
{
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'admin_id'             => 5,
            'admin_name'           => 'sgwtreseller',
            'admin_status'         => 'ok',
            'domain_created'       => 1767225600,
            'fname'                => 'Test',
            'lname'                => 'Reseller',
            'gender'               => 'U',
            'firm'                 => 'Test Ltd',
            'street1'              => '1 Test Street',
            'street2'              => null,
            'city'                 => 'Testville',
            'state'                => 'Testshire',
            'zip'                  => '1234',
            'country'              => 'NZ',
            'email'                => 'sgwtreseller@example.test',
            'phone'                => '+64 3 000 0000',
            'fax'                  => null,
            'max_dmn_cnt'          => 20,   'current_dmn_cnt'      => 3,
            'max_sub_cnt'          => 100,  'current_sub_cnt'      => 4,
            'max_als_cnt'          => 50,   'current_als_cnt'      => 2,
            'max_mail_cnt'         => 0,    'current_mail_cnt'     => 9,
            'max_ftp_cnt'          => 40,   'current_ftp_cnt'      => 1,
            'max_sql_db_cnt'       => 30,   'current_sql_db_cnt'   => 1,
            'max_sql_user_cnt'     => 30,   'current_sql_user_cnt' => 1,
            'max_disk_amnt'        => 51200,
            'max_traff_amnt'       => 102400,
            'reseller_ips'         => '3;7;'
        ), $overrides);
    }

    private function identity(string $role, int $adminId): Identity
    {
        $types = array(
            'ADMIN' => 'admin', 'RESELLER' => 'reseller', 'CUSTOMER' => 'user'
        );

        return new Identity(
            $adminId, 'who', $types[$role], null, 'who@example.test', array(), 1
        );
    }

    private function context(string $role, int $adminId): array
    {
        return array('identity' => $this->identity($role, $adminId));
    }

    public function testTheResellerShapesFromTheJoinedRow(): void
    {
        $shape = ResellerResolver::shape($this->row(), static function (string $v) {
            return $v;
        });

        self::assertSame(GlobalId::encode(NodeType::RESELLER, 5), $shape['id']);
        self::assertSame('sgwtreseller', $shape['username']);
        self::assertSame('2026-01-01T00:00:00Z', $shape['createdAt']);
        self::assertSame('OK', $shape['provisioning']['state']);
        self::assertSame('Test', $shape['contact']['firstName']);
    }

    public function testTheIpListIsSemicolonTerminatedNotSemicolonSeparated(): void
    {
        // reseller_props.reseller_ips is written as "3;7;" - with a trailing
        // separator. explode() alone yields an empty final element, which
        // becomes ip_id 0 and a null inside a non-null list.
        $shape = ResellerResolver::shape($this->row(), static function (string $v) {
            return $v;
        });

        self::assertSame(array(3, 7), $shape['__ipIds']);
    }

    public function testAResellerWithNoIpsHasAnEmptyListNotAZero(): void
    {
        foreach (array('', ';', null) as $stored) {
            $shape = ResellerResolver::shape(
                $this->row(array('reseller_ips' => $stored)),
                static function (string $v) { return $v; }
            );

            self::assertSame(array(), $shape['__ipIds']);
        }
    }

    public function testResellerQuotasHaveNoWithheldState(): void
    {
        // Spec section 7.8: "Unlike a customer's, these have no withheld
        // state: 0 means unlimited." max_mail_cnt is 0 in the row above.
        $quotas = ResellerResolver::quotas($this->row());

        self::assertSame(
            array('enabled' => true, 'limit' => 20, 'used' => 3, 'remaining' => 17),
            $quotas['customers']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 9, 'remaining' => null),
            $quotas['mailAccounts']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 100, 'used' => 4, 'remaining' => 96),
            $quotas['subdomains']
        );
        self::assertSame(array(
            'customers', 'subdomains', 'domainAliases', 'mailAccounts',
            'ftpUsers', 'sqlDatabases', 'sqlUsers'
        ), array_keys($quotas));
    }

    public function testAnAdministratorMayReadAnyResellersPrivateFields(): void
    {
        self::assertSame(1, ResellerResolver::requireReseller(
            $this->context('ADMIN', 1), 5
        )->getAdminId());
    }

    public function testAResellerMayReadTheirOwnPrivateFields(): void
    {
        self::assertSame(5, ResellerResolver::requireReseller(
            $this->context('RESELLER', 5), 5
        )->getAdminId());
    }

    public function testAnotherResellerMayNot(): void
    {
        try {
            ResellerResolver::requireReseller($this->context('RESELLER', 6), 5);
            self::fail('a reseller must not read another reseller');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }

    public function testACustomerMayNotReadTheirOwnResellersPrivateFields(): void
    {
        // The SDL says it in its own description: a customer may read id,
        // username and contact - and nothing else. Quotas, storage, IPs, API
        // access and hosting plans are the reseller's business.
        try {
            ResellerResolver::requireReseller($this->context('CUSTOMER', 7), 5);
            self::fail('a customer must not read their reseller private fields');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }

    public function testAHostingPlanDecodesItsPositionalProperties(): void
    {
        $plan = ResellerResolver::planShape(array(
            'id'          => 61,
            'reseller_id' => 5,
            'name'        => 'sgwt plan',
            'description' => 'A plan for the tests.',
            'status'      => 1,
            'props'       => '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
                . 'yes;no;no;yes;10;10;30;60;128;_no_;_yes_;104857600'
        ));

        self::assertSame(GlobalId::encode(NodeType::HOSTING_PLAN, 61), $plan['id']);
        self::assertSame('sgwt plan', $plan['name']);
        self::assertTrue($plan['available']);
        self::assertSame(5, $plan['__resellerId']);

        self::assertSame(
            array('enabled' => true, 'limit' => 10), $plan['quotas']['subdomains']
        );
        // 0 is unlimited and -1 is withheld, exactly as for a customer.
        self::assertSame(
            array('enabled' => true, 'limit' => null), $plan['quotas']['mailAccounts']
        );
        self::assertSame(
            array('enabled' => false, 'limit' => 0), $plan['quotas']['ftpUsers']
        );

        // Decision D3: disk and traffic are stored in MiB, mailQuota in bytes.
        self::assertSame('5368709120', $plan['storage']['disk']);
        self::assertSame('10737418240', $plan['storage']['traffic']);
        self::assertSame('104857600', $plan['storage']['mailQuota']);

        self::assertTrue($plan['features']['php']);
        self::assertFalse($plan['features']['customDns']);
        self::assertSame(array('DOMAIN', 'SQL'), $plan['features']['backup']);
    }

    public function testAPlanWithUnparseablePropsIsAnInternalErrorNotAFatal(): void
    {
        // A 26th field would shift every reader by one, which is how a
        // customer ends up with somebody else's limits. PlanProps raises; this
        // asserts the raise becomes a structured error.
        try {
            ResellerResolver::planShape(array(
                'id' => 61, 'reseller_id' => 5, 'name' => 'x', 'description' => null,
                'status' => 1, 'props' => 'nonsense'
            ));
            self::fail('an unparseable props string must raise an ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::INTERNAL, $e->getErrorCode());
            self::assertSame(61, $e->getExtensions()['hostingPlanId']);
        }
    }

    public function testAPlanWithStatusZeroIsNotAvailable(): void
    {
        $plan = ResellerResolver::planShape(array(
            'id' => 61, 'reseller_id' => 5, 'name' => 'x', 'description' => null,
            'status' => 0,
            'props' => '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
                . 'yes;no;no;yes;10;10;30;60;128;_no_;_yes_;104857600'
        ));

        self::assertFalse($plan['available']);
    }

    public function testTheMapOwnsTheResellerPlanAndViewerFields(): void
    {
        $resolver = new ResellerResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            true,
            static function () { return array(0, 0); },
            static function (string $v) { return $v; },
            static function (int $adminId) { return null; },
            static function (array $adminIds) { return null; },
            static function (array $ipIds) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        self::assertSame(array(
            'HostingPlan.features', 'HostingPlan.quotas', 'HostingPlan.reseller',
            'HostingPlan.storage', 'Reseller.apiAccess', 'Reseller.customers',
            'Reseller.hostingPlans', 'Reseller.ipAddresses', 'Reseller.quotas',
            'Reseller.storage', 'Viewer.contact', 'Viewer.customer',
            'Viewer.reseller'
        ), $keys);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
tools/test.sh
```

Expected: FAIL — `iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver` and
`iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver` not found. If either
test errors on something else — a missing `PlanProps::ALLOWANCES`, a missing
`ProvisioningFilter` — an earlier task did not land and this one cannot start.

- [ ] **Step 4: Write `Resolver/FtpSqlResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * FtpUser, SqlDatabase and SqlUser - spec section 7.7.
 *
 * Two things here differ from every other type in the schema. ftp_users is
 * keyed by a string (decision D2), so its identifiers go through
 * GlobalId::encodeKey(); and neither SQL type is Provisioned, because the
 * panel creates both synchronously and there is no status column to report.
 */
final class FtpSqlResolver
{
    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    public function __construct(Db $db, BatchLoader $loader, callable $customerRef)
    {
        $this->db = $db;
        $this->loader = $loader;
        $this->customerRef = $customerRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Customer.ftpUsers'     => array($this, 'resolveCustomerFtpUsers'),
            'Customer.sqlDatabases' => array($this, 'resolveCustomerDatabases'),
            'Customer.sqlUsers'     => array($this, 'resolveCustomerSqlUsers'),
            'FtpUser.customer'      => array($this, 'resolveOwner'),
            'SqlDatabase.customer'  => array($this, 'resolveOwner'),
            'SqlDatabase.users'     => array($this, 'resolveDatabaseUsers'),
            'SqlUser.databases'     => array($this, 'resolveSqlUserDatabases')
        );
    }

    /**
     * @param array<string, mixed> $row A row of ftp_users
     * @return array<string, mixed>
     */
    public static function shapeFtp(array $row): array
    {
        // NodeType::isStringKeyed(FTP_USER) is true, so TypeResolver::node()
        // reaches GlobalId::encodeKey() rather than encode().
        return TypeResolver::node(NodeType::FTP_USER, (string)$row['userid'], array(
            'username'      => (string)$row['userid'],
            'homeDirectory' => (string)$row['homedir'],
            'provisioning'  => TypeResolver::provisioning(
                $row['status'] === null ? null : (string)$row['status']
            ),
            '__ownerId'     => (int)$row['admin_id']
        ));
    }

    /**
     * @param array<string, mixed> $row A row of sql_database joined to domain
     * @return array<string, mixed>
     */
    public static function shapeDatabase(array $row): array
    {
        return TypeResolver::node(NodeType::SQL_DATABASE, (int)$row['sqld_id'], array(
            'name'       => (string)$row['sqld_name'],
            '__domainId' => (int)$row['domain_id'],
            // Carried from the join, so that SqlDatabase.customer is not a
            // query per database.
            '__ownerId'  => (int)$row['domain_admin_id']
        ));
    }

    /**
     * @param array<string, mixed> $row A row of sql_user
     * @return array<string, mixed>
     */
    public static function shapeSqlUser(array $row): array
    {
        return TypeResolver::node(NodeType::SQL_USER, (int)$row['sqlu_id'], array(
            'name'     => (string)$row['sqlu_name'],
            'host'     => (string)$row['sqlu_host'],
            '__sqldId' => (int)$row['sqld_id'],
            // SqlUser.databases is keyed by the name, not by this row: one
            // MySQL user granted on three databases is three sql_user rows,
            // and all three list the same three databases.
            '__name'   => (string)$row['sqlu_name']
        ));
    }

    public function ftpReference(string $userid): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'ftp:row',
            $userid,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT userid, admin_id, homedir, status FROM ftp_users'
                        . ' WHERE userid IN (' . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(string)$row['userid']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shapeFtp($row);
        });
    }

    public function databaseReference(int $sqldId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'sqldb:row',
            $sqldId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT sd.sqld_id, sd.domain_id, sd.sqld_name,
                            d.domain_admin_id
                        FROM sql_database AS sd
                        JOIN domain AS d ON d.domain_id = sd.domain_id
                        WHERE sd.sqld_id IN (' . $db->placeholders(count($keys)) . ')
                    ',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['sqld_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shapeDatabase($row);
        });
    }

    public function sqlUserReference(int $sqluId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'sqluser:row',
            $sqluId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT sqlu_id, sqld_id, sqlu_name, sqlu_host FROM sql_user'
                        . ' WHERE sqlu_id IN (' . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['sqlu_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shapeSqlUser($row);
        });
    }

    /**
     * FtpUser.customer and SqlDatabase.customer alike: both shapes carry
     * __ownerId, so both edges are the same call.
     */
    public function resolveOwner($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return call_user_func($this->customerRef, (int)$source['__ownerId']);
    }

    /**
     * Customer.ftpUsers - a paged connection. Decision D6 as in Task 14: one
     * query for the whole batch, sliced per parent afterwards.
     */
    public function resolveCustomerFtpUsers(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::FTP_READ);

        $page = TypeResolver::page($args['page'] ?? null);
        $db = $this->db;

        // Keyed by admin_id: ftp_users has no domain_id column at all.
        return $this->loader->keyed(
            'ftp:by-admin',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT userid, admin_id, homedir, status FROM ftp_users'
                        . ' WHERE admin_id IN (' . $db->placeholders(count($keys))
                        . ') ORDER BY userid',
                    $keys
                );
                $byAdmin = array();

                foreach ($keys as $key) {
                    $byAdmin[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byAdmin[(int)$row['admin_id']][] = $row;
                }

                return $byAdmin;
            }
        )->then(static function ($rows) use ($page) {
            $rows = $rows === null ? array() : $rows;
            $nodes = array();

            foreach (array_slice($rows, $page['offset'], $page['limit']) as $row) {
                $nodes[] = self::shapeFtp($row);
            }

            return array('totalCount' => count($rows), 'nodes' => $nodes);
        });
    }

    public function resolveCustomerDatabases(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'sqldb:by-domain',
            (int)$source['__domainId'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT sd.sqld_id, sd.domain_id, sd.sqld_name,
                            d.domain_admin_id
                        FROM sql_database AS sd
                        JOIN domain AS d ON d.domain_id = sd.domain_id
                        WHERE sd.domain_id IN (' . $db->placeholders(count($keys)) . ')
                        ORDER BY sd.sqld_name
                    ',
                    $keys
                );
                $byDomain = array();

                foreach ($keys as $key) {
                    // sqlDatabases is [SqlDatabase!]!: a customer with none
                    // needs an empty list, not a null that nulls the Customer.
                    $byDomain[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDomain[(int)$row['domain_id']][] = $row;
                }

                return $byDomain;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeDatabase($row);
            }

            return $shaped;
        });
    }

    public function resolveCustomerSqlUsers(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        // One node per grant, as the SDL says. Counts::sqlUsers() counts
        // DISTINCT sqlu_name instead, because that is the rule i-MSCP enforces
        // the quota against - so a user granted on two databases is one
        // against the quota and two nodes here. Both are deliberate.
        return $this->loader->keyed(
            'sqluser:by-domain',
            (int)$source['__domainId'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT su.sqlu_id, su.sqld_id, su.sqlu_name, su.sqlu_host,
                            sd.domain_id
                        FROM sql_user AS su
                        JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                        WHERE sd.domain_id IN (' . $db->placeholders(count($keys)) . ')
                        ORDER BY su.sqlu_name, su.sqlu_id
                    ',
                    $keys
                );
                $byDomain = array();

                foreach ($keys as $key) {
                    $byDomain[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDomain[(int)$row['domain_id']][] = $row;
                }

                return $byDomain;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeSqlUser($row);
            }

            return $shaped;
        });
    }

    public function resolveDatabaseUsers(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'sqluser:by-database',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT sqlu_id, sqld_id, sqlu_name, sqlu_host FROM sql_user'
                        . ' WHERE sqld_id IN (' . $db->placeholders(count($keys))
                        . ') ORDER BY sqlu_name, sqlu_id',
                    $keys
                );
                $byDatabase = array();

                foreach ($keys as $key) {
                    $byDatabase[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDatabase[(int)$row['sqld_id']][] = $row;
                }

                return $byDatabase;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeSqlUser($row);
            }

            return $shaped;
        });
    }

    public function resolveSqlUserDatabases(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        // Keyed by the user's name rather than by this grant row: a MySQL user
        // granted on three databases is three sql_user rows and one identity,
        // and the schema asks which databases that identity may reach.
        return $this->loader->keyed(
            'sqldb:by-user-name',
            (string)$source['__name'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT su.sqlu_name, sd.sqld_id, sd.domain_id,
                            sd.sqld_name, d.domain_admin_id
                        FROM sql_user AS su
                        JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                        JOIN domain AS d ON d.domain_id = sd.domain_id
                        WHERE su.sqlu_name IN (' . $db->placeholders(count($keys)) . ')
                        ORDER BY sd.sqld_name
                    ',
                    $keys
                );
                $byName = array();

                foreach ($keys as $key) {
                    $byName[(string)$key] = array();
                }

                foreach ($rows as $row) {
                    $byName[(string)$row['sqlu_name']][] = $row;
                }

                return $byName;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeDatabase($row);
            }

            return $shaped;
        });
    }
}
```

- [ ] **Step 5: Write `Resolver/ResellerResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use InvalidArgumentException;

/**
 * Reseller and HostingPlan - spec section 7.8 - and the three Viewer fields
 * the schema gained with them.
 *
 * Reseller is the one type in the schema with field-level authorisation. Its
 * own SDL description is the rule: a customer may read the identity of their
 * own reseller and nothing else, so id, username and contact are open to
 * anyone who can reach the object at all, and every other field goes through
 * requireReseller().
 */
final class ResellerResolver
{
    /**
     * admin joined to reseller_props.
     *
     * Inward, because a reseller with no props row cannot answer quotas,
     * storage or ipAddresses, all of which are non-null. The administrator
     * account has no props row either, which is right: an administrator is not
     * a Reseller and Query.reseller must not return one.
     */
    const SELECT = '
        SELECT
            a.admin_id, a.admin_name, a.admin_status, a.domain_created,
            a.fname, a.lname, a.gender, a.firm, a.street1, a.street2, a.city,
            a.state, a.zip, a.country, a.email, a.phone, a.fax,
            p.max_dmn_cnt, p.current_dmn_cnt,
            p.max_sub_cnt, p.current_sub_cnt,
            p.max_als_cnt, p.current_als_cnt,
            p.max_mail_cnt, p.current_mail_cnt,
            p.max_ftp_cnt, p.current_ftp_cnt,
            p.max_sql_db_cnt, p.current_sql_db_cnt,
            p.max_sql_user_cnt, p.current_sql_user_cnt,
            p.max_disk_amnt, p.max_traff_amnt, p.reseller_ips
        FROM admin AS a
        JOIN reseller_props AS p ON p.reseller_id = a.admin_id
    ';

    /** GraphQL allowance name => the reseller_props limit and counter columns. */
    const ALLOWANCES = array(
        'customers'     => array('max_dmn_cnt', 'current_dmn_cnt'),
        'subdomains'    => array('max_sub_cnt', 'current_sub_cnt'),
        'domainAliases' => array('max_als_cnt', 'current_als_cnt'),
        'mailAccounts'  => array('max_mail_cnt', 'current_mail_cnt'),
        'ftpUsers'      => array('max_ftp_cnt', 'current_ftp_cnt'),
        'sqlDatabases'  => array('max_sql_db_cnt', 'current_sql_db_cnt'),
        'sqlUsers'      => array('max_sql_user_cnt', 'current_sql_user_cnt')
    );

    /** MiB to bytes. Decision D3. */
    const MIB = 1048576;

    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var bool */
    private $apiAccessByDefault;

    /** @var callable fn(): array{0: int, 1: int} */
    private $monthBounds;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    /** @var callable fn(array $adminIds): SyncPromise */
    private $customerRefs;

    /** @var callable fn(array $ipIds): SyncPromise */
    private $ipsRef;

    public function __construct(
        Db $db, BatchLoader $loader, bool $apiAccessByDefault,
        callable $monthBounds, callable $toUnicode, callable $customerRef,
        callable $customerRefs, callable $ipsRef
    ) {
        $this->db = $db;
        $this->loader = $loader;
        $this->apiAccessByDefault = $apiAccessByDefault;
        $this->monthBounds = $monthBounds;
        $this->toUnicode = $toUnicode;
        $this->customerRef = $customerRef;
        $this->customerRefs = $customerRefs;
        $this->ipsRef = $ipsRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Reseller.quotas'       => array($this, 'resolveQuotas'),
            'Reseller.storage'      => array($this, 'resolveStorage'),
            'Reseller.ipAddresses'  => array($this, 'resolveIpAddresses'),
            'Reseller.apiAccess'    => array($this, 'resolveApiAccess'),
            'Reseller.customers'    => array($this, 'resolveCustomers'),
            'Reseller.hostingPlans' => array($this, 'resolveHostingPlans'),
            'HostingPlan.reseller'  => array($this, 'resolvePlanReseller'),
            'HostingPlan.quotas'    => array($this, 'resolvePlanQuotas'),
            'HostingPlan.storage'   => array($this, 'resolvePlanStorage'),
            'HostingPlan.features'  => array($this, 'resolvePlanFeatures'),
            'Viewer.contact'        => array($this, 'resolveViewerContact'),
            'Viewer.customer'       => array($this, 'resolveViewerCustomer'),
            'Viewer.reseller'       => array($this, 'resolveViewerReseller')
        );
    }

    /**
     * @param array<string, mixed> $row A row of self::SELECT
     * @return array<string, mixed>
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        return TypeResolver::node(NodeType::RESELLER, (int)$row['admin_id'], array(
            'username'     => (string)$row['admin_name'],
            'contact'      => TypeResolver::contact($row, $toUnicode),
            'createdAt'    => TypeResolver::dateTime($row['domain_created']),
            'provisioning' => TypeResolver::provisioning(
                $row['admin_status'] === null ? null : (string)$row['admin_status']
            ),
            '__ipIds'      => self::ipIds($row['reseller_ips'] ?? null),
            '__row'        => $row
        ));
    }

    /**
     * ResellerQuotas, straight off the row.
     *
     * `used` is i-MSCP's own maintained counter - the number the panel's own
     * reseller pages show - so that the API and the panel cannot disagree
     * about how much of an allowance has been sold. Spec section 7.8: these
     * have no withheld state, so 0 is unlimited and nothing is ever disabled.
     *
     * @param array<string, mixed> $row
     * @return array<string, array>
     */
    public static function quotas(array $row): array
    {
        $quotas = array();

        foreach (self::ALLOWANCES as $name => $columns) {
            $quotas[$name] = Quota::fromResellerLimit(
                (int)$row[$columns[0]], (int)$row[$columns[1]]
            )->toArray();
        }

        return $quotas;
    }

    /**
     * @param array<string, mixed> $row A row of hosting_plans
     * @return array<string, mixed>
     * @throws ApiException when props does not parse
     */
    public static function planShape(array $row): array
    {
        try {
            $props = PlanProps::parse((string)$row['props']);
        } catch (InvalidArgumentException $e) {
            // Uncaught this is a 500 with no indication of which plan is
            // malformed, and a malformed plan is exactly the failure spec
            // section 20 lists as risk 4.
            throw new ApiException(
                ErrorCode::INTERNAL,
                'This hosting plan has properties the API cannot read.',
                array('hostingPlanId' => (int)$row['id']),
                $e
            );
        }

        $quotas = array();

        foreach (array_keys(PlanProps::ALLOWANCES) as $name) {
            $quotas[$name] = $props->allowance($name);
        }

        $storage = $props->storage();
        $description = $row['description'] === null
            ? '' : (string)$row['description'];

        return TypeResolver::node(NodeType::HOSTING_PLAN, (int)$row['id'], array(
            'name'         => (string)$row['name'],
            'description'  => $description === '' ? null : $description,
            // hosting_plans.status is 1 for a plan a reseller may sell.
            'available'    => (bool)(int)$row['status'],
            'quotas'       => $quotas,
            'storage'      => array(
                'disk'      => TypeResolver::bigInt($storage['disk']),
                'traffic'   => TypeResolver::bigInt($storage['traffic']),
                'mailQuota' => TypeResolver::bigInt($storage['mailQuota'])
            ),
            'features'     => $props->features(),
            '__resellerId' => (int)$row['reseller_id']
        ));
    }

    /**
     * The rule the Reseller type's own SDL description states.
     *
     * @param mixed $context
     * @throws ApiException FORBIDDEN
     */
    public static function requireReseller($context, int $resellerId): Identity
    {
        $identity = TypeResolver::identity($context);

        if ($identity->getRole() === Identity::ROLE_ADMIN) {
            return $identity;
        }

        if ($identity->getRole() === Identity::ROLE_RESELLER
            && $identity->getAdminId() === $resellerId
        ) {
            return $identity;
        }

        // Not NOT_FOUND: the object is reachable - a customer may read their
        // reseller's identity - and it is this field that is refused. Spec
        // section 6.3's rule is about objects the caller cannot reach at all.
        throw new ApiException(
            ErrorCode::FORBIDDEN,
            'Only this reseller or an administrator may read this field.'
        );
    }

    /**
     * CustomerFilter as a SQL fragment over `a` (admin) and `d` (domain).
     *
     * Public and static because Query.customers (Task 16) builds the same
     * list from the other direction, and two copies of a filter is two
     * definitions of what `state: PENDING` means.
     *
     * @param array<string, mixed> $filter
     * @return array{0: string, 1: array}
     */
    public static function customerFilter(Db $db, array $filter): array
    {
        $sql = '';
        $bind = array();

        if (isset($filter['username']) && $filter['username'] !== '') {
            $sql .= ' AND a.admin_name LIKE ?';
            $bind[] = '%' . str_replace(
                array('\\', '%', '_'), array('\\\\', '\\%', '\\_'),
                (string)$filter['username']
            ) . '%';
        }

        if (isset($filter['domainName']) && $filter['domainName'] !== '') {
            // Exact, not substring: a domain name is an identifier, and a
            // client asking for one wants that one.
            $sql .= ' AND d.domain_name = ?';
            $bind[] = (string)$filter['domainName'];
        }

        list($stateSql, $stateBind) = ProvisioningFilter::clause(
            'a.admin_status', $filter['state'] ?? null
        );

        return array($sql . $stateSql, array_merge($bind, $stateBind));
    }

    public function reference(int $adminId): SyncPromise
    {
        $toUnicode = $this->toUnicode;

        return $this->row($adminId)->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    /**
     * @param int[] $adminIds
     */
    public function references(array $adminIds): SyncPromise
    {
        $deferreds = array();

        foreach ($adminIds as $adminId) {
            $deferreds[] = $this->row((int)$adminId);
        }

        $toUnicode = $this->toUnicode;

        return new Deferred(static function () use ($deferreds, $toUnicode) {
            $resellers = array();

            foreach ($deferreds as $deferred) {
                if ($deferred->result !== null) {
                    $resellers[] = self::shape($deferred->result, $toUnicode);
                }
            }

            return $resellers;
        });
    }

    public function planReference(int $planId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'plan:row',
            $planId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT id, reseller_id, name, description, props, status'
                        . ' FROM hosting_plans WHERE id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::planShape($row);
        });
    }

    public function resolveQuotas($source, array $args, $context, ResolveInfo $info): array
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        return self::quotas($source['__row']);
    }

    public function resolveStorage($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        $row = $source['__row'];
        $resellerId = (int)$source['__key'];
        $disk = $this->diskOfCustomers($resellerId);
        $traffic = $this->trafficOfCustomers($resellerId);

        return new Deferred(static function () use ($row, $disk, $traffic) {
            $sums = $disk->result === null
                ? array('used' => 0, 'files' => 0, 'mail' => 0, 'sql' => 0)
                : $disk->result;

            return array(
                'diskLimit'    => self::mibToBytes($row['max_disk_amnt']),
                // Summed rather than read from reseller_props.current_disk_amnt:
                // the schema wants the file/mail/SQL split beside the total, no
                // counter carries it, and taking the total from one source and
                // the parts from another would let them disagree.
                'diskUsed'     => TypeResolver::bigInt($sums['used']),
                'diskFiles'    => TypeResolver::bigInt($sums['files']),
                'diskMail'     => TypeResolver::bigInt($sums['mail']),
                'diskSql'      => TypeResolver::bigInt($sums['sql']),
                'trafficLimit' => self::mibToBytes($row['max_traff_amnt']),
                'trafficUsed'  => TypeResolver::bigInt(
                    $traffic->result === null ? 0 : $traffic->result
                ),
                // A reseller has no default per-mailbox quota; that belongs to
                // a hosting plan or to a customer.
                'mailQuota'    => null
            );
        });
    }

    public function resolveIpAddresses($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        return call_user_func($this->ipsRef, $source['__ipIds']);
    }

    public function resolveApiAccess($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        $db = $this->db;
        $byDefault = $this->apiAccessByDefault;

        return $this->loader->keyed(
            'reseller:api-perm',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT admin_id, allowed FROM api_perm WHERE admin_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = (bool)$row['allowed'];
                }

                return $byId;
            }
        )->then(static function ($allowed) use ($byDefault) {
            return $allowed === null ? $byDefault : $allowed;
        });
    }

    /**
     * Reseller.customers.
     *
     * The SDL's own words: "The customers of this reseller that the caller may
     * reach. A customer asking sees only themselves." So this is the one
     * Reseller field a customer may select, and what they get back is a
     * connection of exactly one.
     */
    public function resolveCustomers($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::CUSTOMERS_READ);
        $resellerId = (int)$source['__key'];
        $page = TypeResolver::page($args['page'] ?? null);
        $customerRefs = $this->customerRefs;

        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            // Themselves, and only if this really is their reseller - so that
            // a customer cannot use another reseller's object to confirm their
            // own identity against it.
            $ids = $identity->getCreatedBy() === $resellerId
                ? array($identity->getAdminId()) : array();
            $total = count($ids);

            return call_user_func($customerRefs, $ids)->then(
                static function ($nodes) use ($total) {
                    return array(
                        'totalCount' => $total,
                        'nodes'      => $nodes === null ? array() : $nodes
                    );
                }
            );
        }

        self::requireReseller($context, $resellerId);

        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:customer-ids:' . md5(serialize($filter)),
            $resellerId,
            static function (array $keys) use ($db, $filter) {
                list($filterSql, $filterBind) = self::customerFilter($db, $filter);
                $rows = $db->rows(
                    '
                        SELECT a.admin_id, a.created_by
                        FROM admin AS a
                        JOIN domain AS d ON d.domain_admin_id = a.admin_id
                        WHERE a.admin_type = ? AND a.created_by IN ('
                            . $db->placeholders(count($keys)) . ')' . $filterSql . '
                        ORDER BY a.admin_name
                    ',
                    array_merge(array('user'), $keys, $filterBind)
                );
                $byReseller = array();

                foreach ($keys as $key) {
                    $byReseller[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byReseller[(int)$row['created_by']][] = (int)$row['admin_id'];
                }

                return $byReseller;
            }
        )->then(static function ($ids) use ($page, $customerRefs) {
            $ids = $ids === null ? array() : $ids;
            // Decision D6: the page is applied here rather than in SQL, so
            // that every reseller in the batch comes out of one query.
            $wanted = array_slice($ids, $page['offset'], $page['limit']);
            $total = count($ids);

            // Returning a promise from a then() callback is legal and is what
            // makes the second level batch: SyncPromise::resolve() adopts any
            // thenable it is handed, so the connection is not built until the
            // customer rows have been loaded.
            return call_user_func($customerRefs, $wanted)->then(
                static function ($nodes) use ($total) {
                    return array(
                        'totalCount' => $total,
                        'nodes'      => $nodes === null ? array() : $nodes
                    );
                }
            );
        });
    }

    public function resolveHostingPlans($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'plan:by-reseller',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT id, reseller_id, name, description, props, status'
                        . ' FROM hosting_plans WHERE reseller_id IN ('
                        . $db->placeholders(count($keys)) . ') ORDER BY name',
                    $keys
                );
                $byReseller = array();

                foreach ($keys as $key) {
                    $byReseller[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byReseller[(int)$row['reseller_id']][] = $row;
                }

                return $byReseller;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::planShape($row);
            }

            return $shaped;
        });
    }

    public function resolvePlanReseller($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        return $this->reference((int)$source['__resellerId']);
    }

    public function resolvePlanQuotas($source, array $args, $context, ResolveInfo $info): array
    {
        return $source['quotas'];
    }

    public function resolvePlanStorage($source, array $args, $context, ResolveInfo $info): array
    {
        return $source['storage'];
    }

    public function resolvePlanFeatures($source, array $args, $context, ResolveInfo $info): array
    {
        return $source['features'];
    }

    public function resolveViewerContact($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $identity = TypeResolver::identity($context);
        $db = $this->db;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'admin:contact',
            $identity->getAdminId(),
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT admin_id, fname, lname, gender, firm, street1,
                            street2, city, state, zip, country, email, phone, fax
                        FROM admin WHERE admin_id IN ('
                            . $db->placeholders(count($keys)) . ')
                    ',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) use ($toUnicode) {
            // Viewer.contact is ContactDetails! - non-null - and every field
            // inside it is nullable, so an account row that has gone missing
            // is an empty card rather than a nulled Viewer.
            return TypeResolver::contact(
                $row === null ? array() : $row, $toUnicode
            );
        });
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveViewerCustomer($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::identity($context);

        if ($identity->getRole() !== Identity::ROLE_CUSTOMER) {
            // Nullable in the SDL: "Set when role is CUSTOMER."
            return null;
        }

        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return call_user_func($this->customerRef, $identity->getAdminId());
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveViewerReseller($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::identity($context);

        if ($identity->getRole() !== Identity::ROLE_RESELLER) {
            return null;
        }

        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return $this->reference($identity->getAdminId());
    }

    private function row(int $adminId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:row',
            $adminId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    self::SELECT . ' WHERE a.admin_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = $row;
                }

                return $byId;
            }
        );
    }

    private function diskOfCustomers(int $resellerId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:disk',
            $resellerId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT a.created_by AS k,
                            IFNULL(SUM(d.domain_disk_usage), 0) AS used,
                            IFNULL(SUM(d.domain_disk_file), 0) AS files,
                            IFNULL(SUM(d.domain_disk_mail), 0) AS mail,
                            IFNULL(SUM(d.domain_disk_sql), 0) AS sqldisk
                        FROM admin AS a
                        JOIN domain AS d ON d.domain_admin_id = a.admin_id
                        WHERE a.created_by IN ('
                            . $db->placeholders(count($keys)) . ')
                        GROUP BY a.created_by
                    ',
                    $keys
                );
                $byReseller = array();

                foreach ($rows as $row) {
                    $byReseller[(int)$row['k']] = array(
                        'used'  => (int)$row['used'],
                        'files' => (int)$row['files'],
                        'mail'  => (int)$row['mail'],
                        'sql'   => (int)$row['sqldisk']
                    );
                }

                return $byReseller;
            }
        );
    }

    private function trafficOfCustomers(int $resellerId): SyncPromise
    {
        $db = $this->db;
        $bounds = call_user_func($this->monthBounds);

        // A second query rather than a second set of columns on the first:
        // joining domain_traffic there would multiply every disk sum by the
        // number of accounting rows.
        return $this->loader->keyed(
            'reseller:traffic',
            $resellerId,
            static function (array $keys) use ($db, $bounds) {
                $rows = $db->rows(
                    '
                        SELECT a.created_by AS k,
                            IFNULL(SUM(t.dtraff_web + t.dtraff_ftp + t.dtraff_mail
                                + t.dtraff_pop), 0) AS n
                        FROM admin AS a
                        JOIN domain AS d ON d.domain_admin_id = a.admin_id
                        JOIN domain_traffic AS t ON t.domain_id = d.domain_id
                        WHERE a.created_by IN ('
                            . $db->placeholders(count($keys)) . ')
                            AND t.dtraff_time BETWEEN ? AND ?
                        GROUP BY a.created_by
                    ',
                    array_merge($keys, array($bounds[0], $bounds[1]))
                );
                $byReseller = array();

                foreach ($rows as $row) {
                    $byReseller[(int)$row['k']] = (int)$row['n'];
                }

                return $byReseller;
            }
        );
    }

    /**
     * reseller_props.reseller_ips is a semicolon-TERMINATED list: "3;7;".
     *
     * explode() alone leaves an empty final element, which casts to ip_id 0
     * and becomes a null inside a non-null list.
     *
     * @param mixed $stored
     * @return int[]
     */
    private static function ipIds($stored): array
    {
        if ($stored === null || $stored === '') {
            return array();
        }

        $ids = array();

        foreach (explode(';', (string)$stored) as $id) {
            $id = trim($id);

            if ($id !== '' && (int)$id > 0) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $mib
     */
    private static function mibToBytes($mib): ?string
    {
        return (int)$mib <= 0 ? null : TypeResolver::bigInt((int)$mib * self::MIB);
    }
}
```

- [ ] **Step 6: Write the failing integration tests**

`test/integration/FtpSqlResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class FtpSqlResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var FtpSqlResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new FtpSqlResolver(
            $this->db,
            new BatchLoader($this->db),
            static function (int $adminId) {
                return new Deferred(static function () use ($adminId) {
                    return array('__tag' => NodeType::CUSTOMER, '__key' => $adminId);
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        return array('identity' => $this->fixture->identity('customer'));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    private function customer(): array
    {
        return array(
            '__key'      => $this->fixture->customerId(),
            '__domainId' => $this->fixture->domainId()
        );
    }

    public function testTheFtpConnectionCarriesTheStringKeyedUser(): void
    {
        $connection = $this->value($this->resolver->resolveCustomerFtpUsers(
            $this->customer(), array(), $this->context(), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            $this->fixture->ftpUserId(), $connection['nodes'][0]['username']
        );
        self::assertSame(
            GlobalId::encodeKey(NodeType::FTP_USER, $this->fixture->ftpUserId()),
            $connection['nodes'][0]['id']
        );
        self::assertSame(
            '/var/www/virtual/' . $this->fixture->domainName(),
            $connection['nodes'][0]['homeDirectory']
        );
    }

    public function testAnFtpUserResolvesBackToItsOwner(): void
    {
        $ftp = $this->value($this->resolver->ftpReference($this->fixture->ftpUserId()));
        $owner = $this->value($this->resolver->resolveOwner(
            $ftp, array(), $this->context(), $this->info()
        ));

        self::assertSame($this->fixture->customerId(), $owner['__key']);
    }

    public function testADatabaseCarriesItsOwnerWithoutASecondQuery(): void
    {
        // sql_database has no admin_id, so the owner comes from the join. If
        // it did not, SqlDatabase.customer would be a query per database.
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();
        $databases = null;

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info, &$databases) {
                $databases = $resolver->resolveCustomerDatabases(
                    $customer, array(), $context, $info
                );

                SyncPromise::runQueue();
            }
        );

        self::assertSame(1, $count);
        self::assertCount(1, $databases->result);
        self::assertSame('sgwt_shop', $databases->result[0]['name']);
        self::assertSame(
            $this->fixture->customerId(), $databases->result[0]['__ownerId']
        );
    }

    public function testADatabasesUsersAndAUsersDatabasesAgree(): void
    {
        $database = $this->value(
            $this->resolver->databaseReference($this->fixture->sqlDatabaseId())
        );
        $users = $this->value($this->resolver->resolveDatabaseUsers(
            $database, array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $users);
        self::assertSame('sgwt_u1', $users[0]['name']);
        self::assertSame('localhost', $users[0]['host']);

        $databases = $this->value($this->resolver->resolveSqlUserDatabases(
            $users[0], array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $databases);
        self::assertSame(
            $this->fixture->sqlDatabaseId(), $databases[0]['__key']
        );
    }

    public function testSixCustomersFtpSqlAndUserListsCostThreeQueries(): void
    {
        // One per rule, not one per customer.
        $resolver = $this->resolver;
        $customer = $this->customer();
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(
            function () use ($resolver, $customer, $context, $info) {
                for ($i = 0; $i < 6; $i++) {
                    $resolver->resolveCustomerFtpUsers($customer, array(), $context, $info);
                    $resolver->resolveCustomerDatabases($customer, array(), $context, $info);
                    $resolver->resolveCustomerSqlUsers($customer, array(), $context, $info);
                }

                SyncPromise::runQueue();
            }
        );

        self::assertSame(3, $count);
    }
}
```

`test/integration/ResellerResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class ResellerResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var ResellerResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->resolver = new ResellerResolver(
            $this->db,
            new BatchLoader($this->db),
            true,
            static function () { return array(0, 4102444800); },
            'decode_idna',
            static function (int $adminId) {
                return new Deferred(static function () use ($adminId) {
                    return array('__tag' => NodeType::CUSTOMER, '__key' => $adminId);
                });
            },
            static function (array $adminIds) {
                return new Deferred(static function () use ($adminIds) {
                    $nodes = array();

                    foreach ($adminIds as $adminId) {
                        $nodes[] = array(
                            '__tag' => NodeType::CUSTOMER, '__key' => (int)$adminId
                        );
                    }

                    return $nodes;
                });
            },
            static function (array $ipIds) {
                return new Deferred(static function () use ($ipIds) {
                    return $ipIds;
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(string $who): array
    {
        return array('identity' => $this->fixture->identity($who));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    private function reseller(): array
    {
        return $this->value($this->resolver->reference($this->fixture->resellerId()));
    }

    public function testTheResellerShapesFromAdminJoinedToItsProps(): void
    {
        $reseller = $this->reseller();

        self::assertSame('sgwtreseller', $reseller['username']);
        self::assertSame('2026-01-01T00:00:00Z', $reseller['createdAt']);
        self::assertSame('OK', $reseller['provisioning']['state']);
        self::assertSame(array($this->fixture->ipId()), $reseller['__ipIds']);
    }

    public function testAnAdministratorIsNotAReseller(): void
    {
        // The administrator has no reseller_props row, and the inward join is
        // what keeps Query.reseller from returning a Reseller with no quotas.
        self::assertNull($this->value(
            $this->resolver->reference($this->fixture->adminId())
        ));
    }

    public function testQuotasComeFromTheMaintainedCounters(): void
    {
        $quotas = $this->resolver->resolveQuotas(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        );

        // The fixture writes zeroed counters, which is what a freshly created
        // reseller has. The point of the assertion is the encoding, not the
        // number: max_mail_cnt is 0 and that means unlimited.
        self::assertSame(
            array('enabled' => true, 'limit' => 20, 'used' => 0, 'remaining' => 20),
            $quotas['customers']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 0, 'remaining' => null),
            $quotas['mailAccounts']
        );
    }

    public function testStorageIsSummedOverTheResellersOwnCustomers(): void
    {
        // Two customers, each with the fixture's domain figures - and the
        // other reseller's customer excluded, which is the assertion that
        // matters: a missing WHERE would bill this reseller for a stranger.
        $storage = $this->value($this->resolver->resolveStorage(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame('53687091200', $storage['diskLimit']);
        self::assertSame('2097152', $storage['diskUsed']);
        self::assertSame('1048576', $storage['diskFiles']);
        self::assertSame('524288', $storage['diskMail']);
        self::assertSame('524288', $storage['diskSql']);
        self::assertSame('107374182400', $storage['trafficLimit']);
        self::assertSame('0', $storage['trafficUsed']);
        self::assertNull($storage['mailQuota']);
    }

    public function testACustomerMayReadTheirResellersIdentityAndNothingElse(): void
    {
        $reseller = $this->reseller();

        // Identity: allowed, and it is already in the shape.
        self::assertSame('sgwtreseller', $reseller['username']);
        self::assertSame('Test', $reseller['contact']['firstName']);

        foreach (array('resolveQuotas', 'resolveStorage', 'resolveIpAddresses',
            'resolveApiAccess', 'resolveHostingPlans') as $method
        ) {
            try {
                $this->resolver->$method(
                    $reseller, array(), $this->context('customer'), $this->info()
                );
                self::fail($method . ' must refuse a customer');
            } catch (ApiException $e) {
                self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode(), $method);
            }
        }
    }

    public function testAnotherResellerIsRefusedTheSameFields(): void
    {
        try {
            $this->resolver->resolveQuotas(
                $this->reseller(), array(), $this->context('otherReseller'),
                $this->info()
            );
            self::fail('another reseller must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }

    public function testTheResellerSeesBothTheirCustomersAndNotTheStranger(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);

        $ids = array(
            $connection['nodes'][0]['__key'], $connection['nodes'][1]['__key']
        );
        sort($ids);
        $expected = array($this->fixture->customerId(), $this->fixture->siblingId());
        sort($expected);

        self::assertSame($expected, $ids);
    }

    public function testACustomerAskingForTheirResellersCustomersSeesOnlyThemselves(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            $this->reseller(), array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            $this->fixture->customerId(), $connection['nodes'][0]['__key']
        );
    }

    public function testACustomerAskingAnotherResellerSeesNobody(): void
    {
        // Not an error: the object is reachable and the answer is honestly
        // empty. An error here would let a customer probe which resellers
        // exist by watching which questions fail.
        $other = $this->value(
            $this->resolver->reference($this->fixture->otherResellerId())
        );
        $connection = $this->value($this->resolver->resolveCustomers(
            $other, array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(0, $connection['totalCount']);
        self::assertSame(array(), $connection['nodes']);
    }

    public function testFilteringCustomersByUsernameNarrowsTheList(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            $this->reseller(), array('filter' => array('username' => 'sibling')),
            $this->context('reseller'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            $this->fixture->siblingId(), $connection['nodes'][0]['__key']
        );
    }

    public function testTheHostingPlanDecodesItsProps(): void
    {
        $plans = $this->value($this->resolver->resolveHostingPlans(
            $this->reseller(), array(), $this->context('reseller'), $this->info()
        ));

        self::assertCount(1, $plans);
        self::assertSame('sgwt plan', $plans[0]['name']);
        self::assertTrue($plans[0]['available']);
        self::assertSame(
            array('enabled' => true, 'limit' => 10), $plans[0]['quotas']['subdomains']
        );
        self::assertSame(array('DOMAIN', 'SQL'), $plans[0]['features']['backup']);
    }

    public function testTheViewerOfAResellerCarriesTheResellerAndNoCustomer(): void
    {
        $context = $this->context('reseller');

        self::assertNull($this->resolver->resolveViewerCustomer(
            array(), array(), $context, $this->info()
        ));
        self::assertSame($this->fixture->resellerId(), $this->value(
            $this->resolver->resolveViewerReseller(array(), array(), $context, $this->info())
        )['__key']);
    }

    public function testTheViewerOfACustomerCarriesTheCustomerAndNoReseller(): void
    {
        $context = $this->context('customer');

        self::assertNull($this->resolver->resolveViewerReseller(
            array(), array(), $context, $this->info()
        ));
        self::assertSame($this->fixture->customerId(), $this->value(
            $this->resolver->resolveViewerCustomer(array(), array(), $context, $this->info())
        )['__key']);
    }

    public function testTheViewersContactComesFromTheAdminRow(): void
    {
        $contact = $this->value($this->resolver->resolveViewerContact(
            array(), array(), $this->context('customer'), $this->info()
        ));

        self::assertSame('Test', $contact['firstName']);
        self::assertSame('User', $contact['lastName']);
        self::assertSame('UNSPECIFIED', $contact['gender']);
        self::assertSame('Testville', $contact['city']);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
tools/test.sh
sh test/lint/all.sh
```

Expected, with Steps 4 and 5 in place: PASS — `FtpSqlResolverTest` 5 unit and
5 integration methods, `ResellerResolverTest` 12 unit and 14 integration
methods.

- [ ] **Step 8: Commit**

```bash
git add Resolver/FtpSqlResolver.php Resolver/ResellerResolver.php \
        test/unit/Resolver/FtpSqlResolverTest.php \
        test/unit/Resolver/ResellerResolverTest.php \
        test/integration/FtpSqlResolverTest.php \
        test/integration/ResellerResolverTest.php
git commit -m "Resolve FTP users, SQL objects, resellers and hosting plans

ftp_users is keyed by a string and the SQL tables are not provisioned, so
these three types are where the schema's uniform shape meets i-MSCP's least
uniform tables. A SQL user is one row per grant, which is what the schema
promises and what a client needs to address one, while the quota counts
distinct names because that is the rule the panel enforces - both deliberate,
and now both asserted.

Reseller is the only type in the schema with field-level authorisation: its
own description says a customer may read their reseller's identity and nothing
else, so that rule lives in one method every private field calls. Its
allowances come from i-MSCP's maintained counters, so the API cannot disagree
with the panel about what has been sold; its storage is summed instead,
because the schema wants the file, mail and SQL split beside the total and no
counter carries it - and taking the whole from one source and the parts from
another would let them contradict each other."
```

---

## Task 16: The root query, and the container that wires the read stack — `sonnet`

Spec §7.10 and §17. Six root fields, the composition that builds every resolver
in this plan, and the two schema tests spec §17 asks for that could not be
written until every map existed.

**`Query.node` and `Query.customer` both answer NOT_FOUND, and both come back
null.** The SDL says of `node`: *"Null when the caller cannot reach it."* The
[Global Constraints](#global-constraints) say unreachable objects answer
`NOT_FOUND`, never `FORBIDDEN`. Both hold at once, because both fields are
nullable: `OwnershipResolver::assertReachable()` throws
`ApiException(NOT_FOUND)`, graphql-php nulls the nullable field and puts the
error in `errors` with its code. A caller who cannot reach an object and a
caller naming one that does not exist get byte-identical responses, which is
the point of spec §6.3.

**The read stack has one cycle, and it is closed with closures.**
`Domain.customer` needs `CustomerResolver`; `Customer.domain` needs
`VirtualHostResolver`; `Customer.reseller` needs `ResellerResolver`, whose
`customers` needs `CustomerResolver` again. Constructor injection cannot
express that, which is why Tasks 12–15 all take closures for their cross-type
edges. `Container` is the only place that knows all of them, so it builds them
in one order and closes the cycle with `use (&$variable)` — the variable is
assigned before any closure is ever called, because nothing calls a resolver
during construction.

**`OwnershipResolver` is built from `Db`, not from the container's `$query`.**
Plan 1's `$query` is `exec_query()`, which returns a statement;
`OwnershipResolver` wants `fn(string $sql, array $bind): array` returning rows.
Handing it the wrong one would make every ownership check see zero rows and
answer `NOT_FOUND` for everything — a fail-closed bug, but a total one.

**`apiVersion` becomes `1.1.0`.** Spec §18: the number is the *schema's*, and
this plan adds types and fields within the same major version, which is a minor
bump. Plan 1's `ContainerTest` asserts `1.0.0` in one place and that assertion
is updated here — it is the one plan 1 test this plan changes, and it changes
because the thing it asserts genuinely changed.

**Files:**
- Create: `Resolver/QueryResolver.php`
- Modify: `Api/Container.php`
- Modify: `test/unit/Api/ContainerTest.php` — the `apiVersion` assertion only
- Create: `test/schema/ResolverCoverageTest.php`
- Create: `test/integration/QueryResolverTest.php`

**Interfaces:**
- Consumes:
  - Everything Tasks 10–15 produce, by the exact names in their Interfaces blocks
  - `new OwnershipResolver(callable $query)`, `::assertReachable(Identity, GlobalId): int`,
    `::mayReach(Identity, int): bool`, `::mayReachReseller(Identity, int): bool` (Task 1)
  - `GlobalId::decodeKey(string $encoded, ?string $expectedType = null): GlobalId`,
    `::getType()`, `::getKey()`, `::getId()` (Task 1)
  - `NodeType::isKnown(string $tag): bool`, the tag constants (Task 1)
  - `VirtualHosts::pendingFor(array $customerAdminIds): array` (Task 9)
  - `ResellerResolver::customerFilter(Db $db, array $filter): array` (Task 15)
  - `ProvisioningFilter::clause(string $column, ?string $state): array` (Task 14)
  - `Container::fromPlugin()`, `::forTesting()`, `::schemaFactory()`,
    `::handler()`, `::middleware()`, `::routeHandler()`, `::apiVersion()` (plan 1)
  - `new SchemaFactory(string $sdlPath, ?string $cacheDir, ResolverMap $resolvers, ?callable $resolveType = null)` (Task 11)
- Produces:
  - `new QueryResolver(Db $db, OwnershipResolver $ownership, VirtualHosts $vhosts, VirtualHostResolver $virtualHosts, CustomerResolver $customers, MailResolver $mail, DnsResolver $dns, FtpSqlResolver $ftpSql, ResellerResolver $resellers)`
  - `QueryResolver::map(): array` — `Query.node`, `Query.customer`,
    `Query.customers`, `Query.reseller`, `Query.resellers`, `Query.pending`
  - `QueryResolver::nodeReference(string $tag, $key): ?SyncPromise` — the one
    dispatch from a `NodeType` tag to the resolver that shapes it
  - `Db::detached(): Db` (Task 10) — used by `Container` when no handle is given
  - `Container::forTesting(string $pluginDir, array $config, callable $query, callable $accountLoader, callable $apiAccessChecker, ?Db $db = null, array $panelConfig = array())`
    — **additive**; the five-argument form keeps working and every plan 1 test
    keeps passing unchanged
  - `Container::resolverMaps(): array` — `class short name => map`, the maps
    before they are merged, so a test can assert they are disjoint
  - `Container::apiVersion(): string` — now `1.1.0`

- [ ] **Step 1: Write `Resolver/QueryResolver.php`**

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;
// ... licence header ...

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use InvalidArgumentException;

/**
 * The six root fields of spec section 7.10.
 *
 * Everything here begins the same way: work out what the caller is asking for,
 * ask OwnershipResolver whether they may have it, and only then read. Spec
 * section 6.3's rule - resolve ownership before anything else - is not a
 * convention in this class, it is the first statement of every method.
 */
final class QueryResolver
{
    /** @var Db */
    private $db;

    /** @var OwnershipResolver */
    private $ownership;

    /** @var VirtualHosts */
    private $vhosts;

    /** @var VirtualHostResolver */
    private $virtualHosts;

    /** @var CustomerResolver */
    private $customers;

    /** @var MailResolver */
    private $mail;

    /** @var DnsResolver */
    private $dns;

    /** @var FtpSqlResolver */
    private $ftpSql;

    /** @var ResellerResolver */
    private $resellers;

    public function __construct(
        Db $db, OwnershipResolver $ownership, VirtualHosts $vhosts,
        VirtualHostResolver $virtualHosts, CustomerResolver $customers,
        MailResolver $mail, DnsResolver $dns, FtpSqlResolver $ftpSql,
        ResellerResolver $resellers
    ) {
        $this->db = $db;
        $this->ownership = $ownership;
        $this->vhosts = $vhosts;
        $this->virtualHosts = $virtualHosts;
        $this->customers = $customers;
        $this->mail = $mail;
        $this->dns = $dns;
        $this->ftpSql = $ftpSql;
        $this->resellers = $resellers;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Query.node'      => array($this, 'resolveNode'),
            'Query.customer'  => array($this, 'resolveCustomer'),
            'Query.customers' => array($this, 'resolveCustomers'),
            'Query.reseller'  => array($this, 'resolveReseller'),
            'Query.resellers' => array($this, 'resolveResellers'),
            'Query.pending'   => array($this, 'resolvePending')
        );
    }

    /**
     * From a NodeType tag to the resolver that shapes that tag.
     *
     * The one place the mapping exists. Query.node and Query.pending both use
     * it, and a tag with no case here returns null rather than guessing.
     *
     * @param int|string $key
     */
    public function nodeReference(string $tag, $key): ?SyncPromise
    {
        switch ($tag) {
            case NodeType::CUSTOMER:
                return $this->customers->reference((int)$key);
            case NodeType::DOMAIN:
            case NodeType::SUBDOMAIN:
            case NodeType::ALIAS_SUBDOMAIN:
            case NodeType::DOMAIN_ALIAS:
                return $this->virtualHosts->reference($tag, $key);
            case NodeType::MAIL_ACCOUNT:
                return $this->mail->reference((int)$key);
            case NodeType::FTP_USER:
                return $this->ftpSql->ftpReference((string)$key);
            case NodeType::SQL_DATABASE:
                return $this->ftpSql->databaseReference((int)$key);
            case NodeType::SQL_USER:
                return $this->ftpSql->sqlUserReference((int)$key);
            case NodeType::DNS_RECORD:
                return $this->dns->reference((int)$key);
            case NodeType::RESELLER:
                return $this->resellers->reference((int)$key);
            case NodeType::HOSTING_PLAN:
                return $this->resellers->planReference((int)$key);
            case NodeType::IP_ADDRESS:
                return $this->dns->ipReference((int)$key);
            default:
                return null;
        }
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveNode($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::identity($context);
        $globalId = self::decode((string)$args['id']);

        // Ownership first, before the object is read at all. An identifier the
        // caller cannot reach and an identifier that names nothing produce the
        // same NOT_FOUND, which is what stops node() being an oracle for
        // "does this object exist".
        $this->ownership->assertReachable($identity, $globalId);

        return $this->nodeReference($globalId->getType(), $globalId->getKey());
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveCustomer($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::requireScope($context, Scope::CUSTOMERS_READ);
        $globalId = self::decode((string)$args['id'], NodeType::CUSTOMER);

        $this->ownership->assertReachable($identity, $globalId);

        return $this->customers->reference($globalId->getId());
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveReseller($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::requireScope($context, Scope::RESELLERS_READ);
        $globalId = self::decode((string)$args['id'], NodeType::RESELLER);

        // A customer may reach their own reseller's object; every private
        // field on it is refused separately by
        // ResellerResolver::requireReseller().
        $this->ownership->assertReachable($identity, $globalId);

        return $this->resellers->reference($globalId->getId());
    }

    public function resolveCustomers($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::CUSTOMERS_READ);
        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $page = TypeResolver::page($args['page'] ?? null);

        $sql = '
            SELECT a.admin_id
            FROM admin AS a
            JOIN domain AS d ON d.domain_admin_id = a.admin_id
            WHERE a.admin_type = ?
        ';
        $bind = array('user');

        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            // Spec section 7.10 and the SDL: a customer asking for customers
            // sees themselves. Not an error - the question is legitimate and
            // the honest answer is a list of one.
            $sql .= ' AND a.admin_id = ?';
            $bind[] = $identity->getAdminId();
        } elseif ($identity->getRole() === Identity::ROLE_RESELLER) {
            // filter.resellerId is documented "Administrators only; ignored
            // for a reseller, who only ever sees their own" - and it is
            // ignored here rather than rejected, because rejecting it would
            // make the field's behaviour depend on the caller's role in a way
            // a client cannot predict.
            $sql .= ' AND a.created_by = ?';
            $bind[] = $identity->getAdminId();
        } elseif (isset($filter['resellerId'])) {
            $reseller = self::decode((string)$filter['resellerId'], NodeType::RESELLER);
            $sql .= ' AND a.created_by = ?';
            $bind[] = $reseller->getId();
        }

        list($filterSql, $filterBind) = ResellerResolver::customerFilter($this->db, $filter);
        $sql .= $filterSql . ' ORDER BY a.admin_name';
        $bind = array_merge($bind, $filterBind);

        $ids = array();

        foreach ($this->db->rows($sql, $bind) as $row) {
            $ids[] = (int)$row['admin_id'];
        }

        $total = count($ids);
        // Decision D6 does not apply at the root: there is one list, so the
        // page could have been a LIMIT. It is applied here anyway so that
        // totalCount and the page come from the one query the ids cost.
        $wanted = array_slice($ids, $page['offset'], $page['limit']);

        return $this->customers->references($wanted)->then(
            static function ($nodes) use ($total) {
                return array(
                    'totalCount' => $total,
                    'nodes'      => $nodes === null ? array() : $nodes
                );
            }
        );
    }

    public function resolveResellers($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::RESELLERS_READ);
        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $page = TypeResolver::page($args['page'] ?? null);

        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            // A customer has no business enumerating resellers, and an empty
            // list is the honest answer to "which resellers may I see": their
            // own is reachable by identifier and through Viewer, not by
            // listing every reseller on the box.
            return new Deferred(static function () {
                return array('totalCount' => 0, 'nodes' => array());
            });
        }

        $sql = '
            SELECT a.admin_id
            FROM admin AS a
            JOIN reseller_props AS p ON p.reseller_id = a.admin_id
            WHERE a.admin_type = ?
        ';
        $bind = array('reseller');

        if ($identity->getRole() === Identity::ROLE_RESELLER) {
            $sql .= ' AND a.admin_id = ?';
            $bind[] = $identity->getAdminId();
        }

        if (isset($filter['username']) && $filter['username'] !== '') {
            $sql .= ' AND a.admin_name LIKE ?';
            $bind[] = '%' . str_replace(
                array('\\', '%', '_'), array('\\\\', '\\%', '\\_'),
                (string)$filter['username']
            ) . '%';
        }

        list($stateSql, $stateBind) = ProvisioningFilter::clause(
            'a.admin_status', $filter['state'] ?? null
        );
        $sql .= $stateSql . ' ORDER BY a.admin_name';
        $bind = array_merge($bind, $stateBind);

        $ids = array();

        foreach ($this->db->rows($sql, $bind) as $row) {
            $ids[] = (int)$row['admin_id'];
        }

        $total = count($ids);
        $wanted = array_slice($ids, $page['offset'], $page['limit']);

        return $this->resellers->references($wanted)->then(
            static function ($nodes) use ($total) {
                return array(
                    'totalCount' => $total,
                    'nodes'      => $nodes === null ? array() : $nodes
                );
            }
        );
    }

    /**
     * Query.pending - everything the caller owns that the backend has not
     * finished with (spec section 8.2).
     *
     * Eight queries whatever the number of customers, because
     * VirtualHosts::pendingFor() takes the whole set at once. A reseller with
     * two hundred customers pays the same as a customer with one.
     */
    public function resolvePending($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::ACCOUNT_READ);
        $adminIds = $this->pendingScope($identity);

        if ($adminIds === array()) {
            return new Deferred(static function () {
                // [Node!]! - an administrator of an empty box gets a list, not
                // a null.
                return array();
            });
        }

        $references = array();

        // Every reference is created here, before anything is awaited, so all
        // of them are in their buckets when the first one flushes. The
        // gathering below only decides the order results are collected in, not
        // when they are fetched.
        foreach ($this->vhosts->pendingFor($adminIds) as $pending) {
            $reference = $this->nodeReference($pending['tag'], $pending['key']);

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        $gathered = new Deferred(static function () {
            return array();
        });

        foreach ($references as $reference) {
            // Chained rather than collected by reading ->result: a promise
            // returned by then() is a child that the queue only reaches after
            // its parent has resolved, so a Deferred that read their results
            // directly would read a list of nulls. SyncPromise::resolve()
            // adopts a returned thenable, which is what makes this work.
            $gathered = $gathered->then(
                static function (array $nodes) use ($reference) {
                    return $reference->then(
                        static function ($node) use ($nodes) {
                            if ($node !== null) {
                                $nodes[] = $node;
                            }

                            return $nodes;
                        }
                    );
                }
            );
        }

        return $gathered;
    }

    /**
     * Whose unsettled objects this caller may see.
     *
     * @return int[] admin_ids of customers
     */
    private function pendingScope(Identity $identity): array
    {
        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            return array($identity->getAdminId());
        }

        $sql = "SELECT admin_id FROM admin WHERE admin_type = 'user'";
        $bind = array();

        if ($identity->getRole() === Identity::ROLE_RESELLER) {
            $sql .= ' AND created_by = ?';
            $bind[] = $identity->getAdminId();
        }

        $ids = array();

        foreach ($this->db->rows($sql, $bind) as $row) {
            $ids[] = (int)$row['admin_id'];
        }

        return $ids;
    }

    /**
     * @throws ApiException NOT_FOUND for anything that is not a usable identifier
     */
    private static function decode(string $encoded, ?string $expected = null): GlobalId
    {
        try {
            // decodeKey() rather than decode(): ftp_users' key is a string
            // (decision D2), and decode() would reject it. getId() still
            // refuses a non-numeric key, so a caller expecting an integer
            // cannot be handed a string one by accident.
            $globalId = GlobalId::decodeKey($encoded, $expected);
        } catch (\Exception $e) {
            // A malformed identifier is NOT_FOUND, not BAD_USER_INPUT: spec
            // section 6.3 wants an unreachable object and a nonexistent one to
            // be indistinguishable, and "this is not a valid id" is a third
            // answer a caller could use to tell them apart.
            throw self::notFound();
        }

        if (!NodeType::isKnown($globalId->getType())) {
            throw self::notFound();
        }

        return $globalId;
    }

    private static function notFound(): ApiException
    {
        return new ApiException(
            ErrorCode::NOT_FOUND, 'No such object, or it is not yours to read.'
        );
    }
}
```

- [ ] **Step 2: Rewire `Api/Container.php`**

Everything plan 1 built stays. Four things change: a `Db` handle arrives, the
read stack is built and memoised, `schemaFactory()` merges every map and passes
`TypeResolver::resolveType`, and `apiVersion()` becomes `1.1.0`.

Add the imports:

```php
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\FtpSqlResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\QueryResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
```

Add the properties:

```php
    /** @var Db */
    private $db;

    /** @var array The panel configuration, for CustomerFeatures. */
    private $panelConfig;

    /** @var bool */
    private $apiAccessByDefault;

    /** @var array<string, array<string, callable>>|null */
    private $maps;
```

Widen the private constructor and both factories. `forTesting()` gains two
optional parameters, so plan 1's five-argument calls keep working:

```php
    private function __construct(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, Db $db, array $panelConfig,
        bool $apiAccessByDefault
    ) {
        $this->pluginDir = $pluginDir;
        $this->config = $config;
        $this->query = $query;
        $this->accountLoader = $accountLoader;
        $this->apiAccessChecker = $apiAccessChecker;
        $this->db = $db;
        $this->panelConfig = $panelConfig;
        $this->apiAccessByDefault = $apiAccessByDefault;
    }

    public static function forTesting(
        string $pluginDir, array $config, callable $query, callable $accountLoader,
        callable $apiAccessChecker, ?Db $db = null, array $panelConfig = array()
    ): self {
        return new self(
            $pluginDir, $config, $query, $accountLoader, $apiAccessChecker,
            // A handle that throws on use rather than one that is null: the
            // unit suite builds the whole resolver map, and a resolver that
            // reached the database there should fail loudly rather than
            // silently work against whatever connection was lying about.
            $db ?? Db::detached(),
            $panelConfig,
            true
        );
    }
```

In `fromPlugin()`, change nothing that is already there. Its `new self(...)`
call currently passes five arguments — `$pluginDir`, `$plugin->getConfig()`,
the `exec_query()` closure, the `admin`-row account loader, and the
`customerHasApiAccess()` closure. Leave all five exactly as they are, comments
included, and append three more, in this order:

```php
            Db::fromPanel(),
            self::panelConfig(),
            $allowedByDefault
```

`$allowedByDefault` is the local `fromPlugin()` already computes for the
API-access closure; it is passed again here because `CustomerResolver` and
`ResellerResolver` need the value itself rather than a checker that memoises
per account.

and add the helper that reads the panel's configuration once:

```php
    /**
     * The panel configuration CustomerFeatures and Counts read.
     *
     * Registry::get('config') is an ArrayObject in the panel and absent
     * outside it, so this is the one place that has to cope with both.
     *
     * @return array<string, mixed>
     */
    private static function panelConfig(): array
    {
        if (!class_exists('iMSCP_Registry') || !\iMSCP_Registry::isRegistered('config')) {
            return array();
        }

        return (array)\iMSCP_Registry::get('config');
    }
```

Now the read stack itself:

```php
    /**
     * Every resolver in phase 2, built once, in the one order that works.
     *
     * The graph has a cycle - Domain.customer needs CustomerResolver,
     * Customer.domain needs VirtualHostResolver, Customer.reseller needs
     * ResellerResolver and Reseller.customers needs CustomerResolver again -
     * which constructor injection cannot express. Each resolver therefore
     * takes a closure for its cross-type edges and this method closes the
     * cycle by reference. Nothing calls a resolver during construction, so
     * every variable is assigned long before any closure runs.
     *
     * @return array<string, array<string, callable>> class short name => map
     */
    public function resolverMaps(): array
    {
        if ($this->maps !== null) {
            return $this->maps;
        }

        $db = $this->db;
        $loader = new BatchLoader($db);
        $vhosts = new VirtualHosts($db);
        $counts = new Counts($db, self::countsDefaultMailAccounts($this->panelConfig));
        $toUnicode = self::toUnicode();
        $monthBounds = self::monthBounds();
        $mailboxUsage = self::mailboxUsage($this->panelConfig);
        $apiAccessByDefault = $this->apiAccessByDefault;

        // OwnershipResolver wants rows, and $this->query is exec_query(),
        // which returns a statement. Handing it the wrong one would make
        // every ownership check see nothing and answer NOT_FOUND for
        // everything - fail-closed, and completely.
        $ownership = new OwnershipResolver(
            static function (string $sql, array $bind = array()) use ($db) {
                return $db->rows($sql, $bind);
            }
        );

        $customers = null;
        $resellers = null;
        $dns = null;
        $virtualHosts = null;

        $virtualHosts = new VirtualHostResolver(
            $loader, $vhosts, $db, $toUnicode,
            static function (int $adminId) use (&$customers) {
                return $customers->reference($adminId);
            },
            static function (int $ipId) use (&$dns) {
                return $dns->ipReference($ipId);
            }
        );
        $hostRef = static function (string $tag, $key) use (&$virtualHosts) {
            return $virtualHosts->reference($tag, $key);
        };
        $customerRef = static function (int $adminId) use (&$customers) {
            return $customers->reference($adminId);
        };

        $dns = new DnsResolver($db, $loader, $hostRef);
        $customers = new CustomerResolver(
            $db, $loader, $counts, $vhosts, $this->panelConfig,
            $apiAccessByDefault, $monthBounds, $toUnicode,
            static function (int $resellerId) use (&$resellers) {
                return $resellers->reference($resellerId);
            }
        );
        $mail = new MailResolver($db, $loader, $toUnicode, $mailboxUsage, $hostRef);
        $ftpSql = new FtpSqlResolver($db, $loader, $customerRef);
        $resellers = new ResellerResolver(
            $db, $loader, $apiAccessByDefault, $monthBounds, $toUnicode,
            $customerRef,
            static function (array $adminIds) use (&$customers) {
                return $customers->references($adminIds);
            },
            static function (array $ipIds) use (&$dns) {
                return $dns->ipReferences($ipIds);
            }
        );
        $query = new QueryResolver(
            $db, $ownership, $vhosts, $virtualHosts, $customers, $mail, $dns,
            $ftpSql, $resellers
        );

        $this->maps = array(
            'ViewerResolver'      => (new ViewerResolver($this->apiVersion()))->map(),
            'TypeResolver'        => (new TypeResolver())->map(),
            'VirtualHostResolver' => $virtualHosts->map(),
            'CustomerResolver'    => $customers->map(),
            'MailResolver'        => $mail->map(),
            'DnsResolver'         => $dns->map(),
            'FtpSqlResolver'      => $ftpSql->map(),
            'ResellerResolver'    => $resellers->map(),
            'QueryResolver'       => $query->map()
        );

        return $this->maps;
    }
```

and the merge, which refuses a collision rather than letting one map silently
win:

```php
    public function schemaFactory(): SchemaFactory
    {
        $merged = array();

        foreach ($this->resolverMaps() as $owner => $map) {
            foreach ($map as $key => $resolver) {
                if (isset($merged[$key])) {
                    // array_merge() would have taken the last one and nobody
                    // would have noticed until a field returned the wrong
                    // shape for one caller in production.
                    throw new \LogicException(sprintf(
                        'Two resolvers claim "%s"; %s is the second.', $key, $owner
                    ));
                }

                $merged[$key] = $resolver;
            }
        }

        return new SchemaFactory(
            $this->pluginDir . '/schema/schema.graphql',
            defined('CACHE_PATH') ? CACHE_PATH : null,
            new ResolverMap($merged),
            array(TypeResolver::class, 'resolveType')
        );
    }
```

and the three environment adapters, each with a fallback that lets the unit
suite build the container with no panel around it:

```php
    /**
     * decode_idna(), or identity when there is no panel.
     *
     * @return callable fn(string): string
     */
    private static function toUnicode(): callable
    {
        if (function_exists('decode_idna')) {
            return 'decode_idna';
        }

        return static function (string $value) {
            return $value;
        };
    }

    /**
     * The first and last second of the current calendar month.
     *
     * The panel's getFirstDayOfMonth()/getLastDayOfMonth() build Zend_Date
     * objects and are what its own traffic pages use, so the API agrees with
     * the panel about where a month ends. The fallback is only ever reached
     * in the unit suite, where nothing queries traffic.
     *
     * @return callable fn(): array{0: int, 1: int}
     */
    private static function monthBounds(): callable
    {
        if (function_exists('getFirstDayOfMonth') && function_exists('getLastDayOfMonth')) {
            return static function () {
                return array(
                    (int)getFirstDayOfMonth(), (int)getLastDayOfMonth()
                );
            };
        }

        return static function () {
            $now = time();

            return array(
                (int)mktime(0, 0, 0, (int)date('n', $now), 1, (int)date('Y', $now)),
                (int)mktime(23, 59, 59, (int)date('n', $now),
                    (int)date('t', $now), (int)date('Y', $now))
            );
        };
    }

    /**
     * Bytes held in one mailbox, from the maildirsize file the mail server
     * maintains.
     *
     * MailAccount.quotaUsed is not in the database at all: the panel reads it
     * from MTA_VIRTUAL_MAIL_DIR/<domain>/<user>/maildirsize
     * (gui/public/client/mail_accounts.php:218,251, parsed by
     * parseMaildirsize() in gui/include/Client.php:363). Null for anything
     * that cannot be read, which the schema allows and which is the only
     * honest answer when the file is absent.
     *
     * @param array<string, mixed> $panelConfig
     * @return callable fn(string $address): ?int
     */
    private static function mailboxUsage(array $panelConfig): callable
    {
        return static function (string $address) use ($panelConfig) {
            if (!function_exists('parseMaildirsize') || strpos($address, '@') === false) {
                return null;
            }

            $confDir = $panelConfig['CONF_DIR'] ?? null;

            if ($confDir === null || !is_readable($confDir . '/postfix/postfix.data')) {
                return null;
            }

            $postfix = new \iMSCP_Config_Handler_File($confDir . '/postfix/postfix.data');
            $root = $postfix['MTA_VIRTUAL_MAIL_DIR'] ?? null;

            if ($root === null) {
                return null;
            }

            list($user, $domain) = explode('@', $address, 2);
            $file = $root . '/' . $domain . '/' . $user . '/maildirsize';

            if (!is_readable($file)) {
                return null;
            }

            $parsed = parseMaildirsize($file);

            return isset($parsed['byte_count']) ? (int)$parsed['byte_count'] : null;
        };
    }

    /**
     * @param array<string, mixed> $panelConfig
     */
    private static function countsDefaultMailAccounts(array $panelConfig): bool
    {
        return (bool)($panelConfig['COUNT_DEFAULT_EMAIL_ADDRESSES'] ?? true);
    }
```

and finally:

```php
    public function apiVersion(): string
    {
        // Spec section 18: this is the schema's version, not the plugin's.
        // Phase 2 adds types and fields within the same major version, which
        // is a minor bump.
        return '1.1.0';
    }
```

- [ ] **Step 3: Update the one plan 1 assertion that the version change breaks**

In `test/unit/Api/ContainerTest.php`, the single line

```php
        self::assertSame('1.0.0', $body['data']['apiVersion']);
```

becomes

```php
        self::assertSame('1.1.0', $body['data']['apiVersion']);
```

Change nothing else in that file. If any other plan 1 test fails after Step 2,
something in the container's existing behaviour changed and that is a defect in
Step 2, not a test to update.

- [ ] **Step 4: Write the failing resolver-coverage test**

`test/schema/ResolverCoverageTest.php`. This is spec §17's *"every field in the
SDL has a resolver in the map or a working default — this is the test that
recovers what §3.4 gave up by not having a class per type"*, and the second
half of it, that no two maps claim the same field.

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use PHPUnit\Framework\TestCase;

class ResolverCoverageTest extends TestCase
{
    /**
     * Types that a resolver builds in place, as a plain array, inside the
     * shape of whatever owns them.
     *
     * A field of one of these needs no map entry, because ResolverMap's
     * fallback - read the key of the same name off the source - finds a ready
     * array. Every other object-typed field is an edge, and an edge with no
     * resolver returns null and nulls whatever non-null field holds it.
     *
     * Adding a name here is a deliberate act. Adding an edge and forgetting
     * its resolver is not, and that is the failure this list makes visible.
     */
    const SHAPED_IN_PLACE = array(
        'Provisioning', 'Forwarding', 'ContactDetails', 'Quota', 'Storage',
        'CustomerQuotas', 'CustomerFeatures', 'Autoresponder', 'PlanAllowance',
        'PlanQuotas', 'PlanStorage', 'PlanFeatures', 'ResellerQuotas'
    );

    private function container(): Container
    {
        return Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; }
        );
    }

    public function testEveryEdgeInTheSchemaHasAResolver(): void
    {
        $container = $this->container();
        $schema = $container->schemaFactory()->create();
        $map = array();

        foreach ($container->resolverMaps() as $one) {
            $map += $one;
        }

        $missing = array();

        foreach ($schema->getTypeMap() as $typeName => $type) {
            if (!$type instanceof ObjectType || strpos($typeName, '__') === 0) {
                continue;
            }

            foreach ($type->getFields() as $fieldName => $field) {
                $named = Type::getNamedType($field->getType());

                if (Type::isLeafType($named)
                    || in_array($named->name, self::SHAPED_IN_PLACE, true)
                    || substr($typeName, -10) === 'Connection'
                    || isset($map[$typeName . '.' . $fieldName])
                ) {
                    continue;
                }

                $missing[] = $typeName . '.' . $fieldName;
            }
        }

        self::assertSame(array(), $missing);
    }

    public function testEveryResolverKeyNamesAFieldThatExists(): void
    {
        // The other direction: a map entry for Custmer.domain would never
        // run, and nothing else would ever say so.
        $container = $this->container();
        $schema = $container->schemaFactory()->create();
        $stale = array();

        foreach ($container->resolverMaps() as $owner => $map) {
            foreach (array_keys($map) as $key) {
                list($typeName, $fieldName) = explode('.', $key, 2);
                $type = $schema->getType($typeName);

                if (!$type instanceof ObjectType || !$type->hasField($fieldName)) {
                    $stale[] = $owner . ': ' . $key;
                }
            }
        }

        self::assertSame(array(), $stale);
    }

    public function testNoTwoResolverMapsClaimTheSameField(): void
    {
        // array_merge() would take the last one silently, and the field would
        // return the wrong shape for one caller in production. The container
        // throws on a collision; this asserts there is none to throw on.
        $seen = array();
        $collisions = array();

        foreach ($this->container()->resolverMaps() as $owner => $map) {
            foreach (array_keys($map) as $key) {
                if (isset($seen[$key])) {
                    $collisions[] = $key . ': ' . $seen[$key] . ' and ' . $owner;
                }

                $seen[$key] = $owner;
            }
        }

        self::assertSame(array(), $collisions);
    }

    public function testTheWholeResolverMapBuildsWithNoDatabase(): void
    {
        // Plan 1's ContainerTest builds the schema in the unit suite, which
        // has no panel and no PDO. Db::detached() is what makes that possible,
        // and this asserts nothing in the wiring queries during construction.
        self::assertNotSame(array(), $this->container()->resolverMaps());
    }
}
```

- [ ] **Step 5: Write the failing integration test**

`test/integration/QueryResolverTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\QueryResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class QueryResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var QueryResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();

        // Built through the container, not by hand: this test is as much
        // about the wiring as about the six fields.
        $container = Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db
        );
        $maps = $container->resolverMaps();
        $this->resolver = $this->queryResolverOf($maps);
    }

    /**
     * The container hands back maps, not resolvers; the bound object of any
     * Query.* entry is the QueryResolver that owns it.
     *
     * @param array<string, array<string, callable>> $maps
     */
    private function queryResolverOf(array $maps): QueryResolver
    {
        $entry = $maps['QueryResolver']['Query.node'];

        return $entry[0];
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(string $who): array
    {
        return array('identity' => $this->fixture->identity($who));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    public function testNodeFindsEveryKindTheCallerOwns(): void
    {
        $cases = array(
            NodeType::CUSTOMER       => $this->fixture->customerId(),
            NodeType::DOMAIN         => $this->fixture->domainId(),
            NodeType::SUBDOMAIN      => $this->fixture->subdomainId(),
            NodeType::DOMAIN_ALIAS   => $this->fixture->aliasId(),
            NodeType::MAIL_ACCOUNT   => $this->fixture->mailboxId(),
            NodeType::SQL_DATABASE   => $this->fixture->sqlDatabaseId(),
            NodeType::SQL_USER       => $this->fixture->sqlUserId(),
            NodeType::DNS_RECORD     => $this->fixture->dnsRecordId()
        );

        foreach ($cases as $tag => $id) {
            $node = $this->value($this->resolver->resolveNode(
                null, array('id' => GlobalId::encode($tag, $id)),
                $this->context('customer'), $this->info()
            ));

            self::assertNotNull($node, $tag);
            self::assertSame($tag, $node['__tag'], $tag);
        }
    }

    public function testNodeFindsAStringKeyedFtpUser(): void
    {
        $node = $this->value($this->resolver->resolveNode(
            null,
            array('id' => GlobalId::encodeKey(
                NodeType::FTP_USER, $this->fixture->ftpUserId()
            )),
            $this->context('customer'), $this->info()
        ));

        self::assertSame($this->fixture->ftpUserId(), $node['username']);
    }

    public function testAnAliasSubdomainIsFoundByItsOwnTag(): void
    {
        // Decision D1 end to end: the identifier says AliasSubdomain and the
        // object says its __typename is Subdomain.
        $node = $this->value($this->resolver->resolveNode(
            null,
            array('id' => GlobalId::encode(
                NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()
            )),
            $this->context('customer'), $this->info()
        ));

        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $node['__tag']);
        self::assertSame('blog.' . $this->fixture->aliasName(), $node['name']);
    }

    public function testAStrangersObjectIsNotFoundRatherThanForbidden(): void
    {
        // Spec section 6.3. FORBIDDEN would confirm the object exists, which
        // is exactly the disclosure NOT_FOUND is chosen to avoid.
        try {
            $this->resolver->resolveNode(
                null,
                array('id' => GlobalId::encode(
                    NodeType::DOMAIN, $this->fixture->domainId()
                )),
                $this->context('otherCustomer'), $this->info()
            );
            self::fail('a stranger must not reach this domain');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAMalformedIdentifierIsAlsoNotFound(): void
    {
        // A third answer here - BAD_USER_INPUT - would let a caller tell a
        // well-formed unreachable id from a malformed one, and then probe.
        foreach (array('', 'not-base64', GlobalId::encode('Htaccess', 1)) as $id) {
            try {
                $this->resolver->resolveNode(
                    null, array('id' => $id), $this->context('customer'), $this->info()
                );
                self::fail('a malformed identifier must be NOT_FOUND: ' . $id);
            } catch (ApiException $e) {
                self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode(), $id);
            }
        }
    }

    public function testACustomerListingCustomersSeesOnlyThemselves(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            null, array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame(
            GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
            $connection['nodes'][0]['id']
        );
    }

    public function testAResellerListingCustomersSeesTheirOwnTwo(): void
    {
        $connection = $this->value($this->resolver->resolveCustomers(
            null, array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
    }

    public function testAResellersCustomerListIgnoresAResellerIdFilter(): void
    {
        // Documented behaviour: "Administrators only; ignored for a reseller,
        // who only ever sees their own." Honouring it would let a reseller
        // read another reseller's customer list.
        $connection = $this->value($this->resolver->resolveCustomers(
            null,
            array('filter' => array('resellerId' => GlobalId::encode(
                NodeType::RESELLER, $this->fixture->otherResellerId()
            ))),
            $this->context('reseller'), $this->info()
        ));

        self::assertSame(2, $connection['totalCount']);
    }

    public function testACustomerListingResellersSeesNone(): void
    {
        $connection = $this->value($this->resolver->resolveResellers(
            null, array(), $this->context('customer'), $this->info()
        ));

        self::assertSame(0, $connection['totalCount']);
        self::assertSame(array(), $connection['nodes']);
    }

    public function testAResellerListingResellersSeesOnlyThemselves(): void
    {
        $connection = $this->value($this->resolver->resolveResellers(
            null, array(), $this->context('reseller'), $this->info()
        ));

        self::assertSame(1, $connection['totalCount']);
        self::assertSame('sgwtreseller', $connection['nodes'][0]['username']);
    }

    public function testPendingReturnsTheOneUnsettledObjectTheCustomerOwns(): void
    {
        // The fixture writes exactly one: the alias subdomain, status 'toadd'.
        // Anything else appearing here means pendingFor() is reaching outside
        // the customer.
        $pending = $this->value($this->resolver->resolvePending(
            null, array(), $this->context('customer'), $this->info()
        ));

        self::assertCount(1, $pending);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $pending[0]['__tag']);
        self::assertSame('PENDING', $pending[0]['provisioning']['state']);
    }

    public function testAStrangerHasNothingPending(): void
    {
        $pending = $this->value($this->resolver->resolvePending(
            null, array(), $this->context('otherCustomer'), $this->info()
        ));

        self::assertSame(array(), $pending);
    }
}
```

- [ ] **Step 6: Run the tests to verify they fail, then pass**

```bash
tools/test.sh
sh test/lint/all.sh
```

Expected first: FAIL. Then, with Steps 1–3 in place: PASS —
`ResolverCoverageTest` 4 methods, `QueryResolverTest` 12 integration methods,
`SchemaTest` and every plan 1 test still green. If
`testEveryEdgeInTheSchemaHasAResolver` names a field, do not add it to
`SHAPED_IN_PLACE` to make the test pass — write the resolver, or explain in the
commit why the field really is shaped in place.

- [ ] **Step 7: Regenerate the printed-schema snapshot**

The SDL did not change in this task, so the snapshot should not either. Run
Task 11 Step 9's command again and confirm `git diff` reports no change to
`test/schema/schema.printed.graphql`. If it does report one, the SDL was edited
by accident and that is a defect to fix rather than a snapshot to bless.

- [ ] **Step 8: Commit**

```bash
git add Resolver/QueryResolver.php Api/Container.php \
        test/unit/Api/ContainerTest.php test/schema/ResolverCoverageTest.php \
        test/integration/QueryResolverTest.php
git commit -m "Wire the read stack and open it at the root

Six root fields, and one rule underneath all of them: resolve ownership before
reading anything. An object the caller cannot reach and an object that does not
exist produce the same NOT_FOUND, and so does a malformed identifier - a third
answer there would let a caller tell the cases apart and then probe.

The resolver graph has a cycle, because Customer.domain and Domain.customer are
both real edges, and constructor injection cannot express it. Every resolver
therefore takes closures for its cross-type edges and the container is the one
place that closes the loop. It also refuses a duplicate map key rather than
letting array_merge take the last one, because that failure is invisible until
a field returns the wrong shape for one caller.

Two schema tests arrive with it: every edge in the SDL has a resolver, and no
two maps claim the same field. The first is what specification section 17 asks
for in place of a class per type, and it is only writable now that every map
exists.

apiVersion becomes 1.1.0. The number is the schema's, and the schema grew."
```

---

## Task 17: The query-count test — `opus`

Spec §10.1: *"A resolver that queries per parent is a defect, and §17 has a
test that counts queries for a known document."* Spec §17: *"The integration
suite runs one representative deep document with a query log attached and
asserts the count is below a threshold, so an N+1 regression fails a test
rather than a customer's page load."*

**This task is `opus` for the same reason plan 1's smoke test was: its
threshold is a claim about reality, not about this plan.** Every number below
is a prediction. The task's real work is running the document, reading what the
box actually did, and pinning the constant to that — then explaining any gap
between the prediction and the measurement rather than adjusting the prediction
to fit.

**Two assertions, and the second is the one that matters.** A ceiling catches a
regression that adds queries. It does not catch an N+1, because an N+1 with one
customer in the fixture costs one query and looks fine. So the test also runs
the identical document over one customer and over many and asserts the count is
*the same number*. That is what "batched" means, stated as a test: the cost of
the document is a function of its shape, not of how much data it touches.

**The document must succeed before its count means anything.** A document that
errors halfway issues fewer queries and would pass a ceiling for the worst
possible reason. `$result->errors` is asserted empty first, and the assertion
prints the errors when it fails.

**Files:**
- Create: `test/integration/QueryCountTest.php`

**Interfaces:**
- Consumes:
  - `Db::fromPanel()`, `Db::pdo()`, `Db::row()`, `Db::placeholders()`,
    `Db::countQueries(callable $fn): int` (Task 2)
  - `Fixture` and every accessor on it, `IntegrationTestCase` (Tasks 2, 8)
  - `Container::forTesting(..., ?Db $db = null)`, `Container::schemaFactory()` (Task 16)
  - `GraphQL\GraphQL::executeQuery(Schema $schema, string $source, $rootValue, $context, ?array $variables)`
  - `GraphQL\Executor\ExecutionResult::$errors`, `::toArray(int $debug)`
- Produces:
  - `QueryCountTest::DOCUMENT` — the representative deep document, so that a
    later phase adding a field can extend the same document rather than
    inventing a second one
  - `QueryCountTest::CEILING` — the measured query count, pinned in Step 4

- [ ] **Step 1: Write the test**

`test/integration/QueryCountTest.php`:

```php
<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;

class QueryCountTest extends IntegrationTestCase
{
    /**
     * The representative deep document of spec section 10.1.
     *
     * It selects every edge that costs a loader bucket of its own: the
     * customer list, the whole vhost tree below it including an alias's
     * subdomains, both paged connections, both directions of the SQL grant,
     * DNS with its host edge, the three value blocks that each cost their own
     * batched query, and Domain.createdAt - the one load in this phase that
     * goes through Anorm's byColumn(). If an edge is not in here it is not
     * covered by the N+1 assertion, which is why this is a constant rather
     * than a literal buried in one method.
     */
    const DOCUMENT = '
        query Deep($page: PageInput) {
          customers(page: $page) {
            totalCount
            nodes {
              id
              username
              provisioning { state settled }
              apiAccess
              contact { firstName email }
              reseller { id username }
              quotas {
                subdomains { enabled limit used remaining }
                domainAliases { used }
                mailAccounts { used }
                ftpUsers { enabled used }
                sqlDatabases { used }
                sqlUsers { used }
              }
              storage { diskLimit diskUsed diskFiles diskMail diskSql trafficUsed }
              features { php phpEditor cgi customDns backup supportSystem }
              domain {
                id
                name
                mountPoint
                createdAt
                expiresAt
                forwarding { url type keepHost }
                provisioning { state }
                ipAddress { id address netmask }
                subdomains { id name label }
                aliases {
                  id
                  name
                  forwarding { url type }
                  subdomains { id name label }
                }
              }
              subdomains { id name }
              domainAliases { id name }
              mailAccounts {
                totalCount
                nodes {
                  id
                  address
                  kind
                  forwardTo
                  quota
                  active
                  autoresponder { enabled message }
                  host { id name }
                }
              }
              ftpUsers { totalCount nodes { id username homeDirectory } }
              sqlDatabases { id name users { id name host } }
              sqlUsers { id name host databases { id name } }
              dnsRecords { id name class type value ownedBy host { id name } }
            }
          }
        }
    ';

    /**
     * The measured cost of DOCUMENT.
     *
     * Pinned to what the reference box actually does - see Step 4 of this
     * task. It is an exact assertion rather than a ceiling with slack,
     * because slack is where an N+1 hides: one extra query per level is
     * invisible under a generous ceiling and obvious against a number.
     */
    const CEILING = 27;

    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var \GraphQL\Type\Schema */
    private $schema;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();

        $container = Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db
        );

        // No cache directory: a query-count test must execute the schema it
        // is testing, not whatever a previous run cached.
        $this->schema = $container->schemaFactory()->create();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{0: int, 1: array} query count, response
     */
    private function run(array $variables): array
    {
        $schema = $this->schema;
        $context = array('identity' => $this->fixture->identity('reseller'));
        $response = null;

        $count = $this->db->countQueries(
            function () use ($schema, $context, $variables, &$response) {
                $result = GraphQL::executeQuery(
                    $schema, self::DOCUMENT, null, $context, $variables
                );
                $response = $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
            }
        );

        return array($count, $response);
    }

    public function testTheDeepDocumentSucceeds(): void
    {
        // First, because a document that fails halfway issues fewer queries
        // and would pass a count assertion for the worst possible reason.
        list(, $response) = $this->run(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );
        self::assertSame(2, $response['data']['customers']['totalCount']);

        $node = $response['data']['customers']['nodes'][0];

        self::assertNotNull($node['domain']['name']);
        self::assertNotNull($node['storage']['diskUsed']);
        self::assertNotNull($node['quotas']['subdomains']['used']);
    }

    public function testTheDeepDocumentCostsTheMeasuredNumberOfQueries(): void
    {
        list($count, $response) = $this->run(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey('errors', $response);
        self::assertSame(
            self::CEILING,
            $count,
            'The deep document changed cost. If a field was added, update '
                . 'CEILING and say so in the commit; if not, something started '
                . 'querying per row.'
        );
    }

    public function testTheCostDoesNotGrowWithTheNumberOfCustomers(): void
    {
        // This is the N+1 assertion, and the only one of the three that an
        // N+1 cannot pass. A resolver that queries per customer costs one more
        // query per customer, so twelve customers cost eleven more than one -
        // and the ceiling above would not notice, because with the fixture's
        // two customers an N+1 is almost free.
        list($one,) = $this->run(array('page' => array('limit' => 1)));

        $this->addCustomers(10);

        list($twelve, $response) = $this->run(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey('errors', $response);
        self::assertSame(12, $response['data']['customers']['totalCount']);
        self::assertSame(
            $one,
            $twelve,
            'Reading twelve customers cost more than reading one. That is an '
                . 'N+1: some resolver is querying per parent instead of per level.'
        );
    }

    /**
     * Ten more customers of the same reseller, cloned from the fixture's own
     * rows.
     *
     * Copying the seeded row and changing three columns rather than writing a
     * fresh INSERT: `domain` has forty-odd NOT NULL columns and a second
     * hand-written copy of that row would drift from Fixture's within a
     * release. Everything happens inside the fixture's transaction and is
     * rolled back with it.
     */
    private function addCustomers(int $count): void
    {
        $admin = $this->db->row(
            'SELECT * FROM admin WHERE admin_id = ?',
            array($this->fixture->customerId())
        );
        $domain = $this->db->row(
            'SELECT * FROM domain WHERE domain_id = ?',
            array($this->fixture->domainId())
        );

        unset($admin['admin_id'], $domain['domain_id']);

        for ($i = 0; $i < $count; $i++) {
            $admin['admin_name'] = Fixture::PREFIX . 'bulk' . $i;
            $admin['email'] = Fixture::PREFIX . 'bulk' . $i . '@example.test';
            $admin['customer_id'] = 'REF-bulk' . $i;

            $domain['domain_admin_id'] = $this->insert('admin', $admin);
            $domain['domain_name'] = Fixture::PREFIX . 'bulk' . $i . '.test';

            $this->insert('domain', $domain);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`)'
                . ' VALUES (' . $this->db->placeholders(count($columns)) . ')'
        );
        $statement->execute(array_values($row));

        return (int)$this->db->pdo()->lastInsertId();
    }
}
```

- [ ] **Step 2: Run it and read what actually happened**

```bash
tools/test.sh
```

Expected: `testTheDeepDocumentSucceeds` passes.
`testTheDeepDocumentCostsTheMeasuredNumberOfQueries` **fails**, and its message
names the real number. That number is the deliverable of this task.

One prediction below is softer than the others and is called out here so it is
not mistaken for a defect: rows 19–20 are the `vhost:<kind>` reference buckets,
and a bucket flushes once per *level* at which it is asked, not once per
request. Two levels asking the same bucket for the same key cost one query,
because `keyed()` memoises; two levels asking it for **different** keys cost
two. That is correct behaviour and not an N+1 — the third test is what tells
the two apart.

The prediction, so that a gap between it and the measurement is a question
rather than a surprise. One query per bucket per level:

| # | Bucket | Field that opens it |
| --- | --- | --- |
| 1 | — | `Query.customers` identifier query |
| 2 | `customer:row` | `CustomerResolver::references()` |
| 3–8 | `counts:subdomains`, `counts:aliases`, `counts:mail`, `counts:sqldb`, `counts:sqlusers`, `counts:ftp` | `Customer.quotas` |
| 9 | `domain:traffic` | `Customer.storage` |
| 10 | `reseller:support-system` | `Customer.features` |
| 11 | `customer:api-perm` | `Customer.apiAccess` |
| 12 | `reseller:row` | `Customer.reseller` |
| 13 | `vhost:all-by-domain` | `Customer.domain`, `.subdomains`, `.domainAliases` together |
| 14 | `ip:row` | `Domain.ipAddress` |
| 15 | `vhost:by-domain:sub` | `Domain.subdomains` |
| 16 | `vhost:by-domain:als` | `Domain.aliases` |
| 17 | `vhost:alssub-by-alias` | `DomainAlias.subdomains` |
| 18 | `mail:by-domain:<filter>` | `Customer.mailAccounts` |
| 19–20 | `vhost:dmn`, `vhost:alssub` | `MailAccount.host` — one per vhost kind the mail rows point at |
| 21 | `ftp:by-admin` | `Customer.ftpUsers` |
| 22 | `sqldb:by-domain` | `Customer.sqlDatabases` |
| 23 | `sqluser:by-domain` | `Customer.sqlUsers` |
| 24 | `sqluser:by-database` | `SqlDatabase.users` |
| 25 | `sqldb:by-user-name` | `SqlUser.databases` |
| 26 | `dns:by-domain` | `Customer.dnsRecords` |
| 27 | `col:DomainModel.domain_id` | `Domain.createdAt` and `.expiresAt` together — `BatchLoader::byColumn()`, the one Anorm load in the read path |

`DnsRecord.host` is not in the table on purpose: every fixture DNS record hangs
off the main domain, whose `vhost:dmn` key row 19 already loaded and
`BatchLoader` memoised, so it should cost nothing. If the measurement is 27 and
that is the difference, the memoisation is not working and that is a Task 10
defect, not a number to accept.

- [ ] **Step 3: Account for the difference before changing anything**

If the measured number is not 27, write down which row of the table is wrong
before touching the constant. Three answers are legitimate:

- **A bucket that should have been shared was not.** Two queries where the
  table predicts one means a bucket name is being built differently at two call
  sites. Fix the bucket, not the constant.
- **A memoised key was re-queried.** `BatchLoader::keyed()` is supposed to
  remember a key it has already answered; a repeat that costs a query is a
  Task 10 defect.
- **The prediction was simply wrong** — an extra query the plan did not think
  of, that is genuinely one per level. Then the constant moves and the table
  above gains a row, in the commit message.

What is never legitimate is a number that grows with `addCustomers()`. If
`testTheCostDoesNotGrowWithTheNumberOfCustomers` fails, find the resolver: run
the document again with a `debug_print_backtrace()` inside `Db::rows()` and
look for a query issued twelve times.

- [ ] **Step 4: Pin the constant to the measurement**

Set `CEILING` to the number the box produced, and run again.

```bash
tools/test.sh
```

Expected: PASS, `QueryCountTest` 3 methods, and every other suite still green.

- [ ] **Step 5: Confirm the whole phase is green, in the box**

```bash
tools/test.sh
sh test/lint/all.sh
```

Expected: PASS for the `unit`, `schema` and `integration` suites together, and
`test/lint/all.sh` clean under both `php7.4` and `php8.3` with the `CORE-DEBT`
inventory listing C1 and C10. Do not report this plan complete on anything
less; spec §17's suites are the definition of done for phase 2.

- [ ] **Step 6: Commit**

```bash
git add test/integration/QueryCountTest.php
git commit -m "Count the queries one deep document costs, and pin the number

Specification section 10.1 calls a resolver that queries per parent a defect,
and this is the test that makes that statement enforceable. One document
selecting every batched edge in the phase-2 schema, executed against the
seeded graph with the query counter attached.

The exact count rather than a ceiling with slack, because slack is where an
N+1 hides: one extra query per level is invisible under a generous limit and
obvious against a number. The measured figure and the derivation that predicted
it are both recorded, so the next person to change the count has to say which
row of the table moved.

The assertion that actually catches an N+1 is the third one: the same document
over one customer and over twelve must cost the same number of queries. A
ceiling cannot catch it, because with two customers in the fixture an N+1 is
nearly free."
```

---

## Self-review

Written after the seventeen tasks, reading the specification again with fresh
eyes, then revised after a code review of the finished plan.

Section 2 is the review's findings **as applied** — the tasks above have been
edited and the entries record what changed and why, so that a reviewer can see
the reasoning without diffing 16,000 lines. Sections 3 onwards are conflicts a
reader would otherwise trip over, decisions the plan makes that §7 does not,
and work deliberately left for a later phase. Where an entry says *recorded,
not fixed*, it means exactly that and says why.

### 1. Spec coverage

**§7, type by type.** Every type in the schema has a resolver or a shape, and
Task 16's `ResolverCoverageTest` is the test that keeps it that way rather than
this paragraph.

| Spec section | Types | Task |
| --- | --- | --- |
| §7.1 | scalars, `Provisioning`, `ProvisioningState`, `Node`, `Provisioned` | 11 |
| §7.2 | `Viewer`, `Role`, `Scope`, `ContactDetails`, `Gender` | 11 (SDL), 15 (`Viewer.contact/customer/reseller`), plan 1 (the rest) |
| §7.3 | `VirtualHost`, `Domain`, `Subdomain`, `DomainAlias`, `Forwarding`, `ForwardType` | 9 (rows), 12 (resolvers) |
| §7.4 | `Quota`, `Storage` | 3, 5, 13 |
| §7.5 | `Customer`, `CustomerQuotas`, `CustomerFeatures` | 4, 13 |
| §7.6 | `MailAccount`, `MailAccountKind`, `Autoresponder` | 6, 14 |
| §7.7 | `FtpUser`, `SqlDatabase`, `SqlUser` | 15 |
| §7.8 | `DnsRecord`, `DnsClass`, `DnsRecordType`, `IpAddress`, `HostingPlan`, `Reseller` | 7, 14, 15 |
| §7.9 | `PageInput`, the four connections | 11 (`TypeResolver::page`), 14, 15, 16 |
| §7.10 | `Query` | 16 |

**§2.2, §6.3 ownership** — Task 1, and every root field in Task 16 calls
`assertReachable()` before reading. **§3.5 identifiers** — Task 1's `NodeType`
and decision D1. **§9 error codes** — plan 1's `ErrorFactoryTest` already walks
`ErrorCode::all()`; this plan emits `NOT_FOUND`, `FORBIDDEN` and `INTERNAL`,
all three of which are in that set. **§10.1 batching** — Tasks 10 and 17.
**§17** — the unit, schema and integration suites all gain tasks; the
`test/authz/` matrix is phase 3's, because it is a matrix over mutations.
**§19 phase 2** — "domains, subdomains, aliases, mail, FTP, SQL, DNS, quotas,
provisioning. Batch loaders and the query-count test with it": all present.

**§20 open question 2** is answered in the *Anorm: what was measured* section,
and the answer was narrowed after review; §4 below records the reasoning.

### 2. Defects found in review, and what was changed

All nine were found by reviewing the finished plan. All nine are **fixed in the
tasks above**; each entry says what the defect was, because the fix is only
obvious once the failure is.

**F1 — `->then()` returns a `SyncPromise`, not a `Deferred`. Fixed.**
`GraphQL\Deferred` extends `GraphQL\Executor\Promise\Adapter\SyncPromise`,
and `SyncPromise::then()` does `$child = new self()` — `self` is `SyncPromise`.
Every method declared `: Deferred` that returned a `->then()` result was a
`TypeError` on its first call. Retyped to `SyncPromise`, with the import added,
in **Task 10** (`BatchLoader::byColumn()`) and **Task 12** (thirteen methods on
`VirtualHostResolver`). `keyed()`, `related()`, `parent()` and `edge()` return a
`Deferred` they constructed themselves and keep that type. Tasks 13–17 were
written to the rule. `SyncPromiseAdapter` accepts either, so nothing else moves.

**F2 — a `Deferred` must not read the result of a `then()` child.** Not a
defect anywhere above, but the rule that F1's fix must not break, so it is
recorded with it. `BatchLoader::keyed()` enqueues its `Deferred` immediately, so
a `Deferred` created afterwards runs after it and finds its result set. A
promise returned by `then()` is a *child*, enqueued only once its parent
resolves — later than that. So gather from the raw `keyed()` promises, or chain
with `then()` and let `SyncPromise::resolve()` adopt the returned promise.
`CustomerResolver::references()`, `ResellerResolver::references()`,
`DnsResolver::ipReferences()` and `QueryResolver::resolvePending()` each say so
in a comment at the point where it matters.

**F3 — `BatchLoader::edge()` had no memoisation, so its own test could not
pass. Fixed in Task 10.** `edge()` enqueued unconditionally and `flushEdge()`
`unset()` the bucket afterwards, so the second `related($model, 'domains')` in
`testTheSameParentAskedForTwiceCostsOneQuery` re-enqueued and opened a second
query — the test asserts one. `keyed()` had the guard; `edge()` had nothing
equivalent. The property could not be the test either: both Anorm loaders'
`distributeBatchResults()` assign unconditionally, `[]` for one-has-many and
`null` for many-has-one, so `isset()` cannot tell "loaded and empty" from
"never loaded". `edge()` now consults a `$loadedEdges` map that `flushEdge()`
fills, and holds the model rather than its hash, because `spl_object_hash()`
reuses the hash of a collected object.

**F4 — `testResetForgetsEverything` asserted the wrong number. Fixed in Task
10.** The sequence is `keyed(); drain(); reset(); keyed(); drain();`. `reset()`
zeroes the counter and the second `drain()` increments it again, so
`flushes()` is 1, not 0. The assertion now says 1, and a separate
`testResetZeroesTheCounterOnItsOwn` asserts the zeroing directly — which is the
behaviour the original assertion was reaching for.

**F5 — `testTheMapCoversEveryEdgeOfAllThreeTypes` compared against a wrongly
ordered array. Fixed in Task 12.** The test `sort()`s the keys and compared
against a literal listing `DomainAlias.*` before `Domain.*`. `sort()` is byte
order, `'.'` is 0x2E and `'A'` is 0x41, so every `Domain.` key sorts first. The
literal is reordered and carries a comment saying why. The equivalent
assertions in Tasks 13–16 were checked and are already in byte order.

**F6 — a test method name contained a space. Fixed in Task 12.**
`testAReferenceToFourKindsCostsFourQueriesNotFour PerIdentifier` does not parse.
The plan previously flagged it in prose *below* the code block a subagent copies
verbatim, which is the wrong place for it; the name is now correct in the block
and the prose note is gone.

**F7 — the two batching tests only asked for the same key repeatedly. Fixed in
Tasks 8 and 12.** `array_fill(0, 6, $fixture->domainId())` is six copies of one
id, so what it proved was that `BatchLoader` de-duplicates a repeated key —
weaker and much easier than batching, and *not* the failure the comment claimed
("six parents, one query"). The fixture already seeds three distinct domains but
exposed only one, so **Task 8** gains `siblingDomainId()` and `otherDomainId()`
and **Task 12** asks about three different parents. A resolver that queried per
parent now costs three and cannot pass.

**F8 — `Domain.createdAt` and `Domain.expiresAt` skipped the scope gate. Fixed
in Task 12.** Every sibling in the map calls
`TypeResolver::requireScope($context, Scope::DOMAINS_READ)` and these two did
not, so a token narrowed to `MAIL_READ` that reached a `Domain` through
`MailAccount.host` could read the account's dates. Both are gated now.

**F9 — `Domain.createdAt` is `DateTime!` and returned null on a missing row.
Fixed in Task 12.** Returning null for a non-null field nulls the whole
`Domain` with graphql-php's bare "cannot return null", which names the field and
not the cause. A vhost row whose `domain` row has vanished is broken data, and
`resolveCreatedAt()` now raises `INTERNAL` with the `domainId`. `expiresAt` is
nullable and keeps returning null.

**F10 — `Fixture::identity()` and `Fixture::seed()` disagree about two
usernames. Recorded, not fixed.** `seed()` inserts `sgwtstranger` and
`sgwtother`; `identity()` builds `self::PREFIX . $key`, so it returns
`sgwtotherCustomer` and `sgwtotherReseller`. Nothing in this plan authorises on
a username — every check uses `Identity::getAdminId()` — so no test is wrong
because of it, but a test that ever compares the two will be. **Fix in Task 8
when it is executed**, by seeding `sgwtotherCustomer` and `sgwtotherReseller`;
that is smaller than teaching `identity()` a second name table.

**F11 — the "Interfaces inherited from plan 1" section is stale in two
signatures. Recorded, not fixed** — the section is a quotation of plan 1 and
correcting it in place would hide that plan 1's own review changed them. The
shipped code, verified against the working tree, is:

```php
Container::forTesting(string $pluginDir, array $config, callable $query,
                      callable $accountLoader, callable $apiAccessChecker): self
SGW_GraphQL::customerHasApiAccess($adminId, $defaultAllowed = null): bool
```

Both gained a parameter during plan 1's review. Task 16 is written against the
real signatures; a subagent given only the inherited-interfaces list would write
a four-argument `forTesting()` call. **Dispatch Task 16 with this paragraph
attached.**

**F12 — the `HostingPlan.quotas`, `.storage` and `.features` map entries are
passthroughs.** All three return `$source[...]`, which is what `ResolverMap`'s
fallback does anyway. Kept deliberately: `PlanQuotas`, `PlanStorage` and
`PlanFeatures` are in `ResolverCoverageTest::SHAPED_IN_PLACE`, so without the
entries nothing would notice if `planShape()` stopped producing one of those
keys. If that reasoning is rejected in review, delete all three from the map
*and* from Task 15's expected-keys assertion together.

**F13 — `context(array $scopes = array())` in Task 12's integration test
ignored its parameter. Fixed in Task 12.** Every caller passed nothing and the
body always returned the full-scope fixture identity, so a reader adding a
scope-restricted case would have passed `$scopes` and silently got a full-scope
identity. The parameter is gone and a comment points at `narrowContext()`,
which is how that file actually tests a narrowed token.

### 3. Decisions this plan makes that §7 does not

The plan opened with five (D1–D5). Writing Tasks 12–17 produced four more.
They belong in the same list and are recorded here rather than by editing that
section.

**D6 — a connection's page is applied in PHP, not in SQL.** Stated in full in
Task 14. `Customer.mailAccounts` and `Customer.ftpUsers` hang off `Customer`,
so a reseller's customer list asks for them once per customer; a per-parent
`LIMIT` inside one batched query needs a window function MariaDB 10.1 does not
have, and one query per parent is the N+1 §10.1 forbids. The batched query
therefore has no `LIMIT` and the page is sliced per parent, which also makes
`totalCount` free. Bounded by i-MSCP's own limits and by
`TypeResolver::page()`'s ceiling of 200.

**D7 — a reseller's countable allowances come from i-MSCP's counters; its
storage is summed.** `ResellerQuotas.used` reads `reseller_props.current_*`, so
the API and the panel cannot disagree about what a reseller has sold.
`Storage` cannot: the schema wants `diskFiles`/`diskMail`/`diskSql` beside
`diskUsed`, no counter carries the split, and taking the total from a counter
and the parts from a sum would let the parts contradict the whole. So the whole
block is summed from that reseller's customers' `domain` rows, and only the two
limits come from `max_disk_amnt`/`max_traff_amnt`. The visible consequence: a
reseller's `quotas` can disagree with the panel-independent truth if i-MSCP's
counters have drifted, and its `storage` cannot. That is the right way round —
a quota is enforced against the counter.

**D8 — `Customer.sqlUsers` counts differently from how it lists.**
`Counts::sqlUsers()` is `COUNT(DISTINCT sqlu_name)`, transcribed from
`Counting.php` because it is the rule i-MSCP enforces the quota against. The
list is one node per `sql_user` row, because the SDL says *"One row per grant,
so a user granted on three databases appears in three"* and because a client
needs the grant row to address it. One user granted on two databases therefore
shows `quotas.sqlUsers.used = 1` and two `sqlUsers` nodes. Both are correct;
neither is a bug; a reader who has not been told will file it as one.

**D9 — `MailAccount.quotaUsed` is read from the filesystem, through an injected
closure.** It is not in the database. i-MSCP reads
`MTA_VIRTUAL_MAIL_DIR/<domain>/<user>/maildirsize`
(`gui/public/client/mail_accounts.php:218,251`, parsed by `parseMaildirsize()`
at `gui/include/Client.php:363`, with the directory coming from
`new FileConfig(CONF_DIR . '/postfix/postfix.data')`). `MailResolver` takes
`callable $mailboxUsage` and `Container` supplies the core-backed closure; it
returns null for a forward-only address, for an unlimited mailbox and for any
file it cannot read, all three of which the schema allows. This is the one
field in the read model whose cost is a `stat` per row rather than a query per
level — hence the guard that skips it for anything without a mailbox.

### 4. Where Anorm is used, and where it is deliberately not

The plan's *"Anorm: what was measured"* section originally concluded that this
plan uses Anorm for "models, mappers, relationship declarations, and the two
IN-clause batch loaders". Writing Tasks 12–17 showed that to be an over-claim,
and the review flagged the contradiction. **That section has been rewritten**;
this entry records the reasoning behind it so the conclusion can be argued with
rather than merely read.

**The two structural facts that decide it.**

1. **`Anorm\Model::__construct(\PDO $pdo, DataMapper $mapper)` calls
   `$pdo->setAttribute()` in its first statement**, so a model cannot exist
   without a live connection. Every `shape()` in Tasks 12–16 is a pure function
   the unit suite tests exhaustively with array literals; making them take
   models would move all of that into the integration suite. That is a real
   loss — those tests are where the unit encodings live, and they run in
   milliseconds with no database.
2. **A resolver's source is a shaped array, not an object.** Schema-first (spec
   §3.4) means there is no class per type. `related()` and `parent()` take a
   `Model`, so reaching them from a resolver would mean fetching a model in
   order to ask it for its edge — two queries where `keyed()` does one.

**What that leaves, and it is not nothing.**

- **Task 9's thirteen models stay, and the case for them is DX.** `const
  COLUMNS` and the declared properties are the IDE's map of a schema whose
  names include `domain_aliasses`, `dtraff_pop` and
  `subdomain_alias_wildcard_alias`. Completion and rename-safety over those is
  worth having even where the read path does not instantiate the class. The
  Anorm section now says this instead of the batch-loader claim it retracted.
- **`byColumn()` has a production caller.** Task 12's `domainRow()` was
  hand-writing `SELECT domain_id, domain_created, domain_expires FROM domain
  WHERE domain_id IN (…)` — one table, one column, no join, no filter, no
  aggregate, which is precisely `byColumn()`'s shape. It now calls
  `byColumn(DomainModel::class, 'domain_id', $id)` and the two resolvers above
  it read `$domain->domain_created`. Same query count, typed access, and the
  memoisation contract is now exercised by production code rather than only by
  Task 10's own tests.
- **Phase 3's writes are where the dependency actually pays.** A mutation has a
  PDO in hand and is integration-tested by definition, so fact 1 does not bite;
  `Model` + `DataMapper` give single-layer create, update and delete over
  declared properties with no hand-written SQL. Spec §19 puts customer
  mutations in phase 3, so this is plan 3's to demonstrate.

**Where Anorm is deliberately not used, per site.** Every remaining `keyed()`
call in Tasks 13–16 was checked against `byColumn()`'s shape. These are the ones
that could have gone through it and did not, with the reason:

| Site | Why not |
| --- | --- |
| `customer:row`, `reseller:row` | Joined `admin` + `domain` / `admin` + `reseller_props`, with an explicit column list because both tables carry `domain_created`. Two tables, so no single model. |
| `sqldb:row`, `sqldb:by-domain` | Join `domain` to carry `domain_admin_id` into the shape. Dropping the join to use `byColumn()` would make `SqlDatabase.customer` a second query per level, not a saving. |
| `mail:by-domain`, `reseller:customer-ids` | Filtered and ordered. `byColumn()` matches one column for equality and nothing else. |
| the six `counts:*`, `domain:traffic`, `reseller:disk`, `reseller:traffic` | Aggregates with `GROUP BY`. No model is involved at either end. |
| `vhost:*` | The four-table `dmn`/`sub`/`als`/`alssub` union. Four models, one normalised row. |
| `customer:api-perm`, `reseller:api-perm` | `api_perm` has no model in Task 9, and adding one for a two-column table read once per request buys nothing. |
| `mail:row`, `dns:row`, `dns:by-domain`, `ip:row`, `ftp:row`, `ftp:by-admin`, `sqluser:row`, `sqluser:by-database`, `plan:row`, `plan:by-reseller`, `admin:contact`, `reseller:support-system` | **These do fit `byColumn()`'s shape.** They are left as `keyed()` because their results feed a `shape()` that takes an array, and converting them would either push those shapes into model parameters (fact 1) or add a model-to-array conversion that buys nothing. `domainRow()` is different because its two consumers are resolvers, not a shape. **If plan 3 gives the models a second reason to exist, revisit this table** — the conversion is mechanical and the DX argument applies to shapes too. |

**`related()` and `parent()` remain unused outside their own tests**, for the
structural reason above. They are tested and correct. Carry them into plan 3;
if phase 3's mutations do not reach them either, delete them there — with
`git rm`, not a deprecation.

### 5. Schema shapes invented here, for the spec to catch up with

Spec §7 uses these without defining them, and Task 11's SDL invents them. Each
is derived from the thing it describes, and each should be folded back into §7:

- `ResellerFilter` — `username`, `state`. `CustomerFilter` without the two
  fields that only make sense for a customer.
- `MailAccountFilter` — `kind`, `address` (substring, as the panel's own list
  does), `state`.
- `PlanAllowance`, `PlanQuotas`, `PlanStorage`, `PlanFeatures`,
  `enum BackupTarget { DOMAIN SQL MAIL }` — `HostingPlan.quotas`, `.storage`
  and `.features` are named in §7.8 with no types behind them. `PlanAllowance`
  is `Quota` without `used`, because a plan has no usage.
- `ResellerQuotas` — `Reseller.quotas` is named in §7.8 with no type.
  `CustomerQuotas` plus a `customers` allowance, because
  `reseller_props.max_dmn_cnt` is the customer limit and has nowhere else to go.
- Two behaviours the SDL states in descriptions and §7 does not state at all:
  `Query.customers` returns only themselves for a `CUSTOMER`;
  `Query.resellers` returns only themselves for a `RESELLER` and an empty list
  for a `CUSTOMER`.

### 6. Conflicts between this plan's own sections

Recorded, not fixed, because fixing them means editing text tasks have already
been dispatched against.

1. **The Model-assignment table and the task headings disagree for Tasks 1–7.**
   The table says `haiku` for 2, 4, 5, 6 and `sonnet` for 1, 3, 7; the shipped
   headings are 1 `sonnet`, 2 `sonnet`, 3 `haiku`, 4 `haiku`, 5 `sonnet`,
   6 `haiku`, 7 `sonnet`. **The headings win** — they are what a dispatcher
   reads. Tasks 8–17 match the table exactly (8, 9, 11–16 `sonnet`; 10 and 17
   `opus`).
2. **D5 says "Task 4 adds C7 to spec §21"; the edit is Step 1 of Task 5.** And
   the item was renumbered to **C10** by Task 10 Step 1, because C7, C8 and C9
   were taken. So: the backlog item this plan files is **C10 — batched counting
   functions**, added by Task 5 and renumbered by Task 10. Task 5's own
   `CORE-DEBT(C7)` markers move with it. The markers in `Repository/Counts.php`
   must read `CORE-DEBT(C10)` by the end of Task 10, and `test/lint/all.sh`
   fails if they do not.
3. **The File Structure table lists `test/integration/bootstrap.php`.** Task 2
   Step 2 names it `test/integration/IntegrationTestCase.php` and no task
   creates a `bootstrap.php`. The table's row is stale; the class is the thing.
4. **Task 2's claim that `composer.json` already has an `autoload-dev` mapping
   is wrong.** It has none; Task 8 Step 1 adds it. Task 2 must not assume the
   test namespace autoloads.
5. **Files modified by this plan that the File Structure table does not list:**
   `composer.json` (Task 8), `Support/ProvisioningFilter.php` (Task 14),
   `Resolver/QueryResolver.php` (Task 16 — listed, but as `Query.node` etc.
   only), `test/unit/Api/ContainerTest.php` (Task 16, one assertion),
   `test/schema/ResolverCoverageTest.php` (Task 16),
   `test/integration/QueryCountTest.php` (Task 17), and the several
   `test/integration/*ResolverTest.php` files, which the table covers only
   under its catch-all last row.

### 7. Deliberately deferred, and to where

- **Spec §10.2's per-field complexity.** *"List fields declare a complexity
  proportional to their `limit`."* The depth and complexity rules themselves
  are plan 1's and are live; the per-field weights are not, and phase 2 is
  where the paged list fields first exist. Spec §19 puts complexity limits in
  **phase 6**, so this goes to **plan 4** — with the note that
  `TypeResolver::page()`'s hard ceiling of 200 is what bounds a list until
  then, and that it is a real bound rather than a placeholder.
- **`Query.pending` does not report an account's own unsettled status for a
  reseller or an administrator.** `VirtualHosts::pendingFor()` takes customer
  `admin_id`s, so a reseller whose own `admin_status` is `tochange` does not see
  themselves in `pending`. Their customers' objects are all there. **Plan 4**,
  with `Reseller` gaining the same treatment `Customer` has.
- **`Query.pending` for an administrator reads every customer on the box.** One
  identifier query plus eight batched queries, so it does not N+1 — but the
  result set is unbounded, and `pending` has no `PageInput`. **Plan 4**: either
  a `PageInput` on `pending` or an administrator-only ceiling. A box with ten
  thousand customers should not be able to ask this question by accident.
- **`test/authz/`, the mutation authorisation matrix of spec §17.** It is a
  matrix over mutations, and there are none until phase 3. **Plan 3**, written
  before the resolvers it tests, as §17 requires.
- **`Secret` and `EmailAddress` are declared and never used as inputs.** Both
  are in the SDL from plan 1; `Secret` has no field in the read schema at all,
  which is correct — it is write-only. **Plan 3** gives it its first use.
- **Rate limits, audit, the explorer, packaging.** Phases 5–7, **plan 4**.

### 8. What would make this plan fail

Three things, in the order they are likely:

1. **The measured query count in Task 17 is not the predicted 27.** Most
   likely, and Task 17 Step 3 is written to make it a question rather than a
   surprise. Only one answer is unacceptable: a count that grows with the
   number of customers.
2. **`BatchLoader`'s memoisation does not hold across levels.** Several counts
   above — Task 17's prediction included, where `DnsRecord.host` is expected to
   cost nothing because `MailAccount.host` already loaded the key — assume that
   a key asked for twice in one request is queried once. `keyed()` records every
   key it was asked for, the ones with no rows included, and F3 gave `edge()`
   the same guarantee. Four of Task 10's own tests are the evidence:
   `testAKeyAskedForAgainAfterAFlushIsNotAskedForAgain`,
   `testAKeyTheLoaderOmitsResolvesToNull`,
   `testTheSameParentAskedForTwiceCostsOneQuery` and
   `testByColumnMemoisesAcrossLevels`. Run those first — if any of them fails,
   every query-count assertion downstream is measuring something else.
3. **`Db::countQueries()` counts something other than round trips.** Every
   query-count assertion in Tasks 12–17 rests on it. Task 2 built it; if it
   counts prepared statements rather than executions, or misses the fixture's
   own inserts, half the assertions in this plan are measuring the wrong thing.
   Task 10's tests are the first place that would show, and they are the first
   place to look.

---
