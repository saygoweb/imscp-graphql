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
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\Parser;
use LogicException;
use Throwable;

/**
 * Decides which registered extensions this request's schema carries.
 *
 * An extension is left out, and the panel's log says why, when:
 *
 *   - it throws from any of its own methods;
 *   - its SDL does not parse, or declares anything Extension's docblock does
 *     not allow;
 *   - it names a resolver or a complexity for a field its own SDL does not
 *     declare, or one somebody else already serves;
 *   - the schema will not build with it - an `extend type` of a type that
 *     does not exist, a field another extension also adds, an unknown type.
 *
 * Everything else is kept. The API always comes up: at worst with this
 * plugin's own schema and none of the extensions.
 *
 * Building the schema is the only complete check, and it is not cheap, so
 * the common case is one build of everything. Only when that fails are the
 * extensions added one at a time, in registration order, to find which to
 * leave out - so an extension that clashes with an earlier one is the one
 * dropped, whichever of the two is at fault.
 */
final class ExtensionLoader
{
    /** @var callable fn(string $message): void */
    private $log;

    /**
     * @param callable $log fn(string $message): void - the panel's log, at a
     *                      level that does not mail: the same broken
     *                      extension is reported on every request until it
     *                      is fixed.
     */
    public function __construct(callable $log)
    {
        $this->log = $log;
    }

    /**
     * @param Extension[]      $extensions In registration order
     * @param array<string, mixed> $claimed 'Type.field' => anything, for every
     *                                      field this plugin already resolves
     * @param callable         $build      fn(LoadedExtension[] $loaded): mixed,
     *                                     throwing when that set does not build
     * @return array{0: mixed, 1: LoadedExtension[]} What $build returned for
     *                                               the kept set, and that set
     */
    public function load(array $extensions, ExtensionContext $context, array $claimed, callable $build): array
    {
        $candidates = array();

        foreach ($extensions as $extension) {
            $loaded = $this->read($extension, $context, $claimed);

            if ($loaded !== null) {
                $candidates[] = $loaded;

                foreach ($loaded->getResolvers() as $key => $resolver) {
                    $claimed[$key] = true;
                }
            }
        }

        if ($candidates === array()) {
            return array($build(array()), array());
        }

        try {
            return array($build($candidates), $candidates);
        } catch (Throwable $e) {
            // Somebody's SDL does not fit. Find out whose.
        }

        $kept = array();
        $built = null;

        foreach ($candidates as $candidate) {
            try {
                $built = $build(array_merge($kept, array($candidate)));
                $kept[] = $candidate;
            } catch (Throwable $e) {
                $this->leaveOut($candidate->getName(), 'the schema does not build with it: ' . $e->getMessage());
            }
        }

        if ($kept === array()) {
            // This plugin's own schema. If that throws, the fault is not an
            // extension's, and it must not be reported as one.
            $built = $build(array());
        }

        return array($built, $kept);
    }

    /**
     * Everything the extension returns, asked for once and checked, or null.
     *
     * @param array<string, mixed> $claimed
     */
    private function read(Extension $extension, ExtensionContext $context, array $claimed): ?LoadedExtension
    {
        $name = '(unnamed)';

        try {
            $name = $extension->getName();
            $sdl = $extension->getSdl();
            $document = Parser::parse($sdl, array('noLocation' => true));
            $declared = self::declaredFields($document);
            $resolvers = $extension->getResolvers($context);
            $complexity = $extension->getComplexity();

            self::checkFieldMap('resolver', $resolvers, $declared, $claimed);
            self::checkFieldMap('complexity', $complexity, $declared, array());

            return new LoadedExtension($name, $sdl, $document, $resolvers, $complexity);
        } catch (Throwable $e) {
            $this->leaveOut($name, $e->getMessage());

            return null;
        }
    }

    /**
     * 'Type.field' => true for every object field the document declares, or
     * a LogicException naming the first definition an extension may not make.
     *
     * @return array<string, bool>
     */
    public static function declaredFields(DocumentNode $document): array
    {
        $declared = array();

        foreach ($document->definitions as $definition) {
            if ($definition instanceof ObjectTypeDefinitionNode
                || $definition instanceof ObjectTypeExtensionNode
            ) {
                $typeName = $definition->name->value;

                // Every interface in the schema is this plugin's: Node carries
                // its identifier space and ownership rules, and VirtualHost
                // and Provisioned are resolved by TypeResolver, which knows
                // only this plugin's types.
                if (count($definition->interfaces) > 0) {
                    throw new LogicException(sprintf(
                        '%s may not implement an interface.', $typeName
                    ));
                }

                foreach ($definition->fields as $field) {
                    $declared[$typeName . '.' . $field->name->value] = true;
                }

                continue;
            }

            if ($definition instanceof InputObjectTypeDefinitionNode
                || $definition instanceof EnumTypeDefinitionNode
            ) {
                continue;
            }

            throw new LogicException(sprintf(
                'an extension may declare object, input and enum types and extend object types; '
                    . 'it declares a %s.',
                $definition->kind
            ));
        }

        return $declared;
    }

    /**
     * @param mixed                $map
     * @param array<string, bool>  $declared
     * @param array<string, mixed> $claimed
     */
    private static function checkFieldMap(string $what, $map, array $declared, array $claimed): void
    {
        foreach ($map as $key => $fn) {
            if (!isset($declared[$key])) {
                throw new LogicException(sprintf(
                    'it has a %s for "%s", which its own SDL does not declare.', $what, $key
                ));
            }

            if (isset($claimed[$key])) {
                throw new LogicException(sprintf(
                    'it has a %s for "%s", which is already served.', $what, $key
                ));
            }

            if (!is_callable($fn)) {
                throw new LogicException(sprintf(
                    'its %s for "%s" is not callable.', $what, $key
                ));
            }
        }
    }

    private function leaveOut(string $name, string $reason): void
    {
        call_user_func($this->log, sprintf(
            'SGW_GraphQL: the GraphQL extension "%s" was left out of the API: %s', $name, $reason
        ));
    }
}
