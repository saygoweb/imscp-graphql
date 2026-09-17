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

/**
 * Spec section 17's matrix: every mutation, as every one of the six accounts.
 *
 * The owner, the owner's reseller and an administrator succeed. Everybody
 * else - a sibling customer of the same reseller, another reseller's
 * customer, another reseller - gets NOT_FOUND, never FORBIDDEN (spec 6.3):
 * the object is not theirs to know about.
 */
class AuthorisationMatrixTest extends AuthzTestCase
{
    const EXPECTED = array(
        'customer'      => 'OK',
        'sibling'       => 'NOT_FOUND',
        'otherCustomer' => 'NOT_FOUND',
        'reseller'      => 'OK',
        'otherReseller' => 'NOT_FOUND',
        'admin'         => 'OK'
    );

    /**
     * @dataProvider cases
     */
    public function testTheOutcome(string $field, string $actor): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, $actor);

        self::assertSame(
            self::EXPECTED[$actor],
            self::outcome($result, $field),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function cases(): array
    {
        $cases = array();

        foreach (array_keys(MutationCatalogue::all()) as $field) {
            foreach (array_keys(self::EXPECTED) as $actor) {
                $cases[$field . ' as ' . $actor] = array($field, $actor);
            }
        }

        return $cases;
    }
}
