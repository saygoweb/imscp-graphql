<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use PHPUnit\Framework\TestCase;

class ProvisioningTest extends TestCase
{
    /**
     * @dataProvider knownStatuses
     */
    public function testMapsKnownStatuses(?string $status, string $state, bool $settled): void
    {
        $p = Provisioning::fromStatus($status);

        self::assertSame($state, $p->getState());
        self::assertSame($settled, $p->isSettled());
    }

    public function knownStatuses(): array
    {
        return [
            ['ok',          Provisioning::STATE_OK,       true],
            ['disabled',    Provisioning::STATE_DISABLED, true],
            ['ordered',     Provisioning::STATE_ORDERED,  true],
            ['toadd',       Provisioning::STATE_PENDING,  false],
            ['tochange',    Provisioning::STATE_PENDING,  false],
            ['todelete',    Provisioning::STATE_PENDING,  false],
            ['toenable',    Provisioning::STATE_PENDING,  false],
            ['todisable',   Provisioning::STATE_PENDING,  false],
            ['torestore',   Provisioning::STATE_PENDING,  false],
            ['tochangepwd', Provisioning::STATE_PENDING,  false],
        ];
    }

    public function testAnUnknownStatusIsAnErrorCarryingItsText(): void
    {
        // i-MSCP stores the backend's failure text in the status column, so
        // anything outside the vocabulary is a failure report, not a state.
        $p = Provisioning::fromStatus('Could not create the mail directory: disc full');

        self::assertSame(Provisioning::STATE_ERROR, $p->getState());
        self::assertTrue($p->isSettled(), 'a failed item is settled: the backend has stopped');
        self::assertSame('Could not create the mail directory: disc full', $p->getMessage());
    }

    public function testNullIsTreatedAsDisabled(): void
    {
        // A row that has never been configured has no status.
        $p = Provisioning::fromStatus(null);

        self::assertSame(Provisioning::STATE_DISABLED, $p->getState());
        self::assertTrue($p->isSettled());
    }

    public function testRawIsAlwaysThePanelsOwnString(): void
    {
        // The vocabulary is open: plugins and future versions add verbs, and a
        // client that meets an unknown one is better served by the string.
        self::assertSame('toadd', Provisioning::fromStatus('toadd')->getRaw());
        self::assertSame('', Provisioning::fromStatus(null)->getRaw());
    }

    public function testASuccessfulStateCarriesNoMessage(): void
    {
        self::assertNull(Provisioning::fromStatus('ok')->getMessage());
    }
}
