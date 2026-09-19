#!/bin/sh
# Lint every plugin source file under both PHP 7.4 and PHP 8.3, and report the
# CORE-DEBT inventory.
#
# The dual-version rule is what keeps the panel's later 8.3 migration free for
# this plugin: a construct removed in PHP 8 fails here on the day it is
# written, rather than during the migration.
#
# Run from the plugin root, inside the box:  sh test/lint/all.sh
set -e

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FAILED=0

# Every scan below walks the tree, so every scan needs the same exclusions in
# the same place - the CORE-DEBT inventory once counted each marker twice
# because one scan had them and another did not.
#
# vendor and .git are obvious. .claude and worktrees are not: a git worktree
# created inside the checkout is a complete second copy of the source, so a
# scan that walks into one lints every file twice, counts every CORE-DEBT
# marker twice, and reports a file count nobody can reconcile. Both are also
# in .gitignore, which is what stops them being committed; this is what stops
# them being measured.
php_files() {
    find "$ROOT" -name '*.php' \
        -not -path '*/vendor/*' \
        -not -path '*/.git/*' \
        -not -path '*/.claude/*' \
        -not -path '*/worktrees/*'
}

# grep's own form of the same list, for the scans that read content rather
# than walk paths.
GREP_EXCLUDES="--exclude-dir=vendor --exclude-dir=.git --exclude-dir=.claude --exclude-dir=worktrees"

lint_one() {
    php=$1
    command -v "$php" >/dev/null 2>&1 || {
        echo "  SKIP  $php not installed"; return
    }
    n=0; bad=0
    for f in $(php_files); do
        n=$((n + 1))
        if ! "$php" -l "$f" >/dev/null 2>&1; then
            bad=$((bad + 1)); FAILED=1
            echo "  FAIL  $php $(echo "$f" | sed "s|$ROOT/||")"
            "$php" -l "$f" 2>&1 | head -1 | sed 's/^/          /'
        fi
    done
    printf "  %-8s %s files, %s failures\n" "$php" "$n" "$bad"
}

echo "Syntax:"
lint_one php7.4
lint_one php8.3

# Every CORE-DEBT marker must name an item that exists in the specification, so
# that the debt inventory cannot drift from the backlog it points at.
echo
echo "CORE-DEBT inventory:"
markers=$(grep -rhoE 'CORE-DEBT\(C[0-9]+\)' "$ROOT" --include='*.php' $GREP_EXCLUDES 2>/dev/null | sort | uniq -c || true)
if [ -z "$markers" ]; then
    echo "  none"
else
    echo "$markers" | sed 's/^/  /'
    for item in $(grep -rhoE 'CORE-DEBT\(C[0-9]+\)' "$ROOT" --include='*.php' $GREP_EXCLUDES 2>/dev/null \
                  | sed 's/CORE-DEBT(\(C[0-9]*\))/\1/' | sort -u); do
        if ! grep -q "^\*\*$item — " "$ROOT/docs/SPECIFICATION.md" 2>/dev/null; then
            echo "  FAIL  $item is not an item in docs/SPECIFICATION.md section 21"
            FAILED=1
        fi
    done
fi

# Every source file carries the project's licence header. This is checked
# mechanically because the failure mode is contagious: a fresh pair of hands
# copies the header from whichever file it happened to open, so one wrong
# character propagates through every file written after it. Matching the
# address line alone rather than the whole block keeps the check robust
# against legitimate whitespace variation, which would otherwise produce
# failures nobody trusts.
echo
echo "Licence headers:"
address=' * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.'
missing=0
for f in $(php_files); do
    if ! grep -qF "$address" "$f"; then
        echo "  FAIL  $(echo "$f" | sed "s|$ROOT/||") has no correct licence header"
        missing=$((missing + 1))
        FAILED=1
    fi
done
[ "$missing" -eq 0 ] && echo "  all files carry the correct header"

# Decision D28: the GraphiQL assets are vendored into themes/default/assets/,
# not fetched from a CDN, because the panel is frequently run on a server with
# no outbound access and must not make requests to third parties. This cannot
# catch a reference buried inside the vendored JS/CSS itself - those are
# checked once, by hand, when they are vendored - but it catches the mistake
# this plugin's own code could make: a page markup file pointed at a CDN
# instead of the vendored copy.
#
# -P, not -E: GNU grep's -E does not support the (?!...) negative lookahead
# this needs - it warns ("? at start of expression") and silently matches
# nothing, which would make this check pass vacuously on every offending line.
# Confirmed against this box's grep (GNU grep 3.8) before relying on it.
echo
echo "External origins under themes/:"
offenders=$(grep -rInP 'https?://(?!localhost)' "$ROOT/themes" \
    --include='*.php' --include='*.tpl' --include='*.html' $GREP_EXCLUDES 2>/dev/null \
    | grep -v 'w3.org' || true)
if [ -n "$offenders" ]; then
    echo "$offenders" | sed "s|$ROOT/||"
    echo "  FAIL  themes/ must not reference an external origin (decision D28)"
    FAILED=1
else
    echo "  none"
fi

echo
[ "$FAILED" -eq 0 ] && echo "PASS" || echo "FAIL"
exit "$FAILED"
