<?php
namespace iMSCP\Plugin\SGW_GraphQL\Extension;

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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Resolver\TypeResolver;
use iMSCP\Plugin\SGW_GraphQL\Service\Core;
use iMSCP\Plugin\SGW_GraphQL\Service\Toolkit;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * What an extension's resolvers are given to work with: the same database
 * handle, panel core and batch loader this plugin's own resolvers use, and
 * the one ownership rule every write in the API goes through.
 *
 * The contract an extension is written against. Anything an extension needs
 * that is not here, it should ask for here rather than reach past.
 *
 * Refusals are thrown as ApiException, and Security\Guard's static helpers -
 * badInput(), forbidden(), conflict(), requireState(), requireFeature() - build
 * the ones an extension will want, with the error codes clients already
 * handle (spec section 8.1).
 */
final class ExtensionContext
{
    /** The tags a vhost-targeting argument may name. */
    const VIRTUAL_HOST_TAGS = array(
        NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN, NodeType::DOMAIN_ALIAS
    );

    /** @var Toolkit */
    private $kit;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(string $tag, int $key): SyncPromise */
    private $vhostRef;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    public function __construct(
        Toolkit $kit, BatchLoader $loader, callable $vhostRef, callable $customerRef
    ) {
        $this->kit = $kit;
        $this->loader = $loader;
        $this->vhostRef = $vhostRef;
        $this->customerRef = $customerRef;
    }

    /**
     * The panel's database, through this plugin's handle, so that a test
     * transaction and the query counter see an extension's queries too.
     */
    public function db(): Db
    {
        return $this->kit->db();
    }

    /**
     * The panel's global functions: sendRequest() to wake the daemon after a
     * status change (never inside a transaction), writeLog(), dispatch(),
     * config().
     */
    public function core(): Core
    {
        return $this->kit->core();
    }

    /**
     * The request's batch loader. A field that reads a row per vhost should
     * go through keyed() with a bucket prefixed by the extension's name, so
     * that a list of fifty domains costs one query rather than fifty (spec
     * section 10.1).
     *
     * A mutation calls reset() before returning, as this plugin's own do
     * (decision D18): the object it returns must be read after its write.
     */
    public function loader(): BatchLoader
    {
        return $this->loader;
    }

    /**
     * The authenticated caller, or UNAUTHENTICATED.
     *
     * @param mixed $context The resolver's third argument
     * @throws ApiException
     */
    public function identity($context): Identity
    {
        return TypeResolver::identity($context);
    }

    /**
     * The read gate: the caller, if its credential carries $scope, else
     * FORBIDDEN. Every field an extension adds should ask for one of
     * Auth\Scope's values - the scope of the object the field hangs off is
     * almost always the right one.
     *
     * @param mixed $context The resolver's third argument
     * @throws ApiException
     */
    public function requireScope($context, string $scope): Identity
    {
        return TypeResolver::requireScope($context, $scope);
    }

    /**
     * The vhost a Domain, Subdomain or DomainAlias field is resolving on.
     *
     * The source was reached by the query that led to it, so no second
     * ownership check is needed; the reference carries no status (see
     * VirtualHostRef::getStatus()).
     *
     * @param mixed $source The resolver's first argument
     * @throws ApiException INTERNAL when the source is not a vhost - a field
     *                      the extension declared on the wrong type.
     */
    public function virtualHost($source): VirtualHostRef
    {
        if (!is_array($source)
            || !in_array($source[TypeResolver::TAG] ?? null, self::VIRTUAL_HOST_TAGS, true)
            || !isset($source['__key'], $source['__kind'], $source['__domainId'], $source['__ownerId'])
        ) {
            throw new ApiException(
                ErrorCode::INTERNAL,
                'An extension asked for the virtual host of something that is not one.'
            );
        }

        return new VirtualHostRef(
            $source[TypeResolver::TAG],
            (string)$source['__kind'],
            (int)$source['__key'],
            (int)$source['__domainId'],
            (int)$source['__ownerId'],
            null
        );
    }

    /**
     * The write gate for an argument that names a vhost: spec section 8.1's
     * steps 2 and 3, exactly as this plugin's own mutations take them.
     *
     * NOT_FOUND for anything the caller cannot reach - malformed, the wrong
     * kind, someone else's - and only then FORBIDDEN for a credential without
     * $scope, so that the answer never confirms that something exists. The
     * row is read now, not from the batch loader, so getStatus() is the state
     * the write is deciding on.
     *
     * @param mixed  $encodedId The identifier as the caller sent it
     * @param string $field     Where in the input it came from: 'id',
     *                          'input.domainId', ...
     * @throws ApiException NOT_FOUND, then FORBIDDEN
     */
    public function targetVirtualHost(
        Identity $caller, $encodedId, string $scope, string $field = 'id'
    ): VirtualHostRef {
        $target = $this->kit->guard()->target(
            $caller, $encodedId, self::VIRTUAL_HOST_TAGS, $scope, $field
        );
        $row = $this->kit->vhost($target->getTag(), $target->getKey());

        return new VirtualHostRef(
            $target->getTag(),
            VirtualHosts::kindFor($target->getTag()),
            (int)$target->getKey(),
            (int)$row['domainId'],
            $target->getOwnerId(),
            (string)$row['status']
        );
    }

    /**
     * A vhost as the schema's Domain, Subdomain or DomainAlias, batched. What
     * an extension's field returns when its type is one of those - a
     * mutation's result, typically.
     */
    public function virtualHostReference(VirtualHostRef $vhost): SyncPromise
    {
        return call_user_func($this->vhostRef, $vhost->getTag(), $vhost->getKey());
    }

    /** A customer as the schema's Customer, batched. */
    public function customerReference(int $adminId): SyncPromise
    {
        return call_user_func($this->customerRef, $adminId);
    }
}
