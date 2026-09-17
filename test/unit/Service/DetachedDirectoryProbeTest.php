<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DetachedDirectoryProbe;
use LogicException;
use PHPUnit\Framework\TestCase;

class DetachedDirectoryProbeTest extends TestCase
{
    public function testExistsThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'This DirectoryProbe is detached: a test that runs a mutation '
                . 'must pass a DirectoryProbe to Container::forTesting().'
        );

        (new DetachedDirectoryProbe())->exists('customer', '/', 'shop');
    }
}
