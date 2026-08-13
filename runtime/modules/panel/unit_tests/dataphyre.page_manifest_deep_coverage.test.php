<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\PageManifest;
use Dataphyre\Panel\PageTable;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPage;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel', 'permission']);

test('page manifest covers live children invalid entries and action failure fallback', static function(Context $t): void {
	$request=PanelRequest::fromArray(['operation'=>'view', 'tenant'=>'north', 'user'=>['id'=>7]]);
	$page=PanelPage::make('operations-center')
		->action(Action::make('refresh')->label('Refresh'))
		->action(Action::make('broken')->label(static function(): never {
			throw new RuntimeException('Dynamic action label failed.');
		}))
		->widget(Widget::make('live-widget')->label('Live widget')->value(12))
		->table(PageTable::make('live-table')->label('Live table'))
		->meta(['definition'=>'kept']);

	$builder=PageManifest::from($page, $request, PanelManager::instance(), [
		'surface'=>'deep-coverage',
	]);
	$manifest=$builder->toArray();
	$definition=$page->toArray();
	$actions=$page->actionsList();
	$actions['invalid']=new stdClass();
	$t->nonPublic($page)->writeProperty('actions',$actions);
	$widgets=$page->widgetsList();
	$widgets['invalid']=new stdClass();
	$t->nonPublic($page)->writeProperty('widgets',$widgets);
	$tables=$page->tablesList();
	$tables['invalid']=new stdClass();
	$t->nonPublic($page)->writeProperty('tables',$tables);

	$filteredActions=$t->nonPublic($builder)->invoke('actions',$definition);
	$filteredWidgets=$t->nonPublic($builder)->invoke('widgets',$definition);
	$filteredTables=$t->nonPublic($builder)->invoke('tables',$definition);

	$t->same('page_manifest', $manifest['type']);
	$t->same('operations-center', $manifest['name']);
	$t->same('Refresh', $manifest['actions']['refresh']['presentation']['label'] ?? null);
	$t->same('Dynamic action label failed.', $manifest['actions']['broken']['error'] ?? null);
	$t->same(2, count($manifest['actions']));
	$t->same(1, count($manifest['widgets']));
	$t->same(1, count($manifest['tables']));
	$t->same(2, count($filteredActions));
	$t->same(1, count($filteredWidgets));
	$t->same(1, count($filteredTables));
	$t->same('deep-coverage', $manifest['meta']['surface'] ?? null);
	$t->same('kept', $manifest['meta']['definition'] ?? null);
	$t->same(2, $manifest['permission']['counts']['actions'] ?? null);
})->tag('panel', 'page', 'manifest', 'coverage')->group('framework-coverage');

test('page manifest covers serialized fallbacks capabilities permissions navigation and defaults', static function(Context $t): void {
	$manifest=PageManifest::from([
		'name'=>'sales-report',
		'url'=>'/reports/sales',
		'group'=>'Reports',
		'icon'=>'chart',
		'sort'=>20,
		'hidden_from_navigation'=>true,
		'navigation_description'=>'Sales performance',
		'navigation_badge'=>0,
		'navigation_badge_lazy'=>true,
		'navigation_badge_tone'=>'info',
		'renders'=>true,
		'has_content'=>true,
		'authorizes'=>true,
		'actions'=>[
			'not-an-array',
			[
				'name'=>'approve',
				'label'=>'Approve report',
				'type'=>'custom_action',
				'fields'=>['fields'=>[['name'=>'reason']]],
				'modal'=>true,
				'bulk'=>true,
				'effects'=>['refresh'=>['report', 'summary'], 'events'=>['report.approved']],
			],
			['name'=>'...'],
		],
		'widgets'=>[
			42,
			[
				'name'=>'trend',
				'type'=>'chart',
				'lazy'=>true,
				'url'=>'/reports/sales/trend',
				'meta'=>['chart_type'=>'bar', 'datasets'=>[['label'=>'Sales', 'data'=>[1, 2, 3]]]],
			],
		],
		'tables'=>[
			new stdClass(),
			[
				'name'=>'orders',
				'columns'=>[['name'=>'total'], ['name'=>'region']],
				'filters'=>[['name'=>'status']],
				'views'=>[['name'=>'open']],
			],
		],
		'meta'=>['source'=>'definition', 'overridden'=>'definition'],
	], null, null, [
		'overridden'=>'caller',
		'surface'=>'serialized',
	])->toArray();

	$t->same('Sales Report', $manifest['label']);
	$t->same('/reports/sales', $manifest['navigation']['url']);
	$t->isTrue($manifest['navigation']['hidden']);
	$t->isTrue($manifest['navigation']['badge_lazy']);
	$t->isTrue($manifest['rendering']['custom_renderer']);
	$t->isTrue($manifest['rendering']['has_static_content']);
	$t->isTrue($manifest['rendering']['authorizes']);
	$t->same('custom_action', $manifest['actions']['approve']['kind'] ?? null);
	$t->isTrue($manifest['actions']['approve']['interaction']['has_form'] ?? false);
	$t->same(2, $manifest['actions']['approve']['effects']['refresh_count'] ?? null);
	$t->same(1, $manifest['actions']['approve']['effects']['event_count'] ?? null);
	$t->same(null, $manifest['actions']['approve']['error'] ?? null);
	$t->same(2, count($manifest['actions']));
	$t->same(1, count($manifest['widgets']));
	$t->same(1, count($manifest['tables']));
	$t->same(2, $manifest['capabilities']['actions']['total']);
	$t->same(1, $manifest['capabilities']['actions']['forms']);
	$t->same(1, $manifest['capabilities']['actions']['modals']);
	$t->same(3, $manifest['capabilities']['actions']['effects']);
	$t->same(1, $manifest['capabilities']['widgets']['lazy']);
	$t->same(1, $manifest['capabilities']['widgets']['linked']);
	$t->same(1, $manifest['capabilities']['widgets']['charts']);
	$t->same(2, $manifest['capabilities']['tables']['columns']);
	$t->same(1, $manifest['capabilities']['tables']['filters']);
	$t->same(1, $manifest['capabilities']['tables']['views']);
	$t->same(1, $manifest['permission']['counts']['actions']);
	$t->same(2, $manifest['permission']['counts']['total']);
	$t->same('sales-report', $manifest['permission']['page']);
	$t->same('definition', $manifest['meta']['source']);
	$t->same('caller', $manifest['meta']['overridden']);
	$t->same('serialized', $manifest['meta']['surface']);

	$blank=PageManifest::from(['name'=>'', 'actions'=>[], 'widgets'=>[], 'tables'=>[]])->toArray();
	$t->same('Page', $blank['label']);
	$t->same('', $blank['permission']['page']);
})->tag('panel', 'page', 'manifest', 'coverage')->group('framework-coverage');
