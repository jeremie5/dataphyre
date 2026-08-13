<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once __DIR__.'/panel_test_probes.php';

use Dataphyre\Http\Request as HttpRequest;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\TestFixtures\PanelRequestHeadersProbe;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','http']);

if(!function_exists('getallheaders')){
	function getallheaders(): mixed {
		return PanelRequestHeadersProbe::capturedHeaders();
	}
}

test('panel request normalizes arrays accessors immutable mutations and partial transports',static function(Context $t): void {
	$file=['name'=>'report.csv','type'=>'text/csv','tmp_name'=>'C:/tmp/report.csv','size'=>42,'error'=>UPLOAD_ERR_OK];
	$request=PanelRequest::fromArray([
		'method'=>' patch ',
		'resource'=>'Sales Orders',
		'operation'=>'list',
		'record_key'=>91,
		'relation'=>'Line Items',
		'action'=>'Approve Order',
		'query'=>['page'=>0,'per_page'=>999,'status'=>'open','__panel_partial'=>'fragment'],
		'input'=>['title'=>' Example '],
		'files'=>['report'=>$file,'invalid'=>'not-an-upload'],
		'headers'=>['X_REQUESTED_WITH'=>' DataphyrePanel ','X-Multi'=>[' one ','two '],''=>'ignored'],
		'tenant_key'=>' North ',
		'user'=>['id'=>7],
	]);
	$t->same('PATCH',$request->method());
	$t->same('sales_orders',$request->resourceName());
	$t->same('index',$request->operation());
	$t->same('91',$request->recordKey());
	$t->same('line_items',$request->relationName());
	$t->same('approve_order',$request->actionName());
	$t->same('open',$request->query('status'));
	$t->same('missing',$request->query('absent','missing'));
	$t->same(4,count($request->query()));
	$t->same(' Example ',$request->input('title'));
	$t->same(['title'=>' Example '],$request->input());
	$t->same($file,$request->file('report'));
	$t->same($request->files(),$request->file());
	$t->same('fallback',$request->files('missing','fallback'));
	$t->same('DataphyrePanel',$request->header('X-Requested-With'));
	$t->same('one, two',$request->headers('x_multi'));
	$t->same('fallback',$request->headers(' ','fallback'));
	$t->same(2,count($request->headers()));
	$t->isTrue($request->isPanelFragmentRequest());
	$t->isFalse($request->isPanelModalRequest());
	$t->same(['id'=>7],$request->user());
	$t->same('North',$request->tenantKey());
	$t->same('North',$request->tenant());
	$t->same(1,$request->page());
	$t->same(250,$request->perPage());

	$merged=$request->withQuery(['status'=>'closed','new'=>'yes']);
	$t->same('closed',$merged->query('status'));
	$t->same('yes',$merged->query('new'));
	$t->same('open',$request->query('status'));
	$replaced=$request->withQuery(['only'=>true],true);
	$t->same(['only'=>true],$replaced->query());
	$t->same($request,$request->withQueryValue('   ',1));
	$t->same('active',$request->withQueryValue('Current View','active')->query('current_view'));
	$t->same('fallback',$request->withQueryValue('status',null)->query('status','fallback'));
	$t->same(null,$request->withTenant(' ')->tenant());
	$t->same('south',$request->withTenant(' south ')->tenant());
	$user=(object)['id'=>9];
	$t->same($user,$request->withUser($user)->user());

	$serialized=$request->toArray();
	$t->same('fragment',$serialized['partial']);
	$t->same(['name'=>'report.csv','type'=>'text/csv','size'=>42,'error'=>UPLOAD_ERR_OK],$serialized['files']['report']);
	$t->isFalse(array_key_exists('invalid',$serialized['files']));

	$modalHeader=PanelRequest::fromArray(['headers'=>['x-requested-with'=>'DataphyrePanelModal']]);
	$t->isTrue($modalHeader->isPanelModalRequest());
	$t->same('modal',$modalHeader->toArray()['partial']);
	$modalPartial=PanelRequest::fromArray(['input'=>['__panel_partial'=>'Modal'],'headers'=>['x-requested-with'=>'DataphyrePanelForm']]);
	$t->isTrue($modalPartial->isPanelModalRequest());
	$fragmentHeader=PanelRequest::fromArray(['headers'=>['x-requested-with'=>'DataphyrePanelFragment']]);
	$t->isTrue($fragmentHeader->isPanelFragmentRequest());
	$fragmentPartial=PanelRequest::fromArray(['input'=>['__panel_partial'=>'fragment'],'headers'=>['x-requested-with'=>'DataphyrePanelForm']]);
	$t->isTrue($fragmentPartial->isPanelFragmentRequest());
	$t->isTrue(PanelRequest::fromArray(['query'=>['__panel_partial'=>'Field Options']])->isPanelFieldOptionsRequest());
	$t->isTrue(PanelRequest::fromArray(['input'=>['__panel_partial'=>'field_state']])->isPanelFieldStateRequest());

	$t->same('GET',PanelRequest::fromArray(['method'=>' ','query'=>'invalid','input'=>'invalid','files'=>'invalid','headers'=>'invalid'])->method());
	$t->same(null,PanelRequest::fromArray(['resource'=>[],'record'=>[],'relation'=>new stdClass(),'action'=>[]])->resourceName());
	$t->same('create',PanelRequest::fromArray(['operation'=>'new'])->operation());
	$t->same('store',PanelRequest::fromArray(['operation'=>'save'])->operation());
	$t->same('custom_operation',PanelRequest::fromArray(['operation'=>'Custom Operation'])->operation());
	$t->same(1,PanelRequest::fromArray(['query'=>['per_page'=>0]])->perPage());
	$t->same(17,PanelRequest::fromArray([])->perPage(17));
	$t->same('query-tenant',PanelRequest::fromArray(['query'=>['tenant'=>'query-tenant']])->tenant());
	$t->same('input-tenant',PanelRequest::fromArray(['input'=>['tenant'=>'input-tenant']])->tenant());
	$t->same('header-tenant',PanelRequest::fromArray(['headers'=>['X-Panel-Tenant'=>'header-tenant']])->tenant());
})->tag('panel','request','coverage')->group('framework-coverage');

test('panel request captures superglobals headers uploads action state and inferred defaults',static function(Context $t): void {
	$headers=PanelRequestHeadersProbe::reset($t)->returnHeaders(['X-From-Api'=>' api ','X-List'=>['one',' two ']]);
	$get=$t->globalMap('_GET')->replace([
			'resource'=>'Orders','operation'=>'action','record'=>' 42 ','relation'=>'ignored','action'=>'Approve',
			'page'=>2,
		]);
	$post=$t->globalMap('_POST')->replace(['reason'=>'Valid']);
	$files=$t->globalMap('_FILES')->replace(['attachment'=>['name'=>'proof.txt','type'=>'text/plain','tmp_name'=>'C:/tmp/proof','size'=>5,'error'=>0]]);
	$server=$t->globalMap('_SERVER')->replace([
			'REQUEST_METHOD'=>'PATCH','HTTP_X_SERVER_HEADER'=>' server ','CONTENT_TYPE'=>'application/json',
			'CONTENT_LENGTH'=>'123','UNRELATED'=>'ignored',17=>'numeric-key',
		]);
	$action=PanelRequest::capture();
	$t->same('PATCH',$action->method());
	$t->same('orders',$action->resourceName());
	$t->same('action',$action->operation());
	$t->same('42',$action->recordKey());
	$t->same('approve',$action->actionName());
	$t->same(null,$action->relationName());
	$t->same('api',$action->header('x-from-api'));
	$t->same('server',$action->header('x-server-header'));
	$t->same('application/json',$action->header('content-type'));
	$t->same('one, two',$action->header('x-list'));
	$t->same('proof.txt',$action->toArray()['files']['attachment']['name']);

	$get->replace(['resource'=>'Orders']);
	$post->replace(['title'=>'New']);
	$files->clear();
	$server->replace(['REQUEST_METHOD'=>'POST']);
	$headers->returnHeaders([]);
	$store=PanelRequest::capture();
	$t->same('store',$store->operation());
	$t->same('POST',$store->method());
})->tag('panel','request','coverage')->group('framework-coverage');

test('panel request adapts HTTP routes effective methods segments options tenant and user',static function(Context $t): void {
	$explicit=HttpRequest::create(
		'POST','/panel/orders/42',
		['resource'=>'query-resource','operation'=>'query-operation','record'=>'query-record'],
		['_method'=>'PATCH','title'=>'Updated'],[],[],
		['X-Panel-Tenant'=>'header-tenant'],
		[
			'custom_resource'=>'Orders','custom_operation'=>'update','custom_record'=>'42',
			'custom_relation'=>'Items','custom_action'=>'Approve','custom_tenant'=>'route-tenant',
		],
		['user'=>['id'=>1]],
		['attachment'=>['name'=>'proof.txt','type'=>'text/plain','tmp_name'=>'C:/tmp/proof','size'=>5,'error'=>0]],
	);
	$adapted=PanelRequest::fromHttpRequest($explicit,[
		'infer_segments'=>false,
		'resource_parameters'=>['custom_resource'],
		'operation_parameters'=>'custom_operation',
		'record_parameters'=>['custom_record'],
		'relation_parameters'=>['custom_relation'],
		'action_parameters'=>['custom_action'],
		'tenant_parameters'=>['custom_tenant'],
		'user'=>['id'=>99],
	]);
	$t->same('PATCH',$adapted->method());
	$t->same('orders',$adapted->resourceName());
	$t->same('update',$adapted->operation());
	$t->same('42',$adapted->recordKey());
	$t->same('items',$adapted->relationName());
	$t->same('approve',$adapted->actionName());
	$t->same('route-tenant',$adapted->tenant());
	$t->same(['id'=>99],$adapted->user());
	$t->same('Updated',$adapted->input('title'));
	$t->notEmpty($adapted->files());

	$operationAction=PanelRequest::fromHttpRequest(HttpRequest::create(
		'GET','/panel/orders/action/approve/42',[],[],[],[],[],
		['panel_segments'=>['orders','action','approve','42']],
	));
	$t->same('orders',$operationAction->resourceName());
	$t->same('action',$operationAction->operation());
	$t->same('approve',$operationAction->actionName());
	$t->same('42',$operationAction->recordKey());

	$operationRelation=PanelRequest::fromHttpRequest(HttpRequest::create(
		'GET','/panel/orders/relation/42/items',[],[],[],[],[],
		['panel_segments'=>'/orders/relation/42/items/'],
	));
	$t->same('relation',$operationRelation->operation());
	$t->same('42',$operationRelation->recordKey());
	$t->same('items',$operationRelation->relationName());

	$recordAction=PanelRequest::fromHttpRequest(HttpRequest::create(
		'GET','/panel/orders/42/action/approve',[],[],[],[],[],
		['segments'=>['orders','42','action','approve']],
	));
	$t->same('action',$recordAction->operation());
	$t->same('42',$recordAction->recordKey());
	$t->same('approve',$recordAction->actionName());

	$recordRelation=PanelRequest::fromHttpRequest(HttpRequest::create(
		'GET','/panel/orders/42/relation/items',[],[],[],[],[],
		['path'=>'orders/42/relation/items'],
	));
	$t->same('relation',$recordRelation->operation());
	$t->same('items',$recordRelation->relationName());

	$show=PanelRequest::fromHttpRequest(HttpRequest::create('GET','/panel/orders/42',[],[],[],[],[],['panel_segments'=>['orders','42']]));
	$t->same('show',$show->operation());
	$t->same('42',$show->recordKey());
	$index=PanelRequest::fromHttpRequest(HttpRequest::create('GET','/panel/orders',[],[],[],[],[],['panel_segments'=>['orders']]));
	$t->same('index',$index->operation());
	$fallback=PanelRequest::fromHttpRequest(HttpRequest::create('POST','/panel',['resource'=>'query orders'],[],[],[],[],[]));
	$t->same('query_orders',$fallback->resourceName());
	$t->same('store',$fallback->operation());
})->tag('panel','request','coverage')->group('framework-coverage');

test('panel request private normalization helpers cover every return shape',static function(Context $t): void {
	$t->same([
		'x-one'=>'a, b','content-type'=>'application/json',
	],$t->nonPublic(PanelRequest::class)->invoke('normalizeHeaders',[
		' X_ONE '=>[' a ','b '],'Content_Type'=>' application/json ','   '=>'ignored',
	]));
	$t->same('x-custom-header',$t->nonPublic(PanelRequest::class)->invoke('normalizeHeaderName',' X_Custom_Header '));
	$t->same('X-Custom-Header',$t->nonPublic(PanelRequest::class)->invoke('serverHeaderName','X_CUSTOM_HEADER'));
	$t->same([
		'upload'=>['name'=>'file.txt','type'=>null,'size'=>null,'error'=>null],
	],$t->nonPublic(PanelRequest::class)->invoke('filesSummary',[
		'upload'=>['name'=>'file.txt'],'invalid'=>'text',
	]));

	$t->same(['orders','42'],$t->nonPublic(PanelRequest::class)->invoke('routeSegments',[
		'custom'=>['orders',' ','42'],
	],['segments_parameters'=>[12,'missing','custom']]));
	$t->same(['orders','42','action','approve'],$t->nonPublic(PanelRequest::class)->invoke('routeSegments',[
		'path'=>'/orders/42/action/approve/',
	],[]));
	$t->same([],$t->nonPublic(PanelRequest::class)->invoke('routeSegments',['path'=>'///'],[]));
	$t->same([],$t->nonPublic(PanelRequest::class)->invoke('routeSegments',[],[]));

	$t->same([],$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',[' ','']));
	$operation=$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',['orders','create']);
	$t->same('create',$operation['operation']);
	$action=$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',['orders','action','approve','42']);
	$t->same('approve',$action['action']);
	$t->same('42',$action['record']);
	$relation=$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',['orders','relation','42','items']);
	$t->same('items',$relation['relation']);
	$recordAction=$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',['orders','42','action','approve']);
	$t->same('approve',$recordAction['action']);
	$recordRelation=$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',['orders','42','relation','items']);
	$t->same('items',$recordRelation['relation']);
	$default=$t->nonPublic(PanelRequest::class)->invoke('inferRouteSegments',['orders','42','custom']);
	$t->same('custom',$default['operation']);

	$t->same('value',$t->nonPublic(PanelRequest::class)->invoke('firstRouteValue',[
		'empty'=>'','null'=>null,'match'=>'value',
	],[12,'empty','null','match'],'fallback'));
	$t->same('fallback',$t->nonPublic(PanelRequest::class)->invoke('firstRouteValue',[],['missing'],'fallback'));
	foreach(['list'=>'index','table'=>'index','new'=>'create','save'=>'store','Custom Operation'=>'custom_operation',''=>'index'] as $raw=>$normalized){
		$t->same($normalized,$t->nonPublic(PanelRequest::class)->invoke('normalizeOperation',$raw));
	}
	$t->same(null,$t->nonPublic(PanelRequest::class)->invoke('optionalName',[1]));
	$t->same(null,$t->nonPublic(PanelRequest::class)->invoke('optionalName',' '));
	$t->same('sales_orders',$t->nonPublic(PanelRequest::class)->invoke('optionalName','Sales Orders'));
	$t->same('42',$t->nonPublic(PanelRequest::class)->invoke('optionalName',42));
	$t->same(null,$t->nonPublic(PanelRequest::class)->invoke('optionalString',[1]));
	$t->same(null,$t->nonPublic(PanelRequest::class)->invoke('optionalString',' '));
	$t->same('42',$t->nonPublic(PanelRequest::class)->invoke('optionalString',42));
	$t->same('value',$t->nonPublic(PanelRequest::class)->invoke('optionalString',' value '));
})->tag('panel','request','coverage')->group('framework-coverage');
