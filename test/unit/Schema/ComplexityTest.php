<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Schema;

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

use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Spec section 10.2: "List fields declare a complexity proportional to
 * their limit." These tests never execute a resolver - QueryComplexity is a
 * validation rule, so the schema Container::forTesting() builds (with its
 * detached Db and Core, which throw on use) is exactly what production
 * would build, and there is nothing here for either to throw about.
 */
class ComplexityTest extends TestCase
{
    private function schema(array $config = array()): Schema
    {
        return Container::forTesting(
            dirname(__DIR__, 3),
            $config,
            static function (string $sql, array $bind = array()) { return null; },
            static function (int $adminId) { return null; },
            static function (int $adminId) { return true; }
        )->schemaFactory()->create();
    }

    /**
     * The complexity graphql-php computes for $document.
     *
     * Read off the "Max query complexity should be 1 but got N." message a
     * limit of 1 is guaranteed to produce for every document these tests
     * send (each costs well over 1) - QueryComplexity::DISABLED is 0, so 1
     * is the smallest limit that still enables the rule, and the only way
     * to observe the number it reaches without executing a single field.
     *
     * @param array<string, mixed> $variables
     */
    private function complexityOf(
        string $document, array $variables = array(), array $config = array()
    ): int {
        $rule = new QueryComplexity(1);
        $rule->setRawVariableValues($variables);

        $errors = DocumentValidator::validate(
            $this->schema($config), Parser::parse($document), array($rule)
        );

        self::assertNotEmpty($errors, 'expected a breach against a limit of 1');
        self::assertMatchesRegularExpression(
            '/^Max query complexity should be 1 but got (\d+)\.$/',
            $errors[0]->getMessage(),
            $errors[0]->getMessage()
        );

        preg_match('/got (\d+)\./', $errors[0]->getMessage(), $matches);

        return (int)$matches[1];
    }

    /**
     * '{ id }' under a connection's `nodes` costs 2: the leaf `id` is 1
     * (graphql-php's undeclared-field default of childComplexity + 1), and
     * `nodes` itself - a plain field with no complexity of its own - is
     * that plus 1.
     */
    public function testAPagedListCostsItsLimit(): void
    {
        self::assertSame(
            50 * 2,
            $this->complexityOf('{ customers(page: { limit: 50 }) { nodes { id } } }')
        );
    }

    public function testAListWithNoPageArgumentCostsTheDefault(): void
    {
        self::assertSame(
            50 * 2,
            $this->complexityOf('{ customers { nodes { id } } }')
        );
    }

    public function testALimitAboveTheCapCostsTheCap(): void
    {
        $cost = $this->complexityOf(
            '{ customers(page: { limit: 5000 }) { nodes { id } } }'
        );

        self::assertSame(200 * 2, $cost);
        self::assertNotSame(5000 * 2, $cost);
        self::assertNotSame(50 * 2, $cost);
    }

    public function testAPlainListCostsTheFlatEstimate(): void
    {
        // Customer.subdomains takes no `page` argument at all - there is
        // none in the SDL to pass one to - so this is TypeResolver's
        // FLAT_LIST_COST x child cost regardless of what is asked for, never
        // a number read off an argument.
        self::assertSame(
            TypeResolver::FLAT_LIST_COST * 1 + 1,
            $this->complexityOf('{ customer(id: "x") { subdomains { id } } }')
        );
    }

    /**
     * Checkpoint E, finding E1: a customer reading its own account nests two
     * plain lists, and while those were charged the 200 page cap that cost
     * 200 x 200 = 40,000 - refused outright by a budget of 20,000. These are
     * the two documents the finding measured, and they must now sit far
     * inside the shipped budget rather than over it.
     *
     * The budget itself is asserted against config.php rather than written
     * out here, so that lowering the shipped value without re-measuring
     * fails this test rather than passing quietly.
     */
    public function testACustomerReadingItsOwnAccountIsWellInsideTheBudget(): void
    {
        $budget = (int)(require dirname(__DIR__, 3) . '/config.php')['max_query_complexity'];

        $documents = array(
            '{ viewer { customer { domain { subdomains { id } '
                . 'aliases { id subdomains { id } } } } } }',
            '{ viewer { customer { dnsRecords { id } '
                . 'sqlDatabases { id users { id } } } } }'
        );

        foreach ($documents as $document) {
            $cost = $this->complexityOf($document);

            self::assertLessThan(1000, $cost, $document);
            self::assertLessThan($budget / 10, $cost, $document);
        }
    }

    /**
     * The other end of the same trade: the guard still guards. Three nested
     * pages at the cap measure 16,040,200 against the schema this plugin
     * ships - 320 times the shipped budget - so lowering the flat-list
     * charge did not buy the abusive shape its way in.
     */
    public function testThreeNestedFullPagesAreStillRefusedByAWideMargin(): void
    {
        $budget = (int)(require dirname(__DIR__, 3) . '/config.php')['max_query_complexity'];

        $cost = $this->complexityOf('
            query {
              resellers(page: { limit: 200 }) {
                nodes {
                  customers(page: { limit: 200 }) {
                    nodes {
                      mailAccounts(page: { limit: 200 }) {
                        nodes { id }
                      }
                    }
                  }
                }
              }
            }
        ');

        self::assertSame(16040200, $cost);
        self::assertGreaterThan($budget * 100, $cost);
    }

    /**
     * Checkpoint E, finding E2: docs/API.md's "Query cost" section tells an
     * operator to re-measure before moving `max_query_complexity`, so every
     * number it prints must be one they can reach. Each row of its two cost
     * tables prints its document verbatim in a single backticked cell; this
     * parses them straight out of the file and measures them, so a document
     * or a number edited without the other fails here rather than in an
     * operator's hands.
     */
    public function testEveryCostDocumentedInTheApiGuideReproduces(): void
    {
        $guide = file_get_contents(dirname(__DIR__, 3) . '/docs/API.md');
        self::assertIsString($guide, 'docs/API.md is not readable');

        $section = substr(
            $guide,
            strpos($guide, '## Query cost'),
            strpos($guide, '## Errors') - strpos($guide, '## Query cost')
        );

        preg_match_all(
            '/^\| `(\{.+\})` \| ([\d,]+) \|$/m', $section, $rows, PREG_SET_ORDER
        );

        self::assertNotEmpty($rows, 'no cost rows found in the guide');

        foreach ($rows as $row) {
            self::assertSame(
                (int)str_replace(',', '', $row[2]),
                $this->complexityOf($row[1]),
                $row[1]
            );
        }
    }

    /**
     * Checkpoint E, finding E8. `max_page_size` is read in two places that
     * have to agree: TypeResolver::page(), where the page is applied, and the
     * paged complexity charge here, where it is priced. Lowering the key must
     * lower the charge, or a document is charged for rows it cannot get.
     */
    public function testLoweringMaxPageSizeLowersWhatAPagedListIsCharged(): void
    {
        $document = '{ customers(page: { limit: 5000 }) { nodes { id } } }';

        self::assertSame(200 * 2, $this->complexityOf($document));
        self::assertSame(
            10 * 2,
            $this->complexityOf($document, array(), array('max_page_size' => 10))
        );
    }

    public function testMaxPageSizeDoesNotChangeWhatAPlainListIsCharged(): void
    {
        // A plain list takes no page argument, so the page cap has nothing to
        // do with it; FLAT_LIST_COST is its own constant for that reason.
        $document = '{ customer(id: "x") { subdomains { id } } }';

        self::assertSame(
            $this->complexityOf($document),
            $this->complexityOf($document, array(), array('max_page_size' => 10))
        );
    }

    public function testADocumentThatWouldBreachTheLimitIsRejected(): void
    {
        // Three nested 200-item pages: resellers -> customers -> mail
        // accounts, each asking for the cap. Structurally this is millions
        // of points against a budget of 1000.
        $document = '
            query {
              resellers(page: { limit: 200 }) {
                nodes {
                  customers(page: { limit: 200 }) {
                    nodes {
                      mailAccounts(page: { limit: 200 }) {
                        nodes { id }
                      }
                    }
                  }
                }
              }
            }
        ';

        $errors = DocumentValidator::validate(
            $this->schema(), Parser::parse($document),
            array(new QueryComplexity(1000))
        );

        self::assertNotEmpty($errors);
        self::assertMatchesRegularExpression(
            '/^Max query complexity should be 1000 but got \d+\.$/',
            $errors[0]->getMessage()
        );
    }
}
