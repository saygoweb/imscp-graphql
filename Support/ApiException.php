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

use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * An error the caller is meant to see, carrying a specification section 9 code.
 *
 * Anything that is not one of these is an INTERNAL error and its message is not
 * shown to the caller.
 */
class ApiException extends Exception
{
    /** @var string */
    private $errorCode;

    /** @var array */
    private $extensions;

    public function __construct(
        string $code,
        string $message,
        array $extensions = array(),
        ?Throwable $previous = null
    ) {
        if (!ErrorCode::isValid($code)) {
            throw new InvalidArgumentException(sprintf('Unknown error code "%s".', $code));
        }

        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
        $this->extensions = $extensions;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getHttpStatus(): int
    {
        return ErrorCode::httpStatus($this->errorCode);
    }
}
