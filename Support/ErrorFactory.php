<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

use Throwable;

/**
 * Turns a throwable into the GraphQL error entry a client receives.
 */
final class ErrorFactory
{
    /**
     * @return array{message: string, extensions: array}
     */
    public static function format(Throwable $e, bool $debug): array
    {
        if ($e instanceof ApiException) {
            return array(
                'message'    => $e->getMessage(),
                'extensions' => array_merge(
                    $e->getExtensions(), array('code' => $e->getErrorCode())
                )
            );
        }

        // An unexpected throwable may carry a SQL fragment, a file path or a
        // stack trace. The caller gets a correlation id; the detail goes to the
        // panel's log, where support can join the two.
        $correlationId = bin2hex(random_bytes(8));

        if (function_exists('write_log')) {
            write_log(
                sprintf(
                    'SGW_GraphQL internal error %s: %s in %s:%d',
                    $correlationId, $e->getMessage(), $e->getFile(), $e->getLine()
                ),
                E_USER_ERROR
            );
        }

        return array(
            'message'    => $debug
                ? sprintf('An internal error occurred: %s', $e->getMessage())
                : 'An internal error occurred.',
            'extensions' => array(
                'code'          => ErrorCode::INTERNAL,
                'correlationId' => $correlationId
            )
        );
    }
}
