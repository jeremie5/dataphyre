<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Trust, anti-replay, durable desired state, topology, drift, and quorum for Panel fleets. */
final class PanelFederationControlPlane implements \JsonSerializable {
	/** @var array<string,string> */private array $keys=[];
	/** @var array<string,PanelFederationNode> */private array $nodes=[];
	/** @var array<string,string> */private array $desired=[];
	private int $revision=0;
	private readonly \Closure $clock;
	private readonly string $currentKeyId;
	private readonly ?PanelAtomicSnapshotStore $store;

	/** @param array<string,string> $trustedKeys */
	public function __construct(array $trustedKeys,?callable $clock=null,?string $root=null,?string $currentKeyId=null,int $retention=2048) {
		if($trustedKeys===[]){throw new \InvalidArgumentException('Federation requires a trust keyring.');}
		foreach($trustedKeys as$id=>$key){$id=PanelOperationsGuard::name((string)$id,'federation key id');if(!is_string($key)||strlen($key)<32){throw new \InvalidArgumentException('Federation trust keys require at least 32 bytes.');}$this->keys[$id]=$key;}
		$this->currentKeyId=PanelOperationsGuard::name($currentKeyId??(string)array_key_first($this->keys),'current federation key id');if(!isset($this->keys[$this->currentKeyId])){throw new \InvalidArgumentException('Current federation key is not trusted.');}
		$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():string=>gmdate('c');
		if($root!==null){$initial=$this->signedState(0,[],[]);$this->store=new PanelAtomicSnapshotStore($root,'panel.federation-control.v1',$initial,max(8,$retention));$this->load($this->store->payload());}else{$this->store=null;}
	}

	public function ingest(PanelFederationNode $node):self {
		if(!$node->verify($this->keys,$this->now())){throw new \LogicException('Federation node attestation is expired or untrusted.');}$current=$this->nodes[$node->id()]??null;if($current!==null&&$node->sequence()<=$current->sequence()){throw new \LogicException('Federation node attestation replay was rejected.');}
		$nodes=$this->nodes;$nodes[$node->id()]=$node;ksort($nodes,SORT_STRING);$this->commit($this->revision+1,$nodes,$this->desired,'federation.node.ingested',['node_id'=>$node->id(),'sequence'=>$node->sequence()]);return$this;
	}

	/** @param array<string,string> $digests */
	public function desired(array $digests):self {
		$normalized=[];foreach($digests as$name=>$digest){$name=PanelOperationsGuard::name((string)$name,'federation desired-state name');if(!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \InvalidArgumentException('Federation desired-state digest is invalid.');}$normalized[$name]=$digest;}ksort($normalized,SORT_STRING);if($normalized!==$this->desired){$this->commit($this->revision+1,$this->nodes,$normalized,'federation.desired.changed',['state_names'=>array_keys($normalized)]);}return$this;
	}

	/** @return list<array<string,mixed>> */
	public function reconcile():array {
		$actions=[];$now=$this->now();foreach($this->nodes as$node){$online=$node->verify($this->keys,$now);foreach($this->desired as$name=>$digest){$actual=$node->stateDigests()[$name]??null;if(!$online||$actual!==$digest){$actions[]=['node_id'=>$node->id(),'state'=>$name,'desired_digest'=>$digest,'actual_digest'=>$actual,'action'=>$online?'converge':'wait_for_heartbeat','online'=>$online];}}}usort($actions,static fn(array $a,array $b):int=>[$a['node_id'],$a['state']]<=>[$b['node_id'],$b['state']]);return$actions;
	}

	/** @return array<string,mixed> */
	public function quorum(string $capability,int $minimum):array {
		$capability=PanelOperationsGuard::name($capability,'federation capability');$minimum=max(1,$minimum);$now=$this->now();$eligible=[];foreach($this->nodes as$node){if($node->verify($this->keys,$now)&&in_array($capability,$node->capabilities(),true)){$eligible[]=$node->id();}}return['capability'=>$capability,'minimum'=>$minimum,'eligible'=>$eligible,'count'=>count($eligible),'met'=>count($eligible)>=$minimum];
	}

	public function node(string $nodeId):PanelFederationNode {$nodeId=PanelOperationsGuard::name($nodeId,'federation node id');if(!isset($this->nodes[$nodeId])){throw new \OutOfBoundsException('Federation node is not registered.');}return$this->nodes[$nodeId];}
	/** @return array<string,PanelFederationNode> */public function nodes():array{return$this->nodes;}
	/** @return array<string,string> */public function desiredState():array{return$this->desired;}
	public function revision():int{return$this->revision;}
	public function durable():bool{return$this->store instanceof PanelAtomicSnapshotStore;}
	public function store():?PanelAtomicSnapshotStore{return$this->store;}

	/** @return array<string,mixed> */
	public function checkpoint():array {
		$payload=PanelManifestContract::stamp(['type'=>'panel_federation_checkpoint','version'=>2,'revision'=>$this->revision,'desired'=>$this->desired,'nodes'=>array_map(static fn(PanelFederationNode $node):array=>$node->jsonSerialize(),$this->nodes),'key_id'=>$this->currentKeyId]);$fingerprint=PanelOperationsGuard::digest($payload);return$payload+['fingerprint'=>$fingerprint,'signature'=>hash_hmac('sha256',$fingerprint,$this->keys[$this->currentKeyId])];
	}

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self {
		$signature=$checkpoint['signature']??null;$fingerprint=$checkpoint['fingerprint']??null;$payload=$checkpoint;unset($payload['signature'],$payload['fingerprint']);$keyId=$payload['key_id']??null;$key=is_string($keyId)?($this->keys[$keyId]??null):null;
		if(!is_string($signature)||!is_string($fingerprint)||!is_string($key)||!hash_equals($fingerprint,PanelOperationsGuard::digest($payload))||!hash_equals($signature,hash_hmac('sha256',$fingerprint,$key))||($payload['type']??null)!=='panel_federation_checkpoint'||($payload['version']??null)!==2||($payload['schema_version']??null)!==PanelManifestContract::SCHEMA_VERSION||($payload['api_version']??null)!==PanelManifestContract::API_VERSION||!is_int($payload['revision']??null)||$payload['revision']<0||!is_array($payload['desired']??null)||!is_array($payload['nodes']??null)){throw new \UnexpectedValueException('Federation checkpoint is invalid or untrusted.');}
		$desired=$this->normalizeDesired($payload['desired']);$nodes=[];foreach($payload['nodes']as$id=>$nodePayload){if(!is_string($id)||!is_array($nodePayload)){throw new \UnexpectedValueException('Federation checkpoint node is invalid.');}$node=PanelFederationNode::hydrate($nodePayload);if($node->id()!==$id||!$node->verifyStored($this->keys)){throw new \UnexpectedValueException('Federation checkpoint node is untrusted.');}$nodes[$id]=$node;}ksort($nodes,SORT_STRING);
		$this->commit($payload['revision'],$nodes,$desired,'federation.checkpoint.restored',['checkpoint_revision'=>$payload['revision']],true);return$this;
	}

	/** @return array<string,mixed> */
	public function verifyIntegrity():array {
		try{if($this->store instanceof PanelAtomicSnapshotStore){$this->validateState($this->store->payload());}foreach($this->nodes as$node){if(!$node->verifyStored($this->keys)){throw new \UnexpectedValueException('Federation node signature is untrusted.');}}return['ok'=>true,'revision'=>$this->revision,'node_count'=>count($this->nodes)];}catch(\Throwable){return['ok'=>false,'revision'=>null,'node_count'=>null];}
	}

	public function jsonSerialize():array {
		return PanelManifestContract::stamp(['type'=>'panel_federation_control_plane_manifest','version'=>2,'revision'=>$this->revision,'node_count'=>count($this->nodes),'desired_state'=>$this->desired,'nodes'=>array_map(static fn(PanelFederationNode $node):array=>$node->jsonSerialize(),$this->nodes),'drift_count'=>count($this->reconcile()),'trusted_key_ids'=>array_keys($this->keys),'integrity'=>$this->verifyIntegrity(),'durable'=>$this->durable(),'capabilities'=>['signed_attestations'=>true,'anti_replay'=>true,'expiry'=>true,'desired_state'=>true,'drift_reconciliation'=>true,'capability_quorum'=>true,'multi_region'=>true,'multi_environment'=>true,'signed_checkpoints'=>true,'atomic_persistence'=>$this->durable(),'restart_recovery'=>$this->durable(),'metadata_redaction'=>true,'key_rotation'=>count($this->keys)>1]]);
	}

	/** @param array<string,PanelFederationNode> $nodes @param array<string,string> $desired */
	private function commit(int $revision,array $nodes,array $desired,string $type,array $event=[],bool $allowRevisionRestore=false):void {
		if($revision<0||(!$allowRevisionRestore&&$revision!==$this->revision+1)){throw new \LogicException('Federation revision transition is invalid.');}$payload=$this->signedState($revision,$nodes,$desired);
		if($this->store instanceof PanelAtomicSnapshotStore){$this->store->transaction(function(array &$state)use($payload):void{$this->validateState($state);$state=$payload;},$type,PanelOperationsGuard::safeMetadata($event,64));}
		$this->revision=$revision;$this->nodes=$nodes;$this->desired=$desired;
	}

	/** @param array<string,PanelFederationNode> $nodes @param array<string,string> $desired @return array<string,mixed> */
	private function signedState(int $revision,array $nodes,array $desired):array {$state=['schema'=>'panel_federation_control_plane_state','version'=>1,'revision'=>$revision,'desired'=>$desired,'nodes'=>array_map(static fn(PanelFederationNode $node):array=>$node->jsonSerialize(),$nodes)];$digest=PanelOperationsGuard::digest($state);$state['integrity']=['key_id'=>$this->currentKeyId,'digest'=>$digest,'signature'=>hash_hmac('sha256',$digest,$this->keys[$this->currentKeyId])];return$state;}

	/** @param array<string,mixed> $state */
	private function load(array $state):void {$this->validateState($state);$nodes=[];foreach($state['nodes']as$id=>$payload){$nodes[$id]=PanelFederationNode::hydrate($payload);}ksort($nodes,SORT_STRING);$this->revision=$state['revision'];$this->nodes=$nodes;$this->desired=$state['desired'];}

	/** @param array<string,mixed> $state */
	private function validateState(array $state):void {
		if(($state['schema']??null)!=='panel_federation_control_plane_state'||($state['version']??null)!==1||!is_int($state['revision']??null)||$state['revision']<0||!is_array($state['desired']??null)||!is_array($state['nodes']??null)||!is_array($state['integrity']??null)){throw new \UnexpectedValueException('Federation persistent state is invalid.');}$this->normalizeDesired($state['desired']);$integrity=$state['integrity'];$unsigned=$state;unset($unsigned['integrity']);$digest=PanelOperationsGuard::digest($unsigned);$key=is_string($integrity['key_id']??null)?($this->keys[$integrity['key_id']]??null):null;if(!is_string($key)||!is_string($integrity['digest']??null)||!is_string($integrity['signature']??null)||!hash_equals($digest,$integrity['digest'])||!hash_equals($integrity['signature'],hash_hmac('sha256',$digest,$key))){throw new \UnexpectedValueException('Federation persistent state signature is untrusted.');}
		foreach($state['nodes']as$id=>$payload){if(!is_string($id)||!is_array($payload)){throw new \UnexpectedValueException('Federation persistent node is invalid.');}$node=PanelFederationNode::hydrate($payload);if($node->id()!==$id||!$node->verifyStored($this->keys)){throw new \UnexpectedValueException('Federation persistent node is untrusted.');}}
	}

	/** @param array<string,mixed> $digests @return array<string,string> */
	private function normalizeDesired(array $digests):array {$normalized=[];foreach($digests as$name=>$digest){$name=PanelOperationsGuard::name((string)$name,'federation desired-state name');if(!is_string($digest)||preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \UnexpectedValueException('Federation desired state is invalid.');}$normalized[$name]=$digest;}ksort($normalized,SORT_STRING);return$normalized;}

	private function now():string {$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Federation clock must return an instant.');}return PanelOperationsGuard::instant($value);}
}
