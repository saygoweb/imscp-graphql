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
use iMSCP\Plugin\SGW_GraphQL\Resolver\ResellerResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use InvalidArgumentException;

/**
 * Spec section 17's other half of the props property test: "parse then re-emit
 * must be byte-identical for every plan on the reference box and for generated
 * inputs".
 *
 * test/unit/Support/PlanPropsTest.php holds the generated half - five hundred
 * seeded iterations, no database. This holds the reference-box half, which had
 * been proved once by hand against a temporarily inserted row and never
 * committed. Spec section 20 lists a misparsed plan as risk 4: it creates a
 * customer with the wrong limits, silently, so the guard belongs in the suite
 * rather than in somebody's terminal history.
 *
 * Whatever is on the box is the point of it: a plan written by a real reseller
 * through the real page is the input no generator thinks of. A box with no
 * plans - and the reference box has none today - skips rather than fails, so
 * this is a real guard where there is something to guard and never a false
 * alarm where there is not.
 */
class PlanPropsTest extends IntegrationTestCase
{
    /** @var Db */
    private $db;

    /** @var Fixture|null */
    private $fixture;

    protected function setUp(): void
    {
        $this->db = Db::fromPanel();
        // Seeded per test rather than here: the first test is about the rows
        // the box already holds, and a fixture plan in its view would make
        // "the box has no plans" unreachable and the skip below dead code.
        $this->fixture = null;
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->rollBack();
        }
    }

    public function testEveryPlanOnThisBoxRoundTripsByteForByte(): void
    {
        $rows = $this->db->rows(
            'SELECT id, name, props FROM hosting_plans ORDER BY id'
        );

        if ($rows === array()) {
            self::markTestSkipped('this box has no hosting plans to round-trip');
        }

        foreach ($rows as $row) {
            $props = (string)$row['props'];
            $where = sprintf('hosting_plans.id %d (%s)', $row['id'], $row['name']);

            try {
                // Caught rather than left to fail the test as an error: the
                // exception says how many fields it found and not which plan
                // found them, and on a box with fifty plans that is the whole
                // question.
                $parsed = PlanProps::parse($props);
            } catch (InvalidArgumentException $e) {
                self::fail($where . ' does not parse: ' . $e->getMessage());
            }

            self::assertSame($props, $parsed->toString(), $where);
            // Parsed as well as re-emitted: a plan that round-trips but has
            // twenty-six fields would still create a customer with somebody
            // else's limits, and parse() is where that is refused.
            self::assertCount(
                count(PlanProps::FIELDS), explode(';', $props), $where
            );
        }
    }

    public function testTheSeededPlanRoundTripsAndShapesByteForByte(): void
    {
        // The half that always runs. The fixture's props string is a copy of
        // what hosting_plan_add.php:446 writes, and this asserts the whole
        // production path over it - planShape() is what a client's HostingPlan
        // actually goes through - rather than the parser in isolation.
        $this->fixture = new Fixture($this->db);
        $this->fixture->seed();

        $rows = $this->db->rows(
            'SELECT id, reseller_id, name, description, props, status'
                . ' FROM hosting_plans WHERE id = ?',
            array($this->fixture->hostingPlanId())
        );

        self::assertCount(1, $rows);

        $props = (string)$rows[0]['props'];

        self::assertSame($props, PlanProps::parse($props)->toString());

        $shape = ResellerResolver::planShape($rows[0]);

        self::assertSame(Fixture::PREFIX . ' plan', $shape['name']);
        self::assertSame(
            array('enabled' => true, 'limit' => 10), $shape['quotas']['subdomains']
        );
        // Decision D3: every BigInt in this schema is a count of bytes, and
        // the props file holds MiB for disk and traffic.
        self::assertSame((string)(5120 * 1048576), $shape['storage']['disk']);
        self::assertSame((string)(10240 * 1048576), $shape['storage']['traffic']);
        self::assertSame('104857600', $shape['storage']['mailQuota']);
    }
}
