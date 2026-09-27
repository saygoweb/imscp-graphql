<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Extension;


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

use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionRegistry;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeExtension;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ExtensionRegistryTest extends TestCase
{
    public function testExtensionsAreKeptInTheOrderTheyRegistered(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register(new FakeExtension('SGW_PhpVersion', ''));
        $registry->register(new FakeExtension('SGW_ApacheCache', ''));

        self::assertSame(array('SGW_PhpVersion', 'SGW_ApacheCache'), array_keys($registry->all()));
    }

    public function testASecondExtensionOfTheSameNameIsRefused(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register(new FakeExtension('SGW_PhpVersion', ''));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $registry->register(new FakeExtension('SGW_PhpVersion', ''));
    }

    /**
     * @dataProvider malformedNames
     */
    public function testAMalformedNameIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExtensionRegistry())->register(new FakeExtension($name, ''));
    }

    public function malformedNames(): array
    {
        return array(
            'empty'          => array(''),
            'leading digit'  => array('1Plugin'),
            'space'          => array('My Plugin'),
            'colon'          => array('Extension:Other')
        );
    }

    public function testTheEventNameIsTheLiteralTheDocsTellListenersToUse(): void
    {
        // A sibling plugin names the event as a string so that it loads no
        // class of this plugin's unless this plugin dispatches. Renaming the
        // constant silently disconnects every one of them.
        self::assertSame('onGraphQLRegisterExtensions', ExtensionRegistry::EVENT);
    }
}
