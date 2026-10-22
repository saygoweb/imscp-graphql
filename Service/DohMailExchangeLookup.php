<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

/**
 * MailExchangeLookup over DNS-over-HTTPS, in the JSON form Cloudflare's and
 * Google's public resolvers both answer (application/dns-json).
 *
 * HTTPS rather than port 53 because PHP has no resolver of its own to point
 * at another server - dns_get_record() asks the box's, which is the answer
 * MailExchangeLookup exists to avoid - and outbound 443 is the port a panel
 * host is least likely to have filtered.
 */
final class DohMailExchangeLookup implements MailExchangeLookup
{
    const TYPE_A = 1;
    const TYPE_MX = 15;
    const TYPE_AAAA = 28;

    const STATUS_NOERROR = 0;
    const STATUS_NXDOMAIN = 3;

    /** config.php's mx_check_resolvers as shipped. */
    const PUBLIC_RESOLVERS = array('https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve');

    /** Seconds per resolver, per question. */
    const TIMEOUT = 5;

    /** @var string[] */
    private $resolvers;

    /** @var callable fn(string $url): ?string */
    private $fetch;

    /**
     * @param string[]      $resolvers DoH JSON endpoints, asked in turn until
     *                                 one answers
     * @param callable|null $fetch     fn(string $url): ?string, the response
     *                                 body or null on any failure. Defaults to
     *                                 an HTTPS GET; a test passes its own.
     */
    public function __construct(array $resolvers, ?callable $fetch = null)
    {
        $this->resolvers = array_values($resolvers);
        $this->fetch = $fetch ?? array(self::class, 'get');
    }

    public function mx(string $asciiName): array
    {
        $hosts = array();

        foreach ($this->ask($asciiName, self::TYPE_MX) as $data) {
            $parts = preg_split('/\s+/', trim($data));

            if (count($parts) === 2 && ctype_digit($parts[0])) {
                $hosts[] = array('host' => strtolower(rtrim($parts[1], '.')), 'preference' => (int)$parts[0]);
            }
        }

        return $hosts;
    }

    public function addresses(string $asciiHost): array
    {
        return array_merge($this->ask($asciiHost, self::TYPE_A), $this->ask($asciiHost, self::TYPE_AAAA));
    }

    /**
     * The data of each answer of the asked type: a CNAME the resolver
     * followed comes back alongside, and is not one.
     *
     * @return string[]
     * @throws DnsLookupException
     */
    private function ask(string $name, int $type): array
    {
        foreach ($this->resolvers as $resolver) {
            $body = call_user_func(
                $this->fetch,
                $resolver . (strpos($resolver, '?') === false ? '?' : '&')
                    . http_build_query(array('name' => $name, 'type' => $type))
            );
            $answer = is_string($body) ? json_decode($body, true) : null;

            if (!is_array($answer) || !isset($answer['Status'])) {
                continue;
            }

            if ((int)$answer['Status'] === self::STATUS_NXDOMAIN) {
                return array();
            }

            if ((int)$answer['Status'] !== self::STATUS_NOERROR) {
                continue;
            }

            $data = array();

            foreach ((array)($answer['Answer'] ?? array()) as $record) {
                if (is_array($record) && (int)($record['type'] ?? 0) === $type && isset($record['data'])) {
                    $data[] = (string)$record['data'];
                }
            }

            return $data;
        }

        throw new DnsLookupException(sprintf('No public DNS resolver answered for %s.', $name));
    }

    private static function get(string $url): ?string
    {
        $body = @file_get_contents($url, false, stream_context_create(array(
            'http' => array(
                'method'        => 'GET',
                'header'        => "Accept: application/dns-json\r\n",
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true
            )
        )));

        // $http_response_header is set by file_get_contents() in this scope.
        if ($body === false || !isset($http_response_header[0])
            || !preg_match('#^HTTP/\S+\s+200\b#', $http_response_header[0])
        ) {
            return null;
        }

        return $body;
    }
}
