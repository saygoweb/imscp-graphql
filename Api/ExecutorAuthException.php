<?php
namespace iMSCP\Plugin\SGW_GraphQL\Api;

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

use RuntimeException;

/**
 * Executor::execute() was refused before anything ran, for want of a
 * credential the API would accept.
 *
 * The two reasons are the two refusals POST /api/graphql answers before a
 * document is parsed - 401 UNAUTHENTICATED and 403 API_ACCESS_WITHDRAWN - and
 * they are kept apart for the same reason the endpoint keeps them apart: the
 * first says "sign in again", the second says "signing in again will not
 * help". UNAUTHENTICATED does not say which check failed (a missing, unknown,
 * wrong, revoked or expired token, or one refused by its own address
 * allow-list), for the reason AuthenticateMiddleware gives.
 *
 * A refusal that happens once the document is running - a field the token's
 * scopes do not cover, a rate limit - is not one of these. It comes back in
 * the result envelope's `errors`, as it would over HTTP.
 */
final class ExecutorAuthException extends RuntimeException
{
    const UNAUTHENTICATED = 'UNAUTHENTICATED';
    const API_ACCESS_WITHDRAWN = 'API_ACCESS_WITHDRAWN';

    /** @var string One of the constants above. */
    private $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);

        $this->reason = $reason === self::API_ACCESS_WITHDRAWN
            ? self::API_ACCESS_WITHDRAWN
            : self::UNAUTHENTICATED;
    }

    public static function unauthenticated(): self
    {
        return new self(self::UNAUTHENTICATED, 'Authentication is required.');
    }

    public static function apiAccessWithdrawn(): self
    {
        return new self(
            self::API_ACCESS_WITHDRAWN, 'API access has been withdrawn from this account.'
        );
    }

    /**
     * @return string self::UNAUTHENTICATED or self::API_ACCESS_WITHDRAWN
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
