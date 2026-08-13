<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelDataMutation;
use Dataphyre\Panel\PanelDataMutationAccessDenied;
use Dataphyre\Panel\PanelDataMutationBatch;
use Dataphyre\Panel\PanelDataMutationReceipt;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelHttpDataMutationAuthenticator;
use Dataphyre\Panel\PanelHttpDataMutationCapabilityPin;
use Dataphyre\Panel\PanelHttpDataMutationDefinition;
use Dataphyre\Panel\PanelHttpDataMutationException;
use Dataphyre\Panel\PanelHttpDataMutationProtocolRequest;
use Dataphyre\Panel\PanelHttpDataMutationScopeMapper;
use Dataphyre\Panel\PanelHttpDataSource;
use Dataphyre\Panel\PanelHttpDataSourceCapabilityPin;
use Dataphyre\Panel\PanelHttpDataSourceDefinition;
use Dataphyre\Panel\PanelHttpDataSourceRuntime;
use Dataphyre\Panel\PanelHttpDataSourceScope;
use Dataphyre\Panel\PanelHttpDataSourceScopeMapper;
use Dataphyre\Panel\PanelHttpDataSourceTransport;
use Dataphyre\Panel\PanelHttpDataSourceTransportRequest;
use Dataphyre\Panel\PanelHttpDataSourceTransportResponse;
use Dataphyre\Panel\PanelHttpMutableDataSource;
use Dataphyre\Panel\PanelScriptedHttpDataSourceTransport;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelHttpMutationRuntime implements PanelHttpDataSourceRuntime {
	public int $now=2_000_000_000;public int $ids=0;public bool $cancelled=false;/** @var list<int> */public array $waits=[];
	public function nowMilliseconds():int{return$this->now;}public function requestId():string{return'mutreq_'.str_pad((string)++$this->ids,8,'0',STR_PAD_LEFT);}public function cancellationRequested():bool{return$this->cancelled;}public function cancellationReason():?string{return$this->cancelled?'test_cancelled':null;}public function waitMilliseconds(int $milliseconds,int $deadlineUnixMilliseconds):bool{$this->waits[]=$milliseconds;$this->now+=$milliseconds;return$this->now<$deadlineUnixMilliseconds;}
}

final class DpPanelHttpMutationTransport implements PanelHttpDataSourceTransport {
	/** @var list<PanelHttpDataSourceTransportRequest> */public array $requests=[];private readonly Closure $handler;
	public function __construct(callable $handler){$this->handler=Closure::fromCallable($handler);}
	public function send(PanelHttpDataSourceTransportRequest $request):PanelHttpDataSourceTransportResponse{$this->requests[]=$request;return($this->handler)($request,count($this->requests));}
}

/** @return array{source:PanelHttpMutableDataSource,definition:PanelHttpDataMutationDefinition,authenticator:PanelHttpDataMutationAuthenticator,runtime:DpPanelHttpMutationRuntime} */
function dp_panel_http_mutation_fixture(PanelHttpDataSourceTransport $transport,?PanelHttpDataMutationScopeMapper $mapper=null,array $definitionOptions=[]):array{
	$readPin=PanelHttpDataSourceCapabilityPin::readOnly('id');$readDefinition=new PanelHttpDataSourceDefinition('remote_orders','https://private.example/read',$readPin,['cursor_keys'=>['active'=>str_repeat('c',32)]]);
	$readMapper=new class implements PanelHttpDataSourceScopeMapper {public function map(\Dataphyre\Panel\PanelDataQuery $query,PanelHttpDataSourceDefinition $definition):PanelHttpDataSourceScope{return PanelHttpDataSourceScope::make('operator-1',$query->tenantKey(),['read'=>true]);}};
	$read=new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$readDefinition,$readMapper,new DpPanelHttpMutationRuntime());
	$authenticator=new PanelHttpDataMutationAuthenticator(['active'=>str_repeat('m',32),'previous'=>str_repeat('p',32)],'active',7);$pin=PanelHttpDataMutationCapabilityPin::writable('id');$definition=new PanelHttpDataMutationDefinition('remote_orders','https://private.example/mutate',$pin,$authenticator,array_replace(['max_attempts'=>2,'retry_backoff_ms'=>10],$definitionOptions));
	$mapper??=new class implements PanelHttpDataMutationScopeMapper {public function map(PanelDataMutation $mutation,PanelHttpDataMutationDefinition $definition):PanelHttpDataSourceScope{return PanelHttpDataSourceScope::make($mutation->actorId(),$mutation->tenantKey(),['orders_mutate'=>true]);}};
	$runtime=new DpPanelHttpMutationRuntime();return['source'=>new PanelHttpMutableDataSource($read,$transport,$definition,$mapper,$runtime),'definition'=>$definition,'authenticator'=>$authenticator,'runtime'=>$runtime];
}

/** @return array<string,mixed> */
function dp_panel_http_mutation_options(string $idempotency,?int $revision=null,array $extra=[]):array{$options=['idempotency_key'=>$idempotency,'actor_id'=>'operator-1','tenant'=>'north','authorization'=>['local_role'=>'ops'],'metadata'=>['origin'=>'test']];if($revision!==null){$options['expected_revision']=$revision;}return array_replace($options,$extra);}

/** @return array<string,mixed> */
function dp_panel_http_mutation_wire(PanelHttpDataSourceTransportRequest $request):array{return json_decode($request->body(),true,64,JSON_THROW_ON_ERROR);}

/** @param list<PanelDataMutationReceipt> $receipts */
function dp_panel_http_mutation_success(PanelHttpDataMutationDefinition $definition,PanelHttpDataSourceTransportRequest $transportRequest,array $receipts):PanelHttpDataSourceTransportResponse{
	$wire=dp_panel_http_mutation_wire($transportRequest);$body=['type'=>'panel_http_data_mutation_response','version'=>1,'operation'=>$wire['operation'],'request_id'=>$wire['request_id'],'source'=>$wire['source'],'definition_fingerprint'=>$wire['definition_fingerprint'],'capability'=>$wire['capability'],'request_fingerprint'=>$wire['request_fingerprint'],'result'=>['atomic'=>$wire['request']['atomic'],'count'=>count($receipts),'receipts'=>array_map(static fn(PanelDataMutationReceipt $receipt):array=>$receipt->jsonSerialize(),$receipts)]];
	return PanelHttpDataSourceTransportResponse::json(200,$definition->authenticator()->seal($body),2.5);
}

function dp_panel_http_mutation_error(PanelHttpDataMutationDefinition $definition,PanelHttpDataSourceTransportRequest $transportRequest,int $status=503,bool $retryable=true):PanelHttpDataSourceTransportResponse{
	$wire=dp_panel_http_mutation_wire($transportRequest);$body=['type'=>'panel_http_data_mutation_error','version'=>1,'operation'=>$wire['operation'],'request_id'=>$wire['request_id'],'source'=>$wire['source'],'definition_fingerprint'=>$wire['definition_fingerprint'],'capability'=>$wire['capability'],'request_fingerprint'=>$wire['request_fingerprint'],'error'=>['code'=>'upstream_unavailable','status'=>$status,'retryable'=>$retryable]];
	return PanelHttpDataSourceTransportResponse::json($status,$definition->authenticator()->seal($body),1.0);
}

/** @return list<PanelDataMutationReceipt> */
function dp_panel_http_mutation_receipts(PanelHttpDataMutationDefinition $definition,PanelHttpDataSourceTransportRequest $request,array &$stored=[]):array{
	$wire=dp_panel_http_mutation_wire($request);$receipts=[];foreach($wire['request']['mutations']as$payload){$digest=$payload['idempotency_digest'];if(isset($stored[$digest])){$receipts[]=$stored[$digest]->asReplay();continue;}$record=$payload['operation']==='delete'?null:array_replace($payload['values'],['id'=>$payload['key'],'tenant_id'=>$wire['scope']['tenant']]);$outcome=match($payload['operation']){'create'=>'created','delete'=>'deleted',default=>'updated'};$revision=$payload['expected_revision']===null?1:$payload['expected_revision']+1;$receipt=new PanelDataMutationReceipt($definition->name(),$payload['operation'],$payload['key'],$outcome,$revision,$payload['mutation_fingerprint'],$digest,'2026-07-16T12:00:00+00:00',$payload['return_record']?$record:null,array_keys($payload['values']),['remote'=>true]);$stored[$digest]=$receipt;$receipts[]=$receipt;}return$receipts;
}

test('remote mutation capability and authentication stay separate exact and secret-safe',static function(Context $t):void{
	$auth=new PanelHttpDataMutationAuthenticator(['new'=>str_repeat('n',32),'old'=>str_repeat('o',32)],'new',3);$sealed=$auth->seal(['type'=>'probe','value'=>1]);$auth->verify($sealed);$t->same('new',$sealed['key_id']);$t->isFalse($auth->jsonSerialize()['secrets_serialized']);
	$tampered=$sealed;$tampered['value']=2;$t->throws(static fn()=>$auth->verify($tampered),UnexpectedValueException::class);
	$pin=PanelHttpDataMutationCapabilityPin::writable('id',['mutation_max_batch'=>25],4);$t->same(25,$pin->capabilities()['mutation_max_batch']);$t->same(64,strlen($pin->fingerprint()));
	$t->same($pin->fingerprint(),PanelHttpDataMutationCapabilityPin::fromArray($pin->version(),$pin->capabilities())->fingerprint());
	$t->throws(static fn()=>PanelHttpDataMutationCapabilityPin::writable('id',['mutation_idempotency'=>false]),InvalidArgumentException::class);
	$read=PanelHttpDataSourceCapabilityPin::readOnly();$readCapabilities=$read->capabilities();$readCapabilities['mutations']=true;$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::fromArray(1,$readCapabilities),InvalidArgumentException::class);
	$definition=new PanelHttpDataMutationDefinition('orders','https://private.example/mutate',$pin,$auth,[]);$encoded=json_encode($definition,JSON_THROW_ON_ERROR);$t->notContains('private.example',$encoded);$t->notContains(str_repeat('n',32),$encoded);$t->isTrue($definition->jsonSerialize()['retry']['idempotent_mutations_only']);
	$t->throws(static fn()=>new PanelHttpDataMutationDefinition('orders','https://user:pass@private.example/mutate',$pin,$auth,[]),InvalidArgumentException::class);
})->tag('panel','data-source','http','mutation','capability','authentication')->maxMillis(3000);

test('signed remote mutation sends scoped values but never raw idempotency or local authority',static function(Context $t):void{
	$fixture=null;$stored=[];$transport=new DpPanelHttpMutationTransport(static function(PanelHttpDataSourceTransportRequest $request)use(&$fixture,&$stored):PanelHttpDataSourceTransportResponse{return dp_panel_http_mutation_success($fixture['definition'],$request,dp_panel_http_mutation_receipts($fixture['definition'],$request,$stored));});$fixture=dp_panel_http_mutation_fixture($transport);
	$mutation=PanelDataMutation::create('o1',['email'=>'one@example.test','status'=>'open'],dp_panel_http_mutation_options('remote-create-o1-0001'));
	$receipt=$fixture['source']->mutate($mutation);$t->same('created',$receipt->outcome());$t->same('o1',$receipt->record()['id']);$t->same(1,$receipt->revision());
	$wire=dp_panel_http_mutation_wire($transport->requests[0]);$fixture['authenticator']->verify($wire);$body=$transport->requests[0]->body();$t->contains('one@example.test',$body);$t->notContains('remote-create-o1-0001',$body);$t->notContains('local_role',$body);$t->same($mutation->idempotencyDigest(),$wire['request']['mutations'][0]['idempotency_digest']);$t->same(['orders_mutate'=>true],$wire['scope']['authorization']);
	$replay=$fixture['source']->mutate($mutation);$t->isTrue($replay->replayed());$t->same($receipt->receiptId(),$replay->receiptId());
	$manifest=json_encode($fixture['source'],JSON_THROW_ON_ERROR);$t->notContains('private.example',$manifest);$t->notContains('one@example.test',$manifest);$t->isTrue($fixture['source']->capabilities()['mutations']);$t->same('upstream_persistent',$fixture['source']->capabilities()['mutation_idempotency_scope']);
	$t->same($fixture['definition'],$fixture['source']->mutationDefinition());$t->same($transport,$fixture['source']->mutationTransport());$t->same('remote_orders',$fixture['source']->readSource()->definition()->name());
	$t->throws(static fn()=>$fixture['source']->query(PanelDataQuery::make()),Throwable::class);$t->throws(static fn()=>$fixture['source']->find('missing'),Throwable::class);
})->tag('panel','data-source','http','mutation','wire','privacy')->maxMillis(4000);

test('remote mutation retries only authenticated idempotent failures with stable request identity',static function(Context $t):void{
	$fixture=null;$calls=0;$stored=[];$transport=new DpPanelHttpMutationTransport(static function(PanelHttpDataSourceTransportRequest $request)use(&$fixture,&$calls,&$stored):PanelHttpDataSourceTransportResponse{$calls++;if($calls===1){return dp_panel_http_mutation_error($fixture['definition'],$request,503,true);}return dp_panel_http_mutation_success($fixture['definition'],$request,dp_panel_http_mutation_receipts($fixture['definition'],$request,$stored));});$fixture=dp_panel_http_mutation_fixture($transport);
	$mutation=PanelDataMutation::create('o1',['status'=>'open'],dp_panel_http_mutation_options('retry-create-o1-001'));$t->same('created',$fixture['source']->mutate($mutation)->outcome());$t->same(2,count($transport->requests));$t->same([10],$fixture['runtime']->waits);
	$one=dp_panel_http_mutation_wire($transport->requests[0]);$two=dp_panel_http_mutation_wire($transport->requests[1]);$t->same($one['request_id'],$two['request_id']);$t->same($one['request_fingerprint'],$two['request_fingerprint']);$t->same($one['request']['mutations'][0]['idempotency_digest'],$two['request']['mutations'][0]['idempotency_digest']);$t->same(1,$one['execution']['attempt']);$t->same(2,$two['execution']['attempt']);$fixture['authenticator']->verify($one);$fixture['authenticator']->verify($two);
	$health=$fixture['source']->mutationHealth();$t->same('closed',$health['status']);$t->same(2,$health['attempts']);$t->same(1,$health['retries']);
})->tag('panel','data-source','http','mutation','retry','idempotency')->maxMillis(4000);

test('remote mutation batch receipts pass the universal mutable adapter conformance pack',static function(Context $t):void{
	$fixture=null;$stored=[];$transport=new DpPanelHttpMutationTransport(static function(PanelHttpDataSourceTransportRequest $request)use(&$fixture,&$stored):PanelHttpDataSourceTransportResponse{return dp_panel_http_mutation_success($fixture['definition'],$request,dp_panel_http_mutation_receipts($fixture['definition'],$request,$stored));});$fixture=dp_panel_http_mutation_fixture($transport);
	$mutation=PanelDataMutation::create('c1',['status'=>'open'],dp_panel_http_mutation_options('remote-conform-c1'));
	$batch=new PanelDataMutationBatch([PanelDataMutation::create('c2',['status'=>'open'],dp_panel_http_mutation_options('remote-conform-c2')),PanelDataMutation::create('c3',['status'=>'open'],dp_panel_http_mutation_options('remote-conform-c3'))]);
	$report=(new PanelAdapterConformanceRunner())->run(PanelAdapterConformanceCatalog::mutableDataSource(),$fixture['source'],['allow_destructive'=>true,'mutation'=>$mutation,'batch'=>$batch]);$t->isTrue($report->passed());$t->same(3,$report->summary()['passed']);$t->same(4,count($transport->requests));$t->notContains('remote-conform-c1',json_encode($report,JSON_THROW_ON_ERROR));
})->tag('panel','data-source','http','mutation','batch','conformance')->maxMillis(5000);

test('remote mutation scope and signed response integrity fail closed before accepting effects',static function(Context $t):void{
	$wrongMapper=new class implements PanelHttpDataMutationScopeMapper {public function map(PanelDataMutation $mutation,PanelHttpDataMutationDefinition $definition):PanelHttpDataSourceScope{return PanelHttpDataSourceScope::make('another-actor',$mutation->tenantKey(),['orders_mutate'=>true]);}};
	$unused=new DpPanelHttpMutationTransport(static fn():PanelHttpDataSourceTransportResponse=>throw new RuntimeException('must not send'));$fixture=dp_panel_http_mutation_fixture($unused,$wrongMapper);$mutation=PanelDataMutation::create('o1',['status'=>'open'],dp_panel_http_mutation_options('scope-create-o1-01'));
	$t->throws(static fn()=>$fixture['source']->mutate($mutation),PanelDataMutationAccessDenied::class);$t->same(0,count($unused->requests));
	$badFixture=null;$badTransport=new DpPanelHttpMutationTransport(static function(PanelHttpDataSourceTransportRequest $request)use(&$badFixture):PanelHttpDataSourceTransportResponse{$receipt=dp_panel_http_mutation_receipts($badFixture['definition'],$request)[0];$response=dp_panel_http_mutation_success($badFixture['definition'],$request,[$receipt]);$body=json_decode($response->body(),true,64,JSON_THROW_ON_ERROR);$body['result']['receipts'][0]['revision']=99;return PanelHttpDataSourceTransportResponse::json(200,$body);});$badFixture=dp_panel_http_mutation_fixture($badTransport,definitionOptions:['max_attempts'=>1,'circuit_failure_threshold'=>1]);
	try{$badFixture['source']->mutate($mutation);$t->fail('Tampered response must fail.');}catch(PanelHttpDataMutationException $error){$t->same('mutation_remote_protocol_invalid',$error->publicCode());}$t->same('open',$badFixture['source']->mutationHealth()['status']);
	$t->throws(static fn()=>$badFixture['source']->mutate($mutation),PanelHttpDataMutationException::class);
})->tag('panel','data-source','http','mutation','scope','integrity','circuit')->maxMillis(4000);

test('remote mutation protocol DTO redacts public values and enforces request bounds',static function(Context $t):void{
	$fixture=dp_panel_http_mutation_fixture(new PanelScriptedHttpDataSourceTransport());$mutation=PanelDataMutation::create('o1',['payload'=>'visible-on-wire'],dp_panel_http_mutation_options('dto-create-o1-0001'));$scope=PanelHttpDataSourceScope::make('operator-1','north',['orders_mutate'=>true]);
	$request=new PanelHttpDataMutationProtocolRequest('mutreq_00000001',$fixture['definition'],$mutation,$scope,2_000_010_000,10000,1,2);$manifest=json_encode($request,JSON_THROW_ON_ERROR);$t->notContains('visible-on-wire',$manifest);$t->isFalse($request->jsonSerialize()['values_serialized']);$t->contains('visible-on-wire',$request->encode(1048576));$fixture['authenticator']->verify($request->wireEnvelope());
	$t->throws(static fn()=>$request->encode(64),LengthException::class);$fixture['runtime']->cancelled=true;$t->throws(static fn()=>$fixture['source']->mutate($mutation),PanelHttpDataMutationException::class);$t->same(0,$fixture['source']->mutationHealth()['attempts']);
})->tag('panel','data-source','http','mutation','dto','bounds')->maxMillis(3000);

test('remote mutation transport failures and stable infrastructure factories remain classified',static function(Context $t):void{
	$transport=new DpPanelHttpMutationTransport(static fn(PanelHttpDataSourceTransportRequest $request):PanelHttpDataSourceTransportResponse=>throw new RuntimeException('private transport failure'));$fixture=dp_panel_http_mutation_fixture($transport,definitionOptions:['max_attempts'=>1]);$mutation=PanelDataMutation::create('transport-one',['status'=>'open'],dp_panel_http_mutation_options('transport-failure-one'));
	$error=$t->throws(static fn()=>$fixture['source']->mutate($mutation),PanelHttpDataMutationException::class);$t->same('mutation_remote_transport_unavailable',$error->publicCode());$t->isTrue($error->countsTowardCircuit());
	$cases=[PanelHttpDataMutationException::deadline(),PanelHttpDataMutationException::transportUnavailable(),PanelHttpDataMutationException::capabilityMismatch(),PanelHttpDataMutationException::requestTooLarge(),PanelHttpDataMutationException::runtimeUnavailable()];$t->same(['mutation_remote_deadline','mutation_remote_transport_unavailable','mutation_remote_capability_mismatch','mutation_remote_request_too_large','mutation_remote_runtime_unavailable'],array_map(static fn(PanelHttpDataMutationException $failure):string=>$failure->publicCode(),$cases));$t->same([true,true,true,false,false],array_map(static fn(PanelHttpDataMutationException $failure):bool=>$failure->countsTowardCircuit(),$cases));
})->tag('panel','data-source','http','mutation','transport','classification')->maxMillis(3000);
