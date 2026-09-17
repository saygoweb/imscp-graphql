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

/**
 * Whether a directory exists inside a customer's web space.
 *
 * The panel asks through VirtualFileSystem, which creates a temporary FTP
 * account and logs in over FTP (spec section 3.2). That cannot run inside a
 * transaction - the FTP server must see the temporary row committed - so it
 * sits behind this port (decision D12), and validate_ftp_home_dir switches it
 * off (spec section 14, decision D20).
 */
interface DirectoryProbe
{
    /**
     * @param string $customerUsername admin.admin_name of the owning customer
     * @param string $root             The VFS root, relative to the customer's
     *                                 web space: '/' or '<mount>/htdocs'
     * @param string $path             The directory, relative to $root
     */
    public function exists(string $customerUsername, string $root, string $path): bool;
}
