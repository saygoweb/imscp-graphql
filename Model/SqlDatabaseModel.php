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
 * i-MSCP's `sql_database` table.
 *
 * There is no status column: SQL databases are created synchronously by the
 * panel rather than by the backend (spec section 2.1), which is why
 * SqlDatabase does not implement Provisioned.
 */
class SqlDatabaseModel extends Model
{
    const TABLE = 'sql_database';
    const PRIMARY_KEY = 'sqld_id';

    const COLUMNS = array('sqld_id', 'domain_id', 'sqld_name');

    public $sqld_id;
    public $domain_id;
    public $sqld_name;

    /** @var DomainModel|null */
    public $domain;

    /** @var SqlUserModel[]|null */
    public $users;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
        $this->hasMany(SqlUserModel::class, 'sqld_id', 'sqld_id', 'users');
    }
}
