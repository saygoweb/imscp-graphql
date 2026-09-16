<?php
namespace iMSCP\Plugin\SGW_GraphQL\Resolver;

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

use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

/**
 * The conventions every resolver in this phase shares.
 *
 * A resolver returns a plain array carrying the SDL's own field names, so that
 * ResolverMap's fallback - read the key of the same name off the source -
 * covers every value field and only edges need a map entry. Alongside those it
 * carries underscore-prefixed private keys, which cannot collide with an SDL
 * field because the SDL has none.
 *
 * __tag is the one every abstract type depends on: spec section 3.5's opaque
 * identifiers mean the executor cannot tell a Domain array from a MailAccount
 * array by inspection, so the type is carried explicitly.
 */
final class TypeResolver
{
    /** The private key carrying a value's NodeType tag. */
    const TAG = '__tag';

    /** i-MSCP's admin.gender vocabulary => the schema's. */
    const GENDERS = array(
        'M' => 'MALE',
        'F' => 'FEMALE',
        'U' => 'UNSPECIFIED'
    );

    /** Spec section 7.9's default and ceiling. */
    const PAGE_DEFAULT = 50;
    const PAGE_MAX = 200;

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        // Provisioning, ContactDetails, Quota, Storage and the connections are
        // all plain arrays whose keys are their field names, so they need no
        // entries at all. This map exists so that Container has one uniform
        // way to merge every resolver, including the ones that contribute
        // nothing but a resolveType.
        return array();
    }

    /**
     * Which object type an abstract value actually is.
     *
     * Returns a type name rather than a Type instance, which
     * ReferenceExecutor::ensureValidRuntimeType() accepts and resolves against
     * the schema - so this needs no access to the schema it is attached to.
     *
     * @param mixed $value
     * @throws ApiException when a resolver forgot to tag its array
     * @throws \InvalidArgumentException for a tag NodeType does not know
     */
    public static function resolveType($value, $context, ResolveInfo $info): string
    {
        if (!is_array($value) || !isset($value[self::TAG])) {
            // Left to the executor this would surface as "abstract type must
            // resolve to an Object type at runtime" with no clue which
            // resolver was at fault, which is a bad half-hour for whoever
            // reads the log.
            throw new ApiException(
                ErrorCode::INTERNAL,
                'A value reached an interface field without a type tag.'
            );
        }

        return NodeType::graphqlType($value[self::TAG]);
    }

    /**
     * The array shape of one Node: its tag, its raw key and its opaque id.
     *
     * @param int|string $key
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function node(string $tag, $key, array $fields): array
    {
        $id = NodeType::isStringKeyed($tag)
            ? GlobalId::encodeKey($tag, (string)$key)
            : GlobalId::encode($tag, (int)$key);

        return array_merge($fields, array(
            self::TAG => $tag,
            '__key'   => $key,
            'id'      => $id
        ));
    }

    /**
     * The Provisioning shape of spec section 7.1.
     *
     * @return array{state: string, raw: string, settled: bool, message: string|null}
     */
    public static function provisioning(?string $status): array
    {
        $provisioning = Provisioning::fromStatus($status);

        return array(
            'state'   => $provisioning->getState(),
            'raw'     => $provisioning->getRaw(),
            'settled' => $provisioning->isSettled(),
            'message' => $provisioning->getMessage()
        );
    }

    /**
     * An ISO-8601 instant in UTC, or null.
     *
     * i-MSCP writes 0 rather than NULL for "no expiry" in
     * domain.domain_expires and elsewhere. Rendering that as 1970-01-01 would
     * tell every client the account expired fifty-six years ago.
     *
     * @param mixed $timestamp
     */
    public static function dateTime($timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '' || (int)$timestamp === 0) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', (int)$timestamp);
    }

    /**
     * A decimal string, because spec section 7.1's BigInt is a string.
     *
     * The SDL's custom scalars are built from the SDL and therefore serialise
     * by identity, so a resolver returning an int would put a JSON number in
     * the response and lose precision above 2^53 - which disk and traffic
     * figures reach.
     *
     * @param mixed $value
     */
    public static function bigInt($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string)(int)$value;
    }

    public static function gender(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::GENDERS[$raw] ?? null;
    }

    /**
     * The ContactDetails shape, shared by Viewer, Customer and Reseller.
     *
     * @param array<string, mixed> $adminRow A row of the `admin` table
     * @param callable $toUnicode fn(string): string - decode_idna, so a
     *                            punycode email address is returned as Unicode
     * @return array<string, mixed>
     */
    public static function contact(array $adminRow, callable $toUnicode): array
    {
        $email = $adminRow['email'] ?? null;

        return array(
            'firstName' => self::text($adminRow, 'fname'),
            'lastName'  => self::text($adminRow, 'lname'),
            'gender'    => self::gender(
                isset($adminRow['gender']) ? (string)$adminRow['gender'] : null
            ),
            'company'   => self::text($adminRow, 'firm'),
            'street1'   => self::text($adminRow, 'street1'),
            'street2'   => self::text($adminRow, 'street2'),
            'city'      => self::text($adminRow, 'city'),
            'state'     => self::text($adminRow, 'state'),
            'postCode'  => self::text($adminRow, 'zip'),
            'country'   => self::text($adminRow, 'country'),
            'email'     => ($email === null || $email === '')
                ? null : (string)call_user_func($toUnicode, (string)$email),
            'phone'     => self::text($adminRow, 'phone'),
            'fax'       => self::text($adminRow, 'fax')
        );
    }

    /**
     * @param mixed $context
     * @throws ApiException
     */
    public static function identity($context): Identity
    {
        return ViewerResolver::identityFrom($context);
    }

    /**
     * The scope gate.
     *
     * Spec section 7.2: a credential's scopes may be narrower than its role.
     * Identity::hasScope() answers true when a token records none at all - a
     * token issued before scopes existed is a full token - so this is the only
     * place that has to ask, and it must ask everywhere a scope applies.
     *
     * It raises rather than returning an empty list. An empty list is
     * indistinguishable from "this customer has none", which would tell a
     * client its token works when it does not.
     *
     * @param mixed $context
     * @throws ApiException FORBIDDEN
     */
    public static function requireScope($context, string $scope): Identity
    {
        $identity = self::identity($context);

        if (!$identity->hasScope($scope)) {
            throw new ApiException(
                ErrorCode::FORBIDDEN,
                'This credential does not carry the scope this field needs.',
                array('scope' => $scope)
            );
        }

        return $identity;
    }

    /**
     * Spec section 7.9's PageInput, normalised and capped.
     *
     * @param array<string, mixed>|null $pageInput
     * @return array{limit: int, offset: int}
     */
    public static function page(?array $pageInput): array
    {
        $limit = (int)($pageInput['limit'] ?? self::PAGE_DEFAULT);
        $offset = (int)($pageInput['offset'] ?? 0);

        return array(
            'limit'  => max(1, min(self::PAGE_MAX, $limit)),
            'offset' => max(0, $offset)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): ?string
    {
        if (!isset($row[$column]) || $row[$column] === '') {
            return null;
        }

        return (string)$row[$column];
    }
}
