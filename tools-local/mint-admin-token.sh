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
#
# Listing and revoking: the same script manages the tokens of the account
# whose token is already in the env file, with that token as the credential
# (no password prompt). The token is read from the file inside python and sent
# as the Authorization header; it is never an argument, a shell variable or a
# line of output, and no token secret is ever printed.
#
#   tools-local/mint-admin-token.sh --endpoint URL --env-file docker/.env \
#       [--var IMSCP_ADMIN_TOKEN] --list [--include-revoked]
#   tools-local/mint-admin-token.sh --endpoint URL --env-file docker/.env \
#       [--var IMSCP_ADMIN_TOKEN] --revoke <id> [--yes]
#
# --list             a table of the account's own tokens (viewer.apiTokens):
#                    id, name, prefix, scopes, created, last used, expires,
#                    status. The row marked '*' is the token in the env file.
# --include-revoked  with --list, show revoked tokens too.
# --revoke <id>      revoke one of the account's tokens by the id --list shows.
#                    Refused for an id that is not one of the account's
#                    tokens. Asks for the token's name (or 'yes') first;
#                    --yes skips that for scripts. Revoking the token in the
#                    env file itself is warned about and always needs its
#                    name typed, --yes or not: the file's token stops working.
set -euo pipefail

ENDPOINT=
ENV_FILE=
VAR=IMSCP_ADMIN_TOKEN
NAME=dev-readonly
DAYS=90
SCOPES=read
MODE=mint
REVOKE_ID=
INCLUDE_REVOKED=0
ASSUME_YES=0
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
        --list) [ "$MODE" = mint ] || die "--list and --revoke are exclusive"; MODE=list; shift ;;
        --revoke) [ "$MODE" = mint ] || die "--list and --revoke are exclusive"
                  MODE=revoke; REVOKE_ID=${2:-}; shift 2
                  [ -n "$REVOKE_ID" ] || die "--revoke needs a token id (see --list)" ;;
        --include-revoked) INCLUDE_REVOKED=1; shift ;;
        --yes) ASSUME_YES=1; shift ;;
        -h|--help) awk 'NR == 1 { next } /^#/ { print; next } { exit }' "$0"; exit 0 ;;
        *) die "unknown option '$1' (see --help)" ;;
    esac
done

[ -n "$ENDPOINT" ] || die "--endpoint is required"
[ -n "$ENV_FILE" ] || die "--env-file is required"
[ -f "$ENV_FILE" ] || die "$ENV_FILE does not exist"
grep -q "^${VAR}=" "$ENV_FILE" || die "$ENV_FILE has no uncommented ${VAR}= line to fill"
command -v python3 >/dev/null || die "python3 is required"

# --list and --revoke: one python program, which reads the bearer token out of
# the env file itself and makes the requests with urllib, so the token is
# never on a command line (curl -H would put it in ps) nor in the shell.
if [ "$MODE" != mint ]; then
    exec env ENDPOINT_="$ENDPOINT" ENV_FILE_="$ENV_FILE" VAR_="$VAR" MODE_="$MODE" \
        REVOKE_ID_="$REVOKE_ID" INCLUDE_REVOKED_="$INCLUDE_REVOKED" ASSUME_YES_="$ASSUME_YES" \
        python3 -I -c '
import json, os, re, sys, urllib.error, urllib.request

def fail(msg):
    sys.exit(os.path.basename("mint-admin-token.sh") + ": " + msg)

def bearer():
    path, var = os.environ["ENV_FILE_"], os.environ["VAR_"]
    m = re.search(r"(?m)^" + re.escape(var) + r"=(.*)$", open(path).read())
    value = (m.group(1) if m else "").strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"\x27":
        value = value[1:-1]
    if not value:
        fail("no token in " + var + " in " + path + "; mint one first")
    return value

TOKEN = bearer()

def gql(query, variables=None):
    body = json.dumps({"query": query, "variables": variables or {}}).encode()
    req = urllib.request.Request(os.environ["ENDPOINT_"], data=body, headers={
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": "Bearer " + TOKEN,
    })
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            raw = r.read()
    except urllib.error.HTTPError as e:
        raw = e.read()
        if e.code == 401:
            fail("the endpoint refused the token in " + os.environ["VAR_"] + " (HTTP 401): revoked, expired or wrong")
        if not raw:
            fail("HTTP %d from the endpoint" % e.code)
    except urllib.error.URLError as e:
        fail("could not reach the endpoint: %s" % e.reason)
    try:
        d = json.loads(raw)
    except ValueError:
        fail("the endpoint did not answer JSON")
    if d.get("errors"):
        for e in d["errors"]:
            print(" -", e.get("message"), e.get("extensions", {}), file=sys.stderr)
        fail("the request failed")
    return d.get("data") or {}

FIELDS = "id name prefix scopes createdAt expiresAt lastUsedAt revokedAt"

def tokens(include_revoked):
    data = gql("query($r: Boolean) { viewer { username token { id } apiTokens(includeRevoked: $r) { %s } } }" % FIELDS,
               {"r": include_revoked})
    v = data.get("viewer") or {}
    return v.get("username", "?"), (v.get("token") or {}).get("id"), v.get("apiTokens") or []

def when(ts):
    return "-" if not ts else ts.replace("T", " ")[:16]

def status(t):
    from datetime import datetime, timezone
    if t.get("revokedAt"):
        return "revoked " + when(t["revokedAt"])[:10]
    exp = t.get("expiresAt")
    if exp:
        try:
            at = datetime.fromisoformat(exp.replace("Z", "+00:00"))
            if at <= datetime.now(timezone.utc):
                return "expired"
        except ValueError:
            pass
    return "active"

def table(rows, current):
    head = ["", "ID", "NAME", "PREFIX", "SCOPES", "CREATED", "LAST USED", "EXPIRES", "STATUS"]
    body = [["*" if t["id"] == current else "", t["id"], t["name"], t["prefix"],
             str(len(t.get("scopes") or [])), when(t.get("createdAt")), when(t.get("lastUsedAt")),
             when(t.get("expiresAt")) if t.get("expiresAt") else "never", status(t)] for t in rows]
    widths = [max(len(r[i]) for r in [head] + body) for i in range(len(head))]
    for r in [head] + body:
        print("  ".join(c.ljust(w) for c, w in zip(r, widths)).rstrip())

mode = os.environ["MODE_"]

if mode == "list":
    include = os.environ["INCLUDE_REVOKED_"] == "1"
    user, current, rows = tokens(include)
    print("API tokens of %s%s:" % (user, " (revoked included)" if include else ""))
    if not rows:
        print("  none")
    else:
        table(rows, current)
        if current is None:
            print("(the token in %s is not among these)" % os.environ["VAR_"])
        else:
            print("* the token in %s, used for this call" % os.environ["VAR_"])
    sys.exit(0)

# revoke
target = os.environ["REVOKE_ID_"]
user, current, rows = tokens(True)
match = [t for t in rows if t["id"] == target]
if not match:
    fail("%s is not one of the tokens of %s; nothing revoked (see --list --include-revoked)" % (target, user))
t = match[0]
print("Token %s of %s:" % (t["id"], user))
print("  name     %s" % t["name"])
print("  prefix   %s" % t["prefix"])
print("  expires  %s" % (when(t.get("expiresAt")) if t.get("expiresAt") else "never"))
print("  status   %s" % status(t))
if t.get("revokedAt"):
    print("Already revoked; nothing to do.")
    sys.exit(0)

def ask(prompt):
    sys.stdout.write(prompt)
    sys.stdout.flush()
    line = sys.stdin.readline()
    if not line:
        print()
    return line.strip()

if t["id"] == current:
    print()
    print("!" * 72)
    print("!! WARNING: this is the token in %s, the one making this call." % os.environ["VAR_"])
    print("!! Revoking it locks this script (and anything else using it) out.")
    print("!! You will have to mint a new one to use the API again.")
    print("!" * 72)
    answer = ask("Type the token name (%s) to revoke the token you are using: " % t["name"])
    if answer != t["name"]:
        fail("not confirmed; nothing revoked")
elif os.environ["ASSUME_YES_"] != "1":
    answer = ask("Type the token name (%s) or yes to revoke it: " % t["name"])
    if answer != t["name"] and answer.lower() != "yes":
        fail("not confirmed; nothing revoked")

done = gql("mutation($id: ID!) { tokenRevoke(id: $id) { id name prefix revokedAt } }", {"id": t["id"]}).get("tokenRevoke") or {}
if not done.get("revokedAt"):
    fail("tokenRevoke did not report a revokedAt")
print("Revoked %s (%s, prefix %s) at %s." % (done.get("id"), done.get("name"), done.get("prefix"), done["revokedAt"]))
if t["id"] == current:
    print("The token in %s no longer works." % os.environ["VAR_"])
'
fi

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
