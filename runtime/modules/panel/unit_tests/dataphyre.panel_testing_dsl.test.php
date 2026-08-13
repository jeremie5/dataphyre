<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\Testing\PanelTestKit;
use Dataphyre\Test\Context;
use Dataphyre\Test\GeneratedCases;
use Dataphyre\Test\Generators;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel', 'http']);
require_once dirname(__DIR__).'/testing/bootstrap.php';

suite('Panel TestKit semantic journeys')
	->contract('panel.testing.semantic-journey', 1)
	->layer(TestLayer::Contract)
	->risk(TestRisk::High)
	->watches('module:panel', 'module:http', 'extension:panel')
	->through('testkit.extension', 'panel.test-harness', 'panel.request', 'panel.manager')
	->isolation(TestIsolation::Process)
	->tag('panel', 'testing', 'dsl')
	->group('framework-coverage');

/** @return list<array{id:string,title:string,status:string}> */
function dp_panel_testing_dsl_records(): array {
	return [
		['id'=>'100', 'title'=>'Order 100', 'status'=>'pending'],
		['id'=>'200', 'title'=>'Order 200', 'status'=>'pending'],
		['id'=>'300', 'title'=>'Order 300', 'status'=>'approved'],
	];
}

function dp_panel_testing_dsl_resource(?Action $action=null): Resource {
	$resource=Resource::make('testkit_orders')
		->recordKeyUsing('id')
		->queryUsing(dp_panel_testing_dsl_records(...))
		->columns([
			Column::make('id')->label('ID'),
			Column::make('title')->label('Title'),
			Column::make('status')->label('Status'),
		])
		->bulkField(Field::make('status')->required())
		->bulkUpdateUsing(static fn(array $data, array $selected): array=>[
			'updated'=>count($selected),
			'status'=>$data['status'] ?? null,
		])
		->statusTransition('approve', 'approved', 'Approve', 'pending', 'success')
		->transitionUsing(static fn(array $transition, array $record): array=>[
			'transitioned'=>true,
			'id'=>$record['id'] ?? null,
			'to'=>$transition['to'] ?? null,
		]);
	return $action instanceof Action ? $resource->action($action) : $resource;
}

$ambientIdentity=Generators::shape([
	'resource'=>Generators::element(['stale_resource', 'testkit_orders', '0', '']),
	'operation'=>Generators::element(['show', 'edit', 'action', 'bulk_export']),
	'record'=>Generators::nullable(Generators::element(['stale-record', '100', '0'])),
	'action'=>Generators::nullable(Generators::element(['stale_action', 'approve_selected'])),
	'relation'=>Generators::nullable(Generators::element(['stale_relation', 'items'])),
	'page'=>Generators::integer(1, 500),
]);
dataset(
	'Panel pretty-route precedence property shards',
	Generators::cases($ambientIdentity, count:64, seed:20260714, kind:'panel-pretty-route-precedence')->shards(8),
);

test('a module extension exposes one typed Panel kit per test context', static function(Context $t): void {
	$explicit=$t->extension('panel', PanelTestKit::class);
	$fluent=$t->panel();

	$t->same($explicit, $fluent);
	$t->instanceOf(PanelTestKit::class, $fluent);
});

test('bulk update replaces stale route identity while preserving unrelated view state', static function(Context $t): void {
	$t->panel()
		->registerResource(dp_panel_testing_dsl_resource())
		->underStaleRouteIdentity(['view'=>'queue'])
		->bulkUpdate('testkit_orders', ['100', '200'])
		->returningTo('/panel/testkit-orders?view=queue')
		->expectIdentity([
			'resource'=>'testkit_orders',
			'operation'=>'bulk_update',
			'record'=>null,
			'action'=>null,
			'relation'=>null,
		])
		->expectQuery(['view'=>'queue'])
		->expectStatus(200)
		->expectData(['kind'=>'bulk_update_form', 'selected_count'=>2])
		->expectContentContains(
			'__panel_bulk_update_submit',
			'name="selected[]" value="100"',
			'name="selected[]" value="200"',
		);
});

test('pretty-route identity dominates every generated ambient page identity', static function(Context $t, GeneratedCases $ambientCases): void {
	$panel=$t->panel()->registerResource(dp_panel_testing_dsl_resource());
	$t->fuzz(
		$ambientCases,
		static function(Context $t, array $ambient) use ($panel): void {
			$panel->underAmbientQuery($ambient)
				->bulkUpdate('testkit_orders', ['100'])
				->expectIdentity([
					'resource'=>'testkit_orders',
					'operation'=>'bulk_update',
					'record'=>null,
					'action'=>null,
					'relation'=>null,
				])
				->expectQuery(['page'=>$ambient['page']])
				->expectStatus(200);
		},
	);
})->with('Panel pretty-route precedence property shards');

test('bulk transition expresses selection redirect and grouped outcomes without request plumbing', static function(Context $t): void {
	$returnTo='/panel/testkit-orders?view=queue';
	$t->panel()
		->registerResource(dp_panel_testing_dsl_resource())
		->underStaleRouteIdentity(['view'=>'queue'])
		->bulkTransition('testkit_orders', 'approve', ['100', '200'])
		->returningTo($returnTo)
		->expectIdentity(['resource'=>'testkit_orders', 'operation'=>'bulk_transition'])
		->expectQuery(['transition'=>'approve', 'view'=>'queue'])
		->expectRedirect($returnTo)
		->expectData([
			'kind'=>'bulk_transition',
			'transitioned'=>['100', '200'],
			'unavailable'=>[],
			'failed'=>[],
			'denied'=>[],
		])
		->expectNotificationCount(1);
});

test('native bulk export and confirmed custom action retain distinct observable contracts', static function(Context $t): void {
	$panel=$t->panel()->underStaleRouteIdentity();
	$panel->registerResource(dp_panel_testing_dsl_resource());
	$panel->bulkExport('testkit_orders', ['100', '200'])
		->expectIdentity(['resource'=>'testkit_orders', 'operation'=>'bulk_export'])
		->expectStatus(200)
		->expectHeader('Content-Type', 'text/csv; charset=utf-8')
		->expectData(['kind'=>'bulk_export', 'selected_count'=>2])
		->expectContentContains('Order 100', 'Order 200')
		->expectContentMissing('Order 300');

	$received=[];
	$action=Action::make('approve_selected')
		->bulk()
		->requiresConfirmation()
		->handle(static function(array $selected) use (&$received): array {
			$received=array_column($selected, 'id');
			return ['message'=>'Approved '.count($selected).' selected records'];
		});
	$actions=$t->panel()->underStaleRouteIdentity();
	$actions->registerResource(dp_panel_testing_dsl_resource($action));
	$actions->confirmedBulkAction('testkit_orders', 'approve_selected', ['100', '200'])
		->returningTo('/panel/testkit-orders')
		->expectIdentity([
			'resource'=>'testkit_orders',
			'operation'=>'action',
			'action'=>'approve_selected',
			'record'=>null,
		])
		->expectRedirect('/panel/testkit-orders')
		->expectData(['action_state.mode'=>'bulk_action', 'action_state.selected_count'=>2]);
	$t->same(['100', '200'], $received);
});
