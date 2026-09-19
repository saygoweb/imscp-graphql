<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use Throwable;

/**
 * Specification section 11's audit trail: one `api_audit` row per request,
 * with every `Secret`-typed value replaced by `***`.
 *
 * **Redaction is by type.** redact() reads the operation's own variable
 * declarations, resolves each one against the schema, and walks the value it
 * was given against the type it was declared as. A password is replaced
 * because its type is `Secret`, not because somebody remembered to put
 * "password" on a list - so a field added to the schema tomorrow carrying a
 * secret is redacted the day it is added, by nobody, and a field named
 * `password` that is not a secret is not needlessly hidden.
 *
 * The three cases that decide whether that claim is true are the ones with no
 * types to walk:
 *
 * - a document that did not parse redacts **everything**. There is nothing to
 *   resolve a type against, so no value can be *shown* to be safe, and an
 *   audit row is not worth a password;
 * - a variable the document does not declare keeps its key and loses its
 *   value, for the same reason: nothing is guessed about a value whose type
 *   is unknown;
 * - a variable whose declared type is not in the schema at all resolves to
 *   nothing, which is the same state of knowledge, and ends the same way.
 *
 * Everything else is a walk: a list walks its elements against the element
 * type, an input object walks the fields it declares and drops every key it
 * does not, and a scalar or an enum is kept as it was given. Keys and array
 * shape survive so that a row is still readable a year later, which is the
 * whole point of writing one. Any *other* type is `***` - the walk only ever
 * passes a value through for a type it can reason about, because
 * AST::typeFromAST() resolves an output type name as readily as an input one
 * and `mutation($p: ApiToken)` is otherwise a way of writing an arbitrary
 * structure into the row (checkpoint D, D7).
 *
 * **What is not in the table, and why.** A row is written by
 * GraphQLHandler, which is the only layer that has the document, the
 * variables and the result envelope at once - so a request refused *before*
 * it gets there has no row. That is every TlsMiddleware refusal, every CORS
 * preflight, every refusal of the `queries` bucket, and - the one worth
 * naming - a credential that was presented and refused, which
 * AuthenticateMiddleware answers with a 401 of its own. None of those ran an
 * operation, and each is already visible where it happened: the rate table
 * counts its own refusals and `api_token`.`last_used_at` moves only for a
 * credential that was accepted. A refused *mutation* that did reach the
 * handler is recorded, outcome and code and all, because by then there is a
 * document to name.
 *
 * Note also that `mutations` mode asks whether the *document* contains a
 * mutation, not whether the operation that ran was one: a batched document
 * that hides a mutation behind a query, or an operation named to look like a
 * query, is recorded all the same.
 *
 * **Decision D27: a failure to write a row never fails the request.** record()
 * catches everything, reports it through Core::writeLog() - the panel's own
 * log, where an operator will see it beside the actions it is missing rows
 * for - and returns. An endpoint that answered 500 because its audit table
 * was missing would be an audit trail that takes the API down, which is a
 * worse failure than the gap in the trail.
 */
final class Audit
{
    /** `audit` in config.php. */
    const MODE_NONE      = 'none';
    const MODE_MUTATIONS = 'mutations';
    const MODE_ALL       = 'all';

    /** What a Secret becomes. */
    const REDACTED = '***';

    /** The scalar the SDL declares for every secret (schema/schema.graphql). */
    const SECRET = 'Secret';

    /** The column widths of `api_audit` (sql/001_create_api_tables.php). */
    const MAX_OPERATION  = 255;
    const MAX_FIELDS     = 1024;
    const MAX_ERROR_CODE = 64;

    /**
     * `variables` is a mediumtext, so this is not the column's limit - it is
     * a limit on what one row is worth. A caller can post megabytes of
     * variables, and sixteen megabytes of them in a row nobody can read is
     * not an audit trail, it is a way of filling a disk.
     */
    const MAX_VARIABLES = 65535;

    /**
     * prune() runs on about one recorded request in this many, the way
     * RateLimiter prunes its own table: no cron, and no request paying for it
     * twice.
     */
    const PRUNE_ODDS = 100;

    /** @var Db */
    private $db;

    /** @var Schema */
    private $schema;

    /** @var string One of the MODE_ constants. */
    private $mode;

    /** @var int */
    private $retentionDays;

    /** @var callable fn(): int the current Unix time */
    private $now;

    /** @var Core|null */
    private $core;

    /**
     * @param string   $mode          `audit` from config.php: none, mutations
     *                                or all. Anything else is read as
     *                                `mutations`, which is the default the
     *                                shipped config.php carries: an operator
     *                                who mistypes the key gets the documented
     *                                default rather than silence.
     * @param int      $retentionDays `audit_retention_days`. Less than one
     *                                keeps every row for ever; see prune().
     * @param callable $now           fn(): int. Injected so a test can drive a
     *                                clock rather than wait ninety days.
     * @param Core|null $core         Where a failed write is reported
     *                                (decision D27). Null only where there is
     *                                no panel to report to; production always
     *                                passes one, and a null one means a
     *                                failure is swallowed with nothing said,
     *                                which is why Container does not.
     */
    public function __construct(
        Db $db, Schema $schema, string $mode, int $retentionDays, callable $now,
        ?Core $core = null
    ) {
        $this->db = $db;
        $this->schema = $schema;
        $this->mode = $mode === self::MODE_NONE || $mode === self::MODE_ALL
            ? $mode : self::MODE_MUTATIONS;
        $this->retentionDays = $retentionDays;
        $this->now = $now;
        $this->core = $core;
    }

    /**
     * One row for one request, or nothing at all when `audit` says so.
     *
     * Called from GraphQLHandler's `finally`, so a request that threw on its
     * way out is recorded exactly like one that answered.
     *
     * @param DocumentNode|null    $document  Null when the document did not
     *                                        parse - see redact().
     * @param array<string, mixed> $variables As the caller sent them.
     * @param array<string, mixed> $result    The GraphQL envelope, for its
     *                                        `errors` key and nothing else.
     *
     * @return void
     */
    public function record(
        ?DocumentNode $document, array $variables, array $result, AuditContext $context
    ): void {
        try {
            if (!$this->shouldRecord($document)) {
                return;
            }

            $this->write($document, $variables, $result, $context);
            $this->pruneOccasionally();
        } catch (Throwable $e) {
            // D27. Everything: a missing table, a connection that has gone
            // away, a schema that will not resolve a type. The request has
            // already been answered by the time this runs, or is about to be,
            // and nothing here is allowed to change that.
            $this->report($e);
        }
    }

    /**
     * The request's variables with every `Secret` replaced.
     *
     * Static because it is a pure function of the three things it is given,
     * and because the unit suite has no database to build an Audit with. The
     * schema is a parameter rather than a field for the same reason.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    public static function redact(
        ?DocumentNode $document, array $variables, Schema $schema
    ): array {
        if ($document === null) {
            return self::everything($variables);
        }

        $types = self::variableTypes($document, $schema);
        $redacted = array();

        foreach ($variables as $name => $value) {
            $type = array_key_exists($name, $types) ? $types[$name] : null;

            if ($type === null) {
                // Undeclared, or declared as a type the schema does not have.
                // Either way there is nothing to walk it against.
                $redacted[$name] = self::REDACTED;

                continue;
            }

            try {
                $redacted[$name] = self::walk($value, $type);
            } catch (Throwable $e) {
                // A type that will not resolve its own fields. Whatever the
                // reason, the walk did not finish, so the value was not shown
                // to be safe.
                $redacted[$name] = self::REDACTED;
            }
        }

        return $redacted;
    }

    /**
     * Delete the rows `audit_retention_days` says are past keeping.
     *
     * **It cannot delete a live row.** With a retention of one day or more the
     * cut-off is at least a whole day behind the clock, so the row this
     * request has just written is never inside it. A retention of zero - or a
     * negative one, or a key an operator has emptied out - would put the
     * cut-off at *now* and delete the whole table on the next request,
     * including the row just written; that is why less than one day is read
     * as "keep everything" and deletes nothing at all. (Task 11 found the
     * same shape of bug in the rate table's prune, where an unscoped cut-off
     * computed from one window length would have deleted another window's
     * live rows. One table, one retention, one cut-off - but the guard is
     * worth stating, because the failure is silent and total.)
     *
     * @return int rows deleted
     */
    public function prune(): int
    {
        if ($this->retentionDays < 1) {
            return 0;
        }

        $cutoff = (int)call_user_func($this->now) - ($this->retentionDays * 86400);

        if ($cutoff < 1) {
            // A clock before the epoch, or a retention long enough to reach
            // back past it. Nothing is old enough to delete.
            return 0;
        }

        return $this->db->execute('DELETE FROM api_audit WHERE at < ?', array($cutoff));
    }

    /**
     * Whether this document is one the configured mode records.
     */
    private function shouldRecord(?DocumentNode $document): bool
    {
        if ($this->mode === self::MODE_NONE) {
            return false;
        }

        if ($this->mode === self::MODE_ALL) {
            return true;
        }

        // `mutations`: only a document that contains one. A document that did
        // not parse contains nothing that can be named, so it is not one -
        // `all` is the mode that records those.
        return $document !== null && self::hasMutation($document);
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $result
     *
     * @return void
     */
    private function write(
        ?DocumentNode $document, array $variables, array $result, AuditContext $context
    ): void {
        $errors = isset($result['errors']) && is_array($result['errors'])
            ? $result['errors'] : array();

        $this->db->execute(
            'INSERT INTO api_audit'
                . ' (at, admin_id, token_id, ip, operation, fields, variables,'
                . ' outcome, error_code, duration_ms)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array(
                (int)call_user_func($this->now),
                $context->getAdminId(),
                $context->getTokenId(),
                $context->getIp(),
                self::cap(self::operationName($document), self::MAX_OPERATION),
                self::cap(self::fieldNames($document), self::MAX_FIELDS),
                self::encode(self::redact($document, $variables, $this->schema)),
                $errors === array() ? 'ok' : 'error',
                self::cap(self::firstErrorCode($errors), self::MAX_ERROR_CODE),
                $context->durationMs()
            )
        );
    }

    /**
     * @return void
     */
    private function pruneOccasionally(): void
    {
        if (random_int(1, self::PRUNE_ODDS) !== 1) {
            return;
        }

        $this->prune();
    }

    /**
     * Say out loud that a row is missing, in the log an operator already
     * reads (decision D27).
     *
     * The message carries the exception's class and text and nothing from the
     * request: the row's own values never reach it, and the only values that
     * could have reached the exception at all have been through redact()
     * first.
     *
     * @return void
     */
    private function report(Throwable $e): void
    {
        if ($this->core === null) {
            return;
        }

        try {
            $this->core->writeLog(
                sprintf(
                    'SGW_GraphQL: an API request could not be audited (%s: %s). '
                        . 'The request itself was answered; see docs/SPECIFICATION.md section 11.',
                    get_class($e), $e->getMessage()
                ),
                E_USER_ERROR
            );
        } catch (Throwable $ignored) {
            // The log is the panel's, and it inserts a row and may send mail.
            // If even that is unavailable there is nowhere left to say so,
            // and the one thing still not allowed is to fail the request.
        }
    }

    /**
     * Every variable redacted, for a document that gave no types to walk.
     *
     * The keys survive and every value goes: which variables a caller sent is
     * worth having, and the shape below the top level is not - there is no
     * type that says any part of it is safe to keep.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private static function everything(array $variables): array
    {
        $redacted = array();

        foreach ($variables as $name => $ignored) {
            $redacted[$name] = self::REDACTED;
        }

        return $redacted;
    }

    /**
     * Each variable the document declares, resolved against the schema.
     *
     * A name that resolves to nothing is kept with a null type rather than
     * left out, so that redact() can tell "declared as something I cannot
     * resolve" from "not declared at all" - both end in `***`, and both are
     * meant to.
     *
     * @return array<string, Type|null>
     */
    private static function variableTypes(DocumentNode $document, Schema $schema): array
    {
        // AST::typeFromAST() takes a type loader, not a schema (graphql-php
        // v15). Schema::getType() answers null for a name the SDL does not
        // define, which is the case that has to reach redact() as a null.
        $loader = static function (string $name) use ($schema) {
            return $schema->getType($name);
        };

        $types = array();

        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }

            foreach ($definition->variableDefinitions as $variable) {
                $name = $variable->variable->name->value;

                try {
                    $type = AST::typeFromAST($loader, $variable->type);
                } catch (Throwable $e) {
                    $type = null;
                }

                if (!array_key_exists($name, $types)) {
                    $types[$name] = $type;

                    continue;
                }

                // A batched document whose operations declare the same name
                // as two different types. Only one of them can be the type of
                // the value that was actually sent, and nothing here knows
                // which operation ran, so neither is trusted.
                $known = $types[$name];

                if ($known === null || $type === null
                    || $known->toString() !== $type->toString()
                ) {
                    $types[$name] = null;
                }
            }
        }

        return $types;
    }

    /**
     * One value against one type.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function walk($value, Type $type)
    {
        if ($type instanceof NonNull) {
            return self::walk($value, $type->getWrappedType());
        }

        if ($value === null) {
            // A null carries nothing, whatever its type says it could have.
            return null;
        }

        if ($type instanceof ListOfType) {
            return self::walkList($value, $type->getWrappedType());
        }

        if ($type instanceof InputObjectType) {
            if (!is_array($value)) {
                // Not the shape the type describes, so the type does not
                // describe it and nothing in it has been shown to be safe.
                return self::REDACTED;
            }

            $fields = $type->getFields();
            $walked = array();

            foreach ($value as $key => $item) {
                if (!isset($fields[(string)$key])) {
                    // A key the type does not declare. The executor would
                    // refuse the document over it; here it is simply dropped,
                    // because there is no type to walk it against - which is
                    // also what stops a caller smuggling a value into the row
                    // under a name the schema has never heard of.
                    continue;
                }

                $walked[$key] = self::walk($item, $fields[(string)$key]->getType());
            }

            return $walked;
        }

        if ($type instanceof NamedType && $type->name() === self::SECRET) {
            return self::REDACTED;
        }

        if ($type instanceof ScalarType || $type instanceof EnumType) {
            // Every other leaf - a String, an Int, an enum, a custom scalar -
            // is what the caller sent. The row exists to say what was done.
            return $value;
        }

        // Anything else is a type this walk cannot reason about, and it fails
        // closed (checkpoint D, D7). AST::typeFromAST() resolves an *output*
        // type name as happily as an input one, so `mutation($p: ApiToken)`
        // used to reach this line and store the caller's whole structure raw -
        // up to 64KB of unvalidated content in `api_audit`.`variables`, under
        // a type that says nothing about it. An unresolvable type is already
        // `***`; a resolvable one that cannot be walked is no better known.
        return self::REDACTED;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function walkList($value, Type $inner)
    {
        // GraphQL coerces a single value into a one-element list, so a value
        // that is not a list is walked against the element type rather than
        // refused. An associative array in a list position is that case too:
        // it is an input object, not a list of them.
        if (!is_array($value) || !self::isList($value)) {
            return self::walk($value, $inner);
        }

        $walked = array();

        foreach ($value as $item) {
            $walked[] = self::walk($item, $inner);
        }

        return $walked;
    }

    /**
     * Whether an array is a list - sequential integer keys from zero.
     *
     * array_is_list() is PHP 8.1 and this plugin targets 7.4.
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;

        foreach ($value as $key => $ignored) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }

    private static function hasMutation(DocumentNode $document): bool
    {
        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode
                && $definition->operation === 'mutation'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The operation's name, or null for an anonymous one.
     *
     * The first named operation in the document. A document with several
     * operations is refused by the executor unless the request names which
     * one to run, and record() is not told which that was - so this is a
     * label, and fieldNames() below is the part that is exhaustive.
     */
    private static function operationName(?DocumentNode $document): ?string
    {
        if ($document === null) {
            return null;
        }

        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode
                && $definition->name !== null
            ) {
                return $definition->name->value;
            }
        }

        return null;
    }

    /**
     * The top-level field names of every operation in the document, in order
     * and without repeats.
     *
     * Every operation, not only the one that ran: a batched document is one
     * request and gets one row, and a row that named only the first of its
     * operations would be an audit trail with a hole in exactly the shape an
     * attacker would choose.
     */
    private static function fieldNames(?DocumentNode $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $names = array();

        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue;
            }

            foreach ($definition->selectionSet->selections as $selection) {
                // A fragment spread at the top level of a mutation is not
                // legal, and a field is the only other thing that can be
                // there. Named rather than aliased: the row says which field
                // was invoked, not what the client chose to call it.
                if ($selection instanceof FieldNode
                    && !in_array($selection->name->value, $names, true)
                ) {
                    $names[] = $selection->name->value;
                }
            }
        }

        return $names === array() ? null : implode(',', $names);
    }

    /**
     * @param array<int, mixed> $errors
     */
    private static function firstErrorCode(array $errors): ?string
    {
        foreach ($errors as $error) {
            if (is_array($error) && isset($error['extensions']['code'])) {
                return (string)$error['extensions']['code'];
            }
        }

        return null;
    }

    /**
     * The redacted variables as JSON the column can hold.
     *
     * Always valid JSON, including when it is too big or will not encode:
     * something has to read this column back, and a truncated object with its
     * braces cut off is worse than a short one that says it was dropped.
     *
     * @param array<string, mixed> $redacted
     */
    private static function encode(array $redacted): string
    {
        $json = json_encode(
            $redacted,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            return (string)json_encode(array('_unencodable' => true));
        }

        if (strlen($json) <= self::MAX_VARIABLES) {
            return $json;
        }

        return (string)json_encode(array(
            '_dropped' => 'too large for one audit row', '_bytes' => strlen($json)
        ));
    }

    private static function cap(?string $value, int $length): ?string
    {
        return $value === null ? null : substr($value, 0, $length);
    }
}
