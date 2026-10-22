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

use iMSCP\Plugin\SGW_GraphQL\Service\DomainService;
use iMSCP\Plugin\SGW_GraphQL\Service\MailExchangeCheck;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DomainServiceTest extends ServiceTestCase
{
    private function service(): DomainService
    {
        return new DomainService($this->kit, $this->mailExchange());
    }

    private function id(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function row(): array
    {
        return $this->db->row(
            'SELECT document_root, url_forward, type_forward, host_forward, wildcard_alias, domain_status FROM domain WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
    }

    public function testForwardingTheMainDomain(): void
    {
        $ref = $this->service()->update($this->caller('customer'), $this->id(), array(
            'forwarding' => array('url' => 'https://example.net/app', 'type' => 'TEMPORARY_307')
        ));

        self::assertSame(NodeType::DOMAIN, $ref->getTag());
        self::assertSame(array(
            'document_root'  => '/htdocs',
            'url_forward'    => 'https://example.net/app/',
            'type_forward'   => '307',
            'host_forward'   => 'Off',
            'wildcard_alias' => 'no',
            'domain_status'  => 'tochange'
        ), $this->row());
        self::assertSame(array('onBeforeEditDomain', 'onAfterEditDomain'), $this->core->eventNames());
        self::assertSame(array(
            'domainId'      => $this->fixture->domainId(),
            'domainName'    => $this->fixture->domainName(),
            'mountPoint'    => '/',
            'documentRoot'  => '/htdocs',
            'forwardUrl'    => 'https://example.net/app/',
            'forwardType'   => '307',
            'forwardHost'   => 'Off',
            'wildcardAlias' => 'no'
        ), $this->core->events[0][1]);
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            'The ' . $this->fixture->domainName() . ' domain properties were updated by sgwtcustomer',
            $this->core->logs[0][0]
        );
    }

    public function testTheMainDomainsDocumentRootIsCheckedUnderItsHtdocs(): void
    {
        $this->service()->update($this->caller('customer'), $this->id(), array('documentRoot' => '/htdocs/public'));

        self::assertSame(array(array('sgwtcustomer', '/htdocs', '/public')), $this->probe->asked);
        self::assertSame('/htdocs/public', $this->row()['document_root']);
    }

    public function testADomainCannotForwardToItself(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array(
                'forwarding' => array('url' => 'https://' . $this->fixture->domainName() . '/', 'type' => 'FOUND_302')
            ));
        });

        self::assertSame('input.forwarding.url', $e->getExtensions()['field']);
    }

    public function testTurningExternalMailOnAloneFiresNoEditEvents(): void
    {
        $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => true));

        $row = $this->db->row(
            'SELECT external_mail, domain_status, wildcard_alias FROM domain WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        self::assertSame(array('external_mail' => 'on', 'domain_status' => 'tochange', 'wildcard_alias' => 'no'), $row);
        self::assertSame(array(), $this->core->eventNames(), 'mail_external.php fires no edit events');
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            array('External mail feature has been activated for ' . $this->fixture->domainName() . ' by sgwtcustomer'),
            array_column($this->core->logs, 0)
        );
    }

    public function testExternalMailAndAnotherFieldInOneUpdate(): void
    {
        $this->db->execute("UPDATE domain SET external_mail = 'on' WHERE domain_id = ?", array($this->fixture->domainId()));

        $this->service()->update($this->caller('customer'), $this->id(), array('wildcard' => true, 'externalMail' => false));

        $row = $this->db->row(
            'SELECT external_mail, domain_status, wildcard_alias FROM domain WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        self::assertSame(array('external_mail' => 'off', 'domain_status' => 'tochange', 'wildcard_alias' => 'yes'), $row);
        self::assertSame(array('onBeforeEditDomain', 'onAfterEditDomain'), $this->core->eventNames());
        self::assertSame(1, $this->core->requests);
        self::assertSame(array(
            'The ' . $this->fixture->domainName() . ' domain properties were updated by sgwtcustomer',
            'External mail feature has been deactivated for ' . $this->fixture->domainName() . ' by sgwtcustomer'
        ), array_column($this->core->logs, 0));
    }

    public function testTurningExternalMailOffAsksPublicDnsForTheMx(): void
    {
        $this->externalMailOn();

        $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));

        $name = $this->fixture->domainName();
        self::assertSame(array($name, 'mail.' . $name), $this->mx->asked);
        self::assertSame('off', $this->externalMail());
    }

    public function testTurningExternalMailOffWhileTheMxIsElsewhereIsAConflict(): void
    {
        $this->externalMailOn();
        $name = $this->fixture->domainName();
        $this->mx->mx[$name] = array(array('host' => 'aspmx.l.google.com', 'preference' => 1));
        $this->mx->addresses['aspmx.l.google.com'] = array('142.250.4.27');

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));
        });

        self::assertStringContainsString(
            'External mail was not turned off for ' . $name . ': its MX host aspmx.l.google.com resolves to '
                . '142.250.4.27, not to this server (',
            $e->getMessage()
        );
        self::assertStringContainsString(self::BASE_SERVER_PUBLIC_IP, $e->getMessage());
        self::assertStringContainsString('Point the MX record at this server first', $e->getMessage());
        self::assertSame('on', $this->externalMail());
        self::assertSame(0, $this->core->requests);
        self::assertSame(array(), $this->core->logs);
    }

    public function testNoMxRecordIsAConflict(): void
    {
        $this->externalMailOn();
        $this->mx->mx[$this->fixture->domainName()] = array();

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));
        });

        self::assertStringContainsString('public DNS has no MX record for it', $e->getMessage());
        self::assertSame('on', $this->externalMail());
    }

    public function testAnMxHostThatDoesNotResolveIsAConflict(): void
    {
        $this->externalMailOn();
        $this->mx->addresses['mail.' . $this->fixture->domainName()] = array();

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));
        });

        self::assertStringContainsString('does not resolve in public DNS', $e->getMessage());
    }

    public function testANullMxIsAConflict(): void
    {
        $this->externalMailOn();
        $this->mx->mx[$this->fixture->domainName()] = array(array('host' => '', 'preference' => 0));

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));
        });

        self::assertStringContainsString('null MX', $e->getMessage());
    }

    public function testUnreachablePublicDnsIsAConflict(): void
    {
        $this->externalMailOn();
        $this->mx->down = true;

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));
        });

        self::assertStringContainsString('no public DNS resolver answered', $e->getMessage());
        self::assertSame('on', $this->externalMail());
    }

    public function testOnlyThePreferredMxMustBeHere(): void
    {
        $this->externalMailOn();
        $name = $this->fixture->domainName();
        $this->mx->mx[$name] = array(
            array('host' => 'backup.example.net', 'preference' => 20),
            array('host' => 'mail.' . $name, 'preference' => 10)
        );
        $this->mx->addresses['backup.example.net'] = array('198.51.100.7');

        $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));

        self::assertSame('off', $this->externalMail());
        self::assertNotContains('backup.example.net', $this->mx->asked);
    }

    public function testAnMxOnAnyOfTheBoxsServerIpsIsHere(): void
    {
        $this->externalMailOn();
        $ip = (string)$this->db->value('SELECT ip_number FROM server_ips ORDER BY ip_id LIMIT 1');
        $this->mx->addresses['mail.' . $this->fixture->domainName()] = array($ip);

        $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));

        self::assertSame('off', $this->externalMail());
    }

    public function testTurningExternalMailOnOrOffAgainAsksNothing(): void
    {
        $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => false));
        $this->db->execute("UPDATE domain SET domain_status = 'ok' WHERE domain_id = ?", array($this->fixture->domainId()));
        $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => true));

        self::assertSame(array(), $this->mx->asked);
    }

    public function testNoResolversConfiguredSkipsTheCheck(): void
    {
        $this->externalMailOn();
        $this->mx->down = true;

        (new DomainService($this->kit, new MailExchangeCheck($this->kit, null)))
            ->update($this->caller('customer'), $this->id(), array('externalMail' => false));

        self::assertSame('off', $this->externalMail());
    }

    public function testExternalMailNeedsTheAllowance(): void
    {
        $this->db->execute("UPDATE domain SET domain_external_mail = 'no' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => true));
        });

        self::assertSame('externalMail', $e->getExtensions()['feature']);
        self::assertSame(0, $this->core->requests);
    }

    public function testExternalMailOnAnUnsettledDomainIsAConflict(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('externalMail' => true));
        });
    }

    public function testAnUnsettledDomainIsAConflict(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('wildcard' => true));
        });
    }

    public function testADisabledDomainIsForbidden(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'disabled' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->update($this->caller('customer'), $this->id(), array('wildcard' => true));
        });

        self::assertSame('DISABLED', $e->getExtensions()['state']);
    }

    public function testASubdomainIdentifierIsNotADomain(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function () {
            $this->service()->update(
                $this->caller('customer'),
                GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
                array('wildcard' => true)
            );
        });
    }

    public function testTheResellerIsLoggedAsThemselves(): void
    {
        $this->service()->update($this->caller('reseller'), $this->id(), array('wildcard' => true));

        self::assertStringEndsWith('were updated by sgwtreseller', $this->core->logs[0][0]);
    }

    private function externalMailOn(): void
    {
        $this->db->execute("UPDATE domain SET external_mail = 'on' WHERE domain_id = ?", array($this->fixture->domainId()));
    }

    private function externalMail(): string
    {
        return (string)$this->db->value('SELECT external_mail FROM domain WHERE domain_id = ?', array($this->fixture->domainId()));
    }
}
