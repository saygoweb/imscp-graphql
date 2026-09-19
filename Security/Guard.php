<?php
namespace iMSCP\Plugin\SGW_GraphQL\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use InvalidArgumentException;

/**
 * Spec section 8.1, steps 2 to 7: the only place a write is refused.
 *
 *   2. ownership  NOT_FOUND            target()
 *   3. scope      FORBIDDEN            target()
 *   4. feature    FEATURE_UNAVAILABLE  requireFeature()
 *   5. settled    CONFLICT             requireState()
 *   6. input      BAD_USER_INPUT       badInput()
 *   7. quota      LIMIT_EXCEEDED       requireQuota()
 *
 * The order is the point, and it is the service's to keep: "the error a
 * caller gets leaks the least". A service that validated input before
 * resolving ownership would let a stranger learn which names are taken by
 * reading which validation error comes back.
 */
final class Guard
{
    /**
     * Spec section 8.3's suggested wait. Typical settle time on the reference
     * box is a few seconds (spec section 8.2).
     */
    const RETRY_AFTER_SECONDS = 5;

    /** @var OwnershipResolver */
    private $ownership;

    public function __construct(OwnershipResolver $ownership)
    {
        $this->ownership = $ownership;
    }

    /**
     * Steps 2 and 3, in that order.
     *
     * @param mixed    $encodedId The identifier as the caller sent it
     * @param string[] $tags      The NodeType tags this argument may name
     * @param string   $field     Where in the input it came from, for the
     *                            client: 'id', 'input.parentId', ...
     * @throws ApiException NOT_FOUND, then FORBIDDEN
     */
    public function target(Identity $caller, $encodedId, array $tags, string $scope, string $field): Target
    {
        try {
            $id = self::parse(is_string($encodedId) ? $encodedId : '', $tags);
            $ownerId = $this->ownership->assertReachable($caller, $id);
        } catch (ApiException $e) {
            // The field is added, not the reason: which argument was not found
            // tells a client which identifier to look at, and nothing about
            // whether the object exists.
            throw new ApiException(ErrorCode::NOT_FOUND, $e->getMessage(), array('field' => $field));
        }

        self::requireScope($caller, $scope);

        return new Target($id, $ownerId);
    }

    /**
     * An identifier of one of $tags, or NOT_FOUND.
     *
     * Malformed, the wrong kind, and a non-numeric key for an integer-keyed
     * kind are all NOT_FOUND: spec section 6.3 wants every way of naming
     * nothing to be indistinguishable. QueryResolver::decode() delegates here.
     *
     * @param string[] $tags
     * @throws ApiException NOT_FOUND
     */
    public static function parse(string $encoded, array $tags): GlobalId
    {
        try {
            $id = GlobalId::decodeKey($encoded);
        } catch (\Exception $e) {
            throw self::notFound();
        }

        if (!NodeType::isKnown($id->getType()) || !in_array($id->getType(), $tags, true)) {
            throw self::notFound();
        }

        if (!NodeType::isStringKeyed($id->getType())) {
            try {
                $id->getId();
            } catch (InvalidArgumentException $e) {
                throw self::notFound();
            }
        }

        return $id;
    }

    /**
     * @throws ApiException FORBIDDEN
     */
    public static function requireScope(Identity $caller, string $scope): void
    {
        if (!$caller->hasScope($scope)) {
            throw self::forbidden(
                'This credential does not carry the scope this mutation needs.',
                array('scope' => $scope)
            );
        }
    }

    /**
     * Step 4. Always asked of the owning customer's account, never the
     * caller's: a reseller's own allowances are irrelevant to what one of
     * their customers may have.
     *
     * @throws ApiException FEATURE_UNAVAILABLE
     */
    public static function requireFeature(bool $available, string $feature): void
    {
        if (!$available) {
            throw new ApiException(
                ErrorCode::FEATURE_UNAVAILABLE,
                'This feature is not available to the account.',
                array('feature' => $feature)
            );
        }
    }

    /**
     * Step 5, spec section 8.3.
     *
     * Pending is CONFLICT with a retry hint: the backend has not read the
     * object's last instruction, and a second write would overwrite it. Any
     * other state the operation does not accept - disabled, ordered, failed
     * for anything but a delete - is FORBIDDEN, because waiting will not
     * change it.
     *
     * @param string[] $allowedStates Provisioning::STATE_* values
     * @throws ApiException CONFLICT or FORBIDDEN
     */
    public static function requireState(string $status, array $allowedStates): void
    {
        $state = Provisioning::fromStatus($status)->getState();

        if (in_array($state, $allowedStates, true)) {
            return;
        }

        if ($state === Provisioning::STATE_PENDING) {
            throw new ApiException(
                ErrorCode::CONFLICT,
                'The backend has not finished with this object yet.',
                array('state' => $state, 'retryAfterSeconds' => self::RETRY_AFTER_SECONDS)
            );
        }

        throw self::forbidden('The object is not in a state this operation accepts.', array('state' => $state));
    }

    /**
     * Step 7, for creates.
     *
     * @throws ApiException LIMIT_EXCEEDED
     */
    public static function requireQuota(Quota $quota, string $name): void
    {
        if ($quota->isEnabled() && $quota->getLimit() === null) {
            return;
        }

        if ($quota->isEnabled() && $quota->getUsed() < $quota->getLimit()) {
            return;
        }

        throw new ApiException(
            ErrorCode::LIMIT_EXCEEDED,
            'The account has reached this limit.',
            array('quota' => $name, 'limit' => (int)$quota->getLimit(), 'used' => $quota->getUsed())
        );
    }

    /** Step 6. */
    public static function badInput(string $field, string $message, array $extra = array()): ApiException
    {
        return new ApiException(ErrorCode::BAD_USER_INPUT, $message, array_merge(array('field' => $field), $extra));
    }

    /**
     * Step 7, where the limit is not a Quota.
     *
     * A reseller's allowances are measured by LimitRules against two ledgers
     * at once, and the refusal is the panel's own sentence rather than a
     * limit-and-used pair. The extension shape is requireQuota()'s all the
     * same: 'quota' names the allowance, and 'limit' and 'used' are there when
     * the caller knows them.
     */
    public static function limitExceeded(string $message, array $extra = array()): ApiException
    {
        return new ApiException(ErrorCode::LIMIT_EXCEEDED, $message, $extra);
    }

    /** A uniqueness clash (spec section 8.4). */
    public static function conflict(string $message): ApiException
    {
        return new ApiException(ErrorCode::CONFLICT, $message);
    }

    public static function forbidden(string $message, array $extra = array()): ApiException
    {
        return new ApiException(ErrorCode::FORBIDDEN, $message, $extra);
    }

    public static function notFound(): ApiException
    {
        return new ApiException(ErrorCode::NOT_FOUND, 'No such object, or it is not yours to read.');
    }

    /**
     * No credential, or one that was not accepted.
     *
     * The only refusal in this class with no extension at all, and
     * deliberately: `tokenIssue` is the one unauthenticated field, and the
     * three ways a credential can fail there - an unknown username, a known
     * username with the wrong password, and an account whose status is not ok
     * - must be one answer. An extension naming which of them happened would
     * make the field an oracle for valid usernames, which is exactly what the
     * two rate buckets in front of it exist to prevent; there is no point
     * charging for an answer and then giving it away in the error.
     *
     * $message is a parameter only so that the field's own sentence reads
     * naturally. It is never composed from anything the caller sent.
     */
    public static function unauthenticated(string $message): ApiException
    {
        return new ApiException(ErrorCode::UNAUTHENTICATED, $message);
    }
}
