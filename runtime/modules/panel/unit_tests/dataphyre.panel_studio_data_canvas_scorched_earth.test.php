<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
use Dataphyre\Panel\PanelDataSurfaceRegistry;
use Dataphyre\Panel\PanelDataSurfaceType;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPageBundle;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioSchemaException;
use Dataphyre\Panel\PanelStudioSchemaRegistry;
use Dataphyre\Panel\PanelStudioVisualRuntime;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array<string,mixed> */
function dp_panel_studio_data_surface_node(string $surface,string $key='orders_canvas',array $replace=[]):array{
	$properties=[
		'label'=>'Orders canvas','resource'=>'orders','source'=>'studio_data','surface'=>$surface,
		'fields'=>['id','title','description','parent_id','source_id','target_id','row','column','value','group','start','end','progress','latitude','longitude','x','y','width','height','color','status'],
		'stable_key'=>'id','slots'=>[
			'title'=>'title','description'=>'description','parent'=>'parent_id','source'=>'source_id','target'=>'target_id',
			'row'=>'row','column'=>'column','value'=>'value','group'=>'group','start'=>'start','end'=>'end','progress'=>'progress',
			'latitude'=>'latitude','longitude'=>'longitude','x'=>'x','y'=>'y','width'=>'width','height'=>'height','color'=>'color','cross_filter'=>'status',
		],
	];
	return['kind'=>'data_surface','key'=>$key,'properties'=>array_replace($properties,$replace),'children'=>[]];
}

/** @return list<string> */
function dp_panel_studio_data_surface_codes(array $diagnostics):array{return array_map(static fn($diagnostic):string=>$diagnostic->code(),$diagnostics);}

test('Studio schema and materializer own every basic and advanced DataSurface type',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();
	$t->same(3,$registry->manifest()['version']);$t->same(3,$materializer->manifest()['version']);$t->notNull($registry->schema('data_surface'));$t->isTrue(in_array('data_surface',PanelStudioDefinition::KINDS,true));
	foreach(PanelDataSurfaceType::cases()as$type){
		$definition=PanelStudioDefinition::from(dp_panel_studio_data_surface_node($type->value,'surface_'.$type->value));$validation=$registry->validate($definition);$t->isTrue($validation->valid(),$type->value);$runtime=$materializer->materialize($definition,$registry);$surface=$runtime->root();$t->instanceOf(PanelDataSurfaceDefinition::class,$surface,$type->value);$t->same($type,$surface->surface(),$type->value);$t->same($type->advanced(),$surface->canvas()!==null,$type->value);$t->same('studio_data',$surface->source());$t->isFalse($surface->jsonSerialize()['query_resolver']);$t->same('data_surface_definition',$runtime->manifest()['builder_contract']['root']);
	}
	$manifest=$materializer->manifest();$t->same('typed_callback_free_definition_with_host_bound_source',$manifest['data_surface_output']);$t->isTrue($manifest['security']['data_surface_contract_preflight']);$t->isTrue($manifest['security']['data_source_binding_required']);$t->isFalse($manifest['security']['query_resolvers']);
})->tag('panel','studio','data-surface','materialization','scorched-earth')->isolation('case')->maxMillis(12000);

test('Studio DataSurface semantic and interaction contracts fail before materialization',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();
	$cases=[
		dp_panel_studio_data_surface_node('pivot','bad_pivot',['fields'=>['id','value'],'slots'=>['value'=>'value']]),
		dp_panel_studio_data_surface_node('graph','bad_graph_edit',['editable'=>true]),
		dp_panel_studio_data_surface_node('map','bad_cross_filter',['cross_filter_group'=>'orders']),
		dp_panel_studio_data_surface_node('spreadsheet','bad_frozen',['fields'=>['id'],'slots'=>[],'frozen_fields'=>2]),
	];
	foreach($cases as$case){$diagnostics=$materializer->diagnose($case,$registry);$t->isTrue(in_array('data_surface_contract_invalid',dp_panel_studio_data_surface_codes($diagnostics),true),(string)$case['key']);$t->throws(static fn()=>$materializer->materialize($case,$registry),PanelStudioSchemaException::class);}
	$invalid=dp_panel_studio_data_surface_node('table','invalid_surface');$invalid['properties']['surface']='unknown';$validation=$registry->validate($invalid);$t->isFalse($validation->valid());$t->isTrue(in_array('property_not_in_enum',dp_panel_studio_data_surface_codes($validation->diagnostics()),true));
})->tag('panel','studio','data-surface','validation','security','scorched-earth')->isolation('case')->maxMillis(6000);

test('Studio page bundles expose and explicitly register host-bound DataSurfaces',static function(Context $t):void{
	$page=['kind'=>'page','key'=>'operations','properties'=>['label'=>'Operations'],'children'=>[
		dp_panel_studio_data_surface_node('table','orders_table'),dp_panel_studio_data_surface_node('heatmap','orders_heatmap'),
	]];$bundle=(new PanelStudioMaterializer())->materialize($page,PanelStudioSchemaRegistry::defaults())->root();$t->instanceOf(PanelStudioPageBundle::class,$bundle);$t->same(2,count($bundle->dataSurfaces()));$t->same(2,$bundle->jsonSerialize()['data_surface_count']);$t->same('data_surface_definition',$bundle->jsonSerialize()['surfaces']['root.children[0]']);$t->isTrue($bundle->jsonSerialize()['runtime']['data_surface_registerable']);
	$sources=(new PanelDataSourceRegistry())->register('studio_data',new PanelArrayDataSource([['id'=>'one','tenant_id'=>'tenant','title'=>'One']],['name'=>'studio_data']));$runtime=new PanelDataSurfaceRegistry($sources,new PanelDataSurfaceIntentSigner(['studio'=>str_repeat('s',32)],'studio',static fn():int=>1000),static fn():bool=>true);$host=new PanelManager();$t->same($bundle->page(),$bundle->registerAll($host,$runtime));$t->isTrue($runtime->has('orders_table'));$t->isTrue($runtime->has('orders_heatmap'));$t->same($bundle->page(),$host->getPage('operations'));$t->throws(static fn()=>$bundle->registerDataSurfaces($runtime),LogicException::class);
})->tag('panel','studio','data-surface','page-bundle','registration','scorched-earth')->isolation('case')->maxMillis(6000);

test('Studio visual runtime renders every advanced DataCanvas through first-party SSR without source execution',static function(Context $t):void{
	$runtime=new PanelStudioVisualRuntime();$manager=new PanelStudioManager(new Dataphyre\Panel\PanelInMemoryStudioStore(),PanelStudioPolicy::permit(static fn():bool=>true),visualRuntime:$runtime);
	foreach(PanelDataSurfaceType::cases()as$index=>$type){if(!$type->advanced()){continue;}$definition=PanelStudioDefinition::from(dp_panel_studio_data_surface_node($type->value,'visual_'.$type->value));$session=PanelStudioEditor::open($manager,PanelStudioDocument::make('tenant-canvas','canvas-'.$index,'Canvas '.$type->value),'designer',$definition);$preview=PanelStudioEditor::visualPreview($session);$surface=$preview->surface('root');$t->notNull($surface,$type->value);$t->isFalse($surface?->failed()??true,$type->value);$t->same('data_surface_definition',$surface?->symbol(),$type->value);$content=$surface?->result()?->content()??'';$t->contains('dp-data-surface--'.$type->value,$content,$type->value);$t->contains('data-dp-data-surface-version="2"',$content,$type->value);$t->notContains('<script src=',$content,$type->value);}
	$manifest=$runtime->manifest();$t->same(2,$manifest['version']);$t->isTrue($manifest['capabilities']['advanced_data_canvas_preview']);$t->isFalse($manifest['security']['data_sources_executed']);
})->tag('panel','studio','data-surface','visual-runtime','advanced-canvas','scorched-earth')->isolation('case')->maxMillis(30000);

test('Studio editor exposes DataSurface composition and typed inspection in SSR and progressive models',static function(Context $t):void{
	$manager=new PanelStudioManager(new Dataphyre\Panel\PanelInMemoryStudioStore(),PanelStudioPolicy::permit(static fn():bool=>true));$page=PanelStudioDefinition::from(['kind'=>'page','key'=>'operations','properties'=>['label'=>'Operations'],'children'=>[dp_panel_studio_data_surface_node('gantt')]]);$session=PanelStudioEditor::open($manager,PanelStudioDocument::make('tenant-editor','data-editor','Data editor'),'designer',$page);$session->apply(Dataphyre\Panel\PanelStudioEditorCommand::select('operations/orders_canvas'));
	$html=PanelStudioEditor::render($session,PanelStudioEditorOptions::make(['action_url'=>'/studio','csrf_name'=>'csrf','csrf_token'=>str_repeat('t',32)]));$t->contains('Data canvases',$html);$t->contains('data-dp-studio-add="data_surface"',$html);$t->contains('data-dp-studio-property="surface"',$html);$t->contains('<strong>Gantt</strong>',$html);$manifest=PanelStudioEditor::manifest($session);$t->same(6,$manifest['version']);$t->same(3,$manifest['renderer']['version']);$t->isTrue($manifest['renderer']['contracts']['data_surface_inspector']);
})->tag('panel','studio','data-surface','editor','accessibility','scorched-earth')->isolation('case')->maxMillis(8000);
