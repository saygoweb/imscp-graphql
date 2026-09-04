# SGW_GraphQL — specification, variant B: in the i-MSCP codebase

A GraphQL API for i-MSCP, built **inside `saygoweb/imscp`** rather than as a
plugin. Same API, same schema, same authorisation model; a different place to
put it, and — because of where it sits — a materially better answer to the two
worst problems in [variant A](SPECIFICATION.md).

Status: **not adopted.** Reviewed 2026-09-04 against
[variant A](SPECIFICATION.md); **A was chosen**, and this document is retained
for the reasoning, not for implementation.

The decisive argument was not in either document. This variant's case rests on
the premise that a plugin must live with duplicated business logic because it
cannot change core (§1). A plugin cannot — but it can change core *separately*.
Variant A now treats each duplicated rule as an entry in a backlog of
improvements to `saygoweb/imscp`, each worth making on i-MSCP's own merits, each
of which then lets the plugin delete its copy. That reaches this document's
§4, §5 and §6 incrementally, without §2.1's one-way door on distribution.

The list is [variant A §21](SPECIFICATION.md#21-the-core-improvement-backlog),
and §4.4 below is its ancestor. Read this document for *why* those changes are
worth making; read A for what is actually being built.

One decision here was also superseded: this variant assumed PHP 8.3. The
decision is to move the panel to **7.4 first** — free, measured, and enough for
everything the plugin needs — with 8.3 as separate later work. Appendix B's
measurements still stand and support both.

Facts quoted here were read out of `/home/cambell/src/sgw/imscp` at commit
`7d8788400` or measured inside the running `imscp_debian_trixie` box. New
measurements since variant A are in [Appendix B](#appendix-b--what-was-measured-for-this-variant).

---

## 1. Why this variant exists

Variant A is a competent plugin design, and two things in it are ugly. Both are
ugly for exactly one reason: **a plugin cannot change core.**

**A §3.2 — the business logic is reimplemented.** The rules for adding a
subdomain live in 250 lines of `gui/public/client/subdomain_add.php`, written
against `$_POST` and `$_SESSION`. A plugin cannot call them, so variant A
transcribes them, and A's risk #2 is the consequence: two copies of the same
rules, drifting apart, with a comment citing a line number as the only thread
between them.

**A §6.4 — the identity shim.** Core helpers such as `customerHasFeature()`
read `$_SESSION['user_id']` directly. A plugin cannot change that signature, so
variant A fakes a session for a request that has none, and then has to forbid
changing identity mid-request because `customerHasFeature()` caches its answer
in a `static` that is not keyed by user (`gui/include/Client.php:84`).

Neither is a design choice. Both are the shape a plugin has to fold itself into.
Inside the codebase, the first becomes *extraction* rather than duplication, and
the second becomes a parameter. That is the whole argument for this variant, and
everything below is its consequences.

---

## 2. The decision

**Build the API inside `saygoweb/imscp`, and extract the business logic out of
the page scripts into a service layer that both the pages and the API call.**

### 2.1 What it costs, assessed honestly

The standard objection is fork divergence: patch core and you own the merges
forever. That objection is real, and here it is **mostly already paid**:

| | |
| --- | --- |
| `upstream` remote | `https://github.com/i-MSCP/imscp.git`, configured but dormant |
| Commits on `saygoweb/imscp` `main` | 11,054 |
| Recent work already in core | Debian 13 (Trixie) port, the Dovecot 2.4 port, three Courier authentication fixes, a CustomDNS module fix — all in the last fortnight |
| Existing core-touching plugins in the family | `SGW_ApacheCache`, `SGW_LetsEncrypt`, `SGW_DnsExternal`, `SGW_PostfixAuth`, `SGW_PhpVersion` |

You are already the de-facto maintainer of this codebase, already carrying
distribution ports that upstream will never take, and about to carry a PHP 8.3
migration that upstream will never take either. The marginal cost of one more
subsystem in core is low, and lower than the cost of the two compromises in §1.

Two costs do *not* go away, and should be weighed:

1. **It is a one-way door for distribution.** A plugin can be handed to another
   i-MSCP operator as a `.tgz`. An in-core API cannot, without extracting it
   again. If there is any intention of publishing this the way
   `SGW_ApacheCache` is published, variant A is the right answer and this
   document is the wrong one. **This is open question 1.**
2. **Blast radius.** Refactoring `subdomain_add.php` can break the panel's own
   subdomain creation, which a plugin never could. §4.3 is how that is
   contained.

### 2.2 What it buys

| Variant A problem | In-core |
| --- | --- |
| A §3.2 — logic reimplemented, two copies drifting | Extracted once, called twice (§4) |
| A §6.4 — the `$_SESSION` identity shim | Identity becomes a parameter (§5) |
| A §6.5 — `showErrorPage()` exits mid-request with a non-GraphQL body | The helpers are changed to throw (§6) |
| A §3.3 — dependencies vendored into a `.tgz`, pinned to the panel's PHP | Ordinary entries in `gui/composer.json` (§7) |
| A §15 — plugin-owned tables, `migrateDb()` | `database.sql` and the core update mechanism (§9) |
| A §17 — services only reachable through HTTP and a plugin manager | Extracted services are directly unit-testable (§10) |
| A §13 — packaging, versioning, `info.php`, a release archive | None of it exists (§8) |

---

## 3. What is unchanged from variant A

Most of the *API* is unaffected by where the code lives. These sections of
variant A stand as written and are not restated here:

* **§1 Scope** — same actors, same entities, same exclusions.
* **§2.1–2.3, §2.6** — the ground it stands on. Asynchronous provisioning, the
  ownership chain, the three separate rights questions, the router fall-through.
  §2.4 and §2.5 are superseded (by §4 and §7 here).
* **§3.4 Schema-first (SDL)** — see the note in §7.3 below; the decision holds
  but one of its three arguments changes.
* **§3.5 Opaque global identifiers**, **§3.6 errors as GraphQL errors**.
* **§4 Transport**, **§5 Authentication**, **§7 The schema** in full,
  **§8 Mutation semantics**, **§9 Errors**, **§10 Performance and abuse limits**,
  **§11 Observability**, **§12 Security**, **§16 Panel pages**,
  **§18 Compatibility**.

Read those there. What follows is only what changes.

---

## 4. Extract and share

This replaces variant A §3.2, and it is the heart of this variant.

### 4.1 The shape

For each entity, the rules currently embedded in a page script are moved into a
service, and the page is re-pointed at it:

```
Before                                    After

client/subdomain_add.php                  client/subdomain_add.php
  ├── read $_POST                           ├── read $_POST
  ├── 250 lines of rules                    ├── build SubdomainCreateInput
  ├── INSERT ... 'toadd'                    ├── SubdomainService::create($identity, $input)
  ├── phpini row                            ├── set_page_message / redirectTo
  ├── default mail accounts                 └── render the form
  ├── send_request()
  ├── set_page_message                    iMSCP\Service\SubdomainService
  └── render the form                       ├── validate
                                            ├── check feature, quota, settled
                                            ├── transaction + events
                                            ├── INSERT ... 'toadd'
                                            ├── phpini row
                                            ├── default mail accounts
                                            ├── send_request()
                                            └── return the entity

                                          GraphQL Mutation.subdomainCreate
                                            └── SubdomainService::create($identity, $input)
```

The service takes an explicit identity and a typed input object, returns an
entity or throws a typed exception. It knows nothing about HTTP, `$_POST`,
`$_SESSION`, page messages or redirects. Those stay in the page.

### 4.2 What a service looks like

```php
namespace iMSCP\Service;

final class SubdomainService
{
    /**
     * @throws ValidationException   input the caller can fix
     * @throws QuotaException        a limit reached
     * @throws FeatureException      the feature is withheld from the account
     * @throws ConflictException     the parent is not settled, or the name is taken
     * @throws AuthorizationException the identity may not reach the parent
     */
    public function create(Identity $identity, SubdomainCreateInput $input): Subdomain;

    public function update(Identity $identity, int $id, SubdomainUpdateInput $input): Subdomain;

    public function delete(Identity $identity, int $id): Subdomain;
}
```

The exception types are the same set as the API's error codes (A §9), so the
mapping from service to GraphQL error is a lookup table and not a judgement.
The page maps them to `set_page_message()` instead. Neither surface invents its
own vocabulary of failure.

### 4.3 How the extraction is kept safe

The rule is: **the page's observable behaviour does not change.** Extraction is
mechanical — move the code, thread the identity, replace `set_page_message` +
`return false` with a thrown exception, replace `$_POST['x']` with `$input->x`.
Nothing is redesigned on the way through. Any improvement is a separate commit,
after the extraction has landed and the page still works.

That gives three layers of safety:

1. **The page is the regression test.** It exercised these rules before the
   extraction and must exercise them identically afterwards. A behaviour change
   in the panel UI is the loudest possible signal that the extraction was not
   faithful.
2. **One entity per pull request.** Page, service and API mutation land
   together, so a bisect lands on one entity's extraction and not on a
   twelve-entity refactor.
3. **The integration suite (§10) drives both surfaces** — the panel page and the
   GraphQL mutation — against the same fixtures, and asserts the same database
   and filesystem end state from each.

### 4.4 Which pages, in which order

Ordered by how much the API needs them against how tangled the page is, so the
cheap ones establish the pattern before the hard ones test it.

| Order | Service | Extracted from | Notes |
| --- | --- | --- | --- |
| 1 | `SqlDatabaseService`, `SqlUserService` | `client/sql_database_add.php`, `sql_user_add.php`, `sql_delete_user.php`, `sql_database_delete.php` | Smallest, and synchronous — no daemon involved. The pattern-setter. |
| 2 | `FtpUserService` | `client/ftp_add.php`, `ftp_edit.php`, `ftp_delete.php` | Watch the `VirtualFileSystem` home-directory check (A §3.2). |
| 3 | `MailService` | `client/mail_add.php`, `mail_edit.php`, `mail_delete.php`, `mail_catchall*.php`, `mail_autoresponder_*.php` | The twelve `mail_type` values (A §7.6) get one mapping, here. |
| 4 | `SubdomainService` | `client/subdomain_add.php`, `subdomain_edit.php`, `subdomain_delete.php`, `alssub_delete.php` | 582 lines; the reference case. `deleteSubdomain()` and `deleteSubdomainAlias()` in `include/Client.php` fold in here. |
| 5 | `DomainAliasService` | `client/alias_add.php`, `alias_edit.php`, `alias_delete.php`, `reseller/alias.php` | Carries the ordering/approval flow. |
| 6 | `DnsRecordService` | `client/dns_add.php`, `dns_edit.php`, `dns_delete.php` | |
| 7 | `DomainService` | `client/domain_edit.php` | Forwarding and document root only. |
| 8 | `CustomerService` | `reseller/user_add1.php`, `user_add2.php`, `user_add3.php`, `user_edit.php`, `user_delete.php`, `domain_status_change.php` | The three-step wizard collapses into one call; `$_SESSION`-carried wizard state disappears. `deleteCustomer()` and `change_domain_status()` in `include/Shared.php` fold in here. |
| 9 | `HostingPlanService` | `reseller/hosting_plan*.php` | Owns the 25-field `props` codec (A §7.8). |
| 10 | `ResellerService` | `admin/user_add*.php`, `admin/user_edit.php`, `admin/user_delete.php` | |

Ten services, roughly 4,500 lines of page script to read and about 2,000 lines
of rules to move. This is the bulk of the work in this variant, and it is work
that improves the panel whether or not the API ships.

### 4.5 What is *not* extracted

The read side. Page scripts build template variables; the API builds a schema.
There is nothing shared to extract, and forcing one would produce a data
structure that suits neither. The API's read model is its own (§7.2).

---

## 5. Identity without a shim

Replaces variant A §6.4, which disappears entirely.

An `Identity` value object — admin id, username, type, `created_by` — is
constructed once per request, from a bearer token (A §5.1), from the panel
session, or from the CLI. It is passed explicitly into every service call.

The core helpers that currently read `$_SESSION` gain an explicit first
parameter, defaulting to the session so every existing caller keeps working:

```php
// gui/include/Client.php
function customerHasFeature($featureNames, $forceReload = false, $adminId = NULL)
{
    $adminId = $adminId ?? $_SESSION['user_id'];
    static $availableFeatures = [];               // now keyed by $adminId
    ...
}
```

Two things worth noticing. The `static` cache becomes **keyed by admin id**,
which removes A §6.4's one-identity-per-request rule and the class of bug it was
guarding against — a reseller acting on two of their customers in one request
now works correctly, where in variant A it was forbidden. And the change is
additive: every one of the ~90 existing call sites is untouched.

The same treatment applies to `customerSqlDbLimitIsReached()`,
`resellerHasFeature()`, `deleteSubdomain()`, `deleteSubdomainAlias()`,
`deleteDomainAlias()`, `deleteCustomer()`, `delete_sql_database()` and
`sql_delete_user()` — though most of the last group are absorbed into services
by §4.4 anyway.

---

## 6. Errors without the exit hazard

Replaces variant A §6.5.

`showErrorPage()` (`gui/include/View.php:932`) terminates the request with
`exit(json_encode(...))` when `Accept` contains `application/json` — which is
what a GraphQL client sends. Variant A had to build three layers of defence
around a function it could not change.

In-core, it is changed. `showBadRequestErrorPage()` and its siblings become
thin wrappers that throw a typed exception; a handler registered by the page
front controller catches it and renders exactly the response it renders today,
so the panel's behaviour is unchanged. The API's handler catches the same
exception and turns it into a GraphQL error.

The shutdown guard from A §6.5 layer 2 is still worth keeping — cheap, and it
catches the unknown unknowns — but layers 1 and 3 stop being necessary, and the
"comment naming the pre-check that makes this reachable" convention goes away.

---

## 7. Dependencies, on PHP 8.3

Replaces variant A §3.3, which existed almost entirely to work around PHP 7.3.

### 7.1 The PHP version this assumes

**PHP 8.3.** The measurements behind that decision are in Appendix B: the panel
runs on 8.3 after a bounded set of changes — three dependency swaps, 25
`#[\ReturnTypeWillChange]` attributes, three `#[\AllowDynamicProperties]`, and
four small code fixes — with 20 of 21 pages rendering and no deprecations left.

**PHP 7.4 is also, separately, free.** The unmodified panel and its unmodified
7.3-era dependencies run on 7.4 with *zero* changes: 21 of 21 pages render,
no deprecations, nothing to patch. If the 8.3 work slips, 7.4 can be taken
today as a one-line change to `PHP_FPM_BIN_PATH`, and it already unblocks
`webonyx/graphql-php` v15, `lcobucci/jwt` v4 and Anorm unpatched. It buys the
panel nothing else — 7.4 is as end-of-life as 7.3, and Roundcube 1.7 needs 8.1
— so it is an interim, not a destination.

### 7.2 What the API depends on

| Package | Why |
| --- | --- |
| `webonyx/graphql-php ^15` | The schema engine. v15 requires PHP 7.4+, so it was unavailable in variant A. |
| `saygoweb/anorm ^3.1` | The API's **read** models. See §7.3. Runs unmodified on 7.4 and 8.3; measured. |

That is the whole list. No JWT library (A §5.1's opaque tokens are still the
right answer, and for the same reason: a token that can create system users has
to be revocable now, which means a database lookup anyway). No
`simpod/graphql-utils` (A §3.4 removes the need, and on 8.0+ it would at least
be *available*, unlike in variant A).

### 7.3 Anorm, on the read side only

Variant A recommended against Anorm, for a reason that had nothing to do with
its PHP 7.4 requirement: a write in i-MSCP is never "persist a model", it is
validate → quota → transaction → before-event → INSERT with a `toadd` status →
`php_ini` row → default mail accounts → after-event → commit → poke the daemon
→ log. An ORM helps with one step in ten. **That reasoning is unchanged, and
the write side stays on `exec_query()` inside the services of §4.**

The read side is a different problem, and Anorm turns out to fit it unusually
well. A §10.1 requires DataLoader-style batching so that "list my customers,
and for each their domain and its subdomains" is not an N+1. Anorm v3 already
ships that: `Relationship\BatchLoadingOrchestrator` with
`ManyHasOneBatchLoader`, `OneHasManyBatchLoader` and `ManyHasManyBatchLoader`
behind it, and — the part that matters — it takes a **field selection**
specification, `['posts', 'company:name,address']`, which is precisely the
shape of a GraphQL `ResolveInfo`. Wiring a resolver's requested fields into
Anorm's batch loader is a mapping, not an implementation.

So: Anorm for the read models and their batching, hand-written `exec_query()`
for the writes. The split is not arbitrary — each side uses the tool that
matches its actual problem — but it is two idioms in one codebase, and that has
a cost. **This is open question 2.**

One knock-on: A §3.4 gave three arguments for SDL-first over code-first, and one
of them was that it avoided the PHP 7.4 requirement of `simpod/graphql-utils`.
On 8.3 that argument evaporates. The other two — that a 400-line SDL file is
reviewable in a way that 60 PHP classes are not, and that the SDL is the
artefact clients generate from — still stand, and the decision stands with them.

---

## 8. Where the code lives

Replaces variant A §13. There is no plugin, no `info.php`, no `config.php`, no
`makefile.json`, no `upload-exclude.txt`, no release archive and no version
number of its own.

```
gui/
  composer.json                    +webonyx/graphql-php, +saygoweb/anorm

  public/
    api.php                        API front controller (§8.1)
    client/…, reseller/…, admin/…  existing pages, re-pointed at the services

  schema/
    schema.graphql                 the schema (A §7). Served at GET /api/schema

  src/
    Service/                       §4 — the shared business logic. Called by the
                                   pages and by the API. The centre of gravity
                                   of this variant.
      SubdomainService.php  MailService.php  FtpUserService.php
      SqlDatabaseService.php  SqlUserService.php  DnsRecordService.php
      DomainService.php  DomainAliasService.php
      CustomerService.php  ResellerService.php  HostingPlanService.php
      Input/                       typed input objects
      Exception/                   ValidationException, QuotaException, …

    Api/
      Http/                        PSR-15 middleware: Tls, Cors, Authenticate,
                                   RateLimit, Audit; and the endpoint handler
      Auth/                        TokenService, Identity, Scope
      Security/                    OwnershipResolver, Guard
      Schema/                      SchemaFactory (SDL + AST cache), ResolverMap,
                                   GlobalId, scalars
      Resolver/                    thin; reads via Model/, writes via Service/
      Model/                       Anorm models and the batch-loading wiring

    Config/, Event/, …             unchanged core

  include/
    Client.php, Shared.php, …      §5 — helpers gain an explicit $adminId
    View.php                       §6 — error pages throw rather than exit

configs/debian/default/database/database.sql    +3 tables (§9)
engine/PerlLib/iMSCP/Update/                    +one update step (§9)
test/                                            §10
```

### 8.1 The front controller

Variant A registered `/api/graphql` through the plugin router, which meant every
API request paid for the panel's full bootstrap — Slim application, Zend
translator, navigation tree, plugin manager — before reaching a resolver.

In-core, `public/api.php` is its own entry point with its own nginx `location`,
and it bootstraps only what the API needs: configuration, database, event
manager, plugin manager (still needed — the other plugins' event listeners must
fire, which was variant A §3.1's whole argument and is no less true here). It
does not build the translator, the navigation or the template engine.

```nginx
location = /api/graphql {
    include imscp_fastcgi.conf;
    fastcgi_param SCRIPT_FILENAME {WEB_DIR}/public/api.php;
    fastcgi_param SCRIPT_NAME /api.php;
}
```

One line of `00_master.nginx` and `00_master_ssl.nginx`, which a plugin could
not have touched.

---

## 9. Configuration and database

Replaces variant A §14 and §15.

**Configuration** moves from a plugin `config.php` into `imscp.conf`, with the
same keys, prefixed `API_`. They are then visible to the Perl engine as well as
the frontEnd, and settable through preseeding — which matters for anyone
provisioning a server unattended:

```
API_ENABLED               = 1
API_ENDPOINT              = /api/graphql
API_REQUIRE_TLS           = 1
API_ALLOW_SESSION_AUTH    = 1
API_ALLOW_PASSWORD_GRANT  = 1
API_INTROSPECTION         = 1
API_MAX_QUERY_DEPTH       = 15
API_MAX_QUERY_COMPLEXITY  = 1000
API_RATE_LIMIT_QUERIES    = 120
API_RATE_LIMIT_MUTATIONS  = 30
API_AUDIT                 = mutations
API_AUDIT_RETENTION_DAYS  = 90
```

**The three tables** of A §15 — `api_token`, `api_perm`, `api_audit`, renamed
from `graphql_*` since they are no longer plugin-scoped — are added to
`configs/debian/default/database/database.sql` for fresh installs and to the
engine's database update mechanism for existing ones. `AbstractPlugin::migrateDb()`
and the plugin `sql/` directory are not involved.

This is strictly better than the plugin arrangement in one respect worth
naming: a plugin's tables vanish on uninstall, which is correct for a plugin and
wrong for an audit log.

---

## 10. Testing

Replaces variant A §17, and this is where the variant pays off most visibly.

**Unit tests, of the services, without HTTP.** In variant A the business logic
was reachable only through a Slim route, behind authentication, behind a plugin
manager, with a faked session. Here `SubdomainService::create()` takes an
`Identity` and an input object and is called directly from PHPUnit against a
seeded database. Every rule transcribed in A §4.4's page scripts becomes a test
case where it previously became a comment.

**Extraction fidelity.** For each entity, a test that drives the *page* (POST to
`client/subdomain_add.php`) and a test that drives the *API*
(`mutation { subdomainCreate }`) with equivalent inputs, asserting the same rows,
the same statuses, the same events dispatched and the same daemon request. This
is the test that makes §4.3's "the page is the regression test" a fact rather
than a hope, and it is the reason the extraction can be done safely at all.

**Unchanged from A:** the schema tests, the authorisation matrix, the
integration suite against a live box, and the query-count assertion.

**One addition, specific to this variant:** a test that the panel still works.
The 21-page sweep used in Appendix B — log in as admin, reseller and customer,
render every significant page, assert no fatal and no deprecation — becomes a
committed test. It is what caught every problem in the PHP 8.3 migration, and
it is exactly the tripwire a codebase being refactored under itself needs.

---

## 11. Delivery

Replaces variant A §19. The phases are differently shaped: extraction is the
spine, and the API follows it entity by entity rather than arriving all at once.

| # | Phase | Ends when |
| --- | --- | --- |
| 0 | **PHP 8.3** | The panel runs on 8.3 in the box and in staging: the dependency swaps, the attributes, the four code fixes, `PHP_FPM_BIN_PATH`, `Requirements.pm`. The 21-page sweep is a committed test and is green. Nothing GraphQL happens until this is done — building on 7.3 and migrating later would mean doing §7 twice. |
| 1 | **Seams** | §5 (identity as a parameter) and §6 (error pages throw) land, with every existing call site untouched and the panel unchanged. Pure refactor, no new behaviour, fully covered by the page sweep. |
| 2 | **Walking skeleton** | `public/api.php` answers `{ viewer { username role } }` for a bearer token: front controller, nginx location, middleware stack, `api_token` table, token UI, SDL cache. Every integration unknown resolved on one field. |
| 3 | **Read model** | The customer graph reads, with Anorm models and batch loading and the query-count test. Touches no page script — safe to run in parallel with phase 4. |
| 4 | **Extraction, entities 1–7** | `SqlDatabase`, `SqlUser`, `FtpUser`, `Mail`, `Subdomain`, `DomainAlias`, `DnsRecord`, `Domain`. One entity per pull request: service extracted, page re-pointed, mutation added, fidelity test green. |
| 5 | **Extraction, entities 8–10** | `Customer`, `HostingPlan`, `Reseller`. The reseller wizard and the 25-field `props` codec are here, which is why they are last. |
| 6 | **Hardening** | Rate limits, audit, complexity limits, the explorer, the client-facing API guide. |
| 7 | **Release** | i-MSCP release notes, upgrade path for the three tables, documentation. |

Phase 4 is the one that runs long, and it is also the one that can be stopped
at any entity boundary with everything shipped so far still working. That is
the property to protect.

---

## 12. Risks

Variant A's risk #2 (reimplemented logic drifts) and risk #3 (the identity shim)
are designed out. Its risk #1 (PHP 7.3 end of life) is resolved by phase 0.
Risks #4 (`hosting_plans.props`) and #5 (the FTP home-directory check) carry
over unchanged. Three are new or newly shaped:

1. **The extraction breaks the panel.** Refactoring ten pages that create system
   users, mailboxes and databases is the real risk in this variant, and it is
   larger than anything in variant A. Mitigations are §4.3's three layers, and
   the ordering in §4.4 which puts the smallest and most self-contained entity
   first so the pattern is proven before `subdomain_add.php` is touched.
2. **Divergence becomes a one-way door.** §2.1 argues the merge cost is already
   paid; it does not argue the distribution cost is. If this should ever be
   shippable to other i-MSCP operators, decide that before phase 4, not after.
3. **The two-idiom read/write split** (§7.3). Anorm on one side and
   `exec_query()` on the other is defensible per side and awkward as a whole.
   The cheapest reversal is to drop Anorm and hand-write the batch loaders;
   the decision point is the start of phase 3.

---

## 13. Open questions

Variant A's open questions 3 (scope granularity), 4 (reseller acting *as* a
customer — now cheap to allow, because §5 removes the one-identity-per-request
rule that forbade it), 5 (alias ordering), 6 (traffic history) and 7 (rate limit
defaults) carry over. Its questions 1 (SDL vs code-first) and 2 (Anorm) are
answered here, in §7.3.

New, and in the order they need answering:

1. **Is this ever to be distributed?** If another i-MSCP operator should be able
   to install this, variant A is correct and this document is not. §2.1. This
   question gates everything else and should be settled first.
2. **Anorm on the read side, or hand-written?** §7.3. Two idioms in one
   codebase, against a batch loader that already does what the GraphQL read
   layer needs. Decide at the start of phase 3.
3. **How far does the extraction go?** §4.4 lists ten services covering the
   entities the API needs. The same treatment would benefit pages the API does
   not touch — protected areas, custom error pages, backups, the PHP editor.
   Extract only what the API needs, or take the opportunity while the pattern is
   fresh? The first is disciplined; the second leaves the codebase in one style
   rather than two.
4. **Does the panel UI get rebuilt on the API?** Not proposed here and not
   costed, but it becomes possible for the first time once §4 is done, and it is
   the reason someone might argue for going further than question 3 suggests.
   Worth an explicit "not now" rather than a silence.

---

## Appendix B — what was measured for this variant

Everything below was measured in the `imscp_debian_trixie` box on 2026-09-04.
Appendix A of variant A still applies for the PHP 7.3 measurements.

| Claim | Method | Result |
| --- | --- | --- |
| The panel's own code is PHP 8.3-clean syntactically | `php8.3 -l`, one invocation per file, over `src include public plugins` | 264 files, 0 failures |
| The panel runs on 8.3 after a bounded set of fixes | Built `/tmp/gui83`: dependency swaps, 25 `#[\ReturnTypeWillChange]`, 3 `#[\AllowDynamicProperties]`, `Serializable` → `__serialize`, 2 `(string)` casts, escaper and IDNA API changes. Served under `php8.3` with a real session; swept 21 pages as admin, reseller and customer | 20 of 21 render; 0 deprecations; the one failure is `src/SystemInfo.php:501` (`list()` off a short `preg_split`), an admin-only diagnostics page |
| `shardj/zf1-future` covers the panel's Zend surface | 22 component checks — Acl, Form, Navigation, Translate, Locale, Cache, Session, Validate, Filter, File_Transfer, Date, View, FlashMessenger, Json, Config, Registry, Uri — on 8.3, 8.4 and 8.5 | All pass on all three; zero deprecations from ZF1 |
| Both `#[...]` attribute fixes are 7.3-safe | `php7.3 -l` and `php8.3 -l` on all six touched files | Clean on both — `#[...]` is an attribute on PHP 8 and a comment on PHP 7, so one source tree serves both |
| **PHP 7.4 needs no changes at all** | Unmodified `gui/` and unmodified 7.3-era `vendor/`, served under `php7.4`, same 21-page sweep | 21 of 21 render; 0 deprecations; 0 syntax failures in panel code or in ZF1, idna-convert, Slim or Flysystem |
| `webonyx/graphql-php` v15 and `lcobucci/jwt` v4 are available from 7.4 | `composer update` with `platform.php = 7.4.33` | graphql-php v15.37.2, lcobucci/jwt 4.3.0 |
| `simpod/graphql-utils` is *not* available with v15 on 7.4 | Same | 0.5.0–0.5.3 pin graphql-php `^14`; 0.5.4 requires PHP `^8.0`. On 7.4 you may have v15 or simpod, not both |
| Anorm v3.1.1 runs unmodified from 7.4 | `php7.4 -l` and `php8.3 -l`, one invocation per file | 31 files, 0 failures on both. The three typed-property files that fail on 7.3 are fine from 7.4 |
| Anorm has DataLoader-shaped batching | Read `src/Relationship/BatchLoadingOrchestrator.php` and `BatchLoader/*` | `loadRelationshipsForModels(array $models, array $relationshipSpecs)` takes field-selection specs of the form `['posts', 'company:name,address']` — the shape of a GraphQL `ResolveInfo` |
| Upstream i-MSCP is dormant; the fork is already substantial | `git remote -v`, `git log`, `git for-each-ref` | `upstream` = `i-MSCP/imscp`, not fetched; 11,054 commits on `saygoweb/imscp` `main`; the last fortnight added the Trixie port, the Dovecot 2.4 port and three Courier fixes |
