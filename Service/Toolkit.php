<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Accounts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Counts;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;

/**
 * The fixed set of collaborators every service takes.
 *
 * A bundle rather than eight constructor parameters per service: the set is
 * the same for all of them, and a service that needs something outside it
 * (SqlService's SqlServer) takes that as a second argument, so the exception
 * is visible in the constructor.
 */
final class Toolkit
{
    /** @var Db */
    private $db;
    /** @var Core */
    private $core;
    /** @var Guard */
    private $guard;
    /** @var Accounts */
    private $accounts;
    /** @var VirtualHosts */
    private $vhosts;
    /** @var Counts */
    private $counts;
    /** @var Writer */
    private $writer;
    /** @var DirectoryProbe */
    private $probe;

    public function __construct(
        Db $db, Core $core, Guard $guard, Accounts $accounts, VirtualHosts $vhosts,
        Counts $counts, Writer $writer, DirectoryProbe $probe
    ) {
        $this->db = $db;
        $this->core = $core;
        $this->guard = $guard;
        $this->accounts = $accounts;
        $this->vhosts = $vhosts;
        $this->counts = $counts;
        $this->writer = $writer;
        $this->probe = $probe;
    }

    public function db(): Db { return $this->db; }
    public function core(): Core { return $this->core; }
    public function guard(): Guard { return $this->guard; }
    public function accounts(): Accounts { return $this->accounts; }
    public function vhosts(): VirtualHosts { return $this->vhosts; }
    public function counts(): Counts { return $this->counts; }
    public function writer(): Writer { return $this->writer; }
    public function probe(): DirectoryProbe { return $this->probe; }

    /**
     * One vhost's normalised row, read now rather than from the batch loader:
     * a write decides on the state the row is in at this moment.
     *
     * @param int|string $key
     * @return array<string, mixed> See VirtualHosts::normalise()
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException NOT_FOUND
     */
    public function vhost(string $tag, $key): array
    {
        $kind = VirtualHosts::kindFor($tag);
        $rows = $this->vhosts->byKeys(array(array($kind, (int)$key)));

        if (!isset($rows[$kind . ':' . (int)$key])) {
            throw Guard::notFound();
        }

        return $rows[$kind . ':' . (int)$key];
    }

    /**
     * The panel configuration keys a Support\ class wants as an array.
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    public function panelConfig(array $keys): array
    {
        $config = array();

        foreach ($keys as $key) {
            $config[$key] = $this->core->config($key);
        }

        return $config;
    }
}
