<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Durable encrypted federation gateway with signed acknowledgements, exact
 * replay, monotonic peer sequences, nonce defense, outbox retry, and recovery.
 */
final class PanelFederationGateway implements \JsonSerializable {
	private const OUTBOUND_KINDS=['heartbeat','desired_state','reconcile_request'];
	private const TERMINAL_OUTBOX=['delivered','rejected'];

	private readonly PanelAtomicSnapshotStore $store;
	private readonly \Closure $clock;
	/** @var array<string,string> */private array $keys=[];

	/** @param array<string,string> $keys */
	public function __construct(
		string $root,
		private readonly string $localNodeId,
		private readonly PanelFederationControlPlane $controlPlane,
		private readonly PanelPolicyControlPlane $policy,
		private readonly PanelCommandPayloadCodec $codec,
		array $keys,
		private readonly string $currentKeyId,
		private readonly ?PanelFederationTransport $transport=null,
		?callable $clock=null,
		private readonly int $messageTtlSeconds=300,
		private readonly int $maximumEntries=10000,
		int $snapshotRetention=2048,
	) {
		PanelOperationsGuard::name($localNodeId,'local federation node');if($keys===[]){throw new \InvalidArgumentException('Federation gateway requires a keyring.');}foreach($keys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'federation gateway key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Federation gateway keys require at least 32 bytes.');}$this->keys[$id]=$key;}
		PanelOperationsGuard::name($currentKeyId,'current federation gateway key id');if(!isset($this->keys[$currentKeyId])){throw new \InvalidArgumentException('Current federation gateway key is not trusted.');}if($messageTtlSeconds<30||$messageTtlSeconds>3600){throw new \InvalidArgumentException('Federation message TTL must be between 30 and 3600 seconds.');}if($maximumEntries<1||$maximumEntries>1000000){throw new \InvalidArgumentException('Federation gateway retention bound is invalid.');}
		$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():string=>gmdate('c');$initial=$this->signedState(['schema'=>'panel_federation_gateway','version'=>1,'revision'=>0,'outbound_sequence'=>0,'inbound_sequences'=>[],'outbox'=>[],'inbox'=>[],'idempotency'=>[],'nonces'=>[]]);$this->store=new PanelAtomicSnapshotStore($root,'panel.federation-gateway.v1',$initial,max(8,$snapshotRetention));$this->assertState($this->store->payload());
	}

	public function localNodeId():string{return$this->localNodeId;}public function controlPlane():PanelFederationControlPlane{return$this->controlPlane;}public function policy():PanelPolicyControlPlane{return$this->policy;}public function store():PanelAtomicSnapshotStore{return$this->store;}public function transportConfigured():bool{return$this->transport instanceof PanelFederationTransport;}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function send(string $kind,string $targetNode,array $payload,PanelPolicyRequest|array $request,string $idempotencyKey,bool $immediate=true):array {
		$request=$this->request($request);$this->authorize($request,'federation.transport.send');$kind=strtolower(trim($kind));if(!in_array($kind,self::OUTBOUND_KINDS,true)){throw new \InvalidArgumentException('Federation outbound message kind is invalid.');}$targetNode=PanelOperationsGuard::name($targetNode,'federation target node');if($targetNode===$this->localNodeId){throw new \InvalidArgumentException('Federation messages cannot target their source node.');}$payload=PanelOperationsGuard::safeMetadata($payload,1024);$payloadDigest=PanelOperationsGuard::digest($payload);$idempotencyHash=$this->idempotency($request,$idempotencyKey);$fingerprint=PanelOperationsGuard::digest(['kind'=>$kind,'target_node'=>$targetNode,'payload_digest'=>$payloadDigest]);
		$state=$this->state();$known=$state['idempotency'][$idempotencyHash]??null;if(is_array($known)){if(!hash_equals((string)($known['fingerprint']??''),$fingerprint)){throw new \LogicException('Federation outbox idempotency key conflict.');}$projection=$this->outbound((string)$known['id']);if(in_array($projection['status'],self::TERMINAL_OUTBOX,true)||!$immediate||!$this->transportConfigured()){return array_replace($projection,['replayed'=>true]);}return$this->deliver((string)$known['id'],$request);}
		$messageId=$this->mutate(function(array &$state)use($kind,$targetNode,$payload,$payloadDigest,$idempotencyHash,$fingerprint):string {
			$this->prune($state,'outbox');if(count($state['outbox'])>=$this->maximumEntries){throw new \LengthException('Federation outbox retention limit is reached.');}$state['outbound_sequence']++;$now=$this->now();$message=PanelFederationMessage::sign($kind,$this->localNodeId,$targetNode,$state['outbound_sequence'],$payload,$now,$this->after($now,$this->messageTtlSeconds),$this->currentKeyId,$this->keys[$this->currentKeyId],$this->codec);
			$state['outbox'][$message->id()]=['manifest'=>$message->jsonSerialize(),'sealed'=>$message->wire()['sealed'],'status'=>'pending','attempts'=>0,'idempotency_hash'=>$idempotencyHash,'fingerprint'=>$fingerprint,'acknowledgement'=>null,'last_error_code'=>null,'created_at'=>$now,'updated_at'=>$now];$state['idempotency'][$idempotencyHash]=['id'=>$message->id(),'fingerprint'=>$fingerprint];ksort($state['outbox'],SORT_STRING);ksort($state['idempotency'],SORT_STRING);return$message->id();
		},'federation.outbox.queued',['kind'=>$kind,'target_node'=>$targetNode]);
		if(!$immediate||!$this->transportConfigured()){return$this->outbound($messageId);}return$this->deliver($messageId,$request);
	}

	/** @return array<string,mixed> */
	public function heartbeat(PanelFederationNode $node,string $targetNode,PanelPolicyRequest|array $request,string $idempotencyKey,bool $immediate=true):array {if($node->id()!==$this->localNodeId){throw new \LogicException('Federation heartbeat node does not match the local gateway identity.');}return$this->send('heartbeat',$targetNode,['node'=>$node->jsonSerialize()],$request,$idempotencyKey,$immediate);}
	/** @param array<string,string> $desired @return array<string,mixed> */public function pushDesired(string $targetNode,array $desired,PanelPolicyRequest|array $request,string $idempotencyKey,bool $immediate=true):array{return$this->send('desired_state',$targetNode,['desired'=>$desired],$request,$idempotencyKey,$immediate);}
	/** @return array<string,mixed> */public function requestReconciliation(string $targetNode,PanelPolicyRequest|array $request,string $idempotencyKey,bool $immediate=true):array{return$this->send('reconcile_request',$targetNode,['requested_revision'=>$this->controlPlane->revision()],$request,$idempotencyKey,$immediate);}

	/** @return array<string,mixed> */
	public function deliver(string $messageId,PanelPolicyRequest|array $request,int $staleAfterSeconds=0):array {
		$request=$this->request($request);$this->authorize($request,'federation.transport.deliver');if(!$this->transport instanceof PanelFederationTransport){throw new \LogicException('Federation transport is not configured.');}$messageId=PanelOperationsGuard::identifier($messageId,'federation message id',190);if($staleAfterSeconds<0||$staleAfterSeconds>604800){throw new \InvalidArgumentException('Federation delivery stale threshold is invalid.');}
		$wire=$this->mutate(function(array &$state)use($messageId,$staleAfterSeconds):?array{$entry=&$this->outboxReference($state,$messageId);if(in_array($entry['status'],self::TERMINAL_OUTBOX,true)){return null;}if($entry['status']==='sending'&&!$this->outboxStale($entry,$staleAfterSeconds)){throw new \LogicException('Federation message delivery is already in progress.');}$entry['status']='sending';$entry['attempts']++;$entry['updated_at']=$this->now();$entry['last_error_code']=null;return['manifest'=>$entry['manifest'],'sealed'=>$entry['sealed']];},'federation.outbox.claimed',['message_id'=>$messageId]);
		if($wire===null){return array_replace($this->outbound($messageId),['replayed'=>true]);}$message=PanelFederationMessage::fromWire($wire);
		try{$ackWire=$this->transport->deliver($wire);$ack=PanelFederationMessage::fromWire($ackWire);if(!$ack->verify($this->keys,$this->now(),$this->localNodeId)||$ack->kind()!=='acknowledgement'||$ack->sourceNode()!==$message->targetNode()||$ack->targetNode()!==$this->localNodeId||$ack->replyTo()!==$message->id()){throw new \UnexpectedValueException('Federation acknowledgement is untrusted or targets another message.');}$payload=$ack->open($this->codec);$status=$payload['status']??null;if(($payload['message_id']??null)!==$message->id()||!in_array($status,['accepted','rejected'],true)||!is_string($payload['result_digest']??null)||preg_match('/^[a-f0-9]{64}$/D',$payload['result_digest'])!==1){throw new \UnexpectedValueException('Federation acknowledgement payload is invalid.');}}
		catch(\Throwable $exception){$this->deliveryFailed($messageId);throw$exception;}
		$this->mutate(function(array &$state)use($messageId,$ack,$status):void{$entry=&$this->outboxReference($state,$messageId);if($entry['status']!=='sending'){throw new \LogicException('Federation outbox claim was lost.');}$entry['status']=$status==='accepted'?'delivered':'rejected';$entry['acknowledgement']=$ack->jsonSerialize();$entry['updated_at']=$this->now();},'federation.outbox.acknowledged',['message_id'=>$messageId,'status'=>$status]);return$this->outbound($messageId);
	}

	/** @return array{delivered:list<array<string,mixed>>,errors:array<string,string>} */
	public function flush(PanelPolicyRequest|array $request,int $limit=25,int $staleAfterSeconds=300):array {
		$request=$this->request($request);$this->authorize($request,'federation.transport.flush');$limit=max(1,min(1000,$limit));if($staleAfterSeconds<0||$staleAfterSeconds>604800){throw new \InvalidArgumentException('Federation delivery stale threshold is invalid.');}$candidates=[];foreach($this->state()['outbox']as$id=>$entry){if(!is_array($entry)||($entry['status']!=='pending'&&($entry['status']!=='sending'||!$this->outboxStale($entry,$staleAfterSeconds)))){continue;}$candidates[$id]=$entry;}uasort($candidates,static fn(array $left,array $right):int=>[$left['updated_at'],$left['manifest']['sequence']]<=>[$right['updated_at'],$right['manifest']['sequence']]);$delivered=[];$errors=[];foreach(array_slice($candidates,0,$limit,true)as$id=>$entry){try{$delivered[]=$this->deliver((string)$id,$request,$staleAfterSeconds);}catch(\Throwable){$errors[(string)$id]='federation_delivery_failed';}}return['delivered'=>$delivered,'errors'=>$errors];
	}

	/** Receives one trusted wire message and returns an encrypted signed acknowledgement. @param array<string,mixed> $wire @return array<string,mixed> */
	public function receive(array $wire,PanelPolicyRequest|array|null $request=null):array {
		$message=PanelFederationMessage::fromWire($wire);if(!$message->verify($this->keys,$this->now(),$this->localNodeId)||$message->sourceNode()===$this->localNodeId||$message->kind()==='acknowledgement'){throw new \LogicException('Federation inbound message is expired, untrusted, or invalid for this gateway.');}
		$request=$request===null?$this->peerRequest($message):$this->request($request);$this->authorize($request,'federation.transport.receive');
		$known=$this->claimInbound($message);if(is_array($known)){return$known;}
		try{$payload=$message->open($this->codec);$result=$this->processInbound($message,$payload);return$this->acknowledge($message,'accepted',PanelOperationsGuard::digest($result),null);}
		catch(\InvalidArgumentException|\UnexpectedValueException|\OutOfBoundsException|\LogicException $exception){return$this->acknowledge($message,'rejected',PanelOperationsGuard::digest(['code'=>'invalid_payload','class'=>get_class($exception)]),'invalid_payload');}
	}

	/** @return array<string,mixed> */public function outbound(string $messageId):array {$entry=$this->state()['outbox'][$messageId]??null;if(!is_array($entry)){throw new \OutOfBoundsException('Federation outbox message does not exist.');}return$this->outboxProjection($entry);}
	/** @return list<array<string,mixed>> */public function outbox(int $limit=100):array {$limit=max(1,min(1000,$limit));$items=[];foreach($this->state()['outbox']as$entry){$items[]=$this->outboxProjection($entry);}usort($items,static fn(array $left,array $right):int=>[$right['updated_at'],$right['sequence']]<=>[$left['updated_at'],$left['sequence']]);return array_slice($items,0,$limit);}

	/** @return array<string,mixed>|null */
	private function claimInbound(PanelFederationMessage $message):?array {
		return$this->mutate(function(array &$state)use($message):?array{$existing=$state['inbox'][$message->id()]??null;if(is_array($existing)){if(!hash_equals((string)$existing['manifest']['digest'],$message->digest())){throw new \LogicException('Federation inbound message identity conflict.');}if(is_array($existing['acknowledgement']??null)){return$existing['acknowledgement'];}return null;}
			$this->prune($state,'inbox');if(count($state['inbox'])>=$this->maximumEntries){throw new \LengthException('Federation inbox retention limit is reached.');}$last=(int)($state['inbound_sequences'][$message->sourceNode()]??0);if($message->sequence()<=$last){throw new \LogicException('Federation inbound sequence replay was rejected.');}$nonceHash=hash('sha256',$message->sourceNode().'|'.$message->nonce());if(isset($state['nonces'][$nonceHash])){throw new \LogicException('Federation inbound nonce replay was rejected.');}$now=$this->now();$wire=$message->wire();$state['inbound_sequences'][$message->sourceNode()]=$message->sequence();$state['nonces'][$nonceHash]=$message->id();$state['inbox'][$message->id()]=['manifest'=>$wire['manifest'],'sealed'=>$wire['sealed'],'nonce_hash'=>$nonceHash,'status'=>'claimed','acknowledgement'=>null,'result_digest'=>null,'received_at'=>$now,'updated_at'=>$now];ksort($state['inbound_sequences'],SORT_STRING);ksort($state['nonces'],SORT_STRING);ksort($state['inbox'],SORT_STRING);return null;
		},'federation.inbox.claimed',['message_id'=>$message->id(),'source_node'=>$message->sourceNode(),'sequence'=>$message->sequence()]);
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function processInbound(PanelFederationMessage $message,array $payload):array {
		return match($message->kind()){
			'heartbeat'=>$this->processHeartbeat($message,$payload),
			'desired_state'=>$this->processDesired($payload),
			'reconcile_request'=>['action_count'=>count($this->controlPlane->reconcile()),'revision'=>$this->controlPlane->revision(),'actions_digest'=>PanelOperationsGuard::digest($this->controlPlane->reconcile())],
			default=>throw new \InvalidArgumentException('Federation inbound message kind is unsupported.'),
		};
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function processHeartbeat(PanelFederationMessage $message,array $payload):array {$manifest=$payload['node']??null;if(!is_array($manifest)){throw new \InvalidArgumentException('Federation heartbeat requires a node manifest.');}$node=PanelFederationNode::hydrate($manifest);if($node->id()!==$message->sourceNode()){throw new \LogicException('Federation heartbeat source does not match its node attestation.');}try{$current=$this->controlPlane->node($node->id());if(hash_equals($current->digest(),$node->digest())){return['node_id'=>$node->id(),'sequence'=>$node->sequence(),'revision'=>$this->controlPlane->revision(),'replayed'=>true];}}catch(\OutOfBoundsException){}$this->controlPlane->ingest($node);return['node_id'=>$node->id(),'sequence'=>$node->sequence(),'revision'=>$this->controlPlane->revision(),'replayed'=>false];}
	/** @param array<string,mixed> $payload @return array<string,mixed> */private function processDesired(array $payload):array {$desired=$payload['desired']??null;if(!is_array($desired)){throw new \InvalidArgumentException('Federation desired-state message requires a digest map.');}$this->controlPlane->desired($desired);return['state_count'=>count($desired),'revision'=>$this->controlPlane->revision()];}

	/** @return array<string,mixed> */
	private function acknowledge(PanelFederationMessage $message,string $status,string $resultDigest,?string $errorCode):array {
		return$this->mutate(function(array &$state)use($message,$status,$resultDigest,$errorCode):array{$entry=&$this->inboxReference($state,$message->id());if(is_array($entry['acknowledgement']??null)){return$entry['acknowledgement'];}$state['outbound_sequence']++;$now=$this->now();$payload=['message_id'=>$message->id(),'status'=>$status,'result_digest'=>$resultDigest,'control_revision'=>$this->controlPlane->revision(),'error_code'=>$errorCode];$ack=PanelFederationMessage::sign('acknowledgement',$this->localNodeId,$message->sourceNode(),$state['outbound_sequence'],$payload,$now,$this->after($now,$this->messageTtlSeconds),$this->currentKeyId,$this->keys[$this->currentKeyId],$this->codec,$message->id());$wire=$ack->wire();$entry['status']=$status==='accepted'?'processed':'rejected';$entry['acknowledgement']=$wire;$entry['result_digest']=$resultDigest;$entry['updated_at']=$now;return$wire;},'federation.inbox.acknowledged',['message_id'=>$message->id(),'status'=>$status]);
	}

	private function deliveryFailed(string $messageId):void {$this->mutate(function(array &$state)use($messageId):void{$entry=&$this->outboxReference($state,$messageId);if($entry['status']==='sending'){$entry['status']='pending';$entry['last_error_code']='transport_failed';$entry['updated_at']=$this->now();}},'federation.outbox.failed',['message_id'=>$messageId,'error_code'=>'transport_failed']);}

	/** @param array<string,mixed> $entry @return array<string,mixed> */
	private function outboxProjection(array $entry):array {$message=$entry['manifest'];return PanelManifestContract::stamp(['type'=>'panel_federation_outbox_message','version'=>1,'id'=>(string)$message['id'],'kind'=>(string)$message['kind'],'source_node'=>(string)$message['source_node'],'target_node'=>(string)$message['target_node'],'sequence'=>(int)$message['sequence'],'reply_to'=>$message['reply_to'],'issued_at'=>(string)$message['issued_at'],'expires_at'=>(string)$message['expires_at'],'payload_digest'=>(string)$message['payload_digest'],'payload_redacted'=>true,'status'=>(string)$entry['status'],'attempts'=>(int)$entry['attempts'],'last_error_code'=>$entry['last_error_code'],'acknowledgement_digest'=>is_array($entry['acknowledgement']??null)?(string)($entry['acknowledgement']['digest']??''):null,'created_at'=>(string)$entry['created_at'],'updated_at'=>(string)$entry['updated_at'],'replayed'=>false]);}

	/** @param array<string,mixed> $state */
	private function prune(array &$state,string $box):void {
		if(count($state[$box])<$this->maximumEntries){return;}$terminal=$box==='outbox'?self::TERMINAL_OUTBOX:['processed','rejected'];$candidates=[];foreach($state[$box]as$id=>$entry){if(is_array($entry)&&in_array($entry['status']??null,$terminal,true)){$candidates[$id]=(string)($entry['updated_at']??'');}}asort($candidates,SORT_STRING);$target=max(0,(int)floor($this->maximumEntries*.8));foreach(array_keys($candidates)as$id){if(count($state[$box])<=$target){break;}$entry=$state[$box][$id];unset($state[$box][$id]);if($box==='outbox'){foreach($state['idempotency']as$hash=>$index){if(($index['id']??null)===$id){unset($state['idempotency'][$hash]);}}}else{unset($state['nonces'][(string)($entry['nonce_hash']??'')]);}}
	}

	/** @param array<string,mixed> $entry */private function outboxStale(array $entry,int $seconds):bool {return$this->epoch((string)$entry['updated_at'])<=$this->epoch($this->now())-$seconds;}
	/** @param array<string,mixed> $state @return array<string,mixed> */private function &outboxReference(array &$state,string $id):array {if(!isset($state['outbox'][$id])||!is_array($state['outbox'][$id])){throw new \OutOfBoundsException('Federation outbox message does not exist.');}return$state['outbox'][$id];}
	/** @param array<string,mixed> $state @return array<string,mixed> */private function &inboxReference(array &$state,string $id):array {if(!isset($state['inbox'][$id])||!is_array($state['inbox'][$id])){throw new \OutOfBoundsException('Federation inbox message does not exist.');}return$state['inbox'][$id];}

	private function peerRequest(PanelFederationMessage $message):PanelPolicyRequest{return new PanelPolicyRequest('federation-'.$message->sourceNode(),'federation.transport.receive',null,'federation_node',$message->sourceNode(),'high',['federation_peer'],['federation.*'],['transport'=>'signed_encrypted','message_kind'=>$message->kind(),'message_digest'=>$message->digest()]);}
	private function request(PanelPolicyRequest|array $request):PanelPolicyRequest{return$request instanceof PanelPolicyRequest?$request:PanelPolicyRequest::from($request);}
	private function authorize(PanelPolicyRequest $request,string $ability):void {$attributes=$request->jsonSerialize();$attributes['ability']=$ability;$this->policy->evaluate(PanelPolicyRequest::from($attributes))->assertAllowed();}
	private function idempotency(PanelPolicyRequest $request,string $key):string {$key=trim($key);if($key===''||strlen($key)>512||str_contains($key,"\0")){throw new \InvalidArgumentException('Federation idempotency key is invalid.');}return hash('sha256',($request->tenantId()??'global')."\0".$request->actorId()."\0".$key);}

	/** @return array<string,mixed> */private function state():array {$state=$this->store->payload();$this->assertState($state);return$state;}
	/** @param callable(array<string,mixed>&):mixed $mutation */private function mutate(callable $mutation,string $type,array $event=[]):mixed {$transaction=$this->store->transaction(function(array &$state)use($mutation){$this->assertState($state);$result=$mutation($state);$state['revision']++;$state=$this->signedState($state);return$result;},$type,PanelOperationsGuard::safeMetadata($event,64));return$transaction['result'];}
	/** @param array<string,mixed> $state @return array<string,mixed> */private function signedState(array $state):array {unset($state['integrity']);$digest=PanelOperationsGuard::digest($state);$state['integrity']=['key_id'=>$this->currentKeyId,'digest'=>$digest,'signature'=>hash_hmac('sha256',$digest,$this->keys[$this->currentKeyId])];return$state;}

	/** @param array<string,mixed> $state */
	private function assertState(array $state):void {
		if(($state['schema']??null)!=='panel_federation_gateway'||($state['version']??null)!==1||!is_int($state['revision']??null)||$state['revision']<0||!is_int($state['outbound_sequence']??null)||$state['outbound_sequence']<0||!is_array($state['inbound_sequences']??null)||!is_array($state['outbox']??null)||!is_array($state['inbox']??null)||!is_array($state['idempotency']??null)||!is_array($state['nonces']??null)||!is_array($state['integrity']??null)||count($state['outbox'])>$this->maximumEntries||count($state['inbox'])>$this->maximumEntries){throw new \UnexpectedValueException('Federation gateway state shape is invalid.');}
		$integrity=$state['integrity'];$unsigned=$state;unset($unsigned['integrity']);$digest=PanelOperationsGuard::digest($unsigned);$key=is_string($integrity['key_id']??null)?($this->keys[$integrity['key_id']]??null):null;if(!is_string($key)||!is_string($integrity['digest']??null)||!is_string($integrity['signature']??null)||!hash_equals($digest,$integrity['digest'])||!hash_equals($integrity['signature'],hash_hmac('sha256',$digest,$key))){throw new \UnexpectedValueException('Federation gateway state signature is untrusted.');}
		foreach($state['outbox']as$id=>$entry){if(!is_string($id)||!is_array($entry)||!is_array($entry['manifest']??null)||!is_array($entry['sealed']??null)||!in_array($entry['status']??null,['pending','sending',...self::TERMINAL_OUTBOX],true)||!is_int($entry['attempts']??null)||$entry['attempts']<0||!is_string($entry['idempotency_hash']??null)||!is_string($entry['fingerprint']??null)||!is_string($entry['created_at']??null)||!is_string($entry['updated_at']??null)){throw new \UnexpectedValueException('Federation outbox entry is invalid.');}$message=PanelFederationMessage::hydrate($entry['manifest'],$entry['sealed']);if($message->id()!==$id||$message->sourceNode()!==$this->localNodeId||!in_array($message->kind(),self::OUTBOUND_KINDS,true)){throw new \UnexpectedValueException('Federation outbox message is invalid.');}}
		foreach($state['inbox']as$id=>$entry){if(!is_string($id)||!is_array($entry)||!is_array($entry['manifest']??null)||!is_array($entry['sealed']??null)||!is_string($entry['nonce_hash']??null)||!in_array($entry['status']??null,['claimed','processed','rejected'],true)||(!is_array($entry['acknowledgement']??null)&&($entry['acknowledgement']??null)!==null)||!is_string($entry['received_at']??null)||!is_string($entry['updated_at']??null)){throw new \UnexpectedValueException('Federation inbox entry is invalid.');}$message=PanelFederationMessage::hydrate($entry['manifest'],$entry['sealed']);if($message->id()!==$id||$message->targetNode()!==$this->localNodeId||$message->kind()==='acknowledgement'){throw new \UnexpectedValueException('Federation inbox message is invalid.');}if(is_array($entry['acknowledgement'])){$ack=PanelFederationMessage::fromWire($entry['acknowledgement']);if($ack->kind()!=='acknowledgement'||$ack->replyTo()!==$id||$ack->sourceNode()!==$this->localNodeId){throw new \UnexpectedValueException('Federation inbox acknowledgement is invalid.');}}}
		foreach($state['idempotency']as$hash=>$entry){if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_array($entry)||!is_string($entry['id']??null)||!isset($state['outbox'][$entry['id']])||!is_string($entry['fingerprint']??null)){throw new \UnexpectedValueException('Federation idempotency index is invalid.');}}
		foreach($state['nonces']as$hash=>$id){if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_string($id)||!isset($state['inbox'][$id])){throw new \UnexpectedValueException('Federation nonce index is invalid.');}}
	}

	public function verifyIntegrity():array {try{$state=$this->state();return['ok'=>true,'revision'=>$state['revision'],'outbox_count'=>count($state['outbox']),'inbox_count'=>count($state['inbox'])];}catch(\Throwable){return['ok'=>false,'revision'=>null,'outbox_count'=>null,'inbox_count'=>null];}}
	public function jsonSerialize():array {$state=$this->state();$out=[];$in=[];foreach($state['outbox']as$entry){$status=(string)$entry['status'];$out[$status]=($out[$status]??0)+1;}foreach($state['inbox']as$entry){$status=(string)$entry['status'];$in[$status]=($in[$status]??0)+1;}ksort($out,SORT_STRING);ksort($in,SORT_STRING);return PanelManifestContract::stamp(['type'=>'panel_federation_gateway_manifest','version'=>1,'local_node_id'=>$this->localNodeId,'revision'=>$state['revision'],'outbound_sequence'=>$state['outbound_sequence'],'transport_configured'=>$this->transportConfigured(),'transport'=>$this->transport?->jsonSerialize(),'outbox_count'=>count($state['outbox']),'outbox_statuses'=>$out,'inbox_count'=>count($state['inbox']),'inbox_statuses'=>$in,'peer_count'=>count($state['inbound_sequences']),'recent_outbox'=>$this->outbox(25),'integrity'=>$this->verifyIntegrity(),'security'=>['encrypted_payloads'=>true,'signed_messages'=>true,'signed_acknowledgements'=>true,'nonce_values_exposed'=>false,'payloads_exposed'=>false,'transport_callback_exposed'=>false],'capabilities'=>['durable_outbox'=>true,'durable_inbox'=>true,'monotonic_peer_sequences'=>true,'nonce_replay_defense'=>true,'audience_binding'=>true,'expiry'=>true,'exact_replay'=>true,'idempotent_receive'=>true,'stale_delivery_recovery'=>true,'offline_queue'=>true,'transport_adapters'=>true,'bounded_retention'=>true,'key_rotation'=>count($this->keys)>1]]);}

	private function now():string {$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Federation gateway clock must return an instant.');}return PanelOperationsGuard::instant($value);}
	private function after(string $instant,int $seconds):string{return PanelOperationsGuard::instant((new \DateTimeImmutable($instant))->modify('+'.$seconds.' seconds'));}
	private function epoch(string $instant):int {try{return(new \DateTimeImmutable(PanelOperationsGuard::instant($instant)))->getTimestamp();}catch(\Throwable){throw new \UnexpectedValueException('Federation gateway instant is invalid.');}}
}
