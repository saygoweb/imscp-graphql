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
 * i-MSCP's `admin` table: administrators, resellers and customers alike, told
 * apart by admin_type.
 *
 * Property names are the column names verbatim. Anorm's IN-clause batch loaders
 * use the same string as a PHP property on one side and a SQL column on the
 * other, so any translation between the two would be wrong on one side.
 */
class AdminModel extends Model
{
    const TABLE = 'admin';
    const PRIMARY_KEY = 'admin_id';

    const COLUMNS = array(
        'admin_id', 'admin_name', 'admin_type', 'admin_sys_name', 'domain_created',
        'customer_id', 'created_by', 'fname', 'lname', 'gender', 'firm', 'zip',
        'city', 'state', 'country', 'email', 'phone', 'fax', 'street1', 'street2',
        'admin_status'
    );

    // Untyped on purpose: PDO::FETCH_ASSOC hands back strings.
    public $admin_id;
    public $admin_name;
    public $admin_type;
    public $admin_sys_name;
    public $domain_created;
    public $customer_id;
    public $created_by;
    public $fname;
    public $lname;
    public $gender;
    public $firm;
    public $zip;
    public $city;
    public $state;
    public $country;
    public $email;
    public $phone;
    public $fax;
    public $street1;
    public $street2;
    public $admin_status;

    /** @var AdminModel|null */
    public $reseller;

    /** @var DomainModel[]|null */
    public $domains;

    /** @var FtpUserModel[]|null */
    public $ftpUsers;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        // admin.created_by is the account that created this one: the reseller
        // for a customer, the administrator for a reseller. Spec section 2.2.
        $this->belongsTo(AdminModel::class, 'created_by', 'admin_id', 'reseller');
        $this->hasMany(DomainModel::class, 'domain_admin_id', 'admin_id', 'domains');
        // ftp_users hangs off the customer, not off the domain.
        $this->hasMany(FtpUserModel::class, 'admin_id', 'admin_id', 'ftpUsers');
    }
}
