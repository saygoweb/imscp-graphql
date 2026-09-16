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
 * The 25 positional, semicolon-delimited fields of hosting_plans.props.
 *
 * CORE-DEBT(C4): transcribed from gui/public/reseller/user_add3.php:133-139
 *   (the list() that reads it) and gui/public/reseller/hosting_plan_add.php:446-457
 *   (the concatenation that writes it). Retire this copy when C4 lands a codec
 *   in core.
 *
 * Spec section 20 names getting this wrong as risk 4: a misread field creates
 * a customer with the wrong limits, silently. The re-emitted string is
 * therefore built from the fields as parsed, and the round-trip is a property
 * test rather than a couple of examples.
 */
final class PlanProps
{
    /** In the order user_add3.php:133 destructures them. */
    const FIELDS = array(
        'php', 'cgi', 'sub', 'als', 'mail', 'ftp', 'sqlDb', 'sqlUser',
        'traffic', 'disk', 'backup', 'dns', 'phpEditor', 'phpiniAllowUrlFopen',
        'phpiniDisplayErrors', 'phpiniDisableFunctions', 'phpMailFunction',
        'phpiniPostMaxSize', 'phpiniUploadMaxFileSize', 'phpiniMaxExecutionTime',
        'phpiniMaxInputTime', 'phpiniMemoryLimit', 'extMailServer',
        'webFolderProtection', 'mailQuota'
    );

    /** GraphQL allowance name => the props field holding its limit. */
    const ALLOWANCES = array(
        'subdomains'    => 'sub',
        'domainAliases' => 'als',
        'mailAccounts'  => 'mail',
        'ftpUsers'      => 'ftp',
        'sqlDatabases'  => 'sqlDb',
        'sqlUsers'      => 'sqlUser'
    );

    /** The props spelling of a backup target => the schema's. */
    const BACKUP_TARGETS = array(
        '_dmn_'  => 'DOMAIN',
        '_sql_'  => 'SQL',
        '_mail_' => 'MAIL'
    );

    /** @var array<string, string> Field name => raw value, exactly as stored. */
    private $fields;

    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parse(string $props): self
    {
        $values = explode(';', $props);

        // A 26th field would silently shift every reader by one, which is how
        // a customer ends up with somebody else's limits.
        if (count($values) !== count(self::FIELDS)) {
            throw new InvalidArgumentException(sprintf(
                'A hosting plan has %d properties; this one has %d.',
                count(self::FIELDS), count($values)
            ));
        }

        return new self(array_combine(self::FIELDS, $values));
    }

    /**
     * Byte-identical to what was parsed.
     */
    public function toString(): string
    {
        return implode(';', array_values($this->fields));
    }

    /**
     * The raw stored value of one field.
     *
     * @throws InvalidArgumentException
     */
    public function raw(string $field): string
    {
        if (!array_key_exists($field, $this->fields)) {
            throw new InvalidArgumentException(sprintf(
                'A hosting plan has no "%s" property.', $field
            ));
        }

        return $this->fields[$field];
    }

    /**
     * A countable allowance, in the same three-valued encoding a customer's
     * own limit columns use: -1 withheld, 0 unlimited, n = n. Spec section 2.3.
     *
     * @return array{enabled: bool, limit: int|null}
     * @throws InvalidArgumentException
     */
    public function allowance(string $name): array
    {
        if (!isset(self::ALLOWANCES[$name])) {
            throw new InvalidArgumentException(sprintf(
                'A hosting plan has no "%s" allowance.', $name
            ));
        }

        $quota = Quota::fromCustomerLimit((int)$this->raw(self::ALLOWANCES[$name]), 0);

        return array('enabled' => $quota->isEnabled(), 'limit' => $quota->getLimit());
    }

    /**
     * Disk and traffic are stored in MiB; the mail quota is already stored in
     * bytes, converted once at write time by hosting_plan_add.php:457. Every
     * BigInt in this schema is bytes (decision D3), so disk and traffic are
     * converted here and the mail quota is passed through. Null means
     * unlimited.
     *
     * @return array{disk: int|null, traffic: int|null, mailQuota: int|null}
     */
    public function storage(): array
    {
        $mailQuota = (int)$this->raw('mailQuota');

        return array(
            'disk'      => self::mibToBytes((int)$this->raw('disk')),
            'traffic'   => self::mibToBytes((int)$this->raw('traffic')),
            'mailQuota' => $mailQuota === 0 ? null : $mailQuota
        );
    }

    /**
     * @return array{php: bool, phpEditor: bool, cgi: bool, customDns: bool,
     *               externalMail: bool, webFolderProtection: bool, backup: string[]}
     */
    public function features(): array
    {
        return array(
            'php'                 => $this->raw('php') === '_yes_',
            'phpEditor'           => $this->raw('phpEditor') === 'yes',
            'cgi'                 => $this->raw('cgi') === '_yes_',
            'customDns'           => $this->raw('dns') === '_yes_',
            'externalMail'        => $this->raw('extMailServer') === '_yes_',
            'webFolderProtection' => $this->raw('webFolderProtection') === '_yes_',
            'backup'              => $this->backupTargets()
        );
    }

    /**
     * @return string[]
     */
    private function backupTargets(): array
    {
        $raw = $this->raw('backup');

        if ($raw === '') {
            return array();
        }

        $targets = array();

        foreach (explode('|', $raw) as $target) {
            if (isset(self::BACKUP_TARGETS[$target])) {
                $targets[] = self::BACKUP_TARGETS[$target];
            }
        }

        return $targets;
    }

    private static function mibToBytes(int $mib): ?int
    {
        return $mib === 0 ? null : $mib * 1048576;
    }
}
