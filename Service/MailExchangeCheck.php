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

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;

/**
 * Turning a host's external mail off makes this box its mail server again:
 * Postfix takes the domain back as a local one and delivers to it here,
 * mail its own customers send included. If public DNS still sends the
 * domain's mail somewhere else, that local copy is the wrong one, so the API
 * refuses until the MX points here. The panel's page does not check.
 *
 * "Here" is any address the box has: a row of server_ips, or
 * BASE_SERVER_PUBLIC_IP, which named publishes for a host on a private one
 * (Servers::named::bind, isRoutableAddr()). Only the preferred MX hosts - the
 * lowest preference - are held to it; a backup MX elsewhere is allowed.
 */
final class MailExchangeCheck
{
    /** @var Toolkit */
    private $kit;

    /** @var MailExchangeLookup|null */
    private $lookup;

    /**
     * @param MailExchangeLookup|null $lookup null turns the check off
     *                                        (config.php's mx_check_resolvers
     *                                        set to none)
     */
    public function __construct(Toolkit $kit, ?MailExchangeLookup $lookup)
    {
        $this->kit = $kit;
        $this->lookup = $lookup;
    }

    /**
     * Refuses turning external mail off while the host's MX is elsewhere.
     * Anything else - turning it on, leaving it alone, turning off what is
     * already off - is not checked.
     *
     * @param array<string, mixed> $row          Toolkit::vhost()'s row of a
     *                                           domain or domain alias
     * @param string|null          $externalMail VhostInput::externalMail()
     * @throws ApiException CONFLICT
     */
    public function requireMailHere(array $row, ?string $externalMail): void
    {
        if ($this->lookup === null || $externalMail !== 'off' || $row['externalMail'] !== true) {
            return;
        }

        $name = (string)$row['name'];
        $shown = $this->kit->core()->toUnicode($name);

        try {
            $preferred = self::preferred($this->lookup->mx($name));

            if ($preferred === array()) {
                throw self::refuse($shown, 'public DNS has no MX record for it');
            }

            $here = $this->boxAddresses();

            foreach ($preferred as $host) {
                if ($host === '') {
                    throw self::refuse($shown, 'it publishes a null MX, which accepts no mail at all');
                }

                $addresses = array_values(array_unique(array_filter(
                    array_map(array(self::class, 'normalise'), $this->lookup->addresses($host))
                )));

                if ($addresses === array()) {
                    throw self::refuse($shown, sprintf('its MX host %s does not resolve in public DNS', $host));
                }

                if (array_intersect($addresses, $here) === array()) {
                    throw self::refuse($shown, sprintf(
                        'its MX host %s resolves to %s, not to this server (%s)',
                        $host, implode(', ', $addresses), implode(', ', $here)
                    ));
                }
            }
        } catch (DnsLookupException $e) {
            throw Guard::conflict(sprintf(
                'External mail was not turned off for %s: its MX record could not be checked, '
                    . 'because no public DNS resolver answered. Try again later.',
                $shown
            ));
        }
    }

    /**
     * The hosts at the lowest preference.
     *
     * @param array<int, array{host: string, preference: int}> $mx
     * @return string[]
     */
    private static function preferred(array $mx): array
    {
        if ($mx === array()) {
            return array();
        }

        $lowest = min(array_column($mx, 'preference'));
        $hosts = array();

        foreach ($mx as $record) {
            if ($record['preference'] === $lowest) {
                $hosts[] = $record['host'];
            }
        }

        return array_values(array_unique($hosts));
    }

    /** @return string[] Normalised, so an IPv6 address compares by value. */
    private function boxAddresses(): array
    {
        $addresses = array_column($this->kit->db()->rows('SELECT ip_number FROM server_ips'), 'ip_number');
        $addresses[] = (string)$this->kit->core()->config('BASE_SERVER_PUBLIC_IP', '');

        return array_values(array_unique(array_filter(array_map(array(self::class, 'normalise'), $addresses))));
    }

    private static function normalise(string $address): string
    {
        $packed = @inet_pton(trim($address));

        return $packed === false ? '' : (string)inet_ntop($packed);
    }

    private static function refuse(string $shown, string $why): ApiException
    {
        return Guard::conflict(sprintf(
            'External mail was not turned off for %s: %s. Turned off, this server would take '
                . 'the domain\'s mail as its own, while the rest of the internet still delivers it '
                . 'to the MX in public DNS. Point the MX record at this server first, and allow '
                . 'for its TTL.',
            $shown, $why
        ));
    }
}
