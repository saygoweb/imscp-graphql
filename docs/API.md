# Using the API

A client-facing guide to what the API does today: the whole customer graph to
read, and every customer-level object to write. It does not restate
[`docs/SPECIFICATION.md`](SPECIFICATION.md) — read that for the design and the
reasoning. This page is what a client author needs and nothing else.

**Not yet implemented:** reseller and administrator mutations (customers,
hosting plans, resellers, alias approval), `tokenIssue` and `tokenRevoke`, the
browser explorer, rate limiting, and the audit log. If you have found
documentation elsewhere describing them, it is the specification describing
where this API is going, not what it does today.

## Getting a token

Log in to the panel and open *Profile / API tokens* (customers and resellers
both have this page, under their own menu). Give the token a name, choose the
scopes it needs — or none, to inherit everything your account can do — a
lifetime, and optionally a comma-separated list of addresses or CIDR ranges it
may be used from. The plaintext token is shown exactly once, in the response
to that form; copy it immediately, because the panel stores only its hash and
cannot show it to you again. A resubmission of the same form (with a
different name) mints a new token rather than replacing the old one, up to
`token_max_per_account` (10 by default).

A token name may not be empty, may not exceed 255 characters, and may not
contain `{` or `}`. The brace restriction is not arbitrary: i-MSCP's template
engine re-expands its own substituted output, and a name containing a
placeholder-shaped string can hang the page that renders it forever (tracked
as `docs/SPECIFICATION.md` §21's C8). Rejecting the character at creation is
cheaper than living with the consequence.

A reseller can withdraw a customer's API access entirely, under *Reseller /
Customers / API access*; withdrawing revokes every token that customer holds.
A reseller can also revoke all of a customer's tokens without touching their
access flag — useful if a token has leaked but the customer should keep using
the API. Both of these are panel pages, not API operations, and both require
a CSRF token on their `POST`, like every other state-changing page in the
panel; this matters only if you are scripting against the pages themselves
rather than against the API.

## The endpoint

```
POST /api/graphql
```

(The path is configurable; this is the default.) Every request needs:

| Header | Value |
| --- | --- |
| `Authorization` | `Bearer <token>` |
| `Content-Type` | `application/json` |

The body is `{"query": "...", "variables": {...}, "operationName": "..."}` —
`variables` and `operationName` are optional. The response is always
`application/json`, and is a GraphQL result envelope: `{"data": ...}`,
`{"errors": [...]}`, or both together, per the GraphQL specification.

A panel session can also authenticate a request to this endpoint, not only to
the panel's own pages, as a secondary credential (`allow_session_auth` in
`config.php`). It additionally requires an `X-iMSCP-CSRF` header matching your
session's token, obtainable only from a page the session itself rendered —
this exists so that a page carrying your session cookie on another site cannot
drive the API on your behalf. Unless you are building something that runs
inside the panel itself, use a bearer token.

### A complete example

```
$ curl -s https://panel.example.com/api/graphql \
    -H "Authorization: Bearer imscp_ab3x9k2m_kQ1h2N…" \
    -H 'Content-Type: application/json' \
    -d '{"query": "{ apiVersion viewer { id username role email scopes } }"}'
```

```json
{
  "data": {
    "apiVersion": "1.2.0",
    "viewer": {
      "id": "Vmlld2VyOjE",
      "username": "jdoe",
      "role": "CUSTOMER",
      "email": "jdoe@example.com",
      "scopes": []
    }
  }
}
```

`viewer.id` is an opaque, type-tagged identifier (`docs/SPECIFICATION.md`
§3.5) — decode it if you like, but do not construct one yourself; a future
schema version is free to change its internal shape as long as it still
round-trips. An empty `scopes` array means the token carries no scope
restriction and can do everything your account can — narrower tokens report
the scopes they were issued with, for example `["MAIL_READ"]`.

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

### Every mutation

Grouped as the schema groups them. `GET /api/graphql/schema` has the exact
input and return shapes.

**Virtual hosts**

- `domainUpdate` — the customer's main domain: forwarding, document root and wildcard.
- `subdomainCreate` — hangs a subdomain off a domain or domain alias.
- `subdomainUpdate` — document root, forwarding, wildcard.
- `subdomainDelete` — schedules the subdomain for deletion, with its FTP users, mail accounts, certificates and protected areas.
- `domainAliasCreate` — a customer's own is `ORDERED` until their reseller approves it; a reseller's or administrator's is `PENDING`.
- `domainAliasUpdate` — document root, forwarding, wildcard.
- `domainAliasDelete` — an `ORDERED` alias is withdrawn at once; any other is scheduled for deletion with everything under it.

**Mail**

- `mailAccountCreate` — a mailbox needs a password, and a quota when the account has a mail quota; a forward needs `forwardTo`; `MAILBOX_AND_FORWARD` needs both.
- `mailAccountUpdate` — absent fields are kept; turning a forward into a mailbox needs a password and, where the account has a mail quota, a quota.
- `mailAccountDelete` — also removes the address from the customer's other forwards and catch-alls, deleting one left with nothing to deliver to; the panel's protected default accounts (abuse, hostmaster, postmaster, webmaster) are `FORBIDDEN`.
- `mailAutoresponderSet` — enabling one that has never had a message needs a message; otherwise the stored one is kept.
- `mailCatchallCreate` — one per host; a second is `CONFLICT`.
- `mailCatchallDelete` — removes the catch-all.

**FTP**

- `ftpUserCreate` — the login is username@ the chosen host's name; the home directory must already exist.
- `ftpUserUpdate` — password, home directory.
- `ftpUserDelete` — removes the FTP user.

**SQL**

- `sqlDatabaseCreate` — created by the panel at once; no provisioning state to wait for.
- `sqlDatabaseDelete` — drops the database; a user granted only this database is dropped with it, a user granted others too loses this grant only.
- `sqlUserCreate` — creates a user and grants it the database, or grants the database to one of the same customer's existing users.
- `sqlUserSetPassword` — the password of the SQL account itself, which is the same account on every database it is granted.
- `sqlUserDelete` — removes this grant; the SQL account goes with its last grant.

**DNS**

- `dnsRecordCreate` — one of the types a customer may manage: A, AAAA, CNAME, MX, NS, SPF, SRV or TXT.
- `dnsRecordUpdate` — replaces the record's name, TTL and data together; its type cannot change. A record a plugin owns (`ownedBy` other than `custom_dns_feature`) is `FORBIDDEN`.
- `dnsRecordDelete` — removes the record.

## Errors

Every error carries `extensions.code`, drawn from a closed set that is part of
the compatibility contract — a code is never removed within a major version.

| Code | HTTP | What it means | What to do |
| --- | --- | --- | --- |
| `UNAUTHENTICATED` | 401 | No credential was presented, or it was rejected (unknown, revoked, expired, or the caller's address is not on the token's allow-list) | Re-authenticate. Do not retry with the same token |
| `API_ACCESS_WITHDRAWN` | 403 | The credential is valid, but a reseller or administrator has withdrawn this account's use of the API | Contact the reseller or administrator. Retrying will not help |
| `FORBIDDEN` | 200 | The object is visible but this operation on it is not permitted: a scope the credential lacks (`extensions.scope`), a state the operation does not accept (`extensions.state`), or an object the panel protects | Check the token's scopes, or that the account has the right role |
| `NOT_FOUND` | 200 | No such object, or it exists but is not yours | Treat identically to a genuine absence — the API does not distinguish the two, deliberately |
| `BAD_USER_INPUT` | 200 | Input failed validation. Carries `extensions.field` | Fix the named field and resubmit |
| `LIMIT_EXCEEDED` | 200 | A quota was hit. Carries `extensions.limit` and `extensions.used` | Do not retry until the quota has room. `extensions.quota` names which allowance |
| `FEATURE_UNAVAILABLE` | 200 | The feature is withheld from this account by its hosting plan | Not actionable by the client: the account's reseller withholds it. `extensions.feature` names it |
| `CONFLICT` | 200 | The object has not settled (carries `extensions.retryAfterSeconds`), or the name is taken | Retry after that many seconds, or pick a different name |
| `RATE_LIMITED` | 429 | Too many requests. Carries `Retry-After` | Back off for that many seconds. Not yet enforced — see below |
| `QUERY_TOO_COMPLEX` | 400 | The document exceeded the configured depth or complexity limit | Simplify the query; this is a client-side fix, not a retry |
| `INTERNAL` | 200 | A resolver raised once the document was already executing — `data` is present alongside `errors`, so a real result exists | Safe to retry; if it persists, report the correlation id in the message (only present when the fault is genuinely on this side) |
| `INTERNAL` | 500 | The request failed before the document ran at all — a wiring fault, not something the query caused | Safe to retry; report the correlation id if one is present |

`RATE_LIMITED` is part of the closed vocabulary the schema commits to, but
nothing triggers it yet: rate limiting arrives in a later phase. It is listed
because a code is a stable part of the contract from the moment it can appear
at all, and a client written against this table today needs no change when it
starts firing.

Beyond the table: `200` always means the response body is a genuine GraphQL
result envelope, whatever it contains, including a `200` that carries nothing
but `errors`. `400` is reserved for what is genuinely the client's to fix — a
request body that is not valid JSON, a body with no `query` string, a
document with a syntax error, or one that fails validation (an unknown field,
for instance) — in every one of those cases the document never ran, so there
is nothing under `data`. `500` is also "the document never ran", but for a
reason that was not the client's doing.

## Methods

`GET` is refused for every operation, including queries, with `405`. This is
deliberate: `GET` is the one thing that lets an endpoint be driven from an
`<img>` tag or a plain link, and nothing here needs HTTP-level caching of
query results enough to pay for that risk. `OPTIONS` is accepted, for CORS
preflight only, and only from an origin listed in `allowed_origins` — that
list is empty by default, so no browser origin is allowed until an operator
adds one.

## Caching

Every response from this endpoint carries `Cache-Control: no-store`, because
a response is keyed to a bearer token and must never sit in a shared cache.
The same header is set on any panel page that displays a token's plaintext
secret. On the reference deployment you will also see a second, contradictory
`Cache-Control: public` line on the wire — that comes from a server-wide nginx
directive unrelated to this plugin (`docs/SPECIFICATION.md` §21's C9) and does
not weaken the guarantee: `no-store` wins over `public` in a combined
directive list. If you are inspecting raw headers, expect to see both lines
and do not read the second as a bug in this endpoint.

## The schema

```
GET /api/graphql/schema
```

Returns the current SDL as `text/plain`, for codegen and tooling. This route
is intentionally outside the authentication, TLS and CORS checks the main
endpoint enforces — it is public documentation, not data, and introspection
on the main endpoint is enabled by default anyway. Two things follow from
that: there are no CORS headers on this route, so a browser-based tool on
another origin cannot fetch it directly; and because it is not behind
`TlsMiddleware`, sending your `Authorization` header to this route over plain
HTTP will leak the token on the wire — there is no reason to send credentials
to it at all, so don't.

The schema grows additively and will keep doing so: fields and types are
added, and enums may gain values. `Scope` says so in its own description and
is the one most likely to grow, as API features are added; `Role` mirrors the
panel's three account types and is not expected to grow, but asks the same
tolerance of you anyway. A client must not treat an unrecognised enum value as
an error — decode what you understand and pass the rest through, the way you
would for any field you have not started using yet.
