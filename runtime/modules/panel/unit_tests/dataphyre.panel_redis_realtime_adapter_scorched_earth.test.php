<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelCallbackRedisRealtimeTransport;
use Dataphyre\Panel\PanelPhpRedisRealtimeTransport;
use Dataphyre\Panel\PanelPlatformManifest;
use Dataphyre\Panel\PanelPredisRealtimeTransport;
use Dataphyre\Panel\PanelRedisRealtimeAdapter;
use Dataphyre\Panel\PanelRedisRealtimeTransport;
use Dataphyre\Panel\PanelRealtimeCancellationToken;
use Dataphyre\Panel\PanelRealtimeContext;
use Dataphyre\Panel\PanelRealtimeEndpoint;
use Dataphyre\Panel\PanelRealtimeEvent;
use Dataphyre\Panel\PanelRealtimeException;
use Dataphyre\Panel\PanelRealtimeIntentSigner;
use Dataphyre\Panel\PanelRealtimeIntentVerification;
use Dataphyre\Panel\PanelRealtimeSubscription;
use Dataphyre\Panel\Testing\PanelRealtimeBrokerConformance;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['panel']);
require_once dirname(__DIR__).'/testing/PanelRealtimeBrokerConformance.php';

final class PanelRedisRealtimeTestState {
	public ?string $schema=null;
	/** @var array<string,true> */ public array $registered=[];
	/** @var array<string,list<array{0:string,1:list<string>}>> */ public array $events=[];
	/** @var array<string,int> */ public array $nonces=[];
	/** @var list<array{marker:string,keys:list<string>,arguments:list<string>}> */ public array $calls=[];
	public bool $throw=false;
	public mixed $reply=null;
}

/** Deterministic RESP-shape emulator shared by independent adapter instances. */
final class PanelRedisRealtimeTestTransport implements PanelRedisRealtimeTransport {
	public function __construct(public readonly PanelRedisRealtimeTestState $state){}

	public function evaluate(string $script,array $keys,array $arguments=[]):mixed{
		PanelCallbackRedisRealtimeTransport::input($script,$keys,$arguments);
		$marker=match(true){str_contains($script,'initialize:v1')=>'initialize',str_contains($script,'publish:v1')=>'publish',str_contains($script,'read:v1')=>'read',str_contains($script,'consume:v1')=>'consume',default=>'unknown'};
		$this->state->calls[]=['marker'=>$marker,'keys'=>array_values($keys),'arguments'=>array_values($arguments)];
		if($this->state->throw){$this->state->throw=false;throw new RuntimeException('redis secret transport failure');}
		if($this->state->reply!==null){$reply=$this->state->reply;$this->state->reply=null;return $reply;}
		return match($marker){
			'initialize'=>$this->initialize($arguments),
			'publish'=>$this->publish($keys,$arguments),
			'read'=>$this->read($keys,$arguments),
			'consume'=>$this->consume($arguments),
			default=>throw new RuntimeException('Unknown test script.'),
		};
	}

	public function jsonSerialize():array{return ['type'=>'panel_redis_realtime_test_transport','version'=>1,'state_serialized'=>false];}

	/** @param list<string> $arguments */
	private function initialize(array $arguments):array{
		if($this->state->schema===null){$this->state->schema=$arguments[0];}
		return $this->state->schema===$arguments[0]?['ok',$this->state->schema]:['incompatible',$this->state->schema];
	}

	/** @param list<string> $keys @param list<string> $arguments */
	private function publish(array $keys,array $arguments):array{
		if($this->state->schema===null){return ['schema_required'];}if($this->state->schema!==$arguments[0]){return ['schema_incompatible'];}
		$digest=$arguments[1];$eventKey=$keys[2];$registered=isset($this->state->registered[$digest]);$exists=isset($this->state->events[$eventKey])&&$this->state->events[$eventKey]!==[];
		if($exists&&!$registered){return ['corrupt'];}
		if(!$registered){if(count($this->state->registered)>=(int)$arguments[2]){return ['capacity'];}$this->state->registered[$digest]=true;}
		$rows=$this->state->events[$eventKey]??[];$head=$rows===[]?0:(int)strtok($rows[array_key_last($rows)][0],'-');
		if($head>=(int)$arguments[4]){return ['sequence_exhausted'];}$sequence=$head+1;
		$fields=['channel',$arguments[5],'topic',$arguments[6],'type',$arguments[7],'occurred_at',$arguments[8],'payload_json',$arguments[9],'metadata_json',$arguments[10],'record_digest',$arguments[11]];
		$rows[]=[(string)$sequence.'-0',$fields];$retained=(int)$arguments[3];if(count($rows)>$retained){$rows=array_slice($rows,-$retained);}$this->state->events[$eventKey]=$rows;
		return ['ok',(string)$sequence];
	}

	/** @param list<string> $keys @param list<string> $arguments */
	private function read(array $keys,array $arguments):array{
		if($this->state->schema===null){return ['schema_required'];}if($this->state->schema!==$arguments[0]){return ['schema_incompatible'];}
		$digest=$arguments[1];$eventKey=$keys[2];$registered=isset($this->state->registered[$digest]);$rows=$this->state->events[$eventKey]??[];
		if($rows!==[]&&!$registered){return ['corrupt'];}if(!$registered){return ['missing'];}if($rows===[]){return ['empty'];}
		$earliest=(int)strtok($rows[0][0],'-');$head=(int)strtok($rows[array_key_last($rows)][0],'-');$after=(int)$arguments[2];$limit=(int)$arguments[3];
		if($after>$head){return ['source_reset',(string)$head,(string)$earliest];}if($after+1<$earliest){return ['retention_gap',(string)$head,(string)$earliest];}
		$selected=array_values(array_filter($rows,static fn(array $row):bool=>(int)strtok($row[0],'-')>$after));$selected=array_slice($selected,0,$limit);
		return ['ok',(string)$head,(string)$earliest,$selected];
	}

	/** @param list<string> $arguments */
	private function consume(array $arguments):array{
		if($this->state->schema!==$arguments[0]){return ['unavailable'];}$now=(int)$arguments[1];foreach($this->state->nonces as $hash=>$expires){if($expires<$now){unset($this->state->nonces[$hash]);}}
		if(isset($this->state->nonces[$arguments[2]])){return ['duplicate'];}if(count($this->state->nonces)>=(int)$arguments[4]){return ['capacity'];}
		$this->state->nonces[$arguments[2]]=(int)$arguments[3];return ['ok'];
	}
}

final class PanelPhpRedisClientProbe {
	/** @var array<string,mixed> */ public array $call=[];
	public mixed $result=['ok'];
	public function __call(string $name,array $parameters):mixed{if($name!=='eval'||count($parameters)!==3){throw new BadMethodCallException('Unexpected phpredis probe method.');}[$script,$arguments,$keys]=$parameters;$this->call=compact('script','arguments','keys');return $this->result;}
}

final class PanelPredisClientProbe {
	/** @var list<string> */ public array $command=[];
	public function executeRaw(array $command):mixed{$this->command=$command;return ['ok'];}
}

/** @return array{context:PanelRealtimeContext,other:PanelRealtimeContext,subscription:PanelRealtimeSubscription} */
function dp_panel_redis_realtime_scope():array{
	$context=PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'north','principal_id'=>'operator-7','correlation_id'=>'redis-7']);
	$other=PanelRealtimeContext::fromTrusted('operations',['tenant_id'=>'south','principal_id'=>'operator-8']);
	$subscription=PanelRealtimeSubscription::fromTrusted($context,'orders',['*']);return compact('context','other','subscription');
}

/** @param array<string,mixed> $options @return array{state:PanelRedisRealtimeTestState,transport:PanelRedisRealtimeTestTransport,adapter:PanelRedisRealtimeAdapter} */
function dp_panel_redis_realtime_adapter(array $options=[],?callable $clock=null,?PanelRedisRealtimeTestState $state=null):array{
	$state??=new PanelRedisRealtimeTestState();$transport=new PanelRedisRealtimeTestTransport($state);$adapter=new PanelRedisRealtimeAdapter($transport,$options,$clock);return compact('state','transport','adapter');
}

function dp_panel_redis_realtime_error(Context $t,callable $callback,string $code):PanelRealtimeException{
	try{$callback();}catch(PanelRealtimeException $error){$t->same($code,$error->publicCode());return $error;}throw new RuntimeException("Expected PanelRealtimeException {$code}.");
}

function dp_panel_redis_realtime_intent(string $nonce,int $expiresAt=1100,string $purpose='subscribe'):PanelRealtimeIntentVerification{return new PanelRealtimeIntentVerification($purpose,0,900,$expiresAt,'active',$nonce);}

suite('Panel Redis Streams realtime adapter')
	->contract('panel.realtime.redis-streams-adapter',1)
	->layer('integration')
	->risk('critical')
	->watches('module:panel')
	->through('redis-streams','cluster-slot','atomic-append','retained-replay','nonce-replay-policy','sdk-transports')
	->isolation('case')
	->tag('panel','realtime','redis','streams','scorched-earth')
	->group('framework-coverage');

test('Redis SDK transports preserve exact EVAL shapes and serialize no clients or callbacks',static function(Context $t):void{
	$calls=[];$callback=new PanelCallbackRedisRealtimeTransport(static function(string $script,array $keys,array $arguments)use(&$calls):array{$calls=compact('script','keys','arguments');return ['ok'];});
	$t->same(['ok'],$callback->evaluate('return {"ok"}',['one:{slot}:a'],['value']));$t->same(['one:{slot}:a'],$calls['keys']);$t->same(['value'],$calls['arguments']);
	$phpClient=new PanelPhpRedisClientProbe();$php=new PanelPhpRedisRealtimeTransport($phpClient);$t->same(['ok'],$php->evaluate('return {"ok"}',['one','two'],['three']));$t->same(2,$phpClient->call['keys']);$t->same(['one','two','three'],$phpClient->call['arguments']);$phpClient->result=false;$t->throws(static fn()=>$php->evaluate('return 1',['one']),RuntimeException::class);
	$predisClient=new PanelPredisClientProbe();$predis=new PanelPredisRealtimeTransport($predisClient);$t->same(['ok'],$predis->evaluate('return {"ok"}',['one'],['two']));$t->same(['EVAL','return {"ok"}','1','one','two'],$predisClient->command);
	$t->same('callback',(new PanelRedisRealtimeAdapter($callback))->jsonSerialize()['transport']);$t->same('phpredis',(new PanelRedisRealtimeAdapter($php))->jsonSerialize()['transport']);$t->same('predis',(new PanelRedisRealtimeAdapter($predis))->jsonSerialize()['transport']);
	$t->throws(static fn()=>new PanelPhpRedisRealtimeTransport(new stdClass()),InvalidArgumentException::class);$t->throws(static fn()=>new PanelPredisRealtimeTransport(new stdClass()),InvalidArgumentException::class);
	foreach([['', ['key'],[]],[str_repeat('x',65537),['key'],[]],['x',[],[]],['x',array_fill(0,17,'key'),[]],['x',[''],[]],['x',[str_repeat('k',513)],[]],['x',['key'],array_fill(0,65,'x')],['x',['key'],[1]]] as [$script,$keys,$arguments]){$t->throws(static fn()=>PanelCallbackRedisRealtimeTransport::input($script,$keys,$arguments),InvalidArgumentException::class);}
	$encoded=json_encode([$callback,$php,$predis],JSON_THROW_ON_ERROR);foreach(['password','secret','connection','callback'] as $secret){if($secret==='connection'||$secret==='callback'){$t->contains($secret.'_serialized":false',$encoded);}else{$t->notContains($secret,$encoded);}}
})->tag('panel','realtime','redis','transport','phpredis','predis','security')->maxMillis(3000);

test('Redis namespace initialization is explicit idempotent cluster-safe and manifest-redacted',static function(Context $t):void{
	$fixture=dp_panel_redis_realtime_adapter(['key_prefix'=>'tenant:events','retained_events_per_stream'=>8]);$adapter=$fixture['adapter'];$scope=dp_panel_redis_realtime_scope();
	dp_panel_redis_realtime_error($t,static fn()=>$adapter->read($scope['subscription'],0,1),'broker_schema_required');dp_panel_redis_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]),'broker_schema_required');
	$first=$adapter->installSchema();$t->same($first,$adapter->installSchema());$t->same('redis_streams',$first['adapter']);$t->same('6.2',$first['minimum_redis_version']);$t->isTrue($first['cluster_single_slot']);$t->isFalse($first['destructive']);
	foreach($fixture['state']->calls as $call){$tags=[];foreach($call['keys'] as $key){$t->matches('/\{[a-f0-9]{24}\}/',$key);preg_match('/\{[^}]+\}/',$key,$match);$tags[]=$match[0]??'';}$t->same(1,count(array_unique($tags)));}
	$manifest=$adapter->jsonSerialize();$encoded=json_encode($manifest,JSON_THROW_ON_ERROR);$t->same('panel_realtime_redis_streams_adapter',$manifest['type']);$t->same('custom',$manifest['transport']);$t->isFalse($manifest['transport_class_serialized']);$t->same('host_configured',$manifest['durability']);$t->isFalse($manifest['durable_claim']);$t->isTrue($manifest['exact_retention']);$t->isTrue($manifest['unknown_ack_may_duplicate']);$t->same(4,count(PanelRedisRealtimeAdapter::scriptDigests()));foreach($manifest['script_digests'] as $digest){$t->matches('/^[a-f0-9]{64}$/',$digest);}foreach(['tenant:events','password','dsn','redis://','payload_json','PanelRedisRealtimeTestTransport'] as $secret){$t->notContains($secret,$encoded);}
	$platform=PanelPlatformManifest::inspect()->domain('realtime');foreach(['redis_transport','callback_redis_transport','phpredis_transport','predis_transport','redis_streams_adapter'] as $feature){$t->isTrue($platform['features'][$feature]??false);}
	$fixture['state']->schema='9';dp_panel_redis_realtime_error($t,static fn()=>$adapter->installSchema(),'broker_schema_incompatible');
})->tag('panel','realtime','redis','initialization','cluster','manifest')->maxMillis(3000);

test('Redis adapter passes broker conformance across independent transports and tenants',static function(Context $t):void{
	$shared=new PanelRedisRealtimeTestState();$first=dp_panel_redis_realtime_adapter(['key_prefix'=>'conformance','retained_events_per_stream'=>128],null,$shared)['adapter'];$second=dp_panel_redis_realtime_adapter(['key_prefix'=>'conformance','retained_events_per_stream'=>128],null,$shared)['adapter'];$first->installSchema();$scope=dp_panel_redis_realtime_scope();
	$report=PanelRealtimeBrokerConformance::verify($first,$scope['context'],$scope['other']);$t->isTrue($report['passed']);$t->same(8,$report['checks']);$t->same([],$report['violations']);
	$t->same(4,$second->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>4])->sequence());$t->same(5,$first->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>5])->sequence());
	$result=$second->read($scope['subscription'],0,100);$t->same(5,$result->head());$t->same(range(1,5),array_map(static fn(PanelRealtimeEvent $event):int=>$event->sequence(),$result->events()));$t->isFalse($result->hasMore());
	$foreign=$second->read(PanelRealtimeSubscription::fromTrusted($scope['other'],'orders',['*']),0,10);$t->same(0,$foreign->head());$t->same([],$foreign->events());
})->tag('panel','realtime','redis','conformance','distributed','tenant-isolation')->maxMillis(5000);

test('Redis retained replay advances scanned cursors and reports every unsafe reset',static function(Context $t):void{
	$now=1000;$clock=static function()use(&$now):int{return $now;};$fixture=dp_panel_redis_realtime_adapter(['key_prefix'=>'retention','retained_events_per_stream'=>2],$clock);$adapter=$fixture['adapter'];$adapter->installSchema();$scope=dp_panel_redis_realtime_scope();$paid=PanelRealtimeSubscription::fromTrusted($scope['context'],'orders',['*'],['status'=>'paid']);
	$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1],['status'=>'paid']);$now++;$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>2],['status'=>'review']);$now++;$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>3],['status'=>'paid']);
	$gap=$adapter->read($paid,0,10);$t->same('retention_gap',$gap->resetReason());$t->same(2,$gap->earliest());$t->same(3,$gap->head());$scan=$adapter->read($paid,1,1);$t->same([],$scan->events());$t->same(2,$scan->cursor());$t->isTrue($scan->hasMore());$t->same([3],array_map(static fn(PanelRealtimeEvent $event):int=>$event->sequence(),$adapter->read($paid,2,10)->events()));
	$t->same('source_reset',$adapter->read($paid,99,10)->resetReason());$missing=PanelRealtimeSubscription::fromTrusted($scope['context'],'missing',['*']);$t->same(0,$adapter->read($missing,0,1)->head());$t->same('source_reset',$adapter->read($missing,1,1)->resetReason());
	$cancelled=new PanelRealtimeCancellationToken();$cancelled->cancel();dp_panel_redis_realtime_error($t,static fn()=>$adapter->read($paid,1,1,$cancelled),'read_cancelled');foreach([[-1,1],[0,0],[0,1001]] as [$after,$limit]){$t->throws(static fn()=>$adapter->read($paid,$after,$limit),InvalidArgumentException::class);}
})->tag('panel','realtime','redis','retention','cursor','cancellation')->maxMillis(3000);

test('Redis replay policy atomically consumes only hashed initial-connect nonces',static function(Context $t):void{
	$now=1000;$clock=static function()use(&$now):int{return $now;};$shared=new PanelRedisRealtimeTestState();$first=dp_panel_redis_realtime_adapter(['key_prefix'=>'nonces','maximum_replay_entries'=>1,'replay_retention_grace_seconds'=>0],$clock,$shared)['adapter'];$second=dp_panel_redis_realtime_adapter(['key_prefix'=>'nonces','maximum_replay_entries'=>1,'replay_retention_grace_seconds'=>0],$clock,$shared)['adapter'];$first->installSchema();$scope=dp_panel_redis_realtime_scope();
	$intent=dp_panel_redis_realtime_intent(str_repeat('a',32),1001);$t->isTrue($first->consume($intent,$scope['subscription'],$scope['context']));$t->isFalse($second->consume($intent,$scope['subscription'],$scope['context']));$stored=(string)array_key_first($shared->nonces);$t->matches('/^[a-f0-9]{64}$/',$stored);$t->notContains($intent->nonce(),$stored);
	$capacity=dp_panel_redis_realtime_error($t,static fn()=>$first->consume(dp_panel_redis_realtime_intent(str_repeat('b',32),1100),$scope['subscription'],$scope['context']),'replay_policy_capacity');$t->isTrue($capacity->retryable());$now=1002;$t->isTrue($second->consume(dp_panel_redis_realtime_intent(str_repeat('b',32),1100),$scope['subscription'],$scope['context']));$t->same(1,count($shared->nonces));
	$t->throws(static fn()=>$first->consume(dp_panel_redis_realtime_intent(str_repeat('c',32),1100,'resume'),$scope['subscription'],$scope['context']),InvalidArgumentException::class);$t->throws(static fn()=>$first->consume(dp_panel_redis_realtime_intent(str_repeat('d',32)),$scope['subscription'],$scope['other']),InvalidArgumentException::class);dp_panel_redis_realtime_error($t,static fn()=>$first->consume(dp_panel_redis_realtime_intent(str_repeat('e',32),1001),$scope['subscription'],$scope['context']),'intent_expired');
	$endpointAdapter=dp_panel_redis_realtime_adapter(['key_prefix'=>'endpoint-nonces'],$clock)['adapter'];$endpointAdapter->installSchema();$signer=new PanelRealtimeIntentSigner(['active'=>str_repeat('k',32)],'active',$clock);$token=$signer->issueSubscription($scope['subscription'],60)->token();$endpoint=(new PanelRealtimeEndpoint($endpointAdapter,$signer,null,null,$clock))->protectSubscriptionIntents($endpointAdapter)->authorizeHost(static fn():bool=>true);$t->same(200,$endpoint->open($scope['subscription'],$token,null,$scope['context'])->status());
})->tag('panel','realtime','redis','replay-policy','nonce','endpoint','security')->maxMillis(5000);

test('Redis adapter enforces capacity bytes sequence integrity and sanitized failures',static function(Context $t):void{
	$scope=dp_panel_redis_realtime_scope();$fixture=dp_panel_redis_realtime_adapter(['key_prefix'=>'bounds','maximum_streams'=>1,'maximum_event_bytes'=>1024]);$adapter=$fixture['adapter'];$adapter->installSchema();$t->same(1,$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1])->sequence());
	$capacity=dp_panel_redis_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'other','other.updated','other.updated',['id'=>2]),'broker_capacity');$t->isTrue($capacity->retryable());dp_panel_redis_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',str_repeat('x',2000)),'event_too_large');
	$eventKey=array_key_first($fixture['state']->events);$fixture['state']->events[$eventKey][0][0]=(string)PHP_INT_MAX.'-0';dp_panel_redis_realtime_error($t,static fn()=>$adapter->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>3]),'broker_sequence_exhausted');
	$corrupt=dp_panel_redis_realtime_adapter(['key_prefix'=>'corrupt']);$corrupt['adapter']->installSchema();$corrupt['adapter']->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]);$key=array_key_first($corrupt['state']->events);$corrupt['state']->events[$key][0][1][13]=str_repeat('0',64);dp_panel_redis_realtime_error($t,static fn()=>$corrupt['adapter']->read($scope['subscription'],0,10),'broker_storage_corrupt');
	$unregistered=dp_panel_redis_realtime_adapter(['key_prefix'=>'unregistered']);$unregistered['adapter']->installSchema();$unregistered['adapter']->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]);$unregistered['state']->registered=[];dp_panel_redis_realtime_error($t,static fn()=>$unregistered['adapter']->read($scope['subscription'],0,10),'broker_storage_corrupt');
	$unavailable=dp_panel_redis_realtime_adapter(['key_prefix'=>'unavailable']);$unavailable['state']->throw=true;$failure=dp_panel_redis_realtime_error($t,static fn()=>$unavailable['adapter']->installSchema(),'broker_migration_failed');$t->isTrue($failure->retryable());$unavailable['state']->reply=[];dp_panel_redis_realtime_error($t,static fn()=>$unavailable['adapter']->installSchema(),'broker_migration_failed');$unavailable['adapter']->installSchema();$unavailable['state']->throw=true;$failure=dp_panel_redis_realtime_error($t,static fn()=>$unavailable['adapter']->read($scope['subscription'],0,1),'broker_storage_unavailable');$t->isTrue($failure->retryable());$unavailable['state']->reply=['bad'];dp_panel_redis_realtime_error($t,static fn()=>$unavailable['adapter']->read($scope['subscription'],0,1),'broker_storage_corrupt');
})->tag('panel','realtime','redis','bounds','corruption','availability')->maxMillis(3000);

test('Redis adapter rejects unsafe options clocks replies and replay bounds',static function(Context $t):void{
	$transport=new PanelRedisRealtimeTestTransport(new PanelRedisRealtimeTestState());foreach([['unknown'=>1],['key_prefix'=>'Bad Prefix'],['key_prefix'=>'bad{slot}'],['retained_events_per_stream'=>0],['maximum_streams'=>0],['maximum_event_bytes'=>100],['maximum_replay_entries'=>0],['replay_retention_grace_seconds'=>301]] as $options){$t->throws(static fn()=>new PanelRedisRealtimeAdapter($transport,$options),InvalidArgumentException::class);}
	$scope=dp_panel_redis_realtime_scope();$badClock=dp_panel_redis_realtime_adapter(['key_prefix'=>'clock'],static fn():string=>'bad');$badClock['adapter']->installSchema();$t->throws(static fn()=>$badClock['adapter']->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]),UnexpectedValueException::class);
	$tooLargeClock=dp_panel_redis_realtime_adapter(['key_prefix'=>'clock-large'],static fn():int=>9007199254740992);$tooLargeClock['adapter']->installSchema();$t->throws(static fn()=>$tooLargeClock['adapter']->publish($scope['context'],'orders','orders.updated','orders.updated',['id'=>1]),UnexpectedValueException::class);
	$replay=dp_panel_redis_realtime_adapter(['key_prefix'=>'replay-bound'],static fn():int=>1000);$replay['adapter']->installSchema();dp_panel_redis_realtime_error($t,static fn()=>$replay['adapter']->consume(dp_panel_redis_realtime_intent(str_repeat('f',32),PHP_INT_MAX),$scope['subscription'],$scope['context']),'replay_policy_unavailable');
	$replay['state']->throw=true;dp_panel_redis_realtime_error($t,static fn()=>$replay['adapter']->consume(dp_panel_redis_realtime_intent(str_repeat('e',32),1100),$scope['subscription'],$scope['context']),'replay_policy_unavailable');$replay['state']->reply=['corrupt'];dp_panel_redis_realtime_error($t,static fn()=>$replay['adapter']->consume(dp_panel_redis_realtime_intent(str_repeat('d',32),1100),$scope['subscription'],$scope['context']),'replay_policy_unavailable');$replay['state']->reply=[];dp_panel_redis_realtime_error($t,static fn()=>$replay['adapter']->consume(dp_panel_redis_realtime_intent(str_repeat('c',32),1100),$scope['subscription'],$scope['context']),'replay_policy_unavailable');
})->tag('panel','realtime','redis','validation','exact-coverage')->maxMillis(3000);
