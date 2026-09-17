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

return array(
    'endpoint'                => '/api/graphql',
    'schema_endpoint'         => '/api/graphql/schema',

    // Customers and resellers may use the API unless a reseller or an
    // administrator withdraws it. Mirrors SGW_ApacheCache's permission model.
    'allowed_by_default'      => true,

    // Transport
    'require_tls'             => true,

    // The reverse proxies whose X-Forwarded-Proto (or X-Forwarded-SSL) header
    // may be believed when deciding whether a request arrived over TLS, as
    // addresses or CIDR ranges: array('127.0.0.1', '10.0.0.0/8'). Needed where
    // nginx or Apache terminates TLS and proxies to PHP-FPM, because the
    // backend then sees plain HTTP on port 80 and would otherwise refuse the
    // request — and revoke the token it carried.
    //
    // Either header can be sent by anyone, so one is honoured only from an
    // address listed here. Empty, the default, believes nobody and asks the
    // connection itself: an operator who sets nothing is exactly as safe as
    // before this key existed.
    'trusted_proxies'         => array(),

    'allowed_origins'         => array(),   // never '*'
    'allow_session_auth'      => true,
    'allow_password_grant'    => true,

    // Tokens
    'token_default_ttl_days'  => 365,
    'token_max_ttl_days'      => 730,
    'token_max_per_account'   => 10,

    // Query cost
    'introspection'           => true,
    'max_query_depth'         => 15,
    'max_query_complexity'    => 1000,
    'max_page_size'           => 200,

    // Rate limits, per minute. Enforced from plan 4; the keys exist now so
    // that operators who set them early are not surprised later.
    'rate_limit_queries'      => 120,
    'rate_limit_mutations'    => 30,
    'rate_limit_token_issue'  => 5,

    // Observability
    'audit'                   => 'mutations',   // none | mutations | all
    'audit_retention_days'    => 90,

    // Error detail in responses. Never enable on a production panel.
    'debug'                   => false,

    // Whether directories named in a mutation - an FTP home directory, a new
    // document root - are checked to exist first. The panel checks by
    // creating a temporary FTP account and logging in (spec section 3.2),
    // which costs a connection per check and fails while ProFTPD restarts.
    // Off, the backend creates or refuses the directory itself and says so in
    // provisioning.message.
    'validate_ftp_home_dir'   => true
);
