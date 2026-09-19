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

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
use iMSCP\Plugin\SGW_GraphQL\Service\Audit;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\AuditContext;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Parses the request, executes the operation and serialises the result.
 *
 * The shutdown guard exists because a core helper can still terminate the
 * request: showErrorPage() (gui/include/View.php:932) checks the Accept header
 * and, for application/json, exits with a body of its own. A GraphQL client
 * sends exactly that header. Pre-checks are meant to make that unreachable;
 * this is what happens when one has a hole.
 */
final class GraphQLHandler
{
    /** @var SchemaFactory */
    private $schemaFactory;

    /** @var array */
    private $options;

    /** @var bool */
    private $completed = false;

    public function __construct(SchemaFactory $schemaFactory, array $options)
    {
        $this->schemaFactory = $schemaFactory;
        $this->options = $options;
    }

    public function __invoke(
        ServerRequestInterface $request, ResponseInterface $response, array $args
    ): ResponseInterface {
        $this->completed = false;
        register_shutdown_function(array($this, 'guardAgainstAbruptExit'));

        // Specification section 11's audit row is written from the `finally`
        // at the foot of this method, so that a request which threw on its
        // way out is recorded exactly like one that answered. Everything the
        // row needs is a local of this method, which is why the whole body is
        // one try rather than a call to something that could return without
        // being recorded.
        $auditContext = new AuditContext(
            (string)($request->getServerParams()['REMOTE_ADDR'] ?? ''), microtime(true)
        );
        $parsed = null;
        $variables = array();
        $output = array();

        try {
            try {
                $input = $this->parseBody($request);
            } catch (ApiException $e) {
                $this->completed = true;
                $output = array(
                    'errors' => array(ErrorFactory::format($e, (bool)$this->options['debug']))
                );

                return $this->json($response, 400, $output);
            }

            // Recorded as the caller sent them, and redacted by Audit against
            // the types the document declares - never trimmed here, because
            // this layer has no schema to decide with.
            $variables = (array)$input['variables'];

            // Authentication is the middleware's job, and it always *sets* the
            // identity attribute - to an Identity, or to null for a request that
            // presented no credential at all, which spec section 5.3's
            // `tokenIssue` is allowed to be. An attribute that is not there at
            // all is a different thing: the request never passed through
            // AuthenticateMiddleware, which is a wiring bug, not something a
            // caller could trigger. That is caught here rather than left to
            // whichever resolver first happens to need an identity, because a
            // query that touches none of them (apiVersion, say) would otherwise
            // run to a normal 200 despite the pipeline being broken.
            if (!array_key_exists('identity', $request->getAttributes())) {
                $this->completed = true;
                $output = array('errors' => array(ErrorFactory::format(
                    new ApiException(
                        ErrorCode::INTERNAL, 'The request reached the handler with no identity.'
                    ),
                    (bool)$this->options['debug']
                )));

                return $this->json($response, 500, $output);
            }

            $identity = $request->getAttribute('identity');
            $identity = $identity instanceof Identity ? $identity : null;
            $auditContext->carries($identity);

            $debugFlags = $this->options['debug']
                ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
                : DebugFlag::NONE;

            // The document is parsed here rather than inside executeQuery() so
            // that the operation's *kind* is known before anything runs: spec
            // section 10.3's `mutations` bucket cannot be charged by
            // RateLimitMiddleware, which has only the raw body. The parsed node is
            // then handed to executeQuery(), which takes a DocumentNode as
            // readily as a string, so this costs no second parse.
            //
            // A document that will not parse is left as the string it came in as
            // and handed on unchanged: executeQuery() reports a syntax error with
            // exactly the message, locations and status it always has, from the
            // one Source construction it always used. Nothing that cannot be run
            // is charged for.
            $document = $input['query'];
            $isMutation = false;

            try {
                $document = Parser::parse(new Source($input['query'], 'GraphQL'));
                // The audit's own handle on the document. $document below is
                // whatever executeQuery() is to be given - the node, or the
                // string it could not be made from - and record() takes a node or
                // nothing, because a document that did not parse declares no
                // types and so redacts everything.
                $parsed = $document;
                $isMutation = self::isMutation($document, $input['operationName']);
            } catch (SyntaxError $e) {
                // Deliberately empty; see above. Nothing but the parse is inside
                // this try: a charge that threw - a limiter with no counter to
                // reach, say - must not be caught by the guard around a syntax
                // error and leave the document executing uncounted. That is the
                // one failure this limiter is not allowed to have, so the charge
                // is below rather than here.
                $document = $input['query'];
            }

            // One credential exchange per request (checkpoint D, D8).
            // login_credentials() registers a closure on onAfterAuthentication
            // that captures the plaintext password and never unregisters it,
            // so a second tokenIssue in the same request rewrote the *second*
            // account's admin_pass with a hash of the *first* account's
            // password. Refused here, on the document, before anything
            // authenticates - which is the only place that can be true of.
            if ($parsed !== null && self::tokenIssueFields($parsed) > 1) {
                $this->completed = true;
                $output = array('errors' => array(array(
                    'message'    => 'A request may exchange one credential, not several.',
                    'extensions' => array('code' => ErrorCode::BAD_USER_INPUT)
                )));

                return $this->json($response, 400, $output);
            }

            // Spec section 5.3's exemption is for one field, so it is named
            // here rather than inferred from what the executor happened to
            // complain about. AuthenticateMiddleware lets a credential-less
            // request through because it cannot tell which field was asked
            // for without parsing; this is the first layer that can, and it
            // is where the 401 belongs.
            //
            // needsACredential() below is not enough on its own: it converts
            // to 401 only when some error carries UNAUTHENTICATED, and
            // `{ __schema { types { name } } }` touches no resolver, so it
            // produced none - it answered 200 with the whole schema to anyone
            // who could reach the endpoint (checkpoint D, D3). Turning
            // introspection off stays a separate switch, applied to an
            // authenticated caller by applyValidationRules().
            if ($identity === null && $parsed !== null && !self::onlyTokenIssue($parsed)) {
                $this->completed = true;
                $output = array('errors' => array(array(
                    'message'    => 'Authentication is required.',
                    'extensions' => array('code' => ErrorCode::UNAUTHENTICATED)
                )));

                return $this->json(
                    $response, ErrorCode::httpStatus(ErrorCode::UNAUTHENTICATED), $output
                )->withHeader('WWW-Authenticate', 'Bearer realm="i-MSCP"');
            }

            if ($isMutation) {
                $retryAfter = $this->chargeMutation($request);

                if ($retryAfter > 0) {
                    $this->completed = true;
                    // The refusal envelope in the only shape the audit row reads
                    // it in: an outcome and a code. The response's own body is
                    // not read back for it - a PSR-7 stream that has been
                    // consumed is not always rewindable, and writing an audit row
                    // must never be able to empty a response.
                    $output = array('errors' => array(array(
                        'message'    => 'Too many requests.',
                        'extensions' => array('code' => ErrorCode::RATE_LIMITED)
                    )));

                    return RateLimitMiddleware::refuse($response, $retryAfter);
                }
            }

            try {
                $this->applyValidationRules();

                $result = GraphQL::executeQuery(
                    $this->schemaFactory->create(),
                    $document,
                    null,
                    // Null when the caller presented no credential.
                    // ViewerResolver::identityFrom() answers UNAUTHENTICATED for
                    // that and INTERNAL for a context with no such key at all, so
                    // the two stay as far apart here as they are above.
                    // `audit` is how the one field served without a credential
                    // gets an account into its row: tokenIssue authenticates
                    // inside the resolver, so no layer out here can know who the
                    // request turned out to be unless the resolver says. See
                    // AuditContext::actedAs().
                    // `pageMax` is config.php's max_page_size, carried here
                    // because TypeResolver::page() applies it and only the
                    // request knows what this installation configured. It is
                    // absent from any context built by hand, and
                    // TypeResolver::pageMax() falls back to PAGE_MAX for
                    // that.
                    array(
                        'identity' => $identity,
                        'audit'    => $auditContext,
                        'pageMax'  => (int)($this->options['maxPageSize']
                            ?? TypeResolver::PAGE_MAX)
                    ),
                    $input['variables'],
                    $input['operationName']
                );

                $result->setErrorFormatter(function ($error) {
                    $previous = $error->getPrevious();

                    if ($previous instanceof Throwable) {
                        $formatted = ErrorFactory::format($previous, (bool)$this->options['debug']);
                    } else {
                        $formatted = array(
                            'message'    => $error->getMessage(),
                            'extensions' => array(
                                'code' => $this->isDepthOrComplexityBreach($error)
                                    ? ErrorCode::QUERY_TOO_COMPLEX
                                    : ErrorCode::BAD_USER_INPUT
                            )
                        );
                    }

                    if ($error->path !== null) {
                        $formatted['path'] = $error->path;
                    }

                    return $formatted;
                });

                $output = $result->toArray($debugFlags);
                $status = $this->statusFor($output);

                if ($identity === null && self::needsACredential($output)) {
                    // The 401 AuthenticateMiddleware used to answer for this
                    // request, moved one layer in because that is now the only
                    // layer that knows which field was asked for. Spec section 9
                    // maps UNAUTHENTICATED to 401 (ErrorCode::TRANSPORT_STATUS),
                    // and a client that keys off the status rather than the
                    // extension must not start seeing 200 for a request it has
                    // always had to authenticate.
                    $this->completed = true;

                    return $this->json($response, ErrorCode::httpStatus(ErrorCode::UNAUTHENTICATED), $output)
                        ->withHeader('WWW-Authenticate', 'Bearer realm="i-MSCP"');
                }
            } catch (Throwable $e) {
                $output = array(
                    'errors' => array(ErrorFactory::format($e, (bool)$this->options['debug']))
                );
                $status = 500;
            }

            $this->completed = true;

            return $this->json($response, $status, $output);
        } catch (Throwable $e) {
            // Nothing here answers the request: answering is the inner
            // catch's job, and a throw that reaches this one got past it.
            // What this does is make sure the row written below says the
            // request failed, rather than reading as a success because the
            // envelope it never got to build has no errors in it. The throw
            // then goes on exactly as it did before there was an audit.
            $output = array('errors' => array(array(
                'message'    => 'The request did not complete.',
                'extensions' => array('code' => ErrorCode::INTERNAL)
            )));

            throw $e;
        } finally {
            $this->audit($parsed, $variables, $output, $auditContext);
        }
    }

    /**
     * The audit row for this request, if this installation writes them.
     *
     * Decision D27: Audit::record() swallows and reports its own failures, so
     * this catch is the second belt rather than the first. A request must not
     * fail over its audit row on any path at all - including one where the
     * reporting is itself what threw.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $result
     *
     * @return void
     */
    private function audit(
        ?DocumentNode $document, array $variables, array $result, AuditContext $context
    ): void {
        $audit = $this->options['audit'] ?? null;

        if (!$audit instanceof Audit) {
            return;
        }

        try {
            $audit->record($document, $variables, $result, $context);
        } catch (Throwable $e) {
            // Deliberately empty; see above. There is nothing left to say
            // here that Audit::report() has not already tried to say.
            unset($e);
        }
    }

    /**
     * How many `tokenIssue` fields this document asks for, over every
     * operation in it and however they are aliased.
     *
     * Aliases are deliberately not looked at: two aliases of `tokenIssue` are
     * two credential exchanges, whatever the client chose to call them.
     */
    private static function tokenIssueFields(DocumentNode $document): int
    {
        $found = 0;

        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }

            foreach ($definition->selectionSet->selections as $selection) {
                if ($selection instanceof FieldNode
                    && $selection->name->value === 'tokenIssue'
                ) {
                    $found++;
                }
            }
        }

        return $found;
    }

    /**
     * Whether every field this document asks for is `tokenIssue`.
     *
     * Fails closed: a top-level selection that is not a field - a fragment
     * spread, which is legal at the top level of a query - is not something
     * this can show to be `tokenIssue`, so it is not. Every operation in a
     * batched document is asked, not only the one that will run, because
     * which that is depends on `operationName` and the answer must not.
     */
    private static function onlyTokenIssue(DocumentNode $document): bool
    {
        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }

            foreach ($definition->selectionSet->selections as $selection) {
                if (!$selection instanceof FieldNode
                    || $selection->name->value !== 'tokenIssue'
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether an unauthenticated request was refused for want of a credential.
     *
     * Only asked of a request that presented none, and only about the code:
     * an UNAUTHENTICATED anywhere in the envelope means at least one field
     * the caller asked for needed a credential it did not have, which is the
     * whole of what a 401 says.
     *
     * @param array<string, mixed> $output
     */
    private static function needsACredential(array $output): bool
    {
        foreach ($output['errors'] ?? array() as $error) {
            if (($error['extensions']['code'] ?? null) === ErrorCode::UNAUTHENTICATED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emit a parseable GraphQL envelope if the script is about to die with an
     * operation still in flight. Public only because it is a shutdown callback.
     *
     * @return void
     */
    public function guardAgainstAbruptExit(): void
    {
        if ($this->completed) {
            return;
        }

        if (function_exists('write_log')) {
            write_log(
                'SGW_GraphQL: the request terminated before the operation completed. '
                . 'A core helper very likely called exit(). '
                . 'See docs/SPECIFICATION.md section 6.5.',
                E_USER_ERROR
            );
        }

        if (!headers_sent()) {
            header('Content-Type: application/json', true, 500);
        }

        echo json_encode(array('errors' => array(array(
            'message'    => 'An internal error occurred.',
            'extensions' => array('code' => ErrorCode::INTERNAL)
        ))));
    }

    /**
     * Whether the operation this request will run is a mutation.
     *
     * A named operation is looked up by its name, which is what the executor
     * itself does. An unnamed one is the first operation in the document, again
     * as the executor does - and a document with several operations and no
     * name is refused by the executor before anything runs, so which of them
     * this method picked never mattered.
     */
    private static function isMutation(DocumentNode $document, ?string $operationName): bool
    {
        foreach ($document->definitions as $definition) {
            if (!($definition instanceof OperationDefinitionNode)) {
                continue;
            }

            if ($operationName === null) {
                return $definition->operation === 'mutation';
            }

            if ($definition->name !== null && $definition->name->value === $operationName) {
                return $definition->operation === 'mutation';
            }
        }

        return false;
    }

    /**
     * Charge spec section 10.3's `mutations` bucket, in addition to the
     * `queries` bucket RateLimitMiddleware has already charged this request.
     *
     * @return int seconds to wait, or 0 when allowed - which is also the
     *             answer when no charger is wired, as in the unit suite and in
     *             any test that builds this class with the four options it has
     *             always taken.
     */
    private function chargeMutation(ServerRequestInterface $request): int
    {
        $charger = $this->options['chargeMutation'] ?? null;

        if (!is_callable($charger)) {
            return 0;
        }

        return (int)call_user_func($charger, $request);
    }

    /**
     * @return array{query: string, variables: array|null, operationName: string|null}
     * @throws ApiException
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody();

        if (trim($raw) === '') {
            throw new ApiException(ErrorCode::BAD_USER_INPUT, 'The request body is empty.');
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'The request body is not a JSON object.'
            );
        }

        if (!isset($decoded['query']) || !is_string($decoded['query'])) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, 'The request has no "query".',
                array('field' => 'query')
            );
        }

        $variables = $decoded['variables'] ?? null;

        if ($variables !== null && !is_array($variables)) {
            throw new ApiException(
                ErrorCode::BAD_USER_INPUT, '"variables" must be an object.',
                array('field' => 'variables')
            );
        }

        return array(
            'query'         => $decoded['query'],
            'variables'     => $variables,
            'operationName' => isset($decoded['operationName']) && is_string($decoded['operationName'])
                ? $decoded['operationName'] : null
        );
    }

    /**
     * DocumentValidator's rule registry is process-global static state, not
     * per-request: adding a rule here is visible to every subsequent call in
     * the same process, ours included. Under the panel's classic PHP-FPM
     * model each request is a fresh process, so that is harmless. What is not
     * harmless is leaving a rule's effect to whichever value the previous
     * call happened to leave behind, so every option is asserted explicitly
     * on every call rather than only when it differs from the default.
     */
    private function applyValidationRules(): void
    {
        DocumentValidator::addRule(new QueryDepth((int)$this->options['maxQueryDepth']));
        DocumentValidator::addRule(new QueryComplexity((int)$this->options['maxQueryComplexity']));
        DocumentValidator::addRule(new DisableIntrospection(
            $this->options['introspection']
                ? DisableIntrospection::DISABLED
                : DisableIntrospection::ENABLED
        ));
    }

    /**
     * graphql-php's QueryDepth and QueryComplexity rules (see
     * vendor/webonyx/graphql-php/src/Validator/Rules/QueryDepth.php and
     * QueryComplexity.php, v15.37.2 — the version pinned by composer.lock at
     * the time this was written) report a breach by calling
     * `$context->reportError(new Error($message))`. That `Error` carries no
     * previous exception and no code or class of its own to key off; the
     * message text built by each rule's own public static
     * `maxQueryDepthErrorMessage()` / `maxQueryComplexityErrorMessage()`
     * method is the only signal either rule exposes. There is nothing more
     * stable to bind to, so this isolates that coupling in one place: if a
     * future graphql-php release reworks the wording, this is the method
     * that breaks and needs updating, not a conditional buried in the
     * formatter.
     *
     * Both templates share the shape "Max query <thing> should be <max> but
     * got <count>." — matched structurally rather than against a copied
     * literal so a change to either rule's configured limit does not require
     * touching this regular expression.
     */
    private function isDepthOrComplexityBreach(Error $error): bool
    {
        return (bool)preg_match(
            '/^Max query (?:depth|complexity) should be \d+ but got \d+\.$/',
            $error->getMessage()
        );
    }

    /**
     * 200 whenever the envelope is a GraphQL result, whatever is in it; 400
     * when the document could not be run because of what the client sent
     * (a malformed body, a syntax error, a validation failure). A failure
     * *before* the document runs at all — a wiring fault, an unexpected
     * throw around executeQuery() — is a server fault, not the client's,
     * and is answered 500 by this class's other callers rather than here.
     */
    private function statusFor(array $output): int
    {
        if (!isset($output['errors'])) {
            return 200;
        }

        // A document that produced no data at all never executed: that is a
        // validation or syntax failure, which is a transport-level 400.
        if (!array_key_exists('data', $output)) {
            return 400;
        }

        return 200;
    }

    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $body = json_encode(array('errors' => array(array(
                'message'    => 'The response could not be encoded.',
                'extensions' => array('code' => ErrorCode::INTERNAL)
            ))));
        }

        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            // A response keyed to a bearer token must never be cached anywhere.
            ->withHeader('Cache-Control', 'no-store');

        $response->getBody()->write($body);

        return $response;
    }
}
