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

use InvalidArgumentException;

/**
 * The closed set of error codes the API emits.
 *
 * These are part of the compatibility contract: a code is never removed within
 * a major version. See docs/SPECIFICATION.md section 9.
 */
final class ErrorCode
{
    const UNAUTHENTICATED      = 'UNAUTHENTICATED';
    const API_ACCESS_WITHDRAWN = 'API_ACCESS_WITHDRAWN';
    const FORBIDDEN            = 'FORBIDDEN';
    const NOT_FOUND            = 'NOT_FOUND';
    const BAD_USER_INPUT       = 'BAD_USER_INPUT';
    const LIMIT_EXCEEDED       = 'LIMIT_EXCEEDED';
    const FEATURE_UNAVAILABLE  = 'FEATURE_UNAVAILABLE';
    const CONFLICT             = 'CONFLICT';
    const RATE_LIMITED         = 'RATE_LIMITED';
    const QUERY_TOO_COMPLEX    = 'QUERY_TOO_COMPLEX';
    const INTERNAL             = 'INTERNAL';

    /**
     * Codes that end the request before there is a GraphQL result worth
     * returning. Everything else is a field error inside a 200, because a
     * query may be allowed to read some fields and not others.
     */
    const TRANSPORT_STATUS = array(
        self::UNAUTHENTICATED      => 401,
        self::API_ACCESS_WITHDRAWN => 403,
        self::RATE_LIMITED         => 429,
        self::QUERY_TOO_COMPLEX    => 400
    );

    public static function all(): array
    {
        return array(
            self::UNAUTHENTICATED, self::API_ACCESS_WITHDRAWN, self::FORBIDDEN,
            self::NOT_FOUND, self::BAD_USER_INPUT, self::LIMIT_EXCEEDED,
            self::FEATURE_UNAVAILABLE, self::CONFLICT, self::RATE_LIMITED,
            self::QUERY_TOO_COMPLEX, self::INTERNAL
        );
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    public static function httpStatus(string $code): int
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException(sprintf('Unknown error code "%s".', $code));
        }

        return self::TRANSPORT_STATUS[$code] ?? 200;
    }
}
