<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

class DnsMutationsTest extends AuthzTestCase
{
    public function testACreatedRecordReadsBackWithTheNameAndTtlItWasGiven(): void
    {
        $result = $this->execute(
            'mutation($input: DnsRecordCreateInput!) { dnsRecordCreate(input: $input) { name ttl type value } }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'name' => '_mcp-test', 'ttl' => 300, 'type' => 'A', 'data' => array('address' => '203.0.113.9')
            )),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'name'  => '_mcp-test',
            'ttl'   => 300,
            'type'  => 'A',
            'value' => '203.0.113.9'
        ), $result['data']['dnsRecordCreate']);
    }

    public function testARecordAtTheApexReadsBackAsAt(): void
    {
        $result = $this->execute(
            'mutation($input: DnsRecordCreateInput!) { dnsRecordCreate(input: $input) { name ttl } }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
                'type' => 'TXT', 'data' => array('text' => 'v=spf1 -all')
            )),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array('name' => '@', 'ttl' => 3600), $result['data']['dnsRecordCreate']);
    }

    public function testAnUpdatedRecordReadsBackWithTheNameAndTtlItWasGiven(): void
    {
        // The seeded record is an older row: a bare name and no TTL.
        $result = $this->execute(
            'mutation($id: ID!, $input: DnsRecordUpdateInput!) { dnsRecordUpdate(id: $id, input: $input) { name ttl } }',
            array(
                'id'    => GlobalId::encode(NodeType::DNS_RECORD, $this->fixture->dnsRecordId()),
                'input' => array(
                    'name' => 'mail.' . $this->fixture->domainName() . '.', 'ttl' => 600,
                    'data' => array('address' => '203.0.113.10')
                )
            ),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array('name' => 'mail', 'ttl' => 600), $result['data']['dnsRecordUpdate']);
    }
}
