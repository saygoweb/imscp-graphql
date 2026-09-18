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
                'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024 * 1048576, 'disk' => 512 * 1048576,
                // A6: a mail quota cannot be unlimited against a finite disk limit.
                'mailQuota' => 104857600, 'php' => true, 'cgi' => false, 'customDns' => false,
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

    public function testWwwIsStrippedFromTheDomainName(): void
    {
        // A7: user_add1.php:55-58 - "www is considered as an alias of the domain".
        $ref = $this->service()->create($this->caller('reseller'), $this->input(array(
            'domainName' => 'www.sgwwww.test'
        )));

        $domain = $this->db->row('SELECT domain_name FROM domain WHERE domain_admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwwww.test', $domain['domain_name']);

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'domainName' => 'sgwwww.test', 'username' => 'sgwwww2.test'
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
                // A6: the mail quota must still fit inside the disk limit asked for.
                'allowances' => array('disk' => 50 * 1048576, 'mailQuota' => 1048576) + $this->input()['allowances']
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

    public function testAMailQuotaOutsideTheDiskLimitIsRefused(): void
    {
        // A6: user_add2.php:454-473 - neither transcribed before this fix.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('disk' => 1048576, 'mailQuota' => 1048576 + 1) + $this->input()['allowances']
            )));
        });
        self::assertSame('input.allowances.mailQuota', $e->getExtensions()['field']);

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array(
                'allowances' => array('disk' => 1048576, 'mailQuota' => 0) + $this->input()['allowances']
            )));
        });
        self::assertSame('input.allowances.mailQuota', $e->getExtensions()['field']);
    }

    public function testNothingIsWrittenWhenARefusalHappens(): void
    {
        $before = (int)$this->db->value('SELECT COUNT(*) FROM admin');

        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input(array('domainName' => 'not a domain')));
        });

        self::assertSame($before, (int)$this->db->value('SELECT COUNT(*) FROM admin'));
    }

    // ---- update() ---------------------------------------------------------

    private function customerUsername(): string
    {
        return (string)$this->db->value(
            'SELECT admin_name FROM admin WHERE admin_id = ?', array($this->fixture->customerId())
        );
    }

    public function testTheContactDetailsChangeWithoutTouchingTheLimits(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());
        $before = $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($this->fixture->customerId()));

        $this->service()->update($this->caller('reseller'), $id, array(
            'contact' => array('email' => 'new@sgwt.test', 'firstName' => 'Grace')
        ));

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->customerId()));
        self::assertSame('new@sgwt.test', $admin['email']);
        self::assertSame('Grace', $admin['fname']);
        self::assertSame(
            $before, $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($this->fixture->customerId()))
        );
    }

    public function testAPasswordChangeForcesTheCustomerToLogInAgain(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());
        $this->db->execute(
            'INSERT INTO login (session_id, ipaddr, user_name, lastaccess) VALUES (?, ?, ?, ?)',
            array('sgwt-session', '127.0.0.1', $this->customerUsername(), time())
        );

        $this->service()->update($this->caller('reseller'), $id, array('password' => 'An0therSecret!'));

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->customerId()));
        self::assertStringStartsWith('$apr1$', $admin['admin_pass']);
        self::assertSame('tochangepwd', $admin['admin_status']);
        self::assertSame(
            0,
            (int)$this->db->value('SELECT COUNT(*) FROM login WHERE user_name = ?', array($this->customerUsername()))
        );
    }

    public function testAnUpdateThatNamesNothingIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->update(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), array()
            );
        });
    }

    public function testLoweringALimitBelowWhatIsUsedIsRefused(): void
    {
        // The fixture's customer already has subdomains.
        $used = (int)$this->db->value(
            'SELECT COUNT(*) FROM subdomain WHERE domain_id = ?', array($this->fixture->domainId())
        );
        self::assertGreaterThan(0, $used, 'the fixture must have a subdomain for this to measure anything');

        $this->refused(ErrorCode::LIMIT_EXCEEDED, function () use ($used): void {
            $this->service()->update(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
                array('allowances' => array('subdomains' => $used - 1))
            );
        });
    }

    public function testChangingALimitSchedulesTheDomainAndPokesTheDaemon(): void
    {
        $this->service()->update(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
            array('allowances' => array('mailAccounts' => 42))
        );

        $domain = $this->db->row('SELECT * FROM domain WHERE domain_admin_id = ?', array($this->fixture->customerId()));
        self::assertSame(42, (int)$domain['domain_mailacc_limit']);
        self::assertContains(array('sendRequest'), $this->core->calls);
        self::assertContains(array('updateResellerCounters', $this->fixture->resellerId()), $this->core->calls);
    }

    public function testWithdrawingCustomDnsRemovesTheCustomersOwnRecords(): void
    {
        // domain_edit.php:920-927: the records go, the plugin-owned ones stay.
        $this->db->execute("UPDATE domain SET domain_dns = 'yes' WHERE domain_id = ?", array($this->fixture->domainId()));
        $this->insert('domain_dns', array(
            'domain_id' => $this->fixture->domainId(), 'alias_id' => 0, 'domain_dns' => "sgwt.\t3600",
            'domain_class' => 'IN', 'domain_type' => 'A', 'domain_text' => '203.0.113.9',
            'owned_by' => 'custom_dns_feature', 'domain_dns_status' => 'ok'
        ));
        $keptId = $this->insert('domain_dns', array(
            'domain_id' => $this->fixture->domainId(), 'alias_id' => 0, 'domain_dns' => "plugin.\t3600",
            'domain_class' => 'IN', 'domain_type' => 'A', 'domain_text' => '203.0.113.10',
            'owned_by' => 'some_plugin', 'domain_dns_status' => 'ok'
        ));

        $this->service()->update(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
            array('allowances' => array('customDns' => false))
        );

        self::assertSame(
            0,
            (int)$this->db->value(
                "SELECT COUNT(*) FROM domain_dns WHERE domain_id = ? AND owned_by = 'custom_dns_feature'",
                array($this->fixture->domainId())
            )
        );
        self::assertSame(
            1, (int)$this->db->value('SELECT COUNT(*) FROM domain_dns WHERE domain_dns_id = ?', array($keptId))
        );
    }

    public function testACustomerOfAnotherResellerIsNotFound(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            $this->service()->update(
                $this->caller('otherReseller'),
                GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()),
                array('contact' => array('email' => 'thief@example.test'))
            );
        });
    }

    // ---- setState() ---------------------------------------------------------

    public function testDisablingASettledCustomerSchedulesIt(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());

        $this->service()->setState($this->caller('reseller'), $id, 'DISABLED');

        $domain = $this->db->row(
            'SELECT domain_status FROM domain WHERE domain_id = ?', array($this->fixture->domainId())
        );
        self::assertSame('todisable', $domain['domain_status']);
        self::assertContains(array('sendRequest'), $this->core->calls);
    }

    public function testEnablingADisabledCustomerSchedulesIt(): void
    {
        $this->db->execute(
            "UPDATE domain SET domain_status = 'disabled' WHERE domain_id = ?", array($this->fixture->domainId())
        );

        $this->service()->setState(
            $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), 'ENABLED'
        );

        $domain = $this->db->row(
            'SELECT domain_status FROM domain WHERE domain_id = ?', array($this->fixture->domainId())
        );
        self::assertSame('toenable', $domain['domain_status']);
    }

    public function testAskingForTheStateACustomerIsAlreadyInIsAConflict(): void
    {
        // M12: the page refuses anything but ok->deactivate and
        // disabled->activate, by way of showBadRequestErrorPage().
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->setState(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), 'ENABLED'
            );
        });
    }

    public function testACustomerInTransitIsNotStateChanged(): void
    {
        $this->db->execute(
            "UPDATE domain SET domain_status = 'tochange' WHERE domain_id = ?", array($this->fixture->domainId())
        );

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->setState(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId()), 'DISABLED'
            );
        });
    }

    // ---- delete() -------------------------------------------------------------

    public function testDeletingACustomerCallsThePanelsOwnHelperOutsideAnyTransaction(): void
    {
        // Not $this->fixture->customerId(): that customer carries a seeded
        // SQL database, and deleteCustomer() drops it via
        // delete_sql_database() *before* opening its own transaction -
        // real DDL, which implicitly commits whatever transaction is
        // already open in MySQL, including the fixture's own. The sibling
        // has no SQL database, so the real helper's work here cannot commit
        // the fixture out from under itself; measured directly against this
        // box (see the task report) with the main customer, which corrupts
        // the connection's savepoint bookkeeping outside any Writer::run()
        // of ours at all.
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->siblingId());

        // D22: delete() must open no Writer::run() of its own around the
        // helper - deleteCustomer() manages its own transaction. The Core
        // double has no commit() to assert against (Core is not the
        // transaction boundary), so this reads the panel's own transaction
        // counter the same way Fixture::rollBack() does, and checks that
        // delete() leaves it exactly where it found it.
        $panel = \iMSCP\Database\DatabaseMySQL::getInstance();
        $counter = new \ReflectionProperty($panel, 'transactionCounter');
        $counter->setAccessible(true);
        $depthBefore = $counter->getValue($panel);

        $ref = $this->service()->delete($this->caller('reseller'), $id);

        self::assertSame($this->fixture->siblingId(), (int)$ref->getKey());
        self::assertContains(array('deleteCustomer', $this->fixture->siblingId()), $this->core->calls);
        self::assertSame(
            $depthBefore, $counter->getValue($panel), 'delete() must leave the transaction depth unchanged'
        );
    }

    public function testARefusalHappensBeforeTheHelperIsCalled(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            $this->service()->delete(
                $this->caller('otherReseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId())
            );
        });

        self::assertSame(array(), $this->core->callsNamed('deleteCustomer'));
    }

    public function testACustomerAlreadyOnItsWayOutIsNotDeletedTwice(): void
    {
        $this->db->execute(
            "UPDATE admin SET admin_status = 'todelete' WHERE admin_id = ?", array($this->fixture->customerId())
        );

        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->delete(
                $this->caller('reseller'), GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId())
            );
        });
    }
}
