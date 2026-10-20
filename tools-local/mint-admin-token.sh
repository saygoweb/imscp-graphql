#!/usr/bin/env bash
# Mint an API token for an i-MSCP administrator and write it into an env file,
# without the token ever being printed.
#
# The admin has no "API tokens" page (only System tools / API and API
# explorer), so an admin token is minted with tokenIssue's password grant
# (docs/API.md, "Or over the API"). This wraps that call so the password is
# read without echo and the plaintext token goes straight into the file a
# client reads it from - a terminal or a chat log never sees it.
#
# Operator tooling, run from a workstation. Never part of the plugin: tools-local/
# is excluded from the release archive (upload-exclude.txt), from
# tools/deploy.sh's sync, and from the autoloader's classmap.
#
#   tools-local/mint-admin-token.sh --endpoint https://panel.example.com:8443/api/graphql \
#       --env-file docker/.env [--var IMSCP_ADMIN_TOKEN] [--name dev-readonly] \
#       [--days 90] [--scopes read|all|ACCOUNT_READ,DOMAINS_READ,...]
#
# --scopes takes a comma-separated list, or a shorthand:
#   read   every *_READ scope (the default)
#   all    every scope, read and write
# The shorthands expand from `enum Scope` in this checkout's
# schema/schema.graphql, the schema the plugin is built from, so they follow
# scopes as they are added. (The server's own list can't be asked for first:
# introspection needs a token, and minting one is what this script is for.)
# The default is read, so a token minted without thinking about it cannot
# write. Pass --scopes all to widen it deliberately.
# The env file must already hold an uncommented `VAR=` line; that line's value
# is replaced and nothing else in the file changes.
set -euo pipefail

ENDPOINT=
ENV_FILE=
VAR=IMSCP_ADMIN_TOKEN
NAME=dev-readonly
DAYS=90
SCOPES=read
SCHEMA=$(cd "$(dirname "$0")/.." && pwd)/schema/schema.graphql

die() { echo "$(basename "$0"): $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --endpoint) ENDPOINT=${2:-}; shift 2 ;;
        --env-file) ENV_FILE=${2:-}; shift 2 ;;
        --var) VAR=${2:-}; shift 2 ;;
        --name) NAME=${2:-}; shift 2 ;;
        --days) DAYS=${2:-}; shift 2 ;;
        --scopes) SCOPES=${2:-}; shift 2 ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) die "unknown option '$1' (see --help)" ;;
    esac
done

[ -n "$ENDPOINT" ] || die "--endpoint is required"
[ -n "$ENV_FILE" ] || die "--env-file is required"
[ -f "$ENV_FILE" ] || die "$ENV_FILE does not exist"
grep -q "^${VAR}=" "$ENV_FILE" || die "$ENV_FILE has no uncommented ${VAR}= line to fill"
command -v python3 >/dev/null || die "python3 is required"

# The Scope enum's values, one per line, read from the schema file.
scope_enum() {
    [ -f "$SCHEMA" ] || die "$SCHEMA not found; pass --scopes as an explicit list"
    awk '/^enum Scope[ {]/{in_enum=1; next} in_enum && /^}/{exit} in_enum && /^[[:space:]]+[A-Z_]+[[:space:]]*$/{print $1}' "$SCHEMA"
}

case "$(printf '%s' "$SCOPES" | tr '[:upper:]' '[:lower:]')" in
    all)  SCOPES=$(scope_enum | paste -sd, -) ;;
    read) SCOPES=$(scope_enum | grep '_READ$' | paste -sd, -) ;;
esac
[ -n "$SCOPES" ] || die "no scopes resolved from $SCHEMA"
echo "Scopes: $SCOPES"

read -r -p "i-MSCP admin username: " user
read -r -s -p "i-MSCP admin password: " pass
echo

# The request body is built by python from the environment rather than by
# shell interpolation, so a password holding quotes or backslashes is sent
# as typed.
body=$(USER_="$user" PASS_="$pass" NAME_="$NAME" DAYS_="$DAYS" SCOPES_="$SCOPES" python3 -c '
import json, os
print(json.dumps({
    "query": "mutation($i: TokenIssueInput!) { tokenIssue(input: $i) { token expiresAt } }",
    "variables": {"i": {
        "username": os.environ["USER_"],
        "password": os.environ["PASS_"],
        "name": os.environ["NAME_"],
        "expiresInDays": int(os.environ["DAYS_"]),
        "scopes": [s for s in os.environ["SCOPES_"].split(",") if s],
    }},
}))')
unset pass

resp=$(curl -sS "$ENDPOINT" -H 'Content-Type: application/json' --data-binary @- <<<"$body")

# Written by python too, reading the token from the response on stdin, so it
# is never a shell variable, an argument or a line of output.
ENV_FILE_="$ENV_FILE" VAR_="$VAR" python3 -c '
import json, os, re, sys
try:
    d = json.load(sys.stdin)
except ValueError:
    sys.exit("tokenIssue: the endpoint did not answer JSON")
issued = (d.get("data") or {}).get("tokenIssue") or {}
if not issued.get("token"):
    for e in d.get("errors", []):
        print(" -", e.get("message"), e.get("extensions", {}), file=sys.stderr)
    sys.exit("tokenIssue failed")
path, var = os.environ["ENV_FILE_"], os.environ["VAR_"]
text = open(path).read()
text, n = re.subn(r"(?m)^" + re.escape(var) + r"=.*$", lambda m: var + "=" + issued["token"], text, count=1)
if n != 1:
    sys.exit("no uncommented " + var + "= line in " + path)
open(path, "w").write(text)
print("Token written to " + path + " as " + var + " (expires " + issued["expiresAt"] + ").")
' <<<"$resp"
