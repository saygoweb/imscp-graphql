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

use InvalidArgumentException;

/**
 * mail_users.mail_type, both ways.
 *
 * i-MSCP stores the cross product of the vhost kind and the account kind in
 * one column, and comma-joins two of them for a mailbox that also forwards
 * (gui/public/client/mail_add.php:243-256). The vhost half is already carried
 * by MailAccount.host, so spec section 7.6 keeps only the second half and this
 * is the one place the product is taken apart and put back together.
 *
 * The constants are copied from gui/include/Shared.php:38-49 rather than
 * referenced: the unit suite runs without the panel, and therefore without
 * those define()s.
 */
final class MailType
{
    const KIND_MAILBOX             = 'MAILBOX';
    const KIND_FORWARD             = 'FORWARD';
    const KIND_MAILBOX_AND_FORWARD = 'MAILBOX_AND_FORWARD';
    const KIND_CATCHALL            = 'CATCHALL';

    const HOST_DMN    = 'dmn';
    const HOST_SUB    = 'sub';
    const HOST_ALS    = 'als';
    const HOST_ALSSUB = 'alssub';

    /** Vhost kind => the prefix i-MSCP uses for it in mail_type. */
    const PREFIX = array(
        self::HOST_DMN    => 'normal',
        self::HOST_ALS    => 'alias',
        self::HOST_SUB    => 'subdom',
        self::HOST_ALSSUB => 'alssub'
    );

    /** Account kind => the suffix, or the pair of suffixes. */
    const SUFFIX = array(
        self::KIND_MAILBOX             => array('mail'),
        self::KIND_FORWARD             => array('forward'),
        self::KIND_MAILBOX_AND_FORWARD => array('mail', 'forward'),
        self::KIND_CATCHALL            => array('catchall')
    );

    /**
     * Every value that can appear in the column.
     *
     * @return string[]
     */
    public static function all(): array
    {
        $values = array();

        foreach (array_keys(self::PREFIX) as $hostType) {
            foreach (array_keys(self::SUFFIX) as $kind) {
                $values[] = self::toMailType($hostType, $kind);
            }
        }

        return $values;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function toMailType(string $hostType, string $kind): string
    {
        if (!isset(self::PREFIX[$hostType])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown vhost kind "%s".', $hostType
            ));
        }

        if (!isset(self::SUFFIX[$kind])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown mail account kind "%s".', $kind
            ));
        }

        $prefix = self::PREFIX[$hostType];
        $parts = array();

        foreach (self::SUFFIX[$kind] as $suffix) {
            $parts[] = $prefix . '_' . $suffix;
        }

        return implode(',', $parts);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function kindOf(string $mailType): string
    {
        return self::split($mailType)[1];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function hostTypeOf(string $mailType): string
    {
        return self::split($mailType)[0];
    }

    /**
     * @return array{0: string, 1: string} host kind, account kind
     * @throws InvalidArgumentException
     */
    private static function split(string $mailType): array
    {
        $suffixes = array();
        $hostType = null;

        foreach (explode(',', $mailType) as $part) {
            $underscore = strrpos($part, '_');

            if ($underscore === false) {
                throw self::unknown($mailType);
            }

            $prefix = substr($part, 0, $underscore);
            $suffix = substr($part, $underscore + 1);
            $found = array_search($prefix, self::PREFIX, true);

            // Every part must name the same vhost, or the row is corrupt: a
            // single address cannot belong to two vhosts.
            if ($found === false || ($hostType !== null && $hostType !== $found)) {
                throw self::unknown($mailType);
            }

            $hostType = $found;
            $suffixes[] = $suffix;
        }

        foreach (self::SUFFIX as $kind => $expected) {
            if ($suffixes === $expected) {
                return array($hostType, $kind);
            }
        }

        throw self::unknown($mailType);
    }

    private static function unknown(string $mailType): InvalidArgumentException
    {
        // i-MSCP's mail_type vocabulary is closed, unlike the status vocabulary
        // of spec section 7.1, so an unrecognised value is data corruption and
        // not something to flatten into a default.
        return new InvalidArgumentException(sprintf(
            'Unknown mail_type "%s".', $mailType
        ));
    }
}
