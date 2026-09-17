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

use LogicException;

/**
 * The Db::detached() precedent, for DirectoryProbe.
 *
 * Container::forTesting()'s previous default - UncheckedDirectoryProbe -
 * silently answered "exists" for every path, where production asks the real
 * VFS. A test that cares what the probe answers must say so at the call
 * site; one that does not should never be able to pass by accident because
 * the default happened to agree.
 */
final class DetachedDirectoryProbe implements DirectoryProbe
{
    public function exists(string $customerUsername, string $root, string $path): bool
    {
        throw new LogicException(
            'This DirectoryProbe is detached: a test that runs a mutation '
                . 'must pass a DirectoryProbe to Container::forTesting().'
        );
    }
}
