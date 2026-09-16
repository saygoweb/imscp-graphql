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
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\DnsResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\MailResolver;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use PHPUnit\Framework\TestCase;

class MailResolverTest extends TestCase
{
    /**
     * A mail_users row, exactly as the column names come back.
     */
    private function row(array $overrides = array()): array
    {
        return array_merge(array(
            'mail_id'                => 21,
            'mail_acc'               => 'sales',
            'mail_addr'              => 'sales@xn--bcher-kva.test',
            'mail_forward'           => null,
            'domain_id'              => 12,
            'mail_type'              => 'normal_mail',
            'sub_id'                 => 0,
            'status'                 => 'ok',
            'po_active'              => 'yes',
            'mail_auto_respond'      => 0,
            'mail_auto_respond_text' => null,
            'quota'                  => 104857600
        ), $overrides);
    }

    private function toUnicode(): callable
    {
        return static function (string $value) {
            return $value === 'sales@xn--bcher-kva.test'
                ? 'sales@bücher.test' : $value;
        };
    }

    private function resolver(): MailResolver
    {
        return new MailResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            $this->toUnicode(),
            static function (string $address) { return null; },
            static function (string $tag, $key) { return null; }
        );
    }

    public function testTheAddressIsReturnedAsUnicode(): void
    {
        $shape = MailResolver::shape($this->row(), $this->toUnicode());

        self::assertSame('sales@bücher.test', $shape['address']);
        self::assertSame(GlobalId::encode(NodeType::MAIL_ACCOUNT, 21), $shape['id']);
    }

    public function testTheKindDropsTheVhostHalfOfMailType(): void
    {
        // Spec section 7.6: the vhost half of i-MSCP's twelve mail_type values
        // is carried by `host`, so the enum keeps only the second half. A
        // resolver exposing 'normal_mail' would leak a storage detail into
        // every client.
        self::assertSame('MAILBOX', MailResolver::shape(
            $this->row(), $this->toUnicode()
        )['kind']);
        self::assertSame('FORWARD', MailResolver::shape(
            $this->row(array('mail_type' => 'alssub_forward')), $this->toUnicode()
        )['kind']);
        self::assertSame('MAILBOX_AND_FORWARD', MailResolver::shape(
            $this->row(array('mail_type' => 'subdom_mail,subdom_forward')),
            $this->toUnicode()
        )['kind']);
        self::assertSame('CATCHALL', MailResolver::shape(
            $this->row(array('mail_type' => 'alias_catchall')), $this->toUnicode()
        )['kind']);
    }

    public function testTheHostOfAMainDomainAddressIsTheDomainNotSubIdZero(): void
    {
        // sub_id is 0 for the main domain, and 0 is not an identifier. A
        // resolver that passed it through would ask for Subdomain:0 and get
        // nothing, nulling a non-null field.
        $shape = MailResolver::shape($this->row(), $this->toUnicode());

        self::assertSame(NodeType::DOMAIN, $shape['__hostTag']);
        self::assertSame(12, $shape['__hostKey']);
    }

    public function testTheHostOfAnAliasSubdomainAddressIsTheAliasSubdomain(): void
    {
        $shape = MailResolver::shape(
            $this->row(array('mail_type' => 'alssub_forward', 'sub_id' => 9)),
            $this->toUnicode()
        );

        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $shape['__hostTag']);
        self::assertSame(9, $shape['__hostKey']);
    }

    public function testForwardsAreSplitOnTheComma(): void
    {
        $shape = MailResolver::shape($this->row(array(
            'mail_type'    => 'normal_forward',
            'mail_forward' => 'a@example.net,b@example.net'
        )), $this->toUnicode());

        self::assertSame(array('a@example.net', 'b@example.net'), $shape['forwardTo']);
    }

    public function testAMailboxWithNoForwardsHasAnEmptyListNotNull(): void
    {
        // forwardTo is [EmailAddress!]! - non-null. Null here would null the
        // whole MailAccount.
        self::assertSame(array(), MailResolver::shape(
            $this->row(array('mail_forward' => '_no_')), $this->toUnicode()
        )['forwardTo']);
        self::assertSame(array(), MailResolver::shape(
            $this->row(), $this->toUnicode()
        )['forwardTo']);
    }

    public function testAZeroQuotaIsUnlimitedNotZeroBytes(): void
    {
        self::assertSame('104857600', MailResolver::shape(
            $this->row(), $this->toUnicode()
        )['quota']);
        self::assertNull(MailResolver::shape(
            $this->row(array('quota' => 0)), $this->toUnicode()
        )['quota']);
    }

    public function testTheAutoresponderIsNullWhenItIsOff(): void
    {
        // Autoresponder.message is String! - so an off autoresponder must be
        // an absent object, not an object with an empty message.
        self::assertNull(MailResolver::shape($this->row(), $this->toUnicode())['autoresponder']);

        $on = MailResolver::shape($this->row(array(
            'mail_auto_respond' => 1, 'mail_auto_respond_text' => 'On holiday.'
        )), $this->toUnicode());

        self::assertSame(
            array('enabled' => true, 'message' => 'On holiday.'), $on['autoresponder']
        );
    }

    public function testAnAutoresponderWithNoTextStillHasAMessage(): void
    {
        $on = MailResolver::shape($this->row(array(
            'mail_auto_respond' => 1, 'mail_auto_respond_text' => null
        )), $this->toUnicode());

        self::assertSame('', $on['autoresponder']['message']);
    }

    public function testPopAccessIsOnlyTrueWhenTheColumnSaysYes(): void
    {
        self::assertTrue(MailResolver::shape($this->row(), $this->toUnicode())['active']);
        self::assertFalse(MailResolver::shape(
            $this->row(array('po_active' => 'no')), $this->toUnicode()
        )['active']);
    }

    public function testAMailTypeTheCodecDoesNotKnowIsAnInternalError(): void
    {
        // Left alone this would be an uncaught InvalidArgumentException and a
        // 500 with a stack trace. Spec section 9 wants a structured code.
        try {
            MailResolver::shape(
                $this->row(array('mail_type' => 'gopher_mail')), $this->toUnicode()
            );
            self::fail('an unknown mail_type must raise an ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::INTERNAL, $e->getErrorCode());
            self::assertSame('gopher_mail', $e->getExtensions()['mailType']);
        }
    }

    public function testTheMapOwnsTheMailFieldsAndCustomerMailAccounts(): void
    {
        $keys = array_keys($this->resolver()->map());
        sort($keys);

        self::assertSame(array(
            'Customer.mailAccounts', 'MailAccount.host', 'MailAccount.quotaUsed'
        ), $keys);
    }

    public function testTheDnsMapOwnsTheDnsFieldsAndCustomerDnsRecords(): void
    {
        $dns = new DnsResolver(
            Db::detached(),
            new BatchLoader(Db::detached()),
            static function (string $tag, $key) { return null; }
        );

        $keys = array_keys($dns->map());
        sort($keys);

        self::assertSame(array('Customer.dnsRecords', 'DnsRecord.host'), $keys);
    }

    public function testADnsRecordOnTheMainDomainHasAliasIdZero(): void
    {
        // domain_dns.alias_id = 0 means "on the main domain"
        // (gui/public/client/dns_edit.php:592). Reading it as an alias id
        // would ask for DomainAlias:0 and null a non-null field.
        $onDomain = DnsResolver::shape(array(
            'domain_dns_id'     => 31,
            'domain_id'         => 12,
            'alias_id'          => 0,
            'domain_dns'        => 'mail',
            'domain_class'      => 'IN',
            'domain_type'       => 'A',
            'domain_text'       => '203.0.113.7',
            'owned_by'          => 'custom_dns_feature',
            'domain_dns_status' => 'ok'
        ));

        self::assertSame(NodeType::DOMAIN, $onDomain['__hostTag']);
        self::assertSame(12, $onDomain['__hostKey']);
        self::assertSame('mail', $onDomain['name']);
        self::assertSame('IN', $onDomain['class']);
        self::assertSame('A', $onDomain['type']);
        self::assertSame('203.0.113.7', $onDomain['value']);
        self::assertSame('custom_dns_feature', $onDomain['ownedBy']);
        self::assertSame(NodeType::DNS_RECORD, $onDomain[TypeResolver::TAG]);
    }

    public function testADnsRecordOnAnAliasHangsOffTheAlias(): void
    {
        $onAlias = DnsResolver::shape(array(
            'domain_dns_id'     => 32,
            'domain_id'         => 12,
            'alias_id'          => 4,
            'domain_dns'        => 'www',
            'domain_class'      => 'IN',
            'domain_type'       => 'CNAME',
            'domain_text'       => 'example.net.',
            'owned_by'          => 'SGW_LetsEncrypt',
            'domain_dns_status' => 'ok'
        ));

        self::assertSame(NodeType::DOMAIN_ALIAS, $onAlias['__hostTag']);
        self::assertSame(4, $onAlias['__hostKey']);
    }

    public function testAnIpAddressShapesFromServerIps(): void
    {
        $ip = DnsResolver::shapeIp(array(
            'ip_id'      => 3,
            'ip_number'  => '203.0.113.7',
            'ip_netmask' => 24,
            'ip_card'    => 'eth0'
        ));

        self::assertSame(GlobalId::encode(NodeType::IP_ADDRESS, 3), $ip['id']);
        self::assertSame('203.0.113.7', $ip['address']);
        self::assertSame(24, $ip['netmask']);
        self::assertSame('eth0', $ip['card']);
    }

    public function testAnIpAddressWithNoNetmaskOrCardIsStillAnIpAddress(): void
    {
        // Both columns are nullable in i-MSCP, and both are nullable in the
        // schema. Casting a null netmask to 0 would be a lie about the network.
        $ip = DnsResolver::shapeIp(array(
            'ip_id' => 3, 'ip_number' => '203.0.113.7',
            'ip_netmask' => null, 'ip_card' => null
        ));

        self::assertNull($ip['netmask']);
        self::assertNull($ip['card']);
    }
}
