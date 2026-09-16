<?php
namespace iMSCP\Plugin\SGW_GraphQL\Model;

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

use Anorm\DataMapper;
use Anorm\Model;
use PDO;

/**
 * i-MSCP's `reseller_props` table: a reseller's limits and current counts.
 *
 * Its limit encoding is not the customer one: 0 means unlimited and there is no
 * withheld state (spec section 2.3), which is why Support\Quota has a separate
 * fromResellerLimit(). max_disk_amnt and max_traff_amnt are in MiB
 * (gui/public/reseller/index.php:158,177 multiply both by 1048576).
 */
class ResellerPropsModel extends Model
{
    const TABLE = 'reseller_props';
    const PRIMARY_KEY = 'id';

    const COLUMNS = array(
        'id', 'reseller_id', 'current_dmn_cnt', 'max_dmn_cnt', 'current_sub_cnt',
        'max_sub_cnt', 'current_als_cnt', 'max_als_cnt', 'current_mail_cnt',
        'max_mail_cnt', 'current_ftp_cnt', 'max_ftp_cnt', 'current_sql_db_cnt',
        'max_sql_db_cnt', 'current_sql_user_cnt', 'max_sql_user_cnt',
        'current_disk_amnt', 'max_disk_amnt', 'current_traff_amnt',
        'max_traff_amnt', 'support_system', 'reseller_ips'
    );

    public $id;
    public $reseller_id;
    public $current_dmn_cnt;
    public $max_dmn_cnt;
    public $current_sub_cnt;
    public $max_sub_cnt;
    public $current_als_cnt;
    public $max_als_cnt;
    public $current_mail_cnt;
    public $max_mail_cnt;
    public $current_ftp_cnt;
    public $max_ftp_cnt;
    public $current_sql_db_cnt;
    public $max_sql_db_cnt;
    public $current_sql_user_cnt;
    public $max_sql_user_cnt;
    public $current_disk_amnt;
    public $max_disk_amnt;
    public $current_traff_amnt;
    public $max_traff_amnt;
    public $support_system;
    public $reseller_ips;

    /** @var AdminModel|null */
    public $reseller;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'reseller_id', 'admin_id', 'reseller');
    }
}
