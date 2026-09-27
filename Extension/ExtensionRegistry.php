<?php
namespace iMSCP\Plugin\SGW_GraphQL\Extension;

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

use InvalidArgumentException;

/**
 * The extensions other plugins have registered for this request.
 *
 * Filled by dispatching EVENT through the panel's event manager, with this
 * registry as the 'registry' parameter. Every enabled plugin's register() has
 * run by then - Application::loadPlugins() loads them all before routing - so
 * a plugin only has to listen:
 *
 *   public function register(EventManagerInterface $events)
 *   {
 *       $events->registerListener('onGraphQLRegisterExtensions', $this);
 *   }
 *
 *   public function onGraphQLRegisterExtensions(Event $event)
 *   {
 *       $event->getParam('registry')->register(new MyExtension());
 *   }
 *
 * The listener names the event as a string literal rather than as EVENT, so
 * that a plugin whose GraphQL support is optional loads no class of this one
 * unless this one is installed and actually dispatches.
 */
final class ExtensionRegistry
{
    /** The event name. A literal a listener can copy; see the class docblock. */
    const EVENT = 'onGraphQLRegisterExtensions';

    /** @var array<string, Extension> name => extension, in registration order */
    private $extensions = array();

    /**
     * @throws InvalidArgumentException A malformed or duplicate name. Thrown
     *                                  into the registering plugin's listener,
     *                                  which is where the mistake is.
     */
    public function register(Extension $extension): void
    {
        $name = $extension->getName();

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(sprintf(
                'A GraphQL extension name must be letters, digits and underscores, '
                    . 'starting with a letter; "%s" is not.',
                $name
            ));
        }

        if (isset($this->extensions[$name])) {
            throw new InvalidArgumentException(sprintf(
                'A GraphQL extension named "%s" is already registered.', $name
            ));
        }

        $this->extensions[$name] = $extension;
    }

    /**
     * @return array<string, Extension> name => extension, in registration order
     */
    public function all(): array
    {
        return $this->extensions;
    }
}
