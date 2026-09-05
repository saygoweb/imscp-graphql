#!/bin/sh
# End-to-end check of the API against the running box.
#
# Everything the phase-1 endpoint promises, in the order a client would meet
# it. Run from the plugin root on the host:
#
#   test/api/smoke.sh [box]
#
# This is the host-side counterpart to tools/test.sh: that one pushes the tree
# into the box and runs the linter and the unit suite there, this one drives
# the deployed endpoint over the network exactly as a client would.
#
# On 'set -e', deliberately absent:
#
#   The brief's skeleton opened with it, and it is wrong for this script. A
#   smoke test that stops at the first failing check reports one symptom and
#   hides the rest, so the next run only ever tells you about one thing. Every
#   check here therefore records its result and the script carries on; the
#   count of failures is what decides the exit status at the end. Errors that
#   are not check failures — no box, no token, no ssh — are fatal, and go
#   through die(), which is explicit rather than implicit.
#
# On leaving the box as it was found:
#
#   Every token this script mints is remembered by id and deleted again in
#   cleanup(), which runs from an EXIT trap, so it runs on the failure paths
#   and on Ctrl-C as well as on success. The api_perm row the access-withdrawal
#   check needs is restored to whatever was there before — including "no row at
#   all", which is the normal production state. Nothing else is written.

BOX=${1:-imscp_debian_trixie}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VAGRANT_DIR=${IMSCP_VAGRANT_DIR:-$ROOT/../imscp/Vagrant}
SSH_CONFIG=$ROOT/.ssh-config
ENDPOINT=${ENDPOINT:-/api/graphql}
SCHEMA_ENDPOINT=${SCHEMA_ENDPOINT:-/api/graphql/schema}

# Every token minted by this run carries this prefix, so that a run killed
# hard enough to skip its own cleanup can still be swept up by the next one.
# It deliberately ends in a hyphen: the box carries a permanent fixture token
# named 'smoke', and 'smoke-run-%' cannot match it.
NAME_PREFIX=smoke-run-

PASSED=0
FAILED=0
PERM_INITIAL=    # 'none', or the allowed value api_perm held before we started
CLEANED=0

# Where PERM_INITIAL is written, durably, just before the withdrawal check
# mutates api_perm. A run killed too hard to run its own EXIT trap (SIGKILL,
# a power cut, a host lockup) between that mutation and the restore leaves
# the row behind; without this file the next run would read that leftover
# row as the account's genuine prior state and preserve it forever. Named
# after the box, so two runs against different boxes cannot tread on each
# other's marker.
MARKER_FILE="${TMPDIR:-/tmp}/sgw-smoke.perm-marker.$BOX"

# The ledger of minted token ids, one per line. A file rather than a variable
# because mint() is called from a command substitution, which is a subshell:
# anything it assigned to a variable would be lost the moment it returned, and
# cleanup() would have nothing to delete.
MINTED_FILE=$(mktemp "${TMPDIR:-/tmp}/sgw-smoke.XXXXXX") || exit 2

pass() { printf '  ok    %s\n' "$1"; PASSED=$((PASSED + 1)); }
fail() { printf '  FAIL  %s\n' "$1"; FAILED=$((FAILED + 1)); }

check() {
    # $1 description, $2 expected, $3 actual
    if [ "$2" = "$3" ]; then
        pass "$1"
    else
        fail "$1 (expected '$2', got '$3')"
    fi
}

check_contains() {
    # $1 description, $2 needle, $3 haystack
    case "$3" in
        *"$2"*) pass "$1" ;;
        *) fail "$1 (no '$2' in: $(printf '%s' "$3" | head -c 300))" ;;
    esac
}

check_lacks() {
    # $1 description, $2 needle, $3 haystack
    case "$3" in
        *"$2"*) fail "$1 (unexpected '$2' in: $(printf '%s' "$3" | head -c 300))" ;;
        *) pass "$1" ;;
    esac
}

die() { printf '%s: %s\n' "$0" "$1" >&2; exit 2; }

# --- the box ----------------------------------------------------------------

ssh_box() { ssh -F "$SSH_CONFIG" "$BOX" "$@"; }

sql() {
    # Statements arrive on stdin rather than in -e, so that nothing here has
    # to survive two rounds of shell quoting on the way to MariaDB.
    printf '%s\n' "$1" | ssh_box 'sudo mysql --batch --skip-column-names imscp'
}

imscp_conf() {
    ssh_box "sudo sed -n 's/^$1 *= *//p' /etc/imscp/imscp.conf" | tr -d ' \r'
}

cleanup() {
    [ "$CLEANED" -eq 1 ] && return
    CLEANED=1

    statements=
    if [ -s "$MINTED_FILE" ]; then
        while read -r id; do
            [ -n "$id" ] || continue
            statements="${statements}DELETE FROM api_token WHERE token_id = $id;
"
        done < "$MINTED_FILE"
    fi
    rm -f "$MINTED_FILE"

    # Only touched if the initial state was actually read; otherwise the row
    # that is there is somebody else's and must be left alone.
    case "$PERM_INITIAL" in
        none) statements="${statements}DELETE FROM api_perm WHERE admin_id = $ADMIN_ID;
" ;;
        ?*) statements="${statements}INSERT INTO api_perm (admin_id, allowed) VALUES ($ADMIN_ID, $PERM_INITIAL) ON DUPLICATE KEY UPDATE allowed = $PERM_INITIAL;
" ;;
    esac

    if [ -z "$statements" ]; then
        # Nothing to restore, so nothing this run could have left half-done
        # in api_perm either.
        rm -f "$MARKER_FILE"
        return
    fi

    echo
    echo "Clean up:"

    if sql "$statements" >/dev/null 2>&1; then
        pass "the box is back as it was found"
        # The restore above just did, by hand, what MARKER_FILE exists to
        # survive a kill through. Once it has succeeded, the marker's job is
        # done.
        rm -f "$MARKER_FILE"
    else
        printf '  FAIL  could not clean up. Remove by hand:\n%s' "$statements" >&2
        FAILED=$((FAILED + 1))
        # MARKER_FILE deliberately left in place: the restore above just
        # failed, so the next run must still repair api_perm from it.
    fi
}

on_signal() { cleanup; exit 130; }

trap cleanup EXIT
trap on_signal INT TERM HUP

# --- discovery --------------------------------------------------------------

[ -d "$VAGRANT_DIR" ] || die "no Vagrant directory at $VAGRANT_DIR; set IMSCP_VAGRANT_DIR"

(cd "$VAGRANT_DIR" && vagrant ssh-config "$BOX") > "$SSH_CONFIG" \
    || die "could not read the ssh config for $BOX; is the box running?"

HOST=$(awk '/HostName/ {print $2}' "$SSH_CONFIG")
[ -n "$HOST" ] || die "the ssh config for $BOX names no host"

VHOST=$(imscp_conf BASE_SERVER_VHOST)
HTTPS_PORT=$(imscp_conf BASE_SERVER_VHOST_HTTPS_PORT)
HTTP_PORT=$(imscp_conf BASE_SERVER_VHOST_HTTP_PORT)
PLUGINS_DIR=$(imscp_conf PLUGINS_DIR)
[ -n "$VHOST" ] && [ -n "$HTTPS_PORT" ] && [ -n "$PLUGINS_DIR" ] \
    || die "could not read the panel's vhost and ports from /etc/imscp/imscp.conf"

PLUGIN_DIR=$PLUGINS_DIR/SGW_GraphQL
BASE="https://$VHOST:$HTTPS_PORT"

# The account under test: the same one the brief picks, the first customer.
# A customer rather than an administrator because CUSTOMER is the role whose
# mapping the viewer check is asserting.
ADMIN_ID=$(sql "SELECT admin_id FROM admin WHERE admin_type = 'user' AND admin_status = 'ok' ORDER BY admin_id LIMIT 1;")
ADMIN_NAME=$(sql "SELECT admin_name FROM admin WHERE admin_id = $ADMIN_ID;")
[ -n "$ADMIN_ID" ] || die "the box has no customer account to test with"

# Whatever a previous run was killed before removing. Scoped to this account
# and to this script's own name prefix, so no fixture token can be caught.
sql "DELETE FROM api_token WHERE admin_id = $ADMIN_ID AND name LIKE '${NAME_PREFIX}%';" >/dev/null

# The same idea applied to api_perm: if MARKER_FILE is still here, a previous
# run was killed too hard to reach its own EXIT trap, between writing the
# withdrawal row and restoring it. Repair the row from the marker's record of
# what the account looked like before that run started, *before* reading the
# live table below as this run's baseline — otherwise the leftover row would
# be read as the account's genuine prior state and preserved forever.
if [ -f "$MARKER_FILE" ]; then
    marker_admin=$(sed -n '1p' "$MARKER_FILE")
    marker_state=$(sed -n '2p' "$MARKER_FILE")
    if [ -n "$marker_admin" ]; then
        case "$marker_state" in
            none) sql "DELETE FROM api_perm WHERE admin_id = $marker_admin;" >/dev/null \
                      || die "could not repair api_perm left by a killed run (admin_id $marker_admin)" ;;
            ?*) sql "INSERT INTO api_perm (admin_id, allowed) VALUES ($marker_admin, $marker_state) ON DUPLICATE KEY UPDATE allowed = $marker_state;" >/dev/null \
                      || die "could not repair api_perm left by a killed run (admin_id $marker_admin)" ;;
        esac
        echo "Repaired api_perm left behind by a run killed mid-withdrawal (admin_id $marker_admin)."
    fi
    rm -f "$MARKER_FILE"
fi

PERM_INITIAL=$(sql "SELECT allowed FROM api_perm WHERE admin_id = $ADMIN_ID;")
[ -n "$PERM_INITIAL" ] || PERM_INITIAL=none

echo "Endpoint: $BASE$ENDPOINT"
echo "Account:  $ADMIN_NAME (admin_id $ADMIN_ID)"

# --- minting ----------------------------------------------------------------

mint() {
    # $1 name suffix, $2 scopes as a PHP array literal, $3 TTL in days,
    # $4 IP allow-list as a PHP expression. Prints '<token id> <token>', and
    # records the id in the ledger so that it is removed however this run ends.
    #
    # Tokens are minted through TokenService directly rather than by driving
    # the panel's own forms, so that no account's password is needed and none
    # can be disturbed.
    issued=$(ssh_box 'sudo php7.4' <<EOF
<?php
define('IMSCP_CONF', '/etc/imscp/imscp.conf');
require '/var/www/imscp/gui/library/imscp-lib.php';
require '$PLUGIN_DIR/vendor/autoload.php';
\$service = iMSCP\Plugin\SGW_GraphQL\Auth\TokenService::fromPanel();
\$token = \$service->issue($ADMIN_ID, '$NAME_PREFIX$1', $2, $3, $4);
echo \$token['tokenId'], ' ', \$token['token'], PHP_EOL;
EOF
)
    issued=$(printf '%s' "$issued" | tail -n 1)
    id=${issued%% *}

    case "$id" in
        ''|*[!0-9]*) return 1 ;;
    esac

    printf '%s\n' "$id" >> "$MINTED_FILE"
    printf '%s' "$issued"
}


# --- requests ---------------------------------------------------------------

CURL="curl -sk --max-time 30 --resolve $VHOST:$HTTPS_PORT:$HOST"

gql_body() {
    # $1 a GraphQL document -> a JSON request body. The documents below carry
    # no backslashes and no newlines, so escaping the quotes is enough.
    printf '{"query":"%s"}' "$(printf '%s' "$1" | sed 's/"/\\"/g')"
}

api() {
    # $1 bearer token ('' for none), $2 document, $3.. extra curl options.
    tok=$1
    doc=$2
    shift 2

    # shellcheck disable=SC2086
    if [ -n "$tok" ]; then
        $CURL "$@" -X POST "$BASE$ENDPOINT" \
            -H "Authorization: Bearer $tok" \
            -H 'Content-Type: application/json' \
            --data-binary "$(gql_body "$doc")"
    else
        $CURL "$@" -X POST "$BASE$ENDPOINT" \
            -H 'Content-Type: application/json' \
            --data-binary "$(gql_body "$doc")"
    fi
}

api_status()  { api "$1" "$2" -o /dev/null -w '%{http_code}'; }
api_headers() { api "$1" "$2" -D - -o /dev/null; }

post_raw_status() {
    # $1 token, $2 a body that is not necessarily a GraphQL request at all.
    # shellcheck disable=SC2086
    $CURL -o /dev/null -w '%{http_code}' -X POST "$BASE$ENDPOINT" \
        -H "Authorization: Bearer $1" -H 'Content-Type: application/json' \
        --data-binary "$2"
}

json_string() {
    # $1 body, $2 field -> the first string value of that field.
    printf '%s' "$1" | sed -n "s/.*\"$2\":\"\\([^\"]*\\)\".*/\\1/p"
}

header_lines() {
    # $1 raw headers, $2 an ERE -> how many header lines match it, lower-cased
    # and stripped of CR. Counting matching lines rather than reading "the"
    # value is not fussiness: nginx appends a server-wide
    # 'add_header Cache-Control public' to the plugin's own 'no-store', so a
    # response legitimately carries two Cache-Control lines. An exact match on
    # the header, or a count of headers by name, would either fail or pass for
    # the wrong reason.
    printf '%s' "$1" | tr -d '\r' | tr 'A-Z' 'a-z' | grep -cE "$2"
}

# ============================================================================
# Each check below names the regression it is there to catch. A check that
# cannot fail against a plausible regression does not belong in this file.
# ============================================================================

# mint() is called from a command substitution, so a die() inside it would
# only leave the subshell; the status is checked here in the parent instead.
ISSUED=$(mint full '[]' 1 'null') || die "could not mint the full-access token"
TOKEN_ID=${ISSUED%% *}
TOKEN=${ISSUED#* }

echo
echo "Happy path:"
# Catches: the route unwired, the middleware pipeline throwing, the schema
# failing to build — any of which take the endpoint from 200 to 500.
HEADERS=$(api_headers "$TOKEN" '{ apiVersion }')
check "a valid query is 200" "200" "$(api_status "$TOKEN" '{ apiVersion viewer { id username role email scopes } }')"

BODY=$(api "$TOKEN" '{ apiVersion viewer { id username role email scopes } }')
# Catches: Query.apiVersion losing its entry in the resolver map, which
# resolves to null rather than erroring.
check "apiVersion is served" "1.0.0" "$(json_string "$BODY" apiVersion)"
# Catches: the token -> admin_id -> account lookup returning the wrong row.
check "the viewer is the account the token belongs to" "$ADMIN_NAME" "$(json_string "$BODY" username)"
# Catches: the admin_type -> Role mapping being lost, which would either error
# on the Role enum or report the wrong role to an authorisation-aware client.
check "the viewer's role is CUSTOMER" "CUSTOMER" "$(json_string "$BODY" role)"
# Catches: a resolver throwing while the transport still answers 200 — the
# failure mode a status check alone cannot see.
check_lacks "the envelope carries no errors" '"errors"' "$BODY"
# Catches: the handler's own Content-Type being lost, or a core helper
# answering with its own HTML error page (specification section 6.5).
check "the response is JSON" "1" "$(header_lines "$HEADERS" '^content-type: application/json')"
# Catches: the handler dropping Cache-Control: no-store, which would let a
# proxy cache a response keyed to a bearer token. Written to tolerate nginx's
# extra 'Cache-Control: public' line — see header_lines().
check "the response is not cacheable" "1" "$(header_lines "$HEADERS" '^cache-control: *no-store *$')"
# Catches: stampLastUsed() silently failing, which loses the only evidence of
# when a credential was last exercised — and proves the request really did go
# through verify() rather than some path that skips it.
LAST_USED=$(sql "SELECT last_used_at FROM api_token WHERE token_id = $TOKEN_ID;")
check_lacks "the token records that it was used" "NULL" "$LAST_USED"

echo
echo "Transport:"
NO_CRED=$(api '' '{ apiVersion }')
NO_CRED_HEADERS=$(api_headers '' '{ apiVersion }')
# Catches: authentication being skipped altogether, the fail-open shape this
# plugin has had to close more than once.
check "no credential is 401" "401" "$(api_status '' '{ apiVersion }')"
# Catches: the error envelope drifting, which silently breaks every client
# that branches on extensions.code rather than on the status.
check_contains "and it says UNAUTHENTICATED" '"code":"UNAUTHENTICATED"' "$NO_CRED"
# Catches: the challenge header being dropped, which is what tells a
# standards-aware client that a bearer token is what is wanted.
check "and it challenges for a bearer token" "1" \
      "$(header_lines "$NO_CRED_HEADERS" '^www-authenticate: bearer realm="i-mscp"')"
# A well-formed token that no row matches. Catches: verify() accepting a token
# on its prefix alone without comparing the secret's hash.
check "a well-formed but unknown token is 401" "401" \
      "$(api_status 'imscp_aaaaaaaa_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' '{ apiVersion }')"
# Catches: GET being accepted, which is the one thing that makes a GraphQL
# endpoint drivable from a link or an <img> tag.
# shellcheck disable=SC2086
check "GET is refused" "405" \
      "$($CURL -o /dev/null -w '%{http_code}' "$BASE$ENDPOINT?query=%7BapiVersion%7D")"

# The schema route sits outside the TLS/CORS/authentication pipeline on
# purpose: the SDL is public API documentation, not data. Asserted as intended
# behaviour. Catches: the route being pulled behind authentication, which
# breaks every client-side codegen tool, or serving something other than the
# SDL.
# shellcheck disable=SC2086
check "the schema endpoint serves SDL unauthenticated" "200" \
      "$($CURL -o /dev/null -w '%{http_code}' "$BASE$SCHEMA_ENDPOINT")"
# shellcheck disable=SC2086
SCHEMA_HEADERS=$($CURL -D - -o /dev/null "$BASE$SCHEMA_ENDPOINT")
check "and serves it as text/plain" "1" \
      "$(header_lines "$SCHEMA_HEADERS" '^content-type: text/plain')"
# shellcheck disable=SC2086
check_contains "and the SDL is the schema" "type Query" "$($CURL "$BASE$SCHEMA_ENDPOINT")"

echo
echo "CORS:"
# allowed_origins is empty, so no browser origin is allowed. Catches:
# CorsMiddleware defaulting to permissive, or answering a preflight before it
# has checked the origin at all.
# shellcheck disable=SC2086
check "a preflight from an unlisted origin is refused" "403" \
      "$($CURL -o /dev/null -w '%{http_code}' -X OPTIONS "$BASE$ENDPOINT" \
          -H 'Origin: https://unlisted.example' -H 'Access-Control-Request-Method: POST')"
# Catches: the middleware reflecting whatever Origin it was sent, which would
# let any site read a token-authenticated response.
ORIGIN_HEADERS=$(api "$TOKEN" '{ apiVersion }' -D - -o /dev/null -H 'Origin: https://unlisted.example')
check "an unlisted origin is not reflected back" "0" \
      "$(header_lines "$ORIGIN_HEADERS" '^access-control-allow-origin:')"

echo
echo "Malformed input:"
# All five catch the same regression from different directions: a fault the
# client caused answering as anything but 400. A 500 here would mean the
# handler stopped distinguishing "you sent me nonsense" from "I broke", which
# is exactly the distinction the INTERNAL-is-500 change turns on.
check "an empty body is 400" "400" "$(post_raw_status "$TOKEN" '')"
check "a body that is not JSON is 400" "400" "$(post_raw_status "$TOKEN" 'not json at all')"
check "a JSON body with no query is 400" "400" "$(post_raw_status "$TOKEN" '{"variables":{}}')"
check "a syntax error is 400" "400" "$(api_status "$TOKEN" '{ viewer { ')"
check "a validation failure is 400" "400" "$(api_status "$TOKEN" '{ noSuchField }')"

echo
echo "Query cost:"
# A document nested well past any sane max_query_depth, built out of the
# introspection type graph because the phase-1 schema is too shallow to nest.
NEST=
UNNEST=
i=0
while [ "$i" -lt 24 ]; do
    NEST="$NEST ofType {"
    UNNEST="$UNNEST }"
    i=$((i + 1))
done
DEEP="{ __schema { types { fields { type {$NEST name$UNNEST } } } } }"
DEEP_BODY=$(api "$TOKEN" "$DEEP")
# Catches: QueryDepth never being registered, or being registered against a
# limit that never fires — both of which answer 200. The message is asserted
# as well as the status, because a 400 alone would also be produced by a
# malformed generated document, and that would pass for the wrong reason.
#
# extensions.code is asserted too, and is the check that actually matters:
# task-17's review found that the handler derived extensions.code from the
# error's previous exception and QueryDepth/QueryComplexity raise a plain
# Error with none, so every breach fell through to BAD_USER_INPUT while this
# script kept passing on the message text alone. QUERY_TOO_COMPLEX is part of
# the documented compatibility contract (docs/API.md, SPECIFICATION.md §9 and
# §18); a client branching on it must actually be able to see it fire. The
# message check is kept alongside it — it is what pins this down to the
# depth rule specifically, since the code alone would also be produced by a
# complexity breach (this schema excludes __schema from complexity scoring,
# so this particular document cannot trip that rule instead, but the message
# still documents which one is under test).
check "a query past the depth limit is 400" "400" "$(api_status "$TOKEN" "$DEEP")"
check_contains "and it is the depth rule that refused it" \
      "Max query depth should be" "$DEEP_BODY"
check_contains "and extensions.code is QUERY_TOO_COMPLEX, not BAD_USER_INPUT" \
      '"code":"QUERY_TOO_COMPLEX"' "$DEEP_BODY"

echo
echo "Scopes:"
ISSUED=$(mint scoped '["MAIL_READ"]' 1 'null') || die "could not mint the scoped token"
SCOPED=${ISSUED#* }
SCOPED_BODY=$(api "$SCOPED" '{ viewer { scopes } }')
# Catches: scopes not surviving the round trip through api_token.scopes onto
# the Identity, which would silently hand a narrowed credential the full set.
check_contains "a scoped token reports its scopes" '"scopes":["MAIL_READ"]' "$SCOPED_BODY"
# Catches: an empty scopes column parsing as [''] or, worse, being treated as
# "no restriction recorded, therefore all of them".
check_contains "an unscoped token reports no scopes" '"scopes":[]' "$BODY"

echo
echo "Credential lifecycle:"
# Revoked, expired and IP-restricted are each driven by writing the column
# directly rather than through TokenService, so that what is under test is
# verify() honouring the column, not the writer that set it.
ISSUED=$(mint revoked '[]' 1 'null') || die "could not mint the token to revoke"
REVOKED=${ISSUED#* }
sql "UPDATE api_token SET revoked_at = UNIX_TIMESTAMP() WHERE token_id = ${ISSUED%% *};" >/dev/null \
    || die "could not set up the revoked-token state"
# Catches: verify() ignoring revoked_at, which would make revocation cosmetic.
check "a revoked token is 401" "401" "$(api_status "$REVOKED" '{ apiVersion }')"

ISSUED=$(mint expired '[]' 1 'null') || die "could not mint the token to expire"
EXPIRED=${ISSUED#* }
sql "UPDATE api_token SET expires_at = UNIX_TIMESTAMP() - 60 WHERE token_id = ${ISSUED%% *};" >/dev/null \
    || die "could not set up the expired-token state"
# Catches: verify() ignoring expires_at, which would make every TTL infinite.
check "an expired token is 401" "401" "$(api_status "$EXPIRED" '{ apiVersion }')"

# 192.0.2.0/24 is the reserved documentation range, so it can never be the
# address this script is calling from.
ISSUED=$(mint restricted '[]' 1 "'192.0.2.1'") || die "could not mint the restricted token"
RESTRICTED=${ISSUED#* }
# Catches: the IP allow-list not being enforced, or failing open when the
# stored value does not parse.
check "a token restricted to another address is 401" "401" \
      "$(api_status "$RESTRICTED" '{ apiVersion }')"

echo
echo "API access withdrawal:"
# Recorded before the row is touched, so a kill between here and the
# restoring DELETE below leaves a durable record of what to put back — see
# MARKER_FILE's own comment for why the EXIT trap cannot be relied on for
# this.
printf '%s\n%s\n' "$ADMIN_ID" "$PERM_INITIAL" > "$MARKER_FILE"
sql "INSERT INTO api_perm (admin_id, allowed) VALUES ($ADMIN_ID, 0) ON DUPLICATE KEY UPDATE allowed = 0;" >/dev/null \
    || die "could not set up the withdrawn-access state"
WITHDRAWN=$(api "$TOKEN" '{ apiVersion }')
# Catches: the access checker failing open — granting access because a
# dependency was not wired, or because the query threw. api_perm is empty in
# production, so this branch is otherwise never exercised on a live box.
check "a withdrawn account is 403" "403" "$(api_status "$TOKEN" '{ apiVersion }')"
check_contains "and it says API_ACCESS_WITHDRAWN" '"code":"API_ACCESS_WITHDRAWN"' "$WITHDRAWN"
sql "DELETE FROM api_perm WHERE admin_id = $ADMIN_ID;" >/dev/null \
    || die "could not restore api_perm after the withdrawal check"
# Catches: a withdrawal that cannot be undone because the decision is cached
# across requests. It is also the control for the two checks above: without
# it, they would pass just as happily if the account were denied for some
# reason that has nothing to do with api_perm.
check "and restoring the row restores access" "200" "$(api_status "$TOKEN" '{ apiVersion }')"

echo
echo "TLS:"
# nginx 302s plaintext to HTTPS before PHP runs, so TlsMiddleware never sees
# the request and its revoke-a-token-presented-in-the-clear behaviour cannot
# be driven from out here at all. It is covered by the unit suite instead.
# What is worth asserting from the outside is that the redirect is there.
# Deliberately sent with no credential: a request that did reach PHP would
# have its token revoked, and a smoke test must not be able to destroy one.
#
# Catches: the panel vhost being reconfigured to serve the API over plaintext,
# which would put every bearer token on the wire in the clear.
PLAIN=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' \
    --max-time 30 --resolve "$VHOST:$HTTP_PORT:$HOST" \
    -X POST "http://$VHOST:$HTTP_PORT$ENDPOINT" \
    -H 'Content-Type: application/json' --data-binary '{"query":"{ apiVersion }"}')
check "plain HTTP is redirected to HTTPS" "302 $BASE$ENDPOINT" "$PLAIN"

cleanup

echo
printf '%s passed, %s failed\n' "$PASSED" "$FAILED"
[ "$FAILED" -eq 0 ] && echo "PASS" || echo "FAIL"
[ "$FAILED" -eq 0 ] || exit 1
exit 0
