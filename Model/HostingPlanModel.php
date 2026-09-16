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
 * i-MSCP's `hosting_plans` table.
 *
 * `props` is the 25-field positional string Support\PlanProps decodes, and
 * `status` is a boolean in an int column: truthy means the plan is offered
 * (gui/public/reseller/hosting_plan.php:69).
 */
class HostingPlanModel extends Model
{
    const TABLE = 'hosting_plans';
    const PRIMARY_KEY = 'id';

    const COLUMNS = array('id', 'reseller_id', 'name', 'props', 'description', 'status');

    public $id;
    public $reseller_id;
    public $name;
    public $props;
    public $description;
    public $status;

    /** @var AdminModel|null */
    public $reseller;

    public function __construct(PDO $pdo)
    {
        $mapper = DataMapper::create(
            $pdo, self::TABLE, array_combine(self::COLUMNS, self::COLUMNS)
        );
        $mapper->modelPrimaryKey = self::PRIMARY_KEY;

        parent::__construct($pdo, $mapper);

        $this->belongsTo(AdminModel::class, 'reseller_id', 'admin_id', 'reseller');
    }
}
