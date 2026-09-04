<?php
namespace iMSCP\Plugin\SGW_GraphQL;
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

use iMSCP\Event\Event;
use iMSCP\Event\EventManagerInterface;
use iMSCP\Event\Events;
use iMSCP\Plugin\AbstractPlugin;
use iMSCP\Plugin\PluginException;
use iMSCP\Plugin\PluginManager;
use iMSCP\Registry;
use PDO;

/**
 * A GraphQL API for i-MSCP.
 *
 * The plugin owns an HTTP endpoint rather than a feature: see docs/SPECIFICATION.md.
 */
class SGW_GraphQL extends AbstractPlugin
{
    /**
     * Plugin initialisation
     *
     * @return void
     */
    public function init()
    {
        self::loadVendor();
        l10n_addTranslations(__DIR__ . '/l10n', 'Array', $this->getName());
    }

    /**
     * Register the bundled Composer autoloader.
     *
     * The panel's own autoloader already resolves this plugin's classes, since
     * it maps iMSCP\Plugin\ onto the plugins directory. This exists only for
     * the two vendored libraries, and must not run more than once per request.
     *
     * @return void
     */
    public static function loadVendor()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $autoload = __DIR__ . '/vendor/autoload.php';

        if (!@is_readable($autoload)) {
            throw new PluginException(
                'The SGW_GraphQL plugin is missing its vendor directory. '
                . 'It must be installed from a release archive, not from a Git checkout.'
            );
        }

        require_once $autoload;
        $loaded = true;
    }

    /**
     * Register event listeners
     *
     * @param EventManagerInterface $eventsManager
     * @return void
     */
    public function register(EventManagerInterface $eventsManager)
    {
        $eventsManager->registerListener(
            array(
                Events::onClientScriptStart,
                Events::onResellerScriptStart,
                // An account that goes away must take its credentials with it.
                Events::onAfterDeleteCustomer,
                Events::onAfterDeleteUser
            ),
            $this
        );
    }

    /**
     * Plugin installation
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @return void
     */
    public function install(PluginManager $pluginManager)
    {
        try {
            $this->migrateDb('up');
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Plugin update
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @param string $fromVersion
     * @param string $toVersion
     * @return void
     */
    public function update(PluginManager $pluginManager, $fromVersion, $toVersion)
    {
        try {
            $this->migrateDb('up');
            $this->clearTranslations();
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Plugin uninstallation
     *
     * @throws PluginException
     * @param PluginManager $pluginManager
     * @return void
     */
    public function uninstall(PluginManager $pluginManager)
    {
        try {
            $this->migrateDb('down');
            $this->clearTranslations();
        } catch (PluginException $e) {
            throw new PluginException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * onClientScriptStart event listener
     *
     * @return void
     */
    public function onClientScriptStart()
    {
        if (self::customerHasApiAccess(intval($_SESSION['user_id']))) {
            $this->setupNavigation('client');
        }
    }

    /**
     * onResellerScriptStart event listener
     *
     * @return void
     */
    public function onResellerScriptStart()
    {
        $this->setupNavigation('reseller');
    }

    /**
     * onAfterDeleteCustomer event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteCustomer(Event $event)
    {
        self::forgetAccount(intval($event->getParam('customerId')));
    }

    /**
     * onAfterDeleteUser event listener
     *
     * @param Event $event
     * @return void
     */
    public function onAfterDeleteUser(Event $event)
    {
        self::forgetAccount(intval($event->getParam('userId')));
    }

    /**
     * Get routes
     *
     * Filled in once the endpoint exists.
     *
     * @return array
     */
    public function getRoutes()
    {
        return array();
    }

    /**
     * May the given account use the API at all?
     *
     * Available to everyone unless a reseller or an administrator has
     * explicitly withdrawn it, which is recorded as a row in api_perm.
     *
     * @param int $adminId Account unique identifier
     * @return bool
     */
    public static function customerHasApiAccess($adminId)
    {
        static $hasAccess = array();

        if (!array_key_exists($adminId, $hasAccess)) {
            $stmt = exec_query(
                'SELECT allowed FROM api_perm WHERE admin_id = ?', array($adminId)
            );
            $row = $stmt->fetchRow(PDO::FETCH_ASSOC);
            $hasAccess[$adminId] = ($row === false) ? true : (bool)$row['allowed'];
        }

        return $hasAccess[$adminId];
    }

    /**
     * Drop everything the plugin holds for an account that has been deleted.
     *
     * A withdrawn or deleted account's tokens go with it: a credential and the
     * account it acts as should never outlive one another.
     *
     * @param int $adminId
     * @return void
     */
    protected static function forgetAccount($adminId)
    {
        exec_query('DELETE FROM api_token WHERE admin_id = ?', array($adminId));
        exec_query('DELETE FROM api_perm WHERE admin_id = ?', array($adminId));
    }

    /**
     * Inject links into the navigation object
     *
     * @param string $level UI level (reseller|client)
     * @return void
     */
    protected function setupNavigation($level)
    {
        if (!Registry::isRegistered('navigation')) {
            return;
        }

        /** @var \Zend_Navigation $navigation */
        $navigation = Registry::get('navigation');

        if ($level == 'client') {
            if (($page = $navigation->findOneBy('uri', '/client/profile.php'))) {
                $page->addPage(array(
                    'label'       => tr('API tokens'),
                    'uri'         => '/client/api_tokens.php',
                    'title_class' => 'profile'
                ));
            }
            return;
        }

        if (($page = $navigation->findOneBy('uri', '/reseller/users.php'))) {
            $page->addPage(array(
                'label'              => tr('API access'),
                'uri'                => '/reseller/api_access.php',
                'title_class'        => 'users',
                'privilege_callback' => array('name' => 'resellerHasCustomers')
            ));
        }
        if (($page = $navigation->findOneBy('uri', '/reseller/profile.php'))) {
            $page->addPage(array(
                'label'       => tr('API tokens'),
                'uri'         => '/reseller/api_tokens.php',
                'title_class' => 'profile'
            ));
        }
    }

    /**
     * Clear translations if any
     *
     * @return void
     */
    protected function clearTranslations()
    {
        /** @var \Zend_Translate $translator */
        $translator = Registry::get('translator');

        if ($translator->hasCache()) {
            $translator->clearCache($this->getName());
        }
    }
}
