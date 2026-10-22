<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DnsLookupException;
use iMSCP\Plugin\SGW_GraphQL\Service\DohMailExchangeLookup;
use PHPUnit\Framework\TestCase;

class DohMailExchangeLookupTest extends TestCase
{
    /** @var string[] */
    private $urls = array();

    /**
     * @param array<string, string|null> $bodies Response body by URL; a URL
     *                                           not listed fails
     */
    private function lookup(array $bodies, array $resolvers = array('https://one.test/dns-query')): DohMailExchangeLookup
    {
        return new DohMailExchangeLookup($resolvers, function (string $url) use ($bodies) {
            $this->urls[] = $url;

            return $bodies[$url] ?? null;
        });
    }

    private static function answer(int $status, array $records = array()): string
    {
        return json_encode(array('Status' => $status, 'Answer' => $records));
    }

    public function testMxRecordsAreParsed(): void
    {
        $lookup = $this->lookup(array(
            'https://one.test/dns-query?name=example.test&type=15' => self::answer(0, array(
                array('name' => 'example.test.', 'type' => 15, 'TTL' => 300, 'data' => '10 Mail.Example.test.'),
                array('name' => 'example.test.', 'type' => 15, 'TTL' => 300, 'data' => '20 backup.example.net.')
            ))
        ));

        self::assertSame(array(
            array('host' => 'mail.example.test', 'preference' => 10),
            array('host' => 'backup.example.net', 'preference' => 20)
        ), $lookup->mx('example.test'));
    }

    public function testANullMxIsTheEmptyHost(): void
    {
        $lookup = $this->lookup(array(
            'https://one.test/dns-query?name=example.test&type=15' => self::answer(0, array(
                array('name' => 'example.test.', 'type' => 15, 'data' => '0 .')
            ))
        ));

        self::assertSame(array(array('host' => '', 'preference' => 0)), $lookup->mx('example.test'));
    }

    public function testNxdomainIsNoRecords(): void
    {
        $lookup = $this->lookup(array(
            'https://one.test/dns-query?name=gone.test&type=15' => self::answer(3)
        ));

        self::assertSame(array(), $lookup->mx('gone.test'));
    }

    public function testAddressesAreAAndAaaaWithoutTheCname(): void
    {
        $lookup = $this->lookup(array(
            'https://one.test/dns-query?name=mail.example.test&type=1' => self::answer(0, array(
                array('name' => 'mail.example.test.', 'type' => 5, 'data' => 'host.example.net.'),
                array('name' => 'host.example.net.', 'type' => 1, 'data' => '192.0.2.1')
            )),
            'https://one.test/dns-query?name=mail.example.test&type=28' => self::answer(0, array(
                array('name' => 'host.example.net.', 'type' => 28, 'data' => '2001:db8::1')
            ))
        ));

        self::assertSame(array('192.0.2.1', '2001:db8::1'), $lookup->addresses('mail.example.test'));
    }

    public function testAFailingResolverFallsThroughToTheNext(): void
    {
        $lookup = $this->lookup(array(
            'https://one.test/dns-query?name=example.test&type=15' => self::answer(2),
            'https://two.test/resolve?name=example.test&type=15' => self::answer(0, array(
                array('name' => 'example.test.', 'type' => 15, 'data' => '10 mail.example.test.')
            ))
        ), array('https://one.test/dns-query', 'https://unreachable.test/dns-query', 'https://two.test/resolve'));

        self::assertSame(array(array('host' => 'mail.example.test', 'preference' => 10)), $lookup->mx('example.test'));
        self::assertCount(3, $this->urls);
    }

    public function testNoResolverAnsweringThrows(): void
    {
        $lookup = $this->lookup(array(
            'https://one.test/dns-query?name=example.test&type=15' => 'not json'
        ));

        $this->expectException(DnsLookupException::class);

        $lookup->mx('example.test');
    }
}
