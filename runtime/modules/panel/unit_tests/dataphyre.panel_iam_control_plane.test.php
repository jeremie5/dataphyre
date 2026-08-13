<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelAtomicIamStore;
use Dataphyre\Panel\PanelAtomicSnapshotStore;
use Dataphyre\Panel\PanelIamAuditEvent;
use Dataphyre\Panel\PanelIamAuthorizationException;
use Dataphyre\Panel\PanelIamConflict;
use Dataphyre\Panel\PanelIamGuard;
use Dataphyre\Panel\PanelIamManager;
use Dataphyre\Panel\PanelIamMembership;
use Dataphyre\Panel\PanelIamMutation;
use Dataphyre\Panel\PanelIamPrincipal;
use Dataphyre\Panel\PanelIamQuery;
use Dataphyre\Panel\PanelIamReceipt;
use Dataphyre\Panel\PanelIamServiceAccount;
use Dataphyre\Panel\PanelIamState;
use Dataphyre\Panel\PanelMemoryIamStore;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelSecurityDecision;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @param array<string,mixed> $options */
function dp_panel_iam_manager(?object $store=null,?callable $authorize=null,array $options=[]):PanelIamManager {
	return new PanelIamManager(
		$store instanceof \Dataphyre\Panel\PanelIamStore?$store:new PanelMemoryIamStore(),
		str_repeat('a',32),
		$authorize,
		$options+['clock'=>static fn():string=>'2026-07-14T12:00:00Z','high_risk_permissions'=>['iam.*','security.*','tenant.owner']]
	);
}

/** @param array<string,mixed> $options */
function dp_panel_iam_command(string $operation,string $tenant,string $type,string $subject,string $idempotency,?int $revision=null,array $options=[]):PanelIamMutation {
	return PanelIamMutation::make($operation,$tenant,$type,$subject,$options['actor_id']??'operator','A bounded operational reason.',$idempotency,$revision,$options);
}

test('IAM guard and immutable value objects reject ambiguity secrets and unbounded state',static function(Context $t):void{
	$t->same('tenant:one',PanelIamGuard::identifier(' tenant:one ','tenant id'));
	$t->same('membership.grant',PanelIamGuard::operation(' Membership.Grant '));
	$t->same('service',PanelIamGuard::subjectType(' SERVICE '));
	$t->same('suspended',PanelIamGuard::status('SUSPENDED'));
	$t->same(['admin','viewer'],PanelIamGuard::names(['Viewer','admin','viewer'],'role'));
	$t->same('Reason',PanelIamGuard::text(' Reason ','reason',20));
	$t->same('2026-07-14T12:00:00+00:00',PanelIamGuard::instant('2026-07-14 12:00:00 UTC','time',false));
	$t->same(null,PanelIamGuard::instant(null,'optional'));
	$t->same(PanelIamGuard::digest(['a'=>1,'b'=>2]),PanelIamGuard::digest(['b'=>2,'a'=>1]));
	$t->same(['nested'=>['items'=>[1,true,null]]],PanelIamGuard::metadata(['nested'=>['items'=>[1,true,null]]]));
	$credential=PanelIamGuard::credentialMetadata(['version'=>'2','key_id'=>'key-2','rotated_at'=>1720958400,'expires_at'=>null,'algorithm'=>'ed25519','provider'=>'vault','state'=>'active','last_four'=>'A19F'],true);
	$t->same(2,$credential['version']);$t->same('2024-07-14T12:00:00+00:00',$credential['rotated_at']);$t->same([],PanelIamGuard::credentialMetadata([]));

	foreach([
		static fn()=>PanelIamGuard::identifier('bad id'),static fn()=>PanelIamGuard::operation('1bad'),static fn()=>PanelIamGuard::subjectType('robot'),static fn()=>PanelIamGuard::status('pending'),
		static fn()=>PanelIamGuard::names([new stdClass()]),static fn()=>PanelIamGuard::names(array_fill(0,101,'role')),static fn()=>PanelIamGuard::names(['bad role']),
		static fn()=>PanelIamGuard::text('','reason',20),static fn()=>PanelIamGuard::instant('not-a-date','time',false),static fn()=>PanelIamGuard::instant(null,'time',false),
		static fn()=>PanelIamGuard::metadata(['password'=>'open']),static fn()=>PanelIamGuard::metadata(['api_key'=>'open']),static fn()=>PanelIamGuard::metadata(['value'=>INF]),static fn()=>PanelIamGuard::metadata(['value'=>new stdClass()]),
		static fn()=>PanelIamGuard::metadata(['value'=>str_repeat('x',4097)]),static fn()=>PanelIamGuard::metadata(['value'=>"\xB1"]),static fn()=>PanelIamGuard::metadata([1,2]),
		static fn()=>PanelIamGuard::credentialMetadata([],true),static fn()=>PanelIamGuard::credentialMetadata(['secret'=>'open']),static fn()=>PanelIamGuard::credentialMetadata(['key_id'=>'key'],true),
		static fn()=>PanelIamGuard::credentialMetadata(['version'=>0]),static fn()=>PanelIamGuard::credentialMetadata(['version'=>'x']),static fn()=>PanelIamGuard::credentialMetadata(['last_four'=>'12']),
	]as$failure){$t->throws($failure,Throwable::class);}
	$deep=['value'=>1];for($i=0;$i<10;$i++){$deep=['nested'=>$deep];}$t->throws(static fn()=>PanelIamGuard::metadata($deep),LengthException::class);
	$many=[];for($i=0;$i<1001;$i++){$many['k'.$i]=$i;}$t->throws(static fn()=>PanelIamGuard::metadata($many),LengthException::class);

	$principal=PanelIamPrincipal::make('person-1','Avery Stone',['email'=>'AVERY@example.test','metadata'=>['department'=>'ops'],'now'=>'2026-07-14T10:00:00Z']);
	$t->same('person-1',$principal->id());$t->same('principal',$principal->subjectType());$t->same('Avery Stone',$principal->displayName());$t->same('avery@example.test',$principal->email());$t->same('active',$principal->status());$t->same(0,$principal->revision());$t->same(['department'=>'ops'],$principal->metadata());$t->same('2026-07-14T10:00:00+00:00',$principal->createdAt());$t->same($principal->createdAt(),$principal->updatedAt());
	$principal=$principal->withRevision(1,'2026-07-14T10:01:00Z')->withStatus('suspended','2026-07-14T10:02:00Z');$t->same(1,$principal->revision());$t->same('suspended',$principal->status());$t->same($principal->storagePayload(),PanelIamPrincipal::restore($principal->storagePayload())->storagePayload());$t->contains('panel_iam_principal',json_encode($principal,JSON_THROW_ON_ERROR));
	foreach([static fn()=>PanelIamPrincipal::make('','Name'),static fn()=>PanelIamPrincipal::make('id','',['now'=>'2026-01-01']),static fn()=>PanelIamPrincipal::make('id','Name',['email'=>'bad']),static fn()=>PanelIamPrincipal::restore(['id'=>'id','display_name'=>'Name','revision'=>-1,'created_at'=>'2026-01-02','updated_at'=>'2026-01-01']),static fn()=>$principal->withRevision(-1)]as$failure){$t->throws($failure,Throwable::class);}

	$service=PanelIamServiceAccount::make('svc-1','Order synchronizer',['metadata'=>['owner'=>'platform'],'now'=>'2026-07-14T10:00:00Z']);
	$t->same('svc-1',$service->id());$t->same('service',$service->subjectType());$t->same('Order synchronizer',$service->displayName());$t->same('active',$service->status());$t->same(0,$service->revision());$t->same(['owner'=>'platform'],$service->metadata());$t->same([],$service->credentialMetadata());$t->same($service->createdAt(),$service->updatedAt());
	$service=$service->rotateCredential(['key_id'=>'key-1','version'=>1,'rotated_at'=>'2026-07-14T11:00:00Z'],'2026-07-14T11:00:00Z')->withRevision(2)->withStatus('suspended','2026-07-14T11:01:00Z');
	$t->same(2,$service->revision());$t->same('key-1',$service->credentialMetadata()['key_id']);$t->same('suspended',$service->status());$t->same($service->storagePayload(),PanelIamServiceAccount::restore($service->storagePayload())->storagePayload());$t->isFalse(str_contains(json_encode($service,JSON_THROW_ON_ERROR),'raw-secret'));
	foreach([static fn()=>PanelIamServiceAccount::make('','Name'),static fn()=>PanelIamServiceAccount::restore(['id'=>'s','display_name'=>'S','revision'=>-1,'created_at'=>'2026-01-01','updated_at'=>'2026-01-01']),static fn()=>$service->withRevision(-1),static fn()=>$service->rotateCredential(['raw_secret'=>'x'],'2026-07-14')]as$failure){$t->throws($failure,Throwable::class);}

	$membership=PanelIamMembership::make('tenant-one','principal','person-1',['viewer'],['orders.read'],['expires_at'=>'2026-07-15T00:00:00Z','metadata'=>['source'=>'manual'],'now'=>'2026-07-14T10:00:00Z']);
	$t->same('tenant-one',$membership->tenantId());$t->same('principal',$membership->subjectType());$t->same('person-1',$membership->subjectId());$t->same('principal:person-1',$membership->key());$t->same(['viewer'],$membership->roles());$t->same(['orders.read'],$membership->permissions());$t->same('active',$membership->status());$t->same('2026-07-15T00:00:00+00:00',$membership->expiresAt());$t->same(0,$membership->revision());$t->same(['source'=>'manual'],$membership->metadata());$t->same('2026-07-14T10:00:00+00:00',$membership->createdAt());$t->same($membership->createdAt(),$membership->updatedAt());$t->isTrue($membership->activeAt('2026-07-14T12:00:00Z'));$t->isFalse($membership->activeAt('2026-07-16T12:00:00Z'));
	$membership=$membership->evolve(['status'=>'revoked'],1,'2026-07-14T11:00:00Z');$t->isFalse($membership->activeAt());$t->same(1,$membership->revision());$t->same($membership->storagePayload(),PanelIamMembership::restore($membership->storagePayload())->storagePayload());$t->contains('panel_iam_membership',json_encode($membership,JSON_THROW_ON_ERROR));
	foreach([static fn()=>PanelIamMembership::make('','principal','id'),static fn()=>PanelIamMembership::make('t','robot','id'),static fn()=>PanelIamMembership::restore(['tenant_id'=>'t','subject_type'=>'principal','subject_id'=>'id','revision'=>-1,'created_at'=>'2026-01-01','updated_at'=>'2026-01-01']),static fn()=>$membership->evolve([],0,'2026-01-01')]as$failure){$t->throws($failure,Throwable::class);}
})->tag('panel','iam','values','security')->maxMillis(5000);

test('IAM mutations receipts and audit events preserve provenance without raw credentials',static function(Context $t):void{
	$mutation=PanelIamMutation::make('membership.grant','tenant-a','principal','person-1','approver','Approved owner access.','raw-idempotency-value',3,['requester_id'=>'requester','approver_id'=>'approver']);
	$t->same('membership.grant',$mutation->operation());$t->same('tenant-a',$mutation->tenantId());$t->same('principal',$mutation->subjectType());$t->same('person-1',$mutation->subjectId());$t->same('approver',$mutation->actorId());$t->same('requester',$mutation->requesterId());$t->same('approver',$mutation->approverId());$t->same('Approved owner access.',$mutation->reason());$t->same(3,$mutation->expectedRevision());$t->same(64,strlen($mutation->idempotencyDigest()));$mutation->assert('membership.grant','principal','person-1');
	$t->same($mutation->fingerprint(['roles'=>['owner']]),$mutation->fingerprint(['roles'=>['owner']]));$encoded=json_encode($mutation,JSON_THROW_ON_ERROR);$t->notContains('raw-idempotency-value',$encoded);$t->contains('raw_idempotency_serialized',$encoded);
	foreach([static fn()=>PanelIamMutation::make('unsupported','t','principal','p','a','reason','id'),static fn()=>PanelIamMutation::make('membership.grant','t','principal','p','a','','id'),static fn()=>PanelIamMutation::make('membership.grant','t','principal','p','a','reason','',-1),static fn()=>$mutation->assert('membership.revoke','principal','person-1')]as$failure){$t->throws($failure,Throwable::class);}
	$query=PanelIamQuery::make('iam.membership.read','tenant-a','reader','principal','person-1',['include_expired'=>false]);$t->same('iam.membership.read',$query->ability());$t->same('tenant-a',$query->tenantId());$t->same('reader',$query->actorId());$t->same('principal',$query->subjectType());$t->same('person-1',$query->subjectId());$t->same(['include_expired'=>false],$query->criteria());$t->contains('panel_iam_query',json_encode($query,JSON_THROW_ON_ERROR));$t->throws(static fn()=>PanelIamQuery::make('security.read','t','a'),InvalidArgumentException::class);$t->throws(static fn()=>PanelIamQuery::make('iam.read','t','a','principal'),InvalidArgumentException::class);

	$event=PanelIamAuditEvent::make(1,$mutation,str_repeat('b',64),str_repeat('0',64),['permission_count'=>1],'key-2026',str_repeat('k',32),'2026-07-14T12:00:00Z');
	$t->same(1,$event->sequence());$t->same('tenant-a',$event->tenantId());$t->same('key-2026',$event->keyId());$t->same(str_repeat('0',64),$event->previousHash());$t->same(64,strlen($event->hash()));$t->isTrue($event->verify(str_repeat('k',32)));$t->isFalse($event->verify(str_repeat('x',32)));$t->same($event->storagePayload(),PanelIamAuditEvent::restore($event->storagePayload())->storagePayload());$t->contains('hmac-sha256-v1',json_encode($event,JSON_THROW_ON_ERROR));
	foreach([static fn()=>PanelIamAuditEvent::make(0,$mutation,str_repeat('b',64),str_repeat('0',64),[],'key',str_repeat('k',32),'2026-01-01'),static fn()=>PanelIamAuditEvent::make(1,$mutation,str_repeat('b',64),'bad',[],'key',str_repeat('k',32),'2026-01-01'),static fn()=>PanelIamAuditEvent::make(1,$mutation,str_repeat('b',64),str_repeat('0',64),[],'bad key',str_repeat('k',32),'2026-01-01'),static fn()=>PanelIamAuditEvent::make(1,$mutation,str_repeat('b',64),str_repeat('0',64),[],'key','short','2026-01-01'),static fn()=>$event->verify('short'),static function()use($event){$payload=$event->storagePayload();$payload['integrity']='sha256';return PanelIamAuditEvent::restore($payload);}]as$failure){$t->throws($failure,Throwable::class);}

	$receipt=PanelIamReceipt::restore(['id'=>str_repeat('c',64),'operation'=>'membership.grant','tenant_id'=>'tenant-a','subject_type'=>'principal','subject_id'=>'person-1','actor_id'=>'approver','requester_id'=>'requester','approver_id'=>'approver','reason'=>'Approved owner access.','idempotency_digest'=>str_repeat('d',64),'fingerprint'=>str_repeat('e',64),'revision'=>4,'status'=>'active','occurred_at'=>'2026-07-14T12:00:00Z','audit_hash'=>$event->hash(),'metadata'=>['high_risk'=>true]]);
	$t->same(str_repeat('c',64),$receipt->id());$t->same('membership.grant',$receipt->operation());$t->same('tenant-a',$receipt->tenantId());$t->same('principal',$receipt->subjectType());$t->same('person-1',$receipt->subjectId());$t->same(4,$receipt->revision());$t->same('active',$receipt->status());$t->same($event->hash(),$receipt->auditHash());$t->isFalse($receipt->replayed());$replay=$receipt->asReplay();$t->isTrue($replay->replayed());$t->isFalse(array_key_exists('replayed',$replay->storagePayload()));$t->isTrue($replay->jsonSerialize()['replayed']);
	$t->throws(static fn()=>PanelIamReceipt::restore([]),InvalidArgumentException::class);$bad=$receipt->storagePayload();$bad['fingerprint']='bad';$t->throws(static fn()=>PanelIamReceipt::restore($bad),InvalidArgumentException::class);
})->tag('panel','iam','mutation','audit','security')->maxMillis(3000);

test('IAM stores enforce atomic tenant isolation append-only state and crash-safe persistence',static function(Context $t):void{
	$memory=new PanelMemoryIamStore();$initial=PanelIamState::initial();$t->same($initial,$memory->read('tenant-a'));$t->same(0,$memory->cursor());
	$principal=PanelIamPrincipal::make('person-1','Avery',['now'=>'2026-07-14T12:00:00Z'])->withRevision(1);
	$result=$memory->transaction('tenant-a',static function(array &$state)use($principal):string{$state['principals'][$principal->id()]=$principal->storagePayload();return'created';},'iam.principal.create',['actor'=>'operator']);
	$t->same('created',$result);$t->same(1,$memory->cursor());$t->same('person-1',PanelIamPrincipal::restore($memory->read('tenant-a')['principals']['person-1'])->id());$t->same($initial,$memory->read('tenant-b'));$t->same('memory',$memory->manifest()['adapter']);$t->same($memory->manifest(),$memory->jsonSerialize());
	try{$memory->transaction('tenant-a',static function(array &$state):void{$state['principals']=[];throw new RuntimeException('abort');},'iam.abort');}catch(RuntimeException){}$t->same(1,count($memory->read('tenant-a')['principals']));
	$t->throws(static fn()=>$memory->transaction('tenant-a',static function(array &$state):void{$state['receipts']['bad']=[];},'iam.invalid'),Throwable::class);
	$t->throws(static fn()=>$memory->transaction('tenant-a',static fn(array &$state)=>null,'bad event'),InvalidArgumentException::class);
	$t->throws(static fn()=>$memory->transaction('tenant-a',static fn(array &$state)=>null,'iam.event',['token'=>'open']),InvalidArgumentException::class);

	$directory=$t->tempDirectory('panel-iam-atomic');$atomic=new PanelAtomicIamStore($directory,16);$manager=dp_panel_iam_manager($atomic,static fn():bool=>true);$create=dp_panel_iam_command('principal.create','tenant-a','principal','persisted','persist-create');$manager->createPrincipal($create,PanelIamPrincipal::make('persisted','Persisted',['now'=>'2026-07-14T12:00:00Z']));
	$reopened=new PanelAtomicIamStore($directory,16);$t->same('persisted',dp_panel_iam_manager($reopened,static fn():bool=>true)->principal('tenant-a','persisted')?->id());$t->same('atomic_json',$reopened->manifest()['adapter']);$t->isFalse(str_contains(json_encode($reopened,JSON_THROW_ON_ERROR),str_replace('\\','/',$directory)));
	try{$reopened->transaction('tenant-a',static function(array &$state):void{$state['principals']=[];throw new RuntimeException('abort');},'iam.abort');}catch(RuntimeException){}$t->same(1,count($reopened->read('tenant-a')['principals']));

	$corrupt=$t->tempDirectory('panel-iam-corrupt');$snapshot=new PanelAtomicSnapshotStore($corrupt,'dataphyre.panel.iam.v1',['schema_version'=>1,'tenants'=>[]]);$snapshot->transaction(static function(array &$root):void{$root=['bad'=>true];},'corrupt');$t->throws(static fn()=>(new PanelAtomicIamStore($corrupt))->read('tenant-a'),UnexpectedValueException::class);
	PanelIamState::assertValid($initial,'tenant-a');$bad=$initial;$bad['extra']=true;$t->throws(static fn()=>PanelIamState::assertValid($bad,'tenant-a'),UnexpectedValueException::class);$bad=$initial;$bad['principals']=['wrong'=>PanelIamPrincipal::make('right','Right',['now'=>'2026-01-01'])->storagePayload()];$t->throws(static fn()=>PanelIamState::assertValid($bad,'tenant-a'),UnexpectedValueException::class);$bad=$initial;$bad['audit']['anchor_hash']='bad';$t->throws(static fn()=>PanelIamState::assertValid($bad,'tenant-a'),UnexpectedValueException::class);
})->tag('panel','iam','store','atomic','tenant')->maxMillis(5000);

test('IAM manager is fail closed optimistic idempotent tenant scoped and approval aware',static function(Context $t):void{
	$tenant='tenant-a';$principal=PanelIamPrincipal::make('person-1','Avery',['now'=>'2026-07-14T12:00:00Z']);$create=dp_panel_iam_command('principal.create',$tenant,'principal','person-1','create-1');
	$denied=dp_panel_iam_manager();$t->throws(static fn()=>$denied->createPrincipal($create,$principal),PanelIamAuthorizationException::class);$t->same(null,$denied->principal($tenant,'person-1'));
	$refused=dp_panel_iam_manager(null,static fn():bool=>false);$t->throws(static fn()=>$refused->createPrincipal($create,$principal),PanelIamAuthorizationException::class);
	$broken=dp_panel_iam_manager(null,static fn()=>throw new RuntimeException('policy outage'));$t->throws(static fn()=>$broken->createPrincipal($create,$principal),PanelIamAuthorizationException::class);

	$abilities=[];$manager=dp_panel_iam_manager(null,static function(string $ability)use(&$abilities):PanelSecurityDecision{$abilities[]=$ability;return new PanelSecurityDecision(true,$ability);});$receipt=$manager->createPrincipal($create,$principal);$t->same(1,$receipt->revision());$t->isFalse($receipt->replayed());$t->same(['iam.principal.create'],$abilities);$t->same($receipt->id(),$manager->createPrincipal($create,$principal)->id());$t->isTrue($manager->createPrincipal($create,$principal)->replayed());
	$t->throws(static fn()=>$manager->createPrincipal($create,PanelIamPrincipal::make('person-1','Changed',['now'=>'2026-07-14T12:00:00Z'])),PanelIamConflict::class);
	$t->throws(static fn()=>$manager->createPrincipal(dp_panel_iam_command('principal.create',$tenant,'principal','person-1','duplicate'),$principal),PanelIamConflict::class);

	$service=PanelIamServiceAccount::make('svc-1','Synchronizer',['now'=>'2026-07-14T12:00:00Z']);$serviceReceipt=$manager->createServiceAccount(dp_panel_iam_command('service.create',$tenant,'service','svc-1','service-create'),$service);$t->same(1,$serviceReceipt->revision());$t->same('svc-1',$manager->serviceAccount($tenant,'svc-1')?->id());$t->same(1,count($manager->principals($tenant)));$t->same(1,count($manager->serviceAccounts($tenant)));$t->same(null,$manager->principal('tenant-b','person-1'));

	$grant=$manager->grant(dp_panel_iam_command('membership.grant',$tenant,'principal','person-1','grant-1',0),['viewer'],['orders.read'],['expires_at'=>'2026-07-20T00:00:00Z','metadata'=>['source'=>'admin']]);$t->same(1,$grant->revision());$membership=$manager->membership($tenant,'principal','person-1');$t->same(['viewer'],$membership?->roles());$t->same(['orders.read'],$membership?->permissions());
	$grant2=$manager->grant(dp_panel_iam_command('membership.grant',$tenant,'principal','person-1','grant-2',1),['operator'],['orders.write']);$t->same(2,$grant2->revision());$t->same(['operator','viewer'],$manager->membership($tenant,'principal','person-1')?->roles());
	$t->throws(static fn()=>$manager->grant(dp_panel_iam_command('membership.grant',$tenant,'principal','person-1','grant-stale',1),['x'],[]),PanelIamConflict::class);
	$t->throws(static fn()=>$manager->grant(dp_panel_iam_command('membership.grant',$tenant,'principal','missing','grant-missing',0),['x'],[]),OutOfBoundsException::class);
	$t->throws(static fn()=>$manager->grant(dp_panel_iam_command('membership.grant',$tenant,'principal','person-1','grant-empty',2),[],[]),InvalidArgumentException::class);

	$suspended=$manager->suspend(dp_panel_iam_command('membership.suspend',$tenant,'principal','person-1','suspend',2));$t->same('suspended',$suspended->status());$t->throws(static fn()=>$manager->suspend(dp_panel_iam_command('membership.suspend',$tenant,'principal','person-1','suspend-again',3)),PanelIamConflict::class);
	$restored=$manager->restore(dp_panel_iam_command('membership.restore',$tenant,'principal','person-1','restore',3));$t->same('active',$restored->status());$revoked=$manager->revoke(dp_panel_iam_command('membership.revoke',$tenant,'principal','person-1','revoke',4));$t->same('revoked',$revoked->status());
	$t->same(1,count($manager->memberships($tenant,['status'=>'revoked'])));$t->same(1,count($manager->memberships($tenant,['role'=>'viewer'])));$t->same(1,count($manager->memberships($tenant,['permission'=>'orders.write'])));$t->same(0,count($manager->memberships($tenant,['active'=>true,'at'=>'2026-07-14T12:00:00Z'])));$t->same(0,count($manager->memberships('tenant-b')));

	$rotation=$manager->rotateServiceCredential(dp_panel_iam_command('service.rotate_credential',$tenant,'service','svc-1','rotate',1),['key_id'=>'service-key-2','version'=>2,'rotated_at'=>'2026-07-14T12:00:00Z','algorithm'=>'ed25519']);$t->same(2,$rotation->revision());$t->same(2,$manager->serviceAccount($tenant,'svc-1')?->credentialMetadata()['version']);$t->throws(static fn()=>$manager->rotateServiceCredential(dp_panel_iam_command('service.rotate_credential',$tenant,'service','svc-1','rotate-stale',1),['key_id'=>'key-3','version'=>3,'rotated_at'=>'2026-07-14']),PanelIamConflict::class);
	$t->throws(static fn()=>$manager->rotateServiceCredential(dp_panel_iam_command('service.rotate_credential',$tenant,'service','missing','rotate-missing',0),['key_id'=>'key-3','version'=>3,'rotated_at'=>'2026-07-14']),OutOfBoundsException::class);

	$highRisk=dp_panel_iam_command('membership.grant',$tenant,'service','svc-1','high-risk',0,['actor_id'=>'approver','requester_id'=>'requester','approver_id'=>'approver']);$manager->grant($highRisk,['automation'],['iam.manage']);$t->same('iam.membership.grant.high_risk',$abilities[array_key_last($abilities)]);
	$unapproved=dp_panel_iam_command('membership.grant',$tenant,'service','svc-1','unapproved',1);$t->throws(static fn()=>$manager->grant($unapproved,[],['security.admin']),PanelIamAuthorizationException::class);
	$sameParty=dp_panel_iam_command('membership.grant',$tenant,'service','svc-1','same-party',1,['actor_id'=>'operator','requester_id'=>'operator','approver_id'=>'operator']);$t->throws(static fn()=>$manager->grant($sameParty,[],['tenant.owner']),PanelIamAuthorizationException::class);
	$singleParty=dp_panel_iam_manager(null,static fn():array=>['allowed'=>true],['require_high_risk_approval'=>false]);$singleParty->createServiceAccount(dp_panel_iam_command('service.create',$tenant,'service','svc-2','svc2'),PanelIamServiceAccount::make('svc-2','Worker',['now'=>'2026-07-14']));$t->same(1,$singleParty->grant(dp_panel_iam_command('membership.grant',$tenant,'service','svc-2','single',0),[],['iam.manage'])->revision());

	$scope=$manager->scope($tenant,'reader');$t->same($tenant,$scope->tenantId());$t->same('reader',$scope->actorId());$t->same('person-1',$scope->principal('person-1')?->id());$t->same('svc-1',$scope->serviceAccount('svc-1')?->id());$t->same('revoked',$scope->membership('principal','person-1')?->status());$t->same(1,count($scope->principals()));$t->same(1,count($scope->serviceAccounts()));$t->same(2,count($scope->memberships()));$t->isTrue(count($scope->audit())>0);$t->same(null,$manager->scope('tenant-b','reader')->principal('person-1'));$t->throws(static fn()=>$denied->scope($tenant,'reader')->principals(),PanelIamAuthorizationException::class);
	$t->isTrue($manager->verifyAudit($tenant));$t->isFalse((new PanelIamManager($manager->store(),str_repeat('z',32),static fn():bool=>true))->verifyAudit($tenant));$events=$manager->audit($tenant,2,3);$t->same(3,count($events));$t->isTrue($events[0]->sequence()>2);
	$manifest=$manager->manifest()->jsonSerialize();$t->isTrue($manifest['authorization']['configured']);$t->isTrue($manifest['authorization']['replay_reauthorized']);$t->isTrue($manifest['security']['request_facing_cross_tenant_query']===false);$t->isTrue($manifest['security']['trusted_internal_unscoped_reads']);$t->same($manifest,$manager->jsonSerialize());$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->notContains(str_repeat('a',32),$encoded);$t->notContains('raw-idempotency-value',$encoded);
})->tag('panel','iam','manager','authorization','idempotency')->maxMillis(7000);

test('IAM retention preserves a verifiable anchored audit suffix and bounded receipt window',static function(Context $t):void{
	$store=new PanelMemoryIamStore();$manager=dp_panel_iam_manager($store,static fn():bool=>true,['audit_retention'=>8,'receipt_retention'=>16]);
	for($index=0;$index<18;$index++){$id='person-'.$index;$manager->createPrincipal(dp_panel_iam_command('principal.create','tenant-r','principal',$id,'create-'.$index),PanelIamPrincipal::make($id,'Person '.$index,['now'=>'2026-07-14T12:00:00Z']));}
	$state=$store->read('tenant-r');$t->same(8,count($state['audit']['events']));$t->same(16,count($state['receipts']));$t->same(16,count($state['receipt_order']));$t->same(18,$state['audit']['sequence']);$t->isFalse($state['audit']['anchor_hash']===str_repeat('0',64));$t->isTrue($manager->verifyAudit('tenant-r'));$t->same(8,count($manager->audit('tenant-r')));
	$last=dp_panel_iam_command('principal.create','tenant-r','principal','person-17','create-17');$t->isTrue($manager->createPrincipal($last,PanelIamPrincipal::make('person-17','Person 17',['now'=>'2026-07-14T12:00:00Z']))->replayed());
	$t->throws(static fn()=>$manager->createPrincipal(dp_panel_iam_command('principal.create','tenant-r','principal','person-0','create-0'),PanelIamPrincipal::make('person-0','Person 0',['now'=>'2026-07-14T12:00:00Z'])),PanelIamConflict::class);

	$t->throws(static function()use($store):void{$store->transaction('tenant-r',static function(array &$state):void{$state['audit']['events'][0]['metadata']['changed']=true;},'iam.tamper');},LogicException::class);
	$t->isTrue($manager->verifyAudit('tenant-r'));
})->tag('panel','iam','retention','integrity')->maxMillis(5000);

test('IAM audit keyrings rotate without invalidating retained history or exposing key material',static function(Context $t):void{
	$store=new PanelMemoryIamStore();$oldKey=str_repeat('o',32);$newKey=str_repeat('n',32);$authorize=static fn():bool=>true;$clock=['clock'=>static fn():string=>'2026-07-14T12:00:00Z'];
	$old=new PanelIamManager($store,['2026-q2'=>$oldKey],$authorize,$clock);$t->same('2026-q2',$old->currentAuditKeyId());$t->same(['2026-q2'],$old->auditKeyIds());
	$first=dp_panel_iam_command('principal.create','tenant-k','principal','first','keyring-first');$old->createPrincipal($first,PanelIamPrincipal::make('first','First',['now'=>'2026-07-14']));
	$rotated=new PanelIamManager($store,['2026-q2'=>$oldKey,'2026-q3'=>$newKey],$authorize,$clock+['current_audit_key_id'=>'2026-q3']);$t->isTrue($rotated->verifyAudit('tenant-k'));$t->isTrue($rotated->createPrincipal($first,PanelIamPrincipal::make('first','First',['now'=>'2026-07-14']))->replayed());
	$rotated->createPrincipal(dp_panel_iam_command('principal.create','tenant-k','principal','second','keyring-second'),PanelIamPrincipal::make('second','Second',['now'=>'2026-07-14']));$events=$rotated->audit('tenant-k');$t->same(['2026-q2','2026-q3'],array_map(static fn(PanelIamAuditEvent $event):string=>$event->keyId(),$events));$t->isTrue($rotated->verifyAudit('tenant-k'));
	$manifest=$rotated->manifest()->jsonSerialize();$t->same('2026-q3',$manifest['audit_keys']['current_key_id']);$t->same(['2026-q2','2026-q3'],$manifest['audit_keys']['accepted_key_ids']);$t->same(2,$manifest['audit_keys']['accepted_key_count']);$t->isTrue($manifest['audit_keys']['rotation_supported']);$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->notContains($oldKey,$encoded);$t->notContains($newKey,$encoded);
	$missingOld=new PanelIamManager($store,['2026-q3'=>$newKey],$authorize,$clock);$t->isFalse($missingOld->verifyAudit('tenant-k'));$t->throws(static fn()=>$missingOld->createPrincipal(dp_panel_iam_command('principal.create','tenant-k','principal','third','keyring-third'),PanelIamPrincipal::make('third','Third',['now'=>'2026-07-14'])),RuntimeException::class);
	foreach([
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),[],$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),[str_repeat('x',32)],$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),array_fill_keys(array_map(static fn(int $i):string=>'key-'.$i,range(1,9)),str_repeat('x',32)),$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),['bad key'=>str_repeat('x',32)],$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),['key'=>'short'],$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),['key'=>str_repeat('x',32),' key '=>str_repeat('y',32)],$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),['old'=>str_repeat('x',32),'new'=>str_repeat('y',32)],$authorize),
		static fn()=>new PanelIamManager(new PanelMemoryIamStore(),['old'=>str_repeat('x',32)],$authorize,['current_audit_key_id'=>'new']),
	]as$failure){$t->throws($failure,InvalidArgumentException::class);}
})->tag('panel','iam','audit','keyring','rotation')->maxMillis(5000);

test('IAM platform opt in manifest and adapter conformance are additive and secret free',static function(Context $t):void{
	$disabled=['operations'=>false,'distributed_operations'=>false,'migrations'=>false,'observability'=>false,'data'=>false,'workflows'=>false,'automation'=>false,'authentication'=>false,'notifications'=>false,'media'=>false,'localization'=>false,'preferences'=>false,'collaboration'=>false,'relations'=>false,'security'=>false,'development'=>false,'extensions'=>false,'platform'=>false];
	$root=$t->tempDirectory('panel-platform-iam');$platform=PanelPlatform::defaults(['state_root'=>$root,'iam'=>['audit_key'=>str_repeat('p',32),'authorize'=>static fn():bool=>true,'snapshot_retention'=>16,'audit_retention'=>20,'receipt_retention'=>30]]+$disabled);$manifest=$platform->manifest();
	$t->isTrue($manifest->available('iam'));$t->isTrue($manifest->configured('iam'));$t->isTrue($manifest->ready('iam'));$payload=$manifest->jsonSerialize();$t->same(count($payload['domains']),$payload['counts']['domains']);$t->hasKey('iam',$payload['domains']);$t->same(1,$payload['counts']['configured']);$t->same(2,$payload['counts']['services']);$t->same($platform->iamStore(),$platform->iam()->store());$t->isTrue(in_array('iam',$platform->jsonSerialize()['metadata']['enabled_domains'],true));$t->isFalse(in_array('*',$platform->iam()->highRiskPermissions(),true));$t->same(['iam.*','security.*','tenant.owner'],$platform->iam()->highRiskPermissions());$t->notContains(str_repeat('p',32),json_encode([$payload,$platform,$platform->iam()],JSON_THROW_ON_ERROR));
	$platform->iam()->createPrincipal(dp_panel_iam_command('principal.create','tenant-p','principal','person-p','platform-create'),PanelIamPrincipal::make('person-p','Platform person',['now'=>'2026-07-14']));$t->same('person-p',$platform->iam()->principal('tenant-p','person-p')?->id());
	$reopened=PanelPlatform::defaults(['state_root'=>$root,'iam'=>['audit_key'=>str_repeat('p',32),'authorize'=>static fn():bool=>true]]+$disabled);$t->same('person-p',$reopened->iam()->principal('tenant-p','person-p')?->id());
	$keyringPlatform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-iam-keyring'),'iam'=>['audit_keys'=>['old'=>str_repeat('o',32),'current'=>str_repeat('c',32)],'current_audit_key_id'=>'current','authorize'=>static fn():bool=>true]]+$disabled);$t->same('current',$keyringPlatform->iam()->currentAuditKeyId());$t->same(['current','old'],$keyringPlatform->iam()->auditKeyIds());$t->notContains(str_repeat('c',32),json_encode($keyringPlatform,JSON_THROW_ON_ERROR));
	$without=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-no-iam')]+$disabled);$t->isFalse($without->has('iam.store'));$t->isFalse($without->manifest()->configured('iam'));$t->throws(static fn()=>$without->iam(),LogicException::class);$t->throws(static fn()=>PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-bad-iam'),'iam'=>['audit_key'=>'short']]+$disabled),InvalidArgumentException::class);

	$runner=new PanelAdapterConformanceRunner();$memoryReport=$runner->run(PanelAdapterConformanceCatalog::iamStore(),new PanelMemoryIamStore(),['allow_destructive'=>true]);$t->isTrue($memoryReport->passed());$t->same(2,$memoryReport->summary()['passed']);$atomicReport=$runner->run(PanelAdapterConformanceCatalog::iamStore(),new PanelAtomicIamStore($t->tempDirectory('panel-iam-conformance')),['allow_destructive'=>true]);$t->isTrue($atomicReport->passed());$t->same(2,$atomicReport->summary()['passed']);$t->notContains('conformance-secret',json_encode([$memoryReport,$atomicReport],JSON_THROW_ON_ERROR));
})->tag('panel','iam','platform','conformance')->maxMillis(10000);
