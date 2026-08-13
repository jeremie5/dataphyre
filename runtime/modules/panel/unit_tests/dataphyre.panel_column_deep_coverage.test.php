<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel column imports a comprehensive manifest definition',static function(Context $t): void {
	$dynamic=static fn(mixed ...$arguments): mixed=>$arguments[0] ?? null;
	$column=Column::fromArray([
		'name'=>'total','type'=>'money','label'=>'Total','sortable'=>true,'searchable'=>true,
		'toggleable'=>true,'visible_by_default'=>false,'hidden_by_default'=>true,
		'hidden'=>false,'visible'=>true,'visible_on'=>['index','table'],'hidden_on'=>'export',
		'align'=>'right','group'=>'Financial','group_description'=>'Money values',
		'header_attributes'=>['class'=>'numeric','data-column'=>'total'],
		'cell_attributes'=>['class'=>'numeric-cell','aria-label'=>'Total'],
		'description'=>'Order total','description_using'=>$dynamic,'tooltip'=>$dynamic,
		'copyable'=>true,'copy_value_using'=>$dynamic,'copy_message'=>'Copied',
		'icon'=>'money','icon_using'=>$dynamic,'color'=>'success','color_using'=>$dynamic,
		'link_to'=>'/orders/{id}','link_new_tab'=>true,'truncate'=>24,
		'editable'=>'number','editable_type'=>'number','editable_options'=>['min'=>0,'step'=>0.01],
		'footer'=>'Totals','footer_using'=>$dynamic,
		'summary'=>['type'=>'sum','label'=>'Grand total'],'meta'=>['currency'=>'CAD'],
	]);
	$manifest=$column->toArray();
	$t->same('total',$column->name());
	$t->same('money',$manifest['type']);
	$t->isTrue($manifest['sortable']);
	$t->isTrue($manifest['searchable']);
	$t->isTrue($manifest['editable']);
	$t->same('sum',$manifest['meta']['summary']);

	$t->same('status',Column::fromArray([
		'name'=>'status','column_group'=>'Workflow','extra_attributes'=>['class'=>'status'],
		'href'=>'/status','limit'=>12,'copy_using'=>$dynamic,'summary'=>true,
	])->name());
	$t->same('average',Column::fromArray([
		'name'=>'average','summarize'=>'avg','summary_label'=>'Average',
	])->name());
})->tag('panel','column','coverage')->group('framework-coverage');

test('panel column resolves values formatting visibility search and footer state',static function(Context $t): void {
	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'index']);
	$records=[
		['id'=>1,'amount'=>12.5,'status'=>'open','created_at'=>'2026-01-02 03:04:05','enabled'=>true],
		['id'=>2,'amount'=>7.5,'status'=>'closed','created_at'=>'2026-01-03 04:05:06','enabled'=>false],
	];
	$column=Column::make('amount')
		->valueUsing(static fn(array $record): float=>(float)$record['amount'])
		->format(static fn(float $value): string=>'$'.number_format($value,2))
		->sortUsing(static fn(array $record): float=>(float)$record['amount'])
		->searchUsing(static fn(array $record): string=>'amount '.$record['amount'])
		->descriptionUsing(static fn(): string=>'Dynamic description')
		->tooltipUsing(static fn(): string=>'Dynamic tooltip')
		->copyValueUsing(static fn(mixed $value): string=>'copy-'.$value)
		->iconUsing(static fn(): string=>'money')
		->colorUsing(static fn(): string=>'success')
		->linkTo(static fn(array $record): string=>'/orders/'.$record['id'],static fn(array $record): bool=>$record['id']===1)
		->editable('number',static fn(): array=>['min'=>0])
		->footerUsing(static fn(array $records): string=>'Rows: '.count($records))
		->headerAttributes(static fn(): array=>['data-header'=>'amount'])
		->cellAttributes(static fn(): array=>['data-cell'=>'amount'])
		->visibleUsing(static fn(): bool=>true)->hiddenUsing(static fn(): bool=>false);
	$t->same(12.5,$column->resolveValue($records[0]));
	$t->same('$12.50',$column->formatValue(12.5,$records[0]));
	$t->same('$12.50',$column->exportValue($records[0]));
	$t->same('Dynamic description',$column->resolveDescription($records[0],12.5,'$12.50'));
	$t->same('Dynamic tooltip',$column->resolveTooltip($records[0],12.5,'$12.50'));
	$t->same('copy-12.5',$column->resolveCopyValue($records[0],12.5,'$12.50'));
	$t->same('money',$column->resolveIcon($records[0],12.5,'$12.50'));
	$t->same('success',$column->resolveColor($records[0],12.5,'$12.50'));
	$t->same('/orders/1',$column->resolveLinkUrl($records[0],12.5,'$12.50'));
	$t->isTrue($column->resolveLinkNewTab($records[0],12.5,'$12.50'));
	$editable=Column::make('amount')->editable('number',static fn(): array=>['min'=>0]);
	$t->isTrue($editable->isEditable($records[0],$request));
	$t->same('number',$editable->editableInputType());
	$t->same(['min'=>'0'],$editable->resolveEditableOptions($records[0],$request));
	$t->same(['data-header'=>'amount'],$column->resolveHeaderAttributes($request));
	$t->same(['data-cell'=>'amount'],$column->resolveCellAttributes($records[0],12.5,'$12.50',$request));
	$t->isTrue($column->isVisible('table',$records[0],$request));
	$t->same(12.5,$column->resolveSortValue($records[0],$request));
	$t->same(-1,$column->compareForSort($records[0],$records[1],'desc',$request));
	$t->contains('amount',$column->resolveSearchValue($records[0],$request));
	$t->isTrue($column->matchesSearch($records[0],'12.5',$request));
	$t->isFalse($column->matchesSearch($records[0],'missing',$request));
	$t->notEmpty($column->resolveFooter($records,$request));

	$t->contains('CAD',Column::make('amount')->money('CAD')->formatValue(12.5));
	$t->notEmpty(Column::make('created')->date()->formatValue('2026-01-02'));
	$t->notEmpty(Column::make('created')->datetime()->formatValue('2026-01-02 03:04:05'));
	$t->same('Yes',Column::make('enabled')->booleanLabels('Yes','No')->formatValue(true));
	$t->same('No',Column::make('enabled')->booleanLabels('Yes','No')->formatValue(false));
	$t->notEmpty(Column::make('status')->badge(['open'=>'success'])->formatValue('open'));
	$t->same('user@example.test',Column::make('email')->email()->formatValue('user@example.test'));
})->tag('panel','column','coverage')->group('framework-coverage');

test('panel column normalizers handle structured values attributes and comparison edges',static function(Context $t): void {
	$t->same('index',$t->nonPublic(Column::class)->invoke('normalizeOperation','index'));
	$t->same(['index','export','table'],$t->nonPublic(Column::class)->invoke('normalizeOperations',['index','export','']));
	$t->same(0,$t->nonPublic(Column::class)->invoke('compareValues',null,null));
	$t->isTrue($t->nonPublic(Column::class)->invoke('compareValues',1,2)<0);
	$t->same((new DateTimeImmutable('2026-01-02'))->getTimestamp(),$t->nonPublic(Column::class)->invoke('normalizeComparableValue',new DateTimeImmutable('2026-01-02')));
	$t->notEmpty($t->nonPublic(Column::class)->invoke('searchableStrings',[1,true,['nested'=>'value'],new class implements Stringable {
		public function __toString(): string { return 'stringable'; }
	}]));
	$t->same('12.50%',$t->nonPublic(Column::make('percent'))->invoke('formatPercent',0.125));
	$t->same('["a","b"]',$t->nonPublic(Column::make('json'))->invoke('formatStructured',['a','b']));
	$t->same('Open',$t->nonPublic(Column::make('badge')->meta(['labels'=>['open'=>'Open']]))->invoke('formatBadge','open'));
	$t->isTrue($t->nonPublic(Column::make('truthy'))->invoke('truthy','yes'));
	$t->isFalse($t->nonPublic(Column::make('truthy'))->invoke('truthy','off'));
	$attributes=$t->nonPublic(Column::class)->invoke('normalizeExtraAttributes',[
		'class','role'=>'cell','data-value'=>'yes','data-dp-panel-owned'=>'no',
		'aria-label'=>'Value','aria-sort'=>'ascending','invalid name'=>'x',
		1=>new stdClass(),'tabindex'=>null,
	]);
	$t->contains('class',array_keys($attributes));
	$t->contains('data-value',array_keys($attributes));
	$t->same('order-id',$t->nonPublic(Column::class)->invoke('normalizeAttributeSegment',' Order ID '));
})->tag('panel','column','coverage')->group('framework-coverage');

test('panel column residual aliases footer aggregates and record fallbacks',static function(Context $t): void {
	$columns=[
		Column::make('a')->visible(false),Column::make('a')->hidden(false),
		Column::make('a')->onlyOn('index'),Column::make('a')->exceptOn('export'),
		Column::make('a')->columnGroup('Group'),Column::make('a')->stateUsing(static fn(): string=>'state'),
		Column::make('a')->badge(),Column::make('a')->url(),Column::make('a')->tooltip('Tip'),
		Column::make('a')->href('/a'),Column::make('a')->inlineEditable(),
		Column::make('a')->editable(false),Column::make('a')->editableType('boolean'),
		Column::make('a')->editableOptions(['one'=>'One']),
		Column::make('a')->sum('Sum'),Column::make('a')->avg('Average'),
		Column::make('a')->average('Average'),Column::make('a')->min('Minimum'),
		Column::make('a')->max('Maximum'),Column::make('a')->count('Count'),
		Column::make('a')->extraAttributes(['class'=>'cell']),
		Column::make('a')->attributes(['role'=>'cell']),
		Column::make('a')->headerAttribute('title','Header'),
		Column::make('a')->cellAttribute('title','Cell'),
		Column::make('a')->headerData('column','a'),Column::make('a')->cellData('row','a'),
		Column::make('a')->headerAria('label','Header'),Column::make('a')->cellAria('label','Cell'),
	];
	$t->isTrue(count($columns)>20);
	foreach($columns as $column){
		$t->isTrue(is_array($column->toArray()));
	}

	$request=PanelRequest::fromArray(['operation'=>'index']);
	$plain=Column::make('name');
	$t->same('fallback',$plain->resolveValue(null,'fallback'));
	$t->same('value',$plain->formatValue('value'));
	$t->same('',$plain->exportValue(null));
	$t->same('',$plain->resolveDescription());
	$t->same('',$plain->resolveTooltip());
	$t->same('',$plain->resolveCopyValue());
	$t->same('',$plain->resolveIcon());
	$t->same('',$plain->resolveColor());
	$t->same('',$plain->resolveLinkUrl());
	$t->isFalse($plain->resolveLinkNewTab());
	$t->isFalse($plain->isEditable());
	$t->same('text',$plain->editableInputType());
	$t->same([],$plain->resolveEditableOptions());
	$t->same([],$plain->resolveHeaderAttributes());
	$t->same([],$plain->resolveCellAttributes());
	$t->same(['label'=>'','value'=>'','type'=>''],$plain->resolveFooter());
	$t->isTrue($plain->isVisible());
	$t->isFalse(Column::make('a')->visibleOn('export')->isVisible('table'));
	$t->isFalse(Column::make('a')->hiddenOn('table')->isVisible('table'));
	$t->isFalse(Column::make('a')->hidden()->isVisible());
	$t->isFalse(Column::make('a')->visibleUsing(static fn(): bool=>false)->isVisible());
	$t->isFalse(Column::make('a')->hiddenUsing(static fn(): bool=>true)->isVisible());
	$t->same('',$plain->resolveSortValue(null,$request));
	$t->same('',$plain->resolveSearchValue(null,$request));
	$t->isTrue($plain->matchesSearch([], '',$request));

	$records=[['amount'=>10],['amount'=>20],['amount'=>'not-numeric']];
	foreach([
		'sum'=>30.0,'avg'=>15.0,'average'=>15.0,'min'=>10.0,'max'=>20.0,'count'=>3,
	] as $type=>$expected){
		$footer=Column::make('amount')->summarize($type,ucfirst($type))->resolveFooter($records);
		$t->same($type==='average' ? 'average' : $type,$footer['type']);
		$t->notEmpty($footer['value']);
	}
	$t->same('',Column::make('amount')->sum()->resolveFooter([])['value']);
	$t->same('Static',Column::make('amount')->footer('Static')->resolveFooter($records)['value']);
	$t->same('Custom',Column::make('amount')->footerUsing(static fn(): array=>[
		'label'=>'Label','value'=>'Custom','type'=>'custom',
	])->resolveFooter($records)['value']);
	$t->same('2',Column::make('amount')->footerUsing(static fn(array $records): int=>count($records))->resolveFooter(array_slice($records,0,2))['value']);
	$t->notEmpty(Column::make('amount')->format(static function(): never {
		throw new RuntimeException('format failed');
	})->sum()->resolveFooter(array_slice($records,0,2))['value']);

	$object=new class {
		public string $public='property';
		public function getDisplayName(): string { return 'getter'; }
	};
	$t->same('array',$t->nonPublic(Column::class)->invoke('recordValue',['value'=>'array'],'value','default'));
	$t->same('property',$t->nonPublic(Column::class)->invoke('recordValue',$object,'public','default'));
	$t->same('getter',$t->nonPublic(Column::class)->invoke('recordValue',$object,'display_name','default'));
	$t->same('default',$t->nonPublic(Column::class)->invoke('recordValue',$object,'missing','default'));
	foreach([
		[null,''],[false,''],[true,1],[5,5],['5',5.0],[' text ','text'],
		[new class implements Stringable { public function __toString(): string { return 'object'; } },'object'],
	] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(Column::class)->invoke('normalizeComparableValue',$value));
	}
	$t->notEmpty($t->nonPublic(Column::class)->invoke('searchableStrings',true));
	$t->notEmpty($t->nonPublic(Column::class)->invoke('searchableStrings',new DateTimeImmutable('2026-01-01')));
	$t->notEmpty($t->nonPublic(Column::class)->invoke('searchableStrings',(object)['name'=>'Object']));
	$t->same('',$t->nonPublic(Column::make('x')->meta(['empty'=>'']))->invoke('formatBuiltIn',null));
	$t->same('raw',$t->nonPublic(Column::make('x','unknown'))->invoke('formatBuiltIn','raw'));
	$t->same('',$t->nonPublic(Column::make('x'))->invoke('footerString',null));
	$t->same('Yes',$t->nonPublic(Column::make('x'))->invoke('footerString',true));
	$t->same('{"a":1}',$t->nonPublic(Column::make('x'))->invoke('footerString',['a'=>1]));
	$t->same('invalid',$t->nonPublic(Column::make('x'))->invoke('formatTemporal','invalid','Y-m-d'));
	$t->same('2026-01-01',$t->nonPublic(Column::make('x'))->invoke('formatTemporal',new DateTimeImmutable('2026-01-01'),'Y-m-d'));
	$t->same('not-number',$t->nonPublic(Column::make('x'))->invoke('formatMoney','not-number'));
	$t->same('not-number',$t->nonPublic(Column::make('x'))->invoke('formatPercent','not-number'));
	$t->same('scalar',$t->nonPublic(Column::make('x'))->invoke('formatStructured','scalar'));
	$t->isFalse($t->nonPublic(Column::make('x'))->invoke('truthy',0));
	$t->isTrue($t->nonPublic(Column::make('x'))->invoke('truthy',new stdClass()));
	$t->same([],Column::make('x')->headerAttributes(static fn(): string=>'invalid')->resolveHeaderAttributes());
	$t->same([],Column::make('x')->cellAttributes(static fn(): string=>'invalid')->resolveCellAttributes());
	$t->isTrue(Column::make('x')->visible(static fn(): bool=>true)->isVisible());
	$t->isFalse(Column::make('x')->hidden(static fn(): bool=>true)->isVisible());
	$t->same(['open'=>'success'],Column::make('x')->badge(['open'=>'success'])->toArray()['meta']['tones']);
	$t->same('title',Column::make('x')->url('title')->toArray()['meta']['label_column']);
	$t->same('sum',Column::make('x')->summarize('unsupported')->toArray()['meta']['summary']);
	$t->same([],Column::make('x')->headerAttributes(['class'=>'one'])->headerAttributes([],false)->resolveHeaderAttributes());
	$t->same([],Column::make('x')->cellAttributes(['class'=>'one'])->cellAttributes([],false)->resolveCellAttributes());
	$t->isFalse(Column::make('x')->valueUsing(static fn(): string=>'value')->editable()->isEditable([], $request));
	$showRequest=PanelRequest::fromArray(['operation'=>'show']);
	$t->isFalse(Column::make('x')->editable()->isEditable([], $showRequest));
	$t->same(['a'=>'A','b'=>'B'],Column::make('x')->editableOptions([
		['value'=>'a','label'=>'A'],'b'=>'B',
	])->resolveEditableOptions());
	$t->same('value',Column::make('x')->copyable()->resolveCopyValue(null,' value '));
	$t->same('{"a":1}',$t->nonPublic(Column::class)->invoke('normalizeComparableValue',['a'=>1]));
	$t->same([],$t->nonPublic(Column::class)->invoke('searchableStrings',null));
	$t->same([],$t->nonPublic(Column::class)->invoke('searchableStrings',fopen('php://memory','r')));
	$t->same('12.50%',$t->nonPublic(Column::make('x','percent'))->invoke('formatBuiltIn',0.125));
	$t->same('{"a":1}',$t->nonPublic(Column::make('x','json'))->invoke('formatBuiltIn',['a'=>1]));
	$resource=fopen('php://memory','r');
	$t->same('',$t->nonPublic(Column::make('x'))->invoke('footerString',$resource));
	fclose($resource);
	$t->same('2026-01-01',$t->nonPublic(Column::make('x'))->invoke('formatTemporal',(new DateTimeImmutable('2026-01-01'))->getTimestamp(),'Y-m-d'));
	$staticAttributes=Column::make('x')->headerAttributes(['class'=>'head'])->cellAttributes(['class'=>'cell']);
	$t->same(['class'=>'head'],$staticAttributes->resolveHeaderAttributes());
	$t->same(['class'=>'cell'],$staticAttributes->resolveCellAttributes());
	$t->isTrue($t->nonPublic(Column::class)->invoke('hasDynamicExtraAttributes',[static fn(): array=>[]]));
	$t->same('danger',Column::make('x')->badge('danger')->toArray()['meta']['tone']);
	$comparableResource=fopen('php://memory','r');
	$t->same('',$t->nonPublic(Column::class)->invoke('normalizeComparableValue',$comparableResource));
	fclose($comparableResource);
	\Dataphyre\Panel\PanelComponentRegistry::registerColumnType('coverage',null,[
		'value'=>static fn(mixed $record,mixed $default,Column $column): string=>'hook-value',
		'format'=>static fn(mixed $value,mixed $record,Column $column): string=>'hook-format',
		'export'=>static fn(mixed $value,mixed $record,Column $column): string=>'hook-export',
	]);
	$custom=Column::make('custom','coverage');
	$t->same('hook-value',$custom->resolveValue([]));
	$t->same('hook-format',$custom->formatValue('value',[]));
	$t->same('hook-export',$custom->exportValue([]));
})->tag('panel','column','coverage')->group('framework-coverage');
