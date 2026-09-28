<?php
namespace iMSCP\Plugin\SGW_GraphQL\Http;

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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The standalone developer tools beside the endpoint: GraphiQL at
 * `{endpoint}/graphiql` and GraphQL Voyager at `{endpoint}/voyager`.
 *
 * Unlike the in-panel explorer (frontend/{role}/api_explorer.php) these are
 * whole pages of their own, outside the panel's layout, for a developer who
 * wants the tool full-window. Each is switched by its own config key and
 * needs 'introspection' as well, for the reason explorerDisabledBy() gives:
 * both tools are the introspection query, and without it they are broken
 * rather than reduced. A switched-off tool answers 404, as a route that does
 * not exist would, so a production panel does not advertise it.
 *
 * The pages send nothing but their own markup. Every query they run is a
 * POST to the endpoint like any other client's, through the same TLS,
 * authentication, rate-limit and audit stack. A visitor with a panel session
 * runs as that account: the page carries the session's CSRF token, which
 * AuthenticateMiddleware requires alongside the cookie. One without is
 * anonymous, and the API answers an anonymous introspection query
 * UNAUTHENTICATED - so Voyager says to sign in to the panel first, and
 * GraphiQL works once a bearer token is put in its Headers pane.
 *
 * The assets are vendored under themes/default/assets/ and served by
 * SGW_GraphQL::explorerAssetRoutes() and voyagerAssetRoutes() - decision
 * D28, no CDN.
 */
final class DevToolPage
{
    const GRAPHIQL = 'graphiql';
    const VOYAGER = 'voyager';

    /** @var string */
    private $tool;

    /** @var bool */
    private $enabled;

    /** @var string */
    private $endpoint;

    /** @var bool */
    private $allowSessionAuth;

    /**
     * @param string $tool             self::GRAPHIQL or self::VOYAGER
     * @param bool   $toolEnabled      The tool's own config key
     * @param bool   $introspection    config.php's 'introspection'
     * @param string $endpoint         config.php's 'endpoint'
     * @param bool   $allowSessionAuth config.php's 'allow_session_auth'
     */
    public function __construct(
        string $tool, bool $toolEnabled, bool $introspection, string $endpoint, bool $allowSessionAuth
    ) {
        if ($tool !== self::GRAPHIQL && $tool !== self::VOYAGER) {
            throw new \InvalidArgumentException('Unknown developer tool: ' . $tool);
        }

        $this->tool = $tool;
        $this->enabled = $toolEnabled && $introspection;
        $this->endpoint = $endpoint;
        $this->allowSessionAuth = $allowSessionAuth;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response
    ): ResponseInterface {
        if (!$this->enabled) {
            $response->getBody()->write('Not found');

            return $response->withStatus(404)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response->getBody()->write($this->html($this->csrfToken()));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // The page embeds the session's CSRF token.
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The page's markup.
     *
     * @param string|null $csrf The token to send with every query, or null
     *                          for a visitor with no panel session.
     */
    public function html(?string $csrf): string
    {
        $headers = $csrf === null ? array() : array('X-iMSCP-CSRF' => $csrf);

        // json_encode with the HEX flags is safe inside a <script> element:
        // no '<', '>', '&' or quote survives unescaped to close it.
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $endpointJs = json_encode($this->endpoint, $flags);
        $headersJs = json_encode((object)$headers, $flags);

        return $this->tool === self::VOYAGER
            ? $this->voyagerHtml($endpointJs, $headersJs)
            : $this->graphiqlHtml($endpointJs, $headersJs);
    }

    /**
     * The token AuthenticateMiddleware::fromSession() checks, minted exactly
     * as frontend/common.php's csrfToken() mints it, or null when there is
     * no panel session for it to authenticate.
     */
    private function csrfToken(): ?string
    {
        if (!$this->allowSessionAuth || empty($_SESSION['user_id'])) {
            return null;
        }

        if (empty($_SESSION['graphql_csrf'])) {
            $_SESSION['graphql_csrf'] = bin2hex(random_bytes(16));
        }

        return (string)$_SESSION['graphql_csrf'];
    }

    private function graphiqlHtml(string $endpointJs, string $headersJs): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GraphiQL</title>
  <style>
    body { margin: 0; height: 100vh; overflow: hidden; }
    #graphiql { height: 100vh; }
  </style>
  <link rel="stylesheet" href="/api/graphql/explorer-assets/graphiql.min.css">
</head>
<body>
  <div id="graphiql"></div>
  <script src="/api/graphql/explorer-assets/react.production.min.js"></script>
  <script src="/api/graphql/explorer-assets/react-dom.production.min.js"></script>
  <script src="/api/graphql/explorer-assets/graphiql.min.js"></script>
  <script>
  (function () {
      'use strict';

      var fetcher = GraphiQL.createFetcher({
          url: {$endpointJs},
          headers: {$headersJs},
          fetch: function (input, init) {
              init = init || {};
              init.credentials = 'same-origin';
              return window.fetch(input, init);
          }
      });

      ReactDOM.createRoot(document.getElementById('graphiql'))
          .render(React.createElement(GraphiQL, {fetcher: fetcher}));
  })();
  </script>
</body>
</html>

HTML;
    }

    private function voyagerHtml(string $endpointJs, string $headersJs): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GraphQL Voyager</title>
  <style>
    body { margin: 0; height: 100vh; overflow: hidden; }
    #voyager { height: 100vh; }
    #voyager.failed { height: auto; padding: 2em; font: 16px sans-serif; }
  </style>
  <link rel="stylesheet" href="/api/graphql/voyager-assets/voyager.css">
</head>
<body>
  <main id="voyager"></main>
  <script src="/api/graphql/voyager-assets/voyager.standalone.js"></script>
  <script>
  (function () {
      'use strict';

      var headers = {$headersJs};
      headers['Content-Type'] = 'application/json';
      headers['Accept'] = 'application/json';

      var introspection = window.fetch({$endpointJs}, {
          method: 'POST',
          credentials: 'same-origin',
          headers: headers,
          body: JSON.stringify({query: GraphQLVoyager.voyagerIntrospectionQuery})
      }).then(function (response) {
          return response.json();
      }).then(function (result) {
          if (result && result.data) {
              return result;
          }

          // Voyager has no error state of its own - it would spin forever.
          var errors = (result && result.errors) || [];
          var unauthenticated = errors.some(function (e) {
              return e.extensions && e.extensions.code === 'UNAUTHENTICATED';
          });
          var message = unauthenticated
              ? 'Sign in to the control panel in this browser, then reload this page.'
              : 'The schema could not be read: '
                  + errors.map(function (e) { return e.message; }).join(' ');
          var box = document.getElementById('voyager');
          box.textContent = message;
          box.className = 'failed';
          throw new Error(message);
      });

      GraphQLVoyager.renderVoyager(document.getElementById('voyager'), {
          introspection: introspection
      });
  })();
  </script>
</body>
</html>

HTML;
    }
}
