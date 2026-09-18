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

use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\Allowances;
use iMSCP\Plugin\SGW_GraphQL\Support\LimitRules;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;
use Throwable;

/**
 * The customer lifecycle, as a reseller sees it.
 *
 * The panel spreads creation across three pages with the half-built customer
 * in $_SESSION (M1); spec section 7.11 takes it in one call, applying the same
 * validation each step applied, in the same order (decision D23).
 */
final class CustomerService
{
    /**
     * What the panel's own welcome template calls the account it describes.
     * The page passes tr('Customer'); the API has no request locale to
     * translate into, so the untranslated word is what the placeholder gets.
     */
    const ROLE_LABEL = 'Customer';

    /** Allowance name => the `domain` column holding the customer's own limit. */
    const LIMIT_COLUMNS = array(
        'subdomains'    => 'domain_subd_limit',
        'domainAliases' => 'domain_alias_limit',
        'mailAccounts'  => 'domain_mailacc_limit',
        'ftpUsers'      => 'domain_ftpacc_limit',
        'sqlDatabases'  => 'domain_sqld_limit',
        'sqlUsers'      => 'domain_sqlu_limit'
    );

    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3. A reseller creates under itself; an administrator must say
        // for whom (decision D30).
        Guard::requireScope($caller, Scope::CUSTOMERS_WRITE);
        $reseller = $kit->accounts()->reseller($this->resellerFor($caller, $input));

        // 4. There is no "customers" feature flag; the reseller's own domain
        // allowance is the nearest thing, and it is a quota, not a feature.

        // 6. The panel's order: the name, then the allowances, then the IP,
        // then the contact details.
        //
        // CORE-DEBT(C3): user_add1.php:55-58 strips every leading "www." -
        // "www is considered as an alias of the domain" - before encode_idna()
        // and before the existence check. VhostRules::stripWww() already does
        // this for the alias path; it strips a leading "www." only, in a
        // loop, so "www.www.example.com" reduces all the way. The page's own
        // loop instead strips while "www." appears anywhere in the string,
        // which for a name with "www." off the front - "foo.www.example.com" -
        // strips leading characters that are not "www." at all. That shape
        // is unreachable through a normal domain name and is not matched here.
        $name = VhostRules::stripWww(mb_strtolower(trim((string)($input['domainName'] ?? ''))));
        $ascii = $core->toAscii($name);
        $reason = $ascii === '' ? 'Invalid domain name.' : $core->domainNameError($ascii);

        if ($reason !== null) {
            throw Guard::badInput('input.domainName', $reason);
        }

        if ($core->domainExists($ascii, $reseller->getAdminId())) {
            throw Guard::conflict(sprintf('The domain %s already exists.', $name));
        }

        $username = trim((string)($input['username'] ?? ''), ' ');

        if ($username === '' || !$core->isValidUsername($username)) {
            throw Guard::badInput('input.username', 'Invalid username.');
        }

        if ($this->usernameTaken($username)) {
            throw Guard::conflict(sprintf('The username %s is already taken.', $username));
        }

        $password = (string)($input['password'] ?? '');

        if ($password === '' || !$core->isAcceptablePassword($password)) {
            throw Guard::badInput('input.password', "The password does not meet the panel's password policy.");
        }

        $allowances = $this->allowancesFor($reseller, $input);
        $ipId = $this->ipFor($reseller, $input);
        $contact = $this->contactFor($input, $core);
        $expiresAt = $this->expiryFor($input);

        // 7. The create path's own rule (Support\LimitRules::createReason()):
        // the customer count, all six services and traffic/disk, in
        // reseller_limits_check()'s order - not the edit page's
        // isValidServiceLimit(), which D21 wrongly reused for both paths.
        $wanted = array(
            'subdomains'    => $allowances->limit('subdomains'),
            'domainAliases' => $allowances->limit('domainAliases'),
            'mailAccounts'  => $allowances->limit('mailAccounts'),
            'ftpUsers'      => $allowances->limit('ftpUsers'),
            'sqlDatabases'  => $allowances->limit('sqlDatabases'),
            'sqlUsers'      => $allowances->limit('sqlUsers'),
            'traffic'       => $allowances->storage('traffic'),
            'disk'          => $allowances->storage('disk')
        );

        // A10: $resellerMax notes which allowance it was last asked about.
        // createReason() calls it exactly once per allowance it evaluates, in
        // order, and returns the instant one refuses - so when it does, the
        // last allowance $resellerMax saw is the one responsible, and its
        // numbers are ready for the exception.
        $triggered = null;
        $resellerMax = function (string $allowance) use ($reseller, &$triggered): int {
            $triggered = $allowance;

            return $reseller->maxOf($allowance);
        };

        $reason = LimitRules::createReason($wanted, $resellerMax, array($reseller, 'usedOf'));

        if ($reason !== null) {
            throw Guard::limitExceeded($reason, array(
                'quota' => $triggered,
                'limit' => $reseller->maxOf($triggered),
                'used'  => $reseller->usedOf($triggered)
            ));
        }

        $columns = $allowances->domainColumns();
        $forward = VhostRules::noForwarding();
        $eventParams = array(
            'createdBy'     => $reseller->getAdminId(),
            'customerEmail' => $contact['email'],
            'domainName'    => $ascii,
            'mountPoint'    => '/',
            'documentRoot'  => '/htdocs',
            'forwardUrl'    => $forward['url'],
            'forwardType'   => $forward['type'],
            'forwardHost'   => $forward['host'],
            'wildcardAlias' => 'no'
        );
        $panel = $kit->panelConfig(array(
            'CREATE_DEFAULT_EMAIL_ADDRESSES', 'USER_INITIAL_LANG', 'USER_INITIAL_THEME'
        ));

        // 8. CORE-DEBT(C3): transcribed from gui/public/reseller/user_add3.php:150-290.
        // CORE-DEBT(C11): C11 item 12 - the welcome message is sent after the
        //   commit, not inside the transaction as the page sends it.
        $written = $kit->writer()->run(function () use (
            $kit, $core, $reseller, $username, $password, $ascii, $contact, $expiresAt,
            $columns, $ipId, $allowances, $eventParams, $panel, $forward
        ) {
            $db = $kit->db();

            $db->execute(
                "
                    INSERT INTO admin (
                        admin_name, admin_pass, admin_type, domain_created, created_by, fname, lname, firm,
                        zip, city, state, country, email, phone, fax, street1, street2, gender, admin_status
                    ) VALUES (?, ?, 'user', unix_timestamp(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'toadd')
                ",
                array(
                    $username, $core->hashAccountPassword($password), $reseller->getAdminId(),
                    $contact['firstName'], $contact['lastName'], $contact['company'], $contact['postcode'],
                    $contact['city'], $contact['state'], $contact['country'], $contact['email'],
                    $contact['phone'], $contact['fax'], $contact['street1'], $contact['street2'], $contact['gender']
                )
            );

            $adminId = $db->lastInsertId();
            $core->dispatch(Events::onBeforeAddDomain, array('customerId' => $adminId) + $eventParams);

            $db->execute(
                '
                    INSERT INTO domain (
                        domain_name, domain_admin_id, domain_created, domain_expires, domain_status,
                        domain_ip_id, domain_disk_usage, url_forward, type_forward, host_forward, wildcard_alias,
                        domain_mailacc_limit, domain_ftpacc_limit, domain_traffic_limit, domain_sqld_limit,
                        domain_sqlu_limit, domain_alias_limit, domain_subd_limit, domain_disk_limit,
                        domain_php, domain_cgi, allowbackup, domain_dns, phpini_perm_system,
                        phpini_perm_allow_url_fopen, phpini_perm_display_errors, phpini_perm_disable_functions,
                        phpini_perm_mail_function, domain_external_mail, web_folder_protection, mail_quota
                    ) VALUES (
                        ?, ?, unix_timestamp(), ?, ?, ?, 0, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ',
                array(
                    $ascii, $adminId, $expiresAt, 'toadd', $ipId,
                    $forward['url'], $forward['type'], $forward['host'], 'no',
                    $columns['domain_mailacc_limit'], $columns['domain_ftpacc_limit'],
                    $columns['domain_traffic_limit'], $columns['domain_sqld_limit'],
                    $columns['domain_sqlu_limit'], $columns['domain_alias_limit'],
                    $columns['domain_subd_limit'], $columns['domain_disk_limit'],
                    $columns['domain_php'], $columns['domain_cgi'], $columns['allowbackup'],
                    $columns['domain_dns'], $columns['phpini_perm_system'],
                    $columns['phpini_perm_allow_url_fopen'], $columns['phpini_perm_display_errors'],
                    $columns['phpini_perm_disable_functions'], $columns['phpini_perm_mail_function'],
                    $columns['domain_external_mail'], $columns['web_folder_protection'], $columns['mail_quota']
                )
            );

            $domainId = $db->lastInsertId();

            $core->savePhpIniForNewDomain($reseller->getAdminId(), $adminId, $domainId, array(
                'phpiniMemoryLimit'       => $allowances->phpIni('phpiniMemoryLimit'),
                'phpiniPostMaxSize'       => $allowances->phpIni('phpiniPostMaxSize'),
                'phpiniUploadMaxFileSize' => $allowances->phpIni('phpiniUploadMaxFileSize'),
                'phpiniMaxExecutionTime'  => $allowances->phpIni('phpiniMaxExecutionTime'),
                'phpiniMaxInputTime'      => $allowances->phpIni('phpiniMaxInputTime')
            ));

            if ($panel['CREATE_DEFAULT_EMAIL_ADDRESSES']) {
                // 'normal_forward', not '': Shared.php:70-74 throws for a
                // sub-id of 0 with any other forward type, and 0 is what a
                // customer's own domain gets.
                $core->createDefaultMailAccounts($domainId, $contact['email'], $ascii, 'normal_forward', 0);
            }

            $db->execute(
                'INSERT INTO user_gui_props (user_id, lang, layout) VALUES (?, ?, ?)',
                array($adminId, $panel['USER_INITIAL_LANG'], $panel['USER_INITIAL_THEME'])
            );

            $core->updateResellerCounters($reseller->getAdminId());
            $core->dispatch(
                Events::onAfterAddDomain,
                array('customerId' => $adminId, 'domainId' => $domainId) + $eventParams
            );

            return array('adminId' => $adminId, 'domainId' => $domainId);
        });

        // 9, 10. C11 item 12: after the commit, so a rollback cannot leave a
        // customer holding credentials for an account that does not exist.
        $core->sendRequest();
        $core->writeLog(
            sprintf('A new customer (%s) has been created by: %s', $username, $caller->getUsername()),
            E_USER_NOTICE
        );

        // The rows are committed and the daemon is on its way, so a mail that
        // fails is a courtesy that failed, not a create that failed. C11 item
        // 12 is why it is here at all rather than inside the transaction.
        if (!empty($input['sendWelcomeEmail'])) {
            try {
                $core->sendAccountCreatedEmail(
                    $reseller->getAdminId(), $username, $password, $contact['email'],
                    $contact['firstName'], $contact['lastName'], self::ROLE_LABEL
                );
            } catch (Throwable $e) {
                $core->writeLog(sprintf(
                    "Couldn't send the welcome message to %s: %s", $username, $e->getMessage()
                ), E_USER_ERROR);
            }
        }

        return new ObjectRef(NodeType::CUSTOMER, $written['adminId']);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/user_edit.php:44-120
     *   (the account) and gui/public/reseller/domain_edit.php:607-1010 (the
     *   allowances). The page splits them because it has two forms; one
     *   customer is one object here, so one mutation changes either.
     *   Retire when CustomerService lands in core.
     */
    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3.
        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');
        $customer = $kit->accounts()->customer($target->getKey());
        $reseller = $kit->accounts()->reseller($customer->getResellerId());

        // 5. A customer in transit is not changed (spec section 8.3).
        Guard::requireState((string)$customer->domain('domain_status'), array(Provisioning::STATE_OK));

        // 6. An explicit null names nothing, as phase 3's checkpoint C settled.
        $given = array_filter($input, static function ($value) {
            return $value !== null;
        });
        $named = array_intersect(
            array_keys($given), array('password', 'contact', 'allowances', 'hostingPlanId', 'expiresAt', 'ipAddressId')
        );

        if ($named === array()) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        if (isset($given['allowances']) && isset($given['hostingPlanId'])) {
            throw Guard::badInput('input', 'Give at most one of hostingPlanId and allowances.');
        }

        $password = null;

        if (isset($given['password'])) {
            $password = (string)$given['password'];

            if ($password === '' || !$core->isAcceptablePassword($password)) {
                throw Guard::badInput('input.password', "The password does not meet the panel's password policy.");
            }
        }

        $contact = isset($given['contact']) ? $this->contactFor($given, $core) : null;
        $allowances = isset($given['allowances']) || isset($given['hostingPlanId'])
            ? $this->allowancesForUpdate($reseller, $customer, $given)
            : null;
        $ipId = isset($given['ipAddressId']) ? $this->ipFor($reseller, $given) : null;
        $expiresAt = array_key_exists('expiresAt', $input) ? $this->expiryOrNever($input) : null;

        // 7. Both ledgers again, now with the customer's own consumption.
        if ($allowances !== null) {
            $used = $kit->counts()->forCustomer($customer->getDomainId());

            foreach (array_keys(LimitRules::SERVICES) as $service) {
                $customerLimit = (int)$customer->domain(self::LIMIT_COLUMNS[$service]);

                // domain_edit.php:636-692: each of the six is only checked at
                // all "if ($data['fallback_domain_X_limit'] != -1)" - a
                // service already withheld for this customer is not
                // re-validated when the form resubmits it unchanged.
                if ($customerLimit === LimitRules::WITHHELD) {
                    continue;
                }

                $reason = LimitRules::reason(
                    $allowances->limit($service), (int)($used[$service] ?? 0), $customerLimit,
                    $reseller->usedOf($service), $reseller->maxOf($service), $service
                );

                if ($reason !== null) {
                    throw Guard::limitExceeded($reason, array('quota' => $service));
                }
            }
        }

        $adminId = (int)$target->getKey();
        $domainId = $customer->getDomainId();
        $username = $customer->getUsername();
        $withdrawsDns = $allowances !== null
            && $allowances->feature('customDns') === '_no_'
            && (string)$customer->domain('domain_dns') === 'yes';
        // domain_edit.php:905-919: the daemon is needed when the IP, the mail
        // feature, PHP, CGI or the web folder protection changed.
        $needsDaemon = $allowances !== null || $ipId !== null;

        $kit->writer()->run(function () use (
            $kit, $core, $adminId, $domainId, $username, $password, $contact,
            $allowances, $ipId, $expiresAt, $withdrawsDns, $needsDaemon
        ) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeEditUser, array('userId' => $adminId));

            if ($password !== null || $contact !== null) {
                $db->execute(
                    "
                        UPDATE admin
                        SET admin_pass = IFNULL(?, admin_pass), fname = IFNULL(?, fname), lname = IFNULL(?, lname),
                            firm = IFNULL(?, firm), zip = IFNULL(?, zip), city = IFNULL(?, city),
                            state = IFNULL(?, state), country = IFNULL(?, country), email = IFNULL(?, email),
                            phone = IFNULL(?, phone), fax = IFNULL(?, fax), street1 = IFNULL(?, street1),
                            street2 = IFNULL(?, street2), gender = IFNULL(?, gender),
                            admin_status = IF(?, 'tochangepwd', admin_status)
                        WHERE admin_id = ?
                    ",
                    array(
                        $password === null ? null : $core->hashAccountPassword($password),
                        $contact === null ? null : $contact['firstName'],
                        $contact === null ? null : $contact['lastName'],
                        $contact === null ? null : $contact['company'],
                        $contact === null ? null : $contact['postcode'],
                        $contact === null ? null : $contact['city'],
                        $contact === null ? null : $contact['state'],
                        $contact === null ? null : $contact['country'],
                        $contact === null ? null : $contact['email'],
                        $contact === null ? null : $contact['phone'],
                        $contact === null ? null : $contact['fax'],
                        $contact === null ? null : $contact['street1'],
                        $contact === null ? null : $contact['street2'],
                        $contact === null ? null : $contact['gender'],
                        $password === null ? 0 : 1, $adminId
                    )
                );

                // user_edit.php:92 - a password or an email change ends the
                // customer's sessions.
                $db->execute('DELETE FROM login WHERE user_name = ?', array($username));
            }

            if ($withdrawsDns) {
                $db->execute(
                    "DELETE FROM domain_dns WHERE domain_id = ? AND owned_by = 'custom_dns_feature'",
                    array($domainId)
                );
            }

            if ($allowances !== null || $ipId !== null || $expiresAt !== null) {
                $columns = $allowances === null ? array() : $allowances->domainColumns();
                $sets = array('domain_last_modified = ?');
                $bind = array(time());

                foreach ($columns as $column => $value) {
                    $sets[] = $column . ' = ?';
                    $bind[] = $value;
                }

                if ($ipId !== null) {
                    $sets[] = 'domain_ip_id = ?';
                    $bind[] = $ipId;
                }

                if ($expiresAt !== null) {
                    $sets[] = 'domain_expires = ?';
                    $bind[] = $expiresAt;
                }

                $sets[] = 'domain_status = ?';
                $bind[] = $needsDaemon ? 'tochange' : 'ok';
                $bind[] = $domainId;

                $db->execute('UPDATE domain SET ' . implode(', ', $sets) . ' WHERE domain_id = ?', $bind);
            }

            $core->updateResellerCounters($kit->accounts()->customer($adminId)->getResellerId());
            $core->dispatch(Events::onAfterEditUser, array('userId' => $adminId));
        });

        if ($needsDaemon) {
            $core->sendRequest();
        }

        $core->writeLog(
            sprintf('The %s user has been updated by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }

    /**
     * D30: a reseller creates under itself and may not name another; an
     * administrator has no customers of its own, so it must name one.
     */
    private function resellerFor(Identity $caller, array $input): int
    {
        $named = isset($input['resellerId'])
            ? Guard::parse((string)$input['resellerId'], array(NodeType::RESELLER))->getId()
            : null;

        if ($caller->getRole() === Identity::ROLE_ADMIN) {
            if ($named === null) {
                throw Guard::badInput('input.resellerId', 'An administrator must say which reseller the customer belongs to.');
            }

            return $named;
        }

        if ($caller->getRole() !== Identity::ROLE_RESELLER) {
            throw Guard::forbidden('Only a reseller or an administrator may create a customer.');
        }

        if ($named !== null && $named !== $caller->getAdminId()) {
            // Not NOT_FOUND: the caller named an account it can see - its own
            // role - and asked for something its role does not permit.
            throw Guard::forbidden('A reseller may only create customers of its own.');
        }

        return $caller->getAdminId();
    }

    /** D24: exactly one of hostingPlanId and allowances. */
    private function allowancesFor(ResellerAccount $reseller, array $input): Allowances
    {
        $planId = isset($input['hostingPlanId']) ? (string)$input['hostingPlanId'] : null;
        $explicit = isset($input['allowances']) ? (array)$input['allowances'] : null;

        if (($planId === null) === ($explicit === null)) {
            throw Guard::badInput('input', 'Give exactly one of hostingPlanId and allowances.');
        }

        if ($explicit !== null) {
            return Allowances::fromInput($explicit);
        }

        $key = Guard::parse($planId, array(NodeType::HOSTING_PLAN))->getId();
        $props = $this->kit->db()->value(
            'SELECT props FROM hosting_plans WHERE id = ? AND reseller_id = ?',
            array($key, $reseller->getAdminId())
        );

        if ($props === null) {
            throw Guard::notFound();
        }

        return Allowances::fromPlanProps(PlanProps::parse((string)$props));
    }

    /**
     * An update's allowances are partial. A hosting plan replaces them whole;
     * an explicit `allowances` is merged over the customer's current values,
     * because an input that names only `mailAccounts` must not reset the
     * customer's PHP permissions to an input default.
     */
    private function allowancesForUpdate(ResellerAccount $reseller, CustomerAccount $customer, array $given): Allowances
    {
        if (isset($given['hostingPlanId'])) {
            return $this->allowancesFor($reseller, array('hostingPlanId' => $given['hostingPlanId']));
        }

        return Allowances::fromInput(((array)$given['allowances']) + $this->currentAllowances($customer));
    }

    /**
     * The customer's `domain` row, read back in CustomerAllowancesInput's
     * terms. `domain_disk_limit` and `domain_traffic_limit` are stored in
     * MiB (M7); `Allowances::fromInput()` takes every BigInt as bytes (D3,
     * checkpoint A finding A4), so both are scaled up here on the way out -
     * the same 1048576 `PlanProps::mibToBytes()` and `Allowances::bytesToMib()`
     * use on their own round trips. `mail_quota` is already bytes.
     */
    private function currentAllowances(CustomerAccount $customer): array
    {
        $backup = (string)$customer->domain('allowbackup');
        $targets = array();

        foreach (explode('|', $backup) as $one) {
            $key = '_' . $one . '_';

            if (isset(PlanProps::BACKUP_TARGETS[$key])) {
                $targets[] = PlanProps::BACKUP_TARGETS[$key];
            }
        }

        return array(
            'subdomains'    => (int)$customer->domain('domain_subd_limit'),
            'domainAliases' => (int)$customer->domain('domain_alias_limit'),
            'mailAccounts'  => (int)$customer->domain('domain_mailacc_limit'),
            'ftpUsers'      => (int)$customer->domain('domain_ftpacc_limit'),
            'sqlDatabases'  => (int)$customer->domain('domain_sqld_limit'),
            'sqlUsers'      => (int)$customer->domain('domain_sqlu_limit'),
            'traffic'       => self::mibLimitToBytes((int)$customer->domain('domain_traffic_limit')),
            'disk'          => self::mibLimitToBytes((int)$customer->domain('domain_disk_limit')),
            'mailQuota'     => (int)$customer->domain('mail_quota'),
            'php'           => (string)$customer->domain('domain_php') === 'yes',
            'cgi'           => (string)$customer->domain('domain_cgi') === 'yes',
            'customDns'     => (string)$customer->domain('domain_dns') === 'yes',
            'externalMail'  => (string)$customer->domain('domain_external_mail') === 'yes',
            'webFolderProtection' => (string)$customer->domain('web_folder_protection') === 'yes',
            'backup'        => $targets,
            'phpEditor'     => (string)$customer->domain('phpini_perm_system') === 'yes',
            'phpiniAllowUrlFopen'    => (string)$customer->domain('phpini_perm_allow_url_fopen') === 'yes',
            'phpiniDisplayErrors'    => (string)$customer->domain('phpini_perm_display_errors') === 'yes',
            'phpiniDisableFunctions' => (string)$customer->domain('phpini_perm_disable_functions') === 'yes',
            'phpMailFunction'        => (string)$customer->domain('phpini_perm_mail_function') === 'yes'
        );
    }

    /**
     * -1 (withheld) and 0 (unlimited) pass through; a positive MiB limit
     * becomes the bytes `Allowances::fromInput()` expects.
     */
    private static function mibLimitToBytes(int $mib): int
    {
        return $mib <= 0 ? $mib : $mib * 1048576;
    }

    /** expiresAt: a date, or an explicit null meaning "never expires". */
    private function expiryOrNever(array $input): int
    {
        return $input['expiresAt'] === null ? 0 : $this->expiryFor($input);
    }

    /** M17, and C11 item 13: identity, not a loose comparison. */
    private function ipFor(ResellerAccount $reseller, array $input): int
    {
        $ipId = Guard::parse((string)($input['ipAddressId'] ?? ''), array(NodeType::IP_ADDRESS))->getId();

        if (!$reseller->hasIp($ipId)) {
            throw Guard::badInput('input.ipAddressId', 'That IP address is not one of this reseller\'s.');
        }

        return $ipId;
    }

    /**
     * ContactDetailsInput. Only the email is required; the panel's form agrees.
     *
     * @return array<string, string>
     */
    private function contactFor(array $input, Core $core): array
    {
        $contact = (array)($input['contact'] ?? array());
        $email = trim((string)($contact['email'] ?? ''));

        if ($email === '' || !$core->isValidEmail($core->toAscii($email))) {
            throw Guard::badInput('input.contact.email', 'Not an email address.');
        }

        $optional = array(
            'firstName' => 'firstName', 'lastName' => 'lastName', 'company' => 'company',
            'postcode' => 'postcode', 'city' => 'city', 'state' => 'state', 'country' => 'country',
            'phone' => 'phone', 'fax' => 'fax', 'street1' => 'street1', 'street2' => 'street2'
        );
        $values = array('email' => $core->toAscii($email), 'gender' => 'U');

        foreach ($optional as $name => $key) {
            $values[$name] = trim((string)($contact[$key] ?? ''));
        }

        if (isset($contact['gender']) && in_array((string)$contact['gender'], array('M', 'F', 'U'), true)) {
            $values['gender'] = (string)$contact['gender'];
        }

        return $values;
    }

    /** A DateTime, stored as the panel stores it: a unix timestamp, 0 for never. */
    private function expiryFor(array $input): int
    {
        if (!isset($input['expiresAt']) || $input['expiresAt'] === null) {
            return 0;
        }

        $at = strtotime((string)$input['expiresAt']);

        if ($at === false || $at <= time()) {
            throw Guard::badInput('input.expiresAt', 'An expiry date is in the future.');
        }

        return $at;
    }

    private function usernameTaken(string $username): bool
    {
        return (int)$this->kit->db()->value(
            'SELECT COUNT(*) FROM admin WHERE admin_name = ?', array($username)
        ) > 0;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/domain_status_change.php:37-60.
     *   The page's two legal transitions are the whole rule (M12); it answers
     *   anything else with showBadRequestErrorPage(), which this reports as
     *   the CONFLICT it is.
     *
     * CORE-DEBT(C3): also transcribed from gui/include/Shared.php:445
     *   change_domain_status(), unlike deleteCustomer() (decision D22) -
     *   it manages its own transaction too, but it unconditionally calls
     *   set_page_message(), which throws outside a running web session
     *   (measured: reliably, not only under this suite). Its own writeLog()
     *   and sendRequest() calls are dropped for the same reason this
     *   method's own steps 9 and 10 exist: the caller, not the transcribed
     *   body, owns them.
     */
    public function setState(Identity $caller, string $id, string $state): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');
        $customer = $kit->accounts()->customer($target->getKey());
        $status = (string)$customer->domain('domain_status');

        if (!in_array($state, array('ENABLED', 'DISABLED'), true)) {
            throw Guard::badInput('state', 'A customer is ENABLED or DISABLED.');
        }

        $wanted = $state === 'DISABLED' ? 'deactivate' : 'activate';
        // The raw `domain` status, not Provisioning::fromStatus(): the page's
        // rule is only ever "ok" or "disabled", never a PENDING or ERROR verb.
        $from = $state === 'DISABLED' ? 'ok' : 'disabled';

        if ($status !== $from) {
            throw Guard::conflict($status === ($state === 'DISABLED' ? 'disabled' : 'ok')
                ? sprintf('This customer is already %s.', strtolower($state))
                : 'This customer is not settled, so its state cannot be changed.');
        }

        $adminId = (int)$target->getKey();
        $domainId = $customer->getDomainId();
        $newStatus = $state === 'DISABLED' ? 'todisable' : 'toenable';
        $panel = $kit->panelConfig(array('HARD_MAIL_SUSPENSION'));

        $kit->writer()->run(function () use ($kit, $core, $adminId, $domainId, $wanted, $newStatus, $panel) {
            $db = $kit->db();
            $core->dispatch(Events::onBeforeChangeDomainStatus, array('customerId' => $adminId, 'action' => $wanted));

            if ($wanted === 'deactivate') {
                if ($panel['HARD_MAIL_SUSPENSION']) {
                    $db->execute(
                        "UPDATE mail_users SET status = 'todisable', po_active = 'no' WHERE domain_id = ?",
                        array($domainId)
                    );
                } else {
                    $db->execute("UPDATE mail_users SET po_active = 'no' WHERE domain_id = ?", array($domainId));
                }
            } else {
                $db->execute(
                    "
                        UPDATE mail_users SET status = 'toenable',
                            po_active = IF(mail_type LIKE '%_mail%', 'yes', po_active)
                        WHERE domain_id = ? AND status = 'disabled'
                    ",
                    array($domainId)
                );
                $db->execute(
                    "
                        UPDATE mail_users SET po_active = IF(mail_type LIKE '%_mail%', 'yes', po_active)
                        WHERE domain_id = ? AND status <> 'disabled'
                    ",
                    array($domainId)
                );
            }

            $db->execute('UPDATE ftp_users SET status = ? WHERE admin_id = ?', array($newStatus, $adminId));
            $db->execute('UPDATE htaccess SET status = ? WHERE dmn_id = ?', array($newStatus, $domainId));
            $db->execute('UPDATE htaccess_groups SET status = ? WHERE dmn_id = ?', array($newStatus, $domainId));
            $db->execute('UPDATE htaccess_users SET status = ? WHERE dmn_id = ?', array($newStatus, $domainId));
            $db->execute('UPDATE domain SET domain_status = ? WHERE domain_id = ?', array($newStatus, $domainId));
            $db->execute('UPDATE subdomain SET subdomain_status = ? WHERE domain_id = ?', array($newStatus, $domainId));
            $db->execute(
                'UPDATE domain_aliasses SET alias_status = ? WHERE domain_id = ?', array($newStatus, $domainId)
            );
            $db->execute(
                '
                    UPDATE subdomain_alias
                    JOIN domain_aliasses USING(alias_id)
                    SET subdomain_alias_status = ?
                    WHERE domain_id = ?
                ',
                array($newStatus, $domainId)
            );
            $db->execute(
                'UPDATE domain_dns SET domain_dns_status = ? WHERE domain_id = ?', array($newStatus, $domainId)
            );

            $core->dispatch(Events::onAfterChangeDomainStatus, array('customerId' => $adminId, 'action' => $wanted));
        });

        $core->sendRequest();
        $core->writeLog(
            sprintf(
                'The %s customer has been %s by %s',
                $customer->getUsername(), $state === 'DISABLED' ? 'disabled' : 'enabled', $caller->getUsername()
            ),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }

    /**
     * Decision D22: the panel's own helper does the work, and this opens no
     * transaction around it. Every refusal happens first, so a caller who may
     * not delete never reaches it.
     */
    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::CUSTOMER), Scope::CUSTOMERS_WRITE, 'id');
        $customer = $kit->accounts()->customer($target->getKey());

        Guard::requireState(
            (string)$customer->adminStatus(),
            array(Provisioning::STATE_OK, Provisioning::STATE_ERROR, Provisioning::STATE_DISABLED)
        );

        $adminId = (int)$target->getKey();
        $username = $customer->getUsername();

        if (!$core->deleteCustomer($adminId)) {
            // It returns false only when the row it was given is not there,
            // which after Guard::target() means it went between the two.
            throw Guard::conflict('That customer is no longer there.');
        }

        // deleteCustomer() pokes the daemon itself (M10); a second request
        // would be harmless but dishonest about who did what.
        $core->writeLog(
            sprintf('The %s customer has been deleted by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $adminId);
    }
}
