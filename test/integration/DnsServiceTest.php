<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

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

use iMSCP\Plugin\SGW_GraphQL\Service\DnsService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DnsServiceTest extends ServiceTestCase
{
    private function service(): DnsService
    {
        return new DnsService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function recordId(): string
    {
        return GlobalId::encode(NodeType::DNS_RECORD, $this->fixture->dnsRecordId());
    }

    private function record(int $id): array
    {
        return $this->db->row('SELECT * FROM domain_dns WHERE domain_dns_id = ?', array($id));
    }

    private function aRecord(array $extra = array()): array
    {
        return array_merge(array(
            'hostId' => $this->domainId(), 'name' => 'www', 'type' => 'A', 'data' => array('address' => '203.0.113.9')
        ), $extra);
    }

    public function testARecordIsCreatedInTheDomainsZone(): void
    {
        $ref = $this->service()->create($this->caller('customer'), $this->aRecord());

        $row = $this->record($ref->getKey());
        self::assertSame(array(
            'domain_id'         => (string)$this->fixture->domainId(),
            'alias_id'          => '0',
            'domain_dns'        => "www.sgwtcustomer.test.\t3600",
            'domain_class'      => 'IN',
            'domain_type'       => 'A',
            'domain_text'       => '203.0.113.9',
            'owned_by'          => 'custom_dns_feature',
            'domain_dns_status' => 'toadd'
        ), array_map('strval', array_diff_key($row, array('domain_dns_id' => 1))));
        self::assertSame(array('onBeforeAddCustomDNSrecord', 'onAfterAddCustomDNSrecord'), $this->core->eventNames());
        self::assertSame(array(
            'domainId' => $this->fixture->domainId(),
            'aliasId'  => 0,
            'name'     => "www.sgwtcustomer.test.\t3600",
            'class'    => 'IN',
            'type'     => 'A',
            'data'     => '203.0.113.9'
        ), $this->core->events[0][1]);
        self::assertSame($ref->getKey(), $this->core->events[1][1]['id']);
        self::assertSame(1, $this->core->requests);
        self::assertSame('DNS resource record has been scheduled for addition by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testARecordOnAnAliasIsInTheAliasesZone(): void
    {
        $ref = $this->service()->create($this->caller('customer'), $this->aRecord(array(
            'hostId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
        )));

        $row = $this->record($ref->getKey());
        self::assertSame((string)$this->fixture->aliasId(), (string)$row['alias_id']);
        self::assertSame('www.' . $this->fixture->aliasName() . ".\t3600", $row['domain_dns']);
    }

    public function testTheSameRecordTwiceIsAConflict(): void
    {
        $this->service()->create($this->caller('customer'), $this->aRecord());

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord());
        });
    }

    public function testTheFeatureFollowsTheAccountAndTheServer(): void
    {
        $this->db->execute("UPDATE domain SET domain_dns = 'no' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord());
        });
        self::assertSame('customDns', $e->getExtensions()['feature']);

        $this->db->execute("UPDATE domain SET domain_dns = 'yes' WHERE domain_id = ?", array($this->fixture->domainId()));
        $this->reconfigure(array('NAMED_PACKAGE' => 'Servers::noserver'));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord());
        });
    }

    public function testAnOrderedAliasHasNoZoneYet(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord(array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
            )));
        });
    }

    public function testInvalidDataIsRefusedOnItsField(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), $this->aRecord(array('data' => array('address' => 'nope'))));
        });

        self::assertSame('input.data.address', $e->getExtensions()['field']);
    }

    public function testARecordIsReplacedButKeepsItsType(): void
    {
        $this->service()->update($this->caller('customer'), $this->recordId(), array(
            'name' => 'mail', 'ttl' => 600, 'data' => array('address' => '203.0.113.10')
        ));

        $row = $this->record($this->fixture->dnsRecordId());
        self::assertSame("mail.sgwtcustomer.test.\t600", $row['domain_dns']);
        self::assertSame('203.0.113.10', $row['domain_text']);
        self::assertSame('A', $row['domain_type']);
        self::assertSame('tochange', $row['domain_dns_status']);
        self::assertSame(array('onBeforeEditCustomDNSrecord', 'onAfterEditCustomDNSrecord'), $this->core->eventNames());
        self::assertSame($this->fixture->dnsRecordId(), $this->core->events[0][1]['id']);
        self::assertSame('DNS resource record has been scheduled for update by sgwtcustomer', $this->core->logs[0][0]);

        // A target does not make it a CNAME: the A record still needs an address.
        $this->db->execute("UPDATE domain_dns SET domain_dns_status = 'ok' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->recordId(), array(
                'name' => 'mail', 'ttl' => 600, 'data' => array('target' => 'example.net.')
            ));
        });
    }

    public function testAPluginsRecordIsReadOnlyHere(): void
    {
        $this->db->execute("UPDATE domain_dns SET owned_by = 'SGW_LetsEncrypt' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));

        $e = $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->update($this->caller('customer'), $this->recordId(), array(
                'name' => 'mail', 'ttl' => 600, 'data' => array('address' => '203.0.113.10')
            ));
        });
        self::assertSame('SGW_LetsEncrypt', $e->getExtensions()['ownedBy']);

        $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->delete($this->caller('customer'), $this->recordId());
        });
    }

    public function testAPendingRecordCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE domain_dns SET domain_dns_status = 'toadd' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->recordId(), array(
                'name' => 'mail', 'ttl' => 600, 'data' => array('address' => '203.0.113.10')
            ));
        });
    }

    public function testDeletingSchedulesTheRecordAndNamesItInTheEvent(): void
    {
        $this->service()->delete($this->caller('customer'), $this->recordId());

        self::assertSame('todelete', $this->record($this->fixture->dnsRecordId())['domain_dns_status']);
        self::assertSame(array('onBeforeDeleteCustomDNSrecord', 'onAfterDeleteCustomDNSrecord'), $this->core->eventNames());
        self::assertSame(array('id' => $this->fixture->dnsRecordId()), $this->core->events[0][1], 'C11 item 5');
        self::assertSame('sgwtcustomer scheduled deletion of a custom DNS record', $this->core->logs[0][0]);
    }

    public function testAFailedRecordMayBeDeleted(): void
    {
        $this->db->execute("UPDATE domain_dns SET domain_dns_status = 'Invalid zone' WHERE domain_dns_id = ?", array($this->fixture->dnsRecordId()));

        $this->service()->delete($this->caller('customer'), $this->recordId());

        self::assertSame('todelete', $this->record($this->fixture->dnsRecordId())['domain_dns_status']);
    }
}
