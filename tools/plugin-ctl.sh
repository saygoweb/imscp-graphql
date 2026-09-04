#!/bin/sh
# Development-only wrapper that drives i-MSCP's plugin manager from the
# command line, since there is no browser here to click through
# System tools / Plugin management.
#
# Usage:
#   tools/plugin-ctl.sh sync
#   tools/plugin-ctl.sh install <PluginName>
#   tools/plugin-ctl.sh uninstall <PluginName>
#   tools/plugin-ctl.sh delete <PluginName>
#   tools/plugin-ctl.sh status <PluginName>
#
# Each call opens the panel's own bootstrap (library/imscp-lib.php) inside the
# box and calls the same iMSCP\Plugin\PluginManager methods the panel's own
# "Plugin management" page calls, so a plugin ends up in exactly the state
# the UI would have left it in.
#
# Runs as vu2000, the panel's own user: it is the only account allowed to
# read /etc/imscp/imscp.conf and write the panel's cache and persistent data
# directories, both of which the plugin manager touches. It runs under
# php7.4 (the panel's own PHP-FPM binary has no CLI script mode; the
# system php7.4 is the same PHP 7.4.33 the panel runs under).
set -e

ACTION=${1:-}
NAME=${2:-}
BOX=${IMSCP_BOX:-imscp_debian_trixie}
SRC=$(cd "$(dirname "$0")/.." && pwd)
SSH_CONFIG=$SRC/.ssh-config

usage() {
    echo "Usage: $0 {sync|install|uninstall|delete|status} [PluginName]" >&2
    exit 1
}

case "$ACTION" in
    sync)
        ;;
    install|uninstall|delete|status)
        [ -n "$NAME" ] || usage
        ;;
    *)
        usage
        ;;
esac

[ -f "$SSH_CONFIG" ] || {
    echo "$0: no ssh config at $SSH_CONFIG." >&2
    echo "Regenerate it with:" >&2
    echo "  (cd ../imscp/Vagrant && vagrant ssh-config $BOX) > $SSH_CONFIG" >&2
    exit 1
}

ssh -F "$SSH_CONFIG" "$BOX" "sudo -u vu2000 php7.4 -- '$ACTION' '$NAME'" <<'PHP'
<?php
/**
 * i-MSCP SGW_GraphQL plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

require '/var/www/imscp/gui/library/imscp-lib.php';

/** @var iMSCP\Plugin\PluginManager $pm */
$pm = iMSCP\Registry::get('pluginManager');

$action = isset($argv[1]) ? $argv[1] : '';
$name   = isset($argv[2]) ? $argv[2] : '';

/**
 * Print $message plus a trailing error from the plugin manager, if any, then
 * exit non-zero.
 *
 * @param string $name
 * @param string $message
 * @return void
 */
function bail($name, $message)
{
    global $pm;
    fwrite(STDERR, $message . "\n");
    if ($name !== '' && $pm->pluginIsKnown($name) && $pm->pluginHasError($name)) {
        fwrite(STDERR, 'plugin error: ' . $pm->pluginGetError($name) . "\n");
    }
    exit(1);
}

try {
    switch ($action) {
        case 'sync':
            $pm->pluginSyncData();
            echo "synced\n";
            break;

        case 'status':
            if (!$pm->pluginIsKnown($name)) {
                bail($name, "$name: not known to the panel (run 'sync' first)");
            }
            echo $pm->pluginGetStatus($name), "\n";
            if ($pm->pluginHasError($name)) {
                fwrite(STDERR, 'plugin error: ' . $pm->pluginGetError($name) . "\n");
            }
            break;

        case 'install':
            if (!$pm->pluginIsKnown($name)) {
                bail($name, "$name: not known to the panel (run 'sync' first)");
            }
            $pm->pluginInstall($name);
            echo $pm->pluginGetStatus($name), "\n";
            break;

        case 'uninstall':
            if (!$pm->pluginIsKnown($name)) {
                bail($name, "$name: not known to the panel (run 'sync' first)");
            }
            // pluginUninstall() only accepts a plugin that is already
            // 'disabled' or 'touninstall'; an enabled plugin has to be
            // disabled first, exactly as the UI's own uninstall link does.
            if ($pm->pluginGetStatus($name) === 'enabled') {
                $pm->pluginDisable($name);
            }
            $pm->pluginUninstall($name);
            echo $pm->pluginGetStatus($name), "\n";
            break;

        case 'delete':
            if (!$pm->pluginIsKnown($name)) {
                bail($name, "$name: not known to the panel (run 'sync' first)");
            }
            $status = $pm->pluginGetStatus($name);
            if ($status === 'enabled') {
                $pm->pluginDisable($name);
                $pm->pluginUninstall($name);
            } elseif ($status === 'disabled') {
                $pm->pluginUninstall($name);
            }
            $pm->pluginDelete($name);
            echo $pm->pluginGetStatus($name), "\n";
            break;

        default:
            fwrite(STDERR, "unknown action '$action'\n");
            exit(1);
    }
} catch (Exception $e) {
    bail($name, get_class($e) . ': ' . $e->getMessage());
} catch (Throwable $e) {
    bail($name, get_class($e) . ': ' . $e->getMessage());
}
PHP
