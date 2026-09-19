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
use iMSCP\Registry;
use iMSCP\TemplateEngine;
use PDO;

use function SGW_GraphQL\Frontend\configKeys;
use function SGW_GraphQL\Frontend\formatConfigValue;
use function SGW_GraphQL\Frontend\formatWhen;
use function SGW_GraphQL\Frontend\safeText;

require_once __DIR__ . '/../common.php';

/** How many `api_audit` rows one page shows. */
const AUDIT_PAGE_SIZE = 50;

/**
 * @param TemplateEngine $tpl
 * @param \iMSCP\Plugin\AbstractPlugin $plugin
 * @return void
 */
function generateConfigSection(TemplateEngine $tpl, $plugin)
{
    foreach (configKeys() as $key) {
        $tpl->assign(array(
            'CONFIG_KEY'   => tohtml($key),
            'CONFIG_VALUE' => safeText(formatConfigValue($plugin->getConfigParam($key)))
        ));
        $tpl->parse('CONFIG_ITEM', '.config_item');
    }
}

/**
 * One page of `api_audit`, newest first.
 *
 * @param TemplateEngine $tpl
 * @param int $page One-based.
 * @return void
 */
function generateAuditSection(TemplateEngine $tpl, $page)
{
    $total = (int)exec_query('SELECT COUNT(*) AS c FROM api_audit')
        ->fetchRow(PDO::FETCH_ASSOC)['c'];
    $totalPages = max(1, (int)ceil($total / AUDIT_PAGE_SIZE));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * AUDIT_PAGE_SIZE;

    $tpl->assign(array(
        'AUDIT_TOTAL'       => $total,
        'AUDIT_PAGE'        => $page,
        'AUDIT_TOTAL_PAGES' => $totalPages,
        'AUDIT_PREV_PAGE'   => max(1, $page - 1),
        'AUDIT_NEXT_PAGE'   => min($totalPages, $page + 1)
    ));
    if ($page <= 1) {
        $tpl->assign('AUDIT_PREV_DISABLED', ' disabled');
    } else {
        $tpl->assign('AUDIT_PREV_DISABLED', '');
    }

    if ($page >= $totalPages) {
        $tpl->assign('AUDIT_NEXT_DISABLED', ' disabled');
    } else {
        $tpl->assign('AUDIT_NEXT_DISABLED', '');
    }

    // LIMIT takes literal integers here, not bound placeholders: PDO binds a
    // placeholder as a string by default, and MySQL rejects a quoted LIMIT
    // ('0', '50') as a syntax error. $offset and AUDIT_PAGE_SIZE are both
    // already ints - $page was cast and clamped above - so interpolating
    // them is exactly what admin_log.php's own pager does for the same
    // reason.
    $rows = exec_query(
        'SELECT a.audit_id, a.at, a.admin_id, adm.admin_name, a.token_id,'
            . ' t.token_prefix, a.ip, a.operation, a.fields, a.outcome,'
            . ' a.error_code, a.duration_ms'
            . ' FROM api_audit a'
            . ' LEFT JOIN admin adm ON adm.admin_id = a.admin_id'
            . ' LEFT JOIN api_token t ON t.token_id = a.token_id'
            . ' ORDER BY a.audit_id DESC'
            . ' LIMIT ' . (int)$offset . ', ' . (int)AUDIT_PAGE_SIZE
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === array()) {
        $tpl->assign('AUDIT_LIST', '');
        $tpl->parse('NO_AUDIT_BLOCK', 'no_audit_block');
        return;
    }

    $tpl->assign('NO_AUDIT_BLOCK', '');

    foreach ($rows as $row) {
        $tpl->assign(array(
            'AUDIT_AT'         => tohtml(formatWhen((int)$row['at'])),
            'AUDIT_ACCOUNT'    => safeText(
                $row['admin_name'] === null
                    ? tr('#%d (deleted)', (int)$row['admin_id'])
                    : decode_idna($row['admin_name'])
            ),
            'AUDIT_VIA'        => $row['token_id'] === null
                ? tohtml(tr('Session'))
                : tohtml(tr('Token %s', $row['token_prefix'] ?? ('#' . $row['token_id']))),
            'AUDIT_IP'         => tohtml($row['ip']),
            'AUDIT_OPERATION'  => $row['operation'] === null
                ? tohtml(tr('(anonymous)'))
                : safeText($row['operation']),
            'AUDIT_FIELDS'     => $row['fields'] === null ? '' : safeText($row['fields']),
            'AUDIT_OUTCOME'    => tohtml($row['outcome']),
            'AUDIT_OUTCOME_ICON' => $row['outcome'] === 'ok' ? 'ok' : 'disabled',
            'AUDIT_ERROR_CODE' => $row['error_code'] === null ? '' : tohtml($row['error_code']),
            'AUDIT_DURATION'   => tohtml((string)(int)$row['duration_ms'])
        ));
        $tpl->parse('AUDIT_ITEM', '.audit_item');
    }

    $tpl->parse('AUDIT_LIST', 'audit_list');
}

check_login('admin');
EventAggregator::getInstance()->dispatch(Events::onAdminScriptStart);

$plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'          => 'shared/layouts/ui.tpl',
    'page'            => '../../plugins/SGW_GraphQL/themes/default/view/admin/api_audit.tpl',
    'page_message'    => 'layout',
    'config_item'     => 'page',
    'no_audit_block'  => 'page',
    'audit_list'      => 'page',
    'audit_item'      => 'audit_list'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'       => tohtml(tr('Admin / System tools / API')),
    'TR_CONFIG_TITLE'     => tohtml(tr('Effective configuration')),
    'TR_CONFIG_KEY'       => tohtml(tr('Key')),
    'TR_CONFIG_VALUE'     => tohtml(tr('Value')),
    'TR_AUDIT_TITLE'      => tohtml(tr('Request log')),
    'TR_NO_AUDIT'         => tohtml(tr('Nothing has been logged yet.')),
    'TR_AT'               => tohtml(tr('When')),
    'TR_ACCOUNT'          => tohtml(tr('Account')),
    'TR_VIA'              => tohtml(tr('Via')),
    'TR_IP'               => tohtml(tr('From')),
    'TR_OPERATION'        => tohtml(tr('Operation')),
    'TR_FIELDS'           => tohtml(tr('Fields')),
    'TR_OUTCOME'          => tohtml(tr('Outcome')),
    'TR_ERROR_CODE'       => tohtml(tr('Error code')),
    'TR_DURATION'         => tohtml(tr('ms')),
    'TR_PREV'             => tohtml(tr('Previous')),
    'TR_NEXT'             => tohtml(tr('Next'))
));

generateNavigation($tpl);
generateConfigSection($tpl, $plugin);
generateAuditSection($tpl, $page);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onAdminScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
