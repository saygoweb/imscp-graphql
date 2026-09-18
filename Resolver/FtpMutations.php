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
use iMSCP\Plugin\SGW_GraphQL\Service\FtpService;

/** The three FTP mutations. See VirtualHostMutations for the pattern. */
final class FtpMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var FtpService */
    private $ftp;

    /** @var FtpSqlResolver */
    private $ftpSql;

    public function __construct(BatchLoader $loader, FtpService $ftp, FtpSqlResolver $ftpSql)
    {
        $this->loader = $loader;
        $this->ftp = $ftp;
        $this->ftpSql = $ftpSql;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.ftpUserCreate' => array($this, 'resolveFtpUserCreate'),
            'Mutation.ftpUserUpdate' => array($this, 'resolveFtpUserUpdate'),
            'Mutation.ftpUserDelete' => array($this, 'resolveFtpUserDelete')
        );
    }

    public function resolveFtpUserCreate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->ftp->create(TypeResolver::identity($context), (array)$args['input']);

        return $this->ftpSql->ftpReference((string)$ref->getKey());
    }

    public function resolveFtpUserUpdate($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->ftp->update(TypeResolver::identity($context), (string)$args['id'], (array)$args['input']);

        return $this->ftpSql->ftpReference((string)$ref->getKey());
    }

    public function resolveFtpUserDelete($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        $this->loader->reset();
        $ref = $this->ftp->delete(TypeResolver::identity($context), (string)$args['id']);

        return $this->ftpSql->ftpReference((string)$ref->getKey());
    }
}
