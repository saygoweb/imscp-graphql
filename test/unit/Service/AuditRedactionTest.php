<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

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

use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Service\Audit;
use PHPUnit\Framework\TestCase;

/**
 * Specification section 11's redaction, against the real schema.
 *
 * The schema is built from `schema/schema.graphql` rather than from a handful
 * of types written for the test, because the whole claim being made here is
 * that a value is redacted for its *type* and not for its name: a redactor is
 * only as good as the types it walks, and a test that walks types of its own
 * invention proves nothing about the ones the endpoint actually serves.
 *
 * The cases that matter most are the last three. A name-based redactor - one
 * with a list of field names it knows to hide - passes the first few and
 * fails those: it cannot fail closed on a document it could not parse, it has
 * nothing to say about a variable the document never declared, and it lets a
 * secret through the day somebody adds a field with a name that is not on its
 * list.
 */
class AuditRedactionTest extends TestCase
{
    /** @var Schema|null Built once; BuildSchema over the whole SDL is not free. */
    private static $schema;

    public static function tearDownAfterClass(): void
    {
        self::$schema = null;
    }

    /**
     * The endpoint's own schema, with no resolvers: nothing here executes a
     * document, it only walks the types one declares.
     */
    private function schema(): Schema
    {
        if (self::$schema === null) {
            self::$schema = (new SchemaFactory(
                dirname(__DIR__, 3) . '/schema/schema.graphql',
                null,
                new ResolverMap(array())
            ))->create();
        }

        return self::$schema;
    }

    private function parse(string $document): ?DocumentNode
    {
        try {
            return Parser::parse($document);
        } catch (SyntaxError $e) {
            // Exactly what GraphQLHandler hands Audit::record() for a document
            // that would not parse: nothing.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function redact(string $document, array $variables): array
    {
        return Audit::redact($this->parse($document), $variables, $this->schema());
    }

    // ---- the ordinary cases ---------------------------------------------

    public function testASecretVariableIsReplaced(): void
    {
        self::assertSame(
            array('password' => '***'),
            $this->redact(
                'mutation M($password: Secret!) {'
                    . ' sqlUserSetPassword(id: "x", password: $password) { id } }',
                array('password' => 'hunter2')
            )
        );
    }

    public function testANonSecretVariableSurvives(): void
    {
        // The point of the audit row: what was done is still readable. Only
        // the Secret changes, and the ID beside it - the thing an
        // administrator reads the row to find out - is untouched.
        self::assertSame(
            array('id' => 'SqlUser:7', 'password' => '***'),
            $this->redact(
                'mutation M($id: ID!, $password: Secret!) {'
                    . ' sqlUserSetPassword(id: $id, password: $password) { id } }',
                array('id' => 'SqlUser:7', 'password' => 'hunter2')
            )
        );
    }

    public function testASecretInsideAnInputObjectIsReplaced(): void
    {
        $redacted = $this->redact(
            'mutation M($input: CustomerCreateInput!) { customerCreate(input: $input) { id } }',
            array('input' => array(
                'username'   => 'jane',
                'password'   => 'hunter2',
                'domainName' => 'example.test'
            ))
        );

        self::assertSame(
            array(
                'username'   => 'jane',
                'password'   => '***',
                'domainName' => 'example.test'
            ),
            $redacted['input']
        );
    }

    public function testASecretInsideAListOfInputObjectsIsReplaced(): void
    {
        // No field in the schema takes a list of input objects today, so the
        // list is declared on the operation instead. That is deliberate: the
        // redactor must be right about the type it is given, not about the
        // shapes the schema happens to use this month - the day such a field
        // is added, this is the case that was already covered.
        $redacted = $this->redact(
            'mutation M($batch: [CustomerCreateInput!]!) { apiVersion }',
            array('batch' => array(
                array('username' => 'jane', 'password' => 'hunter2'),
                array('username' => 'john', 'password' => 'correct horse')
            ))
        );

        self::assertSame(
            array(
                array('username' => 'jane', 'password' => '***'),
                array('username' => 'john', 'password' => '***')
            ),
            $redacted['batch']
        );
    }

    public function testANestedInputObjectIsWalkedToTheBottom(): void
    {
        $redacted = $this->redact(
            'mutation M($outer: [[CustomerCreateInput!]!]!) { apiVersion }',
            array('outer' => array(array(array(
                'username' => 'jane',
                'password' => 'hunter2',
                'contact'  => array('email' => 'jane@example.test', 'firstName' => 'Jane')
            ))))
        );

        // Two list wrappers and an input object down, and it is still found.
        self::assertSame('***', $redacted['outer'][0][0]['password']);

        // ...and the walk did not stop there: the input object nested inside
        // the input object was walked too, and kept.
        self::assertSame(
            array('email' => 'jane@example.test', 'firstName' => 'Jane'),
            $redacted['outer'][0][0]['contact']
        );
    }

    public function testAKeyTheInputTypeDoesNotDeclareIsDropped(): void
    {
        // ContactDetailsInput declares no `password`, so there is no type to
        // walk this value against and it does not reach the row at all.
        $redacted = $this->redact(
            'mutation M($input: CustomerCreateInput!) { customerCreate(input: $input) { id } }',
            array('input' => array(
                'username' => 'jane',
                'password' => 'hunter2',
                'contact'  => array('email' => 'jane@example.test', 'password' => 'smuggled')
            ))
        );

        self::assertSame(array('email' => 'jane@example.test'), $redacted['input']['contact']);
        self::assertStringNotContainsString('smuggled', json_encode($redacted));
    }

    // ---- the cases a name-based redactor gets wrong ---------------------

    public function testAVariableTheDocumentDoesNotDeclareIsDroppedNotGuessed(): void
    {
        $redacted = $this->redact(
            'mutation M($password: Secret!) {'
                . ' sqlUserSetPassword(id: "x", password: $password) { id } }',
            array('password' => 'hunter2', 'smuggled' => 'correct horse')
        );

        // The *value* is dropped, not the key: an undeclared variable has no
        // type to walk, so nothing about it can be shown to be safe and its
        // value never reaches the row. The key stays, because "this caller
        // sent a variable the document never declared" is itself worth having
        // in an audit trail - and a redactor that guessed the value was
        // harmless because it is not called `password` is the exact failure
        // this whole class exists to rule out.
        self::assertSame(array('password' => '***', 'smuggled' => '***'), $redacted);
        self::assertStringNotContainsString('correct horse', json_encode($redacted));
    }

    public function testAVariableWhoseDeclaredTypeIsNotInTheSchemaIsRedacted(): void
    {
        // A type the schema does not define resolves to nothing, which is the
        // same state of knowledge as no declaration at all.
        self::assertSame(
            array('input' => '***'),
            $this->redact(
                'mutation M($input: NoSuchInput!) { apiVersion }',
                array('input' => array('password' => 'hunter2'))
            )
        );
    }

    public function testAnUnparseableDocumentRedactsEverything(): void
    {
        // Fail closed: with no types to walk, no value can be shown to be
        // safe. The one case where a name-based redactor is at its most
        // confident - it does not need a document at all - and at its most
        // wrong.
        self::assertSame(
            array('password' => '***', 'input' => '***', 'page' => '***'),
            $this->redact(
                'mutation M($password: Secret! { this is not a document',
                array(
                    'password' => 'hunter2',
                    'input'    => array('password' => 'hunter2'),
                    'page'     => 3
                )
            )
        );
    }

    public function testTheShapeIsPreservedSoAnAuditRowStaysReadable(): void
    {
        $redacted = $this->redact(
            'mutation M($input: TokenIssueInput!) { tokenIssue(input: $input) { token } }',
            array('input' => array(
                'username'      => 'jane',
                'password'      => 'hunter2',
                'name'          => 'ci',
                'scopes'        => array('DOMAINS_READ', 'DOMAINS_WRITE'),
                'expiresInDays' => 30,
                'ipAllowlist'   => array('203.0.113.0/24')
            ))
        );

        // Keys in order, lists still lists, scalars still their own types:
        // the row an administrator reads a year from now says what was asked
        // for, and says nothing about the password.
        self::assertSame(
            array(
                'username'      => 'jane',
                'password'      => '***',
                'name'          => 'ci',
                'scopes'        => array('DOMAINS_READ', 'DOMAINS_WRITE'),
                'expiresInDays' => 30,
                'ipAllowlist'   => array('203.0.113.0/24')
            ),
            $redacted['input']
        );
        self::assertSame(
            '{"input":{"username":"jane","password":"***","name":"ci",'
                . '"scopes":["DOMAINS_READ","DOMAINS_WRITE"],"expiresInDays":30,'
                . '"ipAllowlist":["203.0.113.0\/24"]}}',
            json_encode($redacted),
            'a list that came back as an object would make the row unreadable'
        );
    }

    public function testNoVariablesAtAllIsNoVariablesAtAll(): void
    {
        self::assertSame(array(), $this->redact('{ apiVersion }', array()));
    }

    /**
     * Checkpoint D, finding D7. AST::typeFromAST() resolves an output type
     * name as happily as an input one, so a variable declared as `ApiToken`
     * resolved to a real ObjectType - and the walk, which kept anything it
     * did not recognise, stored the caller's structure verbatim. The executor
     * would refuse the document, but redact() runs on the parsed document
     * whether it ran or not.
     */
    public function testAVariableDeclaredAsAnOutputTypeIsRedacted(): void
    {
        self::assertSame(
            array('p' => '***'),
            $this->redact(
                'mutation M($p: ApiToken) { tokenRevoke(id: "x") { id } }',
                array('p' => array(
                    'prefix'  => 'abcdefgh',
                    'smuggled' => array('anything' => 'the caller likes')
                ))
            )
        );
    }

    public function testAnOutputTypeInsideAnInputPositionIsRedactedToo(): void
    {
        // The same rule one level down: the walk passes a value through only
        // for a type it can reason about, so a list of them is a list of
        // `***` rather than a list of whatever arrived.
        self::assertSame(
            array('p' => array('***', '***')),
            $this->redact(
                'mutation M($p: [ApiToken!]) { tokenRevoke(id: "x") { id } }',
                array('p' => array(array('prefix' => 'a'), array('prefix' => 'b')))
            )
        );
    }
}
