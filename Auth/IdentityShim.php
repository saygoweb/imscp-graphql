<?php
namespace iMSCP\Plugin\SGW_GraphQL\Auth;

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
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1335, USA.
 */

use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use stdClass;

/**
 * Mirrors an Identity into the four $_SESSION keys i-MSCP's own helpers read.
 *
 * CORE-DEBT(C1): customerHasFeature(), resellerHasFeature() and the delete
 *   helpers read $_SESSION['user_id'] directly, so an API request that has no
 *   session has to fake one. Retire this class when those helpers take an
 *   explicit $adminId. See docs/SPECIFICATION.md section 21, item C1.
 *
 * No 'login' row is written and no browser session is started: this is the
 * shape core expects, not a real sign-in.
 */
final class IdentityShim
{
    /** @var int|null */
    private static $appliedFor = null;

    /**
     * @throws ApiException if a second, different identity is applied.
     */
    public static function apply(Identity $identity): void
    {
        // customerHasFeature() caches its answer in a static that is not keyed
        // by user (gui/include/Client.php:84), so a request that switched
        // identity would read the first identity's features for the second.
        // One identity per request, enforced rather than documented.
        if (self::$appliedFor !== null && self::$appliedFor !== $identity->getAdminId()) {
            throw new ApiException(
                ErrorCode::INTERNAL,
                'An API request may act as one account only.'
            );
        }

        if (self::$appliedFor === $identity->getAdminId()) {
            return;
        }

        $identityObject = new stdClass();
        $identityObject->admin_id = $identity->getAdminId();
        $identityObject->admin_name = $identity->getUsername();
        $identityObject->admin_type = $identity->getAdminType();
        $identityObject->email = $identity->getEmail();
        $identityObject->created_by = $identity->getCreatedBy();

        $_SESSION['user_id']         = $identity->getAdminId();
        $_SESSION['user_type']       = $identity->getAdminType();
        $_SESSION['user_logged']     = $identity->getUsername();
        $_SESSION['user_created_by'] = $identity->getCreatedBy();
        $_SESSION['user_email']      = $identity->getEmail();
        $_SESSION['user_identity']   = $identityObject;

        self::$appliedFor = $identity->getAdminId();
    }

    /**
     * Test-only. There is no production path that unsets an identity.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$appliedFor = null;
    }
}
