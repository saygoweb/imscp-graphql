<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Security;

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
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OwnershipResolverTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array}> */
    private $log = array();

    /** @var array<string, array> */
    private $rows = array();

    protected function setUp(): void
    {
        $this->log = array();

        // Keyed by a distinctive fragment of the query. The fixture is one
        // reseller (5) with two customers (7 and 8), and customer 7 owns
        // everything below.
        $this->rows = array(
            'FROM admin WHERE admin_id = ? AND admin_type'  => array(array('owner_id' => 7)),
            'FROM domain WHERE domain_id'                    => array(array('owner_id' => 7)),
            'FROM subdomain AS s'                            => array(array('owner_id' => 7)),
            'FROM subdomain_alias AS sa'                     => array(array('owner_id' => 7)),
            'FROM domain_aliasses AS a'                      => array(array('owner_id' => 7)),
            'FROM mail_users AS m'                           => array(array('owner_id' => 7)),
            'FROM ftp_users AS f'                            => array(array('owner_id' => 7)),
            'FROM sql_database AS sd'                        => array(array('owner_id' => 7)),
            'FROM sql_user AS su'                            => array(array('owner_id' => 7)),
            'FROM domain_dns AS dd'                          => array(array('owner_id' => 7)),
            'FROM hosting_plans'                             => array(array('owner_id' => 5)),
            'SELECT created_by'                              => array(array('created_by' => 5))
        );
    }

    private function resolver(): OwnershipResolver
    {
        return new OwnershipResolver(function (string $sql, array $bind) {
            $this->log[] = array($sql, $bind);

            foreach ($this->rows as $needle => $result) {
                if (strpos($sql, $needle) !== false) {
                    return $result;
                }
            }

            return array();
        });
    }

    private function identity(int $adminId, string $type, ?int $createdBy): Identity
    {
        return new Identity($adminId, 'account' . $adminId, $type, $createdBy, null, array(), null);
    }

    private function owner(): Identity      { return $this->identity(7, 'user', 5); }
    private function sibling(): Identity    { return $this->identity(8, 'user', 5); }
    private function reseller(): Identity   { return $this->identity(5, 'reseller', 1); }
    private function stranger(): Identity   { return $this->identity(6, 'reseller', 1); }
    private function admin(): Identity      { return $this->identity(1, 'admin', null); }

    public function testADomainResolvesToItsCustomer(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::DOMAIN, 12));

        self::assertSame(7, $this->resolver()->ownerOf($id));
        self::assertCount(1, $this->log, 'one query per object type, per spec section 6.3');
        self::assertSame(array(12), $this->log[0][1]);
    }

    /**
     * @dataProvider customerOwnedIdentifiers
     */
    public function testEveryCustomerOwnedTypeResolvesToItsCustomer(string $tag): void
    {
        $id = GlobalId::decode(GlobalId::encode($tag, 3));

        self::assertSame(7, $this->resolver()->ownerOf($id), $tag);
    }

    public function customerOwnedIdentifiers(): array
    {
        return array(
            'customer'        => array(NodeType::CUSTOMER),
            'domain'          => array(NodeType::DOMAIN),
            'subdomain'       => array(NodeType::SUBDOMAIN),
            'alias subdomain' => array(NodeType::ALIAS_SUBDOMAIN),
            'domain alias'    => array(NodeType::DOMAIN_ALIAS),
            'mail account'    => array(NodeType::MAIL_ACCOUNT),
            'sql database'    => array(NodeType::SQL_DATABASE),
            'sql user'        => array(NodeType::SQL_USER),
            'dns record'      => array(NodeType::DNS_RECORD)
        );
    }

    public function testAnFtpUserResolvesFromItsStringKey(): void
    {
        $id = GlobalId::decodeKey(GlobalId::encodeKey(NodeType::FTP_USER, 'shop@example.com'));

        self::assertSame(7, $this->resolver()->ownerOf($id));
        self::assertSame(array('shop@example.com'), $this->log[0][1]);
    }

    public function testAMissingRowHasNoOwner(): void
    {
        $this->rows = array();

        self::assertNull($this->resolver()->ownerOf(
            GlobalId::decode(GlobalId::encode(NodeType::DOMAIN, 12))
        ));
    }

    public function testTheAnswerIsMemoisedWithinOneRequest(): void
    {
        // A deep document resolves the same parent many times; asking the
        // database once per object per request is the whole point.
        $resolver = $this->resolver();
        $id = GlobalId::decode(GlobalId::encode(NodeType::DOMAIN, 12));

        $resolver->ownerOf($id);
        $resolver->ownerOf($id);

        self::assertCount(1, $this->log);
    }

    public function testTheOwnerMayReachTheirOwnObject(): void
    {
        self::assertTrue($this->resolver()->mayReach($this->owner(), 7));
    }

    public function testTheOwningResellerMayReachIt(): void
    {
        self::assertTrue($this->resolver()->mayReach($this->reseller(), 7));
    }

    public function testAnotherResellerMayNot(): void
    {
        self::assertFalse($this->resolver()->mayReach($this->stranger(), 7));
    }

    public function testAnotherCustomerOfTheSameResellerMayNot(): void
    {
        self::assertFalse($this->resolver()->mayReach($this->sibling(), 7));
    }

    public function testAnAdministratorMayReachAnything(): void
    {
        self::assertTrue($this->resolver()->mayReach($this->admin(), 7));
        self::assertCount(0, $this->log, 'an administrator needs no lookup');
    }

    public function testAssertReachableReturnsTheOwner(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::SUBDOMAIN, 3));

        self::assertSame(7, $this->resolver()->assertReachable($this->owner(), $id));
    }

    public function testAnUnreachableObjectIsNotFoundNotForbidden(): void
    {
        // Spec section 6.3: the API must not confirm the existence of other
        // people's objects. FORBIDDEN is reserved for objects the caller can
        // see but may not change.
        $id = GlobalId::decode(GlobalId::encode(NodeType::SUBDOMAIN, 3));

        try {
            $this->resolver()->assertReachable($this->sibling(), $id);
            self::fail('an unreachable object must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAMissingObjectIsAlsoNotFound(): void
    {
        $this->rows = array();
        $id = GlobalId::decode(GlobalId::encode(NodeType::SUBDOMAIN, 3));

        try {
            $this->resolver()->assertReachable($this->owner(), $id);
            self::fail('a missing object must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->getErrorCode());
        }
    }

    public function testAHostingPlanIsReachedThroughItsReseller(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::HOSTING_PLAN, 2));

        self::assertSame(5, $this->resolver()->resellerOf($id));
        self::assertSame(5, $this->resolver()->assertReachable($this->reseller(), $id));
    }

    public function testACustomerMayNotReachAHostingPlan(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::HOSTING_PLAN, 2));

        $this->expectException(ApiException::class);
        $this->resolver()->assertReachable($this->owner(), $id);
    }

    public function testAResellerMayReachAServerIpAddressAndACustomerMayNot(): void
    {
        $id = GlobalId::decode(GlobalId::encode(NodeType::IP_ADDRESS, 1));

        self::assertSame(0, $this->resolver()->assertReachable($this->reseller(), $id));
        self::assertSame(0, $this->resolver()->assertReachable($this->admin(), $id));

        $this->expectException(ApiException::class);
        $this->resolver()->assertReachable($this->owner(), $id);
    }

    public function testAnUnknownTagIsRejectedBeforeAnyQuery(): void
    {
        $encoded = GlobalId::encode('Htaccess', 1);

        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->ownerOf(GlobalId::decode($encoded));
    }
}
