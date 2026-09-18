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

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Service\DnsService;

/** The three DNS mutations. See VirtualHostMutations for the pattern. */
final class DnsMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var DnsService */
    private $dns;

    /** @var DnsResolver */
    private $dnsResolver;

    public function __construct(BatchLoader $loader, DnsService $dns, DnsResolver $dnsResolver)
    {
        $this->loader = $loader;
        $this->dns = $dns;
        $this->dnsResolver = $dnsResolver;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.dnsRecordCreate' => array($this, 'resolveDnsRecordCreate'),
            'Mutation.dnsRecordUpdate' => array($this, 'resolveDnsRecordUpdate'),
            'Mutation.dnsRecordDelete' => array($this, 'resolveDnsRecordDelete')
        );
    }

    public function resolveDnsRecordCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->dns->create(TypeResolver::identity($context), (array)$args['input']);

        return $this->dnsResolver->reference((int)$ref->getKey());
    }

    public function resolveDnsRecordUpdate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->dns->update(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']);

        return $this->dnsResolver->reference((int)$ref->getKey());
    }

    public function resolveDnsRecordDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->dns->delete(TypeResolver::identity($context), (string)$args['id']);

        return $this->dnsResolver->reference((int)$ref->getKey());
    }
}
