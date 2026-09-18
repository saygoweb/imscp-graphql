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

use iMSCP\Plugin\SGW_GraphQL\Service\MailService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class MailServiceTest extends ServiceTestCase
{
    const MIB = 1048576;

    private function service(): MailService
    {
        return new MailService($this->kit);
    }

    private function domainId(): string
    {
        return GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId());
    }

    private function mailboxId(): string
    {
        return GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->mailboxId());
    }

    private function mail(int $id): array
    {
        return $this->db->row('SELECT * FROM mail_users WHERE mail_id = ?', array($id));
    }

    /**
     * The fixture's mailbox has mail_pass '_no_'. Give it a real one, so an
     * update that does not mention the password has one to keep.
     */
    private function giveTheMailboxAPassword(): void
    {
        $this->db->execute("UPDATE mail_users SET mail_pass = '\$6\$kept' WHERE mail_id = ?", array($this->fixture->mailboxId()));
    }

    // ---- create ---------------------------------------------------------

    public function testAMailboxIsCreated(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'Info', 'kind' => 'MAILBOX',
            'password' => 'Mailb0xPass', 'quota' => (string)(10 * self::MIB)
        ));

        self::assertSame(NodeType::MAIL_ACCOUNT, $ref->getTag());
        $row = $this->mail($ref->getKey());
        self::assertSame('info', $row['mail_acc']);
        self::assertSame('info@' . $this->fixture->domainName(), $row['mail_addr']);
        self::assertSame('normal_mail', $row['mail_type']);
        self::assertSame('0', (string)$row['sub_id']);
        self::assertStringStartsWith('$6$', $row['mail_pass']);
        self::assertSame('_no_', $row['mail_forward']);
        self::assertSame((string)(10 * self::MIB), (string)$row['quota']);
        self::assertSame('yes', $row['po_active']);
        self::assertSame('toadd', $row['status']);
        self::assertSame(array('onBeforeAddMail', 'onAfterAddMail'), $this->core->eventNames());
        self::assertSame(
            array('mailUsername' => 'info', 'MailAddress' => 'info@' . $this->fixture->domainName()),
            $this->core->events[0][1],
            'the page spells the before-event key MailAddress'
        );
        self::assertSame($ref->getKey(), $this->core->events[1][1]['mailId']);
        self::assertSame(1, $this->core->requests);
        self::assertSame('A mail account has been added by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testAForwardOnlyAccountIsCreatedWithQuotaZero(): void
    {
        // Measurement M7, C11 item 1: the panel cannot create one at all.
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'team', 'kind' => 'FORWARD',
            'forwardTo' => array('A@Example.NET', 'b@example.net', 'a@example.net')
        ));

        $row = $this->mail($ref->getKey());
        self::assertSame('normal_forward', $row['mail_type']);
        self::assertSame('a@example.net,b@example.net', $row['mail_forward'], 'lower-cased and de-duplicated');
        self::assertSame('_no_', $row['mail_pass']);
        self::assertSame('0', (string)$row['quota']);
        self::assertSame('no', $row['po_active']);
    }

    public function testAMailboxThatForwardsTooOnASubdomain(): void
    {
        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId'    => GlobalId::encode(NodeType::SUBDOMAIN, $this->fixture->subdomainId()),
            'localPart' => 'both', 'kind' => 'MAILBOX_AND_FORWARD',
            'password'  => 'Mailb0xPass', 'quota' => (string)self::MIB, 'forwardTo' => array('x@example.net')
        ));

        $row = $this->mail($ref->getKey());
        self::assertSame('subdom_mail,subdom_forward', $row['mail_type']);
        self::assertSame((string)$this->fixture->subdomainId(), (string)$row['sub_id']);
        self::assertSame('both@' . $this->fixture->subdomainName(), $row['mail_addr']);
    }

    public function testACatchallKindIsRefusedHere(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'CATCHALL'
            ));
        });

        self::assertSame('input.kind', $e->getExtensions()['field']);
    }

    public function testAMailboxNeedsAnAcceptablePassword(): void
    {
        $missing = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX', 'quota' => (string)self::MIB
            ));
        });
        self::assertSame('input.password', $missing->getExtensions()['field']);

        $weak = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'short', 'quota' => (string)self::MIB
            ));
        });
        self::assertSame('input.password', $weak->getExtensions()['field']);
        self::assertSame(6, $weak->getExtensions()['minLength']);
    }

    public function testAQuotaMustBeWholeMebibytes(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'Mailb0xPass', 'quota' => '1000'
            ));
        });

        self::assertSame('input.quota', $e->getExtensions()['field']);
    }

    public function testAQuotaMustFitWhatIsLeftOfTheAccountsMailQuota(): void
    {
        // mail_quota is 1 GiB and the fixture's mailbox holds 100 MiB of it.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'Mailb0xPass', 'quota' => (string)(1000 * self::MIB)
            ));
        });

        self::assertSame((string)(924 * self::MIB), $e->getExtensions()['maximum']);
    }

    public function testWithAnAccountMailQuotaEveryMailboxNeedsOne(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX', 'password' => 'Mailb0xPass'
            ));
        });
    }

    public function testWithoutAnAccountMailQuotaAMailboxMayBeUnlimited(): void
    {
        $this->db->execute('UPDATE domain SET mail_quota = 0 WHERE domain_id = ?', array($this->fixture->domainId()));

        $ref = $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX', 'password' => 'Mailb0xPass'
        ));

        self::assertSame('0', (string)$this->mail($ref->getKey())['quota']);
    }

    public function testNoMailboxOnTheServersOwnHostname(): void
    {
        $this->reconfigure(array('SERVER_HOSTNAME' => $this->fixture->domainName()));

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'MAILBOX',
                'password' => 'Mailb0xPass', 'quota' => (string)self::MIB
            ));
        });
        self::assertSame('input.kind', $e->getExtensions()['field']);

        // A forward is fine there.
        $this->service()->create($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
        ));
    }

    public function testAForwardCannotForwardToItself(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'loop', 'kind' => 'FORWARD',
                'forwardTo' => array('ok@example.net', 'loop@' . $this->fixture->domainName())
            ));
        });

        self::assertSame(array('field' => 'input.forwardTo', 'index' => 1), $e->getExtensions());
    }

    public function testATakenAddressIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'sales', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
            ));
        });
    }

    public function testTheLimitIsLimitExceeded(): void
    {
        // The fixture has two accounts.
        $this->db->execute('UPDATE domain SET domain_mailacc_limit = 2 WHERE domain_id = ?', array($this->fixture->domainId()));

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'localPart' => 'x', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
            ));
        });
    }

    public function testAnUnsettledHostIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->create($this->caller('customer'), array(
                'hostId'    => GlobalId::encode(NodeType::ALIAS_SUBDOMAIN, $this->fixture->aliasSubdomainId()),
                'localPart' => 'x', 'kind' => 'FORWARD', 'forwardTo' => array('y@example.net')
            ));
        });
    }

    // ---- update ---------------------------------------------------------

    public function testTurningAMailboxIntoAForward(): void
    {
        $this->service()->update($this->caller('customer'), $this->mailboxId(), array(
            'kind' => 'FORWARD', 'forwardTo' => array('elsewhere@example.net')
        ));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('normal_forward', $row['mail_type']);
        self::assertSame('_no_', $row['mail_pass']);
        self::assertSame('0', (string)$row['quota']);
        self::assertSame('no', $row['po_active']);
        self::assertSame('elsewhere@example.net', $row['mail_forward']);
        self::assertSame('tochange', $row['status']);
        self::assertSame(array('onBeforeEditMail', 'onAfterEditMail'), $this->core->eventNames());
        self::assertSame(array('mailId' => $this->fixture->mailboxId()), $this->core->events[0][1]);
        self::assertSame('A mail account (sales@' . $this->fixture->domainName() . ') has been edited by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testAPasswordLeftOutIsKept(): void
    {
        $this->giveTheMailboxAPassword();

        $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => (string)(50 * self::MIB)));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('$6$kept', $row['mail_pass']);
        self::assertSame((string)(50 * self::MIB), (string)$row['quota']);
    }

    public function testAMailboxWithNoPasswordYetMustBeGivenOne(): void
    {
        // mail_edit.php:115: a '_no_' password must be replaced.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => (string)(50 * self::MIB)));
        });

        self::assertSame('input.password', $e->getExtensions()['field']);
    }

    public function testAQuotaCheckOnUpdateLeavesTheAccountItselfOut(): void
    {
        // 1 GiB total; this mailbox's own 100 MiB is not "someone else's".
        $this->giveTheMailboxAPassword();

        $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => (string)(1024 * self::MIB)));

        self::assertSame((string)(1024 * self::MIB), (string)$this->mail($this->fixture->mailboxId())['quota']);
    }

    public function testAForwardAddingAMailboxNeedsAPasswordAndQuota(): void
    {
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());

        $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($forward) {
            $this->service()->update($this->caller('customer'), $forward, array('kind' => 'MAILBOX_AND_FORWARD'));
        });

        $this->service()->update($this->caller('customer'), $forward, array(
            'kind' => 'MAILBOX_AND_FORWARD', 'password' => 'Mailb0xPass', 'quota' => (string)self::MIB
        ));

        $row = $this->mail($this->fixture->forwardId());
        self::assertSame('alssub_mail,alssub_forward', $row['mail_type']);
        self::assertSame('a@example.net,b@example.net', $row['mail_forward'], 'the forwards were kept');
    }

    public function testAnEmptyUpdateIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array());
        });

        self::assertSame('input', $e->getExtensions()['field']);
    }

    public function testAnExplicitNullNamesNothing(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array('quota' => null));
        });

        self::assertSame('input', $e->getExtensions()['field']);
        self::assertSame('The update names nothing to change.', $e->getMessage());
        self::assertSame('ok', $this->mail($this->fixture->mailboxId())['status']);
    }

    public function testAPasswordOnAForwardIsRefused(): void
    {
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($forward) {
            $this->service()->update($this->caller('customer'), $forward, array('password' => 'N3wSecret'));
        });

        self::assertSame('input.password', $e->getExtensions()['field']);
        self::assertSame('ok', $this->mail($this->fixture->forwardId())['status']);
    }

    public function testAForwardToOnAMailboxOnlyAccountIsRefused(): void
    {
        $this->giveTheMailboxAPassword();

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array(
                'forwardTo' => array('elsewhere@example.net')
            ));
        });

        self::assertSame('input.forwardTo', $e->getExtensions()['field']);
    }

    public function testChangingKindToForwardWhileSendingQuotaIsRefused(): void
    {
        $this->giveTheMailboxAPassword();

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array(
                'kind' => 'FORWARD', 'forwardTo' => array('elsewhere@example.net'), 'quota' => (string)self::MIB
            ));
        });

        self::assertSame('input.quota', $e->getExtensions()['field']);
    }

    public function testAnUnsettledAccountCannotBeUpdated(): void
    {
        $this->db->execute("UPDATE mail_users SET status = 'toadd' WHERE mail_id = ?", array($this->fixture->mailboxId()));

        $this->refused(ErrorCode::CONFLICT, function () {
            $this->service()->update($this->caller('customer'), $this->mailboxId(), array('kind' => 'FORWARD', 'forwardTo' => array('x@example.net')));
        });
    }

    // ---- delete ---------------------------------------------------------

    public function testDeletingRemovesTheAddressFromTheCustomersOtherAccounts(): void
    {
        // C11 item 4: the page never did this, and would have done it for
        // every customer on the box.
        $address = 'sales@' . $this->fixture->domainName();
        $shared = $this->insert('mail_users', array(
            'mail_acc' => 'team', 'mail_pass' => '_no_', 'mail_forward' => $address . ',x@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'team@' . $this->fixture->domainName()
        ));
        $only = $this->insert('mail_users', array(
            'mail_acc' => 'alias', 'mail_pass' => '_no_', 'mail_forward' => $address,
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'alias@' . $this->fixture->domainName()
        ));
        $catchall = $this->insert('mail_users', array(
            'mail_acc' => $address, 'mail_pass' => '_no_', 'mail_forward' => '_no_',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_catchall', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => '@' . $this->fixture->domainName()
        ));
        $strangers = $this->insert('mail_users', array(
            'mail_acc' => 'fan', 'mail_pass' => '_no_', 'mail_forward' => $address,
            'domain_id' => $this->fixture->otherDomainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'fan@sgwtstranger.test'
        ));

        $this->service()->delete($this->caller('customer'), $this->mailboxId());

        self::assertSame('todelete', $this->mail($this->fixture->mailboxId())['status']);
        self::assertSame('tochange', $this->mail($shared)['status']);
        self::assertSame('x@example.net', $this->mail($shared)['mail_forward']);
        self::assertSame('todelete', $this->mail($only)['status'], 'nothing left to forward to');
        self::assertSame('todelete', $this->mail($catchall)['status'], 'nothing left to catch for');
        self::assertSame('ok', $this->mail($strangers)['status'], 'another customer is untouched');
        self::assertSame($address, $this->mail($strangers)['mail_forward']);
        self::assertSame(1, $this->core->prunes);
        self::assertSame(array('onBeforeDeleteMail', 'onAfterDeleteMail'), $this->core->eventNames());
        self::assertSame('1 mail account(s) were deleted by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testADefaultAccountIsProtected(): void
    {
        $webmaster = $this->insert('mail_users', array(
            'mail_acc' => 'webmaster', 'mail_pass' => '_no_', 'mail_forward' => 'owner@example.net',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_forward', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => 'webmaster@' . $this->fixture->domainName()
        ));
        $id = GlobalId::encode(NodeType::MAIL_ACCOUNT, $webmaster);

        $this->refused(ErrorCode::FORBIDDEN, function () use ($id) {
            $this->service()->delete($this->caller('customer'), $id);
        });

        $this->reconfigure(array('PROTECT_DEFAULT_EMAIL_ADDRESSES' => 0));
        $this->service()->delete($this->caller('customer'), $id);
        self::assertSame('todelete', $this->mail($webmaster)['status']);
    }

    public function testACatchallIsNotDeletedHere(): void
    {
        $catchall = $this->insert('mail_users', array(
            'mail_acc' => 'x@example.net', 'mail_pass' => '_no_', 'mail_forward' => '_no_',
            'domain_id' => $this->fixture->domainId(), 'mail_type' => 'normal_catchall', 'sub_id' => 0,
            'status' => 'ok', 'po_active' => 'no', 'quota' => 0, 'mail_addr' => '@' . $this->fixture->domainName()
        ));

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($catchall) {
            $this->service()->delete($this->caller('customer'), GlobalId::encode(NodeType::MAIL_ACCOUNT, $catchall));
        });

        self::assertSame('id', $e->getExtensions()['field']);
    }

    // ---- autoresponder --------------------------------------------------

    public function testEnablingAnAutoresponder(): void
    {
        $this->service()->setAutoresponder($this->caller('customer'), $this->mailboxId(), array(
            'enabled' => true, 'message' => '  Away until Monday.  '
        ));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('1', (string)$row['mail_auto_respond']);
        self::assertSame('Away until Monday.', $row['mail_auto_respond_text']);
        self::assertSame('tochange', $row['status']);
        self::assertSame(1, $this->core->requests);
        self::assertSame('A mail autoresponder has been activated by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testEnablingWithoutAMessageUsesTheOneAlreadyStored(): void
    {
        // mail_autoresponder_enable.php:124: an existing text is reused.
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());
        $this->db->execute('UPDATE mail_users SET mail_auto_respond = 0 WHERE mail_id = ?', array($this->fixture->forwardId()));

        $this->service()->setAutoresponder($this->caller('customer'), $forward, array('enabled' => true));

        self::assertSame('On holiday.', $this->mail($this->fixture->forwardId())['mail_auto_respond_text']);
    }

    public function testEnablingWithNoMessageAnywhereIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->setAutoresponder($this->caller('customer'), $this->mailboxId(), array('enabled' => true));
        });

        self::assertSame('input.message', $e->getExtensions()['field']);
    }

    public function testDisablingAnAutoresponder(): void
    {
        $forward = GlobalId::encode(NodeType::MAIL_ACCOUNT, $this->fixture->forwardId());

        $this->service()->setAutoresponder($this->caller('customer'), $forward, array('enabled' => false));

        $row = $this->mail($this->fixture->forwardId());
        self::assertSame('0', (string)$row['mail_auto_respond']);
        self::assertSame('On holiday.', $row['mail_auto_respond_text'], 'the text is kept for next time');
        self::assertSame('tochange', $row['status']);
        self::assertSame('A mail autoresponder has been deactivated by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testEditingTheTextOfADisabledAutoresponderProvisionsNothing(): void
    {
        // mail_autoresponder_edit.php:78: status changes only when it is on.
        $this->service()->setAutoresponder($this->caller('customer'), $this->mailboxId(), array(
            'enabled' => false, 'message' => 'For later.'
        ));

        $row = $this->mail($this->fixture->mailboxId());
        self::assertSame('ok', $row['status']);
        self::assertSame('For later.', $row['mail_auto_respond_text']);
        self::assertSame(0, $this->core->requests);
        self::assertSame('A mail autoresponder has been edited by sgwtcustomer', $this->core->logs[0][0]);
    }

    // ---- catch-alls -----------------------------------------------------

    public function testACatchallIsCreatedForAHost(): void
    {
        $ref = $this->service()->createCatchall($this->caller('customer'), array(
            'hostId'    => GlobalId::encode(NodeType::DOMAIN_ALIAS, $this->fixture->aliasId()),
            'addresses' => array('Sales@' . $this->fixture->domainName(), 'x@example.net')
        ));

        $row = $this->mail($ref->getKey());
        self::assertSame('sales@' . $this->fixture->domainName() . ',x@example.net', $row['mail_acc']);
        self::assertSame('_no_', $row['mail_forward']);
        self::assertSame('alias_catchall', $row['mail_type']);
        self::assertSame((string)$this->fixture->aliasId(), (string)$row['sub_id']);
        self::assertSame('@' . $this->fixture->aliasName(), $row['mail_addr']);
        self::assertSame('toadd', $row['status']);
        self::assertSame(array('onBeforeAddMailCatchall', 'onAfterAddMailCatchall'), $this->core->eventNames());
        self::assertSame(array(
            'mailCatchallDomain'    => $this->fixture->aliasName(),
            'mailCatchallAddresses' => array('sales@' . $this->fixture->domainName(), 'x@example.net')
        ), $this->core->events[0][1]);
        self::assertSame($ref->getKey(), $this->core->events[1][1]['mailCatchallId']);
        self::assertSame('A catch-all account has been created by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testASecondCatchallOnTheSameHostIsAConflict(): void
    {
        $input = array('hostId' => $this->domainId(), 'addresses' => array('x@example.net'));
        $this->service()->createCatchall($this->caller('customer'), $input);

        $this->refused(ErrorCode::CONFLICT, function () use ($input) {
            $this->service()->createCatchall($this->caller('customer'), $input);
        });
    }

    public function testACatchallNeedsValidAddresses(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createCatchall($this->caller('customer'), array(
                'hostId' => $this->domainId(), 'addresses' => array('x@example.net', 'not an address')
            ));
        });
        self::assertSame(array('field' => 'input.addresses', 'index' => 1), $e->getExtensions());

        $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->createCatchall($this->caller('customer'), array('hostId' => $this->domainId(), 'addresses' => array()));
        });
    }

    public function testACatchallIsDeleted(): void
    {
        $ref = $this->service()->createCatchall($this->caller('customer'), array(
            'hostId' => $this->domainId(), 'addresses' => array('x@example.net')
        ));
        $this->db->execute("UPDATE mail_users SET status = 'ok' WHERE mail_id = ?", array($ref->getKey()));
        $this->reconfigure(array());

        $this->service()->deleteCatchall($this->caller('customer'), GlobalId::encode(NodeType::MAIL_ACCOUNT, $ref->getKey()));

        self::assertSame('todelete', $this->mail($ref->getKey())['status']);
        self::assertSame(array('onBeforeDeleteMailCatchall', 'onAfterDeleteMailCatchall'), $this->core->eventNames());
        self::assertSame(array('mailCatchallId' => $ref->getKey()), $this->core->events[0][1]);
        self::assertSame('A catch-all account has been deleted by sgwtcustomer', $this->core->logs[0][0]);
    }

    public function testAnOrdinaryAccountIsNotDeletedAsACatchall(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () {
            $this->service()->deleteCatchall($this->caller('customer'), $this->mailboxId());
        });

        self::assertSame('id', $e->getExtensions()['field']);
    }
}
