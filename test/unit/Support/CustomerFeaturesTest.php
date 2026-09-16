<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\CustomerFeatures;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CustomerFeaturesTest extends TestCase
{
    /**
     * A customer with everything switched on, and a server with everything
     * available.
     */
    private function domain(array $overrides = array()): array
    {
        return array_merge(array(
            'domain_php'                    => 'yes',
            'domain_cgi'                    => 'yes',
            'domain_dns'                    => 'yes',
            'domain_external_mail'          => 'yes',
            'allowbackup'                   => 'dmn|sql|mail',
            'phpini_perm_system'            => 'yes',
            'phpini_perm_allow_url_fopen'   => 'yes',
            'phpini_perm_display_errors'    => 'no',
            'phpini_perm_disable_functions' => 'no'
        ), $overrides);
    }

    private function config(array $overrides = array()): array
    {
        return array_merge(array(
            'NAMED_PACKAGE'          => 'Servers::named::bind',
            'WEB_STATISTIC_PACKAGES' => 'AWStats',
            'BACKUP_DOMAINS'         => 'yes',
            'ENABLE_SSL'             => 1,
            'IMSCP_SUPPORT_SYSTEM'   => 1
        ), $overrides);
    }

    private function features(array $domain = array(), array $config = array(), bool $support = true): CustomerFeatures
    {
        return CustomerFeatures::fromDomainRow(
            $this->domain($domain), $this->config($config), $support
        );
    }

    public function testEverythingOnIsEverythingTrue(): void
    {
        self::assertSame(
            array(
                'php' => true, 'phpEditor' => true, 'cgi' => true,
                'customDns' => true, 'externalMail' => true, 'backup' => true,
                'ssl' => true, 'webStats' => true, 'supportSystem' => true
            ),
            $this->features()->toArray()
        );
    }

    public function testPhpAndCgiComeStraightFromTheDomainRow(): void
    {
        self::assertFalse($this->features(array('domain_php' => 'no'))->has('php'));
        self::assertFalse($this->features(array('domain_cgi' => 'no'))->has('cgi'));
    }

    public function testCustomDnsNeedsBothTheCustomerAndANameServer(): void
    {
        self::assertFalse($this->features(array('domain_dns' => 'no'))->has('customDns'));
        self::assertFalse(
            $this->features(array(), array('NAMED_PACKAGE' => 'Servers::noserver'))
                ->has('customDns')
        );
    }

    public function testBackupNeedsBothTheServerAndANonEmptyAllowBackup(): void
    {
        self::assertFalse($this->features(array('allowbackup' => ''))->has('backup'));
        self::assertFalse(
            $this->features(array(), array('BACKUP_DOMAINS' => 'no'))->has('backup')
        );
    }

    public function testWebStatsAndSslAreServerWide(): void
    {
        self::assertFalse(
            $this->features(array(), array('WEB_STATISTIC_PACKAGES' => 'no'))->has('webStats')
        );
        self::assertFalse($this->features(array(), array('ENABLE_SSL' => 0))->has('ssl'));
    }

    public function testSupportNeedsTheServerSwitchAndTheReseller(): void
    {
        self::assertFalse($this->features(array(), array(), false)->has('supportSystem'));
        self::assertFalse(
            $this->features(array(), array('IMSCP_SUPPORT_SYSTEM' => 0), true)
                ->has('supportSystem')
        );
    }

    /**
     * The php editor expression in gui/include/Client.php:99 is
     *   (system AND allow_url_fopen) OR display_errors OR disable_functions
     * because && binds tighter than ||. That precedence is transcribed rather
     * than corrected: the API must agree with the panel, bug for bug.
     *
     * @dataProvider phpEditorCases
     */
    public function testPhpEditorTranscribesCoresPrecedence(array $perms, bool $expected): void
    {
        self::assertSame($expected, $this->features($perms)->has('phpEditor'));
    }

    public function phpEditorCases(): array
    {
        return array(
            'system and fopen' => array(
                array('phpini_perm_system' => 'yes', 'phpini_perm_allow_url_fopen' => 'yes'),
                true
            ),
            'system alone is not enough' => array(
                array('phpini_perm_system' => 'yes', 'phpini_perm_allow_url_fopen' => 'no'),
                false
            ),
            'display errors alone is enough' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_display_errors' => 'yes'
                ),
                true
            ),
            'disable functions yes is enough' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_disable_functions' => 'yes'
                ),
                true
            ),
            'disable functions exec is enough' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_disable_functions' => 'exec'
                ),
                true
            ),
            'nothing' => array(
                array(
                    'phpini_perm_system' => 'no', 'phpini_perm_allow_url_fopen' => 'no',
                    'phpini_perm_display_errors' => 'no',
                    'phpini_perm_disable_functions' => 'no'
                ),
                false
            )
        );
    }

    public function testAnUnknownFeatureNameIsFalseRatherThanAWarning(): void
    {
        self::assertFalse($this->features()->has('protectedAreas'));
    }

    /**
     * gui/include/Client.php:82-135 reads $cfg['KEY'] on the panel's own
     * ArrayConfig/DbConfig, whose offsetGet() throws when the key is
     * missing rather than returning null (gui/src/Config/ArrayConfig.php:86,
     * gui/src/Config/DbConfig.php:390). A $config short one of CONFIG_KEYS
     * must fail the same way here, loudly, rather than quietly reporting
     * the feature it gates as available.
     */
    public function testAConfigMissingAKeyThrowsRatherThanFailingOpen(): void
    {
        $config = $this->config();
        unset($config['WEB_STATISTIC_PACKAGES']);

        self::expectException(InvalidArgumentException::class);

        CustomerFeatures::fromDomainRow($this->domain(), $config, true);
    }

    public function testTheConfigKeysAreDeclared(): void
    {
        // The resolver reads exactly these out of the panel's registry, so the
        // list must not drift from what fromDomainRow() actually uses.
        self::assertSame(
            array(
                'NAMED_PACKAGE', 'WEB_STATISTIC_PACKAGES', 'BACKUP_DOMAINS',
                'ENABLE_SSL', 'IMSCP_SUPPORT_SYSTEM'
            ),
            CustomerFeatures::CONFIG_KEYS
        );
    }
}
