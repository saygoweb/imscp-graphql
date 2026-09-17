<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\VirtualFileSystem;

/**
 * The panel's own check. Exercised end to end by Task 17, not by the
 * integration suite: it needs a committed row and a running FTP server.
 *
 * A failure to connect is VirtualFileSystem's RuntimeException, left to
 * propagate: ErrorFactory turns it into INTERNAL with a correlation id, which
 * is the honest answer when ProFTPD is restarting (spec section 20, risk 5).
 */
final class VfsDirectoryProbe implements DirectoryProbe
{
    public function exists(string $customerUsername, string $root, string $path): bool
    {
        $vfs = new VirtualFileSystem($customerUsername, $root);

        return $vfs->exists($path, VirtualFileSystem::VFS_TYPE_DIR);
    }
}
