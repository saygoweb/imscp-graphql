<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;


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

use iMSCP\Plugin\SGW_GraphQL\Extension\Extension;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionContext;

/**
 * An extension built from its parts, standing in for another plugin's.
 *
 * $resolvers is a map, or fn(ExtensionContext): array for a test that needs
 * the context - which is how a real extension gets at it.
 */
final class FakeExtension implements Extension
{
    /** @var string */
    private $name;

    /** @var string */
    private $sdl;

    /** @var array<string, callable>|callable */
    private $resolvers;

    /** @var array<string, callable> */
    private $complexity;

    /** @var ExtensionContext|null The context getResolvers() was handed. */
    public $context;

    /**
     * @param array<string, callable>|callable $resolvers
     * @param array<string, callable>          $complexity
     */
    public function __construct(string $name, string $sdl, $resolvers = array(), array $complexity = array())
    {
        $this->name = $name;
        $this->sdl = $sdl;
        $this->resolvers = $resolvers;
        $this->complexity = $complexity;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSdl(): string
    {
        return $this->sdl;
    }

    public function getResolvers(ExtensionContext $context): array
    {
        $this->context = $context;

        return is_callable($this->resolvers)
            ? call_user_func($this->resolvers, $context)
            : $this->resolvers;
    }

    public function getComplexity(): array
    {
        return $this->complexity;
    }
}
