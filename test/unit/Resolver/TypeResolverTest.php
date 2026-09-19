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

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TypeResolverTest extends TestCase
{
    private function context(array $scopes = array()): array
    {
        return array('identity' => new Identity(
            7, 'customer', 'user', 5, 'c@example.test', $scopes, 11
        ));
    }

    private function info(): ResolveInfo
    {
        // resolveType never reads it; the executor's signature requires it.
        return $this->createMock(ResolveInfo::class);
    }

    public function testAnAliasSubdomainResolvesToTheSubdomainType(): void
    {
        // Decision D1: the tag and the GraphQL type differ in exactly one
        // place, and getting it wrong here would make every alias subdomain
        // unrenderable - "abstract type Subdomain must resolve to an Object
        // type at runtime".
        self::assertSame('Subdomain', TypeResolver::resolveType(
            array(TypeResolver::TAG => NodeType::ALIAS_SUBDOMAIN),
            $this->context(),
            $this->info()
        ));
    }

    public function testEachTagResolvesToItsOwnType(): void
    {
        foreach (array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::SUBDOMAIN,
            NodeType::DOMAIN_ALIAS, NodeType::MAIL_ACCOUNT, NodeType::FTP_USER,
            NodeType::SQL_DATABASE, NodeType::SQL_USER, NodeType::DNS_RECORD,
            NodeType::RESELLER, NodeType::HOSTING_PLAN, NodeType::IP_ADDRESS
        ) as $tag) {
            self::assertSame($tag, TypeResolver::resolveType(
                array(TypeResolver::TAG => $tag), $this->context(), $this->info()
            ), $tag);
        }
    }

    public function testAValueWithNoTagIsAnInternalError(): void
    {
        // A resolver that forgot to tag its array would otherwise produce
        // "abstract type must resolve to an Object type" from deep inside the
        // executor, with no clue which resolver was at fault.
        try {
            TypeResolver::resolveType(array('id' => 'x'), $this->context(), $this->info());
            self::fail('an untagged value must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::INTERNAL, $e->getErrorCode());
        }
    }

    public function testNodeEncodesAnIntegerIdentifier(): void
    {
        $node = TypeResolver::node(NodeType::DOMAIN, 12, array('name' => 'a.test'));

        self::assertSame(GlobalId::encode(NodeType::DOMAIN, 12), $node['id']);
        self::assertSame(NodeType::DOMAIN, $node[TypeResolver::TAG]);
        self::assertSame(12, $node['__key']);
        self::assertSame('a.test', $node['name']);
    }

    public function testNodeEncodesAStringIdentifierForAnFtpUser(): void
    {
        // ftp_users' primary key is userid varchar(255). Encoding it with
        // encode() would raise; encoding it as an integer would collide.
        $node = TypeResolver::node(NodeType::FTP_USER, 'shop@a.test', array());

        self::assertSame(
            GlobalId::encodeKey(NodeType::FTP_USER, 'shop@a.test'), $node['id']
        );
    }

    public function testProvisioningCarriesTheRawStatus(): void
    {
        $p = TypeResolver::provisioning('toadd');

        self::assertSame('PENDING', $p['state']);
        self::assertSame('toadd', $p['raw']);
        self::assertFalse($p['settled']);
        self::assertNull($p['message']);
    }

    public function testProvisioningCarriesTheBackendsFailureText(): void
    {
        $p = TypeResolver::provisioning('Failed to add domain: no such user');

        self::assertSame('ERROR', $p['state']);
        self::assertSame('Failed to add domain: no such user', $p['message']);
    }

    public function testDateTimeIsUtcAndIsoEightSixHundredOne(): void
    {
        self::assertSame('2026-01-01T00:00:00Z', TypeResolver::dateTime(1767225600));
        self::assertSame('2026-01-01T00:00:00Z', TypeResolver::dateTime('1767225600'));
    }

    public function testDateTimeTreatsImscpsZeroAsUnset(): void
    {
        // i-MSCP writes 0 for "no expiry", not NULL. Rendering that as
        // 1970-01-01 would tell every client the account expired in 1970.
        self::assertNull(TypeResolver::dateTime(0));
        self::assertNull(TypeResolver::dateTime('0'));
        self::assertNull(TypeResolver::dateTime(''));
        self::assertNull(TypeResolver::dateTime(null));
    }

    public function testBigIntIsADecimalString(): void
    {
        // Spec section 7.1: a JSON number is a double, and disk figures exceed
        // 2^53. A BigInt resolver that returned an int would be serialised by
        // the identity custom scalar as a number and silently lose precision.
        self::assertSame('1099511627776', TypeResolver::bigInt(1099511627776));
        self::assertSame('0', TypeResolver::bigInt(0));
        self::assertSame('0', TypeResolver::bigInt('0'));
        self::assertNull(TypeResolver::bigInt(null));
    }

    public function testGenderMapsImscpsSingleLetters(): void
    {
        self::assertSame('MALE', TypeResolver::gender('M'));
        self::assertSame('FEMALE', TypeResolver::gender('F'));
        self::assertSame('UNSPECIFIED', TypeResolver::gender('U'));
        self::assertNull(TypeResolver::gender(null));
        self::assertNull(TypeResolver::gender(''));
    }

    public function testContactDecodesPunycodeInTheEmailAddress(): void
    {
        $contact = TypeResolver::contact(array(
            'fname' => 'Ada', 'lname' => 'Lovelace', 'gender' => 'F',
            'firm' => 'Analytical Ltd', 'street1' => '1 Test Street',
            'street2' => null, 'city' => 'Testville', 'state' => 'Testshire',
            'zip' => '1234', 'country' => 'NZ',
            'email' => 'ada@xn--bcher-kva.test', 'phone' => '+64 3 000 0000',
            'fax' => null
        ), static function (string $value) {
            return $value === 'ada@xn--bcher-kva.test' ? 'ada@bücher.test' : $value;
        });

        self::assertSame('Ada', $contact['firstName']);
        self::assertSame('Lovelace', $contact['lastName']);
        self::assertSame('FEMALE', $contact['gender']);
        self::assertSame('Analytical Ltd', $contact['company']);
        self::assertSame('1234', $contact['postCode']);
        self::assertSame('ada@bücher.test', $contact['email']);
        self::assertNull($contact['fax']);
    }

    public function testRequireScopeAllowsAScopeTheTokenCarries(): void
    {
        $identity = TypeResolver::requireScope(
            $this->context(array(Scope::MAIL_READ)), Scope::MAIL_READ
        );

        self::assertSame(7, $identity->getAdminId());
    }

    public function testRequireScopeRefusesAScopeTheTokenLacks(): void
    {
        // The fail-closed half. A token scoped to MAIL_READ asking for SQL
        // must be refused, not quietly handed an empty list that reads as
        // "this customer has no databases".
        try {
            TypeResolver::requireScope(
                $this->context(array(Scope::MAIL_READ)), Scope::SQL_READ
            );
            self::fail('a missing scope must raise');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
            self::assertSame(
                array('scope' => Scope::SQL_READ), $e->getExtensions()
            );
        }
    }

    public function testATokenWithNoRecordedScopesCarriesThemAll(): void
    {
        // Plan 1's rule, restated here because requireScope() is where it now
        // has consequences: a token issued before scopes existed is a full
        // token, not a token with nothing.
        self::assertInstanceOf(Identity::class, TypeResolver::requireScope(
            $this->context(), Scope::SQL_READ
        ));
    }

    public function testPageInputIsClampedToTheServersCeiling(): void
    {
        // Spec section 7.9: limit is capped at 200 by the server regardless of
        // what is asked for. A client asking for a million rows is the cheapest
        // denial of service this API has.
        self::assertSame(
            array('limit' => 200, 'offset' => 0),
            TypeResolver::page(array('limit' => 100000, 'offset' => 0))
        );
        self::assertSame(
            array('limit' => 50, 'offset' => 0), TypeResolver::page(null)
        );
        self::assertSame(
            array('limit' => 1, 'offset' => 0),
            TypeResolver::page(array('limit' => 0, 'offset' => -5))
        );
        self::assertSame(
            array('limit' => 25, 'offset' => 75),
            TypeResolver::page(array('limit' => 25, 'offset' => 75))
        );
    }

    public function testAnUnknownTagRaisesRatherThanGuessing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TypeResolver::resolveType(
            array(TypeResolver::TAG => 'Htaccess'), $this->context(), $this->info()
        );
    }

    /**
     * Checkpoint E, finding E8: config.php's `max_page_size` was displayed on
     * the audit page and read by nothing. It is now the ceiling, carried on
     * the request context; PAGE_MAX is only the default for a context that
     * does not carry one.
     */
    public function testTheConfiguredCeilingCapsThePage(): void
    {
        self::assertSame(
            array('limit' => 10, 'offset' => 0),
            TypeResolver::page(array('limit' => 5000), array('pageMax' => 10))
        );
    }

    public function testTheConfiguredCeilingIsNotADefaultLimit(): void
    {
        // A ceiling raised above PAGE_DEFAULT does not change what an
        // unspecified page asks for.
        self::assertSame(
            array('limit' => TypeResolver::PAGE_DEFAULT, 'offset' => 0),
            TypeResolver::page(null, array('pageMax' => 1000))
        );
    }

    public function testAContextWithNoCeilingFallsBackToPageMax(): void
    {
        self::assertSame(TypeResolver::PAGE_MAX, TypeResolver::pageMax(null));
        self::assertSame(TypeResolver::PAGE_MAX, TypeResolver::pageMax(array()));
        self::assertSame(
            TypeResolver::PAGE_MAX,
            TypeResolver::pageMax(array('identity' => 'whatever'))
        );
    }

    public function testAMisconfiguredCeilingStillReturnsARow(): void
    {
        // 0 or a negative would make every page empty, which is not what any
        // operator means by a page size.
        self::assertSame(1, TypeResolver::pageMax(array('pageMax' => 0)));
        self::assertSame(1, TypeResolver::pageMax(array('pageMax' => -5)));
    }
}
