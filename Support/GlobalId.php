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
 *
 * The key is carried as a string internally, because one table — ftp_users —
 * has no integer primary key (decision D2). encode()/decode()/getId() keep
 * their original, integer-only behaviour; encodeKey()/decodeKey()/getKey()
 * are the additive, string-keyed path they now delegate to.
 */
final class GlobalId
{
    /** @var string */
    private $type;

    /** @var string The raw key, which is not always an integer. */
    private $key;

    private function __construct(string $type, string $key)
    {
        $this->type = $type;
        $this->key = $key;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * The raw identifier portion, integer or not.
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @throws InvalidArgumentException when the key is not a positive integer
     */
    public function getId(): int
    {
        if (!preg_match('/^[1-9][0-9]*$/', $this->key)) {
            throw new InvalidArgumentException(sprintf(
                'The identifier for %s is not an integer.', $this->type
            ));
        }

        return (int)$this->key;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function encode(string $type, int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException(
                'A global identifier wraps a positive row identifier.'
            );
        }

        return self::encodeKey($type, (string)$id);
    }

    /**
     * Encode an identifier whose primary key is not an integer.
     *
     * ftp_users is the only such table in i-MSCP: its primary key is
     * userid varchar(255), which holds 'user@domain'.
     *
     * @throws InvalidArgumentException
     */
    public static function encodeKey(string $type, string $key): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type)) {
            throw new InvalidArgumentException(sprintf(
                'A global identifier type must be an alphanumeric name; got "%s".', $type
            ));
        }

        if ($key === '' || strpos($key, ':') !== false) {
            throw new InvalidArgumentException(
                'A global identifier key must be non-empty and must not contain ":".'
            );
        }

        return rtrim(strtr(base64_encode($type . ':' . $key), '+/', '-_'), '=');
    }

    /**
     * @param string|null $expectedType Reject any identifier of another type.
     * @throws InvalidArgumentException
     */
    public static function decode(string $encoded, ?string $expectedType = null): self
    {
        $decoded = self::decodeKey($encoded, $expectedType);

        // '03' and '3' must not both decode to 3, or one row would have two
        // identifiers and any cache keyed on the identifier would be wrong.
        if (!preg_match('/^[1-9][0-9]*$/', $decoded->getKey())) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        return $decoded;
    }

    /**
     * @param string|null $expectedType Reject any identifier of another type.
     * @throws InvalidArgumentException
     */
    public static function decodeKey(string $encoded, ?string $expectedType = null): self
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        $payload = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($payload === false || substr_count($payload, ':') !== 1) {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        list($type, $key) = explode(':', $payload, 2);

        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type) || $key === '') {
            throw new InvalidArgumentException('Malformed identifier.');
        }

        if ($expectedType !== null && $type !== $expectedType) {
            throw new InvalidArgumentException(sprintf(
                'Expected a %s identifier.', $expectedType
            ));
        }

        return new self($type, $key);
    }
}
