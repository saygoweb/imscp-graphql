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
 * i-MSCP's `mail_users` table.
 *
 * sub_id names the vhost the address belongs to - a subdomain_id, alias_id or
 * subdomain_alias_id depending on mail_type, and 0 for the main domain. Which
 * of the three it is comes from Support\MailType::hostTypeOf().
 */
class MailAccountModel extends Model
{
    const TABLE = 'mail_users';
    const PRIMARY_KEY = 'mail_id';

    const COLUMNS = array(
        'mail_id', 'mail_acc', 'mail_forward', 'domain_id', 'mail_type', 'sub_id',
        'status', 'po_active', 'mail_auto_respond', 'mail_auto_respond_text',
        'quota', 'mail_addr'
    );

    public $mail_id;
    public $mail_acc;
    public $mail_forward;
    public $domain_id;
    public $mail_type;
    public $sub_id;
    public $status;
    public $po_active;
    public $mail_auto_respond;
    public $mail_auto_respond_text;
    public $quota;
    public $mail_addr;

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
