<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Frontend;

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

use PHPUnit\Framework\TestCase;
use function SGW_GraphQL\Frontend\resolveTtlDays;
use function SGW_GraphQL\Frontend\safeAttr;
use function SGW_GraphQL\Frontend\safeText;

require_once __DIR__ . '/GlobalStubs.php';
require_once dirname(__DIR__, 3) . '/frontend/common.php';

/**
 * frontend/common.php is require_once'd by the page scripts under the
 * SGW_GraphQL\Frontend namespace (a deliberate convention shared with the
 * sibling SGW_ApacheCache plugin — it is not autoloaded, hence the manual
 * require above rather than a `use` of an autoloadable class).
 */
class CommonTest extends TestCase
{
    public function testSafeTextRemovesAPlaceholderThatWouldNeverTerminate(): void
    {
        // {NAME} substituting to itself is exactly the input that hangs
        // TemplateEngine::substitute_dynamic() forever (see the doc comment
        // on safeText()).
        self::assertSame('NAME', safeText('{NAME}'));
    }

    public function testSafeTextRemovesBracesWithoutTouchingOtherCharacters(): void
    {
        self::assertSame('my token', safeText('my {token'));
        self::assertSame('my token', safeText('my }token'));
    }

    public function testSafeTextStillEscapesHtmlMetacharacters(): void
    {
        // A future "simplification" to str_replace() alone, dropping the
        // tohtml() call, must fail this.
        $result = safeText('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testSafeAttrRemovesAPlaceholderThatWouldNeverTerminate(): void
    {
        self::assertSame('NEW_TOKEN', safeAttr('{NEW_TOKEN}'));
    }

    public function testSafeAttrStillEscapesAttributeMetacharacters(): void
    {
        $result = safeAttr('"><script>alert(1)</script>');

        self::assertStringNotContainsString('"', $result);
        self::assertStringNotContainsString('<script>', $result);
    }

    public function testResolveTtlDaysDefaultsAMissingValue(): void
    {
        // A missing field must mint a token WITH an expiry (the configured
        // default), never one that never expires.
        self::assertSame(365, resolveTtlDays(null, 365, 730));
    }

    public function testResolveTtlDaysDefaultsAnEmptyValue(): void
    {
        self::assertSame(365, resolveTtlDays('', 365, 730));
    }

    public function testResolveTtlDaysDefaultsANonStringValue(): void
    {
        // A crafted ttl_days[]=x POST array must not crash the page; it
        // falls back to the default like any other empty submission.
        self::assertSame(365, resolveTtlDays(['x'], 365, 730));
    }

    public function testResolveTtlDaysRejectsAValueAboveTheConfiguredCap(): void
    {
        // The bound is enforced unconditionally, including on a value that
        // was supplied (not merely omitted) precisely to defeat the cap.
        self::assertNull(resolveTtlDays('731', 365, 730));
    }

    public function testResolveTtlDaysRejectsAValueBelowOne(): void
    {
        self::assertNull(resolveTtlDays('0', 365, 730));
    }

    public function testResolveTtlDaysAcceptsAnInRangeValue(): void
    {
        self::assertSame(90, resolveTtlDays('90', 365, 730));
    }
}
