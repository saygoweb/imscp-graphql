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
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use InvalidArgumentException;

/**
 * Reseller and HostingPlan - spec section 7.8 - and the three Viewer fields
 * the schema gained with them.
 *
 * Reseller is the one type in the schema with field-level authorisation. Its
 * own SDL description is the rule: a customer may read the identity of their
 * own reseller and nothing else, so id, username and contact are open to
 * anyone who can reach the object at all, and every other field goes through
 * requireReseller().
 */
final class ResellerResolver
{
    /**
     * admin joined to reseller_props.
     *
     * Inward, because a reseller with no props row cannot answer quotas,
     * storage or ipAddresses, all of which are non-null. The administrator
     * account has no props row either, which is right: an administrator is not
     * a Reseller and Query.reseller must not return one.
     */
    const SELECT = '
        SELECT
            a.admin_id, a.admin_name, a.admin_status, a.domain_created,
            a.fname, a.lname, a.gender, a.firm, a.street1, a.street2, a.city,
            a.state, a.zip, a.country, a.email, a.phone, a.fax,
            p.max_dmn_cnt, p.current_dmn_cnt,
            p.max_sub_cnt, p.current_sub_cnt,
            p.max_als_cnt, p.current_als_cnt,
            p.max_mail_cnt, p.current_mail_cnt,
            p.max_ftp_cnt, p.current_ftp_cnt,
            p.max_sql_db_cnt, p.current_sql_db_cnt,
            p.max_sql_user_cnt, p.current_sql_user_cnt,
            p.max_disk_amnt, p.max_traff_amnt, p.reseller_ips
        FROM admin AS a
        JOIN reseller_props AS p ON p.reseller_id = a.admin_id
    ';

    /** GraphQL allowance name => the reseller_props limit and counter columns. */
    const ALLOWANCES = array(
        'customers'     => array('max_dmn_cnt', 'current_dmn_cnt'),
        'subdomains'    => array('max_sub_cnt', 'current_sub_cnt'),
        'domainAliases' => array('max_als_cnt', 'current_als_cnt'),
        'mailAccounts'  => array('max_mail_cnt', 'current_mail_cnt'),
        'ftpUsers'      => array('max_ftp_cnt', 'current_ftp_cnt'),
        'sqlDatabases'  => array('max_sql_db_cnt', 'current_sql_db_cnt'),
        'sqlUsers'      => array('max_sql_user_cnt', 'current_sql_user_cnt')
    );

    /** MiB to bytes. Decision D3. */
    const MIB = 1048576;

    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var bool */
    private $apiAccessByDefault;

    /** @var callable fn(): array{0: int, 1: int} */
    private $monthBounds;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    /** @var callable fn(array $adminIds): SyncPromise */
    private $customerRefs;

    /** @var callable fn(array $ipIds): SyncPromise */
    private $ipsRef;

    public function __construct(
        Db $db, BatchLoader $loader, bool $apiAccessByDefault,
        callable $monthBounds, callable $toUnicode, callable $customerRef,
        callable $customerRefs, callable $ipsRef
    ) {
        $this->db = $db;
        $this->loader = $loader;
        $this->apiAccessByDefault = $apiAccessByDefault;
        $this->monthBounds = $monthBounds;
        $this->toUnicode = $toUnicode;
        $this->customerRef = $customerRef;
        $this->customerRefs = $customerRefs;
        $this->ipsRef = $ipsRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Reseller.quotas'       => array($this, 'resolveQuotas'),
            'Reseller.storage'      => array($this, 'resolveStorage'),
            'Reseller.ipAddresses'  => array($this, 'resolveIpAddresses'),
            'Reseller.apiAccess'    => array($this, 'resolveApiAccess'),
            'Reseller.customers'    => array($this, 'resolveCustomers'),
            'Reseller.hostingPlans' => array($this, 'resolveHostingPlans'),
            'HostingPlan.reseller'  => array($this, 'resolvePlanReseller'),
            'HostingPlan.quotas'    => array($this, 'resolvePlanQuotas'),
            'HostingPlan.storage'   => array($this, 'resolvePlanStorage'),
            'HostingPlan.features'  => array($this, 'resolvePlanFeatures'),
            'Viewer.contact'        => array($this, 'resolveViewerContact'),
            'Viewer.customer'       => array($this, 'resolveViewerCustomer'),
            'Viewer.reseller'       => array($this, 'resolveViewerReseller')
        );
    }

    /**
     * @param array<string, mixed> $row A row of self::SELECT
     * @return array<string, mixed>
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        return TypeResolver::node(NodeType::RESELLER, (int)$row['admin_id'], array(
            'username'     => (string)$row['admin_name'],
            'contact'      => TypeResolver::contact($row, $toUnicode),
            'createdAt'    => TypeResolver::dateTime($row['domain_created']),
            'provisioning' => TypeResolver::provisioning(
                $row['admin_status'] === null ? null : (string)$row['admin_status']
            ),
            '__ipIds'      => self::ipIds($row['reseller_ips'] ?? null),
            '__row'        => $row
        ));
    }

    /**
     * ResellerQuotas, straight off the row.
     *
     * `used` is i-MSCP's own maintained counter - the number the panel's own
     * reseller pages show - so that the API and the panel cannot disagree
     * about how much of an allowance has been sold. Spec section 7.8: these
     * have no withheld state, so 0 is unlimited and nothing is ever disabled.
     *
     * @param array<string, mixed> $row
     * @return array<string, array>
     */
    public static function quotas(array $row): array
    {
        $quotas = array();

        foreach (self::ALLOWANCES as $name => $columns) {
            $quotas[$name] = Quota::fromResellerLimit(
                (int)$row[$columns[0]], (int)$row[$columns[1]]
            )->toArray();
        }

        return $quotas;
    }

    /**
     * @param array<string, mixed> $row A row of hosting_plans
     * @return array<string, mixed>
     * @throws ApiException when props does not parse
     */
    public static function planShape(array $row): array
    {
        try {
            $props = PlanProps::parse((string)$row['props']);
        } catch (InvalidArgumentException $e) {
            // Uncaught this is a 500 with no indication of which plan is
            // malformed, and a malformed plan is exactly the failure spec
            // section 20 lists as risk 4.
            throw new ApiException(
                ErrorCode::INTERNAL,
                'This hosting plan has properties the API cannot read.',
                array('hostingPlanId' => (int)$row['id']),
                $e
            );
        }

        $quotas = array();

        foreach (array_keys(PlanProps::ALLOWANCES) as $name) {
            $quotas[$name] = $props->allowance($name);
        }

        $storage = $props->storage();
        $description = $row['description'] === null
            ? '' : (string)$row['description'];

        return TypeResolver::node(NodeType::HOSTING_PLAN, (int)$row['id'], array(
            'name'         => (string)$row['name'],
            'description'  => $description === '' ? null : $description,
            // hosting_plans.status is 1 for a plan a reseller may sell.
            'available'    => (bool)(int)$row['status'],
            'quotas'       => $quotas,
            'storage'      => array(
                'disk'      => TypeResolver::bigInt($storage['disk']),
                'traffic'   => TypeResolver::bigInt($storage['traffic']),
                'mailQuota' => TypeResolver::bigInt($storage['mailQuota'])
            ),
            'features'     => $props->features(),
            '__resellerId' => (int)$row['reseller_id']
        ));
    }

    /**
     * The rule the Reseller type's own SDL description states.
     *
     * @param mixed $context
     * @throws ApiException FORBIDDEN
     */
    public static function requireReseller($context, int $resellerId): Identity
    {
        $identity = TypeResolver::identity($context);

        if ($identity->getRole() === Identity::ROLE_ADMIN) {
            return $identity;
        }

        if ($identity->getRole() === Identity::ROLE_RESELLER
            && $identity->getAdminId() === $resellerId
        ) {
            return $identity;
        }

        // Not NOT_FOUND: the object is reachable - a customer may read their
        // reseller's identity - and it is this field that is refused. Spec
        // section 6.3's rule is about objects the caller cannot reach at all.
        throw new ApiException(
            ErrorCode::FORBIDDEN,
            'Only this reseller or an administrator may read this field.'
        );
    }

    /**
     * CustomerFilter as a SQL fragment over `a` (admin) and `d` (domain).
     *
     * Public and static because Query.customers (Task 16) builds the same
     * list from the other direction, and two copies of a filter is two
     * definitions of what `state: PENDING` means.
     *
     * @param array<string, mixed> $filter
     * @return array{0: string, 1: array}
     */
    public static function customerFilter(Db $db, array $filter): array
    {
        $sql = '';
        $bind = array();

        if (isset($filter['username']) && $filter['username'] !== '') {
            $sql .= ' AND a.admin_name LIKE ?';
            $bind[] = '%' . str_replace(
                array('\\', '%', '_'), array('\\\\', '\\%', '\\_'),
                (string)$filter['username']
            ) . '%';
        }

        if (isset($filter['domainName']) && $filter['domainName'] !== '') {
            // Exact, not substring: a domain name is an identifier, and a
            // client asking for one wants that one.
            $sql .= ' AND d.domain_name = ?';
            $bind[] = (string)$filter['domainName'];
        }

        list($stateSql, $stateBind) = ProvisioningFilter::clause(
            'a.admin_status', $filter['state'] ?? null
        );

        return array($sql . $stateSql, array_merge($bind, $stateBind));
    }

    public function reference(int $adminId): SyncPromise
    {
        $toUnicode = $this->toUnicode;

        return $this->row($adminId)->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    /**
     * @param int[] $adminIds
     */
    public function references(array $adminIds): SyncPromise
    {
        $deferreds = array();

        foreach ($adminIds as $adminId) {
            $deferreds[] = $this->row((int)$adminId);
        }

        $toUnicode = $this->toUnicode;

        return new Deferred(static function () use ($deferreds, $toUnicode) {
            $resellers = array();

            foreach ($deferreds as $deferred) {
                if ($deferred->result !== null) {
                    $resellers[] = self::shape($deferred->result, $toUnicode);
                }
            }

            return $resellers;
        });
    }

    public function planReference(int $planId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'plan:row',
            $planId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT id, reseller_id, name, description, props, status'
                        . ' FROM hosting_plans WHERE id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::planShape($row);
        });
    }

    public function resolveQuotas($source, array $args, $context, ResolveInfo $info): array
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        return self::quotas($source['__row']);
    }

    public function resolveStorage($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        $row = $source['__row'];
        $resellerId = (int)$source['__key'];
        $disk = $this->diskOfCustomers($resellerId);
        $traffic = $this->trafficOfCustomers($resellerId);

        return new Deferred(static function () use ($row, $disk, $traffic) {
            $sums = $disk->result === null
                ? array('used' => 0, 'files' => 0, 'mail' => 0, 'sql' => 0)
                : $disk->result;

            return array(
                'diskLimit'    => self::mibToBytes($row['max_disk_amnt']),
                // Summed rather than read from reseller_props.current_disk_amnt:
                // the schema wants the file/mail/SQL split beside the total, no
                // counter carries it, and taking the total from one source and
                // the parts from another would let them disagree.
                'diskUsed'     => TypeResolver::bigInt($sums['used']),
                'diskFiles'    => TypeResolver::bigInt($sums['files']),
                'diskMail'     => TypeResolver::bigInt($sums['mail']),
                'diskSql'      => TypeResolver::bigInt($sums['sql']),
                'trafficLimit' => self::mibToBytes($row['max_traff_amnt']),
                'trafficUsed'  => TypeResolver::bigInt(
                    $traffic->result === null ? 0 : $traffic->result
                ),
                // A reseller has no default per-mailbox quota; that belongs to
                // a hosting plan or to a customer.
                'mailQuota'    => null
            );
        });
    }

    public function resolveIpAddresses($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        return call_user_func($this->ipsRef, $source['__ipIds']);
    }

    public function resolveApiAccess($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        $db = $this->db;
        $byDefault = $this->apiAccessByDefault;

        return $this->loader->keyed(
            'reseller:api-perm',
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
     * Reseller.customers.
     *
     * The SDL's own words: "The customers of this reseller that the caller may
     * reach. A customer asking sees only themselves." So this is the one
     * Reseller field a customer may select, and what they get back is a
     * connection of exactly one.
     */
    public function resolveCustomers($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::CUSTOMERS_READ);
        $resellerId = (int)$source['__key'];
        $page = TypeResolver::page($args['page'] ?? null, $context);
        $customerRefs = $this->customerRefs;

        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            // Themselves, and only if this really is their reseller - so that
            // a customer cannot use another reseller's object to confirm their
            // own identity against it.
            $ids = $identity->getCreatedBy() === $resellerId
                ? array($identity->getAdminId()) : array();
            $total = count($ids);

            return call_user_func($customerRefs, $ids)->then(
                static function ($nodes) use ($total) {
                    return array(
                        'totalCount' => $total,
                        'nodes'      => $nodes === null ? array() : $nodes
                    );
                }
            );
        }

        self::requireReseller($context, $resellerId);

        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:customer-ids:' . md5(serialize($filter)),
            $resellerId,
            static function (array $keys) use ($db, $filter) {
                list($filterSql, $filterBind) = self::customerFilter($db, $filter);
                $rows = $db->rows(
                    '
                        SELECT a.admin_id, a.created_by
                        FROM admin AS a
                        JOIN domain AS d ON d.domain_admin_id = a.admin_id
                        WHERE a.admin_type = ? AND a.created_by IN ('
                            . $db->placeholders(count($keys)) . ')' . $filterSql . '
                        ORDER BY a.admin_name
                    ',
                    array_merge(array('user'), $keys, $filterBind)
                );
                $byReseller = array();

                foreach ($keys as $key) {
                    $byReseller[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byReseller[(int)$row['created_by']][] = (int)$row['admin_id'];
                }

                return $byReseller;
            }
        )->then(static function ($ids) use ($page, $customerRefs) {
            $ids = $ids === null ? array() : $ids;
            // Decision D6: the page is applied here rather than in SQL, so
            // that every reseller in the batch comes out of one query.
            $wanted = array_slice($ids, $page['offset'], $page['limit']);
            $total = count($ids);

            // Returning a promise from a then() callback is legal and is what
            // makes the second level batch: SyncPromise::resolve() adopts any
            // thenable it is handed, so the connection is not built until the
            // customer rows have been loaded.
            return call_user_func($customerRefs, $wanted)->then(
                static function ($nodes) use ($total) {
                    return array(
                        'totalCount' => $total,
                        'nodes'      => $nodes === null ? array() : $nodes
                    );
                }
            );
        });
    }

    public function resolveHostingPlans($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        self::requireReseller($context, (int)$source['__key']);
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'plan:by-reseller',
            (int)$source['__key'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT id, reseller_id, name, description, props, status'
                        . ' FROM hosting_plans WHERE reseller_id IN ('
                        . $db->placeholders(count($keys)) . ') ORDER BY name',
                    $keys
                );
                $byReseller = array();

                foreach ($keys as $key) {
                    $byReseller[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byReseller[(int)$row['reseller_id']][] = $row;
                }

                return $byReseller;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::planShape($row);
            }

            return $shaped;
        });
    }

    public function resolvePlanReseller($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::RESELLERS_READ);

        return $this->reference((int)$source['__resellerId']);
    }

    public function resolvePlanQuotas($source, array $args, $context, ResolveInfo $info): array
    {
        return $source['quotas'];
    }

    public function resolvePlanStorage($source, array $args, $context, ResolveInfo $info): array
    {
        return $source['storage'];
    }

    public function resolvePlanFeatures($source, array $args, $context, ResolveInfo $info): array
    {
        return $source['features'];
    }

    public function resolveViewerContact($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        $identity = TypeResolver::identity($context);
        $db = $this->db;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'admin:contact',
            $identity->getAdminId(),
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT admin_id, fname, lname, gender, firm, street1,
                            street2, city, state, zip, country, email, phone, fax
                        FROM admin WHERE admin_id IN ('
                            . $db->placeholders(count($keys)) . ')
                    ',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['admin_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) use ($toUnicode) {
            // Viewer.contact is ContactDetails! - non-null - and every field
            // inside it is nullable, so an account row that has gone missing
            // is an empty card rather than a nulled Viewer.
            return TypeResolver::contact(
                $row === null ? array() : $row, $toUnicode
            );
        });
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveViewerCustomer($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::identity($context);

        if ($identity->getRole() !== Identity::ROLE_CUSTOMER) {
            // Nullable in the SDL: "Set when role is CUSTOMER."
            return null;
        }

        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return call_user_func($this->customerRef, $identity->getAdminId());
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveViewerReseller($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::identity($context);

        if ($identity->getRole() !== Identity::ROLE_RESELLER) {
            return null;
        }

        TypeResolver::requireScope($context, Scope::ACCOUNT_READ);

        return $this->reference($identity->getAdminId());
    }

    private function row(int $adminId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:row',
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

    private function diskOfCustomers(int $resellerId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'reseller:disk',
            $resellerId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    '
                        SELECT a.created_by AS k,
                            IFNULL(SUM(d.domain_disk_usage), 0) AS used,
                            IFNULL(SUM(d.domain_disk_file), 0) AS files,
                            IFNULL(SUM(d.domain_disk_mail), 0) AS mail,
                            IFNULL(SUM(d.domain_disk_sql), 0) AS sqldisk
                        FROM admin AS a
                        JOIN domain AS d ON d.domain_admin_id = a.admin_id
                        WHERE a.created_by IN ('
                            . $db->placeholders(count($keys)) . ')
                        GROUP BY a.created_by
                    ',
                    $keys
                );
                $byReseller = array();

                foreach ($rows as $row) {
                    $byReseller[(int)$row['k']] = array(
                        'used'  => (int)$row['used'],
                        'files' => (int)$row['files'],
                        'mail'  => (int)$row['mail'],
                        'sql'   => (int)$row['sqldisk']
                    );
                }

                return $byReseller;
            }
        );
    }

    private function trafficOfCustomers(int $resellerId): SyncPromise
    {
        $db = $this->db;
        $bounds = call_user_func($this->monthBounds);

        // A second query rather than a second set of columns on the first:
        // joining domain_traffic there would multiply every disk sum by the
        // number of accounting rows.
        return $this->loader->keyed(
            'reseller:traffic',
            $resellerId,
            static function (array $keys) use ($db, $bounds) {
                $rows = $db->rows(
                    '
                        SELECT a.created_by AS k,
                            IFNULL(SUM(t.dtraff_web + t.dtraff_ftp + t.dtraff_mail
                                + t.dtraff_pop), 0) AS n
                        FROM admin AS a
                        JOIN domain AS d ON d.domain_admin_id = a.admin_id
                        JOIN domain_traffic AS t ON t.domain_id = d.domain_id
                        WHERE a.created_by IN ('
                            . $db->placeholders(count($keys)) . ')
                            AND t.dtraff_time BETWEEN ? AND ?
                        GROUP BY a.created_by
                    ',
                    array_merge($keys, array($bounds[0], $bounds[1]))
                );
                $byReseller = array();

                foreach ($rows as $row) {
                    $byReseller[(int)$row['k']] = (int)$row['n'];
                }

                return $byReseller;
            }
        );
    }

    /**
     * reseller_props.reseller_ips is a semicolon-TERMINATED list: "3;7;".
     *
     * explode() alone leaves an empty final element, which casts to ip_id 0
     * and becomes a null inside a non-null list.
     *
     * @param mixed $stored
     * @return int[]
     */
    private static function ipIds($stored): array
    {
        if ($stored === null || $stored === '') {
            return array();
        }

        $ids = array();

        foreach (explode(';', (string)$stored) as $id) {
            $id = trim($id);

            if ($id !== '' && (int)$id > 0) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $mib
     */
    private static function mibToBytes($mib): ?string
    {
        return (int)$mib <= 0 ? null : TypeResolver::bigInt((int)$mib * self::MIB);
    }
}
