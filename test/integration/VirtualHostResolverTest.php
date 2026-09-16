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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\VirtualHostResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

class VirtualHostResolverTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture */
    private $fixture;

    /** @var BatchLoader */
    private $loader;

    /** @var VirtualHostResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();
        $this->loader = new BatchLoader($this->db);
        $this->resolver = new VirtualHostResolver(
            $this->loader,
            new VirtualHosts($this->db),
            $this->db,
            'decode_idna',
            static function (int $adminId) {
                return new \GraphQL\Deferred(static function () use ($adminId) {
                    return array('__tag' => NodeType::CUSTOMER, '__key' => $adminId);
                });
            },
            static function (int $ipId) {
                return new \GraphQL\Deferred(static function () use ($ipId) {
                    return array('__tag' => NodeType::IP_ADDRESS, '__key' => $ipId);
                });
            }
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->rollBack();
    }

    private function context(): array
    {
        // The fixture's identities record no scopes, and Identity::hasScope()
        // answers true for all of them when none are recorded. narrowContext()
        // below is how this file tests a narrowed token.
        return array('identity' => $this->fixture->identity('customer'));
    }

    private function narrowContext(): array
    {
        $customer = $this->fixture->identity('customer');

        return array('identity' => new Identity(
            $customer->getAdminId(), $customer->getUsername(), 'user',
            $customer->getCreatedBy(), $customer->getEmail(),
            array(\iMSCP\Plugin\SGW_GraphQL\Auth\Scope::MAIL_READ), 1
        ));
    }

    private function info(): \GraphQL\Type\Definition\ResolveInfo
    {
        return $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
    }

    private function value($deferred)
    {
        SyncPromise::runQueue();

        return $deferred->result;
    }

    private function domain(): array
    {
        return $this->value($this->resolver->forDomain($this->fixture->domainId()));
    }

    public function testTheMainDomainShapesFromTheDatabase(): void
    {
        $domain = $this->domain();

        self::assertSame($this->fixture->domainName(), $domain['name']);
        self::assertSame('/', $domain['mountPoint']);
        self::assertSame('/htdocs', $domain['documentRoot']);
        self::assertFalse($domain['wildcard']);
        self::assertNull($domain['forwarding']);
        self::assertSame('OK', $domain['provisioning']['state']);
    }

    public function testCreatedAtComesFromTheDomainRow(): void
    {
        $domain = $this->domain();

        self::assertSame('2026-01-01T00:00:00Z', $this->value(
            $this->resolver->resolveCreatedAt($domain, array(), $this->context(), $this->info())
        ));
    }

    public function testSubdomainsAndAliasesAreSeparateLists(): void
    {
        $domain = $this->domain();

        $subdomains = $this->value($this->resolver->resolveDomainSubdomains(
            $domain, array(), $this->context(), $this->info()
        ));
        $aliases = $this->value($this->resolver->resolveDomainAliases(
            $domain, array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $subdomains);
        self::assertSame($this->fixture->subdomainName(), $subdomains[0]['name']);
        self::assertCount(1, $aliases);
        self::assertSame($this->fixture->aliasName(), $aliases[0]['name']);
        self::assertSame('https://example.net/', $aliases[0]['forwarding']['url']);
        self::assertSame('PERMANENT_301', $aliases[0]['forwarding']['type']);
        self::assertFalse($aliases[0]['forwarding']['keepHost']);
    }

    public function testAnAliasSubdomainsParentIsTheAliasNotTheDomain(): void
    {
        // The whole point of decision D1: an alias subdomain is a Subdomain
        // whose parent is a DomainAlias. Resolving its parent to the main
        // domain would put it in the wrong place in every client's tree.
        $domain = $this->domain();
        $aliases = $this->value($this->resolver->resolveDomainAliases(
            $domain, array(), $this->context(), $this->info()
        ));
        $subdomains = $this->value($this->resolver->resolveAliasSubdomains(
            $aliases[0], array(), $this->context(), $this->info()
        ));

        self::assertCount(1, $subdomains);
        self::assertSame(NodeType::ALIAS_SUBDOMAIN, $subdomains[0]['__tag']);

        $parent = $this->value($this->resolver->resolveParent(
            $subdomains[0], array(), $this->context(), $this->info()
        ));

        self::assertSame(NodeType::DOMAIN_ALIAS, $parent['__tag']);
        self::assertSame($this->fixture->aliasName(), $parent['name']);
    }

    public function testTheSubdomainsOfThreeDistinctDomainsCostOneQuery(): void
    {
        // The N+1 this class exists to prevent, and it has to be three
        // *different* domains: six copies of one id would only prove that
        // BatchLoader de-duplicates a repeated key, which is a weaker and
        // much easier property than batching. A resolver that queried per
        // parent costs three here and cannot pass.
        $ids = array(
            $this->fixture->domainId(),
            $this->fixture->siblingDomainId(),
            $this->fixture->otherDomainId()
        );
        $resolver = $this->resolver;
        $context = $this->context();
        $info = $this->info();

        $count = $this->db->countQueries(function () use ($resolver, $ids, $context, $info) {
            foreach ($ids as $id) {
                $resolver->resolveDomainSubdomains(
                    array('__domainId' => $id), array(), $context, $info
                );
            }

            SyncPromise::runQueue();
        });

        self::assertSame(1, $count);
    }

    public function testAReferenceToFourKindsCostsFourQueriesRegardlessOfCount(): void
    {
        // One query per vhost kind, not one per identifier: the three distinct
        // domains go into a single IN clause, and asking for each of them
        // twice adds nothing on top because the loader memoises what it has
        // already answered.
        $resolver = $this->resolver;
        $fixture = $this->fixture;
        $domains = array(
            $fixture->domainId(),
            $fixture->siblingDomainId(),
            $fixture->otherDomainId()
        );

        $count = $this->db->countQueries(function () use ($resolver, $fixture, $domains) {
            for ($i = 0; $i < 2; $i++) {
                foreach ($domains as $domainId) {
                    $resolver->reference(NodeType::DOMAIN, $domainId);
                }

                $resolver->reference(NodeType::SUBDOMAIN, $fixture->subdomainId());
                $resolver->reference(NodeType::DOMAIN_ALIAS, $fixture->aliasId());
                $resolver->reference(
                    NodeType::ALIAS_SUBDOMAIN, $fixture->aliasSubdomainId()
                );
            }

            SyncPromise::runQueue();
        });

        self::assertSame(4, $count);
    }

    public function testANarrowedTokenIsRefusedRatherThanGivenAnEmptyList(): void
    {
        // The fail-closed half. A MAIL_READ-only token asking for subdomains
        // must be told no, not handed an empty list it would render as "this
        // customer has no subdomains".
        $domain = $this->domain();

        try {
            $this->resolver->resolveDomainSubdomains(
                $domain, array(), $this->narrowContext(), $this->info()
            );
            self::fail('a token without DOMAINS_READ must be refused');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        }
    }
}
