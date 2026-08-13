<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Action;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Field;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel','http']);
require_once dirname(__DIR__).'/testing/bootstrap.php';

suite('Panel pretty-route bulk operation lifecycle')
	->contract('panel.bulk.pretty-route-lifecycle', 1)
	->layer(TestLayer::Integration)
	->risk(TestRisk::Critical)
	->watches('module:panel', 'module:http', 'symbol:PanelRouteParser::infer')
	->through('http.request', 'panel.request', 'panel.route-parser', 'panel.manager', 'panel.response')
	->isolation(TestIsolation::File)
	->tag('panel', 'http', 'bulk', 'pretty-route')
	->group('framework-coverage');

/** @return list<array{id:string,title:string,status:string}> */
function dp_panel_pretty_bulk_server_records(): array {
	return [
		['id'=>'100','title'=>'Order 100','status'=>'pending'],
		['id'=>'200','title'=>'Order 200','status'=>'pending'],
		['id'=>'300','title'=>'Order 300','status'=>'approved'],
	];
}

function dp_panel_pretty_bulk_server_resource(?Action $action=null): Resource {
	$records=dp_panel_pretty_bulk_server_records();
	$resource=Resource::make('server_lifecycle_orders')
		->label('Server lifecycle order')
		->pluralLabel('Server lifecycle orders')
		->recordKeyUsing('id')
		->queryUsing(static fn(): array=>$records)
		->columns([
			Column::make('id')->label('ID'),
			Column::make('title')->label('Title'),
			Column::make('status')->label('Status'),
		])
		->bulkField(Field::make('status')->label('Status')->required())
		->bulkUpdateUsing(static fn(array $data,array $selected): array=>[
			'updated'=>count($selected),
			'status'=>$data['status'] ?? null,
		])
		->statusTransition('approve','approved','Approve','pending','success')
		->transitionUsing(static fn(array $transition,array $record): array=>[
			'transitioned'=>true,
			'id'=>$record['id'] ?? null,
			'to'=>$transition['to'] ?? null,
		]);
	return $action instanceof Action ? $resource->action($action) : $resource;
}

test('pretty bulk update route replaces stale page identity and returns the selected-record form',static function(Context $t): void {
	$t->panel()
		->registerResource(dp_panel_pretty_bulk_server_resource())
		->underStaleRouteIdentity(['view'=>'queue'])
		->bulkUpdate('server_lifecycle_orders', ['100', '200'])
		->returningTo('/panel/server-lifecycle-orders?view=queue')
		->asModal()
		->expectIdentity([
			'resource'=>'server_lifecycle_orders',
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

test('pretty bulk transition route approves every selected record and redirects to its table',static function(Context $t): void {
	$returnTo='/panel/server-lifecycle-orders?view=queue';
	$t->panel()
		->registerResource(dp_panel_pretty_bulk_server_resource())
		->underStaleRouteIdentity(['view'=>'queue'])
		->bulkTransition('server_lifecycle_orders', 'approve', ['100', '200'])
		->returningTo($returnTo)
		->asModal()
		->expectIdentity(['resource'=>'server_lifecycle_orders', 'operation'=>'bulk_transition'])
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

test('pretty bulk export route returns a native CSV attachment for only the selected records',static function(Context $t): void {
	$t->panel()
		->registerResource(dp_panel_pretty_bulk_server_resource())
		->underStaleRouteIdentity()
		->bulkExport('server_lifecycle_orders', ['100', '200'])
		->expectIdentity(['resource'=>'server_lifecycle_orders', 'operation'=>'bulk_export'])
		->expectStatus(200)
		->expectHeader('Content-Type', 'text/csv; charset=utf-8')
		->expectHeaderContains('Content-Disposition', 'attachment; filename="server_lifecycle_orders-selected-')
		->expectData(['kind'=>'bulk_export', 'selected_count'=>2])
		->expectContentContains(
			'Order 100',
			'Order 200',
		)
		->expectContentMissing('Order 300');
});

test('pretty confirmed custom bulk action receives the selected records instead of stale action identity',static function(Context $t): void {
	$received=[];
	$action=Action::make('approve_selected')
		->label('Approve selected')
		->bulk()
		->requiresConfirmation()
		->handle(static function(array $selected) use (&$received): array {
			$received=array_map(static fn(array $record): string=>(string)$record['id'],$selected);
			return ['message'=>'Approved '.count($selected).' selected records'];
		});
	$returnTo='/panel/server-lifecycle-orders?view=queue';
	$t->panel()
		->registerResource(dp_panel_pretty_bulk_server_resource($action))
		->underStaleRouteIdentity(['view'=>'queue'])
		->confirmedBulkAction('server_lifecycle_orders', 'approve_selected', ['100', '200'])
		->returningTo($returnTo)
		->asModal()
		->expectIdentity([
			'resource'=>'server_lifecycle_orders',
			'operation'=>'action',
			'action'=>'approve_selected',
			'record'=>null,
		])
		->expectRedirect($returnTo)
		->expectData(['action_state.mode'=>'bulk_action', 'action_state.selected_count'=>2]);
	$t->same(['100', '200'], $received);
});
