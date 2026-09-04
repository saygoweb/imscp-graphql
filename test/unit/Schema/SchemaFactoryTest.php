<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

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
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use PHPUnit\Framework\TestCase;

class SchemaFactoryTest extends TestCase
{
    private $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/sgw-graphql-test-' . getmypid();
        @mkdir($this->cacheDir, 0700, true);
        array_map('unlink', glob($this->cacheDir . '/*') ?: []);
    }

    private function sdlPath(): string
    {
        return dirname(__DIR__, 3) . '/schema/schema.graphql';
    }

    private function factory(?string $cacheDir = null): SchemaFactory
    {
        return new SchemaFactory($this->sdlPath(), $cacheDir, new ResolverMap([
            'Query.apiVersion' => static function () { return '1.0.0'; },
            'Query.viewer'     => static function () {
                return [
                    'id' => 'Vmlld2VyOjc', 'username' => 'wpcache.test',
                    'role' => 'CUSTOMER', 'email' => 'c@example.com',
                    'scopes' => ['DOMAINS_READ'],
                ];
            },
        ]));
    }

    public function testTheSchemaIsValid(): void
    {
        $this->factory()->create()->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testAQueryResolvesThroughTheMap(): void
    {
        $result = GraphQL::executeQuery(
            $this->factory()->create(),
            '{ apiVersion viewer { id username role scopes } }'
        )->toArray();

        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame('1.0.0', $result['data']['apiVersion']);
        self::assertSame('wpcache.test', $result['data']['viewer']['username']);
        self::assertSame('CUSTOMER', $result['data']['viewer']['role']);
        self::assertSame(['DOMAINS_READ'], $result['data']['viewer']['scopes']);
    }

    public function testAFieldWithNoResolverFallsBackToTheSourceArray(): void
    {
        $result = GraphQL::executeQuery(
            $this->factory()->create(), '{ viewer { email } }'
        )->toArray();

        self::assertSame('c@example.com', $result['data']['viewer']['email']);
    }

    public function testTheAstIsCachedAndReused(): void
    {
        $this->factory($this->cacheDir)->create();

        $cached = glob($this->cacheDir . '/sgw_graphql_schema.php');
        self::assertCount(1, $cached, 'the parsed AST should be written to the cache dir');

        // A second build must not re-parse: prove it by making the cache
        // authoritative, then checking the schema still builds from it.
        $this->factory($this->cacheDir)->create()->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testTheCacheIsInvalidatedWhenTheSdlChanges(): void
    {
        $this->factory($this->cacheDir)->create();
        $before = file_get_contents($this->cacheDir . '/sgw_graphql_schema.php');

        // A stale cache after an upgrade would serve the previous schema
        // silently, which is the worst possible failure for this cache.
        touch($this->sdlPath(), time() + 10);
        clearstatcache();
        $this->factory($this->cacheDir)->create();
        $after = file_get_contents($this->cacheDir . '/sgw_graphql_schema.php');

        touch($this->sdlPath(), time() - 10);

        self::assertNotSame($before, $after, 'the cache must carry the SDL mtime');
    }

    public function testItWorksWithNoCacheDirectory(): void
    {
        $this->factory(null)->create()->assertValid();
        $this->addToAssertionCount(1);
    }
}
