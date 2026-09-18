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
use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;

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
        $name = mb_strtolower(trim((string)($input['domainName'] ?? '')));
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
        if (!empty($input['sendWelcomeEmail'])) {
            $core->sendAccountCreatedEmail(
                $reseller->getAdminId(), $username, $password, $contact['email'],
                $contact['firstName'], $contact['lastName'], self::ROLE_LABEL
            );
        }

        $core->sendRequest();
        $core->writeLog(
            sprintf('A new customer (%s) has been created by: %s', $username, $caller->getUsername()),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::CUSTOMER, $written['adminId']);
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
}
