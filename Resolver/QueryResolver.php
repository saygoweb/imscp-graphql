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
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use InvalidArgumentException;

/**
 * The six root fields of spec section 7.10.
 *
 * Everything here begins the same way: work out what the caller is asking for,
 * ask OwnershipResolver whether they may have it, and only then read. Spec
 * section 6.3's rule - resolve ownership before anything else - is not a
 * convention in this class, it is the first statement of every method.
 */
final class QueryResolver
{
    /** @var Db */
    private $db;

    /** @var OwnershipResolver */
    private $ownership;

    /** @var VirtualHosts */
    private $vhosts;

    /** @var VirtualHostResolver */
    private $virtualHosts;

    /** @var CustomerResolver */
    private $customers;

    /** @var MailResolver */
    private $mail;

    /** @var DnsResolver */
    private $dns;

    /** @var FtpSqlResolver */
    private $ftpSql;

    /** @var ResellerResolver */
    private $resellers;

    public function __construct(
        Db $db, OwnershipResolver $ownership, VirtualHosts $vhosts,
        VirtualHostResolver $virtualHosts, CustomerResolver $customers,
        MailResolver $mail, DnsResolver $dns, FtpSqlResolver $ftpSql,
        ResellerResolver $resellers
    ) {
        $this->db = $db;
        $this->ownership = $ownership;
        $this->vhosts = $vhosts;
        $this->virtualHosts = $virtualHosts;
        $this->customers = $customers;
        $this->mail = $mail;
        $this->dns = $dns;
        $this->ftpSql = $ftpSql;
        $this->resellers = $resellers;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Query.node'      => array($this, 'resolveNode'),
            'Query.customer'  => array($this, 'resolveCustomer'),
            'Query.customers' => array($this, 'resolveCustomers'),
            'Query.reseller'  => array($this, 'resolveReseller'),
            'Query.resellers' => array($this, 'resolveResellers'),
            'Query.pending'   => array($this, 'resolvePending')
        );
    }

    /**
     * From a NodeType tag to the resolver that shapes that tag.
     *
     * The one place the mapping exists. Query.node and Query.pending both use
     * it, and a tag with no case here returns null rather than guessing.
     *
     * @param int|string $key
     */
    public function nodeReference(string $tag, $key): ?SyncPromise
    {
        switch ($tag) {
            case NodeType::CUSTOMER:
                return $this->customers->reference((int)$key);
            case NodeType::DOMAIN:
            case NodeType::SUBDOMAIN:
            case NodeType::ALIAS_SUBDOMAIN:
            case NodeType::DOMAIN_ALIAS:
                return $this->virtualHosts->reference($tag, $key);
            case NodeType::MAIL_ACCOUNT:
                return $this->mail->reference((int)$key);
            case NodeType::FTP_USER:
                return $this->ftpSql->ftpReference((string)$key);
            case NodeType::SQL_DATABASE:
                return $this->ftpSql->databaseReference((int)$key);
            case NodeType::SQL_USER:
                return $this->ftpSql->sqlUserReference((int)$key);
            case NodeType::DNS_RECORD:
                return $this->dns->reference((int)$key);
            case NodeType::RESELLER:
                return $this->resellers->reference((int)$key);
            case NodeType::HOSTING_PLAN:
                return $this->resellers->planReference((int)$key);
            case NodeType::IP_ADDRESS:
                return $this->dns->ipReference((int)$key);
            default:
                return null;
        }
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveNode($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::identity($context);
        $globalId = self::decode((string)$args['id']);

        // Ownership first, before the object is read at all. An identifier the
        // caller cannot reach and an identifier that names nothing produce the
        // same NOT_FOUND, which is what stops node() being an oracle for
        // "does this object exist".
        $this->ownership->assertReachable($identity, $globalId);

        return $this->nodeReference($globalId->getType(), $globalId->getKey());
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveCustomer($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::requireScope($context, Scope::CUSTOMERS_READ);
        $globalId = self::decode((string)$args['id'], NodeType::CUSTOMER);

        $this->ownership->assertReachable($identity, $globalId);

        return $this->customers->reference($globalId->getId());
    }

    /**
     * @return SyncPromise|null
     */
    public function resolveReseller($source, array $args, $context, ResolveInfo $info)
    {
        $identity = TypeResolver::requireScope($context, Scope::RESELLERS_READ);
        $globalId = self::decode((string)$args['id'], NodeType::RESELLER);

        // A customer may reach their own reseller's object; every private
        // field on it is refused separately by
        // ResellerResolver::requireReseller().
        $this->ownership->assertReachable($identity, $globalId);

        return $this->resellers->reference($globalId->getId());
    }

    public function resolveCustomers($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::CUSTOMERS_READ);
        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $page = TypeResolver::page($args['page'] ?? null);

        $sql = '
            SELECT a.admin_id
            FROM admin AS a
            JOIN domain AS d ON d.domain_admin_id = a.admin_id
            WHERE a.admin_type = ?
        ';
        $bind = array('user');

        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            // Spec section 7.10 and the SDL: a customer asking for customers
            // sees themselves. Not an error - the question is legitimate and
            // the honest answer is a list of one.
            $sql .= ' AND a.admin_id = ?';
            $bind[] = $identity->getAdminId();
        } elseif ($identity->getRole() === Identity::ROLE_RESELLER) {
            // filter.resellerId is documented "Administrators only; ignored
            // for a reseller, who only ever sees their own" - and it is
            // ignored here rather than rejected, because rejecting it would
            // make the field's behaviour depend on the caller's role in a way
            // a client cannot predict.
            $sql .= ' AND a.created_by = ?';
            $bind[] = $identity->getAdminId();
        } elseif (isset($filter['resellerId'])) {
            $reseller = self::decode((string)$filter['resellerId'], NodeType::RESELLER);
            $sql .= ' AND a.created_by = ?';
            $bind[] = $reseller->getId();
        }

        list($filterSql, $filterBind) = ResellerResolver::customerFilter($this->db, $filter);
        $sql .= $filterSql . ' ORDER BY a.admin_name';
        $bind = array_merge($bind, $filterBind);

        $ids = array();

        foreach ($this->db->rows($sql, $bind) as $row) {
            $ids[] = (int)$row['admin_id'];
        }

        $total = count($ids);
        // Decision D6 does not apply at the root: there is one list, so the
        // page could have been a LIMIT. It is applied here anyway so that
        // totalCount and the page come from the one query the ids cost.
        $wanted = array_slice($ids, $page['offset'], $page['limit']);

        return $this->customers->references($wanted)->then(
            static function ($nodes) use ($total) {
                return array(
                    'totalCount' => $total,
                    'nodes'      => $nodes === null ? array() : $nodes
                );
            }
        );
    }

    public function resolveResellers($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::RESELLERS_READ);
        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $page = TypeResolver::page($args['page'] ?? null);

        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            // A customer has no business enumerating resellers, and an empty
            // list is the honest answer to "which resellers may I see": their
            // own is reachable by identifier and through Viewer, not by
            // listing every reseller on the box.
            return new Deferred(static function () {
                return array('totalCount' => 0, 'nodes' => array());
            });
        }

        $sql = '
            SELECT a.admin_id
            FROM admin AS a
            JOIN reseller_props AS p ON p.reseller_id = a.admin_id
            WHERE a.admin_type = ?
        ';
        $bind = array('reseller');

        if ($identity->getRole() === Identity::ROLE_RESELLER) {
            $sql .= ' AND a.admin_id = ?';
            $bind[] = $identity->getAdminId();
        }

        if (isset($filter['username']) && $filter['username'] !== '') {
            $sql .= ' AND a.admin_name LIKE ?';
            $bind[] = '%' . str_replace(
                array('\\', '%', '_'), array('\\\\', '\\%', '\\_'),
                (string)$filter['username']
            ) . '%';
        }

        list($stateSql, $stateBind) = ProvisioningFilter::clause(
            'a.admin_status', $filter['state'] ?? null
        );
        $sql .= $stateSql . ' ORDER BY a.admin_name';
        $bind = array_merge($bind, $stateBind);

        $ids = array();

        foreach ($this->db->rows($sql, $bind) as $row) {
            $ids[] = (int)$row['admin_id'];
        }

        $total = count($ids);
        $wanted = array_slice($ids, $page['offset'], $page['limit']);

        return $this->resellers->references($wanted)->then(
            static function ($nodes) use ($total) {
                return array(
                    'totalCount' => $total,
                    'nodes'      => $nodes === null ? array() : $nodes
                );
            }
        );
    }

    /**
     * Query.pending - everything the caller owns that the backend has not
     * finished with (spec section 8.2).
     *
     * Eight queries whatever the number of customers, because
     * VirtualHosts::pendingFor() takes the whole set at once. A reseller with
     * two hundred customers pays the same as a customer with one.
     */
    public function resolvePending($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $identity = TypeResolver::requireScope($context, Scope::ACCOUNT_READ);
        $adminIds = $this->pendingScope($identity);

        if ($adminIds === array()) {
            return new Deferred(static function () {
                // [Node!]! - an administrator of an empty box gets a list, not
                // a null.
                return array();
            });
        }

        $references = array();

        // Every reference is created here, before anything is awaited, so all
        // of them are in their buckets when the first one flushes. The
        // gathering below only decides the order results are collected in, not
        // when they are fetched.
        foreach ($this->vhosts->pendingFor($adminIds) as $pending) {
            $reference = $this->nodeReference($pending['tag'], $pending['key']);

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        $gathered = new Deferred(static function () {
            return array();
        });

        foreach ($references as $reference) {
            // Chained rather than collected by reading ->result: a promise
            // returned by then() is a child that the queue only reaches after
            // its parent has resolved, so a Deferred that read their results
            // directly would read a list of nulls. SyncPromise::resolve()
            // adopts a returned thenable, which is what makes this work.
            $gathered = $gathered->then(
                static function (array $nodes) use ($reference) {
                    return $reference->then(
                        static function ($node) use ($nodes) {
                            if ($node !== null) {
                                $nodes[] = $node;
                            }

                            return $nodes;
                        }
                    );
                }
            );
        }

        return $gathered;
    }

    /**
     * Whose unsettled objects this caller may see.
     *
     * @return int[] admin_ids of customers
     */
    private function pendingScope(Identity $identity): array
    {
        if ($identity->getRole() === Identity::ROLE_CUSTOMER) {
            return array($identity->getAdminId());
        }

        $sql = "SELECT admin_id FROM admin WHERE admin_type = 'user'";
        $bind = array();

        if ($identity->getRole() === Identity::ROLE_RESELLER) {
            $sql .= ' AND created_by = ?';
            $bind[] = $identity->getAdminId();
        }

        $ids = array();

        foreach ($this->db->rows($sql, $bind) as $row) {
            $ids[] = (int)$row['admin_id'];
        }

        return $ids;
    }

    /**
     * @throws ApiException NOT_FOUND for anything that is not a usable identifier
     */
    private static function decode(string $encoded, ?string $expected = null): GlobalId
    {
        try {
            // decodeKey() rather than decode(): ftp_users' key is a string
            // (decision D2), and decode() would reject it. getId() still
            // refuses a non-numeric key, so a caller expecting an integer
            // cannot be handed a string one by accident.
            $globalId = GlobalId::decodeKey($encoded, $expected);
        } catch (\Exception $e) {
            // A malformed identifier is NOT_FOUND, not BAD_USER_INPUT: spec
            // section 6.3 wants an unreachable object and a nonexistent one to
            // be indistinguishable, and "this is not a valid id" is a third
            // answer a caller could use to tell them apart.
            throw self::notFound();
        }

        if (!NodeType::isKnown($globalId->getType())) {
            throw self::notFound();
        }

        return $globalId;
    }

    private static function notFound(): ApiException
    {
        return new ApiException(
            ErrorCode::NOT_FOUND, 'No such object, or it is not yours to read.'
        );
    }
}
