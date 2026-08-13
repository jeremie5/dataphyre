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
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\InfolistEntry;
use Dataphyre\Panel\PanelCommand;
use Dataphyre\Panel\PanelCompatibilityMatrix;
use Dataphyre\Panel\PanelFormState;
use Dataphyre\Panel\PanelPackageManifest;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\SchemaComponent;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel core value and composition contracts')
	->framework(['panel'], ['functions'=>['tracelog']])
	->tag('panel','panel-core-five-exact','deep-coverage')
	->group('framework-coverage');

final class DpPanelCoreFiveMacroCallable {
	public function __invoke(Infolist $infolist, string $suffix): string {
		return (string)($infolist->schema()->metadata()['macro'] ?? '').$suffix;
	}
}

test('panel extensible covers ordered configurators instance macros static macros and missing dispatch',static function(Context $t): void {
	Infolist::flushConfigurators();
	Infolist::flushMacros();
	$order=[];
	Infolist::configureUsing(static function(Infolist $infolist) use (&$order): Infolist {
		$order[]='normal';
		return $infolist->meta(['normal'=>true]);
	});
	Infolist::configureUsing(static function(Infolist $infolist) use (&$order): string {
		$order[]='ignored';
		return $infolist::class;
	});
	Infolist::configureUsing(static function(Infolist $infolist) use (&$order): Infolist {
		$order[]='important';
		return $infolist->meta(['important'=>true]);
	},true);

	$configured=Infolist::make();
	$t->same(['normal','ignored','important'],$order);
	$t->same(true,$configured->schema()->metadata()['normal'] ?? false);
	$t->same(true,$configured->schema()->metadata()['important'] ?? false);

	Infolist::macro(' ',static fn(): string=>'ignored');
	$t->isFalse(Infolist::hasMacro(''));
	Infolist::macro('with marker',function(string $value): Infolist {
		return $this->meta(['macro'=>$value]);
	});
	Infolist::macro('read marker',new DpPanelCoreFiveMacroCallable());
	Infolist::macro('sum values',static fn(int $left,int $right): int=>$left+$right);
	$t->isTrue(Infolist::hasMacro('with marker'));
	$marked=$configured->with_marker('closure');
	$t->same('closure!',$marked->read_marker('!'));
	$t->same(7,Infolist::sum_values(3,4));
	$t->throws(static fn()=>$marked->missingPanelMacro(),\BadMethodCallException::class);
	$t->throws(static fn()=>Infolist::missingStaticPanelMacro(),\BadMethodCallException::class);

	Infolist::flushMacros();
	Infolist::flushConfigurators();
	$t->isFalse(Infolist::hasMacro('with marker'));
})->tag('panel-extensible');

test('panel infolist covers factories replacement layouts entries manifests and normalization',static function(Context $t): void {
	Infolist::flushConfigurators();
	Infolist::flushMacros();
	$text=Infolist::make()->textEntry('title');
	$badge=Infolist::make()->badgeEntry('status',['active'=>'success']);
	$image=Infolist::make()->imageEntry('avatar');
	$t->same('text',$text->field()->toArray()['type']);
	$t->same('badge',$badge->field()->toArray()['type']);
	$t->same('image',$image->field()->toArray()['type']);

	$base=Infolist::make()->columns(['small'=>2,'wide'=>4])->meta(['source'=>'base']);
	$rebuilt=$base->components([
		$text,
		['entry'=>true,'name'=>'array_status','type'=>'badge'],
		SchemaComponent::group('details',[Field::make('code')]),
		'plain_value',
	]);
	$t->same(['sm'=>2,'2xl'=>4],$rebuilt->schema()->responsiveColumns());
	$t->same('base',$rebuilt->schema()->metadata()['source'] ?? null);

	$layout=Infolist::make()
		->component(Field::make('field_component'))
		->component($badge)
		->component(['kind'=>'entry','name'=>'array_entry','type'=>'text'])
		->entry('headline','text')
		->section(FormSection::make('details'),[$text,'section_value'])
		->group('summary',[$badge])
		->tab('Account',[$image])
		->step('Confirm',[InfolistEntry::make('accepted')])
		->columns(3)
		->meta(['surface'=>'detail']);
	$t->isTrue(count($layout->fieldsList())>=8);
	$t->same(3,count($layout->sectionsList()));
	$t->same($layout,Infolist::from($layout));
	$t->same(null,Infolist::from(new stdClass()));
	$t->same('infolist',Infolist::fromSchema(Schema::make()->field('wrapped'))->toArray()['kind']);
	$t->same('infolist',Infolist::from(Schema::make()->field('converted'))->toArray()['kind']);
	$t->same('infolist',Infolist::from(['components'=>['array_converted']])->toArray()['kind']);
	$t->same('schema_manifest',$layout->manifest('show',['scope'=>'coverage'])['type']);
	$t->same('infolist',$layout->toArray()['kind']);
	$t->same('infolist',$layout->schema()->metadata()['usage'] ?? null);
})->tag('infolist');

test('panel schema covers normalization legacy forms responsive grids lifecycle bridges and serialization',static function(Context $t): void {
	Schema::flushConfigurators();
	Schema::flushMacros();
	$existing=Schema::make();
	$t->same($existing,Schema::from($existing));
	$t->same(null,Schema::from(new stdClass()));
	$t->same(null,Schema::from(['unsupported'=>'definition']));
	$t->instanceOf(Schema::class,Schema::from(['listed_field',['name'=>'listed_array']]));

	$components=Schema::from([
		'components'=>[Field::make('component_field'),SchemaComponent::group('component_group',[Field::make('nested')])],
		'columns'=>['base'=>1,'medium'=>3],
		'meta'=>['source'=>'components'],
		'presentation'=>['views'=>'brick'],
	]);
	$t->same(3,$components->columnsCount());
	$t->same('brick',$components->presentations()['views']['display'] ?? null);

	$legacy=Schema::from([
		'columns'=>2,
		'sections'=>[['name'=>'legacy','label'=>'Legacy']],
		'fields'=>[
			['name'=>'sectioned','meta'=>['section'=>'Legacy']],
			['name'=>'loose'],
		],
		'meta'=>['source'=>'legacy'],
		'presentation'=>['groups'=>'segmented'],
	]);
	$t->same(2,$legacy->columnsCount());
	$t->same('segmented',$legacy->presentations()['groups']['display'] ?? null);

	$form=ResourceForm::make()
		->section(FormSection::make('details')->label('Details'))
		->field(Field::make('inside')->section('Details'))
		->field(Field::make('loose'))
		->columns(2)
		->meta(['source'=>'form'])
		->collectionPresentations(['views'=>'brick']);
	$fromForm=Schema::from($form);
	$t->instanceOf(Schema::class,$fromForm);
	$t->same(2,count($fromForm->fieldsList()));
	$t->same(1,count($fromForm->sectionsList()));
	$t->same('form',$fromForm->metadata()['source'] ?? null);

	$schema=Schema::make([Field::make('discarded')])
		->components([SchemaComponent::group('kept',[Field::make('nested_kept')])])
		->component(InfolistEntry::make('entry_component'))
		->field('plain_field','text')
		->entry('entry_field','badge')
		->section('details',[Field::make('section_field')])
		->group('summary',[Field::make('group_field')])
		->tab('General',[Field::make('tab_field')])
		->step('Review',[Field::make('step_field')])
		->columns([
			''=>0,
			'small'=>2,
			'medium'=>3,
			'large'=>4,
			'xl'=>5,
			'wide'=>6,
			'unsupported'=>12,
		])
		->meta(['source'=>'fluent'])
		->collectionPresentations(['steps'=>'brick']);
	$t->same(8,count($schema->componentsList()));
	$t->same(6,$schema->columnsCount());
	$t->same(['default'=>1,'sm'=>2,'md'=>3,'lg'=>4,'xl'=>5,'2xl'=>6],$schema->responsiveColumns());
	$t->same('fluent',$schema->metadata()['source'] ?? null);
	$t->same(1,Schema::make()->columns([])->columnsCount());
	$t->same(12,Schema::make()->columns(99)->columnsCount());
	$t->same(1,Schema::make()->columns(0)->columnsCount());

	$roundTrip=$schema->toForm(4);
	$t->same(4,$roundTrip->columnsCount());
	$t->same('brick',$roundTrip->presentations()['steps']['display'] ?? null);
	$t->same('schema_manifest',$schema->manifest('edit',['scope'=>'coverage'])['type']);
	$t->same('schema',$schema->toArray()['type']);

	$runtimeSchema=Schema::make()->field(Field::make('name')->required()->default('Default'));
	$request=PanelRequest::fromArray(['method'=>'POST','operation'=>'create','input'=>['name'=>'Ada']]);
	$t->instanceOf(PanelFormState::class,$runtimeSchema->hydrate(['name'=>'Grace'],$request));
	$t->instanceOf(PanelFormState::class,$runtimeSchema->dehydrate($request));
	$t->instanceOf(PanelFormState::class,$runtimeSchema->validate(['name'=>'Ada'],null,$request,'create'));
	$t->instanceOf(PanelFormState::class,$runtimeSchema->submit($request,null,'create'));
	$t->instanceOf(PanelFormState::class,$runtimeSchema->state(null,$request,'create',['name'=>'Ada'],true));
	$t->same(['name'],array_keys($runtimeSchema->lifecycle(['scope'=>'coverage'])->fields()));
})->tag('schema');

test('panel command covers array hydration clone builders lazy success and traced failure paths',static function(Context $t): void {
	$t->same('',PanelCommand::make(' ')->toArray()['label']);
	$hydrated=PanelCommand::fromArray([
		'name'=>'open-reports',
		'label'=>' Open reports ',
		'group'=>'Initial',
		'category'=>'Reports',
		'description'=>' View reports ',
		'icon'=>' chart ',
		'url'=>' /reports ',
		'sort'=>7,
		'keywords'=>[' reports ','open','reports',''],
		'new_tab'=>true,
		'hidden'=>true,
		'meta'=>['source'=>'array'],
	]);
	$hydratedData=$hydrated->toArray();
	$t->same('open-reports',$hydrated->name());
	$t->same('Reports',$hydratedData['group']);
	$t->same(['reports','open'],$hydratedData['keywords']);
	$t->isFalse($hydrated->isVisible());

	$command=PanelCommand::make('export.data')
		->label(' Export data ')
		->group(' ')
		->category('Operations')
		->description('')
		->description(' Export the current data ')
		->icon('')
		->icon('download')
		->url('/exports')
		->newTab(false)
		->hide(false)
		->sort(11)
		->keywords('export, data export  ')
		->meta(['source'=>'fluent']);
	$t->isTrue($command->isVisible());
	$t->same('/exports',$command->toArray()['url']);

	$lazy=PanelCommand::make('lazy-command')
		->url(static fn(?PanelRequest $request,PanelCommand $resolved): string=>'/lazy/'.$resolved->name())
		->visibleUsing(static fn(): bool=>true);
	$t->isTrue($lazy->isVisible());
	$t->same('/lazy/lazy-command',$lazy->toArray()['url']);
	$t->isFalse(PanelCommand::make('false-visible')->visibleUsing(static fn(): bool=>false)->isVisible());

	$visibilityFailure=PanelCommand::make('visibility-failure')->visibleUsing(static function(): never {
		throw new RuntimeException('visibility failed');
	});
	$t->isFalse($visibilityFailure->isVisible());
	$urlFailure=PanelCommand::make('url-failure')->url(static function(): never {
		throw new RuntimeException('url failed');
	});
	$t->same(null,$urlFailure->toArray()['url']);
	$t->same('command_manifest',$lazy->manifest(null,null,['scope'=>'coverage'])['type']);
})->tag('panel-command');

test('panel compatibility matrix covers runtime mutation keyed packages filters aggregates and serialization',static function(Context $t): void {
	$defaults=PanelCompatibilityMatrix::make();
	$t->same(PHP_VERSION,$defaults->runtime()['php']);
	$t->same('2.0',PanelCompatibilityMatrix::defaultRuntime()['panel']);

	$compatible=PanelPackageManifest::make('compatible-package')
		->requires('php','>=1.0')
		->provides(['widgets','commands']);
	$blocked=PanelPackageManifest::make('blocked-package')
		->requires('php','>=99.0')
		->provides(['widgets']);
	$matrix=new PanelCompatibilityMatrix([
		'keyed-package'=>['label'=>'Keyed package','provides'=>['commands']],
		$compatible,
		$blocked,
	],['php'=>PHP_VERSION,'panel'=>'2.0','modules'=>[],'themes'=>[]]);
	$t->same(3,count($matrix->packages()));
	$t->same(2,count($matrix->compatible()));
	$t->same(1,count($matrix->blocked()));

	$t->same($matrix,$matrix->runtime(['modules'=>['panel'=>'2.0']]));
	$t->same($matrix,$matrix->runtime('reactor','2.0'));
	$t->same($matrix,$matrix->runtime(' ',false));
	$t->same('2.0',$matrix->runtime()['reactor']);
	$t->same('panel_package',$matrix->package(' ')->id());
	$t->same($compatible,$matrix->package('compatible-package'));
	$t->same('registered-package',$matrix->register(['id'=>'registered-package','provides'=>['widgets']])->id());

	$t->same($matrix,$matrix->meta(['source'=>'coverage']));
	$t->same($matrix,$matrix->meta('run','focused'));
	$t->same($matrix,$matrix->meta(' ',false));
	$manifest=$matrix->manifest(['scope'=>'exact']);
	$t->same('panel_compatibility_matrix',$manifest['type']);
	$t->same(5,$manifest['package_count']);
	$t->same(3,$manifest['provides']['widgets']);
	$t->same('exact',$manifest['meta']['scope']);
	$t->same($matrix->toArray(),$matrix->jsonSerialize());
})->tag('panel-compatibility');
