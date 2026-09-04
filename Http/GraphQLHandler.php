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
use GraphQL\GraphQL;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Schema\SchemaFactory;
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

        try {
            $input = $this->parseBody($request);
        } catch (ApiException $e) {
            $this->completed = true;

            return $this->json($response, 400, array(
                'errors' => array(ErrorFactory::format($e, (bool)$this->options['debug']))
            ));
        }

        // Authentication is the middleware's job. Every route this handler
        // serves sits behind AuthenticateMiddleware, so an identity is always
        // present by the time a request gets here; a missing one is a wiring
        // bug, not something a caller could trigger. It is caught here rather
        // than left to whichever resolver first happens to need one, because
        // a query that touches none of them (apiVersion, say) would otherwise
        // run to a normal 200 despite the pipeline being broken.
        if (!($request->getAttribute('identity') instanceof Identity)) {
            $this->completed = true;

            return $this->json($response, 500, array('errors' => array(ErrorFactory::format(
                new ApiException(
                    ErrorCode::INTERNAL, 'The request reached the handler with no identity.'
                ),
                (bool)$this->options['debug']
            ))));
        }

        $debugFlags = $this->options['debug']
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;

        try {
            $this->applyValidationRules();

            $result = GraphQL::executeQuery(
                $this->schemaFactory->create(),
                $input['query'],
                null,
                array('identity' => $request->getAttribute('identity')),
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
                        'extensions' => array('code' => ErrorCode::BAD_USER_INPUT)
                    );
                }

                if ($error->path !== null) {
                    $formatted['path'] = $error->path;
                }

                return $formatted;
            });

            $output = $result->toArray($debugFlags);
            $status = $this->statusFor($output);
        } catch (Throwable $e) {
            $output = array(
                'errors' => array(ErrorFactory::format($e, (bool)$this->options['debug']))
            );
            $status = 500;
        }

        $this->completed = true;

        return $this->json($response, $status, $output);
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
