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
 * i-MSCP's `domain` table: one row per customer, carrying the customer's
 * limits, disk figures and feature flags as well as the main vhost.
 */
class DomainModel extends Model
{
    const TABLE = 'domain';
    const PRIMARY_KEY = 'domain_id';

    const COLUMNS = array(
        'domain_id', 'domain_name', 'domain_admin_id', 'domain_created',
        'domain_expires', 'domain_mailacc_limit', 'domain_ftpacc_limit',
        'domain_traffic_limit', 'domain_sqld_limit', 'domain_sqlu_limit',
        'domain_status', 'domain_alias_limit', 'domain_subd_limit', 'domain_ip_id',
        'domain_disk_limit', 'domain_disk_usage', 'domain_disk_file',
        'domain_disk_mail', 'domain_disk_sql', 'domain_php', 'domain_cgi',
        'allowbackup', 'domain_dns', 'phpini_perm_system',
        'phpini_perm_allow_url_fopen', 'phpini_perm_display_errors',
        'phpini_perm_disable_functions', 'domain_external_mail', 'mail_quota',
        'document_root', 'url_forward', 'type_forward', 'host_forward',
        'wildcard_alias'
    );

    public $domain_id;
    public $domain_name;
    public $domain_admin_id;
    public $domain_created;
    public $domain_expires;
    public $domain_mailacc_limit;
    public $domain_ftpacc_limit;
    public $domain_traffic_limit;
    public $domain_sqld_limit;
    public $domain_sqlu_limit;
    public $domain_status;
    public $domain_alias_limit;
    public $domain_subd_limit;
    public $domain_ip_id;
    public $domain_disk_limit;
    public $domain_disk_usage;
    public $domain_disk_file;
    public $domain_disk_mail;
    public $domain_disk_sql;
    public $domain_php;
    public $domain_cgi;
    public $allowbackup;
    public $domain_dns;
    public $phpini_perm_system;
    public $phpini_perm_allow_url_fopen;
    public $phpini_perm_display_errors;
    public $phpini_perm_disable_functions;
    public $domain_external_mail;
    public $mail_quota;
    public $document_root;
    public $url_forward;
    public $type_forward;
    public $host_forward;
    public $wildcard_alias;

    /** @var AdminModel|null */
    public $customer;

    /** @var ServerIpModel|null */
    public $ipAddress;

    /** @var MailAccountModel[]|null */
    public $mailAccounts;

    /** @var SqlDatabaseModel[]|null */
    public $sqlDatabases;

    /** @var DnsRecordModel[]|null */
    public $dnsRecords;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'domain_admin_id', 'admin_id', 'customer');
        $this->belongsTo(ServerIpModel::class, 'domain_ip_id', 'ip_id', 'ipAddress');
        $this->hasMany(MailAccountModel::class, 'domain_id', 'domain_id', 'mailAccounts');
        $this->hasMany(SqlDatabaseModel::class, 'domain_id', 'domain_id', 'sqlDatabases');
        $this->hasMany(DnsRecordModel::class, 'domain_id', 'domain_id', 'dnsRecords');

        // Subdomains and aliases are deliberately not relationships here: a
        // Subdomain's name is its label joined to the parent's name, which the
        // IN-clause loader's single-table SELECT cannot produce. They come from
        // Repository\VirtualHosts instead.
    }
}
