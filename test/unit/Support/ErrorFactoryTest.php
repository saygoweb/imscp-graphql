<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1335, USA.
 */

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ErrorFactoryTest extends TestCase
{
    public function testAnApiExceptionKeepsItsCodeMessageAndExtensions(): void
    {
        $e = new ApiException(
            ErrorCode::LIMIT_EXCEEDED, 'Subdomain limit reached.',
            ['limit' => 10, 'used' => 10]
        );

        $formatted = ErrorFactory::format($e, false);

        self::assertSame('Subdomain limit reached.', $formatted['message']);
        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $formatted['extensions']['code']);
        self::assertSame(10, $formatted['extensions']['limit']);
        self::assertSame(10, $formatted['extensions']['used']);
    }

    public function testAnUnexpectedThrowableBecomesInternalWithoutDetail(): void
    {
        // A stack trace or a SQL fragment reaching a client is a disclosure, so
        // the message is fixed and the detail is reachable only by correlation.
        $formatted = ErrorFactory::format(
            new RuntimeException('SQLSTATE[42S02]: table imscp.secret missing'), false
        );

        self::assertSame(ErrorCode::INTERNAL, $formatted['extensions']['code']);
        self::assertStringNotContainsString('SQLSTATE', $formatted['message']);
        self::assertStringNotContainsString('secret', $formatted['message']);
        self::assertArrayHasKey('correlationId', $formatted['extensions']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{16}$/', $formatted['extensions']['correlationId']
        );
    }

    public function testDebugModeRevealsTheUnderlyingMessage(): void
    {
        $formatted = ErrorFactory::format(new RuntimeException('boom'), true);

        self::assertStringContainsString('boom', $formatted['message']);
    }

    public function testDebugModeDoesNotChangeAnApiExceptionsMessage(): void
    {
        // An ApiException's message was written for the caller already.
        $e = new ApiException(ErrorCode::NOT_FOUND, 'No such subdomain.');

        self::assertSame('No such subdomain.', ErrorFactory::format($e, true)['message']);
    }

    public function testEveryCodeHasAnHttpStatus(): void
    {
        foreach (ErrorCode::all() as $code) {
            self::assertIsInt(ErrorCode::httpStatus($code), $code . ' has no HTTP status');
        }
    }

    public function testTheCodesThatAreTransportLevelUseTheirOwnStatus(): void
    {
        self::assertSame(401, ErrorCode::httpStatus(ErrorCode::UNAUTHENTICATED));
        self::assertSame(403, ErrorCode::httpStatus(ErrorCode::API_ACCESS_WITHDRAWN));
        self::assertSame(429, ErrorCode::httpStatus(ErrorCode::RATE_LIMITED));
        self::assertSame(400, ErrorCode::httpStatus(ErrorCode::QUERY_TOO_COMPLEX));
    }

    public function testFieldLevelCodesAreCarriedInsideATwoHundred(): void
    {
        // A query may legitimately be allowed to read some fields and not
        // others, so an authorisation failure is a field error in a 200.
        foreach ([
            ErrorCode::FORBIDDEN, ErrorCode::NOT_FOUND, ErrorCode::BAD_USER_INPUT,
            ErrorCode::LIMIT_EXCEEDED, ErrorCode::FEATURE_UNAVAILABLE,
            ErrorCode::CONFLICT, ErrorCode::INTERNAL
        ] as $code) {
            self::assertSame(200, ErrorCode::httpStatus($code), $code);
        }
    }

    public function testAnUnknownCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiException('NOT_A_REAL_CODE', 'nope');
    }
}
