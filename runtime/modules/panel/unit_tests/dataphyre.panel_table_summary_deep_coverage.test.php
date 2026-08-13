<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\Resource;
use Dataphyre\Panel\TableSummary;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel table summary imports manifests and fluent aggregate aliases',static function(Context $t): void {
	$summary=TableSummary::fromArray([
		'name'=>'gross-total','type'=>'sum','label'=>'Gross total','column'=>'order_amount',
		'tone'=>'success','meta'=>['currency'=>'CAD'],
	]);
	$manifest=$summary->toArray();
	$t->same('gross-total',$summary->name());
	$t->same('sum',$manifest['type']);
	$t->same('Gross total',$manifest['label']);
	$t->same('order_amount',$manifest['column']);
	$t->same('success',$manifest['tone']);
	$t->same(['currency'=>'CAD'],$manifest['meta']);

	$variants=[
		TableSummary::make('empty','')->type('')->column('')->count(),
		TableSummary::make('sum')->sum('amount'),
		TableSummary::make('average')->avg('amount'),
		TableSummary::make('minimum')->min('amount'),
		TableSummary::make('maximum')->max('amount'),
		TableSummary::make('money')->money(' USD ',99),
		TableSummary::make('money')->money('',-1),
		TableSummary::make('percent')->percent(99,1.0),
		TableSummary::make('tone')->tone('invalid'),
		TableSummary::make('meta')->meta(['one'=>1])->meta(['two'=>2]),
	];
	$t->same('count',$variants[0]->toArray()['type']);
	$t->same('sum',$variants[1]->toArray()['type']);
	$t->same('avg',$variants[2]->toArray()['type']);
	$t->same('min',$variants[3]->toArray()['type']);
	$t->same('max',$variants[4]->toArray()['type']);
	$t->same(8,$variants[5]->toArray()['meta']['decimals']);
	$t->same(0,$variants[6]->toArray()['meta']['decimals']);
	$t->same(8,$variants[7]->toArray()['meta']['decimals']);
	$t->same('neutral',$variants[8]->toArray()['tone']);
	$t->same(['one'=>1,'two'=>2],$variants[9]->toArray()['meta']);
})->tag('panel','table-summary','coverage')->group('framework-coverage');

test('panel table summary resolves every built in aggregate',static function(Context $t): void {
	$resource=Resource::make('orders');
	$request=PanelRequest::fromArray(['operation'=>'index']);
	$object=new class {
		public float $amount=20.0;
	};
	$getter=new class {
		public function getAmount(): float { return 30.0; }
	};
	$records=[['amount'=>10],$object,$getter,['amount'=>'not-numeric'],new stdClass()];
	$expected=['sum'=>60.0,'avg'=>20.0,'average'=>20.0,'min'=>10.0,'max'=>30.0];
	foreach($expected as $type=>$value){
		$result=TableSummary::make($type,$type)->column('amount')->resolve($records,$resource,$request);
		$t->same($value,$result['value']);
	}
	$t->same(5,TableSummary::make('count')->resolve($records,$resource,$request)['value']);
	$t->same(5,TableSummary::make('custom','unsupported')->column('amount')->resolve($records,$resource,$request)['value']);
	$t->same(null,TableSummary::make('empty')->sum('missing')->resolve($records,$resource,$request)['value']);
	$t->same(null,TableSummary::make('no-column','sum')->resolve($records,$resource,$request)['value']);
})->tag('panel','table-summary','coverage')->group('framework-coverage');

test('panel table summary formats callbacks money percent empty and failures',static function(Context $t): void {
	$resource=Resource::make('orders');
	$request=PanelRequest::fromArray(['operation'=>'index']);
	$records=[['amount'=>12.5],['amount'=>7.5]];
	$custom=TableSummary::make('custom')->valueUsing(
		static fn(array $records,Resource $resource,PanelRequest $request,TableSummary $summary): float=>
			count($records)+($resource->name()==='orders' ? 1 : 0)+($request->operation()==='index' ? 1 : 0)+($summary->name()==='custom' ? .5 : 0)
	)->format(static fn(float $value,TableSummary $summary): array=>['value'=>$value,'name'=>$summary->name()]);
	$result=$custom->resolve($records,$resource,$request);
	$t->same(4.5,$result['value']);
	$t->same('{"value":4.5,"name":"custom"}',$result['formatted']);
	$t->isTrue($custom->toArray()['computed']);
	$t->isTrue($custom->toArray()['formatted']);

	$t->same('CAD 20.00',TableSummary::make('money')->sum('amount')->money('CAD',2)->resolve($records,$resource,$request)['formatted']);
	$t->same('20.0',TableSummary::make('money')->sum('amount')->money('',1)->resolve($records,$resource,$request)['formatted']);
	$t->same('2,000.0%',TableSummary::make('percent')->sum('amount')->percent(1)->resolve($records,$resource,$request)['formatted']);
	$t->same('10',TableSummary::make('float')->avg('amount')->resolve($records,$resource,$request)['formatted']);
	$t->same('No values',TableSummary::make('empty')->sum('missing')->meta(['empty'=>'No values'])->resolve($records,$resource,$request)['formatted']);
	$t->same('Unavailable',TableSummary::make('failed')->valueUsing(static function(): never {
		throw new RuntimeException('resolver failed');
	})->resolve($records,$resource,$request)['formatted']);
	$t->same('Custom unavailable',TableSummary::make('failed')->valueUsing(static fn(): int=>1)->format(static function(): never {
		throw new RuntimeException('formatter failed');
	})->meta(['empty'=>'Custom unavailable'])->resolve($records,$resource,$request)['formatted']);
})->tag('panel','table-summary','coverage')->group('framework-coverage');

test('panel table summary private value helpers cover record and string fallbacks',static function(Context $t): void {
	$object=new class {
		public string $public='property';
		public function getDisplayName(): string { return 'getter'; }
	};
	$t->same('array',$t->nonPublic(TableSummary::class)->invoke('recordValue',['value'=>'array'],'value','default'));
	$t->same('property',$t->nonPublic(TableSummary::class)->invoke('recordValue',$object,'public','default'));
	$t->same('getter',$t->nonPublic(TableSummary::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(TableSummary::class)->invoke('recordValue',$object,'missing','default'));
	$t->same('default',$t->nonPublic(TableSummary::class)->invoke('recordValue',null,'missing','default'));
	$t->same('1',$t->nonPublic(TableSummary::class)->invoke('stringValue',true));
	$t->same('0',$t->nonPublic(TableSummary::class)->invoke('stringValue',false));
	$t->same('',$t->nonPublic(TableSummary::class)->invoke('stringValue',null));
	$t->same('12',$t->nonPublic(TableSummary::class)->invoke('stringValue',12));
	$t->same('{"a":1}',$t->nonPublic(TableSummary::class)->invoke('stringValue',['a'=>1]));
	$resource=fopen('php://memory','r');
	$t->same('',$t->nonPublic(TableSummary::class)->invoke('stringValue',$resource));
	fclose($resource);
	$t->same('Plain',TableSummary::make('plain')->resolve([1],Resource::make('orders'),PanelRequest::fromArray([]))['label']);
	$t->same('',TableSummary::make('')->toArray()['label']);
})->tag('panel','table-summary','coverage')->group('framework-coverage');
