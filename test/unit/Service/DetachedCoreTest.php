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

use iMSCP\Plugin\SGW_GraphQL\Service\Core;
use iMSCP\Plugin\SGW_GraphQL\Service\DetachedCore;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The Db::detached() precedent, for Core: constructing it must touch
 * nothing, and every method that would otherwise reach the panel - dispatch
 * a real event, write the log (which may mail), send the alias-order mail,
 * prune rows - must instead fail loudly.
 */
class DetachedCoreTest extends TestCase
{
    public function testEveryMethodThrows(): void
    {
        $core = new DetachedCore();
        $methods = (new ReflectionClass(Core::class))->getMethods();

        self::assertNotEmpty($methods);

        foreach ($methods as $method) {
            $args = array();

            foreach ($method->getParameters() as $parameter) {
                $args[] = $this->dummyFor($parameter->getType());
            }

            try {
                $core->{$method->getName()}(...$args);
                self::fail(sprintf('Core::%s() did not throw.', $method->getName()));
            } catch (LogicException $e) {
                self::assertSame(
                    'This Core is detached: a test that runs a mutation must '
                        . 'pass a Core to Container::forTesting().',
                    $e->getMessage(),
                    $method->getName() . '() threw the wrong message'
                );
            }
        }
    }

    /**
     * @return mixed
     */
    private function dummyFor(?ReflectionNamedType $type)
    {
        if ($type === null) {
            return '';
        }

        switch ($type->getName()) {
            case 'string':
                return '';
            case 'int':
                return 0;
            case 'bool':
                return false;
            case 'array':
                return array();
            default:
                return null;
        }
    }
}
