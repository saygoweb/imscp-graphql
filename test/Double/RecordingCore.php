<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Double;

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

use iMSCP\Plugin\SGW_GraphQL\Service\Core;

/**
 * A Core that records its side effects instead of performing them, and asks a
 * real Core for everything else.
 *
 * Recorded, not performed: dispatch() (other plugins' listeners are not this
 * suite's subject, and the transcription is asserted by name and parameters
 * instead), sendRequest() (the daemon would act on rows the test rolls back),
 * writeLog() and sendAliasOrderEmail() (a test must not send mail).
 *
 * Delegated: the validators, the IDN codec, the password hash, PhpEditor,
 * createDefaultMailAccounts() and domainExists() - the panel's own rules,
 * which are exactly what the integration suite is for.
 */
final class RecordingCore implements Core
{
    /** @var array<int, array{0: string, 1: array}> */
    public $events = array();

    /** @var int */
    public $requests = 0;

    /** @var array<int, array{0: string, 1: int}> */
    public $logs = array();

    /** @var array<int, array{0: int, 1: string}> */
    public $aliasOrders = array();

    /** @var int */
    public $prunes = 0;

    /** @var Core */
    private $inner;

    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed> $config Overrides for config(), so a test can
     *                                     state the setting it depends on
     *                                     rather than inherit the box's.
     */
    public function __construct(Core $inner, array $config = array())
    {
        $this->inner = $inner;
        $this->config = $config;
    }

    /** @return string[] */
    public function eventNames(): array
    {
        $names = array();

        foreach ($this->events as $event) {
            $names[] = $event[0];
        }

        return $names;
    }

    public function dispatch(string $event, array $params): void
    {
        $this->events[] = array($event, $params);
    }

    public function sendRequest(): void
    {
        $this->requests++;
    }

    public function writeLog(string $message, int $level): void
    {
        $this->logs[] = array($message, $level);
    }

    public function config(string $key, $default = null)
    {
        return array_key_exists($key, $this->config)
            ? $this->config[$key]
            : $this->inner->config($key, $default);
    }

    public function toAscii(string $name): string { return $this->inner->toAscii($name); }
    public function toUnicode(string $name): string { return $this->inner->toUnicode($name); }
    public function domainNameError(string $name): ?string { return $this->inner->domainNameError($name); }
    public function isValidEmail(string $address): bool { return $this->inner->isValidEmail($address); }
    public function isValidEmailLocalPart(string $localPart): bool { return $this->inner->isValidEmailLocalPart($localPart); }
    public function isAcceptablePassword(string $password): bool { return $this->inner->isAcceptablePassword($password); }
    public function isValidUsername(string $username): bool { return $this->inner->isValidUsername($username); }
    public function isValidSqlHost(string $asciiHost): bool { return $this->inner->isValidSqlHost($asciiHost); }
    public function normalisePath(string $path): string { return $this->inner->normalisePath($path); }
    public function hashPassword(string $password): string { return $this->inner->hashPassword($password); }
    public function domainExists(string $name, int $resellerId): bool { return $this->inner->domainExists($name, $resellerId); }

    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void {
        $this->inner->createDefaultMailAccounts($mainDomainId, $customerEmail, $asciiHostName, $forwardMailType, $subId);
    }

    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void {
        $this->inner->savePhpIni($resellerId, $customerAdminId, $mainDomainId, $vhostId, $vhostType);
    }

    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string
    {
        return $this->inner->normaliseForwardUrl($url, $selfAsciiName, $proxy);
    }

    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
    {
        $this->aliasOrders[] = array($customerAdminId, $aliasName);
    }

    public function pruneAutoreplyLog(): void
    {
        $this->prunes++;
    }
}
