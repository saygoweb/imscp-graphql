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

use iMSCP\Plugin\SGW_GraphQL\Service\CustomerService;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class CustomerServiceTest extends ServiceTestCase
{
    private function service(): CustomerService
    {
        return new CustomerService($this->kit);
    }

    /** A complete, valid input for the fixture's reseller. */
    private function input(array $overrides = array()): array
    {
        return $overrides + array(
            'username'   => 'sgwnew.test',
            'password'   => 'N3wCustomer!',
            'domainName' => 'sgwnew.test',
            'ipAddressId' => GlobalId::encode(NodeType::IP_ADDRESS, $this->fixture->ipId()),
            'contact'    => array(
                'email' => 'owner@sgwnew.test', 'firstName' => 'Ada', 'lastName' => 'Lovelace'
            ),
            'allowances' => array(
                'subdomains' => 2, 'domainAliases' => 1, 'mailAccounts' => 5, 'ftpUsers' => 2,
                'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024, 'disk' => 512,
                'mailQuota' => 0, 'php' => true, 'cgi' => false, 'customDns' => false,
                'externalMail' => false, 'backup' => array(), 'phpEditor' => false
            ),
            'sendWelcomeEmail' => false
        );
    }

    public function testACustomerIsScheduledWithItsDomain(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwnew.test', $admin['admin_name']);
        self::assertSame('user', $admin['admin_type']);
        self::assertSame('toadd', $admin['admin_status']);
        self::assertSame($this->fixture->resellerId(), (int)$admin['created_by']);

        $domain = $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwnew.test', $domain['domain_name']);
        self::assertSame('toadd', $domain['domain_status']);
        self::assertSame(2, (int)$domain['domain_subd_limit']);
        self::assertSame('yes', $domain['domain_php'], 'M7: no underscores in the domain row');
        self::assertSame('no', $domain['allowbackup']);
    }

    public function testThePasswordIsHashedTheWayAnAccountIsHashedNotTheWayAMailboxIs(): void
    {
        // M4: apr1MD5, not sha512. The prefix is the whole assertion.
        $ref = $this->service()->create($this->caller('reseller'), $this->input());
        $hash = (string)$this->db->value('SELECT admin_pass FROM admin WHERE admin_id = ?', array($ref->getKey()));

        self::assertStringStartsWith('$apr1$', $hash);
    }

    public function testTheGuiPropertiesRowIsCreated(): void
    {
        $ref = $this->service()->create($this->caller('reseller'), $this->input());

        self::assertSame(
            1, (int)$this->db->value('SELECT COUNT(*) FROM user_gui_props WHERE user_id = ?', array($ref->getKey()))
        );
    }

    public function testTheResellerCountersAreRecalculatedAndTheDaemonIsPoked(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        self::assertContains(
            array('updateResellerCounters', $this->fixture->resellerId()), $this->core->calls
        );
        self::assertContains(array('sendRequest'), $this->core->calls);
    }

    public function testTheEventsCarryThePanelsOwnParameters(): void
    {
        // M3: onBeforeAddDomain has no domainId; onAfterAddDomain has one.
        $ref = $this->service()->create($this->caller('reseller'), $this->input());
        $before = $this->core->eventNamed('onBeforeAddDomain');
        $after = $this->core->eventNamed('onAfterAddDomain');

        self::assertSame($this->fixture->resellerId(), $before['createdBy']);
        self::assertSame('sgwnew.test', $before['domainName']);
        self::assertSame('/', $before['mountPoint']);
        self::assertSame('/htdocs', $before['documentRoot']);
        self::assertArrayNotHasKey('domainId', $before);
        self::assertSame((int)$ref->getKey(), $after['customerId']);
        self::assertArrayHasKey('domainId', $after);
    }

    public function testNeitherEventCarriesThePassword(): void
    {
        $this->service()->create($this->caller('reseller'), $this->input());

        foreach ($this->core->events as $event) {
            self::assertStringNotContainsString('N3wCustomer!', json_encode($event[1]));
        }

        foreach ($this->core->logs as $log) {
            self::assertStringNotContainsString('N3wCustomer!', $log[0]);
        }
    }

    public function testTheWelcomeMessageIsSentAfterTheCommitAndOnlyWhenAsked(): void
    {
        // C11 item 12: the page sends it inside the transaction.
        $this->service()->create($this->caller('reseller'), $this->input());
        self::assertSame(array(), $this->core->callsNamed('sendAccountCreatedEmail'));

        $this->service()->create($this->caller('reseller'), $this->input(array(
            'username' => 'sgwnew2.test', 'domainName' => 'sgwnew2.test', 'sendWelcomeEmail' => true
        )));

        $sent = $this->core->callsNamed('sendAccountCreatedEmail');
        self::assertCount(1, $sent);

        // The recorder sees the whole sequence, so "after the commit" is
        // assertable as "after every call the transaction made": the last of
        // those is the onAfterAddDomain dispatch.
        $names = array();

        foreach ($this->core->calls as $call) {
            $names[] = $call[0];
        }

        self::assertGreaterThan(
            max(array_keys($names, 'dispatch', true)),
            array_search('sendAccountCreatedEmail', $names, true),
            'the mail is sent after the writer has committed'
        );
    }

    public function testAFailedWelcomeMessageDoesNotStrandTheCustomer(): void
    {
        // A3: get_welcome_email()/send_mail() throw rather than return false,
        // so a mail that fails must not undo, or appear to undo, a create
        // that already committed and already poked the daemon.
        $this->core->sendAccountCreatedEmailThrows = new \RuntimeException('SMTP is down');

        $ref = $this->service()->create(
            $this->caller('reseller'), $this->input(array('sendWelcomeEmail' => true))
        );

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($ref->getKey()));
        self::assertSame('toadd', $admin['admin_status']);
        self::assertContains(array('sendRequest'), $this->core->calls);

        $errors = array_values(array_filter($this->core->logs, function (array $log): bool {
            return $log[1] === E_USER_ERROR;
        }));
        self::assertCount(1, $errors);
        self::assertStringContainsString('sgwnew.test', $errors[0][0]);
        self::assertStringContainsString('SMTP is down', $errors[0][0]);
    }

    public function testDefaultMailAccountsFollowThePanelsSetting(): void
    {
        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => false));
        $this->service()->create($this->caller('reseller'), $this->input());
        self::assertSame(array(), $this->core->callsNamed('createDefaultMailAccounts'));

        $this->reconfigure(array('CREATE_DEFAULT_EMAIL_ADDRESSES' => true));
        $this->service()->create($this->caller('reseller'), $this->input(array(
            'username' => 'sgwnew3.test', 'domainName' => 'sgwnew3.test'
        )));
        self::assertCount(1, $this->core->callsNamed('createDefaultMailAccounts'));
    }

    // ---- the refusals, in spec section 8.1's order ----------------------

    public function testACustomerMayNotCreateACustomer(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->create($this->caller('customer'), $this->input());
        });
    }

    public function testAnotherResellersIpIsNotFound(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET reseller_ips = ? WHERE reseller_id = ?',
            array('', $this->fixture->resellerId())
        );

        // C11 item 13: under PHP 7 the page's loose in_array() lets this pass.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input());
        });

        self::assertSame('input.ipAddressId', $e->getExtensions()['field']);
    }

    public function testNeitherAPlanNorAllowancesIsRefused(): void
    {
        $input = $this->input();
        unset($input['allowances']);

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($input): void {
            $this->service()->create($this->caller('reseller'), $input);
        });

        self::assertSame('input', $e->getExtensions()['field']);
    }

    public function testBothAPlanAndAllowancesIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'hostingPlanId' => GlobalId::encode(NodeType::HOSTING_PLAN, $this->fixture->hostingPlanId())
            )));
        });
    }

    public function testADomainNameThatExistsIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'domainName' => $this->fixture->domainName(), 'username' => 'sgwdup.test'
            )));
        });
    }

    public function testAnInvalidDomainNameIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array('domainName' => 'not a domain')));
        });

        self::assertSame('input.domainName', $e->getExtensions()['field']);
    }

    public function testAWeakPasswordIsRefusedWithoutEchoingIt(): void
    {
        try {
            $this->service()->create($this->caller('reseller'), $this->input(array('password' => 'x')));
            self::fail('expected a refusal');
        } catch (\iMSCP\Plugin\SGW_GraphQL\Support\ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertStringNotContainsString('x', $e->getMessage() . json_encode($e->getExtensions()));
        }
    }

    public function testALimitBeyondWhatTheResellerHasLeftIsRefused(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET max_sub_cnt = 4, current_sub_cnt = 3 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('subdomains' => 2) + $this->input()['allowances']
            )));
        });

        // The create path's own rule (LimitRules::createReason(), not the
        // edit page's): current (3) + asked for (2) is over the reseller's
        // max (4), and the refusal carries the numbers that tripped it (A10).
        self::assertSame('subdomains', $e->getExtensions()['quota']);
        self::assertSame('You are exceeding your subdomains limit.', $e->getMessage());
        self::assertSame(4, $e->getExtensions()['limit']);
        self::assertSame(3, $e->getExtensions()['used']);
    }

    public function testACustomerCountIsCheckedBeforeAnyServiceEvenWithRoomLeftInIt(): void
    {
        // A8, A9: the reseller is out of customers, but has room in every
        // service - so if the count is not checked first, the create would
        // wrongly succeed.
        $this->db->execute(
            'UPDATE reseller_props SET max_dmn_cnt = 1, current_dmn_cnt = 1 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input());
        });

        self::assertSame('customers', $e->getExtensions()['quota']);
        self::assertSame('You have reached your domains limit. You cannot add more domains.', $e->getMessage());
        self::assertSame(1, $e->getExtensions()['limit']);
        self::assertSame(1, $e->getExtensions()['used']);
    }

    public function testDiskAndTrafficAreEnforcedOnCreate(): void
    {
        // A1: neither was ever measured before the root-cause fix.
        $this->db->execute(
            'UPDATE reseller_props SET max_disk_amnt = 100, current_disk_amnt = 60 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('disk' => 50) + $this->input()['allowances']
            )));
        });

        self::assertSame('disk', $e->getExtensions()['quota']);
        self::assertSame('You are exceeding your disk space limit.', $e->getMessage());
    }

    public function testAWithheldLimitDoesNotBlockACreateEvenWhenTheResellerIsOverItsOwn(): void
    {
        // A9: before the fix, a reseller whose own consumption already
        // exceeds its limit could create no customer at all, even one that
        // withholds the very service the reseller is over on.
        $this->db->execute(
            'UPDATE reseller_props SET max_sql_db_cnt = 1, current_sql_db_cnt = 5 WHERE reseller_id = ?',
            array($this->fixture->resellerId())
        );

        $ref = $this->service()->create($this->caller('reseller'), $this->input(array(
            'allowances' => array('sqlDatabases' => -1, 'sqlUsers' => -1) + $this->input()['allowances']
        )));

        self::assertNotNull($ref);
    }

    public function testNothingIsWrittenWhenARefusalHappens(): void
    {
        $before = (int)$this->db->value('SELECT COUNT(*) FROM admin');

        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array('domainName' => 'not a domain')));
        });

        self::assertSame($before, (int)$this->db->value('SELECT COUNT(*) FROM admin'));
    }
}
