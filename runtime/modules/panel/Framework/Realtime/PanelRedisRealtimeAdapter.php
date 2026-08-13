<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Redis Streams publication, retained replay, and initial-connect nonce store.
 * Namespace initialization is explicit and every multi-key script is pinned to
 * one Redis Cluster hash slot. Redis durability remains a host configuration.
 */
final class PanelRedisRealtimeAdapter implements PanelRealtimeBroker, PanelRealtimePublisher, PanelRealtimeSubscriptionIntentReplayPolicy {
	private const SCHEMA_VERSION=1;
	private const MINIMUM_REDIS_VERSION='6.2';
	private const DEFAULT_PREFIX='panel:realtime';
	private const MAXIMUM_EXACT_REDIS_INTEGER=9007199254740991;
	private const OPTION_NAMES=['key_prefix','retained_events_per_stream','maximum_streams','maximum_event_bytes','maximum_replay_entries','replay_retention_grace_seconds'];

	private const INITIALIZE_SCRIPT=<<<'LUA'
-- dataphyre-panel:redis-realtime-initialize:v1
local function kind(key)
    local value=redis.call('TYPE',key)
    if type(value)=='table' then return value['ok'] end
    return value
end
local schema_type=kind(KEYS[1])
local streams_type=kind(KEYS[2])
local nonces_type=kind(KEYS[3])
if (schema_type~='none' and schema_type~='string') or (streams_type~='none' and streams_type~='set') or (nonces_type~='none' and nonces_type~='zset') then
    return {'corrupt'}
end
local current=redis.call('GET',KEYS[1])
if not current then
    redis.call('SET',KEYS[1],ARGV[1],'NX')
    current=redis.call('GET',KEYS[1])
end
if current~=ARGV[1] then return {'incompatible',current or ''} end
return {'ok',current}
LUA;

	private const PUBLISH_SCRIPT=<<<'LUA'
-- dataphyre-panel:redis-realtime-publish:v1
local function kind(key)
    local value=redis.call('TYPE',key)
    if type(value)=='table' then return value['ok'] end
    return value
end
local function canonical(value)
    return type(value)=='string' and string.match(value,'^%d+$')~=nil and (value=='0' or string.sub(value,1,1)~='0')
end
local function compare(left,right)
    if #left<#right then return -1 end
    if #left>#right then return 1 end
    if left<right then return -1 end
    if left>right then return 1 end
    return 0
end
local function increment(value)
    local output=''
    local carry=1
    for index=#value,1,-1 do
        local digit=string.byte(value,index)-48+carry
        if digit>=10 then digit=digit-10 carry=1 else carry=0 end
        output=string.char(48+digit)..output
    end
    if carry==1 then output='1'..output end
    return output
end
if kind(KEYS[1])~='string' or redis.call('GET',KEYS[1])~=ARGV[1] then
    if kind(KEYS[1])=='none' then return {'schema_required'} end
    return {'schema_incompatible'}
end
local streams_type=kind(KEYS[2])
local stream_type=kind(KEYS[3])
if (streams_type~='none' and streams_type~='set') or (stream_type~='none' and stream_type~='stream') then return {'corrupt'} end
local registered=redis.call('SISMEMBER',KEYS[2],ARGV[2])
if stream_type=='stream' and registered~=1 then return {'corrupt'} end
local head='0'
if stream_type=='stream' then
    local latest=redis.call('XREVRANGE',KEYS[3],'+','-','COUNT',1)
    if #latest~=1 or type(latest[1])~='table' or type(latest[1][1])~='string' then return {'corrupt'} end
    local identifier=latest[1][1]
    local separator=string.find(identifier,'-',1,true)
    if not separator or string.sub(identifier,separator+1)~='0' then return {'corrupt'} end
    head=string.sub(identifier,1,separator-1)
    if not canonical(head) then return {'corrupt'} end
elseif registered~=1 then
    if redis.call('SCARD',KEYS[2])>=tonumber(ARGV[3]) then return {'capacity'} end
    redis.call('SADD',KEYS[2],ARGV[2])
end
if not canonical(ARGV[5]) or compare(head,ARGV[5])>=0 then return {'sequence_exhausted'} end
local sequence=increment(head)
local identifier=sequence..'-0'
local inserted=redis.call('XADD',KEYS[3],'MAXLEN','=',ARGV[4],identifier,
    'channel',ARGV[6],'topic',ARGV[7],'type',ARGV[8],'occurred_at',ARGV[9],
    'payload_json',ARGV[10],'metadata_json',ARGV[11],'record_digest',ARGV[12])
if inserted~=identifier then return {'corrupt'} end
return {'ok',sequence}
LUA;

	private const READ_SCRIPT=<<<'LUA'
-- dataphyre-panel:redis-realtime-read:v1
local function kind(key)
    local value=redis.call('TYPE',key)
    if type(value)=='table' then return value['ok'] end
    return value
end
local function canonical(value)
    return type(value)=='string' and string.match(value,'^%d+$')~=nil and (value=='0' or string.sub(value,1,1)~='0')
end
local function compare(left,right)
    if #left<#right then return -1 end
    if #left>#right then return 1 end
    if left<right then return -1 end
    if left>right then return 1 end
    return 0
end
local function increment(value)
    local output=''
    local carry=1
    for index=#value,1,-1 do
        local digit=string.byte(value,index)-48+carry
        if digit>=10 then digit=digit-10 carry=1 else carry=0 end
        output=string.char(48+digit)..output
    end
    if carry==1 then output='1'..output end
    return output
end
local function sequence(identifier)
    if type(identifier)~='string' then return nil end
    local separator=string.find(identifier,'-',1,true)
    if not separator or string.sub(identifier,separator+1)~='0' then return nil end
    local value=string.sub(identifier,1,separator-1)
    if not canonical(value) then return nil end
    return value
end
if kind(KEYS[1])~='string' or redis.call('GET',KEYS[1])~=ARGV[1] then
    if kind(KEYS[1])=='none' then return {'schema_required'} end
    return {'schema_incompatible'}
end
local streams_type=kind(KEYS[2])
local stream_type=kind(KEYS[3])
if (streams_type~='none' and streams_type~='set') or (stream_type~='none' and stream_type~='stream') then return {'corrupt'} end
local registered=redis.call('SISMEMBER',KEYS[2],ARGV[2])
if stream_type=='none' then
    if registered==1 then return {'empty'} end
    return {'missing'}
end
if registered~=1 then return {'corrupt'} end
local first=redis.call('XRANGE',KEYS[3],'-','+','COUNT',1)
local last=redis.call('XREVRANGE',KEYS[3],'+','-','COUNT',1)
if #first~=1 or #last~=1 then return {'corrupt'} end
local earliest=sequence(first[1][1])
local head=sequence(last[1][1])
if not earliest or not head or compare(earliest,head)>0 then return {'corrupt'} end
local after=ARGV[3]
if not canonical(after) then return {'corrupt'} end
if compare(after,head)>0 then return {'source_reset',head,earliest} end
if compare(after,head)<0 and compare(increment(after),earliest)<0 then return {'retention_gap',head,earliest} end
local rows={}
if compare(after,head)<0 then rows=redis.call('XRANGE',KEYS[3],'('..after..'-0','+','COUNT',ARGV[4]) end
return {'ok',head,earliest,rows}
LUA;

	private const CONSUME_SCRIPT=<<<'LUA'
-- dataphyre-panel:redis-realtime-consume:v1
local function kind(key)
    local value=redis.call('TYPE',key)
    if type(value)=='table' then return value['ok'] end
    return value
end
if kind(KEYS[1])~='string' or redis.call('GET',KEYS[1])~=ARGV[1] then return {'unavailable'} end
local nonce_type=kind(KEYS[2])
if nonce_type~='none' and nonce_type~='zset' then return {'corrupt'} end
redis.call('ZREMRANGEBYSCORE',KEYS[2],'-inf','('..ARGV[2])
if redis.call('ZSCORE',KEYS[2],ARGV[3]) then return {'duplicate'} end
if redis.call('ZCARD',KEYS[2])>=tonumber(ARGV[5]) then return {'capacity'} end
redis.call('ZADD',KEYS[2],ARGV[4],ARGV[3])
return {'ok'}
LUA;

	private readonly string $prefix;
	private readonly string $baseKey;
	private readonly int $retainedEvents;
	private readonly int $maximumStreams;
	private readonly int $maximumEventBytes;
	private readonly int $maximumReplayEntries;
	private readonly int $retentionGraceSeconds;
	private readonly \Closure $clock;

	/** @param array<string,mixed> $options */
	public function __construct(private readonly PanelRedisRealtimeTransport $transport, array $options=[], ?callable $clock=null){
		foreach(array_keys($options) as $name){if(!is_string($name)||!in_array($name,self::OPTION_NAMES,true)){throw new \InvalidArgumentException('Panel Redis realtime adapter options contain an unsupported name.');}}
		$this->prefix=self::prefix((string)($options['key_prefix']??self::DEFAULT_PREFIX));
		$slot=substr(hash('sha256',"panel-redis-realtime-slot-v1\0".$this->prefix),0,24);
		$this->baseKey=$this->prefix.':{'.$slot.'}';
		$this->retainedEvents=self::option($options,'retained_events_per_stream',1024,1,100000);
		$this->maximumStreams=self::option($options,'maximum_streams',100000,1,1000000);
		$this->maximumEventBytes=self::option($options,'maximum_event_bytes',196608,1024,1048576);
		$this->maximumReplayEntries=self::option($options,'maximum_replay_entries',100000,1,1000000);
		$this->retentionGraceSeconds=self::option($options,'replay_retention_grace_seconds',60,0,300);
		$this->clock=\Closure::fromCallable($clock??static fn():int=>time());
	}

	/** @return array{type:string,version:int,adapter:string,schema_version:int,idempotent:bool,destructive:bool,minimum_redis_version:string,cluster_single_slot:bool} */
	public function installSchema(): array {
		try{
			$reply=$this->evaluate(self::INITIALIZE_SCRIPT,[$this->schemaKey(),$this->streamsKey(),$this->noncesKey()],[(string)self::SCHEMA_VERSION],'broker_migration_failed','Panel Redis realtime namespace initialization failed.');
			$status=$this->status($reply);
			if($status==='incompatible'){throw new PanelRealtimeException('broker_schema_incompatible',503,'Panel Redis realtime schema version is incompatible.');}
			if($status!=='ok'){throw new PanelRealtimeException('broker_migration_failed',503,'Panel Redis realtime namespace initialization failed.',true);}
			return ['type'=>'panel_redis_realtime_initialization','version'=>1,'adapter'=>'redis_streams','schema_version'=>self::SCHEMA_VERSION,'idempotent'=>true,'destructive'=>false,'minimum_redis_version'=>self::MINIMUM_REDIS_VERSION,'cluster_single_slot'=>true];
		}catch(PanelRealtimeException $error){
			if(in_array($error->publicCode(),['broker_schema_incompatible','broker_migration_failed'],true)){throw $error;}
			throw new PanelRealtimeException('broker_migration_failed',503,'Panel Redis realtime namespace initialization failed.',true);
		}
	}

	public function publish(PanelRealtimeContext $context, string $channel, string $topic, string $type, mixed $payload, array $metadata=[], ?string $occurredAt=null): PanelRealtimeEvent {
		$channel=PanelRealtimeGuard::identifier($channel,'channel',96);$topic=PanelRealtimeGuard::identifier($topic,'topic',96);$type=PanelRealtimeGuard::identifier($type,'event type',96);
		$streamKey=$context->streamKey($channel);$occurredAt=$occurredAt??gmdate('Y-m-d\TH:i:s\Z',$this->now());
		try{$probe=new PanelRealtimeEvent(PHP_INT_MAX,$streamKey,$channel,$topic,$type,$occurredAt,$payload,$metadata);}catch(\LengthException){throw new PanelRealtimeException('event_too_large',422,'Panel realtime event exceeds the broker byte bound.');}
		if($probe->wireBytes()>$this->maximumEventBytes){throw new PanelRealtimeException('event_too_large',422,'Panel realtime event exceeds the broker byte bound.');}
		$payloadJson=self::encodeJson($payload);$metadataJson=self::encodeJson($metadata);$digest=self::recordDigest($streamKey,$channel,$topic,$type,$occurredAt,$payloadJson,$metadataJson);
		$reply=$this->evaluate(self::PUBLISH_SCRIPT,[$this->schemaKey(),$this->streamsKey(),$this->streamKey($streamKey)],[(string)self::SCHEMA_VERSION,$streamKey,(string)$this->maximumStreams,(string)$this->retainedEvents,(string)PHP_INT_MAX,$channel,$topic,$type,$occurredAt,$payloadJson,$metadataJson,$digest],'broker_storage_unavailable','Panel Redis realtime broker is unavailable.');
		$status=$this->status($reply);
		if($status==='schema_required'){throw new PanelRealtimeException('broker_schema_required',503,'Panel Redis realtime namespace is not initialized.');}
		if($status==='schema_incompatible'){throw new PanelRealtimeException('broker_schema_incompatible',503,'Panel Redis realtime schema version is incompatible.');}
		if($status==='capacity'){throw new PanelRealtimeException('broker_capacity',503,'Panel realtime broker capacity is exhausted.',true);}
		if($status==='sequence_exhausted'){throw new PanelRealtimeException('broker_sequence_exhausted',503,'Panel realtime stream sequence is exhausted.');}
		if($status!=='ok' || !array_key_exists(1,$reply)){throw $this->corrupt();}
		$sequence=$this->integer($reply[1],'event sequence',1,PHP_INT_MAX);
		return new PanelRealtimeEvent($sequence,$streamKey,$channel,$topic,$type,$occurredAt,$payload,$metadata);
	}

	public function read(PanelRealtimeSubscription $subscription, int $afterSequence, int $limit, ?PanelRealtimeCancellation $cancellation=null): PanelRealtimeReadResult {
		if($afterSequence<0||$limit<1||$limit>1000){throw new \InvalidArgumentException('Panel realtime broker read bounds are invalid.');}
		$this->cancelled($cancellation);
		$streamKey=$subscription->streamKey();
		$reply=$this->evaluate(self::READ_SCRIPT,[$this->schemaKey(),$this->streamsKey(),$this->streamKey($streamKey)],[(string)self::SCHEMA_VERSION,$streamKey,(string)$afterSequence,(string)$limit],'broker_storage_unavailable','Panel Redis realtime broker is unavailable.');
		$status=$this->status($reply);
		if($status==='schema_required'){throw new PanelRealtimeException('broker_schema_required',503,'Panel Redis realtime namespace is not initialized.');}
		if($status==='schema_incompatible'){throw new PanelRealtimeException('broker_schema_incompatible',503,'Panel Redis realtime schema version is incompatible.');}
		if($status==='missing'||$status==='empty'){return $afterSequence===0?new PanelRealtimeReadResult(0,[],0,0,1):new PanelRealtimeReadResult($afterSequence,[],0,0,1,false,'source_reset');}
		if(in_array($status,['source_reset','retention_gap'],true)){
			if(!array_key_exists(2,$reply)){throw $this->corrupt();}
			$head=$this->integer($reply[1]??null,'stream head',0,PHP_INT_MAX);$earliest=$this->integer($reply[2],'earliest sequence',1,PHP_INT_MAX);
			return new PanelRealtimeReadResult($afterSequence,[],$head,$head,$earliest,false,$status);
		}
		if($status!=='ok'||!array_key_exists(3,$reply)||!is_array($reply[3])||!array_is_list($reply[3])||count($reply[3])>$limit){throw $this->corrupt();}
		$head=$this->integer($reply[1]??null,'stream head',0,PHP_INT_MAX);$earliest=$this->integer($reply[2]??null,'earliest sequence',1,PHP_INT_MAX);
		$events=[];$cursor=$afterSequence;$expected=$afterSequence===PHP_INT_MAX?PHP_INT_MAX:$afterSequence+1;
		foreach($reply[3] as $row){
			$this->cancelled($cancellation);$event=$this->hydrate($row,$streamKey);
			if($event->sequence()!==$expected||!hash_equals($subscription->channel(),$event->channel())){throw $this->corrupt();}
			$cursor=$event->sequence();$expected++;if($subscription->accepts($event)){$events[]=$event;}
		}
		if(count($reply[3])<$limit&&$cursor<$head){throw $this->corrupt();}
		return new PanelRealtimeReadResult($afterSequence,$events,$cursor,$head,$earliest,$cursor<$head);
	}

	public function consume(PanelRealtimeIntentVerification $intent, PanelRealtimeSubscription $subscription, PanelRealtimeContext $context): bool {
		if($intent->purpose()!=='subscribe'||!$subscription->belongsTo($context)){throw new \InvalidArgumentException('Panel realtime replay policy accepts only matching subscription intents.');}
		$now=$this->now();if($intent->expiresAt()<$now){throw new PanelRealtimeException('intent_expired',401,'Panel realtime intent has expired.');}
		if($intent->expiresAt()>self::MAXIMUM_EXACT_REDIS_INTEGER-$this->retentionGraceSeconds){throw new PanelRealtimeException('replay_policy_unavailable',503,'Panel realtime replay protection is unavailable.',true);}
		$nonceHash=hash('sha256',"panel-realtime-subscription-nonce-v1\0".$intent->nonce());$expires=$intent->expiresAt()+$this->retentionGraceSeconds;
		try{$reply=$this->evaluate(self::CONSUME_SCRIPT,[$this->schemaKey(),$this->noncesKey()],[(string)self::SCHEMA_VERSION,(string)$now,$nonceHash,(string)$expires,(string)$this->maximumReplayEntries],'replay_policy_unavailable','Panel realtime replay protection is unavailable.');$status=$this->status($reply);}
		catch(PanelRealtimeException $error){if($error->publicCode()==='replay_policy_unavailable'){throw $error;}throw new PanelRealtimeException('replay_policy_unavailable',503,'Panel realtime replay protection is unavailable.',true);}
		if($status==='duplicate'){return false;}
		if($status==='capacity'){throw new PanelRealtimeException('replay_policy_capacity',503,'Panel realtime replay protection is at capacity.',true);}
		if($status!=='ok'){throw new PanelRealtimeException('replay_policy_unavailable',503,'Panel realtime replay protection is unavailable.',true);}
		return true;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_realtime_redis_streams_adapter','version'=>1,'adapter'=>'redis_streams','transport'=>$this->transportName(),'transport_class_serialized'=>false,'distributed'=>true,'cross_process'=>true,'redis_cluster_single_slot'=>true,'minimum_redis_version'=>self::MINIMUM_REDIS_VERSION,'durability'=>'host_configured','durable_claim'=>false,'ordered_per_stream'=>true,'serialized_publication'=>true,'atomic_event_append'=>true,'replay'=>true,'retention_gap_detection'=>true,'exact_retention'=>true,'retained_events_per_stream'=>$this->retainedEvents,'maximum_streams'=>$this->maximumStreams,'maximum_event_bytes'=>$this->maximumEventBytes,'subscription_intent_replay_policy'=>true,'single_use_initial_connect'=>true,'maximum_replay_entries'=>$this->maximumReplayEntries,'replay_retention_grace_seconds'=>$this->retentionGraceSeconds,'nonce_digest'=>'sha256_domain_separated','raw_nonces_stored'=>false,'resume_intents_consumed'=>false,'schema_version'=>self::SCHEMA_VERSION,'schema_initialization'=>'explicit_idempotent','automatic_schema_mutation'=>false,'delivery'=>'at_least_once_across_reconnect','unknown_ack_may_duplicate'=>true,'exactly_once'=>false,'publication_retry'=>'host_owned','connection_details_serialized'=>false,'credentials_serialized'=>false,'key_prefix_serialized'=>false,'keys_serialized'=>false,'scripts_serialized'=>false,'script_digests'=>self::scriptDigests(),'live_counts_queried'=>false];
	}

	/** @return array{initialize:string,publish:string,read:string,consume:string} */
	public static function scriptDigests(): array {return ['initialize'=>hash('sha256',self::INITIALIZE_SCRIPT),'publish'=>hash('sha256',self::PUBLISH_SCRIPT),'read'=>hash('sha256',self::READ_SCRIPT),'consume'=>hash('sha256',self::CONSUME_SCRIPT)];}

	/** @param list<string> $keys @param list<string> $arguments */
	private function evaluate(string $script,array $keys,array $arguments,string $failureCode,string $message): array {
		try{$reply=$this->transport->evaluate($script,$keys,$arguments);}catch(\Throwable){throw new PanelRealtimeException($failureCode,503,$message,true);}
		if(!is_array($reply)||!array_is_list($reply)||$reply===[]){throw $this->corrupt();}
		return $reply;
	}

	private function status(array $reply): string {if(!isset($reply[0])||!is_string($reply[0])||preg_match('/^[a-z_]{2,32}$/D',$reply[0])!==1){throw $this->corrupt();}return $reply[0];}

	private function hydrate(mixed $row,string $streamKey): PanelRealtimeEvent {
		try{
			if(!is_array($row)||!array_is_list($row)||count($row)!==2||!is_string($row[0])||!is_array($row[1])||!array_is_list($row[1])||count($row[1])!==14){throw new \UnexpectedValueException('Stored Redis stream row is invalid.');}
			if(preg_match('/^(0|[1-9][0-9]*)-0$/D',$row[0],$match)!==1){throw new \UnexpectedValueException('Stored Redis stream identifier is invalid.');}
			$sequence=$this->integer($match[1],'event sequence',1,PHP_INT_MAX);$fields=[];
			for($index=0;$index<count($row[1]);$index+=2){$name=$row[1][$index]??null;$value=$row[1][$index+1]??null;if(!is_string($name)||!is_string($value)||array_key_exists($name,$fields)){throw new \UnexpectedValueException('Stored Redis stream fields are invalid.');}$fields[$name]=$value;}
			$expected=['channel','topic','type','occurred_at','payload_json','metadata_json','record_digest'];if(array_keys($fields)!==$expected){throw new \UnexpectedValueException('Stored Redis stream field grammar is invalid.');}
			if(strlen($fields['payload_json'])>$this->maximumEventBytes||strlen($fields['metadata_json'])>$this->maximumEventBytes||preg_match('/^[a-f0-9]{64}$/D',$fields['record_digest'])!==1){throw new \UnexpectedValueException('Stored Redis stream bounds are invalid.');}
			$digest=self::recordDigest($streamKey,$fields['channel'],$fields['topic'],$fields['type'],$fields['occurred_at'],$fields['payload_json'],$fields['metadata_json']);if(!hash_equals($digest,$fields['record_digest'])){throw new \UnexpectedValueException('Stored Redis stream digest is invalid.');}
			$payload=json_decode($fields['payload_json'],true,64,JSON_THROW_ON_ERROR);$metadata=json_decode($fields['metadata_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($metadata)){throw new \UnexpectedValueException('Stored Redis stream metadata is invalid.');}
			$event=new PanelRealtimeEvent($sequence,$streamKey,$fields['channel'],$fields['topic'],$fields['type'],$fields['occurred_at'],$payload,$metadata);if($event->wireBytes()>$this->maximumEventBytes){throw new \UnexpectedValueException('Stored Redis stream event exceeds its byte bound.');}return $event;
		}catch(PanelRealtimeException $error){throw $error;}catch(\Throwable){throw $this->corrupt();}
	}

	private function schemaKey():string{return $this->baseKey.':schema';}
	private function streamsKey():string{return $this->baseKey.':streams';}
	private function noncesKey():string{return $this->baseKey.':nonces';}
	private function streamKey(string $streamKey):string{PanelRealtimeGuard::digest($streamKey,'stream key');return $this->baseKey.':stream:'.$streamKey;}
	private function transportName():string{return match(true){$this->transport instanceof PanelCallbackRedisRealtimeTransport=>'callback',$this->transport instanceof PanelPhpRedisRealtimeTransport=>'phpredis',$this->transport instanceof PanelPredisRealtimeTransport=>'predis',default=>'custom'};}
	private function cancelled(?PanelRealtimeCancellation $cancellation):void{if($cancellation?->isCancellationRequested()){throw new PanelRealtimeException('read_cancelled',408,'Panel realtime broker read was cancelled.');}}
	private function corrupt():PanelRealtimeException{return new PanelRealtimeException('broker_storage_corrupt',503,'Panel Redis realtime broker failed integrity validation.');}
	private function now():int{$value=($this->clock)();if(!is_int($value)||$value<0||$value>self::MAXIMUM_EXACT_REDIS_INTEGER){throw new \UnexpectedValueException('Panel Redis realtime adapter clock must return an exactly representable non-negative timestamp.');}return $value;}

	private function integer(mixed $value,string $label,int $minimum,int $maximum):int {
		if(is_int($value)){$number=$value;}elseif(is_string($value)&&preg_match('/^(0|[1-9][0-9]*)$/D',$value)===1&&strlen($value)<=strlen((string)$maximum)){$number=(int)$value;if((string)$number!==$value){throw $this->corrupt();}}else{throw $this->corrupt();}
		if($number<$minimum||$number>$maximum){throw $this->corrupt();}return $number;
	}

	private static function recordDigest(string $streamKey,string $channel,string $topic,string $type,string $occurredAt,string $payloadJson,string $metadataJson):string{return hash('sha256',"panel-redis-realtime-record-v1\0{$streamKey}\0{$channel}\0{$topic}\0{$type}\0{$occurredAt}\0{$payloadJson}\0{$metadataJson}");}
	private static function encodeJson(mixed $value):string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	private static function prefix(string $prefix):string{$prefix=strtolower(trim($prefix));if(preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D',$prefix)!==1||str_contains($prefix,'{')||str_contains($prefix,'}')){throw new \InvalidArgumentException('Panel Redis realtime key prefix is invalid.');}return $prefix;}
	/** @param array<string,mixed> $options */
	private static function option(array $options,string $name,int $default,int $minimum,int $maximum):int{$value=$options[$name]??$default;if(!is_int($value)||$value<$minimum||$value>$maximum){throw new \InvalidArgumentException("Panel Redis realtime option '{$name}' is outside its supported bound.");}return $value;}
}
