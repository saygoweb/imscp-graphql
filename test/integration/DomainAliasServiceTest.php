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

use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class DomainAliasServiceTest extends ServiceTestCase
{
    private function service(): DomainAliasService
    {
        return new DomainAliasService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function aliasId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId());
    }

    private function aliasRow(int $id): ?array
    {
        return $this->db->row('SELECT * FROM domain_aliasses WHERE alias_id = ?', array($id));
    }

    // ---- create ---------------------------------------------------------

    public function testACustomersAliasIsOrderedAndTheResellerIsNotified(): void
    {
        // Decision D13.
        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
        ));

        self::assertSame(NodeType::DOMAIN_ALIAS, $ref->getTag());

        $row = $this->aliasRow($ref->getKey());
        self::assertSame($this->fixture->domainId(), (int)$row['domain_id']);
        self::assertSame('sgwtnew.test', $row['alias_name']);
        self::assertSame('/sgwtnew.test', $row['alias_mount']);
        self::assertSame('/htdocs', $row['alias_document_root']);
        self::assertSame($this->fixture->ipId(), (int)$row['alias_ip_id']);
        self::assertSame('ordered', $row['alias_status']);

        self::assertSame(0, $this->core->requests, 'an order is not provisioned');
        self::assertSame(array(array($this->fixture->customerId(), 'sgwtnew.test')), $this->core->aliasOrders);
        self::assertSame('A new domain alias (sgwtnew.test) has been ordered by sgwtcustomer', $this->core->logs[0][0]);
        self::assertSame('0', (string)$this->db->value('SELECT COUNT(*) FROM mail_users WHERE sub_id = ? AND mail_type = ?', array($ref->getKey(), 'alias_forward')));
        self::assertSame('1', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($ref->getKey())));
        self::assertSame(array('onBeforeAddDomainAlias', 'onAfterAddDomainAlias'), $this->core->eventNames());
        self::assertSame($ref->getKey(), $this->core->events[1][1]['domainAliasId']);
    }

    public function testAResellersAliasIsCreatedWithItsDefaultMailAccounts(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), array(
            'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
        ));

        self::assertSame('toadd', $this->aliasRow($ref->getKey())['alias_status']);
        self::assertSame(1, $this->core->requests);
        self::assertSame(array(), $this->core->aliasOrders);
        self::assertSame('A new domain alias (sgwtnew.test) has been created by sgwtreseller', $this->core->logs[0][0]);
        self::assertSame(
            array('abuse', 'hostmaster', 'postmaster', 'webmaster'),
            array_column($this->db->rows(
                'SELECT mail_acc FROM mail_users WHERE sub_id = ? AND mail_type = ? ORDER BY mail_acc',
                array($ref->getKey(), 'alias_forward')
            ), 'mail_acc')
        );
    }

    public function testWwwIsStrippedAsOftenAsItAppears(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'WWW.www.sgwtnew.test'
        ));

        self::assertSame('sgwtnew.test', $this->aliasRow($ref->getKey())['alias_name']);
    }

    public function testAUnicodeNameIsStoredAsPunycode(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'bücher-sgwt.test'
        ));

        self::assertSame(
            $this->core->toAscii('bücher-sgwt.test'),
            $this->aliasRow($ref->getKey())['alias_name']
        );
        self::assertStringStartsWith('xn--', $this->aliasRow($ref->getKey())['alias_name']);
    }

    public function testATakenNameIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => $this->fixture->aliasName()
            ));
        });
    }

    public function testASubzoneOfAnotherResellersDomainIsAConflict(): void
    {
        // imscp_domain_exists(): 'sgwtstranger.test' belongs to the other
        // reseller's customer.
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'shop.sgwtstranger.test'
            ));
        });
    }

    public function testAnInvalidNameIsRefusedOnItsField(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'no-dot'
            ));
        });

        self::assertSame('input.name', $e->getExtensions()['field']);
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        $this->db->execute('UPDATE domain SET domain_alias_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
            ));
        });
    }

    public function testOrderedAliasesDoNotCountTowardsTheLimit(): void
    {
        // Counting.php:497.
        $this->db->execute('UPDATE domain SET domain_alias_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $ref = $this->service()->create($this->caller('customer'), array(
            'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
        ));

        self::assertNotNull($this->aliasRow($ref->getKey()));
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_alias_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->service()->create($this->caller('customer'), array(
                'domainId' => $this->domainId(), 'name' => 'sgwtnew.test'
            ));
        });
    }

    // ---- update ---------------------------------------------------------

    public function testAnAliasUpdateWritesThePanelsColumns(): void
    {
        $this->service()->update($this->caller('customer'), $this->aliasId(), array('wildcard' => true));

        $row = $this->aliasRow($this->fixture->aliasId());
        self::assertSame('yes', $row['wildcard_alias']);
        self::assertSame('https://example.net/', $row['url_forward'], 'forwarding kept');
        self::assertSame('tochange', $row['alias_status']);
        self::assertSame(array('onBeforeEditDomainAlias', 'onAfterEditDomainAlias'), $this->core->eventNames());
        self::assertSame($this->fixture->aliasId(), $this->core->events[0][1]['domainAliasId']);
        self::assertSame(
            'sgwtcustomer updated properties of the ' . $this->fixture->aliasName() . ' domain alias',
            $this->core->logs[0][0]
        );
    }

    public function testRemovingForwardingAndSettingADocumentRootInOneUpdate(): void
    {
        $this->service()->update($this->caller('customer'), $this->aliasId(), array(
            'forwarding' => null, 'documentRoot' => '/htdocs/site'
        ));

        $row = $this->aliasRow($this->fixture->aliasId());
        self::assertSame('no', $row['url_forward']);
        self::assertSame('/htdocs/site', $row['alias_document_root']);
        self::assertSame(array(array('sgwtcustomer', '/alias/htdocs', '/site')), $this->probe->asked);
    }

    public function testAnOrderedAliasCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $e = $this->refused(ErrorCode::FORBIDDEN, function () {
            $this->service()->update($this->caller('customer'), $this->aliasId(), array('wildcard' => true));
        });

        self::assertSame('ORDERED', $e->getExtensions()['state']);
    }

    // ---- delete ---------------------------------------------------------

    public function testDeletingSchedulesTheAliasAndEverythingUnderIt(): void
    {
        $alias = $this->fixture->aliasName();
        $blog = $this->fixture->aliasSubdomainId();
        $this->db->execute("UPDATE subdomain_alias SET subdomain_alias_status = 'ok' WHERE subdomain_alias_id = ?", array($blog));

        $this->insert('ftp_users', array(
            'userid' => 'a@' . $alias, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            'uid' => 64003, 'gid' => 64003, 'shell' => '/bin/sh', 'homedir' => '/x', 'status' => 'ok'
        ));
        $this->insert('ftp_users', array(
            'userid' => 'b@blog.' . $alias, 'admin_id' => $this->fixture->customerId(), 'passwd' => 'x',
            'uid' => 64003, 'gid' => 64003, 'shell' => '/bin/sh', 'homedir' => '/x', 'status' => 'ok'
        ));
        $this->insert('ftp_group', array(
            'groupname' => 'sgwtcustomer', 'gid' => 64003,
            'members'   => 'a@' . $alias . ',b@blog.' . $alias . ',' . $this->fixture->ftpUserId()
        ));
        $aliasMail = $this->insert('mail_users', array(
            'mail_acc' => 'info', 'mail_pass' => '_no_', 'mail_forward' => 'x@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'alias_forward',
            'sub_id' => $this->fixture->aliasId(), 'status' => 'ok', 'po_active' => 'no', 'quota' => 0,
            'mail_addr' => 'info@' . $alias
        ));
        $dns = $this->insert('domain_dns', array(
            'domain_id' => $this->fixture->domainId(), 'alias_id' => $this->fixture->aliasId(),
            'domain_dns' => 'www', 'domain_class' => 'IN', 'domain_type' => 'CNAME',
            'domain_text' => $alias . '.', 'owned_by' => 'custom_dns_feature', 'domain_dns_status' => 'ok'
        ));
        $this->insert('php_ini', array('admin_id' => $this->fixture->customerId(), 'domain_id' => $this->fixture->aliasId(), 'domain_type' => 'als'));
        $this->insert('php_ini', array('admin_id' => $this->fixture->customerId(), 'domain_id' => $blog, 'domain_type' => 'subals'));
        $this->insert('ssl_certs', array('domain_id' => $this->fixture->aliasId(), 'domain_type' => 'als', 'private_key' => 'k', 'certificate' => 'c', 'status' => 'ok'));
        $this->insert('ssl_certs', array('domain_id' => $blog, 'domain_type' => 'alssub', 'private_key' => 'k', 'certificate' => 'c', 'status' => 'ok'));

        $ref = $this->service()->delete($this->caller('customer'), $this->aliasId());

        self::assertNull($ref->getSnapshot());
        self::assertSame('todelete', $this->aliasRow($this->fixture->aliasId())['alias_status']);
        self::assertSame('todelete', $this->db->value('SELECT subdomain_alias_status FROM subdomain_alias WHERE subdomain_alias_id = ?', array($blog)));
        self::assertSame('todelete', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array('a@' . $alias)));
        self::assertSame('todelete', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array('b@blog.' . $alias)));
        self::assertSame('ok', $this->db->value('SELECT status FROM ftp_users WHERE userid = ?', array($this->fixture->ftpUserId())));
        self::assertSame($this->fixture->ftpUserId(), $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertSame('todelete', $this->db->value('SELECT status FROM mail_users WHERE mail_id = ?', array($aliasMail)));
        self::assertSame('todelete', $this->db->value('SELECT status FROM mail_users WHERE mail_id = ?', array($this->fixture->forwardId())), 'hello@blog.<alias>');
        self::assertNull($this->db->value('SELECT domain_dns_id FROM domain_dns WHERE domain_dns_id = ?', array($dns)));
        self::assertSame('0', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE (domain_id = ? AND domain_type = 'als') OR (domain_id = ? AND domain_type = 'subals')", array($this->fixture->aliasId(), $blog)));
        self::assertSame('2', (string)$this->db->value("SELECT COUNT(*) FROM ssl_certs WHERE status = 'todelete' AND ((domain_id = ? AND domain_type = 'als') OR (domain_id = ? AND domain_type = 'alssub'))", array($this->fixture->aliasId(), $blog)));
        self::assertSame(array('onBeforeDeleteDomainAlias', 'onAfterDeleteDomainAlias'), $this->core->eventNames());
        self::assertSame(array('domainAliasId' => $this->fixture->aliasId(), 'domainAliasName' => $alias), $this->core->events[0][1]);
        self::assertSame(1, $this->core->requests);
        self::assertSame('sgwtcustomer scheduled deletion of the ' . $alias . ' domain alias', $this->core->logs[0][0]);
    }

    public function testDeletingAnAliasMountedAtTheRootLeavesEveryHtaccessRowAlone(): void
    {
        // Checkpoint B, B2: an alias sharing the main domain's own mount
        // point ('/') must not schedule the whole customer's protected areas
        // for deletion.
        $this->db->execute("UPDATE domain_aliasses SET alias_mount = '/' WHERE alias_id = ?", array($this->fixture->aliasId()));
        $inside = $this->insert('htaccess', array(
            'dmn_id' => $this->fixture->domainId(), 'user_id' => '1', 'auth_type' => 'Basic',
            'auth_name' => 'x', 'path' => '/shop/private', 'status' => 'ok'
        ));

        $this->service()->delete($this->caller('customer'), $this->aliasId());

        self::assertSame('todelete', $this->aliasRow($this->fixture->aliasId())['alias_status']);
        self::assertSame('ok', $this->db->value('SELECT status FROM htaccess WHERE id = ?', array($inside)));
    }

    public function testCancellingAnOrderRemovesTheRowAndItsPhpIni(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($this->fixture->aliasId()));
        $this->insert('php_ini', array('admin_id' => $this->fixture->customerId(), 'domain_id' => $this->fixture->aliasId(), 'domain_type' => 'als'));

        $ref = $this->service()->delete($this->caller('customer'), $this->aliasId());

        self::assertNull($this->aliasRow($this->fixture->aliasId()));
        self::assertSame('0', (string)$this->db->value("SELECT COUNT(*) FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($this->fixture->aliasId())), 'C11 item 8');
        self::assertSame(array(), $this->core->events, 'an order that never reached the backend dispatches nothing');
        self::assertSame(0, $this->core->requests);
        self::assertSame('ordered', $ref->getSnapshot()['status']);
        self::assertSame($this->fixture->aliasName(), $ref->getSnapshot()['name']);
    }

    public function testAPendingAliasCannotBeDeleted(): void
    {
        $this->db->execute("UPDATE domain_aliasses SET alias_status = 'toadd' WHERE alias_id = ?", array($this->fixture->aliasId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->delete($this->caller('customer'), $this->aliasId());
        });
    }
}
