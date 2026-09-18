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
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\ResellerService;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/**
 * The four administrator verbs: create, change and delete a reseller, and
 * its API-access switch.
 *
 * Thin, as every resolver here is (see CustomerMutations): reset the batch
 * loader (decision D18), call the service, and read the object back through
 * the read model without the read scope gate (decision D19).
 */
final class ResellerMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var ResellerService */
    private $resellers;

    /** @var ResellerResolver */
    private $resellerResolver;

    /** @var callable fn(string): string */
    private $toUnicode;

    public function __construct(
        BatchLoader $loader, ResellerService $resellers, ResellerResolver $resellerResolver, callable $toUnicode
    ) {
        $this->loader = $loader;
        $this->resellers = $resellers;
        $this->resellerResolver = $resellerResolver;
        $this->toUnicode = $toUnicode;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.resellerCreate'       => array($this, 'resolveResellerCreate'),
            'Mutation.resellerUpdate'       => array($this, 'resolveResellerUpdate'),
            'Mutation.resellerDelete'       => array($this, 'resolveResellerDelete'),
            'Mutation.resellerSetApiAccess' => array($this, 'resolveResellerSetApiAccess')
        );
    }

    public function resolveResellerCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->reseller($this->resellers->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveResellerUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->reseller($this->resellers->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveResellerDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->reseller($this->resellers->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveResellerSetApiAccess($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->reseller($this->resellers->setApiAccess(
            TypeResolver::identity($context), (string)$args['id'], (bool)$args['allowed']
        ));
    }

    /**
     * M16: a reseller's admin row is removed outright on delete - no
     * `todelete` verb leaves it readable behind (unlike a customer's) - so a
     * delete's ObjectRef carries the snapshot Support\ObjectRef exists for,
     * in the same shape Resolver\ResellerResolver::shape() reads; create,
     * update and setApiAccess all leave the row in place and are read back
     * live.
     *
     * @return \GraphQL\Executor\Promise\Adapter\SyncPromise|array
     */
    private function reseller(ObjectRef $ref)
    {
        $snapshot = $ref->getSnapshot();

        if ($snapshot !== null) {
            return ResellerResolver::shape($snapshot, $this->toUnicode);
        }

        return $this->resellerResolver->reference((int)$ref->getKey());
    }
}
