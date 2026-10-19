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

// Which entry point a request came in by (SGW_GraphQL 1.2.0): 'http' for
// POST /api/graphql, 'mcp' for Api\Executor, which another plugin calls in the
// same PHP process. Every row written before this column existed came in over
// HTTP, because nothing else could reach the API, so the default is true of
// them as well as of the rows the endpoint goes on writing.
//
// IF [NOT] EXISTS so that a re-run on an installation that already has the
// column - an update replayed by hand, a test suite that applies every
// migration before it starts - is a no-op rather than an error. MariaDB has
// both forms; i-MSCP runs on nothing else.
return array(
    'up'   => "
        ALTER TABLE `api_audit`
            ADD COLUMN IF NOT EXISTS `via` varchar(16) COLLATE utf8_unicode_ci
                NOT NULL DEFAULT 'http' AFTER `ip`;
    ",
    'down' => "
        ALTER TABLE `api_audit` DROP COLUMN IF EXISTS `via`;
    "
);
