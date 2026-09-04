<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Auth;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\IdentityShim;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use PHPUnit\Framework\TestCase;

class IdentityTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        IdentityShim::reset();
    }

    private function customer(int $id = 7): Identity
    {
        return new Identity($id, 'wpcache.test', 'user', 3, 'c@example.com',
                            ['DOMAINS_READ'], 42);
    }

    public function testMapsTheAdminTypeOntoARole(): void
    {
        self::assertSame(Identity::ROLE_CUSTOMER, $this->customer()->getRole());
        self::assertSame(
            Identity::ROLE_RESELLER,
            (new Identity(3, 'r', 'reseller', 1, null, [], null))->getRole()
        );
        self::assertSame(
            Identity::ROLE_ADMIN,
            (new Identity(1, 'a', 'admin', null, null, [], null))->getRole()
        );
    }

    public function testAnUnknownAdminTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Identity(1, 'x', 'wizard', null, null, [], null);
    }

    public function testScopesNarrowButAnEmptySetDoesNot(): void
    {
        // A token records the scopes it was issued with. A session-authenticated
        // identity records none, and is limited by its role alone.
        self::assertTrue($this->customer()->hasScope('DOMAINS_READ'));
        self::assertFalse($this->customer()->hasScope('MAIL_WRITE'));

        $session = new Identity(7, 'wpcache.test', 'user', 3, null, [], null);
        self::assertTrue($session->hasScope('MAIL_WRITE'));
    }

    public function testTheShimPopulatesExactlyTheKeysCoreReads(): void
    {
        IdentityShim::apply($this->customer());

        self::assertSame(7, $_SESSION['user_id']);
        self::assertSame('user', $_SESSION['user_type']);
        self::assertSame('wpcache.test', $_SESSION['user_logged']);
        self::assertSame(3, $_SESSION['user_created_by']);
        self::assertSame(7, $_SESSION['user_identity']->admin_id);
    }

    public function testTheShimIsIdempotentForTheSameIdentity(): void
    {
        IdentityShim::apply($this->customer());
        IdentityShim::apply($this->customer());

        self::assertSame(7, $_SESSION['user_id']);
    }

    public function testTheShimRefusesToChangeIdentityMidRequest(): void
    {
        // customerHasFeature() caches its answer in a static that is not keyed
        // by user, so a request that switched identity would read the first
        // identity's features for the second.
        IdentityShim::apply($this->customer(7));

        $this->expectException(ApiException::class);

        IdentityShim::apply($this->customer(8));
    }
}
