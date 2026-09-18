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

use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;

class QueryCountTest extends IntegrationTestCase
{
    /**
     * The representative deep document of spec section 10.1.
     *
     * It selects every edge that costs a loader bucket of its own: the
     * customer list, the whole vhost tree below it including an alias's
     * subdomains, both paged connections, both directions of the SQL grant,
     * DNS with its host edge, the three value blocks that each cost their own
     * batched query, and Domain.createdAt. If an edge is not in here it is not
     * covered by the N+1 assertion, which is why this is a constant rather
     * than a literal buried in one method.
     */
    const DOCUMENT = '
        query Deep($page: PageInput) {
          customers(page: $page) {
            totalCount
            nodes {
              id
              username
              provisioning { state settled }
              apiAccess
              contact { firstName email }
              reseller { id username }
              quotas {
                subdomains { enabled limit used remaining }
                domainAliases { used }
                mailAccounts { used }
                ftpUsers { enabled used }
                sqlDatabases { used }
                sqlUsers { used }
              }
              storage { diskLimit diskUsed diskFiles diskMail diskSql trafficUsed }
              features { php phpEditor cgi customDns backup supportSystem }
              domain {
                id
                name
                mountPoint
                createdAt
                expiresAt
                forwarding { url type keepHost }
                provisioning { state }
                ipAddress { id address netmask }
                subdomains { id name label }
                aliases {
                  id
                  name
                  forwarding { url type }
                  subdomains { id name label }
                }
              }
              subdomains { id name }
              domainAliases { id name }
              mailAccounts {
                totalCount
                nodes {
                  id
                  address
                  kind
                  forwardTo
                  quota
                  active
                  autoresponder { enabled message }
                  host { id name }
                }
              }
              ftpUsers { totalCount nodes { id username homeDirectory } }
              sqlDatabases { id name users { id name host } }
              sqlUsers { id name host databases { id name } }
              dnsRecords { id name class type value ownedBy host { id name } }
            }
          }
        }
    ';

    /**
     * The measured cost of DOCUMENT.
     *
     * Pinned to what the reference box actually does - see Task 17 of the
     * phase-2 plan, whose derivation is reproduced in COST below. It is an
     * exact assertion rather than a ceiling with slack, because slack is
     * where an N+1 hides: one extra query per level is invisible under a
     * generous ceiling and obvious against a number.
     */
    const CEILING = 31;

    /**
     * Where CEILING comes from, query by query.
     *
     * Measured with a query log attached to Db::rows(), not derived: the
     * phase-2 plan predicted 27 and the box does 31, and the four extra are
     * accounted for below. A number nobody can account for is a snapshot, not
     * a threshold.
     *
     * A bucket flushes once per *level* at which it is asked, not once per
     * request: two levels asking the same bucket for the same key cost one
     * query because keyed() memoises, and two levels asking it for different
     * keys cost two. That is correct batching, not an N+1 - and
     * testTheCostDoesNotGrowWithTheNumberOfCustomers is what tells the two
     * apart, because neither number moves with the number of customers.
     *
     * Two buckets cost more than one query each, and they are the whole of
     * the four-query gap between the prediction and the measurement:
     *
     *   - counts:subdomains adds up `subdomain` and `subdomain_alias`, in two
     *     queries rather than one, because i-MSCP counts a subdomain of an
     *     alias as a subdomain. Same finding as Task 13's, which corrected an
     *     assertion of 6 to 7 for the same reason.
     *   - vhost:all-by-domain is VirtualHosts::forDomains(), which emits one
     *     SELECT per vhost kind - dmn, sub, als, alssub - and normalises the
     *     four result sets in PHP. Four queries, one level. Same finding as
     *     Task 13's, which corrected an assertion of 1 to 4.
     *
     * Two edges are free, and worth saying so because a reader counting
     * fields would expect to find them here:
     *
     *   - DnsRecord.host costs nothing. Every fixture DNS record hangs off the
     *     main domain, whose vhost:dmn key MailAccount.host has already asked
     *     for, and BatchLoader memoises it. If this ever becomes a query the
     *     memoisation has stopped working, which is a BatchLoader defect and
     *     not a number to accept.
     *   - Customer.contact costs nothing: the contact columns come down with
     *     customer:row's joined admin row rather than in a second query.
     *
     * @var array<string, array{0: int, 1: string}> bucket => queries, opener
     */
    const COST = array(
        'reseller:customer-ids:<filter>' => array(1, 'Query.customers - the admin_ids this reseller owns, in order'),
        'customer:row'                   => array(1, 'CustomerResolver::references(); Customer.contact rides along on the joined admin row'),
        'customer:api-perm'              => array(1, 'Customer.apiAccess'),
        'reseller:row'                   => array(1, 'Customer.reseller'),
        'counts:subdomains'              => array(2, 'Customer.quotas - subdomain plus subdomain_alias, added up in PHP'),
        'counts:aliases'                 => array(1, 'Customer.quotas'),
        'counts:mail'                    => array(1, 'Customer.quotas'),
        'counts:sqldb'                   => array(1, 'Customer.quotas'),
        'counts:sqlusers'                => array(1, 'Customer.quotas'),
        'counts:ftp'                     => array(1, 'Customer.quotas'),
        'domain:traffic'                 => array(1, 'Customer.storage'),
        'reseller:support-system'        => array(1, 'Customer.features'),
        'vhost:all-by-domain'            => array(4, 'Customer.domain, .subdomains and .domainAliases together - one SELECT per vhost kind'),
        'mail:by-domain:<filter>'        => array(1, 'Customer.mailAccounts'),
        'ftp:by-admin'                   => array(1, 'Customer.ftpUsers'),
        'sqldb:by-domain'                => array(1, 'Customer.sqlDatabases'),
        'sqluser:by-domain'              => array(1, 'Customer.sqlUsers'),
        'dns:by-domain'                  => array(1, 'Customer.dnsRecords'),
        'domain:dates'                   => array(1, 'Domain.createdAt and .expiresAt together'),
        'ip:row'                         => array(1, 'Domain.ipAddress'),
        'vhost:by-domain:sub'            => array(1, 'Domain.subdomains'),
        'vhost:by-domain:als'            => array(1, 'Domain.aliases'),
        'vhost:dmn'                      => array(1, 'MailAccount.host for the mailbox, and DnsRecord.host for free after it'),
        'vhost:alssub'                   => array(1, 'MailAccount.host for the forward, which hangs off an alias subdomain'),
        'sqluser:by-database'            => array(1, 'SqlDatabase.users'),
        'sqldb:by-user-name'             => array(1, 'SqlUser.databases'),
        'vhost:alssub-by-alias'          => array(1, 'DomainAlias.subdomains')
    );

    /**
     * A fixed panel configuration, not the box's.
     *
     * Customer.features is in DOCUMENT and CustomerFeatures refuses to answer
     * without these five keys, so an empty configuration would make the
     * document error halfway - which is exactly the failure the first test
     * exists to rule out. Fixed rather than read from the box's Registry for
     * the reason CustomerResolverTest gives: the answer should be a statement
     * about the customer's row, not about today's server configuration.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return array(
            'NAMED_PACKAGE'          => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES' => 'Awstats',
            'BACKUP_DOMAINS'         => 'yes',
            'ENABLE_SSL'             => 1,
            'IMSCP_SUPPORT_SYSTEM'   => 1
        );
    }

    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    /**
     * One request's schema, with a BatchLoader of its own.
     *
     * A fresh container per document, not one shared across the whole test
     * case. The loader memoises for the life of the container and the
     * container is built per HTTP request in production - SGW_GraphQL.php's
     * route closure calls Container::fromPlugin() inside the request - so a
     * shared one would let the second document in a test read the first
     * document's answers for free and report a cost no real request pays.
     * That is not a hypothetical: measured against a shared container the
     * same document cost 31 queries cold and 23 warm, which reads as an
     * improvement and is the opposite of one.
     */
    private function schema(): \GraphQL\Type\Schema
    {
        $container = Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db,
            $this->config()
        );

        return $container->schemaFactory()->create();
    }

    /**
     * @param array<string, mixed> $variables
     * @return array{0: int, 1: array} query count, response
     */
    private function execute(array $variables): array
    {
        // Built before the window opens, not inside it: parsing the SDL - or
        // reading the AST cache the panel's CACHE_PATH holds - is file I/O
        // rather than a query, but nothing outside the document's own cost
        // belongs in the measurement.
        $schema = $this->schema();
        $context = array('identity' => $this->fixture->identity('reseller'));
        $response = null;

        $count = $this->db->countQueries(
            function () use ($schema, $context, $variables, &$response) {
                $result = GraphQL::executeQuery(
                    $schema, self::DOCUMENT, null, $context, $variables
                );
                $response = $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
            }
        );

        return array($count, $response);
    }

    public function testTheDeepDocumentSucceeds(): void
    {
        // First, because a document that fails halfway issues fewer queries
        // and would pass a count assertion for the worst possible reason.
        list(, $response) = $this->execute(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );
        self::assertSame(2, $response['data']['customers']['totalCount']);

        $node = $this->customerNode($response);

        self::assertNotNull($node['domain']['name']);
        self::assertNotNull($node['storage']['diskUsed']);
        self::assertNotNull($node['quotas']['subdomains']['used']);
    }

    public function testTheDeepDocumentReachesEveryEdgeTheCountIsMadeOf(): void
    {
        // The count only covers a bucket the document actually opens, and a
        // bucket is only opened by a non-empty edge. If the fixture ever stops
        // seeding one of these, the corresponding query stops being issued and
        // CEILING would have to fall - which would read as an improvement
        // rather than as lost coverage. This is the test that says otherwise.
        list(, $response) = $this->execute(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );

        $node = $this->customerNode($response);

        self::assertNotSame(array(), $node['domain']['subdomains']);
        self::assertNotSame(array(), $node['domain']['aliases']);
        self::assertNotSame(array(), $node['domain']['aliases'][0]['subdomains']);
        self::assertNotSame(array(), $node['mailAccounts']['nodes']);
        self::assertNotSame(array(), $node['ftpUsers']['nodes']);
        self::assertNotSame(array(), $node['sqlDatabases']);
        self::assertNotSame(array(), $node['sqlDatabases'][0]['users']);
        self::assertNotSame(array(), $node['sqlUsers']);
        self::assertNotSame(array(), $node['sqlUsers'][0]['databases']);
        self::assertNotSame(array(), $node['dnsRecords']);
        self::assertNotNull($node['domain']['ipAddress']['address']);
        self::assertNotNull($node['domain']['createdAt']);
    }

    public function testTheDeepDocumentCostsTheMeasuredNumberOfQueries(): void
    {
        list($count, $response) = $this->execute(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey('errors', $response);
        self::assertSame(
            self::CEILING,
            $count,
            'The deep document changed cost. If a field was added, update '
                . 'CEILING and the COST table with it and say so in the '
                . 'commit; if not, something started querying per row.'
        );
    }

    public function testTheMeasuredNumberIsAccountedForQueryByQuery(): void
    {
        // Cheap, and the point of it is the pressure it applies: a CEILING
        // raised without saying which bucket grew fails here rather than
        // passing quietly.
        $accounted = 0;

        foreach (self::COST as $entry) {
            $accounted += $entry[0];
        }

        self::assertSame(
            self::CEILING,
            $accounted,
            'CEILING and COST disagree, so the number is no longer accounted '
                . 'for query by query. Name the bucket that moved.'
        );
    }

    public function testTheCostDoesNotGrowWithTheNumberOfCustomers(): void
    {
        // This is the N+1 assertion, and the only one of the four that an
        // N+1 cannot pass. A resolver that queries per customer costs one more
        // query per customer, so twelve customers cost eleven more than one -
        // and the ceiling above would not notice, because with the fixture's
        // two customers an N+1 is almost free.
        list($one, $first) = $this->execute(array('page' => array('limit' => 1)));

        self::assertArrayNotHasKey('errors', $first);
        self::assertCount(1, $first['data']['customers']['nodes']);

        // And it is the seeded customer, not the sibling. The list is ordered
        // by admin_name and the sibling owns nothing below its domain, so a
        // page of one holding the sibling would open fewer buckets and the
        // comparison below would be between two different documents.
        self::assertSame(
            Fixture::PREFIX . 'customer',
            $first['data']['customers']['nodes'][0]['username']
        );

        $this->addCustomers(10);

        list($twelve, $response) = $this->execute(array('page' => array('limit' => 50)));

        self::assertArrayNotHasKey('errors', $response);
        self::assertSame(12, $response['data']['customers']['totalCount']);
        self::assertCount(12, $response['data']['customers']['nodes']);
        self::assertSame(
            $one,
            $twelve,
            'Reading twelve customers cost more than reading one. That is an '
                . 'N+1: some resolver is querying per parent instead of per level.'
        );
    }

    /**
     * The seeded customer's node, whichever position the list returned it in.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function customerNode(array $response): array
    {
        foreach ($response['data']['customers']['nodes'] as $node) {
            if ($node['username'] === Fixture::PREFIX . 'customer') {
                return $node;
            }
        }

        self::fail('The deep document did not return the fixture customer.');
    }

    /**
     * $count more customers of the same reseller, each a full copy of the
     * fixture's own tree.
     *
     * Copying the seeded rows and changing the few columns that have to be
     * unique, rather than writing fresh INSERTs: `domain` alone has forty-odd
     * NOT NULL columns and a second hand-written copy of that row would drift
     * from Fixture's within a release.
     *
     * Every object is cloned, not just the account and its domain. A customer
     * with a bare domain would leave the levels below Customer with a single
     * parent however many customers there were, so an alias that queried its
     * subdomains one alias at a time would still cost one query and the N+1
     * assertion would not see it. With the whole tree cloned, twelve
     * customers mean twelve aliases, twelve mailboxes and twelve databases,
     * and every level of the document has many parents at once.
     *
     * Everything happens inside the fixture's transaction and is rolled back
     * with it.
     */
    private function addCustomers(int $count): void
    {
        $admin = $this->rowOf('admin', 'admin_id', $this->fixture->customerId());
        $domain = $this->rowOf('domain', 'domain_id', $this->fixture->domainId());
        $subdomain = $this->rowOf('subdomain', 'subdomain_id', $this->fixture->subdomainId());
        $alias = $this->rowOf('domain_aliasses', 'alias_id', $this->fixture->aliasId());
        $aliasSub = $this->rowOf(
            'subdomain_alias', 'subdomain_alias_id', $this->fixture->aliasSubdomainId()
        );
        $mailbox = $this->rowOf('mail_users', 'mail_id', $this->fixture->mailboxId());
        $forward = $this->rowOf('mail_users', 'mail_id', $this->fixture->forwardId());
        $ftpUser = $this->rowOf('ftp_users', 'userid', $this->fixture->ftpUserId());
        $database = $this->rowOf('sql_database', 'sqld_id', $this->fixture->sqlDatabaseId());
        $sqlUser = $this->rowOf('sql_user', 'sqlu_id', $this->fixture->sqlUserId());
        $dnsRecord = $this->rowOf('domain_dns', 'domain_dns_id', $this->fixture->dnsRecordId());

        for ($i = 0; $i < $count; $i++) {
            $tag = Fixture::PREFIX . 'bulk' . $i;
            $name = $tag . '.test';
            $aliasName = $tag . 'alias.test';

            $admin['admin_name'] = $tag;
            $admin['email'] = $tag . '@example.test';
            $admin['customer_id'] = 'REF-' . $tag;
            $adminId = $this->insert('admin', $admin);

            $domain['domain_admin_id'] = $adminId;
            $domain['domain_name'] = $name;
            $domainId = $this->insert('domain', $domain);

            $subdomain['domain_id'] = $domainId;
            $this->insert('subdomain', $subdomain);

            $alias['domain_id'] = $domainId;
            $alias['alias_name'] = $aliasName;
            $aliasId = $this->insert('domain_aliasses', $alias);

            $aliasSub['alias_id'] = $aliasId;
            $aliasSubId = $this->insert('subdomain_alias', $aliasSub);

            $mailbox['domain_id'] = $domainId;
            $mailbox['mail_addr'] = 'sales@' . $name;
            $this->insert('mail_users', $mailbox);

            $forward['domain_id'] = $domainId;
            $forward['sub_id'] = $aliasSubId;
            $forward['mail_addr'] = 'hello@blog.' . $aliasName;
            $this->insert('mail_users', $forward);

            $ftpUser['userid'] = $tag . 'ftp@' . $name;
            $ftpUser['admin_id'] = $adminId;
            $this->insert('ftp_users', $ftpUser);

            $database['domain_id'] = $domainId;
            $database['sqld_name'] = $tag . '_shop';
            $databaseId = $this->insert('sql_database', $database);

            $sqlUser['sqld_id'] = $databaseId;
            $sqlUser['sqlu_name'] = $tag . '_u1';
            $this->insert('sql_user', $sqlUser);

            $dnsRecord['domain_id'] = $domainId;
            $this->insert('domain_dns', $dnsRecord);
        }
    }

    /**
     * One row by its primary key, with that key removed so it can be inserted
     * back as a new row.
     *
     * @param int|string $id
     * @return array<string, mixed>
     */
    private function rowOf(string $table, string $key, $id): array
    {
        $row = $this->db->row(
            'SELECT * FROM `' . $table . '` WHERE `' . $key . '` = ?', array($id)
        );

        self::assertNotNull($row, sprintf('No %s row to clone.', $table));
        unset($row[$key]);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`)'
                . ' VALUES (' . $this->db->placeholders(count($columns)) . ')'
        );
        $statement->execute(array_values($row));

        return (int)$this->db->pdo()->lastInsertId();
    }
}
