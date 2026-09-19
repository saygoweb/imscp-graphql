# i-MSCP GraphQL Plugin

A GraphQL API for i-MSCP. A customer, reseller or administrator authenticates
as themselves and performs, over HTTP, the operations the panel would let them
perform in its web interface — and only those.

**Status: 1.0.0.** The whole customer, reseller and administrator surface:
the read model, all forty mutations, tokens and scopes, rate limiting, an
audit trail, a browser explorer and a release archive the panel installs.
See [the changelog](CHANGELOG.md) for what each release added.

* [API](docs/API.md) — a client-facing guide to what shipped: the endpoint,
  authentication, tokens, scopes, every mutation, the cost limits and the
  error model. Start here if you are writing a client.
* [Specification](docs/SPECIFICATION.md) — the design, and the reasoning
  behind it. §21 is the backlog of i-MSCP improvements that retire the
  duplication this design starts with.
* [In-core variant](docs/SPECIFICATION-IN-CORE.md) — the alternative that was
  considered and not adopted, retained for its reasoning.
* [Development](docs/DEVELOPMENT.md) — the box, deployment, tests.

## What it does

| Actor | May create, update and delete |
| --- | --- |
| Customer | Subdomains, alias subdomains, domain aliases, mail accounts and catch-alls, autoresponders, FTP users, SQL databases and users, custom DNS records; and edit their own domain's forwarding |
| Reseller | Customers, hosting plans, alias approvals — plus everything a customer may do, on their own customers |
| Administrator | Resellers — plus everything a reseller may do |

Administrator-level *system* operations — server settings, service
configuration, IP and plugin management — are deliberately out of scope.

Reads are the same graph seen from the caller's own position: a customer
reads its own account and everything hanging off it, a reseller its own
customers, an administrator everyone. An object the caller cannot reach is
`NOT_FOUND` rather than `FORBIDDEN`, so the API never confirms that something
exists to somebody who may not see it.

## What is proven, and how

Nothing here is a claim about untested code.

* An **authorisation matrix** (`test/authz/`) runs 38 of the 40 mutations as
  each of six accounts — customer, sibling customer, another reseller's
  customer, reseller, another reseller, administrator — and checks the scope
  rule for each. `tokenIssue` and `tokenRevoke`, neither of which has an
  "owner" the matrix's shape can ask about, have their own integration test
  case for case.
* An **integration suite** against a seeded MariaDB, inside a transaction, for
  every service and every read edge. A query-count test executes a deep
  document and pins it at 31 queries, then asserts that twelve customers cost
  exactly what one does, so an N+1 anywhere in the graph moves the number.
* **`test/api/provision.php`** runs a customer's whole lifecycle, a subdomain,
  a mailbox, an FTP user, a SQL database and user and a DNS record through the
  real i-MSCP backend on a live box, then checks the result in MariaDB and on
  disk and deletes everything again. That is a spot check on the mutations it
  names, not a claim about every mutation in the release.

`tools/test.sh` runs the whole suite inside the box, under both PHP 7.4 and
PHP 8.3.

## Provisioning is asynchronous

```graphql
mutation {
  subdomainCreate(input: { parentId: "RG9tYWluOjE", label: "shop" }) {
    id
    name
    provisioning { state settled }
  }
}
```

Note `provisioning`. i-MSCP provisions asynchronously: the panel records an
intent and a daemon carries it out afterwards. A mutation returns as soon as
the intent is recorded, and the client polls until it settles. The schema says
so rather than pretending otherwise — see
[§2.1](docs/SPECIFICATION.md#21-provisioning-is-asynchronous-and-the-panel-is-only-half-of-it)
and [§8.2](docs/SPECIFICATION.md#82-mutations-return-intent-not-completion).

## The browser explorer

A GraphiQL explorer ships with the plugin, vendored rather than fetched from a
CDN, and appears under each role's own menu. It is **off by default**:
`explorer` in `config.php` turns it on, and it needs `introspection` as well.
It is a developer tool on a production control panel — turn it on while you
are writing a client, and off again afterwards.

One caveat: its *Execute query* button can intermittently blank the page in
Chromium-based browsers, an upstream `react-dom` rendering bug rather than
anything this plugin does. Reloading recovers it, and the request it sent
still reached the API and completed either way. `docs/DEVELOPMENT.md` has the
full account.

## Installing it

`tools/package.sh`, run from inside the box, builds `SGW_GraphQL.tgz` from a
clean checkout — refusing a dirty tree, a stale printed schema or a red
suite — and the panel installs that archive under *System tools / Plugin
management*.

## Requirements

* i-MSCP 1.5.x (plugin API 1.5.1)
* PHP 7.4 — the panel ships on 7.3; moving it to 7.4 needs no code changes and
  is a prerequisite. The plugin's own source also runs unchanged on PHP 8.3
* Debian 13 (Trixie); developed against the Vagrant box in the i-MSCP
  repository

## How to Help

The [core-improvement backlog](docs/SPECIFICATION.md#21-the-core-improvement-backlog)
is the most useful place to start: changes to
[saygoweb/imscp](https://github.com/saygoweb/imscp) that are worth making to the
panel on their own merits — some that let this plugin delete a duplicated copy
of a rule, and some (found by testing the deployed core while building this)
that are simply defects in i-MSCP itself. The specification's
[open questions](docs/SPECIFICATION.md#20-risks-and-open-questions) are where the
design is least settled.

Report issues in the
[GitHub issue tracker](https://github.com/saygoweb/imscp-graphql/issues),
with as much detail as you can. Pull requests are welcome.

## License

```
i-MSCP SGW_GraphQL plugin
Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## Authors

* Cambell Prince <cambell.prince@gmail.com>
