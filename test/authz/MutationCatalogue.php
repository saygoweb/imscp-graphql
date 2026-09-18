<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Authz;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Integration\Fixture;

/**
 * Every mutation of spec section 7.11, each with a document that succeeds for
 * the object's owner against the seeded fixture.
 *
 * Each entry:
 *   scope     the write scope the mutation requires
 *   document  selects only `id`, so the row asserts the mutation and not a
 *             read edge's own scope (decision D19)
 *   prepare   fn(Fixture, Db): array - adjusts the fixture so the owner's
 *             call is valid, and returns anything variables() needs
 *   variables fn(Fixture, array $prepared): array
 *   owner     the fixture account the mutation belongs to: 'customer' for a
 *             customer-level mutation, 'reseller' for one of phase 4's
 *             reseller and administrator verbs
 *   expected  what each of the six accounts gets, which is the row's own
 *             business rather than one shape for the whole catalogue
 *
 * `owner` and `expected` are defaulted, in all(), to the customer-owned shape
 * the catalogue was born with. A row that is not customer-owned says so.
 */
final class MutationCatalogue
{
    /**
     * The owner and the owner's reseller succeed, and so does an
     * administrator. Everybody else - a sibling customer of the same reseller,
     * another reseller's customer, another reseller - gets NOT_FOUND, never
     * FORBIDDEN (spec 6.3): the object is not theirs to know about.
     */
    const CUSTOMER_OWNED = array(
        'customer'      => 'OK',
        'sibling'       => 'NOT_FOUND',
        'otherCustomer' => 'NOT_FOUND',
        'reseller'      => 'OK',
        'otherReseller' => 'NOT_FOUND',
        'admin'         => 'OK'
    );

    /**
     * A verb over an object a customer owns but may not administer: its own
     * account, or an alias order of its own. The customer reaches the object -
     * the schema hands it to them - so hiding it would be a lie; what they are
     * refused is the verb, which is their own role and so FORBIDDEN (spec
     * section 8.1 step 3).
     */
    const RESELLER_OWNED = array(
        'customer'      => 'FORBIDDEN',
        'sibling'       => 'NOT_FOUND',
        'otherCustomer' => 'NOT_FOUND',
        'reseller'      => 'OK',
        'otherReseller' => 'NOT_FOUND',
        'admin'         => 'OK'
    );

    /**
     * A reseller's own property - a hosting plan. A customer cannot reach one
     * at all (OwnershipResolver::mayAdministerReseller()), so it never gets as
     * far as the role check the RESELLER_OWNED rows stop at.
     */
    const RESELLER_PROPERTY = array(
        'customer'      => 'NOT_FOUND',
        'sibling'       => 'NOT_FOUND',
        'otherCustomer' => 'NOT_FOUND',
        'reseller'      => 'OK',
        'otherReseller' => 'NOT_FOUND',
        'admin'         => 'OK'
    );

    /**
     * A create, which has no object to own and so nothing to hide: every
     * refusal names the caller's own role or the reseller it asked to act for,
     * and is FORBIDDEN (decision D30).
     */
    const RESELLER_CREATE = array(
        'customer'      => 'FORBIDDEN',
        'sibling'       => 'FORBIDDEN',
        'otherCustomer' => 'FORBIDDEN',
        'reseller'      => 'OK',
        'otherReseller' => 'FORBIDDEN',
        'admin'         => 'OK'
    );

    /**
     * @return array<string, array{scope: string, document: string, prepare: callable, variables: callable, owner: string, expected: array<string, string>}>
     */
    public static function all(): array
    {
        $entries = array();

        foreach (self::entries() as $field => $entry) {
            $entries[$field] = array(
                'scope'     => $entry['scope'],
                'document'  => $entry['document'],
                'prepare'   => $entry['prepare'],
                'variables' => $entry['variables'],
                'owner'     => $entry['owner'] ?? 'customer',
                'expected'  => $entry['expected'] ?? self::CUSTOMER_OWNED
            );
        }

        return $entries;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function entries(): array
    {
        $nothing = static function (Fixture $f, Db $db): array {
            return array();
        };

        // The fixture withholds FTP (domain_ftpacc_limit = -1) so that a
        // FEATURE_UNAVAILABLE test has something to find. The matrix is about
        // who may act, so it grants FTP first.
        $grantFtp = static function (Fixture $f, Db $db): array {
            $db->execute('UPDATE domain SET domain_ftpacc_limit = 0 WHERE domain_id = ?', array($f->domainId()));

            return array();
        };

        $byId = static function (string $field): string {
            return 'mutation($id: ID!) { ' . $field . '(id: $id) { id } }';
        };

        $domain = static function (Fixture $f): string {
            return GlobalId::encode(NodeType::DOMAIN, $f->domainId());
        };

        $customer = static function (Fixture $f): string {
            return GlobalId::encode(NodeType::CUSTOMER, $f->customerId());
        };

        // An alias in the state its reseller may act on. The fixture's alias
        // is settled, because the customer-level rows need it that way.
        $ordered = static function (Fixture $f, Db $db): array {
            $db->execute(
                "UPDATE domain_aliasses SET alias_status = 'ordered' WHERE alias_id = ?", array($f->aliasId())
            );

            return array();
        };

        // A5: a create names every allowance - the six services, traffic, disk
        // and the mail quota - and never leaves one to a default. Bytes, and a
        // whole number of MiB for traffic and disk (A4/D3); the mail quota
        // fits inside the finite disk limit, as A6 requires. Within the
        // fixture reseller's own ledger (Fixture::resellerProps()).
        $allowances = array(
            'subdomains' => 2, 'domainAliases' => 1, 'mailAccounts' => 5, 'ftpUsers' => 2,
            'sqlDatabases' => 1, 'sqlUsers' => 1, 'traffic' => 1024 * 1048576,
            'disk' => 512 * 1048576, 'mailQuota' => 128 * 1048576
        );

        return array(
            'domainUpdate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($id: ID!, $input: DomainUpdateInput!) { domainUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('id' => $domain($f), 'input' => array('wildcard' => true));
                }
            ),
            'subdomainCreate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($input: SubdomainCreateInput!) { subdomainCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array('parentId' => $domain($f), 'label' => 'authz'));
                }
            ),
            'subdomainUpdate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($id: ID!, $input: SubdomainUpdateInput!) { subdomainUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::SUBDOMAIN, $f->subdomainId()),
                        'input' => array('wildcard' => true)
                    );
                }
            ),
            'subdomainDelete' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => $byId('subdomainDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::SUBDOMAIN, $f->subdomainId()));
                }
            ),
            'domainAliasCreate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($input: DomainAliasCreateInput!) { domainAliasCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array('domainId' => $domain($f), 'name' => 'sgwtauthz.test'));
                }
            ),
            'domainAliasUpdate' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => 'mutation($id: ID!, $input: DomainAliasUpdateInput!) { domainAliasUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::DOMAIN_ALIAS, $f->aliasId()),
                        'input' => array('wildcard' => true)
                    );
                }
            ),
            'domainAliasDelete' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => $byId('domainAliasDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $f->aliasId()));
                }
            ),
            'mailAccountCreate' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'localPart' => 'authz', 'kind' => 'FORWARD',
                        'forwardTo' => array('someone@example.net')
                    ));
                }
            ),
            'mailAccountUpdate' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($id: ID!, $input: MailAccountUpdateInput!) { mailAccountUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::MAIL_ACCOUNT, $f->mailboxId()),
                        'input' => array('kind' => 'FORWARD', 'forwardTo' => array('someone@example.net'))
                    );
                }
            ),
            'mailAccountDelete' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => $byId('mailAccountDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::MAIL_ACCOUNT, $f->mailboxId()));
                }
            ),
            'mailAutoresponderSet' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($id: ID!, $input: AutoresponderInput!) { mailAutoresponderSet(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::MAIL_ACCOUNT, $f->mailboxId()),
                        'input' => array('enabled' => true, 'message' => 'Away until Monday.')
                    );
                }
            ),
            'mailCatchallCreate' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => 'mutation($input: MailCatchallCreateInput!) { mailCatchallCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'addresses' => array('sales@' . $f->domainName())
                    ));
                }
            ),
            'mailCatchallDelete' => array(
                'scope'     => Scope::MAIL_WRITE,
                'document'  => $byId('mailCatchallDelete'),
                'prepare'   => static function (Fixture $f, Db $db): array {
                    $db->execute(
                        "
                            INSERT INTO mail_users (mail_acc, mail_pass, mail_forward, domain_id, mail_type,
                                sub_id, status, po_active, mail_auto_respond, quota, mail_addr)
                            VALUES (?, '_no_', '_no_', ?, 'normal_catchall', 0, 'ok', 'no', 0, 0, ?)
                        ",
                        array('sales@' . $f->domainName(), $f->domainId(), '@' . $f->domainName())
                    );

                    return array('catchall' => $db->lastInsertId());
                },
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::MAIL_ACCOUNT, $p['catchall']));
                }
            ),
            'ftpUserCreate' => array(
                'scope'     => Scope::FTP_WRITE,
                'document'  => 'mutation($input: FtpUserCreateInput!) { ftpUserCreate(input: $input) { id } }',
                'prepare'   => $grantFtp,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'username' => 'authz', 'password' => 'Authz0Pass'
                    ));
                }
            ),
            'ftpUserUpdate' => array(
                'scope'     => Scope::FTP_WRITE,
                'document'  => 'mutation($id: ID!, $input: FtpUserUpdateInput!) { ftpUserUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $grantFtp,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encodeKey(NodeType::FTP_USER, $f->ftpUserId()),
                        'input' => array('password' => 'Authz0Pass2')
                    );
                }
            ),
            'ftpUserDelete' => array(
                'scope'     => Scope::FTP_WRITE,
                'document'  => $byId('ftpUserDelete'),
                'prepare'   => $grantFtp,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encodeKey(NodeType::FTP_USER, $f->ftpUserId()));
                }
            ),
            'sqlDatabaseCreate' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => 'mutation($input: SqlDatabaseCreateInput!) { sqlDatabaseCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array('domainId' => $domain($f), 'name' => 'sgwt_authz'));
                }
            ),
            'sqlDatabaseDelete' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => $byId('sqlDatabaseDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::SQL_DATABASE, $f->sqlDatabaseId()));
                }
            ),
            'sqlUserCreate' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => 'mutation($input: SqlUserCreateInput!) { sqlUserCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('input' => array(
                        'databaseId' => GlobalId::encode(NodeType::SQL_DATABASE, $f->sqlDatabaseId()),
                        'name' => 'sgwt_authz', 'host' => 'localhost', 'password' => 'Authz0Pass'
                    ));
                }
            ),
            'sqlUserSetPassword' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => 'mutation($id: ID!, $password: Secret!) { sqlUserSetPassword(id: $id, password: $password) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'       => GlobalId::encode(NodeType::SQL_USER, $f->sqlUserId()),
                        'password' => 'Authz0Pass2'
                    );
                }
            ),
            'sqlUserDelete' => array(
                'scope'     => Scope::SQL_WRITE,
                'document'  => $byId('sqlUserDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::SQL_USER, $f->sqlUserId()));
                }
            ),
            'dnsRecordCreate' => array(
                'scope'     => Scope::DNS_WRITE,
                'document'  => 'mutation($input: DnsRecordCreateInput!) { dnsRecordCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p) use ($domain): array {
                    return array('input' => array(
                        'hostId' => $domain($f), 'name' => 'authz', 'type' => 'A',
                        'data' => array('address' => '203.0.113.9')
                    ));
                }
            ),
            'dnsRecordUpdate' => array(
                'scope'     => Scope::DNS_WRITE,
                'document'  => 'mutation($id: ID!, $input: DnsRecordUpdateInput!) { dnsRecordUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::DNS_RECORD, $f->dnsRecordId()),
                        'input' => array('name' => 'mail', 'ttl' => 3600, 'data' => array('address' => '203.0.113.10'))
                    );
                }
            ),
            'dnsRecordDelete' => array(
                'scope'     => Scope::DNS_WRITE,
                'document'  => $byId('dnsRecordDelete'),
                'prepare'   => $nothing,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::DNS_RECORD, $f->dnsRecordId()));
                }
            ),

            // ---- phase 4: the reseller and administrator verbs -------------

            'customerCreate' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => 'mutation($input: CustomerCreateInput!) { customerCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                // D30: the input names the fixture's reseller, so the owning
                // reseller names itself (allowed) and the other reseller names
                // somebody else (FORBIDDEN, not NOT_FOUND - it asked for
                // something its own role does not permit).
                'expected'  => self::RESELLER_CREATE,
                'variables' => static function (Fixture $f, array $p) use ($allowances): array {
                    return array('input' => array(
                        'username'    => 'sgwtauthz.test',
                        'password'    => 'Authz0Pass!',
                        'domainName'  => 'sgwtauthz.test',
                        'ipAddressId' => GlobalId::encode(NodeType::IP_ADDRESS, $f->ipId()),
                        'resellerId'  => GlobalId::encode(NodeType::RESELLER, $f->resellerId()),
                        'contact'     => array('email' => 'owner@sgwtauthz.test'),
                        'allowances'  => $allowances
                    ));
                }
            ),
            'customerUpdate' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => 'mutation($id: ID!, $input: CustomerUpdateInput!) { customerUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_OWNED,
                'variables' => static function (Fixture $f, array $p) use ($customer): array {
                    // B8: a key present with a null value still names a
                    // change - this one says "never expires".
                    return array('id' => $customer($f), 'input' => array('expiresAt' => null));
                }
            ),
            'customerDelete' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => $byId('customerDelete'),
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                // The sibling, not the fixture's main customer: deleteCustomer()
                // drops a customer's SQL databases through real DDL, which
                // commits the fixture's own transaction out from under the
                // test (see CustomerServiceTest's note). The sibling has none.
                // So here the customer that owns the object is the sibling,
                // and 'customer' is a stranger to it.
                'expected'  => array(
                    'customer'      => 'NOT_FOUND',
                    'sibling'       => 'FORBIDDEN',
                    'otherCustomer' => 'NOT_FOUND',
                    'reseller'      => 'OK',
                    'otherReseller' => 'NOT_FOUND',
                    'admin'         => 'OK'
                ),
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::CUSTOMER, $f->siblingId()));
                }
            ),
            'customerSetState' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => 'mutation($id: ID!, $state: AccountState!) { customerSetState(id: $id, state: $state) { id } }',
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_OWNED,
                'variables' => static function (Fixture $f, array $p) use ($customer): array {
                    // The fixture's customer is settled and enabled, so
                    // DISABLED is the transition M12 allows.
                    return array('id' => $customer($f), 'state' => 'DISABLED');
                }
            ),
            'customerSetApiAccess' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => 'mutation($id: ID!, $allowed: Boolean!) { customerSetApiAccess(id: $id, allowed: $allowed) { id } }',
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_OWNED,
                'variables' => static function (Fixture $f, array $p) use ($customer): array {
                    return array('id' => $customer($f), 'allowed' => false);
                }
            ),
            'hostingPlanCreate' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => 'mutation($input: HostingPlanInput!) { hostingPlanCreate(input: $input) { id } }',
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_CREATE,
                'variables' => static function (Fixture $f, array $p) use ($allowances): array {
                    return array('input' => array(
                        'name'       => 'sgwt authz plan',
                        'resellerId' => GlobalId::encode(NodeType::RESELLER, $f->resellerId()),
                        'allowances' => $allowances
                    ));
                }
            ),
            'hostingPlanUpdate' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => 'mutation($id: ID!, $input: HostingPlanInput!) { hostingPlanUpdate(id: $id, input: $input) { id } }',
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_PROPERTY,
                'variables' => static function (Fixture $f, array $p) use ($allowances): array {
                    return array(
                        'id'    => GlobalId::encode(NodeType::HOSTING_PLAN, $f->hostingPlanId()),
                        'input' => array('name' => 'sgwt authz plan', 'allowances' => $allowances)
                    );
                }
            ),
            'hostingPlanDelete' => array(
                'scope'     => Scope::CUSTOMERS_WRITE,
                'document'  => $byId('hostingPlanDelete'),
                'prepare'   => $nothing,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_PROPERTY,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::HOSTING_PLAN, $f->hostingPlanId()));
                }
            ),
            'domainAliasApprove' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => $byId('domainAliasApprove'),
                'prepare'   => $ordered,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_OWNED,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $f->aliasId()));
                }
            ),
            'domainAliasReject' => array(
                'scope'     => Scope::DOMAINS_WRITE,
                'document'  => $byId('domainAliasReject'),
                'prepare'   => $ordered,
                'owner'     => 'reseller',
                'expected'  => self::RESELLER_OWNED,
                'variables' => static function (Fixture $f, array $p): array {
                    return array('id' => GlobalId::encode(NodeType::DOMAIN_ALIAS, $f->aliasId()));
                }
            )
        );
    }
}
