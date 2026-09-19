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

// The unit suite runs without the panel bootstrapped, so only the plugin's own
// autoloader is registered here. Anything needing exec_query() or $_SESSION
// belongs in the integration suite, not this one.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// The session functions have to work in this process, because
// Service\PanelCore::authenticate() runs the panel's login under a session of
// its own and the tests for that are the specification of checkpoint D's
// first finding. Measured in the box: once anything has been written to
// stdout - and PHPUnit writes its banner before the first test - headers_sent()
// is true, and PHP then refuses session_id(), session_start() and even
// ini_set() of any session key. Two settings are what it refuses them over: it
// will not send a session cookie, and it will not send a cache limiter. This
// file is loaded before the banner, which is the last moment either can be
// turned off, and neither matters to a CLI process that has no client to send
// a header to.
//
// Nothing else is affected: the panel's own bootstrap starts no session under
// the CLI SAPI at all (iMSCP\Application::startSession() returns early for
// PHP_SAPI == 'cli'), so the only session in this process is the throwaway one
// PanelCore opens and destroys inside a single call.
if (PHP_SAPI === 'cli') {
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
}
