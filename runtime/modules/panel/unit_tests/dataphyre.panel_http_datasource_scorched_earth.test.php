<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelHttpDataSource;
use Dataphyre\Panel\PanelHttpDataSourceCapabilityPin;
use Dataphyre\Panel\PanelHttpDataSourceCursorCodec;
use Dataphyre\Panel\PanelHttpDataSourceDefinition;
use Dataphyre\Panel\PanelHttpDataSourceException;
use Dataphyre\Panel\PanelHttpDataSourceProtocolRequest;
use Dataphyre\Panel\PanelHttpDataSourceProtocolResponse;
use Dataphyre\Panel\PanelHttpDataSourceRuntime;
use Dataphyre\Panel\PanelHttpDataSourceScope;
use Dataphyre\Panel\PanelHttpDataSourceScopeMapper;
use Dataphyre\Panel\PanelHttpDataSourceTransport;
use Dataphyre\Panel\PanelHttpDataSourceTransportRequest;
use Dataphyre\Panel\PanelHttpDataSourceTransportResponse;
use Dataphyre\Panel\PanelHttpDataSourceValue;
use Dataphyre\Panel\PanelQueryCapabilities;
use Dataphyre\Panel\PanelQueryComparison;
use Dataphyre\Panel\PanelQueryGroup;
use Dataphyre\Panel\PanelQueryRelation;
use Dataphyre\Panel\PanelScriptedHttpDataSourceTransport;
use Dataphyre\Panel\PanelSystemHttpDataSourceRuntime;
use Dataphyre\Panel\PanelUnsupportedQueryException;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

final class DpPanelHttpRuntime implements PanelHttpDataSourceRuntime {
	public int $now=1000000;
	public int $sequence=0;
	public bool $cancelled=false;
	public bool $waitResult=true;
	public bool $throwNow=false;
	public bool $throwId=false;
	public bool $throwCancel=false;
	public bool $throwWait=false;
	/** @var list<int> */ public array $waits=[];
	public function nowMilliseconds(): int { if($this->throwNow){ throw new RuntimeException('clock secret'); } return $this->now; }
	public function requestId(): string { if($this->throwId){ throw new RuntimeException('id secret'); } return 'rmt_'.str_pad((string)++$this->sequence,8,'0',STR_PAD_LEFT); }
	public function cancellationRequested(): bool { if($this->throwCancel){ throw new RuntimeException('cancel secret'); } return $this->cancelled; }
	public function cancellationReason(): ?string { return $this->cancelled ? 'host requested' : null; }
	public function waitMilliseconds(int $milliseconds, int $deadlineUnixMilliseconds): bool { if($this->throwWait){ throw new RuntimeException('wait secret'); } $this->waits[]=$milliseconds; $this->now+=$milliseconds; return $this->waitResult && !$this->cancelled && $this->now<$deadlineUnixMilliseconds; }
}

final class DpPanelHttpScopeMapper implements PanelHttpDataSourceScopeMapper {
	public bool $throw=false;
	public function __construct(public PanelHttpDataSourceScope $scope){}
	public function map(PanelDataQuery $query, PanelHttpDataSourceDefinition $definition): PanelHttpDataSourceScope { if($this->throw){ throw new RuntimeException('mapper secret'); } return $this->scope; }
}

/** @param array<string,mixed> $capabilityOverrides @param array<string,mixed> $definitionOverrides @return array{pin:PanelHttpDataSourceCapabilityPin,definition:PanelHttpDataSourceDefinition,scope:PanelHttpDataSourceScope,mapper:DpPanelHttpScopeMapper,runtime:DpPanelHttpRuntime} */
function dp_panel_http_fixture(array $capabilityOverrides=[], array $definitionOverrides=[]): array {
	$pin=PanelHttpDataSourceCapabilityPin::readOnly('id', $capabilityOverrides);
	$definition=new PanelHttpDataSourceDefinition('remote_orders','https://private.example.test/v1/panel-data',$pin,array_replace([
		'cursor_keys'=>['current'=>str_repeat('k',32)],'cursor_active_key'=>'current','max_attempts'=>1,
	],$definitionOverrides));
	$scope=PanelHttpDataSourceScope::make('operator-1','north',['permissions'=>['orders.read'],'role'=>'ops']);
	return ['pin'=>$pin,'definition'=>$definition,'scope'=>$scope,'mapper'=>new DpPanelHttpScopeMapper($scope),'runtime'=>new DpPanelHttpRuntime()];
}

function dp_panel_http_query_fingerprint(PanelHttpDataSourceDefinition $definition, PanelDataQuery $query, PanelHttpDataSourceScope $scope, string $operation='query', string|int|null $recordKey=null): string {
	$wire=PanelHttpDataSourceProtocolRequest::sanitizedQuery($query); unset($wire['offset'],$wire['limit']);
	return hash('sha256',PanelHttpDataSourceValue::canonical(['definition'=>$definition->fingerprint(),'capability'=>$definition->capabilityPin()->fingerprint(),'operation'=>$operation,'query'=>$wire,'scope'=>$scope->fingerprint(),'record_key'=>$recordKey]));
}

/** @param list<array<string,mixed>> $items @param list<string> $fields @param array<string,mixed> $aggregates @param array<string,list<array<string,mixed>>> $included @return array<string,mixed> */
function dp_panel_http_success(PanelHttpDataSourceDefinition $definition, PanelDataQuery $query, PanelHttpDataSourceScope $scope, string $requestId, array $items, array $fields, array $aggregates=[], array $included=[], ?string $next=null, ?string $previous=null, string $operation='query', string|int|null $recordKey=null, ?int $offset=null): array {
	return [
		'type'=>'panel_http_data_response','version'=>1,'operation'=>$operation,'request_id'=>$requestId,
		'definition_fingerprint'=>$definition->fingerprint(),'capability'=>['version'=>$definition->capabilityPin()->version(),'fingerprint'=>$definition->capabilityPin()->fingerprint()],
		'query_fingerprint'=>dp_panel_http_query_fingerprint($definition,$query,$scope,$operation,$recordKey),
		'data'=>[
			'items'=>$items,'page'=>['offset'=>$offset ?? $query->offsetValue(),'limit'=>$query->limitValue(),'returned'=>count($items),'total'=>count($items),'next_cursor'=>$next,'previous_cursor'=>$previous],
			'projection'=>['fields'=>$fields,'record_key'=>$definition->capabilityPin()->recordKeyField()],
			'aggregates'=>$aggregates,'included'=>$included,
		],
	];
}

/** @return array<string,mixed> */
function dp_panel_http_error(PanelHttpDataSourceDefinition $definition, PanelDataQuery $query, PanelHttpDataSourceScope $scope, string $requestId, int $status=503): array {
	return [
		'type'=>'panel_http_data_error','version'=>1,'operation'=>'query','request_id'=>$requestId,
		'definition_fingerprint'=>$definition->fingerprint(),'capability'=>['version'=>$definition->capabilityPin()->version(),'fingerprint'=>$definition->capabilityPin()->fingerprint()],
		'query_fingerprint'=>dp_panel_http_query_fingerprint($definition,$query,$scope),'error'=>['code'=>$status===429?'rate_limited':'unavailable','retryable'=>true],
	];
}

test('HTTP remote definition capability scope and generic preflight are closed and redacted', static function(Context $t): void {
	$fixture=dp_panel_http_fixture(); $pin=$fixture['pin']; $definition=$fixture['definition'];
	$t->same(1,$pin->version()); $t->same(64,strlen($pin->fingerprint())); $t->same('id',$pin->recordKeyField()); $t->same(250,$pin->maxLimit()); $t->isTrue($pin->supportsFind());
	$t->same('panel_http_data_capability_pin',$pin->jsonSerialize()['type']);
	$t->same('remote_orders',$definition->name()); $t->same('https://private.example.test/v1/panel-data',$definition->endpoint());
	$t->same(900,$definition->cursorTtl()); $t->same(262144,$definition->maxRequestBytes()); $t->same(1048576,$definition->maxResponseBytes()); $t->same(5000,$definition->timeoutMilliseconds()); $t->same(1,$definition->maxAttempts());
	$t->same([408,425,429,500,502,503,504],$definition->retryStatuses()); $t->same(25,$definition->retryBackoffMilliseconds()); $t->same(5,$definition->circuitFailureThreshold()); $t->same(30000,$definition->circuitOpenMilliseconds()); $t->same(64,strlen($definition->fingerprint()));
	$manifest=json_encode($definition,JSON_THROW_ON_ERROR); $t->notContains('private.example',$manifest); $t->notContains(str_repeat('k',32),$manifest); $t->contains('request_selectable',$manifest);

	$scope=$fixture['scope']; $t->same('operator-1',$scope->principal()); $t->same('north',$scope->tenant()); $t->same(['permissions'=>['orders.read'],'role'=>'ops'],$scope->authorization()); $t->same(64,strlen($scope->fingerprint()));
	$t->throws(static fn()=>PanelHttpDataSourceScope::make('operator',null,['api_token'=>'secret']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceScope::make(' ',null,[]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceScope::make('operator',str_repeat('x',193),[]),InvalidArgumentException::class);

	$base=PanelQueryCapabilities::full('generic');
	$features=[
		'search'=>PanelDataQuery::make()->search('x'),'select'=>PanelDataQuery::make()->select(['id']),'include'=>PanelDataQuery::make()->include(['items']),
		'aggregates'=>PanelDataQuery::make()->aggregate('count','count'),'cursor'=>PanelDataQuery::make()->cursor('opaque'),'offset'=>PanelDataQuery::make()->offset(1),
		'tenant'=>PanelDataQuery::make()->tenant('north'),'authorization'=>PanelDataQuery::make()->authorization(['actor'=>'one']),
	];
	foreach($features as $capability=>$query){ $t->throws(static fn()=>PanelQueryCapabilities::fromArray($base+[$capability=>false])->assertSupports($query),PanelUnsupportedQueryException::class); }
	$t->throws(static fn()=>PanelQueryCapabilities::fromArray($base+['max_limit'=>2])->assertSupports(PanelDataQuery::make()->limit(3)),PanelUnsupportedQueryException::class);
	PanelQueryCapabilities::fromArray($base)->assertSupports(PanelDataQuery::make()->search('legacy')->include(['items'])->limit(999)); $t->isTrue(true);

	$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::readOnly('id',['unknown'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::readOnly('id',['mutations'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::readOnly('id',['relations'=>false,'relation_depth'=>1]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::readOnly('id',['query_expression'=>false,'groups'=>['and']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::readOnly('id',['filters'=>false]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelHttpDataSourceCapabilityPin::readOnly('id',['max_limit'=>0]),InvalidArgumentException::class);
})->tag('panel','data-source','http','capabilities','security')->maxMillis(3000);

test('HTTP remote query validates projection aggregates includes and authenticated continuation', static function(Context $t): void {
	$fixture=dp_panel_http_fixture(['include'=>true,'cursor_previous'=>true]);
	$query=PanelDataQuery::make()->select(['name'])->include(['items'])->aggregate('orders','count')->aggregate('gross','sum','total')->aggregate('minimum','min','total')->tenant('north')->authorization(['bearer'=>'must-not-leak'])->limit(2);
	$first=dp_panel_http_success($fixture['definition'],$query,$fixture['scope'],'rmt_00000001',[
		['id'=>'o1','name'=>'One'],['id'=>'o2','name'=>'Two'],
	],['name','id'],['orders'=>2,'gross'=>30,'minimum'=>10],['items'=>[['id'=>'i1','sku'=>'A']]],'upstream-next');
	$transport=new PanelScriptedHttpDataSourceTransport([PanelHttpDataSourceTransportResponse::json(200,$first,12.5)]);
	$source=new PanelHttpDataSource($transport,$fixture['definition'],$fixture['mapper'],$fixture['runtime']);
	$result=$source->query($query);
	$t->same(2,$result->count()); $t->same('o1',$result->items()[0]['id']); $t->same(2,$result->aggregates()['orders']); $t->same(30,$result->aggregates()['gross']); $t->same(10,$result->aggregates()['minimum']); $t->same('i1',$result->included()['items'][0]['id']);
	$t->same(2,$result->page()->total()); $t->isTrue($result->page()->hasMore()); $t->same('http_remote',$result->metadata()['adapter']);
	$t->same(['name','id'],$result->metadata()['projection']); $t->isFalse($result->metadata()['scope_serialized']); $t->same(1,$result->metadata()['attempts']);
	$wire=json_decode($transport->requests()[0]->body(),true,flags:JSON_THROW_ON_ERROR);
	$t->same('POST',$transport->requests()[0]->method()); $t->same('application/json',$transport->requests()[0]->accept()); $t->same('north',$wire['scope']['tenant']); $t->same(['orders.read'],$wire['scope']['authorization']['permissions']);
	$t->same(null,$wire['cursor']); $t->same(null,$wire['record_key']); $t->same(1,$wire['execution']['attempt']);
	$encoded=PanelHttpDataSourceValue::encode($wire); $t->notContains('must-not-leak',$encoded); $t->notContains('bearer',$encoded); $t->notContains('private.example',$encoded);
	$t->same('upstream-next',$fixture['definition']->cursorCodec()->decode($result->page()->nextCursor(),$wire['query_fingerprint'],$fixture['definition']->fingerprint(),$fixture['runtime']->now));

	$continued=$query->cursor($result->page()->nextCursor());
	$second=dp_panel_http_success($fixture['definition'],$continued,$fixture['scope'],'rmt_00000002', [['id'=>'o3','name'=>'Three']],['name','id'],['orders'=>3,'gross'=>60,'minimum'=>10],['items'=>[]],null,'upstream-previous',offset:2);
	$second['data']['page']['total']=3;
	$transport->push(PanelHttpDataSourceTransportResponse::json(200,$second));
	$next=$source->query($continued); $t->same('o3',$next->items()[0]['id']); $t->same(2,$next->page()->offset()); $t->same('upstream-next',json_decode($transport->requests()[1]->body(),true,flags:JSON_THROW_ON_ERROR)['cursor']);
	$t->same('upstream-previous',$fixture['definition']->cursorCodec()->decode($next->page()->previousCursor(),$wire['query_fingerprint'],$fixture['definition']->fingerprint(),$fixture['runtime']->now));
	try{ $source->query($continued->where('status','open')); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_cursor_invalid',$e->publicCode()); }
})->tag('panel','data-source','http','protocol','cursor')->maxMillis(3000);

test('HTTP remote retries reads with one idempotency key and reports safe health', static function(Context $t): void {
	$fixture=dp_panel_http_fixture([],['max_attempts'=>2,'retry_backoff_ms'=>10]); $query=PanelDataQuery::make()->tenant('north')->limit(1);
	$error=dp_panel_http_error($fixture['definition'],$query,$fixture['scope'],'rmt_00000001');
	$success=dp_panel_http_success($fixture['definition'],$query,$fixture['scope'],'rmt_00000001',[['id'=>'o1']],['id']);
	$transport=new PanelScriptedHttpDataSourceTransport([PanelHttpDataSourceTransportResponse::json(503,$error,2.0),PanelHttpDataSourceTransportResponse::json(200,$success,3.0)]);
	$source=new PanelHttpDataSource($transport,$fixture['definition'],$fixture['mapper'],$fixture['runtime']);
	$t->same('o1',$source->query($query)->items()[0]['id']); $t->same([10],$fixture['runtime']->waits); $t->same(2,count($transport->requests()));
	$one=json_decode($transport->requests()[0]->body(),true,flags:JSON_THROW_ON_ERROR); $two=json_decode($transport->requests()[1]->body(),true,flags:JSON_THROW_ON_ERROR);
	$t->same($one['read_idempotency_key'],$two['read_idempotency_key']); $t->same(1,$one['execution']['attempt']); $t->same(2,$two['execution']['attempt']);
	$health=$source->health(); $t->same('closed',$health['status']); $t->same(1,$health['requests']); $t->same(2,$health['attempts']); $t->same(1,$health['retries']); $t->same(3.0,$health['last_latency_ms']);
	$manifest=json_encode($source,JSON_THROW_ON_ERROR); $t->notContains('private.example',$manifest); $t->notContains('PanelScripted',$manifest); $t->contains('credential_policy_owner',$manifest);
})->tag('panel','data-source','http','retry','health')->maxMillis(3000);

test('HTTP remote fails closed on mapper runtime cancellation deadline and circuit faults', static function(Context $t): void {
	$fixture=dp_panel_http_fixture([],['circuit_failure_threshold'=>2,'circuit_open_ms'=>100,'max_attempts'=>1]); $query=PanelDataQuery::make()->tenant('north')->limit(1);
	$source=new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$fixture['definition'],$fixture['mapper'],$fixture['runtime']);
	$fixture['mapper']->throw=true;
	try{ $source->query($query); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_scope_denied',$e->publicCode()); $t->notContains('secret',$e->getMessage()); }
	$fixture['mapper']->throw=false; $fixture['runtime']->cancelled=true;
	try{ $source->query($query); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_request_cancelled',$e->publicCode()); }
	$fixture['runtime']->cancelled=false;
	for($i=0;$i<2;$i++){ try{ $source->query($query); }catch(PanelHttpDataSourceException $e){ $t->same('remote_transport_unavailable',$e->publicCode()); } }
	$t->same('open',$source->health()['status']);
	try{ $source->query($query); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_circuit_open',$e->publicCode()); }
	$fixture['runtime']->now+=101; $t->same('half_open',$source->health()['status']);
	$successQuery=$query; $success=dp_panel_http_success($fixture['definition'],$successQuery,$fixture['scope'],'rmt_00000004',[['id'=>'ok']],['id']);
	$source->transport()->push(PanelHttpDataSourceTransportResponse::json(200,$success));
	$t->same('ok',$source->query($query)->items()[0]['id']); $t->same('closed',$source->health()['status']);

	$deadlineFixture=dp_panel_http_fixture([],['timeout_ms'=>50]); $deadlineQuery=PanelDataQuery::make()->tenant('north')->limit(1);
	$deadlineTransport=new class($deadlineFixture['runtime']) implements PanelHttpDataSourceTransport {
		public function __construct(private DpPanelHttpRuntime $runtime){}
		public function send(PanelHttpDataSourceTransportRequest $request): PanelHttpDataSourceTransportResponse { $this->runtime->now+=51; return PanelHttpDataSourceTransportResponse::json(200,[]); }
	};
	$deadlineSource=new PanelHttpDataSource($deadlineTransport,$deadlineFixture['definition'],$deadlineFixture['mapper'],$deadlineFixture['runtime']);
	try{ $deadlineSource->query($deadlineQuery); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_deadline_exceeded',$e->publicCode()); }
	$runtimeFixture=dp_panel_http_fixture(); $runtimeFixture['runtime']->throwId=true;
	try{ (new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$runtimeFixture['definition'],$runtimeFixture['mapper'],$runtimeFixture['runtime']))->query(PanelDataQuery::make()->tenant('north')); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_runtime_unavailable',$e->publicCode()); }
})->tag('panel','data-source','http','circuit','deadline','cancellation')->maxMillis(3000);

test('HTTP remote exact response grammar rejects arbitrary and mismatched upstream shapes', static function(Context $t): void {
	$cases=[]; $query=PanelDataQuery::make()->select(['name'])->tenant('north')->limit(2);
	foreach(range(1,7) as $index){ $cases[$index]=dp_panel_http_fixture(); }
	$base=dp_panel_http_success($cases[1]['definition'],$query,$cases[1]['scope'],'rmt_00000001',[['id'=>'o1','name'=>'One']],['id','name']);
	$unknown=$base; $unknown['unexpected']=true;
	$capability=$base; $capability['capability']['fingerprint']=str_repeat('0',64);
	$projection=$base; $projection['data']['projection']['fields']=['name'];
	$record=$base; $record['data']['items'][0]['extra']='x';
	$duplicate=$base; $duplicate['data']['items'][]=$duplicate['data']['items'][0]; $duplicate['data']['page']['returned']=2; $duplicate['data']['page']['total']=2;
	$content=new PanelHttpDataSourceTransportResponse(200,'text/plain',PanelHttpDataSourceValue::encode($base));
	$responses=[PanelHttpDataSourceTransportResponse::json(200,$unknown),PanelHttpDataSourceTransportResponse::json(200,$capability),PanelHttpDataSourceTransportResponse::json(200,$projection),PanelHttpDataSourceTransportResponse::json(200,$record),PanelHttpDataSourceTransportResponse::json(200,$duplicate),$content,new PanelHttpDataSourceTransportResponse(200,'application/json','{')];
	foreach($responses as $index=>$response){
		$fixture=$cases[$index+1];
		/* Rebind the template to this definition while retaining the deliberate fault. */
		if($index!==5 && $index!==6){
			$body=json_decode($response->body(),true,flags:JSON_THROW_ON_ERROR); $body['definition_fingerprint']=$fixture['definition']->fingerprint(); $body['capability']=['version'=>$fixture['pin']->version(),'fingerprint'=>$index===1?str_repeat('0',64):$fixture['pin']->fingerprint()]; $body['query_fingerprint']=dp_panel_http_query_fingerprint($fixture['definition'],$query,$fixture['scope']); $response=PanelHttpDataSourceTransportResponse::json(200,$body);
		}
		$source=new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport([$response]),$fixture['definition'],$fixture['mapper'],$fixture['runtime']);
		try{ $source->query($query); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same($index===1?'remote_capability_mismatch':'remote_protocol_invalid',$e->publicCode()); }
	}
	$errorFixture=dp_panel_http_fixture(); $errorQuery=PanelDataQuery::make()->tenant('north'); $error=dp_panel_http_error($errorFixture['definition'],$errorQuery,$errorFixture['scope'],'rmt_00000001',403); $error['error']['details']='secret';
	try{ (new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport([PanelHttpDataSourceTransportResponse::json(403,$error)]),$errorFixture['definition'],$errorFixture['mapper'],$errorFixture['runtime']))->query($errorQuery); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_protocol_invalid',$e->publicCode()); $t->notContains('secret',$e->getMessage()); }
})->tag('panel','data-source','http','adversarial','response')->maxMillis(4000);

test('HTTP remote find and generic adapter conformance remain deterministic', static function(Context $t): void {
	$fixture=dp_panel_http_fixture(); $query=PanelDataQuery::make()->tenant('north')->search('First')->limit(5); $find=PanelDataQuery::make()->limit(1);
	$transport=new PanelScriptedHttpDataSourceTransport([
		PanelHttpDataSourceTransportResponse::json(200,dp_panel_http_success($fixture['definition'],$query,$fixture['scope'],'rmt_00000001',[['id'=>'one','name'=>'First']],['id','name'])),
		PanelHttpDataSourceTransportResponse::json(200,dp_panel_http_success($fixture['definition'],$find,$fixture['scope'],'rmt_00000002',[['id'=>'one','name'=>'First']],['id','name'],operation:'find',recordKey:'one')),
		PanelHttpDataSourceTransportResponse::json(200,dp_panel_http_success($fixture['definition'],$find,$fixture['scope'],'rmt_00000003',[],['id','name'],operation:'find',recordKey:'absent')),
	]);
	$source=new PanelHttpDataSource($transport,$fixture['definition'],$fixture['mapper'],$fixture['runtime']);
	$report=(new PanelAdapterConformanceRunner())->run(PanelAdapterConformanceCatalog::dataSource(),$source,['query'=>$query,'known_id'=>'one','missing_id'=>'absent']);
	$t->isTrue($report->passed()); $t->same(3,$report->summary()['passed']); $t->same(3,count($transport->requests()));
})->tag('panel','data-source','http','conformance','find')->maxMillis(5000);

test('HTTP remote cursor transport DTOs runtime and option faults stay bounded', static function(Context $t): void {
	$codec=new PanelHttpDataSourceCursorCodec(['old'=>str_repeat('o',32),'new'=>str_repeat('n',32)],'new'); $q=str_repeat('a',64); $d=str_repeat('b',64);
	$cursor=$codec->encode('opaque-upstream',$q,$d,1000,30); $t->same('opaque-upstream',$codec->decode($cursor,$q,$d,1001)); $t->same(2,$codec->jsonSerialize()['retained_keys']);
	$t->throws(static fn()=>$codec->decode($cursor,str_repeat('c',64),$d,1001),InvalidArgumentException::class);
	$t->throws(static fn()=>$codec->decode($cursor,$q,$d,31000),InvalidArgumentException::class);
	$t->throws(static fn()=>$codec->decode(substr($cursor,0,-1).'x',$q,$d,1001),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelHttpDataSourceCursorCodec([]),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelHttpDataSourceCursorCodec(['x'=>'short']),InvalidArgumentException::class);

	$runtime=new DpPanelHttpRuntime(); $request=new PanelHttpDataSourceTransportRequest('https://private.test','{}',1001000,1000,1,$runtime);
	$t->same('POST',$request->method()); $t->same('{}',$request->body()); $t->same(1001000,$request->deadlineUnixMilliseconds()); $t->same(1000,$request->timeoutMilliseconds()); $t->same(1,$request->attempt()); $t->isFalse($request->cancellationRequested()); $t->same(null,$request->cancellationReason()); $t->isFalse($request->jsonSerialize()['endpoint_serialized']);
	$response=PanelHttpDataSourceTransportResponse::json(429,['error'=>true],1.25,25); $t->same(429,$response->status()); $t->same(25,$response->retryAfterMilliseconds()); $t->same(1.25,$response->elapsedMilliseconds()); $t->isFalse($response->jsonSerialize()['body_serialized']);
	$script=new PanelScriptedHttpDataSourceTransport([$response]); $t->same($response,$script->send($request)); $t->same(1,count($script->requests())); $t->same(0,$script->pending()); $t->same(1,$script->jsonSerialize()['calls']); $t->throws(static fn()=>$script->send($request),PanelHttpDataSourceException::class);

	$system=new PanelSystemHttpDataSourceRuntime(); $t->contains('phr_',$system->requestId()); $t->isFalse($system->cancellationRequested()); $t->same(null,$system->cancellationReason()); $t->isTrue($system->waitMilliseconds(0,$system->nowMilliseconds()+100)); $t->throws(static fn()=>$system->waitMilliseconds(-1,0),InvalidArgumentException::class);
	$pin=PanelHttpDataSourceCapabilityPin::readOnly();
	$invalid=[
		static fn()=>new PanelHttpDataSourceDefinition('x','ftp://host/path',$pin,['cursor_keys'=>['a'=>str_repeat('a',32)]]),
		static fn()=>new PanelHttpDataSourceDefinition('x','https://user:pass@host/path',$pin,['cursor_keys'=>['a'=>str_repeat('a',32)]]),
		static fn()=>new PanelHttpDataSourceDefinition('x','https://host/path?query=1',$pin,['cursor_keys'=>['a'=>str_repeat('a',32)]]),
		static fn()=>new PanelHttpDataSourceDefinition('x','https://host/path',$pin,[]),
		static fn()=>new PanelHttpDataSourceDefinition('x','https://host/path',$pin,['cursor_keys'=>['a'=>str_repeat('a',32)],'max_attempts'=>4]),
		static fn()=>new PanelHttpDataSourceDefinition('x','https://host/path',$pin,['cursor_keys'=>['a'=>str_repeat('a',32)],'retry_statuses'=>[418]]),
		static fn()=>new PanelHttpDataSourceDefinition('x','https://host/path',$pin,['cursor_keys'=>['a'=>str_repeat('a',32)],'unknown'=>1]),
	];
	foreach($invalid as $factory){ $t->throws($factory,InvalidArgumentException::class); }
	$error=PanelHttpDataSourceException::upstream(429); $t->same('remote_rate_limited',$error->publicCode()); $t->same(429,$error->httpStatus()); $t->isTrue($error->retryable()); $t->isTrue($error->countsTowardCircuit()); $t->same('panel_http_data_source_error',$error->jsonSerialize()['type']);
})->tag('panel','data-source','http','dto','bounds')->maxMillis(4000);

test('HTTP remote exact residual branches keep runtime protocol and capability guards observable', static function(Context $t): void {
	$fixture=dp_panel_http_fixture(); $query=PanelDataQuery::make()->tenant('north')->limit(1); $qf=dp_panel_http_query_fingerprint($fixture['definition'],$query,$fixture['scope']);
	$protocol=new PanelHttpDataSourceProtocolRequest('query','rmt_00000001',$fixture['definition']->name(),$fixture['definition']->fingerprint(),$fixture['pin'],$qf,str_repeat('a',64),PanelHttpDataSourceProtocolRequest::sanitizedQuery($query),null,null,$fixture['scope'],1005000,5000,1,1);
	$t->same('remote_orders',$protocol->source()); $t->same('query',$protocol->operation()); $t->same('rmt_00000001',$protocol->requestId()); $t->same($fixture['definition']->fingerprint(),$protocol->definitionFingerprint()); $t->same($fixture['pin'],$protocol->capabilityPin()); $t->same($qf,$protocol->queryFingerprint()); $t->same(null,$protocol->recordKey()); $t->same(1,$protocol->queryPayload()['limit']);
	$success=dp_panel_http_success($fixture['definition'],$query,$fixture['scope'],'rmt_00000001',[['id'=>'one']],['id']);
	$decoded=PanelHttpDataSourceProtocolResponse::decode(PanelHttpDataSourceTransportResponse::json(200,$success),$protocol,'id',1048576);
	$t->same([['id'=>'one']],$decoded->items()); $t->same(0,$decoded->offset()); $t->same(1,$decoded->limit()); $t->same(1,$decoded->total()); $t->same(null,$decoded->nextCursor()); $t->same(null,$decoded->previousCursor()); $t->same(['id'],$decoded->projection()); $t->same([],$decoded->aggregates()); $t->same([],$decoded->included()); $t->same('panel_http_data_decoded_response',$decoded->jsonSerialize()['type']);
	$t->same($fixture['definition'],(new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$fixture['definition'],$fixture['mapper'],$fixture['runtime']))->definition());
	$t->same($fixture['pin']->fingerprint(),PanelHttpDataSourceCapabilityPin::fromArray($fixture['pin']->version(),$fixture['pin']->capabilities())->fingerprint());

	$validError=dp_panel_http_error($fixture['definition'],$query,$fixture['scope'],'rmt_00000001',403);
	try{ (new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport([PanelHttpDataSourceTransportResponse::json(403,$validError)]),$fixture['definition'],$fixture['mapper'],new DpPanelHttpRuntime()))->query($query); $t->isTrue(false); }
	catch(PanelHttpDataSourceException $e){ $t->same('remote_access_denied',$e->publicCode()); }

	$largeFixture=dp_panel_http_fixture([],['max_request_bytes'=>4096]);
	try{ (new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$largeFixture['definition'],$largeFixture['mapper'],$largeFixture['runtime']))->query(PanelDataQuery::make()->tenant('north')->where('payload',str_repeat('x',5000))); $t->isTrue(false); }
	catch(PanelHttpDataSourceException $e){ $t->same('remote_request_too_large',$e->publicCode()); }

	$throwFixture=dp_panel_http_fixture();
	$throwingTransport=new class implements PanelHttpDataSourceTransport { public function send(PanelHttpDataSourceTransportRequest $request): PanelHttpDataSourceTransportResponse { throw new RuntimeException('transport secret'); } };
	try{ (new PanelHttpDataSource($throwingTransport,$throwFixture['definition'],$throwFixture['mapper'],$throwFixture['runtime']))->query(PanelDataQuery::make()->tenant('north')); $t->isTrue(false); }
	catch(PanelHttpDataSourceException $e){ $t->same('remote_transport_unavailable',$e->publicCode()); }

	$waitFixture=dp_panel_http_fixture([],['max_attempts'=>2]); $waitQuery=PanelDataQuery::make()->tenant('north'); $waitFixture['runtime']->throwWait=true;
	$waitError=dp_panel_http_error($waitFixture['definition'],$waitQuery,$waitFixture['scope'],'rmt_00000001');
	try{ (new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport([PanelHttpDataSourceTransportResponse::json(503,$waitError)]),$waitFixture['definition'],$waitFixture['mapper'],$waitFixture['runtime']))->query($waitQuery); $t->isTrue(false); }
	catch(PanelHttpDataSourceException $e){ $t->same('remote_runtime_unavailable',$e->publicCode()); }

	$clockFixture=dp_panel_http_fixture(); $clockFixture['runtime']->throwNow=true; $clockSource=new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$clockFixture['definition'],$clockFixture['mapper'],$clockFixture['runtime']);
	$t->same('unavailable',$clockSource->health()['status']); $t->same('remote_runtime_unavailable',$clockSource->health()['last_error_code']);
	try{ $clockSource->query(PanelDataQuery::make()->tenant('north')); $t->isTrue(false); }catch(PanelHttpDataSourceException $e){ $t->same('remote_runtime_unavailable',$e->publicCode()); }
	$cancelFixture=dp_panel_http_fixture(); $cancelFixture['runtime']->throwCancel=true;
	try{ (new PanelHttpDataSource(new PanelScriptedHttpDataSourceTransport(),$cancelFixture['definition'],$cancelFixture['mapper'],$cancelFixture['runtime']))->query(PanelDataQuery::make()->tenant('north')); $t->isTrue(false); }
	catch(PanelHttpDataSourceException $e){ $t->same('remote_runtime_unavailable',$e->publicCode()); }

	$legacy=PanelQueryCapabilities::legacy('legacy'); $t->same(1,$legacy['expression_version']); $t->same(['native'],$legacy['sort_nulls']);
	$group=PanelQueryGroup::any(PanelQueryComparison::make('status','eq','open'),PanelQueryComparison::make('status','eq','closed'));
	PanelQueryCapabilities::fromArray(PanelQueryCapabilities::full('groups'))->assertSupports(PanelDataQuery::make()->replaceExpression($group)); $t->isTrue(true);
	$relation=PanelQueryRelation::make('items',PanelQueryComparison::make('sku','eq','A'),'any',PanelQueryComparison::make('tenant_id','eq','north'));
	try{ PanelQueryCapabilities::fromArray(array_replace(PanelQueryCapabilities::full('no_relations'),['relations'=>false,'relation_depth'=>0]))->assertSupports(PanelDataQuery::make()->replaceExpression($relation)); $t->isTrue(false); }
	catch(PanelUnsupportedQueryException $e){ $t->isTrue(in_array('relations',$e->unsupported(),true)); $t->isTrue(in_array('relation_depth',$e->unsupported(),true)); }

	$key=str_repeat('n',32); $badJson='{'; $b64=static fn(string $value):string=>rtrim(strtr(base64_encode($value),'+/','-_'),'='); $forged=$b64($badJson).'.'.$b64(hash_hmac('sha256',$badJson,$key,true));
	try{ (new PanelHttpDataSourceCursorCodec(['new'=>$key],'new'))->decode($forged,str_repeat('a',64),str_repeat('b',64),1); $t->isTrue(false); }catch(InvalidArgumentException){ $t->isTrue(true); }
	$t->same('https://private.test',(new PanelHttpDataSourceTransportRequest('https://private.test','{}',1001000,1000,1,new DpPanelHttpRuntime()))->endpoint());
	$system=new PanelSystemHttpDataSourceRuntime(); $t->isFalse($system->waitMilliseconds(100,$system->nowMilliseconds())); $t->isTrue($system->waitMilliseconds(1,$system->nowMilliseconds()+100));
})->tag('panel','data-source','http','exact-coverage','residual')->maxMillis(5000);
