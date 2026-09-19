<?php
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

/**
 * Exports the schema a client builds against: schema/schema.graphql as the
 * server actually builds it, printed canonically to
 * test/schema/schema.printed.graphql.
 *
 * Two audiences, one file. A consumer of this plugin - the admin frontend's
 * codegen, or any other client generator - wants the effective schema rather
 * than the SDL source, whose ordering and '#' comments are ours and not the
 * API's. And test/schema/SchemaTest compares the same bytes, so a schema
 * change is always a visible diff in review (docs/SPECIFICATION.md section
 * 17). One file for both is the point: a second copy is the drift this
 * script exists to prevent.
 *
 * The file is written here rather than by a shell redirection, because the
 * redirect creates it as whoever ran the command - which on a bind-mounted
 * checkout is how a root-owned snapshot ends up in a working tree.
 *
 * Usage, inside the box or container, which is where PHP 7.4 and the
 * mbstring extension the parser needs are:
 *
 *   php7.4 /var/www/imscp/gui/bin/composer.phar schema
 *   php7.4 /var/www/imscp/gui/bin/composer.phar schema:check
 *
 * or directly:
 *
 *   php7.4 tools/export-schema.php [--check|--stdout]
 *
 *   (no option)  rewrite the snapshot, and say whether it changed
 *   --check      exit 1 if the snapshot is stale, writing nothing
 *   --stdout     print the schema instead, for piping into a diff
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use GraphQL\Utils\SchemaPrinter;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;

$root = dirname(__DIR__);
$target = $root . '/test/schema/schema.printed.graphql';
$mode = isset($argv[1]) ? $argv[1] : '';

if (!in_array($mode, array('', '--check', '--stdout'), true)) {
    fwrite(STDERR, "usage: php7.4 tools/export-schema.php [--check|--stdout]\n");
    exit(2);
}

// No cache directory: an export must read the SDL it is exporting, not
// whatever a previous run left in the cache.
$printed = SchemaPrinter::doPrint((new SchemaFactory(
    $root . '/schema/schema.graphql',
    null,
    new ResolverMap(array()),
    array(TypeResolver::class, 'resolveType')
))->create());

if ($mode === '--stdout') {
    echo $printed;
    exit(0);
}

$current = is_file($target) ? file_get_contents($target) : null;

if ($mode === '--check') {
    if ($current === $printed) {
        echo "test/schema/schema.printed.graphql is up to date.\n";
        exit(0);
    }

    fwrite(STDERR, "test/schema/schema.printed.graphql is stale.\n"
        . "Regenerate it with: php7.4 /var/www/imscp/gui/bin/composer.phar schema\n");
    exit(1);
}

if (file_put_contents($target, $printed) === false) {
    fwrite(STDERR, "Could not write $target\n");
    exit(1);
}

echo 'Wrote test/schema/schema.printed.graphql (' . strlen($printed) . ' bytes), '
    . ($current === $printed ? "unchanged.\n" : "changed - review the diff.\n");
