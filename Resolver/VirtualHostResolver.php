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
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Model\DomainModel;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Repository\VirtualHosts;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

/**
 * Domain, Subdomain and DomainAlias - spec section 7.3's three virtual-host
 * types, all shaped from the one normalised row Repository\VirtualHosts
 * produces, so that the four-way dmn/sub/als/alssub split exists in exactly
 * one place.
 *
 * Every edge goes through BatchLoader. A resolver that queried per row would
 * cost one query per subdomain on a page that lists them, which is the N+1
 * spec section 10.1 calls a defect.
 */
final class VirtualHostResolver
{
    /** i-MSCP's type_forward vocabulary => the schema's ForwardType. */
    const FORWARD_TYPES = array(
        '301'   => 'PERMANENT_301',
        '302'   => 'FOUND_302',
        '303'   => 'SEE_OTHER_303',
        '307'   => 'TEMPORARY_307',
        'proxy' => 'PROXY'
    );

    /** @var BatchLoader */
    private $loader;

    /** @var VirtualHosts */
    private $vhosts;

    /** @var Db */
    private $db;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(int $adminId): SyncPromise */
    private $customerRef;

    /** @var callable fn(int $ipId): SyncPromise */
    private $ipRef;

    public function __construct(
        BatchLoader $loader, VirtualHosts $vhosts, Db $db, callable $toUnicode,
        callable $customerRef, callable $ipRef
    ) {
        $this->loader = $loader;
        $this->vhosts = $vhosts;
        $this->db = $db;
        $this->toUnicode = $toUnicode;
        $this->customerRef = $customerRef;
        $this->ipRef = $ipRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'Domain.customer'         => array($this, 'resolveCustomer'),
            'Domain.ipAddress'        => array($this, 'resolveIpAddress'),
            'Domain.createdAt'        => array($this, 'resolveCreatedAt'),
            'Domain.expiresAt'        => array($this, 'resolveExpiresAt'),
            'Domain.subdomains'       => array($this, 'resolveDomainSubdomains'),
            'Domain.aliases'          => array($this, 'resolveDomainAliases'),
            'Subdomain.parent'        => array($this, 'resolveParent'),
            'Subdomain.customer'      => array($this, 'resolveCustomer'),
            'DomainAlias.domain'      => array($this, 'resolveAliasDomain'),
            'DomainAlias.customer'    => array($this, 'resolveCustomer'),
            'DomainAlias.subdomains'  => array($this, 'resolveAliasSubdomains')
        );
    }

    /**
     * One normalised vhost row becomes the array all three types resolve
     * against. Everything that is a value is here under its SDL field name, so
     * ResolverMap's fallback covers it; everything an edge needs is here under
     * an underscore-prefixed key, which no SDL field can collide with.
     *
     * @param array<string, mixed> $row A Repository\VirtualHosts normalised row
     * @param callable $toUnicode fn(string): string
     * @return array<string, mixed>
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        return TypeResolver::node($row['tag'], $row['key'], array(
            // i-MSCP stores punycode; spec section 7.1 says DomainName is
            // always returned as Unicode.
            'name'         => (string)call_user_func($toUnicode, $row['name']),
            'mountPoint'   => $row['mountPoint'],
            'documentRoot' => $row['documentRoot'],
            'wildcard'     => $row['wildcard'],
            'label'        => $row['label'],
            'forwarding'   => self::forwarding($row),
            'provisioning' => TypeResolver::provisioning($row['status']),
            '__kind'       => $row['kind'],
            '__domainId'   => $row['domainId'],
            '__ownerId'    => $row['ownerId'],
            '__parentTag'  => $row['parentTag'],
            '__parentKey'  => $row['parentKey'],
            '__ipId'       => $row['ipId']
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{url: string, type: string, keepHost: bool}|null
     */
    public static function forwarding(array $row): ?array
    {
        $url = (string)$row['urlForward'];

        // url_forward is NOT NULL DEFAULT 'no' in all four tables: the literal
        // string 'no' is how i-MSCP says "no forwarding".
        if ($url === '' || $url === 'no') {
            return null;
        }

        $type = $row['typeForward'] === null ? '' : (string)$row['typeForward'];

        return array(
            'url'  => $url,
            // type_forward is nullable while url_forward is not, so a row can
            // carry a URL and no type. The panel's own edit form defaults the
            // radio to 301, and this agrees with it rather than nulling a
            // non-null schema field.
            'type' => self::FORWARD_TYPES[$type] ?? 'PERMANENT_301',
            // 'On' only for a proxy; every other row stores 'Off'.
            'keepHost' => $row['hostForward'] === 'On'
        );
    }

    /**
     * One vhost by tag and key, batched: one query per kind per level, never
     * one per identifier.
     *
     * @param int|string $key
     */
    public function reference(string $tag, $key): SyncPromise
    {
        $kind = VirtualHosts::kindFor($tag);
        $vhosts = $this->vhosts;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'vhost:' . $kind,
            (int)$key,
            static function (array $keys) use ($vhosts, $kind) {
                $pairs = array();

                foreach ($keys as $one) {
                    $pairs[] = array($kind, $one);
                }

                $found = array();

                foreach ($vhosts->byKeys($pairs) as $composite => $row) {
                    $found[$row['key']] = $row;
                }

                return $found;
            }
        )->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    /**
     * The shaped Domain of one main domain. Task 13's Customer.domain uses it.
     */
    public function forDomain(int $domainId): SyncPromise
    {
        return $this->reference(NodeType::DOMAIN, $domainId);
    }

    public function resolveCustomer($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return call_user_func($this->customerRef, (int)$source['__ownerId']);
    }

    public function resolveIpAddress($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return call_user_func($this->ipRef, (int)$source['__ipId']);
    }

    public function resolveCreatedAt($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        // Gated like every other field on this type. A MAIL_READ-only token
        // reaches a Domain through MailAccount.host, and without this it could
        // read the account's dates through a credential scoped to mail.
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        $domainId = (int)$source['__domainId'];

        return $this->domainRow($domainId)
            ->then(static function ($domain) use ($domainId) {
                if ($domain === null) {
                    // Domain.createdAt is DateTime! - non-null. Returning null
                    // would null the whole Domain with graphql-php's bare
                    // "cannot return null for non-nullable field", which names
                    // the field and not the reason. A vhost row whose `domain`
                    // row has vanished is broken data, and saying so is more
                    // use than a nulled object.
                    throw new ApiException(
                        ErrorCode::INTERNAL,
                        'This virtual host has no domain row.',
                        array('domainId' => $domainId)
                    );
                }

                return TypeResolver::dateTime($domain->domain_created);
            });
    }

    public function resolveExpiresAt($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->domainRow((int)$source['__domainId'])
            ->then(static function ($domain) {
                // expiresAt is nullable, so a missing row is null here rather
                // than the error createdAt has to raise. i-MSCP also writes 0,
                // not NULL, for an account that never expires;
                // TypeResolver::dateTime() turns that into null rather than
                // 1970.
                return $domain === null
                    ? null : TypeResolver::dateTime($domain->domain_expires);
            });
    }

    public function resolveDomainSubdomains(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->ofKind(VirtualHosts::KIND_SUB, (int)$source['__domainId']);
    }

    public function resolveDomainAliases(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->ofKind(VirtualHosts::KIND_ALS, (int)$source['__domainId']);
    }

    public function resolveAliasDomain($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        return $this->reference(NodeType::DOMAIN, (int)$source['__domainId']);
    }

    public function resolveParent($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        // A Domain for a subdomain of the main domain, a DomainAlias for a
        // subdomain of an alias. Spec section 7.3 makes both a Subdomain, and
        // the parent is where the difference shows.
        return $this->reference($source['__parentTag'], $source['__parentKey']);
    }

    public function resolveAliasSubdomains(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::DOMAINS_READ);

        $vhosts = $this->vhosts;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'vhost:alssub-by-alias',
            (int)$source['__key'],
            static function (array $keys) use ($vhosts) {
                return $vhosts->aliasSubdomainsOf($keys);
            }
        )->then(static function ($rows) use ($toUnicode) {
            return self::shapeAll($rows, $toUnicode);
        });
    }

    private function ofKind(string $kind, int $domainId): SyncPromise
    {
        $vhosts = $this->vhosts;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'vhost:by-domain:' . $kind,
            $domainId,
            static function (array $keys) use ($vhosts, $kind) {
                return $vhosts->ofKind($kind, $keys);
            }
        )->then(static function ($rows) use ($toUnicode) {
            return self::shapeAll($rows, $toUnicode);
        });
    }

    /**
     * The `domain` model behind a vhost, for the two fields the normalised
     * vhost row does not carry: domain_created and domain_expires.
     *
     * The one load in this phase that goes through Anorm rather than through
     * hand-written SQL, and it is here because it is the only one that fits:
     * one table, one column, no join, no filter, no aggregate.
     * BatchLoader::byColumn() batches it exactly as keyed() would - it is
     * built on keyed() - and hands back a DomainModel, so the two resolvers
     * above read $domain->domain_created rather than $row['domain_created'].
     * That is the DX case for Task 9's models stated in code: the column names
     * are declared on the class, so an IDE completes them and a rename is a
     * rename rather than a search for a string.
     *
     * @return SyncPromise Resolves to DomainModel|null
     */
    private function domainRow(int $domainId): SyncPromise
    {
        return $this->loader
            ->byColumn(DomainModel::class, 'domain_id', $domainId)
            ->then(static function (array $models) {
                // byColumn() answers a list because a column need not be
                // unique; domain_id is the primary key, so this one is never
                // longer than one.
                return $models === array() ? null : $models[0];
            });
    }

    /**
     * @param array<int, array>|null $rows
     * @return array<int, array>
     */
    private static function shapeAll(?array $rows, callable $toUnicode): array
    {
        // A list field is non-null in the SDL, so "no rows" must be an empty
        // list. Returning null here would null the object the list hangs off.
        if ($rows === null) {
            return array();
        }

        $shaped = array();

        foreach ($rows as $row) {
            $shaped[] = self::shape($row, $toUnicode);
        }

        return $shaped;
    }
}
