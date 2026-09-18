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

class MailMutationsTest extends AuthzTestCase
{
    public function testAMailboxReadsBackWithItsQuotaInBytesAndNoPassword(): void
    {
        $result = $this->execute(
            'mutation($input: MailAccountCreateInput!) {
                mailAccountCreate(input: $input) { address kind quota forwardTo active provisioning { state } }
            }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'localPart' => 'info', 'kind' => 'MAILBOX', 'password' => 'Mailb0xPass', 'quota' => '10485760'
            )),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'address'      => 'info@' . $this->fixture->domainName(),
            'kind'         => 'MAILBOX',
            'quota'        => '10485760',
            'forwardTo'    => array(),
            'active'       => true,
            'provisioning' => array('state' => 'PENDING')
        ), $result['data']['mailAccountCreate']);
    }

    public function testASecretIsNeverEchoedInAnError(): void
    {
        // Spec section 11: a Secret is write-only. A validation error names
        // the field, never the value.
        $result = $this->execute(
            'mutation($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id } }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'localPart' => 'info', 'kind' => 'MAILBOX', 'password' => 'weak-sekrit', 'quota' => '10485760'
            )),
            $this->fixture->identity('customer')
        );

        self::assertSame('input.password', $result['errors'][0]['extensions']['field']);
        self::assertStringNotContainsString('weak-sekrit', json_encode($result));
    }

    public function testACatchallReadsBackWithItsAddresses(): void
    {
        $result = $this->execute(
            'mutation($input: MailCatchallCreateInput!) { mailCatchallCreate(input: $input) { address kind forwardTo } }',
            array('input' => array(
                'hostId'    => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'addresses' => array('a@example.net', 'b@example.net')
            )),
            $this->fixture->identity('customer')
        );

        self::assertSame(array(
            'address'   => '@' . $this->fixture->domainName(),
            'kind'      => 'CATCHALL',
            'forwardTo' => array('a@example.net', 'b@example.net')
        ), $result['data']['mailCatchallCreate']);
    }
}
