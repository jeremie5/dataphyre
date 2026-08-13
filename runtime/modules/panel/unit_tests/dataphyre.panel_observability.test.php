<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCompositeTelemetryExporter;
use Dataphyre\Panel\PanelDeterministicTelemetrySampler;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelInMemoryTelemetryExporter;
use Dataphyre\Panel\PanelNavigationIntent;
use Dataphyre\Panel\PanelNavigationIntentVerification;
use Dataphyre\Panel\PanelOperationExecution;
use Dataphyre\Panel\PanelOperationRecord;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelTelemetryBridge;
use Dataphyre\Panel\PanelTelemetryContext;
use Dataphyre\Panel\PanelTelemetryExporter;
use Dataphyre\Panel\PanelTelemetryHub;
use Dataphyre\Panel\PanelTelemetryPropagator;
use Dataphyre\Panel\PanelTelemetryRuntime;
use Dataphyre\Panel\PanelTelemetrySignal;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{0:PanelTelemetryHub,1:PanelInMemoryTelemetryExporter,2:Closure} */
function dp_panel_telemetry_hub(float $ratio=1.0,?PanelTelemetryExporter $exporter=null):array{
	$time=1000.125;$sequence=0;$clock=static fn():float=>$time;
	$advance=static function(float $seconds)use(&$time):void{$time+=$seconds;};
	$ids=static function(int $bytes)use(&$sequence):string{$sequence++;return str_pad(dechex($sequence),$bytes*2,'a',STR_PAD_LEFT);};
	$memory=$exporter instanceof PanelInMemoryTelemetryExporter?$exporter:new PanelInMemoryTelemetryExporter(100);
	return[new PanelTelemetryHub($exporter??$memory,new PanelDeterministicTelemetrySampler($ratio,'tests'),new PanelTelemetryPropagator(),$clock,$ids),$memory,$advance];
}

test('telemetry contexts keep correlation strict and manifests secret free',static function(Context $t):void{
	$root=PanelTelemetryContext::root(true,['region'=>'north','token'=>'never'],'vendor=value',str_repeat('a',32),str_repeat('b',16));
	$t->same(str_repeat('a',32),$root->traceId());$t->same(str_repeat('b',16),$root->spanId());$t->same(1,$root->traceFlags());$t->isTrue($root->sampled());$t->isFalse($root->remoteParent());$t->same('vendor=value',$root->traceState());$t->same('north',$root->baggage()['region']);$t->same('00-'.str_repeat('a',32).'-'.str_repeat('b',16).'-01',$root->traceParent());
	$child=$root->child(str_repeat('c',16));$t->same($root->traceId(),$child->traceId());$t->same(str_repeat('c',16),$child->spanId());
	$remote=PanelTelemetryContext::remote(str_repeat('d',32),str_repeat('e',16),255);$t->isTrue($remote->remoteParent());$t->isTrue($remote->sampled());
	$json=json_encode($root,JSON_THROW_ON_ERROR);$t->contains('baggage_keys',$json);$t->notContains('north',$json);$t->notContains('never',$json);
	$t->throws(static fn()=>PanelTelemetryContext::remote(str_repeat('0',32),str_repeat('a',16)),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryContext::remote(str_repeat('A',32),str_repeat('a',16)),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryContext::remote(str_repeat('a',32),str_repeat('0',16)),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryContext::remote(str_repeat('a',32),str_repeat('b',16),256),InvalidArgumentException::class);
})->tag('panel','observability','context','security')->maxMillis(1000);

test('W3C propagation is strict bounded and baggage safe',static function(Context $t):void{
	$propagator=new PanelTelemetryPropagator();$parent='00-'.str_repeat('1',32).'-'.str_repeat('2',16).'-01';
	$context=$propagator->extract(['TraceParent'=>$parent,'TraceState'=>'vendor=value,tenant@system=opaque','Baggage'=>'region=north%20america,token=secret,bad%=x,dup=one,dup=two,control=%0A,opaque=a=b,raw_space=not valid']);
	$t->isTrue($context instanceof PanelTelemetryContext);$t->isTrue($context?->sampled());$t->same('vendor=value,tenant@system=opaque',$context?->traceState());$t->same(['region'=>'north america','dup'=>'one','opaque'=>'a=b'],$context?->baggage());
	$headers=$propagator->inject($context,['X-Test'=>'ok']);$t->same($parent,$headers['traceparent']);$t->contains('region=north%20america',$headers['baggage']);$t->same('ok',$headers['x-test']);
	$empty=PanelTelemetryContext::root(false,[], '',str_repeat('3',32),str_repeat('4',16));$injected=$propagator->inject($empty,['tracestate'=>'old','baggage'=>'old']);$t->isFalse(isset($injected['tracestate']));$t->isFalse(isset($injected['baggage']));
	foreach(['','00-'.str_repeat('0',32).'-'.str_repeat('2',16).'-01','00-'.str_repeat('1',32).'-'.str_repeat('0',16).'-01','ff-'.str_repeat('1',32).'-'.str_repeat('2',16).'-01','00-'.str_repeat('A',32).'-'.str_repeat('2',16).'-01']as$invalid){$t->same(null,$propagator->extract(['traceparent'=>$invalid]));}
	$future=$propagator->extract(['traceparent'=>'01-'.str_repeat('5',32).'-'.str_repeat('6',16).'-03-extra']);$t->isTrue($future instanceof PanelTelemetryContext);$t->same(1,$future?->traceFlags());$t->same('00-'.str_repeat('5',32).'-'.str_repeat('6',16).'-01',$future?->traceParent());
	$t->same('', $propagator->normalizeTraceState(str_repeat('x',513)));$t->same('', $propagator->normalizeTraceState('bad member'));
	$t->same('', $propagator->normalizeTraceState('a=one,a=two'));$t->same('', $propagator->normalizeTraceState('a=one,b='));$t->same('', $propagator->normalizeTraceState('a=o=ne'));
	$many=[];for($i=0;$i<33;$i++){$many[]='k'.$i.'=v';}$t->same('', $propagator->normalizeTraceState(implode(',',$many)));
	$t->same([], $propagator->normalizeBaggage(str_repeat('a',8193)));$t->same([], $propagator->normalizeBaggage(new stdClass()));
	$baggage=[];for($i=0;$i<40;$i++){$baggage[]='k'.$i.'=v';}$t->same(32,count($propagator->normalizeBaggage(implode(',',$baggage))));
	$manifest=$propagator->jsonSerialize();$t->same('w3c',$manifest['formats']['traceparent']);$t->same(32,$manifest['limits']['baggage_members']);
})->tag('panel','observability','w3c','baggage')->maxMillis(1000);

test('deterministic sampling is stable and bounded',static function(Context $t):void{
	$never=new PanelDeterministicTelemetrySampler(-1,'');$always=new PanelDeterministicTelemetrySampler(2,'seed');$half=new PanelDeterministicTelemetrySampler(.5,'seed');
	$t->isFalse($never->sample(str_repeat('a',32),'route'));$t->isTrue($always->sample(str_repeat('a',32),'route'));
	$t->same($half->sample(str_repeat('b',32),'route'),$half->sample(str_repeat('b',32),'other',['ignored'=>'by contract']));
	$t->same(.5,$half->ratio());$t->same('sha256-threshold-v1',$half->jsonSerialize()['algorithm']);$t->notContains('"seed":"seed"',json_encode($half,JSON_THROW_ON_ERROR));
})->tag('panel','observability','sampling')->maxMillis(1000);

test('telemetry signals normalize lifecycle and never serialize raw failures',static function(Context $t):void{
	$context=PanelTelemetryContext::root(true,[], '',str_repeat('a',32),str_repeat('b',16));$resource=fopen('php://memory','rb');$deep='x';for($index=0;$index<10;$index++){$deep=['level'=>$deep];}
	$event=PanelTelemetrySignal::event('', $context,10.25,['password'=>'secret','error'=>new RuntimeException('secret customer message'),'object'=>new stdClass(),'resource'=>$resource,'deep'=>$deep],'unsupported');
	$t->same('telemetry',$event->name());$t->same('info',$event->body()['severity']);$t->same('event',$event->signal());$t->same('[REDACTED]',$event->attributes()['password']);$t->same(RuntimeException::class,$event->attributes()['error']['exception_type']);
	$span=PanelTelemetrySignal::span('HTTP Request',$context,11,10,'strange',['authorization'=>'Bearer abc'],new RuntimeException('password=hunter2'),'invalid','weird');
	$t->same('http_request',$span->name());$t->same('error',$span->body()['status']);$t->same('internal',$span->body()['kind']);$t->same(null,$span->body()['parent_span_id']);$t->same(0.0,$span->body()['duration_ms']);
	$encoded=json_encode($span,JSON_THROW_ON_ERROR);$t->notContains('hunter2',$encoded);$t->notContains('password=',$encoded);$t->contains('message_fingerprint',$encoded);$t->same($context,$span->context());
	$trace=PanelTelemetrySignal::trace('root',$context,1,2,'ok',[],'failure code',str_repeat('c',16),'server');$t->same('trace',$trace->signal());$t->same(str_repeat('c',16),$trace->body()['parent_span_id']);
	$measurement=PanelTelemetrySignal::measurement('latency',$context,2.5,12.5,'ms');$t->same(12.5,$measurement->body()['value']);$t->same('ms',$measurement->body()['unit']);
	$t->throws(static fn()=>PanelTelemetrySignal::measurement('bad',$context,1,NAN),InvalidArgumentException::class);$t->throws(static fn()=>PanelTelemetrySignal::measurement('bad',$context,1,1,'bad unit'),InvalidArgumentException::class);$t->throws(static fn()=>PanelTelemetrySignal::event('bad',$context,NAN),InvalidArgumentException::class);
	if(is_resource($resource)){fclose($resource);}
})->tag('panel','observability','signals','redaction')->maxMillis(1000);

test('in-memory exporter is bounded queryable and manifest only',static function(Context $t):void{
	$exporter=new PanelInMemoryTelemetryExporter(2);$context=PanelTelemetryContext::root(true,[], '',str_repeat('a',32),str_repeat('b',16));
	$exporter->export(PanelTelemetrySignal::event('one',$context,1));$exporter->export(PanelTelemetrySignal::event('two',$context,2));$exporter->export(PanelTelemetrySignal::measurement('three',$context,3,3));$exporter->flush();
	$t->same(2,count($exporter->signals()));$t->same(1,count($exporter->signals('event')));$t->same(1,count($exporter->records('measurement','three')));$t->same([],$exporter->records('span'));
	$manifest=$exporter->manifest();$t->same(3,$manifest['exported']);$t->same(1,$manifest['evicted']);$t->same(1,$manifest['flushes']);$t->same($manifest,$exporter->jsonSerialize());
	$exporter->clear();$t->same([],$exporter->signals());
})->tag('panel','observability','exporter')->maxMillis(1000);

test('composite exporter isolates sinks and reports only generic failures',static function(Context $t):void{
	$memory=new PanelInMemoryTelemetryExporter();$failing=new class implements PanelTelemetryExporter{public function export(PanelTelemetrySignal $signal):void{throw new RuntimeException('secret exporter failure');}public function flush():void{throw new RuntimeException('secret flush failure');}public function manifest():array{throw new RuntimeException('secret manifest failure');}};
	$t->throws(static fn()=>new PanelCompositeTelemetryExporter([]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelCompositeTelemetryExporter([new stdClass()]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelCompositeTelemetryExporter(array_fill(0,17,$memory)),InvalidArgumentException::class);
	$composite=new PanelCompositeTelemetryExporter([$failing,$memory]);$signal=PanelTelemetrySignal::event('fanout',PanelTelemetryContext::root(true),1);
	$t->throws(static fn()=>$composite->export($signal),RuntimeException::class);$t->same(1,count($memory->signals()));$t->throws(static fn()=>$composite->flush(),RuntimeException::class);
	$manifest=$composite->manifest();$t->same(2,$manifest['failures']);$t->same('failed',$manifest['exporters'][0]['manifest']);$t->same($manifest,$composite->jsonSerialize());$t->notContains('secret',json_encode($manifest,JSON_THROW_ON_ERROR));
})->tag('panel','observability','exporter','failure-isolation')->maxMillis(1000);

test('telemetry runtime validates injectable exporters and deterministic configuration',static function(Context $t):void{
	$memory=new PanelInMemoryTelemetryExporter(3);$runtime=PanelTelemetryRuntime::fromConfig(['exporter'=>$memory,'sample_ratio'=>'0.5','sampling_seed'=>'runtime']);$t->same($memory,$runtime->exporter());$t->same($memory,$runtime->hub()->exporter());$t->same($runtime->hub(),$runtime->bridge()->hub());$t->same('panel_telemetry_runtime',$runtime->jsonSerialize()['type']);
	$composite=PanelTelemetryRuntime::fromConfig(['exporters'=>[$memory,new PanelInMemoryTelemetryExporter()]]);$t->isTrue($composite->exporter() instanceof PanelCompositeTelemetryExporter);
	$single=PanelTelemetryRuntime::fromConfig(['exporters'=>[$memory],'sampler'=>new PanelDeterministicTelemetrySampler(),'propagator'=>new PanelTelemetryPropagator(),'clock'=>static fn():float=>1.0,'id_factory'=>static fn(int $bytes):string=>str_repeat('a',$bytes*2)]);$single->hub()->event('configured');$t->same(1,count($memory->signals('event','configured')));
	$default=PanelTelemetryRuntime::fromConfig(['memory_capacity'=>1]);$t->isTrue($default->exporter() instanceof PanelInMemoryTelemetryExporter);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['exporter'=>$memory,'exporters'=>[$memory]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['exporters'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['exporters'=>'bad']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['exporter'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['sample_ratio'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['sample_ratio'=>INF]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['sampler'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['propagator'=>new stdClass()]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['clock'=>'missing_callable']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTelemetryRuntime::fromConfig(['id_factory'=>'missing_callable']),InvalidArgumentException::class);
})->tag('panel','observability','runtime','configuration')->maxMillis(1000);

test('PanelPlatform exposes observability as an explicit optional instance domain',static function(Context $t):void{
	$disabled=['operations'=>false,'distributed_operations'=>false,'migrations'=>false,'data'=>false,'workflows'=>false,'automation'=>false,'authentication'=>false,'notifications'=>false,'media'=>false,'localization'=>false,'preferences'=>false,'collaboration'=>false,'relations'=>false,'security'=>false,'development'=>false,'extensions'=>false,'platform'=>false];
	$platform=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-observability'),'observability'=>['memory_capacity'=>2,'sample_ratio'=>1,'sampling_seed'=>'platform']]+$disabled);$manifest=$platform->manifest();
	$t->isTrue($manifest->available('observability'));$t->isTrue($manifest->configured('observability'));$t->isTrue($manifest->ready('observability'));$payload=$manifest->jsonSerialize();$t->same(count($payload['domains']),$payload['counts']['domains']);$t->hasKey('observability',$payload['domains']);$t->same(1,$payload['counts']['configured']);$t->same(4,$payload['counts']['services']);
	$t->same($platform->observability()->exporter(),$platform->telemetryExporter());$t->same($platform->observability()->hub(),$platform->telemetry());$t->same($platform->observability()->bridge(),$platform->telemetryBridge());$platform->telemetry()->event('platform.live');$t->same(1,count(($platform->telemetryExporter())->signals('event','platform.live')));
	$t->isTrue(in_array('observability',$platform->jsonSerialize()['metadata']['enabled_domains'],true));
	$without=PanelPlatform::defaults(['state_root'=>$t->tempDirectory('panel-platform-without-observability')]+$disabled);$t->isFalse($without->has('observability.hub'));$t->isFalse($without->manifest()->configured('observability'));$t->throws(static fn()=>$without->telemetry(),LogicException::class);
})->tag('panel','observability','platform')->maxMillis(3000);

test('telemetry hub records full lifecycle while exporter failures stay fail safe',static function(Context $t):void{
	[$hub,$memory,$advance]=dp_panel_telemetry_hub();$result=$hub->trace('request',static function($span)use($advance,$hub):string{$t= $hub->current();if(!$t instanceof PanelTelemetryContext){throw new LogicException('missing current');}$span->event('inside',['token'=>'hidden']);$span->measurement('items',2);$advance(.25);return'ok';},['method'=>'GET'],null,'server');
	$t->same('ok',$result);$t->same(null,$hub->current());$t->same(3,count($memory->signals()));$t->same(1,count($memory->signals('trace','request')));
	$span=$hub->startSpan('child');$t->same('child',$span->name());$t->same('span',$span->signal());$t->isFalse($span->ended());$t->isTrue($span->end('ok',['safe'=>true]));$t->isFalse($span->end());$t->isFalse($span->fail('late'));$t->isTrue($span->ended());$t->same('panel_telemetry_span',$span->jsonSerialize()['type']);
	$t->throws(static fn()=>new Dataphyre\Panel\PanelTelemetrySpan($hub,'bad','bad',$hub->context(),NAN),InvalidArgumentException::class);
	try{$hub->trace('failure',static fn()=>throw new RuntimeException('token=top-secret'));$t->fail('Expected exception.');}catch(RuntimeException $error){$t->contains('top-secret',$error->getMessage());}
	$failure=json_encode($memory->signals('trace','failure')[0],JSON_THROW_ON_ERROR);$t->notContains('top-secret',$failure);$t->isTrue($hub->flush());$manifest=$hub->manifest();$t->same(0,$manifest['counters']['active_spans']);$t->same(1,$manifest['contracts']['measurement']);$t->same($manifest,$hub->jsonSerialize());
	[$droppedHub,$unused]=dp_panel_telemetry_hub(0);$droppedHub->event('drop');$t->same(1,$droppedHub->manifest()['counters']['dropped']);$t->same([],$unused->signals());
	$failing=new class implements PanelTelemetryExporter{public function export(PanelTelemetrySignal $signal):void{throw new RuntimeException('secret');}public function flush():void{throw new RuntimeException('secret');}public function manifest():array{throw new RuntimeException('secret');}};[$failedHub]=dp_panel_telemetry_hub(1,$failing);$failedHub->event('safe');$t->isFalse($failedHub->flush());$failedManifest=$failedHub->manifest();$t->same(1,$failedManifest['counters']['export_failures']);$t->same(1,$failedManifest['counters']['flush_failures']);$t->same('failed',$failedManifest['exporter']['manifest']);$t->notContains('secret',json_encode($failedManifest,JSON_THROW_ON_ERROR));
	$badRuntime=new PanelTelemetryHub(new PanelInMemoryTelemetryExporter(),null,null,static fn():string=>'bad',static fn()=>throw new RuntimeException('bad id'));$badRuntime->event('fallback');$t->same(1,$badRuntime->manifest()['counters']['emitted']['event']);
})->tag('panel','observability','hub','lifecycle')->maxMillis(2000);

test('telemetry bridge correlates requests routes navigation workers and Reactor',static function(Context $t):void{
	[$hub,$memory]=dp_panel_telemetry_hub();$bridge=new PanelTelemetryBridge($hub);$t->same($hub,$bridge->hub());$traceId=str_repeat('9',32);$request=Dataphyre\Panel\PanelRequest::fromArray(['method'=>'POST','resource'=>'orders','operation'=>'update','record'=>'customer@example.test','tenant'=>'tenant-a','headers'=>['traceparent'=>'00-'.$traceId.'-'.str_repeat('8',16).'-01','x-requested-with'=>'DataphyrePanelModal']]);
	$seen=$bridge->request($request,static fn($request,$context):string=>$context->traceId());$t->same($traceId,$seen);$t->same($traceId,$memory->signals('trace')[0]->context()->traceId());
	$t->same('route',$bridge->route('orders.update',$request,static fn():string=>'route'));
	$intent=PanelNavigationIntent::make('/panel/orders',['issued_at'=>100,'not_before'=>100,'expires_at'=>200,'nonce'=>str_repeat('N',24),'panel'=>'admin','surface'=>'order_modal']);$bridge->navigationIssued($intent);$bridge->navigationVerified(PanelNavigationIntentVerification::accepted($intent,'key'));$bridge->navigationVerified(PanelNavigationIntentVerification::rejected('invalid_signature'));
	$context=$hub->context('submit');$options=$bridge->operationOptions(['metadata'=>['safe'=>true]],$context);$record=PanelOperationRecord::make('export','Export',['id'=>'telemetry-operation','metadata'=>$options['metadata']]);$t->same($context->traceId(),$bridge->operationContext($record)?->traceId());$t->same(null,$bridge->operationContext(PanelOperationRecord::make('plain','Plain',['id'=>'plain-operation'])));
	$store=new PanelFilesystemOperationStore($t->tempDirectory('panel-telemetry-operation'));$store->create($record);$execution=new PanelOperationExecution($store,$record->id());$wrapped=$bridge->operationHandler(static fn($payload,$execution,$record,$workerContext):array=>['trace'=>$workerContext->traceId(),'payload'=>$payload]);$operationResult=$wrapped(['safe'=>true],$execution,$record);$t->same($context->traceId(),$operationResult['trace']);
	$reactor=$bridge->reactor('Order Editor','tx-secret',static fn($reactorContext):string=>$reactorContext->traceId(),['password'=>'hidden']);$t->isTrue(strlen($reactor)===32);
	$encoded=json_encode($bridge,JSON_THROW_ON_ERROR);$t->contains('signed_navigation',$encoded);$t->notContains('customer@example.test',$encoded);$t->notContains('tx-secret',$encoded);$t->notContains('hidden',$encoded);
})->tag('panel','observability','correlation','integration')->maxMillis(3000);
