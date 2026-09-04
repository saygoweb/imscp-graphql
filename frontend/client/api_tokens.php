<?php
namespace SGW_GraphQL;
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

use iMSCP\Event\EventAggregator;
use iMSCP\Event\Events;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use iMSCP\Registry;
use iMSCP\TemplateEngine;

use function SGW_GraphQL\Frontend\formatWhen;
use function SGW_GraphQL\Frontend\tokenService;
use function SGW_GraphQL\Frontend\tokenState;

require_once __DIR__ . '/../common.php';

/**
 * Revoke a token, when asked from the list.
 *
 * @param int $adminId
 * @return void
 */
function handleRevoke($adminId)
{
    if (!isset($_GET['action'], $_GET['id']) || $_GET['action'] !== 'revoke') {
        return;
    }

    if (tokenService()->revoke($adminId, intval($_GET['id']))) {
        write_log(sprintf(
            'An API token was revoked by %s', $_SESSION['user_logged']
        ), E_USER_NOTICE);
        set_page_message(tr('Token revoked.'), 'success');
    } else {
        set_page_message(tr('That token could not be revoked.'), 'error');
    }

    redirectTo('api_tokens.php');
}

/**
 * Create a token, returning the plaintext exactly once.
 *
 * @param int $adminId
 * @return string|null The new token, or NULL when nothing was created
 */
function handleCreate($adminId)
{
    if (empty($_POST)) {
        return NULL;
    }

    $plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');
    $maxPerAccount = intval($plugin->getConfigParam('token_max_per_account', 10));
    $maxTtl = intval($plugin->getConfigParam('token_max_ttl_days', 730));

    $live = 0;
    foreach (tokenService()->listFor($adminId) as $token) {
        if ($token->getRevokedAt() === NULL
            && ($token->getExpiresAt() === NULL || $token->getExpiresAt() > time())
        ) {
            $live++;
        }
    }

    if ($live >= $maxPerAccount) {
        set_page_message(tr(
            'You already have %d tokens. Revoke one before creating another.',
            $maxPerAccount
        ), 'error');
        return NULL;
    }

    $name = isset($_POST['name']) ? clean_input($_POST['name']) : '';
    $scopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
        ? array_map('clean_input', $_POST['scopes']) : array();
    $ttlDays = isset($_POST['ttl_days']) ? intval($_POST['ttl_days']) : NULL;
    $ipAllowlist = isset($_POST['ip_allowlist'])
        ? trim(clean_input($_POST['ip_allowlist'])) : '';

    if ($ttlDays !== NULL && ($ttlDays < 1 || $ttlDays > $maxTtl)) {
        set_page_message(tr('A token may live for 1 to %d days.', $maxTtl), 'error');
        return NULL;
    }

    try {
        $result = tokenService()->issue(
            $adminId, $name, $scopes, $ttlDays, $ipAllowlist === '' ? NULL : $ipAllowlist
        );
    } catch (\Exception $e) {
        set_page_message(tohtml($e->getMessage()), 'error');
        return NULL;
    }

    write_log(sprintf(
        'A new API token (%s) was created by %s', $name, $_SESSION['user_logged']
    ), E_USER_NOTICE);

    return $result['token'];
}

/**
 * @param TemplateEngine $tpl
 * @param int $adminId
 * @param string|null $newToken
 * @return void
 */
function generatePage(TemplateEngine $tpl, $adminId, $newToken)
{
    $plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');

    if ($newToken === NULL) {
        $tpl->assign('NEW_TOKEN_BLOCK', '');
    } else {
        $tpl->assign('NEW_TOKEN', tohtml($newToken));
        $tpl->parse('NEW_TOKEN_BLOCK', 'new_token_block');
    }

    $tokens = tokenService()->listFor($adminId);

    if ($tokens === array()) {
        $tpl->assign('TOKEN_LIST', '');
        $tpl->parse('NO_TOKENS_BLOCK', 'no_tokens_block');
    } else {
        $tpl->assign('NO_TOKENS_BLOCK', '');

        foreach ($tokens as $token) {
            $state = tokenState($token);
            $scopes = $token->getScopes();

            $tpl->assign(array(
                'STATE'      => tohtml($state['label']),
                'STATE_ICON' => $state['icon'],
                'NAME'       => tohtml($token->getName()),
                'PREFIX'     => tohtml($token->getPrefix()),
                'SCOPES'     => tohtml($scopes === array() ? tr('All') : implode(', ', $scopes)),
                'CREATED'    => tohtml(formatWhen($token->getCreatedAt())),
                'EXPIRES'    => tohtml(formatWhen($token->getExpiresAt())),
                'LAST_USED'  => tohtml(
                    $token->getLastUsedAt() === NULL
                        ? tr('Never')
                        : formatWhen($token->getLastUsedAt())
                            . ' (' . $token->getLastUsedIp() . ')'
                ),
                'TOKEN_ID'   => $token->getTokenId()
            ));

            if ($token->getRevokedAt() === NULL) {
                $tpl->parse('REVOKE_ACTION', 'revoke_action');
            } else {
                $tpl->assign('REVOKE_ACTION', '');
            }

            $tpl->parse('TOKEN_ITEM', '.token_item');
        }

        $tpl->parse('TOKEN_LIST', 'token_list');
    }

    foreach (Scope::all() as $scope) {
        $tpl->assign(array(
            'SCOPE'         => tohtml($scope),
            'SCOPE_CHECKED' => isset($_POST['scopes']) && in_array($scope, (array)$_POST['scopes'], true)
                ? ' checked' : ''
        ));
        $tpl->parse('SCOPE_ITEM', '.scope_item');
    }

    $tpl->assign(array(
        'NAME_VALUE'         => isset($_POST['name']) ? tohtml($_POST['name'], 'htmlAttr') : '',
        'TTL_VALUE'          => intval($plugin->getConfigParam('token_default_ttl_days', 365)),
        'MAX_TTL'            => intval($plugin->getConfigParam('token_max_ttl_days', 730)),
        'IP_ALLOWLIST_VALUE' => isset($_POST['ip_allowlist'])
            ? tohtml($_POST['ip_allowlist'], 'htmlAttr') : '',
        'ENDPOINT'           => tohtml($plugin->getConfigParam('endpoint', '/api/graphql')),
        'SCHEMA_ENDPOINT'    => tohtml($plugin->getConfigParam('schema_endpoint', '/api/graphql/schema'))
    ));
}

check_login('user');
EventAggregator::getInstance()->dispatch(Events::onClientScriptStart);

SGW_GraphQL::customerHasApiAccess(intval($_SESSION['user_id'])) or showBadRequestErrorPage();

$adminId = intval($_SESSION['user_id']);
handleRevoke($adminId);
$newToken = handleCreate($adminId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'          => 'shared/layouts/ui.tpl',
    'page'            => '../../plugins/SGW_GraphQL/themes/default/view/client/api_tokens.tpl',
    'page_message'    => 'layout',
    'new_token_block' => 'page',
    'no_tokens_block' => 'page',
    'token_list'      => 'page',
    'token_item'      => 'token_list',
    'revoke_action'   => 'token_item',
    'scope_item'      => 'page'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'        => tohtml(tr('Client / Profile / API tokens')),
    'TR_INTRO'             => tohtml(tr('Tokens let a script act as your account through the API. Give each one only the scopes it needs, and revoke any you no longer use.')),
    'TR_NEW_TOKEN_INTRO'   => tohtml(tr('Your new token:')),
    'TR_NEW_TOKEN_WARNING' => tohtml(tr('Copy it now. It will not be shown again.')),
    'TR_NO_TOKENS'         => tohtml(tr('You have no tokens.')),
    'TR_STATE'             => tohtml(tr('State')),
    'TR_NAME'              => tohtml(tr('Name')),
    'TR_PREFIX'            => tohtml(tr('Prefix')),
    'TR_SCOPES'            => tohtml(tr('Scopes')),
    'TR_SCOPES_HINT'       => tohtml(tr('Selecting none gives the token everything your account can do.')),
    'TR_CREATED'           => tohtml(tr('Created')),
    'TR_EXPIRES'           => tohtml(tr('Expires')),
    'TR_LAST_USED'         => tohtml(tr('Last used')),
    'TR_ACTION'            => tohtml(tr('Actions')),
    'TR_REVOKE'            => tohtml(tr('Revoke')),
    'TR_REVOKE_CONFIRM'    => tojs(tr('Revoke this token? Anything using it will stop working immediately.')),
    'TR_CREATE'            => tohtml(tr('Create a token')),
    'TR_LIFETIME'          => tohtml(tr('Lifetime')),
    'TR_DAYS'              => tohtml(tr('days')),
    'TR_IP_ALLOWLIST'      => tohtml(tr('Restrict to addresses')),
    'TR_IP_ALLOWLIST_HINT' => tohtml(tr('Comma-separated addresses or CIDR ranges. Leave empty for no restriction.')),
    'TR_ENDPOINT'          => tohtml(tr('Endpoint')),
    'TR_SCHEMA'            => tohtml(tr('Schema'))
));

generateNavigation($tpl);
generatePage($tpl, $adminId, $newToken);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onClientScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
