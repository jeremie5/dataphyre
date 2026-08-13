<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelFilesystemStudioStore;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelDeveloperToolkit;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelSchemaBlueprint;
use Dataphyre\Panel\PanelStudioCompiler;
use Dataphyre\Panel\PanelStudioArtifact;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDiagnostic;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioDraft;
use Dataphyre\Panel\PanelStudioImpactPlan;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioPreviewIntent;
use Dataphyre\Panel\PanelStudioPreviewSigner;
use Dataphyre\Panel\PanelStudioReceipt;
use Dataphyre\Panel\PanelStudioRevision;
use Dataphyre\Panel\PanelStudioSchemaRegistry;
use Dataphyre\Panel\PanelStudioStateEngine;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_studio_tree(string $label='Orders'):array{return['kind'=>'page','key'=>'orders','properties'=>['label'=>$label,'layout'=>'masonry'],'children'=>[
	['kind'=>'form','key'=>'order_form','properties'=>[],'children'=>[['kind'=>'form_section','key'=>'identity','properties'=>['label'=>'Identity'],'children'=>[
		['kind'=>'field','key'=>'email','properties'=>['label'=>'Email','type'=>'email','required'=>true],'children'=>[]],
	]]]],
	['kind'=>'table','key'=>'orders_table','properties'=>['density'=>'normal'],'children'=>[['kind'=>'column','key'=>'email','properties'=>['label'=>'Email','sortable'=>true],'children'=>[]]]],
]];}

function dp_panel_studio_trusted_artifact(PanelStudioDefinition $definition):PanelStudioArtifact{return(new PanelStudioMaterializer())->materialize($definition,PanelStudioSchemaRegistry::defaults())->artifact();}

test('Studio compiler accepts only bounded JSON component trees and reuses framework manifests',static function(Context $t):void{
	$compiler=new PanelStudioCompiler();$definition=PanelStudioDefinition::from(dp_panel_studio_tree());$manifest=$compiler->compile($definition);
	$t->same($definition->hash(),$manifest['definition_hash']);$t->same(['column'=>1,'field'=>1,'form'=>1,'form_section'=>1,'page'=>1,'table'=>1],$manifest['component_kinds']);$t->isTrue($manifest['runtime']['framework_neutral']);$t->isFalse($manifest['runtime']['executable_code']);$t->same([], $compiler->diagnose($definition));
	$invalid=$compiler->diagnose('not-a-tree');$t->instanceOf(PanelStudioDiagnostic::class,$invalid[0]);$t->same('invalid_root',$invalid[0]->code());$t->same('root',$invalid[0]->path());$t->same('error',$invalid[0]->severity());$t->contains('object-like',$invalid[0]->message());json_encode($invalid[0],JSON_THROW_ON_ERROR);
	$blueprint=PanelSchemaBlueprint::make('sales.orders',['id'=>['type'=>'integer','generated'=>true],'email'=>['type'=>'varchar','nullable'=>false],'password'=>['type'=>'varchar','default'=>'must-not-survive'],'status'=>['type'=>'varchar','enum'=>['draft','paid']]]);$generated=$compiler->fromBlueprint($blueprint,'orders_resource');$t->same('page',$generated->root()['kind']);$t->same(2,count($generated->root()['children']));$t->notContains('must-not-survive',json_encode($generated,JSON_THROW_ON_ERROR));
	$imported=$compiler->import(['type'=>'panel_resource_manifest','fields'=>[['name'=>'name','label'=>'Name','password'=>'secret'],false],'columns'=>[['name'=>'id','label'=>'ID']],'widgets'=>[['key'=>'trend','label'=>'Trend']],'navigation'=>[['key'=>'orders','label'=>'Orders']]],'imported_orders');$encoded=json_encode($imported,JSON_THROW_ON_ERROR);$t->notContains('secret',$encoded);$t->same(4,count($imported->root()['children']));
	$t->same(1,$compiler->manifest()['version']);json_encode($compiler,JSON_THROW_ON_ERROR);
})->tag('panel','studio','compiler','security')->maxMillis(2000);

test('Studio manager performs authorized optimistic approval publication impact and rollback lifecycle',static function(Context $t):void{
	$store=new PanelInMemoryStudioStore();json_encode($store,JSON_THROW_ON_ERROR);$policy=PanelStudioPolicy::permit(static fn(string $action,string $tenant,string $principal,string $document):bool=>$tenant==='acme'&&$document==='orders','test_host');$clock=static fn():string=>'2026-07-14T12:00:00+00:00';$manager=new PanelStudioManager($store,$policy,null,null,1,$clock);$document=PanelStudioDocument::make('acme','orders','Order workspace',['owner'=>'ops']);
	$save=$manager->saveDraft($document,dp_panel_studio_tree(),0,'save-1','author');$t->instanceOf(PanelStudioReceipt::class,$save);$t->same(1,$save->revision());$t->isFalse($save->replayed());$replay=$manager->saveDraft($document,dp_panel_studio_tree(),0,'save-1','author');$t->isTrue($replay->replayed());$t->same($save->id(),$replay->id());$t->same(1,$store->cursor());
	$draft=$manager->draft('acme','orders','author');$t->instanceOf(PanelStudioDraft::class,$draft);$t->same(1,$draft->expectedRevision());$t->same('Order workspace',$draft->document()->title());$t->same('ops',$draft->document()->meta()['owner']);
	$t->throws(static fn()=>$manager->publish('acme','orders',1,'publish-too-soon','author'),LogicException::class);
	$approve=$manager->approve('acme','orders',1,'approve-1','reviewer');$t->same(2,$approve->revision());$publish=$manager->publish('acme','orders',2,'publish-1','author');$t->same(3,$publish->revision());$t->same('published',$manager->published('acme','orders','author')?->state());$t->same(null,$manager->draft('acme','orders','author'));
	$changed=dp_panel_studio_tree('Priority orders');$save2=$manager->saveDraft($document,$changed,3,'save-2','author');$t->same(4,$save2->revision());$impact=$manager->impact('acme','orders','author');$t->instanceOf(PanelStudioImpactPlan::class,$impact);$t->isTrue($impact->changed());$t->isFalse($impact->breaking());$t->same(2,$impact->diff()->summary()['changed']);json_encode($impact,JSON_THROW_ON_ERROR);
	$manager->approve('acme','orders',4,'approve-2','reviewer');$manager->publish('acme','orders',5,'publish-2','author');$rollback=$manager->rollback('acme','orders',3,6,'rollback-1','operator');$t->same(7,$rollback->revision());$t->same(PanelStudioDefinition::from(dp_panel_studio_tree())->hash(),$manager->published('acme','orders','author')?->contentHash());
	$history=$manager->history('acme','orders','author');$t->same(7,count($history));$t->same('rollback',$history[0]->action());$t->isTrue($store->verify('acme','orders'));$t->same(7,$manager->head('acme','orders','author')?->number());$t->same(7,$store->cursor());
	$oldPublish=$manager->publish('acme','orders',2,'publish-1','author');$t->isTrue($oldPublish->replayed());$t->same(7,$manager->published('acme','orders','author')?->number());
	$t->isTrue($manager->manifest()['two_person_publish']);$serialized=json_encode($manager,JSON_THROW_ON_ERROR);$t->notContains('save-1',$serialized);$t->notContains('test_host_policy_callable',$serialized);
})->tag('panel','studio','workflow','approval','rollback')->maxMillis(2000);

test('Studio defaults deny and trusted maintenance is an explicit scoped clone',static function(Context $t):void{
	$manager=new PanelStudioManager(new PanelInMemoryStudioStore());$document=PanelStudioDocument::make('acme','orders','Orders');$t->throws(static fn()=>$manager->saveDraft($document,dp_panel_studio_tree(),0,'save','maintenance'),RuntimeException::class);
	$trusted=$manager->trustedMaintenance(['maintenance']);$receipt=$trusted->saveDraft($document,dp_panel_studio_tree(),0,'save','maintenance');$t->same(1,$receipt->revision());$t->throws(static fn()=>$trusted->head('acme','orders','intruder'),RuntimeException::class);$t->isTrue($trusted->manifest()['authorization']['trusted_maintenance']);$t->isFalse($manager->manifest()['authorization']['trusted_maintenance']);
})->tag('panel','studio','authorization')->maxMillis(1000);

test('Studio filesystem adapter is atomic reloadable idempotent and exposes a cursor feed',static function(Context $t):void{
	$directory=$t->tempDirectory('panel-studio-filesystem');$store=new PanelFilesystemStudioStore($directory,16);$definition=PanelStudioDefinition::from(dp_panel_studio_tree());$document=PanelStudioDocument::make('north','catalog','Catalog');
	$save=$store->save($document,$definition,0,'fs-save','builder','2026-07-14T12:00:00+00:00');$t->same(1,$save->revision());$t->isTrue($store->verify('north','catalog'));$reloaded=new PanelFilesystemStudioStore($directory,16);$t->same($definition->hash(),$reloaded->head('north','catalog')?->contentHash());$t->same(1,count($reloaded->history('north','catalog')));$t->same(1,$reloaded->cursor());
	$changes=$reloaded->changesSince(0);$t->same(1,count($changes['changes']));$t->same('studio.save',$changes['changes'][0]['type']);$replay=$reloaded->save($document,$definition,0,'fs-save','builder','2026-07-14T13:00:00+00:00');$t->isTrue($replay->replayed());$t->same(1,$reloaded->head('north','catalog')?->number());$t->same('filesystem_atomic_json',$reloaded->manifest()['adapter']);json_encode($reloaded,JSON_THROW_ON_ERROR);
})->tag('panel','studio','store','filesystem')->maxMillis(3000);

test('Studio preview capabilities are rotating scope bound expiring and secret free',static function(Context $t):void{
	$now=1784040000;$nonce=0;$signer=new PanelStudioPreviewSigner(['old'=>str_repeat('o',32),'current'=>str_repeat('c',32)],'current',static function()use(&$now):int{return$now;},static function()use(&$nonce):string{return'nonce_'.str_pad((string)++$nonce,16,'0',STR_PAD_LEFT);});
	$revision=PanelStudioRevision::make(1,'draft','save',PanelStudioDefinition::from(dp_panel_studio_tree()),'author','2026-07-14T12:00:00+00:00');$intent=$signer->issue('acme','author','orders',$revision,60);$t->instanceOf(PanelStudioPreviewIntent::class,$intent);$verified=$signer->verify($intent->token(),'acme','author','orders',1);$t->same($intent->claims(),$verified->claims());$t->same($now+60,$intent->expiresAt());
	$public=json_encode([$signer,$intent],JSON_THROW_ON_ERROR);$t->notContains(str_repeat('c',32),$public);$t->notContains($intent->token(),$public);$t->contains('token_digest',$public);$t->isTrue($signer->manifest()['key_rotation']);
	$t->same('bounded_reusable_until_expiry',$signer->manifest()['nonce_semantics']);$t->isFalse($signer->manifest()['replay_consumption_store']);
	$oldSigner=new PanelStudioPreviewSigner(['old'=>str_repeat('o',32)],'old',static fn():int=>$now,static fn():string=>'nonce_000000000000099');$oldIntent=$oldSigner->issue('acme','author','orders',$revision,30);$t->same('old',$signer->verify($oldIntent->token(),'acme','author','orders',1)->claims()['key_id']);
	$t->throws(static fn()=>$signer->verify($intent->token(),'other','author','orders',1),UnexpectedValueException::class);$tampered=substr($intent->token(),0,-1).(str_ends_with($intent->token(),'a')?'b':'a');$t->throws(static fn()=>$signer->verify($tampered,'acme','author','orders',1),UnexpectedValueException::class);$now+=61;$t->throws(static fn()=>$signer->verify($intent->token(),'acme','author','orders',1),UnexpectedValueException::class);
	$t->throws(static fn()=>$signer->verify(str_repeat('a',PanelStudioPreviewSigner::MAX_TOKEN_BYTES+1),'acme','author','orders'),UnexpectedValueException::class);$oversizedSegment=str_repeat('a',PanelStudioPreviewSigner::MAX_SEGMENT_BYTES+1).'.a.'.str_repeat('a',43);$t->throws(static fn()=>$signer->verify($oversizedSegment,'acme','author','orders'),UnexpectedValueException::class);$t->throws(static fn()=>$signer->verify('invalid$.segment.'.str_repeat('a',43),'acme','author','orders'),UnexpectedValueException::class);
})->tag('panel','studio','preview','security')->maxMillis(1000);

test('Studio value types and state engine reject unsafe malformed and stale material',static function(Context $t):void{
	$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'unknown','key'=>'x']),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'page','key'=>'x','properties'=>['password'=>'secret']]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'page','key'=>'x','properties'=>['label'=>'<script>x</script>']]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'page','key'=>'x','properties'=>['handler'=>static fn()=>true]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'field','key'=>'password','properties'=>['type'=>'password','default'=>'credential-value']]),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'field','key'=>'note','properties'=>['label'=>'authorization: Bearer credential-value']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioDefinition::from(['kind'=>'page','key'=>'root','properties'=>[],'children'=>[['kind'=>'field','key'=>'duplicate','properties'=>[]],['kind'=>'column','key'=>'duplicate','properties'=>[]]]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioDocument::make('../bad','x','Title'),InvalidArgumentException::class);$t->throws(static fn()=>PanelStudioDocument::make('ok','x',''),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioDiagnostic('bad path!','invalid','Message'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioDiagnostic('root','x','Message'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioDiagnostic('root','valid_code',''),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioDiagnostic('root','valid_code','Message','fatal'),InvalidArgumentException::class);
	$store=new PanelInMemoryStudioStore();$document=PanelStudioDocument::make('acme','orders','Orders');$definition=PanelStudioDefinition::from(dp_panel_studio_tree());$store->save($document,$definition,0,'one','author','2026-07-14T12:00:00+00:00');$t->throws(static fn()=>$store->save($document,$definition,0,'two','author','2026-07-14T12:00:00+00:00'),RuntimeException::class);$t->throws(static fn()=>$store->save($document,$definition,0,'one','different','2026-07-14T12:00:00+00:00'),RuntimeException::class);$t->throws(static fn()=>$store->rollback('acme','orders',99,1,'rollback','author','2026-07-14T12:00:00+00:00'),OutOfBoundsException::class);
	$t->isTrue(PanelStudioStateEngine::verify(PanelStudioStateEngine::initialState(),'acme','none'));$t->throws(static fn()=>new PanelInMemoryStudioStore(['version'=>99,'documents'=>[]]),UnexpectedValueException::class);$t->throws(static fn()=>new PanelStudioPreviewSigner([],'none'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioManager(new PanelInMemoryStudioStore(),requiredPublishApprovals:11),InvalidArgumentException::class);
})->tag('panel','studio','validation','security')->maxMillis(2000);

test('Studio history reads reject reordered self-referential semantically invalid and stale publication state',static function(Context $t):void{
	$definition=PanelStudioDefinition::from(dp_panel_studio_tree());
	$t->throws(static fn()=>PanelStudioRevision::make(2,'draft','save',$definition,'author','2026-07-14T12:00:00+00:00',str_repeat('a',64),2),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioRevision::make(2,'published','approve',$definition,'author','2026-07-14T12:00:00+00:00',str_repeat('a',64),1),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioRevision::make(2,'draft','approve',$definition,'author','2026-07-14T12:00:00+00:00',str_repeat('a',64)),InvalidArgumentException::class);
	$state=PanelStudioStateEngine::initialState();$document=PanelStudioDocument::make('acme','orders','Orders');PanelStudioStateEngine::save($state,$document,$definition,0,'save','author','2026-07-14T12:00:00+00:00',dp_panel_studio_trusted_artifact($definition));PanelStudioStateEngine::approve($state,'acme','orders',1,'approve','reviewer','2026-07-14T12:01:00+00:00');PanelStudioStateEngine::publish($state,'acme','orders',2,'publish','author',1,'2026-07-14T12:02:00+00:00');
	$scope=hash('sha256',"acme\0orders");$t->isTrue(PanelStudioStateEngine::verify($state,'acme','orders'));
	$reordered=$state;$reordered['documents'][$scope]['revisions']=array_reverse($reordered['documents'][$scope]['revisions'],true);$t->isFalse(PanelStudioStateEngine::verify($reordered,'acme','orders'));$t->throws(static fn()=>PanelStudioStateEngine::head($reordered,'acme','orders'),UnexpectedValueException::class);
	$badPointer=$state;$badPointer['documents'][$scope]['published_revision']=1;$t->isFalse(PanelStudioStateEngine::verify($badPointer,'acme','orders'));$t->throws(static fn()=>new PanelInMemoryStudioStore($badPointer),UnexpectedValueException::class);
	$tampered=$state;$tampered['documents'][$scope]['revisions'][2]['parent_hash']=str_repeat('f',64);$t->isFalse(PanelStudioStateEngine::verify($tampered,'acme','orders'));
	$wrongScope=$state;$wrongScope['documents'][$scope]['document']['tenant_id']='other';$t->isFalse(PanelStudioStateEngine::verify($wrongScope,'acme','orders'));
	$extra=$state;$extra['documents'][$scope]['revisions'][1]['api_token']='must-not-survive';$t->isFalse(PanelStudioStateEngine::verify($extra,'acme','orders'));$extraState=$state;$extraState['api_token']='must-not-survive';$t->isFalse(PanelStudioStateEngine::verify($extraState,'acme','orders'));
	$badReceipt=$state;$idempotency=array_key_first($badReceipt['documents'][$scope]['idempotency']);$badReceipt['documents'][$scope]['idempotency'][$idempotency]['receipt']['id']=str_repeat('f',64);$t->isFalse(PanelStudioStateEngine::verify($badReceipt,'acme','orders'));
})->tag('panel','studio','integrity','adversarial')->maxMillis(1000);

test('Studio store adapters share a rehydratable stale cursor reset envelope',static function(Context $t):void{
	$stores=[new PanelInMemoryStudioStore(null,8),new PanelFilesystemStudioStore($t->tempDirectory('panel-studio-reset'),8)];$definition=PanelStudioDefinition::from(dp_panel_studio_tree());$document=PanelStudioDocument::make('acme','feed','Feed');
	foreach($stores as$store){
		$revision=0;for($cycle=0;$cycle<6;$cycle++){$store->save($document,$definition,$revision,'save-'.$cycle,'author','2026-07-14T12:00:00+00:00',dp_panel_studio_trusted_artifact($definition));$revision++;$store->publish('acme','feed',$revision,'publish-'.$cycle,'author',0,'2026-07-14T12:01:00+00:00');$revision++;}
		$reset=$store->changesSince(1);$t->isTrue($reset['reset_required']);$t->same([], $reset['changes']);$t->same(['schema','sequence','committed_at','payload','event'],array_keys($reset['snapshot']));$t->same('dataphyre.panel.studio.v1',$reset['snapshot']['schema']);$t->same($store->cursor(),$reset['snapshot']['sequence']);$t->same(1,$reset['snapshot']['payload']['version']);$t->isTrue(PanelStudioStateEngine::verify($reset['snapshot']['payload'],'acme','feed'));$t->same('studio_state_envelope_v1',$store->manifest()['capabilities']['reset_snapshot']);$t->isTrue($store->manifest()['security']['reset_snapshot_requires_authorized_host_boundary']);
	}
})->tag('panel','studio','store','conformance','cursor')->maxMillis(4000);

test('Studio concrete APIs expose complete typed surfaces and preview freshness',static function(Context $t):void{
	$compiler=new PanelStudioCompiler();$t->same([], $compiler->diagnose(dp_panel_studio_tree()));$t->same('invalid_definition',$compiler->diagnose(['kind'=>'nope','key'=>'x'])[0]->code());$oversized=dp_panel_studio_tree();$oversized['properties']['label']=str_repeat('x',PanelStudioDefinition::MAX_STRING_BYTES+1);$t->same('limit_exceeded',$compiler->diagnose($oversized)[0]->code());
	$policy=PanelStudioPolicy::permit(static fn():bool=>true);json_encode($policy,JSON_THROW_ON_ERROR);$now=1784040000;$signer=new PanelStudioPreviewSigner(['preview'=>str_repeat('p',32)],'preview',static fn():int=>$now,static fn():string=>'nonce_000000000000123');$store=new PanelInMemoryStudioStore();$manager=new PanelStudioManager($store,$policy,$compiler,$signer,0,static fn():string=>'2026-07-14T12:00:00+00:00');$document=PanelStudioDocument::make('acme','preview','Preview');$receipt=$manager->saveDraft($document,dp_panel_studio_tree(),0,'preview-save','author');$t->same($receipt->requestHash(),PanelStudioReceipt::hydrate($receipt->jsonSerialize())->requestHash());
	$draft=$manager->draft('acme','preview','author');$t->same(1,$draft?->revision()->number());json_encode($draft,JSON_THROW_ON_ERROR);$intent=$manager->preview('acme','preview',1,'author',60);$t->same($intent->claims(),$manager->verifyPreview($intent->token(),'acme','preview','author',1)->claims());$t->same($compiler,$manager->compiler());$t->same($store,$manager->store());
	$revision=$manager->head('acme','preview','author');$t->same('author',$revision?->actor());$t->same('2026-07-14T12:00:00+00:00',$revision?->createdAt());$t->same(null,$revision?->sourceRevision());
	$manager->saveDraft($document,dp_panel_studio_tree('Changed'),1,'preview-save-2','author');$t->throws(static fn()=>$manager->verifyPreview($intent->token(),'acme','preview','author',1),RuntimeException::class);
	$t->throws(static fn()=>(new PanelStudioManager(new PanelInMemoryStudioStore(),$policy))->preview('acme','none',1,'author'),LogicException::class);$t->throws(static fn()=>(new PanelStudioManager(new PanelInMemoryStudioStore(),$policy))->verifyPreview('x','acme','none','author'),LogicException::class);$t->throws(static fn()=>$manager->impact('acme','missing','author'),OutOfBoundsException::class);
	$breaking=new PanelStudioImpactPlan(['kind'=>'field','required'=>true,'api_token'=>'impact-secret'],['kind'=>'column']);$t->isTrue($breaking->breaking());$t->same(3,count($breaking->impacts()));$impactJson=json_encode($breaking,JSON_THROW_ON_ERROR);$t->notContains('impact-secret',$impactJson);$t->contains('[REDACTED]',$impactJson);
	$receiptPayload=$receipt->jsonSerialize();$receiptPayload['id']=str_repeat('f',64);$t->throws(static fn()=>PanelStudioReceipt::hydrate($receiptPayload),UnexpectedValueException::class);
	$badClock=new PanelStudioManager(new PanelInMemoryStudioStore(),$policy,clock:static fn():string=>'tomorrow');$t->throws(static fn()=>$badClock->saveDraft(PanelStudioDocument::make('acme','clock','Clock'),dp_panel_studio_tree(),0,'clock','author'),UnexpectedValueException::class);
})->tag('panel','studio','api','preview','coverage')->maxMillis(2000);

test('Studio filesystem adapter covers the complete promotion contract',static function(Context $t):void{
	$store=new PanelFilesystemStudioStore($t->tempDirectory('panel-studio-full-contract'));$document=PanelStudioDocument::make('acme','full','Full');$definition=PanelStudioDefinition::from(dp_panel_studio_tree());$store->save($document,$definition,0,'save','author','2026-07-14T12:00:00+00:00',dp_panel_studio_trusted_artifact($definition));$t->same(1,$store->draft('acme','full')?->expectedRevision());$store->approve('acme','full',1,'approve','reviewer','2026-07-14T12:01:00+00:00');$store->publish('acme','full',2,'publish','author',1,'2026-07-14T12:02:00+00:00');$t->same(3,$store->published('acme','full')?->number());$store->rollback('acme','full',3,3,'rollback','operator','2026-07-14T12:03:00+00:00');$t->same(4,$store->head('acme','full')?->number());$t->same(4,$store->published('acme','full')?->number());
})->tag('panel','studio','store','filesystem','coverage')->maxMillis(3000);

test('Studio store conformance pack certifies both reference adapters',static function(Context $t):void{
	$runner=new PanelAdapterConformanceRunner();foreach([new PanelInMemoryStudioStore(),new PanelFilesystemStudioStore($t->tempDirectory('panel-studio-conformance'))]as$store){$report=$runner->run(PanelAdapterConformanceCatalog::studioStore(),$store,['allow_destructive'=>true]);$t->isTrue($report->passed());$t->same(1,$report->summary()['passed']);$t->same(0,$report->summary()['failed']);$t->same(0,$report->summary()['skipped']);$t->isTrue($store->manifest()['state']['documents']>=1);}
})->tag('panel','studio','adapter','conformance')->maxMillis(5000);

test('Studio integrates as an optional platform domain with private preview configuration',static function(Context $t):void{
	$key=str_repeat('K',32);$root=$t->tempDirectory('panel-studio-platform');$platform=PanelPlatform::defaults(['state_root'=>$root,'authentication'=>false,'media'=>false,'studio'=>['authorization'=>static fn():bool=>true,'preview_keys'=>['current'=>$key],'current_preview_key'=>'current','required_publish_approvals'=>1,'clock'=>static fn():string=>'2026-07-14T12:00:00+00:00','preview_clock'=>static fn():int=>1784040000,'nonce_factory'=>static fn():string=>'nonce_000000000000777']]);
	$t->same($platform->get('studio.store'),$platform->studioStore());$t->same($platform->get('studio.manager'),$platform->studio());$manifest=$platform->manifest();$manifestPayload=$manifest->jsonSerialize();$t->same(count($manifestPayload['domains']),$manifestPayload['counts']['domains']);$t->hasKey('studio',$manifestPayload['domains']);$t->isTrue($manifest->available('studio'));$t->isTrue($manifest->configured('studio'));$t->isTrue($manifest->ready('studio'));$t->isTrue($manifest->domain('studio')['features']['compiler']);$t->isTrue($manifest->domain('studio')['features']['editor']);$t->isTrue($manifest->domain('studio')['features']['editor_renderer']);$t->isTrue(is_dir($root.DIRECTORY_SEPARATOR.'studio'));
	$document=PanelStudioDocument::make('acme','platform','Platform');$receipt=$platform->studio()->saveDraft($document,dp_panel_studio_tree(),0,'platform-save','author');$intent=$platform->studio()->preview('acme','platform',$receipt->revision(),'author');$t->same('current',$intent->claims()['key_id']);$encoded=json_encode([$platform,$manifest,$platform->studio()],JSON_THROW_ON_ERROR);$t->notContains($key,$encoded);$t->isFalse($platform->studio()->manifest()['capabilities']['visual_editor_runtime']);$t->isTrue($platform->studio()->manifest()['capabilities']['structured_schema_composition']);
	$session=$platform->openStudioEditor($document,'author');$resumed=$platform->resumeStudioEditor($document,'author',$session->checkpoint());$options=PanelStudioEditorOptions::make(['action_url'=>'/studio','preview_url'=>'/studio/preview','csrf_token'=>str_repeat('C',32),'inline_assets'=>false]);$t->contains('data-dp-studio-editor',$platform->renderStudioEditor($resumed,$options));
	$panel=PanelInstance::make('studio')->usePlatform($platform);$panelSession=$panel->openStudioEditor($document,'author');$t->contains('data-dp-studio-editor',$panel->renderStudioEditor($panelSession,$options));$t->same('panel_studio_editor',$panel->studioEditorManifest()['type']);
	$disabled=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-studio-disabled'),'authentication'=>false,'media'=>false]);$t->isFalse($disabled->has('studio.manager'));$t->isFalse($disabled->manifest()->configured('studio'));$t->throws(static fn()=>$disabled->studio(),LogicException::class);
})->tag('panel','studio','platform','manifest','security')->maxMillis(5000);

test('Studio platform configuration fails closed and coexists with IAM migrations and observability',static function(Context $t):void{
	$base=static fn(string $root,array $studio):array=>['state_root'=>$root,'authentication'=>false,'media'=>false,'studio'=>$studio];
	$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-bad-store'),['store'=>new stdClass()])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-bad-auth'),['authorization'=>'allow'])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-bad-compiler'),['compiler'=>new stdClass()])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-bad-signer'),['preview_signer'=>new stdClass()])),InvalidArgumentException::class);$t->throws(static fn()=>PanelPlatform::defaults($base($t->tempDirectory('studio-bad-keys'),['preview_keys'=>'secret'])),InvalidArgumentException::class);
	$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('studio-coexistence'),'authentication'=>false,'media'=>false,'migrations'=>[],'observability'=>[],'iam'=>['audit_key'=>str_repeat('I',32),'authorize'=>static fn():bool=>true],'studio'=>['authorization'=>static fn():bool=>true]]);$manifest=$platform->manifest();foreach(['migrations','observability','iam','studio']as$domain){$t->isTrue($manifest->configured($domain));$t->isTrue($manifest->ready($domain));}$t->same($platform->studioStore(),$platform->studio()->store());$t->same($platform->iamStore(),$platform->iam()->store());
	$deny=PanelPlatform::defaults($base($t->tempDirectory('studio-default-deny'),[]));$t->throws(static fn()=>$deny->studio()->saveDraft(PanelStudioDocument::make('acme','denied','Denied'),dp_panel_studio_tree(),0,'denied','author'),RuntimeException::class);
})->tag('panel','studio','platform','iam','migrations','observability','coexistence')->maxMillis(6000);

test('Developer toolkit exposes truthful portable Studio blueprints and impact plans',static function(Context $t):void{
	$compiler=PanelDeveloperToolkit::studioCompiler();$t->instanceOf(PanelStudioCompiler::class,$compiler);$manifest=$compiler->compile(dp_panel_studio_tree());$t->same('panel_studio_portable_blueprint_manifest',$manifest['type']);$t->isTrue($manifest['runtime']['portable_blueprint']);$t->isFalse($manifest['runtime']['kind_specific_schema_validation']);$t->isFalse($compiler->manifest()['validation']['parent_child_grammar']);$impact=PanelDeveloperToolkit::studioImpact([], $manifest);$t->instanceOf(PanelStudioImpactPlan::class,$impact);$t->isTrue($impact->changed());
})->tag('panel','studio','development','portable-blueprint')->maxMillis(1000);
