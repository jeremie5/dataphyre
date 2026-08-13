<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel table filter imports manifests and covers fluent aliases',static function(Context $t): void {
	$filter=TableFilter::fromArray([
		'name'=>'order-status','type'=>'select','label'=>'Order status','column'=>'workflow_status',
		'options'=>['open'=>'Open','closed'=>'Closed'],'default'=>'open','hidden'=>false,
		'visible_on'=>['index','table'],'hidden_on'=>'export','meta'=>['source'=>'manifest'],
		'indicator'=>'Current status','indicator_tone'=>'success',
	]);
	$manifest=$filter->toArray();
	$t->same('order-status',$filter->name());
	$t->same('select',$manifest['type']);
	$t->same('workflow_status',$manifest['column']);
	$t->same('Current status',$manifest['indicator']);
	$t->same(['source'=>'manifest'],$manifest['meta']);

	$variants=[
		TableFilter::make('empty')->type(''),
		TableFilter::make('range')->range(),
		TableFilter::make('dates')->dateRange(),
		TableFilter::make('numbers')->numberRange(),
		TableFilter::make('column')->column(''),
		TableFilter::make('options')->options(['a'=>'A']),
		TableFilter::make('dynamic')->optionsUsing(static fn(): array=>['b'=>'B']),
		TableFilter::make('default')->default(0),
		TableFilter::make('predicate')->where(static fn(): bool=>true),
		TableFilter::make('visible')->visible(false),
		TableFilter::make('visible_callback')->visible(static fn(): bool=>true),
		TableFilter::make('hidden')->hidden(false),
		TableFilter::make('hidden_callback')->hidden(static fn(): bool=>false),
		TableFilter::make('visible_using')->visibleUsing(static fn(): bool=>true),
		TableFilter::make('hidden_using')->hiddenUsing(static fn(): bool=>false),
		TableFilter::make('visible_on')->visibleOn(['index','export'],'table'),
		TableFilter::make('only_on')->onlyOn('index'),
		TableFilter::make('hidden_on')->hiddenOn(['show'],'export'),
		TableFilter::make('except_on')->exceptOn('export'),
		TableFilter::make('indicator')->indicator(null),
		TableFilter::make('indicator_string')->indicator(' Static '),
		TableFilter::make('indicator_callback')->indicator(static fn(): string=>'Dynamic'),
		TableFilter::make('indicator_using')->indicatorUsing(static fn(): string=>'Dynamic'),
		TableFilter::make('tone')->indicatorTone(''),
		TableFilter::make('meta')->meta(['one'=>1])->meta(['two'=>2]),
	];
	foreach($variants as $variant){
		$t->isTrue(is_array($variant->toArray()));
	}
	$t->same('text',$variants[0]->toArray()['type']);
	$t->same('range',$variants[1]->toArray()['type']);
	$t->same('date_range',$variants[2]->toArray()['type']);
	$t->same('number_range',$variants[3]->toArray()['type']);
	$t->same('column',$variants[4]->toArray()['column']);
	$t->same('neutral',$variants[23]->toArray()['indicator_tone']);
})->tag('panel','table-filter','coverage')->group('framework-coverage');

test('panel table filter resolves active values options and range input',static function(Context $t): void {
	$empty=PanelRequest::fromArray(['query'=>[]]);
	$t->same(null,TableFilter::make('search')->activeValue($empty));
	$t->same('fallback',TableFilter::make('search')->default('fallback')->activeValue($empty));
	$t->same(null,TableFilter::make('search')->default('   ')->activeValue($empty));
	$t->same(0,TableFilter::make('search')->default(0)->activeValue($empty));

	$options=[
		'Statuses'=>['label'=>'Statuses','options'=>[
			['value'=>'open','label'=>'Open order'],
			'closed'=>'Closed order',
		]],
		'legacy'=>['draft'=>'Draft order'],
	];
	$select=TableFilter::make('status','select')->options($options);
	$t->same('open',$select->activeValue(PanelRequest::fromArray(['query'=>['status'=>'open']])));
	$t->same(null,$select->activeValue(PanelRequest::fromArray(['query'=>['status'=>'missing']])));
	$t->same('anything',TableFilter::make('status','select')->activeValue(
		PanelRequest::fromArray(['query'=>['status'=>'anything']]),[]
	));
	$t->same(null,TableFilter::make('status')->options(['open'=>'Open'])->activeValue(
		PanelRequest::fromArray(['query'=>['status'=>'missing']])
	));

	$dynamic=TableFilter::make('status','select')->optionsUsing(static fn(PanelRequest $request): array=>[
		(string)$request->query('allowed','open')=>'Allowed',
	]);
	$request=PanelRequest::fromArray(['query'=>['status'=>'closed','allowed'=>'closed']]);
	$t->same(['closed'=>'Allowed'],$dynamic->optionsFor($request));
	$t->same('closed',$dynamic->activeValue($request));
	$t->same([],TableFilter::make('bad')->optionsUsing(static fn(): string=>'invalid')->optionsFor($empty));
	$t->same(['a'=>'A'],TableFilter::make('static')->options(['a'=>'A'])->optionsFor($empty));

	$range=TableFilter::make('price')->numberRange();
	$t->same(null,$range->activeValue(PanelRequest::fromArray(['query'=>['price_from'=>' ','price_to'=>[]]])));
	$t->same(['from'=>'10','to'=>null],$range->activeValue(PanelRequest::fromArray(['query'=>['price_from'=>'10']])));
	$t->same(['from'=>null,'to'=>'20'],$range->activeValue(PanelRequest::fromArray(['query'=>['price_to'=>'20']])));
	$t->same(['from'=>'10','to'=>'20'],$range->activeValue(PanelRequest::fromArray(['query'=>[
		'price_from'=>'10','price_to'=>'20',
	]])));
})->tag('panel','table-filter','coverage')->group('framework-coverage');

test('panel table filter matches records across built in and predicate types',static function(Context $t): void {
	$inactive=PanelRequest::fromArray(['query'=>[]]);
	$t->isTrue(TableFilter::make('name')->matches(['name'=>'Alice'],$inactive));
	$t->isTrue(TableFilter::make('name')->hidden()->matches(['name'=>'Alice'],PanelRequest::fromArray([
		'query'=>['name'=>'Bob'],
	])));

	$predicate=TableFilter::make('status')->where(
		static fn(array $record,string $value,PanelRequest $request,TableFilter $filter): bool=>
			$record['status']===$value && $request->operation()==='index' && $filter->name()==='status'
	);
	$t->isTrue($predicate->matches(['status'=>'open'],PanelRequest::fromArray(['query'=>['status'=>'open']])));
	$t->isFalse($predicate->matches(['status'=>'closed'],PanelRequest::fromArray(['query'=>['status'=>'open']])));

	$t->isTrue(TableFilter::make('flag','boolean')->matches(['flag'=>'on'],PanelRequest::fromArray(['query'=>['flag'=>'yes']])));
	$t->isFalse(TableFilter::make('flag','toggle')->matches(['flag'=>0],PanelRequest::fromArray(['query'=>['flag'=>'true']])));
	$t->isTrue(TableFilter::make('status','select')->options(['open'=>'Open'])->matches(
		['status'=>'open'],PanelRequest::fromArray(['query'=>['status'=>'open']])
	));
	$t->isFalse(TableFilter::make('status','enum')->options(['open'=>'Open'])->matches(
		['status'=>'closed'],PanelRequest::fromArray(['query'=>['status'=>'open']])
	));
	$t->isTrue(TableFilter::make('created_at','date')->matches(
		['created_at'=>'2026-07-10 12:30:00'],PanelRequest::fromArray(['query'=>['created_at'=>'2026-07-10']])
	));
	$t->isFalse(TableFilter::make('created_at','date')->matches(
		['created_at'=>'2026-07-09'],PanelRequest::fromArray(['query'=>['created_at'=>'2026-07-10']])
	));
	$t->isTrue(TableFilter::make('name')->matches(['name'=>'Alice Example'],PanelRequest::fromArray(['query'=>['name'=>'example']])));
	$t->isFalse(TableFilter::make('name')->matches(['name'=>'Alice'],PanelRequest::fromArray(['query'=>['name'=>'Bob']])));

	$object=new class {
		public string $status='open';
		public function getTotalAmount(): float { return 15.0; }
	};
	$t->isTrue(TableFilter::make('status','select')->options(['open'=>'Open'])->matches(
		$object,PanelRequest::fromArray(['query'=>['status'=>'open']])
	));
	$t->isTrue(TableFilter::make('total')->column('total_amount')->numberRange()->matches(
		$object,PanelRequest::fromArray(['query'=>['total_from'=>'10','total_to'=>'20']])
	));
	$t->isFalse(TableFilter::make('total')->column('total_amount')->numberRange()->matches(
		$object,PanelRequest::fromArray(['query'=>['total_from'=>'16']])
	));
})->tag('panel','table-filter','coverage')->group('framework-coverage');

test('panel table filter applies operation and callback visibility',static function(Context $t): void {
	$index=PanelRequest::fromArray(['operation'=>'index']);
	$export=PanelRequest::fromArray(['operation'=>'export']);
	$t->isTrue(TableFilter::make('a')->isVisible());
	$t->isFalse(TableFilter::make('a')->visibleOn('export')->isVisible($index));
	$t->isTrue(TableFilter::make('a')->visibleOn('index')->isVisible($index));
	$t->isFalse(TableFilter::make('a')->hiddenOn('export')->isVisible($export));
	$t->isFalse(TableFilter::make('a')->hidden()->isVisible($index));
	$t->isFalse(TableFilter::make('a')->visibleUsing(static fn(): bool=>false)->isVisible($index));
	$t->isFalse(TableFilter::make('a')->hiddenUsing(static fn(): bool=>true)->isVisible($index));
	$t->isTrue(TableFilter::make('a')
		->visibleUsing(static fn(string $operation,mixed $request,TableFilter $filter,mixed $resource,mixed $table): bool=>
			$operation==='index' && $request instanceof PanelRequest && $filter->name()==='a' && $resource==='resource' && $table==='table'
		)
		->hiddenUsing(static fn(string $operation): bool=>$operation==='export')
		->isVisible($index,'resource','table'));
	$t->isTrue(TableFilter::make('a')->visible(true)->hidden(false)->isVisible($index));
})->tag('panel','table-filter','coverage')->group('framework-coverage');

test('panel table filter builds default and dynamic indicators',static function(Context $t): void {
	$empty=PanelRequest::fromArray(['query'=>[]]);
	$t->same([],TableFilter::make('status')->indicators($empty));

	$options=['open'=>'Open order'];
	$request=PanelRequest::fromArray(['query'=>['status'=>'open']]);
	$default=TableFilter::make('status','select')->label('Status')->options($options)->indicatorTone('success');
	$t->same('Open order',$default->indicators($request)[0]['value']);
	$t->same('Status',$default->indicators($request)[0]['label']);
	$t->same('Selected',$default->indicator('Selected')->indicators($request)[0]['label']);

	$boolean=TableFilter::make('enabled','boolean');
	$t->same('Yes',$boolean->indicators(PanelRequest::fromArray(['query'=>['enabled'=>'on']]))[0]['value']);
	$t->same('No',$boolean->indicators(PanelRequest::fromArray(['query'=>['enabled'=>'off']]))[0]['value']);
	$range=TableFilter::make('price')->numberRange();
	$t->same('10 to 20',$range->indicators(PanelRequest::fromArray(['query'=>['price_from'=>'10','price_to'=>'20']]))[0]['value']);
	$t->same('from 10',$range->indicators(PanelRequest::fromArray(['query'=>['price_from'=>'10']]))[0]['value']);
	$t->same('to 20',$range->indicators(PanelRequest::fromArray(['query'=>['price_to'=>'20']]))[0]['value']);

	$t->same([],TableFilter::make('status')->indicatorUsing(static fn(): mixed=>null)->indicators($request));
	$t->same('Callback value',TableFilter::make('status')->indicatorUsing(
		static fn(string $value,PanelRequest $request,TableFilter $filter,array $options): string=>
			$value==='open' && $request->operation()==='index' && $filter->name()==='status' && $options===[] ? 'Callback value' : 'Unexpected'
	)->indicators($request)[0]['value']);
	$list=TableFilter::make('status')->indicatorUsing(static fn(): array=>[
		'First',
		new class implements Stringable { public function __toString(): string { return 'Second'; } },
		new stdClass(),
		['filter'=>' custom filter ','label'=>'Custom','text'=>'Third','tone'=>'warning','clear'=>['status',' status_to ','']],
		['filter'=>'','label'=>'','value'=>'','tone'=>'','clear'=>' status '],
	])->indicators($request);
	$t->same(3,count($list));
	$t->same('custom_filter',$list[2]['filter']);
	$t->same(['status','status_to'],$list[2]['clear']);
})->tag('panel','table-filter','coverage')->group('framework-coverage');

test('panel table filter private normalizers cover edge structures',static function(Context $t): void {
	$object=new class {
		public string $public='property';
		public function getDisplayName(): string { return 'getter'; }
	};
	$t->same('array',$t->nonPublic(TableFilter::class)->invoke('recordValue',['value'=>'array'],'value','default'));
	$t->same('property',$t->nonPublic(TableFilter::class)->invoke('recordValue',$object,'public','default'));
	$t->same('getter',$t->nonPublic(TableFilter::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(TableFilter::class)->invoke('recordValue',$object,'missing','default'));
	$t->same('default',$t->nonPublic(TableFilter::class)->invoke('recordValue',null,'missing','default'));

	foreach([[null,true],[' ',true],[[],true],[0,false],[false,false],[['x'],false]] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(TableFilter::class)->invoke('blank',$value),'blank '.get_debug_type($value));
	}
	foreach([[true,true],[false,false],[1,true],[0,false],[1.5,true],[0.0,false],[' YES ',true],['off',false],[null,false],[new stdClass(),true]] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(TableFilter::class)->invoke('truthy',$value),'truthy '.get_debug_type($value));
	}
	$t->isTrue($t->nonPublic(TableFilter::make('x','select'))->invoke('optionValidationEnabled'));
	$t->isTrue($t->nonPublic(TableFilter::make('x')->options(['a'=>'A']))->invoke('optionValidationEnabled'));
	$t->isFalse($t->nonPublic(TableFilter::make('x'))->invoke('optionValidationEnabled'),'plain validation');
	$t->isTrue($t->nonPublic(TableFilter::make('x','money_range'))->invoke('isRange'));
	$t->isFalse($t->nonPublic(TableFilter::make('x'))->invoke('isRange'),'plain range');

	$t->isTrue($t->nonPublic(TableFilter::class)->invoke('matchesRange',10,[],'range'));
	$t->isFalse($t->nonPublic(TableFilter::class)->invoke('matchesRange','not-number',['from'=>1],'number_range'),'non numeric range');
	$t->isTrue($t->nonPublic(TableFilter::class)->invoke('matchesRange',10,['from'=>10,'to'=>20],'numeric_range'));
	$t->isFalse($t->nonPublic(TableFilter::class)->invoke('matchesRange',9,['from'=>10],'money_range'),'below numeric range');
	$t->isTrue($t->nonPublic(TableFilter::class)->invoke('matchesRange','2026-07-10 12:00:00',['from'=>'2026-07-01','to'=>'2026-07-31'],'date_range'));
	$t->isFalse($t->nonPublic(TableFilter::class)->invoke('matchesRange','2026-08-01',['to'=>'2026-07-31'],'date_range'),'above date range');
	$t->isTrue($t->nonPublic(TableFilter::class)->invoke('matchesRange','m',['from'=>'a','to'=>'z'],'range'));
	$t->isFalse($t->nonPublic(TableFilter::class)->invoke('matchesRange','0',['from'=>'a'],'range'),'below string range');

	$options=[
		'Group'=>['label'=>'Group','options'=>[
			['value'=>'a','label'=>'Alpha'],'b'=>'Beta',
		]],
		'Legacy'=>['c'=>'Charlie'],
		['value'=>'d','label'=>'Delta'],
		'Echo',
		'a'=>'Duplicate Alpha',
	];
	$t->same(['a','b','c','d','Echo'],$t->nonPublic(TableFilter::class)->invoke('optionValues',$options));
	$t->same('Alpha',$t->nonPublic(TableFilter::class)->invoke('optionLabel',$options,'a'));
	$t->same('Beta',$t->nonPublic(TableFilter::class)->invoke('optionLabel',$options,'b'));
	$t->same('Charlie',$t->nonPublic(TableFilter::class)->invoke('optionLabel',$options,'c'));
	$t->same('Delta',$t->nonPublic(TableFilter::class)->invoke('optionLabel',$options,'d'));
	$t->same('Echo',$t->nonPublic(TableFilter::class)->invoke('optionLabel',$options,'Echo'));
	$t->same(null,$t->nonPublic(TableFilter::class)->invoke('optionLabel',$options,'missing'));

	$labelFilter=TableFilter::make('x');
	$t->same('1 to 2',$t->nonPublic($labelFilter)->invoke('valueLabel',["from"=>1,"to"=>2],[]));
	$t->same('from 1',$t->nonPublic($labelFilter)->invoke('valueLabel',["from"=>1],[]));
	$t->same('to 2',$t->nonPublic($labelFilter)->invoke('valueLabel',["to"=>2],[]));
	$t->same('Yes',$t->nonPublic(TableFilter::make('x','checkbox'))->invoke('valueLabel',1,[]));
	$t->same('No',$t->nonPublic(TableFilter::make('x','bool'))->invoke('valueLabel',0,[]));
	$t->same('Alpha',$t->nonPublic($labelFilter)->invoke('valueLabel','a',$options));
	$t->same('plain',$t->nonPublic($labelFilter)->invoke('valueLabel',' plain ',[]));

	foreach([
		[null,''],[true,'1'],[false,'0'],[' text ','text'],[12,'12'],
		[new class implements Stringable { public function __toString(): string { return ' stringable '; } },'stringable'],
		[['a'=>1],'{"a":1}'],
	] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(TableFilter::class)->invoke('stringValue',$value));
	}
	$resource=fopen('php://memory','r');
	$t->same('',$t->nonPublic(TableFilter::class)->invoke('stringValue',$resource));
	fclose($resource);

	$t->same('index',$t->nonPublic(TableFilter::class)->invoke('normalizeOperation',''));
	$t->same('bulk_export',$t->nonPublic(TableFilter::class)->invoke('normalizeOperation',' Bulk Export '));
	$t->same(['index','export','table'],$t->nonPublic(TableFilter::class)->invoke('normalizeOperations',[['index','export','index'],'table','']));
	$t->isTrue($t->nonPublic(TableFilter::class)->invoke('isOptionGroup',['options'=>[]]));
	$t->isTrue($t->nonPublic(TableFilter::class)->invoke('isOptionGroup',['a'=>'A']));
	$t->isFalse($t->nonPublic(TableFilter::class)->invoke('isOptionGroup',['value'=>'a','label'=>'A']),'option record group');
	$t->isFalse($t->nonPublic(TableFilter::class)->invoke('isOptionGroup',['A','B']),'list group');

	$t->same([],$t->nonPublic(TableFilter::class)->invoke('normalizeIndicators',false,'status','Status','neutral','open'));
	$t->same('scalar',$t->nonPublic(TableFilter::class)->invoke('normalizeIndicators','scalar','status','Status','neutral','open')[0]['value']);
	$t->same('stringable',$t->nonPublic(TableFilter::class)->invoke('normalizeIndicators',new class implements Stringable { public function __toString(): string { return 'stringable'; } },
		'status','Status','neutral','open',)[0]['value']);
})->tag('panel','table-filter','coverage')->group('framework-coverage');
