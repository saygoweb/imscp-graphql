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

/**
 * Query.apiVersion and Query.viewer.
 *
 * The viewer is read entirely off the Identity the middleware put on the
 * request: it needs no database access at all, which is what makes it the
 * right query to prove the whole path with.
 */
final class ViewerResolver
{
    /** @var string */
    private $apiVersion;

    public function __construct(string $apiVersion)
    {
        $this->apiVersion = $apiVersion;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Query.apiVersion' => array($this, 'resolveApiVersion'),
            'Query.viewer'     => array($this, 'resolveViewer')
        );
    }

    /**
     * Asks for an identity although it uses none.
     *
     * Spec section 5.3 says `tokenIssue` is *the* unauthenticated field, and
     * from the moment AuthenticateMiddleware stopped refusing a request with
     * no credential, this was the one other field in the schema with nothing
     * to stop it: every other resolver reaches identity() on its own way to
     * an answer, and this one never did. It is only a version string, but
     * "the only unauthenticated field" is a sentence worth being able to say
     * without an exception attached.
     *
     * @throws ApiException UNAUTHENTICATED
     */
    public function resolveApiVersion($source, array $args, $context, ResolveInfo $info): string
    {
        self::identityFrom($context);

        return $this->apiVersion;
    }

    public function resolveViewer($source, array $args, $context, ResolveInfo $info): array
    {
        $identity = self::identityFrom($context);

        return array(
            'id'       => GlobalId::encode('Viewer', $identity->getAdminId()),
            'username' => $identity->getUsername(),
            'role'     => $identity->getRole(),
            'email'    => $identity->getEmail(),
            'scopes'   => $identity->getScopes()
        );
    }

    /**
     * @param mixed $context
     * @throws ApiException
     */
    public static function identityFrom($context): Identity
    {
        if (is_array($context) && ($context['identity'] ?? null) instanceof Identity) {
            return $context['identity'];
        }

        // A context that carries the key with null in it is a request that
        // presented no credential. That is a thing a caller can do - spec
        // section 5.3's `tokenIssue` is served without one - so it is the
        // caller's own answer, and it is the same answer for every field:
        // this is the gate that keeps the rest of the schema shut while
        // AuthenticateMiddleware lets an unauthenticated request past.
        if (is_array($context) && array_key_exists('identity', $context)) {
            throw new ApiException(
                ErrorCode::UNAUTHENTICATED, 'Authentication is required.'
            );
        }

        // No key at all: reaching a resolver without an identity means the
        // middleware chain is mis-wired. It is not the caller's fault and must
        // not leak detail.
        throw new ApiException(
            ErrorCode::INTERNAL, 'The request reached a resolver with no identity.'
        );
    }
}
