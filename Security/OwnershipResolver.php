<?php
namespace iMSCP\Plugin\SGW_GraphQL\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;

/**
 * Resolves any global identifier to the account that owns it, and answers
 * whether a caller may reach it.
 *
 * Spec section 2.2 draws the chain; this class is one query per object type
 * derived from it, and spec section 6.3 requires it to be asked before
 * anything else - before feature checks, before quota checks, before
 * validation - so that the error a caller gets leaks the least.
 *
 * A caller who may not reach an object gets NOT_FOUND, never FORBIDDEN, so the
 * API does not confirm the existence of other people's objects.
 */
final class OwnershipResolver
{
    /**
     * One query per object type, each returning a single `owner_id` column
     * holding the owning customer's admin_id.
     */
    const OWNER_QUERIES = array(
        NodeType::CUSTOMER => "
            SELECT admin_id AS owner_id
            FROM admin WHERE admin_id = ? AND admin_type = 'user'
        ",
        NodeType::DOMAIN => '
            SELECT domain_admin_id AS owner_id
            FROM domain WHERE domain_id = ?
        ',
        NodeType::SUBDOMAIN => '
            SELECT d.domain_admin_id AS owner_id
            FROM subdomain AS s
            JOIN domain AS d ON d.domain_id = s.domain_id
            WHERE s.subdomain_id = ?
        ',
        NodeType::ALIAS_SUBDOMAIN => '
            SELECT d.domain_admin_id AS owner_id
            FROM subdomain_alias AS sa
            JOIN domain_aliasses AS a ON a.alias_id = sa.alias_id
            JOIN domain AS d ON d.domain_id = a.domain_id
            WHERE sa.subdomain_alias_id = ?
        ',
        NodeType::DOMAIN_ALIAS => '
            SELECT d.domain_admin_id AS owner_id
            FROM domain_aliasses AS a
            JOIN domain AS d ON d.domain_id = a.domain_id
            WHERE a.alias_id = ?
        ',
        NodeType::MAIL_ACCOUNT => '
            SELECT d.domain_admin_id AS owner_id
            FROM mail_users AS m
            JOIN domain AS d ON d.domain_id = m.domain_id
            WHERE m.mail_id = ?
        ',
        // ftp_users hangs off the customer, not off the domain. Spec section 2.2.
        NodeType::FTP_USER => '
            SELECT f.admin_id AS owner_id
            FROM ftp_users AS f
            WHERE f.userid = ?
        ',
        NodeType::SQL_DATABASE => '
            SELECT d.domain_admin_id AS owner_id
            FROM sql_database AS sd
            JOIN domain AS d ON d.domain_id = sd.domain_id
            WHERE sd.sqld_id = ?
        ',
        NodeType::SQL_USER => '
            SELECT d.domain_admin_id AS owner_id
            FROM sql_user AS su
            JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
            JOIN domain AS d ON d.domain_id = sd.domain_id
            WHERE su.sqlu_id = ?
        ',
        NodeType::DNS_RECORD => '
            SELECT d.domain_admin_id AS owner_id
            FROM domain_dns AS dd
            JOIN domain AS d ON d.domain_id = dd.domain_id
            WHERE dd.domain_dns_id = ?
        '
    );

    /** Reseller-owned objects, resolved one level up the same chain. */
    const RESELLER_QUERIES = array(
        NodeType::RESELLER => "
            SELECT admin_id AS owner_id
            FROM admin WHERE admin_id = ? AND admin_type = 'reseller'
        ",
        NodeType::HOSTING_PLAN => '
            SELECT reseller_id AS owner_id
            FROM hosting_plans WHERE id = ?
        '
    );

    /** @var callable fn(string $sql, array $bind): array<int, array<string, mixed>> */
    private $query;

    /** @var array<string, int|null> */
    private $owners = array();

    /** @var array<int, int|null> */
    private $resellers = array();

    public function __construct(callable $query)
    {
        $this->query = $query;
    }

    /**
     * The owning customer's admin_id, or null when there is no such object.
     *
     * @throws InvalidArgumentException for a type tag that is not customer-owned
     */
    public function ownerOf(GlobalId $id): ?int
    {
        $tag = $id->getType();

        if (!isset(self::OWNER_QUERIES[$tag])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a customer-owned type.', $tag
            ));
        }

        $memo = $tag . ':' . $id->getKey();

        if (!array_key_exists($memo, $this->owners)) {
            $rows = $this->run(self::OWNER_QUERIES[$tag], array(self::bindValue($id)));
            $this->owners[$memo] = isset($rows[0]['owner_id'])
                ? (int)$rows[0]['owner_id'] : null;
        }

        return $this->owners[$memo];
    }

    /**
     * The owning reseller's admin_id, or null when there is no such object.
     *
     * @throws InvalidArgumentException for a type tag that is not reseller-owned
     */
    public function resellerOf(GlobalId $id): ?int
    {
        $tag = $id->getType();

        if (!isset(self::RESELLER_QUERIES[$tag])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a reseller-owned type.', $tag
            ));
        }

        $memo = $tag . ':' . $id->getKey();

        if (!array_key_exists($memo, $this->owners)) {
            $rows = $this->run(self::RESELLER_QUERIES[$tag], array(self::bindValue($id)));
            $this->owners[$memo] = isset($rows[0]['owner_id'])
                ? (int)$rows[0]['owner_id'] : null;
        }

        return $this->owners[$memo];
    }

    /**
     * The value bound into an owner/reseller query: the raw string key for a
     * string-keyed type (ftp_users.userid), the integer id for everything
     * else, so a caller binding straight into a typed column gets the type
     * that column expects.
     *
     * @return int|string
     */
    private static function bindValue(GlobalId $id)
    {
        return NodeType::isStringKeyed($id->getType()) ? $id->getKey() : $id->getId();
    }

    /**
     * The reseller who created the given customer, or null.
     */
    public function resellerIdOf(int $customerAdminId): ?int
    {
        if (!array_key_exists($customerAdminId, $this->resellers)) {
            $rows = $this->run(
                'SELECT created_by FROM admin WHERE admin_id = ?',
                array($customerAdminId)
            );
            $this->resellers[$customerAdminId] = isset($rows[0]['created_by'])
                ? (int)$rows[0]['created_by'] : null;
        }

        return $this->resellers[$customerAdminId];
    }

    /**
     * True when the caller is the owner, the owner's reseller, or an
     * administrator. Spec section 6.3.
     */
    public function mayReach(Identity $caller, int $ownerId): bool
    {
        if ($caller->getRole() === Identity::ROLE_ADMIN) {
            return true;
        }

        if ($caller->getAdminId() === $ownerId) {
            return true;
        }

        if ($caller->getRole() === Identity::ROLE_RESELLER) {
            return $this->resellerIdOf($ownerId) === $caller->getAdminId();
        }

        return false;
    }

    /**
     * Whether the caller may reach this reseller's object at all: the reseller
     * itself, an administrator, or a customer that reseller created.
     *
     * The customer is admitted because the schema already hands them the same
     * object: spec section 7.5 makes Customer.reseller a non-null Reseller!,
     * and the Reseller type's own description says "A customer may read the
     * identity of their own reseller - id, username and contact details - and
     * nothing else". Refusing it here made Query.reseller answer NOT_FOUND for
     * an object the caller could hold in their hand through Customer.reseller.
     *
     * "And nothing else" is not this method's business: every private field of
     * a Reseller is refused separately by ResellerResolver::requireReseller(),
     * which is FORBIDDEN rather than NOT_FOUND precisely because the object is
     * reachable. Their own reseller and no other, so this still confirms
     * nothing about identifiers the caller did not already know.
     */
    public function mayReachReseller(Identity $caller, int $resellerId): bool
    {
        if ($this->mayAdministerReseller($caller, $resellerId)) {
            return true;
        }

        // getCreatedBy() rather than a query: it is the customer's own
        // admin.created_by, read when the identity was resolved, and
        // ResellerResolver::resolveCustomers() already trusts it for the same
        // question from the other direction.
        return $caller->getRole() === Identity::ROLE_CUSTOMER
            && $caller->getCreatedBy() === $resellerId;
    }

    /**
     * Whether the caller may reach a reseller's *own* things - a hosting plan,
     * the catalogue it sells from: the reseller itself, or an administrator.
     * A customer may reach their reseller (above) but none of its property.
     */
    public function mayAdministerReseller(Identity $caller, int $resellerId): bool
    {
        return $caller->getRole() === Identity::ROLE_ADMIN
            || $caller->getAdminId() === $resellerId;
    }

    /**
     * Resolve and authorise in one call. Returns the owning account's admin_id
     * - 0 for a server-owned object, which has no owning account.
     *
     * @throws ApiException NOT_FOUND, never FORBIDDEN, for anything the caller
     *                      may not reach or that does not exist
     * @throws InvalidArgumentException for an unknown type tag
     */
    public function assertReachable(Identity $caller, GlobalId $id): int
    {
        $tag = $id->getType();

        if (in_array($tag, NodeType::SERVER_OWNED, true)) {
            // Server IP addresses belong to no account. A customer sees one
            // only as a field of their own domain, never by identifier.
            if ($caller->getRole() === Identity::ROLE_CUSTOMER) {
                throw self::notFound();
            }

            return 0;
        }

        if (in_array($tag, NodeType::RESELLER_OWNED, true)) {
            $ownerId = $this->resellerOf($id);
            // The reseller object itself and the reseller's property are two
            // different questions, and only the first admits the customer.
            $reachable = $ownerId !== null && ($tag === NodeType::RESELLER
                ? $this->mayReachReseller($caller, $ownerId)
                : $this->mayAdministerReseller($caller, $ownerId));

            if (!$reachable) {
                throw self::notFound();
            }

            return $ownerId;
        }

        $ownerId = $this->ownerOf($id);

        if ($ownerId === null || !$this->mayReach($caller, $ownerId)) {
            throw self::notFound();
        }

        return $ownerId;
    }

    private static function notFound(): ApiException
    {
        return new ApiException(ErrorCode::NOT_FOUND, 'No such object.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function run(string $sql, array $bind): array
    {
        return call_user_func($this->query, $sql, $bind);
    }
}
