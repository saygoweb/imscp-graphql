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

use iMSCP\Plugin\SGW_GraphQL\Service\FtpService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Double\FakeDirectoryProbe;

class FtpServiceTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The fixture withholds FTP; every test here but one wants it granted.
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = 0 WHERE domain_id = ?', array($this->fixture->domainId()));
    }

    private function service(): FtpService
    {
        return new FtpService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function ftpId(): string
    {
        return GlobalId::encodeKey(NodeType::FTP_USER, $this->fixture->ftpUserId());
    }

    private function ftp(string $userid): ?array
    {
        return $this->db->row('SELECT * FROM ftp_users WHERE userid = ?', array($userid));
    }

    private function create(string $username, array $extra = array())
    {
        return $this->service()->create($this->caller('customer'), array_merge(array(
            'hostId' => $this->domainId(), 'username' => $username, 'password' => 'Ftp0Password'
        ), $extra));
    }

    public function testAnFtpUserIsCreatedInTheCustomersWebRoot(): void
    {
        $ref = $this->create('web');

        $userid = 'web@' . $this->fixture->domainName();
        self::assertSame(NodeType::FTP_USER, $ref->getTag());
        self::assertSame($userid, $ref->getKey());

        $row = $this->ftp($userid);
        self::assertSame($this->fixture->customerId(), (int)$row['admin_id']);
        self::assertStringStartsWith('$6$', $row['passwd']);
        self::assertSame('/bin/sh', $row['shell']);
        self::assertSame('/var/www/virtual/' . $this->fixture->domainName(), $row['homedir']);
        self::assertSame('toadd', $row['status']);

        self::assertSame(array('groupname' => 'sgwtcustomer', 'gid' => '0', 'members' => $userid), array_map('strval', $this->db->row(
            "SELECT groupname, gid, members FROM ftp_group WHERE groupname = 'sgwtcustomer'"
        )));
        // bytes_in_avail is a FLOAT, which MariaDB prints to six significant
        // digits. 5 GiB is exact in a float, so a DECIMAL cast reads it whole.
        self::assertSame('5368709120', (string)$this->db->value(
            "SELECT CAST(bytes_in_avail AS DECIMAL(20,0)) FROM quotalimits WHERE name = 'sgwtcustomer'"
        ), 'the account disk limit, 5120 MiB, in bytes');

        self::assertSame(array('onBeforeAddFtp', 'onAfterAddFtp'), $this->core->eventNames());
        self::assertSame(array(
            'ftpUserId'    => $userid,
            'ftpPassword'  => 'Ftp0Password',
            'ftpUserUid'   => 0,
            'ftpUserGid'   => 0,
            'ftpUserShell' => '/bin/sh',
            'ftpUserHome'  => '/var/www/virtual/' . $this->fixture->domainName()
        ), $this->core->events[0][1], 'listeners are handed the password in clear, as the page hands it');
        self::assertSame(1, $this->core->requests);
        self::assertSame('A new FTP account (' . $userid . ') has been created by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testASecondUserJoinsTheGroupAndTheQuotaRowIsNotRepeated(): void
    {
        $this->create('one');
        $this->create('two');

        self::assertSame(
            'one@' . $this->fixture->domainName() . ',two@' . $this->fixture->domainName(),
            $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'")
        );
        self::assertSame('1', (string)$this->db->value("SELECT COUNT(*) FROM quotalimits WHERE name = 'sgwtcustomer'"));
    }

    public function testADirectoryIsCheckedAndBecomesTheHome(): void
    {
        $this->create('web', array('directory' => 'shop/../shop/'));

        self::assertSame(array(array('sgwtcustomer', '/', '/shop')), $this->probe->asked);
        self::assertSame(
            '/var/www/virtual/' . $this->fixture->domainName() . '/shop',
            $this->ftp('web@' . $this->fixture->domainName())['homedir']
        );
    }

    public function testAMissingDirectoryIsRefused(): void
    {
        $this->probe = new FakeDirectoryProbe(false);
        $this->reconfigure(array());

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->create('web', array('directory' => '/nowhere'));
        });

        self::assertSame('input.directory', $e->getExtensions()['field']);
    }

    public function testTheLoginIsOnTheChosenHost(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId'   => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
            'username' => 'web', 'password' => 'Ftp0Password'
        ));

        self::assertSame('web@' . $this->fixture->subdomainName(), $ref->getKey());
    }

    public function testAnInvalidUsernameIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->create('-bad');
        });

        self::assertSame('input.username', $e->getExtensions()['field']);
    }

    public function testAWeakPasswordIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->create('web', array('password' => 'short'));
        });

        self::assertSame('input.password', $e->getExtensions()['field']);
    }

    public function testATakenLoginIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->create('sgwtftp');
        });
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = -1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::FEATURE_UNAVAILABLE, function () {
            $this->create('web');
        });
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = 1 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->create('web');
        });
    }

    public function testChangingThePasswordKeepsTheHome(): void
    {
        $before = $this->ftp($this->fixture->ftpUserId());

        $this->service()->update($this->caller('customer'), $this->ftpId(), array('password' => 'Ftp0Password2'));

        $row = $this->ftp($this->fixture->ftpUserId());
        self::assertNotSame($before['passwd'], $row['passwd']);
        self::assertStringStartsWith('$6$', $row['passwd']);
        self::assertSame($before['homedir'], $row['homedir']);
        self::assertSame('tochange', $row['status']);
        self::assertSame(array('onBeforeEditFtp', 'onAfterEditFtp'), $this->core->eventNames());
        self::assertSame('Ftp0Password2', $this->core->events[0][1]['ftpPassword']);
        self::assertSame(
            'An FTP account (' . $this->fixture->ftpUserId() . ') has been updated by: sgwtcustomer',
            $this->core->logs[0][0]
        );
    }

    public function testChangingTheDirectoryKeepsThePassword(): void
    {
        $before = $this->ftp($this->fixture->ftpUserId());

        $this->service()->update($this->caller('customer'), $this->ftpId(), array('directory' => '/logs'));

        $row = $this->ftp($this->fixture->ftpUserId());
        self::assertSame($before['passwd'], $row['passwd']);
        self::assertSame('/var/www/virtual/' . $this->fixture->domainName() . '/logs', $row['homedir']);
        self::assertSame('', $this->core->events[0][1]['ftpPassword'], 'the page passes an empty password when it is unchanged');
    }

    public function testAnEmptyUpdateIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->ftpId(), array());
        });
    }

    public function testAPendingUserCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE ftp_users SET status = 'tochange' WHERE userid = ?", array($this->fixture->ftpUserId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->ftpId(), array('password' => 'Ftp0Password2'));
        });
    }

    public function testDeletingTheLastMemberRemovesTheGroupAndItsQuota(): void
    {
        $this->insert('ftp_group', array('groupname' => 'sgwtcustomer', 'gid' => 0, 'members' => $this->fixture->ftpUserId()));
        $this->insert('quotalimits', array('name' => 'sgwtcustomer', 'quota_type' => 'group'));

        $ref = $this->service()->delete($this->caller('customer'), $this->ftpId());

        self::assertSame($this->fixture->ftpUserId(), $ref->getKey());
        self::assertSame('todelete', $this->ftp($this->fixture->ftpUserId())['status']);
        self::assertNull($this->db->value("SELECT groupname FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
        self::assertNull($this->db->value("SELECT name FROM quotalimits WHERE name = 'sgwtcustomer'"));
        self::assertSame(array('onBeforeDeleteFtp', 'onAfterDeleteFtp'), $this->core->eventNames());
        self::assertSame(array('ftpUserId' => $this->fixture->ftpUserId()), $this->core->events[0][1]);
        self::assertSame('An FTP account has been deleted by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testDeletingOneMemberKeepsTheOthers(): void
    {
        $this->insert('ftp_group', array(
            'groupname' => 'sgwtcustomer', 'gid' => 0,
            'members'   => 'other@' . $this->fixture->domainName() . ',' . $this->fixture->ftpUserId()
        ));

        $this->service()->delete($this->caller('customer'), $this->ftpId());

        self::assertSame(
            'other@' . $this->fixture->domainName(),
            $this->db->value("SELECT members FROM ftp_group WHERE groupname = 'sgwtcustomer'")
        );
    }

    public function testAUserNotInTheGroupLeavesTheGroupAlone(): void
    {
        $this->insert('ftp_group', array('groupname' => 'sgwtcustomer', 'gid' => 0, 'members' => ''));

        $this->service()->delete($this->caller('customer'), $this->ftpId());

        self::assertSame('sgwtcustomer', $this->db->value("SELECT groupname FROM ftp_group WHERE groupname = 'sgwtcustomer'"));
    }
}
