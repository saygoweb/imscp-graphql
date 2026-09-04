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
use iMSCP\TemplateEngine;

use function SGW_GraphQL\Frontend\csrfToken;
use function SGW_GraphQL\Frontend\customersOf;
use function SGW_GraphQL\Frontend\isCustomerOf;
use function SGW_GraphQL\Frontend\safeText;
use function SGW_GraphQL\Frontend\setApiAccess;
use function SGW_GraphQL\Frontend\tokenService;

require_once __DIR__ . '/../common.php';

/**
 * Grant, withdraw, or revoke all tokens for a customer's API access, when
 * asked from the grid.
 *
 * A state-changing action must never be a GET: a GET is pre-fetchable and is
 * followed by link scanners, so this is a POST, carrying the same CSRF token
 * pattern the token pages use.
 *
 * @param int $resellerId
 * @return void
 */
function handleAction($resellerId)
{
    if (!isset($_POST['action'], $_POST['id'])) {
        return;
    }

    if (!isset($_POST['csrf']) || !hash_equals(csrfToken(), (string)$_POST['csrf'])) {
        showBadRequestErrorPage();
        exit;
    }

    $action = clean_input($_POST['action']);
    $adminId = intval($_POST['id']);

    // A reseller may only reach their own customers. Anything else is
    // indistinguishable from a customer that does not exist, whatever the
    // action - checked before the action itself is even looked at.
    if (!isCustomerOf($resellerId, $adminId)
        || !in_array($action, array('grant', 'withdraw', 'revoke_all'), true)
    ) {
        showBadRequestErrorPage();
        exit;
    }

    // Specification §16: "...a count of that customer's live tokens with a
    // button to revoke all of them." A separate action from grant/withdraw
    // deliberately: revoking every token is not the same operation as
    // withdrawing and re-granting access, and must not touch api_perm.
    if ($action === 'revoke_all') {
        tokenService()->revokeAllFor($adminId);

        write_log(sprintf(
            'All API tokens were revoked for customer %d by %s',
            $adminId, $_SESSION['user_logged']
        ), E_USER_NOTICE);

        set_page_message(tr('All of that customer\'s tokens were revoked.'), 'success');
        redirectTo('api_access.php');
        return;
    }

    setApiAccess($adminId, $action === 'grant');

    write_log(sprintf(
        'API access was %s for customer %d by %s',
        $action === 'grant' ? 'granted' : 'withdrawn',
        $adminId,
        $_SESSION['user_logged']
    ), E_USER_NOTICE);

    set_page_message(
        $action === 'grant'
            ? tr('API access granted.')
            : tr('API access withdrawn, and that customer\'s tokens revoked.'),
        'success'
    );
    redirectTo('api_access.php');
}

/**
 * @param TemplateEngine $tpl
 * @param int $resellerId
 * @return void
 */
function generatePage(TemplateEngine $tpl, $resellerId)
{
    // Assigned before any block referencing it is parsed: every row's
    // grant/withdraw form carries it.
    $tpl->assign('CSRF_TOKEN', tohtml(csrfToken(), 'htmlAttr'));

    $customers = customersOf($resellerId);

    if ($customers === array()) {
        $tpl->assign('CUSTOMER_LIST', '');
        $tpl->parse('NO_CUSTOMERS_BLOCK', 'no_customers_block');
        return;
    }

    $tpl->assign('NO_CUSTOMERS_BLOCK', '');

    foreach ($customers as $customer) {
        $allowed = (bool)$customer['allowed'];
        $liveTokens = intval($customer['live_tokens']);

        $tpl->assign(array(
            'ACCESS'         => tohtml($allowed ? tr('Allowed') : tr('Withdrawn')),
            'ACCESS_ICON'    => $allowed ? 'ok' : 'disabled',
            // A customer's admin_name is theirs to set and is free-form, so
            // it carries the same placeholder-injection risk as a token
            // name (see safeText()'s own doc comment).
            'CUSTOMER_NAME'  => safeText(decode_idna($customer['admin_name'])),
            'LIVE_TOKENS'    => $liveTokens,
            // An integer primary key: cast, not escaped - there is nothing
            // here for tohtml() to protect against.
            'ADMIN_ID'       => intval($customer['admin_id']),
            'TOGGLE_ACTION'  => $allowed ? 'withdraw' : 'grant',
            'TOGGLE_ICON'    => $allowed ? 'delete' : 'ok',
            'TOGGLE_LABEL'   => tohtml($allowed ? tr('Withdraw') : tr('Grant')),
            'TOGGLE_CONFIRM' => tojs($allowed
                ? tr('Withdraw API access? This revokes every token that customer holds.')
                : tr('Grant API access to this customer?'))
        ));

        // Specification §16: shown only where it means something - a
        // customer with no live tokens has nothing for it to revoke.
        if ($liveTokens > 0) {
            $tpl->parse('REVOKE_ALL_ACTION', 'revoke_all_action');
        } else {
            $tpl->assign('REVOKE_ALL_ACTION', '');
        }

        $tpl->parse('CUSTOMER_ITEM', '.customer_item');
    }

    $tpl->parse('CUSTOMER_LIST', 'customer_list');
}

check_login('reseller');
EventAggregator::getInstance()->dispatch(Events::onResellerScriptStart);

$resellerId = intval($_SESSION['user_id']);
handleAction($resellerId);

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'              => 'shared/layouts/ui.tpl',
    'page'                => '../../plugins/SGW_GraphQL/themes/default/view/reseller/api_access.tpl',
    'page_message'        => 'layout',
    'no_customers_block'  => 'page',
    'customer_list'       => 'page',
    'customer_item'       => 'customer_list',
    'revoke_all_action'   => 'customer_item'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'          => tohtml(tr('Reseller / Customers / API access')),
    'TR_INTRO'               => tohtml(tr('Customers may use the API unless you withdraw it here. Withdrawing also revokes every token that customer holds.')),
    'TR_NO_CUSTOMERS'        => tohtml(tr('You have no customers.')),
    'TR_ACCESS'              => tohtml(tr('Access')),
    'TR_CUSTOMER'            => tohtml(tr('Customer')),
    'TR_LIVE_TOKENS'         => tohtml(tr('Live tokens')),
    'TR_ACTION'              => tohtml(tr('Actions')),
    'TR_REVOKE_ALL'          => tohtml(tr('Revoke all')),
    'TR_REVOKE_ALL_CONFIRM'  => tojs(tr('Revoke every token this customer holds? This does not change their API access.'))
));

generateNavigation($tpl);
generatePage($tpl, $resellerId);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onResellerScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
