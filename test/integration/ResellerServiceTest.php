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

use iMSCP\Plugin\SGW_GraphQL\Service\ResellerService;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use RuntimeException;

class ResellerServiceTest extends ServiceTestCase
{
    private function service(): ResellerService
    {
        return new ResellerService($this->kit);
    }

    /** A complete, valid input for an administrator's own create. */
    private function input(array $overrides = array()): array
    {
        return $overrides + array(
            'username'     => 'sgwnewreseller.test',
            'password'     => 'N3wReseller!',
            'contact'      => array('email' => 'owner@sgwnewreseller.test', 'firstName' => 'Ada', 'lastName' => 'Lovelace'),
            'ipAddressIds' => array(GlobalId::encode(NodeType::IP_ADDRESS, $this->fixture->ipId())),
            'allowances'   => array(
                'customers' => 10, 'subdomains' => 10, 'domainAliases' => 10, 'mailAccounts' => 10,
                'ftpUsers' => 10, 'sqlDatabases' => 10, 'sqlUsers' => 10,
                'traffic' => 1024 * 1048576, 'disk' => 1024 * 1048576
            )
        );
    }

    /**
     * C1: a reseller with no `admin` row naming it in created_by - neither
     * of the fixture's own two resellers qualifies any more, since
     * otherReseller has otherCustomer.
     */
    private function createChildlessReseller(): int
    {
        static $n = 0;
        $n++;
        $name = 'sgwt_childless_reseller' . $n;
        $resellerId = $this->insert('admin', array(
            'admin_name'     => $name,
            'admin_pass'     => 'x',
            'admin_type'     => 'reseller',
            'admin_sys_uid'  => 0,
            'admin_sys_gid'  => 0,
            'domain_created' => time(),
            'customer_id'    => 'REF-' . $name,
            'created_by'     => $this->fixture->adminId(),
            'fname'          => 'Test',
            'lname'          => 'Reseller',
            'gender'         => 'U',
            'firm'           => 'Test Ltd',
            'zip'            => '1234',
            'city'           => 'Testville',
            'state'          => 'Testshire',
            'country'        => 'NZ',
            'email'          => $name . '@example.test',
            'phone'          => '+64 3 000 0000',
            'fax'            => null,
            'street1'        => '1 Test Street',
            'street2'        => null,
            'admin_status'   => 'ok'
        ));

        $this->insert('reseller_props', array(
            'reseller_id'          => $resellerId,
            'current_dmn_cnt'      => 0,
            'max_dmn_cnt'          => 20,
            'current_sub_cnt'      => 0,
            'max_sub_cnt'          => 100,
            'current_als_cnt'      => 0,
            'max_als_cnt'          => 50,
            'current_mail_cnt'     => 0,
            'max_mail_cnt'         => 0,
            'current_ftp_cnt'      => 0,
            'max_ftp_cnt'          => 40,
            'current_sql_db_cnt'   => 0,
            'max_sql_db_cnt'       => 30,
            'current_sql_user_cnt' => 0,
            'max_sql_user_cnt'     => 30,
            'current_disk_amnt'    => 0,
            'max_disk_amnt'        => 51200,
            'current_traff_amnt'   => 0,
            'max_traff_amnt'       => 102400,
            'support_system'       => 'yes',
            'reseller_ips'         => $this->fixture->ipId() . ';'
        ));

        return $resellerId;
    }

    /** An `admin` row only - enough for a created_by COUNT(*), nothing more. */
    private function createCustomerUnder(int $resellerId, string $adminStatus = 'ok'): int
    {
        static $n = 0;
        $n++;
        $name = 'sgwt_midcustomer' . $n;

        return $this->insert('admin', array(
            'admin_name'     => $name,
            'admin_pass'     => 'x',
            'admin_type'     => 'user',
            'admin_sys_uid'  => 0,
            'admin_sys_gid'  => 0,
            'domain_created' => time(),
            'customer_id'    => 'REF-' . $name,
            'created_by'     => $resellerId,
            'fname'          => 'Test',
            'lname'          => 'Customer',
            'gender'         => 'U',
            'firm'           => 'Test Ltd',
            'zip'            => '1234',
            'city'           => 'Testville',
            'state'          => 'Testshire',
            'country'        => 'NZ',
            'email'          => $name . '@example.test',
            'phone'          => '+64 3 000 0000',
            'fax'            => null,
            'street1'        => '1 Test Street',
            'street2'        => null,
            'admin_status'   => $adminStatus
        ));
    }

    // ---- create() -----------------------------------------------------

    public function testAResellerIsCreatedWithItsPropsRowAndItsIps(): void
    {
        $ref = $this->service()->create($this->caller('admin'), $this->input());

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwnewreseller.test', $admin['admin_name']);
        self::assertSame('reseller', $admin['admin_type']);
        self::assertSame($this->fixture->adminId(), (int)$admin['created_by']);

        $props = $this->db->row('SELECT * FROM reseller_props WHERE reseller_id = ?', array($ref->getKey()));
        self::assertSame(10, (int)$props['max_dmn_cnt']);
        self::assertSame($this->fixture->ipId() . ';', $props['reseller_ips']);
        self::assertSame('yes', $props['support_system']);
    }

    public function testEveryCurrentCounterStartsAtZero(): void
    {
        // M16.
        $ref = $this->service()->create($this->caller('admin'), $this->input());
        $props = $this->db->row('SELECT * FROM reseller_props WHERE reseller_id = ?', array($ref->getKey()));

        foreach (array(
            'current_dmn_cnt', 'current_sub_cnt', 'current_als_cnt', 'current_mail_cnt',
            'current_ftp_cnt', 'current_sql_db_cnt', 'current_sql_user_cnt',
            'current_traff_amnt', 'current_disk_amnt'
        ) as $column) {
            self::assertSame(0, (int)$props[$column], $column);
        }
    }

    public function testTheIpListIsStoredSortedWithATrailingSemicolon(): void
    {
        // M17.
        $secondIpId = $this->insert('server_ips', array(
            'ip_number' => '203.0.113.20', 'ip_netmask' => 24, 'ip_card' => 'eth0',
            'ip_config_mode' => 'manual', 'ip_status' => 'ok'
        ));
        $ids = array($this->fixture->ipId(), $secondIpId);
        rsort($ids);

        $ref = $this->service()->create($this->caller('admin'), $this->input(array(
            'ipAddressIds' => array_map(static function (int $id): string {
                return GlobalId::encode(NodeType::IP_ADDRESS, $id);
            }, $ids)
        )));

        $sorted = $ids;
        sort($sorted);
        $props = $this->db->row('SELECT reseller_ips FROM reseller_props WHERE reseller_id = ?', array($ref->getKey()));
        self::assertSame(implode(';', $sorted) . ';', $props['reseller_ips']);
    }

    public function testAResellerIsSynchronousAndPokesNoDaemon(): void
    {
        // M16: no send_request().
        $this->service()->create($this->caller('admin'), $this->input());

        self::assertSame(0, $this->core->requests);
    }

    public function testAtLeastOneIpIsRequired(): void
    {
        // reseller_add.php:283.
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('admin'), $this->input(array('ipAddressIds' => array())));
        });

        self::assertSame('input.ipAddressIds', $e->getExtensions()['field']);
    }

    public function testAnUnknownIpIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('admin'), $this->input(array(
                'ipAddressIds' => array(GlobalId::encode(NodeType::IP_ADDRESS, 999999))
            )));
        });

        self::assertSame('input.ipAddressIds', $e->getExtensions()['field']);
    }

    public function testOnlyAnAdministratorMayCreateAReseller(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->create($this->caller('reseller'), $this->input());
        });
    }

    public function testAUsernameThatIsTakenIsAConflict(): void
    {
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->create($this->caller('admin'), $this->input(array(
                'username' => $this->fixture->identity('reseller')->getUsername()
            )));
        });
    }

    public function testAWeakPasswordIsRefusedWithoutEchoingIt(): void
    {
        try {
            $this->service()->create($this->caller('admin'), $this->input(array('password' => 'x')));
            self::fail('expected a refusal');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
            self::assertStringNotContainsString('x', $e->getMessage() . json_encode($e->getExtensions()));
        }
    }

    public function testAnAllowanceMissingFromCreateIsRefused(): void
    {
        $input = $this->input();
        unset($input['allowances']['mailAccounts']);

        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function () use ($input): void {
            $this->service()->create($this->caller('admin'), $input);
        });

        self::assertSame('input.allowances.mailAccounts', $e->getExtensions()['field']);
    }

    public function testAWithheldServiceIsAcceptedOnCreate(): void
    {
        $ref = $this->service()->create($this->caller('admin'), $this->input(array(
            'allowances' => array('sqlDatabases' => -1, 'sqlUsers' => -1) + $this->input()['allowances']
        )));

        $props = $this->db->row(
            'SELECT max_sql_db_cnt, max_sql_user_cnt FROM reseller_props WHERE reseller_id = ?', array($ref->getKey())
        );
        self::assertSame(-1, (int)$props['max_sql_db_cnt']);
        self::assertSame(-1, (int)$props['max_sql_user_cnt']);
    }

    public function testACustomerCountMayNotBeWithheld(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('admin'), $this->input(array(
                'allowances' => array('customers' => -1) + $this->input()['allowances']
            )));
        });

        self::assertSame('input.allowances.customers', $e->getExtensions()['field']);
    }

    public function testATrafficLimitThatIsNotAWholeMibIsRefused(): void
    {
        $e = $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('admin'), $this->input(array(
                'allowances' => array('traffic' => 1048576 + 1) + $this->input()['allowances']
            )));
        });

        self::assertSame('input.allowances.traffic', $e->getExtensions()['field']);
    }

    /**
     * C4: traffic and disk are BigInt - "serialised as a decimal string"
     * (schema.graphql:24) - and the schema has no parseValue for it, so a
     * conforming client's string arrives exactly as sent.
     */
    public function testCreateAcceptsABigIntAllowanceAsTheStringItIsDocumentedAs(): void
    {
        $ref = $this->service()->create($this->caller('admin'), $this->input(array(
            'allowances' => array('traffic' => (string)(2048 * 1048576), 'disk' => (string)(1024 * 1048576))
                + $this->input()['allowances']
        )));

        $props = $this->db->row('SELECT max_traff_amnt, max_disk_amnt FROM reseller_props WHERE reseller_id = ?', array($ref->getKey()));
        self::assertSame(2048, (int)$props['max_traff_amnt']);
        self::assertSame(1024, (int)$props['max_disk_amnt']);
    }

    public function testTheWelcomeMessageIsSentAfterTheCommit(): void
    {
        // C11 item 12's own reasoning.
        $this->service()->create($this->caller('admin'), $this->input());

        $sent = $this->core->callsNamed('sendAccountCreatedEmail');
        self::assertCount(1, $sent);

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

    public function testAFailedWelcomeMessageDoesNotStrandTheReseller(): void
    {
        $this->core->sendAccountCreatedEmailThrows = new RuntimeException('SMTP is down');

        $ref = $this->service()->create($this->caller('admin'), $this->input());

        $admin = $this->db->row('SELECT admin_name FROM admin WHERE admin_id = ?', array($ref->getKey()));
        self::assertSame('sgwnewreseller.test', $admin['admin_name']);

        $errors = array_values(array_filter($this->core->logs, static function (array $log): bool {
            return $log[1] === E_USER_ERROR;
        }));
        self::assertCount(1, $errors);
    }

    public function testNothingIsWrittenWhenARefusalHappens(): void
    {
        $before = (int)$this->db->value('SELECT COUNT(*) FROM admin');

        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->create($this->caller('admin'), $this->input(array('password' => '')));
        });

        self::assertSame($before, (int)$this->db->value('SELECT COUNT(*) FROM admin'));
    }

    // ---- update() -------------------------------------------------------

    public function testTheContactDetailsChangeWithoutTouchingTheLimits(): void
    {
        $id = GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId());
        $before = $this->db->row(
            'SELECT * FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())
        );

        $this->service()->update($this->caller('admin'), $id, array(
            'contact' => array('email' => 'new@sgwt.test', 'firstName' => 'Grace')
        ));

        $admin = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->resellerId()));
        self::assertSame('new@sgwt.test', $admin['email']);
        self::assertSame('Grace', $admin['fname']);
        self::assertSame(
            $before, $this->db->row('SELECT * FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId()))
        );
    }

    public function testAPartialContactDoesNotBlankTheRest(): void
    {
        $before = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->resellerId()));

        $this->service()->update(
            $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
            array('contact' => array('email' => 'grace@sgwt.test'))
        );

        $after = $this->db->row('SELECT * FROM admin WHERE admin_id = ?', array($this->fixture->resellerId()));
        self::assertSame('grace@sgwt.test', $after['email']);
        self::assertSame($before['fname'], $after['fname']);
        self::assertSame($before['lname'], $after['lname']);
        self::assertSame($before['firm'], $after['firm']);
    }

    public function testAnyUpdateForcesTheResellerToLogInAgain(): void
    {
        // reseller_edit.php:680: unconditional, unlike a customer's own gate.
        $username = $this->fixture->identity('reseller')->getUsername();
        $this->db->execute(
            'INSERT INTO login (session_id, ipaddr, user_name, lastaccess) VALUES (?, ?, ?, ?)',
            array('sgwt-reseller-session', '127.0.0.1', $username, time())
        );

        $this->service()->update(
            $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
            array('supportSystem' => false)
        );

        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM login WHERE user_name = ?', array($username))
        );
    }

    public function testAnUpdateThatNamesNothingIsRefused(): void
    {
        $this->refused(ErrorCode::BAD_USER_INPUT, function (): void {
            $this->service()->update(
                $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()), array()
            );
        });
    }

    public function testLoweringALimitBelowWhatIsAlreadyInUseIsRefused(): void
    {
        // "the same shape as LimitRules' fourth test, with the reseller in
        // the customer's place" (task 10 brief).
        $this->db->execute(
            'UPDATE reseller_props SET current_sub_cnt = 5 WHERE reseller_id = ?', array($this->fixture->resellerId())
        );

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->update(
                $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
                array('allowances' => array('subdomains' => 3))
            );
        });

        self::assertSame('subdomains', $e->getExtensions()['quota']);
    }

    /**
     * C3: checkResellerLimit() (reseller_edit.php:757-775) - a service
     * already sold to this reseller's customers (its own current_* counter)
     * cannot be withheld.
     */
    public function testWithholdingAServiceAlreadySoldIsRefused(): void
    {
        $this->db->execute(
            'UPDATE reseller_props SET current_mail_cnt = 3 WHERE reseller_id = ?', array($this->fixture->resellerId())
        );

        $e = $this->refused(ErrorCode::LIMIT_EXCEEDED, function (): void {
            $this->service()->update(
                $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
                array('allowances' => array('mailAccounts' => -1))
            );
        });

        self::assertSame('mailAccounts', $e->getExtensions()['quota']);
    }

    /** C3: 0 (unlimited) is left alone, the page's own sense - only -1 is new here. */
    public function testWithholdingAServiceNotYetSoldIsAllowed(): void
    {
        $this->service()->update(
            $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
            array('allowances' => array('mailAccounts' => -1))
        );

        $after = $this->db->row(
            'SELECT max_mail_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())
        );
        self::assertSame(-1, (int)$after['max_mail_cnt']);
    }

    /**
     * C4: traffic and disk are BigInt - "serialised as a decimal string"
     * (schema.graphql:24) - and the schema has no parseValue for it, so a
     * conforming client's string arrives exactly as sent.
     */
    public function testUpdateAcceptsABigIntAllowanceAsTheStringItIsDocumentedAs(): void
    {
        $this->service()->update(
            $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
            array('allowances' => array('traffic' => (string)(2048 * 1048576)))
        );

        $after = $this->db->row(
            'SELECT max_traff_amnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())
        );
        self::assertSame(2048, (int)$after['max_traff_amnt']);
    }

    public function testAllowancesAreMergedNotReplaced(): void
    {
        $before = $this->db->row(
            'SELECT max_als_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())
        );

        $this->service()->update(
            $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
            array('allowances' => array('subdomains' => 7))
        );

        $after = $this->db->row(
            'SELECT max_sub_cnt, max_als_cnt FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())
        );
        self::assertSame(7, (int)$after['max_sub_cnt']);
        self::assertSame((int)$before['max_als_cnt'], (int)$after['max_als_cnt']);
    }

    /**
     * C2: reseller_edit.php:403-411 - array_unique(array_merge($resellerIps,
     * $data['used_ips'])) before sort. The fixture's own customer domain
     * sits on the fixture's own IP, so naming only a second IP must not
     * drop it: the customer's own IP is merged back in, not replaced away.
     */
    public function testChangingTheIpListKeepsAnyIpACustomerIsOn(): void
    {
        $secondIpId = $this->insert('server_ips', array(
            'ip_number' => '203.0.113.21', 'ip_netmask' => 24, 'ip_card' => 'eth0',
            'ip_config_mode' => 'manual', 'ip_status' => 'ok'
        ));

        $this->service()->update(
            $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
            array('ipAddressIds' => array(GlobalId::encode(NodeType::IP_ADDRESS, $secondIpId)))
        );

        $props = $this->db->row(
            'SELECT reseller_ips FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())
        );
        $expected = array($this->fixture->ipId(), $secondIpId);
        sort($expected);
        self::assertSame(implode(';', $expected) . ';', $props['reseller_ips']);
    }

    public function testOnlyAnAdministratorMayUpdateAReseller(): void
    {
        // Checkpoint B's own finding, restated for a reseller acting on
        // itself: missing this check is a privilege escalation.
        $before = $this->db->row('SELECT * FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId()));

        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->update(
                $this->caller('reseller'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
                array('allowances' => array('subdomains' => 999999))
            );
        });

        self::assertSame(
            $before, $this->db->row('SELECT * FROM reseller_props WHERE reseller_id = ?', array($this->fixture->resellerId())),
            'the row must be unchanged, not merely the exception thrown'
        );
    }

    public function testAnotherResellerIsNotFound(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            $this->service()->update(
                $this->caller('otherReseller'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()),
                array('supportSystem' => false)
            );
        });
    }

    // ---- delete() -------------------------------------------------------

    public function testAResellerWithCustomersCannotBeDeleted(): void
    {
        // admin/user_delete.php:144: every admin row this reseller created,
        // whatever its own status - the fixture's own reseller already has
        // two (customer, sibling). Not current_dmn_cnt: M9's own counter is
        // no longer read here at all (C1).
        $this->refused(ErrorCode::CONFLICT, function (): void {
            $this->service()->delete(
                $this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId())
            );
        });

        self::assertSame(array(), $this->core->callsNamed('deleteReseller'));
    }

    /**
     * C1: current_dmn_cnt (M9) is maintained by update_reseller_c_props()
     * with "AND domain_status <> 'todelete'" (Shared.php:414-421), so a
     * reseller's only customer mid-deletion already reads as zero there
     * while its `admin` row - and its dangling `created_by` - is still
     * live. admin_validateUserDeletion()'s own COUNT (user_delete.php:144)
     * carries no such filter, and neither does this.
     */
    public function testAResellerWhoseOnlyCustomerIsMidDeletionCannotBeDeleted(): void
    {
        $resellerId = $this->createChildlessReseller();
        $this->createCustomerUnder($resellerId, 'todelete');

        $this->refused(ErrorCode::CONFLICT, function () use ($resellerId): void {
            $this->service()->delete($this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $resellerId));
        });

        self::assertSame(array(), $this->core->callsNamed('deleteReseller'));
    }

    public function testDeletingAGenuinelyChildlessResellerRemovesItsPropsAndItsTokens(): void
    {
        $resellerId = $this->createChildlessReseller();
        $tokenId = $this->insert('api_token', array(
            'admin_id'     => $resellerId,
            'name'         => 'sgwt token',
            'token_prefix' => 'sgwttest',
            'token_hash'   => str_repeat('a', 64),
            'scopes'       => 'RESELLERS_READ',
            'ip_allowlist' => null,
            'created_at'   => time(),
            'expires_at'   => null,
            'last_used_at' => null,
            'last_used_ip' => null,
            'revoked_at'   => null
        ));

        $ref = $this->service()->delete($this->caller('admin'), GlobalId::encode(NodeType::RESELLER, $resellerId));

        self::assertSame($resellerId, (int)$ref->getKey());
        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM reseller_props WHERE reseller_id = ?', array($resellerId))
        );
        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM admin WHERE admin_id = ?', array($resellerId))
        );
        self::assertSame(
            0, (int)$this->db->value('SELECT COUNT(*) FROM api_token WHERE token_id = ?', array($tokenId))
        );
    }

    public function testOnlyAnAdministratorMayDeleteAReseller(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->delete(
                $this->caller('reseller'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId())
            );
        });

        self::assertSame(array(), $this->core->callsNamed('deleteReseller'));
    }

    public function testARefusalHappensBeforeTheHelperIsCalled(): void
    {
        $this->refused(ErrorCode::NOT_FOUND, function (): void {
            $this->service()->delete(
                $this->caller('otherReseller'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId())
            );
        });

        self::assertSame(array(), $this->core->callsNamed('deleteReseller'));
    }

    // ---- setApiAccess() ---------------------------------------------------

    public function testGrantingAndWithdrawingApiAccess(): void
    {
        $id = GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId());

        $this->service()->setApiAccess($this->caller('admin'), $id, false);
        self::assertSame(
            0, (int)$this->db->value('SELECT allowed FROM api_perm WHERE admin_id = ?', array($this->fixture->resellerId()))
        );

        $this->service()->setApiAccess($this->caller('admin'), $id, true);
        self::assertSame(
            1, (int)$this->db->value('SELECT allowed FROM api_perm WHERE admin_id = ?', array($this->fixture->resellerId()))
        );
    }

    public function testOnlyAnAdministratorMayChangeApiAccess(): void
    {
        $this->refused(ErrorCode::FORBIDDEN, function (): void {
            $this->service()->setApiAccess(
                $this->caller('reseller'), GlobalId::encode(NodeType::RESELLER, $this->fixture->resellerId()), false
            );
        });
    }
}
