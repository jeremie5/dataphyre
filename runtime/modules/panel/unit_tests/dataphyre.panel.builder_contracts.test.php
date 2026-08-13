<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return mixed */
function dp_panel_builder_contract_path(array $data, string $path): mixed {
	$value=$data;
	foreach(explode('.', $path) as $segment){
		if(!is_array($value) || !array_key_exists($segment, $value)){
			return null;
		}
		$value=$value[$segment];
	}
	return $value;
}

dataset('panel simple builder mutations', [
	'cluster label'=>['Dataphyre\\Panel\\NavigationCluster', 'label', ['Sales desk'], 'label', 'Sales desk'],
	'cluster icon'=>['Dataphyre\\Panel\\NavigationCluster', 'icon', ['cart'], 'icon', 'cart'],
	'cluster description'=>['Dataphyre\\Panel\\NavigationCluster', 'description', ['Revenue work'], 'description', 'Revenue work'],
	'cluster sort'=>['Dataphyre\\Panel\\NavigationCluster', 'sort', [17], 'sort', 17],
	'cluster badge'=>['Dataphyre\\Panel\\NavigationCluster', 'badge', [8], 'badge', 8],
	'cluster badge tone'=>['Dataphyre\\Panel\\NavigationCluster', 'badgeTone', ['success'], 'badge_tone', 'success'],
	'cluster collapsed'=>['Dataphyre\\Panel\\NavigationCluster', 'collapsed', [true], 'collapsed', true],
	'cluster meta'=>['Dataphyre\\Panel\\NavigationCluster', 'meta', [['probe'=>'cluster']], 'meta.probe', 'cluster'],

	'menu label'=>['Dataphyre\\Panel\\PanelMenuItem', 'label', ['Account'], 'label', 'Account'],
	'menu description'=>['Dataphyre\\Panel\\PanelMenuItem', 'description', ['Manage account'], 'description', 'Manage account'],
	'menu icon'=>['Dataphyre\\Panel\\PanelMenuItem', 'icon', ['user'], 'icon', 'user'],
	'menu url'=>['Dataphyre\\Panel\\PanelMenuItem', 'url', ['/panel/account'], 'url', '/panel/account'],
	'menu tone'=>['Dataphyre\\Panel\\PanelMenuItem', 'tone', ['primary'], 'tone', 'primary'],
	'menu sort'=>['Dataphyre\\Panel\\PanelMenuItem', 'sort', [21], 'sort', 21],
	'menu new tab'=>['Dataphyre\\Panel\\PanelMenuItem', 'newTab', [true], 'new_tab', true],
	'menu hidden'=>['Dataphyre\\Panel\\PanelMenuItem', 'hide', [true], 'hidden', true],
	'menu meta'=>['Dataphyre\\Panel\\PanelMenuItem', 'meta', [['probe'=>'menu']], 'meta.probe', 'menu'],

	'tenant label'=>['Dataphyre\\Panel\\PanelTenant', 'label', ['North'], 'label', 'North'],
	'tenant description'=>['Dataphyre\\Panel\\PanelTenant', 'description', ['Northern tenant'], 'description', 'Northern tenant'],
	'tenant icon'=>['Dataphyre\\Panel\\PanelTenant', 'icon', ['building'], 'icon', 'building'],
	'tenant url'=>['Dataphyre\\Panel\\PanelTenant', 'url', ['/panel/tenant/north'], 'url', '/panel/tenant/north'],
	'tenant badge'=>['Dataphyre\\Panel\\PanelTenant', 'badge', [3], 'badge', 3],
	'tenant badge tone'=>['Dataphyre\\Panel\\PanelTenant', 'badgeTone', ['warning'], 'badge_tone', 'warning'],
	'tenant current'=>['Dataphyre\\Panel\\PanelTenant', 'current', [true], 'current', true],
	'tenant sort'=>['Dataphyre\\Panel\\PanelTenant', 'sort', [31], 'sort', 31],
	'tenant hidden'=>['Dataphyre\\Panel\\PanelTenant', 'hide', [true], 'hidden', true],
	'tenant meta'=>['Dataphyre\\Panel\\PanelTenant', 'meta', [['probe'=>'tenant']], 'meta.probe', 'tenant'],

	'action group label'=>['Dataphyre\\Panel\\ActionGroup', 'label', ['Review'], 'label', 'Review'],
	'action group icon'=>['Dataphyre\\Panel\\ActionGroup', 'icon', ['check'], 'icon', 'check'],
	'action group tone'=>['Dataphyre\\Panel\\ActionGroup', 'tone', ['danger'], 'tone', 'danger'],
	'action group style'=>['Dataphyre\\Panel\\ActionGroup', 'style', ['outline'], 'style', 'outline'],
	'action group variant alias'=>['Dataphyre\\Panel\\ActionGroup', 'variant', ['ghost'], 'style', 'ghost'],
	'action group outlined'=>['Dataphyre\\Panel\\ActionGroup', 'outlined', [true], 'style', 'outline'],
	'action group outline alias'=>['Dataphyre\\Panel\\ActionGroup', 'outline', [true], 'style', 'outline'],
	'action group ghost'=>['Dataphyre\\Panel\\ActionGroup', 'ghost', [true], 'style', 'ghost'],
	'action group subtle'=>['Dataphyre\\Panel\\ActionGroup', 'subtle', [true], 'style', 'ghost'],
	'action group link'=>['Dataphyre\\Panel\\ActionGroup', 'link', [true], 'style', 'link'],
	'action group size'=>['Dataphyre\\Panel\\ActionGroup', 'size', ['large'], 'size', 'lg'],
	'action group compact'=>['Dataphyre\\Panel\\ActionGroup', 'compact', [true], 'size', 'sm'],
	'action group large'=>['Dataphyre\\Panel\\ActionGroup', 'large', [true], 'size', 'lg'],
	'action group icon only'=>['Dataphyre\\Panel\\ActionGroup', 'iconOnly', [true], 'icon_only', true],
	'action group icon button alias'=>['Dataphyre\\Panel\\ActionGroup', 'iconButton', [true], 'icon_only', true],
	'action group width'=>['Dataphyre\\Panel\\ActionGroup', 'dropdownWidth', ['24rem'], 'dropdown_width', 'md'],
	'action group alignment'=>['Dataphyre\\Panel\\ActionGroup', 'dropdownAlignment', ['end'], 'dropdown_alignment', 'end'],
	'action group align start'=>['Dataphyre\\Panel\\ActionGroup', 'alignStart', [], 'dropdown_alignment', 'start'],
	'action group align center'=>['Dataphyre\\Panel\\ActionGroup', 'alignCenter', [], 'dropdown_alignment', 'center'],
	'action group align end'=>['Dataphyre\\Panel\\ActionGroup', 'alignEnd', [], 'dropdown_alignment', 'end'],
	'action group meta'=>['Dataphyre\\Panel\\ActionGroup', 'meta', [['probe'=>'actions']], 'meta.probe', 'actions'],

	'dashboard label'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'label', ['Today'], 'label', 'Today'],
	'dashboard description'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'description', ['Current day'], 'description', 'Current day'],
	'dashboard tone'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'tone', ['info'], 'tone', 'info'],
	'dashboard icon'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'icon', ['calendar'], 'icon', 'calendar'],
	'dashboard values'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'values', [['period'=>'today']], 'values.period', 'today'],
	'dashboard query alias'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'query', [['period'=>'week']], 'values.period', 'week'],
	'dashboard hidden'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'hide', [true], 'hidden', true],
	'dashboard sort'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'sort', [41], 'sort', 41],
	'dashboard meta'=>['Dataphyre\\Panel\\PanelDashboardFilterPreset', 'meta', [['probe'=>'dashboard']], 'meta.probe', 'dashboard'],

	'notification title'=>['Dataphyre\\Panel\\PanelNotificationItem', 'title', ['Alerts'], 'title', 'Alerts'],
	'notification message'=>['Dataphyre\\Panel\\PanelNotificationItem', 'message', ['Three alerts'], 'message', 'Three alerts'],
	'notification type'=>['Dataphyre\\Panel\\PanelNotificationItem', 'type', ['warning'], 'type', 'warning'],
	'notification icon'=>['Dataphyre\\Panel\\PanelNotificationItem', 'icon', ['bell'], 'icon', 'bell'],
	'notification url'=>['Dataphyre\\Panel\\PanelNotificationItem', 'url', ['/panel/alerts'], 'url', '/panel/alerts'],
	'notification count'=>['Dataphyre\\Panel\\PanelNotificationItem', 'count', [3], 'count', 3],
	'notification sort'=>['Dataphyre\\Panel\\PanelNotificationItem', 'sort', [51], 'sort', 51],
	'notification hidden'=>['Dataphyre\\Panel\\PanelNotificationItem', 'hide', [true], 'hidden', true],
	'notification meta'=>['Dataphyre\\Panel\\PanelNotificationItem', 'meta', [['probe'=>'notification']], 'meta.probe', 'notification'],

	'search label'=>['Dataphyre\\Panel\\PanelSearchProvider', 'label', ['Orders'], 'label', 'Orders'],
	'search description'=>['Dataphyre\\Panel\\PanelSearchProvider', 'description', ['Order search'], 'description', 'Order search'],
	'search icon'=>['Dataphyre\\Panel\\PanelSearchProvider', 'icon', ['search'], 'icon', 'search'],
	'search sort'=>['Dataphyre\\Panel\\PanelSearchProvider', 'sort', [61], 'sort', 61],
	'search limit'=>['Dataphyre\\Panel\\PanelSearchProvider', 'limit', [17], 'limit', 17],
	'search hidden'=>['Dataphyre\\Panel\\PanelSearchProvider', 'hide', [true], 'hidden', true],
	'search meta'=>['Dataphyre\\Panel\\PanelSearchProvider', 'meta', [['probe'=>'search']], 'meta.probe', 'search'],

	'filter label'=>['Dataphyre\\Panel\\TableFilter', 'label', ['Status'], 'label', 'Status'],
	'filter type'=>['Dataphyre\\Panel\\TableFilter', 'type', ['select'], 'type', 'select'],
	'filter range'=>['Dataphyre\\Panel\\TableFilter', 'range', ['range'], 'range', true],
	'filter date range'=>['Dataphyre\\Panel\\TableFilter', 'dateRange', [], 'type', 'date_range'],
	'filter number range'=>['Dataphyre\\Panel\\TableFilter', 'numberRange', [], 'type', 'number_range'],
	'filter column'=>['Dataphyre\\Panel\\TableFilter', 'column', ['state'], 'column', 'state'],
	'filter options'=>['Dataphyre\\Panel\\TableFilter', 'options', [['open'=>'Open']], 'options.open', 'Open'],
	'filter default'=>['Dataphyre\\Panel\\TableFilter', 'default', ['open'], 'default', 'open'],
	'filter visible'=>['Dataphyre\\Panel\\TableFilter', 'visible', [true], 'hidden', false],
	'filter hidden'=>['Dataphyre\\Panel\\TableFilter', 'hidden', [true], 'hidden', true],
	'filter visible on'=>['Dataphyre\\Panel\\TableFilter', 'visibleOn', ['index'], 'visible_on.0', 'index'],
	'filter hidden on'=>['Dataphyre\\Panel\\TableFilter', 'hiddenOn', ['edit'], 'hidden_on.0', 'edit'],
	'filter indicator'=>['Dataphyre\\Panel\\TableFilter', 'indicator', ['Active'], 'indicator', 'Active'],
	'filter indicator tone'=>['Dataphyre\\Panel\\TableFilter', 'indicatorTone', ['success'], 'indicator_tone', 'success'],
	'filter meta'=>['Dataphyre\\Panel\\TableFilter', 'meta', [['probe'=>'filter']], 'meta.probe', 'filter'],

	'group label'=>['Dataphyre\\Panel\\TableGroup', 'label', ['Channel'], 'label', 'Channel'],
	'group direction'=>['Dataphyre\\Panel\\TableGroup', 'direction', ['desc'], 'direction', 'desc'],
	'group default'=>['Dataphyre\\Panel\\TableGroup', 'default', [true], 'default', true],
	'group collapsible'=>['Dataphyre\\Panel\\TableGroup', 'collapsible', [true], 'collapsible', true],
	'group collapsed'=>['Dataphyre\\Panel\\TableGroup', 'collapsed', [true], 'collapsed', true],
	'group meta'=>['Dataphyre\\Panel\\TableGroup', 'meta', [['probe'=>'group']], 'meta.probe', 'group'],

	'summary label'=>['Dataphyre\\Panel\\TableSummary', 'label', ['Revenue'], 'label', 'Revenue'],
	'summary type'=>['Dataphyre\\Panel\\TableSummary', 'type', ['sum'], 'type', 'sum'],
	'summary column'=>['Dataphyre\\Panel\\TableSummary', 'column', ['amount'], 'column', 'amount'],
	'summary count'=>['Dataphyre\\Panel\\TableSummary', 'count', [], 'type', 'count'],
	'summary sum type'=>['Dataphyre\\Panel\\TableSummary', 'sum', ['amount'], 'type', 'sum'],
	'summary sum column'=>['Dataphyre\\Panel\\TableSummary', 'sum', ['amount'], 'column', 'amount'],
	'summary avg type'=>['Dataphyre\\Panel\\TableSummary', 'avg', ['amount'], 'type', 'avg'],
	'summary min type'=>['Dataphyre\\Panel\\TableSummary', 'min', ['amount'], 'type', 'min'],
	'summary max type'=>['Dataphyre\\Panel\\TableSummary', 'max', ['amount'], 'type', 'max'],
	'summary tone'=>['Dataphyre\\Panel\\TableSummary', 'tone', ['success'], 'tone', 'success'],
	'summary meta'=>['Dataphyre\\Panel\\TableSummary', 'meta', [['probe'=>'summary']], 'meta.probe', 'summary'],

	'view label'=>['Dataphyre\\Panel\\TableView', 'label', ['Open'], 'label', 'Open'],
	'view default'=>['Dataphyre\\Panel\\TableView', 'default', [true], 'default', true],
	'view tone'=>['Dataphyre\\Panel\\TableView', 'tone', ['primary'], 'tone', 'primary'],
	'view badge'=>['Dataphyre\\Panel\\TableView', 'badge', [9], 'badge', 9],
	'view query'=>['Dataphyre\\Panel\\TableView', 'query', [['status'=>'open']], 'query.status', 'open'],
	'view query default'=>['Dataphyre\\Panel\\TableView', 'queryDefault', ['page', 2], 'query.page', 2],
	'view preset alias'=>['Dataphyre\\Panel\\TableView', 'preset', ['density', 'compact'], 'query.density', 'compact'],
	'view search'=>['Dataphyre\\Panel\\TableView', 'search', ['urgent'], 'query.q', 'urgent'],
	'view columns'=>['Dataphyre\\Panel\\TableView', 'columns', ['id', 'status'], 'query.visible_columns.1', 'status'],
	'view visible columns'=>['Dataphyre\\Panel\\TableView', 'visibleColumns', ['id', 'total'], 'query.visible_columns.1', 'total'],
	'view filter value'=>['Dataphyre\\Panel\\TableView', 'filterValue', ['status', 'paid'], 'query.status', 'paid'],
	'view range from'=>['Dataphyre\\Panel\\TableView', 'range', ['total', 10, 100], 'query.total_from', 10],
	'view range to'=>['Dataphyre\\Panel\\TableView', 'range', ['total', 10, 100], 'query.total_to', 100],
	'view sort column'=>['Dataphyre\\Panel\\TableView', 'sort', ['created_at', 'desc'], 'query.sort', 'created_at'],
	'view sort direction'=>['Dataphyre\\Panel\\TableView', 'sort', ['created_at', 'desc'], 'query.dir', 'desc'],
	'view per page'=>['Dataphyre\\Panel\\TableView', 'perPage', [75], 'query.per_page', 75],
	'view density'=>['Dataphyre\\Panel\\TableView', 'density', ['compact'], 'query.density', 'compact'],
	'view meta'=>['Dataphyre\\Panel\\TableView', 'meta', [['probe'=>'view']], 'meta.probe', 'view'],

	'widget label'=>['Dataphyre\\Panel\\Widget', 'label', ['Revenue'], 'label', 'Revenue'],
	'widget type'=>['Dataphyre\\Panel\\Widget', 'type', ['chart'], 'type', 'chart'],
	'widget value'=>['Dataphyre\\Panel\\Widget', 'value', [42], 'value', 42],
	'widget description'=>['Dataphyre\\Panel\\Widget', 'description', ['Current revenue'], 'description', 'Current revenue'],
	'widget tone'=>['Dataphyre\\Panel\\Widget', 'tone', ['success'], 'tone', 'success'],
	'widget icon'=>['Dataphyre\\Panel\\Widget', 'icon', ['currency'], 'icon', 'currency'],
	'widget url'=>['Dataphyre\\Panel\\Widget', 'url', ['/panel/revenue'], 'url', '/panel/revenue'],
	'widget group'=>['Dataphyre\\Panel\\Widget', 'group', ['finance'], 'group', 'finance'],
	'widget sort'=>['Dataphyre\\Panel\\Widget', 'sort', [71], 'sort', 71],
	'widget chart'=>['Dataphyre\\Panel\\Widget', 'chart', ['bar'], 'meta.chart_type', 'bar'],
	'widget data'=>['Dataphyre\\Panel\\Widget', 'data', [[10, 20]], 'meta.data.1', 20],
	'widget labels'=>['Dataphyre\\Panel\\Widget', 'labels', [['Mon', 'Tue']], 'meta.labels.1', 'Tue'],
	'widget height'=>['Dataphyre\\Panel\\Widget', 'height', [320], 'meta.height', 320],
	'widget unit'=>['Dataphyre\\Panel\\Widget', 'unit', ['CAD'], 'meta.unit', 'CAD'],
	'widget meta'=>['Dataphyre\\Panel\\Widget', 'meta', [['probe'=>'widget']], 'meta.probe', 'widget'],
]);

test('simple panel builders serialize each fluent mutation', static function(Context $t, string $class, string $method, array $arguments, string $path, mixed $expected): void {
	$builder=$class::make('contract');
	$builder=$builder->{$method}(...$arguments);
	$t->same($expected, dp_panel_builder_contract_path($builder->toArray(), $path));
})->with('panel simple builder mutations')->tag('panel', 'builder', 'serialization')->maxMillis(1000);
