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
            'an underscore label'         => array('selector._domainkey', 'selector._domainkey.zone.test'),
            'an underscore deeper in'     => array('_dmarc.sub', '_dmarc.sub.zone.test'),
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

    // ---- decoding domain_dns ----------------------------------------------

    /**
     * @dataProvider storedNames
     */
    public function testTheStoredColumnSplitsIntoNameAndTtl(string $stored, string $name, ?int $ttl): void
    {
        self::assertSame(
            array('name' => $name, 'ttl' => $ttl, 'service' => null, 'protocol' => null),
            DnsRecordData::decode($stored, self::ZONE, 'A')
        );
    }

    public function storedNames(): array
    {
        return array(
            // Written by encode() or dns_edit.php: absolute, then a tab and the TTL.
            'a name in the zone'           => array("www.zone.test.\t3600", 'www', 3600),
            'the zone itself'              => array("zone.test.\t300", '@', 300),
            'a deeper name'                => array("selector._domainkey.zone.test.\t3600", 'selector._domainkey', 3600),
            'a wildcard'                   => array("*.zone.test.\t3600", '*', 3600),
            'spaces, not a tab'            => array('www.zone.test. 600', 'www', 600),
            'the zone in capitals'         => array("WWW.Zone.Test.\t3600", 'WWW', 3600),
            // Older rows: a bare name relative to the zone, and no TTL.
            'a relative name, no TTL'      => array('mail', 'mail', null),
            'a relative @, no TTL'         => array('@', '@', null),
            'a relative name with a TTL'   => array("mail\t7200", 'mail', 7200),
            'an absolute name, no TTL'     => array('mail.zone.test.', 'mail', null),
            // Not this zone: kept fully qualified, so it cannot be mistaken
            // for a name in it.
            'another zone'                 => array("www.other.test.\t3600", 'www.other.test.', 3600),
            'a zone that merely ends so'   => array("evilzone.test.\t3600", 'evilzone.test.', 3600),
            // As stored, though a write wants 60 or more.
            'a TTL below a minute'         => array("www.zone.test.\t30", 'www', 30),
            // RFC 2181 section 8's ceiling, which is also Int's.
            'a TTL past what Int can hold' => array("www.zone.test.\t2147483648", 'www', 2147483647),
            'a TTL far past it'            => array("www.zone.test.\t99999999999999999999", 'www', 2147483647),
            // A blank owner is not the apex.
            'nothing at all'               => array('', '', null),
            'a TTL and no owner'           => array("\t3600", '', 3600)
        );
    }

    /**
     * @dataProvider names
     */
    public function testANameReadBackIsOneTheInputTakes(string $name, string $expected): void
    {
        $input = array('name' => $name, 'ttl' => 600, 'data' => array('address' => '203.0.113.9'));
        $read = DnsRecordData::decode($this->encode('A', $input)['domain_dns'], self::ZONE, 'A');

        self::assertSame(600, $read['ttl']);
        // Written back, it encodes to the very same column.
        self::assertSame(
            $this->encode('A', $input)['domain_dns'],
            $this->encode('A', array('name' => $read['name']) + $input)['domain_dns']
        );
    }

    public function testWithNoZoneKnownTheNameIsAsStored(): void
    {
        // The alias the record was in is gone (DnsResolver::ROW_SELECT).
        self::assertSame(
            array('name' => 'www.zone.test.', 'ttl' => 3600, 'service' => null, 'protocol' => null),
            DnsRecordData::decode("www.zone.test.\t3600", null, 'A')
        );
    }

    /**
     * @dataProvider storedServiceNames
     */
    public function testAServiceRecordsNameIsWithoutItsServiceAndProtocol(
        string $stored, string $name, string $service, ?string $protocol
    ): void {
        self::assertSame(
            array('name' => $name, 'ttl' => 3600, 'service' => $service, 'protocol' => $protocol),
            DnsRecordData::decode($stored, self::ZONE, 'SRV')
        );
    }

    public function storedServiceNames(): array
    {
        // dns_edit.php:495.
        return array(
            'in the zone'         => array("_sip._tcp.www.zone.test.\t3600", 'www', '_sip', 'TCP'),
            'at the apex'         => array("_xmpp._udp.zone.test.\t3600", '@', '_xmpp', 'UDP'),
            'TLS'                 => array("_sips._tls.zone.test.\t3600", '@', '_sips', 'TLS'),
            'outside the zone'    => array("_sip._tcp.other.test.\t3600", 'other.test.', '_sip', 'TCP'),
            'in capitals'         => array("_SIP._TCP.www.zone.test.\t3600", 'www', '_sip', 'TCP'),
            // Not one the input offers, so nothing a write could take.
            'another transport'   => array("_sip._sctp.www.zone.test.\t3600", 'www', '_sip', null)
        );
    }

    public function testAServiceRecordReadBackWritesBackTheSame(): void
    {
        // Its service and protocol are in the data, and composed back onto
        // the name; left on the name too, they would be there twice.
        $data = array('port' => 5060, 'target' => 'sip.zone.test.');
        $stored = $this->encode('SRV', array(
            'name' => 'www', 'ttl' => 600, 'data' => $data + array('service' => '_sip', 'protocol' => 'TCP')
        ))['domain_dns'];
        $read = DnsRecordData::decode($stored, self::ZONE, 'SRV');

        self::assertSame(array('name' => 'www', 'ttl' => 600, 'service' => '_sip', 'protocol' => 'TCP'), $read);
        // Written back from what was read alone, it is the same record.
        self::assertSame($stored, $this->encode('SRV', array(
            'name' => $read['name'], 'ttl' => $read['ttl'],
            'data' => $data + array('service' => $read['service'], 'protocol' => $read['protocol'])
        ))['domain_dns']);
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
            'a name carrying a space'            => array(array('name' => 'www evil'), 'input.name'),
            'a name carrying a line break'       => array(array('name' => "www\nevil"), 'input.name'),
            'a TTL below a minute'               => array(array('name' => 'www', 'ttl' => 59), 'input.ttl'),
            'a TTL that is not a number'         => array(array('name' => 'www', 'ttl' => '3600'), 'input.ttl')
        );
    }

    public function testTheFirstCharacterIsNotStrippedBlindly(): void
    {
        // C11 item 5: the page strips the first character of every name, so
        // 'xa..b' would be validated as 'a..b' there and 'x..b' would pass.
        // Here only underscores are ignored, and only for the check.
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

    public function testALongTxtSplitDoesNotBreakAnEscapedQuote(): void
    {
        // D2: byte 254 is the backslash, byte 255 the quote it escapes -
        // exactly where a naive substr($data, $i, 255) would cut, leaving a
        // lone backslash to escape the chunk's own closing quote.
        $data = str_repeat('a', 254) . '\\"' . str_repeat('a', 44);
        self::assertSame(300, strlen($data));

        $result = DnsRecordData::formatTxt($data);

        self::assertSame(
            '"' . str_repeat('a', 254) . '" "\\"' . str_repeat('a', 44) . '"',
            $result
        );

        foreach (explode(' ', $result) as $chunk) {
            self::assertMatchesRegularExpression('/^".*"$/', $chunk);
            $content = substr($chunk, 1, -1);
            self::assertLessThanOrEqual(255, strlen($content));

            $backslashes = 0;
            for ($i = strlen($content) - 1; $i >= 0 && $content[$i] === '\\'; $i--) {
                $backslashes++;
            }

            self::assertSame(0, $backslashes % 2, 'the closing quote must be a real delimiter, not escaped');
        }
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
