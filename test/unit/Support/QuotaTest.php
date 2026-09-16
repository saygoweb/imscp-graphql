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

use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use PHPUnit\Framework\TestCase;

class QuotaTest extends TestCase
{
    public function testACustomerLimitOfMinusOneIsAWithheldFeature(): void
    {
        // Spec section 2.3: -1 on a customer means the feature is withheld,
        // which is not the same as a limit of zero and not the same as
        // unlimited. Getting this wrong grants or withholds a feature.
        $quota = Quota::fromCustomerLimit(-1, 0);

        self::assertFalse($quota->isEnabled());
        self::assertSame(0, $quota->getLimit());
        self::assertSame(0, $quota->getRemaining());
    }

    public function testACustomerLimitOfZeroIsUnlimited(): void
    {
        $quota = Quota::fromCustomerLimit(0, 17);

        self::assertTrue($quota->isEnabled());
        self::assertNull($quota->getLimit(), 'null means unlimited');
        self::assertSame(17, $quota->getUsed());
        self::assertNull($quota->getRemaining());
    }

    public function testAPositiveCustomerLimitIsItself(): void
    {
        $quota = Quota::fromCustomerLimit(10, 4);

        self::assertTrue($quota->isEnabled());
        self::assertSame(10, $quota->getLimit());
        self::assertSame(4, $quota->getUsed());
        self::assertSame(6, $quota->getRemaining());
    }

    public function testRemainingNeverGoesNegative(): void
    {
        // A limit lowered below current usage is ordinary in this panel.
        self::assertSame(0, Quota::fromCustomerLimit(2, 5)->getRemaining());
    }

    public function testAWithheldFeatureStillReportsWhatIsUsed(): void
    {
        // A feature can be withdrawn while objects created under it remain.
        $quota = Quota::fromCustomerLimit(-1, 3);

        self::assertFalse($quota->isEnabled());
        self::assertSame(3, $quota->getUsed());
    }

    public function testAResellerLimitOfZeroIsUnlimited(): void
    {
        $quota = Quota::fromResellerLimit(0, 42);

        self::assertTrue($quota->isEnabled());
        self::assertNull($quota->getLimit());
        self::assertSame(42, $quota->getUsed());
    }

    public function testAPositiveResellerLimitIsItself(): void
    {
        self::assertSame(25, Quota::fromResellerLimit(25, 3)->getLimit());
    }

    public function testANegativeResellerLimitIsTreatedAsWithheld(): void
    {
        // reseller_props has no withheld state, so a negative value is a data
        // fault. Withheld is the safe direction to fail in.
        self::assertFalse(Quota::fromResellerLimit(-1, 0)->isEnabled());
    }

    public function testToArrayIsTheShapeTheSchemaExpects(): void
    {
        self::assertSame(
            array('enabled' => true, 'limit' => 10, 'used' => 4, 'remaining' => 6),
            Quota::fromCustomerLimit(10, 4)->toArray()
        );

        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 4, 'remaining' => null),
            Quota::fromCustomerLimit(0, 4)->toArray()
        );
    }
}
