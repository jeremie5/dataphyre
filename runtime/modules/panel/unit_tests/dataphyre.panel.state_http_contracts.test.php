<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelLifecycleResult;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return mixed */
function dp_panel_state_contract_path(array $data, string $path): mixed {
	$value=$data;
	foreach(explode('.', $path) as $segment){
		if(!is_array($value) || !array_key_exists($segment, $value)){
			return null;
		}
		$value=$value[$segment];
	}
	return $value;
}

dataset('panel request normalization contracts', [
	'uppercase method'=>[['method'=>'post'], 'method', [], 'POST'],
	'blank method'=>[['method'=>'  '], 'method', [], 'GET'],
	'resource name'=>[['resource'=>'Sales Orders'], 'resourceName', [], 'sales_orders'],
	'blank resource'=>[['resource'=>''], 'resourceName', [], null],
	'list operation'=>[['operation'=>'list'], 'operation', [], 'index'],
	'table operation'=>[['operation'=>'table'], 'operation', [], 'index'],
	'new operation'=>[['operation'=>'new'], 'operation', [], 'create'],
	'save operation'=>[['operation'=>'save'], 'operation', [], 'store'],
	'custom operation'=>[['operation'=>'status board'], 'operation', [], 'status_board'],
	'record alias'=>[['record_key'=>42], 'recordKey', [], '42'],
	'blank record'=>[['record'=>'  '], 'recordKey', [], null],
	'action name'=>[['action'=>'Approve Order'], 'actionName', [], 'approve_order'],
	'relation name'=>[['relation'=>'Line Items'], 'relationName', [], 'line_items'],
	'query value'=>[['query'=>['status'=>'open']], 'query', ['status'], 'open'],
	'query default'=>[['query'=>[]], 'query', ['missing', 'fallback'], 'fallback'],
	'input value'=>[['input'=>['title'=>'Order']], 'input', ['title'], 'Order'],
	'input default'=>[['input'=>[]], 'input', ['missing', 'fallback'], 'fallback'],
	'file value'=>[['files'=>['receipt'=>['name'=>'a.pdf']]], 'file', ['receipt'], ['name'=>'a.pdf']],
	'file default'=>[['files'=>[]], 'file', ['receipt', 'none'], 'none'],
	'header case'=>[['headers'=>['X-Panel-Test'=>'yes']], 'header', ['x_panel_test'], 'yes'],
	'header arrays'=>[['headers'=>['Accept'=>['text/html', 'application/json']]], 'header', ['accept'], 'text/html, application/json'],
	'explicit tenant'=>[['tenant'=>'north'], 'tenantKey', [], 'north'],
	'tenant alias'=>[['tenant_key'=>'south'], 'tenant', [], 'south'],
	'tenant query'=>[['query'=>['tenant'=>'east']], 'tenant', [], 'east'],
	'tenant input'=>[['input'=>['tenant'=>'west']], 'tenant', [], 'west'],
	'tenant header'=>[['headers'=>['X-Panel-Tenant'=>'central']], 'tenant', [], 'central'],
	'user context'=>[['user'=>['id'=>7]], 'user', [], ['id'=>7]],
	'page normal'=>[['query'=>['page'=>3]], 'page', [], 3],
	'page lower clamp'=>[['query'=>['page'=>-5]], 'page', [], 1],
	'per page normal'=>[['query'=>['per_page'=>75]], 'perPage', [], 75],
	'per page lower clamp'=>[['query'=>['per_page'=>0]], 'perPage', [], 1],
	'per page upper clamp'=>[['query'=>['per_page'=>999]], 'perPage', [], 250],
]);

test('panel requests normalize route and transport values', static function(Context $t, array $data, string $method, array $arguments, mixed $expected): void {
	$request=PanelRequest::fromArray($data);
	$t->same($expected, $request->{$method}(...$arguments));
})->with('panel request normalization contracts')->tag('panel', 'request', 'normalization')->maxMillis(1000);

dataset('panel request partial detection contracts', [
	'modal header'=>[['headers'=>['X-Requested-With'=>'DataphyrePanelModal']], 'isPanelModalRequest', true],
	'modal query and panel header'=>[['headers'=>['X-Requested-With'=>'DataphyrePanel'], 'query'=>['__panel_partial'=>'modal']], 'isPanelModalRequest', true],
	'modal query without panel header'=>[['query'=>['__panel_partial'=>'modal']], 'isPanelModalRequest', false],
	'fragment header'=>[['headers'=>['X-Requested-With'=>'DataphyrePanel']], 'isPanelFragmentRequest', true],
	'fragment explicit header'=>[['headers'=>['X-Requested-With'=>'DataphyrePanelFragment']], 'isPanelFragmentRequest', true],
	'fragment input'=>[['headers'=>['X-Requested-With'=>'DataphyrePanel'], 'input'=>['__panel_partial'=>'fragment']], 'isPanelFragmentRequest', true],
	'ordinary ajax'=>[['headers'=>['X-Requested-With'=>'XMLHttpRequest']], 'isPanelFragmentRequest', false],
	'field options hyphen rejected'=>[['query'=>['__panel_partial'=>'field-options']], 'isPanelFieldOptionsRequest', false],
	'field options input'=>[['input'=>['__panel_partial'=>'field_options']], 'isPanelFieldOptionsRequest', true],
	'field state hyphen rejected'=>[['query'=>['__panel_partial'=>'field-state']], 'isPanelFieldStateRequest', false],
	'field state input'=>[['input'=>['__panel_partial'=>'field_state']], 'isPanelFieldStateRequest', true],
	'field state missing'=>[[], 'isPanelFieldStateRequest', false],
]);

test('panel requests detect each partial transport explicitly', static function(Context $t, array $data, string $method, bool $expected): void {
	$t->same($expected, PanelRequest::fromArray($data)->{$method}());
})->with('panel request partial detection contracts')->tag('panel', 'request', 'partial')->maxMillis(1000);

dataset('panel request immutable mutation contracts', [
	'merge query'=>['query_merge', 'query.status', 'closed'],
	'preserve query'=>['query_merge', 'query.page', 2],
	'replace query'=>['query_replace', 'query.status', null],
	'replace query value'=>['query_replace', 'query.page', 4],
	'add query value'=>['query_value', 'query.sort', 'total'],
	'remove query value'=>['query_remove', 'query.status', null],
	'with tenant'=>['tenant', 'tenant', 'north'],
	'clear tenant'=>['tenant_clear', 'tenant', null],
]);

test('panel request mutations return isolated query and tenant snapshots', static function(Context $t, string $mutation, string $path, mixed $expected): void {
	$base=PanelRequest::fromArray(['query'=>['page'=>2, 'status'=>'open'], 'tenant'=>'south']);
	$result=match($mutation){
		'query_merge'=>$base->withQuery(['status'=>'closed']),
		'query_replace'=>$base->withQuery(['page'=>4], true),
		'query_value'=>$base->withQueryValue('sort', 'total'),
		'query_remove'=>$base->withQueryValue('status', null),
		'tenant'=>$base->withTenant('north'),
		'tenant_clear'=>$base->withTenant('  '),
	};
	$t->same($expected, dp_panel_state_contract_path($result->toArray(), $path));
	$t->same('open', $base->query('status'));
	$t->same('south', $base->tenant());
})->with('panel request immutable mutation contracts')->tag('panel', 'request', 'immutable')->maxMillis(1000);

dataset('panel form state read contracts', [
	'value'=>['value', ['status'], 'closed'],
	'value default'=>['value', ['missing', 'fallback'], 'fallback'],
	'field errors'=>['fieldErrors', ['status'], ['Invalid status']],
	'valid false'=>['valid', [], false],
	'invalid true'=>['invalid', [], true],
	'mode'=>['mode', [], 'edit'],
	'operation'=>['operation', [], 'update'],
	'initial value'=>['initialValue', ['status'], 'open'],
	'raw values'=>['rawValues', [], ['status'=>' CLOSED ']],
	'dehydrated value'=>['dehydratedValue', ['status'], 'closed'],
	'dirty'=>['dirty', [], true],
	'dirty fields'=>['dirtyFields', [], ['status']],
	'is dirty field'=>['isDirty', ['status'], true],
	'is dirty other'=>['isDirty', ['title'], false],
	'state updates'=>['stateUpdates', ['status'], ['visible'=>true]],
	'server values'=>['serverValues', [], ['slug'=>'order-1']],
]);

test('panel form state exposes normalized values errors and lifecycle metadata', static function(Context $t, string $method, array $arguments, mixed $expected): void {
	$state=PanelFormState::make(
		['status'=>'closed', 'title'=>'Order'],
		['status'=>' Invalid status ', 'empty'=>'  '],
		[
			'mode'=>'edit', 'operation'=>'update',
			'initial_values'=>['status'=>'open'],
			'raw_values'=>['status'=>' CLOSED '],
			'dehydrated_values'=>['status'=>'closed'],
			'dirty_fields'=>[' status ', ''],
			'state_updates'=>['status'=>['visible'=>true]],
			'server_values'=>['slug'=>'order-1'],
		]
	);
	$t->same($expected, $state->{$method}(...$arguments));
})->with('panel form state read contracts')->tag('panel', 'form_state', 'read')->maxMillis(1000);

dataset('panel form state mutation contracts', [
	'with value'=>['withValue', ['status', 'paid'], 'values.status', 'paid'],
	'with values merge'=>['withValues', [['status'=>'paid']], 'values.title', 'Order'],
	'with values replace'=>['withValues', [['status'=>'paid'], false], 'values.title', null],
	'with error'=>['withError', ['title', 'Required'], 'errors.title.0', 'Required'],
	'with errors merge'=>['withErrors', [['title'=>'Required']], 'errors.status.0', 'Invalid'],
	'with errors replace'=>['withErrors', [['title'=>'Required'], false], 'errors.status.0', null],
	'without field error'=>['withoutError', ['status'], 'errors.status.0', null],
	'without all errors'=>['withoutError', [], 'valid', true],
	'with meta merge'=>['withMeta', [['probe'=>'yes']], 'meta.mode', 'edit'],
	'with meta replace'=>['withMeta', [['probe'=>'yes'], false], 'meta.mode', null],
	'only values'=>['only', [['status']], 'values.title', null],
	'only errors'=>['only', [['title']], 'errors.status.0', null],
	'except values'=>['except', [['status']], 'values.status', null],
	'except preserves'=>['except', [['status']], 'values.title', 'Order'],
]);

test('panel form state mutations remain immutable and scoped', static function(Context $t, string $method, array $arguments, string $path, mixed $expected): void {
	$base=PanelFormState::make(['status'=>'open', 'title'=>'Order'], ['status'=>'Invalid'], ['mode'=>'edit']);
	$changed=$base->{$method}(...$arguments);
	$t->same($expected, dp_panel_state_contract_path($changed->jsonSerialize(), $path));
	$t->same('open', $base->value('status'));
	$t->same(['Invalid'], $base->fieldErrors('status'));
})->with('panel form state mutation contracts')->tag('panel', 'form_state', 'immutable')->maxMillis(1000);

dataset('panel lifecycle result contracts', [
	'halt flag'=>['halt', [' Stop ', [], 422, ['id'=>1]], 'halted', true],
	'halt message'=>['halt', [' Stop ', [], 422, null], 'message', 'Stop'],
	'halt low clamp'=>['halt', ['Stop', [], 200, null], 'status', 400],
	'halt high clamp'=>['halt', ['Stop', [], 700, null], 'status', 599],
	'halt payload'=>['halt', ['Stop', [], 422, ['id'=>1]], 'payload.id', 1],
	'redirect target'=>['redirect', [' /panel/orders ', '', [], 303, null], 'redirect_to', '/panel/orders'],
	'redirect low clamp'=>['redirect', ['/panel', '', [], 200, null], 'status', 300],
	'redirect high clamp'=>['redirect', ['/panel', '', [], 500, null], 'status', 399],
	'redirect not halted'=>['redirect', ['/panel'], 'halted', false],
	'notify message'=>['notify', ['Saved'], 'message', 'Saved'],
	'notify success'=>['notify', ['Saved'], 'status', 200],
	'notify halt'=>['notify', ['Blocked', true], 'halted', true],
	'notify halt status'=>['notify', ['Blocked', true], 'status', 422],
]);

test('panel lifecycle factories clamp status and preserve intent', static function(Context $t, string $factory, array $arguments, string $path, mixed $expected): void {
	$result=PanelLifecycleResult::{$factory}(...$arguments);
	$t->same($expected, dp_panel_state_contract_path($result->jsonSerialize(), $path));
})->with('panel lifecycle result contracts')->tag('panel', 'lifecycle', 'result')->maxMillis(1000);

dataset('panel page result contracts', [
	'html status'=>['html', ['<main>OK</main>', 201], 'status', 201],
	'html content'=>['html', ['<main>OK</main>'], '@content', '<main>OK</main>'],
	'html content type'=>['html', ['OK'], 'headers.Content-Type', 'text/html; charset=utf-8'],
	'csv content type'=>['csv', ['a,b', 'report.csv'], 'headers.Content-Type', 'text/csv; charset=utf-8'],
	'csv filename sanitized'=>['csv', ['a,b', '../May Report?.csv'], 'headers.Content-Disposition', 'attachment; filename="..-May-Report-.csv"'],
	'json download type'=>['jsonDownload', [['ok'=>true], 'report.json'], 'headers.Content-Type', 'application/json; charset=utf-8'],
	'json response status'=>['json', [['ok'=>true], 202], 'status', 202],
	'json custom header'=>['json', [['ok'=>true], 200, ['X-Probe'=>'yes']], 'headers.X-Probe', 'yes'],
	'redirect status'=>['redirect', ['/panel/orders'], 'status', 303],
	'redirect target'=>['redirect', ['/panel/orders'], 'redirect_to', '/panel/orders'],
	'redirect unsafe scheme'=>['redirect', ['data:text/html,bad'], 'redirect_to', '#'],
	'redirect protocol relative'=>['redirect', ['//evil.example'], 'redirect_to', '#'],
]);

test('panel page result factories preserve safe response contracts', static function(Context $t, string $factory, array $arguments, string $path, mixed $expected): void {
	$result=PanelPageResult::{$factory}(...$arguments);
	$actual=$path==='@content' ? $result->content() : dp_panel_state_contract_path($result->jsonSerialize(), $path);
	$t->same($expected, $actual);
})->with('panel page result contracts')->tag('panel', 'http', 'result')->maxMillis(1000);
