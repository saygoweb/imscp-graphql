<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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
use iMSCP\Plugin\SGW_GraphQL\Service\MailExchangeLookup;

/**
 * Public DNS as a test states it. Unless told otherwise, every name's MX is
 * mail.<name> at preference 10, and every host resolves to $here.
 */
final class FakeMailExchangeLookup implements MailExchangeLookup
{
    /** @var string[] Every name and host asked, in order. */
    public $asked = array();

    /** @var array<string, array<int, array{host: string, preference: int}>> */
    public $mx = array();

    /** @var array<string, string[]> */
    public $addresses = array();

    /** @var bool Every question throws DnsLookupException. */
    public $down = false;

    /** @var string */
    private $here;

    public function __construct(string $here)
    {
        $this->here = $here;
    }

    public function mx(string $asciiName): array
    {
        $this->ask($asciiName);

        return $this->mx[$asciiName] ?? array(array('host' => 'mail.' . $asciiName, 'preference' => 10));
    }

    public function addresses(string $asciiHost): array
    {
        $this->ask($asciiHost);

        return $this->addresses[$asciiHost] ?? array($this->here);
    }

    private function ask(string $name): void
    {
        $this->asked[] = $name;

        if ($this->down) {
            throw new DnsLookupException('down');
        }
    }
}
