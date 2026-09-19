# Using the API

A client-facing guide to what the API does today: the whole customer,
reseller and administrator graph to read, and every object each of those may
create, change or delete. It does not restate
[`docs/SPECIFICATION.md`](SPECIFICATION.md) — read that for the design and the
reasoning. This page is what a client author needs and nothing else.

**What is proven, and how.** Every mutation below, and the read model behind
it, is exercised by a seeded-database integration suite and by an
authorisation matrix (`test/authz/`) that runs 38 of the 40 mutations as each
of six accounts and checks the scope rule for each; `tokenIssue` and
`tokenRevoke` — neither of which has an "owner" the matrix's shape can ask
about — are instead covered case for case by their own integration test.
`test/api/provision.php`
additionally runs a handful of these mutations — a customer's whole lifecycle,
a subdomain, a mailbox, an FTP user, a SQL database and user, a DNS record —
through the real i-MSCP backend on a live box, end to end, checking the
result in MariaDB and on disk. That is a spot check on the mutations it
names, not a claim that every mutation in this document has been run against
a live backend.

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

### Or over the API

`tokenIssue` is the one field in the schema that may be called without a
credential, because it is how you get one:

```
$ curl -s https://panel.example.com/api/graphql \
    -H 'Content-Type: application/json' \
    -d '{"query": "mutation($i: TokenIssueInput!) { tokenIssue(input: $i) { token expiresAt } }",
         "variables": {"i": {"username": "jdoe", "password": "…", "name": "ci",
                             "scopes": ["DOMAINS_WRITE"], "expiresInDays": 90}}}'
```

The same rules as the panel page apply, and a few more besides:

- `scopes` may not be empty. A token that records no scopes at all is read as
  a *full* token, so asking for none would mint the widest token your account
  can hold rather than the narrowest; the panel page's "none, to inherit
  everything" is deliberately not available here.
- A scope your role could never hold — a customer asking for
  `RESELLERS_WRITE` — is `FORBIDDEN`, naming the scope in
  `extensions.scope`. It is refused rather than dropped, so a token never
  quietly lacks something you asked for.
- `expiresInDays` is capped at `token_max_ttl_days` rather than refused, and
  an absent one is `token_default_ttl_days`. The answer's `expiresAt` is what
  you actually got. A token that never expires cannot be asked for here.
- `ipAllowlist` is a list of addresses or CIDR ranges; an empty list, or an
  absent one, means anywhere.
- The count against `token_max_per_account` is of tokens that still work:
  revoked and expired ones do not hold a place.

Every credential failure is one answer — `UNAUTHENTICATED`, "Those
credentials were not accepted." — whether the username is unknown, the
password is wrong, or the account is disabled or expired. There is no
extension telling them apart, and the two rate buckets below are charged
before the credentials are looked at, so a wrong password costs exactly what a
right one does.

This field is rate limited far more tightly than anything else: **5 attempts a
minute from one source address, and 10 an hour for one username**
(`rate_limit_token_issue` is the first of those). Exceeding either is
`RATE_LIMITED` with `extensions.retryAfterSeconds`. It is in addition to
i-MSCP's own `BruteForce` plugin, not instead of it, and the whole field can
be switched off with `allow_password_grant = false` — which answers
`FORBIDDEN` before looking at anything the caller sent.

`tokenRevoke(id:)` revokes one of *your own* tokens, by the `id` from
`tokenIssue`'s `apiToken`. It needs no scope: a narrowed token must always be
able to burn itself or a sibling that has leaked. Revoking a token that is
already revoked succeeds and returns the same `revokedAt` — it is idempotent,
not merely tolerant. Another account's token, and a token that never existed,
are both `NOT_FOUND`.

The secret in `token` is returned exactly once, here. No query returns it, no
field of `ApiToken` carries it, and only a SHA-256 of it was ever stored.

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

The one exception is `tokenIssue` above, which is served without an
`Authorization` header. A request that carries no credential is not refused at
the door any more — it reaches the schema, where every field but that one
answers `UNAUTHENTICATED`, and the response is still a `401`.

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
    "apiVersion": "2.0.0",
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

## Administrator reads

`ipAddresses` lists every IP address the server has — administrator only. A
reseller reaches its own subset through `viewer.reseller.ipAddresses` (or
`Reseller.ipAddresses` on a reseller read elsewhere in the graph), and a
customer has no field that reaches an `IpAddress` at all. It exists because
an administrator creating a reseller's first customer needs an IP id from
somewhere, and there is no other field this credential can read one from.

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

**Credentials**

- `tokenIssue` — mints a bearer token from a username and password; the only field served without a credential. See *Getting a token* above.
- `tokenRevoke` — revokes one of the caller's own tokens; revoking a revoked token succeeds.

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

**Customers** (reseller and administrator only)

- `customerCreate` — creates a customer and its main domain in one call; an administrator must name the reseller, a reseller may only name its own; exactly one of `hostingPlanId` and `allowances`.
- `customerUpdate` — partial: an absent field keeps its value; `allowances` is merged over what the customer has today, `hostingPlanId` replaces the lot.
- `customerDelete` — schedules the customer and everything it owns for deletion.
- `customerSetState` — `ENABLED` from `DISABLED` and back; any other transition, or a customer that is not settled, is `CONFLICT`.
- `customerSetApiAccess` — withdrawing access refuses the account's next API request and revokes every token it holds.

**Hosting plans** (reseller and administrator only)

- `hostingPlanCreate` — written at once; a hosting plan has no provisioning state.
- `hostingPlanUpdate` — a whole replacement of the plan's allowances, name, description and availability; the plan keeps the reseller it belongs to.
- `hostingPlanDelete` — removes the plan; customers already created from it keep what they have.

**Domain alias approval** (reseller and administrator only)

- `domainAliasApprove` — approves an alias in the `ORDERED` state.
- `domainAliasReject` — removes the order; nothing was built for it, so nothing is scheduled.

**Resellers** (administrator only)

- `resellerCreate` — written at once; a reseller has no provisioning state (M16).
- `resellerUpdate` — partial: an absent field keeps its value.
- `resellerDelete` — removes the reseller outright; refused while it still has any customer.
- `resellerSetApiAccess` — withdrawing access refuses the account's next API request and revokes every token it holds.

## Query cost

Two limits guard against an expensive document before it runs a single
resolver: `max_query_depth` (15 by default) refuses one nested deeper than
that, and `max_query_complexity` (50,000 by default) refuses one whose
*complexity* — a number computed from the document's shape alone — exceeds
the budget. Both answer `QUERY_TOO_COMPLEX`.

Complexity is charged per field. Almost every field costs one plus whatever
its own selection costs. A list is charged differently, because a list is
where an innocent-looking document gets expensive fast:

- A paginated field (`customers`, `resellers`, `Reseller.customers`,
  `Customer.mailAccounts`, `Customer.ftpUsers`) is charged the page it will
  actually fetch — `page.limit`, capped at `max_page_size` (200 by default)
  either way — times whatever its own selection costs. `page: { limit: 5000 }`
  is charged as 200, not 5000: the field never returns more than that
  regardless of what was asked for, and the charge follows what happens, not
  what was typed.
- A plain list with no `page` argument at all (`Domain.subdomains`,
  `Customer.sqlDatabases`, `Query.ipAddresses`, and the rest like them) has
  no limit to read, so it is charged a flat **25** times its own selection.
  That 25 is an estimate for a list i-MSCP bounds by the customer's own
  allowance, not an upper bound on rows: a customer with 200 subdomains is
  under-charged, deliberately. The limit exists to refuse documents that are
  structurally abusive, not to meter rows — `max_page_size` bounds the paged
  lists and the per-minute rate limits bound the actual work.

Those multiply when a document nests them, which is the trap a flat per-field
limit misses. Six documents, measured against the schema this plugin ships —
by building the real schema and running graphql-php's own `QueryComplexity`
validation rule over each one (the technique is in
`test/unit/Schema/ComplexityTest.php`), not an estimate. Each is printed in
full so you can reproduce the number rather than take it on trust:

| Document | Cost |
| --- | --- |
| `{ viewer { customer { domain { name subdomains { id name } } } } }` | 54 |
| `{ viewer { customer { domain { subdomains { id } aliases { id subdomains { id } } } } } }` | 678 |
| `{ customers(page: { limit: 50 }) { totalCount nodes { id username domain { name subdomains { id name } } } } }` | 2,800 |
| `{ customers(page: { limit: 50 }) { nodes { domain { subdomains { id } } } } }` | 1,350 |
| `{ resellers(page: { limit: 50 }) { totalCount nodes { id username customers(page: { limit: 50 }) { totalCount nodes { id username } } } } }` | 10,200 |
| `{ customers(page: { limit: 200 }) { totalCount nodes { id username domain { name subdomains { id name } aliases { id name } } sqlDatabases { id } dnsRecords { id } } } }` | 31,200 |

In order those are: a customer reading its own domain and subdomains; a
customer reading everything on its own account (two nested plain lists); a
reseller listing 50 customers with each one's domain and subdomains; the same
50 customers with nothing but their subdomain ids; an administrator listing 50
resellers with 50 customers each; and a full 200-row page — the largest single
page the API will return — with a fat selection on every row.

`max_query_complexity` ships at **50,000**, which is that last row's 31,200
with about 1.6× of headroom, and five times or more the ordinary reads above
it. The other end of the trade — what the limit exists to refuse — is nesting
paged lists at the cap. Measured the same way:

| Document | Cost |
| --- | --- |
| `{ resellers(page: { limit: 200 }) { nodes { customers(page: { limit: 200 }) { nodes { id } } } } }` | 80,200 |
| `{ customers(page: { limit: 200 }) { nodes { id mailAccounts(page: { limit: 200 }) { nodes { id address } } } } }` | 120,400 |
| `{ resellers(page: { limit: 200 }) { nodes { customers(page: { limit: 200 }) { nodes { mailAccounts(page: { limit: 200 }) { nodes { id } } } } } } }` | 16,040,200 |

Two nested full pages are refused with about 1.6× to spare; three are refused
by a factor of 320. A client that genuinely needs those rows asks for a
smaller inner page, which is what pagination is for.

An operator moving this value is trading against these same numbers: lower it
and a legitimate read like the ones above may start failing; raise it and a
document nesting two or three paged lists gets more expensive before it is
ever refused. There is no "right" number independent of what your own clients
actually send — measure your heaviest legitimate document the same way before
moving far from the shipped default. Two earlier releases set this by
reasoning rather than by measuring, and both were wrong: 1,000 refused every
ordinary read, and 20,000 refused the second row of the first table above.

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
| `RATE_LIMITED` | 429 | Too many requests. Carries a `Retry-After` header and `extensions.retryAfterSeconds`, which are the same number | Back off for that many seconds — see below |
| `QUERY_TOO_COMPLEX` | 400 | The document exceeded the configured depth or complexity limit | Simplify the query; this is a client-side fix, not a retry |
| `INTERNAL` | 200 | A resolver raised once the document was already executing — `data` is present alongside `errors`, so a real result exists | Safe to retry; if it persists, report the correlation id in the message (only present when the fault is genuinely on this side) |
| `INTERNAL` | 500 | The request failed before the document ran at all — a wiring fault, not something the query caused | Safe to retry; report the correlation id if one is present |

`RATE_LIMITED` is counted in fixed one-minute windows. Two buckets are
charged, and they are separate limits rather than alternatives: a request
costs one `queries` hit (`rate_limit_queries`, 120 a minute by default), and a
request whose operation is a mutation costs one `mutations` hit as well
(`rate_limit_mutations`, 30 a minute). Thirty mutations a minute therefore
come out of the hundred and twenty queries a minute, they are not additional
to them.

The two buckets are keyed differently, because they are charged at different
points. `queries` is charged before the credential is checked, so it is keyed
by the token *prefix* the request presented — the short public part of the
bearer, before the secret — or by the source address when no bearer was
presented at all. A token that has expired or been revoked is therefore
counted exactly like one that is accepted, rather than getting unlimited
refusals for free. `mutations` is charged once the credential is known, so it
is keyed per token, or per account for a panel session.

A request from a source address listed in `trusted_clients` — a first-party
integration on this box or a peer of it, say a panel elsewhere mirroring this
one's resellers — is charged against `rate_limit_queries_trusted` and
`rate_limit_mutations_trusted` instead (1,200 and 600 a minute by default).
It is a bigger bucket, never no bucket: a trusted client is still counted and
still refusable, because a runaway integration is a real failure mode and
unlimited is how it takes the panel down. `trusted_clients` is empty by
default, so nothing is exempted until an operator sets it, and it is never
read for `tokenIssue`, whose own limits are the tighter ones above — an
IP-based exemption there would be unlimited credential guessing at the
plugin layer, from any tenant sharing that address.

A refused request never executes, so its body carries `errors` and no `data`
key at all. `Retry-After` is the number of seconds left in the current window:
never zero, and never longer than the window itself. Waiting exactly that long
is enough — the window is fixed, not sliding, so the count is reset rather than
decayed.

A negative value for any of the three `rate_limit_*` settings means
*unlimited*, not "refuse everything".

The counters live in APCu where the panel has it and in the `api_rate` table
where it does not, and the two do not promise the same thing. APCu is a cache:
under memory pressure it can evict a counter that is still inside its window,
and the next request then looks like the first one of a fresh window — so a
caller may occasionally get an allowance it had already spent. The database
counter cannot do that; its own inaccuracy is bounded, and always in the
direction of letting through at most one request per concurrent request too
many. An installation that needs a hard bound should disable APCu for the
panel.

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

## The audit trail

Configurable with `audit` in `config.php`: `none`, `mutations` (the default)
or `all`. In `mutations` mode, one row is written to `api_audit` for every
request whose *document* contains a mutation — whether or not that mutation
is the operation that actually ran, and whether or not it succeeded; `all`
widens that to every request, queries included; `none` writes nothing.

Each row carries: when; which account and which token (both null for a panel
session, which carries no token); the source address; the operation name, if
the document gave one; the names of every top-level field the document
invoked; its variables; whether the request ended in an error and which
`extensions.code` it carried; and how long it took.

Variables are redacted by *type*, not by field name: whatever the schema
declares as `Secret` — a password, in every mutation that takes one — is
replaced with `***` before the row is written, whatever the argument happens
to be called, so a field added to the schema tomorrow that carries a secret
is redacted from the day it is added, by nobody. A document that failed to
parse, or a variable the document never declared, is redacted in full rather
than guessed at.

A row is written after the request has already been answered, so a failure to
write one never fails the request — it is reported to the panel's own log
instead, which is where an operator would notice a gap in the trail. There is
no field here for a client to call; it exists for whoever administers the
panel. Rows older than `audit_retention_days` (90 by default; less than one
keeps every row indefinitely) are pruned from roughly one write in a hundred,
never touching the row that write itself just made. An administrator reads
these rows from the panel itself, under *System tools / API* — there is no
GraphQL field that returns one.

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

## The browser explorer

`explorer` in `config.php` is **off by default**: it is a developer tool on a
production control panel, reachable by any account that can log in, and a box
with no client being written against it has no use for one. Turn it on while
integrating, and off again afterwards. It also needs `introspection`, which
is on by default — an explorer that cannot read the schema is a broken tool
rather than a reduced one, since GraphiQL's documentation pane, completions
and validation are all the introspection query. With either off, the page
says which one, and the menu does not offer it at all.

With both on, a GraphiQL-based query explorer is available from the panel
itself, labelled *API explorer* under each role's own menu — *Domains* for a customer, *Customers* for a reseller, *System
tools* for an administrator — with each role seeing only its own page,
authenticated the same way any other panel page is. It is a convenience for
reading the schema and trying a query by hand, not a separate API surface:
every request it sends goes through this same `/api/graphql` endpoint, the
same middleware and the same audit trail as a request from any other client.

Its "Execute query" button can intermittently blank the page in
Chromium-based browsers — an upstream `react-dom` rendering bug in the
vendored UI layer, not something this plugin's own code triggers; reloading
the page recovers it, and the request itself still reached the API and
completed regardless of whether the result rendered. See
[`docs/DEVELOPMENT.md`](DEVELOPMENT.md) for the full account.
