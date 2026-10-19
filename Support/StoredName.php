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
 * A name, or a fragment of one, in the form i-MSCP stores names: punycode.
 *
 * domain.domain_name, and admin.admin_name for a customer the panel created,
 * hold encode_idna()'s output, so a filter has to encode what it is given the
 * same way before comparing. The encoder is handed in (Container::toAscii(),
 * encode_idna() on the panel) because only PanelCore may call the panel
 * (decision D10).
 */
final class StoredName
{
    /**
     * Each dot-separated label with a non-ASCII character goes through
     * $encodeLabel on its own, so a fragment's leading or trailing dot and
     * its empty labels survive (".müller." stays ".xn--mller-kva."). A label
     * the encoder refuses comes back as given (encode_idna() returns its
     * input on failure): the stored column is ASCII, so it then matches
     * nothing, which is the honest answer for a name that cannot exist. An
     * ASCII value is returned unchanged.
     *
     * @param callable $encodeLabel fn(string): string
     */
    public static function toAscii(string $name, callable $encodeLabel): string
    {
        if (!self::hasNonAscii($name)) {
            return $name;
        }

        $labels = explode('.', $name);

        foreach ($labels as $i => $label) {
            if (self::hasNonAscii($label)) {
                $labels[$i] = (string)call_user_func($encodeLabel, $label);
            }
        }

        return implode('.', $labels);
    }

    private static function hasNonAscii(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) === 1;
    }
}
