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
 * i-MSCP's `domain_dns` table.
 *
 * alias_id is 0 for a record on the main domain and the alias_id otherwise
 * (gui/public/client/dns_edit.php:592), which is how DnsRecord.host picks
 * between a Domain and a DomainAlias.
 */
class DnsRecordModel extends Model
{
    const TABLE = 'domain_dns';
    const PRIMARY_KEY = 'domain_dns_id';

    const COLUMNS = array(
        'domain_dns_id', 'domain_id', 'alias_id', 'domain_dns', 'domain_class',
        'domain_type', 'domain_text', 'owned_by', 'domain_dns_status'
    );

    public $domain_dns_id;
    public $domain_id;
    public $alias_id;
    public $domain_dns;
    public $domain_class;
    public $domain_type;
    public $domain_text;
    public $owned_by;
    public $domain_dns_status;

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
