<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Repository;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use PHPUnit\Framework\TestCase;

/**
 * A Db that answers from a canned table rather than a database, and records
 * every query it was asked for.
 */
class FakeDb extends Db
{
    /** @var array<string, array> Keyed by a distinctive fragment of the query. */
    public $canned = array();

    /** @var array<int, array{0: string, 1: array}> */
    public $log = array();

    public function __construct()
    {
        // No connection: every method used here is overridden.
    }

    public function rows(string $sql, array $bind = array()): array
    {
        $this->log[] = array($sql, $bind);

        foreach ($this->canned as $needle => $rows) {
            if (strpos($sql, $needle) !== false) {
                return $rows;
            }
        }

        return array();
    }

    public function placeholders(int $n): string
    {
        return $n < 1 ? '' : rtrim(str_repeat('?,', $n), ',');
    }
}

class CountsTest extends TestCase
{
    /** @var FakeDb */
    private $db;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
    }

    private function counts(bool $countDefaults = true): Counts
    {
        return new Counts($this->db, $countDefaults);
    }

    public function testSubdomainsSumsBothTables(): void
    {
        // A customer's subdomain allowance covers subdomains of the main
        // domain and subdomains of its aliases alike: Counting.php:467.
        $this->db->canned = array(
            'FROM subdomain WHERE'      => array(array('k' => 4, 'n' => 2)),
            'FROM subdomain_alias AS s' => array(array('k' => 4, 'n' => 3))
        );

        self::assertSame(array(4 => 5), $this->counts()->subdomains(array(4)));
    }

    public function testEveryRequestedIdIsPresentEvenWithNoRows(): void
    {
        // A resolver must not have to distinguish "no rows" from "not asked".
        self::assertSame(
            array(4 => 0, 9 => 0),
            $this->counts()->subdomains(array(4, 9))
        );
    }

    public function testAnEmptyIdListIssuesNoQuery(): void
    {
        self::assertSame(array(), $this->counts()->subdomains(array()));
        self::assertSame(array(), $this->db->log);
    }

    public function testDomainAliasesExcludeOrderedAndDeleting(): void
    {
        // Counting.php:497 - an ordered alias is not yet the customer's.
        $this->counts()->domainAliases(array(4));

        self::assertStringContainsString(
            "alias_status NOT IN('ordered', 'todelete')", $this->db->log[0][0]
        );
    }

    public function testMailAccountsCountDefaultsWhenTheServerSaysSo(): void
    {
        $this->counts(true)->mailAccounts(array(4));

        self::assertStringNotContainsString('hostmaster', $this->db->log[0][0]);
    }

    public function testMailAccountsExcludeDefaultsWhenTheServerSaysSo(): void
    {
        // Counting.php:518 - COUNT_DEFAULT_EMAIL_ADDRESSES decides whether the
        // abuse/hostmaster/postmaster/webmaster forwards count against a limit.
        $this->counts(false)->mailAccounts(array(4));

        $sql = $this->db->log[0][0];
        self::assertStringContainsString('hostmaster', $sql);
        self::assertStringContainsString('normal_forward', $sql);
        self::assertStringContainsString('alssub_forward', $sql);
    }

    public function testSqlUsersCountDistinctNames(): void
    {
        // Counting.php:586 - one SQL user may be granted on several databases
        // and still counts once against the limit.
        $this->counts()->sqlUsers(array(4));

        self::assertStringContainsString('COUNT(DISTINCT sqlu_name)', $this->db->log[0][0]);
    }

    public function testSqlDatabasesHaveNoStatusFilter(): void
    {
        // sql_database has no status column: it is created synchronously by
        // the panel. Spec section 2.1.
        $this->counts()->sqlDatabases(array(4));

        self::assertStringNotContainsString('todelete', $this->db->log[0][0]);
    }

    public function testFtpUsersAreKeyedByCustomerNotDomain(): void
    {
        // ftp_users.admin_id is the customer, not the domain. Spec section 2.2.
        $this->db->canned = array('FROM ftp_users' => array(array('k' => 7, 'n' => 3)));

        self::assertSame(array(7 => 3), $this->counts()->ftpUsers(array(7)));
        self::assertStringContainsString('admin_id IN', $this->db->log[0][0]);
    }

    public function testOneQueryPerRuleWhateverTheNumberOfCustomers(): void
    {
        // The whole point of this class.
        $this->counts()->mailAccounts(array(1, 2, 3, 4, 5, 6, 7, 8));

        self::assertCount(1, $this->db->log);
        self::assertSame(array(1, 2, 3, 4, 5, 6, 7, 8), $this->db->log[0][1]);
    }
}
