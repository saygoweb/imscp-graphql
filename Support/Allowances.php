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

use iMSCP\Plugin\SGW_GraphQL\Security\Guard;

/**
 * The 25 fields of hosting_plans.props (M5), whichever end they came from: a
 * hosting plan the reseller already has, or an explicit CustomerAllowancesInput.
 * Everything downstream takes one of these, so the code that writes a `domain`
 * row cannot tell which the caller sent (decision D24).
 */
final class Allowances
{
    /** The six countable allowances, GraphQL name => props field. */
    const LIMITS = PlanProps::ALLOWANCES;

    /** Feature name => props field. The six that M7 stores with underscores. */
    const UNDERSCORED = array(
        'php'                 => 'php',
        'cgi'                 => 'cgi',
        'backup'              => 'backup',
        'customDns'           => 'dns',
        'externalMail'        => 'extMailServer',
        'webFolderProtection' => 'webFolderProtection'
    );

    /** @var array<string, string> props field => raw value */
    private $fields;

    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    public static function fromPlanProps(PlanProps $props): self
    {
        $fields = array();

        foreach (PlanProps::FIELDS as $field) {
            $fields[$field] = $props->raw($field);
        }

        return new self($fields);
    }

    /**
     * CustomerAllowancesInput. Every limit is -1, 0 or a positive integer;
     * disk, traffic and the mail quota are all bytes, because every BigInt in
     * this schema is bytes (D3) - the same unit PlanProps::storage() already
     * promises on the way out. disk and traffic are converted to MiB here,
     * the way the props store them, the way PlanProps::storage() converts
     * them back on the way out.
     *
     * @throws \iMSCP\Plugin\SGW_GraphQL\Support\ApiException BAD_USER_INPUT
     */
    public static function fromInput(array $input): self
    {
        $fields = array();

        foreach (self::LIMITS as $name => $field) {
            $fields[$field] = (string)self::limitValue($input, $name);
        }

        $fields['traffic'] = (string)self::mibFromBytes($input, 'traffic');
        $fields['disk'] = (string)self::mibFromBytes($input, 'disk');
        $fields['mailQuota'] = (string)self::mailQuota($input);

        foreach (array('php', 'cgi', 'customDns', 'externalMail') as $name) {
            $fields[self::UNDERSCORED[$name]] = self::flag($input, $name, false);
        }

        // The panel's own default is protection on (hosting_plan_add.php:481).
        $fields['webFolderProtection'] = self::flag($input, 'webFolderProtection', true);
        $fields['backup'] = self::backup($input);
        $fields['phpEditor'] = !empty($input['phpEditor']) ? 'yes' : 'no';

        foreach (array('phpiniAllowUrlFopen', 'phpiniDisplayErrors', 'phpiniDisableFunctions', 'phpMailFunction') as $name) {
            $fields[$name] = !empty($input[$name]) ? 'yes' : 'no';
        }

        foreach (array('phpiniPostMaxSize', 'phpiniUploadMaxFileSize', 'phpiniMaxExecutionTime',
                       'phpiniMaxInputTime', 'phpiniMemoryLimit') as $name) {
            $fields[$name] = (string)max(0, (int)($input[$name] ?? 0));
        }

        return new self($fields);
    }

    public function limit(string $allowance): int
    {
        return (int)$this->fields[self::LIMITS[$allowance]];
    }

    public function storage(string $which): int
    {
        return (int)$this->fields[$which];
    }

    public function feature(string $name): string
    {
        return $this->fields[self::UNDERSCORED[$name]];
    }

    public function phpIni(string $name): string
    {
        return $this->fields[$name];
    }

    public function backupTargets(): string
    {
        return $this->fields['backup'];
    }

    public function toProps(): PlanProps
    {
        return PlanProps::fromFields($this->fields);
    }

    /**
     * The `domain` columns these allowances become, with M7 applied: the six
     * underscored fields are stored in `domain` without their underscores,
     * which user_add3.php:145-150 does with str_replace and this does once.
     *
     * @return array<string, string|int>
     */
    public function domainColumns(): array
    {
        return array(
            'domain_subd_limit'             => $this->limit('subdomains'),
            'domain_alias_limit'            => $this->limit('domainAliases'),
            'domain_mailacc_limit'          => $this->limit('mailAccounts'),
            'domain_ftpacc_limit'           => $this->limit('ftpUsers'),
            'domain_sqld_limit'             => $this->limit('sqlDatabases'),
            'domain_sqlu_limit'             => $this->limit('sqlUsers'),
            'domain_traffic_limit'          => $this->storage('traffic'),
            'domain_disk_limit'             => $this->storage('disk'),
            'mail_quota'                    => $this->storage('mailQuota'),
            'domain_php'                    => self::stripped($this->feature('php')),
            'domain_cgi'                    => self::stripped($this->feature('cgi')),
            'allowbackup'                   => self::stripped($this->backupTargets()),
            'domain_dns'                    => self::stripped($this->feature('customDns')),
            'domain_external_mail'          => self::stripped($this->feature('externalMail')),
            'web_folder_protection'         => self::stripped($this->feature('webFolderProtection')),
            'phpini_perm_system'            => $this->phpIni('phpEditor'),
            'phpini_perm_allow_url_fopen'   => $this->phpIni('phpiniAllowUrlFopen'),
            'phpini_perm_display_errors'    => $this->phpIni('phpiniDisplayErrors'),
            'phpini_perm_disable_functions' => $this->phpIni('phpiniDisableFunctions'),
            'phpini_perm_mail_function'     => $this->phpIni('phpMailFunction')
        );
    }

    /** M7: '_yes_' => 'yes', '_dmn_|_sql_' => 'dmn|sql'. */
    private static function stripped(string $value): string
    {
        return str_replace('_', '', $value);
    }

    private static function limitValue(array $input, string $name): int
    {
        $value = $input[$name] ?? 0;

        if (!is_int($value) || $value < -1) {
            throw Guard::badInput(
                'input.allowances.' . $name,
                'A limit is -1 to withhold the feature, 0 for unlimited, or a positive number.'
            );
        }

        return $value;
    }

    /**
     * A3/D3: disk and traffic arrive as bytes, like every other BigInt, but
     * the props store them in MiB (the same unit PlanProps::mibToBytes()
     * converts back from). -1 (withheld) and 0 (unlimited) pass through
     * unconverted; a positive value that is not a whole number of MiB is
     * refused rather than truncated.
     */
    private static function mibFromBytes(array $input, string $name): int
    {
        $bytes = self::limitValue($input, $name);

        if ($bytes <= 0) {
            return $bytes;
        }

        if ($bytes % 1048576 !== 0) {
            throw Guard::badInput(
                'input.allowances.' . $name,
                sprintf('A %s limit is a whole number of MiB, given in bytes.', $name)
            );
        }

        return intdiv($bytes, 1048576);
    }

    private static function mailQuota(array $input): int
    {
        $value = $input['mailQuota'] ?? 0;

        if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 0) {
            throw Guard::badInput('input.allowances.mailQuota', 'A mail quota is a whole number of bytes, or 0 for none.');
        }

        return $value;
    }

    private static function flag(array $input, string $name, bool $default): string
    {
        $on = array_key_exists($name, $input) && $input[$name] !== null ? (bool)$input[$name] : $default;

        return $on ? '_yes_' : '_no_';
    }

    private static function backup(array $input): string
    {
        $targets = array();

        foreach (array_values((array)($input['backup'] ?? array())) as $index => $one) {
            $key = array_search((string)$one, PlanProps::BACKUP_TARGETS, true);

            if ($key === false) {
                throw Guard::badInput('input.allowances.backup', 'A backup target is DOMAIN, SQL or MAIL.', array('index' => $index));
            }

            $targets[$key] = true;
        }

        return $targets === array() ? '_no_' : implode('|', array_keys($targets));
    }
}
