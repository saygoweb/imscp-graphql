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

/** i-MSCP's `subdomain` table: subdomains of a customer's main domain. */
class SubdomainModel extends Model
{
    const TABLE = 'subdomain';
    const PRIMARY_KEY = 'subdomain_id';

    const COLUMNS = array(
        'subdomain_id', 'domain_id', 'subdomain_name', 'subdomain_mount',
        'subdomain_document_root', 'subdomain_url_forward', 'subdomain_type_forward',
        'subdomain_host_forward', 'subdomain_wildcard_alias', 'subdomain_status'
    );

    public $subdomain_id;
    public $domain_id;
    public $subdomain_name;
    public $subdomain_mount;
    public $subdomain_document_root;
    public $subdomain_url_forward;
    public $subdomain_type_forward;
    public $subdomain_host_forward;
    public $subdomain_wildcard_alias;
    public $subdomain_status;

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
