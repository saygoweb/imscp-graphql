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
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ProvisioningFilter;
use InvalidArgumentException;

/**
 * MailAccount and Autoresponder - spec section 7.6.
 *
 * The one interesting mapping here is mail_type. i-MSCP stores the cross
 * product of {normal, alias, subdom, alssub} and {mail, forward, catchall},
 * sometimes comma-joined; the schema splits that in two, putting the vhost
 * half behind `host` and keeping only the account kind. Support\MailType is
 * the table, and this class is the only caller that turns a failure to read it
 * into a structured error rather than a fatal.
 */
final class MailResolver
{
    /** The vhost half of mail_type => the NodeType tag of that host. */
    const HOST_TAGS = array(
        MailType::HOST_DMN    => NodeType::DOMAIN,
        MailType::HOST_SUB    => NodeType::SUBDOMAIN,
        MailType::HOST_ALS    => NodeType::DOMAIN_ALIAS,
        MailType::HOST_ALSSUB => NodeType::ALIAS_SUBDOMAIN
    );

    /** i-MSCP's "this column holds nothing" spelling, used across the schema. */
    const NOTHING = '_no_';

    /** @var Db */
    private $db;

    /** @var BatchLoader */
    private $loader;

    /** @var callable fn(string): string */
    private $toUnicode;

    /** @var callable fn(string $address): ?int */
    private $mailboxUsage;

    /** @var callable fn(string $tag, $key): SyncPromise */
    private $hostRef;

    public function __construct(
        Db $db, BatchLoader $loader, callable $toUnicode, callable $mailboxUsage,
        callable $hostRef
    ) {
        $this->db = $db;
        $this->loader = $loader;
        $this->toUnicode = $toUnicode;
        $this->mailboxUsage = $mailboxUsage;
        $this->hostRef = $hostRef;
    }

    /**
     * @return array<string, callable>
     */
    public function map(): array
    {
        return array(
            'MailAccount.host'      => array($this, 'resolveHost'),
            'MailAccount.quotaUsed' => array($this, 'resolveQuotaUsed'),
            'Customer.mailAccounts' => array($this, 'resolveCustomerMailAccounts')
        );
    }

    /**
     * @param array<string, mixed> $row A row of mail_users
     * @param callable $toUnicode fn(string): string
     * @return array<string, mixed>
     * @throws ApiException when mail_type is a value the codec does not know
     */
    public static function shape(array $row, callable $toUnicode): array
    {
        $mailType = (string)$row['mail_type'];

        try {
            $kind = MailType::kindOf($mailType);
            $hostType = MailType::hostTypeOf($mailType);
        } catch (InvalidArgumentException $e) {
            // Uncaught this is a 500 with a stack trace and no clue which row
            // caused it. Spec section 9 wants a code and an extension.
            throw new ApiException(
                ErrorCode::INTERNAL,
                'This mail account has a type the API does not recognise.',
                array('mailType' => $mailType),
                $e
            );
        }

        // sub_id is 0 for an address on the main domain, and 0 is not an
        // identifier: the host is the domain itself.
        $hostKey = $hostType === MailType::HOST_DMN
            ? (int)$row['domain_id'] : (int)$row['sub_id'];

        return TypeResolver::node(NodeType::MAIL_ACCOUNT, (int)$row['mail_id'], array(
            'address'       => (string)call_user_func($toUnicode, (string)$row['mail_addr']),
            'kind'          => $kind,
            // A catch-all's targets are in mail_acc; everything else's in
            // mail_forward (mail_catchall_add.php:182).
            'forwardTo'     => self::forwards(
                $kind === MailType::KIND_CATCHALL ? $row['mail_acc'] : $row['mail_forward']
            ),
            // 0 is unlimited, as everywhere else in i-MSCP.
            'quota'         => (int)$row['quota'] === 0
                ? null : TypeResolver::bigInt($row['quota']),
            'active'        => $row['po_active'] === 'yes',
            'autoresponder' => self::autoresponder($row),
            'provisioning'  => TypeResolver::provisioning(
                $row['status'] === null ? null : (string)$row['status']
            ),
            '__domainId'    => (int)$row['domain_id'],
            '__hostTag'     => self::HOST_TAGS[$hostType],
            '__hostKey'     => $hostKey
        ));
    }

    public function reference(int $mailId): SyncPromise
    {
        $db = $this->db;
        $toUnicode = $this->toUnicode;

        return $this->loader->keyed(
            'mail:row',
            $mailId,
            static function (array $keys) use ($db) {
                $rows = $db->rows(
                    'SELECT * FROM mail_users WHERE mail_id IN ('
                        . $db->placeholders(count($keys)) . ')',
                    $keys
                );
                $byId = array();

                foreach ($rows as $row) {
                    $byId[(int)$row['mail_id']] = $row;
                }

                return $byId;
            }
        )->then(static function ($row) use ($toUnicode) {
            return $row === null ? null : self::shape($row, $toUnicode);
        });
    }

    public function resolveHost($source, array $args, $context, ResolveInfo $info): SyncPromise
    {
        TypeResolver::requireScope($context, Scope::MAIL_READ);

        return call_user_func($this->hostRef, $source['__hostTag'], $source['__hostKey']);
    }

    public function resolveQuotaUsed($source, array $args, $context, ResolveInfo $info): ?string
    {
        TypeResolver::requireScope($context, Scope::MAIL_READ);

        // Spec section 7.6: null when there is no mailbox, when no quota is
        // set, or when the file cannot be read. A forward-only address has no
        // maildir at all, so asking the filesystem about it would be a stat
        // per row for an answer that is always null.
        if ($source['quota'] === null
            || !in_array($source['kind'], array(
                MailType::KIND_MAILBOX, MailType::KIND_MAILBOX_AND_FORWARD
            ), true)
        ) {
            return null;
        }

        $bytes = call_user_func($this->mailboxUsage, $source['address']);

        return $bytes === null ? null : TypeResolver::bigInt($bytes);
    }

    /**
     * Customer.mailAccounts - a filtered, paged connection.
     *
     * Decision D6: the batched query has no LIMIT and the page is sliced per
     * parent afterwards, because a per-parent LIMIT inside one query needs a
     * window function MariaDB 10.1 does not have and the alternative is a
     * query per customer. totalCount is then free: it is the length of the
     * parent's rows before the slice.
     */
    public function resolveCustomerMailAccounts(
        $source, array $args, $context, ResolveInfo $info
    ): SyncPromise {
        TypeResolver::requireScope($context, Scope::MAIL_READ);

        $filter = isset($args['filter']) && is_array($args['filter'])
            ? $args['filter'] : array();
        $page = TypeResolver::page($args['page'] ?? null, $context);
        $db = $this->db;
        $toUnicode = $this->toUnicode;

        // Every customer in the same selection set passes the same filter, so
        // the bucket name carries it and they all share one query. Two
        // different filters in one document are two buckets, which is right:
        // they are two different questions.
        return $this->loader->keyed(
            'mail:by-domain:' . self::bucket($filter),
            (int)$source['__domainId'],
            static function (array $keys) use ($db, $filter) {
                return self::loadByDomain($db, $keys, $filter);
            }
        )->then(static function ($rows) use ($page, $toUnicode) {
            $rows = $rows === null ? array() : $rows;
            $nodes = array();

            foreach (array_slice($rows, $page['offset'], $page['limit']) as $row) {
                $nodes[] = self::shape($row, $toUnicode);
            }

            return array('totalCount' => count($rows), 'nodes' => $nodes);
        });
    }

    /**
     * @param int[] $domainIds
     * @param array<string, mixed> $filter
     * @return array<int, array> domainId => rows
     */
    private static function loadByDomain(Db $db, array $domainIds, array $filter): array
    {
        $sql = 'SELECT * FROM mail_users WHERE domain_id IN ('
            . $db->placeholders(count($domainIds)) . ')';
        $bind = $domainIds;

        if (isset($filter['kind'])) {
            // The schema's kind is half of mail_type, so filtering on it means
            // listing every stored value whose second half matches - all four
            // vhost flavours of that kind.
            $types = array();

            foreach (MailType::all() as $stored) {
                if (MailType::kindOf($stored) === $filter['kind']) {
                    $types[] = $stored;
                }
            }

            $sql .= ' AND mail_type IN (' . $db->placeholders(count($types)) . ')';
            $bind = array_merge($bind, $types);
        }

        if (isset($filter['address']) && $filter['address'] !== '') {
            // Substring, as the panel's own mail list does. The wildcards are
            // added here rather than taken from the client, so a client cannot
            // smuggle a leading % past an index it does not know about.
            $sql .= ' AND mail_addr LIKE ?';
            $bind[] = '%' . self::escapeLike((string)$filter['address']) . '%';
        }

        list($stateSql, $stateBind) = ProvisioningFilter::clause(
            'status', $filter['state'] ?? null
        );
        $sql .= $stateSql;
        $bind = array_merge($bind, $stateBind);

        // Ordered so that a page is stable between requests; mail_id is the
        // only column guaranteed unique.
        $sql .= ' ORDER BY mail_id';

        $byDomain = array();

        foreach ($domainIds as $domainId) {
            // Every requested key present, so that a customer with no mail
            // accounts gets an empty connection rather than a null one.
            $byDomain[(int)$domainId] = array();
        }

        foreach ($db->rows($sql, $bind) as $row) {
            $byDomain[(int)$row['domain_id']][] = $row;
        }

        return $byDomain;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function bucket(array $filter): string
    {
        ksort($filter);

        return md5(serialize($filter));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $value);
    }

    /**
     * @param mixed $stored
     * @return string[]
     */
    private static function forwards($stored): array
    {
        // forwardTo is [EmailAddress!]! - non-null - so "none" is an empty
        // list. i-MSCP writes NULL for a mailbox and the literal '_no_' for a
        // row that once had forwards and no longer does.
        if ($stored === null || $stored === '' || $stored === self::NOTHING) {
            return array();
        }

        $addresses = array();

        foreach (explode(',', (string)$stored) as $address) {
            $address = trim($address);

            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{enabled: bool, message: string}|null
     */
    private static function autoresponder(array $row): ?array
    {
        if (!(int)$row['mail_auto_respond']) {
            // Autoresponder.message is String!, so an autoresponder that is
            // off must be an absent object rather than an object with nothing
            // in it.
            return null;
        }

        return array(
            'enabled' => true,
            'message' => $row['mail_auto_respond_text'] === null
                ? '' : (string)$row['mail_auto_respond_text']
        );
    }
}
