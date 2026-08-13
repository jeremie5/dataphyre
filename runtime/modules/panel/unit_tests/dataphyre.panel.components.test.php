<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
require_once dirname(__DIR__).'/Framework/Bootstrap.php';

/** Returns a compact JSON assertion surface for component manifests. */
function dp_panel_component_json(mixed $value): string {
	return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

test('documentation media and infolist value objects expose stable manifests', static function(Context $t): void {
	$entry=\Dataphyre\Panel\PanelDocumentationEntry::make('getting_started', 'Getting started');
	$catalog=\Dataphyre\Panel\PanelDocumentationCatalog::make([$entry]);
	$collection=\Dataphyre\Panel\PanelMediaCollection::make('product_images')->disk('public')->path('products')->minSize(1024)->maxSize(2048);
	$item=new \Dataphyre\Panel\PanelMediaItem(['name'=>'hero.jpg', 'path'=>'products/hero.jpg', 'disk'=>'public']);
	$library=\Dataphyre\Panel\PanelMediaLibrary::make([$collection]);
	$infolist=\Dataphyre\Panel\InfolistEntry::make('status', 'badge')->label('Status');
	$section=\Dataphyre\Panel\FormSection::make('details')->label('Details')->columns(2);

	$t->contains('getting_started', dp_panel_component_json($entry));
	$t->same('panel_documentation_catalog', $catalog->manifest()['type'] ?? null);
	$t->contains('product_images', dp_panel_component_json($collection));
	$t->contains('1 KB', dp_panel_component_json($collection->validate(['name'=>'tiny.jpg', 'size'=>512])));
	$t->contains('2 KB', dp_panel_component_json($collection->validate(['name'=>'large.jpg', 'size'=>4096])));
	$t->contains('hero.jpg', dp_panel_component_json($item));
	$t->contains('product_images', dp_panel_component_json($library));
	$t->same('status', $infolist->toArray()['name'] ?? null);
	$t->same('details', $section->toArray()['name'] ?? null);
})->tag('panel', 'components', 'documentation', 'media')->maxMillis(1000);

test('navigation table and support components normalize public state', static function(Context $t): void {
	$components=[
		\Dataphyre\Panel\NavigationCluster::make('sales')->label('Sales')->toArray(),
		\Dataphyre\Panel\PanelMenuItem::make('profile')->label('Profile')->url('/panel/profile')->toArray(),
		\Dataphyre\Panel\PanelTenant::make('north')->label('North')->toArray(),
		\Dataphyre\Panel\ActionGroup::make('review')->label('Review')->toArray(),
		\Dataphyre\Panel\PanelDashboardFilterPreset::make('today')->label('Today')->toArray(),
		\Dataphyre\Panel\PanelNotificationItem::make('alerts')->title('Alerts')->message('Ready')->toArray(),
		\Dataphyre\Panel\PanelSearchProvider::make('orders')->label('Orders')->toArray(),
		\Dataphyre\Panel\PageTable::make('orders')->toArray(),
		\Dataphyre\Panel\TableFilter::make('status')->toArray(),
		\Dataphyre\Panel\TableGroup::make('channel')->toArray(),
		\Dataphyre\Panel\TableSummary::make('total')->toArray(),
		\Dataphyre\Panel\TableView::make('open')->toArray(),
	];
	$json=dp_panel_component_json($components);

	foreach(['sales', 'profile', 'north', 'review', 'today', 'alerts', 'orders', 'status', 'channel', 'total', 'open'] as $name){
		$t->contains($name, $json);
	}
	$t->same('halted', \Dataphyre\Panel\PanelLifecycleResult::halt('halted')->message());
	$t->same([], \Dataphyre\Panel\PanelInfolistState::make()->entries());
	$t->contains('sales', dp_panel_component_json(\Dataphyre\Panel\PanelWidgetState::fromResolved(['name'=>'sales', 'type'=>'stat'])));
})->tag('panel', 'components', 'navigation', 'tables')->maxMillis(1000);

test('operations and package read models retain deterministic summaries', static function(Context $t): void {
	$job=\Dataphyre\Panel\PanelDataJob::export('orders')->items([1, 2])->chunkSize(1)->metadata(['tenant'=>'north']);
	$result=\Dataphyre\Panel\PanelDataJobResult::make(['id'=>'job-1', 'type'=>'export', 'name'=>'orders', 'status'=>'completed', 'total'=>2, 'processed'=>2, 'succeeded'=>2]);
	$repository=\Dataphyre\Panel\PanelPackageRepository::make(['sample']);
	$lock=\Dataphyre\Panel\PanelPackageLock::fromRepository($repository);
	$apply=\Dataphyre\Panel\PanelPackageApplyResult::make(['ok'=>true, 'package'=>['id'=>'sample'], 'written'=>[], 'skipped'=>[], 'backups'=>[], 'blocked'=>[]]);
	$rollback=\Dataphyre\Panel\PanelPackageRollbackPlan::fromApplyResult($apply);
	$policy=\Dataphyre\Panel\PanelPackageTrustPolicy::make();
	$report=new \Dataphyre\Panel\PanelPackageTrustReport([['id'=>'sample', 'trusted'=>true]], ['trusted'=>1, 'blocked'=>0]);

	$t->same(2, $job->plan()['total'] ?? null);
	$t->same('completed', $result->status());
	$t->same('panel_package_repository', $repository->manifest()['type'] ?? null);
	$t->contains('sample', dp_panel_component_json($lock));
	$t->same(true, $apply->ok());
	$t->same('panel_package_rollback_plan', $rollback->manifest()['type'] ?? null);
	$t->same('panel_package_trust_policy', $policy->toArray()['type'] ?? null);
	$t->same(true, $report->ok());
})->tag('panel', 'components', 'operations', 'packages')->maxMillis(1000);

test('scaffolding widgets plugins and browser manifests expose contracts', static function(Context $t): void {
	$scaffolder=\Dataphyre\Panel\PanelScaffolder::make();
	$resource=$scaffolder->resource('App\\Panel\\OrderResource');
	$explicit=\Dataphyre\Panel\PanelScaffoldResult::make('page', 'reports', 'App\\Panel\\ReportsPage', 'src/ReportsPage.php', '<?php');
	$widget=\Dataphyre\Panel\Widget::make('sales', 'stat')->label('Sales')->value(42);
	$widgetManifest=\Dataphyre\Panel\WidgetManifest::from($widget)->toArray();
	$browser=\Dataphyre\Panel\PanelBrowserRegressionManifest::make('orders', '/panel/orders', ['viewport'=>['width'=>1280, 'height'=>800]]);

	$t->same('resource', $resource->kind());
	$t->same('reports', $explicit->name());
	$t->same('sales', $widget->toArray()['name'] ?? null);
	$t->same('widget_manifest', $widgetManifest['type'] ?? null);
	$t->same('panel_browser_regression_manifest', $browser->toArray()['type'] ?? null);
	$t->same(true, interface_exists(\Dataphyre\Panel\PanelPlugin::class));
	$t->same(true, interface_exists(\Dataphyre\Panel\PanelProvider::class));
	$t->same(true, class_exists(\Dataphyre\Panel\PluginManifest::class));
	$t->same(true, class_exists(\dataphyre\panel::class, false));
})->tag('panel', 'components', 'scaffolding', 'widgets')->maxMillis(1000);
