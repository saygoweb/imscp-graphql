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

/** i-MSCP's `domain_aliasses` table. The spelling is i-MSCP's. */
class DomainAliasModel extends Model
{
    const TABLE = 'domain_aliasses';
    const PRIMARY_KEY = 'alias_id';

    const COLUMNS = array(
        'alias_id', 'domain_id', 'alias_name', 'alias_status', 'alias_mount',
        'alias_document_root', 'alias_ip_id', 'url_forward', 'type_forward',
        'host_forward', 'wildcard_alias'
    );

    public $alias_id;
    public $domain_id;
    public $alias_name;
    public $alias_status;
    public $alias_mount;
    public $alias_document_root;
    public $alias_ip_id;
    public $url_forward;
    public $type_forward;
    public $host_forward;
    public $wildcard_alias;

    /** @var DomainModel|null */
    public $domain;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainModel::class, 'domain_id', 'domain_id', 'domain');
    }
}
