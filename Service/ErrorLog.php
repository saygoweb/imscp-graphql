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
use GraphQL\Type\Schema;
use Throwable;

/**
 * Every error the API answers with, one line each, in a file an operator can
 * tail - `error_log` in config.php, gui/data/logs/sgw_graphql.log by default.
 *
 * The audit row (spec section 11) says a request failed and with which code;
 * it does not keep the message, and with `audit` at its `mutations` default it
 * keeps no row for a failed query at all. The message is what says *which*
 * CONFLICT a client hit - a name already taken, or a domain the backend has not
 * finished with - so this keeps it, with who asked and what they sent.
 *
 * gui/data/logs rather than /var/log/imscp: the panel's pool runs as its own
 * user, which /var/log/imscp (root:imscp 0750) does not let write. That
 * directory is the backend daemon's.
 *
 * Like the audit (decision D27), a failure to write never fails the request:
 * it goes to PHP's own error_log and the request is answered regardless.
 */
final class ErrorLog
{
    /** `error_log`'s shipped value, relative to the panel's gui/ directory. */
    const DEFAULT_PATH = 'data/logs/sgw_graphql.log';

    /** The log is rotated to one `.1` file past this size. */
    const MAX_BYTES = 10485760;

    /** What one line's variables are cut to; the rest is in the audit row. */
    const MAX_VARIABLES = 2048;

    /** @var string */
    private $path;

    /** @var Schema */
    private $schema;

    /** @var callable fn(): int the current Unix time */
    private $now;

    /** @var int */
    private $maxBytes;

    /**
     * @param Schema   $schema What the variables are redacted against
     *                         (Audit::redact()): a Secret never reaches a file.
     * @param callable $now    fn(): int
     */
    public function __construct(string $path, Schema $schema, callable $now, int $maxBytes = self::MAX_BYTES)
    {
        $this->path = $path;
        $this->schema = $schema;
        $this->now = $now;
        $this->maxBytes = $maxBytes;
    }

    /**
     * A line for each error in the envelope; nothing for a success.
     *
     * @param array<string, mixed> $variables As the caller sent them.
     * @param array<string, mixed> $result    The GraphQL envelope.
     */
    public function record(
        ?DocumentNode $document, array $variables, array $result, AuditContext $context
    ): void {
        try {
            $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array();

            if ($errors === array()) {
                return;
            }

            $request = sprintf(
                'admin=%d token=%s via=%s ip=%s operation=%s fields=%s',
                $context->getAdminId(),
                $context->getTokenId() === null ? '-' : (string)$context->getTokenId(),
                $context->getVia(),
                $context->getIp() === '' ? '-' : $context->getIp(),
                Audit::operationName($document) ?? '-',
                Audit::fieldNames($document) ?? '-'
            );
            $sent = self::json(Audit::redact($document, $variables, $this->schema));

            if (strlen($sent) > self::MAX_VARIABLES) {
                $sent = substr($sent, 0, self::MAX_VARIABLES) . '...';
            }

            $at = gmdate('Y-m-d H:i:s', (int)call_user_func($this->now));
            $lines = '';

            foreach ($errors as $error) {
                $error = is_array($error) ? $error : array();
                $extensions = isset($error['extensions']) && is_array($error['extensions'])
                    ? $error['extensions'] : array();
                $code = isset($extensions['code']) ? (string)$extensions['code'] : 'UNKNOWN';
                unset($extensions['code']);

                $lines .= sprintf(
                    "[%s] [%s] %s path=%s: %s%s variables=%s\n",
                    $at,
                    $code,
                    $request,
                    isset($error['path']) && is_array($error['path']) ? implode('.', $error['path']) : '-',
                    self::oneLine(isset($error['message']) ? (string)$error['message'] : ''),
                    $extensions === array() ? '' : ' ' . self::json($extensions),
                    $sent
                );
            }

            $this->append($lines);
        } catch (Throwable $e) {
            error_log(sprintf('SGW_GraphQL: an API error could not be logged to %s (%s: %s).',
                $this->path, get_class($e), $e->getMessage()));
        }
    }

    private function append(string $lines): void
    {
        clearstatcache(true, $this->path);

        if (is_file($this->path) && filesize($this->path) + strlen($lines) > $this->maxBytes) {
            // One older file is enough to read back across a rotation, and it
            // bounds the log at twice the limit with no cron to do it.
            @rename($this->path, $this->path . '.1');
        }

        if (@file_put_contents($this->path, $lines, FILE_APPEND | LOCK_EX) === false) {
            $error = error_get_last();

            throw new \RuntimeException($error === null ? 'the write failed' : $error['message']);
        }
    }

    /** A message on one line, so one error is one line to grep. */
    private static function oneLine(string $message): string
    {
        return trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', $message));
    }

    /** @param mixed $value */
    private static function json($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '{"_unencodable":true}' : $json;
    }
}
