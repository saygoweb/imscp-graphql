#!/bin/sh
# Run the test suite against both PHP 7.4 and PHP 8.3, and report the
# CORE-DEBT inventory.
#
# Runs either on the host or inside the box, and works out which:
#
#   host:   tools/test.sh [box]   pushes the tree over 'vagrant ssh' and then
#                                 re-enters itself in the box. This is necessary
#                                 because the host may not have both PHP versions
#                                 installed. Default box: imscp_debian_trixie.
#   in-box: tools/test.sh         runs the lint script under both PHP versions
#                                 and the PHPUnit suite if available.

set -e

PLUGIN=SGW_GraphQL
TEST_STAGE=/tmp/$PLUGIN.test
SRC=$(cd "$(dirname "$0")/.." && pwd)

# Everything needed to run tests: include test/, tools/ and vendor/
EXCLUDES="--exclude=.git --exclude=.ssh-config"

if [ -d /var/www/imscp ]; then
    # Running inside the box

    # Run the lint script
    cd "$SRC"
    sh test/lint/all.sh

    # Run PHPUnit if available. Invoked with an explicit php7.4, not via
    # vendor/bin/phpunit's own #!/usr/bin/env php shebang: the box's default
    # 'php' is 7.3, which fails composer's platform check for dependencies
    # resolved against 7.4.33.
    if [ -f vendor/bin/phpunit ] && [ -f test/phpunit.xml ]; then
        php7.4 vendor/bin/phpunit --configuration test/phpunit.xml
    else
        echo "Note: PHPUnit not yet configured; skipping unit tests"
    fi

    exit 0
fi

BOX=${1:-imscp_debian_trixie}
VAGRANT_DIR=${IMSCP_VAGRANT_DIR:-$SRC/../imscp/Vagrant}

[ -d "$VAGRANT_DIR" ] || {
    echo "$0: no Vagrant directory at $VAGRANT_DIR." >&2
    echo "Set IMSCP_VAGRANT_DIR to the i-MSCP repository's Vagrant directory." >&2
    exit 1
}

# One ssh config for both the push and the tests, so 'vagrant ssh' is only
# asked for its connection details once.
SSH_CONFIG=$SRC/.ssh-config
(cd "$VAGRANT_DIR" && vagrant ssh-config "$BOX") > "$SSH_CONFIG" || {
    echo "$0: could not read the ssh config for $BOX; is the box running?" >&2
    exit 1
}

# shellcheck disable=SC2086
rsync -a --delete $EXCLUDES \
    -e "ssh -F $SSH_CONFIG" "$SRC/" "$BOX:$TEST_STAGE/"

# The staged copy has the test suite ready to run, so it tests itself.
ssh -F "$SSH_CONFIG" "$BOX" "cd $TEST_STAGE && sh tools/test.sh"
