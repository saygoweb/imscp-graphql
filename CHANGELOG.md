# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

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
