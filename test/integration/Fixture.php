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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use InvalidArgumentException;

/**
 * One reseller, two of its customers, a second reseller with a customer of its
 * own, and one of every object hanging off the first customer.
 *
 * That shape is what spec section 17's authorisation matrix needs: "the owner
 * may reach it", "a sibling of the same reseller may not", "another reseller's
 * customer may not" and "the owning reseller may" are all answerable against
 * this one seed.
 *
 * Everything happens inside a transaction that rollBack() undoes. A fixture
 * that committed would leave rows on the reference box, and the next run would
 * either collide on admin.admin_name or pass because of the previous run.
 */
final class Fixture
{
    /** Every name this fixture writes starts with this. */
    const PREFIX = 'sgwt';

    /** @var Db */
    private $db;

    /** @var bool */
    private $open = false;

    /** @var array<string, int|string> */
    private $ids = array();

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    public function seed(): void
    {
        if ($this->open) {
            return;
        }

        // Through Db, not the PDO: a service under test opens its own
        // transaction through the same counter, and that one must become a
        // savepoint inside this one (decision D11). Opened on the PDO, the
        // panel's counter would read 0 and the service's begin would be
        // refused (M2).
        $this->db->beginTransaction();
        $this->open = true;

        $now = 1767225600;   // 2026-01-01T00:00:00Z, fixed so dates are assertable

        $this->ids['admin'] = (int)$this->db->value(
            "SELECT admin_id FROM admin WHERE admin_type = 'admin' ORDER BY admin_id LIMIT 1"
        );

        $this->ids['ip'] = $this->insert('server_ips', array(
            'ip_number'      => '203.0.113.7',   // TEST-NET-3, never routable
            'ip_netmask'     => 24,
            'ip_card'        => 'eth0',
            'ip_config_mode' => 'manual',
            'ip_status'      => 'ok'
        ));

        // R4: the account name passed here is also what identity() rebuilds
        // as self::PREFIX . $key (see the $accounts table below), so the two
        // must use the same key - 'otherReseller' and 'otherCustomer' - or a
        // test comparing the two would see 'sgwtother'/'sgwtstranger' here and
        // 'sgwtotherReseller'/'sgwtotherCustomer' there.
        $this->ids['reseller'] = $this->account(
            self::PREFIX . 'reseller', 'reseller', $this->ids['admin'], $now
        );
        $this->ids['otherReseller'] = $this->account(
            self::PREFIX . 'otherReseller', 'reseller', $this->ids['admin'], $now
        );

        $this->resellerProps((int)$this->ids['reseller'], (int)$this->ids['ip']);
        $this->resellerProps((int)$this->ids['otherReseller'], (int)$this->ids['ip']);

        $this->ids['customer'] = $this->account(
            self::PREFIX . 'customer', 'user', $this->ids['reseller'], $now
        );
        $this->ids['sibling'] = $this->account(
            self::PREFIX . 'sibling', 'user', $this->ids['reseller'], $now
        );
        $this->ids['otherCustomer'] = $this->account(
            self::PREFIX . 'otherCustomer', 'user', $this->ids['otherReseller'], $now
        );

        $this->ids['domain'] = $this->domain(
            (int)$this->ids['customer'], $this->domainName(), $now
        );
        $this->ids['siblingDomain'] = $this->domain(
            (int)$this->ids['sibling'], self::PREFIX . 'sibling.test', $now
        );
        $this->ids['otherDomain'] = $this->domain(
            (int)$this->ids['otherCustomer'], self::PREFIX . 'stranger.test', $now
        );

        $this->ids['subdomain'] = $this->insert('subdomain', array(
            'domain_id'               => $this->ids['domain'],
            'subdomain_name'          => 'shop',
            'subdomain_mount'         => '/shop',
            'subdomain_document_root' => '/htdocs',
            'subdomain_url_forward'   => 'no',
            'subdomain_host_forward'  => 'Off',
            'subdomain_wildcard_alias'=> 'no',
            'subdomain_status'        => 'ok'
        ));

        $this->ids['alias'] = $this->insert('domain_aliasses', array(
            'domain_id'           => $this->ids['domain'],
            'alias_name'          => $this->aliasName(),
            'alias_status'        => 'ok',
            'alias_mount'         => '/alias',
            'alias_document_root' => '/htdocs',
            'alias_ip_id'         => $this->ids['ip'],
            'url_forward'         => 'https://example.net/',
            'type_forward'        => '301',
            'host_forward'        => 'Off',
            'wildcard_alias'      => 'no',
            'external_mail'       => 'off'
        ));

        $this->ids['aliasSubdomain'] = $this->insert('subdomain_alias', array(
            'alias_id'                      => $this->ids['alias'],
            'subdomain_alias_name'          => 'blog',
            'subdomain_alias_mount'         => '/blog',
            'subdomain_alias_document_root' => '/htdocs',
            'subdomain_alias_url_forward'   => 'no',
            'subdomain_alias_host_forward'  => 'Off',
            'subdomain_alias_wildcard_alias'=> 'yes',
            'subdomain_alias_status'        => 'toadd'   // one unsettled object
        ));

        $this->ids['mailbox'] = $this->insert('mail_users', array(
            'mail_acc'               => 'sales',
            'mail_pass'              => '_no_',
            'mail_forward'           => null,
            'domain_id'              => $this->ids['domain'],
            'mail_type'              => 'normal_mail',
            'sub_id'                 => 0,
            'status'                 => 'ok',
            'po_active'              => 'yes',
            'mail_auto_respond'      => 0,
            'mail_auto_respond_text' => null,
            'quota'                  => 104857600,
            'mail_addr'              => 'sales@' . $this->domainName()
        ));

        $this->ids['forward'] = $this->insert('mail_users', array(
            'mail_acc'               => 'hello',
            'mail_pass'              => '_no_',
            'mail_forward'           => 'a@example.net,b@example.net',
            'domain_id'              => $this->ids['domain'],
            'mail_type'              => 'alssub_forward',
            'sub_id'                 => $this->ids['aliasSubdomain'],
            'status'                 => 'ok',
            'po_active'              => 'no',
            'mail_auto_respond'      => 1,
            'mail_auto_respond_text' => 'On holiday.',
            'quota'                  => 0,
            'mail_addr'              => 'hello@blog.' . $this->aliasName()
        ));

        $this->ids['ftpUser'] = self::PREFIX . 'ftp@' . $this->domainName();
        $this->insert('ftp_users', array(
            'userid'   => $this->ids['ftpUser'],
            'admin_id' => $this->ids['customer'],
            'passwd'   => 'x',
            'uid'      => 2000,
            'gid'      => 2000,
            'shell'    => '/bin/sh',
            'homedir'  => '/var/www/virtual/' . $this->domainName(),
            'status'   => 'ok'
        ));

        $this->ids['sqlDatabase'] = $this->insert('sql_database', array(
            'domain_id' => $this->ids['domain'],
            'sqld_name' => self::PREFIX . '_shop'
        ));

        $this->ids['sqlUser'] = $this->insert('sql_user', array(
            'sqld_id'   => $this->ids['sqlDatabase'],
            'sqlu_name' => self::PREFIX . '_u1',
            'sqlu_host' => 'localhost'
        ));

        $this->ids['dnsRecord'] = $this->insert('domain_dns', array(
            'domain_id'         => $this->ids['domain'],
            'alias_id'          => 0,
            'domain_dns'        => 'mail',
            'domain_class'      => 'IN',
            'domain_type'       => 'A',
            'domain_text'       => '203.0.113.7',
            'owned_by'          => 'custom_dns_feature',
            'domain_dns_status' => 'ok'
        ));

        $this->ids['hostingPlan'] = $this->insert('hosting_plans', array(
            'reseller_id' => $this->ids['reseller'],
            'name'        => self::PREFIX . ' plan',
            'description' => 'A plan for the tests.',
            'props'       => '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
                . 'no;no;no;yes;10;5;30;60;128;_no_;_yes_;104857600',
            'status'      => 1
        ));
    }

    public function rollBack(): void
    {
        if (!$this->open) {
            return;
        }

        $this->open = false;

        // Unwound through reflection rather than by calling Db::rollBack()
        // once. A test that fails between a service's begin and its rollback
        // leaves DatabaseMySQL's counter above one; a single rollBack() would
        // then only roll back to a savepoint and leave the real transaction
        // open for the next test to seed into - which would pass, on rows a
        // previous test left behind. Zeroing the count and rolling the PDO
        // back undoes everything, whatever depth a failure left.
        $panel = \iMSCP\Database\DatabaseMySQL::getInstance();
        $counter = new \ReflectionProperty($panel, 'transactionCounter');
        $counter->setAccessible(true);
        $counter->setValue($panel, 0);

        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }

        $this->ids = array();
    }

    public function adminId(): int         { return (int)$this->id('admin'); }
    public function resellerId(): int      { return (int)$this->id('reseller'); }
    public function otherResellerId(): int { return (int)$this->id('otherReseller'); }
    public function customerId(): int      { return (int)$this->id('customer'); }
    public function siblingId(): int       { return (int)$this->id('sibling'); }
    public function otherCustomerId(): int { return (int)$this->id('otherCustomer'); }
    public function domainId(): int        { return (int)$this->id('domain'); }
    // The sibling's and the stranger's domains, so that a batching test can
    // ask about several distinct parents. Without them the only assertion
    // available is that one key asked for twice costs one query, which is
    // de-duplication rather than batching.
    public function siblingDomainId(): int { return (int)$this->id('siblingDomain'); }
    public function otherDomainId(): int   { return (int)$this->id('otherDomain'); }
    public function subdomainId(): int     { return (int)$this->id('subdomain'); }
    public function aliasId(): int         { return (int)$this->id('alias'); }
    public function aliasSubdomainId(): int{ return (int)$this->id('aliasSubdomain'); }
    public function mailboxId(): int       { return (int)$this->id('mailbox'); }
    public function forwardId(): int       { return (int)$this->id('forward'); }
    public function ftpUserId(): string    { return (string)$this->id('ftpUser'); }
    public function sqlDatabaseId(): int   { return (int)$this->id('sqlDatabase'); }
    public function sqlUserId(): int       { return (int)$this->id('sqlUser'); }
    public function dnsRecordId(): int     { return (int)$this->id('dnsRecord'); }
    public function hostingPlanId(): int   { return (int)$this->id('hostingPlan'); }
    public function ipId(): int            { return (int)$this->id('ip'); }

    public function domainName(): string    { return self::PREFIX . 'customer.test'; }
    public function aliasName(): string     { return self::PREFIX . 'alias.test'; }
    public function subdomainName(): string { return 'shop.' . $this->domainName(); }

    /**
     * @param string $who customer|sibling|otherCustomer|reseller|otherReseller|admin
     * @param string[] $scopes The scopes the credential carries; the empty
     *                         array is a token that records none, which
     *                         Identity reads as a full one.
     * @throws InvalidArgumentException
     */
    public function identity(string $who, array $scopes = array()): Identity
    {
        $accounts = array(
            'customer'      => array('customer', 'user', 'reseller'),
            'sibling'       => array('sibling', 'user', 'reseller'),
            'otherCustomer' => array('otherCustomer', 'user', 'otherReseller'),
            'reseller'      => array('reseller', 'reseller', 'admin'),
            'otherReseller' => array('otherReseller', 'reseller', 'admin'),
            'admin'         => array('admin', 'admin', null)
        );

        if (!isset($accounts[$who])) {
            throw new InvalidArgumentException(sprintf('No fixture account "%s".', $who));
        }

        list($key, $type, $parent) = $accounts[$who];

        return new Identity(
            (int)$this->id($key),
            self::PREFIX . $key,
            $type,
            $parent === null ? null : (int)$this->id($parent),
            self::PREFIX . $key . '@example.test',
            $scopes,
            $scopes === array() ? null : 1
        );
    }

    /**
     * @return int|string
     */
    private function id(string $key)
    {
        if (!array_key_exists($key, $this->ids)) {
            throw new InvalidArgumentException(sprintf(
                'The fixture has no "%s"; call seed() first.', $key
            ));
        }

        return $this->ids[$key];
    }

    private function account(string $name, string $type, int $createdBy, int $now): int
    {
        return $this->insert('admin', array(
            'admin_name'    => $name,
            'admin_pass'    => 'x',
            'admin_type'    => $type,
            'admin_sys_uid' => 0,
            'admin_sys_gid' => 0,
            'domain_created'=> $now,
            'customer_id'   => 'REF-' . $name,
            'created_by'    => $createdBy,
            'fname'         => 'Test',
            'lname'         => ucfirst($type),
            'gender'        => 'U',
            'firm'          => 'Test Ltd',
            'zip'           => '1234',
            'city'          => 'Testville',
            'state'         => 'Testshire',
            'country'       => 'NZ',
            'email'         => $name . '@example.test',
            'phone'         => '+64 3 000 0000',
            'fax'           => null,
            'street1'       => '1 Test Street',
            'street2'       => null,
            'admin_status'  => 'ok'
        ));
    }

    private function resellerProps(int $resellerId, int $ipId): void
    {
        $this->insert('reseller_props', array(
            'reseller_id'          => $resellerId,
            'current_dmn_cnt'      => 0,
            'max_dmn_cnt'          => 20,
            'current_sub_cnt'      => 0,
            'max_sub_cnt'          => 100,
            'current_als_cnt'      => 0,
            'max_als_cnt'          => 50,
            'current_mail_cnt'     => 0,
            'max_mail_cnt'         => 0,          // unlimited
            'current_ftp_cnt'      => 0,
            'max_ftp_cnt'          => 40,
            'current_sql_db_cnt'   => 0,
            'max_sql_db_cnt'       => 30,
            'current_sql_user_cnt' => 0,
            'max_sql_user_cnt'     => 30,
            'current_disk_amnt'    => 0,
            'max_disk_amnt'        => 51200,      // MiB
            'current_traff_amnt'   => 0,
            'max_traff_amnt'       => 102400,     // MiB
            'support_system'       => 'yes',
            'reseller_ips'         => $ipId . ';'
        ));
    }

    private function domain(int $adminId, string $name, int $now): int
    {
        return $this->insert('domain', array(
            'domain_name'          => $name,
            'domain_admin_id'      => $adminId,
            'domain_created'       => $now,
            'domain_expires'       => $now + 31536000,
            'domain_last_modified' => $now,
            'domain_mailacc_limit' => 0,          // unlimited
            'domain_ftpacc_limit'  => -1,         // withheld
            'domain_traffic_limit' => 10240,      // MiB
            'domain_sqld_limit'    => 2,
            'domain_sqlu_limit'    => 2,
            'domain_status'        => 'ok',
            'domain_alias_limit'   => 5,
            'domain_subd_limit'    => 10,
            'domain_ip_id'         => $this->ids['ip'],
            'domain_disk_limit'    => 5120,       // MiB
            'domain_disk_usage'    => 1048576,    // bytes
            'domain_disk_file'     => 524288,
            'domain_disk_mail'     => 262144,
            'domain_disk_sql'      => 262144,
            'domain_php'           => 'yes',
            'domain_cgi'           => 'yes',
            'allowbackup'          => 'dmn|sql|mail',
            'domain_dns'           => 'yes',
            'phpini_perm_system'   => 'yes',
            'phpini_perm_allow_url_fopen'   => 'yes',
            'phpini_perm_display_errors'    => 'no',
            'phpini_perm_disable_functions' => 'no',
            'phpini_perm_mail_function'     => 'yes',
            'domain_external_mail' => 'yes',
            'external_mail'        => 'off',
            'web_folder_protection' => 'yes',
            'mail_quota'           => 1073741824,  // bytes
            'document_root'        => '/htdocs',
            'url_forward'          => 'no',
            'host_forward'         => 'Off',
            'wildcard_alias'       => 'no'
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return int The auto-increment identifier, or 0 for a table without one.
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (' . $this->db->placeholders(count($columns)) . ')';

        // Not Db::rows(): fetchAll() on a statement with no result set is not
        // something every PDO driver tolerates.
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute(array_values($row));

        return (int)$this->db->pdo()->lastInsertId();
    }
}
