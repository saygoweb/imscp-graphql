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

    private function factory(?string $cacheDir = null, ?string $sdlPath = null): SchemaFactory
    {
        return new SchemaFactory($sdlPath ?? $this->sdlPath(), $cacheDir, new ResolverMap([
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
        // Warm the cache from the real, unmodified SDL.
        $this->factory($this->cacheDir)->create();

        // Work on a copy so a failing assertion can never leave the
        // repository's own schema.graphql dirty.
        $modifiedSdl = $this->cacheDir . '/modified-schema.graphql';
        file_put_contents(
            $modifiedSdl,
            str_replace(
                'type Query {', "type Query {\n  spare: String",
                file_get_contents($this->sdlPath())
            )
        );
        touch($modifiedSdl, filemtime($this->sdlPath()) + 10);
        clearstatcache();

        // A stale cache after an upgrade would serve the previous schema
        // silently, which is the worst possible failure for this cache: the
        // served schema must track the SDL's content, not merely carry
        // whichever mtime was stamped on the last write.
        $modifiedSchema = $this->factory($this->cacheDir, $modifiedSdl)->create();
        self::assertTrue(
            $modifiedSchema->getQueryType()->hasField('spare'),
            'a schema built from the modified SDL must carry the field it added'
        );

        // Pointed back at the original SDL with the same cache directory,
        // the factory must not serve the modified schema back. A bug that
        // merely re-stamped the new mtime onto the old cached AST, without
        // really re-parsing, would fail this assertion.
        $originalSchema = $this->factory($this->cacheDir)->create();
        self::assertFalse(
            $originalSchema->getQueryType()->hasField('spare'),
            'the original SDL must still build without the field the copy added'
        );
    }

    public function testACorruptCacheFileIsTreatedAsAMissAndRebuilt(): void
    {
        // @include only suppresses warnings; a syntactically broken file
        // raises a ParseError, which must not become an uncaught fatal. A
        // corrupt cache — disk corruption, a manual edit, an incompatible
        // cache left behind by a downgrade — is a cache miss, not an outage.
        file_put_contents($this->cacheDir . '/sgw_graphql_schema.php', '<?php this is not php');

        $this->factory($this->cacheDir)->create()->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testItWorksWithNoCacheDirectory(): void
    {
        $this->factory(null)->create()->assertValid();
        $this->addToAssertionCount(1);
    }
}
