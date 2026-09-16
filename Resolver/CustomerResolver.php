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

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Support\CustomerFeatures;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;

/**
 * Customer, and the three value objects spec section 7.5 hangs off it.
 *
 * The Customer type has the most edges in the schema, and this class owns only
 * the ones whose other end it shapes. Mail belongs to MailResolver, DNS to
 * DnsResolver, FTP and SQL to FtpSqlResolver; Task 16's container test asserts
 * that no two maps claim the same key.
 */
final class CustomerResolver
{
    /**
     * The joined admin/domain select list.
     *
     * Both tables carry a domain_created column - the account's creation date
     * in `admin`, the domain's in `domain` - so `a.*, d.*` would silently
     * resolve one of them to the other depending on driver order. Every column
     * is therefore named, and only admin's domain_created is taken.
     *
     * The join is inward on purpose. A customer with no domain row cannot
     * answer domain, quotas, storage or features, every one of which is
     * non-null, so it would serialise as a null Customer inside a non-null
     * list and null the entire query. i-MSCP writes both rows together, so in
     * sound data this excludes nothing.
     */
    const SELECT = '
        SELECT
            a.admin_id, a.admin_name, a.admin_type, a.created_by, a.customer_id,
            a.admin_status, a.domain_created, a.fname, a.lname, a.gender,
            a.firm, a.street1, a.street2, a.city, a.state, a.zip, a.country,
            a.email, a.phone, a.fax,
            d.domain_id, d.domain_expires, d.domain_status,
            d.domain_subd_limit, d.domain_alias_limit, d.domain_mailacc_limit,
            d.domain_ftpacc_limit, d.domain_sqld_limit, d.domain_sqlu_limit,
            d.domain_disk_limit, d.domain_disk_usage, d.domain_disk_file,
            d.domain_disk_mail, d.domain_disk_sql, d.domain_traffic_limit,
            d.mail_quota,
            d.domain_php, d.domain_cgi, d.domain_dns, d.domain_external_mail,
            d.allowbackup, d.phpini_perm_system, d.phpini_perm_allow_url_fopen,
            d.phpini_perm_display_errors, d.phpini_perm_disable_functions
        FROM admin AS a
        JOIN domain AS d ON d.domain_admin_id = a.admin_id
    ';

    /** MiB to bytes. Decision D3: every BigInt in this schema is bytes. */
    const MIB = 1048576;

    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var Counts */
    private $counts;

    /** @var VirtualHosts */
    private $vhosts;

    /** @var array The panel configuration, at least CustomerFeatures::CONFIG_KEYS. */
    private $config;

    /** @var bool The plugin's allowed_by_default, for accounts with no api_perm row. */
    private $apiAccessByDefault;

    /** @var callable fn(): array{0: int, 1: int} */
    private $monthBounds;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(int $resellerAdminId): SyncPromise */
    private $resellerRef;

    public function __construct(
        Db $db, BatchLoader $loader, Counts $counts, VirtualHosts $vhosts,
        array $config, bool $apiAccessByDefault, callable $monthBounds,
        callable $toUnicode, callable $resellerRef
    ) {
        $this->db = $db;
        $this->loader = $loader;
        $this->counts = $counts;
        $this->vhosts = $vhosts;
        $this->config = $config;
        $this->apiAccessByDefault = $apiAccessByDefault;
        $this->monthBounds = $monthBounds;
        $this->toUnicode = $toUnicode;
        $this->resellerRef = $resellerRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Customer.reseller'      => array($this, 'resolveReseller'),
            'Customer.domain'        => array($this, 'resolveDomain'),
            'Customer.subdomains'    => array($this, 'resolveSubdomains'),
            'Customer.domainAliases' => array($this, 'resolveDomainAliases'),
            'Customer.quotas'        => array($this, 'resolveQuotas'),
            'Customer.storage'       => array($this, 'resolveStorage'),
            'Customer.features'      => array($this, 'resolveFeatures'),
            'Customer.apiAccess'     => array($this, 'resolveApiAccess')
        );
    }

    /**
     * One joined row becomes the array the Customer type resolves against.
     *
     * @param array<string, mixed> $row A row of self::SELECT
     * @param callable $toUnicode fn(string): string
     * @return array<string, mixed>
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        $reference = isset($row['customer_id']) ? (string)$row['customer_id'] : '';

        return TypeResolver::node(NodeType::CUSTOMER, (int)$row['admin_id'], array(
            'username'     => (string)$row['admin_name'],
            'reference'    => $reference === '' ? null : $reference,
            'contact'      => TypeResolver::contact($row, $toUnicode),
            // admin.domain_created, not domain.domain_created: this is when
            // the account was created.
            'createdAt'    => TypeResolver::dateTime($row['domain_created']),
            'expiresAt'    => TypeResolver::dateTime($row['domain_expires']),
            // The account's own status. The domain has its own Provisioning,
            // on the Domain type.
            'provisioning' => TypeResolver::provisioning(
                $row['admin_status'] === null ? null : (string)$row['admin_status']
            ),
            '__domainId'   => (int)$row['domain_id'],
            '__resellerId' => $row['created_by'] === null
                ? null : (int)$row['created_by'],
            // quotas, storage and features all read domain columns. Carrying
            // the row is what keeps each of them from costing a query.
            '__row'        => $row
        ));
    }

    /**
     * One shaped Customer, batched.
     */
    public function reference(int $adminId): SyncPromise
    {
        $toUnicode = $this->toUnicode;

        return $this->row($adminId)->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    /**
     * Several shaped Customers, in the order asked for.
     *
     * Every identifier joins the same buffer, so a list of two hundred
     * customers is one query. An identifier the join did not return is
     * dropped rather than becoming a null inside a non-null list.
     *
     * It gathers the raw row promises rather than reference()'s shaped ones,
     * and the reason is queue order. BatchLoader::keyed() enqueues a Deferred
     * immediately, so the Deferred created below sits behind every one of
     * them and finds each result already set. A ->then() child is enqueued
     * only once its parent resolves, which is after that - so gathering
     * reference()'s results here would read a queue of nulls.
     *
     * @param int[] $adminIds
     */
    public function references(array $adminIds): SyncPromise
    {
        $rows = array();

        foreach ($adminIds as $adminId) {
            $rows[] = $this->row((int)$adminId);
        }

        $toUnicode = $this->toUnicode;

        return new Deferred(static function () use ($rows, $toUnicode) {
            $customers = array();

            foreach ($rows as $row) {
                if ($row->result !== null) {
                    $customers[] = self::shape($row->result, $toUnicode);
                }
            }

            return $customers;
        });
    }

    /**
     * The joined row behind one customer, unshaped and batched.
     */
    private function row(int $adminId): Deferred
    {
        $db = $this->db;

        return $this->loader->keyed(
            'customer:row',
            $adminId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    self::SELECT . ' WHERE a.admin_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = $row;
                }

                return $byId;
            }
        );
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveReseller($source, array $args, $context, ResolveInfo $info)
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        if ($source['__resellerId'] === null) {
            // admin.created_by is nullable, and shape() keeps that null rather
            // than flattening it. (int)null is 0, which reads as a reseller
            // that cannot exist: reference(0) finds nothing and answers null,
            // and while the field was Reseller! that null nulled the whole
            // Customer - and inside CustomerConnection.nodes: [Customer!]! the
            // whole page with it.
            return null;
        }

        return call_user_func($this->resellerRef, (int)$source['__resellerId']);
    }

    public function resolveDomain($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        $toUnicode = $this->toUnicode;

        return $this->vhostRows((int)$source['__domainId'])
            ->then(static function ($rows) use ($toUnicode) {
                foreach (($rows === null ? array() : $rows) as $row) {
                    if ($row['kind'] === VirtualHosts::KIND_DMN) {
                        return VirtualHostResolver::shape($row, $toUnicode);
                    }
                }

                return null;
            });
    }

    public function resolveSubdomains($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        // Spec section 7.5: "Subdomains of the main domain and of every alias
        // alike". Decision D1 is what lets both sit in one list without their
        // identifiers colliding.
        return $this->vhostsOfKinds(
            (int)$source['__domainId'],
            array(VirtualHosts::KIND_SUB, VirtualHosts::KIND_ALSSUB)
        );
    }

    public function resolveDomainAliases($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->vhostsOfKinds(
            (int)$source['__domainId'], array(VirtualHosts::KIND_ALS)
        );
    }

    public function resolveQuotas($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $row = $source['__row'];
        $domainId = (int)$source['__domainId'];
        $adminId = (int)$source['__key'];
        $counts = $this->counts;

        // Six buckets, six queries per level however many customers asked -
        // decision D5. Counting.php's own functions take one customer at a
        // time, which is six queries per row of a reseller's customer list.
        $subdomains = $this->count('counts:subdomains', $domainId, static function (array $ids) use ($counts) {
            return $counts->subdomains($ids);
        });
        $aliases = $this->count('counts:aliases', $domainId, static function (array $ids) use ($counts) {
            return $counts->domainAliases($ids);
        });
        $mail = $this->count('counts:mail', $domainId, static function (array $ids) use ($counts) {
            return $counts->mailAccounts($ids);
        });
        $databases = $this->count('counts:sqldb', $domainId, static function (array $ids) use ($counts) {
            return $counts->sqlDatabases($ids);
        });
        $sqlUsers = $this->count('counts:sqlusers', $domainId, static function (array $ids) use ($counts) {
            return $counts->sqlUsers($ids);
        });
        // Keyed by admin_id: ftp_users has no domain_id column.
        $ftpUsers = $this->count('counts:ftp', $adminId, static function (array $ids) use ($counts) {
            return $counts->ftpUsers($ids);
        });

        return new Deferred(static function () use (
            $row, $subdomains, $aliases, $mail, $ftpUsers, $databases, $sqlUsers
        ) {
            return array(
                'subdomains'    => self::quota($row['domain_subd_limit'], $subdomains->result),
                'domainAliases' => self::quota($row['domain_alias_limit'], $aliases->result),
                'mailAccounts'  => self::quota($row['domain_mailacc_limit'], $mail->result),
                'ftpUsers'      => self::quota($row['domain_ftpacc_limit'], $ftpUsers->result),
                'sqlDatabases'  => self::quota($row['domain_sqld_limit'], $databases->result),
                'sqlUsers'      => self::quota($row['domain_sqlu_limit'], $sqlUsers->result)
            );
        });
    }

    public function resolveStorage($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $row = $source['__row'];

        return $this->traffic((int)$source['__domainId'])
            ->then(static function ($used) use ($row) {
                return array(
                    // Decision D3. domain_disk_limit and domain_traffic_limit
                    // are the only two figures i-MSCP keeps in MiB; every
                    // usage column beside them is bytes. A client comparing
                    // diskUsed to diskLimit must not be out by a factor of a
                    // million.
                    'diskLimit'    => self::mibToBytes($row['domain_disk_limit']),
                    'diskUsed'     => TypeResolver::bigInt($row['domain_disk_usage']),
                    'diskFiles'    => TypeResolver::bigInt($row['domain_disk_file']),
                    'diskMail'     => TypeResolver::bigInt($row['domain_disk_mail']),
                    'diskSql'      => TypeResolver::bigInt($row['domain_disk_sql']),
                    'trafficLimit' => self::mibToBytes($row['domain_traffic_limit']),
                    'trafficUsed'  => TypeResolver::bigInt($used === null ? 0 : $used),
                    'mailQuota'    => (int)$row['mail_quota'] === 0
                        ? null : TypeResolver::bigInt($row['mail_quota'])
                );
            });
    }

    public function resolveFeatures($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $row = $source['__row'];
        $config = $this->config;

        if ($source['__resellerId'] === null) {
            // No reseller, so no reseller_props row to read support_system
            // from, and the panel's own customerHasFeature() answers false for
            // the same reason. Said here rather than left to (int)null = 0
            // quietly missing the row.
            return new Deferred(static function () use ($row, $config) {
                return CustomerFeatures::fromDomainRow($row, $config, false)
                    ->toArray();
            });
        }

        // support_system is the reseller's, not the customer's: a reseller who
        // does not offer the ticket system withholds it from every customer.
        return $this->resellerSupport((int)$source['__resellerId'])
            ->then(static function ($supportSystem) use ($row, $config) {
                return CustomerFeatures::fromDomainRow(
                    $row, $config, $supportSystem === 'yes'
                )->toArray();
            });
    }

    public function resolveApiAccess($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $db = $this->db;
        $byDefault = $this->apiAccessByDefault;

        // SGW_GraphQL::customerHasApiAccess() answers for one account and
        // memoises in a static, which is right for the one account a request
        // authenticates as and wrong for a reseller reading fifty. The rule it
        // encodes is reproduced here: an api_perm row wins, and its absence
        // means the plugin's allowed_by_default.
        return $this->loader->keyed(
            'customer:api-perm',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT admin_id, allowed FROM api_perm WHERE admin_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = (bool)$row['allowed'];
                }

                return $byId;
            }
        )->then(static function ($allowed) use ($byDefault) {
            return $allowed === null ? $byDefault : $allowed;
        });
    }

    /**
     * All four vhost kinds of one domain, in one bucket.
     *
     * domain, subdomains and domainAliases share it, so a document selecting
     * all three costs what a document selecting one of them costs.
     */
    private function vhostRows(int $domainId): SyncPromise
    {
        $vhosts = $this->vhosts;

        return $this->loader->keyed(
            'vhost:all-by-domain',
            $domainId,
            static function (array $keys) use ($vhosts) {
                return $vhosts->forDomains($keys);
            }
        );
    }

    /**
     * @param string[] $kinds
     */
    private function vhostsOfKinds(int $domainId, array $kinds): SyncPromise
    {
        $toUnicode = $this->toUnicode;

        return $this->vhostRows($domainId)
            ->then(static function ($rows) use ($kinds, $toUnicode) {
                $shaped = array();

                // A list field is non-null in the SDL, so "no rows" is an
                // empty list; returning null would null the Customer.
                foreach (($rows === null ? array() : $rows) as $row) {
                    if (in_array($row['kind'], $kinds, true)) {
                        $shaped[] = VirtualHostResolver::shape($row, $toUnicode);
                    }
                }

                return $shaped;
            });
    }

    /**
     * @param int|string $key
     */
    private function count(string $bucket, $key, callable $counter): SyncPromise
    {
        return $this->loader->keyed($bucket, $key, $counter);
    }

    /**
     * This calendar month's web, FTP, mail and POP traffic added together.
     *
     * domain_traffic holds one row per accounting interval, so the figure is a
     * sum rather than a column. The month bounds come from the panel, because
     * the core's own getFirstDayOfMonth()/getLastDayOfMonth() build Zend_Date
     * objects and the API must agree with the panel about where a month ends.
     */
    private function traffic(int $domainId): SyncPromise
    {
        $db = $this->db;
        $bounds = call_user_func($this->monthBounds);

        return $this->loader->keyed(
            'domain:traffic',
            $domainId,
            static function (array $keys) use ($db, $bounds) {
                $rows = $db->rows(
                    '
                        SELECT domain_id AS k,
                            IFNULL(SUM(dtraff_web + dtraff_ftp + dtraff_mail
                                + dtraff_pop), 0) AS n
                        FROM domain_traffic
                        WHERE domain_id IN (' . $db->placeholders(count($keys)) . ')
                            AND dtraff_time BETWEEN ? AND ?
                        GROUP BY domain_id
                    ',
                    array_merge($keys, array($bounds[0], $bounds[1]))
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['k']] = (int)$row['n'];
                }

                return $byId;
            }
        );
    }

    private function resellerSupport(int $resellerId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:support-system',
            $resellerId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT reseller_id, support_system FROM reseller_props'
                        . ' WHERE reseller_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['reseller_id']] = (string)$row['support_system'];
                }

                return $byId;
            }
        );
    }

    /**
     * @param mixed $limit
     * @param mixed $used
     * @return array<string, mixed>
     */
    private static function quota($limit, $used): array
    {
        return Quota::fromCustomerLimit((int)$limit, (int)$used)->toArray();
    }

    /**
     * @param mixed $mib
     */
    private static function mibToBytes($mib): ?string
    {
        // 0 is unlimited, and -1 - which domain_traffic_limit never carries
        // but domain_disk_limit can on a withheld account - is not a size at
        // all. Both are "no ceiling to report".
        return (int)$mib <= 0 ? null : TypeResolver::bigInt((int)$mib * self::MIB);
    }
}
