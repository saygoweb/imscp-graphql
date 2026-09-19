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

    // The in-panel GraphiQL explorer, under each role's own menu. Off by
    // default because it is a developer tool on a production control panel:
    // it is reachable by any account that can log in, it advertises the whole
    // schema to whoever opens it, and a box that has no client being written
    // against it has no use for it. Turn it on while integrating, and off
    // again afterwards.
    //
    // It needs 'introspection' as well - an explorer that cannot read the
    // schema is a broken tool, not a reduced one - so both must be true for
    // the pages to appear. With either off the page says which one, and the
    // menu does not offer it at all.
    'explorer'                => false,

    // Tokens
    'token_default_ttl_days'  => 365,
    'token_max_ttl_days'      => 730,
    'token_max_per_account'   => 10,

    // Query cost. A *Connection field is charged limit x child cost, capped
    // at 'max_page_size' either way (spec section 10.2); a plain list, which
    // takes no `page` argument, is charged TypeResolver::FLAT_LIST_COST - an
    // estimate for a list i-MSCP bounds by the customer's own allowance, not
    // an upper bound on rows. See that constant's docblock for why an
    // estimate is the honest figure there.
    //
    // 50,000 is derived from measurements against the schema this plugin
    // ships, taken by running graphql-php's QueryComplexity over each
    // document (docs/API.md's "Query cost" section prints them, and
    // test/unit/Schema/ComplexityTest.php is the technique):
    //
    //   customer reading its own account, everything on it        678
    //   reseller listing 50 customers with domains and subdomains 2,800
    //   administrator listing 50 resellers x 50 customers        10,200
    //   a full 200-row page with a fat selection on each row     31,200
    //   two nested 200-row pages                                 80,200
    //   three nested 200-row pages                           16,040,200
    //
    // So it clears the most expensive legitimate read - one page at the
    // 'max_page_size' cap, with everything selected on each row - by about
    // 1.6x, and the ordinary reads above by 5x or more, while still refusing
    // two nested full pages and refusing three by a factor of 320. Nesting
    // paged lists at the cap is what the limit exists to refuse; a client
    // that needs those rows asks for a smaller inner page.
    //
    // Two earlier defaults were set by reasoning rather than measuring and
    // both were wrong (1,000 refused every ordinary read; 20,000 refused a
    // customer reading its own subdomains and aliases, because the flat-list
    // charge above was then the 200 page cap and two nested flat lists cost
    // 40,000). Measure before moving this.
    'introspection'           => true,
    'max_query_depth'         => 15,
    'max_query_complexity'    => 50000,

    // The ceiling on a paged list's `page.limit`. A client asking for more
    // gets this many, and is charged this many by the complexity rule above.
    // Lowering it lowers both, together.
    'max_page_size'           => 200,

    // Rate limits, per minute. Enforced from plan 4; the keys exist now so
    // that operators who set them early are not surprised later.
    //
    // Counted in APCu where the box has it, and in the `api_rate` table where
    // it does not. APCu is a cache: under memory pressure it may evict a live
    // counter, which restarts that window and hands back an allowance already
    // spent. An installation that needs a hard bound should disable APCu for
    // the panel, whose database counter can only ever under-count by the
    // number of requests running at once.
    'rate_limit_queries'      => 120,
    'rate_limit_mutations'    => 30,
    'rate_limit_token_issue'  => 5,

    // A first-party integration on this box or a peer of it - the panel at
    // my.saygoweb.com mirroring its resellers, say - can exceed the ordinary
    // buckets the first time it mirrors anything. A client whose address
    // matches here has its `queries` and `mutations` buckets replaced by the
    // `_trusted` limits below; it is still counted and still refusable; it is
    // a bigger bucket, never no bucket, because a runaway trusted integration
    // is a real failure mode and unlimited is how it takes the panel down.
    //
    // `tokenIssue` never reads this list, for anyone: it is the one
    // unauthenticated field, and on a shared box "same box" includes every
    // tenant's PHP - an IP-based exemption there would be unlimited
    // credential guessing at the plugin layer.
    //
    // CIDRs, same form as 'trusted_proxies'. Empty, the default, matches no
    // client: an operator who sets nothing is exactly as limited as before
    // these keys existed.
    'trusted_clients'              => array(),
    'rate_limit_queries_trusted'   => 1200,
    'rate_limit_mutations_trusted' => 600,

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
