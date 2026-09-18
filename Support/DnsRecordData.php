<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use InvalidArgumentException;

/**
 * A custom DNS record's input, encoded as the two columns the page writes.
 *
 * CORE-DEBT(C3): transcribed from gui/public/client/dns_edit.php:42-425 and
 *   597-767. Retire when DnsRecordService lands in core (spec section 21, C3
 *   row 6).
 */
final class DnsRecordData
{
    /** dns_edit.php:546: the types a customer may manage. */
    const CREATABLE_TYPES = array('A', 'AAAA', 'CNAME', 'MX', 'NS', 'SPF', 'SRV', 'TXT');

    const SRV_PROTOCOLS = array('TCP' => 'tcp', 'UDP' => 'udp', 'TLS' => 'tls');

    const DEFAULT_TTL = 3600;
    const MIN_TTL = 60;
    const MAX_TTL = 2147483647;

    /**
     * @param array    $input           name?, ttl?, data
     * @param callable $toAscii         fn(string): string - encode_idna()
     * @param callable $domainNameError fn(string): ?string - null when valid
     * @return array{domain_dns: string, domain_text: string}
     * @throws ApiException BAD_USER_INPUT
     */
    public static function encode(
        string $type, array $input, string $zoneAscii, callable $toAscii, callable $domainNameError
    ): array {
        if (!in_array($type, self::CREATABLE_TYPES, true)) {
            throw Guard::badInput('input.type', 'Only A, AAAA, CNAME, MX, NS, SPF, SRV and TXT records can be managed here.');
        }

        $ttl = $input['ttl'] ?? self::DEFAULT_TTL;

        if (!is_int($ttl) || $ttl < self::MIN_TTL || $ttl > self::MAX_TTL) {
            throw Guard::badInput('input.ttl', 'A TTL is a whole number of seconds from 60 to 2147483647.');
        }

        $name = self::name((string)($input['name'] ?? ''), $zoneAscii, $toAscii, $domainNameError);
        $data = isset($input['data']) ? (array)$input['data'] : array();

        switch ($type) {
            case 'A':
                $text = self::address($data, FILTER_FLAG_IPV4, 'Not an IPv4 address.');
                break;
            case 'AAAA':
                $text = self::address($data, FILTER_FLAG_IPV6, 'Not an IPv6 address.');
                break;
            case 'CNAME':
                $text = self::host($data, $zoneAscii, $toAscii, $domainNameError, true) . '.';
                break;
            case 'MX':
                $priority = self::number($data, 'priority', false);
                $text = sprintf('%d %s.', $priority, self::host($data, $zoneAscii, $toAscii, $domainNameError, false));
                break;
            case 'NS':
                $text = self::host($data, $zoneAscii, $toAscii, $domainNameError, false) . '.';

                if ($name === $zoneAscii) {
                    throw Guard::badInput('input.name', 'NS records are only allowed for subzone delegation.');
                }
                break;
            case 'SRV':
                $service = mb_strtolower(trim((string)($data['service'] ?? '')));

                // D1: anchored at both ends. $service is already lower-cased,
                // so the /i is dead weight; a prefix match here used to let
                // anything - including tabs, newlines and further records -
                // ride through to the composed owner name below unvalidated.
                if (!preg_match('/^_[a-z0-9]+$/', $service)) {
                    throw Guard::badInput('input.data.service', 'A service name starts with an underscore, e.g. _sip.');
                }

                $protocol = self::SRV_PROTOCOLS[(string)($data['protocol'] ?? '')] ?? null;

                if ($protocol === null) {
                    throw Guard::badInput('input.data.protocol', 'The protocol is TCP, UDP or TLS.');
                }

                $priority = self::number($data, 'priority', false);
                $weight = self::number($data, 'weight', false);
                $port = self::number($data, 'port', true);
                $target = self::host($data, $zoneAscii, $toAscii, $domainNameError, false);
                // Safe to compose without its own domainNameError check:
                // $service is anchored to ^_[a-z0-9]+$ above, $protocol comes
                // only from self::SRV_PROTOCOLS, and $name was already
                // validated on its own account.
                $name = sprintf('%s._%s.%s', $service, $protocol, $name);
                $text = sprintf('%d %d %d %s.', $priority, $weight, $port, $target);
                break;
            default:
                try {
                    $text = self::formatTxt((string)($data['text'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    throw Guard::badInput('input.data.text', $e->getMessage());
                }
        }

        return array('domain_dns' => $name . ".\t" . $ttl, 'domain_text' => $text);
    }

    /**
     * dns_edit.php:298-351.
     *
     * @throws InvalidArgumentException
     */
    public static function formatTxt(string $data): string
    {
        $data = trim($data, "\t\n\r\0\x0B\x28\x29");

        if ($data === '') {
            throw new InvalidArgumentException('The text cannot be empty.');
        }

        if (!preg_match('/^[[:print:]\s]+$/', $data)) {
            throw new InvalidArgumentException('Only printable ASCII characters and line breaks are allowed.');
        }

        list($quoted, $unquoted) = self::quotedAndUnquoted($data);

        if (!empty($quoted) && !empty($unquoted)) {
            throw new InvalidArgumentException('The text cannot have both quoted and unquoted strings.');
        }

        foreach ($unquoted as $string) {
            if (preg_match('/(?<!\\\\)(?:\\\\{2})*\K"/', $string)) {
                throw new InvalidArgumentException('A quote that is not a string delimiter must be escaped.');
            }
        }

        $data = implode('', empty($quoted) ? $unquoted : $quoted);

        // RFC 4408 section 3.1.3: a character-string is at most 255 bytes.
        if (strlen($data) > 255) {
            $chunks = array();
            $offset = 0;
            $length = strlen($data);

            while ($offset < $length) {
                $take = min(255, $length - $offset);

                // D2: never split between a backslash and the character it
                // escapes. An odd run of trailing backslashes means the
                // last one is unpaired, and cutting the chunk there would
                // let it escape the closing quote added below. Shortening
                // is always safe here, because there is more data ahead to
                // carry that byte into the next chunk; only checked when
                // this chunk is not the last, since a genuine trailing
                // backslash at the very end has nowhere to go.
                if ($offset + $take < $length && self::endsWithOddBackslashes(substr($data, $offset, $take))) {
                    $take--;
                }

                $chunks[] = '"' . substr($data, $offset, $take) . '"';
                $offset += $take;
            }

            return implode(' ', $chunks);
        }

        return '"' . $data . '"';
    }

    /**
     * Whether $piece ends with an odd number of backslashes - the last one,
     * if so, is unpaired and would escape whatever follows it.
     */
    private static function endsWithOddBackslashes(string $piece): bool
    {
        $count = 0;

        for ($i = strlen($piece) - 1; $i >= 0 && $piece[$i] === '\\'; $i--) {
            $count++;
        }

        return $count % 2 === 1;
    }

    /**
     * dns_edit.php:42-87, including the page's own "TODO: to be improved".
     *
     * @return array{0: string[], 1: string[]} quoted strings, unquoted strings
     */
    public static function quotedAndUnquoted(string $string): array
    {
        $string = trim(str_replace(array("\r\n", "\n", "\r"), ' ', $string));
        $quoted = $unquoted = array();
        $unquotedIndex = 0;
        $escaped = $afterQuoted = false;

        for ($i = 0, $length = strlen($string); $i < $length; $i++) {
            if ($afterQuoted && $string[$i] == ' ') {
                continue;
            }

            if (!$escaped && $string[$i] == '"') {
                $quotedString = '';

                while (isset($string[++$i]) && ($escaped || $string[$i] != '"')) {
                    $quotedString .= $string[$i];
                    $escaped = $string[$i] == '\\';
                }

                if (isset($string[$i])) {
                    if ($quotedString != '') {
                        $quoted[] = $quotedString;
                    }

                    $afterQuoted = true;
                } else {
                    $afterQuoted = false;
                    $unquoted[] = '"' . $quotedString;
                }

                $unquotedIndex++;
                $escaped = false;
                continue;
            }

            $afterQuoted = false;
            $escaped = $string[$i] == '\\';

            if (isset($unquoted[$unquotedIndex])) {
                $unquoted[$unquotedIndex] .= $string[$i];
            } else {
                $unquoted[$unquotedIndex] = $string[$i];
            }
        }

        return array($quoted, $unquoted);
    }

    /**
     * The record's owner name, completed in the zone, without its trailing dot.
     *
     * dns_edit.php:598-623.
     */
    private static function name(string $input, string $zone, callable $toAscii, callable $domainNameError): string
    {
        $name = mb_strtolower(trim($input));

        if ($name === '@' || $name === '') {
            $name = $zone . '.';
        } elseif (substr($name, -1) !== '.') {
            $name .= '.' . $zone . '.';
        }

        $name = (string)call_user_func($toAscii, $name);

        // CORE-DEBT(C11): C11 item 5 - anchored, and the zone quoted: the page's
        //   /(?:.*?\.)?zone\.test\.$/ also accepts evilzone.test.
        if (!preg_match('/^(?:.+\.)?' . preg_quote($zone, '/') . '\.$/', $name)) {
            throw Guard::badInput('input.name', 'The name is outside the zone.');
        }

        $name = rtrim($name, '.');
        $check = $name;

        // CORE-DEBT(C11): C11 item 5 - "=== 0"; the page's "== 0" is true for false.
        if (strpos($check, '_') === 0) {
            $check = substr($check, 1);
        }

        if (strpos($check, '*.') === 0) {
            $check = substr($check, 2);
        }

        $reason = call_user_func($domainNameError, $check);

        if ($reason !== null) {
            throw Guard::badInput('input.name', (string)$reason);
        }

        return $name;
    }

    /**
     * A target host, completed in the zone, without its trailing dot.
     *
     * @param bool $ignoreUnderscores dns_edit.php:190 - a CNAME may point at a
     *                                name with underscores (DKIM, RFC 4871)
     */
    private static function host(
        array $data, string $zone, callable $toAscii, callable $domainNameError, bool $ignoreUnderscores
    ): string {
        $host = mb_strtolower(trim((string)($data['target'] ?? '')));

        if ($host === '') {
            throw Guard::badInput('input.data.target', 'A target host is required.');
        }

        if (substr($host, -1) !== '.') {
            $host .= '.' . $zone;
        }

        $host = (string)call_user_func($toAscii, rtrim($host, '.'));
        $reason = call_user_func($domainNameError, $ignoreUnderscores ? str_replace('_', '', $host) : $host);

        if ($reason !== null) {
            throw Guard::badInput('input.data.target', (string)$reason);
        }

        return $host;
    }

    private static function address(array $data, int $flag, string $message): string
    {
        $address = trim((string)($data['address'] ?? ''));

        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP, $flag) === false) {
            throw Guard::badInput('input.data.address', $message);
        }

        return $address;
    }

    /**
     * A 16-bit unsigned field: a priority, a weight or a port.
     */
    private static function number(array $data, string $key, bool $required): int
    {
        if (!isset($data[$key])) {
            if ($required) {
                throw Guard::badInput('input.data.' . $key, sprintf('%s is required.', ucfirst($key)));
            }

            return 0;
        }

        $value = $data[$key];

        if (!is_int($value) || $value < 0 || $value > 65535) {
            throw Guard::badInput('input.data.' . $key, sprintf('%s is a whole number from 0 to 65535.', ucfirst($key)));
        }

        return $value;
    }
}
