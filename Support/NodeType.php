<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

use InvalidArgumentException;

/**
 * The identifier-tag vocabulary.
 *
 * A tag is what goes inside a GlobalId. It is usually the same string as the
 * GraphQL type, and there is exactly one place where it is not: an alias
 * subdomain. Spec section 7.3 makes it a Subdomain whose parent is a
 * DomainAlias, which is right for a client, but `subdomain` and
 * `subdomain_alias` are separate tables with separate id spaces, so
 * subdomain_alias 3 and subdomain 3 must not share an identifier.
 */
final class NodeType
{
    const CUSTOMER        = 'Customer';
    const DOMAIN          = 'Domain';
    const SUBDOMAIN       = 'Subdomain';
    const ALIAS_SUBDOMAIN = 'AliasSubdomain';
    const DOMAIN_ALIAS    = 'DomainAlias';
    const MAIL_ACCOUNT    = 'MailAccount';
    const FTP_USER        = 'FtpUser';
    const SQL_DATABASE    = 'SqlDatabase';
    const SQL_USER        = 'SqlUser';
    const DNS_RECORD      = 'DnsRecord';
    const RESELLER        = 'Reseller';
    const HOSTING_PLAN    = 'HostingPlan';
    const IP_ADDRESS      = 'IpAddress';

    /** Tags that resolve to an owning customer's admin_id. */
    const CUSTOMER_OWNED = array(
        self::CUSTOMER, self::DOMAIN, self::SUBDOMAIN, self::ALIAS_SUBDOMAIN,
        self::DOMAIN_ALIAS, self::MAIL_ACCOUNT, self::FTP_USER,
        self::SQL_DATABASE, self::SQL_USER, self::DNS_RECORD
    );

    /** Tags that resolve to an owning reseller's admin_id. */
    const RESELLER_OWNED = array(self::RESELLER, self::HOSTING_PLAN);

    /** Tags that belong to the server rather than to any account. */
    const SERVER_OWNED = array(self::IP_ADDRESS);

    /** Tags whose primary key is not an integer. */
    const STRING_KEYED = array(self::FTP_USER);

    /** Tag => GraphQL type, for the tags where the two differ. */
    const GRAPHQL_TYPE = array(self::ALIAS_SUBDOMAIN => self::SUBDOMAIN);

    public static function isKnown(string $tag): bool
    {
        return in_array($tag, self::CUSTOMER_OWNED, true)
            || in_array($tag, self::RESELLER_OWNED, true)
            || in_array($tag, self::SERVER_OWNED, true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function graphqlType(string $tag): string
    {
        if (!self::isKnown($tag)) {
            throw new InvalidArgumentException(sprintf('Unknown node type "%s".', $tag));
        }

        return self::GRAPHQL_TYPE[$tag] ?? $tag;
    }

    public static function isStringKeyed(string $tag): bool
    {
        return in_array($tag, self::STRING_KEYED, true);
    }

    /**
     * Both tables a Subdomain can come from. A resolver that takes a subdomain
     * identifier accepts either.
     *
     * @return string[]
     */
    public static function subdomainTags(): array
    {
        return array(self::SUBDOMAIN, self::ALIAS_SUBDOMAIN);
    }
}
