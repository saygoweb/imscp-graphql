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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;
use InvalidArgumentException;

/**
 * Forwarding, document-root and shared-mount-point input, shared by the domain, subdomain and
 * alias services: the half of those pages' rules that needs the panel.
 */
final class VhostInput
{
    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    /**
     * A ForwardingInput as the three forwarding columns.
     *
     * @param array|null $input null for no forwarding
     * @param string     $field e.g. 'input.forwarding'
     * @return array{url: string, type: string|null, host: string}
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public function forwarding(?array $input, string $selfAsciiName, string $field): array
    {
        if ($input === null) {
            return VhostRules::noForwarding();
        }

        $type = (string)($input['type'] ?? '');

        if (!isset(VhostRules::PANEL_FORWARD_TYPES[$type])) {
            throw Guard::badInput($field . '.type', 'Unknown forward type.');
        }

        $proxy = $type === 'PROXY';
        $keepHost = (bool)($input['keepHost'] ?? false);

        if ($keepHost && !$proxy) {
            throw Guard::badInput($field . '.keepHost', 'keepHost applies to PROXY forwarding only.');
        }

        $url = trim((string)($input['url'] ?? ''));

        if ($url === '') {
            throw Guard::badInput($field . '.url', 'A forward URL is required.');
        }

        try {
            $normalised = $this->kit->core()->normaliseForwardUrl($url, $selfAsciiName, $proxy);
        } catch (InvalidArgumentException $e) {
            throw Guard::badInput($field . '.url', $e->getMessage());
        }

        return array(
            'url'  => $normalised,
            'type' => VhostRules::PANEL_FORWARD_TYPES[$type],
            'host' => $keepHost ? 'On' : 'Off'
        );
    }

    /**
     * A document root, normalised and checked to exist.
     *
     * CORE-DEBT(C3): transcribed from gui/public/client/subdomain_edit.php:327-351
     *   and its twins in domain_edit.php and alias_edit.php.
     *
     * @param string $mountPoint The host's mount point: '/' for the main domain
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public function documentRoot(CustomerAccount $account, string $mountPoint, string $input, string $field): string
    {
        $root = VhostRules::documentRoot($input, array($this->kit->core(), 'normalisePath'));

        if ($root === null) {
            throw Guard::badInput($field, 'A document root must be /htdocs or a directory inside it.');
        }

        $relative = VhostRules::relativeToHtdocs($root);
        $vfsRoot = rtrim($mountPoint, '/') . VhostRules::HTDOCS;

        if ($relative !== '/' && !$this->kit->probe()->exists($account->getUsername(), $vfsRoot, $relative)) {
            throw Guard::badInput($field, 'The new document root must already exist inside /htdocs.');
        }

        return $root;
    }

    /**
     * The host's columns after a partial update (decision D14): the current
     * row, overlaid with what the input names, then the pages' rules applied
     * to the result.
     *
     * @param array<string, mixed> $row   A VirtualHosts normalised row
     * @param array<string, mixed> $input documentRoot, forwarding, wildcard - each optional
     * @return array{documentRoot: string, url: string, type: string|null, host: string, wildcard: string}
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public function update(CustomerAccount $account, array $row, array $input): array
    {
        $hasForwarding = array_key_exists('forwarding', $input);
        $hasRoot = isset($input['documentRoot']);
        $hasWildcard = isset($input['wildcard']);

        if (!$hasForwarding && !$hasRoot && !$hasWildcard) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $forwarding = $hasForwarding
            ? $this->forwarding($input['forwarding'], (string)$row['name'], 'input.forwarding')
            : array('url' => $row['urlForward'], 'type' => $row['typeForward'], 'host' => $row['hostForward']);

        $documentRoot = (string)$row['documentRoot'];

        if ($hasRoot) {
            // The pages offer a document root only when forwarding is off
            // (domain_edit.php:264: "elseif").
            if ($forwarding['url'] !== 'no') {
                throw Guard::badInput(
                    'input.documentRoot',
                    'A forwarded host serves no document root. Remove the forwarding in the same update to set one.'
                );
            }

            $documentRoot = $this->documentRoot($account, (string)$row['mountPoint'], (string)$input['documentRoot'], 'input.documentRoot');
        }

        return array(
            'documentRoot' => $documentRoot,
            'url'          => $forwarding['url'],
            'type'         => $forwarding['type'],
            'host'         => $forwarding['host'],
            'wildcard'     => $hasWildcard ? VhostRules::wildcard((bool)$input['wildcard']) : VhostRules::wildcard((bool)$row['wildcard'])
        );
    }

    /**
     * The mount point of another of the same customer's hosts, for
     * sharedMountPointOf (subdomain_add.php:290-310, alias_add.php:269-293).
     *
     * @param mixed $encoded
     * @throws ApiException NOT_FOUND, BAD_USER_INPUT
     */
    public function sharedMountPoint(Identity $caller, $encoded, int $ownerId): string
    {
        $shared = $this->kit->guard()->target(
            $caller, $encoded,
            array(NodeType::DOMAIN, NodeType::SUBDOMAIN, NodeType::DOMAIN_ALIAS, NodeType::ALIAS_SUBDOMAIN),
            Scope::DOMAINS_WRITE, 'input.sharedMountPointOf'
        );

        if ($shared->getOwnerId() !== $ownerId) {
            // Reachable by a reseller, but another customer's: from the point
            // of view of this subdomain, it does not exist.
            throw new ApiException(
                ErrorCode::NOT_FOUND, Guard::notFound()->getMessage(), array('field' => 'input.sharedMountPointOf')
            );
        }

        $row = $this->kit->vhost($shared->getTag(), $shared->getKey());

        if ($row['status'] !== 'ok' || $row['urlForward'] !== 'no') {
            throw Guard::badInput(
                'input.sharedMountPointOf',
                'Only a settled host that is not forwarded has a directory to share.'
            );
        }

        return (string)$row['mountPoint'];
    }
}
