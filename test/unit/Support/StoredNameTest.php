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

use iMSCP\Plugin\SGW_GraphQL\Support\StoredName;
use PHPUnit\Framework\TestCase;

/**
 * Against a stand-in encoder, so these pin the label handling; the
 * integration suite's customer-filter tests run encode_idna() itself.
 */
class StoredNameTest extends TestCase
{
    /** @var string[] */
    private $asked = array();

    private function encoder(): callable
    {
        return function (string $label): string {
            $this->asked[] = $label;

            return 'xn--' . strlen($label);   // bytes: ü is two
        };
    }

    public function testAnAsciiValueIsNotEncodedAtAll(): void
    {
        self::assertSame('Elijah', StoredName::toAscii('Elijah', $this->encoder()));
        self::assertSame('a_b%.test', StoredName::toAscii('a_b%.test', $this->encoder()));
        self::assertSame(array(), $this->asked);
    }

    public function testOnlyTheNonAsciiLabelsAreEncodedEachOnItsOwn(): void
    {
        self::assertSame(
            'shop.xn--7.xn--2.de',
            StoredName::toAscii('shop.müller.ü.de', $this->encoder())
        );
        self::assertSame(array('müller', 'ü'), $this->asked);
    }

    public function testLeadingAndTrailingDotsAndEmptyLabelsSurvive(): void
    {
        self::assertSame('.xn--7.', StoredName::toAscii('.müller.', $this->encoder()));
        self::assertSame('a..xn--7', StoredName::toAscii('a..müller', $this->encoder()));
    }

    public function testALabelTheEncoderRefusesIsKeptAsGiven(): void
    {
        // encode_idna() returns its input when it cannot encode it.
        $identity = static function (string $label): string {
            return $label;
        };

        self::assertSame('ü_x.de', StoredName::toAscii('ü_x.de', $identity));
    }
}
