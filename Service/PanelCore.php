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

use Exception;
use iMSCP\Authentication\AuthService;
use iMSCP\Crypt;
use iMSCP\Database\DatabaseMySQL;
use iMSCP\Event\EventAggregator;
use iMSCP\Event\Events;
use iMSCP\PhpEditor;
use iMSCP\Registry;
use iMSCP\Uri\UriRedirect;
use iMSCP\Validate\CommonValidation;
use InvalidArgumentException;
use PDO;

/**
 * The one class on the API path that calls global panel functions (D10).
 *
 * Constructing it touches nothing, so the unit suite can build the whole
 * resolver map with one. Every method needs the panel bootstrapped.
 */
final class PanelCore implements Core
{
    /** @var bool */
    private $pokeDaemon;

    /**
     * @param bool $pokeDaemon False for a test harness that must not start
     *                         the backend on rows it will roll back.
     */
    public function __construct(bool $pokeDaemon = true)
    {
        $this->pokeDaemon = $pokeDaemon;
    }

    /**
     * Whether init_login() has already registered the panel's login
     * listeners in this process.
     *
     * gui/public/index.php calls init_login() once per request and nothing
     * else calls it at all, so the panel's own listeners are not registered
     * on an API request. They have to be, or AuthService::authenticate() runs
     * with no credential handler and answers FAILURE_UNCATEGORIZED for every
     * password there is. Registering them twice would run login_credentials
     * twice and build a second BruteForce, so this is the once-per-process
     * flag that a request in production gets for free.
     *
     * @var bool
     */
    private static $loginInitialised = false;

    /**
     * CORE-DEBT(C1): AuthService::authenticate() takes its credentials from
     *   $_POST through the registered handler (M21), and on success calls
     *   setIdentity(), which regenerates the session and writes a `login`
     *   row. Spec section 5.3 wants neither.
     *
     * **The whole call runs under a session id that belongs to nothing else**
     * (decision D34, which supersedes D32). Everything the panel's login
     * touches in `login` it addresses by `session_id()`, and `login`'s primary
     * key is `session_id`, so borrowing the caller's session id means writing
     * over - and deleting - rows that are not this call's to write over:
     *
     * - `AuthService::unsetIdentity()` runs
     *   `DELETE FROM login WHERE session_id = ?` with `session_id()`
     *   (gui/src/Authentication/AuthService.php:176), whatever else that row
     *   happens to be. `AuthService::authenticate()` calls it itself, before
     *   `setIdentity()`, on every successful result (:120);
     * - `BruteForce` looks its counter up by `ipaddr` and `user_name` but
     *   *stores* it with `session_id = session_id()`, captured in its
     *   constructor (gui/src/Plugin/BruteForce.php:132,325) - and it stores it
     *   with `REPLACE INTO login`, so under the caller's session id it
     *   replaces the caller's own row outright;
     * - `setIdentity()` calls `session_regenerate_id()` *before* its INSERT
     *   (:214-227), so on the success path the identity row's session id is a
     *   fresh one and deleting by it deletes the identity row and nothing
     *   else.
     *
     * So: the caller's session, if there is one, is closed untouched; a
     * throwaway session with a random id is started for the duration; and an
     * identity is unset only when one was actually set, which - by that last
     * fact - is the only case in which the row being deleted is this call's
     * own. On the failure path nothing is deleted at all, because the row
     * under that id is then BruteForce's, and BruteForce has to be able to
     * count past one for spec section 5.3's one unauthenticated field to be
     * defended at all.
     *
     * `init_login()` is called *inside* the throwaway session for the same
     * reason: it is what constructs `BruteForce`, and `BruteForce` captures
     * `session_id()` there and then.
     *
     * $_POST and $_SESSION are emptied for the call and restored afterwards,
     * and the throwaway session is destroyed and the caller's reopened, in a
     * `finally` so that the throwing path is left as tidy as the others.
     *
     * Everything the panel does on a login - the BruteForce plugin, the
     * account status and expiry checks in login_checkDomainAccount(), the
     * APR-1 rehash of a legacy password - happens inside this call and is not
     * reimplemented.
     *
     * **`$result->isValid()` is not the answer on its own.** A disabled or
     * expired account authenticates: login_credentials() sets SUCCESS on the
     * password alone, and login_checkDomainAccount() refuses it afterwards by
     * stopping the onBeforeSetIdentity event, which makes setIdentity() return
     * before it writes anything. AuthResult still says valid, and the panel's
     * own pages tell the difference exactly as this does - by asking whether
     * an identity actually reached the session (AuthService::hasIdentity(),
     * gui/include/Login.php's check_login()). Reading isValid() alone would
     * hand a token to every disabled account on the box.
     *
     * $_SESSION is emptied first for the same reason: a request that happened
     * to carry a panel session would otherwise leave a `user_identity` in
     * place that this method would read back as its own success.
     *
     * @return array{admin_id: int, admin_name: string, admin_type: string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $callerSessionId = session_id();
        $callerSessionWasOpen = session_status() === PHP_SESSION_ACTIVE;

        if ($callerSessionWasOpen) {
            // Closed, never destroyed: its data and its `login` row are the
            // caller's and this call has no business with either.
            session_write_close();
        }

        $savedPost = $_POST;
        $savedSession = isset($_SESSION) ? $_SESSION : array();

        // Nothing in the system owns this id, so nothing in the system can be
        // deleted or replaced by it.
        session_id(bin2hex(random_bytes(16)));
        session_start();

        try {
            if (!self::$loginInitialised) {
                init_login(EventAggregator::getInstance());
                self::$loginInitialised = true;
            }

            $_POST = array('uname' => $username, 'upass' => $password);
            $_SESSION = array();

            $result = AuthService::getInstance()->authenticate();

            if (!$result->isValid() || !isset($_SESSION['user_identity'])) {
                return null;
            }

            $identity = $_SESSION['user_identity'];

            return array(
                'admin_id'   => (int)$identity->admin_id,
                'admin_name' => (string)$identity->admin_name,
                'admin_type' => (string)$identity->admin_type
            );
        } finally {
            // Only when an identity was really set. setIdentity() regenerated
            // the session id before inserting, so the row this deletes is the
            // identity row it inserted. With no identity there is no row of
            // this call's to remove, and the one that is there is BruteForce's.
            if (isset($_SESSION['user_identity'])) {
                AuthService::getInstance()->unsetIdentity();
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            $_POST = $savedPost;
            $_SESSION = $savedSession;

            if ($callerSessionWasOpen) {
                session_id($callerSessionId);
                session_start();
                // session_start() refills $_SESSION from the store; the
                // caller's own copy is what it is owed.
                $_SESSION = $savedSession;
            }
        }
    }

    public function dispatch(string $event, array $params): void
    {
        EventAggregator::getInstance()->dispatch($event, $params);
    }

    public function sendRequest(): void
    {
        if ($this->pokeDaemon) {
            // Logs and returns false when the daemon is unreachable. The
            // rows are committed and a later request will pick them up, so a
            // failure here is not the caller's error.
            send_request();
        }
    }

    public function writeLog(string $message, int $level): void
    {
        write_log($message, $level);
    }

    public function config(string $key, $default = null)
    {
        $config = Registry::get('config');

        // isset() rather than [] alone: ArrayConfig::get() throws on a missing
        // key, and a missing optional key is a default, not an outage.
        return isset($config[$key]) ? $config[$key] : $default;
    }

    public function toAscii(string $name): string
    {
        return (string)encode_idna($name);
    }

    public function toUnicode(string $name): string
    {
        return (string)decode_idna($name);
    }

    public function domainNameError(string $name): ?string
    {
        global $dmnNameValidationErrMsg;

        $dmnNameValidationErrMsg = '';

        if (isValidDomainName($name)) {
            return null;
        }

        return $dmnNameValidationErrMsg === '' ? 'Invalid domain name.' : (string)$dmnNameValidationErrMsg;
    }

    public function isValidEmail(string $address): bool
    {
        return (bool)chk_email($address);
    }

    public function isValidEmailLocalPart(string $localPart): bool
    {
        return (bool)chk_email($localPart, true);
    }

    public function isAcceptablePassword(string $password): bool
    {
        // The third argument suppresses set_page_message(); the second is the
        // panel's own default character rule, restated because it precedes it.
        return (bool)checkPasswordSyntax($password, '/[^\x21-\x7e]/', true);
    }

    public function isValidUsername(string $username): bool
    {
        return (bool)validates_username($username);
    }

    public function isValidSqlHost(string $asciiHost): bool
    {
        // CORE-DEBT(C3): transcribed from gui/public/client/sql_user_add.php:174-180.
        //   Retire when SqlUserService lands in core.
        if (strpos($asciiHost, '%') !== false || strpos($asciiHost, '_') !== false
            || $asciiHost === 'localhost'
        ) {
            return true;
        }

        return (bool)CommonValidation::getInstance()->hostname($asciiHost, array(
            'allow' => \Zend_Validate_Hostname::ALLOW_DNS | \Zend_Validate_Hostname::ALLOW_IP
        ));
    }

    public function normalisePath(string $path): string
    {
        return (string)utils_normalizePath($path);
    }

    public function hashPassword(string $password): string
    {
        return (string)Crypt::sha512($password);
    }

    /**
     * M4: an account's password is APR-1, not the sha512 a mailbox or an FTP
     * user gets. The difference is not a style choice - the panel's own login
     * compares against this.
     */
    public function hashAccountPassword(string $password): string
    {
        return (string)Crypt::apr1MD5($password);
    }

    public function domainExists(string $name, int $resellerId): bool
    {
        return (bool)imscp_domain_exists($name, $resellerId);
    }

    public function createDefaultMailAccounts(
        int $mainDomainId, string $customerEmail, string $asciiHostName,
        string $forwardMailType, int $subId
    ): void {
        createDefaultMailAccounts($mainDomainId, $customerEmail, $asciiHostName, $forwardMailType, $subId);
    }

    public function savePhpIni(
        int $resellerId, int $customerAdminId, int $mainDomainId, int $vhostId,
        string $vhostType
    ): void {
        // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:438-452.
        //   The four calls are PhpEditor's own; the sequence is the page's.
        //   Retire when SubdomainService lands in core.
        $editor = PhpEditor::getInstance();
        $editor->loadResellerPermissions((string)$resellerId);
        $editor->loadClientPermissions((string)$customerAdminId);
        $editor->loadDomainIni((string)$customerAdminId, (string)$mainDomainId, 'dmn');
        $editor->saveDomainIni((string)$customerAdminId, (string)$vhostId, $vhostType);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/user_add3.php:226-245.
     *   The four loads and the five setters are PhpEditor's own; the order is
     *   the page's, and it matters: memory limit before post max size, post max
     *   size before upload max filesize. Retire when CustomerService lands in
     *   core.
     *
     * @param array<string, string> $values phpiniMemoryLimit, phpiniPostMaxSize,
     *        phpiniUploadMaxFileSize, phpiniMaxExecutionTime, phpiniMaxInputTime
     */
    public function savePhpIniForNewDomain(int $resellerId, int $customerAdminId, int $domainId, array $values): void
    {
        $editor = PhpEditor::getInstance();
        $editor->loadResellerPermissions((string)$resellerId);
        $editor->loadClientPermissions();
        $editor->loadDomainIni();

        $editor->setDomainIni('phpiniMemoryLimit', $values['phpiniMemoryLimit']);
        $editor->setDomainIni('phpiniPostMaxSize', $values['phpiniPostMaxSize']);
        $editor->setDomainIni('phpiniUploadMaxFileSize', $values['phpiniUploadMaxFileSize']);
        $editor->setDomainIni('phpiniMaxExecutionTime', $values['phpiniMaxExecutionTime']);
        $editor->setDomainIni('phpiniMaxInputTime', $values['phpiniMaxInputTime']);
        $editor->saveDomainIni((string)$customerAdminId, (string)$domainId, 'dmn');
    }

    public function normaliseForwardUrl(string $url, string $selfAsciiName, bool $proxy): string
    {
        // CORE-DEBT(C3): transcribed from gui/public/client/subdomain_add.php:341-379,
        //   repeated in alias_add.php, subdomain_edit.php, alias_edit.php and
        //   domain_edit.php. Retire when the vhost services land in core.
        try {
            $uri = UriRedirect::fromString($url);
            $uri->setHost(encode_idna(mb_strtolower($uri->getHost())));
            $uri->setPath(rtrim(utils_normalizePath($uri->getPath()), '/') . '/');
        } catch (Exception $e) {
            // UriException from fromString(), Zend_Uri_Exception from the
            // setters: both are "not a URL we accept".
            throw new InvalidArgumentException(sprintf('Forward URL %s is not valid.', $url), 0, $e);
        }

        // getPort() is false when there is none (M18); in_array()'s loose
        // comparison makes false equal '', which is the page's intent.
        if ($uri->getHost() == $selfAsciiName && $uri->getPath() == '/'
            && in_array($uri->getPort(), array('', 80, 443))
        ) {
            throw new InvalidArgumentException(sprintf(
                'Forward URL %s is not valid: a host cannot be forwarded to itself.', $url
            ));
        }

        if ($proxy) {
            $port = $uri->getPort();

            if ($port && $port < 1025) {
                throw new InvalidArgumentException(
                    'Only ports above 1024 are allowed in a proxied forward URL.'
                );
            }
        }

        return (string)$uri->getUri();
    }

    public function sendAliasOrderEmail(int $customerAdminId, string $aliasName): void
    {
        // CORE-DEBT(C1): transcribed from gui/public/client/alias_add.php:45-80,
        //   which reads the customer from $_SESSION['user_id'].
        // CORE-DEBT(C11): C11 item 9 - the page sends the reseller's "your
        //   customer is awaiting approval" template to the customer's own
        //   address; kept deliberately, because changing who receives mail
        //   is the panel's decision to make first.
        $row = exec_query(
            'SELECT admin_name, created_by, fname, lname, email FROM admin WHERE admin_id = ?',
            array($customerAdminId)
        )->fetchRow(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return;
        }

        $data = get_alias_order_email($row['created_by']);
        $sent = send_mail(array(
            'mail_id'      => 'alias-order-msg',
            'fname'        => $row['fname'],
            'lname'        => $row['lname'],
            'username'     => $row['admin_name'],
            'email'        => $row['email'],
            'subject'      => $data['subject'],
            'message'      => $data['message'],
            'placeholders' => array(
                '{CUSTOMER}' => decode_idna($row['admin_name']),
                '{ALIAS}'    => $aliasName
            )
        ));

        if (!$sent) {
            write_log(sprintf("Couldn't send alias order to %s", $row['admin_name']), E_USER_ERROR);
        }
    }

    public function monthBounds(): array
    {
        return array((int)getFirstDayOfMonth(), (int)getLastDayOfMonth());
    }

    public function syncMailboxQuota(int $domainId, int $bytes): void
    {
        sync_mailboxes_quota($domainId, $bytes);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/reseller/domain_edit.php:901,
     *   collapsed to the shape savePhpIniForNewDomain() already uses: every
     *   value the API's allowances carry is given explicitly, so there is no
     *   $_POST-optional branch to reproduce. The reseller - needed only so
     *   PhpEditor can cap each value against its own PHP limits, the same
     *   role it plays in savePhpIniForNewDomain() - is looked up from
     *   $customerAdminId the way sendAliasOrderEmail() looks up the reseller
     *   that sent its mail: it is not a second identity the API needs to
     *   assert anything about. Retire when CustomerService lands in core.
     *
     * @param array<string, string> $values phpiniMemoryLimit, phpiniPostMaxSize,
     *        phpiniUploadMaxFileSize, phpiniMaxExecutionTime, phpiniMaxInputTime
     */
    public function updatePhpIniForDomain(int $customerAdminId, int $domainId, array $values): void
    {
        $row = exec_query(
            'SELECT created_by FROM admin WHERE admin_id = ?', array($customerAdminId)
        )->fetchRow(PDO::FETCH_ASSOC);
        $resellerId = (int)($row['created_by'] ?? 0);

        $editor = PhpEditor::getInstance();
        $editor->loadResellerPermissions((string)$resellerId);
        $editor->loadClientPermissions((string)$customerAdminId);
        $editor->loadDomainIni((string)$customerAdminId, (string)$domainId, 'dmn');

        $editor->setDomainIni('phpiniMemoryLimit', $values['phpiniMemoryLimit']);
        $editor->setDomainIni('phpiniPostMaxSize', $values['phpiniPostMaxSize']);
        $editor->setDomainIni('phpiniUploadMaxFileSize', $values['phpiniUploadMaxFileSize']);
        $editor->setDomainIni('phpiniMaxExecutionTime', $values['phpiniMaxExecutionTime']);
        $editor->setDomainIni('phpiniMaxInputTime', $values['phpiniMaxInputTime']);

        $editor->updateClientDomainIni($editor->getDomainIni(), (string)$customerAdminId);
    }

    /**
     * CORE-DEBT(C1): send_add_user_auto_msg() reads nothing from the session,
     *   but its first argument is the reseller the message is "from".
     *
     * The cleartext password is the message's whole point (M6); it travels no
     * further than this call.
     */
    public function sendAccountCreatedEmail(
        int $createdBy, string $username, string $password, string $email,
        string $firstName, string $lastName, string $role
    ): bool {
        return (bool)send_add_user_auto_msg(
            $createdBy, $username, $password, $email, $firstName, $lastName, $role
        );
    }

    /** M9: the current_* counters are derived, and this is their only writer (D25). */
    public function updateResellerCounters(int $resellerId): void
    {
        update_reseller_c_props($resellerId);
    }

    public function pruneAutoreplyLog(): void
    {
        delete_autoreplies_log_entries();
    }

    /**
     * CORE-DEBT(C3): gui/include/Shared.php:779 deleteCustomer(), called with
     *   $checkCreatedBy = false because the API has already established
     *   ownership (spec section 6.3) and the helper's own check reads
     *   $_SESSION.
     *
     * Decision D22: it owns its transaction and its DDL, so the caller must
     * not be inside a Writer::run().
     */
    public function deleteCustomer(int $customerAdminId): bool
    {
        return (bool)deleteCustomer($customerAdminId, false);
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/admin/user_delete.php:40-119
     *   admin_deleteUser(), the reseller branch. The page defines this helper
     *   inline in its own script rather than in a shared library the way
     *   deleteCustomer() (gui/include/Shared.php:779) is, so there is nothing
     *   for decision D10 to wrap: this reproduces its five DELETEs (in the
     *   page's own order: hosting_plans and reseller_props first, then the
     *   items every admin/reseller row shares) and its two events directly.
     *
     *   Also dropped, for the same reason CustomerService::setState()'s own
     *   note gives for change_domain_status(): admin_deleteUser() calls
     *   set_page_message() unconditionally on success, and writes its own
     *   write_log() from $_SESSION['user_logged'] - both page-only concerns
     *   a web session provides and an API request does not. Both fail D10's
     *   test the same two ways change_domain_status() does, so the caller
     *   (ResellerService::delete()) supplies its own writeLog() instead, the
     *   same split setState() already uses. The ISP logo file cleanup
     *   (user_delete.php:101-108) is left alone too: removing a file is not
     *   a database concern, and nothing in this API reads or writes one.
     *
     *   api_token and api_perm are the plugin's own tables (sql/001_create_
     *   api_tables.php), not the panel's, so admin_deleteUser() knows
     *   nothing of them; they are cleaned up here, inside the same
     *   transaction, so a deleted reseller leaves no orphaned credential
     *   behind it.
     */
    public function deleteReseller(int $resellerAdminId): bool
    {
        $exists = (bool)exec_query(
            "SELECT 1 FROM admin WHERE admin_id = ? AND admin_type = 'reseller'", array($resellerAdminId)
        )->rowCount();

        if (!$exists) {
            return false;
        }

        $db = DatabaseMySQL::getInstance();

        try {
            $db->beginTransaction();

            $this->dispatch(Events::onBeforeDeleteUser, array('userId' => $resellerAdminId));

            exec_query('DELETE FROM hosting_plans WHERE reseller_id = ?', array($resellerAdminId));
            exec_query('DELETE FROM reseller_props WHERE reseller_id = ?', array($resellerAdminId));
            exec_query('DELETE FROM admin WHERE admin_id = ?', array($resellerAdminId));
            exec_query('DELETE FROM email_tpls WHERE owner_id = ?', array($resellerAdminId));
            exec_query(
                'DELETE FROM tickets WHERE ticket_from = ? OR ticket_to = ?',
                array($resellerAdminId, $resellerAdminId)
            );
            exec_query('DELETE FROM user_gui_props WHERE user_id = ?', array($resellerAdminId));
            exec_query('DELETE FROM api_token WHERE admin_id = ?', array($resellerAdminId));
            exec_query('DELETE FROM api_perm WHERE admin_id = ?', array($resellerAdminId));

            $this->dispatch(Events::onAfterDeleteUser, array('userId' => $resellerAdminId));

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return true;
    }
}
