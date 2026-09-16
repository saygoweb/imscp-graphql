<?php
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

// SGW_GraphQL.php (the plugin's root class) extends the real i-MSCP
// AbstractPlugin and calls the real i-MSCP Registry, neither of which is
// vendored or reachable from the unit suite (see test/bootstrap.php's note
// that anything needing the panel belongs elsewhere). Those two classes are
// what customerHasApiAccess() and the class's own "extends" line require in
// order to load and run at all. Task 16 adds a third: Container::fromPlugin()
// now resolves Db::fromPanel(), which calls iMSCP\Database\DatabaseMySQL's
// static getPDO() - also never vendored here - so
// testContainerFromPluginBakesTheConfiguredDefaultIntoTheAccessChecker below,
// which drives fromPlugin() end to end, would fail on a missing class rather
// than exercising anything this suite is about. All three are stubbed here,
// minimally, the same way test/unit/Frontend/GlobalStubs.php stands in for
// tohtml().

namespace iMSCP\Plugin {

    if (!class_exists(__NAMESPACE__ . '\AbstractPlugin', false)) {
        abstract class AbstractPlugin
        {
        }
    }
}

namespace iMSCP\Database {

    if (!class_exists(__NAMESPACE__ . '\DatabaseMySQL', false)) {
        /**
         * Minimal stand-in for the real DatabaseMySQL. Only the static
         * getPDO() Repository\Db::fromPanel() calls is exercised here, and it
         * returns null - Db's constructor accepts a null PDO for exactly this
         * ("a detached handle"), and no test in this class ever queries
         * through the Db that fromPlugin() builds.
         */
        class DatabaseMySQL
        {
            public static function getPDO()
            {
                return null;
            }
        }
    }
}

namespace iMSCP {

    if (!class_exists(__NAMESPACE__ . '\Registry', false)) {
        /**
         * Minimal stand-in for the real Registry (a Zend_Registry facade).
         * Only get()/set() are exercised by customerHasApiAccess().
         */
        class Registry
        {
            /** @var array */
            private static $store = array();

            /**
             * @param string $key
             * @param mixed $value
             * @return mixed
             */
            public static function set($key, $value)
            {
                self::$store[$key] = $value;
                return $value;
            }

            /**
             * @param string $key
             * @return mixed
             */
            public static function get($key)
            {
                return self::$store[$key];
            }
        }
    }
}

namespace {

    // A fake row source for the api_perm lookup inside
    // SGW_GraphQL::customerHasApiAccess(). false means "no row" (the
    // exact case §6.2 and this suite care about); an array simulates a
    // stored 'allowed' value.
    if (!isset($GLOBALS['sgw_graphql_test_api_perm_row'])) {
        $GLOBALS['sgw_graphql_test_api_perm_row'] = false;
    }

    if (!class_exists('SGW_GraphQL_Test_FakeStatement', false)) {
        class SGW_GraphQL_Test_FakeStatement
        {
            /** @var array|false */
            private $row;

            /** @param array|false $row */
            public function __construct($row)
            {
                $this->row = $row;
            }

            /** @return array|false */
            public function fetchRow($fetchStyle = null)
            {
                return $this->row;
            }
        }
    }

    if (!function_exists('exec_query')) {
        /**
         * exec_query() is called unqualified from
         * iMSCP\Plugin\SGW_GraphQL, so it must resolve to the global
         * namespace, exactly as it does against the real, panel-bootstrapped
         * function.
         */
        function exec_query($sql, $bind = array())
        {
            return new SGW_GraphQL_Test_FakeStatement($GLOBALS['sgw_graphql_test_api_perm_row']);
        }
    }

    if (!class_exists('SGW_GraphQL_Test_FakePlugin', false)) {
        class SGW_GraphQL_Test_FakePlugin
        {
            /** @var array */
            private $config;

            public function __construct(array $config)
            {
                $this->config = $config;
            }

            public function getConfigParam($param, $default = null)
            {
                return array_key_exists($param, $this->config) ? $this->config[$param] : $default;
            }
        }
    }

    if (!class_exists('SGW_GraphQL_Test_FakePluginManager', false)) {
        class SGW_GraphQL_Test_FakePluginManager
        {
            /** @var SGW_GraphQL_Test_FakePlugin */
            private $plugin;

            public function __construct(SGW_GraphQL_Test_FakePlugin $plugin)
            {
                $this->plugin = $plugin;
            }

            public function pluginGet($name)
            {
                return $this->plugin;
            }
        }
    }

    // A concrete SGW_GraphQL, only so it satisfies Container::fromPlugin()'s
    // type hint. Every method fromPlugin() actually calls is overridden here,
    // so the test never needs the real panel plumbing (a PluginManager, an
    // exec_query()-backed getConfig()) those methods rely on in production.
    if (!class_exists('SGW_GraphQL_Test_FakeContainerPluginManager', false)) {
        class SGW_GraphQL_Test_FakeContainerPluginManager
        {
            public function pluginGetRootDir()
            {
                return '/tmp';
            }
        }
    }

    if (!class_exists('SGW_GraphQL_Test_FakeContainerPlugin', false)) {
        class SGW_GraphQL_Test_FakeContainerPlugin extends \iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL
        {
            /** @var array */
            private $config;

            public function __construct(array $config)
            {
                $this->config = $config;
            }

            public function getConfigParam($param, $default = null)
            {
                return array_key_exists($param, $this->config) ? $this->config[$param] : $default;
            }

            public function getConfig()
            {
                return $this->config;
            }

            public function getName()
            {
                return 'SGW_GraphQL';
            }

            public function getPluginManager()
            {
                return new SGW_GraphQL_Test_FakeContainerPluginManager();
            }
        }
    }
}
