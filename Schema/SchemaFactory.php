<?php
namespace iMSCP\Plugin\SGW_GraphQL\Schema;

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

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use RuntimeException;

/**
 * Builds the executable schema from the SDL, with the parsed AST cached to
 * disk so that BuildSchema does not re-parse on every request.
 *
 * The cache carries the SDL's mtime: a stale cache after an upgrade would
 * serve the previous schema silently, which is the worst failure this cache
 * could have.
 */
final class SchemaFactory
{
    const CACHE_FILE = 'sgw_graphql_schema.php';

    /** @var string */
    private $sdlPath;

    /** @var string|null */
    private $cacheDir;

    /** @var ResolverMap */
    private $resolvers;

    /** @var callable|null */
    private $resolveType;

    /**
     * @param callable|null $resolveType fn($value, $context, ResolveInfo): string
     *                                   Attached to every interface in the SDL.
     *                                   Optional so that plan 1's three-argument
     *                                   construction still builds the viewer
     *                                   slice, which has no interfaces.
     */
    public function __construct(
        string $sdlPath, ?string $cacheDir, ResolverMap $resolvers,
        ?callable $resolveType = null
    ) {
        $this->sdlPath = $sdlPath;
        $this->cacheDir = $cacheDir;
        $this->resolvers = $resolvers;
        $this->resolveType = $resolveType;
    }

    public function create(): Schema
    {
        $resolvers = $this->resolvers;
        $resolveType = $this->resolveType;

        return BuildSchema::build(
            $this->document(),
            static function (array $typeConfig, $typeDefinitionNode) use (
                $resolvers, $resolveType
            ) {
                $typeName = $typeConfig['name'];

                // Node, Provisioned and VirtualHost. Without this, the first
                // query selecting an interface field fails with "abstract type
                // must resolve to an Object type at runtime" - and it fails at
                // execution, not at build, so a schema test alone would not
                // catch it.
                if ($resolveType !== null
                    && $typeDefinitionNode instanceof InterfaceTypeDefinitionNode
                ) {
                    $typeConfig['resolveType'] = $resolveType;
                }

                $typeConfig['resolveField'] = static function (
                    $source, $args, $context, ResolveInfo $info
                ) use ($resolvers, $typeName) {
                    $resolver = $resolvers->for($typeName, $info->fieldName);

                    if ($resolver !== null) {
                        return $resolver($source, $args, $context, $info);
                    }

                    // Plain data fields read straight off the source.
                    if (is_array($source)) {
                        return $source[$info->fieldName] ?? null;
                    }

                    if (is_object($source) && isset($source->{$info->fieldName})) {
                        return $source->{$info->fieldName};
                    }

                    return null;
                };

                return $typeConfig;
            }
        );
    }

    private function document(): DocumentNode
    {
        if (!@is_readable($this->sdlPath)) {
            throw new RuntimeException(sprintf(
                'The schema file %s is missing or unreadable.', $this->sdlPath
            ));
        }

        $mtime = filemtime($this->sdlPath);

        if ($this->cacheDir === null) {
            return Parser::parse(file_get_contents($this->sdlPath), array('noLocation' => true));
        }

        $cacheFile = rtrim($this->cacheDir, '/') . '/' . self::CACHE_FILE;

        if (@is_readable($cacheFile)) {
            // @include only suppresses warnings; a syntactically broken cache
            // file raises a ParseError, which would otherwise be an uncaught
            // fatal. A corrupt cache must be a cache miss, not an outage — the
            // fallthrough below re-parses the SDL and overwrites the file.
            try {
                $cached = @include $cacheFile;
            } catch (\Throwable $e) {
                $cached = null;
            }

            if (is_array($cached)
                && isset($cached['mtime'], $cached['ast'])
                && $cached['mtime'] === $mtime
            ) {
                return AST::fromArray($cached['ast']);
            }
        }

        $document = Parser::parse(
            file_get_contents($this->sdlPath), array('noLocation' => true)
        );

        $this->writeCache($cacheFile, $mtime, AST::toArray($document));

        return $document;
    }

    private function writeCache(string $cacheFile, int $mtime, array $ast): void
    {
        if (!@is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0750, true)
            && !@is_dir($this->cacheDir)
        ) {
            return;   // an uncacheable schema is slow, not broken
        }

        $payload = "<?php\nreturn " . var_export(
            array('mtime' => $mtime, 'ast' => $ast), true
        ) . ";\n";

        // Write and rename, so a concurrent request never reads a half-written
        // cache and rebuilds the schema from a truncated AST.
        $temp = $cacheFile . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temp, $payload, LOCK_EX) !== false) {
            @rename($temp, $cacheFile);
        }
    }
}
