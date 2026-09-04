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

use InvalidArgumentException;

/**
 * An opaque, type-tagged identifier.
 *
 * i-MSCP's identifiers are per-table integers, so subdomain 3, alias 3 and
 * mail account 3 all exist and belong to different customers. Carrying the
 * type inside the identifier turns "the wrong kind of id" into a parse error
 * at the edge of the request, before any resolver has a chance to authorise
 * against the wrong table.
 *
 * The encoding is not a secret and is not claimed to be one. It is a type tag,
 * not a capability.
 */
final class GlobalId
{
    /** @var string */
    private $type;

    /** @var int */
    private $id;

    private function __construct(string $type, int $id)
    {
        $this->type = $type;
        $this->id = $id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function encode(string $type, int $id): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type)) {
            throw new InvalidArgumentException(sprintf(
                'A global identifier type must be an alphanumeric name; got "%s".', $type
            ));
        }

        if ($id < 1) {
            throw new InvalidArgumentException(
                'A global identifier wraps a positive row identifier.'
            );
        }

        return rtrim(strtr(base64_encode($type . ':' . $id), '+/', '-_'), '=');
    }

    /**
     * @param string|null $expectedType Reject any identifier of another type.
     * @throws InvalidArgumentException
     */
    public static function decode(string $encoded, ?string $expectedType = null): self
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($payload === false || substr_count($payload, ':') !== 1) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        list($type, $id) = explode(':', $payload, 2);

        // '03' and '3' must not both decode to 3, or one row would have two
        // identifiers and any cache keyed on the identifier would be wrong.
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type)
            || !preg_match('/^[1-9][0-9]*$/', $id)
        ) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        if ($expectedType !== null && $type !== $expectedType) {
            throw new InvalidArgumentException(sprintf(
                'Expected a %s identifier.', $expectedType
            ));
        }

        return new self($type, (int)$id);
    }
}
