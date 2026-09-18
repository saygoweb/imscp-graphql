<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\Allowances;
use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/**
 * A reseller's catalogue of hosting plans.
 *
 * M15: `hosting_plans` is a panel-only table - no status column of its own
 * (the `status` column here is the "available to customers" flag, not a
 * provisioning state), no daemon, no transaction on the page. Every write
 * here is therefore synchronous, unlike a customer's.
 */
final class HostingPlanService
{
    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/hosting_plan_add.php:431-468.
     *   The page has no transaction and pokes no daemon: hosting_plans is a
     *   panel-only table (M15). Retire when HostingPlanService lands in core.
     */
    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        Guard::requireScope($caller, Scope::CUSTOMERS_WRITE);

        if ($caller->getRole() !== Identity::ROLE_RESELLER && $caller->getRole() !== Identity::ROLE_ADMIN) {
            throw Guard::forbidden('Only a reseller or an administrator may manage hosting plans.');
        }

        $reseller = $kit->accounts()->reseller($this->resellerFor($caller, $input));
        $name = trim((string)($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw Guard::badInput('input.name', 'A hosting plan needs a name of 1 to 255 characters.');
        }

        $allowances = Allowances::fromInput((array)($input['allowances'] ?? array()));

        $this->checkLimits($reseller, $allowances);

        if ($this->nameTaken($reseller->getAdminId(), $name, null)) {
            throw Guard::conflict('A hosting plan with that name already exists.');
        }

        $id = $kit->writer()->run(function () use ($kit, $reseller, $name, $input, $allowances) {
            $kit->db()->execute(
                'INSERT INTO hosting_plans (reseller_id, name, description, props, status) VALUES (?, ?, ?, ?, ?)',
                array(
                    $reseller->getAdminId(), $name, (string)($input['description'] ?? ''),
                    $allowances->toProps()->toString(), empty($input['available']) ? 0 : 1
                )
            );

            return $kit->db()->lastInsertId();
        });

        $core->writeLog(
            sprintf('A hosting plan (%s) has been created by %s', $name, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::HOSTING_PLAN, $id);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/hosting_plan_edit.php:487-514.
     *   Unlike the page (which never checks the name for a collision on
     *   update), this excludes the plan's own row from the uniqueness check
     *   so a plan may keep its name unchanged - the panel's own oversight is
     *   not carried over. Retire when HostingPlanService lands in core.
     */
    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::HOSTING_PLAN), Scope::CUSTOMERS_WRITE, 'id');
        $reseller = $kit->accounts()->reseller($target->getOwnerId());

        $name = trim((string)($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 255) {
            throw Guard::badInput('input.name', 'A hosting plan needs a name of 1 to 255 characters.');
        }

        $allowances = Allowances::fromInput((array)($input['allowances'] ?? array()));

        $this->checkLimits($reseller, $allowances);

        if ($this->nameTaken($reseller->getAdminId(), $name, (int)$target->getKey())) {
            throw Guard::conflict('A hosting plan with that name already exists.');
        }

        $kit->writer()->run(function () use ($kit, $target, $name, $input, $allowances) {
            $kit->db()->execute(
                'UPDATE hosting_plans SET name = ?, description = ?, props = ?, status = ? WHERE id = ?',
                array(
                    $name, (string)($input['description'] ?? ''),
                    $allowances->toProps()->toString(), empty($input['available']) ? 0 : 1,
                    $target->getKey()
                )
            );
        });

        $core->writeLog(
            sprintf('A hosting plan (%s) has been changed by %s', $name, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::HOSTING_PLAN, $target->getKey());
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/hosting_plan_delete.php:37-46.
     *   A plan already used by a customer carries no foreign key back to
     *   `hosting_plans` (M15's own table has none pointing at it), so the
     *   delete takes nothing else with it. Retire when HostingPlanService
     *   lands in core.
     */
    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::HOSTING_PLAN), Scope::CUSTOMERS_WRITE, 'id');
        $name = (string)$kit->db()->value('SELECT name FROM hosting_plans WHERE id = ?', array($target->getKey()));

        $kit->writer()->run(function () use ($kit, $target) {
            $kit->db()->execute(
                'DELETE FROM hosting_plans WHERE id = ? AND reseller_id = ?',
                array($target->getKey(), $target->getOwnerId())
            );
        });

        $core->writeLog(
            sprintf('A hosting plan (%s) has been deleted by %s', $name, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::HOSTING_PLAN, $target->getKey());
    }

    /**
     * D30's rule, for a plan rather than a customer: a reseller manages its
     * own catalogue and may not name another; an administrator has no
     * catalogue of its own, so it must say whose it is managing.
     */
    private function resellerFor(Identity $caller, array $input): int
    {
        $named = isset($input['resellerId'])
            ? Guard::parse((string)$input['resellerId'], array(NodeType::RESELLER))->getId()
            : null;

        if ($caller->getRole() === Identity::ROLE_ADMIN) {
            if ($named === null) {
                throw Guard::badInput(
                    'input.resellerId', 'An administrator must say which reseller the hosting plan belongs to.'
                );
            }

            return $named;
        }

        if ($named !== null && $named !== $caller->getAdminId()) {
            // Not NOT_FOUND: the caller named an account it can see - its own
            // role - and asked for something its role does not permit.
            throw Guard::forbidden('A reseller may only manage hosting plans of its own.');
        }

        return $caller->getAdminId();
    }

    /**
     * A plan may not promise more than the reseller itself has. The page
     * calls reseller_limits_check() on both the add and the edit page; the
     * rule is the create path's (Support\LimitRules::createReason()), with
     * no customer on either side of the arithmetic yet - the same rule
     * CustomerService::create() applies, not the edit path's reason().
     */
    private function checkLimits(ResellerAccount $reseller, Allowances $allowances): void
    {
        $wanted = array(
            'subdomains'    => $allowances->limit('subdomains'),
            'domainAliases' => $allowances->limit('domainAliases'),
            'mailAccounts'  => $allowances->limit('mailAccounts'),
            'ftpUsers'      => $allowances->limit('ftpUsers'),
            'sqlDatabases'  => $allowances->limit('sqlDatabases'),
            'sqlUsers'      => $allowances->limit('sqlUsers'),
            'traffic'       => $allowances->storage('traffic'),
            'disk'          => $allowances->storage('disk')
        );

        // A10: $resellerMax notes which allowance it was last asked about.
        // createReason() calls it exactly once per allowance it evaluates, in
        // order, and returns the instant one refuses - so when it does, the
        // last allowance $resellerMax saw is the one responsible, and its
        // numbers are ready for the exception.
        $triggered = null;
        $resellerMax = function (string $allowance) use ($reseller, &$triggered): int {
            $triggered = $allowance;

            return $reseller->maxOf($allowance);
        };

        $reason = LimitRules::createReason($wanted, $resellerMax, array($reseller, 'usedOf'));

        if ($reason !== null) {
            throw Guard::limitExceeded($reason, array(
                'quota' => $triggered,
                'limit' => $reseller->maxOf($triggered),
                'used'  => $reseller->usedOf($triggered)
            ));
        }
    }

    private function nameTaken(int $resellerId, string $name, ?int $excludeId): bool
    {
        if ($excludeId === null) {
            return (int)$this->kit->db()->value(
                'SELECT COUNT(*) FROM hosting_plans WHERE reseller_id = ? AND name = ?',
                array($resellerId, $name)
            ) > 0;
        }

        return (int)$this->kit->db()->value(
            'SELECT COUNT(*) FROM hosting_plans WHERE reseller_id = ? AND name = ? AND id != ?',
            array($resellerId, $name, $excludeId)
        ) > 0;
    }
}
