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
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\ObjectRef;
use Throwable;

/**
 * The reseller lifecycle, as an administrator sees it (spec section 7.11,
 * phase 5). An administrator is the only caller of every method here.
 *
 * M16: a reseller has no status column and no daemon work - every write
 * here is synchronous, the same as HostingPlanService's, unlike a
 * customer's. `Reseller implements Provisioned` in the schema all the same
 * (phase 2's own reading of admin_status, which this never sets to anything
 * but the table's own default, is what answers it - see create()'s own
 * note).
 */
final class ResellerService
{
    /** What the panel's own welcome template calls the account it describes. */
    const ROLE_LABEL = 'Reseller';

    /**
     * GraphQL allowance name => whether -1 (withheld) is a legal value.
     * reseller_add.php:283-332: the six countable services may be withheld;
     * the customer count, traffic and disk may not (M17: no UI path ever
     * writes -1 to any of the three).
     */
    const WITHHOLDABLE = array(
        'subdomains'    => true,
        'domainAliases' => true,
        'mailAccounts'  => true,
        'ftpUsers'      => true,
        'sqlDatabases'  => true,
        'sqlUsers'      => true,
        'customers'     => false,
        'traffic'       => false,
        'disk'          => false
    );

    /** GraphQL allowance name => the noun reseller_add.php's own messages use. */
    const LABELS = array(
        'customers'     => 'domains',
        'subdomains'    => 'subdomains',
        'domainAliases' => 'domain aliases',
        'mailAccounts'  => 'mail accounts',
        'ftpUsers'      => 'FTP accounts',
        'sqlDatabases'  => 'SQL databases',
        'sqlUsers'      => 'SQL users',
        'traffic'       => 'traffic',
        'disk'          => 'disk space'
    );

    /** @var Toolkit */
    private $kit;

    public function __construct(Toolkit $kit)
    {
        $this->kit = $kit;
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/admin/reseller_add.php:366-436.
     *   The page has no transaction of its own around the three INSERTs
     *   (only around the PhpEditor calls that precede them; M16 has no
     *   daemon in the loop either), so this simply runs them in
     *   Writer::run(). Retire when ResellerService lands in core.
     */
    public function create(Identity $caller, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3. No object exists yet; the caller must simply be an
        // administrator - a reseller creating another reseller is not a
        // verb this API has, spec section 7.11's own reading of phase 5.
        Guard::requireScope($caller, Scope::RESELLERS_WRITE);
        $this->requireAdmin($caller, 'Only an administrator may create a reseller.');

        // 6. The panel's own order: the account, then the IPs, then the limits.
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

        $contact = $this->contactFor($input, $core);
        // reseller_add.php:281-284.
        $ipIds = $this->ipIdsFor((array)($input['ipAddressIds'] ?? array()));
        $allowances = $this->allowancesFor((array)($input['allowances'] ?? array()), true);
        $supportSystem = !array_key_exists('supportSystem', $input) || $input['supportSystem'] === null
            || (bool)$input['supportSystem'];

        $panel = $kit->panelConfig(array('USER_INITIAL_LANG', 'USER_INITIAL_THEME'));

        $written = $kit->writer()->run(function () use (
            $kit, $core, $caller, $username, $password, $contact, $ipIds, $allowances, $supportSystem, $panel
        ) {
            $db = $kit->db();
            $userData = array('admin_name' => $username) + $contact;
            $core->dispatch(Events::onBeforeAddUser, array('userData' => $userData));

            // M16/M20: no admin_status column in this INSERT, the same as
            // the page's own - so the value this row gets is whatever the
            // column's own default is, exactly as the page produces. On
            // this box that default is 'ok' (measured: SHOW COLUMNS FROM
            // admin LIKE 'admin_status', see the task report).
            $db->execute(
                "
                    INSERT INTO admin (
                        admin_name, admin_pass, admin_type, domain_created, created_by, fname, lname, firm,
                        zip, city, state, country, email, phone, fax, street1, street2, gender
                    ) VALUES (?, ?, 'reseller', unix_timestamp(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ",
                array(
                    $username, $core->hashAccountPassword($password), $caller->getAdminId(),
                    $contact['firstName'], $contact['lastName'], $contact['company'], $contact['postcode'],
                    $contact['city'], $contact['state'], $contact['country'], $contact['email'],
                    $contact['phone'], $contact['fax'], $contact['street1'], $contact['street2'], $contact['gender']
                )
            );

            $resellerId = $db->lastInsertId();

            $db->execute(
                'INSERT INTO user_gui_props (user_id, lang, layout) VALUES (?, ?, ?)',
                array($resellerId, $panel['USER_INITIAL_LANG'], $panel['USER_INITIAL_THEME'])
            );

            // M17: sorted, with a trailing semicolon. Every current_* is
            // explicitly 0: a brand new reseller has sold nothing yet.
            //
            // The ten php_ini_* values are PhpEditor's own default reseller
            // permissions (gui/src/PhpEditor.php:518-530), not the
            // reseller_props table's own column defaults, which are not the
            // same values (measured: the table defaults every php_ini_max_*
            // column to 0, where PhpEditor's no-session default is
            // 8/2/30/60). ResellerCreateInput carries no PHP ini fields, so
            // this is what the page itself would write for a request that
            // never touched them.
            $db->execute(
                "
                    INSERT INTO reseller_props (
                        reseller_id, reseller_ips, max_dmn_cnt, current_dmn_cnt, max_sub_cnt, current_sub_cnt,
                        max_als_cnt, current_als_cnt, max_mail_cnt, current_mail_cnt, max_ftp_cnt, current_ftp_cnt,
                        max_sql_db_cnt, current_sql_db_cnt, max_sql_user_cnt, current_sql_user_cnt, max_traff_amnt,
                        current_traff_amnt, max_disk_amnt, current_disk_amnt, support_system, php_ini_system,
                        php_ini_al_disable_functions, php_ini_al_mail_function, php_ini_al_allow_url_fopen,
                        php_ini_al_display_errors, php_ini_max_post_max_size, php_ini_max_upload_max_filesize,
                        php_ini_max_max_execution_time, php_ini_max_max_input_time, php_ini_max_memory_limit
                    ) VALUES (
                        ?, ?, ?, 0, ?, 0, ?, 0, ?, 0, ?, 0, ?, 0, ?, 0, ?, 0, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ",
                array(
                    $resellerId, implode(';', $ipIds) . ';',
                    $allowances['customers'], $allowances['subdomains'], $allowances['domainAliases'],
                    $allowances['mailAccounts'], $allowances['ftpUsers'], $allowances['sqlDatabases'],
                    $allowances['sqlUsers'], $allowances['traffic'], $allowances['disk'],
                    $supportSystem ? 'yes' : 'no',
                    'no', 'no', 'yes', 'no', 'no', 8, 2, 30, 60, 128
                )
            );

            $core->dispatch(Events::onAfterAddUser, array('userId' => $resellerId, 'userData' => $userData));

            return $resellerId;
        });

        // 9. M16: no daemon work for a reseller - nothing to poke.
        $core->writeLog(
            sprintf('A new reseller (%s) has been created by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        // C11 item 12's own reasoning: after the commit, so a rollback
        // cannot leave a reseller holding credentials for an account that
        // does not exist. reseller_add.php:432-436 sends this unconditionally
        // - unlike customerCreate, there is no sendWelcomeEmail toggle here.
        try {
            $core->sendAccountCreatedEmail(
                $caller->getAdminId(), $username, $password, $contact['email'],
                $contact['firstName'], $contact['lastName'], self::ROLE_LABEL
            );
        } catch (Throwable $e) {
            $core->writeLog(sprintf(
                "Couldn't send the welcome message to %s: %s", $username, $e->getMessage()
            ), E_USER_ERROR);
        }

        return new ObjectRef(NodeType::RESELLER, $written);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/admin/reseller_edit.php:353-714.
     *   Simplified from the page in two ways, both noted where they apply:
     *   the PHP ini permissions are left untouched (ResellerUpdateInput
     *   carries none), and the limit check is LimitRules' own fourth test
     *   rather than reseller_edit.php's own checkResellerLimit(), which also
     *   weighs live customer consumption and whether any customer already
     *   holds an unlimited allocation - a second query this update does not
     *   make. Retire when ResellerService lands in core.
     */
    public function update(Identity $caller, string $id, array $input): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        // 2, 3. B1-shaped (see CustomerService::update()'s own note): a
        // reseller reaches its own Reseller node (OwnershipResolver::
        // mayReachReseller() admits it), so the role check goes here, before
        // anything else is read - checkpoint B found the missing version of
        // this a privilege escalation.
        $target = $kit->guard()->target($caller, $id, array(NodeType::RESELLER), Scope::RESELLERS_WRITE, 'id');
        $this->requireAdmin($caller, 'Only an administrator may change a reseller.');

        $reseller = $kit->accounts()->reseller($target->getKey());

        // 6. A key present names a change, the same rule B8 settled for a
        // customer's expiresAt.
        $named = array_intersect(
            array_keys($input), array('password', 'contact', 'ipAddressIds', 'allowances', 'supportSystem')
        );

        if ($named === array()) {
            throw Guard::badInput('input', 'The update names nothing to change.');
        }

        $password = null;

        if (isset($input['password'])) {
            $password = (string)$input['password'];

            if ($password === '' || !$core->isAcceptablePassword($password)) {
                throw Guard::badInput('input.password', "The password does not meet the panel's password policy.");
            }
        }

        $contact = isset($input['contact']) ? $this->contactForUpdate($input, $reseller, $core) : null;
        $ipIds = isset($input['ipAddressIds']) ? $this->ipIdsFor((array)$input['ipAddressIds']) : null;

        // CORE-DEBT(C3): reseller_edit.php:403-411 - the page keeps every IP
        // a reseller's own customers are using, whatever the form said, and
        // marks those checkboxes readonly; the form can drop one, but the
        // save cannot. Without this, ipAddressIds writes exactly what the
        // caller named, which can take away an IP a customer's domain is
        // still on - after which ResellerAccount::hasIp() is false for that
        // customer's own address and CustomerService's ipFor() refuses any
        // further work on it.
        if ($ipIds !== null) {
            $ipIds = array_values(array_unique(array_merge($ipIds, $this->usedIpIds((int)$target->getKey()))));
            sort($ipIds);
        }

        $allowances = isset($input['allowances']) ? $this->allowancesFor((array)$input['allowances'], false) : array();

        // 7. "A max_* lowered below the reseller's own current_* is refused"
        // - LimitRules::reason()'s fourth test, with the reseller in the
        // customer's place.
        if ($allowances !== array()) {
            $this->checkNotBelowUsed($reseller, $allowances);
        }

        $supportSystem = array_key_exists('supportSystem', $input) && $input['supportSystem'] !== null
            ? (bool)$input['supportSystem']
            : null;

        $adminId = (int)$target->getKey();
        $username = $reseller->getUsername();

        $kit->writer()->run(function () use (
            $kit, $core, $adminId, $username, $password, $contact, $ipIds, $allowances, $supportSystem
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
                            street2 = IFNULL(?, street2), gender = IFNULL(?, gender)
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
                        $adminId
                    )
                );
            }

            if ($ipIds !== null || $allowances !== array() || $supportSystem !== null) {
                $sets = array();
                $bind = array();

                if ($ipIds !== null) {
                    $sets[] = 'reseller_ips = ?';
                    $bind[] = implode(';', $ipIds) . ';';
                }

                foreach ($allowances as $name => $value) {
                    $sets[] = ResellerAccount::COLUMNS[$name][0] . ' = ?';
                    $bind[] = $value;
                }

                if ($supportSystem !== null) {
                    $sets[] = 'support_system = ?';
                    $bind[] = $supportSystem ? 'yes' : 'no';
                }

                $bind[] = $adminId;
                $db->execute(
                    'UPDATE reseller_props SET ' . implode(', ', $sets) . ' WHERE reseller_id = ?', $bind
                );
            }

            // reseller_edit.php:680: every successful update forces the
            // reseller to log in again, whatever was actually changed -
            // unlike a customer's, which is gated on a password or a
            // contact change (CustomerService::update()'s own note).
            $db->execute('DELETE FROM login WHERE user_name = ?', array($username));

            $core->dispatch(Events::onAfterEditUser, array('userId' => $adminId));
        });

        // M16: no daemon work for a reseller - nothing to poke.
        $core->writeLog(
            sprintf('The %s reseller has been updated by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::RESELLER, $adminId);
    }

    /**
     * Decision D22-shaped: Core::deleteReseller() manages its own
     * transaction (CORE-DEBT(C3) on PanelCore's own implementation), so this
     * opens no Writer::run() of its own around it. Every refusal happens
     * first, so a caller who may not delete never reaches it.
     */
    public function delete(Identity $caller, string $id): ObjectRef
    {
        $kit = $this->kit;
        $core = $kit->core();

        $target = $kit->guard()->target($caller, $id, array(NodeType::RESELLER), Scope::RESELLERS_WRITE, 'id');
        $this->requireAdmin($caller, 'Only an administrator may delete a reseller.');

        $reseller = $kit->accounts()->reseller($target->getKey());

        // admin_validateUserDeletion()'s own rule (user_delete.php:144):
        // every admin row this reseller created, whatever its own status.
        // current_dmn_cnt (M9) is the wrong instrument for this question -
        // update_reseller_c_props() (Shared.php:414-421) carries
        // "AND domain_status <> 'todelete'" in the query that maintains it,
        // so a reseller's only customer mid-deletion already reads as zero
        // there while its `admin` row - and its dangling `created_by` - is
        // still live. The page's own COUNT has no such filter.
        $customerCount = (int)$kit->db()->value(
            'SELECT COUNT(*) FROM admin WHERE created_by = ?', array($target->getKey())
        );

        if ($customerCount > 0) {
            throw Guard::conflict('This reseller still has customers and cannot be deleted.');
        }

        $adminId = (int)$target->getKey();
        $username = $reseller->getUsername();
        // Read before the delete: a reseller's admin row is gone outright
        // (M16 - no todelete verb to leave it readable behind), the same
        // reason HostingPlanService::delete() takes its own snapshot first.
        $snapshot = $this->snapshot($adminId);

        if (!$core->deleteReseller($adminId)) {
            // It returns false only when the row it was given is not there,
            // which after Guard::target() means it went between the two.
            throw Guard::conflict('That reseller is no longer there.');
        }

        $core->writeLog(
            sprintf('The %s reseller has been deleted by %s', $username, $caller->getUsername()), E_USER_NOTICE
        );

        return new ObjectRef(NodeType::RESELLER, $adminId, $snapshot);
    }

    /**
     * Spec section 6.2, Task 6's own method with NodeType::RESELLER and
     * Scope::RESELLERS_WRITE - the reseller half Task 6 deliberately left
     * for this task. No panel page to transcribe and no daemon to poke: the
     * row is the plugin's own (api_perm), and the next request the account
     * makes is refused or allowed by AuthenticateMiddleware.
     */
    public function setApiAccess(Identity $caller, string $id, bool $allowed): ObjectRef
    {
        $kit = $this->kit;

        $target = $kit->guard()->target($caller, $id, array(NodeType::RESELLER), Scope::RESELLERS_WRITE, 'id');
        $this->requireAdmin($caller, "Only an administrator may change a reseller's API access.");

        $adminId = (int)$target->getKey();
        $kit->access()->setApiAccess($adminId, $allowed);
        $kit->core()->writeLog(
            sprintf(
                'API access has been %s for %s by %s',
                $allowed ? 'granted' : 'withdrawn',
                $kit->accounts()->reseller($adminId)->getUsername(),
                $caller->getUsername()
            ),
            E_USER_NOTICE
        );

        return new ObjectRef(NodeType::RESELLER, $adminId);
    }

    /**
     * Step 3: the caller named its own role, not a hidden object, so a
     * non-administrator is FORBIDDEN here - never NOT_FOUND, and (for
     * update/delete/setApiAccess) after ownership has already been resolved,
     * the same order CustomerService::update() holds B1's check to.
     */
    private function requireAdmin(Identity $caller, string $message): void
    {
        if ($caller->getRole() !== Identity::ROLE_ADMIN) {
            throw Guard::forbidden($message);
        }
    }

    /**
     * ResellerCreateInput.ipAddressIds / ResellerUpdateInput.ipAddressIds:
     * reseller_add.php:272-284 - at least one IP is required, and every one
     * named must be a real server IP.
     *
     * @return int[] sorted, de-duplicated
     */
    private function ipIdsFor(array $encoded): array
    {
        if ($encoded === array()) {
            throw Guard::badInput('input.ipAddressIds', 'You must assign at least one IP to this reseller.');
        }

        $ids = array();

        foreach ($encoded as $one) {
            $ids[] = Guard::parse((string)$one, array(NodeType::IP_ADDRESS))->getId();
        }

        $ids = array_values(array_unique($ids));
        $db = $this->kit->db();
        $existing = $db->rows(
            'SELECT ip_id FROM server_ips WHERE ip_id IN (' . $db->placeholders(count($ids)) . ')', $ids
        );
        $found = array();

        foreach ($existing as $row) {
            $found[] = (int)$row['ip_id'];
        }

        if (array_diff($ids, $found) !== array()) {
            throw Guard::badInput('input.ipAddressIds', 'One of those IP addresses does not exist.');
        }

        sort($ids);

        return $ids;
    }

    /**
     * CORE-DEBT(C3): transcribed from reseller_edit.php's own getFormData()
     * (admin/reseller_edit.php:90-94) - every IP a domain created by this
     * reseller currently sits on, whatever that domain's own status.
     *
     * @return int[]
     */
    private function usedIpIds(int $resellerId): array
    {
        $rows = $this->kit->db()->rows(
            '
                SELECT DISTINCT domain_ip_id
                FROM domain
                JOIN admin ON (admin_id = domain_admin_id)
                WHERE created_by = ?
            ',
            array($resellerId)
        );

        return array_map(static function (array $row): int {
            return (int)$row['domain_ip_id'];
        }, $rows);
    }

    /**
     * ResellerAllowancesInput. $create requires every field to be named, the
     * same rule Support\Allowances::fromInput() applies to a customer's; an
     * update reads only what is given, so the caller can tell a named field
     * from an absent one. traffic and disk arrive as bytes (D3) and are
     * returned in MiB, the unit reseller_props stores them in.
     *
     * @return array<string, int> allowance name => value, present only for a
     *                            field the input actually named
     */
    private function allowancesFor(array $input, bool $create): array
    {
        $result = array();

        foreach (self::WITHHOLDABLE as $name => $withholdable) {
            if (!array_key_exists($name, $input) || $input[$name] === null) {
                if ($create) {
                    throw Guard::badInput('input.allowances.' . $name, 'A create must name every allowance.');
                }

                continue;
            }

            $value = $input[$name];

            if (!is_int($value)) {
                throw Guard::badInput('input.allowances.' . $name, 'A limit is a whole number.');
            }

            if ($name === 'traffic' || $name === 'disk') {
                if ($value < 0) {
                    throw Guard::badInput(
                        'input.allowances.' . $name, 'A limit is 0 for unlimited, or a positive number.'
                    );
                }

                if ($value > 0 && $value % 1048576 !== 0) {
                    throw Guard::badInput(
                        'input.allowances.' . $name,
                        sprintf('A %s limit is a whole number of MiB, given in bytes.', $name)
                    );
                }

                $result[$name] = $value === 0 ? 0 : intdiv($value, 1048576);
                continue;
            }

            $min = $withholdable ? -1 : 0;

            if ($value < $min) {
                throw Guard::badInput(
                    'input.allowances.' . $name,
                    $min === -1
                        ? 'A limit is -1 to withhold the service, 0 for unlimited, or a positive number.'
                        : 'A limit is 0 for unlimited, or a positive number.'
                );
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * "A max_* lowered below the reseller's own current_* is refused" -
     * LimitRules::reason()'s fourth test, with the reseller standing where a
     * customer stands in that test: 0 (unlimited) passes through untested,
     * the same as the customer's; -1 (withheld, where legal) is its own
     * test - C3 below - rather than being skipped outright.
     */
    private function checkNotBelowUsed(ResellerAccount $reseller, array $allowances): void
    {
        foreach ($allowances as $name => $newLimit) {
            if ($newLimit === -1) {
                // checkResellerLimit() (reseller_edit.php:755-775): a
                // service already sold to this reseller's customers - its
                // own current_* counter, the total its customers' own
                // limits already claim - cannot be withheld. The page also
                // weighs live customer consumption and whether a customer
                // already holds an unlimited allocation; neither query is
                // made here (CORE-DEBT(C3)) - both narrower divergences can
                // only under-refuse a *lowering*, not this withholding.
                $used = $reseller->usedOf($name);

                if ($used > 0) {
                    throw Guard::limitExceeded(
                        sprintf(
                            'The %s limit cannot be withheld: the reseller\'s customers are already using it.',
                            self::LABELS[$name]
                        ),
                        array('quota' => $name, 'limit' => $newLimit, 'used' => $used)
                    );
                }

                continue;
            }

            if ($newLimit === 0) {
                continue;
            }

            $used = $reseller->usedOf($name);

            if ($newLimit < $used) {
                throw Guard::limitExceeded(
                    sprintf(
                        'The %s limit cannot be lower than %d, the total already in use.',
                        self::LABELS[$name], $used
                    ),
                    array('quota' => $name, 'limit' => $newLimit, 'used' => $used)
                );
            }
        }
    }

    private function usernameTaken(string $username): bool
    {
        return (int)$this->kit->db()->value(
            'SELECT COUNT(*) FROM admin WHERE admin_name = ?', array($username)
        ) > 0;
    }

    /**
     * ContactDetailsInput. Only the email is required; the panel's form
     * agrees. Identical in shape to CustomerService's own private method of
     * the same name - each service is self-contained (shared-context.md).
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

    /**
     * B7-shaped: a partial contact must not blank the fields it does not
     * name - merged over the reseller's current admin row first, the same
     * rule CustomerService::contactForUpdate() applies.
     */
    private function contactForUpdate(array $given, ResellerAccount $reseller, Core $core): array
    {
        $current = $this->kit->db()->row(
            '
                SELECT email, fname, lname, firm, zip, city, state, country, phone, fax, street1, street2, gender
                FROM admin WHERE admin_id = ?
            ',
            array($reseller->getAdminId())
        ) ?? array();

        $currentContact = array(
            'email'     => (string)($current['email'] ?? ''),
            'firstName' => (string)($current['fname'] ?? ''),
            'lastName'  => (string)($current['lname'] ?? ''),
            'company'   => (string)($current['firm'] ?? ''),
            'postcode'  => (string)($current['zip'] ?? ''),
            'city'      => (string)($current['city'] ?? ''),
            'state'     => (string)($current['state'] ?? ''),
            'country'   => (string)($current['country'] ?? ''),
            'phone'     => (string)($current['phone'] ?? ''),
            'fax'       => (string)($current['fax'] ?? ''),
            'street1'   => (string)($current['street1'] ?? ''),
            'street2'   => (string)($current['street2'] ?? ''),
            'gender'    => (string)($current['gender'] ?? 'U')
        );

        return $this->contactFor(array('contact' => ((array)$given['contact']) + $currentContact), $core);
    }

    /**
     * The row Resolver\ResellerResolver::shape() needs, read before the
     * delete so resellerDelete can still answer the object it just removed -
     * must be kept in sync with Resolver\ResellerResolver::SELECT.
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(int $adminId): ?array
    {
        return $this->kit->db()->row(
            '
                SELECT
                    a.admin_id, a.admin_name, a.admin_status, a.domain_created,
                    a.fname, a.lname, a.gender, a.firm, a.street1, a.street2, a.city,
                    a.state, a.zip, a.country, a.email, a.phone, a.fax,
                    p.max_dmn_cnt, p.current_dmn_cnt,
                    p.max_sub_cnt, p.current_sub_cnt,
                    p.max_als_cnt, p.current_als_cnt,
                    p.max_mail_cnt, p.current_mail_cnt,
                    p.max_ftp_cnt, p.current_ftp_cnt,
                    p.max_sql_db_cnt, p.current_sql_db_cnt,
                    p.max_sql_user_cnt, p.current_sql_user_cnt,
                    p.max_disk_amnt, p.max_traff_amnt, p.reseller_ips
                FROM admin AS a
                JOIN reseller_props AS p ON p.reseller_id = a.admin_id
                WHERE a.admin_id = ?
            ',
            array($adminId)
        );
    }
}
