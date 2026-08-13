<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelUtilityResolver;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelUtilityFixture {
	public object $profile;
	public function __construct() {
		$this->profile=(object)['name'=>'Property Name'];
	}
	public function getOrderCode(): string {
		return 'getter-code';
	}
}

test('resource form private helpers cover records live state prefill uploads comparisons and grids',static function(Context $t): void {
	$t->same('array-value',$t->nonPublic(ResourceForm::class)->invoke('recordValue',['code'=>'array-value'],'code','default'));
	$t->same('default',$t->nonPublic(ResourceForm::class)->invoke('recordValue',[],'code','default'));
	$record=new class {
		public string $public_value='public-value';
		public function getOrderCode(): string { return 'getter-value'; }
	};
	$t->same('public-value',$t->nonPublic(ResourceForm::class)->invoke('recordValue',$record,'public_value','default'));
	$t->same('getter-value',$t->nonPublic(ResourceForm::class)->invoke('recordValue',$record,'order_code','default'));
	$t->same('default',$t->nonPublic(ResourceForm::class)->invoke('recordValue',$record,'missing','default'));
	$t->same('default',$t->nonPublic(ResourceForm::class)->invoke('recordValue',null,'missing','default'));

	$stateValues=['same'=>'value'];
	$serverValues=[];
	$unchanged=$t->nonPublic(ResourceForm::class)->capture(
		'applyResolvedStateValue',
		stateValues:$stateValues,
		serverValues:$serverValues,
		name:'same',
		value:'value',
		flags:['force_value'=>true],
	);
	$t->same(false,$unchanged->result());
	$t->same(['force_value'=>true,'propagate'=>false],$unchanged->argument('serverValues')['same']);
	$changed=$t->nonPublic(ResourceForm::class)->capture(
		'applyResolvedStateValue',
		stateValues:$unchanged->argument('stateValues'),
		serverValues:$unchanged->argument('serverValues'),
		name:'changed',
		value:'new',
		flags:['propagate'=>true],
	);
	$t->same(true,$changed->result());
	$t->same('new',$changed->argument('stateValues')['changed']);
	$t->same(['force_value'=>false,'propagate'=>true],$changed->argument('serverValues')['changed']);

	$t->same([],$t->nonPublic(ResourceForm::class)->invoke('prefillValues',PanelRequest::fromArray(['query'=>['prefill'=>'invalid']])));
	$prefill=PanelRequest::fromArray(['query'=>['prefill'=>[
		' First Name '=>'Ada','count'=>2,'nullable'=>null,'nested'=>['ignored'],'...'=>'ignored',
	]]]);
	$t->same(['first_name'=>'Ada','count'=>2,'nullable'=>null],$t->nonPublic(ResourceForm::class)->invoke('prefillValues',$prefill));

	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank','not-an-upload'));
	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',['name'=>'','error'=>UPLOAD_ERR_OK]));
	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',['name'=>'one.txt','error'=>UPLOAD_ERR_NO_FILE]));
	$t->same(false,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',['name'=>'one.txt','error'=>UPLOAD_ERR_OK]));
	$t->same(false,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',[
		'name'=>['','two.txt'],'error'=>[UPLOAD_ERR_NO_FILE,UPLOAD_ERR_OK],
	]));
	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',[
		'name'=>['',''],'error'=>[UPLOAD_ERR_NO_FILE,UPLOAD_ERR_NO_FILE],
	]));
	$t->same(false,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',[
		'name'=>['selected.txt'],'error'=>'not-an-array',
	]));
	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',[]));
	$t->same(false,$t->nonPublic(ResourceForm::class)->invoke('fileInputBlank',['unexpected'=>true]));

	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('valuesMatch',true,1));
	$t->same(false,$t->nonPublic(ResourceForm::class)->invoke('valuesMatch',false,1));
	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('valuesMatch',1,'1'));
	$t->same(true,$t->nonPublic(ResourceForm::class)->invoke('valuesMatch',['b'=>2,'a'=>['y'=>2,'x'=>1]],
		(object)['a'=>(object)['x'=>1,'y'=>2],'b'=>2],));
	$t->same(false,$t->nonPublic(ResourceForm::class)->invoke('valuesMatch',[1,2],[2,1]));
	$t->same('[1,2]',$t->nonPublic(ResourceForm::class)->invoke('stableValue',[1,2]));
	$stream=fopen('php://memory','r');
	$t->same('',$t->nonPublic(ResourceForm::class)->invoke('stableValue',$stream));
	fclose($stream);
	$t->same('scalar',$t->nonPublic(ResourceForm::class)->invoke('normalizeComparableValue','scalar'));

	$t->same([
		'default'=>1,'sm'=>2,'md'=>3,'lg'=>4,'xl'=>12,'2xl'=>6,
	],$t->nonPublic(ResourceForm::class)->invoke('normalizeGridColumns',[
		'base'=>0,'small'=>2,'medium'=>3,'large'=>4,'xl'=>20,'wide'=>6,'unsupported'=>8,
	]));
})->tag('panel','resource-form','coverage')->group('framework-coverage');

test('panel utility resolver covers named alias typed positional default nullable and fallback injection',static function(Context $t): void {
	$fixture=new DpPanelUtilityFixture();
	$result=PanelUtilityResolver::evaluate(
		static function($data,$row,DpPanelUtilityFixture $service,$legacy,$fallback='default',?string $optional=null): array {
			return [$data,$row,$service,$legacy,$fallback,$optional];
		},
		['data'=>['id'=>1],'record'=>['id'=>2],'fixture'=>$fixture,'position'=>'legacy-value'],
		[null,null,null,'position']
	);
	$t->same(['id'=>1],$result[0]);
	$t->same(['id'=>2],$result[1]);
	$t->same($fixture,$result[2]);
	$t->same('legacy-value',$result[3]);
	$t->same('default',$result[4]);
	$t->same(null,$result[5]);

	$callback=static fn(): string=>'callback-result';
	$t->same($callback,PanelUtilityResolver::evaluate(static fn(Closure $handler): Closure=>$handler,['callback'=>$callback]));
	$exception=new RuntimeException('resolver failure');
	$t->same($exception,PanelUtilityResolver::evaluate(static fn(Throwable $failure): Throwable=>$failure,['exception'=>$exception]));
	$date=new DateTimeImmutable('2026-07-11');
	$t->same($date,PanelUtilityResolver::evaluate(
		static fn(DpPanelUtilityFixture|DateTimeImmutable $typed): object=>$typed,
		['date'=>$date]
	));
	$t->same(null,PanelUtilityResolver::evaluate(static fn(?string $optional): ?string=>$optional,[]));
	$t->throws(
		static fn()=>PanelUtilityResolver::evaluate(static function(array $unresolved): void {},[]),
		TypeError::class
	);

	$request=PanelRequest::fromArray(['user'=>['id'=>17]]);
	$t->same(['id'=>17],PanelUtilityResolver::evaluate(static fn($user): mixed=>$user,['request'=>$request]));
	$utilities=PanelUtilityResolver::evaluate(
		static fn($get,$set,$meta): array=>[$get,$set,$meta],
		[]
	);
	$t->same([],($utilities[0])(null,[]));
	$t->same(null,($utilities[1])('field','value'));
	$t->same([],$utilities[2]);

	$t->same('exact',PanelUtilityResolver::utility('data',['data'=>'exact'],'default'));
	$t->same('alias',PanelUtilityResolver::utility('row',['record'=>'alias'],'default'));
	$t->same('default',PanelUtilityResolver::utility('missing',[],'default'));
})->tag('panel','utility-resolver','coverage')->group('framework-coverage');

test('panel utility resolver default getter traverses data records request input properties and getters',static function(Context $t): void {
	$fixture=new DpPanelUtilityFixture();
	$request=PanelRequest::fromArray(['input'=>['request_only'=>['value'=>'request-value']]]);
	$get=PanelUtilityResolver::utility('get',[
		'data'=>['nested'=>['value'=>'data-value']],
		'record'=>$fixture,
		'request'=>$request,
	]);
	$t->same(['nested'=>['value'=>'data-value']],$get(null,'default'));
	$t->same(['nested'=>['value'=>'data-value']],$get(' ','default'));
	$t->same('data-value',$get('nested.value','default'));
	$t->same('Property Name',$get('profile.name','default'));
	$t->same('getter-code',$get('order_code','default'));
	$t->same('request-value',$get('request_only.value','default'));
	$t->same('default',$get('missing.value','default'));

	$recordOnlyGet=PanelUtilityResolver::utility('get',['record'=>$fixture]);
	$t->same('default',$recordOnlyGet('missing','default'));
})->tag('panel','utility-resolver','coverage')->group('framework-coverage');
