#!/bin/sh
# Stage the working copy into the i-MSCP panel's plugins directory.
#
# Runs either on the host or inside the box, and works out which:
#
#   host:   tools/deploy.sh [box]   pushes the tree over 'vagrant ssh' and then
#                                   re-enters itself in the box. No synced
#                                   folder is needed, so this repository does
#                                   not have to appear in the i-MSCP
#                                   Vagrantfile. Default box: imscp_debian_trixie.
#   in-box: sudo tools/deploy.sh    installs from the tree it is run from, the
#                                   way the sibling plugins do.
#
# The panel runs as vu2000, and a virtiofs share would carry the host uid, so
# the tree is copied into place rather than mounted there.

set -e

PLUGIN=SGW_GraphQL
DEST=/var/www/imscp/gui/plugins/$PLUGIN
STAGE=/tmp/$PLUGIN.stage
SRC=$(cd "$(dirname "$0")/.." && pwd)

# Everything the panel does not need. Kept in step with upload-exclude.txt,
# which does the same job for the release archive. tools/ is excluded here but
# not from the push below, because the push needs this script on the far side.
EXCLUDES="--exclude=.git --exclude=.github --exclude=.ssh-config
--exclude=docs --exclude=test --exclude=tools --exclude=*.tgz"

if [ -d /var/www/imscp/gui/plugins ]; then
    [ "$(id -u)" -eq 0 ] || { echo "$0: must be run as root" >&2; exit 1; }

    # shellcheck disable=SC2086
    rsync -a --delete $EXCLUDES "$SRC/" "$DEST/"

    chown -R vu2000:vu2000 "$DEST"
    find "$DEST" -type d -exec chmod 0750 {} +
    find "$DEST" -type f -exec chmod 0640 {} +

    # The panel's PHP-FPM keeps opcached bytecode, so a redeployed file is
    # otherwise ignored until the pool recycles.
    systemctl restart imscp_panel

    echo "Deployed $SRC -> $DEST"
    echo "Now update the plugin list in the panel: System tools / Plugin management."
    exit 0
fi

BOX=${1:-imscp_debian_trixie}
VAGRANT_DIR=${IMSCP_VAGRANT_DIR:-$SRC/../imscp/Vagrant}

[ -d "$VAGRANT_DIR" ] || {
    echo "$0: no Vagrant directory at $VAGRANT_DIR." >&2
    echo "Set IMSCP_VAGRANT_DIR to the i-MSCP repository's Vagrant directory." >&2
    exit 1
}

# One ssh config for both the push and the install, so 'vagrant ssh' is only
# asked for its connection details once.
SSH_CONFIG=$SRC/.ssh-config
(cd "$VAGRANT_DIR" && vagrant ssh-config "$BOX") > "$SSH_CONFIG" || {
    echo "$0: could not read the ssh config for $BOX; is the box running?" >&2
    exit 1
}

rsync -a --delete --exclude=.git --exclude=.ssh-config --exclude='*.tgz' \
    -e "ssh -F $SSH_CONFIG" "$SRC/" "$BOX:$STAGE/"

# The staged copy is a working tree like any other, so it installs itself.
ssh -F "$SSH_CONFIG" "$BOX" "sudo $STAGE/tools/deploy.sh"
