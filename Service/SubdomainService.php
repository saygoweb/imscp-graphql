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
 * Subdomains of a main domain (`subdomain`) and of an alias
 * (`subdomain_alias`), which the schema calls one type (spec section 7.3).
 *
 * CORE-DEBT(C3): the rules are gui/public/client/subdomain_add.php,
 *   subdomain_edit.php, and the delete helpers in gui/include/Client.php.
 *   Retire when SubdomainService lands in core (spec section 21, C3 row 4).
 */
final class SubdomainService
{
    const TAGS = array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN);

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
        $parent = $kit->guard()->target(
            $caller, $input['parentId'] ?? null, array(NodeType::DOMAIN, NodeType::DOMAIN_ALIAS),
            Scope::DOMAINS_WRITE, 'input.parentId'
        );
        $account = $kit->accounts()->customer($parent->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_subd_limit'),
            $kit->counts()->subdomains(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'subdomains');

        // 5. The pages list only settled parents (subdomain_add.php:72-85).
        $parentRow = $kit->vhost($parent->getTag(), $parent->getKey());
        Guard::requireState($parentRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $label = mb_strtolower(trim((string)($input['label'] ?? '')));

        if ($label === '') {
            throw Guard::badInput('input.label', 'A subdomain label is required.');
        }

        if (VhostRules::isReservedLabel($label)) {
            throw Guard::badInput('input.label', 'www is not allowed as a subdomain label.');
        }

        $labelAscii = $core->toAscii($label);
        $nameAscii = $labelAscii . '.' . $parentRow['name'];
        $reason = $core->domainNameError($nameAscii);

        if ($reason !== null) {
            throw Guard::badInput('input.label', $reason);
        }

        $kind = $parent->getTag() === NodeType::DOMAIN ? VirtualHosts::KIND_DMN : VirtualHosts::KIND_ALS;
        $forwarding = $this->vhostInput->forwarding($input['forwarding'] ?? null, $nameAscii, 'input.forwarding');
        $mountPoint = isset($input['sharedMountPointOf'])
            ? $this->vhostInput->sharedMountPoint($caller, $input['sharedMountPointOf'], $account->getAdminId())
            : VhostRules::subdomainMountPoint($kind, $labelAscii, (string)$parentRow['name']);
        $wildcard = VhostRules::wildcard((bool)($input['wildcard'] ?? false));

        // 7.
        Guard::requireQuota($quota, 'subdomains');

        if ($kit->vhosts()->nameInUse($nameAscii)) {
            throw Guard::conflict(sprintf('%s is already in use.', $core->toUnicode($nameAscii)));
        }

        // The name listeners are given is the page's: the label as typed,
        // lower-cased, on the parent's stored name (subdomain_add.php:234).
        $subdomainName = $label . '.' . $parentRow['name'];
        $params = array(
            'subdomainName'  => $subdomainName,
            'subdomainType'  => $kind,
            'parentDomainId' => (int)$parent->getKey(),
            'mountPoint'     => $mountPoint,
            'documentRoot'   => VhostRules::HTDOCS,
            'forwardUrl'     => $forwarding['url'],
            'forwardType'    => $forwarding['type'],
            'forwardHost'    => $forwarding['host'],
            'wildcardAlias'  => $wildcard,
            'customerId'     => $account->getAdminId()
        );

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:386-483.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $kind, $parent, $labelAscii, $nameAscii, $params) {
            $core->dispatch(Events::onBeforeAddSubdomain, $params);

            $values = array(
                (int)$parent->getKey(), $labelAscii, $params['mountPoint'], VhostRules::HTDOCS,
                $params['forwardUrl'], $params['forwardType'], $params['forwardHost'],
                $params['wildcardAlias'], 'toadd'
            );

            if ($kind === VirtualHosts::KIND_ALS) {
                $kit->db()->execute(
                    '
                        INSERT INTO subdomain_alias (
                            alias_id, subdomain_alias_name, subdomain_alias_mount,
                            subdomain_alias_document_root, subdomain_alias_url_forward,
                            subdomain_alias_type_forward, subdomain_alias_host_forward,
                            subdomain_alias_wildcard_alias, subdomain_alias_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    $values
                );
            } else {
                $kit->db()->execute(
                    '
                        INSERT INTO subdomain (
                            domain_id, subdomain_name, subdomain_mount, subdomain_document_root,
                            subdomain_url_forward, subdomain_type_forward, subdomain_host_forward,
                            subdomain_wildcard_alias, subdomain_status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    $values
                );
            }

            $id = $kit->db()->lastInsertId();

            $core->savePhpIni(
                $account->getResellerId(), $account->getAdminId(), $account->getDomainId(), $id,
                $kind === VirtualHosts::KIND_DMN ? 'sub' : 'subals'
            );

            if ($core->config('CREATE_DEFAULT_EMAIL_ADDRESSES', false)) {
                $core->createDefaultMailAccounts(
                    $account->getDomainId(), $account->getEmail(), $nameAscii,
                    MailType::toMailType(
                        $kind === VirtualHosts::KIND_DMN ? MailType::HOST_SUB : MailType::HOST_ALSSUB,
                        MailType::KIND_FORWARD
                    ),
                    $id
                );
            }

            $core->dispatch(Events::onAfterAddSubdomain, $params + array('subdomainId' => $id));

            return $id;
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(
            sprintf('A new subdomain (%s) has been created by %s', $subdomainName, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(
            $kind === VirtualHosts::KIND_DMN ? NodeType::SUBDOMAIN : NodeType::ALIAS_SUBDOMAIN,
            $id
        );
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, self::TAGS, Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());

        // subdomain_edit.php:426 asks 'subdomains' for both kinds.
        Guard::requireFeature((int)$account->domain('domain_subd_limit') >= 0, 'subdomains');

        $row = $kit->vhost($target->getTag(), $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK));

        $values = $this->vhostInput->update($account, $row, $input);
        $isAlias = $target->getTag() === NodeType::ALIAS_SUBDOMAIN;

        $params = array(
            'subdomainId'   => (int)$target->getKey(),
            'subdomainName' => $row['name'],
            // The page's GET 'type': 'dmn' for a subdomain, 'als' for an alias's.
            'subdomainType' => $isAlias ? VirtualHosts::KIND_ALS : VirtualHosts::KIND_DMN,
            'mountPoint'    => $row['mountPoint'],
            'documentRoot'  => $values['documentRoot'],
            'forwardUrl'    => $values['url'],
            'forwardType'   => $values['type'],
            'forwardHost'   => $values['host'],
            'wildcardAlias' => $values['wildcard']
        );

        // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_edit.php:357-409.
        // CORE-DEBT(C11): C11 item 2 - the alias table's own wildcard column,
        //   and the flag as yes|no.
        $kit->writer()->run(function () use ($kit, $core, $isAlias, $params, $values) {
            $core->dispatch(Events::onBeforeEditSubdomain, $params);

            $kit->db()->execute(
                $isAlias
                    ? '
                        UPDATE subdomain_alias
                        SET subdomain_alias_document_root = ?, subdomain_alias_url_forward = ?,
                            subdomain_alias_type_forward = ?, subdomain_alias_host_forward = ?,
                            subdomain_alias_wildcard_alias = ?, subdomain_alias_status = ?
                        WHERE subdomain_alias_id = ?
                    '
                    : '
                        UPDATE subdomain
                        SET subdomain_document_root = ?, subdomain_url_forward = ?,
                            subdomain_type_forward = ?, subdomain_host_forward = ?,
                            subdomain_wildcard_alias = ?, subdomain_status = ?
                        WHERE subdomain_id = ?
                    ',
                array(
                    $values['documentRoot'], $values['url'], $values['type'], $values['host'],
                    $values['wildcard'], 'tochange', $params['subdomainId']
                )
            );

            $core->dispatch(Events::onAfterEditSubdomain, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('%s updated properties of the %s subdomain', $caller->getUsername(), $core->toUnicode((string)$row['name'])),
            E_USER_NOTICE
        );

        return new ObjectRef($target->getTag(), $target->getKey());
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, self::TAGS, Scope::DOMAINS_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        $isAlias = $target->getTag() === NodeType::ALIAS_SUBDOMAIN;

        // subdomain_delete.php:36 asks 'subdomains'; alssub_delete.php:36 asks
        // 'domain_aliases'.
        if ($isAlias) {
            Guard::requireFeature((int)$account->domain('domain_alias_limit') >= 0, 'domainAliases');
        } else {
            Guard::requireFeature((int)$account->domain('domain_subd_limit') >= 0, 'subdomains');
        }

        $row = $kit->vhost($target->getTag(), $target->getKey());
        Guard::requireState($row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        $key = (int)$target->getKey();
        $name = (string)$row['name'];
        $type = $isAlias ? 'alssub' : 'sub';
        $params = array('subdomainId' => $key, 'subdomainName' => $name, 'subdomainType' => $type, 'type' => $type);

        // CORE-DEBT(C1): transcribed from gui/include/Client.php:432-613 and
        //   618-806, which authorise on $_SESSION['user_id'] as the customer
        //   and exit on a miss (measurement M3). Retire when C1 lands.
        // CORE-DEBT(C11): C11 item 6 - names escaped for LIKE and for the
        //   member regex, protected areas matched by path segment, and a
        //   mount point shared with another live host of the same domain
        //   (checkpoint B, B1) left alone rather than swept as "everything
        //   under this path".
        $kit->writer()->run(function () use ($kit, $core, $account, $isAlias, $key, $name, $type, $row, $params) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteSubdomain, $params);

            $groups = new FtpGroups($db);
            $group = $groups->withMemberOn($name);

            if ($group !== null) {
                $groups->removeMembers($group, static function (string $member) use ($name) {
                    return (bool)preg_match('/@' . preg_quote($name, '/') . '$/', $member);
                });
            }

            $db->execute(
                'DELETE FROM php_ini WHERE domain_id = ? AND domain_type = ?',
                array($key, $isAlias ? 'subals' : 'sub')
            );
            $db->execute(
                "UPDATE ftp_users SET status = 'todelete' WHERE userid LIKE ?",
                array('%@' . VhostRules::likeEscape($name))
            );
            $db->execute(
                "UPDATE mail_users SET status = 'todelete' WHERE sub_id = ? AND mail_type LIKE ?",
                array($key, $isAlias ? '%alssub\\_%' : '%subdom\\_%')
            );
            $db->execute(
                "UPDATE ssl_certs SET status = 'todelete' WHERE domain_id = ? AND domain_type = ?",
                array($key, $type)
            );

            $kind = $isAlias ? VirtualHosts::KIND_ALSSUB : VirtualHosts::KIND_SUB;

            if (!$kit->vhosts()->mountPointInUse($account->getDomainId(), (string)$row['mountPoint'], array(array($kind, $key)))) {
                $mount = rtrim($core->normalisePath((string)$row['mountPoint']), '/');
                $db->execute(
                    "UPDATE htaccess SET status = 'todelete' WHERE dmn_id = ? AND (path = ? OR path LIKE ?)",
                    array($account->getDomainId(), $mount, VhostRules::likeEscape($mount) . '/%')
                );
            }

            $db->execute(
                $isAlias
                    ? "UPDATE subdomain_alias SET subdomain_alias_status = 'todelete' WHERE subdomain_alias_id = ?"
                    : "UPDATE subdomain SET subdomain_status = 'todelete' WHERE subdomain_id = ?",
                array($key)
            );

            $core->dispatch(Events::onAfterDeleteSubdomain, $params);
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('Deletion of the %s subdomain has been scheduled by %s', $core->toUnicode($name), $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef($target->getTag(), $target->getKey());
    }
}
