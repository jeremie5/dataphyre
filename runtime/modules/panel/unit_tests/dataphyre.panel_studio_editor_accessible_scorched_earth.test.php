<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDiagnostic;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorAssets;
use Dataphyre\Panel\PanelStudioEditorCommand;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioEditorRenderer;
use Dataphyre\Panel\PanelStudioEditorSession;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPageBundle;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioPreviewIntent;
use Dataphyre\Panel\PanelStudioPreviewSigner;
use Dataphyre\Panel\PanelStudioReceipt;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_studio_editor_manager(?PanelInMemoryStudioStore $store=null,bool $preview=true):PanelStudioManager {
	$store??=new PanelInMemoryStudioStore();$tick=0;$clock=static function()use(&$tick):string{$tick++;return sprintf('2026-07-14T12:00:%02d+00:00',$tick%60);};
	$signer=$preview?new PanelStudioPreviewSigner(['current'=>str_repeat('K',32)],'current',static fn():int=>1_800_000_000,static fn():string=>'studioeditorpreviewnonce00000001'):null;
	return new PanelStudioManager($store,PanelStudioPolicy::trustedMaintenance(['editor']),null,$signer,0,$clock);
}

function dp_panel_studio_editor_definition():PanelStudioDefinition {
	return PanelStudioDefinition::from(['kind'=>'page','key'=>'orders','properties'=>['label'=>'Orders workspace','description'=>'Edit the trusted order surface.'],'children'=>[
		['kind'=>'form','key'=>'order_form','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'form_section','key'=>'identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
				['kind'=>'field','key'=>'name','properties'=>['label'=>'Name','required'=>true],'children'=>[]],
			]],
			['kind'=>'field','key'=>'email','properties'=>['label'=>'Email','type'=>'email'],'children'=>[]],
		]],
		['kind'=>'table','key'=>'orders_table','properties'=>['density'=>'compact'],'children'=>[
			['kind'=>'column','key'=>'id','properties'=>['label'=>'Order ID','sortable'=>true],'children'=>[]],
		]],
	]]);
}

function dp_panel_studio_editor_options(array $replace=[]):PanelStudioEditorOptions {return PanelStudioEditorOptions::make(array_replace(['action_url'=>'/studio/edit','preview_url'=>'/studio/preview','csrf_name'=>'_token','csrf_token'=>str_repeat('C',32),'editor_id'=>'orders-studio'], $replace));}
function dp_panel_studio_editor_codes(PanelStudioEditorSession $session):array{return array_map(static fn(PanelStudioDiagnostic $diagnostic):string=>$diagnostic->code(),$session->diagnostics());}

test('visual Studio command and option contracts reject untrusted transport shapes',static function(Context $t):void {
	$definition=dp_panel_studio_editor_definition();$replace=PanelStudioEditorCommand::replace($definition);
	$t->same('replace',$replace->type());$t->same($definition,$replace->payload()['definition']);$t->same('panel_studio_editor_command',$replace->jsonSerialize()['type']);
	$t->same('undo',PanelStudioEditorCommand::undo()->type());$t->same('redo',PanelStudioEditorCommand::redo()->type());$t->same('select',PanelStudioEditorCommand::select('orders/order_form')->type());
	$t->throws(static fn()=>PanelStudioEditorCommand::select('bad/path!'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditorCommand::add('orders','unknown'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditorCommand::add('orders','form','Bad Key'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditorCommand::move('orders/order_form','sideways'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditorCommand::update('orders','Bad',[]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditorCommand::update('orders','orders',[1]),InvalidArgumentException::class);
	$options=dp_panel_studio_editor_options(['theme'=>'glass','direction'=>'rtl','title'=>'Studio review','nonce'=>'abc123==','inline_assets'=>true,'zoom'=>125,'reflow'=>'mobile']);
	$t->same('/studio/edit',$options->actionUrl());$t->same('/studio/preview',$options->previewUrl());$t->same('_token',$options->csrfName());$t->same(str_repeat('C',32),$options->csrfToken());$t->same('glass',$options->theme());$t->same('rtl',$options->direction());$t->same('Studio review',$options->title());$t->same('orders-studio',$options->editorId());$t->same('abc123==',$options->nonce());$t->isTrue($options->inlineAssets());$t->same('125',$options->zoom());$t->same('mobile',$options->reflow());$t->isTrue($options->verifyCsrf(['_token'=>str_repeat('C',32)]));$t->isFalse($options->verifyCsrf(['_token'=>'wrong']));
	$serialized=$options->jsonSerialize();$t->isFalse($serialized['csrf']['token_serialized']);$t->notContains(str_repeat('C',32),json_encode($serialized,JSON_THROW_ON_ERROR));
	foreach([
		['unknown'=>true],['action_url'=>'https://example.test/edit'],['preview_url'=>'//evil.test'],['csrf_name'=>'0bad'],['csrf_token'=>'short'],['theme'=>'neon'],['direction'=>'sideways'],['title'=>'<b>bad</b>'],['editor_id'=>'0bad'],['nonce'=>'bad"nonce'],['inline_assets'=>'yes'],['zoom'=>'90'],['reflow'=>'watch'],
	]as$invalid){$t->throws(static fn()=>dp_panel_studio_editor_options($invalid),InvalidArgumentException::class);}
})->tag('panel','studio','editor','security')->maxMillis(2000);

test('visual Studio session edits deterministic trees with bounded undo redo and keyboard moves',static function(Context $t):void {
	$manager=dp_panel_studio_editor_manager();$document=PanelStudioDocument::make('tenant-a','orders-editor','Orders editor');$session=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());
	$t->same('orders',$session->selectedPath());$t->same('page',$session->selectedNode()['kind']);$t->isTrue($session->dirty());$t->isFalse($session->canUndo());$t->isFalse($session->canRedo());
	$session->apply(PanelStudioEditorCommand::select('orders/order_form'));
	$session->apply(PanelStudioEditorCommand::add('orders/order_form','field'));
	$t->same('orders/order_form/field_1',$session->selectedPath());$t->same('field',$session->selectedNode()['kind']);
	$session->apply(PanelStudioEditorCommand::update($session->selectedPath(),'phone',['label'=>'Phone','type'=>'text','required'=>false]));
	$t->same('orders/order_form/phone',$session->selectedPath());$t->same('Phone',$session->selectedNode()['properties']['label']);
	$session->apply(PanelStudioEditorCommand::move($session->selectedPath(),'up'));
	$session->apply(PanelStudioEditorCommand::move($session->selectedPath(),'indent'));
	$t->same('orders/order_form/identity/phone',$session->selectedPath());
	$session->apply(PanelStudioEditorCommand::move($session->selectedPath(),'outdent'));
	$t->same('orders/order_form/phone',$session->selectedPath());
	$session->apply(PanelStudioEditorCommand::move($session->selectedPath(),'down'));
	$beforeRemove=$session->definition()->hash();$session->apply(PanelStudioEditorCommand::remove($session->selectedPath()));$t->same('orders/order_form',$session->selectedPath());$t->notSame($beforeRemove,$session->definition()->hash());
	$session->apply(PanelStudioEditorCommand::undo());$t->same('orders/order_form/phone',$session->selectedPath());$t->isTrue($session->canRedo());$session->apply(PanelStudioEditorCommand::redo());$t->same('orders/order_form',$session->selectedPath());
	$session->apply(PanelStudioEditorCommand::replace(dp_panel_studio_editor_definition()));$t->same('orders/order_form',$session->selectedPath());
	while($session->canUndo()){$session->undo();}$session->undo();$t->contains('nothing_to_undo',dp_panel_studio_editor_codes($session));
	while($session->canRedo()){$session->redo();}$session->redo();$t->contains('nothing_to_redo',dp_panel_studio_editor_codes($session));
	$t->same(PanelStudioEditorSession::MAX_HISTORY,50);
})->tag('panel','studio','editor','commands')->maxMillis(3000);

test('visual Studio composes and inspects executable boards columns and infolists',static function(Context $t):void {
	$manager=dp_panel_studio_editor_manager();$document=PanelStudioDocument::make('tenant-board','board-editor','Board editor');$session=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());
	$session->apply(PanelStudioEditorCommand::select('orders'))->apply(PanelStudioEditorCommand::add('orders','board'));$t->same('orders/board_1',$session->selectedPath());
	$session->apply(PanelStudioEditorCommand::update('orders/board_1','fulfilment',['label'=>'Fulfilment','status_field'=>'state','layout'=>'masonry','columns'=>3,'card_layout'=>'brick']))->apply(PanelStudioEditorCommand::add('orders/fulfilment','board_column'));
	$session->apply(PanelStudioEditorCommand::update($session->selectedPath(),'queued',['label'=>'Queue','status'=>'Queued','tone'=>'warning','accepts_moves'=>false]))->apply(PanelStudioEditorCommand::select('orders/fulfilment'))->apply(PanelStudioEditorCommand::add('orders/fulfilment','board_column'));
	$session->apply(PanelStudioEditorCommand::update($session->selectedPath(),'done',['label'=>'Done','status'=>'Done','tone'=>'success','from'=>['Queued'],'transition'=>'complete','fill_remainder'=>true]));$columnHtml=PanelStudioEditor::render($session,dp_panel_studio_editor_options());foreach(['Board Column','Accepts Moves','Transition','Fill Remainder']as$needle){$t->contains($needle,$columnHtml);}
	$session->apply(PanelStudioEditorCommand::select('orders/fulfilment'));$boardHtml=PanelStudioEditor::render($session,dp_panel_studio_editor_options());foreach(['Status Field','Card Layout','Card Columns','Masonry']as$needle){$t->contains($needle,$boardHtml);}
	$session->apply(PanelStudioEditorCommand::select('orders'))->apply(PanelStudioEditorCommand::add('orders','infolist'))->apply(PanelStudioEditorCommand::add($session->selectedPath(),'infolist_entry'));
	$session->apply(PanelStudioEditorCommand::update($session->selectedPath(),'customer',['label'=>'Customer','copyable'=>true]));$t->isTrue($session->validation()->valid());$t->notNull($session->save('board-editor-save'));
	$runtime=(new PanelStudioMaterializer())->materialize($session->definition(),$manager->registry());$t->instanceOf(PanelStudioPageBundle::class,$runtime->root());$t->same(1,count($runtime->root()->resources()));$t->same(1,count($runtime->root()->infolists()));
})->tag('panel','studio','editor','board','infolist','inspector','scorched-earth')->maxMillis(5000);

test('visual Studio no-JS handler enforces CSRF and decodes typed inspector fields',static function(Context $t):void {
	$manager=dp_panel_studio_editor_manager();$document=PanelStudioDocument::make('tenant-b','no-js-editor','No JS editor');$session=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());$options=dp_panel_studio_editor_options();
	$t->same('editor',$session->principalId());
	$t->throws(static fn()=>$session->handle(['studio_action'=>'refresh'],dp_panel_studio_editor_options(['csrf_token'=>str_repeat('D',32)])),RuntimeException::class);
	$base=['_token'=>str_repeat('C',32),'studio_base_revision'=>'0','studio_base_hash'=>'','studio_definition_json'=>json_encode($session->definition()->root(),JSON_THROW_ON_ERROR),'studio_selected_path'=>'orders/order_form/email'];
	$session->handle($base+['studio_select'=>'orders/order_form/email'],$options);$t->same('orders/order_form/email',$session->selectedPath());
	$session->handle($base+[
		'studio_action'=>'update','studio_path'=>'orders/order_form/email','studio_key'=>'contact','studio_boolean_fields'=>['required','readonly'],'studio_properties'=>[
			'label'=>'Contact','type'=>'"select"','required'=>'1','rows'=>'4','minimum'=>'1.5','options'=>'{"ca":"Canada"}','default'=>'true','column_span'=>'__dp_studio_unset__',
		],
	],$options);
	$node=$session->selectedNode();$t->same('contact',$node['key']);$t->same('select',$node['properties']['type']);$t->same(4,$node['properties']['rows']);$t->same(1.5,$node['properties']['minimum']);$t->same(['ca'=>'Canada'],$node['properties']['options']);$t->isTrue($node['properties']['default']);$t->isTrue($node['properties']['required']);$t->isFalse($node['properties']['readonly']);$t->isFalse(array_key_exists('column_span',$node['properties']));
	$current=['_token'=>str_repeat('C',32),'studio_base_revision'=>'0','studio_base_hash'=>'','studio_definition_json'=>json_encode($session->definition()->root(),JSON_THROW_ON_ERROR),'studio_selected_path'=>$session->selectedPath()];
	$session->handle($current+['studio_move'=>'up:'.$session->selectedPath()],$options);$session->handle($current+['studio_remove'=>$session->selectedPath()],$options);$t->same('orders/order_form',$session->selectedPath());
	$session->handle($current+['studio_action'=>'unsupported'],$options);$t->contains('editor_command_failed',dp_panel_studio_editor_codes($session));
	$session->handle(['_token'=>str_repeat('C',32),'studio_base_revision'=>'9','studio_base_hash'=>str_repeat('f',64),'studio_definition_json'=>'{}','studio_action'=>'save'],$options);$t->contains('remote_revision_conflict',dp_panel_studio_editor_codes($session));

	$actions=PanelStudioEditor::open($manager,PanelStudioDocument::make('tenant-b','facade-actions','Facade actions'),'editor',dp_panel_studio_editor_definition());
	$actions->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Changed']));
	PanelStudioEditor::handle($actions,['_token'=>str_repeat('C',32),'studio_action'=>'undo'],$options);
	PanelStudioEditor::handle($actions,['_token'=>str_repeat('C',32),'studio_action'=>'redo'],$options);
	PanelStudioEditor::handle($actions,['_token'=>str_repeat('C',32),'studio_action'=>'save'],$options);
	$t->same(1,$actions->baseRevision());
	PanelStudioEditor::handle($actions,['_token'=>str_repeat('C',32),'studio_action'=>'preview'],$options);
	$t->notNull($actions->previewIntent());
	PanelStudioEditor::handle($actions,['_token'=>str_repeat('C',32),'studio_action'=>'refresh'],$options);
	PanelStudioEditor::handle($actions,['_token'=>str_repeat('C',32),'studio_action'=>'discard'],$options);
	$t->same(1,$actions->baseRevision());
})->tag('panel','studio','editor','ssr')->maxMillis(3000);

test('visual Studio save preview and optimistic conflict flows stay artifact bound',static function(Context $t):void {
	$store=new PanelInMemoryStudioStore();$manager=dp_panel_studio_editor_manager($store);$document=PanelStudioDocument::make('tenant-c','lifecycle-editor','Lifecycle editor');$session=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());
	$t->same(null,$session->preview());$t->contains('preview_requires_save',dp_panel_studio_editor_codes($session));
	$receipt=$session->save();$t->instanceOf(PanelStudioReceipt::class,$receipt);$t->same(1,$session->baseRevision());$t->isFalse($session->dirty());$t->same($receipt,$session->lastReceipt());
	$intent=$session->preview(180);$t->instanceOf(PanelStudioPreviewIntent::class,$intent);$t->same($intent,$session->previewIntent());$t->same(1,$intent->claims()['revision']);
	$verified=$manager->verifyPreview($intent->token(),'tenant-c','lifecycle-editor','editor',1);$t->same($intent->claims()['content_hash'],$verified->claims()['content_hash']);
	$first=PanelStudioEditor::open($manager,$document,'editor');$second=PanelStudioEditor::open($manager,$document,'editor');$first->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Remote update']));$t->notNull($first->save('remote-save'));
	$second->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Local update']));$t->same(null,$second->save('local-save'));$t->isTrue($second->conflicted());$t->same(2,$second->remoteRevision());$t->contains('remote_revision_conflict',dp_panel_studio_editor_codes($second));$t->same(null,$second->preview());
	$second->discardAndReload();$t->isFalse($second->conflicted());$t->same(2,$second->baseRevision());$t->same('Remote update',$second->definition()->root()['properties']['label']);$second->refresh();$t->contains('editor_current',dp_panel_studio_editor_codes($second));
	$clean=PanelStudioEditor::open($manager,$document,'editor');$first->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Clean refresh remote']));$t->notNull($first->save('remote-save-clean-refresh'));$clean->refresh();$t->same(3,$clean->baseRevision());$t->same('Clean refresh remote',$clean->definition()->root()['properties']['label']);
	$second->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Dirty local']));$first->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Another remote']));$t->notNull($first->save('remote-save-2'));$second->refresh();$t->isTrue($second->conflicted());
	$noPreview=dp_panel_studio_editor_manager(new PanelInMemoryStudioStore(),false);$noPreviewSession=PanelStudioEditor::open($noPreview,PanelStudioDocument::make('tenant-c','no-preview','No preview'),'editor',dp_panel_studio_editor_definition());$t->notNull($noPreviewSession->save());$t->same(null,$noPreviewSession->preview());$t->contains('editor_preview_failed',dp_panel_studio_editor_codes($noPreviewSession));
})->tag('panel','studio','editor','lifecycle')->maxMillis(4000);

test('visual Studio renderer exposes an accessible progressive surface without serializing bearer tokens',static function(Context $t):void {
	$manager=dp_panel_studio_editor_manager();$document=PanelStudioDocument::make('tenant-d','render-editor','Orders & fulfilment');$session=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());$options=dp_panel_studio_editor_options(['title'=>'Panel Studio & review','theme'=>'glass','direction'=>'rtl','inline_assets'=>true,'nonce'=>'abc123']);
	$html=PanelStudioEditor::render($session,$options);foreach(['data-dp-studio-editor','role="tree"','aria-live="polite"','Skip to canvas','Palette','Component tree','Canvas','Properties','Validation diagnostics','Board','Board Column','Trusted','prefers-reduced-motion','forced-colors:active','data-dp-studio-model']as$needle){$t->contains($needle,$html);}$t->notContains('Portable only',$html);
	$t->contains('Panel Studio &amp; review',$html);$t->contains('Orders &amp; fulfilment',$html);$t->contains('nonce="abc123"',$html);$t->notContains('onclick=',$html);$t->notContains('<script>alert',$html);$t->notContains('innerHTML',PanelStudioEditorAssets::javascript());
	$session->save();$intent=$session->preview();$t->notNull($intent);$previewHtml=PanelStudioEditorRenderer::render($session,$options);$t->contains('Open signed preview',$previewHtml);
	$modelStart=strpos($previewHtml,'<script type="application/json" data-dp-studio-model>');$modelEnd=strpos($previewHtml,'</script>',$modelStart);$model=substr($previewHtml,$modelStart,$modelEnd-$modelStart);$t->notContains($intent->token(),$model);$t->contains($intent->token(),$previewHtml);
	$manifest=PanelStudioEditor::manifest($session);$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->same(6,$manifest['version']);$t->same('panel_studio_manifest.v4',$manifest['integration']['manager_contract']);$t->same('panel_studio_schema_registry_manifest.v3',$manifest['integration']['schema_contract']);$t->same('panel_studio_materializer_manifest.v3',$manifest['integration']['materializer_contract']);$t->same('panel_studio_visual_runtime.v2',$manifest['integration']['visual_runtime_contract']);$t->isFalse($manifest['integration']['visual_runtime_active']);$t->isTrue($manifest['renderer']['contracts']['complete_default_kind_palette']);$t->isTrue($manifest['renderer']['contracts']['data_surface_inspector']);$t->isTrue($manifest['renderer']['session']['capabilities']['complete_definition_kind_coverage']);$t->isTrue($manifest['renderer']['session']['capabilities']['board_and_infolist_inspector']);$t->contains('route_free_embeddable',$encoded);$t->contains('signed_artifact_preview',$encoded);$t->notContains($intent->token(),$encoded);
})->tag('panel','studio','editor','rendering','accessibility')->maxMillis(3000);

test('visual Studio checkpoints preserve no-JS history without persisting managers or bearer tokens',static function(Context $t):void {
	$store=new PanelInMemoryStudioStore();$manager=dp_panel_studio_editor_manager($store);$document=PanelStudioDocument::make('tenant-checkpoint','checkpoint-editor','Checkpoint editor');
	$session=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());$session->apply(PanelStudioEditorCommand::select('orders/order_form/email'));$session->apply(PanelStudioEditorCommand::update('orders/order_form/email','contact',['label'=>'Contact','type'=>'email']));
	$checkpoint=PanelStudioEditor::checkpoint($session);$encoded=json_encode($checkpoint,JSON_THROW_ON_ERROR);$t->same('panel_studio_editor_checkpoint',$checkpoint['type']);$t->same(1,$checkpoint['version']);$t->isTrue(strlen($encoded)<=PanelStudioEditorSession::MAX_CHECKPOINT_BYTES);$t->notContains('PanelStudioManager',$encoded);$t->notContains(str_repeat('C',32),$encoded);
	$resumed=PanelStudioEditor::resume($manager,$document,'editor',$checkpoint);$t->same($session->definition()->hash(),$resumed->definition()->hash());$t->same('orders/order_form/contact',$resumed->selectedPath());$t->isTrue($resumed->canUndo());$resumed->undo();$t->same('orders/order_form/email',$resumed->selectedPath());
	$canonical=$checkpoint;ksort($canonical,SORT_STRING);ksort($canonical['scope'],SORT_STRING);ksort($canonical['base'],SORT_STRING);$t->same($session->definition()->hash(),PanelStudioEditor::resume($manager,$document,'editor',$canonical)->definition()->hash());
	$saved=PanelStudioEditor::open($manager,$document,'editor',dp_panel_studio_editor_definition());$t->notNull($saved->save());$intent=$saved->preview();$t->notNull($intent);$cleanCheckpoint=$saved->checkpoint();$t->notContains($intent->token(),json_encode($cleanCheckpoint,JSON_THROW_ON_ERROR));
	$remote=PanelStudioEditor::open($manager,$document,'editor');$remote->apply(PanelStudioEditorCommand::update('orders','orders',['label'=>'Remote checkpoint change']));$t->notNull($remote->save());$stale=PanelStudioEditorSession::resume($manager,$document,'editor',$cleanCheckpoint);$t->isTrue($stale->conflicted());$t->same(2,$stale->remoteRevision());$t->contains('remote_revision_conflict',dp_panel_studio_editor_codes($stale));
	$extra=$checkpoint;$extra['extra']=true;$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$extra),InvalidArgumentException::class);
	$scope=$checkpoint;$scope['scope']['principal_id']='other';$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$scope),InvalidArgumentException::class);
	$base=$checkpoint;$base['base']['revision']='0';$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$base),InvalidArgumentException::class);
	$definition=$checkpoint;$definition['definition']=[];$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$definition),InvalidArgumentException::class);
	$selection=$checkpoint;$selection['selected_path']='orders/missing';$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$selection),InvalidArgumentException::class);
	$history=$checkpoint;$history['undo']=array_fill(0,PanelStudioEditorSession::MAX_HISTORY+1,$checkpoint['undo'][0]);$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$history),InvalidArgumentException::class);
	$snapshot=$checkpoint;$snapshot['undo']=[['definition'=>[],'selection'=>'orders']];$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$snapshot),InvalidArgumentException::class);
	$remoteRevision=$checkpoint;$remoteRevision['remote_revision']=-1;$t->throws(static fn()=>PanelStudioEditor::resume($manager,$document,'editor',$remoteRevision),InvalidArgumentException::class);
})->tag('panel','studio','editor','checkpoint','ssr')->maxMillis(4000);

test('visual Studio assets are deterministic cacheable and reject malformed nonces',static function(Context $t):void {
	$css=PanelStudioEditorAssets::css();$javascript=PanelStudioEditorAssets::javascript();$manifest=PanelStudioEditorAssets::manifest();
	$t->same(hash('sha256',$css),$manifest['styles']['sha256']);$t->same(hash('sha256',$javascript),$manifest['scripts']['sha256']);$t->same(strlen($css),$manifest['styles']['bytes']);$t->same(strlen($javascript),$manifest['scripts']['bytes']);
	$t->contains('payload.contract_version!=='.PanelStudioEditorRenderer::VERSION,$javascript);
	$t->contains('<style>',$tag=PanelStudioEditorAssets::styleTag());$t->contains('</style>',$tag);$t->contains('nonce="abc123"',PanelStudioEditorAssets::scriptTag('abc123'));
	$t->throws(static fn()=>PanelStudioEditorAssets::styleTag('bad nonce'),InvalidArgumentException::class);
	$t->same('panel_studio_editor_renderer',(new PanelStudioEditorRenderer())->jsonSerialize()['type']);$t->same('panel_studio_editor_assets',(new PanelStudioEditorAssets())->jsonSerialize()['type']);$t->same('panel_studio_editor',(new PanelStudioEditor())->jsonSerialize()['type']);
})->tag('panel','studio','editor','assets')->maxMillis(2000);
