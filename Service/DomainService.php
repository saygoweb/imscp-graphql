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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

/**
 * The customer's own main domain: forwarding, document root and wildcard
 * (spec section 1.1). Everything else about a domain is the reseller's, in
 * plan 4.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/domain_edit.php:179-344.
 *   Retire when DomainService lands in core (spec section 21, C3 row 7).
 */
final class DomainService
{
    /** @var Toolkit */
    private $kit;

    /** @var VhostInput */
    private $vhostInput;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
        $this->vhostInput = new VhostInput($kit);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN), Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());

        // domain_edit.php asks no feature: every customer may forward their
        // own domain.
        $row = $kit->vhost(NodeType::DOMAIN, $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK));

        $values = $this->vhostInput->update($account, $row, $input);

        $params = array(
            'domainId'      => (int)$target->getKey(),
            'domainName'    => $row['name'],
            'mountPoint'    => '/',
            'documentRoot'  => $values['documentRoot'],
            'forwardUrl'    => $values['url'],
            'forwardType'   => $values['type'],
            'forwardHost'   => $values['host'],
            'wildcardAlias' => $values['wildcard']
        );

        $kit->writer()->run(function () use ($kit, $core, $params, $values) {
            $core->dispatch(Events::onBeforeEditDomain, $params);

            $kit->db()->execute(
                '
                    UPDATE domain
                    SET document_root = ?, url_forward = ?, type_forward = ?, host_forward = ?,
                        wildcard_alias = ?, domain_status = ?
                    WHERE domain_id = ?
                ',
                array(
                    $values['documentRoot'], $values['url'], $values['type'], $values['host'],
                    $values['wildcard'], 'tochange', $params['domainId']
                )
            );

            $core->dispatch(Events::onAfterEditDomain, $params);
        });

        $core->sendRequest();

        // The page's format string takes the domain name first; it passes the
        // username twice (domain_edit.php:336-339), which is not worth an
        // issue of its own.
        $core->writeLog(
            sprintf('The %s domain properties were updated by %s', $core->toUnicode((string)$row['name']), $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN, $target->getKey());
    }
}
