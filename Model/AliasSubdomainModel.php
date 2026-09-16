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
 * i-MSCP's `subdomain_alias` table: subdomains of a domain alias.
 *
 * A separate table from `subdomain` only because `subdomain` has a domain_id
 * column. To a client this is still a Subdomain (spec section 7.3), but its
 * identifier tag is AliasSubdomain, because the two tables have separate id
 * spaces (decision D1).
 */
class AliasSubdomainModel extends Model
{
    const TABLE = 'subdomain_alias';
    const PRIMARY_KEY = 'subdomain_alias_id';

    const COLUMNS = array(
        'subdomain_alias_id', 'alias_id', 'subdomain_alias_name',
        'subdomain_alias_mount', 'subdomain_alias_document_root',
        'subdomain_alias_url_forward', 'subdomain_alias_type_forward',
        'subdomain_alias_host_forward', 'subdomain_alias_wildcard_alias',
        'subdomain_alias_status'
    );

    public $subdomain_alias_id;
    public $alias_id;
    public $subdomain_alias_name;
    public $subdomain_alias_mount;
    public $subdomain_alias_document_root;
    public $subdomain_alias_url_forward;
    public $subdomain_alias_type_forward;
    public $subdomain_alias_host_forward;
    public $subdomain_alias_wildcard_alias;
    public $subdomain_alias_status;

    /** @var DomainAliasModel|null */
    public $alias;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(DomainAliasModel::class, 'alias_id', 'alias_id', 'alias');
    }
}
