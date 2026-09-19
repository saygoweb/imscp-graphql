<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Unit\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\Numbers;
use PHPUnit\Framework\TestCase;

class NumbersTest extends TestCase
{
    public function testAnIntPassesThroughUnchanged(): void
    {
        self::assertSame(0, Numbers::coerceBigInt(0));
        self::assertSame(-1, Numbers::coerceBigInt(-1));
        self::assertSame(1073741824, Numbers::coerceBigInt(1073741824));
    }

    /** BigInt as schema.graphql:24 documents it: "serialised as a decimal string". */
    public function testADigitStringIsCoerced(): void
    {
        self::assertSame(1073741824, Numbers::coerceBigInt('1073741824'));
        self::assertSame(0, Numbers::coerceBigInt('0'));
    }

    public function testALeadingMinusIsAccepted(): void
    {
        self::assertSame(-1, Numbers::coerceBigInt('-1'));
    }

    public function testAnythingElseIsNull(): void
    {
        self::assertNull(Numbers::coerceBigInt('not a number'));
        self::assertNull(Numbers::coerceBigInt('1.5'));
        self::assertNull(Numbers::coerceBigInt(1.5));
        self::assertNull(Numbers::coerceBigInt(null));
        self::assertNull(Numbers::coerceBigInt(true));
        self::assertNull(Numbers::coerceBigInt(array()));
        self::assertNull(Numbers::coerceBigInt(' 1 '));
        self::assertNull(Numbers::coerceBigInt('+1'));
    }
}
