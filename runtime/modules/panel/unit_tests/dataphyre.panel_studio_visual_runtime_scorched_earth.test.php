<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSensitiveDataSanitizer;
use Dataphyre\Panel\PanelStudioArtifact;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioMaterialization;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioPreviewSigner;
use Dataphyre\Panel\PanelStudioSchemaRegistry;
use Dataphyre\Panel\PanelStudioVisualDataset;
use Dataphyre\Panel\PanelStudioVisualPreview;
use Dataphyre\Panel\PanelStudioVisualRuntime;
use Dataphyre\Panel\PanelStudioVisualSurface;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

suite('Panel Studio visual runtime contracts')
	->coverageMemoryLimit('2G');

function dp_panel_studio_visual_definition():array{
	return['kind'=>'page','key'=>'operations','properties'=>['label'=>'Operations studio','description'=>'Trusted visual composition','layout'=>'masonry'],'children'=>[
		['kind'=>'form','key'=>'order_form','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'field','key'=>'customer','properties'=>['label'=>'Customer','default'=>'Avery Stone'],'children'=>[]],
			['kind'=>'form_section','key'=>'identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
				['kind'=>'field','key'=>'email','properties'=>['label'=>'Email','type'=>'email','required'=>true],'children'=>[]],
			]],
			['kind'=>'tabs','key'=>'form_tabs','properties'=>['layout'=>'brick'],'children'=>[
				['kind'=>'tab','key'=>'details','properties'=>['label'=>'Details','columns'=>1],'children'=>[
					['kind'=>'field','key'=>'notes','properties'=>['label'=>'Notes','type'=>'textarea'],'children'=>[]],
				]],
			]],
		]],
		['kind'=>'table','key'=>'orders_table','properties'=>['per_page'=>10,'density'=>'compact'],'children'=>[
			['kind'=>'column','key'=>'title','properties'=>['label'=>'Order','searchable'=>true],'children'=>[]],
			['kind'=>'column','key'=>'total','properties'=>['label'=>'Total','type'=>'money','currency'=>'CAD'],'children'=>[]],
			['kind'=>'filter','key'=>'search_filter','properties'=>['label'=>'Search','column'=>'title'],'children'=>[]],
			['kind'=>'filters','key'=>'filter_set','properties'=>['layout'=>'brick'],'children'=>[
				['kind'=>'filter','key'=>'state_filter','properties'=>['label'=>'State','type'=>'select','options'=>['active'=>'Active','paused'=>'Paused']],'children'=>[]],
			]],
			['kind'=>'table_view','key'=>'all_view','properties'=>['label'=>'All','default'=>true],'children'=>[]],
			['kind'=>'table_views','key'=>'view_set','properties'=>['layout'=>'masonry'],'children'=>[
				['kind'=>'table_view','key'=>'active_view','properties'=>['label'=>'Active','filters'=>['state_filter'=>'active']],'children'=>[]],
			]],
		]],
		['kind'=>'data_surface','key'=>'order_matrix','properties'=>[
			'label'=>'Order matrix','surface'=>'pivot','fields'=>['id','title','region','quarter','value','status'],
			'slots'=>['title'=>'title','row'=>'region','column'=>'quarter','value'=>'value','cross_filter'=>'status'],
			'selection'=>'multiple','cross_filter_group'=>'orders','cross_filter_field'=>'status',
		],'children'=>[]],
		['kind'=>'show','key'=>'order_show','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'infolist_entry','key'=>'summary_entry','properties'=>['label'=>'Summary'],'children'=>[]],
			['kind'=>'show_section','key'=>'show_identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
				['kind'=>'infolist_entry','key'=>'owner','properties'=>['label'=>'Owner','copyable'=>true],'children'=>[]],
			]],
		]],
		['kind'=>'infolist','key'=>'order_infolist','properties'=>['columns'=>2,'layout'=>'brick'],'children'=>[
			['kind'=>'infolist_entry','key'=>'infolist_status','properties'=>['label'=>'Status','type'=>'badge'],'children'=>[]],
		]],
		['kind'=>'board','key'=>'fulfilment','properties'=>['label'=>'Fulfilment','status_field'=>'state','layout'=>'masonry','card_layout'=>'brick'],'children'=>[
			['kind'=>'board_column','key'=>'queued','properties'=>['label'=>'Queue','status'=>'Queued','accepts_moves'=>false],'children'=>[]],
			['kind'=>'board_column','key'=>'done','properties'=>['label'=>'Done','status'=>'Done','tone'=>'success','from'=>['Queued'],'transition'=>'complete'],'children'=>[]],
		]],
		['kind'=>'actions','key'=>'row_actions','properties'=>['label'=>'Actions','tone'=>'primary'],'children'=>[
			['kind'=>'action','key'=>'inspect','properties'=>['label'=>'Inspect','style'=>'outline'],'children'=>[]],
		]],
		['kind'=>'widget_grid','key'=>'metrics','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'widget','key'=>'volume','properties'=>['label'=>'Volume','value'=>42,'tone'=>'primary'],'children'=>[]],
			['kind'=>'widget','key'=>'trend','properties'=>['label'=>'Trend','type'=>'chart','chart_type'=>'bar','labels'=>['A','B'],'data'=>[2,5]],'children'=>[]],
		]],
		['kind'=>'tabs','key'=>'page_tabs','properties'=>['layout'=>'brick'],'children'=>[
			['kind'=>'tab','key'=>'page_tab','properties'=>['label'=>'Page tab','columns'=>1],'children'=>[
				['kind'=>'field','key'=>'tab_value','properties'=>['label'=>'Tab value','type'=>'number'],'children'=>[]],
			]],
		]],
		['kind'=>'toolbar','key'=>'page_toolbar','properties'=>['label'=>'Toolbar','layout'=>'brick'],'children'=>[
			['kind'=>'action','key'=>'refresh','properties'=>['label'=>'Refresh'],'children'=>[]],
			['kind'=>'actions','key'=>'bulk_actions','properties'=>['label'=>'Bulk'],'children'=>[
				['kind'=>'action','key'=>'archive','properties'=>['label'=>'Archive','tone'=>'warning'],'children'=>[]],
			]],
		]],
		['kind'=>'workflow','key'=>'order_release','properties'=>['label'=>'Order release','initial_state'=>'draft'],'children'=>[
			['kind'=>'workflow_state','key'=>'draft','properties'=>['label'=>'Draft','draft'=>true],'children'=>[]],
			['kind'=>'workflow_state','key'=>'review','properties'=>['label'=>'Review','sla_seconds'=>3600,'assignment_roles'=>['reviewer']],'children'=>[]],
			['kind'=>'workflow_state','key'=>'done','properties'=>['label'=>'Done','terminal'=>true,'tone'=>'success'],'children'=>[]],
			['kind'=>'workflow_transition','key'=>'submit','properties'=>['label'=>'Submit','from_states'=>['draft'],'to_state'=>'review','roles'=>['author']],'children'=>[]],
			['kind'=>'workflow_transition','key'=>'approve','properties'=>['label'=>'Approve','from_states'=>['review'],'to_state'=>'done','requires_approval'=>true,'approval_quorum'=>2,'approval_roles'=>['manager']],'children'=>[]],
		]],
		['kind'=>'navigation','key'=>'workspace_navigation','properties'=>['label'=>'Workspace'],'children'=>[
			['kind'=>'navigation_group','key'=>'workspace','properties'=>['label'=>'Workspace','description'=>'Primary tools'],'children'=>[
				['kind'=>'navigation_item','key'=>'orders','properties'=>['label'=>'Orders','url'=>'/orders','badge'=>12],'children'=>[]],
			]],
			['kind'=>'navigation_item','key'=>'settings','properties'=>['label'=>'Settings','url'=>'/settings'],'children'=>[
				['kind'=>'navigation_item','key'=>'profile','properties'=>['label'=>'Profile','url'=>'/settings/profile'],'children'=>[]],
			]],
		]],
	]];
}

/** @return array<string,array<string,mixed>> */
function dp_panel_studio_visual_kind_roots():array{
	$roots=[];$visit=function(array $node)use(&$roots,&$visit):void{$roots[$node['kind']]??=$node;foreach($node['children']as$child){$visit($child);}};$visit(dp_panel_studio_visual_definition());return$roots;
}

/** @return array{0:PanelStudioManager,1:PanelStudioVisualRuntime,2:PanelInMemoryStudioStore} */
function dp_panel_studio_visual_manager(bool $signed=true):array{
	$store=new PanelInMemoryStudioStore();$runtime=new PanelStudioVisualRuntime();$signer=$signed?new PanelStudioPreviewSigner(['visual'=>str_repeat('V',32)],'visual',static fn():int=>1784040000,static fn():string=>'visual_nonce_00000001'):null;
	$manager=new PanelStudioManager($store,PanelStudioPolicy::permit(static fn():bool=>true),previewSigner:$signer,clock:static fn():string=>'2026-07-14T12:00:00+00:00',visualRuntime:$runtime);return[$manager,$runtime,$store];
}

test('Studio visual datasets are deterministic bounded redacted and value-private',static function(Context $t):void{
	$dataset=new PanelStudioVisualDataset([['id'=>1,'password'=>'do-not-serialize','nested'=>['api_token'=>'also-secret'],'title'=>'Order']],null);
	$t->same(PanelSensitiveDataSanitizer::REDACTED,$dataset->record()['password']);$t->same(PanelSensitiveDataSanitizer::REDACTED,$dataset->record()['nested']['api_token']);$t->same($dataset->record(),$dataset->records()[0]);$t->isFalse($dataset->synthetic());$manifest=$dataset->jsonSerialize();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->same(1,$manifest['record_count']);$t->same(4,$manifest['field_count']);$t->isFalse($manifest['values_serialized']);$t->notContains('do-not-serialize',$encoded);$t->same($dataset->digest(),(new PanelStudioVisualDataset([['id'=>1,'nested'=>['api_token'=>'x'],'password'=>'y','title'=>'Order']]))->digest());
	$sample=PanelStudioVisualDataset::sample(PanelStudioDefinition::from(dp_panel_studio_visual_definition()));$t->isTrue($sample->synthetic());$t->same(2,count($sample->records()));$t->same('Queued',$sample->records()[0]['state']);$t->same('Done',$sample->records()[1]['state']);$t->same('preview@example.test',$sample->record()['email']);$t->same(123.45,$sample->record()['total']);
	$select=PanelStudioVisualDataset::sample(PanelStudioDefinition::from(['kind'=>'field','key'=>'market','properties'=>['type'=>'select','options'=>['ca'=>'Canada']],'children'=>[]]));$t->same('ca',$select->record()['market']);$list=PanelStudioVisualDataset::sample(PanelStudioDefinition::from(['kind'=>'field','key'=>'market','properties'=>['type'=>'radio','options'=>['Canada']],'children'=>[]]));$t->same('Canada',$list->record()['market']);$empty=PanelStudioVisualDataset::sample(PanelStudioDefinition::from(['kind'=>'field','key'=>'market','properties'=>['type'=>'select'],'children'=>[]]));$t->same('Option A',$empty->record()['market']);
	$t->throws(static fn()=>new PanelStudioVisualDataset(['bad'=>[]]),LengthException::class);$t->throws(static fn()=>new PanelStudioVisualDataset(array_fill(0,PanelStudioVisualDataset::MAX_RECORDS+1,[])),LengthException::class);$t->throws(static fn()=>new PanelStudioVisualDataset([[1,2]]),InvalidArgumentException::class);
	$wide=[];for($i=0;$i<=PanelStudioVisualDataset::MAX_FIELDS;$i++){$wide['field_'.$i]=$i;}$t->throws(static fn()=>new PanelStudioVisualDataset([$wide]),LengthException::class);$t->throws(static fn()=>new PanelStudioVisualDataset([['nested'=>range(1,PanelStudioVisualDataset::MAX_FIELDS+1)]]),LengthException::class);
	$t->throws(static fn()=>new PanelStudioVisualDataset([['object'=>new stdClass()]]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualDataset([['callback'=>static fn():bool=>true]]),InvalidArgumentException::class);$handle=fopen('php://memory','r');$t->throws(static fn()=>new PanelStudioVisualDataset([['resource'=>$handle]]),InvalidArgumentException::class);fclose($handle);$t->throws(static fn()=>new PanelStudioVisualDataset([['number'=>NAN]]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualDataset([['text'=>str_repeat('x',PanelStudioVisualDataset::MAX_STRING_BYTES+1)]]),LengthException::class);$t->throws(static fn()=>new PanelStudioVisualDataset([['text'=>"\xB1\x31"]]),InvalidArgumentException::class);
	$deep='end';for($i=0;$i<PanelStudioVisualDataset::MAX_DEPTH+2;$i++){$deep=['next'=>$deep];}$t->throws(static fn()=>new PanelStudioVisualDataset([['deep'=>$deep]]),LengthException::class);
	$record=[];for($i=0;$i<PanelStudioVisualDataset::MAX_FIELDS;$i++){$record['f'.$i]=$i;}$t->throws(static fn()=>new PanelStudioVisualDataset(array_fill(0,15,$record)),LengthException::class);
})->tag('panel','studio','visual-runtime','dataset','security','scorched-earth')->isolation('case')->maxMillis(5000);

test('Studio visual datasets use stable ISO examples for temporal field types',static function(Context $t):void{
	$samples=[];
	foreach(['date','datetime','time']as$type){
		$key='sample_'.$type;
		$definition=PanelStudioDefinition::from([
			'kind'=>'field',
			'key'=>$key,
			'properties'=>['type'=>$type],
			'children'=>[],
		]);
		$samples[$type]=PanelStudioVisualDataset::sample($definition)->record()[$key];
	}
	$t->same([
		'date'=>'2026-07-14',
		'datetime'=>'2026-07-14T12:00:00+00:00',
		'time'=>'12:00',
	],$samples);
})->tag('panel','studio','visual-runtime','dataset','temporal','scorched-earth')->isolation('case')->maxMillis(5000);

test('Studio visual runtime renders unsaved selected page surfaces with inert conditional output',static function(Context $t):void{
	[$manager,$runtime]=dp_panel_studio_visual_manager();$document=PanelStudioDocument::make('tenant-visual','visual-session','Visual session');$session=PanelStudioEditor::open($manager,$document,'visual',PanelStudioDefinition::from(dp_panel_studio_visual_definition()));$session->apply(Dataphyre\Panel\PanelStudioEditorCommand::select('operations/order_form/identity/email'));
	$request=PanelRequest::fromArray(['query'=>['density'=>'compact','page'=>1,'unsafe'=>new stdClass()],'tenant'=>'tenant-visual','user'=>['id'=>'must-not-propagate']]);$preview=PanelStudioEditor::visualPreview($session,null,$request);$t->same($runtime,$manager->visualRuntime());$t->isTrue($manager->hasVisualRuntime());$t->same('session',$preview->source());$t->same(null,$preview->revision());$t->same('operations/order_form/identity/email',$preview->selectedPath());$t->isTrue(count($preview->surfaces())>=11);$t->notNull($preview->surface('root.children[0].children[1].children[0]'));$t->isTrue($preview->surface('root.children[0].children[1].children[0]')?->selected()??false);$t->same(null,$preview->surface('root.children[99]'));
	$html=$preview->html();foreach(['Panel Studio visual runtime','sandbox=""','srcdoc=','inert','Content-Security-Policy','Operations studio','data-selected="true"']as$needle){$t->contains($needle,$html);}$t->notContains('must-not-propagate',$html);$t->contains('dp-studio-visual-manifest',$html);$t->same($html,(string)$preview);$rootContent=$preview->surface('root')?->result()->content()??'';$t->contains('<style data-dp-studio-asset="panel.css">',$rootContent);$t->notContains('<link',$rootContent);$t->notContains('<script',$rootContent);
	$manifest=$preview->jsonSerialize();$json=json_encode($manifest,JSON_THROW_ON_ERROR);$t->same(1,$manifest['version']);$t->same(count($preview->surfaces()),$manifest['surface_count']);$t->isTrue($manifest['refresh']['conditional_get']);$t->isFalse($manifest['content_serialized']);$t->isTrue($manifest['security']['self_contained_frames']);$t->isFalse($manifest['security']['same_origin']);$t->isFalse($manifest['security']['external_assets_loaded']);$t->notContains('Preview record',$json);$t->notContains('srcdoc',$json);
	$t->isFalse($preview->notModified(null));$t->isFalse($preview->notModified(''));$t->isFalse($preview->notModified('"wrong"'));$t->isTrue($preview->notModified($preview->etag()));$t->isTrue($preview->notModified('W/'.$preview->etag()));$fresh=$preview->response();$cached=$preview->response($preview->etag());$t->same(200,$fresh->status());$t->same(304,$cached->status());$t->same('',$cached->content());$t->same($preview->etag(),$fresh->headers()['ETag']);$t->contains("default-src 'none'",$fresh->headers()['Content-Security-Policy']);
	Panel::usePlatform(PanelPlatform::make(['studio.manager'=>$manager]),true);$t->same('session',Panel::renderStudioVisualPreview($session,null,$request)->source());$instance=PanelInstance::make('visual-instance')->usePlatform(PanelPlatform::make(['studio.manager'=>$manager]),true);$t->same('session',$instance->renderStudioVisualPreview($session,null,$request)->source());
})->tag('panel','studio','visual-runtime','session','rendering','scorched-earth')->isolation('case')->maxMillis(12000);

test('Studio visual runtime renders all thirty trusted root kinds as actual Panel surfaces',static function(Context $t):void{
	[$manager]=dp_panel_studio_visual_manager(false);$roots=dp_panel_studio_visual_kind_roots();$t->same(PanelStudioDefinition::KINDS,array_values(array_intersect(PanelStudioDefinition::KINDS,array_keys($roots))));
	foreach(PanelStudioDefinition::KINDS as$index=>$kind){$definition=PanelStudioDefinition::from($roots[$kind]);$document=PanelStudioDocument::make('tenant-kinds','visual-kind-'.$index,'Visual '.str_replace('_',' ',$kind));$session=PanelStudioEditor::open($manager,$document,'visual',$definition);$preview=PanelStudioEditor::visualPreview($session);$surface=$preview->surface('root');$t->notNull($surface,$kind);$t->isFalse($surface?->failed()??true,$kind);$t->same(200,$preview->response()->status(),$kind);$t->contains('sandbox=""',$surface?->frameHtml()??'',$kind);}
	$manifest=$manager->visualRuntime()->manifest();$t->same(PanelStudioDefinition::KINDS,$manifest['supported_kinds']);$t->isTrue($manifest['complete_definition_kind_coverage']);$t->isTrue($manifest['security']['first_party_styles_inlined']);$t->isFalse($manifest['security']['same_origin']);$t->isFalse($manifest['security']['external_assets_loaded']);$t->isFalse($manifest['security']['mutation_authority']);$t->isFalse($manifest['integration']['routes_registered']);$t->same($manifest,(new PanelStudioVisualRuntime())->jsonSerialize());
})->tag('panel','studio','visual-runtime','all-kinds','materialization','scorched-earth')->isolation('case')->maxMillis(30000);

test('Studio signed and published visual previews stay scope artifact and token bound',static function(Context $t):void{
	[$manager,$runtime]=dp_panel_studio_visual_manager();$document=PanelStudioDocument::make('tenant-signed','signed-visual','Signed visual');$session=PanelStudioEditor::open($manager,$document,'visual',PanelStudioDefinition::from(dp_panel_studio_visual_definition()));$receipt=$session->save('save-visual');$t->notNull($receipt);$intent=$session->preview();$t->notNull($intent);
	$signed=$runtime->renderSigned($manager,$intent->token(),'tenant-signed','signed-visual','visual');$t->same('signed',$signed->source());$t->same(1,$signed->revision());$t->same($receipt?->artifactFingerprint(),$signed->materialization()->artifact()->fingerprint());$encoded=json_encode([$signed,$runtime,$manager],JSON_THROW_ON_ERROR);$t->notContains($intent->token(),$encoded);$t->notContains($intent->token(),$signed->html());
	$t->throws(static fn()=>$runtime->renderPublished($manager,'tenant-signed','signed-visual','visual'),OutOfBoundsException::class);$manager->publish('tenant-signed','signed-visual',1,'publish-visual','visual');$published=$runtime->renderPublished($manager,'tenant-signed','signed-visual','visual');$t->same('published',$published->source());$t->same(2,$published->revision());$t->throws(static fn()=>$runtime->renderSigned($manager,$intent->token(),'tenant-signed','signed-visual','visual'),RuntimeException::class);
	$detached=new PanelStudioVisualRuntime();$t->throws(static fn()=>$detached->renderPublished($manager,'tenant-signed','signed-visual','visual'),LogicException::class);$inactive=new PanelStudioManager(new PanelInMemoryStudioStore(),PanelStudioPolicy::permit(static fn():bool=>true));$t->isFalse($inactive->hasVisualRuntime());$t->throws(static fn()=>$inactive->visualRuntime(),LogicException::class);$inactiveSession=PanelStudioEditor::open($inactive,PanelStudioDocument::make('tenant-signed','inactive','Inactive'),'visual',PanelStudioDefinition::from($roots=dp_panel_studio_visual_kind_roots()['field']));$t->throws(static fn()=>PanelStudioEditor::visualPreview($inactiveSession),LogicException::class);
	$clone=$manager->trustedMaintenance(['visual']);$t->same($runtime,$clone->visualRuntime());$editorManifest=PanelStudioEditor::manifest($session);$t->same(6,$editorManifest['version']);$t->isTrue($editorManifest['integration']['visual_runtime_active']);$t->same('panel_studio_manifest.v4',$editorManifest['integration']['manager_contract']);$t->same('panel_studio_visual_runtime',$editorManifest['visual_runtime']['type']);$t->isTrue($manager->manifest()['capabilities']['visual_editor_runtime']);$t->same(4,$manager->manifest()['version']);
})->tag('panel','studio','visual-runtime','signed','published','security','scorched-earth')->isolation('case')->maxMillis(12000);

test('Studio visual platform attachment is explicit cohesive and facade ready',static function(Context $t):void{
	$root=$t->tempDirectory('panel-studio-visual-platform');$platform=PanelPlatform::defaults(['state_root'=>$root,'authentication'=>false,'media'=>false,'studio'=>['authorization'=>static fn():bool=>true,'visual_runtime'=>true]]);$t->instanceOf(PanelStudioVisualRuntime::class,$platform->studioVisualRuntime());$t->same($platform->studioVisualRuntime(),$platform->studio()->visualRuntime());$manifest=$platform->manifest();$t->isTrue($manifest->ready('studio'));$t->isTrue($manifest->domain('studio')['features']['visual_runtime']);$t->isTrue($platform->studio()->manifest()['capabilities']['visual_editor_runtime']);
	$document=PanelStudioDocument::make('tenant-platform','platform-visual','Platform visual');$session=$platform->openStudioEditor($document,'visual',PanelStudioDefinition::from(dp_panel_studio_visual_kind_roots()['widget_grid']));$t->same('session',$platform->renderStudioVisualPreview($session)->source());
	$custom=new PanelStudioVisualRuntime();$customPlatform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-studio-visual-custom'),'authentication'=>false,'media'=>false,'studio'=>['authorization'=>static fn():bool=>true,'visual_runtime'=>$custom]]);$t->same($custom,$customPlatform->studioVisualRuntime());
	$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-studio-visual-invalid'),'authentication'=>false,'media'=>false,'studio'=>['visual_runtime'=>'yes']]),InvalidArgumentException::class);
})->tag('panel','studio','visual-runtime','platform','facade','scorched-earth')->isolation('case')->maxMillis(12000);

test('Studio visual value objects fail closed around malformed identities oversized frames and renderer faults',static function(Context $t):void{
	[$manager,$runtime]=dp_panel_studio_visual_manager(false);$definition=PanelStudioDefinition::from(dp_panel_studio_visual_kind_roots()['field']);$session=PanelStudioEditor::open($manager,PanelStudioDocument::make('tenant-boundary','visual-boundary','Visual boundary'),'visual',$definition);$preview=$runtime->renderSession($session);$materialization=$preview->materialization();$dataset=$preview->dataset();
	$surface=PanelStudioVisualSurface::success('root','field','Field preview',true,PanelPageResult::html('<p>Ready</p>'));$t->isFalse($surface->failed());$t->same('field',$surface->symbol());$t->same('root',$surface->path());$t->instanceOf(PanelPageResult::class,$surface->result());$t->contains('data-status="ready"',$surface->frameHtml());$t->isFalse($surface->jsonSerialize()['sandbox']['same_origin']);$t->isTrue($surface->jsonSerialize()['sandbox']['forms']===false);
	$failed=PanelStudioVisualSurface::failure('root','field','Field preview',false);$t->isTrue($failed->failed());$t->contains('render_failed',$failed->frameHtml());$oversized=PanelStudioVisualSurface::success('root','field','Field preview',false,PanelPageResult::html(str_repeat('x',PanelStudioVisualSurface::MAX_FRAME_BYTES+1)));$t->isTrue($oversized->failed());$t->same('surface_too_large',$oversized->jsonSerialize()['error_code']);
	$t->throws(static fn()=>PanelStudioVisualSurface::success('bad','field','Field',false,PanelPageResult::html('x')),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioVisualSurface::success('root','x','Field',false,PanelPageResult::html('x')),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioVisualSurface::success('root','field','<b>Field</b>',false,PanelPageResult::html('x')),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioVisualSurface::failure('root','field','Field',false,'bad-code'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioVisualPreview('invalid',null,'market',$materialization,$dataset,[$surface]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualPreview('session',0,'market',$materialization,$dataset,[$surface]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualPreview('session',null,'Bad path',$materialization,$dataset,[$surface]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualPreview('session',null,'market',$materialization,$dataset,[]),LengthException::class);$t->throws(static fn()=>new PanelStudioVisualPreview('session',null,'market',$materialization,$dataset,[new stdClass()]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualPreview('session',null,'market',$materialization,$dataset,[$surface,$surface]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioVisualPreview('session',null,'market',$materialization,$dataset,array_fill(0,PanelStudioVisualPreview::MAX_SURFACES+1,$surface)),LengthException::class);
	$large=[];for($i=0;$i<5;$i++){$large[]=PanelStudioVisualSurface::success($i===0?'root':'root.children['.($i-1).']','field','Large '.($i+1),false,PanelPageResult::html(str_repeat('x',3500000)));}$t->throws(static fn()=>new PanelStudioVisualPreview('session',null,'market',$materialization,$dataset,$large),LengthException::class);
	$registry=PanelStudioSchemaRegistry::defaults();$normalized=$registry->validate($definition)->assertValid()->normalized();$t->notNull($normalized);$artifact=PanelStudioArtifact::trusted($definition,$normalized,$registry,new Dataphyre\Panel\PanelStudioCompiler(),new PanelStudioMaterializer(),['root'=>'field']);$faulty=new PanelStudioMaterialization($artifact,['root'=>new stdClass()],['root'=>'field'],['field:market'=>['root']]);$compose=Closure::bind(static fn(PanelStudioVisualRuntime $subject):PanelStudioVisualPreview=>$subject->compose('session',null,'market','root',$definition,$faulty,$dataset,null),null,PanelStudioVisualRuntime::class);$t->instanceOf(Closure::class,$compose);$fault=$compose($runtime);$t->isTrue($fault->surface('root')?->failed()??false);$t->same('render_failed',$fault->surface('root')?->jsonSerialize()['error_code']);
})->tag('panel','studio','visual-runtime','boundaries','coverage','scorched-earth')->isolation('case')->maxMillis(12000);
