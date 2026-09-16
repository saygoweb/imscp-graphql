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

use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MailTypeTest extends TestCase
{
    /**
     * @dataProvider storedValues
     */
    public function testEveryStoredValueMapsToAKindAndAHost(
        string $stored, string $host, string $kind
    ): void {
        self::assertSame($kind, MailType::kindOf($stored), $stored);
        self::assertSame($host, MailType::hostTypeOf($stored), $stored);
    }

    /**
     * @dataProvider storedValues
     */
    public function testTheMappingIsReversible(
        string $stored, string $host, string $kind
    ): void {
        self::assertSame($stored, MailType::toMailType($host, $kind), $stored);
    }

    public function storedValues(): array
    {
        return array(
            // The twelve values of gui/include/Shared.php:38-49 ...
            array('normal_mail',     'dmn',    'MAILBOX'),
            array('normal_forward',  'dmn',    'FORWARD'),
            array('normal_catchall', 'dmn',    'CATCHALL'),
            array('alias_mail',      'als',    'MAILBOX'),
            array('alias_forward',   'als',    'FORWARD'),
            array('alias_catchall',  'als',    'CATCHALL'),
            array('subdom_mail',     'sub',    'MAILBOX'),
            array('subdom_forward',  'sub',    'FORWARD'),
            array('subdom_catchall', 'sub',    'CATCHALL'),
            array('alssub_mail',     'alssub', 'MAILBOX'),
            array('alssub_forward',  'alssub', 'FORWARD'),
            array('alssub_catchall', 'alssub', 'CATCHALL'),
            // ... and the four comma-joined pairs mail_add.php writes for a
            // mailbox that also forwards.
            array('normal_mail,normal_forward', 'dmn',    'MAILBOX_AND_FORWARD'),
            array('alias_mail,alias_forward',   'als',    'MAILBOX_AND_FORWARD'),
            array('subdom_mail,subdom_forward', 'sub',    'MAILBOX_AND_FORWARD'),
            array('alssub_mail,alssub_forward', 'alssub', 'MAILBOX_AND_FORWARD')
        );
    }

    public function testAllListsEverySixteenStoredValues(): void
    {
        $all = MailType::all();

        self::assertCount(16, $all);
        self::assertContains('normal_mail', $all);
        self::assertContains('alssub_mail,alssub_forward', $all);
    }

    public function testEveryValueInAllRoundTrips(): void
    {
        // The guard against adding a value to one table and not the other.
        foreach (MailType::all() as $stored) {
            self::assertSame(
                $stored,
                MailType::toMailType(
                    MailType::hostTypeOf($stored), MailType::kindOf($stored)
                ),
                $stored
            );
        }
    }

    public function testAnUnknownStoredValueIsRejected(): void
    {
        // i-MSCP's vocabulary here is closed, unlike the status vocabulary of
        // spec section 7.1: a mail_type this codec has not seen is data
        // corruption, not forward compatibility.
        $this->expectException(InvalidArgumentException::class);

        MailType::kindOf('normal_bounce');
    }

    public function testAnUnknownKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MailType::toMailType('dmn', 'ARCHIVE');
    }

    public function testAnUnknownHostTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MailType::toMailType('wildcard', 'MAILBOX');
    }
}
