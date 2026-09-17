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

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Service\CustomerAccount;

/**
 * The owning customer, read fresh.
 *
 * CORE-DEBT(C1): stands in for get_domain_default_props()
 *   (gui/include/Shared.php:283), which caches its row in a static for the
 *   whole request with no key (measurement M6). Retire when C1 gives it an
 *   explicit $adminId and a keyed cache.
 */
final class Accounts
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND when the
     *         id is not a customer with a domain. Ownership has already been
     *         resolved by the time a service asks, so this only happens when
     *         the account vanished in between - and NOT_FOUND is still true.
     */
    public function customer(int $adminId): CustomerAccount
    {
        // The admin columns are listed rather than a.*: `admin` and `domain`
        // both carry domain_created, and d.* must win for it.
        $row = $this->db->row(
            "
                SELECT a.admin_id, a.admin_name, a.created_by, a.email,
                    a.admin_sys_uid, a.admin_sys_gid, d.*
                FROM admin AS a
                JOIN domain AS d ON d.domain_admin_id = a.admin_id
                WHERE a.admin_id = ? AND a.admin_type = 'user'
            ",
            array($adminId)
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        $admin = array();

        foreach (array('admin_id', 'admin_name', 'created_by', 'email', 'admin_sys_uid', 'admin_sys_gid') as $column) {
            $admin[$column] = $row[$column];
            unset($row[$column]);
        }

        return new CustomerAccount($admin, $row);
    }
}
