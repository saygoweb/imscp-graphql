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

use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Http\GraphQLHandler;
use iMSCP\Plugin\SGW_GraphQL\Resolver\ViewerResolver;
use iMSCP\Plugin\SGW_GraphQL\Schema\ResolverMap;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use PHPUnit\Framework\TestCase;
use Slim\Http\Environment;
use Slim\Http\Request;
use Slim\Http\Response;

class GraphQLHandlerTest extends TestCase
{
    /**
     * DocumentValidator::addRule() writes into a process-global static
     * registry keyed by rule class name (see
     * webonyx/graphql-php src/Validator/DocumentValidator.php). graphql-php
     * v15 has no public DocumentValidator::initRules() to undo that — the
     * name in the brief refers to a private static bool flag, not a callable
     * method.
     *
     * removeRule() looked like the obvious substitute, since it unsets the
     * very same key addRule() would have written — but GraphQL::executeQuery()
     * unconditionally does
     * DocumentValidator::getRule(QueryComplexity::class)->setRawVariableValues(...)
     * whenever $validationRules is left null, and expects that key to always
     * resolve to a QueryComplexity instance. Removing it outright turns the
     * very next default-rules query anywhere in the suite into a fatal "call
     * on null". Re-adding the disabled default instance keeps the key
     * present and gets back to "no custom limit", which is what a fresh
     * process has.
     */
    protected function setUp(): void
    {
        DocumentValidator::addRule(new QueryDepth(QueryDepth::DISABLED));
        DocumentValidator::addRule(new QueryComplexity(QueryComplexity::DISABLED));
        DocumentValidator::addRule(new DisableIntrospection(DisableIntrospection::DISABLED));
    }

    private function identity(): Identity
    {
        return new Identity(7, 'wpcache.test', 'user', 3, 'c@example.com',
                            ['DOMAINS_READ'], 42);
    }

    private function handler(array $options = []): GraphQLHandler
    {
        $viewer = new ViewerResolver('1.0.0');

        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql',
            null,
            new ResolverMap($viewer->map())
        );

        return new GraphQLHandler($factory, array_merge([
            'debug' => false, 'introspection' => true,
            'maxQueryDepth' => 15, 'maxQueryComplexity' => 1000,
        ], $options));
    }

    private function post(string $query, ?array $variables = null, bool $withIdentity = true): Response
    {
        $body = json_encode(array_filter(
            ['query' => $query, 'variables' => $variables],
            static function ($v) { return $v !== null; }
        ));

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $request = Request::createFromEnvironment($env);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        $request = $request->withBody(new \Slim\Http\Stream($stream));

        if ($withIdentity) {
            $request = $request->withAttribute('identity', $this->identity());
        }

        return ($this->handler())($request, new Response(), []);
    }

    public function testItAnswersTheViewerQuery(): void
    {
        $response = $this->post('{ viewer { id username role scopes } }');
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('errors', $body, (string)$response->getBody());
        self::assertSame('wpcache.test', $body['data']['viewer']['username']);
        self::assertSame('CUSTOMER', $body['data']['viewer']['role']);
        self::assertSame(['DOMAINS_READ'], $body['data']['viewer']['scopes']);
    }

    public function testTheViewerIdIsAnOpaqueGlobalIdentifier(): void
    {
        $body = json_decode((string)$this->post('{ viewer { id } }')->getBody(), true);

        self::assertSame(
            \iMSCP\Plugin\SGW_GraphQL\Support\GlobalId::encode('Viewer', 7),
            $body['data']['viewer']['id']
        );
    }

    public function testItAnswersApiVersion(): void
    {
        $body = json_decode((string)$this->post('{ apiVersion }')->getBody(), true);

        self::assertSame('1.0.0', $body['data']['apiVersion']);
    }

    public function testTheResponseIsAlwaysJson(): void
    {
        $response = $this->post('{ apiVersion }');

        self::assertStringStartsWith(
            'application/json', $response->getHeaderLine('Content-Type')
        );
    }

    public function testTheResponseIsNeverCached(): void
    {
        $response = $this->post('{ apiVersion }');

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAMalformedBodyIsA400(): void
    {
        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $request = Request::createFromEnvironment($env)
            ->withAttribute('identity', $this->identity());

        $response = ($this->handler())($request, new Response(), []);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('BAD_USER_INPUT', $body['errors'][0]['extensions']['code']);
    }

    public function testASyntaxErrorIsA400WithAGraphQlEnvelope(): void
    {
        $response = $this->post('{ viewer { ');
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('errors', $body);
    }

    public function testAnOverDeepQueryIsRefused(): void
    {
        // QueryDepth counts levels of *nested selection sets*, not fields:
        // '{ viewer { username } }' bottoms out at a scalar one level in and
        // measures as depth 0 regardless of the configured limit — this
        // schema's Viewer type has no nested object field to nest into. An
        // introspection query is the only way on this SDL to build a
        // genuinely over-deep document (measured depth 2 here; see
        // task-12-report.md for how that was confirmed against the rule
        // directly), so it is what actually exercises the limit.
        $viewer = new ViewerResolver('1.0.0');
        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql', null,
            new ResolverMap($viewer->map())
        );
        $handler = new GraphQLHandler($factory, [
            'debug' => false, 'introspection' => true,
            'maxQueryDepth' => 1, 'maxQueryComplexity' => 1000,
        ]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ __schema { types { fields { name } } } }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $response = $handler($request, new Response(), []);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'QUERY_TOO_COMPLEX', $body['errors'][0]['extensions']['code'],
            (string)$response->getBody()
        );
    }

    public function testAnOverComplexQueryIsRefused(): void
    {
        // 0 is QuerySecurityRule::DISABLED, not "breach on anything above
        // zero" — passing it turns the rule off rather than tightening it
        // (QuerySecurityRule::isEnabled() checks `!== self::DISABLED`), so
        // this needs a real positive limit undercut by a real query. Every
        // non-introspection field costs 1 by default (see
        // QueryComplexity::nodeComplexity()'s "$childrenComplexity + 1"
        // fallback): 'apiVersion' costs 1 and 'viewer { username }' costs 2
        // (1 for username, +1 for viewer), for a total of 3 against a limit
        // of 1.
        $handler = $this->handler(['maxQueryComplexity' => 1]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ apiVersion viewer { username } }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $response = $handler($request, new Response(), []);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            'QUERY_TOO_COMPLEX', $body['errors'][0]['extensions']['code'],
            (string)$response->getBody()
        );
    }

    public function testAnOrdinaryValidationFailureIsStillBadUserInput(): void
    {
        // The control for the two tests above: an unknown field is a
        // validation failure with no previous Throwable, exactly like a
        // depth or complexity breach, but it is not one of those rules. If
        // the handler mapped every codeless validation error to
        // QUERY_TOO_COMPLEX instead of recognising the specific rules, this
        // would fail alongside them passing.
        $body = json_decode(
            (string)$this->post('{ viewer { thisFieldDoesNotExist } }')->getBody(), true
        );

        self::assertArrayHasKey('errors', $body, (string)json_encode($body));
        self::assertSame('BAD_USER_INPUT', $body['errors'][0]['extensions']['code']);
    }

    public function testIntrospectionCanBeTurnedOff(): void
    {
        $viewer = new ViewerResolver('1.0.0');
        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql', null,
            new ResolverMap($viewer->map())
        );
        $handler = new GraphQLHandler($factory, [
            'debug' => false, 'introspection' => false,
            'maxQueryDepth' => 15, 'maxQueryComplexity' => 1000,
        ]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ __schema { types { name } } }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $body = json_decode((string)$handler($request, new Response(), [])->getBody(), true);

        self::assertArrayHasKey('errors', $body);
    }

    public function testIntrospectionWorksAgainAfterAPriorRequestDisabledIt(): void
    {
        // Proves the fix for the leak the brief warns about: without
        // explicitly re-asserting DisableIntrospection::DISABLED on every
        // call, a later request in the same process would stay locked out of
        // introspection because of a rule an earlier, unrelated request left
        // behind in DocumentValidator's static registry.
        $viewer = new ViewerResolver('1.0.0');
        $factory = new SchemaFactory(
            dirname(__DIR__, 3) . '/schema/schema.graphql', null,
            new ResolverMap($viewer->map())
        );

        $off = new GraphQLHandler($factory, [
            'debug' => false, 'introspection' => false,
            'maxQueryDepth' => 15, 'maxQueryComplexity' => 1000,
        ]);
        $on = new GraphQLHandler($factory, [
            'debug' => false, 'introspection' => true,
            'maxQueryDepth' => 15, 'maxQueryComplexity' => 1000,
        ]);

        $identity = $this->identity();
        $introspect = static function () use ($identity): Request {
            $env = Environment::mock([
                'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
                'CONTENT_TYPE' => 'application/json',
            ]);
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, json_encode(['query' => '{ __schema { types { name } } }']));
            rewind($stream);

            return Request::createFromEnvironment($env)
                ->withBody(new \Slim\Http\Stream($stream))
                ->withAttribute('identity', $identity);
        };

        $off($introspect(), new Response(), []);
        $body = json_decode((string)$on($introspect(), new Response(), [])->getBody(), true);

        self::assertArrayNotHasKey('errors', $body, (string)json_encode($body));
    }

    public function testAMissingIdentityIsAnInternalErrorNotACrash(): void
    {
        // Authentication is the middleware's job; if the handler is reached
        // without one, that is a wiring bug and must not leak a stack trace.
        // It is a server fault, not the client's, so it answers 500 rather
        // than 400 — narrowly, this test pins the status the reviewer found
        // unpinned.
        $response = $this->post('{ apiVersion }', null, false);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('errors', $body);
        self::assertSame('INTERNAL', $body['errors'][0]['extensions']['code']);
    }

    public function testTheShutdownGuardIsSilentAfterANormalResponse(): void
    {
        $handler = $this->handler();
        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => '{ apiVersion }']));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $handler($request, new Response(), []);

        // The request completed normally; the guard registered as a shutdown
        // callback during __invoke() must be a no-op if it ran right now.
        ob_start();
        $handler->guardAgainstAbruptExit();
        $emitted = ob_get_clean();

        self::assertSame('', $emitted, 'a completed request must never emit a second body');
    }

    public function testTheShutdownGuardEmitsAGraphQlEnvelopeWhenTheOperationNeverCompleted(): void
    {
        $handler = $this->handler();

        // Simulate the scenario the guard defends against: some core helper
        // called exit() partway through the operation, so __invoke() never
        // reached its "completed" assignment. No request needs to be made;
        // the guard reads only the instance's own $completed flag, which
        // starts false and is never set on a freshly constructed handler.
        ob_start();
        $handler->guardAgainstAbruptExit();
        $emitted = ob_get_clean();

        $body = json_decode($emitted, true);

        self::assertIsArray($body, $emitted);
        self::assertArrayHasKey('errors', $body);
        self::assertSame('INTERNAL', $body['errors'][0]['extensions']['code']);
    }

    // ---- Specification section 10.3's second bucket ----

    /**
     * The handler is the only place that knows a document is a mutation - the
     * middleware sees a raw body - so it is the only place the `mutations`
     * bucket can be charged. These pin *when* it charges, which is the part a
     * reader of RateLimitMiddleware cannot see.
     *
     * @param array<string, mixed> $options
     */
    private function postCharging(
        string $query, array &$charged, int $retryAfter = 0, array $options = []
    ): Response {
        $handler = $this->handler(array_merge([
            'chargeMutation' => static function ($request) use (&$charged, $retryAfter) {
                $charged[] = $request;

                return $retryAfter;
            }
        ], $options));

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode(['query' => $query]));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        return $handler($request, new Response(), []);
    }

    public function testAMutationIsChargedToTheMutationsBucket(): void
    {
        $charged = [];
        $this->postCharging('mutation { subdomainDelete(id: "x") { id } }', $charged);

        self::assertCount(1, $charged);
    }

    public function testAQueryIsNotChargedToTheMutationsBucket(): void
    {
        // Without this the test above would pass against a handler that
        // charged every request twice, which would make the queries limit a
        // quarter of what an operator configured.
        $charged = [];
        $this->postCharging('{ apiVersion }', $charged);

        self::assertSame([], $charged);
    }

    public function testANamedMutationInADocumentOfSeveralOperationsIsCharged(): void
    {
        $charged = [];
        $handler = $this->handler([
            'chargeMutation' => static function () use (&$charged) {
                $charged[] = true;

                return 0;
            }
        ]);

        $env = Environment::mock([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/graphql',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode([
            'query' => 'query Read { apiVersion } mutation Write { subdomainDelete(id: "x") { id } }',
            'operationName' => 'Write'
        ]));
        rewind($stream);
        $request = Request::createFromEnvironment($env)
            ->withBody(new \Slim\Http\Stream($stream))
            ->withAttribute('identity', $this->identity());

        $handler($request, new Response(), []);

        self::assertCount(1, $charged, 'the named operation decides, not the first one');
    }

    public function testARefusedMutationIsA429WithNoDataKey(): void
    {
        $charged = [];
        $response = $this->postCharging(
            'mutation { subdomainDelete(id: "x") { id } }', $charged, 17
        );
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('17', $response->getHeaderLine('Retry-After'));
        self::assertSame('RATE_LIMITED', $body['errors'][0]['extensions']['code']);
        self::assertSame(17, $body['errors'][0]['extensions']['retryAfterSeconds']);
        self::assertArrayNotHasKey('data', $body, 'the document must not have executed');
    }

    public function testADocumentThatWillNotParseIsNotCharged(): void
    {
        // Nothing that cannot run is charged for - and the syntax error must
        // still come back looking exactly as it always did, which is what the
        // second assertion is for.
        $charged = [];
        $response = $this->postCharging('mutation { this is not graphql', $charged);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame([], $charged);
        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('errors', $body);
        self::assertArrayNotHasKey('data', $body);
    }

    public function testAHandlerWithNoChargerBehavesExactlyAsItAlwaysDid(): void
    {
        // The option is absent from every GraphQLHandler built before this
        // round, the unit suite's own included; an absent charger is 0.
        $response = $this->post('mutation { subdomainDelete(id: "x") { id } }');

        self::assertNotSame(429, $response->getStatusCode());
    }
}
