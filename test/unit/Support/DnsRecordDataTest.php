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

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\DnsRecordData;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DnsRecordDataTest extends TestCase
{
    const ZONE = 'zone.test';

    /** encode_idna() stand-in: every name in these tests is already ASCII. */
    private function toAscii(): callable
    {
        return static function (string $name): string {
            return $name;
        };
    }

    /** isValidDomainName() stand-in: dotted labels of [a-z0-9-], at least two. */
    private function domainNameError(): callable
    {
        return static function (string $name): ?string {
            return preg_match('/^([a-z0-9-]+\.)+[a-z0-9-]+$/', $name) ? null : 'Invalid domain name.';
        };
    }

    private function encode(string $type, array $input): array
    {
        return DnsRecordData::encode($type, $input, self::ZONE, $this->toAscii(), $this->domainNameError());
    }

    private function fieldOf(string $type, array $input): string
    {
        try {
            $this->encode($type, $input);
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());

            return $e->getExtensions()['field'];
        }

        self::fail('expected BAD_USER_INPUT');
    }

    // ---- names and TTLs -------------------------------------------------

    /**
     * @dataProvider names
     */
    public function testNamesAreCompletedInTheZone(string $name, string $expected): void
    {
        self::assertSame(
            $expected . ".\t3600",
            $this->encode('A', array('name' => $name, 'data' => array('address' => '203.0.113.9')))['domain_dns']
        );
    }

    public function names(): array
    {
        // dns_edit.php:601-607.
        return array(
            'empty is the zone'           => array('', 'zone.test'),
            '@ is the zone'               => array('@', 'zone.test'),
            'a relative name'             => array('www', 'www.zone.test'),
            'lower-cased'                 => array('WWW', 'www.zone.test'),
            'fully qualified in the zone' => array('mail.zone.test.', 'mail.zone.test'),
            'a leading underscore'        => array('_dmarc', '_dmarc.zone.test'),
            'a wildcard'                  => array('*', '*.zone.test')
        );
    }

    public function testTheTtlDefaultsAndIsKept(): void
    {
        self::assertSame(
            "www.zone.test.\t7200",
            $this->encode('A', array('name' => 'www', 'ttl' => 7200, 'data' => array('address' => '203.0.113.9')))['domain_dns']
        );
    }

    /**
     * @dataProvider refusedNames
     */
    public function testNamesThatAreRefused(array $input, string $field): void
    {
        self::assertSame($field, $this->fieldOf('A', $input + array('data' => array('address' => '203.0.113.9'))));
    }

    public function refusedNames(): array
    {
        return array(
            'another zone'                       => array(array('name' => 'other.test.'), 'input.name'),
            'a zone that merely ends like this'  => array(array('name' => 'evilzone.test.'), 'input.name'),
            'an invalid label'                   => array(array('name' => 'a..b'), 'input.name'),
            'a TTL below a minute'               => array(array('name' => 'www', 'ttl' => 59), 'input.ttl'),
            'a TTL that is not a number'         => array(array('name' => 'www', 'ttl' => '3600'), 'input.ttl')
        );
    }

    public function testAnUnderscoreIsStrippedOnlyWhenItLeads(): void
    {
        // C11 item 5: the page strips the first character of every name, so
        // 'xa..b' would be validated as 'a..b' there and 'x..b' would pass.
        self::assertSame('input.name', $this->fieldOf('A', array('name' => 'x..b', 'data' => array('address' => '203.0.113.9'))));
    }

    public function testATypeThatCannotBeManagedHereIsRefused(): void
    {
        self::assertSame('input.type', $this->fieldOf('PTR', array('name' => 'www', 'data' => array())));
    }

    // ---- data -----------------------------------------------------------

    public function testAddresses(): void
    {
        self::assertSame('203.0.113.9', $this->encode('A', array('data' => array('address' => ' 203.0.113.9 ')))['domain_text']);
        self::assertSame('2001:db8::1', $this->encode('AAAA', array('data' => array('address' => '2001:db8::1')))['domain_text']);
        self::assertSame('input.data.address', $this->fieldOf('A', array('data' => array('address' => '2001:db8::1'))));
        self::assertSame('input.data.address', $this->fieldOf('AAAA', array('data' => array('address' => '203.0.113.9'))));
        self::assertSame('input.data.address', $this->fieldOf('A', array('data' => array())));
    }

    public function testCanonicalNames(): void
    {
        self::assertSame('www.zone.test.', $this->encode('CNAME', array('name' => 'w', 'data' => array('target' => 'www')))['domain_text']);
        self::assertSame('example.net.', $this->encode('CNAME', array('name' => 'w', 'data' => array('target' => 'Example.NET.')))['domain_text']);
        // dns_edit.php:190: underscores are removed for validation only.
        self::assertSame('_x.example.net.', $this->encode('CNAME', array('name' => 'w', 'data' => array('target' => '_x.example.net.')))['domain_text']);
        self::assertSame('input.data.target', $this->fieldOf('CNAME', array('name' => 'w', 'data' => array())));
    }

    public function testMailExchangers(): void
    {
        self::assertSame(
            '10 mail.zone.test.',
            $this->encode('MX', array('data' => array('priority' => 10, 'target' => 'mail')))['domain_text']
        );
        self::assertSame('0 mx.example.net.', $this->encode('MX', array('data' => array('target' => 'mx.example.net.')))['domain_text']);
        self::assertSame('input.data.priority', $this->fieldOf('MX', array('data' => array('priority' => 70000, 'target' => 'mail'))));
        self::assertSame('input.data.target', $this->fieldOf('MX', array('data' => array('priority' => 10))));
    }

    public function testNameServersDelegateASubzoneOnly(): void
    {
        $encoded = $this->encode('NS', array('name' => 'sub', 'data' => array('target' => 'ns1.example.net.')));

        self::assertSame("sub.zone.test.\t3600", $encoded['domain_dns']);
        self::assertSame('ns1.example.net.', $encoded['domain_text']);
        self::assertSame('input.name', $this->fieldOf('NS', array('name' => '@', 'data' => array('target' => 'ns1.example.net.'))));
    }

    public function testServiceRecords(): void
    {
        $encoded = $this->encode('SRV', array('name' => '@', 'data' => array(
            'service' => '_SIP', 'protocol' => 'TCP', 'priority' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip'
        )));

        self::assertSame("_sip._tcp.zone.test.\t3600", $encoded['domain_dns']);
        self::assertSame('10 5 5060 sip.zone.test.', $encoded['domain_text']);
    }

    /**
     * @dataProvider refusedServices
     */
    public function testServiceRecordsThatAreRefused(array $data, string $field): void
    {
        self::assertSame($field, $this->fieldOf('SRV', array('name' => '@', 'data' => $data + array(
            'service' => '_sip', 'protocol' => 'TCP', 'port' => 5060, 'target' => 'sip'
        ))));
    }

    public function refusedServices(): array
    {
        return array(
            'no leading underscore'      => array(array('service' => 'sip'), 'input.data.service'),
            'an unknown protocol'        => array(array('protocol' => 'SCTP'), 'input.data.protocol'),
            'a port out of range'        => array(array('port' => 70000), 'input.data.port'),
            'a negative weight'          => array(array('weight' => -1), 'input.data.weight'),
            // D1: the service name was only anchored at its start, so a
            // valid prefix followed by anything else used to pass.
            'trailing text after the prefix' => array(array('service' => '_sip evil'), 'input.data.service'),
            'a newline carrying a whole record' => array(
                array('service' => "_sip\nwww 300 IN A 203.0.113.1"), 'input.data.service'
            )
        );
    }

    public function testAServiceRecordNeedsAPort(): void
    {
        self::assertSame('input.data.port', $this->fieldOf('SRV', array('name' => '@', 'data' => array(
            'service' => '_sip', 'protocol' => 'TCP', 'target' => 'sip'
        ))));
    }

    public function testTextRecords(): void
    {
        self::assertSame('"v=spf1 -all"', $this->encode('TXT', array('data' => array('text' => 'v=spf1 -all')))['domain_text']);
        self::assertSame('"v=spf1 -all"', $this->encode('SPF', array('data' => array('text' => 'v=spf1 -all')))['domain_text']);
        self::assertSame('input.data.text', $this->fieldOf('TXT', array('data' => array('text' => ''))));
    }

    // ---- the TXT formatter, against the page's own output (M20) ---------

    /**
     * @dataProvider txtVectors
     */
    public function testTxtFormattingMatchesThePage(string $input, string $expected): void
    {
        self::assertSame($expected, DnsRecordData::formatTxt($input));
    }

    public function txtVectors(): array
    {
        return array(
            'unquoted'                       => array('v=spf1 a mx -all', '"v=spf1 a mx -all"'),
            'already quoted'                 => array('"v=spf1 a mx -all"', '"v=spf1 a mx -all"'),
            'several quoted strings joined'  => array('"part one" "part two"', '"part onepart two"'),
            'an escaped quote'               => array('escaped \" quote', '"escaped \" quote"'),
            'parentheses trimmed'            => array('(v=spf1 -all)', '"v=spf1 -all"'),
            'a line break becomes a space'   => array("  line\nbreak  ", '"line break"'),
            'split at 255'                   => array(str_repeat('a', 300), '"' . str_repeat('a', 255) . '" "' . str_repeat('a', 45) . '"'),
            'a quoted string split at 255'   => array('"' . str_repeat('b', 256) . '"', '"' . str_repeat('b', 255) . '" "b"')
        );
    }

    /**
     * @dataProvider refusedTxt
     */
    public function testTxtThePageRefuses(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        DnsRecordData::formatTxt($input);
    }

    public function refusedTxt(): array
    {
        return array(
            'empty'                    => array(''),
            'not printable ASCII'      => array("caf\xc3\xa9"),
            'an unescaped quote'       => array('unquoted "quote'),
            'quoted and unquoted mixed' => array('"quoted" unquoted'),
            'an unterminated quote'    => array('"unterminated')
        );
    }
}
