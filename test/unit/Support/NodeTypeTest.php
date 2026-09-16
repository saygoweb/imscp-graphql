<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NodeTypeTest extends TestCase
{
    public function testAnAliasSubdomainHasItsOwnTagButIsASubdomainToClients(): void
    {
        // subdomain_id = 3 and subdomain_alias_id = 3 both exist and belong to
        // different customers, so they cannot share an identifier tag. They do
        // share a GraphQL type, because the storage split is not the client's
        // business.
        self::assertSame('Subdomain', NodeType::graphqlType(NodeType::ALIAS_SUBDOMAIN));
        self::assertSame('Subdomain', NodeType::graphqlType(NodeType::SUBDOMAIN));
        self::assertNotSame(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN);
    }

    public function testEveryOtherTagIsItsOwnGraphqlType(): void
    {
        foreach (array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::DOMAIN_ALIAS,
            NodeType::MAIL_ACCOUNT, NodeType::FTP_USER, NodeType::SQL_DATABASE,
            NodeType::SQL_USER, NodeType::DNS_RECORD, NodeType::RESELLER,
            NodeType::HOSTING_PLAN, NodeType::IP_ADDRESS
        ) as $tag) {
            self::assertSame($tag, NodeType::graphqlType($tag), $tag);
        }
    }

    public function testOnlyFtpUsersAreStringKeyed(): void
    {
        // ftp_users' primary key is userid varchar(255) - 'user@domain'.
        self::assertTrue(NodeType::isStringKeyed(NodeType::FTP_USER));
        self::assertFalse(NodeType::isStringKeyed(NodeType::MAIL_ACCOUNT));
    }

    public function testSubdomainTagsCoverBothTables(): void
    {
        self::assertSame(
            array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN),
            NodeType::subdomainTags()
        );
    }

    public function testAnUnknownTagIsRejected(): void
    {
        self::assertFalse(NodeType::isKnown('Htaccess'));

        $this->expectException(InvalidArgumentException::class);
        NodeType::graphqlType('Htaccess');
    }
}
