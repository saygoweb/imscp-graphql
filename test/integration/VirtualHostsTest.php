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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class VirtualHostsTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var VirtualHosts */
    private $vhosts;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->vhosts = new VirtualHosts($this->db);
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    public function testForDomainsReturnsAllFourKinds(): void
    {
        $rows = $this->vhosts->forDomains(array($this->fixture->domainId()));
        $tags = array();

        foreach ($rows[$this->fixture->domainId()] as $row) {
            $tags[] = $row['tag'];
        }

        sort($tags);
        self::assertSame(
            array(
                NodeType::ALIAS_SUBDOMAIN, NodeType::DOMAIN,
                NodeType::DOMAIN_ALIAS, NodeType::SUBDOMAIN
            ),
            $tags
        );
    }

    public function testASubdomainNameIsTheLabelJoinedToItsParent(): void
    {
        // The reason subdomains do not come through an Anorm relationship: the
        // subdomain table stores 'shop', not 'shop.example.test', and a
        // single-table IN-clause SELECT cannot produce the difference.
        $rows = $this->vhosts->ofKind(
            VirtualHosts::KIND_SUB, array($this->fixture->domainId())
        );
        $row = $rows[$this->fixture->domainId()][0];

        self::assertSame('shop', $row['label']);
        self::assertSame($this->fixture->subdomainName(), $row['name']);
        self::assertSame(NodeType::DOMAIN, $row['parentTag']);
        self::assertSame($this->fixture->domainId(), $row['parentKey']);
    }

    public function testAnAliasSubdomainNameIsJoinedToItsAliasNotItsDomain(): void
    {
        $rows = $this->vhosts->aliasSubdomainsOf(array($this->fixture->aliasId()));
        $row = $rows[$this->fixture->aliasId()][0];

        self::assertSame('blog.' . $this->fixture->aliasName(), $row['name']);
        self::assertSame(NodeType::DOMAIN_ALIAS, $row['parentTag']);
        self::assertSame($this->fixture->aliasId(), $row['parentKey']);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $row['tag']);
    }

    public function testEveryRowCarriesTheOwningCustomer(): void
    {
        // Without this the resolvers would have to re-resolve ownership per
        // vhost, which is a query per row.
        foreach ($this->vhosts->forDomains(array($this->fixture->domainId())) as $rows) {
            foreach ($rows as $row) {
                self::assertSame($this->fixture->customerId(), $row['ownerId'], $row['tag']);
                self::assertSame($this->fixture->domainId(), $row['domainId'], $row['tag']);
            }
        }
    }

    public function testTheMainDomainIsMountedAtTheRoot(): void
    {
        $rows = $this->vhosts->ofKind(
            VirtualHosts::KIND_DMN, array($this->fixture->domainId())
        );
        $row = $rows[$this->fixture->domainId()][0];

        self::assertSame('/', $row['mountPoint']);
        self::assertSame('/htdocs', $row['documentRoot']);
        self::assertSame($this->fixture->ipId(), $row['ipId']);
        self::assertFalse($row['wildcard']);
    }

    public function testForwardingColumnsComeThroughUntouched(): void
    {
        $rows = $this->vhosts->ofKind(
            VirtualHosts::KIND_ALS, array($this->fixture->domainId())
        );
        $row = $rows[$this->fixture->domainId()][0];

        self::assertSame('https://example.net/', $row['urlForward']);
        self::assertSame('301', $row['typeForward']);
        self::assertSame('Off', $row['hostForward']);
    }

    public function testWildcardIsABooleanNotTheEnumString(): void
    {
        $rows = $this->vhosts->aliasSubdomainsOf(array($this->fixture->aliasId()));

        self::assertTrue($rows[$this->fixture->aliasId()][0]['wildcard']);
    }

    public function testByKeysLooksUpOneVhostOfEachKind(): void
    {
        $found = $this->vhosts->byKeys(array(
            array(VirtualHosts::KIND_DMN, $this->fixture->domainId()),
            array(VirtualHosts::KIND_SUB, $this->fixture->subdomainId()),
            array(VirtualHosts::KIND_ALS, $this->fixture->aliasId()),
            array(VirtualHosts::KIND_ALSSUB, $this->fixture->aliasSubdomainId())
        ));

        self::assertCount(4, $found);
        self::assertSame(
            $this->fixture->domainName(),
            $found[VirtualHosts::KIND_DMN . ':' . $this->fixture->domainId()]['name']
        );
        self::assertSame(
            'blog.' . $this->fixture->aliasName(),
            $found[VirtualHosts::KIND_ALSSUB . ':' . $this->fixture->aliasSubdomainId()]['name']
        );
    }

    public function testByKeysIssuesOneQueryPerKindAndNoMore(): void
    {
        // Four kinds, four queries, whatever the number of identifiers. A
        // per-identifier lookup here would be the N+1 behind MailAccount.host.
        $keys = array();

        for ($i = 0; $i < 6; $i++) {
            $keys[] = array(VirtualHosts::KIND_DMN, $this->fixture->domainId());
            $keys[] = array(VirtualHosts::KIND_SUB, $this->fixture->subdomainId());
        }

        $vhosts = $this->vhosts;
        $count = $this->db->countQueries(function () use ($vhosts, $keys) {
            $vhosts->byKeys($keys);
        });

        self::assertSame(2, $count);
    }

    public function testPendingFindsOnlyTheUnsettledObject(): void
    {
        // The fixture seeds exactly one object with a pending status: the alias
        // subdomain, at 'toadd'.
        $pending = $this->vhosts->pendingFor(array($this->fixture->customerId()));

        self::assertCount(1, $pending);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $pending[0]['tag']);
        self::assertSame($this->fixture->aliasSubdomainId(), (int)$pending[0]['key']);
        self::assertSame('toadd', $pending[0]['status']);
    }

    public function testPendingSeesAMailAccountTurningPending(): void
    {
        $this->db->pdo()
            ->prepare('UPDATE mail_users SET status = ? WHERE mail_id = ?')
            ->execute(array('tochange', $this->fixture->mailboxId()));

        $tags = array();

        foreach ($this->vhosts->pendingFor(array($this->fixture->customerId())) as $row) {
            $tags[] = $row['tag'];
        }

        sort($tags);
        self::assertSame(array(NodeType::ALIAS_SUBDOMAIN, NodeType::MAIL_ACCOUNT), $tags);
    }

    public function testAnEmptyIdentifierListIssuesNoQuery(): void
    {
        $vhosts = $this->vhosts;
        $count = $this->db->countQueries(function () use ($vhosts) {
            $vhosts->forDomains(array());
            $vhosts->byKeys(array());
            $vhosts->aliasSubdomainsOf(array());
            $vhosts->pendingFor(array());
        });

        self::assertSame(0, $count);
    }
}
