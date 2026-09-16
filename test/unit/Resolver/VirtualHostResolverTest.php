<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class VirtualHostResolverTest extends TestCase
{
    /**
     * A normalised row exactly as Repository\VirtualHosts produces one.
     */
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'tag'          => NodeType::DOMAIN,
            'kind'         => VirtualHosts::KIND_DMN,
            'key'          => 12,
            'domainId'     => 12,
            'ownerId'      => 7,
            'name'         => 'xn--bcher-kva.test',
            'label'        => null,
            'parentTag'    => null,
            'parentKey'    => null,
            'mountPoint'   => '/',
            'documentRoot' => '/htdocs',
            'urlForward'   => 'no',
            'typeForward'  => null,
            'hostForward'  => 'Off',
            'wildcard'     => false,
            'status'       => 'ok',
            'ipId'         => 3
        ), $overrides);
    }

    private function toUnicode(): callable
    {
        return static function (string $name) {
            return $name === 'xn--bcher-kva.test' ? 'bücher.test' : $name;
        };
    }

    public function testANameIsReturnedAsUnicode(): void
    {
        // Spec section 7.1: DomainName is "accepted as Unicode or as punycode;
        // always returned as Unicode". i-MSCP stores punycode, so a resolver
        // that passed the column through would return the wrong string for
        // every internationalised domain on the box.
        $shape = VirtualHostResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('bücher.test', $shape['name']);
    }

    public function testTheIdentifierCarriesTheTagNotTheGraphqlType(): void
    {
        // Decision D1. subdomain 3 and subdomain_alias 3 belong to different
        // customers; if both encoded as Subdomain:3, one customer's identifier
        // would address the other's row.
        $shape = VirtualHostResolver::shape($this->row(array(
            'tag' => NodeType::ALIAS_SUBDOMAIN, 'kind' => VirtualHosts::KIND_ALSSUB,
            'key' => 3, 'label' => 'blog', 'parentTag' => NodeType::DOMAIN_ALIAS,
            'parentKey' => 4
        )), $this->toUnicode());

        self::assertSame(
            GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, 3), $shape['id']
        );
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $shape[TypeResolver::TAG]);
        self::assertNotSame(GlobalId::encode(NodeType::SUBDOMAIN, 3), $shape['id']);
    }

    public function testNoForwardingWhenTheColumnHoldsTheStringNo(): void
    {
        // i-MSCP stores the literal 'no' rather than NULL. Returning it as a
        // Forwarding would tell every client that every unforwarded host
        // redirects to a URL called "no".
        self::assertNull(VirtualHostResolver::forwarding($this->row()));
    }

    public function testNoForwardingWhenTheColumnIsEmpty(): void
    {
        self::assertNull(
            VirtualHostResolver::forwarding($this->row(array('urlForward' => '')))
        );
    }

    /**
     * @dataProvider forwardTypes
     */
    public function testEveryForwardTypeMaps(string $stored, string $expected): void
    {
        $forwarding = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => $stored
        )));

        self::assertSame('https://example.net/', $forwarding['url']);
        self::assertSame($expected, $forwarding['type']);
    }

    public function forwardTypes(): array
    {
        return array(
            '301'   => array('301', 'PERMANENT_301'),
            '302'   => array('302', 'FOUND_302'),
            '303'   => array('303', 'SEE_OTHER_303'),
            '307'   => array('307', 'TEMPORARY_307'),
            'proxy' => array('proxy', 'PROXY')
        );
    }

    public function testKeepHostIsTrueOnlyWhenTheColumnSaysOn(): void
    {
        $on = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => 'proxy',
            'hostForward' => 'On'
        )));
        $off = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => 'proxy',
            'hostForward' => 'Off'
        )));

        self::assertTrue($on['keepHost']);
        self::assertFalse($off['keepHost']);
    }

    public function testAForwardWithNoTypeDefaultsToAPermanentRedirect(): void
    {
        // type_forward is nullable and url_forward is not, so a row can carry
        // a URL and no type. The panel's own edit form defaults the radio to
        // 301, so this agrees with it rather than nulling a non-null field.
        $forwarding = VirtualHostResolver::forwarding($this->row(array(
            'urlForward' => 'https://example.net/', 'typeForward' => null
        )));

        self::assertSame('PERMANENT_301', $forwarding['type']);
    }

    public function testTheShapeCarriesTheOwnerAndTheDomainForItsEdges(): void
    {
        // Without these every edge below a vhost would have to re-resolve
        // ownership, which is one query per row.
        $shape = VirtualHostResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(7, $shape['__ownerId']);
        self::assertSame(12, $shape['__domainId']);
        self::assertSame(3, $shape['__ipId']);
        self::assertSame(VirtualHosts::KIND_DMN, $shape['__kind']);
    }

    public function testASubdomainCarriesItsLabelAndItsParent(): void
    {
        $shape = VirtualHostResolver::shape($this->row(array(
            'tag' => NodeType::SUBDOMAIN, 'kind' => VirtualHosts::KIND_SUB,
            'key' => 5, 'name' => 'shop.bücher.test', 'label' => 'shop',
            'parentTag' => NodeType::DOMAIN, 'parentKey' => 12, 'ipId' => null
        )), $this->toUnicode());

        self::assertSame('shop', $shape['label']);
        self::assertSame(NodeType::DOMAIN, $shape['__parentTag']);
        self::assertSame(12, $shape['__parentKey']);
        self::assertNull($shape['__ipId']);
    }

    public function testProvisioningComesFromTheStatusColumn(): void
    {
        $shape = VirtualHostResolver::shape(
            $this->row(array('status' => 'toadd')), $this->toUnicode()
        );

        self::assertSame('PENDING', $shape['provisioning']['state']);
        self::assertSame('toadd', $shape['provisioning']['raw']);
        self::assertFalse($shape['provisioning']['settled']);
    }

    public function testTheMapCoversEveryEdgeOfAllThreeTypes(): void
    {
        // The failure this catches: an edge added to the SDL and not to the
        // map, which would fall back to reading a key off the source array,
        // find nothing and return null - silently, for a non-null field, so
        // the whole object nulls.
        $resolver = new VirtualHostResolver(
            new \iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader(
                \iMSCP\Plugin\SGW_GraphQL\Repository\Db::detached()
            ),
            new VirtualHosts(\iMSCP\Plugin\SGW_GraphQL\Repository\Db::detached()),
            \iMSCP\Plugin\SGW_GraphQL\Repository\Db::detached(),
            $this->toUnicode(),
            static function (int $adminId) { return null; },
            static function (int $ipId) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        // In this order, and it is worth knowing why: sort() is byte order,
        // '.' is 0x2E and 'A' is 0x41, so every 'Domain.' key sorts before
        // every 'DomainAlias.' key.
        self::assertSame(array(
            'Domain.aliases', 'Domain.createdAt', 'Domain.customer',
            'Domain.expiresAt', 'Domain.ipAddress', 'Domain.subdomains',
            'DomainAlias.customer', 'DomainAlias.domain', 'DomainAlias.subdomains',
            'Subdomain.customer', 'Subdomain.parent'
        ), $keys);
    }
}
