<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;


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

use iMSCP\Event\Event;
use iMSCP\Event\EventAggregator;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionRegistry;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeExtension;
use iMSCP\Registry;

/**
 * The production path: Container::fromPlugin() asks the panel's own event
 * manager, and a listener registered the way another plugin's register()
 * registers one is what answers.
 */
class ExtensionEventTest extends IntegrationTestCase
{
    /**
     * The listener stays registered for the rest of the process - the event
     * manager offers no clean way to take one closure back - so it only
     * answers while a test here says so.
     *
     * @var bool
     */
    private static $listening = false;

    /** @var bool */
    private static $registered = false;

    protected function setUp(): void
    {
        $plugins = Registry::get('pluginManager');

        if (!$plugins->pluginIsKnown('SGW_GraphQL') || $plugins->pluginGetStatus('SGW_GraphQL') !== 'enabled') {
            self::markTestSkipped('SGW_GraphQL is not enabled on this panel');
        }

        if (!self::$registered) {
            // A string, as the docs tell another plugin to write it.
            EventAggregator::getInstance()->registerListener(
                'onGraphQLRegisterExtensions',
                static function (Event $event) {
                    if (self::$listening) {
                        $event->getParam('registry')->register(new FakeExtension(
                            'FromAnotherPlugin',
                            'extend type Query { anotherPlugin: String }',
                            array('Query.anotherPlugin' => static function () { return 'here'; })
                        ));
                    }
                }
            );
            self::$registered = true;
        }

        self::$listening = true;
    }

    protected function tearDown(): void
    {
        self::$listening = false;
    }

    private function container(): Container
    {
        return Container::fromPlugin(Registry::get('pluginManager')->pluginGet('SGW_GraphQL'));
    }

    public function testAListenerOnThePanelsEventManagerRegistersAnExtension(): void
    {
        $container = $this->container();

        self::assertSame(array('FromAnotherPlugin'), array_map(static function ($extension) {
            return $extension->getName();
        }, $container->extensions()));
        self::assertTrue($container->schemaFactory()->create()->getType('Query')->hasField('anotherPlugin'));
        self::assertStringContainsString('# Extension: FromAnotherPlugin', $container->schemaSdl());
    }

    public function testTheEventIsTheOneTheRegistryNames(): void
    {
        self::assertSame(ExtensionRegistry::EVENT, 'onGraphQLRegisterExtensions');
    }

    public function testWithNobodyListeningTheSchemaIsThisPluginsAlone(): void
    {
        self::$listening = false;

        self::assertSame(array(), $this->container()->extensions());
    }
}
