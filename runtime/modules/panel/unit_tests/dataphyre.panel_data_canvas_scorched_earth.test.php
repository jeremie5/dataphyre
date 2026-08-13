<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelArrayDataSource;
use Dataphyre\Panel\PanelDataCanvasModel;
use Dataphyre\Panel\PanelDataCanvasProjector;
use Dataphyre\Panel\PanelDataCanvasSpec;
use Dataphyre\Panel\PanelDataPage;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelDataResult;
use Dataphyre\Panel\PanelDataSource;
use Dataphyre\Panel\PanelDataSourceRegistry;
use Dataphyre\Panel\PanelDataSurfaceContext;
use Dataphyre\Panel\PanelDataSurfaceDefinition;
use Dataphyre\Panel\PanelDataSurfaceException;
use Dataphyre\Panel\PanelDataSurfaceGuard;
use Dataphyre\Panel\PanelDataSurfaceIntentSigner;
use Dataphyre\Panel\PanelDataSurfaceInteraction;
use Dataphyre\Panel\PanelDataSurfaceProjection;
use Dataphyre\Panel\PanelDataSurfaceRange;
use Dataphyre\Panel\PanelDataSurfaceRegistry;
use Dataphyre\Panel\PanelDataSurfaceRenderer;
use Dataphyre\Panel\PanelDataSurfaceType;
use Dataphyre\Panel\PanelDataSurfaceWindowRequest;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return list<array<string,mixed>> */
function dp_canvas_rows():array{return[
	['id'=>'a','tenant_id'=>'north','title'=>'Intake','description'=>'Capture demand','parent_id'=>null,'source'=>'intake','target'=>'review','row'=>'east','column'=>'review','value'=>10,'start'=>'2026-07-01T09:00:00Z','end'=>'2026-07-03T17:00:00Z','progress'=>35,'latitude'=>45.50,'longitude'=>-73.56,'x'=>0,'y'=>0,'width'=>2,'height'=>1,'status'=>'review'],
	['id'=>'b','tenant_id'=>'north','title'=>'Review','description'=>'Assess risk','parent_id'=>'a','source'=>'review','target'=>'pack','row'=>'east','column'=>'paid','value'=>20,'start'=>'2026-07-03T09:00:00Z','end'=>'2026-07-07T17:00:00Z','progress'=>70,'latitude'=>43.65,'longitude'=>-79.38,'x'=>2,'y'=>0,'width'=>1,'height'=>2,'status'=>'paid'],
	['id'=>'c','tenant_id'=>'north','title'=>'Pack','description'=>'Prepare shipment','parent_id'=>'b','source'=>'pack','target'=>'ship','row'=>'west','column'=>'paid','value'=>'invalid','start'=>'2026-07-07T09:00:00Z','end'=>'2026-07-09T17:00:00Z','progress'=>90,'latitude'=>49.28,'longitude'=>-123.12,'x'=>0,'y'=>2,'width'=>2,'height'=>1,'status'=>'paid'],
	['id'=>'d','tenant_id'=>'north','title'=>'Ship','description'=>'Move parcel','parent_id'=>'b','source'=>'ship','target'=>'done','row'=>'west','column'=>'paid','value'=>40,'start'=>'2026-07-09T09:00:00Z','end'=>'2026-07-12T17:00:00Z','progress'=>100,'latitude'=>51.05,'longitude'=>-114.07,'x'=>2,'y'=>2,'width'=>2,'height'=>2,'status'=>'paid'],
];}

function dp_canvas_projection():PanelDataSurfaceProjection{return PanelDataSurfaceProjection::make(
	['id','title','description','parent_id','source','target','row','column','value','start','end','progress','latitude','longitude','x','y','width','height','status'],'id',
	['title'=>'title','description'=>'description','parent'=>'parent_id','source'=>'source','target'=>'target','row'=>'row','column'=>'column','value'=>'value','start'=>'start','end'=>'end','progress'=>'progress','latitude'=>'latitude','longitude'=>'longitude','x'=>'x','y'=>'y','width'=>'width','height'=>'height','cross_filter'=>'status'],
	['title'=>'Stage','row'=>'Region','column'=>'State','value'=>'Amount']
);}

/** @return array{registry:PanelDataSurfaceRegistry,definition:PanelDataSurfaceDefinition,context:PanelDataSurfaceContext,signer:PanelDataSurfaceIntentSigner,source:PanelDataSource,envelopes:array<int,array<string,mixed>>} */
function dp_canvas_fixture(string $surface,array $canvas=[],?array $rows=null,?callable $authorize=null,?PanelDataSurfaceRange $range=null):array {
	$spy=new class($rows??dp_canvas_rows())implements PanelDataSource{
		public array $queries=[];private PanelArrayDataSource $inner;
		public function __construct(array $rows){$this->inner=new PanelArrayDataSource($rows,['name'=>'canvas_rows']);}
		public function query(PanelDataQuery $query):PanelDataResult{$this->queries[]=$query;return$this->inner->query($query);}
		public function find(string|int $id,?PanelDataQuery $scope=null):mixed{return$this->inner->find($id,$scope);}
		public function capabilities():array{return$this->inner->capabilities();}
	};
	$envelopes=[];$authorize??=static function(array$envelope)use(&$envelopes):bool{$envelopes[]=$envelope;return true;};
	$signer=new PanelDataSurfaceIntentSigner(['canvas'=>str_repeat('c',32)],'canvas',static fn():int=>1000);
	$definition=PanelDataSurfaceDefinition::make('workflow_canvas','workflow','workflow_source',$surface,dp_canvas_projection(),$range??PanelDataSurfaceRange::make(0,10,0,0),null,['title'=>'Workflow','description'=>'Operational flow','empty_message'=>'No workflow records.','endpoint'=>'/panel/data-canvas','canvas'=>$canvas]);
	$registry=(new PanelDataSurfaceRegistry((new PanelDataSourceRegistry())->register('workflow_source',$spy),$signer,$authorize))->register($definition);
	return['registry'=>$registry,'definition'=>$definition,'context'=>PanelDataSurfaceContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'operator']),'signer'=>$signer,'source'=>$spy,'envelopes'=>&$envelopes];
}

/** @return array{header:array<string,mixed>,payload:array<string,mixed>} */
function dp_canvas_decode_intent(string $token):array {[$header,$payload]=explode('.',$token,3);return['header'=>json_decode((string)PanelDataSurfaceGuard::decode($header),true,8,JSON_THROW_ON_ERROR),'payload'=>json_decode((string)PanelDataSurfaceGuard::decode($payload),true,32,JSON_THROW_ON_ERROR)];}

function dp_canvas_legacy_intent(string $token,string $secret):string {
	[$head,$body]=array_slice(explode('.',$token),0,2);$header=json_decode((string)PanelDataSurfaceGuard::decode($head),true,8,JSON_THROW_ON_ERROR);$payload=json_decode((string)PanelDataSurfaceGuard::decode($body),true,32,JSON_THROW_ON_ERROR);$header['v']=1;$payload['v']=1;unset($payload['definition_fingerprint']);$input=PanelDataSurfaceGuard::encode(PanelDataSurfaceGuard::canonicalJson($header)).'.'.PanelDataSurfaceGuard::encode(PanelDataSurfaceGuard::canonicalJson($payload));return$input.'.'.PanelDataSurfaceGuard::encode(hash_hmac('sha256',$input,$secret,true));
}

/** @param list<array<string,mixed>> $entries */
function dp_canvas_find(array $entries,string $key,mixed $value):?array {foreach($entries as$entry){if(($entry[$key]??null)===$value){return$entry;}}return null;}

test('advanced canvas specs are typed closed and definition-bound',static function(Context$t):void{
	$projection=dp_canvas_projection();
	foreach(['spreadsheet','pivot','tree','graph','gantt','heatmap','map','canvas']as$surface){$spec=PanelDataCanvasSpec::make($surface,$projection);$t->same($surface,$spec->surface()->value);$t->same(64,strlen($spec->fingerprint()));$t->isTrue($spec->jsonSerialize()['capabilities']['accessible_ssr']);$t->isTrue(PanelDataSurfaceType::normalize($surface)->advanced());}
	$interactive=PanelDataCanvasSpec::make('pivot',$projection,['aggregate'=>'average','selection'=>'multiple','cross_filter_group'=>'operations','cross_filter_field'=>'status','drill_url'=>'/orders?view=canvas#record','drill_parameter'=>'order','show_legend'=>false]);
	$t->same('average',$interactive->aggregate());$t->same('row',$interactive->roles()['row']);$t->isTrue($interactive->showLabels());$t->same('operations',$interactive->crossFilterGroup());$t->same('status',$interactive->crossFilterField());$t->isTrue($interactive->crossFilterEnabled());$t->same('/orders?view=canvas#record',$interactive->drillUrl());$t->same('order',$interactive->drillParameter());$t->isFalse($interactive->showLegend());
	$query=$interactive->applyCrossFilter(PanelDataQuery::make(),['paid','review']);$t->same('in',$query->filterList()[0]['operator']);$t->same(['paid','review'],$query->filterList()[0]['value']);
	foreach([
		static fn()=>PanelDataCanvasSpec::make('pivot',PanelDataSurfaceProjection::make(['id'])),
		static fn()=>PanelDataCanvasSpec::make('pivot',$projection,['roles'=>['unknown'=>'status']]),
		static fn()=>PanelDataCanvasSpec::make('pivot',$projection,['roles'=>['row'=>'missing']]),
		static fn()=>PanelDataCanvasSpec::make('pivot',$projection,['aggregate'=>'median']),
		static fn()=>PanelDataCanvasSpec::make('pivot',$projection,['selection'=>'several']),
		static fn()=>PanelDataCanvasSpec::make('pivot',$projection,['selection'=>'none','cross_filter_group'=>'operations','cross_filter_field'=>'status']),
		static fn()=>PanelDataCanvasSpec::make('pivot',$projection,['editable'=>true]),
		static fn()=>PanelDataCanvasSpec::make('spreadsheet',$projection,['frozen_fields'=>20]),
		static fn()=>PanelDataCanvasSpec::make('map',$projection,['drill_url'=>'https://evil.test/orders']),
		static fn()=>PanelDataCanvasSpec::make('map',$projection,['unknown'=>true]),
	]as$failure){$t->throws($failure,Throwable::class);}
	$definition=PanelDataSurfaceDefinition::make('canvas','records','source','map',$projection,null,null,['canvas'=>['selection'=>'single']]);$t->same('single',$definition->canvas()?->selection());$t->same(64,strlen($definition->fingerprint()));$t->throws(static fn()=>PanelDataSurfaceDefinition::make('bad','records','source','table',$projection,null,null,['canvas'=>$interactive]),InvalidArgumentException::class);$t->throws(static fn()=>PanelDataSurfaceDefinition::make('bad-object','records','source','table',$projection,null,null,['canvas'=>new stdClass()]),InvalidArgumentException::class);
	$interaction=PanelDataSurfaceInteraction::fromArray(['type'=>'cross_filter','values'=>['paid','paid','review']]);$t->same(['type'=>'cross_filter','values'=>['paid','review']],$interaction->jsonSerialize());
})->tag('panel','data-canvas','contracts','adversarial')->group('framework-coverage');

test('every advanced canvas emits a bounded v2 model and dedicated accessible SSR',static function(Context$t):void{
	$classes=['spreadsheet'=>'dp-data-canvas__spreadsheet','pivot'=>'dp-data-canvas__matrix','tree'=>'dp-data-canvas__tree','graph'=>'dp-data-canvas__graph-stage','gantt'=>'dp-data-canvas__gantt','heatmap'=>'dp-data-canvas__matrix','map'=>'dp-data-canvas__map','canvas'=>'dp-data-canvas__freeform'];
	foreach($classes as$surface=>$class){$fixture=dp_canvas_fixture($surface);$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$window=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context']);$payload=$window->jsonSerialize();$t->same(2,$payload['version']);$t->same('panel_data_canvas_model',$payload['canvas']['type']);$t->same($surface,$payload['canvas']['surface']);$t->same(count($payload['records']),$payload['canvas']['record_count']);$t->same($surface,$window->canvas()?->surface()->value);$html=PanelDataSurfaceRenderer::render($fixture['definition'],$window,$intent);$t->contains($class,$html);$t->contains('data-dp-data-surface-version="2"',$html);$t->contains('"canvas":{',$html);$t->contains('aria-live="polite"',$html);$t->notContains('—',$html);$t->notContains('–',$html);}
	$basic=PanelDataSurfaceDefinition::make('basic','records','source','table',PanelDataSurfaceProjection::make(['id']));$result=new \Dataphyre\Panel\PanelDataSurfaceWindowResult('basic','records',PanelDataSurfaceType::TABLE,$basic->projection(),[],PanelDataSurfaceRange::make(),0,false,false,null,null);$t->same(1,$result->jsonSerialize()['version']);$t->isNull($result->canvas());
})->tag('panel','data-canvas','ssr','accessibility','window')->group('framework-coverage');

test('pivot and heatmap aggregation are deterministic and diagnostic',static function(Context$t):void{
	$fixture=dp_canvas_fixture('pivot',['aggregate'=>'sum']);$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$window=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context']);$model=$window->canvas()?->model()??[];$t->same(2,count($model['rows']));$t->same(2,count($model['columns']));$west=dp_canvas_find($model['rows'],'label','west');$paid=dp_canvas_find($model['columns'],'label','paid');$t->notNull($west);$t->notNull($paid);$cell=null;foreach($model['cells']as$candidate){if($candidate['row_key']===$west['key']&&$candidate['column_key']===$paid['key']){$cell=$candidate;break;}}$t->notNull($cell);$t->same(40.0,$cell['value']);$t->same(2,$cell['count']);$t->same(1,$cell['invalid_values']);$t->same(1.0,$cell['intensity']);
	$average=dp_canvas_fixture('heatmap',['aggregate'=>'average']);$intent=$average['registry']->issue('workflow_canvas',$average['context']);$payload=$average['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$average['context'])->canvas()?->model()??[];$east=dp_canvas_find($payload['rows'],'label','east');$review=dp_canvas_find($payload['columns'],'label','review');$cell=null;foreach($payload['cells']as$candidate){if($candidate['row_key']===$east['key']&&$candidate['column_key']===$review['key']){$cell=$candidate;}}$t->same(10.0,$cell['value']);$t->same('average',$payload['aggregate']);
	$minimum=dp_canvas_fixture('pivot',['aggregate'=>'minimum']);$intent=$minimum['registry']->issue('workflow_canvas',$minimum['context']);$payload=$minimum['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$minimum['context'])->canvas()?->model()??[];$t->same('minimum',$payload['aggregate']);
})->tag('panel','data-canvas','pivot','heatmap','aggregate')->group('framework-coverage');

test('tree projection contains corrupt hierarchy input without recursion leaks',static function(Context$t):void{
	$rows=dp_canvas_rows();$rows[0]['parent_id']='missing';$rows[1]['parent_id']='c';$rows[2]['parent_id']='b';$rows[3]['parent_id']=[];
	$fixture=dp_canvas_fixture('tree',[],$rows);$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$canvas=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context'])->canvas();$t->notNull($canvas);$diagnostics=[];foreach($canvas->diagnostics()as$diagnostic){$diagnostics[$diagnostic['code']]=$diagnostic['count'];}$t->same(1,$diagnostics['invalid_parent']);$t->same(1,$diagnostics['missing_parent']);$t->same(2,$diagnostics['cycle']);$model=$canvas->model();$b=dp_canvas_find($model['nodes'],'key','b');$c=dp_canvas_find($model['nodes'],'key','c');$t->isTrue($b['cycle']);$t->isTrue($c['cycle']);$t->isTrue(in_array('a',$model['roots'],true));$t->isTrue($model['maximum_depth']<=64);
})->tag('panel','data-canvas','tree','cycles','adversarial')->group('framework-coverage');

test('graph schedule map and freeform projections reject malformed records locally',static function(Context$t):void{
	$cases=[
		'graph'=>static function(array&$rows):void{$rows[1]['source']=[];},
		'gantt'=>static function(array&$rows):void{$rows[1]['end']='2026-01-01T00:00:00Z';$rows[2]['progress']=200;},
		'map'=>static function(array&$rows):void{$rows[1]['latitude']=91;$rows[2]['longitude']=INF;},
		'canvas'=>static function(array&$rows):void{$rows[1]['width']=0;$rows[2]['x']=2000000000;},
	];
	foreach($cases as$surface=>$mutate){$rows=dp_canvas_rows();$mutate($rows);if($surface==='map'){$rows[2]['longitude']='not-a-number';}$fixture=dp_canvas_fixture($surface,[],$rows);$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$canvas=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context'])->canvas();$t->notNull($canvas);$t->isTrue($canvas->diagnostics()!==[]);$model=$canvas->model();$collection=match($surface){'graph'=>$model['edges'],'gantt'=>$model['tasks'],'map'=>$model['points'],'canvas'=>$model['items']};$t->isTrue(count($collection)<count($rows));PanelDataSurfaceGuard::assertJson($canvas->jsonSerialize());}
})->tag('panel','data-canvas','geometry','dates','graph','adversarial')->group('framework-coverage');

test('signed cross filtering pushes predicates server side and never discloses values to authorization',static function(Context$t):void{
	$envelopes=[];$authorize=static function(array$envelope)use(&$envelopes):bool{$envelopes[]=$envelope;return true;};$fixture=dp_canvas_fixture('pivot',['selection'=>'multiple','cross_filter_group'=>'workflow','cross_filter_field'=>'status'],$rows=null,$authorize,PanelDataSurfaceRange::make(0,1,0,0));$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$request=PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token(),'interaction'=>['type'=>'cross_filter','values'=>['paid','paid']]]);$t->same(['paid'],$request->interaction()?->values());$window=$fixture['registry']->execute($request,$fixture['context']);$t->same(1,$window->range()->length());$t->same(0,$window->range()->start());$t->same(1,count($fixture['source']->queries));$filter=$fixture['source']->queries[0]->filterList()[0];$t->same('status',$filter['field']);$t->same('paid',$filter['value']);$t->same(['issue','window','interact'],array_column($envelopes,'operation'));$interaction=$envelopes[2];$t->same(1,$interaction['value_count']);$t->same(64,strlen($interaction['values_digest']));$t->notContains('paid',json_encode($interaction,JSON_THROW_ON_ERROR));$t->same('workflow',$interaction['cross_filter_group']);
	$t->notNull($window->nextIntent());$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$window->nextIntent()->token()]),$fixture['context']);$t->same('paid',$fixture['source']->queries[1]->filterList()[0]['value']);
})->tag('panel','data-canvas','cross-filter','pushdown','privacy','authorization')->group('framework-coverage');

test('cross filter denial malformed interactions and unsupported surfaces fail before source reads',static function(Context$t):void{
	$authorize=static fn(array$envelope):bool=>($envelope['operation']??'')!=='interact';$fixture=dp_canvas_fixture('pivot',['selection'=>'single','cross_filter_group'=>'workflow','cross_filter_field'=>'status'],null,$authorize);$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$error=$t->throws(static fn()=>$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token(),'interaction'=>['type'=>'cross_filter','values'=>['paid']]]),$fixture['context']),PanelDataSurfaceException::class);$t->same('transport_denied',$error->publicCode());$t->same(0,count($fixture['source']->queries));
	$plain=dp_canvas_fixture('pivot');$plainIntent=$plain['registry']->issue('workflow_canvas',$plain['context']);$error=$t->throws(static fn()=>$plain['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$plainIntent->token(),'interaction'=>['type'=>'cross_filter','values'=>['paid']]]),$plain['context']),PanelDataSurfaceException::class);$t->same('interaction_unsupported',$error->publicCode());$t->same(0,count($plain['source']->queries));
	foreach([
		['intent'=>'x','interaction'=>[]],['intent'=>'x','interaction'=>['type'=>'other','values'=>[]]],['intent'=>'x','interaction'=>['type'=>'cross_filter','values'=>'paid']],['intent'=>'x','interaction'=>['type'=>'cross_filter','values'=>array_fill(0,101,'x')]],['intent'=>'x','interaction'=>['type'=>'cross_filter','values'=>[new stdClass()]]],['intent'=>'x','interaction'=>['type'=>'cross_filter','values'=>[INF]]],
	]as$payload){$failure=$t->throws(static fn()=>PanelDataSurfaceWindowRequest::fromArray($payload),PanelDataSurfaceException::class);$t->same('interaction_invalid',$failure->publicCode());}
})->tag('panel','data-canvas','cross-filter','fail-closed','adversarial')->group('framework-coverage');

test('definition fingerprints invalidate option drift while legacy v1 tokens drain safely',static function(Context$t):void{
	$fixture=dp_canvas_fixture('map');$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$decoded=dp_canvas_decode_intent($intent->token());$t->same(2,$decoded['header']['v']);$t->same(2,$decoded['payload']['v']);$t->same($fixture['definition']->fingerprint(),$decoded['payload']['definition_fingerprint']);$legacy=dp_canvas_legacy_intent($intent->token(),str_repeat('c',32));$verified=$fixture['signer']->verify($legacy,$fixture['context']);$t->isNull($verified->definitionFingerprint());$t->notContains('definition_fingerprint',array_keys($verified->authorizationEnvelope()));$legacyWindow=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$legacy]),$fixture['context']);$t->same(2,$legacyWindow->jsonSerialize()['version']);$manifest=$fixture['signer']->jsonSerialize();$t->same(2,$manifest['issued_token_schema']);$t->same([1,2],$manifest['verified_token_schemas']);
	$changed=PanelDataSurfaceDefinition::make('workflow_canvas','workflow','workflow_source','map',dp_canvas_projection(),PanelDataSurfaceRange::make(0,10,0,0),null,['title'=>'Changed title','endpoint'=>'/panel/data-canvas','canvas'=>[]]);$fixture['registry']->register($changed,true);$error=$t->throws(static fn()=>$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context']),PanelDataSurfaceException::class);$t->same('intent_stale',$error->publicCode());$t->same(1,count($fixture['source']->queries));
})->tag('panel','data-canvas','signing','migration','stale')->group('framework-coverage');

test('canvas models drill links and presentation assets stay bounded safe and responsive',static function(Context$t):void{
	$fixture=dp_canvas_fixture('map',['selection'=>'single','cross_filter_group'=>'workflow','cross_filter_field'=>'status','drill_url'=>'/orders?from=map#record','drill_parameter'=>'order']);$intent=$fixture['registry']->issue('workflow_canvas',$fixture['context']);$window=$fixture['registry']->execute(PanelDataSurfaceWindowRequest::fromArray(['intent'=>$intent->token()]),$fixture['context']);$html=PanelDataSurfaceRenderer::render($fixture['definition'],$window,$intent);$t->contains('data-dp-data-canvas-select',$html);$t->contains('data-dp-data-canvas-group="workflow"',$html);$t->contains('/orders?from=map&amp;order=a#record',$html);$t->contains('role="listbox"',$html);$t->contains('aria-selected="false"',$html);
	$css=(string)$t->nonPublic(PanelRenderer::class)->invoke('dataSurfaceCss');$js=(string)$t->nonPublic(PanelRenderer::class)->invoke('dataSurfaceRuntimeScript');$t->contains('.dp-data-canvas__freeform',$css);$t->contains('@container dp-data-surface (max-width:600px)',$css);$t->contains('@media(forced-colors:active)',$css);$t->contains('@media print',$css);$t->notContains('overflow-x:auto',$css);$t->contains('dpPanelDataCanvasQueueFilter',$js);$t->contains('payload.interaction=interaction',$js);$t->notContains('innerHTML',$js);$t->notContains('insertAdjacentHTML',$js);
	$t->throws(static fn()=>new PanelDataCanvasModel(PanelDataSurfaceType::TABLE,0,[]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelDataCanvasModel(PanelDataSurfaceType::MAP,1,[],[['code'=>'bad','count'=>2]]),InvalidArgumentException::class);$model=new PanelDataCanvasModel(PanelDataSurfaceType::MAP,0,['points'=>[]]);$t->same(0,$model->recordCount());$t->same('panel_data_canvas_model',$model->jsonSerialize()['type']);
})->tag('panel','data-canvas','responsive','xss','assets','bounds')->group('framework-coverage');
