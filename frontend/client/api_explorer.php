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
use iMSCP\Plugin\SGW_GraphQL\SGW_GraphQL;
use iMSCP\Registry;
use iMSCP\TemplateEngine;

use function SGW_GraphQL\Frontend\csrfToken;
use function SGW_GraphQL\Frontend\explorerDisabledBy;
use function SGW_GraphQL\Frontend\safeJs;

require_once __DIR__ . '/../common.php';

/**
 * @param TemplateEngine $tpl
 * @param string|null $disabledBy The config key keeping the explorer off, or
 *                                null when it is on. See
 *                                SGW_GraphQL\Frontend\explorerDisabledBy().
 * @param string $endpoint
 * @return void
 */
function generateExplorerPage(TemplateEngine $tpl, $disabledBy, $endpoint)
{
    if ($disabledBy !== null) {
        $tpl->assign('EXPLORER_BLOCK', '');
        $tpl->parse('DISABLED_BLOCK', 'disabled_block');
        return;
    }

    $tpl->assign(array(
        'DISABLED_BLOCK' => '',
        'ENDPOINT_JS'    => safeJs($endpoint),
        'CSRF_JS'        => safeJs(csrfToken())
    ));
    $tpl->parse('EXPLORER_BLOCK', 'explorer_block');
}

check_login('user');
EventAggregator::getInstance()->dispatch(Events::onClientScriptStart);

if (!SGW_GraphQL::customerHasApiAccess(intval($_SESSION['user_id']))) {
    showBadRequestErrorPage();
    exit;
}

$plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');
// Both keys, and both defaults, are config.php's: 'explorer' is off unless
// an operator turned it on, and 'introspection' is on unless one turned it
// off. Gating on 'introspection' alone shipped the explorer enabled on a
// stock install (checkpoint E, finding E4).
$disabledBy = explorerDisabledBy(
    (bool)$plugin->getConfigParam('explorer', false),
    (bool)$plugin->getConfigParam('introspection', true)
);
$endpoint = (string)$plugin->getConfigParam('endpoint', '/api/graphql');
$schemaEndpoint = (string)$plugin->getConfigParam('schema_endpoint', '/api/graphql/schema');

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'          => 'shared/layouts/ui.tpl',
    'page'            => '../../plugins/SGW_GraphQL/themes/default/view/client/api_explorer.tpl',
    'page_message'    => 'layout',
    'disabled_block'  => 'page',
    'explorer_block'  => 'page'
));
$tpl->assign(array(
    'TR_PAGE_TITLE' => tohtml(tr('Client / Domains / API explorer')),
    'TR_INTRO'      => tohtml(tr('Run GraphQL queries and mutations against your own account, from inside the panel. Nothing here is fetched from outside this server.')),
    'TR_DISABLED'   => tohtml($disabledBy === 'introspection'
        ? tr('The API explorer is switched off because schema introspection is disabled in this installation\'s configuration (the \'introspection\' setting). Ask an administrator to enable it, or use the API directly with a token.')
        : tr('The API explorer is switched off in this installation\'s configuration (the \'explorer\' setting). Ask an administrator to enable it, or use the API directly with a token.')),
    'TR_ENDPOINT'   => tohtml(tr('Endpoint')),
    'TR_SCHEMA'     => tohtml(tr('Schema')),
    'ENDPOINT'      => tohtml($endpoint),
    'SCHEMA_ENDPOINT' => tohtml($schemaEndpoint),
    'SCHEMA_LINK'   => tohtml($schemaEndpoint, 'htmlAttr')
));

generateNavigation($tpl);
generateExplorerPage($tpl, $disabledBy, $endpoint);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onClientScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
