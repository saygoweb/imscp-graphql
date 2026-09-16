<?php
namespace iMSCP\Plugin\SGW_GraphQL\Support;

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

use InvalidArgumentException;

/**
 * What a customer is allowed, in the panel's own terms.
 *
 * CORE-DEBT(C1): transcribed from gui/include/Client.php:82-135
 *   (customerHasFeature()). That function reads $_SESSION['user_id'] and
 *   caches its answer in a static that is not keyed by user, so it can only
 *   ever answer for the account the request is acting as - which is no use to
 *   a reseller reading three customers in one document. Retire this copy when
 *   C1 threads an explicit $adminId through it and keys the cache by that.
 *
 * Spec section 7.5 requires this answer to be the panel's, verbatim and per
 * name, so that the API cannot disagree with the panel about what a customer
 * may do.
 *
 * customerHasFeature() reads its five config keys off $cfg = Registry::get(
 * 'config'), an ArrayAccess object (gui/src/Config/ArrayConfig.php,
 * extended by DbConfig.php): $cfg['KEY'] reaches offsetGet(), which calls
 * get(), and get() throws when the key is missing (ArrayConfig.php:86 /
 * DbConfig.php:390) rather than returning null. So the core does not fail
 * open on an absent key - it fails loudly. assertConfigComplete() below
 * transcribes that: a $config missing one of CONFIG_KEYS throws here too,
 * instead of every key it gates quietly reading as "available".
 */
final class CustomerFeatures
{
    /** Panel configuration keys this class reads. */
    const CONFIG_KEYS = array(
        'NAMED_PACKAGE', 'WEB_STATISTIC_PACKAGES', 'BACKUP_DOMAINS',
        'ENABLE_SSL', 'IMSCP_SUPPORT_SYSTEM'
    );

    /** @var array<string, bool> */
    private $features;

    private function __construct(array $features)
    {
        $this->features = $features;
    }

    /**
     * @param array $domain                 A row of the `domain` table
     * @param array $config                 The panel configuration, at least CONFIG_KEYS
     * @param bool  $resellerSupportSystem  reseller_props.support_system == 'yes'
     * @throws InvalidArgumentException
     */
    public static function fromDomainRow(
        array $domain, array $config, bool $resellerSupportSystem
    ): self {
        self::assertConfigComplete($config);

        return new self(array(
            'php'       => $domain['domain_php'] == 'yes',

            // The precedence here is core's, and is transcribed rather than
            // corrected: && binds tighter than ||, so this reads as
            // (system AND fopen) OR display_errors OR disable_functions.
            // The API must agree with the panel, bug for bug.
            'phpEditor' => $domain['phpini_perm_system'] == 'yes'
                && $domain['phpini_perm_allow_url_fopen'] == 'yes'
                || $domain['phpini_perm_display_errors'] == 'yes'
                || in_array(
                    $domain['phpini_perm_disable_functions'], array('yes', 'exec')
                ),

            'cgi'           => $domain['domain_cgi'] == 'yes',
            'customDns'     => $domain['domain_dns'] != 'no'
                && $config['NAMED_PACKAGE'] != 'Servers::noserver',
            'externalMail'  => $domain['domain_external_mail'] == 'yes',
            'backup'        => $config['BACKUP_DOMAINS'] != 'no'
                && $domain['allowbackup'] != '',
            'ssl'           => $config['ENABLE_SSL'] == 1,
            'webStats'      => $config['WEB_STATISTIC_PACKAGES'] != 'no',
            'supportSystem' => $config['IMSCP_SUPPORT_SYSTEM']
                ? $resellerSupportSystem : false
        ));
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertConfigComplete(array $config): void
    {
        $missing = array_diff(self::CONFIG_KEYS, array_keys($config));

        if ($missing !== array()) {
            throw new InvalidArgumentException(sprintf(
                'The panel configuration is missing: %s.', implode(', ', $missing)
            ));
        }
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->features;
    }

    public function has(string $name): bool
    {
        return $this->features[$name] ?? false;
    }
}
