<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Http;

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

use iMSCP\Plugin\SGW_GraphQL\Http\DevToolPage;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class DevToolPageTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = array();
    }

    protected function tearDown(): void
    {
        $_SESSION = array();
    }

    private function get(DevToolPage $page): Response
    {
        return $page(
            Request::createFromEnvironment(Environment::mock(['REQUEST_METHOD' => 'GET'])),
            new Response()
        );
    }

    private function page(
        string $tool, bool $on = true, bool $introspection = true, bool $sessionAuth = true
    ): DevToolPage {
        return new DevToolPage($tool, $on, $introspection, '/api/graphql', $sessionAuth);
    }

    public function testASwitchedOffToolIsA404(): void
    {
        foreach (array(DevToolPage::GRAPHIQL, DevToolPage::VOYAGER) as $tool) {
            $response = $this->get($this->page($tool, false));

            self::assertSame(404, $response->getStatusCode(), $tool);
            self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testAToolIsA404WithoutIntrospectionEvenWhenItsOwnKeyIsOn(): void
    {
        // Both tools are the introspection query: without it they are broken,
        // not reduced (the same rule as explorerDisabledBy()).
        foreach (array(DevToolPage::GRAPHIQL, DevToolPage::VOYAGER) as $tool) {
            self::assertSame(404, $this->get($this->page($tool, true, false))->getStatusCode(), $tool);
        }
    }

    public function testVoyagerServesItsPageFromTheVendoredAssets(): void
    {
        $response = $this->get($this->page(DevToolPage::VOYAGER));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('/api/graphql/voyager-assets/voyager.standalone.js', $body);
        self::assertStringContainsString('/api/graphql/voyager-assets/voyager.css', $body);
        self::assertStringContainsString('GraphQLVoyager.renderVoyager', $body);
        self::assertStringContainsString('"/api/graphql"', $body);
        self::assertStringNotContainsString('graphiql.min.js', $body);
    }

    public function testGraphiqlServesItsPageFromTheVendoredAssets(): void
    {
        $response = $this->get($this->page(DevToolPage::GRAPHIQL));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/api/graphql/explorer-assets/graphiql.min.js', $body);
        self::assertStringContainsString('/api/graphql/explorer-assets/react.production.min.js', $body);
        self::assertStringContainsString('"/api/graphql"', $body);
        self::assertStringNotContainsString('voyager', $body);
    }

    public function testNeitherPageReferencesAnExternalOrigin(): void
    {
        // Decision D28, as test/lint/all.sh enforces it for themes/.
        foreach (array(DevToolPage::GRAPHIQL, DevToolPage::VOYAGER) as $tool) {
            self::assertDoesNotMatchRegularExpression(
                '~https?://~', $this->page($tool)->html('token'), $tool
            );
        }
    }

    public function testAPanelSessionIsSentItsCsrfToken(): void
    {
        // AuthenticateMiddleware::fromSession() refuses the cookie without it.
        $_SESSION['user_id'] = 7;
        $_SESSION['graphql_csrf'] = 'sekrit';

        foreach (array(DevToolPage::GRAPHIQL, DevToolPage::VOYAGER) as $tool) {
            $response = $this->get($this->page($tool));

            self::assertStringContainsString(
                '{"X-iMSCP-CSRF":"sekrit"}', (string)$response->getBody(), $tool
            );
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testASessionWithNoTokenYetIsMintedOne(): void
    {
        $_SESSION['user_id'] = 7;

        $this->get($this->page(DevToolPage::VOYAGER));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $_SESSION['graphql_csrf']);
    }

    public function testNoSessionSendsNoCsrfHeader(): void
    {
        $body = (string)$this->get($this->page(DevToolPage::VOYAGER))->getBody();

        self::assertStringNotContainsString('X-iMSCP-CSRF', $body);
        self::assertArrayNotHasKey('graphql_csrf', $_SESSION);
    }

    public function testNoCsrfHeaderWhenSessionAuthIsOff(): void
    {
        $_SESSION['user_id'] = 7;

        $body = (string)$this->get(
            $this->page(DevToolPage::GRAPHIQL, true, true, false)
        )->getBody();

        self::assertStringNotContainsString('X-iMSCP-CSRF', $body);
    }

    public function testTheEndpointCannotCloseTheScriptElement(): void
    {
        $page = new DevToolPage(
            DevToolPage::VOYAGER, true, true, '/api/</script><script>alert(1)//', true
        );

        self::assertStringNotContainsString('</script><script>alert', $page->html(null));
    }

    public function testAnUnknownToolIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DevToolPage('playground', true, true, '/api/graphql', true);
    }

    public function testVoyagerSaysToSignInRatherThanSpinningForever(): void
    {
        // The API answers an anonymous introspection query UNAUTHENTICATED,
        // and Voyager has no error state of its own.
        $html = $this->page(DevToolPage::VOYAGER)->html(null);

        self::assertStringContainsString("'UNAUTHENTICATED'", $html);
        self::assertStringContainsString('Sign in to the control panel', $html);
    }
}
