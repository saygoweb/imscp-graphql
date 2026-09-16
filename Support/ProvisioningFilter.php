<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

/**
 * A ProvisioningState turned into a SQL predicate over a status column.
 *
 * The inverse of Provisioning: that class reads a stored status and says what
 * state it is, this one takes a state and says which stored statuses are in
 * it. ERROR is the interesting one - i-MSCP writes the backend's failure text
 * into the status column, so the set of error statuses is open and can only be
 * matched by excluding the closed set of everything else.
 */
final class ProvisioningFilter
{
    /** Every ProvisioningState the schema declares. */
    const STATES = array(
        Provisioning::STATE_OK,
        Provisioning::STATE_PENDING,
        Provisioning::STATE_DISABLED,
        Provisioning::STATE_ORDERED,
        Provisioning::STATE_ERROR
    );

    /** The states that are exactly one stored status. */
    const LITERALS = array(
        Provisioning::STATE_OK       => 'ok',
        Provisioning::STATE_DISABLED => 'disabled',
        Provisioning::STATE_ORDERED  => 'ordered'
    );

    /**
     * @param string $column A column reference, already qualified by the caller
     * @return array{0: string, 1: array} SQL fragment and its bindings
     */
    public static function clause(string $column, ?string $state): array
    {
        if ($state === null || !in_array($state, self::STATES, true)) {
            // An absent or unrecognised filter adds nothing, so that a caller
            // can concatenate the fragment without testing it first. It must
            // not narrow to nothing: a client asking for a state this server
            // has never heard of should see everything, not an empty list it
            // would read as "you have none".
            return array('', array());
        }

        if (isset(self::LITERALS[$state])) {
            return array(' AND ' . $column . ' = ?', array(self::LITERALS[$state]));
        }

        if ($state === Provisioning::STATE_PENDING) {
            return array(
                ' AND ' . $column . ' IN (' . self::questions(
                    count(Provisioning::PENDING_STATUSES)
                ) . ')',
                Provisioning::PENDING_STATUSES
            );
        }

        // ERROR. Everything that is not a status i-MSCP writes deliberately.
        $known = array_merge(
            array_values(self::LITERALS), Provisioning::PENDING_STATUSES
        );

        return array(
            ' AND ' . $column . ' NOT IN (' . self::questions(count($known)) . ')',
            $known
        );
    }

    private static function questions(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }
}
