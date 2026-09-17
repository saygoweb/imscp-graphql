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
use iMSCP\Crypt;
use iMSCP\Event\EventAggregator;
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
        // The page also sends the reseller's "your customer is awaiting
        //   approval" template to the customer's own address. Transcribed as
        //   found; a changed recipient is a behaviour change the panel should
        //   make first, so this is deliberately not fixed here, and is filed
        //   with C11 once section 21 carries that item.
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

    public function pruneAutoreplyLog(): void
    {
        delete_autoreplies_log_entries();
    }
}
