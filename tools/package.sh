#!/bin/sh
# Build the release archive the panel installs: SGW_GraphQL.tgz, containing
# the plugin directory named as the panel expects it.
#
# It refuses to build from a tree that is not releasable, because an archive
# is the one artifact nobody re-reads before installing it:
#
#   - a dirty working tree, so that what ships is what the commit says;
#   - a failing test suite, unless --skip-tests is given deliberately;
#   - a stale printed schema, since schema/schema.printed.graphql ships and a
#     client generator builds against it;
#   - a staged tree carrying anything but the plugin, checked after staging
#     rather than trusted to upload-exclude.txt, because the clean-tree check
#     above is blind to gitignored paths.
#
# vendor/ is not committed (docs/DEVELOPMENT.md), so it must be installed
# before packaging - with --no-dev, resolved against the panel's PHP. The
# archive is built from the working tree rather than from `git archive`
# precisely because vendor/ is not in git.
#
#   sh tools/package.sh              # from the plugin root, inside the box
#   sh tools/package.sh --skip-tests # when the suite has just been run
set -e

ROOT=$(cd "$(dirname "$0")/.." && pwd)
NAME=SGW_GraphQL
SKIP_TESTS=0

for arg in "$@"; do
    case "$arg" in
        --skip-tests) SKIP_TESTS=1 ;;
        *) echo "package.sh: unknown option $arg" >&2; exit 2 ;;
    esac
done

cd "$ROOT"

fail() {
    echo "package.sh: $1" >&2
    exit 1
}

# 1. A clean tree. An archive built from uncommitted work cannot be rebuilt
#    from the tag it claims to be.
#
#    "Could not tell" is not "clean". Inside the container git refuses a
#    bind-mounted checkout it does not own ("dubious ownership"), and an
#    earlier version of this script sent that error to /dev/null and read the
#    empty output as a clean tree - the one failure this check exists to
#    prevent, passing silently.
#    The assignment is inside `if !` so that `set -e` does not kill the script
#    before the failure can be reported.
if ! status=$(git status --porcelain 2>&1); then
    echo "$status" >&2
    fail "git could not report the tree's state, so it cannot be shown clean.
       Inside the container this is usually ownership:
         git config --global --add safe.directory $ROOT"
fi

if [ -n "$status" ]; then
    echo "$status" >&2
    fail "the working tree is dirty; commit or stash before packaging"
fi

# 2. vendor/, without dev dependencies.
#
# The checkout's own vendor/ has PHPUnit in it, because that is how the suite
# runs, and PHPUnit has no business in a release archive. Rather than making a
# developer tear down their own vendor/ to package - and put it back to run a
# test - the archive gets its own, installed --no-dev into the staging copy
# below. The checkout is never touched.
[ -d "$ROOT/vendor" ] || fail "vendor/ is missing; run composer install first (docs/DEVELOPMENT.md)"

COMPOSER=/var/www/imscp/gui/bin/composer.phar
[ -f "$COMPOSER" ] || fail "no composer at $COMPOSER; package from inside the box (docs/DEVELOPMENT.md)"

# 3. The printed schema ships, so it must match the SDL.
if ! php7.4 "$ROOT/tools/export-schema.php" --check >/dev/null 2>&1; then
    fail "schema/schema.printed.graphql is stale; run 'composer schema' and commit the result"
fi

# 4. The suite.
if [ "$SKIP_TESTS" -eq 0 ]; then
    echo "Tests:"
    sh "$ROOT/tools/test.sh" >/dev/null 2>&1 || fail "the test suite is not green; not packaging"
    echo "  green"
fi

VERSION=$(php7.4 -r '$i = require "'"$ROOT"'/info.php"; echo $i["version"];')
[ -n "$VERSION" ] || fail "could not read the version from info.php"

# The panel unpacks the archive and expects one directory named for the
# plugin, so build it under that name whatever this checkout is called.
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir "$STAGE/$NAME"

# Before the tar that reads the tree, not after it. Removing it afterwards
# meant every build copied its own predecessor into the staging tree first,
# so each release embedded the one before it and the archive grew by its own
# size every time. upload-exclude.txt names it too; this is the belt to that
# brace, and it is the half that works when the file is renamed.
rm -f "$ROOT/$NAME.tgz"

tar -cf - --exclude-from="$ROOT/upload-exclude.txt" --exclude='./vendor' . \
    | (cd "$STAGE/$NAME" && tar -xf -)

# The staging copy's own vendor/, with no dev dependencies in it. --no-scripts
# because composer.json's scripts are development conveniences (the schema
# export among them) and none of them should run while packaging.
echo "Dependencies:"
(cd "$STAGE/$NAME" && php7.4 "$COMPOSER" install --no-dev --no-scripts --no-interaction --quiet) \
    || fail "composer install --no-dev failed in the staging copy"

[ -d "$STAGE/$NAME/vendor/webonyx/graphql-php" ] \
    || fail "the staged vendor/ has no webonyx/graphql-php; the archive would not run"
[ -d "$STAGE/$NAME/vendor/phpunit" ] \
    && fail "the staged vendor/ still has dev dependencies; composer ignored --no-dev"

echo "  installed, no dev dependencies"

# 5. Nothing but the plugin.
#
# upload-exclude.txt is a list someone has to remember to update, and the
# clean-tree check above cannot cover for it: `git status --porcelain` never
# reports ignored paths, so the agent scratch directories and the previous
# archive - all of them gitignored - are invisible to it and shipped
# silently. A whole second copy of the plugin, and the previous release,
# went out that way. This is the check that notices when the list was not
# updated, and it runs last, over the staged tree with its vendor/ in place -
# what actually ships, rather than what the exclude list was meant to leave
# out.
STRAYS=$(find "$STAGE/$NAME" \
    \( -name '.claude' -o -name '.serena' -o -name '.superpowers' \
       -o -name 'worktrees' -o -name '*.tgz' \) -print)

if [ -n "$STRAYS" ]; then
    echo "$STRAYS" | sed "s|^$STAGE/|  |" >&2
    fail "the staged tree carries paths that must not ship (scratch directories
       or a previous archive). Add them to upload-exclude.txt."
fi

echo "Contents:"
echo "  the plugin and nothing else"

# upload-exclude.txt keeps tools/ out of the archive, but the panel's own
# plugin manager never reads it, so nothing above is needed at runtime.
(cd "$STAGE" && tar -czf "$ROOT/$NAME.tgz" "$NAME")

SIZE=$(du -h "$ROOT/$NAME.tgz" | cut -f1)
FILES=$(tar -tzf "$ROOT/$NAME.tgz" | wc -l)

echo
echo "Packaged:"
echo "  $NAME.tgz   version $VERSION, $FILES entries, $SIZE"
echo "  install it through the panel: System tools / Plugin management"
