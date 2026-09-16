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
 * i-MSCP's `ftp_users` table.
 *
 * Its primary key is userid varchar(255) - 'user@domain' - which is why
 * Support\GlobalId grew encodeKey()/decodeKey() (decision D2). It is also the
 * one object that hangs off the customer rather than off the domain.
 */
class FtpUserModel extends Model
{
    const TABLE = 'ftp_users';
    const PRIMARY_KEY = 'userid';

    const COLUMNS = array('userid', 'admin_id', 'uid', 'gid', 'shell', 'homedir', 'status');

    public $userid;
    public $admin_id;
    public $uid;
    public $gid;
    public $shell;
    public $homedir;
    public $status;

    /** @var AdminModel|null */
    public $customer;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'admin_id', 'admin_id', 'customer');
    }
}
