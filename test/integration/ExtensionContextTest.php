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

use GraphQL\Type\Schema;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionContext;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionRegistry;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeExtension;

/**
 * An extension shaped like the plugins beside this one - a setting per vhost,
 * keyed on the panel's (domain_type, domain_id), changed by a mutation that
 * marks it for the daemon - against the seeded fixture.
 *
 * What it proves is ExtensionContext's side of the bargain: an extension that
 * uses targetVirtualHost() gets the same NOT_FOUND-then-FORBIDDEN answers as
 * this plugin's own mutations, for the same six accounts.
 */
class ExtensionContextTest extends AuthzTestCase
{
    const SDL = '
        type SiteSetting { kind: String!, key: Int!, domainId: Int!, ownerId: Int! }
        extend type Domain { siteSetting: SiteSetting! }
        extend type Subdomain { siteSetting: SiteSetting! }
        extend type DomainAlias { siteSetting: SiteSetting! }
        extend type Mutation { siteSettingTouch(id: ID!): VirtualHost! }
    ';

    protected function schema(): Schema
    {
        $read = static function (ExtensionContext $context) {
            return static function ($source, array $args, $ctx) use ($context) {
                $context->requireScope($ctx, Scope::DOMAINS_READ);
                $vhost = $context->virtualHost($source);

                return array(
                    'kind'     => $vhost->getKind(),
                    'key'      => $vhost->getKey(),
                    'domainId' => $vhost->getDomainId(),
                    'ownerId'  => $vhost->getOwnerId()
                );
            };
        };

        $registry = new ExtensionRegistry();
        $registry->register(new FakeExtension('SiteSettings', self::SDL, static function (ExtensionContext $context) use ($read) {
            return array(
                'Domain.siteSetting'      => $read($context),
                'Subdomain.siteSetting'   => $read($context),
                'DomainAlias.siteSetting' => $read($context),
                'Mutation.siteSettingTouch' => static function ($source, array $args, $ctx) use ($context) {
                    $vhost = $context->targetVirtualHost(
                        $context->identity($ctx), $args['id'], Scope::DOMAINS_WRITE
                    );
                    Guard::requireState((string)$vhost->getStatus(), array(Provisioning::STATE_OK));

                    $context->core()->sendRequest();
                    $context->loader()->reset();

                    return $context->virtualHostReference($vhost);
                }
            );
        }));

        return Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db,
            (array)\iMSCP\Registry::get('config'),
            $this->core,
            $this->probe,
            $this->sqlServer,
            null,
            null,
            $registry
        )->schemaFactory()->create();
    }

    private function read(string $tag, int $key): array
    {
        $result = $this->execute(
            'query($id: ID!) { node(id: $id) {
                ... on Domain { siteSetting { kind key domainId ownerId } }
                ... on Subdomain { siteSetting { kind key domainId ownerId } }
                ... on DomainAlias { siteSetting { kind key domainId ownerId } }
            } }',
            array('id' => GlobalId::encode($tag, $key)),
            $this->fixture->identity('customer')
        );

        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        return $result['data']['node']['siteSetting'];
    }

    private function touch(string $tag, int $key, string $who, array $scopes = array()): array
    {
        return $this->execute(
            'mutation($id: ID!) { siteSettingTouch(id: $id) { name } }',
            array('id' => GlobalId::encode($tag, $key)),
            $this->fixture->identity($who, $scopes)
        );
    }

    public function testAFieldOnEachKindOfVhostSeesThePanelsOwnKey(): void
    {
        $domainId = $this->fixture->domainId();
        $owner = $this->fixture->customerId();

        self::assertSame(
            array('kind' => 'dmn', 'key' => $domainId, 'domainId' => $domainId, 'ownerId' => $owner),
            $this->read(NodeType::DOMAIN, $domainId)
        );
        self::assertSame(
            array('kind' => 'sub', 'key' => $this->fixture->subdomainId(), 'domainId' => $domainId, 'ownerId' => $owner),
            $this->read(NodeType::SUBDOMAIN, $this->fixture->subdomainId())
        );
        self::assertSame(
            array('kind' => 'als', 'key' => $this->fixture->aliasId(), 'domainId' => $domainId, 'ownerId' => $owner),
            $this->read(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
        );
        self::assertSame(
            array('kind' => 'alssub', 'key' => $this->fixture->aliasSubdomainId(), 'domainId' => $domainId, 'ownerId' => $owner),
            $this->read(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId())
        );
    }

    public function testAFieldAsksForItsScope(): void
    {
        $result = $this->execute(
            'query($id: ID!) { node(id: $id) { ... on Domain { siteSetting { kind } } } }',
            array('id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId())),
            $this->fixture->identity('customer', array(Scope::DOMAINS_READ))
        );
        self::assertArrayNotHasKey('errors', $result, json_encode($result));

        $result = $this->execute(
            'query($id: ID!) { node(id: $id) { ... on Domain { siteSetting { kind } } } }',
            array('id' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId())),
            // DOMAINS_WRITE admits the object (decision D19) but not this
            // field, which asked for DOMAINS_READ.
            $this->fixture->identity('customer', array(Scope::DOMAINS_WRITE))
        );
        self::assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'], json_encode($result));
    }

    /**
     * @dataProvider mayTouch
     */
    public function testTheOwnerTheirResellerAndTheAdministratorMayWrite(string $who): void
    {
        $result = $this->touch(NodeType::SUBDOMAIN, $this->fixture->subdomainId(), $who);

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame($this->fixture->subdomainName(), $result['data']['siteSettingTouch']['name']);
        self::assertSame(1, $this->core->requests);
    }

    public function mayTouch(): array
    {
        return array('customer' => array('customer'), 'reseller' => array('reseller'), 'admin' => array('admin'));
    }

    /**
     * @dataProvider mayNotTouch
     */
    public function testAnybodyElseIsToldTheVhostDoesNotExist(string $who): void
    {
        $result = $this->touch(NodeType::DOMAIN, $this->fixture->domainId(), $who);

        self::assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame('id', $result['errors'][0]['extensions']['field']);
        self::assertSame(0, $this->core->requests);
    }

    public function mayNotTouch(): array
    {
        return array(
            'sibling'       => array('sibling'),
            'otherCustomer' => array('otherCustomer'),
            'otherReseller' => array('otherReseller')
        );
    }

    public function testAnIdentifierOfAnotherKindIsNotFound(): void
    {
        $result = $this->touch(NodeType::CUSTOMER, $this->fixture->customerId(), 'customer');

        self::assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'], json_encode($result));
    }

    public function testOwnershipIsDecidedBeforeScope(): void
    {
        // A read-only token learns nothing about somebody else's vhost that a
        // full one would not: NOT_FOUND, not FORBIDDEN.
        $result = $this->touch(NodeType::DOMAIN, $this->fixture->siblingDomainId(), 'customer', array(Scope::DOMAINS_READ));
        self::assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'], json_encode($result));

        $result = $this->touch(NodeType::DOMAIN, $this->fixture->domainId(), 'customer', array(Scope::DOMAINS_READ));
        self::assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame(0, $this->core->requests);
    }

    public function testTheStatusIsReadAtTheMomentOfTheWrite(): void
    {
        // The fixture's alias subdomain is still 'toadd'.
        $result = $this->touch(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId(), 'customer');

        self::assertSame('CONFLICT', $result['errors'][0]['extensions']['code'], json_encode($result));
        self::assertSame(0, $this->core->requests);
    }
}
