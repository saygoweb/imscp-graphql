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
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\CustomerFeatures;
use iMSCP\Plugin\SGW_GraphQL\Support\DnsRecordData;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

/**
 * A customer's own DNS records in the zone of their domain or an alias.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/dns_edit.php and
 *   dns_delete.php. Retire when DnsRecordService lands in core (spec section
 *   21, C3 row 6).
 */
final class DnsService
{
    const HOSTS = array(NodeType::DOMAIN, NodeType::DOMAIN_ALIAS);

    /** The owned_by of a record the customer made; anything else is a plugin's. */
    const CUSTOMER_OWNED = 'custom_dns_feature';

    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::DNS_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());

        // 4.
        Guard::requireFeature($this->customDns($account), 'customDns');

        // 5. dns_edit.php:907-915 offers no ordered alias's zone.
        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $type = (string)($input['type'] ?? '');
        $encoded = DnsRecordData::encode(
            $type, $input, (string)$hostRow['name'], array($core, 'toAscii'), array($core, 'domainNameError')
        );

        $params = array(
            'domainId' => $account->getDomainId(),
            'aliasId'  => $host->getTag() === NodeType::DOMAIN_ALIAS ? (int)$host->getKey() : 0,
            'name'     => $encoded['domain_dns'],
            'class'    => 'IN',
            'type'     => $type,
            'data'     => $encoded['domain_text']
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/dns_edit.php:788-825.
        //    The unique key on (domain_id, alias_id, name, class, type, data)
        //    makes a repeat CONFLICT.
        $id = $kit->writer()->run(function () use ($kit, $core, $params) {
            $core->dispatch(Events::onBeforeAddCustomDNSrecord, $params);

            $kit->db()->execute(
                "
                    INSERT INTO domain_dns (
                        domain_id, alias_id, domain_dns, domain_class, domain_type, domain_text,
                        owned_by, domain_dns_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'toadd')
                ",
                array(
                    $params['domainId'], $params['aliasId'], $params['name'], $params['class'],
                    $params['type'], $params['data'], self::CUSTOMER_OWNED
                )
            );

            $id = $kit->db()->lastInsertId();
            $core->dispatch(Events::onAfterAddCustomDNSrecord, array('id' => $id) + $params);

            return $id;
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(
            sprintf('DNS resource record has been scheduled for addition by %s', $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DNS_RECORD, $id);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DNS_RECORD), Scope::DNS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature($this->customDns($account), 'customDns');

        $row = $this->recordRow($target);
        // dns_edit.php does not ask; spec section 8.3 does.
        Guard::requireState((string)$row['domain_dns_status'], array(Provisioning::STATE_OK));

        // The type is the row's: the page disables the field on edit (line 956).
        $encoded = DnsRecordData::encode(
            (string)$row['domain_type'], $input, (string)$row['zone_name'],
            array($core, 'toAscii'), array($core, 'domainNameError')
        );

        $recordId = (int)$row['domain_dns_id'];
        $params = array(
            'id'       => $recordId,
            'domainId' => (int)$row['domain_id'],
            'aliasId'  => (int)$row['alias_id'],
            'name'     => $encoded['domain_dns'],
            'class'    => (string)$row['domain_class'],
            'type'     => (string)$row['domain_type'],
            'data'     => $encoded['domain_text']
        );

        // CORE-DEBT(C3): transcribed from gui/public/client/dns_edit.php:826-869.
        $kit->writer()->run(function () use ($kit, $core, $params) {
            $core->dispatch(Events::onBeforeEditCustomDNSrecord, $params);

            $kit->db()->execute(
                "
                    UPDATE domain_dns
                    SET domain_dns = ?, domain_class = ?, domain_type = ?, domain_text = ?, domain_dns_status = 'tochange'
                    WHERE domain_dns_id = ?
                ",
                array($params['name'], $params['class'], $params['type'], $params['data'], $params['id'])
            );

            $core->dispatch(Events::onAfterEditCustomDNSrecord, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('DNS resource record has been scheduled for update by %s', $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DNS_RECORD, $recordId);
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DNS_RECORD), Scope::DNS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature($this->customDns($account), 'customDns');

        $row = $this->recordRow($target);
        Guard::requireState((string)$row['domain_dns_status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $recordId = (int)$row['domain_dns_id'];

        // CORE-DEBT(C3): transcribed from gui/public/client/dns_delete.php:38-62.
        // CORE-DEBT(C11): C11 item 5 - the before-event carries the record's id.
        $kit->writer()->run(function () use ($kit, $core, $recordId) {
            $core->dispatch(Events::onBeforeDeleteCustomDNSrecord, array('id' => $recordId));
            $kit->db()->execute(
                "UPDATE domain_dns SET domain_dns_status = 'todelete' WHERE domain_dns_id = ?",
                array($recordId)
            );
            $core->dispatch(Events::onAfterDeleteCustomDNSrecord, array('id' => $recordId));
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s scheduled deletion of a custom DNS record', $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DNS_RECORD, $recordId);
    }

    /** customerHasFeature('custom_dns_records'), through the plan 2 transcription. */
    private function customDns(CustomerAccount $account): bool
    {
        return CustomerFeatures::fromDomainRow(
            $account->getDomainRow(),
            $this->kit->panelConfig(CustomerFeatures::CONFIG_KEYS),
            false
        )->has('customDns');
    }

    /**
     * The record, its zone's ASCII name, and FORBIDDEN when a plugin owns it.
     *
     * @return array<string, mixed>
     */
    private function recordRow(Target $target): array
    {
        $row = $this->kit->db()->row(
            '
                SELECT dd.*, IFNULL(al.alias_name, d.domain_name) AS zone_name
                FROM domain_dns AS dd
                JOIN domain AS d ON d.domain_id = dd.domain_id
                LEFT JOIN domain_aliasses AS al ON al.alias_id = dd.alias_id
                WHERE dd.domain_dns_id = ?
            ',
            array((int)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        // dns_edit.php:588 and dns_delete.php:49. FORBIDDEN, not NOT_FOUND: the
        // caller can already read this record (spec section 7.8, ownedBy).
        if ($row['owned_by'] !== self::CUSTOMER_OWNED) {
            throw Guard::forbidden(
                'This record belongs to a plugin and is read-only here.',
                array('ownedBy' => (string)$row['owned_by'])
            );
        }

        return $row;
    }
}
