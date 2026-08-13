<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelDashboardFilterPreset;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);
if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

/** @return array<string,mixed> */
function dp_panel_dashboard_filter_preset_export(PanelDashboardFilterPreset $preset,?PanelRequest $request=null,?PanelManager $manager=null): array {
	return PanelContext::run([
		'url_builder'=>static function(string $target,array $query): string {
			$suffix=http_build_query($query,'','&',PHP_QUERY_RFC3986);
			return '/panel'.($suffix!=='' ? '?'.$suffix : '');
		},
	],static fn(): array=>$preset->toArray($request,$manager));
}

/** @return array<string,mixed> */
function dp_panel_dashboard_filter_preset_query(string $url): array {
	$query=[];
	parse_str((string)(parse_url($url,PHP_URL_QUERY) ?? ''),$query);
	return $query;
}

test('panel dashboard filter preset builds immutable static definitions and safe manager urls',static function(Context $t): void {
	$fromArray=PanelDashboardFilterPreset::fromArray([
		'name'=>'review-queue',
		'label'=>' Review queue ',
		'description'=>' Needs attention ',
		'tone'=>'warning',
		'icon'=>' alert-triangle ',
		'values'=>['status'=>'pending'],
		'query'=>['status'=>'review','owner'=>'me'],
		'sort'=>12,
		'hidden'=>1,
		'meta'=>['source'=>'definition'],
	]);
	$fromArrayData=dp_panel_dashboard_filter_preset_export($fromArray);
	$t->same('review-queue',$fromArray->name());
	$t->same('Review queue',$fromArrayData['label']);
	$t->same('Needs attention',$fromArrayData['description']);
	$t->same('warning',$fromArrayData['tone']);
	$t->same('alert-triangle',$fromArrayData['icon']);
	$t->same(['status'=>'review','owner'=>'me'],$fromArrayData['values']);
	$t->same(12,$fromArrayData['sort']);
	$t->same(true,$fromArrayData['hidden']);
	$t->same('definition',$fromArrayData['meta']['source']);
	$t->same(false,$fromArray->isVisible());

	$base=PanelDashboardFilterPreset::make('quick-range');
	$configured=$base
		->label(' Quick range ')
		->description(' Current period ')
		->tone('PRIMARY')
		->icon(' calendar ')
		->values(['period'=>'month'])
		->query(['period'=>'week'])
		->hide(false)
		->sort(25)
		->meta(['first'=>1,'override'=>'first'])
		->meta(['second'=>2,'override'=>'second']);
	$baseData=dp_panel_dashboard_filter_preset_export($base);
	$configuredData=dp_panel_dashboard_filter_preset_export($configured);
	$t->same('Quick Range',$baseData['label']);
	$t->same([],$baseData['values']);
	$t->same('Quick range',$configuredData['label']);
	$t->same('Current period',$configuredData['description']);
	$t->same('primary',$configuredData['tone']);
	$t->same('calendar',$configuredData['icon']);
	$t->same(['period'=>'week'],$configuredData['values']);
	$t->same(25,$configuredData['sort']);
	$t->same(false,$configuredData['hidden']);
	$t->same(1,$configuredData['meta']['first']);
	$t->same(2,$configuredData['meta']['second']);
	$t->same('second',$configuredData['meta']['override']);
	$t->same(null,dp_panel_dashboard_filter_preset_export($configured->description(''))['description']);
	$t->same(null,dp_panel_dashboard_filter_preset_export($configured->icon(''))['icon']);
	$t->same('neutral',dp_panel_dashboard_filter_preset_export($configured->tone('unsupported'))['tone']);
	$t->same('',PanelDashboardFilterPreset::make(' ')->name());
	$t->same('',dp_panel_dashboard_filter_preset_export(PanelDashboardFilterPreset::make(' '))['label']);

	$resource=fopen('php://memory','r');
	try{
		$normalized=$base->values([
			' Status '=>'open',
			'empty'=>null,
			'false'=>false,
			'zero'=>0,
			''=>'ignored',
			'array'=>['ignored'],
			'object'=>new stdClass(),
			'resource'=>$resource,
		]);
		$normalizedData=dp_panel_dashboard_filter_preset_export($normalized);
		$t->same('open',$normalizedData['values']['status']);
		$t->same(null,$normalizedData['values']['empty']);
		$t->same(false,$normalizedData['values']['false']);
		$t->same(0,$normalizedData['values']['zero']);
		$t->same(false,array_key_exists('array',$normalizedData['values']));
		$t->same(false,array_key_exists('object',$normalizedData['values']));
		$t->same(false,array_key_exists('resource',$normalizedData['values']));
	}
	finally {
		fclose($resource);
	}

	$request=PanelRequest::fromArray(['query'=>[
		'resource'=>'orders',
		'operation'=>'index',
		'record'=>'7',
		'relation'=>'items',
		'action'=>'review',
		'page'=>4,
		'status'=>'stale',
		'status_from'=>'old-start',
		'status_to'=>'old-end',
		'keep'=>'yes',
	]]);
	$manager=new PanelManager();
	$preset=$base->values(['status'=>'open']);
	$data=dp_panel_dashboard_filter_preset_export($preset,$request,$manager);
	$query=dp_panel_dashboard_filter_preset_query($data['url']);
	$t->same(false,array_key_exists('resource',$query));
	$t->same(false,array_key_exists('operation',$query));
	$t->same(false,array_key_exists('page',$query));
	$t->same(false,array_key_exists('status_from',$query));
	$t->same(false,array_key_exists('status_to',$query));
	$t->same('open',$query['status']);
	$t->same('yes',$query['keep']);
	$t->same(false,$data['current']);
	$t->same(true,dp_panel_dashboard_filter_preset_export($preset,PanelRequest::fromArray(['query'=>['status'=>'open']]),$manager)['current']);
	$t->same(false,dp_panel_dashboard_filter_preset_export($preset,PanelRequest::fromArray(['query'=>['status'=>'closed']]),$manager)['current']);
	$t->same(true,dp_panel_dashboard_filter_preset_export($base,PanelRequest::fromArray(['query'=>[]]),$manager)['current']);
	$t->same(true,$preset->isVisible($request,$manager));
})->tag('panel','dashboard-filter-preset','coverage')->group('framework-coverage');

test('panel dashboard filter preset resolves lazy values visibility and custom current state',static function(Context $t): void {
	$request=PanelRequest::fromArray(['query'=>['status'=>'open']]);
	$manager=new PanelManager();
	$calls=[];
	$preset=PanelDashboardFilterPreset::make('lazy')
		->values(static function(?PanelRequest $resolvedRequest,PanelDashboardFilterPreset $resolvedPreset,?PanelManager $resolvedManager)use(&$calls): array {
			$calls[]=['values',$resolvedRequest,$resolvedPreset->name(),$resolvedManager];
			return [' Status '=>'open','invalid'=>['array']];
		})
		->visibleUsing(static function(?PanelRequest $resolvedRequest,PanelDashboardFilterPreset $resolvedPreset,?PanelManager $resolvedManager)use(&$calls): bool {
			$calls[]=['visible',$resolvedRequest,$resolvedPreset->name(),$resolvedManager];
			return true;
		})
		->currentUsing(static function(?PanelRequest $resolvedRequest,PanelDashboardFilterPreset $resolvedPreset,?PanelManager $resolvedManager,array $values)use(&$calls): bool {
			$calls[]=['current',$resolvedRequest,$resolvedPreset->name(),$resolvedManager,$values];
			return ($values['status'] ?? null)==='open';
		});
	$t->same(true,$preset->isVisible($request,$manager));
	$data=dp_panel_dashboard_filter_preset_export($preset,$request,$manager);
	$t->same(['status'=>'open'],$data['values']);
	$t->same(true,$data['current']);
	$t->same(true,$data['values_lazy']);
	$t->same(true,$data['visible_lazy']);
	$t->same(true,$data['current_lazy']);
	$t->same('values',$calls[1][0]);
	$t->same($request,$calls[1][1]);
	$t->same($manager,$calls[1][3]);
	$t->same('current',$calls[2][0]);

	$notVisible=$preset->visibleUsing(static fn(): bool=>false);
	$t->same(false,$notVisible->isVisible($request,$manager));
	$nonArray=$preset->query(static fn(): string=>'not an array');
	$t->same([],$nonArrayData=dp_panel_dashboard_filter_preset_export($nonArray,$request,$manager)['values']);
	$t->same(false,$nonArrayData!==[]);
	$customFalse=$preset->currentUsing(static fn(): bool=>false);
	$t->same(false,dp_panel_dashboard_filter_preset_export($customFalse,$request,$manager)['current']);
})->tag('panel','dashboard-filter-preset','coverage')->group('framework-coverage');

test('panel dashboard filter preset traces resolver failures and covers private url guards',static function(Context $t): void {
	PanelTrace::flush();
	$request=PanelRequest::fromArray(['query'=>['empty'=>'stale','null'=>'stale','scalar'=>'old','keep'=>'yes']]);
	$manager=new PanelManager();
	$visibilityFailure=PanelDashboardFilterPreset::make('visibility-failure')->visibleUsing(
		static function(): never { throw new RuntimeException('visibility exploded'); }
	);
	$t->same(false,$visibilityFailure->isVisible($request,$manager));

	$valuesFailure=PanelDashboardFilterPreset::make('values-failure')->values(
		static function(): never { throw new RuntimeException('values exploded'); }
	);
	$valuesFailureData=dp_panel_dashboard_filter_preset_export($valuesFailure,$request,$manager);
	$t->same([],$valuesFailureData['values']);

	$currentFailure=PanelDashboardFilterPreset::make('current-failure')
		->values(['status'=>'open'])
		->currentUsing(static function(): never { throw new RuntimeException('current exploded'); });
	$t->same(false,dp_panel_dashboard_filter_preset_export($currentFailure,$request,$manager)['current']);

	$events=PanelTrace::events();
	$names=array_values(array_map(static fn(array $event): string=>(string)($event['event'] ?? ''),$events));
	$t->contains('dashboard_filter_preset.visibility_error',$names);
	$t->contains('dashboard_filter_preset.values_error',$names);
	$t->contains('dashboard_filter_preset.current_error',$names);
	$t->same(true,count(array_filter($events,static fn(array $event): bool=>str_contains((string)($event['context']['message'] ?? ''),'exploded')))>=3);

	$url=PanelContext::run([
		'url_builder'=>static fn(string $target,array $query): string=>'/panel?'.http_build_query($query),
	],static fn(): string=>$t->nonPublic(
		PanelDashboardFilterPreset::make('private-url')
	)->invoke(
		'url',
		[
			''=>'invalid key',
			'empty'=>'   ',
			'null'=>null,
			'scalar'=>'new',
			'array'=>['ignored'],
		],
		$request,
		null
	));
	$query=dp_panel_dashboard_filter_preset_query($url);
	$t->same(false,array_key_exists('empty',$query));
	$t->same(false,array_key_exists('null',$query));
	$t->same('new',$query['scalar']);
	$t->same(false,array_key_exists('array',$query));
	$t->same('yes',$query['keep']);
})->tag('panel','dashboard-filter-preset','coverage')->group('framework-coverage');
