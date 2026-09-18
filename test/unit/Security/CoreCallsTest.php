<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Security;

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

use PHPUnit\Framework\TestCase;

/**
 * Decision D10, and spec section 6.5's first layer, as a property of the
 * source rather than a comment at each call site.
 *
 * Every call to a global function that PHP itself does not define is listed
 * here with the reason it cannot exit, or the test fails. A new panel
 * function reaching the API path is therefore a visible diff to this file,
 * with its justification beside it, and never an accident.
 *
 * The scan is syntactic, over tokens, not semantic: it sees a name written
 * at the call site. A name assembled at run time - by concatenation, string
 * interpolation, or a variable holding anything other than a literal handed
 * straight to call_user_func()/call_user_func_array() - is invisible to it.
 * That gap is closed by the port rule (D10: only PanelCore may call a panel
 * function at all) and by review, not by this test.
 */
class CoreCallsTest extends TestCase
{
    /**
     * The directories the API request path is built from.
     *
     * Model/ is deliberately not here: its classes call no functions at all,
     * and Task 16 deletes the directory.
     */
    const SCANNED = array(
        'Api', 'Auth', 'Http', 'Repository', 'Resolver', 'Schema', 'Security',
        'Service', 'Support'
    );

    /** Root-level files the API request path is also built from. */
    const SCANNED_FILES = array('SGW_GraphQL.php');

    /**
     * Lower-case function name => why it may be called. Each takes every
     * identity it acts on as an argument, reports failure by return value or
     * exception, reaches no exit, and issues no DDL.
     */
    const ALLOWED = array(
        'exec_query'                    => 'Throws DatabaseException. Plan 1: Container, AccessService, TokenService.',
        'write_log'                     => 'Inserts a log row and may send mail; neither path exits.',
        'getfirstdayofmonth'            => 'Zend_Date arithmetic. Plan 2: Container::monthBounds().',
        'getlastdayofmonth'             => 'Zend_Date arithmetic. Plan 2: Container::monthBounds().',
        'parsemaildirsize'              => 'Reads one file. Plan 2: Container::mailboxUsage().',
        'send_request'                  => 'Opens a socket; logs and returns false on failure.',
        'encode_idna'                   => 'Pure.',
        'decode_idna'                   => 'Pure.',
        'isvaliddomainname'             => 'Pure; sets the $dmnNameValidationErrMsg global.',
        'chk_email'                     => 'Zend validation; pure.',
        'checkpasswordsyntax'           => 'Pure when its third argument is true (no page message).',
        'validates_username'            => 'Pure.',
        'utils_normalizepath'           => 'Pure.',
        'imscp_domain_exists'           => 'Explicit name and reseller id; returns bool.',
        'createdefaultmailaccounts'     => 'Explicit ids; throws DatabaseException; nests its transaction (D11).',
        'get_alias_order_email'         => 'Explicit reseller id; returns the template.',
        'send_add_user_auto_msg'        => 'Explicit reseller id and recipient; logs and returns false on failure.',
        'update_reseller_c_props'       => 'One UPDATE from an explicit reseller id; no session, no exit.',
        'send_mail'                     => 'Throws on bad input; returns bool.',
        'delete_autoreplies_log_entries' => 'One DELETE; no arguments, no session.',
        'tr'                            => 'Returns a translation; used by the plugin class\'s '
            . 'navigation labels, never exits.',
        'l10n_addtranslations'          => 'Registers the plugin\'s own translation resources on '
            . 'a Zend_Translate adapter, from explicit arguments; no session, no exit.'
    );

    /**
     * Named so that nobody adds one of these to ALLOWED without meeting this
     * list first. Each reads the session as the customer, exits, swallows its
     * own failure, or issues DDL (measurements M3-M6).
     */
    const FORBIDDEN = array(
        'showerrorpage', 'showbadrequesterrorpage', 'shownotfounderrorpage',
        'redirectto', 'set_page_message', 'check_login', 'filter_digits',
        'customerhasfeature', 'resellerhasfeature', 'customerhasdomain',
        'get_domain_default_props', 'get_user_domain_id',
        'customersqldblimitisreached', 'deletesubdomain', 'deletesubdomainalias',
        'deletedomainalias', 'delete_sql_database', 'sql_delete_user',
        'deletecustomer', 'change_domain_status'
    );

    public function testNoForbiddenFunctionIsAllowed(): void
    {
        self::assertSame(
            array(),
            array_values(array_intersect(self::FORBIDDEN, array_keys(self::ALLOWED)))
        );
    }

    public function testEveryGlobalCallOnTheApiPathIsAllowed(): void
    {
        $unexplained = array();

        foreach ($this->calls() as $name => $sites) {
            if (!isset(self::ALLOWED[$name])) {
                $unexplained[] = $name . ' at ' . implode(', ', $sites);
            }
        }

        self::assertSame(
            array(),
            $unexplained,
            'A global function reached the API path without an entry in '
                . 'CoreCallsTest::ALLOWED. Call it from Service\PanelCore, and '
                . 'say in ALLOWED why it cannot exit.'
        );
    }

    public function testOnlyPanelCoreCallsThePanelFunctionsThisPlanAdds(): void
    {
        // Plan 1 and 2's five calls, plus the plugin class's own tr() and
        // l10n_addTranslations() calls (SGW_GraphQL.php, in SCANNED_FILES
        // since this checkpoint), predate Service\PanelCore and stay where
        // they are. Everything since goes through the port.
        $predating = array(
            'exec_query', 'write_log', 'getfirstdayofmonth', 'getlastdayofmonth',
            'parsemaildirsize', 'tr', 'l10n_addtranslations'
        );
        $outside = array();

        foreach ($this->calls() as $name => $sites) {
            if (in_array($name, $predating, true)) {
                continue;
            }

            foreach ($sites as $site) {
                if (strpos($site, 'Service/PanelCore.php:') !== 0) {
                    $outside[] = $name . ' at ' . $site;
                }
            }
        }

        self::assertSame(array(), $outside);
    }

    public function testNoForbiddenNameIsUsedAsACallableString(): void
    {
        // 'deleteSubdomain' handed to call_user_func() would slip past a scan
        // for call syntax. Container::toUnicode() already returns
        // 'decode_idna' this way, so the check is not hypothetical.
        // testEveryGlobalCallOnTheApiPathIsAllowed's callsIn() now catches a
        // FORBIDDEN name passed as literally the first argument of
        // call_user_func()/call_user_func_array() too; this scan is kept
        // beside it as the literal-string net the ruling asked for, which
        // also catches a FORBIDDEN name written as a string for any other
        // reason (e.g. stored in an array, or compared against).
        $found = array();

        foreach ($this->files() as $relative => $source) {
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                    && in_array(strtolower(trim($token[1], '\'"')), self::FORBIDDEN, true)
                ) {
                    $found[] = $token[1] . ' at ' . $relative . ':' . $token[2];
                }
            }
        }

        self::assertSame(array(), $found);
    }

    public function testCallsInRecordsADirectCall(): void
    {
        $calls = $this->callsIn("<?php\nexec_query('x');\n", 'Example.php');

        self::assertSame(array('Example.php:2'), $calls['exec_query'] ?? null);
    }

    public function testCallsInRecordsAFullyQualifiedCall(): void
    {
        $calls = $this->callsIn("<?php\n\\write_log('x', 1);\n", 'Example.php');

        self::assertSame(array('Example.php:2'), $calls['write_log'] ?? null);
    }

    public function testCallsInIgnoresAMethodCallAndAStaticCall(): void
    {
        $calls = $this->callsIn(
            "<?php\n\$thing->write_log();\nFoo::write_log();\n", 'Example.php'
        );

        self::assertArrayNotHasKey('write_log', $calls);
    }

    public function testCallsInIgnoresConstruction(): void
    {
        $calls = $this->callsIn("<?php\nnew Foo();\n", 'Example.php');

        self::assertArrayNotHasKey('foo', $calls);
    }

    public function testCallsInRecordsACallableStringPassedToCallUserFunc(): void
    {
        $calls = $this->callsIn(
            "<?php\ncall_user_func('deleteSubdomain', 1);\n", 'Example.php'
        );

        self::assertSame(array('Example.php:2'), $calls['deletesubdomain'] ?? null);
    }

    public function testCallsInRecordsACallableStringPassedToCallUserFuncArray(): void
    {
        $calls = $this->callsIn(
            "<?php\ncall_user_func_array('deleteSubdomain', array(1));\n", 'Example.php'
        );

        self::assertSame(array('Example.php:2'), $calls['deletesubdomain'] ?? null);
    }

    public function testCallsInFlagsAUseFunctionImport(): void
    {
        $calls = $this->callsIn("<?php\nuse function foo as bar;\n", 'Example.php');

        self::assertSame(array('Example.php:2'), $calls['foo'] ?? null);
    }

    /**
     * @return array<string, string[]> lower-case name => "path:line" sites
     */
    private function calls(): array
    {
        $calls = array();

        foreach ($this->files() as $relative => $source) {
            foreach ($this->callsIn($source, $relative) as $name => $sites) {
                $calls[$name] = array_merge($calls[$name] ?? array(), $sites);
            }
        }

        ksort($calls);

        return $calls;
    }

    /**
     * Every call to a global function in one PHP source string, plus every
     * `use function` import (see the class docblock) - the unit this class's
     * own scanner-behaviour tests exercise directly.
     *
     * @return array<string, string[]> lower-case name => "label:line" sites
     */
    private function callsIn(string $source, string $label): array
    {
        $internal = array_flip(get_defined_functions()['internal']);
        $skipBefore = array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST);

        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $skipBefore[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
        }

        $significant = array_values(array_filter(token_get_all($source), static function ($token) {
            return !is_array($token)
                || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
        }));

        $calls = array();

        foreach ($significant as $i => $token) {
            if (is_array($token) && $token[0] === T_USE) {
                // "use function foo as bar;" imports a symbol under a local
                // name of the importer's choosing, which would defeat every
                // check above: a call written as write_log(...) would no
                // longer mean the panel's write_log() at all. The plugin
                // uses no such import, so any one found is recorded as a
                // call to the function it names - the same rule a direct
                // call to that name is held to - rather than attempting to
                // track the alias through the rest of the file.
                $next = $significant[$i + 1] ?? null;

                if (is_array($next) && $next[0] === T_FUNCTION) {
                    $importedName = $significant[$i + 2] ?? null;

                    if (is_array($importedName) && $importedName[0] === T_STRING) {
                        $calls[strtolower($importedName[1])][] = $label . ':' . $token[2];
                    }
                }

                continue;
            }

            $name = null;

            if (is_array($token) && $token[0] === T_STRING) {
                $name = $token[1];
            } elseif (defined('T_NAME_FULLY_QUALIFIED') && is_array($token)
                && $token[0] === constant('T_NAME_FULLY_QUALIFIED')
            ) {
                // PHP 8 tokenises \write_log as one token.
                $name = ltrim($token[1], '\\');

                if (strpos($name, '\\') !== false) {
                    continue;
                }
            }

            if ($name === null || ($significant[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $significant[$i - 1] ?? null;

            if (is_array($previous) && in_array($previous[0], $skipBefore, true)) {
                continue;
            }

            if (is_array($previous) && $previous[0] === T_NS_SEPARATOR) {
                // "\foo(" is a global call; "Bar\foo(" and "new \Foo(" are not.
                $before = $significant[$i - 2] ?? null;

                if (is_array($before) && in_array($before[0], array(T_STRING, T_NEW), true)) {
                    continue;
                }
            }

            $lower = strtolower($name);

            if ($lower === 'call_user_func' || $lower === 'call_user_func_array') {
                // The callee is a string literal handed as the first
                // argument, which the token scan above cannot see: it only
                // records the call to call_user_func() itself, below. That
                // string is a call to the function it names, held to the
                // same rule a direct call would be.
                $firstArg = $significant[$i + 2] ?? null;

                if (is_array($firstArg) && $firstArg[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $calledName = strtolower(trim($firstArg[1], '\'"'));
                    $calls[$calledName][] = $label . ':' . $firstArg[2];
                }
            }

            if (isset($internal[$lower])) {
                continue;
            }

            $calls[$lower][] = $label . ':' . $token[2];
        }

        ksort($calls);

        return $calls;
    }

    /**
     * @return array<string, string> relative path => PHP source
     */
    private function files(): array
    {
        $root = dirname(__DIR__, 3);
        $files = array();

        foreach (self::SCANNED_FILES as $relative) {
            $path = $root . '/' . $relative;

            if (is_file($path)) {
                $files[$relative] = file_get_contents($path);
            }
        }

        foreach (self::SCANNED as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory)
            );

            foreach ($iterator as $file) {
                if (substr((string)$file, -4) !== '.php') {
                    continue;
                }

                $relative = substr((string)$file, strlen($root) + 1);
                $files[$relative] = file_get_contents((string)$file);
            }
        }

        ksort($files);

        return $files;
    }
}
