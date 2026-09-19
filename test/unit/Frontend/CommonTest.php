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
use function SGW_GraphQL\Frontend\configKeys;
use function SGW_GraphQL\Frontend\explorerDisabledBy;
use function SGW_GraphQL\Frontend\formatConfigValue;
use function SGW_GraphQL\Frontend\resolveTtlDays;
use function SGW_GraphQL\Frontend\safeAttr;
use function SGW_GraphQL\Frontend\safeJs;
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
        // Unlike safeText()'s body context, the real attribute escaper
        // (Escaper::escapeHtmlAttr(), confirmed against the box's own
        // tohtml()) already hex-escapes '{' and '}' on its own — nothing
        // that survives it needs str_replace() to strip a brace after the
        // fact. The braces must still be gone from the result either way.
        $result = safeAttr('{NEW_TOKEN}');

        self::assertStringNotContainsString('{', $result);
        self::assertStringNotContainsString('}', $result);
        self::assertStringContainsString('NEW_TOKEN', $result);
    }

    public function testSafeAttrStillEscapesAttributeMetacharacters(): void
    {
        $result = safeAttr('"><script>alert(1)</script>');

        self::assertStringNotContainsString('"', $result);
        self::assertStringNotContainsString('<script>', $result);
    }

    /**
     * Item 7 of the fix round: the stub's tohtml() used to ignore
     * $escapeType, so this suite could never actually prove safeAttr()
     * applies attribute escaping rather than body escaping — a safeAttr()
     * that quietly fell back to safeText()'s behaviour would have kept every
     * existing assertion green. '=' is exactly such a character: left alone
     * by body escaping, but outside the attribute-safe character set.
     */
    public function testSafeTextAndSafeAttrEscapeTheSameValueDifferently(): void
    {
        $value = 'a=b';

        self::assertSame('a=b', safeText($value));
        self::assertSame('a&#x3D;b', safeAttr($value));
        self::assertNotSame(safeText($value), safeAttr($value));
    }

    public function testSafeJsRemovesAPlaceholderThatWouldNeverTerminate(): void
    {
        // Like safeAttr()'s escapeHtmlAttr(), escapeJs() already hex-escapes
        // '{' and '}' on its own ('\x7B', '\x7D') - the brace strip still
        // has to leave none behind, the same guarantee safeText() and
        // safeAttr() make for their own contexts (the API explorer embeds
        // the CSRF token and endpoint inside a single-quoted JS string
        // literal; see api_explorer.php in each role directory).
        $result = safeJs('{ENDPOINT}');

        self::assertStringNotContainsString('{', $result);
        self::assertStringNotContainsString('}', $result);
        self::assertStringContainsString('ENDPOINT', $result);
    }

    public function testSafeJsEscapesAValueThatWouldBreakOutOfTheStringLiteral(): void
    {
        // A single quote is exactly what would end the explorer's
        // '{CSRF_JS}' literal early.
        $result = safeJs("'; alert(1); '");

        self::assertStringNotContainsString("'", $result);
    }

    public function testSafeJsAndSafeTextEscapeTheSameValueDifferently(): void
    {
        // '-' is left alone by safeText()'s body escaping but is outside
        // escapeJs()'s immune set (confirmed against the box: tojs('-') ===
        // '\x2D') - proving safeJs() runs JS escaping, not body escaping.
        $value = 'a-b';

        self::assertSame('a-b', safeText($value));
        self::assertNotSame(safeText($value), safeJs($value));
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

    /**
     * Checkpoint E, finding E4. config.php, CHANGELOG.md, docs/API.md and
     * docs/SPECIFICATION.md all say the explorer is off by default; the pages
     * gated on 'introspection', which defaults to true, so a stock install
     * served it. These are the four combinations, and the first is what a
     * stock install is.
     */
    public function testTheExplorerIsOffWhenItsOwnKeyIsOffEvenWithIntrospectionOn(): void
    {
        self::assertSame('explorer', explorerDisabledBy(false, true));
    }

    public function testTheExplorerIsOffWhenIntrospectionIsOff(): void
    {
        // Both are needed: GraphiQL's documentation pane, completions and
        // validation are all the introspection query, so an explorer without
        // it is broken rather than reduced.
        self::assertSame('introspection', explorerDisabledBy(true, false));
    }

    public function testTheExplorerNamesItsOwnKeyFirstWhenBothAreOff(): void
    {
        // Naming 'introspection' here would send an operator to turn on
        // something they may deliberately have turned off.
        self::assertSame('explorer', explorerDisabledBy(false, false));
    }

    public function testTheExplorerIsOnOnlyWhenBothKeysAreOn(): void
    {
        self::assertNull(explorerDisabledBy(true, true));
    }

    /**
     * The shipped default, read from the file an operator edits: this is the
     * assertion that fails if 'explorer' is ever removed from config.php or
     * flipped to true, which is what made the documents and the code disagree
     * in the first place.
     */
    public function testConfigPhpShipsTheExplorerKeyOff(): void
    {
        $config = require dirname(__DIR__, 3) . '/config.php';

        self::assertArrayHasKey('explorer', $config);
        self::assertFalse($config['explorer']);

        // So a stock install - introspection on, explorer untouched - keeps
        // the pages off, and says so by naming 'explorer'.
        self::assertSame(
            'explorer',
            explorerDisabledBy((bool)$config['explorer'], (bool)$config['introspection'])
        );
    }

    /**
     * Checkpoint E, finding E6. configKeys() promises "every key config.php
     * declares, in the order it declares them", and the audit page's
     * "Effective configuration" section is that promise rendered. It stopped
     * before trusted_clients, rate_limit_queries_trusted and
     * rate_limit_mutations_trusted, so the one page an administrator uses to
     * see who is exempt from the ordinary rate limits did not show the
     * exemption list.
     *
     * Asserted against config.php itself rather than against a copy of the
     * list, so the next key added without a line in configKeys() fails here.
     */
    public function testTheAuditPageListsEveryKeyConfigPhpDeclares(): void
    {
        $config = require dirname(__DIR__, 3) . '/config.php';

        self::assertSame(array_keys($config), configKeys());
    }

    public function testThisWavesKeysAreAmongThem(): void
    {
        // Named explicitly as well as covered by the comparison above: these
        // three are the ones that were missing, and a future rewrite of that
        // assertion must not quietly lose them.
        foreach (array(
            'explorer', 'trusted_clients',
            'rate_limit_queries_trusted', 'rate_limit_mutations_trusted'
        ) as $key) {
            self::assertContains($key, configKeys(), $key);
        }
    }

    public function testAnAddressListRendersAsItsAddresses(): void
    {
        // trusted_clients is an array, and the is_array branch is what
        // renders it; an empty one is "(none)", not "".
        self::assertSame('(none)', formatConfigValue(array()));
        self::assertSame(
            '10.0.0.0/8, 192.0.2.7',
            formatConfigValue(array('10.0.0.0/8', '192.0.2.7'))
        );
    }

    public function testBooleansRenderAsWordsRatherThanAsOneAndEmpty(): void
    {
        self::assertSame('true', formatConfigValue(true));
        self::assertSame('false', formatConfigValue(false));
    }
}
