<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use PHPUnit\Framework\TestCase;

class ProvisioningFilterTest extends TestCase
{
    public function testNoStateIsNoFilterAtAll(): void
    {
        // Not "AND 1=1" and not "AND status IS NOT NULL": an absent filter
        // must add nothing, so that the caller can concatenate it blindly.
        self::assertSame(array('', array()), ProvisioningFilter::clause('s', null));
    }

    public function testOkIsTheOneLiteralStatus(): void
    {
        list($sql, $bind) = ProvisioningFilter::clause('m.status', 'OK');

        self::assertSame(' AND m.status = ?', $sql);
        self::assertSame(array('ok'), $bind);
    }

    public function testPendingIsEveryPendingStatus(): void
    {
        // Provisioning::PENDING_STATUSES is the list plan 1 established; this
        // asserts the filter uses that list rather than a second copy of it
        // that will drift.
        list($sql, $bind) = ProvisioningFilter::clause('m.status', 'PENDING');

        self::assertSame(
            ' AND m.status IN ('
                . implode(', ', array_fill(0, count(Provisioning::PENDING_STATUSES), '?'))
                . ')',
            $sql
        );
        self::assertSame(Provisioning::PENDING_STATUSES, $bind);
    }

    public function testErrorIsEverythingThatIsNotAKnownStatus(): void
    {
        // i-MSCP stores the backend's failure text in the status column, so
        // ERROR cannot be matched by value - only by exclusion. A filter that
        // listed error strings would match none of them.
        list($sql, $bind) = ProvisioningFilter::clause('m.status', 'ERROR');

        self::assertStringStartsWith(' AND (m.status NOT IN (', $sql);
        self::assertContains('ok', $bind);
        self::assertContains('disabled', $bind);
        self::assertContains('ordered', $bind);
        self::assertContains('toadd', $bind);
    }

    public function testOrderedIsALiteral(): void
    {
        self::assertSame(
            array(' AND a.alias_status = ?', array('ordered')),
            ProvisioningFilter::clause('a.alias_status', 'ORDERED')
        );
    }

    public function testDisabledAlsoMatchesAStatusThatIsNull(): void
    {
        // Provisioning::fromStatus(null) is DISABLED, so a row with a NULL
        // status is reported as DISABLED by every resolver in the plugin. A
        // plain '= ?' never matches NULL in SQL, so without the second half of
        // this predicate the API reports a row as DISABLED and then hides it
        // from filter: { state: DISABLED }.
        self::assertSame(
            array(
                ' AND (a.alias_status = ? OR a.alias_status IS NULL)',
                array('disabled')
            ),
            ProvisioningFilter::clause('a.alias_status', 'DISABLED')
        );
    }

    public function testErrorExcludesAStatusThatIsNull(): void
    {
        // The other side of the same coin: NOT IN (...) is unknown for NULL
        // rather than true, but a reader would expect the open-ended ERROR set
        // to swallow it. It is DISABLED, and the predicate says so.
        list($sql, $bind) = ProvisioningFilter::clause('a.alias_status', 'ERROR');

        self::assertStringStartsWith(' AND (a.alias_status NOT IN (', $sql);
        self::assertStringEndsWith(' AND a.alias_status IS NOT NULL)', $sql);
        self::assertContains('disabled', $bind);
    }

    public function testAnUnknownStateFiltersNothingRatherThanEverything(): void
    {
        // The schema's enum makes this unreachable through a query, but a
        // resolver calling this with a value from somewhere else must not
        // silently produce an empty list.
        self::assertSame(
            array('', array()), ProvisioningFilter::clause('s', 'SOMETHING_NEW')
        );
    }

    public function testEveryStateInTheSchemaIsCovered(): void
    {
        foreach (ProvisioningFilter::STATES as $state) {
            list($sql,) = ProvisioningFilter::clause('s', $state);

            self::assertNotSame('', $sql, $state);
        }

        self::assertSame(array(
            Provisioning::STATE_OK, Provisioning::STATE_PENDING,
            Provisioning::STATE_DISABLED, Provisioning::STATE_ORDERED,
            Provisioning::STATE_ERROR
        ), ProvisioningFilter::STATES);
    }
}
