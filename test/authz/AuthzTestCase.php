<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeSqlServer;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;
use iMSCP\Plugin\SGW_GraphQL\Test\Integration\Fixture;
use iMSCP\Plugin\SGW_GraphQL\Test\Integration\IntegrationTestCase;
use Throwable;

/**
 * Runs whole documents against the real schema, the real services and the
 * seeded fixture, as one of the fixture's six accounts.
 *
 * Only the three side effects a rolled-back test must not perform are
 * replaced (decision D12): the daemon and the log (RecordingCore), the
 * FTP-backed directory check, and the SQL server's DDL.
 */
abstract class AuthzTestCase extends IntegrationTestCase
{
    /** @var Db */
    protected $db;

    /** @var Fixture */
    protected $fixture;

    /** @var RecordingCore */
    protected $core;

    /** @var FakeDirectoryProbe */
    protected $probe;

    /** @var FakeSqlServer */
    protected $sqlServer;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->core = new RecordingCore(new PanelCore(false));
        $this->probe = new FakeDirectoryProbe();
        $this->sqlServer = new FakeSqlServer();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * A fresh container per document, as production builds one per request:
     * the batch loader memoises for the container's life.
     */
    protected function schema(): Schema
    {
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
            $this->sqlServer
        )->schemaFactory()->create();
    }

    /**
     * @return array The response envelope, errors formatted as the endpoint
     *               formats them, with debug on so a failing row says why.
     */
    protected function execute(string $document, array $variables, Identity $identity): array
    {
        $result = GraphQL::executeQuery(
            $this->schema(), $document, null, array('identity' => $identity), $variables
        );

        // As Http\GraphQLHandler formats them, path included, so a test
        // reads the same envelope a client does.
        $result->setErrorFormatter(static function (Error $error) {
            $previous = $error->getPrevious();
            $formatted = $previous instanceof Throwable
                ? ErrorFactory::format($previous, true)
                : array('message' => $error->getMessage(), 'extensions' => array('code' => 'GRAPHQL'));

            if ($error->path !== null) {
                $formatted['path'] = $error->path;
            }

            return $formatted;
        });

        return $result->toArray();
    }

    /**
     * 'OK', or the first error's code, or 'NULL' for a null field with no error.
     */
    protected static function outcome(array $result, string $field): string
    {
        if (!empty($result['errors'])) {
            return (string)($result['errors'][0]['extensions']['code'] ?? 'NO_CODE');
        }

        return isset($result['data'][$field]) ? 'OK' : 'NULL';
    }

    protected function skipUnlessInSchema(string $field): void
    {
        $mutation = $this->schema()->getMutationType();

        if ($mutation === null || !$mutation->hasField($field)) {
            self::markTestSkipped($field . ' is not in the schema yet.');
        }
    }

    /**
     * Run one catalogue entry as one account. Named runEntry(), not run():
     * TestCase::run() is PHPUnit's own.
     *
     * @param string[] $scopes
     */
    protected function runEntry(string $field, string $actor, array $scopes = array()): array
    {
        $entry = MutationCatalogue::all()[$field];
        $prepared = call_user_func($entry['prepare'], $this->fixture, $this->db);

        return $this->execute(
            $entry['document'],
            call_user_func($entry['variables'], $this->fixture, $prepared),
            $this->fixture->identity($actor, $scopes)
        );
    }
}
