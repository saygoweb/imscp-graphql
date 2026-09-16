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
 * i-MSCP's `sql_user` table.
 *
 * One row per (user, database) grant, so the same sqlu_name appears once per
 * database it may reach. Spec section 7.7's SqlUser.databases is therefore a
 * list, and Counting.php counts DISTINCT sqlu_name against the limit.
 */
class SqlUserModel extends Model
{
    const TABLE = 'sql_user';
    const PRIMARY_KEY = 'sqlu_id';

    const COLUMNS = array('sqlu_id', 'sqld_id', 'sqlu_name', 'sqlu_host');

    public $sqlu_id;
    public $sqld_id;
    public $sqlu_name;
    public $sqlu_host;

    /** @var SqlDatabaseModel|null */
    public $database;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(SqlDatabaseModel::class, 'sqld_id', 'sqld_id', 'database');
    }
}
