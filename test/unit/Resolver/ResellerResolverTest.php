<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Resolver;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class ResellerResolverTest extends TestCase
{
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'admin_id'             => 5,
            'admin_name'           => 'sgwtreseller',
            'admin_status'         => 'ok',
            'domain_created'       => 1767225600,
            'fname'                => 'Test',
            'lname'                => 'Reseller',
            'gender'               => 'U',
            'firm'                 => 'Test Ltd',
            'street1'              => '1 Test Street',
            'street2'              => null,
            'city'                 => 'Testville',
            'state'                => 'Testshire',
            'zip'                  => '1234',
            'country'              => 'NZ',
            'email'                => 'sgwtreseller@example.test',
            'phone'                => '+64 3 000 0000',
            'fax'                  => null,
            'max_dmn_cnt'          => 20,   'current_dmn_cnt'      => 3,
            'max_sub_cnt'          => 100,  'current_sub_cnt'      => 4,
            'max_als_cnt'          => 50,   'current_als_cnt'      => 2,
            'max_mail_cnt'         => 0,    'current_mail_cnt'     => 9,
            'max_ftp_cnt'          => 40,   'current_ftp_cnt'      => 1,
            'max_sql_db_cnt'       => 30,   'current_sql_db_cnt'   => 1,
            'max_sql_user_cnt'     => 30,   'current_sql_user_cnt' => 1,
            'max_disk_amnt'        => 51200,
            'max_traff_amnt'       => 102400,
            'reseller_ips'         => '3;7;'
        ), $overrides);
    }

    private function identity(string $role, int $adminId): Identity
    {
        $types = array(
            'ADMIN' => 'admin', 'RESELLER' => 'reseller', 'CUSTOMER' => 'user'
        );

        return new Identity(
            $adminId, 'who', $types[$role], null, 'who@example.test', array(), 1
        );
    }

    private function context(string $role, int $adminId): array
    {
        return array('identity' => $this->identity($role, $adminId));
    }

    public function testTheResellerShapesFromTheJoinedRow(): void
    {
        $shape = ResellerResolver::shape($this->row(), static function (string $v) {
            return $v;
        });

        self::assertSame(GlobalId::encode(NodeType::RESELLER, 5), $shape['id']);
        self::assertSame('sgwtreseller', $shape['username']);
        self::assertSame('2026-01-01T00:00:00Z', $shape['createdAt']);
        self::assertSame('OK', $shape['provisioning']['state']);
        self::assertSame('Test', $shape['contact']['firstName']);
    }

    public function testTheIpListIsSemicolonTerminatedNotSemicolonSeparated(): void
    {
        // reseller_props.reseller_ips is written as "3;7;" - with a trailing
        // separator. explode() alone yields an empty final element, which
        // becomes ip_id 0 and a null inside a non-null list.
        $shape = ResellerResolver::shape($this->row(), static function (string $v) {
            return $v;
        });

        self::assertSame(array(3, 7), $shape['__ipIds']);
    }

    public function testAResellerWithNoIpsHasAnEmptyListNotAZero(): void
    {
        foreach (array('', ';', null) as $stored) {
            $shape = ResellerResolver::shape(
                $this->row(array('reseller_ips' => $stored)),
                static function (string $v) { return $v; }
            );

            self::assertSame(array(), $shape['__ipIds']);
        }
    }

    public function testResellerQuotasHaveNoWithheldState(): void
    {
        // Spec section 7.8: "Unlike a customer's, these have no withheld
        // state: 0 means unlimited." max_mail_cnt is 0 in the row above.
        $quotas = ResellerResolver::quotas($this->row());

        self::assertSame(
            array('enabled' => true, 'limit' => 20, 'used' => 3, 'remaining' => 17),
            $quotas['customers']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => null, 'used' => 9, 'remaining' => null),
            $quotas['mailAccounts']
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 100, 'used' => 4, 'remaining' => 96),
            $quotas['subdomains']
        );
        self::assertSame(array(
            'customers', 'subdomains', 'domainAliases', 'mailAccounts',
            'ftpUsers', 'sqlDatabases', 'sqlUsers'
        ), array_keys($quotas));
    }

    public function testAnAdministratorMayReadAnyResellersPrivateFields(): void
    {
        self::assertSame(1, ResellerResolver::requireReseller(
            $this->context('ADMIN', 1), 5
        )->getAdminId());
    }

    public function testAResellerMayReadTheirOwnPrivateFields(): void
    {
        self::assertSame(5, ResellerResolver::requireReseller(
            $this->context('RESELLER', 5), 5
        )->getAdminId());
    }

    public function testAnotherResellerMayNot(): void
    {
        try {
            ResellerResolver::requireReseller($this->context('RESELLER', 6), 5);
            self::fail('a reseller must not read another reseller');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }

    public function testACustomerMayNotReadTheirOwnResellersPrivateFields(): void
    {
        // The SDL says it in its own description: a customer may read id,
        // username and contact - and nothing else. Quotas, storage, IPs, API
        // access and hosting plans are the reseller's business.
        try {
            ResellerResolver::requireReseller($this->context('CUSTOMER', 7), 5);
            self::fail('a customer must not read their reseller private fields');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }

    public function testAHostingPlanDecodesItsPositionalProperties(): void
    {
        $plan = ResellerResolver::planShape(array(
            'id'          => 61,
            'reseller_id' => 5,
            'name'        => 'sgwt plan',
            'description' => 'A plan for the tests.',
            'status'      => 1,
            'props'       => '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
                . 'yes;no;no;yes;10;10;30;60;128;_no_;_yes_;104857600'
        ));

        self::assertSame(GlobalId::encode(NodeType::HOSTING_PLAN, 61), $plan['id']);
        self::assertSame('sgwt plan', $plan['name']);
        self::assertTrue($plan['available']);
        self::assertSame(5, $plan['__resellerId']);

        self::assertSame(
            array('enabled' => true, 'limit' => 10), $plan['quotas']['subdomains']
        );
        // 0 is unlimited and -1 is withheld, exactly as for a customer.
        self::assertSame(
            array('enabled' => true, 'limit' => null), $plan['quotas']['mailAccounts']
        );
        self::assertSame(
            array('enabled' => false, 'limit' => 0), $plan['quotas']['ftpUsers']
        );

        // Decision D3: disk and traffic are stored in MiB, mailQuota in bytes.
        self::assertSame('5368709120', $plan['storage']['disk']);
        self::assertSame('10737418240', $plan['storage']['traffic']);
        self::assertSame('104857600', $plan['storage']['mailQuota']);

        self::assertTrue($plan['features']['php']);
        self::assertFalse($plan['features']['customDns']);
        self::assertSame(array('DOMAIN', 'SQL'), $plan['features']['backup']);
    }

    public function testAPlanWithUnparseablePropsIsAnInternalErrorNotAFatal(): void
    {
        // A 26th field would shift every reader by one, which is how a
        // customer ends up with somebody else's limits. PlanProps raises; this
        // asserts the raise becomes a structured error.
        try {
            ResellerResolver::planShape(array(
                'id' => 61, 'reseller_id' => 5, 'name' => 'x', 'description' => null,
                'status' => 1, 'props' => 'nonsense'
            ));
            self::fail('an unparseable props string must raise an ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::INTERNAL, $e->getErrorCode());
            self::assertSame(61, $e->getExtensions()['hostingPlanId']);
        }
    }

    public function testAPlanWithStatusZeroIsNotAvailable(): void
    {
        $plan = ResellerResolver::planShape(array(
            'id' => 61, 'reseller_id' => 5, 'name' => 'x', 'description' => null,
            'status' => 0,
            'props' => '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
                . 'yes;no;no;yes;10;10;30;60;128;_no_;_yes_;104857600'
        ));

        self::assertFalse($plan['available']);
    }

    public function testTheMapOwnsTheResellerPlanAndViewerFields(): void
    {
        $resolver = new ResellerResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            true,
            static function () { return array(0, 0); },
            static function (string $v) { return $v; },
            static function (int $adminId) { return null; },
            static function (array $adminIds) { return null; },
            static function (array $ipIds) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        self::assertSame(array(
            'HostingPlan.features', 'HostingPlan.quotas', 'HostingPlan.reseller',
            'HostingPlan.storage', 'Reseller.apiAccess', 'Reseller.customers',
            'Reseller.hostingPlans', 'Reseller.ipAddresses', 'Reseller.quotas',
            'Reseller.storage', 'Viewer.contact', 'Viewer.customer',
            'Viewer.reseller'
        ), $keys);
    }
}
