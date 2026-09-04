# i-MSCP GraphQL Plugin

A GraphQL API for i-MSCP. A customer, reseller or administrator authenticates
as themselves and performs, over HTTP, the operations the panel would let them
perform in its web interface — and only those.

**Status: specification accepted; implementation follows.** Built as an i-MSCP
plugin, targeting **PHP 7.4** (the panel moves off 7.3 first; 8.3 later).

* [Specification](docs/SPECIFICATION.md) — the design, and the reasoning
  behind it. §21 is the backlog of i-MSCP improvements that retire the
  duplication this design starts with.
* [In-core variant](docs/SPECIFICATION-IN-CORE.md) — the alternative that was
  considered and not adopted, retained for its reasoning.
* [Development](docs/DEVELOPMENT.md) — the Vagrant box, deployment, tests.

## What it will do

| Actor | May create, update and delete |
| --- | --- |
| Customer | Subdomains, alias subdomains, domain aliases, mail accounts and catch-alls, autoresponders, FTP users, SQL databases and users, custom DNS records; and edit their own domain's forwarding |
| Reseller | Customers, hosting plans, alias approvals — plus everything a customer may do, on their own customers |
| Administrator | Resellers — plus everything a reseller may do |

Administrator-level *system* operations — server settings, service
configuration, IP and plugin management — are deliberately out of scope.

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

## Requirements

* i-MSCP 1.5.x (plugin API 1.5.1)
* PHP 7.4 — the panel ships on 7.3; moving it to 7.4 needs no code changes and
  is a prerequisite. The plugin's own source also runs unchanged on PHP 8.3
* Debian 13 (Trixie); developed against the Vagrant box in the i-MSCP
  repository

## How to Help

The [core-improvement backlog](docs/SPECIFICATION.md#21-the-core-improvement-backlog)
is the most useful place to start: six changes to
[saygoweb/imscp](https://github.com/saygoweb/imscp) that are worth making to the
panel on their own merits, and that each let this plugin delete a duplicated
copy of a rule. The specification's
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
