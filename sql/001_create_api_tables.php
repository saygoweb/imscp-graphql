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

// Three tables, all owned by the plugin, all dropped on uninstall. No i-MSCP
// table is altered: a plugin that alters core tables cannot be uninstalled
// cleanly.
return array(
    'up'   => "
        CREATE TABLE IF NOT EXISTS `api_token` (
            `token_id`     int(11) unsigned NOT NULL AUTO_INCREMENT,
            `admin_id`     int(11) unsigned NOT NULL,
            `name`         varchar(255) COLLATE utf8_unicode_ci NOT NULL,
            -- Shown in the panel so a user can tell their tokens apart, and
            -- the key the lookup is done on. Not a secret.
            `token_prefix` char(8) COLLATE utf8_unicode_ci NOT NULL,
            `token_hash`   char(64) COLLATE utf8_unicode_ci NOT NULL,
            `scopes`       varchar(1024) COLLATE utf8_unicode_ci NOT NULL,
            -- Comma-separated CIDRs, or NULL for no restriction.
            `ip_allowlist` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
            `created_at`   int(11) unsigned NOT NULL,
            `expires_at`   int(11) unsigned DEFAULT NULL,
            `last_used_at` int(11) unsigned DEFAULT NULL,
            `last_used_ip` varchar(45) COLLATE utf8_unicode_ci DEFAULT NULL,
            `revoked_at`   int(11) unsigned DEFAULT NULL,
            PRIMARY KEY (`token_id`),
            UNIQUE KEY `api_token_prefix` (`token_prefix`),
            KEY `api_token_admin_id` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

        CREATE TABLE IF NOT EXISTS `api_perm` (
            `admin_id` int(11) unsigned NOT NULL,
            -- No column default: every INSERT supplies this explicitly (see
            -- Auth/AccessService::setApiAccess()), and a schema-level default
            -- would be a fourth copy of the allowed-by-default decision,
            -- which lives in exactly one place -
            -- SGW_GraphQL.php::customerHasApiAccess()'s defaultAllowed
            -- parameter, itself resolved from the plugin's allowed_by_default
            -- config (section 6.2).
            `allowed`  tinyint(1) NOT NULL,
            PRIMARY KEY (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

        CREATE TABLE IF NOT EXISTS `api_audit` (
            `audit_id`    bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `at`          int(11) unsigned NOT NULL,
            `admin_id`    int(11) unsigned NOT NULL,
            `token_id`    int(11) unsigned DEFAULT NULL,
            `ip`          varchar(45) COLLATE utf8_unicode_ci NOT NULL,
            `operation`   varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
            `fields`      varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
            -- Variables with every Secret-typed value already replaced.
            `variables`   mediumtext COLLATE utf8_unicode_ci,
            `outcome`     enum('ok','error') COLLATE utf8_unicode_ci NOT NULL,
            `error_code`  varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
            `duration_ms` int(11) unsigned NOT NULL,
            PRIMARY KEY (`audit_id`),
            KEY `api_audit_at` (`at`),
            KEY `api_audit_admin_id` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ",
    'down' => "
        DROP TABLE IF EXISTS `api_audit`;
        DROP TABLE IF EXISTS `api_perm`;
        DROP TABLE IF EXISTS `api_token`;
    "
);
