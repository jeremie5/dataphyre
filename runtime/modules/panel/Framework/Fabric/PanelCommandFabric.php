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
 * Policy-gated command journal and signed event outbox.
 *
 * The fabric atomically commits its own journal, receipts, and outbox. Native
 * handlers remain responsible for their storage transactions and idempotency;
 * no cross-database exactly-once or ACID guarantee is claimed.
 */
final class PanelCommandFabric implements \JsonSerializable {
	/** @var array<string,string> */
	private array $keys;
	private readonly PanelCommandObligationVerifier $obligations;
	private readonly string $subscriberWorker;
	private readonly int $subscriberLeaseTtlSeconds;
	private ?\Closure $clock;
	/** @var array<string,array{patterns:list<string>,handler:\Closure}> */
	private array $subscribers=[];

	/** @param array<string,string> $signingKeys */
	public function __construct(
		private readonly PanelCommandRegistry $registry,
		private readonly PanelCommandFabricStore $store,
		private readonly PanelPolicyControlPlane $policy,
		private readonly PanelCommandPayloadCodec $codec,
		array $signingKeys,
		private readonly string $currentKeyId,
		?PanelCommandObligationVerifier $obligations=null,
		?callable $clock=null,
		?string $subscriberWorker=null,
		int $subscriberLeaseTtlSeconds=60,
	){
		$keys=[];
		foreach($signingKeys as $id=>$key){
			$id=PanelOperationsGuard::name((string)$id,'command fabric signing key id');
			if(!is_string($key)||strlen($key)<32){
				throw new \InvalidArgumentException('Command fabric signing keys require at least 32 bytes.');
			}
			$keys[$id]=$key;
		}
		if(!isset($keys[$currentKeyId])){
			throw new \InvalidArgumentException('The current command fabric signing key is unavailable.');
		}
		$this->keys=$keys;
		$this->obligations=$obligations??new PanelStrictCommandObligationVerifier();
		$this->clock=$clock!==null?\Closure::fromCallable($clock):null;
		$this->subscriberWorker=PanelOperationsGuard::identifier($subscriberWorker??'fabric-'.bin2hex(random_bytes(12)),'command fabric subscriber worker',190);
		if($subscriberLeaseTtlSeconds<5||$subscriberLeaseTtlSeconds>3600){
			throw new \InvalidArgumentException('Command fabric subscriber lease TTL is invalid.');
		}
		$this->subscriberLeaseTtlSeconds=$subscriberLeaseTtlSeconds;
		$this->verifyIntegrity();
	}

	public function registry():PanelCommandRegistry{return $this->registry;}
	public function store():PanelCommandFabricStore{return $this->store;}
	public function policy():PanelPolicyControlPlane{return $this->policy;}
	public function obligationVerifier():PanelCommandObligationVerifier{return $this->obligations;}
	/** Returns the exact policy decision dispatch will enforce for this immutable command. */
	public function decisionFor(PanelCommandEnvelope $command):PanelPolicyDecision{return $this->decision($command);}

	/** Executes or replays one tenant-scoped command. */
	public function dispatch(PanelCommandEnvelope $command):PanelCommandReceipt {
		if(($receipt=$this->existingReceipt($command)) instanceof PanelCommandReceipt){return $receipt;}
		$decision=$this->decision($command);
		$obligations=$decision->allowed()
			?$this->obligations->verify($command,$decision)
			:new PanelCommandObligationResult(false,$decision->reasons(),['policy_revision'=>$decision->revision()]);
		if(($receipt=$this->claim($command)) instanceof PanelCommandReceipt){return $receipt;}
		if(!$decision->allowed()){
			return $this->finalize(
				$command,'denied',null,$this->safeError($decision->reasons()[0]??'Policy denied the command.'),[],
				$this->policyMetadata($decision,$obligations)+['error_code'=>'policy_denied'],
			);
		}
		if(!$obligations->satisfied()){
			return $this->finalize(
				$command,'denied',null,$this->safeError($obligations->reasons()[0]??'Policy obligations are not satisfied.'),[],
				$this->policyMetadata($decision,$obligations)+['error_code'=>'obligation_unsatisfied'],
			);
		}
		return $this->executeClaimed($command,$decision,$obligations);
	}

	/**
	 * Reclaims one stale executing journal entry after a crash.
	 * Native handlers receive the original idempotency key and must tolerate replay.
	 */
	public function resume(string $idempotencyHash,int $staleAfterSeconds=300):PanelCommandReceipt {
		$this->assertHash($idempotencyHash,'command fabric idempotency hash');
		if($staleAfterSeconds<0||$staleAfterSeconds>604800){
			throw new \InvalidArgumentException('Command fabric stale threshold is invalid.');
		}
		$now=$this->now();
		$transaction=$this->store->transaction(function(array &$state)use($idempotencyHash,$staleAfterSeconds,$now):array {
			if(isset($state['receipts'][$idempotencyHash])){
				return ['receipt'=>$state['receipts'][$idempotencyHash]];
			}
			$entry=$state['commands'][$idempotencyHash]??null;
			if(!is_array($entry)||($entry['status']??null)!=='executing'){
				throw new \OutOfBoundsException('No executing command journal entry can be resumed.');
			}
			if(!$this->stale((string)$entry['updated_at'],$now,$staleAfterSeconds)){
				throw new \LogicException('The executing command is not stale enough to resume.');
			}
			$entry['attempts']=(int)$entry['attempts']+1;
			$entry['updated_at']=$now;
			$state['commands'][$idempotencyHash]=$entry;
			$state['revision']++;
			return ['entry'=>$entry];
		},'command_resumed',['idempotency_hash'=>$idempotencyHash]);
		$result=$transaction['result'];
		if(is_array($result['receipt']??null)){
			return $this->verifiedReceipt($result['receipt'])->asReplay();
		}
		$entry=$result['entry']??null;
		if(!is_array($entry)){throw new \UnexpectedValueException('Command resume transaction returned an invalid result.');}
		$command=$this->commandFromEntry($idempotencyHash,$entry);
		$decision=$this->decision($command);
		$obligations=$decision->allowed()
			?$this->obligations->verify($command,$decision)
			:new PanelCommandObligationResult(false,$decision->reasons(),['policy_revision'=>$decision->revision()]);
		if(!$decision->allowed()){
			return $this->finalize($command,'denied',null,$this->safeError($decision->reasons()[0]??'Policy denied the resumed command.'),[],$this->policyMetadata($decision,$obligations)+['error_code'=>'policy_denied_on_resume']);
		}
		if(!$obligations->satisfied()){
			return $this->finalize($command,'denied',null,$this->safeError($obligations->reasons()[0]??'Policy obligations are not satisfied.'),[],$this->policyMetadata($decision,$obligations)+['error_code'=>'obligation_unsatisfied_on_resume']);
		}
		return $this->executeClaimed($command,$decision,$obligations);
	}

	/** @return array{resumed:list<PanelCommandReceipt>,errors:array<string,string>} */
	public function recoverStale(int $staleAfterSeconds=300,int $limit=100):array {
		$limit=max(1,min(1000,$limit));
		$state=$this->store->payload();
		$entries=[];
		foreach($state['commands'] as $hash=>$entry){
			if(is_array($entry)&&($entry['status']??null)==='executing'){$entries[(string)$hash]=$entry;}
		}
		uasort($entries,static fn(array $a,array $b):int=>strcmp((string)$a['updated_at'],(string)$b['updated_at']));
		$resumed=[];$errors=[];
		foreach(array_slice($entries,0,$limit,true) as $hash=>$entry){
			if(!$this->stale((string)$entry['updated_at'],$this->now(),$staleAfterSeconds)){continue;}
			try{$resumed[]=$this->resume((string)$hash,$staleAfterSeconds);}
			catch(\Throwable $error){$errors[(string)$hash]=$this->safeError($error instanceof PanelCommandExecutionException?$error->getMessage():'Command recovery failed.');}
		}
		return ['resumed'=>$resumed,'errors'=>$errors];
	}

	/** Registers a process-local projector with a durable cursor in the fabric store. */
	public function subscribe(string $name,string|array $patterns,callable $subscriber):self {
		$name=PanelOperationsGuard::name($name,'command fabric subscriber',128);
		if(isset($this->subscribers[$name])){throw new \LogicException("Command fabric subscriber '{$name}' is already registered.");}
		$patterns=is_array($patterns)?$patterns:[$patterns];
		$normalized=[];
		foreach($patterns as $pattern){$normalized[$this->eventPattern((string)$pattern)]=true;}
		if($normalized===[]){throw new \InvalidArgumentException('A command fabric subscriber requires at least one event pattern.');}
		$this->subscribers[$name]=['patterns'=>array_keys($normalized),'handler'=>\Closure::fromCallable($subscriber)];
		return $this;
	}

	public function unsubscribe(string $name):self {
		$name=PanelOperationsGuard::name($name,'command fabric subscriber',128);
		unset($this->subscribers[$name]);
		return $this;
	}

	/**
	 * Delivers events at least once. A cursor advances only after the subscriber
	 * returns successfully; a crash between projection and cursor commit replays.
	 *
	 * @return array<string,mixed>
	 */
	public function drainSubscriber(string $name,int $limit=100):array {
		$name=PanelOperationsGuard::name($name,'command fabric subscriber',128);
		$subscriber=$this->subscribers[$name]??null;
		if(!is_array($subscriber)){throw new \OutOfBoundsException("Command fabric subscriber '{$name}' is not registered.");}
		$limit=max(1,min(1000,$limit));
		$state=$this->store->payload();
		$cursor=(int)($state['subscriber_cursors'][$name]??0);
		if(!$this->store instanceof PanelLeasedCommandFabricStore){
			return$this->drainOwnedSubscriber($name,$subscriber,$cursor,$limit,null);
		}

		$lease=$this->store->acquireSubscriberLease($name,$this->subscriberWorker,$this->subscriberLeaseTtlSeconds);
		if(!$lease instanceof PanelCommandFabricSubscriberLease){
			return[
				'subscriber'=>$name,'ok'=>true,'cursor'=>$cursor,'processed'=>0,'skipped'=>0,'retry_sequence'=>null,'error'=>null,
				'error_code'=>null,'busy'=>true,'lease'=>['required'=>true,'acquired'=>false,'fence'=>null],
			];
		}

		$response=null;$pending=null;
		try{
			$response=$this->drainOwnedSubscriber($name,$subscriber,$cursor,$limit,$lease);
		}catch(PanelCommandFabricLeaseLost){
			$response=$this->subscriberFailure($name,$cursor,0,0,null,'lease_lost','Subscriber ownership was lost before delivery completed.',$lease);
		}catch(\Throwable $error){
			$pending=$error;
		}
		try{
			$this->store->releaseSubscriberLease($lease);
		}catch(PanelCommandFabricLeaseLost|PanelCommandFabricStorageException){
			if($pending===null&&($response['ok']??false)===true){
				$response['ok']=false;
				$response['error']='Subscriber ownership could not be released safely.';
				$response['error_code']='lease_release_failed';
			}
		}
		if($pending instanceof \Throwable){throw$pending;}
		if(!is_array($response)){throw new \UnexpectedValueException('Subscriber delivery did not produce a result.');}
		return$response;
	}

	/**
	 * @param array{patterns:list<string>,handler:\Closure} $subscriber
	 * @return array<string,mixed>
	 */
	private function drainOwnedSubscriber(string $name,array $subscriber,int $cursor,int $limit,?PanelCommandFabricSubscriberLease $lease):array {
		$events=$this->events($cursor,$limit);
		$processed=0;$skipped=0;
		foreach($events as $event){
			if($lease instanceof PanelCommandFabricSubscriberLease){
				$lease=$this->store instanceof PanelLeasedCommandFabricStore
					?$this->store->renewSubscriberLease($lease,$this->subscriberLeaseTtlSeconds)
					:$lease;
			}
			$matched=$this->eventMatches($subscriber['patterns'],$event->eventType());
			if($matched){
				try{$accepted=($subscriber['handler'])($event,$this);}
				catch(\Throwable $error){
					return$this->subscriberFailure($name,$cursor,$processed,$skipped,$event->sequence(),'projection_failed','Subscriber projection failed.',$lease);
				}
				if($accepted===false){
					return$this->subscriberFailure($name,$cursor,$processed,$skipped,$event->sequence(),'retry_requested','Subscriber requested a retry.',$lease);
				}
				$processed++;
			}else{$skipped++;}
			try{$this->advanceSubscriber($name,$event->sequence(),$lease);}
			catch(PanelCommandFabricLeaseLost){
				return$this->subscriberFailure($name,$cursor,$processed,$skipped,$event->sequence(),'lease_lost','Subscriber ownership was lost before its cursor could advance.',$lease);
			}
			$cursor=$event->sequence();
		}
		$result=['subscriber'=>$name,'ok'=>true,'cursor'=>$cursor,'processed'=>$processed,'skipped'=>$skipped,'retry_sequence'=>null,'error'=>null];
		if($lease instanceof PanelCommandFabricSubscriberLease){
			$result+=['error_code'=>null,'busy'=>false,'lease'=>['required'=>true,'acquired'=>true,'fence'=>$lease->fence()]];
		}
		return$result;
	}

	/** @return list<PanelEventEnvelope> */
	public function events(int $afterSequence=0,int $limit=100,?string $tenantId=null,?string $pattern=null):array {
		if($afterSequence<0){throw new \InvalidArgumentException('Fabric event cursor cannot be negative.');}
		$limit=max(1,min(1000,$limit));
		if($tenantId!==null){PanelOperationsGuard::identifier($tenantId,'fabric event tenant');}
		if($pattern!==null){$pattern=$this->eventPattern($pattern);}
		$state=$this->store->payload();
		$result=[];
		foreach($state['events'] as $payload){
			if(!is_array($payload)){continue;}
			$event=$this->verifiedEvent($payload);
			if($event->sequence()<=$afterSequence||($tenantId!==null&&!hash_equals($tenantId,$event->tenantId()))||($pattern!==null&&!$this->eventMatches([$pattern],$event->eventType()))){continue;}
			$result[]=$event;
			if(count($result)>=$limit){break;}
		}
		return $result;
	}

	/** Verifies every journal command, signed receipt, and hash-chain link. */
	public function verifyIntegrity():array {
		$state=$this->store->payload();
		$fingerprints=[];
		foreach($state['commands'] as $hash=>$entry){
			if(!is_array($entry)){throw new \UnexpectedValueException('Command fabric journal entry is invalid.');}
			$command=$this->commandFromEntry((string)$hash,$entry);
			$fingerprints[$command->fingerprint()]=true;
			$receiptPayload=$state['receipts'][$hash]??null;
			if(($entry['status']??null)==='executing'){
				if($receiptPayload!==null){throw new \UnexpectedValueException('Executing command unexpectedly has a terminal receipt.');}
				continue;
			}
			if(!is_array($receiptPayload)){throw new \UnexpectedValueException('Terminal command is missing its receipt.');}
			$receipt=$this->verifiedReceipt($receiptPayload);
			if(
				!hash_equals($receipt->commandFingerprint(),$command->fingerprint())
				||!hash_equals($receipt->idempotencyHash(),(string)$hash)
				||$receipt->status()!==$entry['status']
			){throw new \UnexpectedValueException('Command fabric receipt does not match its journal entry.');}
		}
		foreach($state['receipts'] as $hash=>$payload){
			if(!isset($state['commands'][$hash])||!is_array($payload)){throw new \UnexpectedValueException('Command fabric contains an orphan receipt.');}
			$this->verifiedReceipt($payload);
		}
		$previous=str_repeat('0',64);$sequence=0;$eventIds=[];
		foreach($state['events'] as $id=>$payload){
			if(!is_array($payload)){throw new \UnexpectedValueException('Command fabric event is invalid.');}
			$event=$this->verifiedEvent($payload);
			$sequence++;
			if($event->sequence()!==$sequence||!hash_equals($previous,$event->previousHash())||!isset($fingerprints[$event->commandFingerprint()])){
				throw new \UnexpectedValueException('Command fabric event chain is invalid.');
			}
			$eventIds[(string)$id]=$event->commandFingerprint();
			$previous=$event->hash();
		}
		if($sequence!==$state['sequence']||!hash_equals($previous,$state['anchor_hash'])){
			throw new \UnexpectedValueException('Command fabric anchor hash is invalid.');
		}
		foreach($state['receipts'] as $payload){
			$receipt=$this->verifiedReceipt($payload);
			foreach($receipt->eventIds() as $eventId){
				if(!isset($eventIds[$eventId])||!hash_equals($eventIds[$eventId],$receipt->commandFingerprint())){
					throw new \UnexpectedValueException('Command receipt references an invalid event.');
				}
			}
		}
		return [
			'ok'=>true,'revision'=>$state['revision'],'sequence'=>$sequence,'commands'=>count($state['commands']),
			'receipts'=>count($state['receipts']),'events'=>count($state['events']),'anchor_hash'=>$state['anchor_hash'],
		];
	}

	/** @return array<string,mixed> */
	public function changesSince(int $cursor=0,int $limit=100):array{return $this->store->changesSince($cursor,$limit);}

	public function jsonSerialize():array {
		$integrity=$this->verifyIntegrity();
		$state=$this->store->payload();
		$subscribers=[];
		foreach($this->subscribers as $name=>$subscriber){
			$subscribers[$name]=['patterns'=>$subscriber['patterns'],'cursor'=>(int)($state['subscriber_cursors'][$name]??0)];
		}
		ksort($subscribers,SORT_STRING);
		$executing=count(array_filter($state['commands'],static fn(mixed $entry):bool=>is_array($entry)&&($entry['status']??null)==='executing'));
		return PanelManifestContract::stamp([
			'type'=>'panel_command_fabric_manifest','version'=>1,'revision'=>$state['revision'],'sequence'=>$state['sequence'],
			'commands'=>count($state['commands']),'receipts'=>count($state['receipts']),'events'=>count($state['events']),'executing'=>$executing,
			'integrity'=>$integrity,'registry'=>$this->registry,'store'=>$this->store,'policy'=>$this->policy,'payload_codec'=>$this->codec,
				'obligation_verifier'=>$this->obligations,'current_signing_key_id'=>$this->currentKeyId,'trusted_signing_key_ids'=>array_keys($this->keys),
				'signing_keys_exposed'=>false,'raw_payloads_exposed'=>false,'subscriber_worker_exposed'=>false,'subscriber_lease_ttl_seconds'=>$this->subscriberLeaseTtlSeconds,'subscribers'=>$subscribers,
				'guarantees'=>[
					'tenant_scoped_idempotency'=>true,'encrypted_command_payloads'=>true,'signed_receipts'=>true,
					'tamper_evident_event_chain'=>true,'atomic_journal_and_outbox'=>true,'subscriber_delivery'=>'at_least_once',
					'fenced_subscriber_ownership'=>$this->store instanceof PanelLeasedCommandFabricStore,
					'atomic_fenced_subscriber_cursor'=>$this->store instanceof PanelLeasedCommandFabricStore,
					'external_handler_delivery'=>'at_least_once','cross_database_acid'=>false,'distributed_exactly_once'=>false,
				],
		]);
	}

	private function executeClaimed(PanelCommandEnvelope $command,PanelPolicyDecision $decision,PanelCommandObligationResult $obligations):PanelCommandReceipt {
		try{
			$route=$this->registry->resolve($command->command());
			$outcome=($route['handler'])($command);
			if(!$outcome instanceof PanelCommandOutcome){
				throw new \UnexpectedValueException('A command handler returned an invalid outcome.');
			}
			$metadata=array_replace($this->policyMetadata($decision,$obligations),[
				'handler_pattern'=>$route['pattern'],'handler_contributor'=>$route['contributor'],'handler_priority'=>$route['priority'],
			],$outcome->metadata());
		}catch(PanelCommandExecutionException $error){
			return $this->finalize($command,'failed',null,$this->safeError($error->getMessage()),[],$this->policyMetadata($decision,$obligations)+['error_code'=>$error->errorCode()]);
		}catch(\OutOfBoundsException $error){
			return $this->finalize($command,'failed',null,'No command handler is registered.',[],$this->policyMetadata($decision,$obligations)+['error_code'=>'handler_not_found']);
		}catch(\Throwable $error){
			return $this->finalize($command,'failed',null,'Command execution failed.',[],$this->policyMetadata($decision,$obligations)+['error_code'=>'handler_exception','exception_type'=>get_debug_type($error)]);
		}
		return $this->finalize($command,'succeeded',$outcome->result(),null,$outcome->events(),$metadata);
	}

	private function existingReceipt(PanelCommandEnvelope $command):?PanelCommandReceipt {
		$state=$this->store->payload();
		$hash=$command->idempotencyHash();
		if(isset($state['receipts'][$hash])){
			$receipt=$this->verifiedReceipt($state['receipts'][$hash]);
			if(!hash_equals($receipt->commandFingerprint(),$command->fingerprint())){
				throw new \LogicException('The idempotency key was already used with different command input.');
			}
			return $receipt->asReplay();
		}
		$entry=$state['commands'][$hash]??null;
		if(is_array($entry)&&!hash_equals((string)($entry['fingerprint']??''),$command->fingerprint())){
			throw new \LogicException('The idempotency key was already used with different command input.');
		}
		return null;
	}

	private function claim(PanelCommandEnvelope $command):?PanelCommandReceipt {
		$hash=$command->idempotencyHash();
		$sealed=$this->codec->seal($command->sealedPayload(),$this->payloadContext($hash,$command->fingerprint()));
		$now=$this->now();
		$transaction=$this->store->transaction(function(array &$state)use($command,$hash,$sealed,$now):array {
			if(isset($state['receipts'][$hash])){return ['receipt'=>$state['receipts'][$hash]];}
			$existing=$state['commands'][$hash]??null;
			if(is_array($existing)){
				if(!hash_equals((string)($existing['fingerprint']??''),$command->fingerprint())){
					throw new \LogicException('The idempotency key was already used with different command input.');
				}
				if(($existing['status']??null)==='executing'){
					throw new \LogicException('The idempotent command is already executing.');
				}
				throw new \UnexpectedValueException('A terminal command journal entry is missing its receipt.');
			}
			$state['commands'][$hash]=[
				'fingerprint'=>$command->fingerprint(),'status'=>'executing','envelope'=>$command->jsonSerialize(),
				'sealed'=>$sealed,'attempts'=>1,'updated_at'=>$now,
			];
			$state['revision']++;
			return ['receipt'=>null];
		},'command_claimed',['idempotency_hash'=>$hash,'command'=>$command->command(),'tenant_id'=>$command->tenantId()]);
		$payload=$transaction['result']['receipt']??null;
		if(!is_array($payload)){return null;}
		$receipt=$this->verifiedReceipt($payload);
		if(!hash_equals($receipt->commandFingerprint(),$command->fingerprint())){
			throw new \LogicException('The idempotency key was already used with different command input.');
		}
		return $receipt->asReplay();
	}

	/** @param list<PanelEventDraft> $drafts @param array<string,mixed> $metadata */
	private function finalize(PanelCommandEnvelope $command,string $status,mixed $result,?string $error,array $drafts,array $metadata):PanelCommandReceipt {
		if(!in_array($status,['succeeded','failed','denied'],true)){throw new \InvalidArgumentException('Command final status is invalid.');}
		if($status!=='succeeded'&&$drafts!==[]){throw new \LogicException('Only successful commands may append domain events.');}
		foreach($drafts as $draft){if(!$draft instanceof PanelEventDraft){throw new \InvalidArgumentException('Command event drafts are invalid.');}}
		$hash=$command->idempotencyHash();
		$completedAt=$this->now();
		$key=$this->keys[$this->currentKeyId];
		$metadata=PanelOperationsGuard::safeMetadata($metadata,512);
		$transaction=$this->store->transaction(function(array &$state)use($command,$status,$result,$error,$drafts,$metadata,$hash,$completedAt,$key):array {
			if(isset($state['receipts'][$hash])){return $state['receipts'][$hash];}
			$entry=$state['commands'][$hash]??null;
			if(!is_array($entry)||($entry['status']??null)!=='executing'||!hash_equals((string)($entry['fingerprint']??''),$command->fingerprint())){
				throw new \UnexpectedValueException('Command completion does not match an executing journal entry.');
			}
			$eventIds=[];$previous=(string)$state['anchor_hash'];$sequence=(int)$state['sequence'];
			foreach($drafts as $draft){
				$event=PanelEventEnvelope::sign(++$sequence,$draft,$command,$previous,$completedAt,$this->currentKeyId,$key);
				if(isset($state['events'][$event->id()])){throw new \UnexpectedValueException('Command fabric event id collision detected.');}
				$state['events'][$event->id()]=$event->jsonSerialize();
				$eventIds[]=$event->id();
				$previous=$event->hash();
			}
			$receipt=PanelCommandReceipt::sign($status,$command,$result,$error,$eventIds,$metadata,$completedAt,$this->currentKeyId,$key);
			$state['sequence']=$sequence;
			$state['anchor_hash']=$previous;
			$entry['status']=$status;
			$entry['updated_at']=$completedAt;
			$state['commands'][$hash]=$entry;
			$state['receipts'][$hash]=$receipt->jsonSerialize();
			$state['revision']++;
			return $receipt->jsonSerialize();
		},'command_'.$status,['idempotency_hash'=>$hash,'command'=>$command->command(),'tenant_id'=>$command->tenantId(),'event_count'=>count($drafts)]);
		$payload=$transaction['result'];
		if(!is_array($payload)){throw new \UnexpectedValueException('Command completion returned an invalid receipt.');}
		$receipt=$this->verifiedReceipt($payload);
		if(!hash_equals($receipt->commandFingerprint(),$command->fingerprint())){
			throw new \UnexpectedValueException('Command completion replay does not match the command.');
		}
		return $receipt;
	}

	private function decision(PanelCommandEnvelope $command):PanelPolicyDecision {
		$metadata=$command->metadata();
		$resource=is_array($metadata['resource']??null)?$metadata['resource']:[];
		$resourceType=is_string($resource['type']??null)?$resource['type']:null;
		$resourceId=is_string($resource['id']??null)?$resource['id']:null;
		if(($resourceType===null)!==($resourceId===null)){$resourceType=$resourceId=null;}
		return $this->policy->evaluate(new PanelPolicyRequest(
			$command->actorId(),$command->ability(),$command->tenantId(),$resourceType,$resourceId,$command->risk(),
			$command->roles(),$command->permissions(),[
				'command'=>$command->command(),'command_fingerprint'=>$command->fingerprint(),
				'correlation_id'=>$command->correlationId(),'causation_id'=>$command->causationId(),
				'expected_revision'=>$command->expectedRevision(),'metadata'=>$metadata,
			],$command->createdAt(),
		));
	}

	/** @return array<string,mixed> */
	private function policyMetadata(PanelPolicyDecision $decision,PanelCommandObligationResult $obligations):array {
		return PanelOperationsGuard::safeMetadata([
			'policy_revision'=>$decision->revision(),'policy_matched_rules'=>$decision->matchedRules(),
			'policy_obligations'=>$decision->obligations(),'obligations_satisfied'=>$obligations->satisfied(),
			'obligation_evidence'=>$obligations->evidence(),
		],512);
	}

	/** @param array<string,mixed> $entry */
	private function commandFromEntry(string $hash,array $entry):PanelCommandEnvelope {
		$this->assertHash($hash,'command fabric idempotency hash');
		$fingerprint=$entry['fingerprint']??null;
		if(!is_string($fingerprint)){throw new \UnexpectedValueException('Stored command fingerprint is invalid.');}
		$this->assertHash($fingerprint,'stored command fingerprint');
		if(!is_array($entry['envelope']??null)||!is_array($entry['sealed']??null)){
			throw new \UnexpectedValueException('Stored command entry is incomplete.');
		}
		$sealed=$this->codec->open($entry['sealed'],$this->payloadContext($hash,$fingerprint));
		$command=PanelCommandEnvelope::hydrate($entry['envelope'],$sealed);
		if(!hash_equals($hash,$command->idempotencyHash())||!hash_equals($fingerprint,$command->fingerprint())){
			throw new \UnexpectedValueException('Stored command entry integrity check failed.');
		}
		return $command;
	}

	/** @param array<string,mixed> $payload */
	private function verifiedReceipt(array $payload):PanelCommandReceipt {
		$receipt=PanelCommandReceipt::hydrate($payload);
		if(!$receipt->verify($this->keys)){throw new \UnexpectedValueException('Command receipt signature is untrusted.');}
		return $receipt;
	}

	/** @param array<string,mixed> $payload */
	private function verifiedEvent(array $payload):PanelEventEnvelope {
		$event=PanelEventEnvelope::hydrate($payload);
		if(!$event->verify($this->keys)){throw new \UnexpectedValueException('Command fabric event signature is untrusted.');}
		return $event;
	}

	private function advanceSubscriber(string $name,int $sequence,?PanelCommandFabricSubscriberLease $lease=null):void {
		if($lease instanceof PanelCommandFabricSubscriberLease){
			if(!$this->store instanceof PanelLeasedCommandFabricStore){throw new \LogicException('A subscriber lease requires a leased command-fabric store.');}
			$this->store->advanceSubscriberCursor($lease,$sequence);
			return;
		}
		$this->store->transaction(function(array &$state)use($name,$sequence):null {
			$current=(int)($state['subscriber_cursors'][$name]??0);
			if($sequence>$state['sequence']){throw new \UnexpectedValueException('Subscriber cursor exceeds the event sequence.');}
			if($sequence>$current){$state['subscriber_cursors'][$name]=$sequence;$state['revision']++;}
			return null;
		},'subscriber_advanced',['subscriber'=>$name,'event_sequence'=>$sequence]);
	}

	/** @return array<string,mixed> */
	private function subscriberFailure(string $name,int $cursor,int $processed,int $skipped,?int $retrySequence,string $code,string $error,?PanelCommandFabricSubscriberLease $lease):array {
		$result=['subscriber'=>$name,'ok'=>false,'cursor'=>$cursor,'processed'=>$processed,'skipped'=>$skipped,'retry_sequence'=>$retrySequence,'error'=>$this->safeError($error)];
		if($lease instanceof PanelCommandFabricSubscriberLease){
			$result+=['error_code'=>$code,'busy'=>false,'lease'=>['required'=>true,'acquired'=>true,'fence'=>$lease->fence()]];
		}
		return$result;
	}

	/** @param list<string> $patterns */
	private function eventMatches(array $patterns,string $eventType):bool {
		foreach($patterns as $pattern){
			if($pattern==='*'||hash_equals($pattern,$eventType)||(str_ends_with($pattern,'.*')&&str_starts_with($eventType,substr($pattern,0,-1)))){return true;}
		}
		return false;
	}

	private function eventPattern(string $pattern):string {
		$pattern=strtolower(trim($pattern));
		if($pattern!=='*'&&preg_match('/^[a-z][a-z0-9_.-]*(?:\.\*)?$/D',$pattern)!==1){
			throw new \InvalidArgumentException('Command fabric event pattern is invalid.');
		}
		return $pattern;
	}

	private function payloadContext(string $hash,string $fingerprint):string{return 'fabric.'.$hash.'.'.$fingerprint;}

	private function now():string {
		$value=$this->clock!==null?($this->clock)():gmdate('c');
		if(!is_string($value)&&!is_int($value)&&!$value instanceof \DateTimeInterface){
			throw new \UnexpectedValueException('Command fabric clock returned an invalid instant.');
		}
		return PanelOperationsGuard::instant($value);
	}

	private function stale(string $updatedAt,string $now,int $seconds):bool {
		$updated=new \DateTimeImmutable($updatedAt);
		$current=new \DateTimeImmutable($now);
		return $updated->getTimestamp()<=$current->getTimestamp()-$seconds;
	}

	private function assertHash(string $hash,string $label):void {
		if(preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw new \InvalidArgumentException(ucfirst($label).' is invalid.');}
	}

	private function safeError(string $error):string {
		$safe=PanelSensitiveDataSanitizer::sanitize($error,['max_depth'=>2,'max_items'=>4,'max_string_bytes'=>2048]);
		return is_string($safe)&&trim($safe)!==''?$safe:'Command execution was refused.';
	}
}
