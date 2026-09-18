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
use iMSCP\Plugin\SGW_GraphQL\Repository\FtpGroups;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;

/**
 * FTP users. Owned by the customer, not the domain (spec section 2.2), and
 * named user@host for whichever of the customer's hosts the login is on.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/ftp_add.php, ftp_edit.php and
 *   ftp_delete.php. Retire when FtpUserService lands in core (spec section 21,
 *   C3 row 2).
 */
final class FtpService
{
    const HOSTS = array(NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN);

    const SHELL = '/bin/sh';

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
        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::FTP_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_ftpacc_limit'),
            $kit->counts()->ftpUsers(array($account->getAdminId()))[$account->getAdminId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'ftp');

        // 5. ftp_add.php lists only settled hosts (lines 122-160).
        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // 6. CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:191-231.
        $username = trim((string)($input['username'] ?? ''), ' ');

        if (!$core->isValidUsername($username)) {
            throw Guard::badInput('input.username', 'Invalid FTP username.');
        }

        $password = $this->password($input);
        $directory = $this->directory($account, (string)($input['directory'] ?? '/'));

        // 7.
        Guard::requireQuota($quota, 'ftpUsers');

        $userid = $username . '@' . $hostRow['name'];
        $home = $this->home($account, $directory);

        $params = array(
            'ftpUserId'    => $userid,
            'ftpPassword'  => $password,
            'ftpUserUid'   => $account->getSysUid(),
            'ftpUserGid'   => $account->getSysGid(),
            'ftpUserShell' => self::SHELL,
            'ftpUserHome'  => $home
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:256-330.
        $kit->writer()->run(function () use ($kit, $core, $account, $userid, $password, $home, $params) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeAddFtp, $params);

            $db->execute(
                "
                    INSERT INTO ftp_users (userid, admin_id, passwd, uid, gid, shell, homedir, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'toadd')
                ",
                array(
                    $userid, $account->getAdminId(), $core->hashPassword($password),
                    $account->getSysUid(), $account->getSysGid(), self::SHELL, $home
                )
            );

            (new FtpGroups($db))->addMember($account->getUsername(), $account->getSysGid(), $userid);

            // The page (ftp_add.php) probes for an existing row first, but
            // quotalimits.name is the primary key: two creates for the same
            // customer can interleave between a probe and its insert, so the
            // insert is made idempotent instead. "name = name" keeps an
            // existing row exactly as it is, which is what the probe meant.
            $diskLimit = (int)$account->domain('domain_disk_limit');

            $db->execute(
                "
                    INSERT INTO quotalimits (
                        name, quota_type, per_session, limit_type, bytes_in_avail, bytes_out_avail,
                        bytes_xfer_avail, files_in_avail, files_out_avail, files_xfer_avail
                    ) VALUES (?, 'group', 'false', 'hard', ?, 0, 0, 0, 0, 0)
                    ON DUPLICATE KEY UPDATE name = name
                ",
                array($account->getUsername(), $diskLimit > 0 ? $diskLimit * 1048576 : 0)
            );

            $core->dispatch(Events::onAfterAddFtp, $params);
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(
            sprintf('A new FTP account (%s) has been created by %s', $userid, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::FTP_USER, $userid);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::FTP_USER), Scope::FTP_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_ftpacc_limit') >= 0, 'ftp');

        $row = $this->ftpRow($target);
        // ftp_edit.php does not ask; spec section 8.3 does.
        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK));

        if (!isset($input['password']) && !isset($input['directory'])) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $password = isset($input['password']) ? $this->password($input) : '';
        $home = isset($input['directory'])
            ? $this->home($account, $this->directory($account, (string)$input['directory']))
            : (string)$row['homedir'];
        $userid = (string)$row['userid'];
        $params = array('ftpUserId' => $userid, 'ftpPassword' => $password, 'ftpUserHome' => $home);

        // CORE-DEBT(C3): transcribed from gui/public/client/ftp_edit.php:88-110.
        $kit->writer()->run(function () use ($kit, $core, $account, $userid, $password, $home, $params) {
            $core->dispatch(Events::onBeforeEditFtp, $params);

            if ($password !== '') {
                $kit->db()->execute(
                    "UPDATE ftp_users SET passwd = ?, homedir = ?, status = 'tochange' WHERE userid = ? AND admin_id = ?",
                    array($core->hashPassword($password), $home, $userid, $account->getAdminId())
                );
            } else {
                $kit->db()->execute(
                    "UPDATE ftp_users SET homedir = ?, status = 'tochange' WHERE userid = ? AND admin_id = ?",
                    array($home, $userid, $account->getAdminId())
                );
            }

            $core->dispatch(Events::onAfterEditFtp, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('An FTP account (%s) has been updated by: %s', $userid, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::FTP_USER, $userid);
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::FTP_USER), Scope::FTP_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_ftpacc_limit') >= 0, 'ftp');

        $row = $this->ftpRow($target);
        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $userid = (string)$row['userid'];

        // CORE-DEBT(C3): transcribed from gui/public/client/ftp_delete.php:53-91.
        $kit->writer()->run(function () use ($kit, $core, $account, $userid) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteFtp, array('ftpUserId' => $userid));

            $groups = new FtpGroups($db);
            $group = $groups->ofCustomer($account->getUsername());

            // The page changes the group only when the user is in it (line 67).
            if ($group !== null && in_array($userid, explode(',', (string)$group['members']), true)) {
                $groups->removeMembers($group, static function (string $member) use ($userid) {
                    return $member === $userid;
                });
            }

            $db->execute("UPDATE ftp_users SET status = 'todelete' WHERE userid = ?", array($userid));
            $core->dispatch(Events::onAfterDeleteFtp, array('ftpUserId' => $userid));
        });

        $core->sendRequest();
        $core->writeLog(sprintf('An FTP account has been deleted by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::FTP_USER, $userid);
    }

    /**
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    private function password(array $input): string
    {
        $core = $this->kit->core();
        $password = trim((string)($input['password'] ?? ''), ' ');

        if ($password === '' || !$core->isAcceptablePassword($password)) {
            throw Guard::badInput('input.password', "The password does not meet the panel's password policy.", array(
                'minLength'                => max(6, (int)$core->config('PASSWD_CHARS', 6)),
                'requiresLettersAndDigits' => (bool)$core->config('PASSWD_STRONG', false)
            ));
        }

        return $password;
    }

    /**
     * A directory relative to the customer's web root, normalised and checked.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:196,225-231.
     */
    private function directory(CustomerAccount $account, string $input): string
    {
        $directory = $this->kit->core()->normalisePath('/' . trim($input, ' '));

        if ($directory !== '/' && !$this->kit->probe()->exists($account->getUsername(), '/', $directory)) {
            throw Guard::badInput('input.directory', sprintf("The directory '%s' does not exist.", $directory));
        }

        return $directory;
    }

    /** CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:234-241. */
    private function home(CustomerAccount $account, string $directory): string
    {
        return $this->kit->core()->normalisePath(
            '/' . $this->kit->core()->config('USER_WEB_DIR', '/var/www/virtual')
                . '/' . $account->getDomainName() . '/' . $directory
        );
    }

    /**
     * @return array{userid: string, admin_id: string, homedir: string, status: string}
     */
    private function ftpRow(Target $target): array
    {
        $row = $this->kit->db()->row(
            'SELECT userid, admin_id, homedir, status FROM ftp_users WHERE userid = ?',
            array((string)$target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        return $row;
    }
}
