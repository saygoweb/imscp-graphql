# scripts/

Operator tooling run from a workstation against a deployed panel. Nothing here
is part of the plugin, and nothing here ever ships: `scripts/` is excluded from
the release archive (`upload-exclude.txt`, checked again by `tools/package.sh`),
from `tools/deploy.sh`'s sync, and from Composer's classmap.

`tools/` is different: it builds, tests and installs the plugin itself.

| Script | Does |
| --- | --- |
| `mint-admin-token.sh` | Mints an administrator's API token with `tokenIssue` (the admin has no tokens page) and writes it into an env file without printing it. Read-only scopes by default. `--help` for options. |
