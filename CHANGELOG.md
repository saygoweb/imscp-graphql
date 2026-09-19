# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [1.0.0] — 2026-09-19

The whole customer, reseller and administrator surface: the read model,
every mutation, rate limiting, an audit trail, a browser explorer and
packaging. `apiVersion` answers `2.0.0`.

### Added

- The whole customer graph now reads. `Customer` carries its contact details,
  allowances, month-to-date usage, feature flags and provisioning state, and
  from it hang domains, subdomains, domain aliases and the subdomains of an
  alias, mail accounts, FTP users, SQL databases and users, DNS records, IP
  addresses, the owning reseller and that reseller's hosting plans.
- Six root fields: `node`, `customer`, `customers`, `reseller`, `resellers`
  and `pending`, with opaque identifiers, filters and paging. `ipAddresses`
  joins them: every IP address the server has, administrator only — a
  reseller reaches its own subset through `Reseller.ipAddresses`.
- Batch loading on every edge, so a document costs one query per level rather
  than one per parent. A query-count test executes a deep document against the
  reference box and pins it at **31 queries**, then asserts that twelve
  customers cost exactly what one does — an N+1 anywhere in the graph moves
  that number, and slack is where an N+1 hides.
- A per-type scope gate on `node` and `pending`. A plain value field has no
  resolver of its own, so without the gate either root field served every
  field of every type to any credential that could name an identifier.
- `trusted_proxies` in `config.php`: the reverse proxies whose
  `X-Forwarded-Proto` (or `X-Forwarded-SSL`) may be believed, as addresses or
  CIDR ranges. Empty by default, which believes nobody.
- **Customer-level mutations.** Everything a customer owns can be created,
  changed and deleted: `domainUpdate`; `subdomainCreate`, `subdomainUpdate`,
  `subdomainDelete`; `domainAliasCreate`, `domainAliasUpdate`,
  `domainAliasDelete`; `mailAccountCreate`, `mailAccountUpdate`,
  `mailAccountDelete`, `mailAutoresponderSet`, `mailCatchallCreate`,
  `mailCatchallDelete`; `ftpUserCreate`, `ftpUserUpdate`, `ftpUserDelete`;
  `sqlDatabaseCreate`, `sqlDatabaseDelete`, `sqlUserCreate`,
  `sqlUserSetPassword`, `sqlUserDelete`; `dnsRecordCreate`, `dnsRecordUpdate`,
  `dnsRecordDelete`. A customer, their reseller and an administrator may each
  call them; nobody else sees the object (`NOT_FOUND`).
- **Reseller mutations.** A reseller (or an administrator) may create,
  change, enable, disable and delete a customer in one call each —
  `customerCreate`, `customerUpdate`, `customerDelete`, `customerSetState`,
  `customerSetApiAccess` — create, change and delete a hosting plan
  (`hostingPlanCreate`, `hostingPlanUpdate`, `hostingPlanDelete`), and approve
  or reject a customer's `ORDERED` domain alias (`domainAliasApprove`,
  `domainAliasReject`). `customerCreate` takes the whole of
  `CustomerCreateInput` — name, password, domain, IP, contact and allowances
  — in one call, the way the panel's own three-page form does it in three.
- **Administrator mutations.** An administrator may create, change, delete
  and withdraw API access from a reseller (`resellerCreate`, `resellerUpdate`,
  `resellerDelete`, `resellerSetApiAccess`). `resellerDelete` refuses while
  the reseller still has a customer; a reseller carries no provisioning state
  of its own (M16), so both mutations are written at once.
- **Credentials over the API.** `tokenIssue` mints a bearer token from a
  username and password — the one field served without a credential — and is
  rate limited far more tightly than anything else (5 a minute per source
  address, 10 an hour per username), independently switchable with
  `allow_password_grant`. `tokenRevoke` revokes one of the caller's own
  tokens, idempotently. Neither ever returns or logs the secret in `token`
  more than the one time `tokenIssue` mints it.
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
- The authorisation matrix of the specification's §17: all 40 mutations as
  each of six accounts, and the scope rule for each, against a seeded database.
- **Rate limiting (spec §10.3).** Every request costs one `queries` hit
  (`rate_limit_queries`, 120 a minute); a mutation costs a second `mutations`
  hit as well (`rate_limit_mutations`, 30 a minute) — two buckets, not one
  bucket counted twice. Refused with `RATE_LIMITED`, a `Retry-After` header
  and `extensions.retryAfterSeconds`. Counted in APCu where the panel has it
  and in the plugin's own `api_rate` table where it does not (dropped on
  uninstall; no i-MSCP table is touched). D35: a request from an address
  listed in the new `trusted_clients` gets a bigger bucket
  (`rate_limit_queries_trusted`, `rate_limit_mutations_trusted` — 1,200 and
  600 a minute by default) instead of the ordinary one, never no bucket at
  all; empty by default, and never consulted for `tokenIssue`.
- **The audit trail (spec §11).** One `api_audit` row per request in
  `mutations` mode (the default); `all` or `none` are also selectable. Every
  `Secret`-typed value is redacted to `***` by walking the document's
  variables against the schema's own types, not by name, so a secret field
  added tomorrow is redacted the day it is added. Kept for
  `audit_retention_days` (90 by default) and pruned from roughly one write in
  a hundred; a failed write is reported to the panel's log rather than
  failing the request (decision D27). Read from the panel itself, under
  *System tools / API* — there is no GraphQL field that returns a row.
- **Query complexity, per list.** Every `*Connection` field, and every plain
  list the schema has no `page` argument for, declares a complexity
  proportional to what it will actually fetch (spec §10.2) — a page of 200
  costs more than a page of one, and a flat list, having no limit to read, is
  charged a named estimate of 25. See *Fixed* below for the default this made
  necessary.
- **The browser explorer.** A GraphiQL-based query explorer, off by default
  (`explorer` in `config.php`), reachable from each role's own menu and
  authenticated the same way as any other panel page. Vendored rather than
  fetched from a CDN (decision D28: this plugin runs on boxes with no
  outbound access). Every request it sends is an ordinary request to
  `/api/graphql` — same middleware, same rate limits, same audit trail.
- `validate_ftp_home_dir` in `config.php`: whether FTP home directories and
  new document roots are checked to exist before writing. On by default.
- `test/api/provision.php`: provisions real objects through the API, runs
  i-MSCP's backend over them, checks the vhost, the maildir and the SQL login,
  then deletes everything and checks it is gone. Gains a customer lifecycle
  at the front: `customerCreate` with explicit allowances through to
  settled, then `customerDelete` through to settled, checking the account
  and its web directory both appear and both disappear. Its sweep now also
  recognises a leftover customer (an `admin` row and a `domain` row, not
  only the per-object leftovers) from a run that died mid-way.
- Composer scripts `schema`, `schema:check` and `schema:print`, which export
  `schema/schema.printed.graphql` — the printed SDL a client generator builds
  against, shipped in the release archive, and the same bytes the snapshot
  test compares. Regenerating it is now one command rather than a shell
  redirection nobody remembers. The snapshot moved there from
  `test/schema/`, which packaging excludes.
- `tools/package.sh`: builds `SGW_GraphQL.tgz` from a clean checkout,
  honouring `upload-exclude.txt`, and refuses to run against a dirty working
  tree or a failing `tools/test.sh`.

### Changed

- **`Customer.reseller` is nullable.** A client that assumed it non-null must
  handle null: `admin.created_by` is nullable, so a customer an administrator
  created directly has no reseller to name. While the field was `Reseller!`
  that null nulled the whole `Customer`, and inside
  `CustomerConnection.nodes: [Customer!]!` it nulled the connection — one such
  account broke `customers` for every customer in the page. No amount of
  handling can conjure a reseller that does not exist, so the field says so.
- `MailAccount.forwardTo` now lists a catch-all's addresses. It read every
  catch-all as delivering nowhere.

### Removed

- The `saygoweb/anorm` dependency. No write used it, and the one read that did
  is now a plain batched query with the same cost. The plugin has one runtime
  dependency, `webonyx/graphql-php`.

### Fixed

- **`max_query_complexity`'s shipped default refused an ordinary read.** A
  list's cost is what it can return times its own selection, and those
  multiply when lists nest. Charging a plain list the 200 page cap made any
  two nested plain lists cost 200 × 200 = 40,000, so a customer reading its
  own domain with its subdomains and aliases measured 40,403 — refused by the
  shipped default, and by the 1,000 before it. A default that refuses a
  legitimate read is worse than shipping no limit at all. A plain list is now
  charged a named estimate of 25 (see *Added* above), and
  `max_query_complexity` ships at **50,000**, derived from nine documents
  measured directly against the built schema rather than reasoned about.
  `docs/API.md`'s "Query cost" section prints every one of them in full with
  its measured number, so an operator can reproduce the table before moving
  the value.
- TLS is no longer mistaken for plain HTTP behind a proxy. Where nginx or
  Apache terminates TLS and proxies to PHP-FPM, the backend sees scheme
  `http`, no `HTTPS` and port 80, and the insecure branch does not merely
  answer `403` — it revokes the bearer it was handed. A correctly configured
  client destroyed its own token on its first valid request. The forwarded
  scheme is now honoured, but only from an address in `trusted_proxies`, since
  any client can send that header. `SERVER_PORT` is no longer consulted at
  all: plaintext arriving on 443 used to count as proof of TLS.
- A bearer token that is presented and refused — revoked, expired, or outside
  its own address allow-list — is now a `401`. It used to fall through to the
  panel session, which records no scopes and is therefore a *full* credential:
  the holder of a token that had just been revoked carried on working with
  more authority than the token ever granted. A request carrying no bearer at
  all still authenticates from the session, as `allow_session_auth` intends.
- **`max_page_size` was displayed and read by nothing.** The audit page
  showed it, `TypeResolver::PAGE_MAX` was a hardcoded 200, and the complexity
  charge used the constant too, so an operator who lowered the key saw the
  page change and behaviour not. It is now the ceiling in both places that
  need it — where a page is applied and where it is charged — with 200 as the
  default rather than the value.
- **A missing explorer asset was served as an empty `200`.**
  `@file_get_contents()` suppressed the failure and `false` was cast to an
  empty body, so a partial deploy rendered a blank explorer with `GraphiQL is
  not defined` in the browser and no signal at all on the server. It is a
  `404` now, with a line in the panel's log naming the file.
- **`provision.php` could not recover from an interrupted customer delete.**
  Its sweep skipped a stray whose `admin_status` was already `todelete`,
  without running the backend over it. The lifecycle then called
  `customerCreate` with the `sgwe2e` username that row still held, was refused
  as a duplicate, and the script died — and because nothing had run the
  backend, the next run did the same, for ever. Such a stray now falls through
  to the wait, which runs the backend between polls; only the mutation it has
  already been asked for is skipped.
- **The audit page's "Effective configuration" omitted this wave's keys.** It
  listed every key up to `rate_limit_token_issue` and stopped, so
  `trusted_clients`, `rate_limit_queries_trusted` and
  `rate_limit_mutations_trusted` were missing: the one page an administrator
  uses to see who is exempt from the ordinary rate limits did not show the
  exemption list. All four of this release's new keys are there now, and the
  list is asserted against `config.php`'s own keys so the next one cannot go
  missing quietly.
- **The explorer shipped enabled, and its documented switch did not exist.**
  `config.php` had no `explorer` key at all: the three pages gated on
  `introspection`, which is on by default, so a stock install served the
  GraphiQL explorer to every account that could log in — while this file,
  `docs/API.md` and the specification all described it as off by default, and
  an operator who followed them and set `'explorer' => false` changed nothing.
  The key now exists, defaults to `false`, and gates all three pages *and*
  their menu entries. Both it and `introspection` must be true for the
  explorer to appear, and when it does not the page names the one that is off.
- `node(id: X)` asks `CUSTOMERS_READ` for a customer that is not the caller's
  own account, as `customer(id: X)` always has. The two disagreed, and a
  `Customer` identifier is `base64("Customer:N")`, so `ACCOUNT_READ` alone
  granted a reseller exactly what `CUSTOMERS_READ` exists to gate.

### Known issues

- The bundled browser explorer's "Execute query" button can intermittently
  blank the page in Chromium-based browsers, an upstream `react-dom`
  rendering bug (see `docs/DEVELOPMENT.md`). Reloading the page recovers it;
  every request it sent still reached the API and completed, whether or not
  the result rendered.

### Proven by

- The whole read model and every mutation, against a seeded database and the
  specification's authorisation matrix (all 40 mutations, as each of six
  accounts).
- `test/api/provision.php`: a customer's full lifecycle, a subdomain, a
  mailbox, an FTP user, a SQL database and user, and a DNS record, run
  through the real i-MSCP backend on a live box — a spot check on the
  mutations it names, not every mutation in this release.

## [0.1.0] — 2026-09-05

The walking skeleton: an authenticated endpoint and the credentials to reach
it.

### Added

- `POST /api/graphql`, answering `apiVersion` and `viewer`.
- `GET /api/graphql/schema`, serving the SDL as `text/plain`. This route sits
  outside the TLS, CORS and authentication pipeline by design — see
  [`docs/SPECIFICATION.md` §4](docs/SPECIFICATION.md#4-transport) — so it
  answers any request, unauthenticated, over any origin.
- Opaque bearer tokens with scopes, expiry, per-token revocation, an optional
  address allow-list, and last-used tracking.
- Token management for customers and resellers, under *Profile / API tokens*.
- API access granted or withdrawn per customer, under
  *Reseller / Customers / API access*, with a separate control to revoke all
  of a customer's tokens without changing their access flag.
- Session authentication as a secondary credential for the endpoint itself
  (not only the panel pages), gated on a matching `X-iMSCP-CSRF` header and a
  JSON content type, so a cross-site request carrying the session cookie alone
  cannot drive it.
- TLS enforcement: a request over plain HTTP is refused with `403`, and any
  bearer token it carried is revoked.
- Depth and complexity limits, and an introspection switch.
- `Cache-Control: no-store` on every API response and on every panel response
  that displays a token secret.

### Fixed

- `allowed_by_default` in `config.php` is now honoured. Previously it was
  defined but never read, so setting it to `false` had no effect — an account
  with no `api_perm` row was always allowed regardless of the setting.

### Requires

- i-MSCP running on **PHP 7.4**. The panel ships on 7.3; moving it needs no
  code changes. See `docs/DEVELOPMENT.md`.
