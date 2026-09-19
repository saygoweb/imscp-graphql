# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

The read model and customer mutations. `apiVersion` answers `1.2.0`: types,
fields and a `Mutation` type within the same major version.

### Added

- The whole customer graph now reads. `Customer` carries its contact details,
  allowances, month-to-date usage, feature flags and provisioning state, and
  from it hang domains, subdomains, domain aliases and the subdomains of an
  alias, mail accounts, FTP users, SQL databases and users, DNS records, IP
  addresses, the owning reseller and that reseller's hosting plans.
- Six root fields: `node`, `customer`, `customers`, `reseller`, `resellers`
  and `pending`, with opaque identifiers, filters and paging.
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
- Composer scripts `schema`, `schema:check` and `schema:print`, which export
  `schema/schema.printed.graphql` — the printed SDL a client generator builds
  against, shipped in the release archive, and the same bytes the snapshot
  test compares. Regenerating it is now one command rather than a shell
  redirection nobody remembers. The snapshot moved there from
  `test/schema/`, which packaging excludes.

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
- `node(id: X)` asks `CUSTOMERS_READ` for a customer that is not the caller's
  own account, as `customer(id: X)` always has. The two disagreed, and a
  `Customer` identifier is `base64("Customer:N")`, so `ACCOUNT_READ` alone
  granted a reseller exactly what `CUSTOMERS_READ` exists to gate.

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
