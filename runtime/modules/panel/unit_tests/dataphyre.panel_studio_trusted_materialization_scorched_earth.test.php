<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\ActionGroup;
use Dataphyre\Panel\Column;
use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelDeveloperToolkit;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelStudioArtifact;
use Dataphyre\Panel\PanelStudioBuilderCollection;
use Dataphyre\Panel\PanelStudioChildRule;
use Dataphyre\Panel\PanelStudioCompiler;
use Dataphyre\Panel\PanelStudioComponentSchema;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDiagnostic;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioMaterialization;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPageBundle;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioPreviewSigner;
use Dataphyre\Panel\PanelStudioPropertySchema;
use Dataphyre\Panel\PanelStudioReceipt;
use Dataphyre\Panel\PanelStudioRevision;
use Dataphyre\Panel\PanelStudioSchemaException;
use Dataphyre\Panel\PanelStudioSchemaRegistry;
use Dataphyre\Panel\ResourceForm;
use Dataphyre\Panel\ResourceTable;
use Dataphyre\Panel\Schema;
use Dataphyre\Panel\TableFilter;
use Dataphyre\Panel\TableView;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_studio_trusted_tree(string $label='Studio operations'):array {
	return ['kind'=>'page','key'=>'studio_ops','properties'=>['label'=>$label,'description'=>'Trusted Studio runtime','layout'=>'masonry','url'=>'/studio/ops','group'=>'Operations'],'children'=>[
		['kind'=>'form','key'=>'customer_form','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'form_section','key'=>'primary','properties'=>['label'=>'Customer','columns'=>2],'children'=>[
				['kind'=>'field','key'=>'name','properties'=>['label'=>'Name','required'=>true],'children'=>[]],
				['kind'=>'field','key'=>'market','properties'=>['label'=>'Market','type'=>'select','options'=>['ca'=>'Canada','us'=>'United States','eu'=>'European Union'],'searchable'=>true],'children'=>[]],
			]],
			['kind'=>'tabs','key'=>'details_tabs','properties'=>['layout'=>'masonry'],'children'=>[
				['kind'=>'tab','key'=>'contact','properties'=>['label'=>'Contact','columns'=>2],'children'=>[
					['kind'=>'field','key'=>'email','properties'=>['label'=>'Email','type'=>'email'],'children'=>[]],
				]],
			]],
		]],
		['kind'=>'form','key'=>'billing_form','properties'=>['columns'=>1],'children'=>[
			['kind'=>'form_section','key'=>'primary','properties'=>['label'=>'Billing'],'children'=>[
				['kind'=>'field','key'=>'name','properties'=>['label'=>'Billing name'],'children'=>[]],
			]],
		]],
		['kind'=>'table','key'=>'orders_table','properties'=>['per_page'=>50,'density'=>'compact','default_sort'=>'id','default_sort_direction'=>'desc'],'children'=>[
			['kind'=>'column','key'=>'id','properties'=>['label'=>'ID','sortable'=>true],'children'=>[]],
			['kind'=>'column','key'=>'status','properties'=>['label'=>'Status','type'=>'badge','searchable'=>true],'children'=>[]],
			['kind'=>'filters','key'=>'order_filters','properties'=>['layout'=>'masonry'],'children'=>[
				['kind'=>'filter','key'=>'status','properties'=>['label'=>'Status','type'=>'select','column'=>'status','options'=>['open'=>'Open','paid'=>'Paid'],'tone'=>'primary'],'children'=>[]],
			]],
			['kind'=>'table_views','key'=>'order_views','properties'=>['layout'=>'masonry'],'children'=>[
				['kind'=>'table_view','key'=>'active','properties'=>['label'=>'Active','default'=>true,'filters'=>['status'=>'open'],'density'=>'compact'],'children'=>[]],
				['kind'=>'table_view','key'=>'paid','properties'=>['label'=>'Paid','filters'=>['status'=>'paid']],'children'=>[]],
			]],
		]],
		['kind'=>'table','key'=>'audit_table','properties'=>[],'children'=>[
			['kind'=>'column','key'=>'id','properties'=>['label'=>'Audit ID'],'children'=>[]],
		]],
		['kind'=>'show','key'=>'order_show','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'show_section','key'=>'profile','properties'=>['label'=>'Profile','columns'=>2],'children'=>[
				['kind'=>'infolist_entry','key'=>'name','properties'=>['label'=>'Name','copyable'=>true],'children'=>[]],
				['kind'=>'infolist_entry','key'=>'status','properties'=>['label'=>'Status','type'=>'badge'],'children'=>[]],
			]],
		]],
		['kind'=>'actions','key'=>'record_actions','properties'=>['label'=>'Record actions','tone'=>'primary'],'children'=>[
			['kind'=>'action','key'=>'create','properties'=>['label'=>'Create','icon'=>'plus','redirect_to'=>'/orders/create','success_message'=>'Order created'],'children'=>[]],
		]],
		['kind'=>'widget_grid','key'=>'operations_widgets','properties'=>['columns'=>3,'layout'=>'masonry'],'children'=>[
			['kind'=>'widget','key'=>'open_orders','properties'=>['label'=>'Open orders','value'=>42,'description'=>'Current demand','tone'=>'info'],'children'=>[]],
			['kind'=>'widget','key'=>'trend','properties'=>['label'=>'Trend','type'=>'chart','chart_type'=>'bar','labels'=>['Mon','Tue'],'data'=>[12,18],'height'=>240],'children'=>[]],
		]],
		['kind'=>'tabs','key'=>'page_tabs','properties'=>['layout'=>'masonry'],'children'=>[
			['kind'=>'tab','key'=>'summary','properties'=>['label'=>'Summary'],'children'=>[
				['kind'=>'infolist_entry','key'=>'reference','properties'=>['label'=>'Reference'],'children'=>[]],
			]],
		]],
		['kind'=>'toolbar','key'=>'page_toolbar','properties'=>['label'=>'Tools','layout'=>'masonry'],'children'=>[
			['kind'=>'action','key'=>'refresh','properties'=>['label'=>'Refresh','style'=>'outline'],'children'=>[]],
		]],
		['kind'=>'navigation','key'=>'studio_navigation','properties'=>['label'=>'Studio'],'children'=>[
			['kind'=>'navigation_group','key'=>'operations','properties'=>['label'=>'Operations','description'=>'Operator pages'],'children'=>[
				['kind'=>'navigation_item','key'=>'orders','properties'=>['label'=>'Orders','url'=>'/orders','badge'=>3,'tone'=>'primary'],'children'=>[]],
			]],
			['kind'=>'navigation_item','key'=>'help','properties'=>['label'=>'Help','url'=>'/help','new_tab'=>true],'children'=>[]],
		]],
	]];
}

function dp_panel_studio_artifact_payload_rehash(array $payload):array {
	unset($payload['fingerprint']);
	$sort=static function(array &$value)use(&$sort):void{if(!array_is_list($value)){ksort($value,SORT_STRING);}foreach($value as&$item){if(is_array($item)){$sort($item);}}};
	$sort($payload);$payload['fingerprint']=hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
	return $payload;
}

function dp_panel_studio_schema_codes(array $diagnostics):array { return array_map(static fn(PanelStudioDiagnostic $diagnostic):string=>$diagnostic->code(),$diagnostics); }

// These contracts construct process-wide framework facades and registries. Strict case isolation keeps
// every assertion count and lifecycle outcome independent of whichever Studio contract ran before it.
test('Trusted Studio registry validates immutable typed schemas grammar identity and safe extensions',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$same=PanelStudioSchemaRegistry::defaults();$tree=dp_panel_studio_trusted_tree();$validation=$registry->validate($tree);
	$t->isTrue($validation->valid());$t->same($registry->fingerprint(),$same->fingerprint());$t->same('sibling',$registry->schema('field')?->identityScope());$t->same('sibling',$registry->schema('column')?->identityScope());$t->same(30,count($registry->kinds()));$t->same([],$registry->manifest()['portable_only_envelope_kinds']);$t->isTrue($registry->manifest()['complete_definition_kind_coverage']);
	$normalized=$validation->normalized()->root();$t->same(100,$normalized['properties']['sort']);$t->same('stack',$normalized['children'][1]['properties']['layout']);$t->same('text',$normalized['children'][1]['children'][0]['children'][0]['properties']['type']);
	$t->isTrue($registry->validate(PanelStudioDefinition::from($tree))->valid());$t->same($registry->version(),$validation->registryVersion());$t->same($registry->fingerprint(),$validation->registryFingerprint());json_encode([$registry,$validation],JSON_THROW_ON_ERROR);

	$cases=[
		'property_not_allowed'=>['kind'=>'page','key'=>'root','properties'=>['unknown'=>'value'],'children'=>[]],
		'invalid_property_type'=>['kind'=>'page','key'=>'root','properties'=>['label'=>false],'children'=>[]],
		'property_not_in_enum'=>['kind'=>'page','key'=>'root','properties'=>['layout'=>'waterfall'],'children'=>[]],
		'property_out_of_bounds'=>['kind'=>'form','key'=>'root','properties'=>['columns'=>13],'children'=>[['kind'=>'field','key'=>'name','properties'=>[],'children'=>[]]]],
		'child_kind_not_allowed'=>['kind'=>'page','key'=>'root','properties'=>[],'children'=>[['kind'=>'field','key'=>'name','properties'=>[],'children'=>[]]]],
		'child_cardinality_violation'=>['kind'=>'form','key'=>'root','properties'=>[],'children'=>[]],
	];
	foreach($cases as$code=>$candidate){$diagnostics=$registry->diagnose($candidate);$t->isTrue(in_array($code,dp_panel_studio_schema_codes($diagnostics),true));$t->contains('root',$diagnostics[0]->path());}
	$missing=(new PanelStudioSchemaRegistry())->diagnose(['kind'=>'board','key'=>'root','properties'=>[],'children'=>[]]);$t->same('schema_missing',$missing[0]->code());
	$secret=['kind'=>'page','key'=>'root','properties'=>[],'children'=>[['kind'=>'form','key'=>'form','properties'=>[],'children'=>[['kind'=>'field','key'=>'password','properties'=>['type'=>'password','default'=>'must-never-persist'],'children'=>[]]]]]];$secretDiagnostics=$registry->diagnose($secret);$t->same('secret_default_forbidden',$secretDiagnostics[0]->code());$t->same('root.children[0].children[0].properties.default',$secretDiagnostics[0]->path());
	$secretManager=new PanelStudioManager(new PanelInMemoryStudioStore(),PanelStudioPolicy::permit(static fn():bool=>true),clock:static fn():string=>'2026-07-14T12:00:00+00:00');$secretDocument=PanelStudioDocument::make('acme','secret-default','Secret default');$t->throws(static fn()=>$secretManager->saveDraft($secretDocument,$secret,0,'secret-save','author'),PanelStudioSchemaException::class);$t->throws(static fn()=>(new PanelStudioMaterializer())->materialize($secret,$registry),PanelStudioSchemaException::class);
	$secretDefinition=PanelStudioDefinition::from(['kind'=>'field','key'=>'api_token','properties'=>['type'=>'api_token','default'=>'opaque-value'],'children'=>[]]);$t->same('secret_default_forbidden',$registry->validate($secretDefinition)->diagnostics()[0]->code());$t->throws(static fn()=>(new PanelStudioMaterializer())->materialize($secretDefinition,$registry),PanelStudioSchemaException::class);
	$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'form','key'=>'root','properties'=>[],'children'=>[['kind'=>'field','key'=>'same','properties'=>[],'children'=>[]],['kind'=>'field','key'=>'same','properties'=>[],'children'=>[]]]]),InvalidArgumentException::class);

	$map=PanelStudioPropertySchema::make('options','scalar_map');$t->same('scalar_map',$map->type());$t->same('invalid_property_type',$map->diagnostic(['class'=>'Unsafe'],'root.properties.options')?->code());json_encode($map,JSON_THROW_ON_ERROR);$long=PanelStudioPropertySchema::make('content','string',['max_length'=>4096]);$t->same(null,$long->diagnostic(str_repeat('x',4096),'root.properties.content'));$t->same('invalid_property_type',$long->diagnostic(str_repeat('x',4097),'root.properties.content')?->code());
	$required=PanelStudioPropertySchema::make('title','string',['required'=>true,'nullable'=>false,'min_length'=>2,'max_length'=>8,'pattern'=>'~^[A-Z]~','enum'=>['Admin']]);$pattern=PanelStudioPropertySchema::make('heading','string',['pattern'=>'~^[A-Z]~']);$t->isTrue($required->required());$t->isFalse($required->nullable());$t->same('property_pattern_mismatch',$pattern->diagnostic('admin','root.properties.heading')?->code());$t->same('property_not_in_enum',$required->diagnostic('Owner','root.properties.title')?->code());
	$t->throws(static fn()=>$required->defaultValue(),LogicException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('api_token'),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','object'),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['mystery'=>true]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['required'=>'yes']),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['required'=>true,'default'=>'Title']),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['enum'=>array_fill(0,129,'x')]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['enum'=>['same','same']]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['min'=>1]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('count','integer',['min_length'=>1]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('count','integer',['pattern'=>'~^1$~']),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('count','integer',['min'=>null]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['min_length'=>3,'max_length'=>2]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('title','string',['pattern'=>'invalid']),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioPropertySchema::make('count','integer',['default'=>'1']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioChildRule::make('unknown'),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioChildRule::make('field',2,1),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioComponentSchema::make('form',[],[PanelStudioChildRule::make('field',2,2)],0,1,'sibling'),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioComponentSchema::make('form',[],[],1,1,'sibling'),InvalidArgumentException::class);

	$field=$registry->schema('field');$t->instanceOf(PanelStudioComponentSchema::class,$field);$replacement=PanelStudioComponentSchema::make('field',array_values($field->properties()),array_values($field->childRules()),$field->minimumChildren(),$field->maximumChildren(),$field->identityScope(),2);
	$t->throws(static fn()=>$registry->withSchema($replacement,'dataphyre.panel','2.0.0'),LogicException::class);$upgraded=$registry->withSchema($replacement,'dataphyre.panel','2.0.0','replace_same_provider');$t->same(2,$upgraded->schema('field')?->schemaVersion());$t->isFalse(hash_equals($registry->fingerprint(),$upgraded->fingerprint()));$t->throws(static fn()=>$upgraded->withSchema($replacement,'dataphyre.panel','3.0.0','replace_same_provider'),LogicException::class);$t->throws(static fn()=>$registry->withSchema($replacement,'other.provider','2.0.0','replace_same_provider'),LogicException::class);
	$incompatible=(new PanelStudioSchemaRegistry())->withSchema(PanelStudioComponentSchema::make('field',[],[],0,0,'sibling'),'application','1.0.0');$t->isTrue($incompatible->validate(['kind'=>'field','key'=>'name','properties'=>[],'children'=>[]])->valid());$t->throws(static fn()=>(new PanelStudioMaterializer())->materialize(['kind'=>'field','key'=>'name','properties'=>[],'children'=>[]],$incompatible),PanelStudioSchemaException::class);
	$boardSchema=PanelStudioComponentSchema::make('board',[],[],0,0,'sibling');$boardRegistry=(new PanelStudioSchemaRegistry())->withSchema($boardSchema,'application','1.0.0');$t->isTrue($boardRegistry->validate(['kind'=>'board','key'=>'board','properties'=>[],'children'=>[]])->valid());$t->throws(static fn()=>(new PanelStudioMaterializer())->materialize(['kind'=>'board','key'=>'board','properties'=>[],'children'=>[]],$boardRegistry),PanelStudioSchemaException::class);
	$schemaError=new PanelStudioSchemaException('Invalid schema',[new PanelStudioDiagnostic('root','invalid_schema','Invalid schema')]);$t->same('invalid_schema',$schemaError->diagnostics()[0]->code());$t->same('panel_studio_schema_exception',$schemaError->jsonSerialize()['type']);json_encode($schemaError,JSON_THROW_ON_ERROR);
})->tag('panel','studio','schema-registry','security','scorched-earth')->isolation('case')->maxMillis(5000);

test('Trusted Studio materializer emits actual Panel builders masonry bindings and host-registerable pages',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();$first=$materializer->materialize(dp_panel_studio_trusted_tree(),$registry);$second=$materializer->materialize(dp_panel_studio_trusted_tree(),$registry);
	$t->instanceOf(PanelStudioMaterialization::class,$first);$t->same($first->artifact()->fingerprint(),$second->artifact()->fingerprint());$t->same($first->manifest(),$second->manifest());$t->isTrue($first->artifact()->materializable());$t->same('trusted_materialization',$first->artifact()->mode());$t->same($registry->fingerprint(),$first->artifact()->registryFingerprint());$t->same([],$materializer->manifest()['portable_only_envelope_kinds']);$t->isTrue($materializer->manifest()['complete_definition_kind_coverage']);$t->isTrue($materializer->validate(dp_panel_studio_trusted_tree(),$registry)->valid());$t->same([], $materializer->diagnose(dp_panel_studio_trusted_tree(),$registry));
	$root=$first->root();$t->instanceOf(PanelStudioPageBundle::class,$root);$t->same(2,count($root->forms()));$t->same(2,count($root->tables()));$t->same(1,count($root->infolists()));$t->same(2,count($root->actionGroups()));$t->same(2,count($root->collections()));$t->same(2,$first->artifact()->builderCount()>1?2:0);$t->same($root->forms()[0],$root->surface('root.children[0]'));$t->same(null,$root->surface('root.children[99]'));$t->same(count($first->builders()),$first->artifact()->builderCount());
	$t->instanceOf(ResourceForm::class,$first->builder('root.children[0]'));$t->instanceOf(ResourceTable::class,$first->builder('root.children[2]'));$t->instanceOf(Column::class,$first->builder('root.children[2].children[0]'));$t->instanceOf(TableFilter::class,$first->builder('root.children[2].children[2].children[0]'));$t->instanceOf(TableView::class,$first->builder('root.children[2].children[3].children[0]'));$t->instanceOf(Infolist::class,$first->builder('root.children[4]'));$t->instanceOf(ActionGroup::class,$first->builder('root.children[5]'));$t->instanceOf(Widget::class,$first->builder('root.children[6].children[0]'));$t->instanceOf(Schema::class,$first->builder('root.children[7]'));
	$t->same(2,count($first->paths('field','name')));$t->same(2,count($first->paths('column','id')));$t->same([], $first->paths('field','missing'));
	$filterCollection=$first->builder('root.children[2].children[2]');$viewCollection=$first->builder('root.children[2].children[3]');$widgetCollection=$first->builder('root.children[6]');$t->instanceOf(PanelStudioBuilderCollection::class,$filterCollection);$t->instanceOf(PanelStudioBuilderCollection::class,$viewCollection);$t->instanceOf(PanelStudioBuilderCollection::class,$widgetCollection);$t->same('order_filters',$filterCollection->key());$t->same('masonry',$filterCollection->display());$t->same('masonry',$viewCollection->display());$t->same('masonry',$widgetCollection->display());$t->same(3,$widgetCollection->presentation()['columns']['base']);
	$table=$root->tables()[0];$t->same('masonry',$table->presentations()['filters']['display']);$t->same('masonry',$table->presentations()['views']['display']);$t->same('masonry',$root->page()->presentations()['widgets']['display']);$t->same('masonry',$root->page()->presentations()['forms']['display']);
	$manifest=$first->manifest();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->isTrue($manifest['runtime']['actual_panel_builders']);$t->isFalse($manifest['runtime']['objects_serialized']);$t->same('masonry',$manifest['collection_bindings']['root.children[6]']['presentation']['display']);$t->notContains('Closure',$encoded);$t->notContains('must-never-persist',$encoded);
	$formWithShowEntry=['kind'=>'form','key'=>'bad_form','properties'=>[],'children'=>[['kind'=>'tabs','key'=>'tabs','properties'=>[],'children'=>[['kind'=>'tab','key'=>'tab','properties'=>[],'children'=>[['kind'=>'infolist_entry','key'=>'name','properties'=>[],'children'=>[]]]]]]]];$showWithField=['kind'=>'show','key'=>'bad_show','properties'=>[],'children'=>[['kind'=>'tabs','key'=>'tabs','properties'=>[],'children'=>[['kind'=>'tab','key'=>'tab','properties'=>[],'children'=>[['kind'=>'field','key'=>'name','properties'=>[],'children'=>[]]]]]]]];$t->isTrue($registry->validate($formWithShowEntry)->valid());$t->isTrue($registry->validate($showWithField)->valid());$t->same('materializer_context_mismatch',$materializer->diagnose($formWithShowEntry,$registry)[0]->code());$t->same('materializer_context_mismatch',$materializer->diagnose($showWithField,$registry)[0]->code());$t->throws(static fn()=>$materializer->materialize($formWithShowEntry,$registry),PanelStudioSchemaException::class);$t->throws(static fn()=>$materializer->materialize($showWithField,$registry),PanelStudioSchemaException::class);
	$validShowTabs=['kind'=>'show','key'=>'valid_show','properties'=>[],'children'=>[['kind'=>'tabs','key'=>'tabs','properties'=>[],'children'=>[['kind'=>'tab','key'=>'tab','properties'=>[],'children'=>[['kind'=>'infolist_entry','key'=>'name','properties'=>['label'=>'Name'],'children'=>[]]]]]]]];$t->instanceOf(Infolist::class,$materializer->materialize($validShowTabs,$registry)->root());$t->same([], $materializer->diagnose($validShowTabs,$registry));$t->same('declarative_builder_definition_only',$materializer->manifest()['action_output']);$t->isFalse($materializer->manifest()['security']['mutation_handlers']);
	$host=new PanelManager();$registered=$root->register($host);$t->same($root->page(),$registered);$t->same($root->page(),$host->getPage('studio_ops'));$rendered=$host->render('studio_ops');$t->same(200,$rendered->status());$t->contains('Trusted Studio runtime',$rendered->content());json_encode([$root,$filterCollection],JSON_THROW_ON_ERROR);
})->tag('panel','studio','materializer','builders','masonry','scorched-earth')->isolation('case')->maxMillis(8000);

test('Trusted Studio compiler imports and blueprints round-trip through the executable registry',static function(Context $t):void{
	$compiler=new PanelStudioCompiler();$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();
	$blueprint=\Dataphyre\Panel\PanelSchemaBlueprint::make('sales.orders',['id'=>['type'=>'integer','generated'=>true],'email'=>['type'=>'varchar','nullable'=>false],'password'=>['type'=>'varchar','default'=>'credential-value'],'status'=>['type'=>'varchar','enum'=>['draft','paid']]]);$generated=$compiler->fromBlueprint($blueprint,'orders');$t->isTrue($registry->validate($generated)->valid());$generatedRuntime=$materializer->materialize($generated,$registry);$t->instanceOf(PanelStudioPageBundle::class,$generatedRuntime->root());$t->notContains('credential-value',json_encode($generatedRuntime,JSON_THROW_ON_ERROR));
	$imported=$compiler->import(['type'=>'panel_resource_manifest','fields'=>[['name'=>'name','label'=>'Name','enum'=>['pending-review']],['name'=>'password','type'=>'password','default'=>'secret']], 'columns'=>[['name'=>'id','label'=>'ID']], 'widgets'=>[['key'=>'throughput','label'=>'Throughput']], 'navigation'=>[['key'=>'orders','label'=>'Orders','url'=>'/orders']]],'imported');$t->isTrue($registry->validate($imported)->valid());$importRuntime=$materializer->materialize($imported,$registry);$t->instanceOf(PanelStudioPageBundle::class,$importRuntime->root());$t->same(4,count($importRuntime->root()->surfaces()));$t->notContains('secret',json_encode($importRuntime,JSON_THROW_ON_ERROR));$t->contains('Pending Review',json_encode($imported,JSON_THROW_ON_ERROR));
	$portable=PanelStudioArtifact::portable($generated,$compiler);$t->isFalse($portable->materializable());$t->same('portable_blueprint',$portable->mode());$t->same('artifact_not_materializable',$portable->compatibilityDiagnostics($registry,$materializer,$compiler)[0]->code());
})->tag('panel','studio','compiler','blueprint','import','materialization')->isolation('case')->maxMillis(5000);

test('Trusted Studio publication binds artifacts rejects stale registries and preserves rollback contracts',static function(Context $t):void{
	$store=new PanelInMemoryStudioStore();$policy=PanelStudioPolicy::permit(static fn():bool=>true);$clock=static fn():string=>'2026-07-14T12:00:00+00:00';$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();$compiler=new PanelStudioCompiler();$manager=new PanelStudioManager($store,$policy,$compiler,null,1,$clock,$registry,$materializer);$document=PanelStudioDocument::make('acme','studio','Studio');
	$save=$manager->saveDraft($document,dp_panel_studio_trusted_tree(),0,'save-1','author');$head=$manager->head('acme','studio','author');$t->same($save->artifactFingerprint(),$head?->artifactFingerprint());$t->same('bound',$head?->bindingStatus());$t->same(2,$head?->formatVersion());$t->same($save->artifactFingerprint(),PanelStudioReceipt::hydrate($save->jsonSerialize())->artifactFingerprint());
	$approve=$manager->approve('acme','studio',1,'approve-1','reviewer');$t->same($save->artifactFingerprint(),$approve->artifactFingerprint());
	$page=$registry->schema('page');$t->instanceOf(PanelStudioComponentSchema::class,$page);$pageV2=PanelStudioComponentSchema::make('page',array_values($page->properties()),array_values($page->childRules()),$page->minimumChildren(),$page->maximumChildren(),$page->identityScope(),2);$registryV2=$registry->withSchema($pageV2,'dataphyre.panel','2.0.0','replace_same_provider');$staleManager=new PanelStudioManager($store,$policy,$compiler,null,1,$clock,$registryV2,$materializer);$stale=$staleManager->compatibilityDiagnostics($staleManager->head('acme','studio','author'));$t->isTrue(in_array('stale_registry',dp_panel_studio_schema_codes($stale),true));$t->throws(static fn()=>$staleManager->publish('acme','studio',2,'publish-stale','author'),PanelStudioSchemaException::class);
	$signer=new PanelStudioPreviewSigner(['preview'=>str_repeat('p',32)],'preview',static fn():int=>1784040000,static fn():string=>'nonce_000000000000888');$previewManager=new PanelStudioManager($store,$policy,$compiler,$signer,1,$clock,$registry,$materializer);$preview=$previewManager->preview('acme','studio',2,'author');$stalePreviewManager=new PanelStudioManager($store,$policy,$compiler,$signer,1,$clock,$registryV2,$materializer);$t->throws(static fn()=>$stalePreviewManager->verifyPreview($preview->token(),'acme','studio','author',2),PanelStudioSchemaException::class);
	$publish=$manager->publish('acme','studio',2,'publish-1','author');$t->same($save->artifactFingerprint(),$publish->artifactFingerprint());$t->same($publish->artifactFingerprint(),$manager->materializePublished('acme','studio','author')->artifact()->fingerprint());
	$changed=dp_panel_studio_trusted_tree('Studio operations v2');$save2=$manager->saveDraft($document,$changed,3,'save-2','author');$manager->approve('acme','studio',4,'approve-2','reviewer');$manager->publish('acme','studio',5,'publish-2','author');$rollback=$manager->rollback('acme','studio',3,6,'rollback','operator');$t->same($publish->artifactFingerprint(),$rollback->artifactFingerprint());$t->same($publish->artifactFingerprint(),$manager->published('acme','studio','author')?->artifactFingerprint());$t->same($save2->artifactFingerprint(),$manager->history('acme','studio','author')[1]->artifactFingerprint());

	$direct=new PanelInMemoryStudioStore();$definition=PanelStudioDefinition::from(dp_panel_studio_trusted_tree());$trusted=$materializer->materialize($definition,$registry)->artifact();$direct->save($document,$definition,0,'direct','author','2026-07-14T12:00:00+00:00',$trusted);$different=$materializer->materialize($definition,$registryV2)->artifact();$t->throws(static fn()=>$direct->save($document,$definition,0,'direct','author','2026-07-14T12:00:00+00:00',$different),RuntimeException::class);
	$portableStore=new PanelInMemoryStudioStore();$portableStore->save($document,$definition,0,'portable','author','2026-07-14T12:00:00+00:00');$t->throws(static fn()=>$portableStore->approve('acme','studio',1,'portable-approve','reviewer','2026-07-14T12:01:00+00:00'),LogicException::class);
})->tag('panel','studio','artifact','publish','rollback','stale-registry','scorched-earth')->isolation('case')->maxMillis(8000);

test('Trusted Studio hydration rejects coercion tampering and legacy publication until explicit rebind',static function(Context $t):void{
	$definition=PanelStudioDefinition::from(dp_panel_studio_trusted_tree());$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();$artifact=$materializer->materialize($definition,$registry)->artifact();$payload=$artifact->jsonSerialize();$t->same($artifact->fingerprint(),PanelStudioArtifact::hydrate($payload)->fingerprint());$t->same($definition->hash(),$artifact->definitionHash());$t->same($payload['normalized_hash'],$artifact->normalizedHash());$t->same($registry->version(),$artifact->registryVersion());$t->same($registry->fingerprint(),$artifact->registryFingerprint());$t->same(PanelStudioCompiler::COMPILER_VERSION,$artifact->compilerVersion());$t->same((new PanelStudioCompiler())->fingerprint(),$artifact->compilerFingerprint());$t->same($materializer->version(),$artifact->materializerVersion());$t->same($materializer->fingerprint(),$artifact->materializerFingerprint());$t->same($payload['builder_contract_hash'],$artifact->builderContractHash());$artifact->assertCompatible($registry,$materializer);
	foreach(['compiler_version','materializer_version','builder_count']as$key){$bad=$payload;$bad[$key]=(string)$bad[$key];$t->throws(static fn()=>PanelStudioArtifact::hydrate($bad),UnexpectedValueException::class);}$bad=$payload;$bad['materializable']=1;$t->throws(static fn()=>PanelStudioArtifact::hydrate($bad),UnexpectedValueException::class);$bad=$payload;$bad['registry_fingerprint']=str_repeat('f',64);$t->throws(static fn()=>PanelStudioArtifact::hydrate($bad),UnexpectedValueException::class);
	$forged=$payload;$forged['compiler_version']++;$forged=dp_panel_studio_artifact_payload_rehash($forged);$forgedArtifact=PanelStudioArtifact::hydrate($forged);$t->same('compiler_version_mismatch',$forgedArtifact->compatibilityDiagnostics($registry,$materializer)[0]->code());$t->throws(static fn()=>$forgedArtifact->assertCompatible($registry,$materializer),PanelStudioSchemaException::class);
	$forged=$payload;$forged['materializer_fingerprint']=str_repeat('e',64);$forged=dp_panel_studio_artifact_payload_rehash($forged);$codes=dp_panel_studio_schema_codes(PanelStudioArtifact::hydrate($forged)->compatibilityDiagnostics($registry,$materializer));$t->isTrue(in_array('materializer_fingerprint_mismatch',$codes,true));

	$revision=PanelStudioRevision::make(1,'draft','save',$definition,'author','2026-07-14T12:00:00+00:00',artifact:$artifact);$revisionPayload=$revision->jsonSerialize();$revisionPayload['number']='1';$t->throws(static fn()=>PanelStudioRevision::hydrate($revisionPayload),Throwable::class);$revisionPayload=$revision->jsonSerialize();$revisionPayload['extra']='no';$t->throws(static fn()=>PanelStudioRevision::hydrate($revisionPayload),UnexpectedValueException::class);
	$receipt=PanelStudioReceipt::make('save','acme','legacy',$revision,str_repeat('a',64));$receiptPayload=$receipt->jsonSerialize();$receiptPayload['revision']='1';$t->throws(static fn()=>PanelStudioReceipt::hydrate($receiptPayload),Throwable::class);$receiptPayload=$receipt->jsonSerialize();$receiptPayload['replayed']=1;$t->throws(static fn()=>PanelStudioReceipt::hydrate($receiptPayload),Throwable::class);

	$legacyUnsigned=['type'=>'panel_studio_revision','version'=>1,'number'=>1,'state'=>'draft','action'=>'save','definition'=>$definition->jsonSerialize(),'content_hash'=>$definition->hash(),'actor'=>'author','created_at'=>'2026-07-14T12:00:00+00:00','parent_hash'=>'','source_revision'=>null,'approvals'=>[]];$legacyPayload=$legacyUnsigned+['hash'=>hash('sha256',json_encode($legacyUnsigned,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR))];$legacy=PanelStudioRevision::hydrate($legacyPayload);$t->same('unbound_legacy',$legacy->bindingStatus());$t->same(null,$legacy->artifact());$t->same($legacyPayload,$legacy->jsonSerialize());
	$requestHash=str_repeat('b',64);$legacyReceiptId=hash('sha256',json_encode(['save','acme','legacy',1,$legacy->hash(),$requestHash],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$legacyReceiptPayload=['type'=>'panel_studio_receipt','version'=>1,'id'=>$legacyReceiptId,'operation'=>'save','tenant_id'=>'acme','document_id'=>'legacy','revision'=>1,'revision_hash'=>$legacy->hash(),'request_hash'=>$requestHash,'replayed'=>false];$legacyReceipt=PanelStudioReceipt::hydrate($legacyReceiptPayload);$t->same('unbound_legacy',$legacyReceipt->bindingStatus());$t->same($legacyReceiptPayload,$legacyReceipt->jsonSerialize());
	$document=PanelStudioDocument::make('acme','legacy','Legacy');$scope=hash('sha256',"acme\0legacy");$state=['version'=>1,'documents'=>[$scope=>['document'=>$document->jsonSerialize(),'revisions'=>[1=>$legacyPayload],'published_revision'=>null,'idempotency'=>[]]]];$store=new PanelInMemoryStudioStore($state);$manager=new PanelStudioManager($store,PanelStudioPolicy::permit(static fn():bool=>true),clock:static fn():string=>'2026-07-14T12:02:00+00:00');$t->same('unbound_legacy_revision',$manager->compatibilityDiagnostics($manager->head('acme','legacy','author'))[0]->code());$t->throws(static fn()=>$manager->approve('acme','legacy',1,'legacy-approve','reviewer'),PanelStudioSchemaException::class);$rebind=$manager->saveDraft($document,$definition,1,'legacy-rebind','author');$t->same('bound',$rebind->bindingStatus());$manager->approve('acme','legacy',2,'legacy-approved','reviewer');$manager->publish('acme','legacy',3,'legacy-published','author');$t->same('bound',$manager->published('acme','legacy','author')?->bindingStatus());
})->tag('panel','studio','hydration','tamper','legacy','migration','security')->isolation('case')->maxMillis(8000);

test('Trusted Studio platform facades conformance and manifests expose the executable contract truthfully',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults('release-1');$materializer=new PanelStudioMaterializer();$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('trusted-studio-platform'),'authentication'=>false,'media'=>false,'studio'=>['authorization'=>static fn():bool=>true,'registry'=>$registry,'materializer'=>$materializer]]);
	$t->same($registry,$platform->studioRegistry());$t->same($materializer,$platform->studioMaterializer());$t->same($registry,$platform->studio()->registry());$t->same($materializer,$platform->studio()->materializer());$manifest=$platform->manifest();$studioManifest=$platform->studio()->manifest();$t->isTrue($manifest->ready('studio'));$t->isTrue($manifest->domain('studio')['features']['schema_registry']);$t->isTrue($manifest->domain('studio')['features']['materializer']);$t->isTrue($manifest->domain('studio')['features']['visual_runtime']);$t->same(4,$studioManifest['version']);$t->isTrue($studioManifest['capabilities']['artifact_bound_publication']);$t->isTrue($studioManifest['capabilities']['complete_definition_kind_coverage']);$t->isTrue($studioManifest['capabilities']['read_only_boards_without_mutation_handlers']);$t->isFalse($studioManifest['capabilities']['visual_editor_runtime']);$t->isFalse($studioManifest['capabilities']['action_builders_execute_mutations']);
	$t->same('release-1',PanelDeveloperToolkit::studioRegistry('release-1')->version());$t->instanceOf(PanelStudioMaterializer::class,PanelDeveloperToolkit::studioMaterializer());$t->instanceOf(PanelStudioPageBundle::class,PanelDeveloperToolkit::studioMaterialize(dp_panel_studio_trusted_tree())->root());
	$base=static fn(string $root,array $studio):array=>['state_root'=>$root,'authentication'=>false,'media'=>false,'studio'=>$studio];$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-invalid-registry'),['registry'=>new stdClass()])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-invalid-materializer'),['materializer'=>new stdClass()])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-invalid-schemas'),['schemas'=>'field'])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-invalid-schema-entry'),['schemas'=>[['schema'=>new stdClass()]]])),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-invalid-registry-version'),['registry_version'=>[]])),InvalidArgumentException::class);$boardSchema=PanelStudioComponentSchema::make('board',[],[],0,0,'sibling');$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-invalid-schema-provenance'),['schemas'=>[['schema'=>$boardSchema,'provider'=>[]]]])),InvalidArgumentException::class);
	$runner=new PanelAdapterConformanceRunner();$report=$runner->run(PanelAdapterConformanceCatalog::studioStore(),new PanelInMemoryStudioStore(),['allow_destructive'=>true]);$t->isTrue($report->passed());$t->same(0,$report->summary()['failed']);
	$encoded=json_encode([$platform->studio(),$registry,$materializer],JSON_THROW_ON_ERROR);$t->notContains('Closure',$encoded);$t->notContains('visual_editor_runtime":true',$encoded);
})->tag('panel','studio','platform','conformance','manifest','scorched-earth')->isolation('case')->maxMillis(10000);
