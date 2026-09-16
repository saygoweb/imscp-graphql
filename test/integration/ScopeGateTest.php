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
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Resolver\QueryResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use Throwable;

/**
 * No field of a scope-bearing type may be read without that type's scope.
 *
 * Spec section 17's resolver-coverage test proves every non-leaf field has a
 * map entry; nothing proved that the entry - or the fallback that stands in
 * for one - asks for a scope. That gap is how three defects of the same shape
 * got in: Domain.createdAt and .expiresAt skipping their gate (caught by hand
 * in review), and Query.node and Query.pending serving every plain value field
 * of every type with no scope check at all, because a field with no resolver
 * is read straight off the source array by SchemaFactory's fallback.
 *
 * So this asks the question from outside, through the executor, one type at a
 * time, and it enumerates rather than spot-checks: the subjects come from
 * NodeType's own tag lists and the fields from the schema, so a tag or a field
 * added later is covered by construction rather than by somebody remembering
 * this file exists.
 */
class ScopeGateTest extends IntegrationTestCase
{
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
     * Every tag the API can produce, with an identifier of one that exists.
     *
     * @return array<string, string> tag => global identifier
     */
    private function subjects(): array
    {
        $keys = array(
            NodeType::CUSTOMER        => $this->fixture->customerId(),
            NodeType::DOMAIN          => $this->fixture->domainId(),
            NodeType::SUBDOMAIN       => $this->fixture->subdomainId(),
            NodeType::ALIAS_SUBDOMAIN => $this->fixture->aliasSubdomainId(),
            NodeType::DOMAIN_ALIAS    => $this->fixture->aliasId(),
            NodeType::MAIL_ACCOUNT    => $this->fixture->mailboxId(),
            NodeType::FTP_USER        => $this->fixture->ftpUserId(),
            NodeType::SQL_DATABASE    => $this->fixture->sqlDatabaseId(),
            NodeType::SQL_USER        => $this->fixture->sqlUserId(),
            NodeType::DNS_RECORD      => $this->fixture->dnsRecordId(),
            NodeType::RESELLER        => $this->fixture->resellerId(),
            NodeType::HOSTING_PLAN    => $this->fixture->hostingPlanId(),
            NodeType::IP_ADDRESS      => $this->fixture->ipId()
        );
        $subjects = array();

        foreach ($keys as $tag => $key) {
            $subjects[$tag] = NodeType::isStringKeyed($tag)
                ? GlobalId::encodeKey($tag, (string)$key)
                : GlobalId::encode($tag, (int)$key);
        }

        return $subjects;
    }

    /** The tags VirtualHosts::pendingFor() can return. */
    private function pendingTags(): array
    {
        return array(
            NodeType::CUSTOMER, NodeType::DOMAIN, NodeType::SUBDOMAIN,
            NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN,
            NodeType::MAIL_ACCOUNT, NodeType::FTP_USER, NodeType::DNS_RECORD
        );
    }

    private function container(): Container
    {
        return Container::forTesting(
            dirname(__DIR__, 2),
            array(),
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; },
            $this->db
        );
    }

    /**
     * Every scope but this one. The caller is the administrator, so role never
     * refuses and what is left is the scope question on its own.
     *
     * @return string[]
     */
    private function allScopesBut(string $scope): array
    {
        $scopes = array();

        foreach (Scope::all() as $one) {
            if ($one !== $scope) {
                $scopes[] = $one;
            }
        }

        return $scopes;
    }

    /**
     * The scope the caller in these enumerations - the administrator - must
     * hold to be handed one subject.
     *
     * QueryResolver::SCOPES is a table of tags, but the Customer gate is per
     * object: ACCOUNT_READ reads the caller's own account and CUSTOMERS_READ
     * reads anybody else's, exactly as Query.customer has always required.
     * Every Customer among these subjects belongs to somebody else - the
     * caller is the administrator and the fixture's customer is not them - so
     * for that one tag the scope under test is CUSTOMERS_READ. The two tests
     * at the foot of this file drive the other half of that gate.
     */
    private function requiredScope(string $tag): string
    {
        return $tag === NodeType::CUSTOMER
            ? Scope::CUSTOMERS_READ
            : QueryResolver::SCOPES[$tag];
    }

    /**
     * @param string[] $scopes
     * @param string $who A Fixture account. The administrator by default,
     *                    because these tests are about scopes and the
     *                    administrator is the caller whose *role* never
     *                    refuses; the Customer gate below is the one question
     *                    where whose account it is matters, so its tests name
     *                    a caller.
     * @return array{0: Schema, 1: array<string, mixed>} schema and context
     */
    private function stack(array $scopes, string $who = 'admin'): array
    {
        $container = $this->container();

        return array(
            $container->schemaFactory()->create(),
            array('identity' => $this->fixture->identity($who, $scopes))
        );
    }

    /**
     * @param string[] $scopes
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function execute(
        string $document, array $scopes, array $variables = array(),
        string $who = 'admin'
    ): array {
        list($schema, $context) = $this->stack($scopes, $who);
        $result = GraphQL::executeQuery(
            $schema, $document, null, $context, $variables
        );

        // The same formatter GraphQLHandler installs. Without it every
        // ApiException reaches toArray() as "Internal server error" with no
        // extensions at all, and this test would be asserting against the
        // executor's default rather than against what a client is sent.
        $result->setErrorFormatter(static function ($error) {
            $previous = $error->getPrevious();

            return $previous instanceof Throwable
                ? ErrorFactory::format($previous, true)
                : array(
                    'message'    => $error->getMessage(),
                    'extensions' => array('code' => ErrorCode::BAD_USER_INPUT)
                );
        });

        return $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
    }

    /**
     * Every leaf field of the GraphQL type behind a tag, as a selection.
     *
     * Leaf fields are the ones with no resolver of their own - the fallback
     * reads them off the source array - so they are exactly the fields a
     * missing gate would expose.
     */
    private function leafSelection(Schema $schema, string $tag): string
    {
        /** @var ObjectType $type */
        $type = $schema->getType(NodeType::graphqlType($tag));
        $fields = array();

        foreach ($type->getFields() as $name => $field) {
            if (Type::isLeafType(Type::getNamedType($field->getType()))) {
                $fields[] = $name;
            }
        }

        self::assertNotSame(array(), $fields, $tag);

        return implode(' ', $fields);
    }

    public function testEveryNodeTypeHasAScopeAndItIsARealOne(): void
    {
        // The enumeration itself: a tag added to NodeType with no entry in
        // QueryResolver::SCOPES would be served by the fallback with nothing
        // asked of the caller, and this is what says so.
        $tags = array_merge(
            NodeType::CUSTOMER_OWNED, NodeType::RESELLER_OWNED,
            NodeType::SERVER_OWNED
        );

        foreach ($tags as $tag) {
            self::assertArrayHasKey($tag, QueryResolver::SCOPES, $tag);
            self::assertTrue(Scope::isValid(QueryResolver::SCOPES[$tag]), $tag);
        }

        self::assertSame(
            count($tags), count(QueryResolver::SCOPES),
            'SCOPES names a tag NodeType does not know.'
        );
    }

    public function testTheSubjectsCoverEveryNodeTypeTheApiCanProduce(): void
    {
        // Without this the two tests below would quietly stop covering a tag
        // the day one is added, and still pass.
        self::assertSame(
            array_keys(QueryResolver::SCOPES), array_keys($this->subjects())
        );
    }

    public function testNoValueFieldOfANodeIsReadableWithoutItsScope(): void
    {
        list($schema,) = $this->stack(array());

        foreach ($this->subjects() as $tag => $id) {
            $scope = $this->requiredScope($tag);
            $response = $this->execute(
                'query Node($id: ID!) { node(id: $id) { __typename ... on '
                    . NodeType::graphqlType($tag) . ' { '
                    . $this->leafSelection($schema, $tag) . ' } } }',
                $this->allScopesBut($scope),
                array('id' => $id)
            );

            self::assertArrayHasKey('errors', $response, $tag);
            self::assertSame(
                ErrorCode::FORBIDDEN,
                $response['errors'][0]['extensions']['code'] ?? null,
                $tag . ' was readable without ' . $scope
            );
            self::assertNull($response['data']['node'], $tag);
        }
    }

    public function testEveryNodeIsReadableWithItsScope(): void
    {
        // The control. Without it the test above would pass just as well
        // against a gate that refused everybody.
        list($schema,) = $this->stack(array());

        foreach ($this->subjects() as $tag => $id) {
            $response = $this->execute(
                'query Node($id: ID!) { node(id: $id) { __typename ... on '
                    . NodeType::graphqlType($tag) . ' { '
                    . $this->leafSelection($schema, $tag) . ' } } }',
                array($this->requiredScope($tag), Scope::ACCOUNT_READ),
                array('id' => $id)
            );

            self::assertArrayNotHasKey(
                'errors', $response,
                $tag . ': ' . json_encode($response['errors'] ?? array())
            );
            self::assertNotNull($response['data']['node'], $tag);
            self::assertSame(
                NodeType::graphqlType($tag),
                $response['data']['node']['__typename'],
                $tag
            );
        }
    }

    public function testNothingInPendingIsReadableWithoutItsScope(): void
    {
        // Query.pending returns Nodes of eight different tags and asks only
        // for ACCOUNT_READ, so it was the second way round every other gate.
        $this->makeEverythingPending();

        foreach ($this->pendingTags() as $tag) {
            $scope = $this->requiredScope($tag);
            $response = $this->execute(
                '{ pending { __typename } }',
                $this->allScopesBut($scope)
            );

            self::assertArrayHasKey('errors', $response, $tag);
            self::assertSame(
                ErrorCode::FORBIDDEN,
                $response['errors'][0]['extensions']['code'] ?? null,
                $tag . ' was readable through pending without ' . $scope
            );
        }
    }

    public function testPendingReturnsEveryTagWhenTheScopesAreThere(): void
    {
        // Two things at once: the control for the test above, and the proof
        // that makeEverythingPending() really did put one of every tag in
        // front of it - without which that test would pass on an empty list.
        $this->makeEverythingPending();

        $response = $this->execute('{ pending { __typename } }', array());

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );

        $types = array();

        foreach ($response['data']['pending'] as $node) {
            $types[$node['__typename']] = true;
        }

        foreach ($this->pendingTags() as $tag) {
            self::assertArrayHasKey(
                NodeType::graphqlType($tag), $types, $tag . ' is not pending'
            );
        }
    }

    /**
     * node() and customer() must not disagree about the same object.
     *
     * The per-type gate tagged Customer ACCOUNT_READ flat, while
     * resolveCustomer() has always required CUSTOMERS_READ - so a reseller
     * holding ACCOUNT_READ and not CUSTOMERS_READ was refused one of their
     * customers by customer(id: X) and handed the same customer by
     * node(id: X). The identifier needed for the second is
     * base64("Customer:N"), and a reseller's own customers are consecutive
     * integers, so the narrower scope bought its holder nothing.
     */
    public function testAResellerWithAccountReadAloneCannotReachACustomerThroughNode(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());
        $node = 'query Node($id: ID!) { node(id: $id) { id } }';

        $response = $this->execute(
            $node, array(Scope::ACCOUNT_READ), array('id' => $id), 'reseller'
        );

        self::assertSame(
            ErrorCode::FORBIDDEN,
            $response['errors'][0]['extensions']['code'] ?? null,
            'ACCOUNT_READ alone reached another account through node()'
        );

        // The two halves of the disagreement, now agreeing. Query.customer
        // refuses the same caller the same object for the same reason...
        $viaCustomer = $this->execute(
            'query C($id: ID!) { customer(id: $id) { id } }',
            array(Scope::ACCOUNT_READ), array('id' => $id), 'reseller'
        );

        self::assertSame(
            ErrorCode::FORBIDDEN,
            $viaCustomer['errors'][0]['extensions']['code'] ?? null
        );

        // ...and the control, without which both assertions above would pass
        // against a gate that simply refused this caller everything:
        // CUSTOMERS_READ is the scope, and with it the object is reachable.
        $allowed = $this->execute(
            $node, array(Scope::CUSTOMERS_READ), array('id' => $id), 'reseller'
        );

        self::assertArrayNotHasKey(
            'errors', $allowed, json_encode($allowed['errors'] ?? array())
        );
        self::assertSame($id, $allowed['data']['node']['id']);
    }

    /**
     * And the half the conditional gate exists to keep: ACCOUNT_READ is the
     * account scope, so it still reads the caller's own account - which is
     * what Query.pending hands a customer about themselves.
     */
    public function testACustomerWithAccountReadAloneStillReachesTheirOwnAccount(): void
    {
        $id = GlobalId::encode(NodeType::CUSTOMER, $this->fixture->customerId());

        $response = $this->execute(
            'query Node($id: ID!) { node(id: $id) { id } }',
            array(Scope::ACCOUNT_READ), array('id' => $id), 'customer'
        );

        self::assertArrayNotHasKey(
            'errors', $response, json_encode($response['errors'] ?? array())
        );
        self::assertSame($id, $response['data']['node']['id']);
    }

    /**
     * One unsettled object of every tag Query.pending can return.
     */
    private function makeEverythingPending(): void
    {
        $updates = array(
            array('admin', 'admin_status', 'admin_id', $this->fixture->customerId()),
            array('domain', 'domain_status', 'domain_id', $this->fixture->domainId()),
            array('subdomain', 'subdomain_status', 'subdomain_id', $this->fixture->subdomainId()),
            array('domain_aliasses', 'alias_status', 'alias_id', $this->fixture->aliasId()),
            array('mail_users', 'status', 'mail_id', $this->fixture->mailboxId()),
            array('ftp_users', 'status', 'userid', $this->fixture->ftpUserId()),
            array('domain_dns', 'domain_dns_status', 'domain_dns_id', $this->fixture->dnsRecordId())
        );

        foreach ($updates as $update) {
            list($table, $column, $key, $value) = $update;
            $statement = $this->db->pdo()->prepare(
                'UPDATE `' . $table . '` SET `' . $column . '` = ?'
                    . ' WHERE `' . $key . '` = ?'
            );
            $statement->execute(array('toadd', $value));
        }

        // The alias subdomain is seeded 'toadd' already.
    }
}
