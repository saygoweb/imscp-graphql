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
 * For a customer-level mutation the owner, the owner's reseller and an
 * administrator succeed, and everybody else - a sibling customer of the same
 * reseller, another reseller's customer, another reseller - gets NOT_FOUND,
 * never FORBIDDEN (spec 6.3): the object is not theirs to know about.
 *
 * Phase 4's verbs are the reseller's, not the customer's, and the same six
 * accounts answer differently for them: a customer that reaches its own
 * account, or its own alias order, is refused the verb rather than the object,
 * and that is FORBIDDEN. So the expectation belongs to the row, not to this
 * class - each catalogue entry carries its own, and this asserts that one.
 */
class AuthorisationMatrixTest extends AuthzTestCase
{
    /** The six fixture accounts, in the order the matrix reads. */
    const ACTORS = array('customer', 'sibling', 'otherCustomer', 'reseller', 'otherReseller', 'admin');

    /**
     * @dataProvider cases
     */
    public function testTheOutcome(string $field, string $actor): void
    {
        $this->skipUnlessInSchema($field);

        $result = $this->runEntry($field, $actor);

        self::assertSame(
            MutationCatalogue::all()[$field]['expected'][$actor],
            self::outcome($result, $field),
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public function cases(): array
    {
        $cases = array();

        foreach (array_keys(MutationCatalogue::all()) as $field) {
            foreach (self::ACTORS as $actor) {
                $cases[$field . ' as ' . $actor] = array($field, $actor);
            }
        }

        return $cases;
    }
}
