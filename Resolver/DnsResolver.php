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

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Type\Definition\ResolveInfo;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * DnsRecord and IpAddress - spec section 7.8.
 *
 * IpAddress lives here rather than with the vhosts because server_ips is the
 * only table it comes from and DNS is the only other thing that talks about
 * addresses. Domain.ipAddress and Reseller.ipAddresses both reach it through
 * the two reference methods below, injected as closures so that neither
 * VirtualHostResolver nor ResellerResolver has to depend on this class.
 *
 * ipReferences() is one of the four places in this plan where F2 applies: a
 * Deferred must not read the result of a ->then() child. BatchLoader::keyed()
 * enqueues its Deferred immediately, so the gathering Deferred constructed in
 * ipReferences() - which is created after every ipRow() call - runs after all
 * of them and finds their results already set. A ->then() child, by contrast,
 * is enqueued only once its parent resolves, which is later than that; that is
 * why ipReferences() gathers the raw keyed() promises from ipRow() rather than
 * the shaped ones ipReference() would hand back.
 */
final class DnsResolver
{
    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(string $tag, $key): SyncPromise */
    private $hostRef;

    public function __construct(Db $db, BatchLoader $loader, callable $hostRef)
    {
        $this->db = $db;
        $this->loader = $loader;
        $this->hostRef = $hostRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'DnsRecord.host'      => array($this, 'resolveHost'),
            'Customer.dnsRecords' => array($this, 'resolveCustomerDnsRecords')
        );
    }

    /**
     * @param array<string, mixed> $row A row of domain_dns
     * @return array<string, mixed>
     */
    public static function shape(array $row): array
    {
        $aliasId = (int)$row['alias_id'];

        return TypeResolver::node(
            NodeType::DNS_RECORD, (int)$row['domain_dns_id'], array(
                'name'         => (string)$row['domain_dns'],
                'class'        => (string)$row['domain_class'],
                'type'         => (string)$row['domain_type'],
                'value'        => (string)$row['domain_text'],
                'ownedBy'      => (string)$row['owned_by'],
                'provisioning' => TypeResolver::provisioning(
                    $row['domain_dns_status'] === null
                        ? null : (string)$row['domain_dns_status']
                ),
                // alias_id = 0 means the record is on the main domain
                // (gui/public/client/dns_edit.php:592). Reading 0 as an alias
                // id would ask for DomainAlias:0 and null a non-null field.
                '__hostTag'  => $aliasId === 0
                    ? NodeType::DOMAIN : NodeType::DOMAIN_ALIAS,
                '__hostKey'  => $aliasId === 0 ? (int)$row['domain_id'] : $aliasId,
                '__domainId' => (int)$row['domain_id']
            )
        );
    }

    /**
     * @param array<string, mixed> $row A row of server_ips
     * @return array<string, mixed>
     */
    public static function shapeIp(array $row): array
    {
        return TypeResolver::node(NodeType::IP_ADDRESS, (int)$row['ip_id'], array(
            'address' => (string)$row['ip_number'],
            // Both nullable in i-MSCP and both nullable in the schema. Casting
            // a null netmask to 0 would be a statement about the network that
            // the database never made.
            'netmask' => $row['ip_netmask'] === null ? null : (int)$row['ip_netmask'],
            'card'    => $row['ip_card'] === null || $row['ip_card'] === ''
                ? null : (string)$row['ip_card']
        ));
    }

    public function reference(int $dnsId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'dns:row',
            $dnsId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT * FROM domain_dns WHERE domain_dns_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['domain_dns_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) {
            return $row === null ? null : self::shape($row);
        });
    }

    public function ipReference(int $ipId): SyncPromise
    {
        return $this->ipRow($ipId)->then(static function ($row) {
            return $row === null ? null : self::shapeIp($row);
        });
    }

    /**
     * @param int[] $ipIds
     */
    public function ipReferences(array $ipIds): SyncPromise
    {
        $deferreds = array();

        foreach ($ipIds as $ipId) {
            $deferreds[] = $this->ipRow((int)$ipId);
        }

        return new Deferred(static function () use ($deferreds) {
            $addresses = array();

            foreach ($deferreds as $deferred) {
                if ($deferred->result !== null) {
                    $addresses[] = self::shapeIp($deferred->result);
                }
            }

            return $addresses;
        });
    }

    public function resolveHost($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DNS_READ);

        return call_user_func($this->hostRef, $source['__hostTag'], $source['__hostKey']);
    }

    public function resolveCustomerDnsRecords(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DNS_READ);

        $db = $this->db;

        return $this->loader->keyed(
            'dns:by-domain',
            (int)$source['__domainId'],
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT * FROM domain_dns WHERE domain_id IN ('
                        . $db->placeholders(count($keys))
                        . ') ORDER BY domain_dns_id',
                    $keys
                );
                $byDomain = array();

                foreach ($keys as $key) {
                    // Present even when empty: dnsRecords is [DnsRecord!]!, so
                    // a customer with none needs a list, not a null.
                    $byDomain[(int)$key] = array();
                }

                foreach ($rows as $row) {
                    $byDomain[(int)$row['domain_id']][] = $row;
                }

                return $byDomain;
            }
        )->then(static function ($rows) {
            $shaped = array();

            foreach (($rows === null ? array() : $rows) as $row) {
                $shaped[] = self::shape($row);
            }

            return $shaped;
        });
    }

    private function ipRow(int $ipId): SyncPromise
    {
        $db = $this->db;

        return $this->loader->keyed(
            'ip:row',
            $ipId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT ip_id, ip_number, ip_netmask, ip_card FROM server_ips'
                        . ' WHERE ip_id IN (' . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['ip_id']] = $row;
                }

                return $byId;
            }
        );
    }
}
