<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;

/**
 * Who a request was, where it came from and how long it took: the part of an
 * audit row that is a property of the request rather than of the document
 * (spec section 11).
 *
 * It is one mutable object per request, built by GraphQLHandler before
 * anything is parsed, because the row has to be written for a request that
 * never got as far as having an identity - a malformed body, a document that
 * would not parse - as readily as for one that ran.
 *
 * The one thing that can change after construction is who the request acted
 * as, and only in one direction. `tokenIssue` (spec section 5.3) is served
 * without a credential, so the account it authenticates is not known to any
 * middleware and not known to the handler either; the resolver that
 * authenticated it says so here, through actedAs(), and that is how the row
 * for the one unauthenticated field in the schema still names an account. A
 * request that *did* present a credential cannot be relabelled that way - see
 * actedAs().
 */
final class AuditContext
{
    /** `api_audit`.`ip` is varchar(45), which is an IPv6 address in full. */
    const MAX_IP = 45;

    /** `api_audit`.`via` (sql/003): POST /api/graphql. */
    const VIA_HTTP = 'http';

    /** `api_audit`.`via`: Api\Executor, called by SGW_Mcp in-process. */
    const VIA_MCP = 'mcp';

    /**
     * The PSR-7 request attribute Api\Executor carries VIA_MCP in.
     *
     * Namespaced, not `via`: Slim turns a route's placeholders into request
     * attributes, and a route that one day declared `{via}` must not be able
     * to relabel its own rows.
     */
    const VIA_ATTRIBUTE = 'sgw_graphql.via';

    /** @var int 0 when the request presented no credential at all. */
    private $adminId = 0;

    /** @var int|null The token, when the credential was one. */
    private $tokenId = null;

    /** @var string */
    private $ip;

    /** @var float microtime(true) when the handler took the request. */
    private $startedAt;

    /** @var string One of the VIA_ constants. */
    private $via;

    /**
     * @param string $via Which entry point the request came in by. Anything
     *                    but a VIA_ constant is read as VIA_HTTP: the value
     *                    reaches here from a request attribute, and a row must
     *                    never say a request came in by a door that does not
     *                    exist.
     */
    public function __construct(string $ip, float $startedAt, string $via = self::VIA_HTTP)
    {
        $this->via = $via === self::VIA_MCP ? self::VIA_MCP : self::VIA_HTTP;

        // Truncated rather than hashed, unlike RateLimiter's keys: nothing
        // keys off this column, so a value too long for it is a display
        // problem and not a two-callers-share-one-counter problem. Nothing
        // this plugin can be given is longer anyway - REMOTE_ADDR is an
        // address - which is why this is a guard and not a code path.
        $this->ip = substr($ip, 0, self::MAX_IP);
        $this->startedAt = $startedAt;
    }

    /**
     * The credential this request presented, if it presented one.
     *
     * @return void
     */
    public function carries(?Identity $identity): void
    {
        if ($identity === null) {
            return;
        }

        $this->adminId = $identity->getAdminId();
        $this->tokenId = $identity->getTokenId();
    }

    /**
     * The account a resolver authenticated for a request that arrived with no
     * credential.
     *
     * Only ever fills a blank. A request that presented a credential already
     * names its account, and letting a resolver write over that would make
     * the row say the request was somebody it was not - which is precisely
     * what an audit trail must not be able to be talked into. Two accounts
     * within one request cannot happen either (IdentityShim enforces the same
     * rule for the panel's own helpers), and if it somehow did, the first one
     * is the one that stands.
     *
     * @return void
     */
    public function actedAs(int $adminId): void
    {
        if ($this->adminId !== 0 || $adminId < 1) {
            return;
        }

        $this->adminId = $adminId;
    }

    public function getAdminId(): int
    {
        return $this->adminId;
    }

    public function getTokenId(): ?int
    {
        return $this->tokenId;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getVia(): string
    {
        return $this->via;
    }

    /**
     * How long the request has taken so far, in milliseconds.
     *
     * Never negative: `api_audit`.`duration_ms` is unsigned, and a clock that
     * stepped backwards mid-request would otherwise turn one audit row into a
     * failed INSERT - which, by decision D27, would then be swallowed, so the
     * row would simply not exist. Zero is the honest floor.
     */
    public function durationMs(): int
    {
        return (int)max(0, round((microtime(true) - $this->startedAt) * 1000));
    }
}
