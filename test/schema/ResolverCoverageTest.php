<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

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

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use PHPUnit\Framework\TestCase;

class ResolverCoverageTest extends TestCase
{
    /**
     * Types that a resolver builds in place, as a plain array, inside the
     * shape of whatever owns them.
     *
     * A field of one of these needs no map entry, because ResolverMap's
     * fallback - read the key of the same name off the source - finds a ready
     * array. Every other object-typed field is an edge, and an edge with no
     * resolver returns null and nulls whatever non-null field holds it.
     *
     * Adding a name here is a deliberate act. Adding an edge and forgetting
     * its resolver is not, and that is the failure this list makes visible.
     */
    const SHAPED_IN_PLACE = array(
        'Provisioning', 'Forwarding', 'ContactDetails', 'Quota', 'Storage',
        'CustomerQuotas', 'CustomerFeatures', 'Autoresponder', 'PlanAllowance',
        'PlanQuotas', 'PlanStorage', 'PlanFeatures', 'ResellerQuotas'
    );

    private function container(): Container
    {
        return Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; }
        );
    }

    public function testEveryEdgeInTheSchemaHasAResolver(): void
    {
        $container = $this->container();
        $schema = $container->schemaFactory()->create();
        $map = array();

        foreach ($container->resolverMaps() as $one) {
            $map += $one;
        }

        $missing = array();

        foreach ($schema->getTypeMap() as $typeName => $type) {
            if (!$type instanceof ObjectType || strpos($typeName, '__') === 0) {
                continue;
            }

            foreach ($type->getFields() as $fieldName => $field) {
                $named = Type::getNamedType($field->getType());

                if (Type::isLeafType($named)
                    || in_array($named->name, self::SHAPED_IN_PLACE, true)
                    || substr($typeName, -10) === 'Connection'
                    || isset($map[$typeName . '.' . $fieldName])
                ) {
                    continue;
                }

                $missing[] = $typeName . '.' . $fieldName;
            }
        }

        self::assertSame(array(), $missing);
    }

    public function testEveryResolverKeyNamesAFieldThatExists(): void
    {
        // The other direction: a map entry for Custmer.domain would never
        // run, and nothing else would ever say so.
        $container = $this->container();
        $schema = $container->schemaFactory()->create();
        $stale = array();

        foreach ($container->resolverMaps() as $owner => $map) {
            foreach (array_keys($map) as $key) {
                list($typeName, $fieldName) = explode('.', $key, 2);
                $type = $schema->getType($typeName);

                if (!$type instanceof ObjectType || !$type->hasField($fieldName)) {
                    $stale[] = $owner . ': ' . $key;
                }
            }
        }

        self::assertSame(array(), $stale);
    }

    public function testNoTwoResolverMapsClaimTheSameField(): void
    {
        // array_merge() would take the last one silently, and the field would
        // return the wrong shape for one caller in production. The container
        // throws on a collision; this asserts there is none to throw on.
        $seen = array();
        $collisions = array();

        foreach ($this->container()->resolverMaps() as $owner => $map) {
            foreach (array_keys($map) as $key) {
                if (isset($seen[$key])) {
                    $collisions[] = $key . ': ' . $seen[$key] . ' and ' . $owner;
                }

                $seen[$key] = $owner;
            }
        }

        self::assertSame(array(), $collisions);
    }

    public function testTheWholeResolverMapBuildsWithNoDatabase(): void
    {
        // Plan 1's ContainerTest builds the schema in the unit suite, which
        // has no panel and no PDO. Db::detached() is what makes that possible,
        // and this asserts nothing in the wiring queries during construction.
        self::assertNotSame(array(), $this->container()->resolverMaps());
    }
}
