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
use iMSCP\Plugin\SGW_GraphQL\Service\SqlService;

/**
 * The five SQL mutations. See VirtualHostMutations for the pattern. A delete
 * removes its row, so it answers from the service's snapshot.
 */
final class SqlMutations
{
    /** @var BatchLoader */
    private $loader;

    /** @var SqlService */
    private $sql;

    /** @var FtpSqlResolver */
    private $ftpSql;

    public function __construct(BatchLoader $loader, SqlService $sql, FtpSqlResolver $ftpSql)
    {
        $this->loader = $loader;
        $this->sql = $sql;
        $this->ftpSql = $ftpSql;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Mutation.sqlDatabaseCreate'  => array($this, 'resolveSqlDatabaseCreate'),
            'Mutation.sqlDatabaseDelete'  => array($this, 'resolveSqlDatabaseDelete'),
            'Mutation.sqlUserCreate'      => array($this, 'resolveSqlUserCreate'),
            'Mutation.sqlUserSetPassword' => array($this, 'resolveSqlUserSetPassword'),
            'Mutation.sqlUserDelete'      => array($this, 'resolveSqlUserDelete')
        );
    }

    public function resolveSqlDatabaseCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->createDatabase(TypeResolver::identity($context), (array)$args['input']);

        return $this->ftpSql->databaseReference((int)$ref->getKey());
    }

    public function resolveSqlDatabaseDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->deleteDatabase(TypeResolver::identity($context), (string)$args['id']);

        return FtpSqlResolver::shapeDatabase($ref->getSnapshot());
    }

    public function resolveSqlUserCreate($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->createUser(TypeResolver::identity($context), (array)$args['input']);

        return $this->ftpSql->sqlUserReference((int)$ref->getKey());
    }

    public function resolveSqlUserSetPassword($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->setUserPassword(TypeResolver::identity($context), (string)$args['id'], (string)$args['password']);

        return $this->ftpSql->sqlUserReference((int)$ref->getKey());
    }

    public function resolveSqlUserDelete($source, array $args, $context, ResolveInfo $info)
    {
        $this->loader->reset();
        $ref = $this->sql->deleteUser(TypeResolver::identity($context), (string)$args['id']);

        return FtpSqlResolver::shapeSqlUser($ref->getSnapshot());
    }
}
