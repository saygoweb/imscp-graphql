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
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\Target;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;

/**
 * Mail accounts, their autoresponders, and catch-alls.
 *
 * CORE-DEBT(C3): the rules are gui/public/client/mail_add.php, mail_edit.php,
 *   mail_delete.php, mail_autoresponder_*.php and mail_catchall_*.php.
 *   Retire when MailService lands in core (spec section 21, C3 row 3).
 */
final class MailService
{
    const HOSTS = array(NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN);

    const MIB = 1048576;

    /** What i-MSCP writes for "no password" and "no forwards". */
    const NOTHING = '_no_';

    /** The kinds a mail account mutation takes; a catch-all has its own. */
    const ACCOUNT_KINDS = array(MailType::KIND_MAILBOX, MailType::KIND_FORWARD, MailType::KIND_MAILBOX_AND_FORWARD);

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
        $host = $kit->guard()->target($caller, $input['hostId'] ?? null, self::HOSTS, Scope::MAIL_WRITE, 'input.hostId');
        $account = $kit->accounts()->customer($host->getOwnerId());

        // 4.
        $quota = Quota::fromCustomerLimit(
            (int)$account->domain('domain_mailacc_limit'),
            $kit->counts()->mailAccounts(array($account->getDomainId()))[$account->getDomainId()]
        );
        Guard::requireFeature($quota->isEnabled(), 'mail');

        // 5. mail_add.php lists only settled hosts (lines 53-71).
        $hostRow = $kit->vhost($host->getTag(), $host->getKey());
        Guard::requireState($hostRow['status'], array(Provisioning::STATE_OK));

        // 6.
        $kind = (string)($input['kind'] ?? '');

        if (!in_array($kind, self::ACCOUNT_KINDS, true)) {
            throw Guard::badInput(
                'input.kind',
                $kind === MailType::KIND_CATCHALL
                    ? 'A catch-all is created with mailCatchallCreate.'
                    : 'Unknown mail account kind.'
            );
        }

        $localPart = mb_strtolower(trim((string)($input['localPart'] ?? '')));

        if ($localPart === '' || !$core->isValidEmailLocalPart($localPart)) {
            throw Guard::badInput('input.localPart', 'Invalid email username.');
        }

        $hostName = (string)$hostRow['name'];
        $address = $localPart . '@' . $hostName;
        $values = $this->accountValues($account, $kind, $hostName, $address, $input, null, true);

        // 7.
        Guard::requireQuota($quota, 'mailAccounts');

        $hostType = VirtualHosts::kindFor($host->getTag());
        $mailType = MailType::toMailType($hostType, $kind);
        $subId = $hostType === VirtualHosts::KIND_DMN ? 0 : (int)$host->getKey();

        // 8. CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:259-284.
        // CORE-DEBT(C11): C11 item 1 - quota 0, not NULL, for an account with no mailbox.
        $id = $kit->writer()->run(function () use ($kit, $core, $account, $localPart, $address, $mailType, $subId, $values) {
            $core->dispatch(Events::onBeforeAddMail, array('mailUsername' => $localPart, 'MailAddress' => $address));

            $kit->db()->execute(
                '
                    INSERT INTO mail_users (
                        mail_acc, mail_pass, mail_forward, domain_id, mail_type, sub_id, status,
                        po_active, mail_auto_respond, mail_auto_respond_text, quota, mail_addr
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ',
                array(
                    $localPart, $values['password'], $values['forward'], $account->getDomainId(), $mailType,
                    $subId, 'toadd', $values['poActive'], 0, null, $values['quota'], $address
                )
            );

            $id = $kit->db()->lastInsertId();
            $core->dispatch(Events::onAfterAddMail, array('mailUsername' => $localPart, 'mailAddress' => $address, 'mailId' => $id));

            return $id;
        });

        // 9, 10.
        $core->sendRequest();
        $core->writeLog(sprintf('A mail account has been added by %s', $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $id);
    }

    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);
        // mail_edit.php does not ask; spec section 8.3 does.
        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK));

        $current = MailType::kindOf((string)$row['mail_type']);

        if ($current === MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'A catch-all is changed with the catch-all mutations.');
        }

        $named = array_intersect(array_keys($input), array('kind', 'password', 'quota', 'forwardTo'));

        if ($named === array()) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $kind = isset($input['kind']) ? (string)$input['kind'] : $current;

        if (!in_array($kind, self::ACCOUNT_KINDS, true)) {
            throw Guard::badInput('input.kind', 'A mail account cannot become a catch-all.');
        }

        $address = (string)$row['mail_addr'];
        $hostName = substr($address, strpos($address, '@') + 1);
        $hadMailbox = $current !== MailType::KIND_FORWARD;
        $values = $this->accountValues(
            $account, $kind, $hostName, $address, $input, $row,
            isset($input['quota']) || !$hadMailbox
        );
        $mailType = MailType::toMailType(MailType::hostTypeOf((string)$row['mail_type']), $kind);
        $mailId = (int)$target->getKey();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_edit.php:227-251.
        $kit->writer()->run(function () use ($kit, $core, $mailId, $mailType, $values) {
            $core->dispatch(Events::onBeforeEditMail, array('mailId' => $mailId));

            $kit->db()->execute(
                '
                    UPDATE mail_users
                    SET mail_pass = ?, mail_forward = ?, mail_type = ?, status = ?, po_active = ?, quota = ?
                    WHERE mail_id = ?
                ',
                array(
                    $values['password'], $values['forward'], $mailType, 'tochange', $values['poActive'],
                    $values['quota'], $mailId
                )
            );

            $core->dispatch(Events::onAfterEditMail, array('mailId' => $mailId));
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf('A mail account (%s) has been edited by %s', $core->toUnicode($address), $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }

    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);

        if (MailType::kindOf((string)$row['mail_type']) === MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'A catch-all is deleted with mailCatchallDelete.');
        }

        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK, Provisioning::STATE_ERROR));

        // mail_delete.php:56-65 skips these silently; the API says why.
        if ($core->config('PROTECT_DEFAULT_EMAIL_ADDRESSES', false) && self::isDefaultAccount($row)) {
            throw Guard::forbidden("The panel's settings protect this default mail account.");
        }

        $mailId = (int)$target->getKey();
        $address = (string)$row['mail_addr'];
        $domainId = $account->getDomainId();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_delete.php:44-126.
        // CORE-DEBT(C11): C11 item 4 - the address really is removed from the
        //   owning customer's other accounts, and only theirs.
        $kit->writer()->run(function () use ($kit, $core, $mailId, $address, $domainId) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeDeleteMail, array('mailId' => $mailId));

            $db->execute("UPDATE mail_users SET status = 'todelete' WHERE mail_id = ?", array($mailId));

            $pattern = '(,|^)' . preg_quote($address) . '(,|$)';
            $others = $db->rows(
                "
                    SELECT mail_id, mail_acc, mail_forward FROM mail_users
                    WHERE domain_id = ? AND mail_id <> ? AND status = 'ok'
                    AND (mail_acc RLIKE ? OR mail_forward RLIKE ?)
                ",
                array($domainId, $mailId, $pattern, $pattern)
            );

            foreach ($others as $other) {
                $isCatchall = $other['mail_forward'] === self::NOTHING;
                $acc = $isCatchall ? self::without((string)$other['mail_acc'], $address) : (string)$other['mail_acc'];
                $forward = $isCatchall ? (string)$other['mail_forward'] : self::without((string)$other['mail_forward'], $address);

                if (($isCatchall ? $acc : $forward) === '') {
                    $db->execute("UPDATE mail_users SET status = 'todelete' WHERE mail_id = ?", array($other['mail_id']));
                } else {
                    $db->execute(
                        "UPDATE mail_users SET status = 'tochange', mail_acc = ?, mail_forward = ? WHERE mail_id = ?",
                        array($acc, $forward, $other['mail_id'])
                    );
                }
            }

            $core->pruneAutoreplyLog();
            $core->dispatch(Events::onAfterDeleteMail, array('mailId' => $mailId));
        });

        $core->sendRequest();
        $core->writeLog(sprintf('%d mail account(s) were deleted by %s', 1, $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }

    public function setAutoresponder(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::MAIL_ACCOUNT), Scope::MAIL_WRITE, 'id');
        $account = $kit->accounts()->customer($target->getOwnerId());
        Guard::requireFeature((int)$account->domain('domain_mailacc_limit') >= 0, 'mail');

        $row = $this->mailRow($target);

        if (MailType::kindOf((string)$row['mail_type']) === MailType::KIND_CATCHALL) {
            throw Guard::badInput('id', 'A catch-all has no autoresponder.');
        }

        Guard::requireState((string)$row['status'], array(Provisioning::STATE_OK));

        $enabled = (bool)($input['enabled'] ?? false);
        $message = isset($input['message']) ? trim((string)$input['message']) : null;

        if ($message === '') {
            throw Guard::badInput('input.message', 'An autoresponder message cannot be empty.');
        }

        $text = $message ?? (string)($row['mail_auto_respond_text'] ?? '');

        if ($enabled && $text === '') {
            throw Guard::badInput('input.message', 'An autoresponder needs a message.');
        }

        $was = (int)$row['mail_auto_respond'] === 1;
        // mail_autoresponder_edit.php:78: the backend is involved only when
        // the responder is on, or is being turned off.
        $provision = $enabled || $was;
        $mailId = (int)$target->getKey();

        // CORE-DEBT(C3): transcribed from gui/public/client/mail_autoresponder_enable.php:63-79,
        //   mail_autoresponder_disable.php:62-68 and mail_autoresponder_edit.php:69-84.
        $kit->writer()->run(function () use ($kit, $mailId, $enabled, $text, $provision, $row) {
            $kit->db()->execute(
                'UPDATE mail_users SET mail_auto_respond = ?, mail_auto_respond_text = ?, status = ? WHERE mail_id = ?',
                array($enabled ? 1 : 0, $text === '' ? null : $text, $provision ? 'tochange' : $row['status'], $mailId)
            );
        });

        if ($provision) {
            $core->sendRequest();
        }

        if ($enabled && !$was) {
            $verb = 'activated';
        } elseif (!$enabled && $was) {
            $verb = 'deactivated';
        } else {
            $verb = 'edited';
        }

        $core->writeLog(sprintf('A mail autoresponder has been %s by %s', $verb, $caller->getUsername()), E_USER_NOTICE);

        return new ObjectRef(NodeType::MAIL_ACCOUNT, $mailId);
    }

    /**
     * The password, forward list, quota and POP flag an account of $kind is
     * written with.
     *
     * @param array|null $current   The row being updated, or null on create
     * @param bool       $checkPool Whether the quota is measured against the
     *                              account's mail quota. An update that does
     *                              not touch the quota of an existing mailbox
     *                              is not refused because a reseller has since
     *                              lowered the account's total.
     * @return array{password: string, forward: string, quota: int, poActive: string}
     */
    private function accountValues(
        CustomerAccount $account, string $kind, string $hostName, string $address, array $input,
        ?array $current, bool $checkPool
    ): array {
        $core = $this->kit->core();
        $mailbox = $kind !== MailType::KIND_FORWARD;
        $forward = $kind !== MailType::KIND_MAILBOX;
        $values = array('password' => self::NOTHING, 'forward' => self::NOTHING, 'quota' => 0, 'poActive' => $mailbox ? 'yes' : 'no');

        if ($mailbox) {
            // CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:134-142.
            if ((string)$core->config('SERVER_HOSTNAME', '') === $hostName) {
                throw Guard::badInput(
                    'input.kind',
                    "The server's own hostname cannot have mailboxes; only forwards are allowed there."
                );
            }

            if (isset($input['password'])) {
                $password = trim((string)$input['password'], ' ');

                if ($password === '' || !$core->isAcceptablePassword($password)) {
                    throw Guard::badInput('input.password', "The password does not meet the panel's password policy.", array(
                        'minLength'               => max(6, (int)$core->config('PASSWD_CHARS', 6)),
                        'requiresLettersAndDigits' => (bool)$core->config('PASSWD_STRONG', false)
                    ));
                }

                $values['password'] = $core->hashPassword($password);
            } elseif ($current !== null && (string)$current['mail_pass'] !== self::NOTHING) {
                $values['password'] = (string)$current['mail_pass'];
            } else {
                throw Guard::badInput('input.password', 'A mailbox needs a password.');
            }

            $values['quota'] = $this->mailboxQuota($account, $input, $current, $checkPool);
        }

        if ($forward) {
            if (isset($input['forwardTo'])) {
                $values['forward'] = $this->forwardList((array)$input['forwardTo'], $address);
            } elseif ($current !== null && !in_array((string)$current['mail_forward'], array('', self::NOTHING), true)) {
                $values['forward'] = (string)$current['mail_forward'];
            } else {
                throw Guard::badInput('input.forwardTo', 'A forward needs at least one address.');
            }
        }

        return $values;
    }

    /**
     * Bytes, a whole number of MiB (decision D20), within what the other
     * mailboxes leave of the account's mail quota.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:170-193
     *   and mail_edit.php:140-164.
     */
    private function mailboxQuota(CustomerAccount $account, array $input, ?array $current, bool $checkPool): int
    {
        if (isset($input['quota'])) {
            $raw = (string)$input['quota'];

            if (!preg_match('/^[0-9]+$/', $raw)) {
                throw Guard::badInput('input.quota', 'A quota is a whole number of bytes.');
            }

            $bytes = (int)$raw;

            if ($bytes % self::MIB !== 0) {
                throw Guard::badInput('input.quota', 'A quota must be a whole number of MiB.', array('unit' => self::MIB));
            }
        } else {
            $bytes = $current === null ? 0 : (int)$current['quota'];
        }

        $limit = (int)$account->domain('mail_quota');

        if ($limit <= 0 || !$checkPool) {
            return $bytes;
        }

        if ($bytes < self::MIB) {
            throw Guard::badInput('input.quota', 'This account has a mail quota, so every mailbox needs one of at least 1 MiB.');
        }

        $bind = array($account->getDomainId());
        $sql = 'SELECT IFNULL(SUM(quota), 0) FROM mail_users WHERE domain_id = ?';

        if ($current !== null) {
            $sql .= ' AND mail_id <> ?';
            $bind[] = (int)$current['mail_id'];
        }

        $room = max(0, $limit - (int)$this->kit->db()->value($sql, $bind));

        if ($bytes > $room) {
            throw Guard::badInput(
                'input.quota',
                "The quota is larger than what is left of the account's mail quota.",
                array('maximum' => (string)$room)
            );
        }

        return $bytes;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/mail_add.php:212-242.
     *
     * @param array<int, mixed> $list
     */
    private function forwardList(array $list, string $address): string
    {
        $core = $this->kit->core();
        $addresses = array();

        foreach (array_values($list) as $index => $one) {
            $ascii = $core->toAscii(mb_strtolower(trim((string)$one)));

            if ($ascii === '' || !$core->isValidEmail($ascii)) {
                throw Guard::badInput('input.forwardTo', 'Not an email address.', array('index' => $index));
            }

            if ($ascii === $address) {
                throw Guard::badInput('input.forwardTo', sprintf('%s cannot forward to itself.', $address), array('index' => $index));
            }

            $addresses[$ascii] = true;
        }

        if ($addresses === array()) {
            throw Guard::badInput('input.forwardTo', 'A forward needs at least one address.');
        }

        return implode(',', array_keys($addresses));
    }

    /**
     * @return array<string, mixed>
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND
     */
    private function mailRow(Target $target): array
    {
        $row = $this->kit->db()->row('SELECT * FROM mail_users WHERE mail_id = ?', array((int)$target->getKey()));

        if ($row === null) {
            throw Guard::notFound();
        }

        return $row;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/mail_delete.php:56-62.
     */
    private static function isDefaultAccount(array $row): bool
    {
        $type = (string)$row['mail_type'];
        $acc = (string)$row['mail_acc'];

        return (in_array($type, array('normal_forward', 'alias_forward'), true)
                && in_array($acc, array('abuse', 'hostmaster', 'postmaster', 'webmaster'), true))
            || ($acc === 'webmaster' && in_array($type, array('subdom_forward', 'alssub_forward'), true));
    }

    private static function without(string $list, string $address): string
    {
        return implode(',', array_values(array_filter(explode(',', $list), static function (string $one) use ($address) {
            return $one !== '' && $one !== $address;
        })));
    }
}
