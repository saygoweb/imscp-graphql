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
 * Every customer-level mutation of spec section 7.11, each with a document
 * that succeeds for the owner against the seeded fixture.
 *
 * Each entry:
 *   scope     the write scope the mutation requires
 *   document  selects only `id`, so the row asserts the mutation and not a
 *             read edge's own scope (decision D19)
 *   prepare   fn(Fixture, Db): array - adjusts the fixture so the owner's
 *             call is valid, and returns anything variables() needs
 *   variables fn(Fixture, array $prepared): array
 */
final class MutationCatalogue
{
    /**
     * @return array<string, array{scope: string, document: string, variables: callable, prepare: callable}>
     */
    public static function all(): array
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
            )
        );
    }
}
