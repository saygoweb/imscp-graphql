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
use iMSCP\Plugin\SGW_GraphQL\Service\DomainAliasService;
use iMSCP\Plugin\SGW_GraphQL\Service\DomainService;
use iMSCP\Plugin\SGW_GraphQL\Service\SubdomainService;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/**
 * The seven vhost mutations. Thin, as every resolver here is: reset the batch
 * loader (decision D18), call the service, and read the object back through
 * the read model without the read scope gate (decision D19).
 */
final class VirtualHostMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var SubdomainService */
    private $subdomains;

    /** @var DomainAliasService */
    private $aliases;

    /** @var DomainService */
    private $domains;

    /** @var VirtualHostResolver */
    private $virtualHosts;

    /** @var callable fn(string): string */
    private $toUnicode;

    public function __construct(
        BatchLoader $loader, SubdomainService $subdomains, DomainAliasService $aliases,
        DomainService $domains, VirtualHostResolver $virtualHosts, callable $toUnicode
    ) {
        $this->loader = $loader;
        $this->subdomains = $subdomains;
        $this->aliases = $aliases;
        $this->domains = $domains;
        $this->virtualHosts = $virtualHosts;
        $this->toUnicode = $toUnicode;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.domainUpdate'      => array($this, 'resolveDomainUpdate'),
            'Mutation.subdomainCreate'   => array($this, 'resolveSubdomainCreate'),
            'Mutation.subdomainUpdate'   => array($this, 'resolveSubdomainUpdate'),
            'Mutation.subdomainDelete'   => array($this, 'resolveSubdomainDelete'),
            'Mutation.domainAliasCreate' => array($this, 'resolveDomainAliasCreate'),
            'Mutation.domainAliasUpdate' => array($this, 'resolveDomainAliasUpdate'),
            'Mutation.domainAliasDelete' => array($this, 'resolveDomainAliasDelete')
        );
    }

    public function resolveDomainUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->domains->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveSubdomainCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->subdomains->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveSubdomainUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->subdomains->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveSubdomainDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->subdomains->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveDomainAliasCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->aliases->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveDomainAliasUpdate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->aliases->update(
            TypeResolver::identity($context), (string)$args['id'], (array)$args['input']
        ));
    }

    public function resolveDomainAliasDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();

        return $this->vhost($this->aliases->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    /**
     * The written object: its snapshot when the row is gone, otherwise read
     * back now, after the commit.
     *
     * @return \GraphQL\Executor\Promise\Adapter\SyncPromise|array
     */
    private function vhost(ObjectRef $ref)
    {
        $snapshot = $ref->getSnapshot();

        if ($snapshot !== null) {
            return VirtualHostResolver::shape($snapshot, $this->toUnicode);
        }

        return $this->virtualHosts->reference($ref->getTag(), $ref->getKey());
    }
}
