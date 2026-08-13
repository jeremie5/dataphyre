<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	if(!class_exists(__NAMESPACE__.'\\PanelManager',false)){
		final class PanelManager {
			/** @param array<string,mixed> $filters */
			public function __construct(private array $filters=[]) {}
			/** @return array<string,mixed> */
			public function dashboardFilters(): array { return $this->filters; }
		}
	}
}

namespace {
	use Dataphyre\Panel\PanelContext;
	use Dataphyre\Panel\PanelDashboardFilterPreset;
	use Dataphyre\Panel\PanelManager;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel']);
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	test('panel dashboard filter preset supports managers exposing dashboard filter registries',static function(Context $t): void {
		$manager=new PanelManager([
			'status'=>['type'=>'select'],
			'created'=>['type'=>'date_range'],
		]);
		$clear=PanelDashboardFilterPreset::make('clear');
		$t->same(false,$clear->toArray(PanelRequest::fromArray(['query'=>['status'=>'open']]),$manager)['current']);
		$t->same(false,$clear->toArray(PanelRequest::fromArray(['query'=>['created_from'=>'2026-01-01']]),$manager)['current']);
		$t->same(true,$clear->toArray(PanelRequest::fromArray(['query'=>['keep'=>'yes']]),$manager)['current']);

		$request=PanelRequest::fromArray(['query'=>[
			'status'=>'stale',
			'created_from'=>'2026-01-01',
			'created_to'=>'2026-01-31',
			'keep'=>'yes',
		]]);
		$data=PanelContext::run([
			'url_builder'=>static fn(string $target,array $query): string=>'/panel?'.http_build_query($query),
		],static fn(): array=>PanelDashboardFilterPreset::make('open')->values(['status'=>'open'])->toArray($request,$manager));
		$query=[];
		parse_str((string)(parse_url($data['url'],PHP_URL_QUERY) ?? ''),$query);
		$t->same('open',$query['status']);
		$t->same(false,array_key_exists('created_from',$query));
		$t->same(false,array_key_exists('created_to',$query));
		$t->same('yes',$query['keep']);
	})->tag('panel','dashboard-filter-preset','coverage')->group('framework-coverage');
}
