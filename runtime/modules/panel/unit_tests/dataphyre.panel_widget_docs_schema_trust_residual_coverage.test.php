<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Field;
use Dataphyre\Panel\FormSection;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\PanelDocumentationEntry;
use Dataphyre\Panel\PanelPackageRepository;
use Dataphyre\Panel\PanelPackageTrustPolicy;
use Dataphyre\Panel\PanelWidgetState;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel widget documentation schema and package trust contracts')
	->framework(['panel'], ['functions'=>['tracelog']])
	->tag('panel','panel-residual-five-exact','deep-coverage')
	->group('framework-coverage');

test('panel widget residual coverage hydrates manifests datasets and lazy value and metadata success and failure paths',static function(Context $t): void {
	$hydrated=Widget::fromArray([
		'name'=>'sales-total','type'=>'custom chart','label'=>'Sales total','value'=>12,
		'description'=>'Current sales','tone'=>'success','icon'=>'chart-bar','url'=>'/sales','group'=>'Revenue',
		'sort'=>7,'meta'=>['source'=>'array'],
	]);
	$hydratedData=$hydrated->toArray();
	$t->same('sales-total',$hydrated->name());
	$t->same('sales-total',$hydratedData['name']);
	$t->same('Sales total',$hydratedData['label']);
	$t->same(12,$hydratedData['value']);
	$t->same('Revenue',$hydratedData['group']);
	$t->same(7,$hydratedData['sort']);

	$datasetWidget=Widget::make('dataset-chart')
		->chart('bar')
		->meta(['datasets'=>'malformed'])
		->dataset('Revenue',[1,'2','bad'],'unsupported-tone');
	$t->same('Revenue',$datasetWidget->toArray()['meta']['datasets'][0]['label']);
	$t->same('primary',$datasetWidget->toArray()['meta']['datasets'][0]['tone']);
	$staticChart=$hydrated->chart()->data(['Jan'=>1])->labels(['Jan'])->height(80)->unit(' CAD ');
	$t->same(120,$staticChart->toArray()['meta']['height']);
	$t->same('CAD',$staticChart->toArray()['meta']['unit']);
	$t->same(12,$hydrated->resolve()['value']);
	$t->same('sales-total',$hydrated->state()->widget()['name']);

	$lazy=Widget::make('lazy-widget')
		->value(static fn(): int=>42)
		->meta([
			'computed'=>static fn(): string=>'ready',
			'broken'=>static function(): never { throw new RuntimeException('metadata failed'); },
			'nested'=>['deep'=>static fn(): int=>9],
		]);
	$lazyData=$lazy->resolve();
	$t->same(42,$lazyData['value']);
	$t->same('ready',$lazyData['meta']['computed']);
	$t->same(null,$lazyData['meta']['broken']);
	$t->same(9,$lazyData['meta']['nested']['deep']);
	$t->isTrue($lazy->toArray()['lazy']);

	$failed=Widget::make('failed-widget')->value(static function(): never { throw new RuntimeException('value failed'); });
	$failedData=$failed->resolve();
	$t->same('Unavailable',$failedData['value']);
	$t->same('warning',$failedData['tone']);
	$t->same(true,$failedData['meta']['error']);
	$t->same('widget_manifest',$hydrated->manifest(null,['scope'=>'coverage'])['type']);
})->tag('widget');

test('panel documentation entry residual coverage hydrates nested definitions and covers search and empty guards',static function(Context $t): void {
	$stringEntry=PanelDocumentationEntry::from('quick-start','Quick start');
	$t->same('quick-start',$stringEntry->id());
	$t->same('Quick start',$stringEntry->title());
	$t->same('made',PanelDocumentationEntry::make('made')->id());
	$t->same($stringEntry,PanelDocumentationEntry::from($stringEntry));

	$entry=PanelDocumentationEntry::from([
		'name'=>'package-guide','title'=>'Package guide','category'=>'Packages','status'=>'Published','summary'=>'Install trusted packages',
		'api'=>['install'=>'Panel::install','Panel::packages','','Panel::packages'],
		'examples'=>[
			'ignored',
			['title'=>'','code'=>'Panel::packages();','language'=>'PHP'],
		],
		'links'=>[
			'ignored',
			['target'=>'/docs/packages'],
		],
		'tags'=>['Packages','trust',''],
		'meta'=>['audience'=>'operators'],
	]);
	$data=$entry->toArray();
	$t->same('Packages',$entry->category());
	$t->same('published',$entry->status());
	$t->same('Install trusted packages',$entry->summary());
	$t->contains('Panel::install',$data['api']);
	$t->same('php',$data['examples'][0]['language']);
	$t->same('/docs/packages',$data['links'][0]['label']);
	$t->contains('packages',$data['tags']);
	$t->same('operators',$data['meta']['audience']);

	$entry->api(['named'=>'Panel::trust','blank'=>''])->api('Panel::report');
	$beforeExamples=count($entry->toArray()['examples']);
	$entry->example('Empty','   ');
	$t->same($beforeExamples,count($entry->toArray()['examples']));
	$beforeLinks=count($entry->toArray()['links']);
	$entry->link('Empty','   ');
	$t->same($beforeLinks,count($entry->toArray()['links']));
	$entry->tags(['Coverage','coverage'])->meta('reviewed',true)->meta(' ',false);
	$t->isTrue($entry->matches(''));
	$t->isTrue($entry->matches('trusted packages'));
	$t->isFalse($entry->matches('unrelated phrase'));
	$t->same($entry->toArray(),$entry->jsonSerialize());
})->tag('documentation');

test('panel schema component residual coverage normalizes infolists arrays child propagation and flat lists',static function(Context $t): void {
	$entry=InfolistEntry::make('status')->label('Status');
	$fieldComponent=SchemaComponent::field($entry);
	$t->same('field',$fieldComponent->kind());
	$t->instanceOf(Field::class,$fieldComponent->fieldDefinition());
	$t->instanceOf(SchemaComponent::class,SchemaComponent::from($entry));
	$t->same(null,SchemaComponent::from(new stdClass()));

	$fromArray=SchemaComponent::from([
		'kind'=>'group','name'=>'details','label'=>'Details group','children'=>[
			['kind'=>'field','name'=>'title','type'=>'text'],
		],'meta'=>['columns'=>2],
	]);
	$t->same('details',$fromArray->name());
	$t->same(1,count($fromArray->childrenList()));
	$t->same(1,count($fromArray->fieldsList()));
	$arraySection=SchemaComponent::from([
		'kind'=>'section','name'=>'array-section','label'=>'Array section','fields'=>[
			['kind'=>'field','name'=>'array_field'],
		],
	]);
	$t->instanceOf(FormSection::class,$arraySection->sectionDefinition());
	$t->instanceOf(FormSection::class,SchemaComponent::section(['name'=>'array-factory'])->sectionDefinition());

	$section=SchemaComponent::section(FormSection::make('main')->label('Main'))
		->child(Field::make('section_field'));
	$t->instanceOf(FormSection::class,$section->sectionDefinition());
	$t->same('Main',$section->childrenList()[0]->fieldDefinition()->toArray()['meta']['section']);

	$tab=SchemaComponent::tab('Account')->child(FormSection::make('profile'))->child(Field::make('email'));
	$t->same('Account',$tab->childrenList()[1]->fieldDefinition()->toArray()['meta']['tab']);
	$step=SchemaComponent::step('Confirm')->child(FormSection::make('review'))->child(Field::make('accept'));
	$t->same('Confirm',$step->childrenList()[1]->fieldDefinition()->toArray()['meta']['step']);

	$blankTab=SchemaComponent::tab('',[Field::make('blank_tab')]);
	$blankStep=SchemaComponent::step('',[Field::make('blank_step')]);
	$t->same(1,count($blankTab->childrenList()));
	$t->same(1,count($blankStep->childrenList()));
	$tree=SchemaComponent::group('tree',[$section,$tab,$step]);
	$t->isTrue(count($tree->sectionsList())>=3);
	$t->hasKey('field',$fieldComponent->toArray());
	$t->hasKey('section',$section->toArray());
	$t->hasKey('children',$tree->toArray());
})->tag('schema');

test('panel widget state residual coverage derives filtered datasets associative data generated labels and renderer state',static function(Context $t): void {
	$datasets=PanelWidgetState::fromResolved([
		'name'=>'chart-one','type'=>'chart','tone'=>'success','value'=>30,
		'meta'=>[
			'chart_type'=>'unsupported','height'=>500,
			'datasets'=>[
				'invalid',
				['label'=>'missing','values'=>'not-an-array'],
				['label'=>'Revenue','data'=>[1,'2','bad'],'tone'=>'warning'],
			],
		],
	],['surface'=>'dashboard']);
	$t->same(['surface'=>'dashboard'],$datasets->meta());
	$t->same('line',$datasets->chart()['type']);
	$t->same(420,$datasets->chart()['height']);
	$t->same(['1','2'],$datasets->chart()['labels']);
	$t->same([1.0,2.0],$datasets->chart()['datasets'][0]['values']);
	$t->same(2,$datasets->chart()['point_count']);
	$t->same('chart-one',$datasets->jsonSerialize()['state']['name']);

	$associative=PanelWidgetState::fromResolved([
		'name'=>'trend-one','type'=>'trend','tone'=>'info',
		'meta'=>['type'=>'sparkline','dataset_label'=>'Visitors','data'=>['Mon'=>4,'bad'=>'x','Tue'=>'5']],
	]);
	$t->same(['Mon','Tue'],$associative->chart()['labels']);
	$t->same([4.0,5.0],$associative->chart()['datasets'][0]['values']);
	$t->same(132,$associative->chart()['height']);
})->tag('widget-state');

test('panel package trust policy residual coverage configures allowlists revocations metadata and aggregate reports',static function(Context $t): void {
	$policy=PanelPackageTrustPolicy::make([
		'trusted_publishers'=>[' Acme Corp ','acme corp',''],
		'trusted_keys'=>['key-one','key-one',''],
		'allowed_statuses'=>[' Stable ','beta','stable',''],
		'revoked_packages'=>['revoked-package'],
		'revoked_signatures'=>['revoked-digest','revoked-digest',''],
		'require_signature'=>true,
		'allow_unknown_publishers'=>false,
		'meta'=>['environment'=>'test'],
	]);
	$policy->meta('reviewed',true)->meta(' ',false);
	$snapshot=$policy->toArray();
	$t->same(['acme_corp'],$snapshot['trusted_publishers']);
	$t->same(['key-one'],$snapshot['trusted_keys']);
	$t->same(['stable','beta'],$snapshot['allowed_statuses']);
	$t->same(['revoked-digest'],$snapshot['revoked_signatures']);
	$t->same(true,$snapshot['meta']['reviewed']);

	$trusted=[
		'id'=>'trusted-package','status'=>'stable',
		'signature'=>['publisher'=>'acme corp','key'=>'key-one','digest'=>'good-digest'],
	];
	$blocked=[
		'id'=>'revoked-package','status'=>'nightly',
		'signature'=>['publisher'=>'unknown-publisher','key'=>'wrong-key','digest'=>'revoked-digest'],
	];
	$t->isTrue($policy->evaluate($trusted)['trusted']);
	$t->isFalse($policy->evaluate($blocked)['trusted']);
	$report=$policy->report([$trusted,$blocked],['source'=>'array']);
	$t->same(2,$report->summary()['total']);
	$t->same(1,$report->trusted());
	$t->same(1,$report->blocked());
	$repository=PanelPackageRepository::make([$trusted],['php_version'=>PHP_VERSION]);
	$t->same(1,$policy->report($repository)->trusted());
	$t->same($policy->toArray(),$policy->jsonSerialize());
})->tag('package-trust');
