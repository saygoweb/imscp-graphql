#!/bin/sh
# Run the test suite against both PHP 7.4 and PHP 8.3, and report the
# CORE-DEBT inventory.
#
# Runs either on the server or on the host, and works out which:
#
#   server:  tools/test.sh [phpunit-args...]
#                                 runs the lint script under both PHP versions
#                                 and then the PHPUnit suite.
#   host:    tools/test.sh [phpunit-args...]
#                                 re-enters itself on the server, because the
#                                 host has neither PHP 7.4 nor the panel the
#                                 integration suite needs.
#
# Two kinds of server are supported, and docker is tried first:
#
#   docker   The container from '../imscp/docker/imscp'. This checkout is
#            already inside it — the directory holding the plugin checkouts is
#            bind mounted whole at /var/www/imscp-plugins — so the tests run
#            against the working tree directly. Nothing is copied, and there is
#            no staged second copy to wonder about.
#   vagrant  The boxes in '../imscp/Vagrant'. No synced folder is assumed, so
#            the tree is pushed over 'vagrant ssh' into a staging directory and
#            the staged copy tests itself.
#
# Force one or the other with --docker or --vagrant [box]. Set IMSCP_DIR if the
# i-MSCP repository is not at ../imscp.

set -e

PLUGIN=SGW_GraphQL
TEST_STAGE=/tmp/$PLUGIN.test
SRC=$(cd "$(dirname "$0")/.." && pwd)
IMSCP_DIR=${IMSCP_DIR:-$SRC/../imscp}

if [ -d /var/www/imscp ]; then
    # Running on the server, wherever it came from.

    cd "$SRC"
    sh test/lint/all.sh

    # PHPUnit is invoked with an explicit php7.4, not through
    # vendor/bin/phpunit's own '#!/usr/bin/env php' shebang: the server's
    # default 'php' may be 7.3, which fails composer's platform check for
    # dependencies resolved against 7.4.33.
    if [ -f vendor/bin/phpunit ] && [ -f test/phpunit.xml ]; then
        php7.4 vendor/bin/phpunit --configuration test/phpunit.xml "$@"
    else
        echo "Note: PHPUnit not yet configured; skipping unit tests"
    fi

    exit 0
fi

# On the host. Pick a server.
MODE=auto
BOX=
case "${1:-}" in
    --docker)  MODE=docker;  shift ;;
    --vagrant) MODE=vagrant; shift
               case "${1:-}" in -*) ;; ?*) BOX=$1; shift ;; esac ;;
esac

# The container names itself after COMPOSE_PROJECT_NAME in docker/.env, which
# 'docker/imscp init' writes. Read it from there rather than guessing.
docker_ready() {
    [ -x "$IMSCP_DIR/docker/imscp" ] || return 1
    command -v docker >/dev/null 2>&1 || return 1
    name=$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$IMSCP_DIR/docker/.env" 2>/dev/null | head -n 1)
    [ -n "$name" ] || return 1
    [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null)" = true ]
}

if [ "$MODE" = auto ]; then
    if docker_ready; then MODE=docker; else MODE=vagrant; fi
fi

if [ "$MODE" = docker ]; then
    docker_ready || {
        echo "$0: no running i-MSCP container for $IMSCP_DIR." >&2
        echo "Start one with: (cd $IMSCP_DIR && docker/imscp up)" >&2
        exit 1
    }

    # Where this checkout appears inside the container. The plugins root is
    # mounted whole at /var/www/imscp-plugins, so the path is this checkout's
    # position within that root — which is what makes the tests run against
    # the working tree rather than a copy of it.
    PLUGINS_ROOT=${IMSCP_PLUGINS_ROOT:-$(cd "$IMSCP_DIR/.." && pwd)}
    case "$SRC/" in
        "$PLUGINS_ROOT"/*) ;;
        *) echo "$0: $SRC is not under the plugins root $PLUGINS_ROOT," >&2
           echo "so the container cannot see it. Set IMSCP_PLUGINS_ROOT in" >&2
           echo "$IMSCP_DIR/docker/.env and re-run 'docker/imscp up'." >&2
           exit 1 ;;
    esac
    IN_CONTAINER=/var/www/imscp-plugins/${SRC#"$PLUGINS_ROOT"/}

    quoted=
    for arg in "$@"; do
        quoted="$quoted '$(printf '%s' "$arg" | sed "s/'/'\\\\''/g")'"
    done

    exec "$IMSCP_DIR/docker/imscp" exec sh -c \
        "cd '$IN_CONTAINER' && sh tools/test.sh$quoted"
fi

BOX=${BOX:-imscp_debian_trixie}
VAGRANT_DIR=${IMSCP_VAGRANT_DIR:-$IMSCP_DIR/Vagrant}

[ -d "$VAGRANT_DIR" ] || {
    echo "$0: no i-MSCP server to test on." >&2
    echo "Either start the docker container — (cd $IMSCP_DIR && docker/imscp up) —" >&2
    echo "or set IMSCP_VAGRANT_DIR to the i-MSCP repository's Vagrant directory." >&2
    exit 1
}

# One ssh config for both the push and the tests, so 'vagrant ssh' is only
# asked for its connection details once.
SSH_CONFIG=$SRC/.ssh-config
(cd "$VAGRANT_DIR" && vagrant ssh-config "$BOX") > "$SSH_CONFIG" || {
    echo "$0: could not read the ssh config for $BOX; is the box running?" >&2
    exit 1
}

# Everything needed to run tests: include test/, tools/ and vendor/
# shellcheck disable=SC2086
rsync -a --delete --exclude=.git --exclude=.ssh-config \
    -e "ssh -F $SSH_CONFIG" "$SRC/" "$BOX:$TEST_STAGE/"

# The staged copy has the test suite ready to run, so it tests itself.
ssh -F "$SSH_CONFIG" "$BOX" "cd $TEST_STAGE && sh tools/test.sh $*"
