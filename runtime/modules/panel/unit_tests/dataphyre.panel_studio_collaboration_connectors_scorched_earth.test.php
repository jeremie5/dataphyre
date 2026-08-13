<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelIamAuthorizationException;
use Dataphyre\Panel\PanelIamManager;
use Dataphyre\Panel\PanelIamMutation;
use Dataphyre\Panel\PanelIamPrincipal;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelMemoryIamStore;
use Dataphyre\Panel\PanelStudioArrayIdentityConnector;
use Dataphyre\Panel\PanelStudioCollaborationConnector;
use Dataphyre\Panel\PanelStudioCollaborationResult;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorAssets;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioEditorSession;
use Dataphyre\Panel\PanelStudioIamIdentityConnector;
use Dataphyre\Panel\PanelStudioIdentityProfile;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioPresenceLease;
use Dataphyre\Panel\PanelStudioWorkspaceSnapshot;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

function dp_panel_studio_connector_manager():PanelStudioManager {
	return new PanelStudioManager(
		new PanelInMemoryStudioStore(),
		PanelStudioPolicy::permit(static fn():bool=>true,'connector_test'),
		clock:static fn():string=>'2026-07-20T12:00:00+00:00'
	);
}

function dp_panel_studio_connector_definition():PanelStudioDefinition {
	return PanelStudioDefinition::from(['kind'=>'page','key'=>'workspace','properties'=>['label'=>'Connector workspace'],'children'=>[]]);
}

/** @return array{session:PanelStudioEditorSession,connector:PanelStudioCollaborationConnector,options:PanelStudioEditorOptions,manager:PanelCollaborationManager} */
function dp_panel_studio_connector_fixture(string $tenant='tenant-a',string $document='orders',string $actor='editor'):array {
	$identities=new PanelStudioArrayIdentityConnector([
		new PanelStudioIdentityProfile('editor','Editorial Operator'),
		new PanelStudioIdentityProfile('mina','Mina Reviewer'),
		new PanelStudioIdentityProfile('noah','Noah Observer'),
		new PanelStudioIdentityProfile('suspended','Suspended Reviewer','suspended'),
	],$tenant);
	$manager=new PanelCollaborationManager(new PanelInMemoryCollaborationStore(),static fn(string $operation,?string $principal):bool=>$principal===$actor);
	$connector=new PanelStudioCollaborationConnector($manager,$identities,['threads'=>10,'comments_per_thread'=>10,'directory'=>10,'presence'=>10]);
	$session=PanelStudioEditor::open(dp_panel_studio_connector_manager(),PanelStudioDocument::make($tenant,$document,'Orders studio'),$actor,dp_panel_studio_connector_definition());
	$options=PanelStudioEditorOptions::make([
		'action_url'=>'/studio','csrf_token'=>str_repeat('C',32),'editor_id'=>'connector-studio',
		'collaboration_connector'=>$connector,
	]);
	return compact('session','connector','options','manager');
}

/** @param array<string,mixed> $replace @return array<string,mixed> */
function dp_panel_studio_connector_input(array $replace=[]):array {
	return array_replace(['_token'=>str_repeat('C',32)],$replace);
}

test('Studio identity connectors expose bounded public profiles through host and scoped IAM directories',static function(Context $t):void {
	$iamPrincipal=PanelIamPrincipal::make('avery','Avery Stone',['email'=>'avery@example.test','now'=>'2026-07-20T10:00:00Z']);
	$profile=PanelStudioIdentityProfile::fromIam($iamPrincipal);$encoded=json_encode($profile,JSON_THROW_ON_ERROR);
	$t->same('avery',$profile->id());$t->same('iam',$profile->source());$t->same('AS',$profile->initials());$t->isTrue($profile->assignable());$t->contains('panel_studio_identity_profile',$encoded);$t->notContains('avery@example.test',$encoded);
	$unknown=PanelStudioIdentityProfile::unresolved('missing');$t->same('unknown',$unknown->status());$t->isFalse($unknown->assignable());
	$array=new PanelStudioArrayIdentityConnector([
		$profile,
		['id'=>'mina','display_name'=>'Mina Reviewer','status'=>'active','source'=>'directory'],
		['id'=>'suspended','display_name'=>'Suspended Reviewer','status'=>'suspended'],
	],'tenant-a');
	$t->same('tenant-a',$array->tenantId());$t->same(['mina'],array_map(static fn(PanelStudioIdentityProfile $item):string=>$item->id(),$array->search('reviewer',1)));$t->same('avery',$array->resolve(['avery','absent'])['avery']->id());$t->same(3,$array->manifest()['count']);$t->isFalse($array->manifest()['security']['email_serialized']);$t->same($array->manifest(),$array->jsonSerialize());
	$t->throws(static fn()=>new PanelStudioIdentityProfile('bad id','Name'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioIdentityProfile('id','Name','pending'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioIdentityProfile('id','Name','active','Bad Source'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioArrayIdentityConnector([['id'=>'id','display_name'=>'Name','email'=>'leak@example.test']]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioArrayIdentityConnector(['invalid-profile']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioArrayIdentityConnector([$profile,$profile]),LogicException::class);
	$t->throws(static fn()=>$array->resolve(array_fill(0,201,'avery')),LengthException::class);
	$t->throws(static fn()=>$array->search(str_repeat('x',191)),InvalidArgumentException::class);

	$abilities=[];$manager=new PanelIamManager(new PanelMemoryIamStore(),str_repeat('I',32),static function(string $ability)use(&$abilities):bool{$abilities[]=$ability;return true;},['clock'=>static fn():string=>'2026-07-20T10:00:00Z']);
	foreach([
		PanelIamPrincipal::make('editor','Editorial Operator',['email'=>'editor@example.test','now'=>'2026-07-20T10:00:00Z']),
		PanelIamPrincipal::make('mina','Mina Reviewer',['email'=>'mina@example.test','now'=>'2026-07-20T10:00:00Z']),
	]as$principal){
		$manager->createPrincipal(PanelIamMutation::make('principal.create','tenant-a','principal',$principal->id(),'bootstrap','Fixture identity creation.','create-'.$principal->id()),$principal);
	}
	$abilities=[];$iam=new PanelStudioIamIdentityConnector($manager->scope('tenant-a','editor'));
	$t->same('tenant-a',$iam->tenantId());$t->same('mina',$iam->resolve(['mina'])['mina']->id());$t->same(['editor','mina'],array_map(static fn(PanelStudioIdentityProfile $item):string=>$item->id(),$iam->search('',10)));
	$t->contains('iam.principal.read',implode(',',$abilities));$t->contains('iam.principal.list',implode(',',$abilities));
	$iamEncoded=json_encode($iam,JSON_THROW_ON_ERROR);$t->notContains('editor@example.test',$iamEncoded);$t->isTrue($iam->manifest()['security']['scoped_iam_reads']);
	$denied=new PanelStudioIamIdentityConnector((new PanelIamManager(new PanelMemoryIamStore(),str_repeat('D',32),static fn():bool=>false))->scope('tenant-a','editor'));
	$t->throws(static fn()=>$denied->search(),PanelIamAuthorizationException::class);
})->tag('panel','studio','collaboration','identity','iam','security')->maxMillis(4000);

test('Studio collaboration mutations derive actor and document scope server side across complete review flows',static function(Context $t):void {
	$fixture=dp_panel_studio_connector_fixture();$session=$fixture['session'];$connector=$fixture['connector'];$options=$fixture['options'];$manager=$fixture['manager'];
	$t->same($manager,$connector->manager());$t->same('tenant-a',$connector->identities()->tenantId());$t->same(10,$connector->limits()['threads']);$t->same($connector->manifest(),$connector->jsonSerialize());
	$created=PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input([
		'studio_collaboration_operation'=>'create_thread','studio_collaboration_title'=>'Approve fulfilment rules',
		'actor'=>'attacker','subject_id'=>'different-document',
	]),$options);
	$t->instanceOf(PanelStudioCollaborationResult::class,$created);$t->same('thread.create',$created->operation());$t->isTrue($created->changed());$t->instanceOf(PanelStudioWorkspaceSnapshot::class,$created->snapshot());$threadId=$created->resourceId();$t->notNull($threadId);
	$thread=$manager->thread((string)$threadId);$t->same('editor',$thread['created_by']);$t->same('studio_document',$thread['subject_type']);$t->same(hash('sha256',"tenant-a\0orders"),$thread['subject_id']);$t->notContains('different-document',json_encode($thread,JSON_THROW_ON_ERROR));
	$comment=PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input([
		'studio_collaboration_operation'=>'comment:'.$threadId,
		'studio_collaboration_comments'=>[$threadId=>'Review <script>alert(1)</script> before release.'],
	]),$options);
	$t->same('comment.create',$comment->operation());$t->same(1,count($manager->comments((string)$threadId)));
	$t->same('thread.resolve',PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'resolve:'.$threadId]),$options)->operation());
	$t->same('resolved',$manager->thread((string)$threadId)['status']);
	$t->same('thread.reopen',PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'reopen:'.$threadId]),$options)->operation());
	$t->same('open',$manager->thread((string)$threadId)['status']);
	$t->same('assignment.assign',PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'assign','studio_collaboration_assignee'=>'mina']),$options)->operation());
	$t->same('mina',$manager->assignment('studio_document',hash('sha256',"tenant-a\0orders"))['assignee']);
	$t->throws(static fn()=>PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'assign','studio_collaboration_assignee'=>'suspended']),$options),InvalidArgumentException::class);
	$t->same('watch.watch',PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'watch']),$options)->operation());$t->same(['editor'],$manager->watchers('studio_document',hash('sha256',"tenant-a\0orders")));
	$t->isTrue(PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'unwatch']),$options)->changed());
	$t->isFalse(PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'unwatch']),$options)->changed());
	$t->isTrue(PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'unassign']),$options)->changed());
	$t->isFalse(PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'unassign']),$options)->changed());
	$clientDefinition=$session->definition()->root();$clientDefinition['properties']['label']='Unsaved collaborative composition';
	$beforeSynchronized=count($manager->threads());$t->same($session,PanelStudioEditor::handle($session,dp_panel_studio_connector_input([
		'studio_collaboration_operation'=>'create_thread','studio_collaboration_title'=>'Second review',
		'studio_definition_json'=>json_encode($clientDefinition,JSON_THROW_ON_ERROR),
		'studio_base_revision'=>(string)$session->baseRevision(),'studio_base_hash'=>$session->baseHash(),
		'studio_selected_path'=>'workspace',
	]),$options));
	$t->same('Unsaved collaborative composition',$session->definition()->root()['properties']['label']);$t->isTrue($session->dirty());$t->same($beforeSynchronized+1,count($manager->threads()));
	$beforeRejected=count($manager->threads());$t->same($session,PanelStudioEditor::handle($session,dp_panel_studio_connector_input([
		'studio_collaboration_operation'=>'create_thread','studio_collaboration_title'=>'Must not be created',
		'studio_definition_json'=>'{','studio_base_revision'=>(string)$session->baseRevision(),'studio_base_hash'=>$session->baseHash(),
	]),$options));$t->same($beforeRejected,count($manager->threads()));
	$t->throws(static fn()=>PanelStudioEditor::collaborate($session,['studio_collaboration_operation'=>'watch','_token'=>'wrong'],$options),RuntimeException::class);
	$t->throws(static fn()=>PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'unsupported']),$options),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'create_thread','studio_collaboration_title'=>'']),$options),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'comment:'.$threadId,'studio_collaboration_comments'=>[$threadId=>'']]),$options),InvalidArgumentException::class);
	$plain=PanelStudioEditorOptions::make(['csrf_token'=>str_repeat('C',32)]);$t->throws(static fn()=>PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'watch']),$plain),LogicException::class);

	$other=PanelStudioEditor::open(dp_panel_studio_connector_manager(),PanelStudioDocument::make('tenant-a','other','Other studio'),'editor',dp_panel_studio_connector_definition());
	$t->throws(static fn()=>$connector->handle($other,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'resolve:'.$threadId])),OutOfBoundsException::class);
	$t->isTrue($manager->verifyReceipts()['valid']);$t->same('editor',$manager->receipts(limit:100)[0]->actor());
})->tag('panel','studio','collaboration','mutations','scope','csrf')->maxMillis(5000);

test('Studio presence leases typing and snapshots remain bounded and bearer-proof free',static function(Context $t):void {
	$fixture=dp_panel_studio_connector_fixture();$session=$fixture['session'];$connector=$fixture['connector'];$options=$fixture['options'];
	$thread=PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'create_thread','studio_collaboration_title'=>'Live review']),$options);$threadId=(string)$thread->resourceId();
	$lease=$connector->acquirePresence($session,30);$t->instanceOf(PanelStudioPresenceLease::class,$lease);$t->matches('/^[a-f0-9]{48}$/',$lease->leaseToken());$t->contains('2026',$lease->expiresAt());
	$serialized=json_encode($lease,JSON_THROW_ON_ERROR);$t->notContains($lease->leaseToken(),$serialized);$t->isFalse($lease->jsonSerialize()['lease_token_serialized']);
	$heartbeat=$connector->heartbeatPresence($session,$lease->leaseToken(),45);$t->same($lease->leaseId(),$heartbeat->leaseId());$t->isTrue($connector->setTyping($session,$threadId,true,12));
	$snapshot=$connector->snapshot($session);$t->instanceOf(PanelStudioWorkspaceSnapshot::class,$snapshot);$t->same('studio_document',$snapshot->subjectType());$t->same(hash('sha256',"tenant-a\0orders"),$snapshot->subjectId());$t->same('editor',$snapshot->currentIdentity()->id());$t->same(1,count($snapshot->presence()));$t->same(1,count($snapshot->threads()[0]['typing']));$t->isTrue($snapshot->receiptChain()['valid']);$t->isTrue($snapshot->cursor()>0);$t->same(1,$snapshot->counts()['threads_total']);$t->same(10,$snapshot->limits()['threads']);
	$snapshotJson=json_encode($snapshot,JSON_THROW_ON_ERROR);$t->notContains($lease->leaseToken(),$snapshotJson);$t->notContains('lease_hash',$snapshotJson);$t->notContains('email',$snapshotJson);$t->same('panel_studio_workspace_summary',$snapshot->model()['type']);
	$t->isTrue($connector->setTyping($session,$threadId,false));$t->isTrue($connector->releasePresence($session,$lease->leaseToken()));$t->isFalse($connector->releasePresence($session,$lease->leaseToken()));
	$t->throws(static fn()=>$connector->heartbeatPresence($session,$lease->leaseToken()),UnexpectedValueException::class);
	$t->throws(static fn()=>new PanelStudioPresenceLease('bad','bad','bad'),InvalidArgumentException::class);
	$wrongConnector=new PanelStudioCollaborationConnector(new PanelCollaborationManager(new PanelInMemoryCollaborationStore()),new PanelStudioArrayIdentityConnector([new PanelStudioIdentityProfile('editor','Editor')],'tenant-b'));
	$t->throws(static fn()=>$wrongConnector->snapshot($session),LogicException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationConnector(new PanelCollaborationManager(new PanelInMemoryCollaborationStore()),new PanelStudioArrayIdentityConnector([]),['threads'=>0]),InvalidArgumentException::class);
})->tag('panel','studio','collaboration','presence','typing','security')->maxMillis(4000);

test('Studio renderer adds one flat responsive review workspace without leaking comments into its browser model',static function(Context $t):void {
	$fixture=dp_panel_studio_connector_fixture();$session=$fixture['session'];$connector=$fixture['connector'];$options=$fixture['options'];
	$thread=PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'create_thread','studio_collaboration_title'=>'Markup safety']),$options);$threadId=(string)$thread->resourceId();
	PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'comment:'.$threadId,'studio_collaboration_comments'=>[$threadId=>'Never render <script>alert("x")</script> as markup.']]),$options);
	PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'assign','studio_collaboration_assignee'=>'mina']),$options);
	PanelStudioEditor::collaborate($session,dp_panel_studio_connector_input(['studio_collaboration_operation'=>'watch']),$options);
	$lease=$connector->acquirePresence($session);
	$html=PanelStudioEditor::render($session,$options);
	foreach(['Review workspace','Document-scoped discussion','Review owner','Start a review thread','Stop watching','Receipt chain verified','data-dp-studio-collaboration']as$needle){$t->contains($needle,$html);}
	$css=PanelStudioEditorAssets::css();$t->contains('@media(max-width:720px)',$css);$t->contains('@media(forced-colors:active)',$css);$t->contains('.dp-studio-collaboration-controls',$css);
	$t->contains('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',$html);$t->notContains('<script>alert("x")</script>',$html);$t->notContains($lease->leaseToken(),$html);$t->same(1,substr_count($html,'<form '));
	$modelStart=strpos($html,'<script type="application/json" data-dp-studio-model>');$modelEnd=strpos($html,'</script>',$modelStart);$model=substr($html,$modelStart,$modelEnd-$modelStart);
	$t->notContains('Never render',$model);$t->notContains('Noah Observer',$model);$t->contains('panel_studio_workspace_summary',$model);
	$optionsJson=json_encode($options,JSON_THROW_ON_ERROR);$t->same(3,$options->jsonSerialize()['version']);$t->isTrue($options->jsonSerialize()['collaboration']['active']);$t->notContains(str_repeat('C',32),$optionsJson);$t->notContains($lease->leaseToken(),$optionsJson);
	$manifest=PanelStudioEditor::manifest($session,$options);$t->same(6,$manifest['version']);$t->isTrue($manifest['integration']['collaboration_connector_active']);$t->isTrue($manifest['integration']['collaboration_preserves_client_state']);$t->isTrue($manifest['renderer']['contracts']['collaborative_review_workspace']);$t->isTrue($manifest['renderer']['contracts']['scoped_identity_connector']);
	$plain=PanelStudioEditorOptions::make(['csrf_token'=>str_repeat('P',32)]);$t->notContains('Review workspace',PanelStudioEditor::render($session,$plain));$t->isFalse(PanelStudioEditor::manifest($session,$plain)['integration']['collaboration_connector_active']);
})->tag('panel','studio','collaboration','renderer','responsive','accessibility','security')->maxMillis(5000);

test('Studio workspace value contracts reject malformed or unbounded projections',static function(Context $t):void {
	$identity=new PanelStudioIdentityProfile('editor','Editor');
	$valid=new PanelStudioWorkspaceSnapshot('studio_document',str_repeat('a',64),$identity,[$identity],null,[],false,[],[],0,['threads_total'=>0],['threads'=>1],['valid'=>true,'count'=>0,'head_hash'=>'']);
	$t->same('panel_studio_workspace_snapshot',$valid->jsonSerialize()['type']);$t->same('panel_studio_collaboration_result',(new PanelStudioCollaborationResult('watch.watch',true,null,$valid))->jsonSerialize()['type']);
	$t->throws(static fn()=>new PanelStudioWorkspaceSnapshot('record','id',$identity,[],null,[],false,[],[],0,[],['threads'=>1],['valid'=>true,'count'=>0,'head_hash'=>'']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioWorkspaceSnapshot('studio_document','id',$identity,array_fill(0,201,$identity),null,[],false,[],[],0,[],['threads'=>1],['valid'=>true,'count'=>0,'head_hash'=>'']),LengthException::class);
	$t->throws(static fn()=>new PanelStudioWorkspaceSnapshot('studio_document','id',$identity,[],null,[],false,[],[],-1,[],['threads'=>1],['valid'=>true,'count'=>0,'head_hash'=>'']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioWorkspaceSnapshot('studio_document','id',$identity,[],null,[],false,[],[],0,[],['threads'=>0],['valid'=>true,'count'=>0,'head_hash'=>'']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioWorkspaceSnapshot('studio_document','id',$identity,[],null,[],false,[],[],0,[],['threads'=>1],['valid'=>'yes','count'=>0,'head_hash'=>'']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioWorkspaceSnapshot('studio_document','id',$identity,[],null,[],false,[],[['id'=>'thread-missing-creator','status'=>'open','comments'=>[],'typing'=>[]]],0,[],['threads'=>1],['valid'=>true,'count'=>0,'head_hash'=>'']),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelStudioCollaborationResult('Bad operation',true,null,$valid),InvalidArgumentException::class);
})->tag('panel','studio','collaboration','values','bounds')->maxMillis(3000);

test('Studio collaboration snapshots cap watcher and typing fanout before public projection',static function(Context $t):void {
	$manager=new PanelCollaborationManager(new PanelInMemoryCollaborationStore());
	$identities=new PanelStudioArrayIdentityConnector([
		new PanelStudioIdentityProfile('editor','Editor'),
		new PanelStudioIdentityProfile('mina','Mina'),
		new PanelStudioIdentityProfile('noah','Noah'),
	],'tenant-a');
	$connector=new PanelStudioCollaborationConnector($manager,$identities,['watchers'=>1,'typing_per_thread'=>1]);
	$session=PanelStudioEditor::open(dp_panel_studio_connector_manager(),PanelStudioDocument::make('tenant-a','bounded','Bounded studio'),'editor',dp_panel_studio_connector_definition());
	$type='studio_document';$subject=hash('sha256',"tenant-a\0bounded");$thread=$manager->createThread('editor',$type,$subject,'Bounded fanout',[], 'thread-bounded-fanout');
	$manager->watch($type,$subject,'mina');$manager->watch($type,$subject,'noah');$manager->typing((string)$thread['id'],'mina',true,30);$manager->typing((string)$thread['id'],'noah',true,30);
	$snapshot=$connector->snapshot($session);
	$t->same(1,count($snapshot->watchers()));$t->same(2,$snapshot->counts()['watchers_total']);$t->same(1,$snapshot->counts()['watchers_visible']);
	$t->same(1,count($snapshot->threads()[0]['typing']));$t->same(1,$snapshot->limits()['watchers']);$t->same(1,$snapshot->limits()['typing_per_thread']);
})->tag('panel','studio','collaboration','bounds','watchers','typing')->maxMillis(3000);
