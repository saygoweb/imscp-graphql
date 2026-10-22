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
 * MX and address records as public DNS answers them.
 *
 * Not the box's own resolver: the box runs the domain's zone in its own
 * named, which answers with what the panel generated whatever the registrar
 * delegates to, and the rest of the internet sends mail by the delegated
 * answer. MailExchangeCheck needs that answer.
 */
interface MailExchangeLookup
{
    /**
     * @param string $asciiName An ASCII (punycode) domain name
     * @return array<int, array{host: string, preference: int}> Empty for no
     *         MX records or a name that does not exist. A host has no
     *         trailing dot; the RFC 7505 null MX is the host ''.
     * @throws DnsLookupException The question could not be answered
     */
    public function mx(string $asciiName): array;

    /**
     * @param string $asciiHost An ASCII (punycode) host name
     * @return string[] Its A and AAAA addresses, CNAMEs followed; empty for none
     * @throws DnsLookupException The question could not be answered
     */
    public function addresses(string $asciiHost): array;
}
