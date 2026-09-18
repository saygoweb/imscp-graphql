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
use iMSCP\Plugin\SGW_GraphQL\Service\MailService;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;

/** The six mail mutations. See VirtualHostMutations for the pattern. */
final class MailMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var MailService */
    private $mail;

    /** @var MailResolver */
    private $mailResolver;

    public function __construct(BatchLoader $loader, MailService $mail, MailResolver $mailResolver)
    {
        $this->loader = $loader;
        $this->mail = $mail;
        $this->mailResolver = $mailResolver;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.mailAccountCreate'    => array($this, 'resolveMailAccountCreate'),
            'Mutation.mailAccountUpdate'    => array($this, 'resolveMailAccountUpdate'),
            'Mutation.mailAccountDelete'    => array($this, 'resolveMailAccountDelete'),
            'Mutation.mailAutoresponderSet' => array($this, 'resolveMailAutoresponderSet'),
            'Mutation.mailCatchallCreate'   => array($this, 'resolveMailCatchallCreate'),
            'Mutation.mailCatchallDelete'   => array($this, 'resolveMailCatchallDelete')
        );
    }

    public function resolveMailAccountCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->create(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveMailAccountUpdate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->update(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']));
    }

    public function resolveMailAccountDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->delete(TypeResolver::identity($context), (string)$args['id']));
    }

    public function resolveMailAutoresponderSet($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->setAutoresponder(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']));
    }

    public function resolveMailCatchallCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->createCatchall(TypeResolver::identity($context), (array)$args['input']));
    }

    public function resolveMailCatchallDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();

        return $this->account($this->mail->deleteCatchall(TypeResolver::identity($context), (string)$args['id']));
    }

    /** Every mail mutation leaves its row in place, so there is never a snapshot. */
    private function account(ObjectRef $ref): SyncPromise
    {
        return $this->mailResolver->reference((int)$ref->getKey());
    }
}
