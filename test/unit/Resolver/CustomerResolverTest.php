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

use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\CustomerResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class CustomerResolverTest extends TestCase
{
    /**
     * A joined admin/domain row exactly as CustomerResolver::SELECT produces
     * one.
     */
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'admin_id'        => 7,
            'admin_name'      => 'customer',
            'admin_type'      => 'user',
            'created_by'      => 5,
            'customer_id'     => 'REF-7',
            'admin_status'    => 'ok',
            'domain_created'  => 1767225600,
            'fname'           => 'Ada',
            'lname'           => 'Lovelace',
            'gender'          => 'F',
            'firm'            => 'Analytical Ltd',
            'street1'         => '1 Test Street',
            'street2'         => null,
            'city'            => 'Testville',
            'state'           => 'Testshire',
            'zip'             => '1234',
            'country'         => 'NZ',
            'email'           => 'ada@xn--bcher-kva.test',
            'phone'           => '+64 3 000 0000',
            'fax'             => null,
            'domain_id'       => 12,
            'domain_expires'  => 0,
            'domain_status'   => 'ok'
        ), $overrides);
    }

    private function toUnicode(): callable
    {
        return static function (string $value) {
            return $value === 'ada@xn--bcher-kva.test' ? 'ada@bücher.test' : $value;
        };
    }

    public function testTheIdentifierIsTheAdminIdNotTheDomainId(): void
    {
        // admin_id and domain_id are different id spaces and both are in the
        // row. Encoding the wrong one would hand every client an identifier
        // that addresses a different customer.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(GlobalId::encode(NodeType::CUSTOMER, 7), $shape['id']);
        self::assertSame(7, $shape['__key']);
        self::assertSame(NodeType::CUSTOMER, $shape[TypeResolver::TAG]);
        self::assertSame(12, $shape['__domainId']);
    }

    public function testTheResellerIsCreatedByNotTheAdministrator(): void
    {
        // admin.created_by is the ownership chain of spec section 2.2. Reading
        // any other column here would put the customer under the wrong
        // reseller, which is an authorisation bug wearing a display bug's
        // clothes.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(5, $shape['__resellerId']);
    }

    public function testCreatedAtComesFromTheAdminRowNotTheDomainRow(): void
    {
        // Both tables have a domain_created column. The customer's account
        // creation is admin.domain_created; SELECT lists only that one, and
        // this asserts the shape reads it.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('2026-01-01T00:00:00Z', $shape['createdAt']);
    }

    public function testAnAccountThatNeverExpiresHasNoExpiryDate(): void
    {
        // i-MSCP writes 0, not NULL. Rendering that as 1970-01-01 would tell
        // every client the account expired fifty-six years ago.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertNull($shape['expiresAt']);
        self::assertSame('2027-01-01T00:00:00Z', CustomerResolver::shape(
            $this->row(array('domain_expires' => 1798761600)), $this->toUnicode()
        )['expiresAt']);
    }

    public function testTheResellersOwnReferenceIsCarriedThrough(): void
    {
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('REF-7', $shape['reference']);
        self::assertNull(CustomerResolver::shape(
            $this->row(array('customer_id' => '')), $this->toUnicode()
        )['reference']);
    }

    public function testContactDetailsComeBackDecoded(): void
    {
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('Ada', $shape['contact']['firstName']);
        self::assertSame('FEMALE', $shape['contact']['gender']);
        self::assertSame('ada@bücher.test', $shape['contact']['email']);
    }

    public function testProvisioningComesFromTheAdminStatusNotTheDomainStatus(): void
    {
        // A Customer is the account. Its domain has its own Provisioning, on
        // the Domain type; conflating the two would report a settled account
        // as pending every time one of its vhosts was being rebuilt.
        $shape = CustomerResolver::shape($this->row(array(
            'admin_status' => 'toadd', 'domain_status' => 'ok'
        )), $this->toUnicode());

        self::assertSame('PENDING', $shape['provisioning']['state']);
        self::assertSame('toadd', $shape['provisioning']['raw']);
    }

    public function testTheWholeRowIsCarriedForTheFieldsThatNeedIt(): void
    {
        // quotas, storage and features all read domain columns. Carrying the
        // row is what keeps them from costing a query each.
        $shape = CustomerResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(12, $shape['__row']['domain_id']);
    }

    public function testTheMapOwnsExactlyTheCustomerFieldsThisTaskShapes(): void
    {
        // The failure this catches, in both directions: a field added to the
        // SDL and not to a map returns null for a non-null field and nulls the
        // whole Customer; a field claimed here as well as in Task 14 or 15
        // means one of the two silently never runs.
        $resolver = new CustomerResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            new Counts(Db::detached(), true),
            new VirtualHosts(Db::detached()),
            array(),
            true,
            static function () { return array(0, 0); },
            $this->toUnicode(),
            static function (int $resellerId) { return null; }
        );

        $keys = array_keys($resolver->map());
        sort($keys);

        self::assertSame(array(
            'Customer.apiAccess', 'Customer.domain', 'Customer.domainAliases',
            'Customer.features', 'Customer.quotas', 'Customer.reseller',
            'Customer.storage', 'Customer.subdomains'
        ), $keys);
    }
}
