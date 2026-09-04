<?php
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

// frontend/common.php is required directly by the page scripts, not
// autoloaded, and the unit suite deliberately does not bootstrap the panel
// (see test/bootstrap.php). safeText()/safeAttr() call i-MSCP's own tohtml(),
// which lives in gui/include/Input.php and reaches into the panel's global
// $ESCAPER (a Zend/Laminas Escaper), so it is not available here. This
// stand-in reproduces enough of its behaviour — escaping the HTML
// metacharacters in the body context, and the much stricter attribute
// context — for CommonTest.php to prove safeText()/safeAttr() do not weaken
// that escaping while also stripping braces. No namespace: tohtml() is
// called unqualified from SGW_GraphQL\Frontend, so it must resolve to the
// global namespace, exactly as it does against the real, panel-bootstrapped
// tohtml().
//
// The two modes are not interchangeable: a previous version of this stub
// ignored $escapeType entirely and ran htmlspecialchars() for both, so every
// assertion about safeAttr() only ever proved that *something* was escaped,
// never that attribute escaping specifically ran — attribute context is
// exactly where the distinction matters (see safeAttr()'s own doc comment,
// and the {NAME} rescanning hazard it describes). The real 'htmlAttr' mode
// (Escaper::escapeHtmlAttr()) hex-escapes every character outside
// [A-Za-z0-9,.\-_], confirmed against the real tohtml() on the box:
// tohtml('{NEW_TOKEN}', 'htmlAttr') === '&#x7B;NEW_TOKEN&#x7D;' and
// tohtml('a=b', 'htmlAttr') === 'a&#x3D;b', neither of which 'html' mode's
// htmlspecialchars() touches at all.
if (!function_exists('tohtml')) {
    function tohtml($string, $escapeType = 'html')
    {
        $string = (string)$string;

        if ($escapeType === 'htmlAttr') {
            return preg_replace_callback(
                '/[^A-Za-z0-9,.\-_]/',
                function ($matches) {
                    return sprintf('&#x%X;', ord($matches[0]));
                },
                $string
            );
        }

        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}
