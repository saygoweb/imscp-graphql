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
use Throwable;

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
 * createDefaultMailAccounts(), updateResellerCounters(), domainExists(),
 * deleteCustomer() and deleteReseller() - the panel's own rules, which are
 * exactly what the integration suite is for.
 */
final class RecordingCore implements Core
{
    /**
     * Every call this double saw, in order, as array($method, ...$arguments).
     *
     * The per-effect properties below predate it and stay: they are what the
     * phase-3 tests assert against. This one is the uniform record, so that a
     * test can ask "was it called, with what, and in which order" of any method
     * without a property being added for each.
     *
     * @var array<int, array<int, mixed>>
     */
    public $calls = array();

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

    /**
     * A test that wants sendAccountCreatedEmail() to fail, the way the
     * panel's own get_welcome_email()/send_mail() do - by throwing rather
     * than returning false - sets this instead of catching anything here.
     *
     * @var Throwable|null
     */
    public $sendAccountCreatedEmailThrows;

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

    /**
     * Every call to $method, with its arguments and without its name.
     *
     * @return array<int, array<int, mixed>>
     */
    public function callsNamed(string $method): array
    {
        $matches = array();

        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                $matches[] = array_slice($call, 1);
            }
        }

        return $matches;
    }

    /**
     * The parameters the first dispatch of $event carried, or an empty array
     * when it was never dispatched - which every assertion on a named key then
     * fails on, saying so.
     *
     * @return array<string, mixed>
     */
    public function eventNamed(string $event): array
    {
        foreach ($this->events as $one) {
            if ($one[0] === $event) {
                return $one[1];
            }
        }

        return array();
    }

    /**
     * Delegated, not recorded beyond the username: this is the panel's own
     * credential check and the integration suite is exactly what it is for.
     * The password is not recorded - a Secret reaches the hasher and nothing
     * else (spec section 12), and a double that kept one would make that
     * property untestable from here.
     */
    public function authenticate(string $username, string $password): ?array
    {
        $this->record('authenticate', $username);

        return $this->inner->authenticate($username, $password);
    }

    public function dispatch(string $event, array $params): void
    {
        $this->record('dispatch', $event, $params);
        $this->events[] = array($event, $params);
    }

    public function sendRequest(): void
    {
        $this->record('sendRequest');
        $this->requests++;
    }

    public function writeLog(string $message, int $level): void
    {
        $this->record('writeLog', $message, $level);
        $this->logs[] = array($message, $level);
    }

    public function config(string $key, $default = null)
    {
        $this->record('config', $key, $default);

        return array_key_exists($key, $this->config)
            ? $this->config[$key]
            : $this->inner->config($key, $default);
    }

    public function toAscii(string $name): string
    {
        $this->record('toAscii', $name);

        return $this->inner->toAscii($name);
    }

    public function toUnicode(string $name): string
    {
        $this->record('toUnicode', $name);

        return $this->inner->toUnicode($name);
    }

    public function domainNameError(string $name): ?string
    {
        $this->record('domainNameError', $name);

        return $this->inner->domainNameError($name);
    }

    public function isValidEmail(string $address): bool
    {
        $this->record('isValidEmail', $address);

        return $this->inner->isValidEmail($address);
    }

    public function isValidEmailLocalPart(string $localPart): bool
    {
        $this->record('isValidEmailLocalPart', $localPart);

        return $this->inner->isValidEmailLocalPart($localPart);
    }

    /**
     * The password is not recorded: a Secret reaches the hasher and nothing
     * else (spec section 12), and a double that kept one would make that
     * property untestable from here.
     */
    public function isAcceptablePassword(string $password): bool
    {
        $this->record('isAcceptablePassword');

        return $this->inner->isAcceptablePassword($password);
    }

    public function isValidUsername(string $username): bool
    {
        $this->record('isValidUsername', $username);

        return $this->inner->isValidUsername($username);
    }

    public function isValidSqlHost(string $asciiHost): bool
    {
        $this->record('isValidSqlHost', $asciiHost);

        return $this->inner->isValidSqlHost($asciiHost);
    }

    public function normalisePath(string $path): string
    {
        $this->record('normalisePath', $path);

        return $this->inner->normalisePath($path);
    }

    /** @see isAcceptablePassword() for why the argument is not recorded. */
    public function hashPassword(string $password): string
    {
        $this->record('hashPassword');

        return $this->inner->hashPassword($password);
    }

    /** @see isAcceptablePassword() for why the argument is not recorded. */
    public function hashAccountPassword(string $password): string
    {
        $this->record('hashAccountPassword');

        return $this->inner->hashAccountPassword($password);
    }

    public function domainExists(string $name, int $resellerId): bool
    {
        $this->record('domainExists', $name, $resellerId);

        return $this->inner->domainExists($name, $resellerId);
    }

    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void {
        $this->record('createDefaultMailAccounts', $mainDomainId, $customerEmail, $asciiHostName, $forwardMailType, $subId);
        $this->inner->createDefaultMailAccounts($mainDomainId, $customerEmail, $asciiHostName, $forwardMailType, $subId);
    }

    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void {
        $this->record('savePhpIni', $resellerId, $customerAdminId, $mainDomainId, $vhostId, $vhostType);
        $this->inner->savePhpIni($resellerId, $customerAdminId, $mainDomainId, $vhostId, $vhostType);
    }

    public function savePhpIniForNewDomain(int $resellerId, int $customerAdminId, int $domainId, array $values): void
    {
        $this->record('savePhpIniForNewDomain', $resellerId, $customerAdminId, $domainId, $values);
        $this->inner->savePhpIniForNewDomain($resellerId, $customerAdminId, $domainId, $values);
    }

    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string
    {
        $this->record('normaliseForwardUrl', $url, $selfAsciiName, $proxy);

        return $this->inner->normaliseForwardUrl($url, $selfAsciiName, $proxy);
    }

    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
    {
        $this->record('sendAliasOrderEmail', $customerAdminId, $aliasName);
        $this->aliasOrders[] = array($customerAdminId, $aliasName);
    }

    public function monthBounds(): array
    {
        $this->record('monthBounds');

        return $this->inner->monthBounds();
    }

    public function syncMailboxQuota(int $domainId, int $bytes): void
    {
        $this->record('syncMailboxQuota', $domainId, $bytes);
        $this->inner->syncMailboxQuota($domainId, $bytes);
    }

    public function updatePhpIniForDomain(int $customerAdminId, int $domainId, array $values): void
    {
        $this->record('updatePhpIniForDomain', $customerAdminId, $domainId, $values);
        $this->inner->updatePhpIniForDomain($customerAdminId, $domainId, $values);
    }

    /**
     * Recorded, never performed: a test must not send mail, and the cleartext
     * password the panel's welcome message carries by design must not outlive
     * the call (spec section 12), so it is not recorded either.
     */
    public function sendAccountCreatedEmail(
        int $createdBy, string $username, string $password, string $email,
        string $firstName, string $lastName, string $role
    ): bool {
        $this->record('sendAccountCreatedEmail', $createdBy, $username, $email, $firstName, $lastName, $role);

        if ($this->sendAccountCreatedEmailThrows !== null) {
            throw $this->sendAccountCreatedEmailThrows;
        }

        return true;
    }

    public function updateResellerCounters(int $resellerId): void
    {
        $this->record('updateResellerCounters', $resellerId);
        $this->inner->updateResellerCounters($resellerId);
    }

    public function pruneAutoreplyLog(): void
    {
        $this->record('pruneAutoreplyLog');
        $this->prunes++;
    }

    public function deleteCustomer(int $customerAdminId): bool
    {
        $this->record('deleteCustomer', $customerAdminId);

        return $this->inner->deleteCustomer($customerAdminId);
    }

    public function deleteReseller(int $resellerAdminId): bool
    {
        $this->record('deleteReseller', $resellerAdminId);

        return $this->inner->deleteReseller($resellerAdminId);
    }

    /**
     * @param mixed ...$arguments
     */
    private function record(string $method, ...$arguments): void
    {
        $this->calls[] = array_merge(array($method), $arguments);
    }
}
