# tools-local/

Operator tooling run from a workstation against a deployed panel. Nothing here
is part of the plugin, and nothing here ever ships: `tools-local/` is excluded from
the release archive (`upload-exclude.txt`, checked again by `tools/package.sh`),
from `tools/deploy.sh`'s sync, and from Composer's classmap.

`tools/` is different: it builds, tests and installs the plugin itself.

| Script | Does |
| --- | --- |
| `mint-admin-token.sh` | Mints an administrator's API token with `tokenIssue` (the admin has no tokens page) and writes it into an env file without printing it. Read-only scopes by default; `--scopes all` for every scope, `--scopes read` for every read scope, or an explicit list. With `--list` (and `--include-revoked`) it instead lists the tokens of the account whose token is in the env file, marking the one in use; with `--revoke <id>` it revokes one of them after confirmation (`--yes` for scripts; revoking the token in use always needs its name typed). Both use the stored token as the credential and never print a token secret. `--help` for options. |
