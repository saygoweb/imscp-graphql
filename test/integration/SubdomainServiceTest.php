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

use iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;

class SubdomainServiceTest extends ServiceTestCase
{
    private function service(): SubdomainService
    {
        return new SubdomainService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function subdomainId(): string
    {
        return GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId());
    }

    private function subdomainRow(int $id): array
    {
        return $this->db->row('SELECT * FROM subdomain WHERE subdomain_id = ?', array($id));
    }

    // ---- create ---------------------------------------------------------

    public function testTheOwnerCreatesASubdomainOfTheirDomain(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'Blog2'
        ));

        self::assertSame(NodeType::SUBDOMAIN, $ref->getTag());

        $row = $this->subdomainRow($ref->getKey());
        self::assertSame($this->fixture->domainId(), (int)$row['domain_id']);
        self::assertSame('blog2', $row['subdomain_name']);
        self::assertSame('/blog2', $row['subdomain_mount']);
        self::assertSame('/htdocs', $row['subdomain_document_root']);
        self::assertSame('no', $row['subdomain_url_forward']);
        self::assertNull($row['subdomain_type_forward']);
        self::assertSame('Off', $row['subdomain_host_forward']);
        self::assertSame('no', $row['subdomain_wildcard_alias']);
        self::assertSame('toadd', $row['subdomain_status']);
    }

    public function testCreatingDispatchesThePagesEventsAndPokesTheDaemonOnce(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'blog2'
        ));

        self::assertSame(array('onBeforeAddSubdomain', 'onAfterAddSubdomain'), $this->core->eventNames());
        self::assertSame(array(
            'subdomainName'  => 'blog2.' . $this->fixture->domainName(),
            'subdomainType'  => 'dmn',
            'parentDomainId' => $this->fixture->domainId(),
            'mountPoint'     => '/blog2',
            'documentRoot'   => '/htdocs',
            'forwardUrl'     => 'no',
            'forwardType'    => null,
            'forwardHost'    => 'Off',
            'wildcardAlias'  => 'no',
            'customerId'     => $this->fixture->customerId()
        ), $this->core->events[0][1]);
        self::assertSame($ref->getKey(), $this->core->events[1][1]['subdomainId']);
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            array(array('A new subdomain (blog2.' . $this->fixture->domainName() . ') has been created by sgwtcustomer', E_USER_NOTICE)),
            $this->core->logs
        );
    }

    public function testCreatingWritesThePhpIniRowAndTheDefaultMailAccount(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'blog2'
        ));

        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM php_ini WHERE admin_id = ? AND domain_id = ? AND domain_type = 'sub'",
            array($this->fixture->customerId(), $ref->getKey())
        ));
        self::assertSame(
            array(array('mail_addr' => 'webmaster@blog2.' . $this->fixture->domainName(), 'mail_type' => 'subdom_forward', 'mail_forward' => 'sgwtcustomer@example.test')),
            $this->db->rows(
                'SELECT mail_addr, mail_type, mail_forward FROM mail_users WHERE sub_id = ? AND mail_type = ?',
                array($ref->getKey(), 'subdom_forward')
            )
        );
    }

    public function testNoDefaultMailAccountWhenThePanelIsSetNotToCreateOne(): void
    {
        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => 0));

        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'blog2'
        ));

        self::assertSame('0', (string)$this->db->value(
            'SELECT COUNT(*) FROM mail_users WHERE sub_id = ? AND mail_type = ?',
            array($ref->getKey(), 'subdom_forward')
        ));
    }

    public function testASubdomainOfAnAliasGoesInTheAliasTable(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
            'label'    => 'news'
        ));

        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $ref->getTag());

        $row = $this->db->row('SELECT * FROM subdomain_alias WHERE subdomain_alias_id = ?', array($ref->getKey()));
        self::assertSame($this->fixture->aliasId(), (int)$row['alias_id']);
        self::assertSame('news', $row['subdomain_alias_name']);
        self::assertSame('/' . $this->fixture->aliasName() . '/news', $row['subdomain_alias_mount']);
        self::assertSame('toadd', $row['subdomain_alias_status']);
        self::assertSame('als', $this->core->events[0][1]['subdomainType']);
        self::assertSame('1', (string)$this->db->value(
            "SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'subals'",
            array($ref->getKey())
        ));
    }

    public function testAReservedDirectoryNameIsMountedUnderASubPrefix(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId' => $this->domainId(), 'label' => 'logs'
        ));

        self::assertSame('/sub_logs', $this->subdomainRow($ref->getKey())['subdomain_mount']);
    }

    public function testForwardingIsNormalisedAndStoredInThePanelsTerms(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId'   => $this->domainId(),
            'label'      => 'go',
            'forwarding' => array('url' => 'HTTPS://Example.NET', 'type' => 'PERMANENT_301', 'keepHost' => false)
        ));

        $row = $this->subdomainRow($ref->getKey());
        self::assertSame('https://example.net/', $row['subdomain_url_forward']);
        self::assertSame('301', $row['subdomain_type_forward']);
        self::assertSame('Off', $row['subdomain_host_forward']);
    }

    public function testAProxyMayKeepTheHostHeader(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId'   => $this->domainId(),
            'label'      => 'app',
            'forwarding' => array('url' => 'http://127.0.0.1:8080/', 'type' => 'PROXY', 'keepHost' => true)
        ));

        $row = $this->subdomainRow($ref->getKey());
        self::assertSame('proxy', $row['subdomain_type_forward']);
        self::assertSame('On', $row['subdomain_host_forward']);
    }

    public function testKeepHostWithoutProxyIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'parentId'   => $this->domainId(),
                'label'      => 'go',
                'forwarding' => array('url' => 'https://example.net/', 'type' => 'FOUND_302', 'keepHost' => true)
            ));
        });

        self::assertSame('input.forwarding.keepHost', $e->getExtensions()['field']);
    }

    public function testAnInvalidForwardUrlIsRefusedOnItsField(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'parentId'   => $this->domainId(),
                'label'      => 'go',
                'forwarding' => array('url' => 'javascript:alert(1)', 'type' => 'FOUND_302')
            ));
        });

        self::assertSame('input.forwarding.url', $e->getExtensions()['field']);
    }

    public function testSharingAMountPointUsesTheOtherHostsDirectory(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'parentId'           => $this->domainId(),
            'label'              => 'shop2',
            'sharedMountPointOf' => $this->subdomainId()
        ));

        self::assertSame('/shop', $this->subdomainRow($ref->getKey())['subdomain_mount']);
    }

    public function testAForwardedHostHasNoDirectoryToShare(): void
    {
        // The fixture's alias forwards to https://example.net/.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'parentId'           => $this->domainId(),
                'label'              => 'shop2',
                'sharedMountPointOf' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId())
            ));
        });

        self::assertSame('input.sharedMountPointOf', $e->getExtensions()['field']);
    }

    public function testAStrangersHostCannotBeShared(): void
    {
        $e = $this->refused(ErrorCode::NOT_FOUND, function () {
            $this->service()->create($this->caller('reseller'), array(
                'parentId'           => $this->domainId(),
                'label'              => 'shop2',
                // The sibling is the same reseller's customer: reachable by the
                // caller, but not the same customer as the parent.
                'sharedMountPointOf' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->siblingDomainId())
            ));
        });

        self::assertSame('input.sharedMountPointOf', $e->getExtensions()['field']);
    }

    public function testWwwIsNotALabel(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'www'));
        });

        self::assertSame('input.label', $e->getExtensions()['field']);
    }

    public function testAnInvalidNameIsRefusedWithThePanelsReason(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'bad..x'));
        });

        self::assertSame('Usage of dot in domain name labels is prohibited.', $e->getMessage());
    }

    public function testAnEmptyLabelIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => '  '));
        });
    }

    public function testANameAlreadyInUseIsAConflict(): void
    {
        // Measurement M9: nothing in the schema stops a second 'shop'.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'shop'));
        });

        self::assertSame(array(), $this->core->events, 'nothing was dispatched');
    }

    public function testAnUnsettledParentIsAConflict(): void
    {
        $this->db->execute("UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'blog2'));
        });

        self::assertSame(5, $e->getExtensions()['retryAfterSeconds']);
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_subd_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'blog2'));
        });
    }

    public function testTheLimitCountsSubdomainsOfAliasesToo(): void
    {
        // The fixture has 'shop' and the alias subdomain 'blog': two.
        $this->db->execute('UPDATE domain SET domain_subd_limit = 2 WHERE domain_id = ?', array($this->fixture->domainId()));

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'blog2'));
        });

        self::assertSame(array('quota' => 'subdomains', 'limit' => 2, 'used' => 2), $e->getExtensions());
    }

    public function testTheFeatureIsAskedBeforeTheInput(): void
    {
        $this->db->execute('UPDATE domain SET domain_subd_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'www'));
        });
    }

    public function testTheInputIsAskedBeforeTheQuota(): void
    {
        $this->db->execute('UPDATE domain SET domain_subd_limit = 2 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array('parentId' => $this->domainId(), 'label' => 'www'));
        });
    }

    public function testAResellerIsCheckedAgainstTheCustomersLimitsAndLoggedAsThemselves(): void
    {
        $this->service()->create($this->caller('reseller'), array('parentId' => $this->domainId(), 'label' => 'blog2'));

        self::assertStringEndsWith('has been created by sgwtreseller', $this->core->logs[0][0]);
        self::assertSame($this->fixture->customerId(), $this->core->events[0][1]['customerId']);
    }

    // ---- update ---------------------------------------------------------

    public function testAPartialUpdateChangesOnlyWhatItNames(): void
    {
        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('wildcard' => true));

        $row = $this->subdomainRow($this->fixture->subdomainId());
        self::assertSame('yes', $row['subdomain_wildcard_alias']);
        self::assertSame('/htdocs', $row['subdomain_document_root']);
        self::assertSame('no', $row['subdomain_url_forward']);
        self::assertSame('tochange', $row['subdomain_status']);
        self::assertSame(array('onBeforeEditSubdomain', 'onAfterEditSubdomain'), $this->core->eventNames());
        self::assertSame('dmn', $this->core->events[0][1]['subdomainType']);
        self::assertSame(1, $this->core->requests);
        self::assertSame(
            'sgwtcustomer updated properties of the shop.' . $this->fixture->domainName() . ' subdomain',
            $this->core->logs[0][0]
        );
    }

    public function testASubdomainOfAnAliasIsUpdatedInItsOwnColumns(): void
    {
        // Measurement M11: the page cannot do this at all.
        $this->db->execute(
            "UPDATE subdomain_alias SET subdomain_alias_status = 'ok' WHERE subdomain_alias_id = ?",
            array($this->fixture->aliasSubdomainId())
        );

        $this->service()->update(
            $this->caller('customer'),
            GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()),
            array('wildcard' => false)
        );

        $row = $this->db->row(
            'SELECT subdomain_alias_wildcard_alias, subdomain_alias_status FROM subdomain_alias WHERE subdomain_alias_id = ?',
            array($this->fixture->aliasSubdomainId())
        );
        self::assertSame(array('subdomain_alias_wildcard_alias' => 'no', 'subdomain_alias_status' => 'tochange'), $row);
        self::assertSame('als', $this->core->events[0][1]['subdomainType']);
    }

    public function testAnUnsettledSubdomainCannotBeUpdated(): void
    {
        // The fixture's alias subdomain is 'toadd'.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update(
                $this->caller('customer'),
                GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()),
                array('wildcard' => true)
            );
        });
    }

    public function testAnEmptyUpdateIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array());
        });

        self::assertSame('input', $e->getExtensions()['field']);
    }

    public function testADocumentRootIsCheckedInsideTheMountsHtdocs(): void
    {
        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs/public'));

        self::assertSame(array(array('sgwtcustomer', '/shop/htdocs', '/public')), $this->probe->asked);
        self::assertSame('/htdocs/public', $this->subdomainRow($this->fixture->subdomainId())['subdomain_document_root']);
    }

    public function testHtdocsItselfNeedsNoCheck(): void
    {
        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs'));

        self::assertSame(array(), $this->probe->asked);
    }

    public function testADocumentRootOutsideHtdocsIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs/../../etc'));
        });

        self::assertSame('input.documentRoot', $e->getExtensions()['field']);
    }

    public function testAMissingDirectoryIsRefused(): void
    {
        $this->probe = new FakeDirectoryProbe(false);
        $this->reconfigure(array());

        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array('documentRoot' => '/htdocs/missing'));
        });
    }

    public function testAForwardedHostServesNoDocumentRoot(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->subdomainId(), array(
                'forwarding'   => array('url' => 'https://example.net/', 'type' => 'FOUND_302'),
                'documentRoot' => '/htdocs/public'
            ));
        });

        self::assertSame('input.documentRoot', $e->getExtensions()['field']);
    }

    public function testForwardingNullRemovesIt(): void
    {
        $this->db->execute(
            "UPDATE subdomain SET subdomain_url_forward = 'https://example.net/', subdomain_type_forward = '302' WHERE subdomain_id = ?",
            array($this->fixture->subdomainId())
        );

        $this->service()->update($this->caller('customer'), $this->subdomainId(), array('forwarding' => null));

        $row = $this->subdomainRow($this->fixture->subdomainId());
        self::assertSame('no', $row['subdomain_url_forward']);
        self::assertNull($row['subdomain_type_forward']);
        self::assertSame('Off', $row['subdomain_host_forward']);
    }

    // ---- delete ---------------------------------------------------------

    public function testDeletingSchedulesTheSubdomainAndEverythingUnderIt(): void
    {
        $name = $this->fixture->subdomainName();
        $this->insert('ftp_users', array(
            'userid' => 'web@' . $name, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            // A gid no real customer on the box has: FtpGroups::withMemberOn()
            // joins ftp_group to ftp_users on gid, as the core does.
            'uid' => 64001, 'gid' => 64001, 'shell' => '/bin/sh', 'homedir' => '/var/www/virtual/x', 'status' => 'ok'
        ));
        $this->insert('ftp_group', array(
            'groupname' => 'sgwtcustomer', 'gid' => 64001,
            'members'   => 'web@' . $name . ',' . $this->fixture->ftpUserId()
        ));
        $mailId = $this->insert('mail_users', array(
            'mail_acc' => 'info', 'mail_pass' => '_no_', 'mail_forward' => 'a@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'subdom_forward',
            'sub_id' => $this->fixture->subdomainId(), 'status' => 'ok', 'po_active' => 'no',
            'quota' => 0, 'mail_addr' => 'info@' . $name
        ));
        $this->insert('ssl_certs', array(
            'domain_id' => $this->fixture->subdomainId(), 'domain_type' => 'sub',
            'private_key' => 'k', 'certificate' => 'c', 'status' => 'ok'
        ));
        $inside = $this->insert('htaccess', array(
            'dmn_id' => $this->fixture->domainId(), 'user_id' => '1', 'auth_type' => 'Basic',
            'auth_name' => 'x', 'path' => '/shop/private', 'status' => 'ok'
        ));
        $lookalike = $this->insert('htaccess', array(
            'dmn_id' => $this->fixture->domainId(), 'user_id' => '1', 'auth_type' => 'Basic',
            'auth_name' => 'x', 'path' => '/shopping', 'status' => 'ok'
        ));
        $this->insert('php_ini', array(
            'admin_id' => $this->fixture->customerId(), 'domain_id' => $this->fixture->subdomainId(), 'domain_type' => 'sub'
        ));

        $ref = $this->service()->delete($this->caller('customer'), $this->subdomainId());

        self::assertSame(NodeType::SUBDOMAIN, $ref->getTag());
        self::assertNull($ref->getSnapshot());
        self::assertSame('todelete', $this->subdomainRow($this->fixture->subdomainId())['subdomain_status']);
        self::assertSame('todelete', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array('web@' . $name)));
        self::assertSame('ok', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array($this->fixture->ftpUserId())));
        self::assertSame($this->fixture->ftpUserId(), $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertSame('todelete', $this->db->value('SELECT status FROM mail_users WHERE mail_id = ?', array($mailId)));
        self::assertSame('todelete', $this->db->value("SELECT status FROM ssl_certs WHERE domain_id = ? AND domain_type = 'sub'", array($this->fixture->subdomainId())));
        self::assertSame('todelete', $this->db->value('SELECT status FROM htaccess WHERE id = ?', array($inside)));
        self::assertSame('ok', $this->db->value('SELECT status FROM htaccess WHERE id = ?', array($lookalike)), 'C11 item 6');
        self::assertSame('0', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'sub'", array($this->fixture->subdomainId())));
        self::assertSame(array('onBeforeDeleteSubdomain', 'onAfterDeleteSubdomain'), $this->core->eventNames());
        self::assertSame(
            array('subdomainId' => $this->fixture->subdomainId(), 'subdomainName' => $name, 'subdomainType' => 'sub', 'type' => 'sub'),
            $this->core->events[0][1]
        );
        self::assertSame(1, $this->core->requests);
        self::assertSame('Deletion of the ' . $name . ' subdomain has been scheduled by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testTheLastMemberLeavingRemovesTheFtpGroupAndItsQuota(): void
    {
        $name = $this->fixture->subdomainName();
        $this->insert('ftp_users', array(
            'userid' => 'web@' . $name, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            'uid' => 64002, 'gid' => 64002, 'shell' => '/bin/sh', 'homedir' => '/var/www/virtual/x', 'status' => 'ok'
        ));
        $this->insert('ftp_group', array('groupname' => 'sgwtcustomer', 'gid' => 64002, 'members' => 'web@' . $name));
        $this->insert('quotalimits', array('name' => 'sgwtcustomer', 'quota_type' => 'group'));

        $this->service()->delete($this->caller('customer'), $this->subdomainId());

        self::assertNull($this->db->value("SELECT groupname FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertNull($this->db->value("SELECT name FROM quotalimits WHERE name = 'sgwtcustomer'"));
    }

    public function testAnUnderscoreInTheNameDoesNotReachAnotherCustomersFtpUsers(): void
    {
        // Measurement M16, C11 item 6.
        $id = $this->insert('subdomain', array(
            'domain_id' => $this->fixture->domainId(), 'subdomain_name' => 'a_b', 'subdomain_mount' => '/a_b',
            'subdomain_status' => 'ok'
        ));
        $this->insert('ftp_users', array(
            'userid' => 'x@axb.' . $this->fixture->domainName(), 'admin_id' => $this->fixture->siblingId(),
            'passwd' => 'x', 'uid' => 1, 'gid' => 1, 'shell' => '/bin/sh', 'homedir' => '/x', 'status' => 'ok'
        ));

        $this->service()->delete($this->caller('customer'), GlobalId::encode(NodeType::SUBDOMAIN, $id));

        self::assertSame('ok', $this->db->value(
            'SELECT status FROM ftp_users WHERE userid = ?', array('x@axb.' . $this->fixture->domainName())
        ));
    }

    public function testAFailedSubdomainMayBeDeleted(): void
    {
        $this->db->execute(
            "UPDATE subdomain SET subdomain_status = 'Could not create the vhost' WHERE subdomain_id = ?",
            array($this->fixture->subdomainId())
        );

        $this->service()->delete($this->caller('customer'), $this->subdomainId());

        self::assertSame('todelete', $this->subdomainRow($this->fixture->subdomainId())['subdomain_status']);
    }

    public function testAPendingSubdomainMayNotBeDeleted(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->delete(
                $this->caller('customer'),
                GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId())
            );
        });
    }

    public function testDeletingAnAliasSubdomainAsksTheAliasFeature(): void
    {
        // alssub_delete.php:36 asks 'domain_aliases', not 'subdomains'.
        $this->db->execute(
            "UPDATE subdomain_alias SET subdomain_alias_status = 'ok' WHERE subdomain_alias_id = ?",
            array($this->fixture->aliasSubdomainId())
        );
        $this->db->execute(
            'UPDATE domain SET domain_alias_limit = -1, domain_subd_limit = 0 WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        $aliasSubdomain = GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId());

        $e = $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () use ($aliasSubdomain) {
            $this->service()->delete($this->caller('customer'), $aliasSubdomain);
        });
        self::assertSame('domainAliases', $e->getExtensions()['feature']);

        $this->db->execute(
            'UPDATE domain SET domain_alias_limit = 0, domain_subd_limit = -1 WHERE domain_id = ?',
            array($this->fixture->domainId())
        );
        $this->service()->delete($this->caller('customer'), $aliasSubdomain);

        self::assertSame('alssub', $this->core->events[0][1]['subdomainType']);
    }
}
