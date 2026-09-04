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
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1335, USA.
 */

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GlobalIdTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $encoded = GlobalId::encode('Subdomain', 3);
        $decoded = GlobalId::decode($encoded);

        self::assertSame('Subdomain', $decoded->getType());
        self::assertSame(3, $decoded->getId());
    }

    public function testEncodingIsUrlSafeAndUnpadded(): void
    {
        // Base64url, so it survives a query string and a JSON body untouched.
        $encoded = GlobalId::encode('DomainAlias', 1234567);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
    }

    public function testDifferentTypesWithTheSameIdDoNotCollide(): void
    {
        self::assertNotSame(
            GlobalId::encode('Subdomain', 3),
            GlobalId::encode('DomainAlias', 3)
        );
    }

    public function testDecodeAcceptsTheExpectedType(): void
    {
        $decoded = GlobalId::decode(GlobalId::encode('MailAccount', 9), 'MailAccount');

        self::assertSame(9, $decoded->getId());
    }

    public function testDecodeRejectsTheWrongType(): void
    {
        // The whole point of the codec: a subdomain id supplied where an alias
        // id is expected fails at the edge, not in a resolver.
        $this->expectException(InvalidArgumentException::class);

        GlobalId::decode(GlobalId::encode('Subdomain', 3), 'DomainAlias');
    }

    /**
     * @dataProvider malformedIdentifiers
     */
    public function testDecodeRejectsMalformedInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::decode($input);
    }

    public function malformedIdentifiers(): array
    {
        return [
            'empty'            => [''],
            'not base64'       => ['!!!!'],
            'no separator'     => [self::raw('Subdomain')],
            'empty type'       => [self::raw(':3')],
            'non-numeric id'   => [self::raw('Subdomain:abc')],
            'negative id'      => [self::raw('Subdomain:-1')],
            'zero id'          => [self::raw('Subdomain:0')],
            'bad type chars'   => [self::raw('Sub domain:3')],
            'extra separator'  => [self::raw('Subdomain:3:4')],
            'leading zero'     => [self::raw('Subdomain:03')],
        ];
    }

    private static function raw(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public function testEncodeRejectsANonPositiveId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::encode('Subdomain', 0);
    }

    public function testEncodeRejectsATypeWithASeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GlobalId::encode('Sub:domain', 1);
    }
}
