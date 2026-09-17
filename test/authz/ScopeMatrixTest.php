<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;

/**
 * Spec section 8.1 steps 2 and 3 for every mutation: scope is asked after
 * ownership, and the write scope is all a write needs (decision D19).
 */
class ScopeMatrixTest extends AuthzTestCase
{
    /**
     * @dataProvider fields
     */
    public function testTheOwnerWithoutTheWriteScopeIsForbidden(string $field): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, 'customer', array(Scope::ACCOUNT_READ));

        self::assertSame('FORBIDDEN', self::outcome($result, $field), json_encode($result));
        self::assertSame(
            MutationCatalogue::all()[$field]['scope'],
            $result['errors'][0]['extensions']['scope']
        );
    }

    /**
     * @dataProvider fields
     */
    public function testAStrangerWithoutTheWriteScopeIsStillNotFound(string $field): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, 'otherCustomer', array(Scope::ACCOUNT_READ));

        self::assertSame('NOT_FOUND', self::outcome($result, $field), json_encode($result));
    }

    /**
     * @dataProvider fields
     */
    public function testTheWriteScopeAloneIsEnough(string $field): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, 'customer', array(MutationCatalogue::all()[$field]['scope']));

        self::assertSame('OK', self::outcome($result, $field), json_encode($result));
    }

    public function fields(): array
    {
        $fields = array();

        foreach (array_keys(MutationCatalogue::all()) as $field) {
            $fields[$field] = array($field);
        }

        return $fields;
    }
}
