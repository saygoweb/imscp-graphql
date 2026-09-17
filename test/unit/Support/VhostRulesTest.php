<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class VhostRulesTest extends TestCase
{
    /**
     * A stand-in for utils_normalizePath() over the inputs these tests use:
     * collapse empty and '.' segments, resolve '..', keep the leading slash.
     */
    private function normalise(): callable
    {
        return static function (string $path): string {
            $segments = array();

            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }

                if ($segment === '..') {
                    array_pop($segments);
                    continue;
                }

                $segments[] = $segment;
            }

            return '/' . implode('/', $segments);
        };
    }

    public function testEveryForwardTypeMapsToThePanelsValue(): void
    {
        self::assertSame('301', VhostRules::panelForwardType('PERMANENT_301'));
        self::assertSame('302', VhostRules::panelForwardType('FOUND_302'));
        self::assertSame('303', VhostRules::panelForwardType('SEE_OTHER_303'));
        self::assertSame('307', VhostRules::panelForwardType('TEMPORARY_307'));
        self::assertSame('proxy', VhostRules::panelForwardType('PROXY'));
    }

    public function testTheMappingIsTheReadModelsInverse(): void
    {
        // VirtualHostResolver::FORWARD_TYPES reads the column; this writes it.
        // One is the other turned round, or a round trip changes the value.
        self::assertSame(
            \iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver::FORWARD_TYPES,
            array_flip(VhostRules::PANEL_FORWARD_TYPES)
        );
    }

    public function testAnUnknownForwardTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VhostRules::panelForwardType('301');
    }

    /**
     * @dataProvider labels
     */
    public function testTheReservedLabel(string $label, bool $reserved): void
    {
        self::assertSame($reserved, VhostRules::isReservedLabel($label));
    }

    public function labels(): array
    {
        return array(
            'www'             => array('www', true),
            'www.anything'    => array('www.shop', true),
            'www2'            => array('www2', false),
            'contains www'    => array('shopwww', false),
            'an ordinary one' => array('shop', false)
        );
    }

    /**
     * @dataProvider mountPoints
     */
    public function testSubdomainMountPoints(string $kind, string $label, string $parent, string $expected): void
    {
        self::assertSame($expected, VhostRules::subdomainMountPoint($kind, $label, $parent));
    }

    public function mountPoints(): array
    {
        // gui/public/client/subdomain_add.php:273-288.
        return array(
            'of the main domain'                 => array('dmn', 'shop', 'example.test', '/shop'),
            'a reserved directory, main domain'  => array('dmn', 'logs', 'example.test', '/sub_logs'),
            'backups'                            => array('dmn', 'backups', 'example.test', '/sub_backups'),
            'cgi-bin'                            => array('dmn', 'cgi-bin', 'example.test', '/sub_cgi-bin'),
            'errors'                             => array('dmn', 'errors', 'example.test', '/sub_errors'),
            'phptmp'                             => array('dmn', 'phptmp', 'example.test', '/sub_phptmp'),
            'of an alias'                        => array('als', 'shop', 'alias.test', '/alias.test/shop'),
            'logs is not reserved under an alias' => array('als', 'logs', 'alias.test', '/alias.test/logs'),
            'cgi-bin under an alias'             => array('als', 'cgi-bin', 'alias.test', '/alias.test/sub_cgi-bin')
        );
    }

    public function testASubdomainCannotHangOffASubdomain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VhostRules::subdomainMountPoint('sub', 'x', 'shop.example.test');
    }

    public function testAliasMountPoint(): void
    {
        self::assertSame('/xn--bcher-kva.test', VhostRules::aliasMountPoint('xn--bcher-kva.test'));
    }

    public function testWwwIsStrippedFromAnAliasAsOftenAsItAppears(): void
    {
        // alias_add.php:244: "www is considered as an alias of the domain alias".
        self::assertSame('example.test', VhostRules::stripWww('www.www.example.test'));
        self::assertSame('wwwexample.test', VhostRules::stripWww('wwwexample.test'));
    }

    /**
     * @dataProvider documentRoots
     */
    public function testDocumentRoots(string $input, ?string $expected): void
    {
        self::assertSame($expected, VhostRules::documentRoot($input, $this->normalise()));
    }

    public function documentRoots(): array
    {
        return array(
            'htdocs itself'             => array('/htdocs', '/htdocs'),
            'a directory inside'        => array('/htdocs/public', '/htdocs/public'),
            'untidy but inside'         => array('htdocs//public/./', '/htdocs/public'),
            'dot-dot back inside'       => array('/htdocs/a/../b', '/htdocs/b'),
            'dot-dot out of it'         => array('/htdocs/../etc', null),
            'a sibling of htdocs'       => array('/logs', null),
            'a prefix that is not it'   => array('/htdocsx', null),
            'the root'                  => array('/', null)
        );
    }

    public function testRelativeToHtdocs(): void
    {
        self::assertSame('/', VhostRules::relativeToHtdocs('/htdocs'));
        self::assertSame('/public/app', VhostRules::relativeToHtdocs('/htdocs/public/app'));
    }

    public function testWildcard(): void
    {
        self::assertSame('yes', VhostRules::wildcard(true));
        self::assertSame('no', VhostRules::wildcard(false));
    }

    public function testNoForwarding(): void
    {
        self::assertSame(array('url' => 'no', 'type' => null, 'host' => 'Off'), VhostRules::noForwarding());
    }

    public function testLikeEscape(): void
    {
        // Measurement M16: '_' is legal in a name and a wildcard in LIKE.
        self::assertSame('a\_b.test', VhostRules::likeEscape('a_b.test'));
        self::assertSame('100\%', VhostRules::likeEscape('100%'));
        self::assertSame('back\\\\slash', VhostRules::likeEscape('back\\slash'));
    }
}
