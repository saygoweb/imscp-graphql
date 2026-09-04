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

lint_one() {
    php=$1
    command -v "$php" >/dev/null 2>&1 || {
        echo "  SKIP  $php not installed"; return
    }
    n=0; bad=0
    for f in $(find "$ROOT" -name '*.php' -not -path '*/vendor/*' -not -path '*/.git/*'); do
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
markers=$(grep -rhoE 'CORE-DEBT\(C[0-9]+\)' "$ROOT" --include='*.php' 2>/dev/null | sort | uniq -c || true)
if [ -z "$markers" ]; then
    echo "  none"
else
    echo "$markers" | sed 's/^/  /'
    for item in $(grep -rhoE 'CORE-DEBT\(C[0-9]+\)' "$ROOT" --include='*.php' 2>/dev/null \
                  | sed 's/CORE-DEBT(\(C[0-9]*\))/\1/' | sort -u); do
        if ! grep -q "^\*\*$item — " "$ROOT/docs/SPECIFICATION.md" 2>/dev/null; then
            echo "  FAIL  $item is not an item in docs/SPECIFICATION.md section 21"
            FAILED=1
        fi
    done
fi

echo
[ "$FAILED" -eq 0 ] && echo "PASS" || echo "FAIL"
exit "$FAILED"
