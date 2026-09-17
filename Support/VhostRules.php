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
 * The rules the subdomain, alias and domain pages share that need nothing but
 * their arguments.
 */
final class VhostRules
{
    /** The schema's ForwardType => i-MSCP's type_forward. */
    const PANEL_FORWARD_TYPES = array(
        'PERMANENT_301' => '301',
        'FOUND_302'     => '302',
        'SEE_OTHER_303' => '303',
        'TEMPORARY_307' => '307',
        'PROXY'         => 'proxy'
    );

    /** Where Apache serves from, under a mount point. */
    const HTDOCS = '/htdocs';

    /**
     * Directories the main domain's web space already uses.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:275.
     *   Retire when SubdomainService lands in core.
     */
    const RESERVED_DIRECTORIES = array('backups', 'cgi-bin', 'errors', 'logs', 'phptmp');

    /**
     * @throws InvalidArgumentException
     */
    public static function panelForwardType(string $forwardType): string
    {
        if (!isset(self::PANEL_FORWARD_TYPES[$forwardType])) {
            throw new InvalidArgumentException(sprintf('Unknown forward type "%s".', $forwardType));
        }

        return self::PANEL_FORWARD_TYPES[$forwardType];
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:229.
     */
    public static function isReservedLabel(string $label): bool
    {
        return $label === 'www' || strpos($label, 'www.') === 0;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:273-288.
     *
     * @param string $parentKind 'dmn' or 'als'
     * @throws InvalidArgumentException
     */
    public static function subdomainMountPoint(string $parentKind, string $labelAscii, string $parentNameAscii): string
    {
        if ($parentKind === 'dmn') {
            return in_array($labelAscii, self::RESERVED_DIRECTORIES, true)
                ? '/sub_' . $labelAscii
                : '/' . $labelAscii;
        }

        if ($parentKind === 'als') {
            return $labelAscii === 'cgi-bin'
                ? '/' . $parentNameAscii . '/sub_' . $labelAscii
                : '/' . $parentNameAscii . '/' . $labelAscii;
        }

        throw new InvalidArgumentException(sprintf(
            'A subdomain hangs off a domain or an alias, not a "%s".', $parentKind
        ));
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/alias_add.php:267.
     */
    public static function aliasMountPoint(string $aliasNameAscii): string
    {
        return '/' . $aliasNameAscii;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/alias_add.php:244-246.
     */
    public static function stripWww(string $name): string
    {
        while (strpos($name, 'www.') === 0) {
            $name = substr($name, 4);
        }

        return $name;
    }

    /**
     * A document root, normalised, or null when it is not /htdocs or inside it.
     *
     * The input is in the same terms VirtualHost.documentRoot reads back -
     * "/htdocs/public" - rather than the pages' form field, which is relative
     * to /htdocs. A client can write what it read.
     *
     * CORE-DEBT(C3): the rule is gui/public/client/domain_edit.php:265-287.
     *
     * @param callable $normalise fn(string): string - utils_normalizePath()
     */
    public static function documentRoot(string $input, callable $normalise): ?string
    {
        $path = (string)call_user_func($normalise, '/' . $input);

        if ($path === self::HTDOCS || strpos($path, self::HTDOCS . '/') === 0) {
            return $path;
        }

        return null;
    }

    /** '/htdocs/public' => '/public'; '/htdocs' => '/'. */
    public static function relativeToHtdocs(string $documentRoot): string
    {
        $relative = substr($documentRoot, strlen(self::HTDOCS));

        return $relative === '' || $relative === false ? '/' : $relative;
    }

    public static function wildcard(bool $on): string
    {
        return $on ? 'yes' : 'no';
    }

    /**
     * The three forwarding columns of a host that forwards nowhere.
     *
     * @return array{url: string, type: null, host: string}
     */
    public static function noForwarding(): array
    {
        return array('url' => 'no', 'type' => null, 'host' => 'Off');
    }

    /**
     * A value for use inside a LIKE pattern, with MySQL's default escape
     * character. Names may contain '_' (measurement M16).
     */
    public static function likeEscape(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }
}
