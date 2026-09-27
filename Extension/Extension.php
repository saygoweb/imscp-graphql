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

/**
 * What another i-MSCP plugin hands this one to add to the API.
 *
 * This plugin knows nothing about any other plugin. A plugin that wants to be
 * reachable over GraphQL opts in: it listens for ExtensionRegistry::EVENT and
 * registers an Extension, and everything it adds - SDL, resolvers, the rules
 * for who may touch what - is its own code. docs/EXTENSIONS.md is the guide.
 *
 * What an extension may declare is deliberately narrow, and ExtensionLoader
 * enforces it rather than trusting it:
 *
 *   - new object, input and enum types, none of which implements an interface
 *     (so none of them is a Node - the identifier space stays this plugin's);
 *   - `extend type X { ... }` on any object type, Query and Mutation included;
 *   - resolvers and complexity for the fields its own SDL declares, and no
 *     others - an extension cannot replace a field this plugin serves.
 *
 * An extension that breaks any of that, or whose SDL does not build against
 * the schema, is left out and the panel's log says why. The API stays up
 * without it: one plugin's defect is not every client's outage.
 */
interface Extension
{
    /**
     * Unique among registered extensions: letters, digits and underscores,
     * starting with a letter. The owning plugin's name is the natural choice.
     */
    public function getName(): string;

    /**
     * An SDL fragment, parsed together with schema/schema.graphql.
     */
    public function getSdl(): string;

    /**
     * 'Type.field' => fn($source, array $args, $context, ResolveInfo $info).
     *
     * Called once per request, after this plugin's own resolvers are built,
     * with the collaborators an extension needs to resolve safely. A field
     * with no entry here reads the key of the same name off its source, as
     * every other field in the schema does.
     *
     * @return array<string, callable>
     */
    public function getResolvers(ExtensionContext $context): array;

    /**
     * 'Type.field' => fn(int $childComplexity, array $args): int, for any list
     * field the extension declares. Spec section 10.2: a list is charged for
     * what it can return. Empty is fine for an extension with no lists.
     *
     * @return array<string, callable>
     */
    public function getComplexity(): array;
}
