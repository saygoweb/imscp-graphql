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

use GraphQL\Language\AST\DocumentNode;

/**
 * An extension that ExtensionLoader has read and checked: everything the
 * extension returned, asked for exactly once.
 */
final class LoadedExtension
{
    /** @var string */
    private $name;

    /** @var string */
    private $sdl;

    /** @var DocumentNode */
    private $document;

    /** @var array<string, callable> */
    private $resolvers;

    /** @var array<string, callable> */
    private $complexity;

    /**
     * @param array<string, callable> $resolvers
     * @param array<string, callable> $complexity
     */
    public function __construct(
        string $name, string $sdl, DocumentNode $document, array $resolvers, array $complexity
    ) {
        $this->name = $name;
        $this->sdl = $sdl;
        $this->document = $document;
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

    public function getDocument(): DocumentNode
    {
        return $this->document;
    }

    /** @return array<string, callable> */
    public function getResolvers(): array
    {
        return $this->resolvers;
    }

    /** @return array<string, callable> */
    public function getComplexity(): array
    {
        return $this->complexity;
    }
}
