<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Extension;


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

use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionContext;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionRegistry;
use iMSCP\Plugin\SGW_GraphQL\Service\DetachedCore;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeExtension;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which extensions a request's schema carries, driven through the Container
 * as production drives it. Nothing here reaches the database: the resolvers
 * that run are the extensions' own.
 */
class ExtensionLoaderTest extends TestCase
{
    const GREETING = '
        type Greeting { text: String! }
        extend type Query { greeting(name: String!): Greeting! }
    ';

    /** @var RecordingCore */
    private $core;

    protected function setUp(): void
    {
        $this->core = new RecordingCore(new DetachedCore());
    }

    private function container(FakeExtension ...$extensions): Container
    {
        $registry = new ExtensionRegistry();

        foreach ($extensions as $extension) {
            $registry->register($extension);
        }

        return Container::forTesting(
            dirname(__DIR__, 3),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            null, array(), $this->core, null, null, null, null,
            $registry
        );
    }

    private function greeting(string $name = 'Greeter'): FakeExtension
    {
        return new FakeExtension($name, self::GREETING, static function (ExtensionContext $context) {
            return array(
                'Query.greeting' => static function ($source, array $args, $ctx) use ($context) {
                    $identity = $context->requireScope($ctx, Scope::ACCOUNT_READ);

                    return array('text' => 'Hello ' . $args['name'] . ' from ' . $identity->getUsername());
                }
            );
        });
    }

    private function identity(array $scopes = array()): Identity
    {
        return new Identity(7, 'c1', 'user', 2, 'c1@example.test', $scopes, $scopes === array() ? null : 1);
    }

    /** @return string[] */
    private function logged(): array
    {
        return array_map(static function (array $log) {
            self::assertSame(E_USER_WARNING, $log[1], 'A dropped extension must not mail the administrator.');

            return $log[0];
        }, $this->core->logs);
    }

    private function names(Container $container): array
    {
        return array_map(static function ($extension) {
            return $extension->getName();
        }, $container->extensions());
    }

    public function testAnExtensionsFieldResolvesThroughItsOwnResolver(): void
    {
        $container = $this->container($this->greeting());

        $result = GraphQL::executeQuery(
            $container->schemaFactory()->create(),
            '{ greeting(name: "Ann") { text } }',
            null,
            array('identity' => $this->identity())
        )->toArray();

        self::assertSame(array('greeting' => array('text' => 'Hello Ann from c1')), $result['data'] ?? null, json_encode($result));
        self::assertSame(array('Greeter'), $this->names($container));
        self::assertSame(array(), $this->logged());
    }

    public function testTheContextsScopeGateAppliesToAnExtensionsField(): void
    {
        $result = GraphQL::executeQuery(
            $this->container($this->greeting())->schemaFactory()->create(),
            '{ greeting(name: "Ann") { text } }',
            null,
            array('identity' => $this->identity(array(Scope::DOMAINS_READ)))
        );

        self::assertSame('FORBIDDEN', $result->errors[0]->getPrevious()->getErrorCode());
    }

    public function testAnExtensionMayExtendACoreType(): void
    {
        $container = $this->container(new FakeExtension(
            'PhpVersion',
            'type PhpVersion { version: String! }
             extend type Domain { phpVersion: PhpVersion }
             extend type Subdomain { phpVersion: PhpVersion }
             input PhpVersionSetInput { id: ID!, version: String! }
             enum PhpPool { DEFAULT, CLOUDFLARE }
             extend type Mutation { phpVersionSet(input: PhpVersionSetInput!): VirtualHost! }',
            array(
                'Domain.phpVersion'      => static function () { return null; },
                'Subdomain.phpVersion'   => static function () { return null; },
                'Mutation.phpVersionSet' => static function () { return null; }
            )
        ));

        $schema = $container->schemaFactory()->create();

        self::assertSame('PhpVersion', $schema->getType('Domain')->getField('phpVersion')->getType()->name);
        self::assertTrue($schema->getType('Mutation')->hasField('phpVersionSet'));
        // The core's fields are all still there.
        self::assertTrue($schema->getType('Domain')->hasField('customer'));
        self::assertSame(array(), $this->logged());
    }

    public function testNoExtensionLeavesTheSchemaExactlyAsItWas(): void
    {
        $container = $this->container();

        self::assertSame(array(), $container->extensions());
        self::assertSame(
            file_get_contents(dirname(__DIR__, 3) . '/schema/schema.graphql'),
            $container->schemaSdl()
        );
    }

    /**
     * @dataProvider unacceptable
     */
    public function testAnUnacceptableExtensionIsLeftOutAndTheRestKept(FakeExtension $bad, string $reason): void
    {
        $container = $this->container($bad, $this->greeting());

        self::assertSame(array('Greeter'), $this->names($container));
        self::assertTrue($container->schemaFactory()->create()->getType('Query')->hasField('greeting'));

        $logged = $this->logged();
        self::assertCount(1, $logged, implode("\n", $logged));
        self::assertStringContainsString('"Bad" was left out', $logged[0]);
        self::assertStringContainsString($reason, $logged[0]);
    }

    public function unacceptable(): array
    {
        $noop = static function () { return null; };

        return array(
            'SDL that does not parse' => array(
                new FakeExtension('Bad', 'extend type Domain { broken: }'), 'Syntax Error'
            ),
            'an extension of a type that does not exist' => array(
                new FakeExtension('Bad', 'extend type Nowhere { x: Int }'), 'the schema does not build'
            ),
            'a field the core already has' => array(
                new FakeExtension('Bad', 'extend type Domain { name: String }'), 'the schema does not build'
            ),
            'a resolver for a field its SDL does not declare' => array(
                new FakeExtension('Bad', 'extend type Domain { x: Int }', array('Domain.name' => $noop)),
                'which its own SDL does not declare'
            ),
            'a resolver for a field the core serves' => array(
                new FakeExtension('Bad', 'extend type Domain { customer: Customer! }', array('Domain.customer' => $noop)),
                'which is already served'
            ),
            'a resolver that is not callable' => array(
                new FakeExtension('Bad', 'extend type Domain { x: Int }', array('Domain.x' => 'no_such_function_anywhere')),
                'is not callable'
            ),
            'a complexity for a field its SDL does not declare' => array(
                new FakeExtension('Bad', 'extend type Domain { x: Int }', array(), array('Query.customers' => $noop)),
                'which its own SDL does not declare'
            ),
            'a type that implements an interface' => array(
                new FakeExtension('Bad', 'type Cert implements Node { id: ID! }'), 'may not implement an interface'
            ),
            'an extension of a type that adds an interface' => array(
                new FakeExtension('Bad', 'extend type Customer implements Provisioned'), 'may not implement an interface'
            ),
            'an interface' => array(
                new FakeExtension('Bad', 'interface Mine { x: Int }'), 'InterfaceTypeDefinition'
            ),
            'an enum extension' => array(
                new FakeExtension('Bad', 'extend enum Scope { SSL_WRITE }'), 'EnumTypeExtension'
            ),
            'a scalar' => array(
                new FakeExtension('Bad', 'scalar Pem'), 'ScalarTypeDefinition'
            ),
            'a type of the same name as a core type' => array(
                new FakeExtension('Bad', 'type Domain { x: Int }'), 'the schema does not build'
            ),
            'a resolver map that throws' => array(
                new FakeExtension('Bad', 'extend type Domain { x: Int }', static function () {
                    throw new RuntimeException('the table is missing');
                }),
                'the table is missing'
            )
        );
    }

    public function testOfTwoExtensionsThatClashTheLaterIsLeftOut(): void
    {
        $container = $this->container(
            new FakeExtension('First', 'extend type Domain { cache: Boolean }'),
            new FakeExtension('Second', 'extend type Domain { cache: Boolean }'),
            $this->greeting('Third')
        );

        self::assertSame(array('First', 'Third'), $this->names($container));
        self::assertStringContainsString('"Second" was left out', implode("\n", $this->logged()));
    }

    public function testTwoExtensionsMayNotClaimTheSameResolver(): void
    {
        $noop = static function () { return null; };
        $container = $this->container(
            new FakeExtension('First', 'extend type Domain { cache: Boolean }', array('Domain.cache' => $noop)),
            new FakeExtension('Second', 'extend type Subdomain { cache: Boolean }', array('Domain.cache' => $noop))
        );

        self::assertSame(array('First'), $this->names($container));
    }

    public function testWhenEveryExtensionIsLeftOutTheCoreSchemaStillServes(): void
    {
        $container = $this->container(new FakeExtension('Bad', 'extend type Nowhere { x: Int }'));

        self::assertSame(array(), $container->extensions());
        self::assertTrue($container->schemaFactory()->create()->getType('Query')->hasField('customers'));
    }

    public function testAnExtensionsComplexityIsCharged(): void
    {
        $container = $this->container(new FakeExtension(
            'Certs',
            'type Cert { name: String! }
             extend type Domain { certificates: [Cert!]! }',
            array('Domain.certificates' => static function () { return array(); }),
            array('Domain.certificates' => static function (int $child) { return 40 * $child; })
        ));

        $field = $container->schemaFactory()->create()->getType('Domain')->getField('certificates');

        self::assertSame(80, call_user_func($field->complexityFn, 2, array()));
    }

    public function testTheResolverMapsNameEachExtension(): void
    {
        $maps = $this->container($this->greeting())->resolverMaps();

        self::assertArrayHasKey('Extension:Greeter', $maps);
        self::assertArrayHasKey('Query.greeting', $maps['Extension:Greeter']);
        self::assertArrayHasKey('QueryResolver', $maps);
    }

    public function testTheServedSdlCarriesEachExtensionUnderItsName(): void
    {
        $sdl = $this->container($this->greeting())->schemaSdl();

        self::assertStringStartsWith(
            rtrim(file_get_contents(dirname(__DIR__, 3) . '/schema/schema.graphql'), "\n"),
            $sdl
        );
        self::assertStringContainsString("# Extension: Greeter\n\ntype Greeting { text: String! }", $sdl);
    }

    public function testEveryObjectFieldAnExtensionAddsIsAnObjectTypeTheSchemaKnows(): void
    {
        // The executable schema, not just the printed SDL, carries the
        // extension's own types.
        $schema = $this->container($this->greeting())->schemaFactory()->create();

        self::assertInstanceOf(ObjectType::class, $schema->getType('Greeting'));
    }
}
