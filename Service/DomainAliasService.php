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
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;

/**
 * Domain aliases of a customer's main domain.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/alias_add.php, alias_edit.php,
 *   alias_delete.php, alias_order_delete.php and deleteDomainAlias() in
 *   gui/include/Shared.php. Retire when DomainAliasService lands in core
 *   (spec section 21, C3 row 5).
 */
final class DomainAliasService
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

    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $domain = $kit->guard()->target(
            $caller, $input['domainId'] ?? null, array(NodeType::DOMAIN), Scope::DOMAINS_WRITE, 'input.domainId'
        );
        $account = $kit->accounts()->customer($domain->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_alias_limit'),
            $kit->counts()->domainAliases(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'domainAliases');

        // 5. The page does not ask; an alias hung off a domain mid-change
        //    would be provisioned against half of that change.
        $domainRow = $kit->vhost(NodeType::DOMAIN, $domain->getKey());
        Guard::requireState($domainRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $name = VhostRules::stripWww(mb_strtolower(trim((string)($input['name'] ?? ''))));

        if ($name === '') {
            throw Guard::badInput('input.name', 'A domain alias name is required.');
        }

        $reason = $core->domainNameError($name);

        if ($reason !== null) {
            throw Guard::badInput('input.name', $reason);
        }

        $nameAscii = $core->toAscii($name);
        $forwarding = $this->vhostInput->forwarding($input['forwarding'] ?? null, $nameAscii, 'input.forwarding');
        $mountPoint = isset($input['sharedMountPointOf'])
            ? $this->vhostInput->sharedMountPoint($caller, $input['sharedMountPointOf'], $account->getAdminId())
            : VhostRules::aliasMountPoint($nameAscii);
        $wildcard = VhostRules::wildcard((bool)($input['wildcard'] ?? false));

        // 7.
        Guard::requireQuota($quota, 'domainAliases');

        if ($core->domainExists($nameAscii, $account->getResellerId())) {
            throw Guard::conflict(sprintf('Domain %s is unavailable.', $name));
        }

        // Decision D13: alias_add.php:370,402.
        $ordered = $caller->getRole() === Identity::ROLE_CUSTOMER;

        $params = array(
            'domainId'        => $account->getDomainId(),
            'domainAliasName' => $nameAscii,
            'mountPoint'      => $mountPoint,
            'documentRoot'    => VhostRules::HTDOCS,
            'forwardUrl'      => $forwarding['url'],
            'forwardType'     => $forwarding['type'],
            'forwardHost'     => $forwarding['host'],
            'wildcardAlias'   => $wildcard
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/alias_add.php:373-449.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $ordered, $nameAscii, $params) {
            $core->dispatch(Events::onBeforeAddDomainAlias, $params);

            $kit->db()->execute(
                '
                    INSERT INTO domain_aliasses (
                        domain_id, alias_name, alias_mount, alias_document_root, alias_status,
                        alias_ip_id, url_forward, type_forward, host_forward, wildcard_alias
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ',
                array(
                    $account->getDomainId(), $nameAscii, $params['mountPoint'], VhostRules::HTDOCS,
                    $ordered ? 'ordered' : 'toadd', $account->getDomainIpId(), $params['forwardUrl'],
                    $params['forwardType'], $params['forwardHost'], $params['wildcardAlias']
                )
            );

            $id = $kit->db()->lastInsertId();

            $core->savePhpIni($account->getResellerId(), $account->getAdminId(), $account->getDomainId(), $id, 'als');

            // An ordered alias gets its default accounts when it is approved
            // (plan 4), as it does in the panel.
            if (!$ordered && $core->config('CREATE_DEFAULT_EMAIL_ADDRESSES', false)) {
                $core->createDefaultMailAccounts(
                    $account->getDomainId(), $account->getEmail(), $nameAscii,
                    MailType::toMailType(MailType::HOST_ALS, MailType::KIND_FORWARD), $id
                );
            }

            $core->dispatch(Events::onAfterAddDomainAlias, array(
                'domainId'        => $params['domainId'],
                'domainAliasName' => $params['domainAliasName'],
                'domainAliasId'   => $id,
                'mountPoint'      => $params['mountPoint'],
                'documentRoot'    => $params['documentRoot'],
                'forwardUrl'      => $params['forwardUrl'],
                'forwardType'     => $params['forwardType'],
                'forwardHost'     => $params['forwardHost'],
                'wildcardAlias'   => $params['wildcardAlias']
            ));

            return $id;
        });

        // 9, 10. An order is not provisioned, so the daemon is not asked.
        if ($ordered) {
            $core->sendAliasOrderEmail($account->getAdminId(), $name);
            $core->writeLog(
                sprintf('A new domain alias (%s) has been ordered by %s', $name, $caller->getUsername()),
                E_USER_NOTICE
            );
        } else {
            $core->sendRequest();
            $core->writeLog(
                sprintf('A new domain alias (%s) has been created by %s', $name, $caller->getUsername()),
                E_USER_NOTICE
            );
        }

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $id);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_alias_limit') >= 0, 'domainAliases');

        $row = $kit->vhost(NodeType::DOMAIN_ALIAS, $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK));

        $values = $this->vhostInput->update($account, $row, $input);

        $params = array(
            'domainAliasId' => (int)$target->getKey(),
            'mountPoint'    => $row['mountPoint'],
            'documentRoot'  => $values['documentRoot'],
            'forwardUrl'    => $values['url'],
            'forwardType'   => $values['type'],
            'forwardHost'   => $values['host'],
            'wildcardAlias' => $values['wildcard']
        );

        // CORE-DEBT(C3): transcribed from gui/public/client/alias_edit.php:303-341.
        $kit->writer()->run(function () use ($kit, $core, $params, $values) {
            $core->dispatch(Events::onBeforeEditDomainAlias, $params);

            $kit->db()->execute(
                '
                    UPDATE domain_aliasses
                    SET alias_document_root = ?, url_forward = ?, type_forward = ?, host_forward = ?,
                        wildcard_alias = ?, alias_status = ?
                    WHERE alias_id = ?
                ',
                array(
                    $values['documentRoot'], $values['url'], $values['type'], $values['host'],
                    $values['wildcard'], 'tochange', $params['domainAliasId']
                )
            );

            $core->dispatch(Events::onAfterEditDomainAlias, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s updated properties of the %s domain alias', $caller->getUsername(), $core->toUnicode((string)$row['name'])),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $target->getKey());
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_alias_limit') >= 0, 'domainAliases');

        $row = $kit->vhost(NodeType::DOMAIN_ALIAS, $target->getKey());
        Guard::requireState(
            $row['status'],
            array(Provisioning::STATE_OK, Provisioning::STATE_ERROR, Provisioning::STATE_ORDERED)
        );

        if ($row['status'] === 'ordered') {
            return $this->cancelOrder($caller, $row);
        }

        $key = (int)$target->getKey();
        $name = (string)$row['name'];
        $params = array('domainAliasId' => $key, 'domainAliasName' => $name);

        // CORE-DEBT(C3): transcribed from deleteDomainAlias(),
        //   gui/include/Shared.php:1022-1232, which swallows its own failure
        //   (measurement M4) and so cannot be called.
        // CORE-DEBT(C11): C11 item 6 - names escaped for LIKE and the member
        //   regex; FTP users limited to the owning customer; a mount point
        //   shared with another live host of the same domain (checkpoint B,
        //   B2) left alone rather than swept as "everything under this path".
        //   The panel's own member regex, '@(?:.+\.)*<alias>$', and its LIKE
        //   pattern, '%@%.<alias>', both over-match: they also reach a login
        //   on a completely different alias that merely ends in ".<alias>"
        //   (checkpoint B, B3). The API matches the alias's own name and the
        //   names of its own subdomain aliases, exactly.
        $kit->writer()->run(function () use ($kit, $core, $account, $key, $name, $row, $params) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteDomainAlias, $params);

            // The exact host names this alias owns: itself, and every
            // subdomain hung off it (whatever its status - it is scheduled
            // for deletion in this same operation). Also this alias's own
            // subdomains' mount points, so they are never treated as "in
            // use" by a surviving host either.
            $names = array($name);
            $excludedMounts = array(array(VirtualHosts::KIND_ALS, $key));

            foreach ($db->rows('SELECT subdomain_alias_id, subdomain_alias_name FROM subdomain_alias WHERE alias_id = ?', array($key)) as $subRow) {
                $names[] = $subRow['subdomain_alias_name'] . '.' . $name;
                $excludedMounts[] = array(VirtualHosts::KIND_ALSSUB, (int)$subRow['subdomain_alias_id']);
            }

            $groups = new FtpGroups($db);
            $group = $groups->ofCustomer($account->getUsername());

            if ($group !== null) {
                $suffixes = array_map(static function (string $n) { return '@' . $n; }, $names);
                $groups->removeMembers($group, static function (string $member) use ($suffixes) {
                    foreach ($suffixes as $suffix) {
                        if (substr($member, -strlen($suffix)) === $suffix) {
                            return true;
                        }
                    }

                    return false;
                });
            }

            $db->execute('DELETE FROM domain_dns WHERE alias_id = ?', array($key));
            $db->execute("DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($key));
            $db->execute(
                "
                    DELETE t1 FROM php_ini AS t1
                    JOIN subdomain_alias AS t2 ON (t2.subdomain_alias_id = t1.domain_id AND t1.domain_type = 'subals')
                    WHERE t2.alias_id = ?
                ",
                array($key)
            );
            $ftpLikes = array();
            $ftpBind = array($account->getAdminId());

            foreach ($names as $n) {
                $ftpLikes[] = 'userid LIKE ?';
                $ftpBind[] = '%@' . VhostRules::likeEscape($n);
            }

            $db->execute(
                "UPDATE ftp_users SET status = 'todelete' WHERE admin_id = ? AND (" . implode(' OR ', $ftpLikes) . ')',
                $ftpBind
            );
            $db->execute(
                "
                    UPDATE mail_users SET status = 'todelete'
                    WHERE domain_id = ? AND (
                        (sub_id = ? AND mail_type LIKE '%alias\\_%')
                        OR (sub_id IN (SELECT subdomain_alias_id FROM subdomain_alias WHERE alias_id = ?)
                            AND mail_type LIKE '%alssub\\_%')
                    )
                ",
                array($account->getDomainId(), $key, $key)
            );
            $db->execute(
                "
                    UPDATE ssl_certs SET status = 'todelete'
                    WHERE domain_type = 'alssub'
                    AND domain_id IN (SELECT subdomain_alias_id FROM subdomain_alias WHERE alias_id = ?)
                ",
                array($key)
            );
            $db->execute("UPDATE ssl_certs SET status = 'todelete' WHERE domain_id = ? AND domain_type = 'als'", array($key));

            if (!$kit->vhosts()->mountPointInUse($account->getDomainId(), (string)$row['mountPoint'], $excludedMounts)) {
                $mount = rtrim($core->normalisePath((string)$row['mountPoint']), '/');
                $db->execute(
                    "UPDATE htaccess SET status = 'todelete' WHERE dmn_id = ? AND (path = ? OR path LIKE ?)",
                    array($account->getDomainId(), $mount, VhostRules::likeEscape($mount) . '/%')
                );
            }

            $db->execute("UPDATE subdomain_alias SET subdomain_alias_status = 'todelete' WHERE alias_id = ?", array($key));
            $db->execute("UPDATE domain_aliasses SET alias_status = 'todelete' WHERE alias_id = ?", array($key));

            $core->dispatch(Events::onAfterDeleteDomainAlias, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s scheduled deletion of the %s domain alias', $caller->getUsername(), $core->toUnicode($name)),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $key);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/alias_order.php:77-130.
     */
    public function approve(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');

        $row = $kit->db()->row(
            '
                SELECT a.alias_id, a.alias_name, a.alias_status, a.domain_id, ad.email
                FROM domain_aliasses AS a
                JOIN domain AS d USING (domain_id)
                JOIN admin AS ad ON ad.admin_id = d.domain_admin_id
                WHERE a.alias_id = ?
            ',
            array($target->getKey())
        );

        if ($row === null) {
            throw Guard::notFound();
        }

        // B13: role before state, matching spec section 8.1's order - a
        // customer probing alias ids must not be able to tell a settled one
        // (CONFLICT) from an ordered one (FORBIDDEN) by the code it gets back.
        if ($caller->getRole() !== Identity::ROLE_RESELLER && $caller->getRole() !== Identity::ROLE_ADMIN) {
            throw Guard::forbidden('Only a reseller or an administrator may approve an alias order.');
        }

        Guard::requireState((string)$row['alias_status'], array(Provisioning::STATE_ORDERED));

        $aliasId = (int)$row['alias_id'];
        $domainId = (int)$row['domain_id'];
        $name = (string)$row['alias_name'];
        $email = (string)$row['email'];
        $cfg = $kit->panelConfig(array('CREATE_DEFAULT_EMAIL_ADDRESSES'));
        $createDefaults = (bool)$cfg['CREATE_DEFAULT_EMAIL_ADDRESSES'];

        $kit->writer()->run(function () use ($kit, $core, $aliasId, $domainId, $name, $email, $createDefaults) {
            $params = array('domainId' => $domainId, 'domainAliasName' => $name);
            $core->dispatch(Events::onBeforeAddDomainAlias, $params);

            $kit->db()->execute(
                "UPDATE domain_aliasses SET alias_status = 'toadd' WHERE alias_id = ?", array($aliasId)
            );

            if ($createDefaults) {
                $core->createDefaultMailAccounts($domainId, $email, $name, 'alias_forward', $aliasId);
            }

            $core->dispatch(Events::onAfterAddDomainAlias, array('domainAliasId' => $aliasId) + $params);
        });

        $core->sendRequest();
        $core->writeLog(sprintf('An alias order has been processed by %s.', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $aliasId);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/alias_order.php:39-71.
     *   The row is removed outright, not scheduled: nothing has been built for
     *   it yet, so there is nothing for the daemon to take away.
     */
    public function reject(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;

        $target = $kit->guard()->target($caller, $id, array(NodeType::DOMAIN_ALIAS), Scope::DOMAINS_WRITE, 'id');

        // The normalised row, as delete() reads it, rather than three columns
        // of its own: the order's row is removed outright, so this is also the
        // snapshot domainAliasReject returns - a non-null DomainAlias that
        // cannot be read back afterwards. cancelOrder(), the customer's side
        // of the same removal, has always returned one; this is the same rule.
        $row = $kit->vhost(NodeType::DOMAIN_ALIAS, $target->getKey());

        // B13: role before state - see approve()'s own note.
        if ($caller->getRole() !== Identity::ROLE_RESELLER && $caller->getRole() !== Identity::ROLE_ADMIN) {
            throw Guard::forbidden('Only a reseller or an administrator may reject an alias order.');
        }

        Guard::requireState((string)$row['status'], array(Provisioning::STATE_ORDERED));

        $aliasId = (int)$row['key'];

        $kit->writer()->run(function () use ($kit, $aliasId) {
            $kit->db()->execute("DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($aliasId));
            $kit->db()->execute("DELETE FROM domain_aliasses WHERE alias_id = ? AND alias_status = 'ordered'", array($aliasId));
        });

        $kit->core()->writeLog(sprintf('An alias order has been rejected by %s.', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $aliasId, $row);
    }

    /**
     * An order the reseller never approved: nothing was provisioned, so the
     * row goes, with no events and no daemon.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/alias_order_delete.php:40-47.
     * CORE-DEBT(C11): C11 item 8 - the php_ini row alias_add.php created goes too.
     *
     * @param array<string, mixed> $row The normalised row, returned as the snapshot
     */
    private function cancelOrder(Identity $caller, array $row): ObjectRef
    {
        $kit = $this->kit;
        $key = (int)$row['key'];

        $kit->writer()->run(function () use ($kit, $key) {
            $kit->db()->execute("DELETE FROM domain_aliasses WHERE alias_id = ? AND alias_status = 'ordered'", array($key));
            $kit->db()->execute("DELETE FROM php_ini WHERE domain_id = ? AND domain_type = 'als'", array($key));
        });

        // The page logs nothing; spec section 11 wants every API write in the
        // panel's log.
        $kit->core()->writeLog(
            sprintf('%s cancelled the order for the %s domain alias', $caller->getUsername(), $kit->core()->toUnicode((string)$row['name'])),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::DOMAIN_ALIAS, $key, $row);
    }
}
