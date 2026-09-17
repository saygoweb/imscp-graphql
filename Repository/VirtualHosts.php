<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

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

use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use InvalidArgumentException;

/**
 * The four-way dmn/sub/als/alssub union behind spec section 7.3's VirtualHost
 * interface, and the unsettled-object sweep behind Query.pending.
 *
 * i-MSCP keys a vhost by the pair (type, id) with type in dmn|sub|als|alssub,
 * and the four tables differ in almost nothing but their column prefixes. The
 * union is written out here, once, so that no resolver has four code paths.
 *
 * This is where subdomains come from rather than from an Anorm relationship:
 * `subdomain` stores the label alone ('shop'), and the fully qualified name is
 * that label joined to the parent's name, which a single-table IN-clause SELECT
 * cannot produce.
 *
 * Not final, so that a resolver test can subclass it.
 */
class VirtualHosts
{
    const KIND_DMN    = 'dmn';
    const KIND_SUB    = 'sub';
    const KIND_ALS    = 'als';
    const KIND_ALSSUB = 'alssub';

    /** i-MSCP's vhost kind => this API's identifier tag. */
    const TAGS = array(
        self::KIND_DMN    => NodeType::DOMAIN,
        self::KIND_SUB    => NodeType::SUBDOMAIN,
        self::KIND_ALS    => NodeType::DOMAIN_ALIAS,
        self::KIND_ALSSUB => NodeType::ALIAS_SUBDOMAIN
    );

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function tagFor(string $kind): string
    {
        if (!isset(self::TAGS[$kind])) {
            throw new InvalidArgumentException(sprintf('Unknown vhost kind "%s".', $kind));
        }

        return self::TAGS[$kind];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function kindFor(string $tag): string
    {
        $kind = array_search($tag, self::TAGS, true);

        if ($kind === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a vhost type.', $tag));
        }

        return $kind;
    }

    /**
     * Every vhost of one kind belonging to the given customers' main domains.
     *
     * @param int[] $domainIds
     * @return array<int, array<int, array>> domainId => rows, ordered by name
     */
    public function ofKind(string $kind, array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));
        $grouped = array_fill_keys($domainIds, array());

        foreach ($this->db->rows($this->sqlFor($kind, "d.domain_id IN ($in)"), $domainIds) as $row) {
            $grouped[(int)$row['domain_id']][] = $this->normalise($kind, $row);
        }

        return $grouped;
    }

    /**
     * The subdomains of the given domain aliases.
     *
     * Keyed by alias_id rather than by domain_id, because that is the edge
     * DomainAlias.subdomains needs.
     *
     * @param int[] $aliasIds
     * @return array<int, array<int, array>> aliasId => rows
     */
    public function aliasSubdomainsOf(array $aliasIds): array
    {
        if ($aliasIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($aliasIds));
        $grouped = array_fill_keys($aliasIds, array());
        $sql = $this->sqlFor(self::KIND_ALSSUB, "sa.alias_id IN ($in)");

        foreach ($this->db->rows($sql, $aliasIds) as $row) {
            $grouped[(int)$row['parent_key']][] = $this->normalise(self::KIND_ALSSUB, $row);
        }

        return $grouped;
    }

    /**
     * Everything Apache serves for the given customers: four queries, whatever
     * the number of customers.
     *
     * @param int[] $domainIds
     * @return array<int, array<int, array>> domainId => rows
     */
    public function forDomains(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $grouped = array_fill_keys($domainIds, array());

        foreach (array_keys(self::TAGS) as $kind) {
            foreach ($this->ofKind($kind, $domainIds) as $domainId => $rows) {
                foreach ($rows as $row) {
                    $grouped[$domainId][] = $row;
                }
            }
        }

        return $grouped;
    }

    /**
     * Look up individual vhosts by (kind, id): one query per kind present,
     * never one per identifier.
     *
     * @param array<int, array{0: string, 1: int}> $keys
     * @return array<string, array> "kind:id" => row
     */
    public function byKeys(array $keys): array
    {
        $wanted = array();

        foreach ($keys as $key) {
            $wanted[$key[0]][(int)$key[1]] = true;
        }

        $found = array();

        foreach ($wanted as $kind => $ids) {
            $ids = array_keys($ids);
            $in = $this->db->placeholders(count($ids));
            $sql = $this->sqlFor($kind, $this->keyColumn($kind) . " IN ($in)");

            foreach ($this->db->rows($sql, $ids) as $row) {
                $normalised = $this->normalise($kind, $row);
                $found[$kind . ':' . $normalised['key']] = $normalised;
            }
        }

        return $found;
    }

    /**
     * Whether a fully qualified ASCII name is already any vhost's, of any
     * kind and in any state.
     *
     * Spec section 8.4 relies on unique keys to turn a retried create into
     * CONFLICT, but none of subdomain, subdomain_alias or domain_aliasses has
     * one on its name (measurement M9). This is that check, asked explicitly.
     */
    public function nameInUse(string $asciiName): bool
    {
        return $this->db->row(
            "
                SELECT 1 FROM domain WHERE domain_name = ?
                UNION ALL
                SELECT 1 FROM domain_aliasses WHERE alias_name = ?
                UNION ALL
                SELECT 1 FROM subdomain AS s JOIN domain AS d ON d.domain_id = s.domain_id
                WHERE CONCAT(s.subdomain_name, '.', d.domain_name) = ?
                UNION ALL
                SELECT 1 FROM subdomain_alias AS sa JOIN domain_aliasses AS al ON al.alias_id = sa.alias_id
                WHERE CONCAT(sa.subdomain_alias_name, '.', al.alias_name) = ?
                LIMIT 1
            ",
            array($asciiName, $asciiName, $asciiName, $asciiName)
        ) !== null;
    }

    /**
     * Every object of the given customers that the backend has not finished
     * with. Spec section 8.2, and Query.pending.
     *
     * Eight queries, fixed, whatever the number of customers. The status
     * vocabulary is Provisioning::PENDING_STATUSES rather than a list written
     * here, so that the SQL and Provisioning::isSettled() cannot disagree.
     *
     * @param int[] $customerAdminIds
     * @return array<int, array{tag: string, key: int|string, status: string}>
     */
    public function pendingFor(array $customerAdminIds): array
    {
        if ($customerAdminIds === array()) {
            return array();
        }

        $customers = $this->db->placeholders(count($customerAdminIds));
        $states = $this->db->placeholders(count(Provisioning::PENDING_STATUSES));
        $bind = array_merge($customerAdminIds, Provisioning::PENDING_STATUSES);

        $queries = array(
            NodeType::CUSTOMER => "
                SELECT a.admin_id AS k, a.admin_status AS s
                FROM admin AS a
                WHERE a.admin_id IN ($customers) AND a.admin_status IN ($states)
            ",
            NodeType::DOMAIN => "
                SELECT d.domain_id AS k, d.domain_status AS s
                FROM domain AS d
                WHERE d.domain_admin_id IN ($customers) AND d.domain_status IN ($states)
            ",
            NodeType::SUBDOMAIN => "
                SELECT s.subdomain_id AS k, s.subdomain_status AS s
                FROM subdomain AS s
                JOIN domain AS d ON d.domain_id = s.domain_id
                WHERE d.domain_admin_id IN ($customers)
                    AND s.subdomain_status IN ($states)
            ",
            NodeType::DOMAIN_ALIAS => "
                SELECT al.alias_id AS k, al.alias_status AS s
                FROM domain_aliasses AS al
                JOIN domain AS d ON d.domain_id = al.domain_id
                WHERE d.domain_admin_id IN ($customers) AND al.alias_status IN ($states)
            ",
            NodeType::ALIAS_SUBDOMAIN => "
                SELECT sa.subdomain_alias_id AS k, sa.subdomain_alias_status AS s
                FROM subdomain_alias AS sa
                JOIN domain_aliasses AS al ON al.alias_id = sa.alias_id
                JOIN domain AS d ON d.domain_id = al.domain_id
                WHERE d.domain_admin_id IN ($customers)
                    AND sa.subdomain_alias_status IN ($states)
            ",
            NodeType::MAIL_ACCOUNT => "
                SELECT m.mail_id AS k, m.status AS s
                FROM mail_users AS m
                JOIN domain AS d ON d.domain_id = m.domain_id
                WHERE d.domain_admin_id IN ($customers) AND m.status IN ($states)
            ",
            NodeType::FTP_USER => "
                SELECT f.userid AS k, f.status AS s
                FROM ftp_users AS f
                WHERE f.admin_id IN ($customers) AND f.status IN ($states)
            ",
            NodeType::DNS_RECORD => "
                SELECT dd.domain_dns_id AS k, dd.domain_dns_status AS s
                FROM domain_dns AS dd
                JOIN domain AS d ON d.domain_id = dd.domain_id
                WHERE d.domain_admin_id IN ($customers)
                    AND dd.domain_dns_status IN ($states)
            "
        );

        $pending = array();

        foreach ($queries as $tag => $sql) {
            foreach ($this->db->rows($sql, $bind) as $row) {
                $pending[] = array(
                    'tag'    => $tag,
                    // FtpUser is keyed by ftp_users.userid, a varchar, and
                    // stays a string. Every other kind here is an
                    // auto-increment int primary key, matching normalise(),
                    // and must be cast the same way: PDO returns every
                    // column as a string, so leaving this uncast makes the
                    // same object's key a numeric string from pendingFor()
                    // but an int from byKeys()/ofKind(), which fails a
                    // strict (===) comparison.
                    'key'    => $tag === NodeType::FTP_USER ? (string)$row['k'] : (int)$row['k'],
                    'status' => (string)$row['s']
                );
            }
        }

        return $pending;
    }

    /**
     * The primary key column of one vhost kind, qualified with the alias the
     * SELECT for that kind uses.
     *
     * @throws InvalidArgumentException
     */
    private function keyColumn(string $kind): string
    {
        $columns = array(
            self::KIND_DMN    => 'd.domain_id',
            self::KIND_SUB    => 's.subdomain_id',
            self::KIND_ALS    => 'al.alias_id',
            self::KIND_ALSSUB => 'sa.subdomain_alias_id'
        );

        if (!isset($columns[$kind])) {
            throw new InvalidArgumentException(sprintf('Unknown vhost kind "%s".', $kind));
        }

        return $columns[$kind];
    }

    /**
     * One SELECT per vhost kind, every one producing the same column names, so
     * that normalise() has a single shape to work on.
     *
     * @throws InvalidArgumentException
     */
    private function sqlFor(string $kind, string $where): string
    {
        switch ($kind) {
            case self::KIND_DMN:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        d.domain_id AS vhost_key, d.domain_name AS name,
                        NULL AS label, NULL AS parent_key,
                        '/' AS mount_point, d.document_root AS document_root,
                        d.url_forward AS url_forward, d.type_forward AS type_forward,
                        d.host_forward AS host_forward,
                        d.wildcard_alias AS wildcard, d.domain_status AS status,
                        d.domain_ip_id AS ip_id
                    FROM domain AS d
                    WHERE $where
                    ORDER BY d.domain_name
                ";
            case self::KIND_SUB:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        s.subdomain_id AS vhost_key,
                        CONCAT(s.subdomain_name, '.', d.domain_name) AS name,
                        s.subdomain_name AS label, d.domain_id AS parent_key,
                        s.subdomain_mount AS mount_point,
                        s.subdomain_document_root AS document_root,
                        s.subdomain_url_forward AS url_forward,
                        s.subdomain_type_forward AS type_forward,
                        s.subdomain_host_forward AS host_forward,
                        s.subdomain_wildcard_alias AS wildcard,
                        s.subdomain_status AS status, NULL AS ip_id
                    FROM subdomain AS s
                    JOIN domain AS d ON d.domain_id = s.domain_id
                    WHERE $where
                    ORDER BY name
                ";
            case self::KIND_ALS:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        al.alias_id AS vhost_key, al.alias_name AS name,
                        NULL AS label, d.domain_id AS parent_key,
                        al.alias_mount AS mount_point,
                        al.alias_document_root AS document_root,
                        al.url_forward AS url_forward, al.type_forward AS type_forward,
                        al.host_forward AS host_forward,
                        al.wildcard_alias AS wildcard, al.alias_status AS status,
                        al.alias_ip_id AS ip_id
                    FROM domain_aliasses AS al
                    JOIN domain AS d ON d.domain_id = al.domain_id
                    WHERE $where
                    ORDER BY al.alias_name
                ";
            case self::KIND_ALSSUB:
                return "
                    SELECT d.domain_id AS domain_id, d.domain_admin_id AS owner_id,
                        sa.subdomain_alias_id AS vhost_key,
                        CONCAT(sa.subdomain_alias_name, '.', al.alias_name) AS name,
                        sa.subdomain_alias_name AS label, al.alias_id AS parent_key,
                        sa.subdomain_alias_mount AS mount_point,
                        sa.subdomain_alias_document_root AS document_root,
                        sa.subdomain_alias_url_forward AS url_forward,
                        sa.subdomain_alias_type_forward AS type_forward,
                        sa.subdomain_alias_host_forward AS host_forward,
                        sa.subdomain_alias_wildcard_alias AS wildcard,
                        sa.subdomain_alias_status AS status, NULL AS ip_id
                    FROM subdomain_alias AS sa
                    JOIN domain_aliasses AS al ON al.alias_id = sa.alias_id
                    JOIN domain AS d ON d.domain_id = al.domain_id
                    WHERE $where
                    ORDER BY name
                ";
            default:
                throw new InvalidArgumentException(sprintf(
                    'Unknown vhost kind "%s".', $kind
                ));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalise(string $kind, array $row): array
    {
        $parentTag = null;

        if ($kind === self::KIND_SUB || $kind === self::KIND_ALS) {
            $parentTag = NodeType::DOMAIN;
        } elseif ($kind === self::KIND_ALSSUB) {
            $parentTag = NodeType::DOMAIN_ALIAS;
        }

        return array(
            'tag'          => self::TAGS[$kind],
            'kind'         => $kind,
            'key'          => (int)$row['vhost_key'],
            'domainId'     => (int)$row['domain_id'],
            'ownerId'      => (int)$row['owner_id'],
            'name'         => (string)$row['name'],
            'label'        => $row['label'] === null ? null : (string)$row['label'],
            'parentTag'    => $parentTag,
            'parentKey'    => $row['parent_key'] === null ? null : (int)$row['parent_key'],
            'mountPoint'   => (string)$row['mount_point'],
            'documentRoot' => (string)$row['document_root'],
            'urlForward'   => (string)$row['url_forward'],
            'typeForward'  => $row['type_forward'] === null ? null : (string)$row['type_forward'],
            'hostForward'  => (string)$row['host_forward'],
            'wildcard'     => $row['wildcard'] === 'yes',
            'status'       => (string)$row['status'],
            'ipId'         => $row['ip_id'] === null ? null : (int)$row['ip_id']
        );
    }
}
