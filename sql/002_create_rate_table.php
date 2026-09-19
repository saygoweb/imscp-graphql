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

// The rate limiter's counters when APCu is not available (specification
// section 10.3). Owned by the plugin and dropped on uninstall, like the three
// tables of 001; no i-MSCP table is altered.
//
// One row per (bucket, key, window). `window_at` is the first second of the
// fixed window, so a row's identity is its window and a new window is a new
// row rather than a reset of an old one - which is what makes the counter
// safe to increment without first reading it. Rows outliving their window are
// pruned opportunistically by Service\RateLimiter, which is why there is a
// key on `window_at` and no cron.
return array(
    'up'   => "
        CREATE TABLE IF NOT EXISTS `api_rate` (
            `bucket`     varchar(32) NOT NULL,
            `rate_key`   varchar(190) NOT NULL,
            `window_at`  int(11) unsigned NOT NULL,
            `hits`       int(11) unsigned NOT NULL DEFAULT '0',
            PRIMARY KEY (`bucket`, `rate_key`, `window_at`),
            KEY `api_rate_window_at` (`window_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ",
    'down' => "
        DROP TABLE IF EXISTS `api_rate`;
    "
);
