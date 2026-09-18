<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use InvalidArgumentException;

/**
 * A reseller's `admin` row and its `reseller_props` row, read once and passed
 * down. The counters are the panel's (M9) and this class never writes them:
 * update_reseller_c_props() is their only writer (decision D25).
 */
final class ResellerAccount
{
    /** Allowance name => the pair of reseller_props columns holding it. */
    const COLUMNS = array(
        'subdomains'    => array('max_sub_cnt', 'current_sub_cnt'),
        'domainAliases' => array('max_als_cnt', 'current_als_cnt'),
        'mailAccounts'  => array('max_mail_cnt', 'current_mail_cnt'),
        'ftpUsers'      => array('max_ftp_cnt', 'current_ftp_cnt'),
        'sqlDatabases'  => array('max_sql_db_cnt', 'current_sql_db_cnt'),
        'sqlUsers'      => array('max_sql_user_cnt', 'current_sql_user_cnt'),
        'customers'     => array('max_dmn_cnt', 'current_dmn_cnt'),
        'disk'          => array('max_disk_amnt', 'current_disk_amnt'),
        'traffic'       => array('max_traff_amnt', 'current_traff_amnt')
    );

    /** @var array */
    private $admin;

    /** @var array */
    private $props;

    public function __construct(array $admin, array $props)
    {
        $this->admin = $admin;
        $this->props = $props;
    }

    public function getAdminId(): int
    {
        return (int)$this->admin['admin_id'];
    }

    public function getUsername(): string
    {
        return (string)$this->admin['admin_name'];
    }

    public function getEmail(): string
    {
        return (string)$this->admin['email'];
    }

    /** A reseller_props column, exactly as stored. */
    public function prop(string $column)
    {
        if (!array_key_exists($column, $this->props)) {
            throw new InvalidArgumentException(sprintf('reseller_props has no "%s".', $column));
        }

        return $this->props[$column];
    }

    public function maxOf(string $allowance): int
    {
        return (int)$this->prop(self::column($allowance, 0));
    }

    public function usedOf(string $allowance): int
    {
        return (int)$this->prop(self::column($allowance, 1));
    }

    /**
     * M17: "1;7;" - sorted ids, trailing separator. An empty list is no ids,
     * not one empty one, which is why the filter is not optional.
     *
     * @return int[]
     */
    public function ipIds(): array
    {
        $raw = array_filter(explode(';', (string)$this->prop('reseller_ips')), static function (string $one): bool {
            return $one !== '';
        });

        return array_map('intval', array_values($raw));
    }

    public function hasIp(int $ipId): bool
    {
        return in_array($ipId, $this->ipIds(), true);
    }

    private static function column(string $allowance, int $which): string
    {
        if (!isset(self::COLUMNS[$allowance])) {
            throw new InvalidArgumentException(sprintf('There is no "%s" allowance.', $allowance));
        }

        return self::COLUMNS[$allowance][$which];
    }
}
