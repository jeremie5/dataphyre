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
use Dataphyre\Panel\PanelInMemoryLocalFirstReplayStore;
use Dataphyre\Panel\PanelLocalFirstCrypto;
use Dataphyre\Panel\PanelLocalFirstCanonical;
use Dataphyre\Panel\PanelLocalFirstClientAssets;
use Dataphyre\Panel\PanelLocalFirstDeviceCredential;
use Dataphyre\Panel\PanelLocalFirstGateway;
use Dataphyre\Panel\PanelLocalFirstRequest;
use Dataphyre\Panel\PanelLocalFirstReplayException;
use Dataphyre\Panel\PanelLocalFirstReplaySchema;
use Dataphyre\Panel\PanelLocalFirstResponse;
use Dataphyre\Panel\PanelLocalReplica;
use Dataphyre\Panel\PanelPdoLocalFirstReplayStore;
use Dataphyre\Panel\PanelSyncDocument;
use Dataphyre\Panel\PanelSyncEnvelope;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return array{private:OpenSSLAsymmetricKey,public:string,credential:PanelLocalFirstDeviceCredential} */
function dp_panel_local_first_device(string $actor='User:1',?string $tenant='north',string $device='Device:1'):array{
	$private=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$private instanceof OpenSSLAsymmetricKey){throw new RuntimeException('Unable to generate test device key.');}$details=openssl_pkey_get_details($private);if(!is_array($details)||!is_string($details['key']??null)){throw new RuntimeException('Unable to inspect test device key.');}$der=base64_decode(preg_replace('/-----[^-]+-----|\s+/','',$details['key'])??'',true);if(!is_string($der)){throw new RuntimeException('Unable to encode test device key.');}$public=PanelLocalFirstCrypto::base64UrlEncode($der);
	$credential=PanelLocalFirstDeviceCredential::issue($actor,$tenant,$device,$public,['orders'],['mutate','sync'],'2026-07-16T11:00:00Z','2026-07-17T11:00:00Z','credential',str_repeat('c',32));return['private'=>$private,'public'=>$public,'credential'=>$credential];
}

/** @param array<string,mixed> $payload */
function dp_panel_local_first_request(array $device,int $sequence,string $kind,array $payload,string $nonce):PanelLocalFirstRequest{
	$issued='2026-07-16T12:00:00Z';$digest=PanelLocalFirstRequest::signingDigest($device['credential'],$sequence,$issued,$nonce,$kind,$payload);if(!openssl_sign($digest,$der,$device['private'],OPENSSL_ALGO_SHA256)){throw new RuntimeException('Unable to sign local-first test request.');}$signature=PanelLocalFirstCrypto::base64UrlEncode(PanelLocalFirstCrypto::derToP1363($der));return PanelLocalFirstRequest::make($device['credential'],$sequence,$issued,$nonce,$kind,$payload,$signature);
}

/** @return array<string,mixed> */
function dp_panel_local_first_mutation_payload(string $id='order-1',string $idempotency='offline-order-one'):array{return['type'=>'panel_local_first_mutation_batch','version'=>1,'source'=>'orders','atomic'=>true,'mutations'=>[['operation'=>'create','key'=>$id,'values'=>['name'=>'Offline order','state'=>'draft'],'idempotency_key'=>$idempotency,'metadata'=>['surface'=>'orders'],'expected_revision'=>null,'reason'=>'Created offline.','return_record'=>true]]];}

final class DpPanelLocalFirstPdoStatementProbe extends PDOStatement {
	public function __construct(){}
	public function execute(?array $params=null):bool{return true;}
	public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return false;}
	public function fetchColumn(int $column=0):mixed{return false;}
	public function rowCount():int{return 1;}
}

final class DpPanelLocalFirstPdoProbe extends PDO {
	private bool $transaction=false;
	public bool $failPrepare=false;
	public bool $failRollback=false;
	/** @var list<string> */ public array $events=[];
	public function __construct(private readonly string $driver='pgsql'){}
	public function getAttribute(int $attribute):mixed{return$this->driver;}
	public function setAttribute(int $attribute,mixed $value):bool{return true;}
	public function beginTransaction():bool{$this->events[]='begin';$this->transaction=true;return true;}
	public function commit():bool{$this->events[]='commit';$this->transaction=false;return true;}
	public function rollBack():bool{$this->events[]='rollback';if($this->failRollback){throw new RuntimeException('rollback probe failure');}$this->transaction=false;return true;}
	public function inTransaction():bool{return$this->transaction;}
	public function exec(string $statement):int|false{$this->events[]=$statement;return 0;}
	public function prepare(string $query,array $options=[]):PDOStatement|false{
		$this->events[]='prepare';
		if($this->failPrepare){throw new RuntimeException('prepare probe failure');}
		return new DpPanelLocalFirstPdoStatementProbe();
	}
}

test('device credentials and signed request envelopes bind browser identity without shared signing secrets',static function(Context $t):void{
	$device=dp_panel_local_first_device();$credential=$device['credential'];$t->isTrue($credential->verify(['credential'=>str_repeat('c',32)],'2026-07-16T12:00:00Z'));$t->isFalse($credential->verify(['credential'=>str_repeat('x',32)],'2026-07-16T12:00:00Z'));$t->isFalse($credential->verify(['credential'=>str_repeat('c',32)],'2026-07-18T12:00:00Z'));$t->same('User:1@Device:1',$credential->sourceActor());$t->same(['orders'],$credential->sources());$t->same(['mutate','sync'],$credential->abilities());$t->isTrue($credential->allowsSource('orders'));$t->isTrue($credential->allows('sync'));$t->same($credential->credentialId(),PanelLocalFirstDeviceCredential::hydrate($credential->jsonSerialize())->credentialId());
	$request=dp_panel_local_first_request($device,1,'mutation_batch',dp_panel_local_first_mutation_payload(),'nonce_device_request_0001');$t->isTrue($request->verifyDeviceSignature());$t->same($request->digest(),PanelLocalFirstRequest::hydrate($request->jsonSerialize())->digest());$t->same(64,strlen($request->digest()));
	$corrupt=$request->jsonSerialize();$corrupt['payload']['atomic']=false;$t->throws(static fn()=>PanelLocalFirstRequest::hydrate($corrupt),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelLocalFirstCrypto::p256PublicKey(PanelLocalFirstCrypto::base64UrlEncode('not-a-key')),InvalidArgumentException::class);$t->throws(static fn()=>PanelLocalFirstCrypto::derToP1363("\x30\x00"),InvalidArgumentException::class);
})->tag('panel','local-first','device','signature','security')->maxMillis(5000);

test('gateway projects trusted actor tenant and authorization into universal mutations',static function(Context $t):void{
	$device=dp_panel_local_first_device();$syncKey=str_repeat('s',32);$replica=new PanelLocalReplica('Server:1',['sync'=>$syncKey],'sync',static fn():bool=>true,static fn():string=>'2026-07-16T12:00:00Z');
	$source=new PanelArrayDataSource([],['name'=>'orders','mutation_authorize'=>static fn($mutation):bool=>$mutation->actorId()==='User:1'&&$mutation->tenantKey()==='north'&&($mutation->authorizationMetadata()['grant']??null)==='offline','clock'=>static fn():string=>'2026-07-16T12:00:00Z']);$registry=(new PanelDataSourceRegistry())->register('orders',$source);$store=new PanelInMemoryLocalFirstReplayStore();
	$gateway=new PanelLocalFirstGateway($registry,static fn()=>$replica,['credential'=>str_repeat('c',32)],['response'=>str_repeat('r',32)],'response',['sync'=>$syncKey],'sync',$store,static fn():bool=>true,static fn(PanelLocalFirstDeviceCredential $credential):array=>['actor_id'=>$credential->actorId(),'tenant'=>$credential->tenantId(),'authorization'=>['grant'=>'offline']],static fn():string=>'2026-07-16T12:00:00Z');
	$request=dp_panel_local_first_request($device,1,'mutation_batch',dp_panel_local_first_mutation_payload(),'nonce_mutation_batch_0001');$response=$gateway->handle($request);$t->same('ok',$response->status());$t->isTrue($response->verify(['response'=>str_repeat('r',32)]));$t->same($response->digest(),PanelLocalFirstResponse::hydrate($response->jsonSerialize())->digest());$receipt=$response->body()['result']['receipts'][0];$t->same('created',$receipt['outcome']);$t->same('north',$source->find('order-1')['tenant_id']);$t->same(1,$store->latestSequence($device['credential']->credentialId()));
	$replayed=$gateway->handle($request);$t->same($response->digest(),$replayed->digest());$t->same(1,$source->sequence());$t->same(1,$store->jsonSerialize()['credential_count']);$t->notContains(str_repeat('c',32),json_encode($gateway,JSON_THROW_ON_ERROR));
})->tag('panel','local-first','mutation','scope','replay')->maxMillis(5000);

test('gateway accepts operation branches and preserves deterministic offline conflict evidence',static function(Context $t):void{
	$device=dp_panel_local_first_device();$syncKey=str_repeat('s',32);$replica=new PanelLocalReplica('Server:1',['sync'=>$syncKey],'sync',static fn():bool=>true,static fn():string=>'2026-07-16T12:00:00Z');$registry=(new PanelDataSourceRegistry())->register('orders',new PanelArrayDataSource([],['tenant_field'=>null,'mutation_authorize'=>static fn():bool=>true]));$store=new PanelInMemoryLocalFirstReplayStore();$gateway=new PanelLocalFirstGateway($registry,static fn()=>$replica,['credential'=>str_repeat('c',32)],['response'=>str_repeat('r',32)],'response',['sync'=>$syncKey],'sync',$store,static fn():bool=>true,null,static fn():string=>'2026-07-16T12:00:00Z');
	$firstPayload=['type'=>'panel_local_first_document_batch','version'=>1,'changes'=>[['document_id'=>'Order:1','base_clock'=>[],'operations'=>[['op'=>'set','path'=>'status','value'=>'draft']]]]];$first=dp_panel_local_first_request($device,1,'document_sync',$firstPayload,'nonce_document_sync_0001');$firstResponse=$gateway->handle($first);$t->same('ok',$firstResponse->status());$clock=$replica->document('Order:1')?->clock()??[];$t->same(1,$clock['User:1@Device:1']);
	$replica->change('Order:1',[['op'=>'set','path'=>'status','value'=>'review']]);$secondPayload=['type'=>'panel_local_first_document_batch','version'=>1,'changes'=>[['document_id'=>'Order:1','base_clock'=>$clock,'operations'=>[['op'=>'set','path'=>'status','value'=>'approved']]]]];$second=dp_panel_local_first_request($device,2,'document_sync',$secondPayload,'nonce_document_sync_0002');$secondResponse=$gateway->handle($second);$t->same('conflict',$secondResponse->status());$t->greaterThan(0,$secondResponse->body()['conflict_count']);$t->same('approved',$replica->document('Order:1')?->get('status'));$t->same($secondResponse->digest(),$gateway->handle($second)->digest());
	$branch=PanelSyncDocument::branch('Branch:1',['Device:1'=>2,'Seen:1'=>2],'Device:1',[['path'=>'name','value'=>'branch']]);$t->same(3,$branch->clock()['Device:1']??0);$t->same(2,$branch->clock()['Seen:1']??0);
})->tag('panel','local-first','crdt','branches','conflicts')->maxMillis(5000);

test('local-first gateway rejects source escalation sequence rebinding and forged device signatures',static function(Context $t):void{
	$device=dp_panel_local_first_device();$syncKey=str_repeat('s',32);$replica=new PanelLocalReplica('Server:1',['sync'=>$syncKey],'sync',static fn():bool=>true);$source=new PanelArrayDataSource([],['mutation_authorize'=>static fn():bool=>true]);$registry=(new PanelDataSourceRegistry())->register('orders',$source)->register('secrets',$source);$store=new PanelInMemoryLocalFirstReplayStore();$gateway=new PanelLocalFirstGateway($registry,static fn()=>$replica,['credential'=>str_repeat('c',32)],['response'=>str_repeat('r',32)],'response',['sync'=>$syncKey],'sync',$store,static fn():bool=>true,null,static fn():string=>'2026-07-16T12:00:00Z');
	$sourceEscalation=dp_panel_local_first_mutation_payload();$sourceEscalation['source']='secrets';$rejected=$gateway->handle(dp_panel_local_first_request($device,1,'mutation_batch',$sourceEscalation,'nonce_source_escalate_01'));$t->same('rejected',$rejected->status());$t->same(0,$store->latestSequence($device['credential']->credentialId()));
	$valid=dp_panel_local_first_request($device,1,'mutation_batch',dp_panel_local_first_mutation_payload(),'nonce_valid_mutation_0001');$validResponse=$gateway->handle($valid);$t->same('ok',$validResponse->status());$rebound=dp_panel_local_first_request($device,1,'mutation_batch',dp_panel_local_first_mutation_payload('order-2','offline-order-two'),'nonce_rebound_mutation_01');$t->throws(static fn()=>$gateway->handle($rebound),LogicException::class);
	$payload=$valid->jsonSerialize();$payload['signature']=PanelLocalFirstCrypto::base64UrlEncode(str_repeat("\0",64));$forged=PanelLocalFirstRequest::hydrate($payload);$t->throws(static fn()=>$gateway->handle($forged),LogicException::class);
	$gap=dp_panel_local_first_request($device,3,'mutation_batch',dp_panel_local_first_mutation_payload('order-3','offline-order-three'),'nonce_sequence_gap_0003');$t->throws(static fn()=>$gateway->handle($gap),LogicException::class);
})->tag('panel','local-first','adversarial','scope','sequence')->maxMillis(5000);

test('local-first authorization and scope resolution fail closed without leaking backend details',static function(Context $t):void{
	$device=dp_panel_local_first_device();$syncKey=str_repeat('s',32);$replica=new PanelLocalReplica('Server:1',['sync'=>$syncKey],'sync',static fn():bool=>true);$registry=(new PanelDataSourceRegistry())->register('orders',new PanelArrayDataSource([],['tenant_field'=>null,'mutation_authorize'=>static fn():bool=>true]));$request=dp_panel_local_first_request($device,1,'mutation_batch',dp_panel_local_first_mutation_payload(),'nonce_fail_closed_0001');
	$denied=new PanelLocalFirstGateway($registry,static fn()=>$replica,['credential'=>str_repeat('c',32)],['response'=>str_repeat('r',32)],'response',['sync'=>$syncKey],'sync',new PanelInMemoryLocalFirstReplayStore(),static fn():bool=>false,null,static fn():string=>'2026-07-16T12:00:00Z');$t->throws(static fn()=>$denied->handle($request),LogicException::class);
	$widened=new PanelLocalFirstGateway($registry,static fn()=>$replica,['credential'=>str_repeat('c',32)],['response'=>str_repeat('r',32)],'response',['sync'=>$syncKey],'sync',new PanelInMemoryLocalFirstReplayStore(),static fn():bool=>true,static fn():array=>['actor_id'=>'Admin:1','tenant'=>'south','authorization'=>[]],static fn():string=>'2026-07-16T12:00:00Z');$response=$widened->handle($request);$t->same('rejected',$response->status());$t->notContains('Admin:1',json_encode($response,JSON_THROW_ON_ERROR));
	$broken=new PanelLocalFirstGateway($registry,static function():never{throw new RuntimeException('private replica detail');},['credential'=>str_repeat('c',32)],['response'=>str_repeat('r',32)],'response',['sync'=>$syncKey],'sync',new PanelInMemoryLocalFirstReplayStore(),static fn():bool=>true,null,static fn():string=>'2026-07-16T12:00:00Z');$document=dp_panel_local_first_request($device,1,'document_sync',['type'=>'panel_local_first_document_batch','version'=>1,'changes'=>[['document_id'=>'Order:1','base_clock'=>[],'operations'=>[['path'=>'name','value'=>'x']]]]],'nonce_broken_replica_01');$failed=$broken->handle($document);$t->same('error',$failed->status());$t->notContains('private replica detail',json_encode($failed,JSON_THROW_ON_ERROR));
})->tag('panel','local-first','authorization','fail-closed','redaction')->maxMillis(5000);

test('fenced replay claims and replica evidence survive response loss and restart',static function(Context $t):void{
	$device=dp_panel_local_first_device();$id=$device['credential']->credentialId();$digest=hash('sha256','request-one');$store=new PanelInMemoryLocalFirstReplayStore();$first=$store->claim($id,1,$digest,'2026-07-16T12:00:00Z',30);$t->isTrue($first->acquiredLease());$t->same('acquired',$first->jsonSerialize()['state']);$t->same(null,$store->response($id,1));$t->throws(static fn()=>$store->claim($id,1,$digest,'2026-07-16T12:00:10Z',30),PanelLocalFirstReplayException::class);$t->throws(static fn()=>$store->claim($id,1,hash('sha256','rebound'),'2026-07-16T12:00:10Z',30),LogicException::class);
	$failure=new PanelLocalFirstReplayException('request_in_flight','Already processing.',true);$t->same('request_in_flight',$failure->publicCode());$t->isTrue($failure->retryable());$t->same('panel_local_first_replay_error',$failure->jsonSerialize()['type']);
	$takeover=$store->claim($id,1,$digest,'2026-07-16T12:00:31Z',30);$response=PanelLocalFirstResponse::sign($digest,1,'ok',['result'=>'recovered'],'2026-07-16T12:00:31Z','response',str_repeat('r',32));$t->throws(static fn()=>$store->complete($first,$response),PanelLocalFirstReplayException::class);$store->complete($takeover,$response);$t->same($response->digest(),$store->response($id,1)?->digest());$replay=$store->claim($id,1,$digest,'2026-07-16T12:00:32Z');$t->isFalse($replay->acquiredLease());$t->same('replay',$replay->jsonSerialize()['state']);$t->same($response->digest(),$replay->response()?->digest());
	$second=$store->claim($id,2,hash('sha256','request-two'),'2026-07-16T12:00:33Z');$store->abandon($second);$t->same(1,$store->latestSequence($id));$t->isTrue($store->claim($id,2,hash('sha256','request-two'),'2026-07-16T12:00:34Z')->acquiredLease());
	$syncKey=str_repeat('s',32);$allow=static fn():bool=>true;$replica=new PanelLocalReplica('Server:1',['sync'=>$syncKey],'sync',$allow);$branch=PanelSyncDocument::branch('Order:1',[],'User:1@Device:1',[['path'=>'name','value'=>'offline']]);$envelope=PanelSyncEnvelope::sign('User:1@Device:1',1,'2026-07-16T12:00:00Z',[$branch],'sync',$syncKey);$merged=$replica->mergeIdempotently($envelope);$t->isFalse($merged['replayed']);$restored=(new PanelLocalReplica('Server:1',['sync'=>$syncKey],'sync',$allow))->restore($replica->checkpoint());$retried=$restored->mergeIdempotently($envelope);$t->isTrue($retried['replayed']);$t->same($merged['envelope_digest'],$retried['envelope_digest']);$t->throws(static fn()=>$restored->merge($envelope),LogicException::class);
})->tag('panel','local-first','replay','lease','restart')->maxMillis(5000);

test('pdo replay ledger requires explicit schema and preserves host transactions durable fencing and exact responses',static function(Context $t):void{
	$pdo=new PDO('sqlite::memory:');$store=new PanelPdoLocalFirstReplayStore($pdo,'panel_lf_replay');$device=dp_panel_local_first_device();$id=$device['credential']->credentialId();$digest=hash('sha256','pdo-request');$t->throws(static fn()=>$store->claim($id,1,$digest,'2026-07-16T12:00:00Z'),PanelLocalFirstReplayException::class);$t->same(2,count(PanelLocalFirstReplaySchema::statements('sqlite','panel_lf_replay')));$t->same(2,count(PanelLocalFirstReplaySchema::statements('pgsql','panel_lf_replay')));$t->same(1,count(PanelLocalFirstReplaySchema::statements('mysql','panel_lf_replay')));$store->migrate();
	$pdo->beginTransaction();$t->throws(static fn()=>$store->claim($id,2,$digest,'2026-07-16T12:00:00Z'),LogicException::class);$t->isTrue($pdo->inTransaction());$pdo->rollBack();
	$pdo->beginTransaction();$rolled=$store->claim($id,1,$digest,'2026-07-16T12:00:00Z');$t->isTrue($pdo->inTransaction());$pdo->rollBack();$claim=$store->claim($id,1,$digest,'2026-07-16T12:00:01Z');$response=PanelLocalFirstResponse::sign($digest,1,'ok',['durable'=>true],'2026-07-16T12:00:01Z','response',str_repeat('r',32));$store->complete($claim,$response);$reopened=new PanelPdoLocalFirstReplayStore($pdo,'panel_lf_replay');$t->same($response->digest(),$reopened->response($id,1)?->digest());$t->same(1,$reopened->latestSequence($id));$t->same($response->digest(),$reopened->claim($id,1,$digest,'2026-07-16T12:00:02Z')->response()?->digest());
	$secondDigest=hash('sha256','pdo-request-two');$pending=$reopened->claim($id,2,$secondDigest,'2026-07-16T12:00:03Z',5);$t->throws(static fn()=>$reopened->claim($id,2,$secondDigest,'2026-07-16T12:00:04Z',5),PanelLocalFirstReplayException::class);$takeover=$reopened->claim($id,2,$secondDigest,'2026-07-16T12:00:09Z',5);$secondResponse=PanelLocalFirstResponse::sign($secondDigest,2,'ok',['takeover'=>true],'2026-07-16T12:00:09Z','response',str_repeat('r',32));$t->throws(static fn()=>$reopened->complete($pending,$secondResponse),PanelLocalFirstReplayException::class);$reopened->complete($takeover,$secondResponse);$t->same(2,$reopened->latestSequence($id));$thirdDigest=hash('sha256','pdo-request-three');$third=$reopened->claim($id,3,$thirdDigest,'2026-07-16T12:00:10Z');$reopened->abandon($third);$t->same(null,$reopened->response($id,3));$t->isTrue($reopened->claim($id,3,$thirdDigest,'2026-07-16T12:00:11Z')->acquiredLease());$manifest=json_encode($reopened,JSON_THROW_ON_ERROR);$t->contains('"durable":true',$manifest);$t->notContains('sqlite::memory',$manifest);$t->isFalse($rolled->leaseToken()===$claim->leaseToken());
})->tag('panel','local-first','pdo','durable','transactions')->maxMillis(5000);

test('pdo replay ledger contains native transaction and rollback failures without leaking ownership',static function(Context $t):void{
	$device=dp_panel_local_first_device();$id=$device['credential']->credentialId();$digest=hash('sha256','pdo-native-transaction');
	$pdo=new DpPanelLocalFirstPdoProbe();$store=new PanelPdoLocalFirstReplayStore($pdo,'panel_lf_native');
	$claim=$store->claim($id,1,$digest,'2026-07-16T12:00:00Z');$t->isTrue($claim->acquiredLease());$t->same(['begin','prepare','prepare','prepare','commit'],$pdo->events);$t->isFalse($pdo->inTransaction());
	$failing=new DpPanelLocalFirstPdoProbe();$failing->failPrepare=true;$broken=new PanelPdoLocalFirstReplayStore($failing,'panel_lf_rollback');
	$t->throws(static fn()=>$broken->claim($id,1,$digest,'2026-07-16T12:00:00Z'),PanelLocalFirstReplayException::class);$t->same(['begin','prepare','rollback'],$failing->events);$t->isFalse($failing->inTransaction());
	$contained=new DpPanelLocalFirstPdoProbe();$contained->failPrepare=true;$contained->failRollback=true;$doubleFailure=new PanelPdoLocalFirstReplayStore($contained,'panel_lf_rollback_failure');
	$t->throws(static fn()=>$doubleFailure->claim($id,1,$digest,'2026-07-16T12:00:00Z'),PanelLocalFirstReplayException::class);$t->same(['begin','prepare','rollback'],$contained->events);
})->tag('panel','local-first','pdo','transactions','rollback','failure-containment')->maxMillis(3000);

test('browser asset exposes encrypted offline runtime service worker and cross-language numeric canonicalization',static function(Context $t):void{
	$javascript=PanelLocalFirstClientAssets::javascript();$worker=PanelLocalFirstClientAssets::serviceWorker();$manifest=PanelLocalFirstClientAssets::manifest();$t->greaterThan(30000,strlen($javascript));$t->matches('/^[a-f0-9]{16}$/',PanelLocalFirstClientAssets::version());$t->same('application/javascript; charset=utf-8',PanelLocalFirstClientAssets::content()['content_type']);$t->contains('immutable',PanelLocalFirstClientAssets::content('service_worker',true)['cache_control']);$t->contains('DataphyrePanelLocalFirst.installServiceWorker(self)',$worker);
	foreach(['indexedDB','AES-GCM','ECDSA','BroadcastChannel','serviceWorker','navigator.locks','signed_response_before_dequeue','canonicalDigest','replaceChildren']as$contract){$t->contains($contract,$javascript);}$t->notContains('localStorage',$javascript);$t->notContains('eval(',$javascript);$t->notContains('.innerHTML',$javascript);$t->isTrue($manifest['capabilities']['encrypted_queue']);$t->isTrue($manifest['capabilities']['service_worker']);$t->isFalse($manifest['shared_asset_registered']);$t->isFalse($manifest['secrets_exposed']);
	$numbers=PanelLocalFirstCanonical::value(['integer'=>1,'decimal'=>1.0,'tiny'=>1.0e-7,'large'=>1.0e20,'negative_zero'=>-0.0]);$t->same('3ff0000000000000',$numbers['integer']['@panel_number_f64']);$t->same($numbers['integer'],$numbers['decimal']);$t->matches('/^[a-f0-9]{16}$/',$numbers['tiny']['@panel_number_f64']);$t->same(64,strlen(PanelLocalFirstCanonical::digest(['sequence'=>1,'values'=>[1.0e-7,1.0e20,-0.0]])));$t->throws(static fn()=>PanelLocalFirstCanonical::value(INF),InvalidArgumentException::class);
	$device=dp_panel_local_first_device();$numeric=dp_panel_local_first_mutation_payload();$numeric['mutations'][0]['values']=['one'=>1.0,'tiny'=>1.0e-7,'large'=>1.0e20,'zero'=>-0.0];$request=dp_panel_local_first_request($device,1,'mutation_batch',$numeric,'nonce_numeric_payload_01');$t->isTrue($request->verifyDeviceSignature());$t->same($request->digest(),PanelLocalFirstRequest::hydrate($request->jsonSerialize())->digest());$response=PanelLocalFirstResponse::sign($request->digest(),1,'ok',['numbers'=>$numeric['mutations'][0]['values']],'2026-07-16T12:00:00Z','response',str_repeat('r',32));$t->isTrue($response->verify(['response'=>str_repeat('r',32)]));
})->tag('panel','local-first','browser','asset','canonical')->maxMillis(5000);
