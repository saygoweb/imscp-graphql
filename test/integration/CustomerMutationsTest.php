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

/**
 * The customer mutations end to end: the document, the schema, the service and
 * the read model, as a reseller's client sees them.
 */
class CustomerMutationsTest extends AuthzTestCase
{
    /**
     * A5: every allowance named, because a create may leave none to a default.
     * A4/D3: traffic, disk and the mail quota are bytes, and a whole number of
     * MiB; the quota fits inside the finite disk limit (A6).
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides = array()): array
    {
        return $overrides + array(
            'username'    => 'sgwtnew.test',
            'password'    => 'N3wCustomer!',
            'domainName'  => 'sgwtnew.test',
            'ipAddressId' => GlobalId::encode(NodeType::IP_ADDRESS, $this->fixture->ipId()),
            'contact'     => array('email' => 'owner@sgwtnew.test', 'firstName' => 'Ada'),
            'allowances'  => array(
                'subdomains' => 2, 'domainAliases' => 1, 'mailAccounts' => 5, 'ftpUsers' => 2,
                'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024 * 1048576,
                'disk' => 512 * 1048576, 'mailQuota' => 128 * 1048576
            )
        );
    }

    public function testANewCustomerReadsBackWithItsDomainAndItsAllowancesInBytes(): void
    {
        $result = $this->execute(
            'mutation($input: CustomerCreateInput!) {
                customerCreate(input: $input) {
                    username
                    contact { firstName email }
                    provisioning { state }
                    domain { name provisioning { state } }
                    quotas { subdomains { enabled limit } ftpUsers { enabled limit } }
                    storage { diskLimit trafficLimit mailQuota }
                }
            }',
            array('input' => $this->input()),
            $this->fixture->identity('reseller')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'username'     => 'sgwtnew.test',
            'contact'      => array('firstName' => 'Ada', 'email' => 'owner@sgwtnew.test'),
            // Provisioning is asynchronous: the account and its domain are
            // scheduled ('toadd'), not built.
            'provisioning' => array('state' => 'PENDING'),
            'domain'       => array(
                'name' => 'sgwtnew.test', 'provisioning' => array('state' => 'PENDING')
            ),
            'quotas'       => array(
                'subdomains' => array('enabled' => true, 'limit' => 2),
                'ftpUsers'   => array('enabled' => true, 'limit' => 2)
            ),
            // D3: bytes out, as bytes went in - the props and the domain row
            // hold disk and traffic in MiB, and neither end of the API says so.
            'storage'      => array(
                'diskLimit'    => (string)(512 * 1048576),
                'trafficLimit' => (string)(1024 * 1048576),
                'mailQuota'    => (string)(128 * 1048576)
            )
        ), $result['data']['customerCreate']);
    }

    public function testASecretIsNeverEchoedInAnError(): void
    {
        // Spec section 11: a Secret is write-only. A validation error names
        // the field, never the value - not in the message, not in the
        // extensions, not anywhere in the envelope.
        $result = $this->execute(
            'mutation($input: CustomerCreateInput!) { customerCreate(input: $input) { id } }',
            array('input' => $this->input(array('password' => 'weak-sekrit'))),
            $this->fixture->identity('reseller')
        );

        self::assertSame('BAD_USER_INPUT', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame('input.password', $result['errors'][0]['extensions']['field']);
        self::assertStringNotContainsString('weak-sekrit', json_encode($result));
        // A mutation field is non-null, so a refused one nulls the whole data.
        self::assertNull($result['data']['customerCreate']);
    }

    public function testAskingForTheStateACustomerIsAlreadyInIsAConflict(): void
    {
        // M12: the panel's two legal transitions are the whole rule, and the
        // fixture's customer is settled and enabled already.
        $result = $this->execute(
            'mutation($id: ID!, $state: AccountState!) { customerSetState(id: $id, state: $state) { id } }',
            array(
                'id'    => GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
                'state' => 'ENABLED'
            ),
            $this->fixture->identity('reseller')
        );

        self::assertSame('CONFLICT', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertNull($result['data']['customerSetState']);
        self::assertSame(
            'ok',
            (string)$this->db->value(
                'SELECT domain_status FROM domain WHERE domain_id = ?', array($this->fixture->domainId())
            )
        );
    }
}
