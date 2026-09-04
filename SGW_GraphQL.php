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
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
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
     * Any URL that is not a real file already reaches the plugin router
     * (gui/public/plugins.php), so the endpoint needs no web server change.
     *
     * GET is not accepted, for any operation: it is the only way a GraphQL
     * endpoint can be driven from a link or an image tag.
     *
     * The route carries no 'middleware' key: PluginRoutesInjector cannot
     * attach one, in either shape it offers (see the long note on
     * Container::routeHandler()), so the whole TLS/CORS/authentication stack
     * is baked into the 'handler' callable instead. Confirmed against the
     * deployed core in Task 13 with a request replayed through the real
     * dispatch path.
     *
     * The vendor load and the Container construction happen inside the route
     * closures below, not here. PluginRoutesInjector::injectRoutes()
     * (gui/src/Plugin/PluginRoutesInjector.php) calls every loaded plugin's
     * getRoutes() in a foreach with no try/catch, on every plugins.php
     * request — including a request for a completely different plugin's URL.
     * loadVendor() throws when vendor/ is absent, which is exactly the state
     * of a deploy from a Git checkout rather than a release archive; thrown
     * from here, that takes down route injection for every plugin on the
     * panel, not just this one's endpoint. Building the Container eagerly is
     * wasted work for the same reason: this method runs on every such
     * request whether or not it is for one of these two routes. Deferring
     * both means a vendor-less deploy instead fails where it belongs — a 500
     * on /api/graphql itself — and the Container is only ever built for a
     * request that actually reaches one of these two closures.
     *
     * @return array
     */
    public function getRoutes()
    {
        // Captured instead of relying on $this inside the closures below:
        // Slim\App::map() unconditionally calls
        // $callable->bindTo($this->container) on any Closure route handler
        // (see the long note on Container::routeHandler()), so inside a route
        // closure $this is the Slim container, not the plugin.
        $plugin = $this;
        $pluginDir = $this->getPluginManager()->pluginGetRootDir() . '/' . $this->getName();

        return array(
            array(
                'name'    => 'sgw_graphql_endpoint',
                'pattern' => $this->getConfigParam('endpoint', '/api/graphql'),
                'methods' => array('POST', 'OPTIONS'),
                // Not `static`: bindTo() on a static closure does not rebind
                // it — it emits a warning and returns null, silently
                // registering a route with no handler at all. Confirmed
                // against the deployed core in Task 13. This closure does not
                // use $this (it uses $plugin instead), so the rebinding Slim
                // performs is harmless; it only has to be legal to perform.
                'handler' => function ($request, $response, array $args) use ($plugin) {
                    self::loadVendor();

                    return (Container::fromPlugin($plugin)->routeHandler())($request, $response, $args);
                }
            ),
            array(
                'name'    => 'sgw_graphql_schema',
                'pattern' => $this->getConfigParam('schema_endpoint', '/api/graphql/schema'),
                'methods' => array('GET'),
                // Not `static`: see the note on the endpoint route's handler
                // above and on Container::routeHandler().
                'handler' => function ($request, $response) use ($plugin) {
                    self::loadVendor();

                    return (Container::fromPlugin($plugin)->schemaRouteHandler())($request, $response);
                }
            ),
            '/client/api_tokens.php'    => $pluginDir . '/frontend/client/api_tokens.php',
            '/reseller/api_tokens.php'  => $pluginDir . '/frontend/reseller/api_tokens.php',
            '/reseller/api_access.php'  => $pluginDir . '/frontend/reseller/api_access.php'
        );
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
