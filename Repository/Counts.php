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

/**
 * The counts behind spec section 7.4's Quota.used, one query per rule
 * regardless of how many customers were asked about.
 *
 * CORE-DEBT(C10): transcribed from gui/include/Counting.php:467-600. The core
 *   functions take one customer at a time, so a reseller's customer list would
 *   be six queries per customer here - the N+1 that spec section 10.1 calls a
 *   defect. Retire this copy when C10 lands the batched forms in core.
 *
 * Not final, so that a resolver test can subclass it.
 */
class Counts
{
    /** @var Db */
    private $db;

    /** @var bool Registry config COUNT_DEFAULT_EMAIL_ADDRESSES. */
    private $countDefaultMailAccounts;

    public function __construct(Db $db, bool $countDefaultMailAccounts)
    {
        $this->db = $db;
        $this->countDefaultMailAccounts = $countDefaultMailAccounts;
    }

    /**
     * Counting.php:467. A customer's subdomain allowance covers subdomains of
     * the main domain and subdomains of its aliases alike, so both tables are
     * counted and the two maps are added.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function subdomains(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        $direct = $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(subdomain_id) AS n
            FROM subdomain WHERE domain_id IN ($in) AND subdomain_status <> 'todelete'
            GROUP BY domain_id
        ", $domainIds);

        $viaAliases = $this->map($domainIds, "
            SELECT a.domain_id AS k, COUNT(sa.subdomain_alias_id) AS n
            FROM subdomain_alias AS sa
            JOIN domain_aliasses AS a ON a.alias_id = sa.alias_id
            WHERE a.domain_id IN ($in) AND sa.subdomain_alias_status <> 'todelete'
            GROUP BY a.domain_id
        ", $domainIds);

        $totals = array();

        foreach ($domainIds as $domainId) {
            $totals[$domainId] = $direct[$domainId] + $viaAliases[$domainId];
        }

        return $totals;
    }

    /**
     * Counting.php:497. An ordered alias is awaiting the reseller's approval
     * and is not yet the customer's.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function domainAliases(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        return $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(alias_id) AS n
            FROM domain_aliasses
            WHERE domain_id IN ($in) AND alias_status NOT IN('ordered', 'todelete')
            GROUP BY domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:518. Whether the default forwards - abuse, hostmaster,
     * postmaster and webmaster - count against the limit is an administrator
     * setting, and a customer who turns one into a normal account makes it
     * count from then on.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function mailAccounts(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));
        $excludeDefaults = '';

        if (!$this->countDefaultMailAccounts) {
            $excludeDefaults = "
                AND !(
                    mail_acc IN('abuse', 'hostmaster', 'postmaster', 'webmaster')
                    AND mail_type IN('normal_forward', 'alias_forward')
                )
                AND !(
                    mail_acc = 'webmaster'
                    AND mail_type IN('subdom_forward', 'alssub_forward')
                )
            ";
        }

        return $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(mail_id) AS n
            FROM mail_users
            WHERE domain_id IN ($in) $excludeDefaults AND status <> 'todelete'
            GROUP BY domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:572. sql_database has no status column - it is created
     * synchronously by the panel (spec section 2.1) - so there is nothing to
     * exclude.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function sqlDatabases(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        return $this->map($domainIds, "
            SELECT domain_id AS k, COUNT(sqld_id) AS n
            FROM sql_database
            WHERE domain_id IN ($in)
            GROUP BY domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:586. One SQL user may be granted on several databases and
     * still counts once against the limit, hence DISTINCT.
     *
     * @param int[] $domainIds
     * @return array<int, int>
     */
    public function sqlUsers(array $domainIds): array
    {
        if ($domainIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($domainIds));

        return $this->map($domainIds, "
            SELECT sd.domain_id AS k, COUNT(DISTINCT sqlu_name) AS n
            FROM sql_user AS su
            JOIN sql_database AS sd ON sd.sqld_id = su.sqld_id
            WHERE sd.domain_id IN ($in)
            GROUP BY sd.domain_id
        ", $domainIds);
    }

    /**
     * Counting.php:553. Keyed by the customer's admin_id, because ftp_users
     * hangs off the customer and not off the domain (spec section 2.2).
     *
     * @param int[] $adminIds
     * @return array<int, int>
     */
    public function ftpUsers(array $adminIds): array
    {
        if ($adminIds === array()) {
            return array();
        }

        $in = $this->db->placeholders(count($adminIds));

        return $this->map($adminIds, "
            SELECT admin_id AS k, COUNT(userid) AS n
            FROM ftp_users
            WHERE admin_id IN ($in) AND status <> 'todelete'
            GROUP BY admin_id
        ", $adminIds);
    }

    /**
     * Run one grouped count and return a map with every requested key present,
     * so a resolver never has to tell "no rows" from "not asked".
     *
     * @param int[] $keys
     * @return array<int, int>
     */
    private function map(array $keys, string $sql, array $bind): array
    {
        $counts = array_fill_keys($keys, 0);

        foreach ($this->db->rows($sql, $bind) as $row) {
            $counts[(int)$row['k']] = (int)$row['n'];
        }

        return $counts;
    }
}
