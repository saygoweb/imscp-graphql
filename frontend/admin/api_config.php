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

use function SGW_GraphQL\Frontend\configKeys;
use function SGW_GraphQL\Frontend\formatConfigValue;
use function SGW_GraphQL\Frontend\safeText;

require_once __DIR__ . '/../common.php';

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

check_login('admin');
EventAggregator::getInstance()->dispatch(Events::onAdminScriptStart);

$plugin = Registry::get('pluginManager')->pluginGet('SGW_GraphQL');

$tpl = new TemplateEngine();
$tpl->define_dynamic(array(
    'layout'       => 'shared/layouts/ui.tpl',
    'page'         => '../../plugins/SGW_GraphQL/themes/default/view/admin/api_config.tpl',
    'page_message' => 'layout',
    'config_item'  => 'page'
));
$tpl->assign(array(
    'TR_PAGE_TITLE'   => tohtml(tr('Admin / System tools / API config')),
    'TR_CONFIG_TITLE' => tohtml(tr('Effective configuration')),
    'TR_CONFIG_KEY'   => tohtml(tr('Key')),
    'TR_CONFIG_VALUE' => tohtml(tr('Value'))
));

generateNavigation($tpl);
generateConfigSection($tpl, $plugin);
generatePageMessage($tpl);

$tpl->parse('LAYOUT_CONTENT', 'page');
EventAggregator::getInstance()->dispatch(Events::onAdminScriptEnd, array('templateEngine' => $tpl));
$tpl->prnt();

unsetMessages();
