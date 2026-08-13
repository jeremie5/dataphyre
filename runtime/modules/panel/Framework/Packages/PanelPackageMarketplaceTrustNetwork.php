<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Durable, contiguous local projection of verified marketplace transparency events. */
final class PanelPackageMarketplaceTrustNetwork implements \JsonSerializable {
	private readonly PanelAtomicSnapshotStore $store;
	private readonly \Closure $clock;
	private readonly int $maxCheckpointAge;
	private readonly int $maxEvents;

	public function __construct(
		string $root,
		private readonly PanelPackageTransparencyVerifier $verifier,
		?callable $clock=null,
		int $maxCheckpointAgeSeconds=86400,
		int $retention=2048,
		int $maxEvents=100000
	){
		$this->clock=$clock!==null?\Closure::fromCallable($clock):static fn():int=>time();
		$this->maxCheckpointAge=max(60,min(31536000,$maxCheckpointAgeSeconds));
		$this->maxEvents=max(1,min(1000000,$maxEvents));
		$initial=[
			'schema'=>'panel_package_marketplace_trust_network','version'=>1,
			'events'=>[],'event_index'=>[],'logs'=>[],'revocations'=>[],'attestations'=>[],
			'verifier_checkpoint'=>[],
		];
		$this->store=new PanelAtomicSnapshotStore($root,'panel.package-marketplace-trust.v1',$initial,max(8,$retention));
		$state=$this->store->payload();
		$this->assertState($state);
		if(($state['verifier_checkpoint']??[])!==[]){$this->verifier->restore(['checkpoints'=>$state['verifier_checkpoint']]);}
	}

	public function verifier():PanelPackageTransparencyVerifier{return$this->verifier;}
	public function revocations():PanelPackageRevocationRegistry{return new PanelPackageRevocationRegistry($this);}
	public function publishers():PanelPackagePublisherTrustRegistry{return new PanelPackagePublisherTrustRegistry($this);}

	/** @param array<string,mixed>|PanelPackageTransparencyReceipt $receipt @return array<string,mixed> */
	public function ingest(array|PanelPackageTransparencyReceipt $receipt):array {
		$receipt=$receipt instanceof PanelPackageTransparencyReceipt?$receipt:PanelPackageTransparencyReceipt::fromArray($receipt);
		$event=$receipt->event();
		$kind=(string)$event['kind'];
		$projection=match($kind){
			'revocation'=>self::normalizeRevocationSubject($event['subject']),
			'publisher_attestation'=>self::normalizeAttestationSubject($event['subject']),
			default=>null,
		};
		$logId=$receipt->checkpoint()->logId();
		if(!in_array($logId,$this->verifier->allowedLogs(),true)){throw new \LogicException('Transparency receipt belongs to a log outside the trust network.');}
		$before=$this->verifier->checkpoint();
		try{
			$result=$this->store->transaction(function(array &$state)use($receipt,$event,$kind,$projection,$logId):array{
				$this->assertState($state);
				$eventId=(string)$event['event_id'];
				$indexKey=$logId.'|'.$eventId;
				$eventDigest=PanelOperationsGuard::digest(['log_id'=>$logId,'event'=>$event]);
				if(isset($state['event_index'][$indexKey])){
					$index=(int)$state['event_index'][$indexKey];$stored=$state['events'][$index]??null;
					if(!is_array($stored)||!hash_equals((string)($stored['event_digest']??''),$eventDigest)){throw new \LogicException('Transparency event id was reused with different public content.');}
					return['replayed'=>true,'event'=>$stored];
				}
				$processed=(int)($state['logs'][$logId]['processed_sequence']??0);
				if((int)$event['sequence']!==$processed+1){throw new \LogicException('Transparency events must be ingested contiguously for absence proofs and revocation completeness.');}
				if(count($state['events'])>=$this->maxEvents){throw new \LengthException('Marketplace trust network reached its configured event limit.');}
				if(($this->verifier)($kind,$event['subject'],$receipt->jsonSerialize())!==true){throw new \UnexpectedValueException('Marketplace transparency receipt did not verify against the trusted log state.');}
				$checkpoint=$receipt->checkpoint();
				if($checkpoint->treeSize()<(int)$event['sequence']){throw new \UnexpectedValueException('Transparency checkpoint does not cover its event sequence.');}
				$record=[
					'log_id'=>$logId,'event'=>$event,'event_digest'=>$eventDigest,
					'receipt_digest'=>PanelOperationsGuard::digest($receipt->jsonSerialize()),
					'checkpoint'=>[
						'tree_size'=>$checkpoint->treeSize(),'root_hash'=>$checkpoint->rootHash(),
						'issued_at'=>$checkpoint->issuedAt(),'digest'=>$checkpoint->digest(),
					],
				];
				$index=count($state['events']);$state['events'][]=$record;$state['event_index'][$indexKey]=$index;
				$state['logs'][$logId]=[
					'processed_sequence'=>(int)$event['sequence'],'head_tree_size'=>$checkpoint->treeSize(),
					'root_hash'=>$checkpoint->rootHash(),'issued_at'=>$checkpoint->issuedAt(),'checkpoint_digest'=>$checkpoint->digest(),
				];
				if($kind==='revocation'&&is_array($projection)){
					$id=$projection['revocation_id'];
					if(isset($state['revocations'][$id])&&!hash_equals(PanelOperationsGuard::digest($state['revocations'][$id]['subject']),PanelOperationsGuard::digest($projection))){throw new \LogicException('Revocation id was reused with a different decision.');}
					$state['revocations'][$id]=['subject'=>$projection,'log_id'=>$logId,'event_id'=>$eventId,'event_sequence'=>$event['sequence'],'published_at'=>$event['published_at'],'event_digest'=>$eventDigest];
				}
				if($kind==='publisher_attestation'&&is_array($projection)){
					$id=$projection['attestation_id'];
					if(isset($state['attestations'][$id])&&!hash_equals(PanelOperationsGuard::digest($state['attestations'][$id]['subject']),PanelOperationsGuard::digest($projection))){throw new \LogicException('Publisher attestation id was reused with different evidence.');}
					if(isset($projection['supersedes'])&&!isset($state['attestations'][$projection['supersedes']])){throw new \LogicException('Publisher attestation cannot supersede unknown evidence.');}
					$state['attestations'][$id]=['subject'=>$projection,'log_id'=>$logId,'event_id'=>$eventId,'event_sequence'=>$event['sequence'],'published_at'=>$event['published_at'],'event_digest'=>$eventDigest];
				}
				$state['verifier_checkpoint']=$this->verifier->trustedCheckpoints();
				ksort($state['logs'],SORT_STRING);ksort($state['revocations'],SORT_STRING);ksort($state['attestations'],SORT_STRING);ksort($state['event_index'],SORT_STRING);
				return['replayed'=>false,'event'=>$record];
			},'marketplace.transparency.ingested',['log_id'=>$logId,'event_id'=>(string)$event['event_id'],'kind'=>$kind])['result'];
			return$result;
		}
		catch(\Throwable $error){$this->verifier->restore($before);throw$error;}
	}

	/** @param list<array<string,mixed>|PanelPackageTransparencyReceipt> $receipts @return list<array<string,mixed>> */
	public function ingestMany(array $receipts):array{
		if(count($receipts)>10000){throw new \LengthException('Marketplace trust ingestion batch exceeds its limit.');}
		$result=[];foreach($receipts as$receipt){if(!is_array($receipt)&&!$receipt instanceof PanelPackageTransparencyReceipt){throw new \InvalidArgumentException('Marketplace trust ingestion requires transparency receipts.');}$result[]=$this->ingest($receipt);}return$result;
	}

	/** @return list<array<string,mixed>> */
	public function revocationRecords():array{return array_values($this->store->payload()['revocations']);}
	/** @return list<array<string,mixed>> */
	public function attestationRecords():array{return array_values($this->store->payload()['attestations']);}

	/** @return array<string,mixed> */
	public function health(string|int|\DateTimeInterface|null $at=null):array{
		$state=$this->store->payload();$this->assertState($state);$asOf=PanelOperationsGuard::instant($at??$this->now());$now=$this->timestamp($asOf);$logs=[];$complete=true;$stale=false;
		foreach($this->verifier->allowedLogs()as$logId){
			$log=$state['logs'][$logId]??null;$observed=is_array($log);$current=$observed&&(int)$log['processed_sequence']===(int)$log['head_tree_size'];$expired=$observed&&$this->timestamp((string)$log['issued_at'])<$now-$this->maxCheckpointAge;
			$logs[$logId]=['observed'=>$observed,'current'=>$current,'stale'=>$expired,'processed_sequence'=>$observed?(int)$log['processed_sequence']:0,'head_tree_size'=>$observed?(int)$log['head_tree_size']:0,'checkpoint_digest'=>$observed?(string)$log['checkpoint_digest']:''];
			$complete=$complete&&$observed&&$current&&!$expired;$stale=$stale||$expired;
		}
		return['complete'=>$complete,'stale'=>$stale,'as_of'=>$asOf,'logs'=>$logs,'event_count'=>count($state['events']),'revocation_count'=>count($state['revocations']),'attestation_count'=>count($state['attestations'])];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		$state=$this->store->payload();$health=$this->health();$kinds=[];foreach($state['events']as$record){$kind=(string)$record['event']['kind'];$kinds[$kind]=($kinds[$kind]??0)+1;}ksort($kinds,SORT_STRING);
		return PanelManifestContract::stamp([
			'type'=>'panel_package_marketplace_trust_network_manifest','version'=>1,
			'event_count'=>count($state['events']),'event_kinds'=>$kinds,
			'revocation_count'=>count($state['revocations']),'attestation_count'=>count($state['attestations']),
			'health'=>$health,'max_checkpoint_age_seconds'=>$this->maxCheckpointAge,'max_events'=>$this->maxEvents,
			'store'=>$this->store->manifest(),'verifier'=>$this->verifier->jsonSerialize(),
			'events_serialized'=>false,'capabilities'=>[
				'contiguous_ingestion'=>true,'absence_completeness'=>true,'durable_checkpoints'=>true,
				'split_view_detection'=>true,'revocation_projection'=>true,'publisher_evidence_projection'=>true,
			],
		]);
	}

	/** @param array<string,mixed> $subject @return array<string,mixed> */
	public static function normalizeRevocationSubject(array $subject):array{
		$allowed=['revocation_id','scope','registry','publisher','key_id','package','version','artifact_sha256','reason','effective_at','expires_at'];
		if(array_diff(array_keys($subject),$allowed)!==[]){throw new \InvalidArgumentException('Revocation event contains unsupported fields.');}
		$id=self::exactName($subject['revocation_id']??null,'revocation id');$scope=self::exactName($subject['scope']??null,'revocation scope');$reason=self::exactName($subject['reason']??null,'revocation reason');
		if(!in_array($scope,['registry','publisher','key','package','version','artifact'],true)){throw new \InvalidArgumentException('Revocation scope is unsupported.');}
		$result=['revocation_id'=>$id,'scope'=>$scope];
		foreach(['registry','publisher','package']as$field){if(array_key_exists($field,$subject)){$result[$field]=self::exactName($subject[$field],$field);}}
		if(array_key_exists('key_id',$subject)){$value=PanelOperationsGuard::identifier((string)$subject['key_id'],'revoked key id',256);if($value!==$subject['key_id']){throw new \InvalidArgumentException('Revoked key id must be canonical.');}$result['key_id']=$value;}
		if(array_key_exists('version',$subject)){if(!is_string($subject['version'])||!PanelPackageManifest::validVersion($subject['version'])){throw new \InvalidArgumentException('Revoked package version is invalid.');}$result['version']=$subject['version'];}
		if(array_key_exists('artifact_sha256',$subject)){$result['artifact_sha256']=self::sha256($subject['artifact_sha256'],'revoked artifact digest');}
		$required=match($scope){'registry'=>'registry','publisher'=>'publisher','key'=>'key_id','package','version'=>'package','artifact'=>'artifact_sha256'};
		if(!isset($result[$required])||($scope==='version'&&!isset($result['version']))){throw new \InvalidArgumentException('Revocation scope is missing its exact target.');}
		$result['reason']=$reason;$result['effective_at']=self::exactInstant($subject['effective_at']??null,'revocation effective time');
		if(array_key_exists('expires_at',$subject)&&$subject['expires_at']!==null){$result['expires_at']=self::exactInstant($subject['expires_at'],'revocation expiry');if(self::time($result['expires_at'])<=self::time($result['effective_at'])){throw new \InvalidArgumentException('Revocation expiry must follow its effective time.');}}
		if(PanelOperationsGuard::json($result)!==PanelOperationsGuard::json($subject)){throw new \InvalidArgumentException('Revocation subject must be canonical and omit null fields.');}
		return$result;
	}

	/** @param array<string,mixed> $subject @return array<string,mixed> */
	public static function normalizeAttestationSubject(array $subject):array{
		$allowed=['attestation_id','publisher','issuer','category','signal','evidence_hash','issued_at','valid_until','package','version','supersedes'];
		if(array_diff(array_keys($subject),$allowed)!==[]){throw new \InvalidArgumentException('Publisher attestation contains unsupported fields.');}
		$result=[
			'attestation_id'=>self::exactName($subject['attestation_id']??null,'attestation id'),
			'publisher'=>self::exactName($subject['publisher']??null,'publisher'),
			'issuer'=>self::exactName($subject['issuer']??null,'attestation issuer'),
			'category'=>self::exactName($subject['category']??null,'attestation category'),
			'signal'=>self::exactName($subject['signal']??null,'attestation signal'),
			'evidence_hash'=>self::sha256($subject['evidence_hash']??null,'attestation evidence hash'),
			'issued_at'=>self::exactInstant($subject['issued_at']??null,'attestation issue time'),
			'valid_until'=>self::exactInstant($subject['valid_until']??null,'attestation validity time'),
		];
		if(!in_array($result['signal'],['verified','warning','failed','withdrawn'],true)){throw new \InvalidArgumentException('Publisher attestation signal is unsupported.');}
		if(self::time($result['valid_until'])<=self::time($result['issued_at'])){throw new \InvalidArgumentException('Publisher attestation validity must follow its issue time.');}
		if(array_key_exists('package',$subject)){$result['package']=self::exactName($subject['package'],'attested package');}
		if(array_key_exists('version',$subject)){if(!isset($result['package'])||!is_string($subject['version'])||!PanelPackageManifest::validVersion($subject['version'])){throw new \InvalidArgumentException('Attested package version is invalid.');}$result['version']=$subject['version'];}
		if(array_key_exists('supersedes',$subject)){$result['supersedes']=self::exactName($subject['supersedes'],'superseded attestation id');if($result['supersedes']===$result['attestation_id']){throw new \InvalidArgumentException('Publisher attestation cannot supersede itself.');}}
		if($result['signal']==='withdrawn'&&!isset($result['supersedes'])){throw new \InvalidArgumentException('A withdrawal must reference the evidence it supersedes.');}
		if(PanelOperationsGuard::json($result)!==PanelOperationsGuard::json($subject)){throw new \InvalidArgumentException('Publisher attestation subject must be canonical.');}
		return$result;
	}

	/** @param array<string,mixed> $state */
	private function assertState(array $state):void{
		if(($state['schema']??null)!=='panel_package_marketplace_trust_network'||($state['version']??null)!==1||!is_array($state['events']??null)||!array_is_list($state['events'])||!is_array($state['event_index']??null)||!is_array($state['logs']??null)||!is_array($state['revocations']??null)||!is_array($state['attestations']??null)||!is_array($state['verifier_checkpoint']??null)){
			throw new \UnexpectedValueException('Marketplace trust network state is invalid.');
		}
		$sequences=[];
		foreach($state['events']as$index=>$record){
			if(!is_array($record)||!is_array($record['event']??null)||!is_array($record['checkpoint']??null)){throw new \UnexpectedValueException('Marketplace trust network event state is invalid.');}
			$logId=PanelOperationsGuard::name((string)($record['log_id']??''),'transparency log id');$event=$record['event'];$key=$logId.'|'.(string)($event['event_id']??'');
			$sequences[$logId]=($sequences[$logId]??0)+1;
			if(($event['sequence']??null)!==$sequences[$logId]||($state['event_index'][$key]??null)!==$index||!hash_equals((string)($record['event_digest']??''),PanelOperationsGuard::digest(['log_id'=>$logId,'event'=>$event]))){throw new \UnexpectedValueException('Marketplace trust network event chain does not verify.');}
		}
		foreach($state['logs']as$logId=>$log){if(!is_array($log)||(int)($log['processed_sequence']??0)!==($sequences[$logId]??0)||(int)($log['head_tree_size']??0)<(int)$log['processed_sequence']){throw new \UnexpectedValueException('Marketplace trust network log cursor is invalid.');}}
	}

	private function now():string{
		$value=($this->clock)();if(!$value instanceof \DateTimeInterface&&!is_string($value)&&!is_int($value)){throw new \UnexpectedValueException('Marketplace trust clock must return an instant.');}return PanelOperationsGuard::instant($value);
	}
	private function timestamp(string|int|\DateTimeInterface $value):int{return(new \DateTimeImmutable(PanelOperationsGuard::instant($value)))->getTimestamp();}
	private static function time(string $instant):int{return(new \DateTimeImmutable($instant))->getTimestamp();}
	private static function exactName(mixed $value,string $label):string{if(!is_string($value))throw new \InvalidArgumentException(ucfirst($label).' must be a string.');$name=PanelOperationsGuard::name($value,$label);if($name!==$value)throw new \InvalidArgumentException(ucfirst($label).' must be canonical.');return$name;}
	private static function exactInstant(mixed $value,string $label):string{if(!is_string($value))throw new \InvalidArgumentException(ucfirst($label).' must be a string.');$instant=PanelOperationsGuard::instant($value,$label);if($instant!==$value)throw new \InvalidArgumentException(ucfirst($label).' must be canonical UTC.');return$instant;}
	private static function sha256(mixed $value,string $label):string{if(!is_string($value)||preg_match('/^[a-f0-9]{64}$/D',$value)!==1)throw new \InvalidArgumentException(ucfirst($label).' must be a lowercase SHA-256 digest.');return$value;}
}
