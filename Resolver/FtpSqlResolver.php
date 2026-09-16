<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * FtpUser, SqlDatabase and SqlUser - spec section 7.7.
 *
 * Two things here differ from every other type in the schema. ftp_users is
 * keyed by a string (decision D2), so its identifiers go through
 * GlobalId::encodeKey(); and neither SQL type is Provisioned, because the
 * panel creates both synchronously and there is no status column to report.
 */
final class FtpSqlResolver
{
    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    public function __construct(Db $db, BatchLoader $loader, callable $customerRef)
    {
        $this->db = $db;
        $this->loader = $loader;
        $this->customerRef = $customerRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Customer.ftpUsers'     => array($this, 'resolveCustomerFtpUsers'),
            'Customer.sqlDatabases' => array($this, 'resolveCustomerDatabases'),
            'Customer.sqlUsers'     => array($this, 'resolveCustomerSqlUsers'),
            'FtpUser.customer'      => array($this, 'resolveOwner'),
            'SqlDatabase.customer'  => array($this, 'resolveOwner'),
            'SqlDatabase.users'     => array($this, 'resolveDatabaseUsers'),
            'SqlUser.databases'     => array($this, 'resolveSqlUserDatabases')
        );
    }

    /**
     * @param array<string, mixed> $row A row of ftp_users
     * @return array<string, mixed>
     */
    public static function shapeFtp(array $row): array
    {
        // NodeType::isStringKeyed(FTP_USER) is true, so TypeResolver::node()
        // reaches GlobalId::encodeKey() rather than encode().
        return TypeResolver::node(NodeType::FTP_USER, (string)$row['userid'], array(
            'username'      => (string)$row['userid'],
            'homeDirectory' => (string)$row['homedir'],
            'provisioning'  => TypeResolver::provisioning(
                $row['status'] === null ? null : (string)$row['status']
            ),
            '__ownerId'     => (int)$row['admin_id']
        ));
    }

    /**
     * @param array<string, mixed> $row A row of sql_database joined to domain
     * @return array<string, mixed>
     */
    public static function shapeDatabase(array $row): array
    {
        return TypeResolver::node(NodeType::SQL_DATABASE, (int)$row['sqld_id'], array(
            'name'       => (string)$row['sqld_name'],
            '__domainId' => (int)$row['domain_id'],
            // Carried from the join, so that SqlDatabase.customer is not a
            // query per database.
            '__ownerId'  => (int)$row['domain_admin_id']
        ));
    }

    /**
     * @param array<string, mixed> $row A row of sql_user
     * @return array<string, mixed>
     */
    public static function shapeSqlUser(array $row): array
    {
        return TypeResolver::node(NodeType::SQL_USER, (int)$row['sqlu_id'], array(
            'name'     => (string)$row['sqlu_name'],
            'host'     => (string)$row['sqlu_host'],
            '__sqldId' => (int)$row['sqld_id'],
            // SqlUser.databases is keyed by the name, not by this row: one
            // MySQL user granted on three databases is three sql_user rows,
            // and all three list the same three databases.
            '__name'   => (string)$row['sqlu_name']
        ));
    }

    public function ftpReference(string $userid): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'ftp:row',
            $userid,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT userid, admin_id, homedir, status FROM ftp_users'
                        . ' WHERE userid IN (' . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(string)$row['userid']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shapeFtp($row);
        });
    }

    public function databaseReference(int $sqldId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'sqldb:row',
            $sqldId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT sd.sqld_id, sd.domain_id, sd.sqld_name,
                            d.domain_admin_id
                        FROM sql_database AS sd
                        JOIN domain AS d ON d.domain_id = sd.domain_id
                        WHERE sd.sqld_id IN (' . $db->placeholders(count($keys)) . ')
                    ',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['sqld_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shapeDatabase($row);
        });
    }

    public function sqlUserReference(int $sqluId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'sqluser:row',
            $sqluId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT sqlu_id, sqld_id, sqlu_name, sqlu_host FROM sql_user'
                        . ' WHERE sqlu_id IN (' . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['sqlu_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shapeSqlUser($row);
        });
    }

    /**
     * FtpUser.customer and SqlDatabase.customer alike: both shapes carry
     * __ownerId, so both edges are the same call.
     */
    public function resolveOwner($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return call_user_func($this->customerRef, (int)$source['__ownerId']);
    }

    /**
     * Customer.ftpUsers - a paged connection. Decision D6 as in Task 14: one
     * query for the whole batch, sliced per parent afterwards.
     */
    public function resolveCustomerFtpUsers(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::FTP_READ);

        $page = TypeResolver::page($args['page'] ?? null);
        $db = $this->db;

        // Keyed by admin_id: ftp_users has no domain_id column at all.
        return $this->loader->keyed(
            'ftp:by-admin',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT userid, admin_id, homedir, status FROM ftp_users'
                        . ' WHERE admin_id IN (' . $db->placeholders(count($keys))
                        . ') ORDER BY userid',
                    $keys
                );
                $byAdmin = array();

                foreach ($keys as $key) {
                    $byAdmin[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byAdmin[(int)$row['admin_id']][] = $row;
                }

                return $byAdmin;
            }
        )->then(static function ($rows) use ($page) {
            $rows = $rows === null ? array() : $rows;
            $nodes = array();

            foreach (array_slice($rows, $page['offset'], $page['limit']) as $row) {
                $nodes[] = self::shapeFtp($row);
            }

            return array('totalCount' => count($rows), 'nodes' => $nodes);
        });
    }

    public function resolveCustomerDatabases(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'sqldb:by-domain',
            (int)$source['__domainId'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT sd.sqld_id, sd.domain_id, sd.sqld_name,
                            d.domain_admin_id
                        FROM sql_database AS sd
                        JOIN domain AS d ON d.domain_id = sd.domain_id
                        WHERE sd.domain_id IN (' . $db->placeholders(count($keys)) . ')
                        ORDER BY sd.sqld_name
                    ',
                    $keys
                );
                $byDomain = array();

                foreach ($keys as $key) {
                    // sqlDatabases is [SqlDatabase!]!: a customer with none
                    // needs an empty list, not a null that nulls the Customer.
                    $byDomain[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDomain[(int)$row['domain_id']][] = $row;
                }

                return $byDomain;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeDatabase($row);
            }

            return $shaped;
        });
    }

    public function resolveCustomerSqlUsers(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        // One node per grant, as the SDL says. Counts::sqlUsers() counts
        // DISTINCT sqlu_name instead, because that is the rule i-MSCP enforces
        // the quota against - so a user granted on two databases is one
        // against the quota and two nodes here. Both are deliberate.
        return $this->loader->keyed(
            'sqluser:by-domain',
            (int)$source['__domainId'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT su.sqlu_id, su.sqld_id, su.sqlu_name, su.sqlu_host,
                            sd.domain_id
                        FROM sql_user AS su
                        JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                        WHERE sd.domain_id IN (' . $db->placeholders(count($keys)) . ')
                        ORDER BY su.sqlu_name, su.sqlu_id
                    ',
                    $keys
                );
                $byDomain = array();

                foreach ($keys as $key) {
                    $byDomain[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDomain[(int)$row['domain_id']][] = $row;
                }

                return $byDomain;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeSqlUser($row);
            }

            return $shaped;
        });
    }

    public function resolveDatabaseUsers(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'sqluser:by-database',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT sqlu_id, sqld_id, sqlu_name, sqlu_host FROM sql_user'
                        . ' WHERE sqld_id IN (' . $db->placeholders(count($keys))
                        . ') ORDER BY sqlu_name, sqlu_id',
                    $keys
                );
                $byDatabase = array();

                foreach ($keys as $key) {
                    $byDatabase[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDatabase[(int)$row['sqld_id']][] = $row;
                }

                return $byDatabase;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeSqlUser($row);
            }

            return $shaped;
        });
    }

    public function resolveSqlUserDatabases(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::SQL_READ);

        $db = $this->db;

        // Keyed by the user's name rather than by this grant row: a MySQL user
        // granted on three databases is three sql_user rows and one identity,
        // and the schema asks which databases that identity may reach.
        return $this->loader->keyed(
            'sqldb:by-user-name',
            (string)$source['__name'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT su.sqlu_name, sd.sqld_id, sd.domain_id,
                            sd.sqld_name, d.domain_admin_id
                        FROM sql_user AS su
                        JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
                        JOIN domain AS d ON d.domain_id = sd.domain_id
                        WHERE su.sqlu_name IN (' . $db->placeholders(count($keys)) . ')
                        ORDER BY sd.sqld_name
                    ',
                    $keys
                );
                $byName = array();

                foreach ($keys as $key) {
                    $byName[(string)$key] = array();
                }

                foreach ($rows as $row) {
                    $byName[(string)$row['sqlu_name']][] = $row;
                }

                return $byName;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shapeDatabase($row);
            }

            return $shaped;
        });
    }
}
