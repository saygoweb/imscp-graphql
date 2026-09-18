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
use iMSCP\Plugin\SGW_GraphQL\Service\CustomerService;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Service\HostingPlanService;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/**
 * The ten reseller and administrator mutations: a customer's lifecycle, a
 * reseller's hosting plans, and the two ends of an alias order.
 *
 * Thin, as every resolver here is (see VirtualHostMutations): reset the batch
 * loader (decision D18), call the service, and read the object back through
 * the read model without the read scope gate (decision D19).
 */
final class CustomerMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var CustomerService */
    private $customers;

    /** @var HostingPlanService */
    private $plans;

    /** @var DomainAliasService */
    private $aliases;

    /** @var CustomerResolver */
    private $customerResolver;

    /** @var ResellerResolver */
    private $resellerResolver;

    /** @var VirtualHostResolver */
    private $virtualHosts;

    /** @var callable fn(string): string */
    private $toUnicode;

    public function __construct(
        BatchLoader $loader, CustomerService $customers, HostingPlanService $plans,
        DomainAliasService $aliases, CustomerResolver $customerResolver,
        ResellerResolver $resellerResolver, VirtualHostResolver $virtualHosts, callable $toUnicode
    ) {
        $this->loader = $loader;
        $this->customers = $customers;
        $this->plans = $plans;
        $this->aliases = $aliases;
        $this->customerResolver = $customerResolver;
        $this->resellerResolver = $resellerResolver;
        $this->virtualHosts = $virtualHosts;
        $this->toUnicode = $toUnicode;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.customerCreate'       => array($this, 'resolveCustomerCreate'),
            'Mutation.customerUpdate'       => array($this, 'resolveCustomerUpdate'),
            'Mutation.customerDelete'       => array($this, 'resolveCustomerDelete'),
            'Mutation.customerSetState'     => array($this, 'resolveCustomerSetState'),
            'Mutation.customerSetApiAccess' => array($this, 'resolveCustomerSetApiAccess'),
            'Mutation.hostingPlanCreate'    => array($this, 'resolveHostingPlanCreate'),
            'Mutation.hostingPlanUpdate'    => array($this, 'resolveHostingPlanUpdate'),
            'Mutation.hostingPlanDelete'    => array($this, 'resolveHostingPlanDelete'),
            'Mutation.domainAliasApprove'   => array($this, 'resolveDomainAliasApprove'),
            'Mutation.domainAliasReject'    => array($this, 'resolveDomainAliasReject')
        );
    }

    public function resolveCustomerCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->customer($this->customers->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveCustomerUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->customer($this->customers->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveCustomerDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->customer($this->customers->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveCustomerSetState($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->customer($this->customers->setState(
            TypeResolver::identity($context), (string)$args['id'], (string)$args['state']
        ));
    }

    public function resolveCustomerSetApiAccess($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->customer($this->customers->setApiAccess(
            TypeResolver::identity($context), (string)$args['id'], (bool)$args['allowed']
        ));
    }

    public function resolveHostingPlanCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->plan($this->plans->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveHostingPlanUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->plan($this->plans->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveHostingPlanDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->plan($this->plans->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveDomainAliasApprove($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->alias($this->aliases->approve(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveDomainAliasReject($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->alias($this->aliases->reject(TypeResolver::identity($context), (string)$args['id']));
    }

    /**
     * A customer's own row survives every one of these: even a delete only
     * schedules it (admin_status = 'todelete'), so there is never a snapshot.
     *
     * @return \GraphQL\Executor\Promise\Adapter\SyncPromise
     */
    private function customer(ObjectRef $ref)
    {
        return $this->customerResolver->reference((int)$ref->getKey());
    }

    /**
     * hosting_plans has no status column (M15), so a deleted plan is gone and
     * comes back as the snapshot the service took before removing it.
     *
     * @return \GraphQL\Executor\Promise\Adapter\SyncPromise|array
     */
    private function plan(ObjectRef $ref)
    {
        $snapshot = $ref->getSnapshot();

        if ($snapshot !== null) {
            return ResellerResolver::planShape($snapshot);
        }

        return $this->resellerResolver->planReference((int)$ref->getKey());
    }

    /**
     * An approved order is still there, with a 'toadd' status; a rejected one
     * is gone, and is the snapshot.
     *
     * @return \GraphQL\Executor\Promise\Adapter\SyncPromise|array
     */
    private function alias(ObjectRef $ref)
    {
        $snapshot = $ref->getSnapshot();

        if ($snapshot !== null) {
            return VirtualHostResolver::shape($snapshot, $this->toUnicode);
        }

        return $this->virtualHosts->reference($ref->getTag(), $ref->getKey());
    }
}
