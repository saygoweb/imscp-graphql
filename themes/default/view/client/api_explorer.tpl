<div class="info">{TR_INTRO}</div>

<!-- BDP: disabled_block -->
<div class="static_info">{TR_DISABLED}</div>
<!-- EDP: disabled_block -->

<!-- BDP: explorer_block -->
<style>
    #sgw-graphiql-explorer {
        height: 80vh;
        min-height: 480px;
        border: 1px solid #ccc;
        margin: 1em 0;
    }
</style>
<link rel="stylesheet" href="/api/graphql/explorer-assets/graphiql.min.css">
<div id="sgw-graphiql-explorer"></div>
<script src="/api/graphql/explorer-assets/react.production.min.js"></script>
<script src="/api/graphql/explorer-assets/react-dom.production.min.js"></script>
<script src="/api/graphql/explorer-assets/graphiql.min.js"></script>
<script>
(function () {
    'use strict';

    // Spec section 5.2: a request authenticated by the panel session must
    // carry a JSON content type and the CSRF header the session emits, or
    // AuthenticateMiddleware treats it as unauthenticated.
    var fetcher = GraphiQL.createFetcher({
        url: '{ENDPOINT_JS}',
        headers: {
            // No explicit Content-Type here: createFetcher()'s default
            // JSON fetcher already sets one ('content-type', lower case).
            // Adding 'Content-Type' (capital C) alongside it used to survive
            // as a SECOND header of the same name - the Fetch Headers
            // constructor treats header names case-insensitively and
            // *appends* rather than overwrites, so the request went out as
            // 'content-type: application/json, application/json'. Harmless
            // to AuthenticateMiddleware's stripos() prefix check, but wrong,
            // and it is exactly the kind of divergence-from-the-library
            // default worth not carrying.
            'X-iMSCP-CSRF': '{CSRF_JS}'
        },
        fetch: function (input, init) {
            // Explicit rather than relying on the browser's fetch() default:
            // this is what carries the panel session cookie.
            init = init || {};
            init.credentials = 'same-origin';
            return window.fetch(input, init);
        }
    });

    var root = ReactDOM.createRoot(document.getElementById('sgw-graphiql-explorer'));
    root.render(React.createElement(GraphiQL, {fetcher: fetcher}));
})();
</script>
<!-- EDP: explorer_block -->

<div class="static_info">
    <p>{TR_ENDPOINT}: <code>{ENDPOINT}</code></p>
    <p>{TR_SCHEMA}: <a href="{SCHEMA_LINK}"><code>{SCHEMA_ENDPOINT}</code></a></p>
</div>
