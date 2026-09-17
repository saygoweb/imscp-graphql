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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

/**
 * What only a whole document shows: the returned object, the serial and
 * separate commits of spec section 8.5, decision D18's loader reset and
 * decision D19's scope rule. The authorisation matrix owns who may act.
 */
class VirtualHostMutationsTest extends AuthzTestCase
{
    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    public function testACreatedSubdomainReadsBackPending(): void
    {
        $result = $this->execute(
            'mutation($input: SubdomainCreateInput!) {
                subdomainCreate(input: $input) {
                    name label mountPoint documentRoot wildcard
                    provisioning { state raw settled }
                }
            }',
            array('input' => array('parentId' => $this->domainId(), 'label' => 'blog2', 'wildcard' => true)),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'name'         => 'blog2.' . $this->fixture->domainName(),
            'label'        => 'blog2',
            'mountPoint'   => '/blog2',
            'documentRoot' => '/htdocs',
            'wildcard'     => true,
            'provisioning' => array('state' => 'PENDING', 'raw' => 'toadd', 'settled' => false)
        ), $result['data']['subdomainCreate']);
        self::assertSame(1, $this->core->requests);
    }

    public function testAMutationsObjectIsReadAfterItsWriteNotFromAnEarlierLoad(): void
    {
        // Decision D18. The first field loads the domain's subdomain list into
        // the batch loader; without the reset, the second field's identical
        // edge would be answered from that memo and miss 'second'.
        $result = $this->execute(
            'mutation($a: SubdomainCreateInput!, $b: SubdomainCreateInput!) {
                first: subdomainCreate(input: $a) { parent { ... on Domain { subdomains { label } } } }
                second: subdomainCreate(input: $b) { parent { ... on Domain { subdomains { label } } } }
            }',
            array(
                'a' => array('parentId' => $this->domainId(), 'label' => 'first'),
                'b' => array('parentId' => $this->domainId(), 'label' => 'second')
            ),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        $labels = array_column($result['data']['second']['parent']['subdomains'], 'label');
        sort($labels);
        self::assertSame(array('first', 'second', 'shop'), $labels);
    }

    public function testEachMutationInADocumentCommitsOnItsOwn(): void
    {
        // Spec section 8.5: the first is done even though the second fails -
        // but 'done' is a database fact, not something this response can show.
        // Both fields return Subdomain! (non-null), and second's field error
        // has no nullable ancestor to stop at, so it propagates all the way to
        // the response root and nulls the whole of `data` - first's own,
        // already-committed result included. Only the database row and the
        // error's own path prove first ran at all.
        $result = $this->execute(
            'mutation($a: SubdomainCreateInput!, $b: SubdomainCreateInput!) {
                first: subdomainCreate(input: $a) { label }
                second: subdomainCreate(input: $b) { label }
            }',
            array(
                'a' => array('parentId' => $this->domainId(), 'label' => 'once'),
                'b' => array('parentId' => $this->domainId(), 'label' => 'once')
            ),
            $this->fixture->identity('customer')
        );

        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM subdomain WHERE domain_id = ? AND subdomain_name = 'once'",
            array($this->fixture->domainId())
        ));
        self::assertNull($result['data'] ?? null);
        self::assertSame('CONFLICT', $result['errors'][0]['extensions']['code']);
        self::assertSame(array('second'), $result['errors'][0]['path']);
    }

    public function testTheWriteScopeAdmitsTheObjectButNotItsEdges(): void
    {
        // Decision D19. Subdomain.customer is Customer!, so its FORBIDDEN nulls
        // the returned object in the response - the write itself happened.
        $result = $this->execute(
            'mutation($input: SubdomainCreateInput!) { subdomainCreate(input: $input) { label customer { username } } }',
            array('input' => array('parentId' => $this->domainId(), 'label' => 'scoped')),
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );

        self::assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code']);
        self::assertSame(array('subdomainCreate', 'customer'), $result['errors'][0]['path']);
        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM subdomain WHERE domain_id = ? AND subdomain_name = 'scoped'",
            array($this->fixture->domainId())
        ));
    }

    public function testACustomersAliasReadsBackOrdered(): void
    {
        $result = $this->execute(
            'mutation($input: DomainAliasCreateInput!) { domainAliasCreate(input: $input) { name provisioning { state settled } } }',
            array('input' => array('domainId' => $this->domainId(), 'name' => 'sgwtnew.test')),
            $this->fixture->identity('customer')
        );

        self::assertSame(
            array('name' => 'sgwtnew.test', 'provisioning' => array('state' => 'ORDERED', 'settled' => true)),
            $result['data']['domainAliasCreate']
        );
    }

    public function testAWithdrawnOrderReturnsTheAliasAsItWas(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $result = $this->execute(
            'mutation($id: ID!) { domainAliasDelete(id: $id) { id name provisioning { state } } }',
            array('id' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'id'           => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
            'name'         => $this->fixture->aliasName(),
            'provisioning' => array('state' => 'ORDERED')
        ), $result['data']['domainAliasDelete']);
    }

    public function testAnUnsettledObjectsConflictCarriesTheRetryHint(): void
    {
        $result = $this->execute(
            'mutation($id: ID!) { subdomainDelete(id: $id) { id } }',
            array('id' => GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId())),
            $this->fixture->identity('customer')
        );

        self::assertSame(
            array('state' => 'PENDING', 'retryAfterSeconds' => 5, 'code' => 'CONFLICT'),
            $result['errors'][0]['extensions']
        );
    }
}
