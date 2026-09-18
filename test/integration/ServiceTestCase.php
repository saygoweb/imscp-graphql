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

use iMSCP\Plugin\SGW_GraphQL\Auth\AccessService;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\TokenService;
use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\RecordingCore;

/**
 * A service against the seeded fixture, with the panel's real validators and
 * the three side effects recorded instead of performed (decision D12).
 */
abstract class ServiceTestCase extends IntegrationTestCase
{
    /** @var Db */
    protected $db;

    /** @var Fixture */
    protected $fixture;

    /** @var RecordingCore */
    protected $core;

    /** @var FakeDirectoryProbe */
    protected $probe;

    /** @var Toolkit */
    protected $kit;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->probe = new FakeDirectoryProbe();
        $this->reconfigure(array());
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * The panel settings the services read, stated rather than inherited from
     * the box (measurement M17 records the box's own values, which match).
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return array(
            'CREATE_DEFAULT_EMAIL_ADDRESSES'  => 1,
            'PROTECT_DEFAULT_EMAIL_ADDRESSES' => 1,
            'SERVER_HOSTNAME'                 => 'imscp.docker.local',
            'MYSQL_PREFIX'                    => 'none',
            'DATABASE_USER_HOST'              => 'localhost',
            'USER_WEB_DIR'                    => '/var/www/virtual',
            'NAMED_PACKAGE'                   => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES'          => 'Awstats',
            'BACKUP_DOMAINS'                  => 'yes',
            'ENABLE_SSL'                      => 1,
            'IMSCP_SUPPORT_SYSTEM'            => 1,
            'HARD_MAIL_SUSPENSION'            => 1
        );
    }

    /**
     * Rebuild the core and the toolkit with some settings changed. Called by
     * setUp() with none; a test that depends on a setting calls it again.
     *
     * @param array<string, mixed> $overrides
     */
    protected function reconfigure(array $overrides): void
    {
        $db = $this->db;
        $this->core = new RecordingCore(new PanelCore(false), array_merge($this->config(), $overrides));
        $query = static function (string $sql, array $bind = array()) use ($db) {
            return $db->rows($sql, $bind);
        };
        $this->kit = new Toolkit(
            $db,
            $this->core,
            new Guard(new OwnershipResolver($query)),
            new Accounts($db),
            new VirtualHosts($db),
            new Counts($db, true),
            new Writer($db),
            $this->probe,
            new AccessService(
                static function (string $sql, array $bind = array()) use ($db) {
                    return $db->rows($sql, $bind);
                },
                TokenService::fromPanel(),
                true
            )
        );
    }

    /**
     * @param string $who customer|sibling|otherCustomer|reseller|otherReseller|admin
     */
    protected function caller(string $who, array $scopes = array()): Identity
    {
        return $this->fixture->identity($who, $scopes);
    }

    /**
     * Assert that $fn is refused with $code, and hand back the exception for
     * any further assertion on its extensions.
     */
    protected function refused(string $code, callable $fn): ApiException
    {
        try {
            $fn();
        } catch (ApiException $e) {
            self::assertSame(
                $code, $e->getErrorCode(),
                $e->getMessage() . ' ' . json_encode($e->getExtensions())
            );

            return $e;
        }

        self::fail('Expected ' . $code . ', and the call succeeded.');
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function insert(string $table, array $row): int
    {
        $columns = array_keys($row);

        $this->db->execute(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES ('
                . $this->db->placeholders(count($columns)) . ')',
            array_values($row)
        );

        return $this->db->lastInsertId();
    }
}
