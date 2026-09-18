# SGW_GraphQL — specification

A GraphQL API for i-MSCP, delivered as an i-MSCP plugin. It lets a customer,
a reseller or an administrator authenticate as themselves and perform, over
HTTP, the operations the panel would let them perform in its web interface —
and only those.

Status: **accepted; implementation follows**. Reviewed 2026-09-04 against
[variant B](SPECIFICATION-IN-CORE.md), which proposed building the same API
inside the i-MSCP codebase. Three decisions came out of that review and are
recorded here:

1. **Build it as a plugin** — this document — rather than in core.
2. **Target PHP 7.4.x first**, not 8.3. §2.5.
3. **The duplication this design forces is a backlog, not a permanent cost.**
   Variant B's case rested on the fact that a plugin cannot change core, so it
   must duplicate. It can, however, change core *separately*: each thing the
   plugin is forced to duplicate becomes an improvement to `saygoweb/imscp`
   that is worth making on its own merits, and that then lets the plugin delete
   its copy. §21 is that list, and it is the part of this document most likely
   to be acted on first.

Facts about i-MSCP quoted below were read out of
`/home/cambell/src/sgw/imscp` at commit `7d8788400`, and the PHP-version and
dependency claims were measured inside the running `imscp_debian_trixie` box,
not assumed. See [Appendix A](#appendix-a--what-was-measured).

---

## 1. Scope

### 1.1 In scope

| Actor | May create, update and delete |
| --- | --- |
| Customer | Subdomains, alias subdomains, domain aliases (subject to the ordering rules), mail accounts, mail catch-alls, mail autoresponders, FTP users, SQL databases, SQL users, custom DNS records; and may edit their own main domain's forwarding and document root |
| Reseller | Customers (which is to say: accounts, their main domain, their limits and their features), hosting plans, and the approval of alias orders; plus everything a customer may do, on their own customers |
| Administrator | Resellers; plus everything a reseller may do |

Reads are available for every entity that is writable, plus the account graph,
quotas, disk and traffic usage, server IP addresses, and the provisioning state
of anything in flight.

### 1.2 Out of scope

Deliberately, and for the first major version:

* Administrator-level system operations — server settings, service
  configuration, IP address management, plugin management, the update
  mechanism, database maintenance. The user asked for none of it, and each is a
  way to break a server from a stolen token.
* Creating or deleting administrator accounts.
* The ticket/support system, the software installer (APS), backups,
  protected areas (`.htaccess`), custom error pages, and the PHP editor's
  per-directive settings. These are candidates for a later version;
  §18 says how they would be added without breaking clients.
* Subscriptions. There is no long-lived process in the panel to hold them
  open. §8.2 says what clients do instead.

---

## 2. The ground this stands on

Six properties of i-MSCP shape every decision that follows. They are not
incidental; a design that ignores any of them produces an API that lies to its
callers.

### 2.1 Provisioning is asynchronous, and the panel is only half of it

The panel never provisions anything. A write sets a row's status column to a
verb — `toadd`, `tochange`, `todelete`, `toenable`, `todisable` — and then
opens a socket to the i-MSCP daemon on `127.0.0.1:9876` and says
`execute query` (`gui/include/Shared.php:2251`, `send_request()`). The daemon
runs the Perl backend, which does the real work — writes the vhost, creates
the system user, provisions the maildir — and then sets the status to `ok`, or
to the text of whatever went wrong.

So a mutation cannot return a finished resource. It returns an intent. **This
is the single most important thing the schema has to get right**, and §8.2 is
where it is dealt with.

There is one exception, and it is an instructive one: SQL databases and SQL
users are created *synchronously*, by the frontEnd itself, with `CREATE
DATABASE` and `GRANT` issued inline (`gui/public/client/sql_database_add.php`).
The `sql_database` and `sql_user` tables have no status column at all. The
schema reflects that asymmetry rather than papering over it — see §7.7.

### 2.2 Ownership is a chain, and every authorisation question is a walk up it

```
admin (admin_type='admin')
  └── admin (admin_type='reseller')        admin.created_by = the administrator
        └── admin (admin_type='user')      admin.created_by = the reseller
              └── domain                   domain.domain_admin_id = the customer
                    ├── subdomain                subdomain.domain_id
                    ├── domain_aliasses          domain_aliasses.domain_id
                    │     └── subdomain_alias    subdomain_alias.alias_id
                    ├── mail_users               mail_users.domain_id
                    ├── sql_database             sql_database.domain_id
                    │     └── sql_user           sql_user.sqld_id
                    └── domain_dns               domain_dns.domain_id / alias_id
              └── ftp_users                ftp_users.admin_id (the customer, not the domain)
```

Every object in the API resolves to exactly one owning customer, and every
customer to exactly one reseller. Authorisation is: resolve the object's owner,
and check the caller is that owner, or that owner's reseller, or an
administrator. §6.3.

### 2.3 A customer's rights are three separate things

The panel asks three different questions before it lets a customer do
anything, and they are easy to conflate:

1. **Is the feature available at all?** `customerHasFeature('subdomains')`
   (`gui/include/Client.php:82`), which is derived from the customer's own
   limit columns and from server configuration.
2. **Is the limit reached?** `get_customer_subdomains_count()` and its
   siblings in `gui/include/Counting.php`, compared against
   `domain.domain_subd_limit`.
3. **Is the object in a state that may be changed?** An object whose status is
   not settled is mid-flight in the backend, and a second write would leave
   the backend acting on half of one change and half of the next.

All three produce different API errors (§9), and all three must be asked
before a write. The limit encoding is a trap worth naming: on a customer,
`-1` means *the feature is withheld*, `0` means *unlimited*, and `n > 0` means
*n*. On a reseller (`reseller_props.max_*_cnt`), `0` means unlimited and there
is no withheld state. §7.4 normalises both into one `Quota` type so no client
ever has to know this.

### 2.4 The business logic lives in the page scripts, and is not callable

`gui/public/client/subdomain_add.php` is 582 lines. About 250 of them are the
actual rules for adding a subdomain — reserved labels, mount point derivation,
shared mount points, forward URL validation, the `php_ini` row, the default
mail accounts — and they are written directly against `$_POST`, `$_SESSION`,
`set_page_message()` and `redirectTo()`. They are not a function. They cannot
be called.

A handful of genuinely reusable functions *do* exist — `deleteSubdomain()`,
`deleteDomainAlias()`, `deleteCustomer()`, `delete_sql_database()`,
`sql_delete_user()`, `change_domain_status()`, `createDefaultMailAccounts()`,
`reseller_limits_check()`, all of `Counting.php`, and the validators in
`Input.php`. But most of *those* read `$_SESSION['user_id']` directly and, on
a failure they do not like, call `showBadRequestErrorPage()`.

Which does something that matters a great deal here. `showErrorPage()`
(`gui/include/View.php:932`) checks the request's `Accept` header, and if it
contains `application/json`:

```php
header("Content-type: application/json");
exit(json_encode(['code' => $code, 'message' => $message]));
```

A GraphQL client sends `Accept: application/json`. So any core helper that
takes exception to its input will not raise — it will terminate the request
with a non-GraphQL body and an HTTP status the client did not expect. §6.5
says what the plugin does about that.

### 2.5 The frontEnd runs on PHP 7.3 today, and moves to 7.4 first

As shipped, the panel runs PHP 7.3 even on Debian 13. `gui/composer.json`
requires `>=7.3 <7.4`; `engine/PerlLib/iMSCP/Requirements.pm:129` enforces
`7.3.0`–`7.3.999`; `configs/debian/trixie/imscp.conf` pins Composer to the 2.2
LTS branch precisely because of it.

**The decision is to move the panel to PHP 7.4.x before this plugin is built,
and to 8.3 later as a separate piece of work.**

7.4 is free, and that is why it goes first. Measured in the box: the
*unmodified* panel, with its *unmodified* 7.3-era dependency tree, runs on 7.4
with 21 of 21 pages rendering, zero deprecations and zero syntax failures —
across the panel's own code and across ZF1, idna-convert, Slim and Flysystem.
There is nothing to patch. The move is two values:
`PHP_FPM_BIN_PATH` in `configs/debian/default/frontend/frontend.data.dist:26`,
and the version gate in `Requirements.pm:129`.

And 7.4 is enough for everything this plugin wants. `webonyx/graphql-php` v15
and Anorm v3.1.1 both need 7.4 and neither needs anything beyond it; on 7.3
the first was unavailable and the second required a patch. §3.3.

8.3 is the destination, not the starting point. It costs roughly a week: three
dependency replacements (ZF1 → `shardj/zf1-future`, zend-escaper →
`laminas/laminas-escaper`, idna-convert v2 → v3), 25 `#[\ReturnTypeWillChange]`
attributes, three `#[\AllowDynamicProperties]`, and four small code fixes —
measured, with 20 of 21 pages rendering and no deprecations left. It buys
things 7.4 does not: a PHP with actual security support, Roundcube 1.7 (which
needs 8.1 and replaces the unsupported 1.3.15 currently shipped), and
phpMyAdmin 6. None of that is on this plugin's critical path, which is why it
is sequenced after.

**What this means for the plugin's own code.** It is written to PHP 7.4 —
typed properties, arrow functions and `??=` are available; constructor
promotion, `match`, enums, named arguments and nullsafe calls are not. It is
also written to *parse and run unchanged on 8.3*, so that the later panel
migration costs the plugin nothing: nothing removed in 8.0, and
`#[\ReturnTypeWillChange]` / `#[\AllowDynamicProperties]` carried from day one
where they will be needed. Both are comments on 7.4 and attributes on 8.x.
§17 requires the test suite to lint on both.

### 2.6 Any URL that is not a file already reaches the plugin router

The panel's nginx vhost (`configs/debian/default/frontend/00_master.nginx`)
ends every `location` in a fall-through to `@plugin`, which is
`public/plugins.php`, which builds a Slim 3 application and hands it to
`PluginRoutesInjector`. That injector reads each loaded plugin's `getRoutes()`
and accepts full route specifications — pattern, methods, PSR-7 handler,
per-route and per-group middleware (`gui/src/Plugin/PluginRoutesInjector.php`).

So `POST /api/graphql` needs no web server change, no core patch, and no
`.php` in its path. A plugin can also register services into the Slim
container via `getServiceProvider()`.

---

## 3. Decisions

Each of these is the answer to a real fork in the road. The rationale is the
point; the decision on its own is not reviewable.

### 3.1 The API runs inside the panel, as a plugin

**Decision.** `SGW_GraphQL` is an ordinary i-MSCP plugin. It registers
`POST /api/graphql` through `getRoutes()` and runs inside the panel's own
PHP-FPM pool, bootstrap, database connection and event manager.

**Rejected: a standalone PHP service** beside the panel, talking to the same
database, on a modern PHP. It is tempting — it would lift the PHP 7.3
constraint entirely and let Anorm and any current library in unmodified. It
was rejected because it would sit *outside* the event manager. Every write the
panel makes dispatches `onBeforeAddSubdomain`, `onAfterDeleteCustomer` and
about 125 other events, and the other plugins on this box — `SGW_ApacheCache`,
`SGW_LetsEncrypt`, `SGW_DnsExternal`, `SGW_PostfixAuth` — listen to them. An
API that wrote rows without dispatching would silently desynchronise every one
of them: a domain deleted through the API would leave its cache configuration
and its certificate behind. Re-creating the event system outside the panel is
not a smaller problem than living with PHP 7.3.

**Cost accepted.** PHP 7.3, and the library consequences in §3.3.

### 3.2 Business logic is reimplemented in a service layer, not scraped from the pages

**Decision.** The plugin owns a `Service\` layer: one class per entity, with
methods that take typed input and a caller identity, and that perform
validation, authorisation, the transaction, the event dispatch, the
`send_request()` and the audit log. The page scripts are read as the
*specification* of each operation, and the rules are transcribed, with a
comment pointing at the page and line the rule came from.

**And the transcription is provisional.** Every rule copied here is a rule that
exists twice, and each of those is an entry in §21 — a change to
`saygoweb/imscp` that extracts the rule into something callable, improves the
panel on its own merits, and then lets this plugin delete its copy. The
duplication is therefore a debt with a repayment schedule rather than a
permanent property of the design, and §21 says how it is tracked so that it
stays visible.

**Not negotiable**, given §2.4 — the logic is not callable. What *is* a choice
is how much of the surrounding scaffolding to reuse, and the answer is: as
much as is safely reusable, listed explicitly.

| Core facility | How it is used |
| --- | --- |
| `exec_query()`, `DatabaseMySQL` | Used directly. Same connection, same transaction. |
| `EventAggregator::dispatch()` | Used directly, with the same event names and the same parameter arrays the pages pass. This is the whole reason for §3.1. |
| `send_request()` | Used directly. |
| `write_log()` | Used directly, so API actions appear in the panel's admin log alongside UI actions. |
| `Counting.php` (all of it) | Used directly. Pure counting, no session, no exits. |
| `Input.php` validators — `validates_username()`, `checkPasswordSyntax()`, `chk_email()`, `isValidDomainName()`, `clean_input()` | Used directly, wrapped so their `set_page_message()` side effects are captured rather than emitted. |
| `encode_idna()` / `decode_idna()` | Used directly. |
| `Crypt::apr1MD5()`, `Crypt::sha512()` | Used directly. Passwords must be hashed exactly as the panel hashes them or the backend will not accept them. |
| `PhpEditor` | Used directly, for the `php_ini` rows a new subdomain or customer needs. |
| `customerHasFeature()`, `resellerHasFeature()`, `customerSqlDbLimitIsReached()`, `get_domain_default_props()` | **Not called.** Each reads the customer from `$_SESSION['user_id']` or caches for the request without a key, so it answers for the caller rather than for the customer a reseller is acting on. Transcribed with `CORE-DEBT(C1)` markers. |
| `deleteSubdomain()`, `deleteSubdomainAlias()`, `deleteDomainAlias()`, `delete_sql_database()`, `sql_delete_user()`, `change_domain_status()` | **Not called.** The first two authorise on `$_SESSION['user_id']` and exit on a miss; `deleteDomainAlias()` swallows its own failure; the SQL two interleave DDL with row writes. Transcribed with `CORE-DEBT` markers. |
| Everything the API path does call | Through one class, `Service\PanelCore`, and only functions that take every identity explicitly, report failure by value or exception, cannot reach `exit`, and issue no DDL. `test/unit/Security/CoreCallsTest.php` holds the list and fails on any other. |
| `set_page_message()`, `redirectTo()`, `showBadRequestErrorPage()`, `TemplateEngine`, the whole `View.php` layer | Never called from the API path. |
| `VirtualFileSystem` | Used for the FTP home-directory existence check, but behind a config switch (§14) — it creates a temporary FTP account and opens an FTP connection for every check, which is a heavy price on an API. |

### 3.3 Dependencies: `webonyx/graphql-php` v15 and Anorm, and nothing else

**Decision.** Two runtime dependencies, vendored into the release archive:

| Package | Role |
| --- | --- |
| `webonyx/graphql-php ^15` | The schema engine. Needs PHP 7.4, which §2.5 provides. Measured: v15.37.2 resolves and installs with `platform.php = 7.4.33`. |
| `saygoweb/anorm ^3.1` | The **read** models and their batch loading. See below. Measured: v3.1.1 lints clean on 7.4 and on 8.3, 31 files, unmodified. |

**Anorm, on the read side only.** An earlier draft of this section rejected
Anorm outright, for a reason that had nothing to do with the PHP version: a
write in this system is never "persist a model". It is validate → check quota →
open a transaction → dispatch a before-event → INSERT with a `toadd` status →
write a `php_ini` row → create default mail accounts → dispatch an
after-event → commit → poke the daemon → `write_log()`. An ORM helps with one
step in ten. **That reasoning is unchanged, and the write side of the
`Service\` layer stays on `exec_query()`.**

The read side is a different problem, and Anorm fits it unusually well. §10.1
requires DataLoader-style batching, so that "list my customers, and for each
their domain and its subdomains" is not an N+1. Anorm v3 already ships it:
`Relationship\BatchLoadingOrchestrator` over `ManyHasOneBatchLoader`,
`OneHasManyBatchLoader` and `ManyHasManyBatchLoader` — and, the part that
matters, `loadRelationshipsForModels()` takes a **field selection**
specification of the form `['posts', 'company:name,address']`, which is the
shape of a GraphQL `ResolveInfo`. Feeding a resolver's requested fields into
Anorm's batch loader is a mapping, not an implementation.

The cost is two idioms in one codebase — Anorm for reads, `exec_query()` for
writes. Each is defensible on its own side; the pair needs justifying, and the
justification is that they are genuinely different problems. The cheapest
reversal is to drop Anorm and hand-write the batch loaders; the decision point
is the start of phase 2.

**Rejected: `simpod/graphql-utils`**, which the `emdc-events` API uses for its
`ObjectBuilder` / `FieldBuilder` fluent API. On PHP 7.4 it cannot be had
alongside graphql-php v15 at all: 0.5.0–0.5.3 pin `webonyx/graphql-php ^14`,
and 0.5.4 requires PHP `^8.0`. Measured. §3.4 removes the need for it anyway,
and this is one of that decision's three supporting arguments.

**Rejected: JWT (`lcobucci/jwt`)**, which `emdc-events` uses. v4 is available on
7.4 — measured, 4.3.0 installs — so this is a design choice rather than a
constraint. A token that can create system users and SQL grants must be
revocable *now*, which means a database lookup on every request, at which point
the token does not need to carry signed claims. §5 uses opaque tokens instead,
and gains a dependency-free implementation and per-token revocation, expiry,
scoping, IP allow-listing and last-used tracking.

**Vendoring.** Both dependencies ship inside `SGW_GraphQL.tgz`, resolved with
`config.platform.php` pinned to the panel's PHP so the archive can never be
built against a newer PHP than the panel runs. When the panel moves to 8.3 the
pin moves with it and nothing else changes: neither dependency has an upper
bound below 8.3.

### 3.4 Schema-first (SDL), not code-first

**Decision.** The schema is one file, `schema/schema.graphql`, in SDL. It is
loaded with `BuildSchema::build()`, its parsed AST is cached to disk, and
resolvers are attached through a resolver map keyed by `Type.field`.

**Rejected: code-first**, the `emdc-events` style of one PHP class per type.
It is the idiom already in use in the neighbouring codebase and there is real
value in that. But:

* This document's reviewers need to read the schema, and a 400-line SDL file
  is reviewable in a way that 60 PHP classes are not. That matters right now,
  during the review this specification exists to invite.
* It removes the `simpod/graphql-utils` problem of §3.3 rather than working
  around it — and on PHP 7.4 that problem is absolute, not merely awkward:
  simpod cannot coexist with graphql-php v15 at any version.
* The SDL file is the artefact clients generate their types from. Having it be
  the source rather than a dump removes a whole class of drift.
* A completeness test (§17) can assert that every field in the SDL has either
  a resolver or a working default, which recovers most of what the
  class-per-type structure gave for free.

**Rejected: nested mutator objects** — `mutation { subdomain { create(...) } }`,
the `MutatorType` pattern from `emdc-events`. The GraphQL specification
guarantees serial execution only for the *top-level* selections of a mutation.
Fields nested one level deeper execute in parallel, so two operations inside
one mutator object have no defined order. For an API whose operations create
system users and databases, that is a correctness problem, not a style
preference. Mutations are therefore flat and named `entityVerb`:
`subdomainCreate`, `subdomainUpdate`, `subdomainDelete`.

### 3.5 Identifiers are opaque and globally unique

**Decision.** Every `ID` is `base64url("<TypeName>:<primary key>")`, e.g.
`U3ViZG9tYWluOjM` for `Subdomain:3`. A `Query.node(id: ID!): Node` field
resolves any of them.

i-MSCP's identifiers are per-table integers, and `subdomain_id = 3`,
`alias_id = 3` and `mail_id = 3` all exist and belong to different customers.
An API that took bare integers would depend on every single resolver checking
that the caller supplied an identifier of the right *kind* before checking
ownership. Encoding the type into the identifier makes the wrong kind a parse
error at the edge, before any resolver runs. This removes an entire class of
authorisation bug for the cost of one codec.

The encoding is not a secret and is not claimed to be one; it is a type tag,
not a capability.

### 3.6 Errors are GraphQL errors with structured extensions

**Decision.** A failed mutation returns `null` for its field and adds an entry
to `errors` carrying `extensions.code`, and where applicable
`extensions.field` and `extensions.limit`. There is no `userErrors` payload
type.

**Rejected: the payload/`userErrors` pattern.** It earns its place when one
mutation can partially succeed across several sub-objects. Every mutation here
is a single entity in a single transaction: it either happened or it did not.
The payload wrapper would add a type per mutation and a level of nesting to
every client, to express a state that cannot occur.

---

## 4. Transport

| | |
| --- | --- |
| Endpoint | `POST /api/graphql` (configurable, §14) |
| Request | `Content-Type: application/json`, body `{"query": …, "variables": …, "operationName": …}` |
| Response | `application/json`, always a GraphQL response envelope |
| Methods | `POST` for everything; `OPTIONS` for CORS preflight. `GET` is **not** accepted, for any operation. |
| Schema | `GET /api/graphql/schema` returns the SDL as `text/plain`, for tooling |
| Explorer | `GET /api/graphql/explorer`, a session-authenticated GraphiQL page, off by default (§14) |

`GET` is refused even for queries. It is the only mechanism by which a
GraphQL endpoint can be driven from a `<img>` tag or a link, and nothing here
needs HTTP caching of query results badly enough to pay for that.

**HTTP status codes.** `200` when the response envelope is a GraphQL result,
whatever is in it — including field errors. `400` for a malformed request body
or a query that fails validation. `401` when authentication is absent or bad.
`403` when the identity is valid but API access has been withdrawn (§6.2).
`405` for a method other than `POST`/`OPTIONS`. `429` when rate limited, with
`Retry-After`. `500` when the request fails *before* the document executes at
all — a wiring fault, or an unexpected throw around execution (§9). `503` when
the plugin is disabled.

Note the deliberate split: authentication failures are transport-level (`401`),
because there is no useful GraphQL result to return; authorisation failures
*within* an authenticated request are field-level errors in a `200` (§9),
because a query may legitimately be allowed to read some fields and not others.
The same reasoning separates the two `INTERNAL` rows in §9: a server fault that
happens once the document is running is folded into the result envelope and is
a `200`, because a GraphQL result really was produced; one that happens before
execution has no result to report and is a `500`. `400` stays reserved for what
is genuinely the client's to fix — a malformed body, or a query that fails
validation.

**CORS.** Off by default. `allowed_origins` in `config.php` is a list, never
`*`, and credentials are never allowed for cookie-authenticated requests from
another origin.

**TLS.** With `require_tls` on (the default), a request that did not arrive
over HTTPS is refused with `403` and a body saying so, and — because the
credential has already been transmitted in the clear at that point — any bearer
token presented on such a request is revoked and its owner is told. That is
harsh, and it is correct: the alternative is a token that has been sighted by
every hop in between and is still valid.

**Behind a proxy.** Where nginx or Apache terminates TLS and proxies to
PHP-FPM, the backend sees scheme `http`, no `HTTPS` and port 80, so the
connection alone cannot answer the question — and answering it wrongly is
expensive in both directions: call a proxied HTTPS request insecure and the
first correctly configured client has its token revoked, believe a client that
merely claims to have been proxied and the requirement is gone. So
`X-Forwarded-Proto` (and `X-Forwarded-SSL`) is honoured only when `REMOTE_ADDR`
matches `trusted_proxies` in `config.php`, a list of addresses and CIDR ranges
that is empty by default. Empty means nothing is believed and the connection is
asked, which is exactly the behaviour before the key existed. The client's own
leg is the *first* entry of a forwarded chain, not the last, and `SERVER_PORT`
is not consulted at all: a port says what was listened on, never what was
spoken on it.

In the shipped deployment this revocation is a defence in depth, not a control
that fires against a client using `http://` today: nginx 302-redirects plain
HTTP to HTTPS before PHP runs at all, so the middleware never sees such a
request, and the behaviour above is verified in-process instead. The control
exists for a front end that is misconfigured or absent — a reverse proxy
terminating TLS elsewhere, a direct FPM bind — and in the standard install the
redirect gets there first. Do not read this as protection against a client
that actually sends `http://` today: the secret has already crossed the wire
in clear by the time either mechanism acts.

**The schema route sits outside this pipeline, deliberately.** `GET
/api/graphql/schema` passes through none of the TLS, CORS or authentication
middleware above; it serves the SDL as `text/plain` to anyone who asks, over
plain HTTP if that is what is sent. That is by design: the SDL is "for
tooling" (see the table above), introspection defaults to `true`
(`config.php`), so this design does not treat the schema as secret, and an
unauthenticated client presents no credential for the route to leak. Two
consequences follow from the omission. First, the route carries no CORS
headers, so browser-based tooling running on another origin cannot read it —
a client wanting that must ship the SDL alongside itself rather than fetching
it live. Second, a client that sends an `Authorization` header to the schema
endpoint over plain HTTP leaks a bearer token that `TlsMiddleware` would
otherwise have revoked, because that middleware is exactly what this route
does not pass through.

---

## 5. Authentication

### 5.1 Bearer tokens (primary)

```
Authorization: Bearer imscp_<prefix>_<secret>
```

* `prefix` is 8 base32 characters, unique, stored in the clear, indexed. It is
  what the lookup is done on, and what the panel UI shows so a user can tell
  their tokens apart.
* `secret` is 32 bytes from `random_bytes()`, base64url encoded (43 chars).
  It is shown to the user exactly once, at creation, and never again.
* Stored as `hash('sha256', secret)`, compared with `hash_equals()`.

Presenting a token proves an identity and carries a set of scopes. A token can
never do more than the identity it belongs to could do; the scopes only ever
narrow.

Verification, in order: parse the header; look up by prefix; constant-time
compare the hash; reject if revoked or expired; reject if the client IP is
outside the token's allow-list, if it has one; reject if the owning account's
`admin_status` is not `ok`; reject if API access has been withdrawn from the
account (§6.2); stamp `last_used_at` and `last_used_ip` (§11 explains why that
write is cheap). Any failure is a `401` with no detail about which check failed.

### 5.2 Panel session (secondary)

If a request carries a valid panel session cookie and no `Authorization`
header, the session identity is used. This makes the in-panel explorer work,
and makes a future panel-embedded UI possible without minting a token for it.

It is a cookie, so it is CSRF-exposed, and the mitigations are stacked
deliberately:

1. `Content-Type: application/json` is required. A form cannot send it, so a
   cross-origin `POST` from a form is a preflighted request, and §4's CORS
   policy fails the preflight.
2. An `X-iMSCP-CSRF` header must match `$_SESSION['graphql_csrf']`, which the
   panel pages emit. A cross-origin attacker cannot read it.
3. `GET` is refused (§4), so there is no link-driven path at all.

If any of those is missing, the request is treated as unauthenticated. Session
authentication may be disabled entirely with `allow_session_auth = false`.

### 5.3 Obtaining a token from credentials

```graphql
mutation { tokenIssue(input: { username: "…", password: "…", name: "ci", scopes: [DOMAINS_WRITE], expiresInDays: 90 }) { token expiresAt } }
```

This is the only unauthenticated field in the schema. It goes through
i-MSCP's own `AuthService::authenticate()`, so it inherits the credential
handler, the `BruteForce` plugin when `BRUTEFORCE` is on, the account status
and expiry checks in `login_checkDomainAccount()`, and the APR-1 rehash of
legacy MD5 passwords — none of which is reimplemented. It does **not** create a
`login` row or a session.

It is rate limited far more tightly than anything else (§10.3), and it can be
disabled with `allow_password_grant = false` for installations that want tokens
minted only through the panel UI.

### 5.4 What is deliberately absent

No OAuth, no refresh tokens, no API-key-in-query-string, no HTTP Basic. Each
adds a credential path to defend for a category of client that a hosting
control panel does not have.

---

## 6. Authorisation

### 6.1 Roles

`admin.admin_type` gives `admin`, `reseller` or `user`, exposed as
`Role.ADMIN`, `Role.RESELLER`, `Role.CUSTOMER`. It is read from the database
on every request, not carried in the token, so a demotion takes effect at once.

### 6.2 Whether the account may use the API at all

A `graphql_perm` table (§15) holds one row per account whose API access has
been changed from the default. A reseller may withdraw API access from any of
their customers; an administrator from any reseller. With
`allowed_by_default = true` (the default), an account with no row may use the
API. This mirrors `SGW_ApacheCache`'s `apache_cache_perm` exactly, so the
behaviour is already familiar on this panel.

Withdrawal revokes that account's tokens as well as blocking the endpoint.
A feature and its effects should go away together.

### 6.3 Ownership

One resolver, used by everything:

```php
OwnershipResolver::ownerOf(GlobalId $id): int   // returns the owning customer's admin_id
OwnershipResolver::mayReach(Identity $caller, int $ownerId): bool
```

`mayReach` is true when the caller *is* the owner; or the caller is a reseller
and `admin.created_by = caller` for that owner; or the caller is an
administrator. Reseller-owned objects (hosting plans, the resellers
themselves) go through the same function one level up.

The resolution is a single query per object type, derived from the chain in
§2.2, and it is checked **before** anything else — before feature checks,
before quota checks, before validation. A caller who may not reach an object
gets `NOT_FOUND`, not `FORBIDDEN`, so the API does not confirm the existence
of other people's objects. `FORBIDDEN` is reserved for objects the caller can
see but may not change.

### 6.4 The identity shim

Several core helpers the plugin reuses read `$_SESSION['user_id']`,
`$_SESSION['user_type']`, `$_SESSION['user_logged']` and
`$_SESSION['user_created_by']` directly (§3.2). Rather than fork them, the
plugin populates exactly those four keys plus `user_identity` from the
authenticated identity, at the start of request handling, without writing a
`login` row and without starting a browser session.

Two rules make this safe, and both are enforced in code, not by convention:

* **One identity per request.** `customerHasFeature()` caches its result in a
  `static` that is not keyed by user (`gui/include/Client.php:84`). A request
  that switched identity would read the first identity's features for the
  second. The plugin therefore refuses to change identity mid-request, and
  there is no impersonation field in the schema.
* **The shim is set up once, in one place**, and is asserted to be consistent
  with the resolved identity before every core call that depends on it.

This is a compromise and is named as one. The alternative — forking six
functions to take an explicit `$adminId` — was rejected because those forks
would then have to be re-audited against upstream on every i-MSCP update, and
a silently stale fork of an authorisation helper is a worse failure than a
documented shim.

### 6.5 The `showErrorPage()` hazard

§2.4 established that a core helper can terminate the request with a
non-GraphQL JSON body. Three layers deal with it:

1. **No exiting helper is called.** The API path calls a global panel function only through `Service\PanelCore`, and only functions that cannot reach `exit` (§3.2). `CoreCallsTest` enforces the list by tokenising the source, so a helper that can exit cannot be reached by accident.
2. **A shutdown guard.** The GraphQL handler registers a shutdown function.
   If the script terminates while a GraphQL operation is in flight, it emits a
   well-formed GraphQL error envelope with `code: INTERNAL` and logs the fact
   loudly. A caller gets a response it can parse even when layer 1 has a hole.
3. **A test that asserts layer 1 holds.** §17.

---

## 7. The schema

The full SDL lives at `schema/schema.graphql`. What follows is that file,
with commentary. Descriptions are abbreviated here and complete in the file.

### 7.1 Scalars and provisioning

```graphql
"ISO-8601 instant in UTC, e.g. 2026-09-04T11:00:00Z."
scalar DateTime

"A 64-bit unsigned integer serialised as a decimal string, because a JSON
 number is a double and disk and traffic figures exceed 2^53."
scalar BigInt

"A domain name. Accepted as Unicode or as punycode; always returned as Unicode."
scalar DomainName

"An email address."
scalar EmailAddress

"A write-only value. Never appears in a response, and is redacted from the
 audit log. Passwords are of this type."
scalar Secret

interface Node { id: ID! }

"""
Whether the i-MSCP backend has caught up with the panel's intent. See §2.1:
a mutation records an intent and the backend carries it out afterwards.
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
  "toadd, tochange, todelete, toenable, todisable, torestore, …"
  PENDING
  "disabled"
  DISABLED
  "ordered — a domain alias awaiting the reseller's approval"
  ORDERED
  "Anything else. i-MSCP stores the backend's failure text in the status column."
  ERROR
}

interface Provisioned { provisioning: Provisioning! }
```

`raw` is not decoration. i-MSCP's status vocabulary is open — plugins and
future versions add verbs — and a client that has to handle an unknown state
is better served by seeing the string than by having it flattened into
`PENDING` with no way to find out more.

### 7.2 Identity

```graphql
enum Role { ADMIN RESELLER CUSTOMER }

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

enum Scope {
  ACCOUNT_READ
  DOMAINS_READ    DOMAINS_WRITE
  MAIL_READ       MAIL_WRITE
  FTP_READ        FTP_WRITE
  SQL_READ        SQL_WRITE
  DNS_READ        DNS_WRITE
  CUSTOMERS_READ  CUSTOMERS_WRITE
  RESELLERS_READ  RESELLERS_WRITE
}

type ContactDetails {
  firstName: String  lastName: String  gender: Gender
  company: String
  street1: String  street2: String  city: String  state: String
  postCode: String  country: String
  email: EmailAddress  phone: String  fax: String
}

enum Gender { MALE FEMALE UNSPECIFIED }
```

### 7.3 Virtual hosts

The four vhost kinds — main domain, subdomain, alias, alias subdomain — differ
in their tables and almost nowhere else. i-MSCP itself keys them by the pair
`(type, id)` with type in `dmn|sub|als|alssub`; the schema expresses the same
thing as an interface, which lets a client render a list of "everything Apache
serves for this customer" without four code paths.

```graphql
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

enum ForwardType { PERMANENT_301 FOUND_302 SEE_OTHER_303 TEMPORARY_307 PROXY }

type Domain implements Node & Provisioned & VirtualHost {
  id: ID!  name: DomainName!  mountPoint: String!  documentRoot: String!
  forwarding: Forwarding  wildcard: Boolean!  provisioning: Provisioning!
  customer: Customer!
  ipAddress: IpAddress!
  createdAt: DateTime!
  expiresAt: DateTime
  subdomains: [Subdomain!]!
  aliases: [DomainAlias!]!
}

type Subdomain implements Node & Provisioned & VirtualHost {
  id: ID!  name: DomainName!  mountPoint: String!  documentRoot: String!
  forwarding: Forwarding  wildcard: Boolean!  provisioning: Provisioning!
  "The label alone, without the parent's name."
  label: String!
  "A Domain, or a DomainAlias when this is an alias subdomain."
  parent: VirtualHost!
  customer: Customer!
}

type DomainAlias implements Node & Provisioned & VirtualHost {
  id: ID!  name: DomainName!  mountPoint: String!  documentRoot: String!
  forwarding: Forwarding  wildcard: Boolean!  provisioning: Provisioning!
  domain: Domain!
  customer: Customer!
  subdomains: [Subdomain!]!
}
```

An alias subdomain is a `Subdomain` whose `parent` is a `DomainAlias`. Making
it a fifth type would expose an i-MSCP storage detail (`subdomain_alias` is a
separate table only because `subdomain` has a `domain_id` column) for no gain.

### 7.4 Quotas

```graphql
"""
One countable allowance. Normalises i-MSCP's three-valued limit columns:
-1 (feature withheld), 0 (unlimited), n (n). See §2.3.
"""
type Quota {
  "False when the feature is withheld from the account entirely."
  enabled: Boolean!
  "Null when unlimited."
  limit: Int
  used: Int!
  "limit - used, or null when unlimited. Convenience; clients get it wrong."
  remaining: Int
}

type Storage {
  diskLimit: BigInt    diskUsed: BigInt!
  diskFiles: BigInt!   diskMail: BigInt!   diskSql: BigInt!
  trafficLimit: BigInt trafficUsed: BigInt!
  "Default per-mailbox quota. Null when unlimited."
  mailQuota: BigInt
}
```

### 7.5 Customer

```graphql
type Customer implements Node & Provisioned {
  id: ID!
  username: String!
  "The reseller's own reference for this customer (admin.customer_id)."
  reference: String
  contact: ContactDetails!
  "Null when the account's creator is not a reseller."
  reseller: Reseller
  createdAt: DateTime!
  expiresAt: DateTime
  domain: Domain!
  quotas: CustomerQuotas!
  storage: Storage!
  features: CustomerFeatures!
  "Whether this account may use this API at all. See §6.2."
  apiAccess: Boolean!
  provisioning: Provisioning!

  subdomains: [Subdomain!]!
  domainAliases: [DomainAlias!]!
  mailAccounts(filter: MailAccountFilter, page: PageInput): MailAccountConnection!
  ftpUsers(page: PageInput): FtpUserConnection!
  sqlDatabases: [SqlDatabase!]!
  sqlUsers: [SqlUser!]!
  dnsRecords: [DnsRecord!]!
}

type CustomerQuotas {
  subdomains: Quota!  domainAliases: Quota!  mailAccounts: Quota!
  ftpUsers: Quota!    sqlDatabases: Quota!   sqlUsers: Quota!
}

type CustomerFeatures {
  php: Boolean!  phpEditor: Boolean!  cgi: Boolean!
  customDns: Boolean!  externalMail: Boolean!
  backup: Boolean!  ssl: Boolean!  webStats: Boolean!  supportSystem: Boolean!
}
```

`features` is `customerHasFeature()`'s answer, verbatim and per name, so the
API cannot disagree with the panel about what a customer is allowed.

`reseller` is nullable because `admin.created_by` is: an account whose creator
is not a reseller has none to name, and a non-null field would resolve that row
to null, null the whole `Customer`, and — inside
`CustomerConnection.nodes: [Customer!]!` — null the page around it as well.

### 7.6 Mail

```graphql
type MailAccount implements Node & Provisioned {
  id: ID!
  address: EmailAddress!
  kind: MailAccountKind!
  "The vhost this address belongs to."
  host: VirtualHost!
  forwardTo: [EmailAddress!]!
  "Null when unlimited."
  quota: BigInt
  quotaUsed: BigInt
  "Whether POP/IMAP access is enabled (mail_users.po_active)."
  active: Boolean!
  autoresponder: Autoresponder
  provisioning: Provisioning!
}

enum MailAccountKind { MAILBOX FORWARD MAILBOX_AND_FORWARD CATCHALL }

type Autoresponder { enabled: Boolean!  message: String! }
```

i-MSCP stores twelve `mail_type` values — the cross product of
`{normal, alias, subdom, alssub}` and `{mail, forward, catchall}`, sometimes
comma-joined for a mailbox that also forwards. The vhost half of that product
is already carried by `host`, so the schema keeps only the second half. The
mapping table lives in the implementation, in one place, tested both ways.

### 7.7 FTP and SQL

```graphql
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
synchronously by the panel itself, not by the backend. See §2.1.
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
  databases: [SqlDatabase!]!
}
```

### 7.8 DNS, IP addresses, hosting plans, resellers

```graphql
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

enum DnsClass { IN CH HS }
enum DnsRecordType { A AAAA CERT CNAME DNAME GPOS KEY KX MX NAPTR NSAP NS NXT PTR PX SIG SRV TXT SPF }

type IpAddress implements Node {
  id: ID!  address: String!  netmask: Int  card: String
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

type Reseller implements Node & Provisioned {
  id: ID!
  username: String!
  contact: ContactDetails!
  createdAt: DateTime!
  quotas: ResellerQuotas!
  storage: Storage!
  ipAddresses: [IpAddress!]!
  apiAccess: Boolean!
  provisioning: Provisioning!
  customers(filter: CustomerFilter, page: PageInput): CustomerConnection!
  hostingPlans: [HostingPlan!]!
}
```

`hosting_plans.props` is a positional, semicolon-delimited string of 25 fields
(`gui/public/reseller/user_add3.php:133`). Parsing and re-emitting it is
delicate and must round-trip exactly; §20 lists it as a risk, and §17 requires
a property test for it.

### 7.9 Pagination

```graphql
input PageInput { limit: Int = 50, offset: Int = 0 }

type CustomerConnection    { totalCount: Int!  nodes: [Customer!]! }
type MailAccountConnection { totalCount: Int!  nodes: [MailAccount!]! }
type FtpUserConnection     { totalCount: Int!  nodes: [FtpUser!]! }
type ResellerConnection    { totalCount: Int!  nodes: [Reseller!]! }
```

Offset pagination, not cursors. A reseller has hundreds of customers, not
millions, the panel's own lists are offset-paged, and cursor connections would
add four types and a page-info object per list to solve a problem this data
does not have. `limit` is capped at 200 by the server regardless of what is
asked for.

Lists with a natural ceiling — a customer's subdomains, a database's users —
are plain lists. i-MSCP's own limits bound them.

### 7.10 Query

```graphql
type Query {
  "The schema version this endpoint serves, e.g. 1.3.0. See §18."
  apiVersion: String!
  viewer: Viewer!
  node(id: ID!): Node

  customer(id: ID!): Customer
  customers(filter: CustomerFilter, page: PageInput): CustomerConnection!
  reseller(id: ID!): Reseller
  resellers(filter: ResellerFilter, page: PageInput): ResellerConnection!

  "Everything the caller owns that the backend has not finished with. See §8.2."
  pending: [Node!]!
}

input CustomerFilter {
  username: String
  domainName: DomainName
  state: ProvisioningState
  "Administrators only; ignored for a reseller, who only ever sees their own."
  resellerId: ID
}
```

### 7.11 Mutations

Flat, `entityVerb`, one entity each (§3.4). `Create` returns the new object,
`Update` the changed one, `Delete` the object as it was at the moment deletion
was scheduled — which is the only moment its fields are still readable.

```graphql
type Mutation {
  # ---- credentials (see §5)
  "The one unauthenticated field in the schema."
  tokenIssue(input: TokenIssueInput!): IssuedToken!
  tokenRevoke(id: ID!): Boolean!

  # ---- the caller's own main domain
  domainUpdate(id: ID!, input: DomainUpdateInput!): Domain!

  # ---- vhosts
  subdomainCreate(input: SubdomainCreateInput!): Subdomain!
  subdomainUpdate(id: ID!, input: SubdomainUpdateInput!): Subdomain!
  subdomainDelete(id: ID!): Subdomain!

  domainAliasCreate(input: DomainAliasCreateInput!): DomainAlias!
  domainAliasUpdate(id: ID!, input: DomainAliasUpdateInput!): DomainAlias!
  domainAliasDelete(id: ID!): DomainAlias!
  "Reseller and administrator only. Approves an alias in the ORDERED state."
  domainAliasApprove(id: ID!): DomainAlias!
  domainAliasReject(id: ID!): DomainAlias!

  # ---- mail
  mailAccountCreate(input: MailAccountCreateInput!): MailAccount!
  mailAccountUpdate(id: ID!, input: MailAccountUpdateInput!): MailAccount!
  mailAccountDelete(id: ID!): MailAccount!
  mailAutoresponderSet(id: ID!, input: AutoresponderInput!): MailAccount!
  mailCatchallCreate(input: MailCatchallCreateInput!): MailAccount!
  mailCatchallDelete(id: ID!): MailAccount!

  # ---- ftp
  ftpUserCreate(input: FtpUserCreateInput!): FtpUser!
  ftpUserUpdate(id: ID!, input: FtpUserUpdateInput!): FtpUser!
  ftpUserDelete(id: ID!): FtpUser!

  # ---- sql
  sqlDatabaseCreate(input: SqlDatabaseCreateInput!): SqlDatabase!
  sqlDatabaseDelete(id: ID!): SqlDatabase!
  sqlUserCreate(input: SqlUserCreateInput!): SqlUser!
  sqlUserSetPassword(id: ID!, password: Secret!): SqlUser!
  sqlUserDelete(id: ID!): SqlUser!

  # ---- dns
  dnsRecordCreate(input: DnsRecordCreateInput!): DnsRecord!
  dnsRecordUpdate(id: ID!, input: DnsRecordUpdateInput!): DnsRecord!
  dnsRecordDelete(id: ID!): DnsRecord!

  # ---- customers (reseller and administrator)
  customerCreate(input: CustomerCreateInput!): Customer!
  customerUpdate(id: ID!, input: CustomerUpdateInput!): Customer!
  customerDelete(id: ID!): Customer!
  customerSetState(id: ID!, state: AccountState!): Customer!
  customerSetApiAccess(id: ID!, allowed: Boolean!): Customer!

  hostingPlanCreate(input: HostingPlanInput!): HostingPlan!
  hostingPlanUpdate(id: ID!, input: HostingPlanInput!): HostingPlan!
  hostingPlanDelete(id: ID!): HostingPlan!

  # ---- resellers (administrator)
  resellerCreate(input: ResellerCreateInput!): Reseller!
  resellerUpdate(id: ID!, input: ResellerUpdateInput!): Reseller!
  resellerDelete(id: ID!): Reseller!
  resellerSetApiAccess(id: ID!, allowed: Boolean!): Reseller!
}

enum AccountState { ENABLED DISABLED }
```

Representative inputs; the rest follow the same shape and are in the SDL file.

```graphql
input SubdomainCreateInput {
  "The Domain or DomainAlias to hang it off."
  parentId: ID!
  "The label alone, e.g. 'shop'. Unicode or punycode."
  label: String!
  "Mount an existing vhost's directory instead of creating a new one."
  sharedMountPointOf: ID
  forwarding: ForwardingInput
  wildcard: Boolean = false
}

input ForwardingInput {
  url: String!
  type: ForwardType!
  "PROXY only."
  keepHost: Boolean = false
}

input CustomerCreateInput {
  username: String!
  password: Secret!
  domainName: DomainName!
  ipAddressId: ID!
  contact: ContactDetailsInput!
  expiresAt: DateTime
  """
  Take limits and features from this plan. Exactly one of hostingPlanId or
  allowances must be given.
  """
  hostingPlanId: ID
  allowances: CustomerAllowancesInput
  reference: String
  "Send i-MSCP's welcome message. Defaults to the panel's own behaviour."
  sendWelcomeEmail: Boolean
}
```

`CustomerCreateInput` is the one place the API is *simpler* than the panel: the
panel spreads this across a three-step wizard with state in `$_SESSION`
(`user_add1.php`, `user_add2.php`, `user_add3.php`). The API takes it in one
call, applying the same validation each step applied.

---

## 8. Mutation semantics

### 8.1 The shape of every write

Identical for all of them, in this order:

1. **Authenticate** (§5) — else `401`.
2. **Resolve ownership** (§6.3) — else `NOT_FOUND`.
3. **Check the scope** on the presented credential — else `FORBIDDEN`.
4. **Check the feature** is available to the owning customer — else
   `FEATURE_UNAVAILABLE`.
5. **Check the object is settled** (§8.3) — else `CONFLICT`.
6. **Validate the input** — else `BAD_USER_INPUT` with `extensions.field`.
7. **Check the quota**, for creates — else `LIMIT_EXCEEDED` with
   `extensions.limit`.
8. **Transaction**: `beginTransaction()`, dispatch the `onBefore…` event with
   the same parameters the panel passes, write the rows with the appropriate
   `to…` status, dispatch `onAfter…`, `commit()`.
9. **`send_request()`** — outside the transaction, after the commit, never
   before. A daemon that read the rows mid-transaction would see nothing.
10. **`write_log()`** and the audit row (§11).
11. **Return the object**, with `provisioning.state = PENDING`.

Steps 2–7 are ordered so that the error a caller gets leaks the least. In
particular ownership is resolved before validation, so a caller cannot probe
which domain names exist by watching which validation errors come back.

### 8.2 Mutations return intent, not completion

A mutation returns as soon as the intent is recorded. The returned object's
`provisioning.state` is `PENDING` and `provisioning.settled` is `false`.

A client that needs to know the operation finished polls:

```graphql
mutation { subdomainCreate(input: {...}) { id provisioning { state settled } } }
# then, until settled:
query($id: ID!) { node(id: $id) { ... on Subdomain { provisioning { state message } } } }
```

`Query.pending` returns everything the caller owns that is not settled, so a
client can poll once for a whole batch rather than once per object.

Typical settle time on the reference box is a few seconds; the backend runs on
the daemon's schedule. There is no server-side wait: a `waitFor` argument would
hold a PHP-FPM worker for the duration, and the panel's pool is small.

**A backend failure is not a GraphQL error.** It happens minutes after the
response was sent. It surfaces as `provisioning.state = ERROR` with the
backend's text in `provisioning.message`, which is exactly where the panel
surfaces it too.

### 8.3 An unsettled object may not be changed

Any mutation targeting an object whose `provisioning.settled` is false is
refused with `CONFLICT`, and the error's `extensions.retryAfterSeconds`
suggests when to try again. §2.3 is why: the backend consumes the status
column as a work queue, and a second write would overwrite an instruction it
has not yet read.

The one exception is delete, which is allowed against an object in the `ERROR`
state — that is how an object that failed to provision is cleaned up, and it
is what the panel's own retry does.

### 8.4 Idempotency

Creates are not idempotent, and no idempotency key is offered in version 1.
They are protected instead by the uniqueness i-MSCP already enforces —
`domain.domain_name`, `mail_users.mail_addr`, `ftp_users.userid`,
`sql_database.sqld_name` are all unique keys — so a retried create fails with
`CONFLICT` rather than producing a duplicate. Updates and deletes are naturally
idempotent; deleting an already-deleted object returns `NOT_FOUND`.

### 8.5 Multiple mutations in one document

Permitted, executed serially in the order written, as the GraphQL
specification requires. They are **not** one transaction: each is committed
separately, because each ends in a `send_request()` that must not be rolled
back. A document whose third mutation fails leaves the first two done. This is
stated in the schema description of `Mutation` so it cannot be discovered by
surprise, and the recommendation to clients is one mutation per request.

---

## 9. Errors

Every error carries `extensions.code`. Codes are a closed set and are part of
the compatibility contract (§18).

| Code | HTTP | Meaning |
| --- | --- | --- |
| `UNAUTHENTICATED` | 401 | No credential, or a bad one |
| `API_ACCESS_WITHDRAWN` | 403 | Valid identity, but §6.2 says no |
| `FORBIDDEN` | 200 | Visible object, disallowed operation, or missing scope |
| `NOT_FOUND` | 200 | No such object, or not the caller's |
| `BAD_USER_INPUT` | 200 | Validation. Carries `extensions.field` |
| `LIMIT_EXCEEDED` | 200 | A quota. Carries `extensions.limit` and `extensions.used` |
| `FEATURE_UNAVAILABLE` | 200 | The feature is withheld from this account |
| `CONFLICT` | 200 | Object not settled (§8.3), or a uniqueness clash |
| `RATE_LIMITED` | 429 | §10.3. Carries `Retry-After` |
| `QUERY_TOO_COMPLEX` | 400 | §10.2 |
| `INTERNAL` | 200 | Raised by a resolver once the document is executing — the envelope carries `data`, so a GraphQL result really was produced |
| `INTERNAL` | 500 | Raised before the document runs at all — a wiring fault, or an unexpected throw around execution — so no GraphQL result exists |

`INTERNAL` never carries a message beyond "an internal error occurred" and a
correlation id, unless `debug = true` in `config.php`. The correlation id is
written to the panel's log with the full exception, so support can join the
two without the client ever seeing a stack trace or a query.

---

## 10. Performance and abuse limits

### 10.1 Batching

The obvious shape — "list my customers, and for each their domain and its
subdomains" — is an N+1 by default. graphql-php's `Deferred` and
`SyncPromiseAdapter` give the DataLoader pattern without a dependency, and the
plugin ships a small `BatchLoader` used by every one-to-many and many-to-one
edge: customer→domain, domain→subdomains, domain→aliases, alias→subdomains,
domain→mail accounts, customer→ftp users, database→sql users, and the reverse
of each. A resolver that queries per parent is a defect, and §17 has a test
that counts queries for a known document.

### 10.2 Query cost

`QueryDepth` (default 15) and `QueryComplexity` (default 1000) rules from
graphql-php, both configurable. List fields declare a complexity proportional
to their `limit`. Introspection is allowed by default and can be turned off;
turning it off is noted in the documentation as obscurity, not security, since
the SDL ships in the archive.

### 10.3 Rate limits

Per token, in a fixed window, counted in APCu (present and enabled in the
reference box) with a database fallback when APCu is not available:

| Bucket | Default |
| --- | --- |
| Queries | 120/minute |
| Mutations | 30/minute |
| `tokenIssue` | 5/minute per source IP, and 10/hour per username |

Exceeding a bucket returns `429` with `Retry-After`. The `tokenIssue` limit is
separate and much tighter because it is the one unauthenticated field, and it
is *in addition to* i-MSCP's `BruteForce` plugin, not instead of it.

### 10.4 Cost of the request itself

The panel bootstrap is not free — it builds a Slim application, a Zend
translator, a navigation tree and a plugin manager on every request. The
plugin cannot avoid it, but it can avoid adding to it: the parsed schema AST is
cached to disk on first use and re-read from `var_export` output afterwards,
so `BuildSchema` does not re-parse the SDL per request. The `last_used_at`
stamp of §5.1 is written at most once per minute per token, not once per
request.

---

## 11. Observability

**The panel's own log.** Every mutation calls `write_log()` with the same
wording the equivalent page uses, so an administrator reading the panel's log
sees API actions and UI actions in one list, and does not have to know which
was which to understand what happened.

**The audit table.** `graphql_audit` (§15) records, per request: time,
identity, token, source IP, operation name, the mutation field names invoked,
the variables **with every `Secret`-typed value replaced by `***`**, outcome,
error code, and duration. `audit` in `config.php` selects `none`, `mutations`
(the default) or `all`. Rows older than `audit_retention_days` are pruned.

Redaction is by schema type, not by field-name heuristics: the request's
variables are walked against the operation's argument types and anything typed
`Secret` is replaced. A password cannot be logged by forgetting to add its
field name to a list, because there is no list.

**Nothing is sent anywhere.** No telemetry, no external calls.

---

## 12. Security

The threat model is worth stating plainly, because it is more serious than a
typical API's. This endpoint can create system users with shells, create
databases and grant privileges on them, create mail accounts, and — for a
reseller token — create customers, each of which is a system account. A stolen
token is not a data breach; it is most of the way to a shell on a shared
hosting server.

| Threat | Control |
| --- | --- |
| Token theft in transit | TLS required (§4), with revocation of any token sighted on a plaintext request |
| Token theft at rest, server side | Only a SHA-256 hash is stored; the secret exists in plaintext exactly once, in the creation response |
| Token theft at rest, client side | Expiry (default 365 days, settable per token), per-token revocation, `last_used_at`/`last_used_ip` shown in the panel so an unexpected use is visible |
| Over-broad tokens | Scopes (§7.2), which only narrow, never widen |
| Token used from an unexpected place | Optional per-token CIDR allow-list |
| CSRF against the session path | Three stacked controls (§5.2), or disable session auth |
| Credential stuffing on `tokenIssue` | i-MSCP `BruteForce` plus a dedicated rate bucket (§10.3) |
| Horizontal privilege escalation | Ownership resolved before every operation, from the database, in one function (§6.3) |
| Vertical privilege escalation | Role read from the database per request, never from the token; no impersonation field exists |
| Object enumeration | Unreachable objects return `NOT_FOUND`, never `FORBIDDEN` (§6.3); opaque type-tagged ids (§3.5) |
| Denial of service by query shape | Depth and complexity limits (§10.2) |
| Denial of service by volume | Rate limits (§10.3) |
| Information disclosure in errors | `INTERNAL` carries a correlation id only (§9) |
| Secrets in logs | Redaction by schema type (§11) |
| Compromise of the whole feature | An administrator can disable the plugin; a reseller can withdraw a customer's access; both revoke tokens |

**Passwords** are accepted in plaintext over TLS, as the panel's own login
does, and are immediately hashed with the same functions the panel uses —
`Crypt::apr1MD5()` for panel accounts, `Crypt::sha512()` for FTP. They are
typed `Secret`, so they are unreadable and unloggable by construction.

**SQL user creation** is the sharpest edge, because it issues real `GRANT`
statements. It reuses i-MSCP's own constraints unchanged: the 16-character
name limit, the reseller-configured prefix policy, the host restriction, and
the reserved database names.

---

## 13. Plugin layout

Following `SGW_ApacheCache`, which is the most recent and the most complete of
the reference plugins.

```
SGW_GraphQL.php               plugin class: install/update/uninstall, routes,
                              service provider, event listeners
info.php                      name, version, require_api 1.5.1, date
config.php                    §14
sql/                          001_create_graphql_tables.php, …

src/                          PSR-4, iMSCP\Plugin\SGW_GraphQL\
  Http/                       PSR-15 middleware: Tls, Cors, Authenticate,
                              RateLimit, Audit; and the endpoint handler
  Auth/                       TokenService, SessionIdentity, Identity, Scope
  Security/                   OwnershipResolver, IdentityShim, Guard
  Schema/                     SchemaFactory (SDL + AST cache), ResolverMap,
                              GlobalId, scalar implementations
  Resolver/                   one class per type; thin — they call Service/
  Service/                    the business logic of §3.2: SubdomainService,
                              MailService, FtpService, SqlService, DnsService,
                              CustomerService, ResellerService, HostingPlanService
  Repository/                 exec_query() wrappers and the batch loaders
  Support/                    Provisioning status mapping, IDN, error factory

schema/schema.graphql         the schema (§7). Shipped, and served at
                              GET /api/graphql/schema

frontend/                     panel pages (§16)
  client/graphql_tokens.php
  reseller/graphql_tokens.php  reseller/graphql.php
  admin/graphql.php
  common.php
themes/default/view/…         templates for the above
l10n/en_GB.php

vendor/                       webonyx/graphql-php only, committed to the
                              release archive but not to git (§3.3)

docs/                         this document, and the client-facing API guide
test/                         §17
tools/                        deploy.sh, version.php, schema-check.php
makefile.json                 clean / test / version / package
upload-exclude.txt            keeps docs, test, tools out of the archive
```

There is no `backend/` directory, and that is worth noticing: unlike
`SGW_ApacheCache` and `SGW_LetsEncrypt`, this plugin has no Perl module and
installs no system configuration. All provisioning is delegated to i-MSCP's
own backend modules through the status columns. Nothing here touches the
server outside the database and the panel.

**Packaging.** `make.phar version && make.phar package` produces
`SGW_GraphQL.tgz`, with `vendor/` included and `composer.json` pinned to
`platform.php = 7.3.33` so the archive can never be built against a newer PHP
than the panel runs. `tools/version.php` is taken from `SGW_ApacheCache`
unchanged.

---

## 14. Configuration

`config.php`, read through `getConfigParam()`:

```php
return array(
    'endpoint'                    => '/api/graphql',
    'schema_endpoint'             => '/api/graphql/schema',

    // Customers and resellers may use the API unless it is withdrawn (§6.2).
    'allowed_by_default'          => true,

    // Transport
    'require_tls'                 => true,
    'trusted_proxies'             => array(),      // whose X-Forwarded-Proto
                                                   // may be believed (§4)
    'allowed_origins'             => array(),      // never '*'
    'allow_session_auth'          => true,
    'allow_password_grant'        => true,
    'explorer'                    => false,        // GraphiQL at /api/graphql/explorer

    // Tokens
    'token_default_ttl_days'      => 365,
    'token_max_ttl_days'          => 730,
    'token_max_per_account'       => 10,

    // Query cost
    'introspection'               => true,
    'max_query_depth'             => 15,
    'max_query_complexity'        => 1000,
    'max_page_size'               => 200,

    // Rate limits, per minute unless stated
    'rate_limit_queries'          => 120,
    'rate_limit_mutations'        => 30,
    'rate_limit_token_issue'      => 5,

    // Observability
    'audit'                       => 'mutations',  // none | mutations | all
    'audit_retention_days'        => 90,
    'debug'                       => false,        // error detail in responses

    // Directory existence checks - FTP home directories and document roots
    // alike - open an FTP connection per call (§3.2).
    'validate_ftp_home_dir'       => true
);
```

---

## 15. Database

Three tables, all owned by the plugin, all dropped on uninstall. No i-MSCP
table is altered — a plugin that alters core tables cannot be uninstalled
cleanly, and `SGW_ApacheCache` sets the precedent of keeping to its own.

```sql
CREATE TABLE IF NOT EXISTS `graphql_token` (
    `token_id`     int(11) unsigned NOT NULL AUTO_INCREMENT,
    `admin_id`     int(11) unsigned NOT NULL,
    `name`         varchar(255) NOT NULL,
    -- Shown in the panel so a user can tell their tokens apart, and the key
    -- the lookup is done on. Not a secret.
    `token_prefix` char(8) NOT NULL,
    `token_hash`   char(64) NOT NULL,
    `scopes`       varchar(1024) NOT NULL,
    -- Comma-separated CIDRs, or NULL for no restriction.
    `ip_allowlist` varchar(1024) DEFAULT NULL,
    `created_at`   int(11) unsigned NOT NULL,
    `expires_at`   int(11) unsigned DEFAULT NULL,
    `last_used_at` int(11) unsigned DEFAULT NULL,
    `last_used_ip` varchar(45) DEFAULT NULL,
    `revoked_at`   int(11) unsigned DEFAULT NULL,
    PRIMARY KEY (`token_id`),
    UNIQUE KEY `graphql_token_prefix` (`token_prefix`),
    KEY `graphql_token_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- One row per account whose API access differs from the default. Same shape
-- as SGW_ApacheCache's apache_cache_perm, for the same reason.
CREATE TABLE IF NOT EXISTS `graphql_perm` (
    `admin_id` int(11) unsigned NOT NULL,
    `allowed`  tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

CREATE TABLE IF NOT EXISTS `graphql_audit` (
    `audit_id`    bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `at`          int(11) unsigned NOT NULL,
    `admin_id`    int(11) unsigned NOT NULL,
    `token_id`    int(11) unsigned DEFAULT NULL,
    `ip`          varchar(45) NOT NULL,
    `operation`   varchar(255) DEFAULT NULL,
    `fields`      varchar(1024) DEFAULT NULL,
    -- Variables with every Secret-typed value already replaced (§11).
    `variables`   mediumtext,
    `outcome`     enum('ok','error') NOT NULL,
    `error_code`  varchar(64) DEFAULT NULL,
    `duration_ms` int(11) unsigned NOT NULL,
    PRIMARY KEY (`audit_id`),
    KEY `graphql_audit_at` (`at`),
    KEY `graphql_audit_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
```

Event listeners keep these in step with the panel: `onAfterDeleteCustomer` and
`onAfterDeleteUser` delete that account's tokens, permission row and — after
the retention period — its audit rows.

---

## 16. Panel pages

The API is the product, but it needs three small pages, and they follow the
navigation-injection pattern of `SGW_ApacheCache::setupNavigation()`.

**`/client/graphql_tokens.php`** — under *Domains*. One row per token: name,
prefix, scopes, created, expires, last used and from where. A create form
(name, scopes, lifetime, optional CIDR list) that shows the secret **once**,
with a clear statement that it will not be shown again, and a revoke button.
Also a link to the endpoint and to the schema.

**`/reseller/graphql_tokens.php`** — the same, for the reseller's own tokens.

**`/reseller/graphql.php`** — under *Customers*. One row per customer, with a
switch that grants or withdraws API access (§6.2), and a count of that
customer's live tokens with a button to revoke all of them.

**`/admin/graphql.php`** — under *System tools*. The same grid for resellers,
plus the effective configuration, plus a paged view of `graphql_audit`.

The explorer, when enabled, is a fourth page rather than a bare route, so it
inherits the panel's session and its navigation. Its GraphiQL assets are
vendored into `themes/default/assets/`, not fetched from a CDN — the panel is
frequently run on servers with no outbound access, and a control panel should
not make requests to third parties.

---

## 17. Testing

Four layers, and the reference plugins already establish where they live.

**Unit** (`test/unit/`, PHPUnit run under PHP 7.4 in the box). No database.
The global-id codec round-trips, including rejection of a mismatched type tag.
The status-string → `ProvisioningState` mapping, in both directions, over every
status i-MSCP is known to write. The mail `mail_type` mapping, both ways, over
all twelve values. The `hosting_plans.props` parser, as a property test:
parse then re-emit must be byte-identical for every plan on the reference box
and for generated inputs. Every input validator.

**Forward compatibility** (`test/lint/`). Every source file in the plugin lints
under **both** `php7.4` and `php8.3`, one invocation per file. This is what
holds §2.5's rule that the plugin costs nothing when the panel migrates: a
construct removed in PHP 8 fails the suite on the day it is written rather than
during the migration, and the `#[...]` attributes the plugin carries are
verified to be comments on 7.4 and attributes on 8.3.

**Core debt** (`test/lint/`). Every `CORE-DEBT(Cn)` marker (§21.2) names an item
that exists in §21, and the suite prints the current inventory so the debt is
reported rather than merely recorded.

**Schema** (`test/schema/`). The SDL parses and `assertValid()` passes. Every
field in the SDL has a resolver in the map or a working default — this is the
test that recovers what §3.4 gave up by not having a class per type. Every
`extensions.code` the code can emit is in the documented set of §9, and vice
versa. A committed snapshot of the SDL must match, so a schema change is
always a visible diff in review.

**Authorisation** (`test/authz/`, against a seeded database). A matrix: for
every mutation, and for each of {owner, another customer of the same reseller,
another reseller's customer, the owning reseller, another reseller,
administrator}, assert the exact outcome. This is the test that matters most,
and it is written before the resolvers it tests. It also asserts §6.5's first
layer: no core helper is reached in a state that would let it exit.

**Integration** (`test/api/`, against the running box, in the style of
`SGW_ApacheCache`'s `test/cache-matrix.sh`). Issue a token; create a customer;
poll until settled; add a subdomain, a mail account, an FTP user, a database
and a SQL user; assert each reaches `ok`; assert the vhost actually answers;
delete everything; assert the database is back to where it started and no
system user, maildir or vhost file is left behind. This is the test that would
have caught the event-dispatch problem of §3.1, and it runs against a box with
`SGW_ApacheCache` and `SGW_LetsEncrypt` installed for exactly that reason.

**Query counting.** The integration suite runs one representative deep
document with a query log attached and asserts the count is below a threshold,
so an N+1 regression (§10.1) fails a test rather than a customer's page load.

---

## 18. Compatibility

`Query.apiVersion` returns a semantic version for the *schema*, which moves
independently of the plugin's own version in `info.php`.

Within a major version: fields and types may be added; arguments may be added
only as optional; nothing is removed or renamed; no field's type is narrowed;
no enum value is removed; no `extensions.code` is removed. A field to be
removed is marked `@deprecated(reason:)` for at least one minor version first,
and the reason names its replacement.

Enums are the sharp edge — adding a value is a breaking change for a client
that switches exhaustively. New values will be added (i-MSCP grows DNS record
types, and plugins add provisioning verbs), so every enum's description says
so, and `ProvisioningState` is paired with `raw` (§7.1) precisely so a client
can cope with a state it has never seen.

---

## 19. Delivery

Phases, each ending somewhere it is safe to stop and each independently
reviewable. The implementation plan will expand these; here they exist to show
the shape and to make the risk order explicit.

There are two tracks. The plugin track is the deliverable; the core track is
§21, and it runs alongside rather than blocking. The only hard ordering between
them is phase 0.

**Plugin track**

| # | Phase | Ends when |
| --- | --- | --- |
| 0 | PHP 7.4, then skeleton | The panel runs on 7.4 (§2.5: two config values, no code changes) and the 21-page sweep of §17 is a committed test and green. Then: the plugin installs, uninstalls and appears in the panel; `sql/001` migrates up and down; `tools/deploy.sh` and the box are in the loop |
| 1 | Walking skeleton | `POST /api/graphql` answers `{ viewer { username role } }` for a bearer token, end to end, with the middleware stack, the token table and the token UI in place. The riskiest integration points — routing, auth, the identity shim, the SDL cache — are all exercised by the smallest possible query |
| 2 | Read model | The whole customer graph reads: domains, subdomains, aliases, mail, FTP, SQL, DNS, quotas, provisioning. Batch loaders and the query-count test with it |
| 3 | Customer mutations | Subdomains, aliases, mail, FTP, SQL, DNS. The authorisation matrix is written first and stays green |
| 4 | Reseller mutations | Customers, hosting plans, alias approval, `customerSetState` |
| 5 | Administrator mutations | Resellers |
| 6 | Hardening | Rate limits, audit, complexity limits, the explorer, the client-facing API guide |
| 7 | Release | Packaging, `CHANGELOG.md`, README, version bump workflow |

Phase 1 is deliberately disproportionate. Every unknown in this document —
whether the identity shim holds, whether `getRoutes()` will carry middleware,
whether the SDL cache survives the panel's opcache, whether `showErrorPage()`
can still escape — is resolved there, on one field, where it is cheap to be
wrong.

**Core track** — §21, in `saygoweb/imscp`, each item independently useful to the
panel whether or not this plugin ever ships. C1 and C2 are worth doing before
plugin phase 3, because they delete design compromises rather than code. C3 is
the long one and is best taken entity by entity, each one trailing the plugin
mutation that duplicated it — write the duplicate, ship it, then extract and
delete it, so the plugin's own tests are already covering the behaviour when
core changes underneath it.

The tracks are deliberately not interlocked. If the core track stalls, the
plugin still works; it just carries more duplication for longer.

---

## 20. Risks and open questions

**Risks**

1. **PHP 7.4 is also long past end of life.** Moving off 7.3 does not fix
   that; it buys the libraries this plugin needs and nothing else. The real
   mitigation is the 8.3 migration of §2.5, and the risk is that 7.4 turns out
   to be comfortable enough that 8.3 never happens. Reviewing it on a date
   rather than on a feeling is the only defence — and the panel's unsupported
   Roundcube 1.3.15 is a better forcing function than this plugin is.
2. **Reimplemented logic drifts from the core.** §3.2's transcription is
   correct on the day it is written and silently rots when i-MSCP changes a
   rule. This is the largest risk in the design and the reason §21 exists:
   the retirement of each duplicate is the real mitigation, and the ones with
   the highest drift risk are also the ones §21 schedules first. Until then:
   each transcribed rule cites the page and line it came from, so a diff of
   those pages between i-MSCP versions is a review checklist; the `CORE-DEBT`
   markers of §21.2 make every duplicate greppable; and the integration suite
   of §17 fails loudly when the backend stops accepting what the API writes.
3. **The identity shim** (§6.4) depends on undocumented `$_SESSION` keys. If
   i-MSCP renames one, the API silently authorises as nobody. §17's
   authorisation matrix catches it; a start-up assertion that the shim
   round-trips catches it faster; and §21's C1 removes it altogether.
4. **`hosting_plans.props`** is 25 positional semicolon-delimited fields with
   underscore-prefixed booleans. Getting it wrong creates a customer with the
   wrong limits. §17 requires the round-trip property test.
5. **The FTP home-directory check** opens an FTP connection per call. On a
   busy API this is a real cost and a real failure mode when ProFTPD is
   restarting. §14 makes it switchable; the default may need to flip.

**Open questions for review**

1. **Is SDL-first the right call**, or is the familiarity of the
   `emdc-events` code-first idiom worth more than the reviewability argument in
   §3.4? Still the decision most worth arguing about, and still the cheapest to
   reverse before phase 2. Note that on PHP 7.4 the code-first route cannot use
   `simpod/graphql-utils` alongside graphql-php v15 at all (§3.3), so choosing
   it means either dropping to graphql-php v14 or writing the builders by hand.
2. ~~Should Anorm be used?~~ **Answered in §3.3**: yes, for the read models and
   their batch loading, on the strength of Anorm v3's field-selection-aware
   batch loaders; no, for writes. What remains open is narrower — whether two
   idioms in one codebase is worth that, with the decision point at the start
   of phase 2.
3. **Scope granularity.** Fourteen scopes (§7.2) may be more than anyone will
   use. Three — `READ`, `WRITE`, `ADMIN` — would be simpler and harder to get
   wrong. Adding scopes later is compatible; removing them is not.
4. **Should a reseller token be able to act *as* one of its customers**, or
   only *on* them? The schema currently says "on": a reseller mutates a
   customer's subdomain by naming it, and there is no impersonation. That is
   safer, and §6.4's one-identity-per-request rule depends on it.
5. **Alias ordering.** i-MSCP lets a customer *order* an alias for reseller
   approval, or create it outright, depending on configuration.
   `domainAliasCreate` currently returns an object in either `PENDING` or
   `ORDERED` accordingly. Is that transparent enough, or should ordering be a
   separate mutation?
6. **Traffic and disk history.** §7 exposes current usage only. Is
   `Domain.trafficHistory(from:, to:)` over `domain_traffic` wanted in version
   1, or later?
7. **Rate limit defaults.** 120 queries and 30 mutations a minute is a guess.
   What does the real client look like?

---

## 21. The core-improvement backlog

Every rule this plugin has to transcribe (§3.2) is a rule that exists twice.
This section is the list of changes to `saygoweb/imscp` that make each of them
exist once again.

The framing matters, and it is the reason variant B was not chosen. Variant B's
case was that a plugin cannot change core, so it must duplicate. True — but a
plugin can change core *separately*. Each item below is worth doing to i-MSCP on
its own merits, by someone who has never heard of this plugin; that it also
lets the plugin delete a copy is the second payoff, not the justification.
Written the other way round — "refactor the panel so my plugin is tidier" — none
of these would be worth doing, and none would survive review.

### 21.1 The list

Ordered by ratio of value to risk. C1 and C2 are pure refactors that delete
design compromises; C3 is the long one and is where most of the duplication
actually lives.

---

**C1 — Thread an explicit `$adminId` through the session-coupled helpers.**

*Change.* Add an optional trailing `$adminId` parameter, defaulting to
`$_SESSION['user_id']`, to `customerHasFeature()`, `customerHasDomain()`,
`customerSqlDbLimitIsReached()` (`gui/include/Client.php`),
`resellerHasFeature()`, `resellerHasCustomers()` (`gui/include/Reseller.php`),
and to `deleteSubdomain()`, `deleteSubdomainAlias()`, `deleteDomainAlias()`,
`deleteCustomer()`, `delete_sql_database()`, `sql_delete_user()`,
`change_domain_status()`. Key `customerHasFeature()`'s `static` cache by
`$adminId` while you are there.

*Why i-MSCP wants it anyway.* The `static` cache at `gui/include/Client.php:84`
is not keyed by user. Any page that asks about two different customers in one
request gets the first one's answer for both — a latent bug in the panel today,
not merely an inconvenience for an API. And a function that reads a superglobal
cannot be tested; these are the functions most worth testing.

*What the plugin deletes.* §6.4, the identity shim, in its entirety — along
with the one-identity-per-request rule it forces and open question 4, which
only exists because of that rule.

*Shape.* Additive. Every one of the existing call sites is untouched.

---

**C2 — Make the error-page helpers throw rather than `exit`.**

*Change.* `showErrorPage()` and its wrappers (`gui/include/View.php:932`) raise
a typed exception. A handler registered by the page bootstrap catches it and
renders exactly what is rendered today, so panel behaviour is unchanged.

*Why i-MSCP wants it anyway.* `exit()` inside a helper makes the surrounding
code untestable and makes any non-HTML consumer of these functions impossible.
The function already contains a JSON branch and an XML branch — the codebase has
been half-admitting it has API-shaped callers for years, while keeping the one
construct that guarantees they cannot work.

*What the plugin deletes.* §6.5 entirely: the pre-check discipline, the "comment
naming the pre-check that makes this unreachable" convention, and the test that
asserts the discipline holds. The shutdown guard is worth keeping regardless.

*Shape.* Additive if the handler is registered by the existing page bootstrap.

---

**C3 — Extract the write logic from the page scripts into callable services.**

*Change.* One service per entity, taking an explicit identity and a typed input,
throwing typed exceptions. The page keeps `$_POST` parsing, `set_page_message()`
and `redirectTo()` and calls the service for everything between. No rule is
redesigned on the way through; extraction is mechanical, and improvements are
separate commits afterwards.

*Why i-MSCP wants it anyway.* `client/subdomain_add.php` is 582 lines, of which
about 250 are business rules that cannot be tested, reused, or read without the
form around them. The reseller's customer-creation flow carries state across
three requests in `$_SESSION` (`user_add1.php`, `user_add2.php`,
`user_add3.php`) for no reason other than that it was written as three forms.

*What the plugin deletes.* The bulk of §3.2 — its whole `Service\` write layer,
entity by entity, replaced by a call into core.

*Order.* Smallest and most self-contained first, so the pattern is proven before
the hard ones test it:

| | Service | Extracted from |
| --- | --- | --- |
| 1 | `SqlDatabaseService`, `SqlUserService` | `sql_database_add.php`, `sql_user_add.php`, `sql_delete_user.php`, `sql_database_delete.php` — smallest, and synchronous, so no daemon in the loop |
| 2 | `FtpUserService` | `ftp_add.php`, `ftp_edit.php`, `ftp_delete.php` |
| 3 | `MailService` | `mail_add.php`, `mail_edit.php`, `mail_delete.php`, `mail_catchall*.php`, `mail_autoresponder_*.php` |
| 4 | `SubdomainService` | `subdomain_add.php`, `subdomain_edit.php`, `subdomain_delete.php`, `alssub_delete.php` |
| 5 | `DomainAliasService` | `alias_add.php`, `alias_edit.php`, `alias_delete.php`, `reseller/alias.php` |
| 6 | `DnsRecordService` | `dns_add.php`, `dns_edit.php`, `dns_delete.php` |
| 7 | `DomainService` | `domain_edit.php` |
| 8 | `CustomerService` | `reseller/user_add{1,2,3}.php`, `user_edit.php`, `user_delete.php`, `domain_status_change.php` |
| 9 | `HostingPlanService` | `reseller/hosting_plan*.php` |
| 10 | `ResellerService` | `admin/user_add*.php`, `user_edit.php`, `user_delete.php` |

*Shape.* Behaviour-preserving, one entity per pull request, with the panel page
as the regression test: it exercised these rules before the extraction and must
exercise them identically after.

---

**C4 — A `hosting_plans.props` codec.**

*Change.* One class that parses and emits the 25 positional semicolon-delimited
fields, with a round-trip property test. Replace the `explode()`/`list()` pairs
that currently do it inline, of which `reseller/user_add3.php:133` is one.

*Why i-MSCP wants it anyway.* Twenty-five positional fields with
underscore-prefixed booleans, parsed by hand in more than one place. Getting it
wrong creates a customer with the wrong limits, silently. This is the highest
ratio of latent-bug to lines-of-fix in the panel.

*What the plugin deletes.* Its own copy of the parser, and risk 4.

---

**C5 — A canonical "every vhost this account owns" query.**

*Change.* One function returning the four-way union of `domain`, `subdomain`,
`domain_aliasses` and `subdomain_alias` keyed by the `(type, id)` pair i-MSCP
already uses everywhere. `SGW_ApacheCache`'s `getDomains()` is the shape.

*Why i-MSCP wants it anyway.* The panel builds this list ad hoc in
`domains_manage.php`, in `ftp_add.php`'s domain selector, in `mail_add.php`'s,
and elsewhere, each slightly differently — which is why alias subdomains
intermittently go missing from one list and not another.

*What the plugin deletes.* Its own copy of the union, and the risk of disagreeing
with the panel about what a customer owns.

---

**C6 — Publish the provisioning status vocabulary.**

*Change.* Constants for the settled and pending status sets, and a helper that
classifies an arbitrary status string, next to the existing
`translate_dmn_status()`.

*Why i-MSCP wants it anyway.* Every plugin re-derives these sets — see
`SGW_ApacheCache::SETTLED_STATUSES` and `PENDING_STATUSES` — and each one is a
separate opportunity to miss a verb and treat an in-flight object as settled.

*What the plugin deletes.* The mapping table behind §7.1's `ProvisioningState`.

---

C7, C8 and C9 differ from C1–C6 in kind, not just number: they were found
during phase 0–1 by replaying real requests through the deployed core, not by
reading source, and each is a defect in i-MSCP itself that this plugin merely
tripped over rather than a rule this plugin duplicates. That provenance is
worth stating plainly, because it is the argument for why they are credible —
none of the three is a theoretical reading of the code.

**C7 — Plugin route middleware cannot be attached, in either shape the core
offers.**

*Defect.* `PluginRoutesInjector::injectRoute()`
(`gui/src/Plugin/PluginRoutesInjector.php:214`) reads a plain route's
`middleware` key with `isset($routeSpec['middleware'])`, but `$routeSpec` is
not defined in that method's scope — its own parameter is `$spec`; `$routeSpec`
is the loop variable belonging to `injectRouteGroup()` one frame up
(line 156), which called `injectRoute()` with `$spec`, not with `$routeSpec`.
`isset()` on an undefined variable is silently `false` under both PHP 7.4 and
8.3, so the key advertised in `AbstractPlugin`'s own docblock is never applied
and no warning is raised. The documented alternative fares no better:
`injectRouteGroup()` hands `Slim\App::group()` a closure that calls
`$this->injectRoute(...)`, relying on `$this` inside that closure meaning the
injector — but `Slim\RouteGroup::__invoke()` (`vendor/slim/slim/Slim/RouteGroup.php`)
rebinds the closure to the `App` before calling it, so the call inside becomes
`App->injectRoute()`, which does not exist, and Slim's `__call()` throws
`BadMethodCallException`. Both halves were confirmed against the deployed core
with a request replayed through the real dispatch path, not inferred from
reading the source.

*Consequence.* Every plugin that wants route-level middleware must currently
bake it into its own handler by hand, as this plugin does in
`Api\Container::routeHandler()`. The security shape is worth naming: a plugin
that trusts the advertised `middleware` key ships an endpoint with no
middleware at all, and is given no indication — no warning, no exception —
that this has happened.

*What the plugin does instead.* `Container::routeHandler()` collapses the
whole middleware stack into the single callable the route spec calls its
`handler`, so the router is never asked to attach middleware at all. This item
would let that workaround be deleted once either code path in core is fixed.

---

**C8 — `TemplateEngine` re-scans its own substituted output, so any
user-controlled string can hang a request.**

*Defect.* `TemplateEngine::substitute_dynamic()` (`gui/src/TemplateEngine.php`)
sets `$startFrom = $curlB - 1` immediately after a substitution (lines 558 and
568), rewinding to just before the text it has just inserted, with the core's
own comment "new value may also begin with `'{'`". That is deliberate, so that
a substituted value which is itself a placeholder reference gets expanded too.
But `tohtml()` (`gui/include/Input.php:118`) escapes only the characters HTML
gives special meaning — `<`, `>`, `&`, `"`, `'` — and leaves `{` and `}` alone,
because neither is special to HTML. So a user-controlled value containing
`{SOMETHING}` is handed back to the scanner and re-expanded, and a value that
substitutes to itself is the same length after every pass: no memory growth,
nothing for a resource limit to catch, pure CPU until `max_execution_time`
finally intervenes.

*Consequence.* This is a whole-panel hazard, not a plugin one, and it has gone
unnoticed until now because the core's own free-form fields are
character-restricted; this plugin's token names were the first 255-character
unconstrained user string the panel renders back to its owner. Two impacts
follow. The page that renders the hostile value can no longer be loaded at
all, so the account that set it cannot undo the damage through the UI it
broke — the only way out is a direct database edit. And a value that happens
to name a real template placeholder renders that other variable's contents in
the wrong place, which is a narrow information leak on top of the denial of
service.

*Recommendation.* Fix it at `tohtml()`, not at every caller: have it strip or
escape `{` and `}` (or have `substitute_dynamic()` refuse to rewind into text
it has already substituted). Either covers every current and future caller in
one place, which is exactly what this plugin cannot do from outside core — it
can only reject the character at the one point it controls, `TokenService::issue()`.

---

**C9 — A server-wide `add_header Cache-Control public` contradicts the
session cache limiter.**

*Defect.* The frontend nginx template sets `add_header Cache-Control public;`
at the `http` block level (`configs/debian/default/frontend/nginx.nginx:56`),
beside the `gzip_*` directives rather than inside any static-asset `location`.
nginx's `add_header` is inherited by every server and location block that does
not set its own, so this directive reaches every response the panel serves,
API and page alike. PHP's session module already emits its own
`Cache-Control: no-store, no-cache, must-revalidate` on any request that
starts a session, so an authenticated page ends up carrying **two
contradictory `Cache-Control` header lines** — verified on the wire against
the deployed core, not assumed from reading the template.

*Consequence.* The practical exposure is bounded today: per RFC 9111, a
`no-store` anywhere in a combined directive list wins, and every authenticated
panel page starts a session, so the harsher directive is the one a compliant
cache honours. But a response that never starts a session gets `public` alone
with nothing to contradict it, and a plugin that sets its own `no-store` — as
this one does on every API response and on every panel response that displays
a token secret — is relying on conflicting-directive resolution in whichever
cache sits between it and the client, rather than on the header actually
saying one consistent thing.

*Recommendation.* Scope the directive to the static-asset locations it was
presumably meant for, rather than the `http` block. That is a template
change, not a plugin change; nothing here can be fixed from outside core.

---

**C10 — Batched counting functions.**

*Numbering.* Reserved as C7 in phase 2's plan; renumbered here because C7–C9
above were claimed by phase 0–1's defects first (see the numbering note that
used to stand in this spot).

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

---

**C11 — Defects in the page scripts that the API does not reproduce.**

*Numbering.* Like C7–C10, found by this plugin's work rather than by reading for duplication: phase 3 transcribed every customer write, and these are the places the transcription had to choose between copying a defect and implementing what the page evidently intends. The API does the latter, marks the site `CORE-DEBT(C11)`, and each item below is a separate issue for `saygoweb/imscp`.

*The list.*

1. `client/mail_add.php:266-279` inserts `quota = NULL` for a forward-only account; `mail_users.quota` is `NOT NULL`, so MariaDB refuses the row with error 1048, and line 289 reports that SQLSTATE 23000 as *"Mail account already exists."* Forward-only accounts cannot be created in the panel at all.
2. `client/subdomain_edit.php:380-388` updates `subdomain_alias.subdomain_wildcard_alias`, a column that table does not have, so a subdomain of an alias cannot be edited; line 354 also reads the wildcard flag as `0|1` where the column is `enum('yes','no')`.
3. `client/sql_user_add.php` checks the SQL user limit in `generatePage()`, which runs only after `addSqlUser()` has written and redirected. The limit is never enforced on a submit.
4. `client/mail_delete.php:88-121` overwrites `$row` in the loop that is meant to remove the deleted address from other accounts' forward and catch-all lists, so it removes nothing. Its log call (line 163) has its arguments swapped. When fixed, it should scan the owning customer's accounts, not every customer's.
5. `client/dns_edit.php:157-163` tests `strpos(...) == 0`, which is also true for `false`, so the first character of every record name is stripped before validation. The same lines strip that one character only, so the underscore labels DKIM and DMARC require (`selector._domainkey`, `_dmarc.sub`) cannot be created at all, although line 190 accepts them in a CNAME target. `client/dns_delete.php:39` dispatches `onBeforeDeleteCustomDNSrecord` with an unassigned `$dnsRecordId`.
6. `deleteSubdomain()`, `deleteSubdomainAlias()` and `deleteDomainAlias()` (`include/Client.php`, `include/Shared.php`) match FTP users with `LIKE CONCAT('%@', name)` and FTP group members with a regex built from the name, unescaped. Names may contain `_`, which `LIKE` reads as a wildcard: deleting `a_b.test` schedules `axb.test`'s FTP users — another customer's — for deletion. Protected areas are matched with `LIKE mount%`, so deleting `/shop` also schedules `/shopping`.
7. `client/sql_database_add.php:57` asks `SHOW DATABASES LIKE ?` with the name unescaped, so a name containing `_` is refused whenever any database matches it as a pattern.
8. `client/alias_order_delete.php` deletes an ordered alias's row and leaves the `php_ini` row `alias_add.php` created for it.
9. `client/alias_add.php:45-80` sends the reseller's *"your customer is awaiting approval"* template to the customer's own address. (The API keeps this behaviour, because changing who receives mail is the panel's decision to make first.)
10. `include/Shared.php:63` `createDefaultMailAccounts()` catches only `PDOException`, but `DatabaseMySQL::execute()` throws `DatabaseException`, so on a database error its own savepoint is neither rolled back nor released and the caller's transaction depth is left one too high.
11. `client/mail_catchall_add.php` never checks `domain_mailacc_limit`, although the row it writes is a `mail_users` row that `Counting.php:518` counts against that limit. A customer at their limit can add catch-alls without end, and the mail-account usage the panel reports then exceeds the limit shown beside it.

*Why i-MSCP wants it anyway.* Each is a user-visible failure or a cross-tenant write in the panel as shipped.

*What the plugin deletes.* Nothing directly: the API already behaves correctly. Each fix lets the corresponding C3 extraction be a straight move rather than a move plus a behaviour change.

---

### 21.2 Keeping the debt visible

Duplication that nobody can find is duplication that never gets retired. Two
mechanisms, both cheap:

1. **A marker at every duplicate.** Each transcribed rule carries

   ```php
   // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:274
   //   Retire when SubdomainService lands in core.
   ```

   so `grep -rn 'CORE-DEBT'` is the current debt inventory, and the item it
   belongs to is in the marker. §17 gains a test that every marker names an item
   that exists in this section.

2. **An issue per item in `saygoweb/imscp`.** C1–C6 are filed there, not here,
   because that is where the work happens and where someone who has never read
   this document will meet them. Each issue is written on i-MSCP's own terms —
   the "why i-MSCP wants it anyway" paragraph above is the issue body, and the
   plugin is a footnote.

### 21.3 What this is not

It is not a commitment to variant B by instalments. Variant B put the API's own
code in core; this puts nothing there. What crosses over is only the business
logic that was always core's to own, extracted in a form the panel itself uses.
If every item here lands, the plugin still ships as a `.tgz`, still installs
through the plugin manager, and is still removable — it simply stops carrying a
second copy of the panel's rules.

---

## Appendix A — what was measured

Everything below was run inside the `imscp_debian_trixie` box on 2026-09-04,
rather than inferred from a manifest.

| Claim | Method | Result |
| --- | --- | --- |
| The panel runs PHP 7.3 on Debian 13 | `php -v` in the box | `PHP 7.3.33-34+0~20260703.143+debian13~1` on Debian 13.6 |
| Required extensions are present | `php -m` | `json`, `mbstring`, `pdo_mysql`, `openssl`, `sodium`, `apcu`, `intl` all present |
| `webonyx/graphql-php` v14 installs under 7.3 | `composer install` with `platform.php = 7.3.33`, using the panel's own `composer.phar` | v14.11.10 installed |
| SDL-first execution works under 7.3 | `BuildSchema::build()` + `assertValid()` + `GraphQL::executeQuery()` on a three-type schema | `{"data":{"viewer":{…}}}` |
| Anorm v3.1.1 does not parse under 7.3 | `php7.3 -l` over all 31 source files | 3 failures, all typed properties: `MangoQuery.php:19`, `SqlCondition.php:11`, `MangoQueryParser.php:11` |
| Removing those types is sufficient | Same lint after stripping the 10 property type declarations | All 31 files clean |
| The box is reachable for deployment without a synced folder | `vagrant ssh-config` → `rsync` → `ssh … sudo sh -s` | Working; `tools/deploy.sh` |
| Panel services are up | `systemctl is-active imscp_panel nginx mariadb` | all `active` |

Added during the 2026-09-04 review that produced the decisions in the header:

| Claim | Method | Result |
| --- | --- | --- |
| **PHP 7.4 needs no changes at all** | Unmodified `gui/` and unmodified 7.3-era `vendor/`, served under `php7.4`, swept 21 pages as admin, reseller and customer with a real session | 21 of 21 render; 0 deprecations; 0 syntax failures in panel code or in ZF1, idna-convert, Slim or Flysystem |
| `webonyx/graphql-php` v15 is available from 7.4 | `composer update` with `platform.php = 7.4.33` | v15.37.2 |
| `lcobucci/jwt` v4 is available from 7.4 | Same | 4.3.0 — so §3.3's rejection of JWT is a choice, not a constraint |
| `simpod/graphql-utils` cannot be had with v15 on 7.4 | Same | 0.5.0–0.5.3 pin graphql-php `^14`; 0.5.4 requires PHP `^8.0` |
| Anorm v3.1.1 runs unmodified from 7.4 | `php7.4 -l` and `php8.3 -l`, one invocation per file | 31 files, 0 failures on both. The three typed-property files that fail on 7.3 are fine from 7.4 |
| Anorm has DataLoader-shaped batching | Read `src/Relationship/BatchLoadingOrchestrator.php` and `BatchLoader/*` | `loadRelationshipsForModels()` takes field-selection specs of the form `['posts', 'company:name,address']` — the shape of a GraphQL `ResolveInfo` |
| The panel also runs on 8.3, after a bounded set of fixes | Built a PHP 8.3 tree: 3 dependency replacements, 25 `#[\ReturnTypeWillChange]`, 3 `#[\AllowDynamicProperties]`, `Serializable` → `__serialize`, 2 `(string)` casts, escaper and IDNA API changes; same 21-page sweep | 20 of 21 render; 0 deprecations; the one failure is `src/SystemInfo.php:501`, an admin-only diagnostics page |
| `shardj/zf1-future` covers the panel's Zend surface | 22 component checks on 8.3, 8.4 and 8.5 | All pass on all three; zero deprecations from ZF1 |
| `#[...]` attributes are safe on 7.4 and 8.3 alike | `php7.4 -l` and `php8.3 -l` on the touched files | Clean on both — a comment on PHP 7, an attribute on PHP 8, which is what lets §2.5's forward-compatibility rule work |
