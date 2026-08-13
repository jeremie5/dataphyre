<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Public, input-redacted state of one governed intelligence command proposal. */
final class PanelIntelligenceProposal implements \JsonSerializable {
	public const STATUSES=['awaiting_approval','ready','dispatching','succeeded','failed','denied','rejected'];
	/** @var list<string> */private readonly array $approverHashes;
	/** @var array<string,mixed>|null */private readonly ?array $receipt;
	/** @var array<string,mixed>|null */private readonly ?array $rejection;
	private readonly string $approvalTarget;

	/** @param list<string> $approverHashes @param array<string,mixed>|null $receipt @param array<string,mixed>|null $rejection */
	private function __construct(
		private readonly string $id,private readonly string $signalId,private readonly string $signalDigest,
		private readonly string $tenantId,private readonly string $proposerHash,private readonly string $command,
		private readonly string $ability,private readonly string $inputDigest,private readonly string $risk,
		private readonly string $reason,private readonly int $requiredApprovals,private readonly string $status,
		array $approverHashes,private readonly int $revision,private readonly int $dispatchAttempts,
		private readonly int $feedbackCount,private readonly string $createdAt,private readonly string $updatedAt,
		?array $receipt=null,?array $rejection=null,?string $approvalTarget=null,
	){
		PanelOperationsGuard::identifier($id,'intelligence proposal id',190);PanelOperationsGuard::identifier($signalId,'intelligence proposal signal id',190);
		foreach([$signalDigest,$proposerHash,$inputDigest]as$digest){if(preg_match('/^[a-f0-9]{64}$/D',$digest)!==1){throw new \InvalidArgumentException('Intelligence proposal digest is invalid.');}}
		PanelOperationsGuard::identifier($tenantId,'intelligence proposal tenant');PanelOperationsGuard::name($command,'intelligence proposal command',160);PanelOperationsGuard::abilityPatterns([$ability],'intelligence proposal ability');
		if(!in_array($risk,['low','medium','high','critical'],true)||$requiredApprovals<0||$requiredApprovals>16||!in_array($status,self::STATUSES,true)||$revision<1||$dispatchAttempts<0||$feedbackCount<0){throw new \InvalidArgumentException('Intelligence proposal state is invalid.');}
		PanelOperationsGuard::label($reason,'intelligence proposal reason',2048);
		$hashes=[];foreach($approverHashes as$hash){if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw new \InvalidArgumentException('Intelligence proposal approver hash is invalid.');}$hashes[$hash]=true;}if(count($hashes)>32){throw new \LengthException('Intelligence proposal exceeds its approver limit.');}$normalized=array_keys($hashes);sort($normalized,SORT_STRING);$this->approverHashes=$normalized;
		if(PanelOperationsGuard::instant($createdAt)!==$createdAt||PanelOperationsGuard::instant($updatedAt)!==$updatedAt||$updatedAt<$createdAt){throw new \InvalidArgumentException('Intelligence proposal timestamps are invalid.');}
		$this->receipt=$receipt===null?null:PanelOperationsGuard::safeMetadata($receipt,128);$this->rejection=$rejection===null?null:PanelOperationsGuard::safeMetadata($rejection,32);
		if(in_array($status,['succeeded','failed','denied'],true)&&$this->receipt===null){throw new \InvalidArgumentException('Terminal intelligence proposal is missing its command receipt summary.');}
		if($status==='rejected'&&$this->rejection===null){throw new \InvalidArgumentException('Rejected intelligence proposal is missing rejection evidence.');}
		$computed=PanelOperationsGuard::digest($this->immutableContract());if($approvalTarget!==null&&!hash_equals($computed,$approvalTarget)){throw new \UnexpectedValueException('Intelligence proposal approval target is invalid.');}$this->approvalTarget=$computed;
	}

	public static function create(string $id,PanelIntelligenceSignal $signal,string $proposerHash,string $command,string $ability,string $inputDigest,string $risk,string $reason,int $requiredApprovals,string $createdAt):self {
		return new self($id,$signal->id(),$signal->digest(),$signal->tenantId(),$proposerHash,$command,$ability,$inputDigest,$risk,$reason,$requiredApprovals,$requiredApprovals>0?'awaiting_approval':'ready',[],1,0,0,$createdAt,$createdAt);
	}

	/** @param array<string,mixed> $payload */
	public static function hydrate(array $payload):self {
		$required=['type','schema_version','api_version','version','id','signal_id','signal_digest','tenant_id','proposer_hash','command','ability','input_digest','input_redacted','risk','reason','required_approvals','status','approval_count','approver_hashes','revision','dispatch_attempts','feedback_count','created_at','updated_at','receipt','rejection','approval_target'];$keys=array_keys($payload);sort($keys,SORT_STRING);sort($required,SORT_STRING);
		if($keys!==$required||($payload['type']??null)!=='panel_intelligence_proposal'||($payload['version']??null)!==1||!is_string($payload['id']??null)||!is_string($payload['signal_id']??null)||!is_string($payload['signal_digest']??null)||!is_string($payload['tenant_id']??null)||!is_string($payload['proposer_hash']??null)||!is_string($payload['command']??null)||!is_string($payload['ability']??null)||!is_string($payload['input_digest']??null)||($payload['input_redacted']??null)!==true||!is_string($payload['risk']??null)||!is_string($payload['reason']??null)||!is_int($payload['required_approvals']??null)||!is_string($payload['status']??null)||!is_int($payload['approval_count']??null)||!is_array($payload['approver_hashes']??null)||!is_int($payload['revision']??null)||!is_int($payload['dispatch_attempts']??null)||!is_int($payload['feedback_count']??null)||!is_string($payload['created_at']??null)||!is_string($payload['updated_at']??null)||(!is_array($payload['receipt'])&&$payload['receipt']!==null)||(!is_array($payload['rejection'])&&$payload['rejection']!==null)||!is_string($payload['approval_target']??null)){throw new \UnexpectedValueException('Stored intelligence proposal shape is invalid.');}
		$self=new self($payload['id'],$payload['signal_id'],$payload['signal_digest'],$payload['tenant_id'],$payload['proposer_hash'],$payload['command'],$payload['ability'],$payload['input_digest'],$payload['risk'],$payload['reason'],$payload['required_approvals'],$payload['status'],$payload['approver_hashes'],$payload['revision'],$payload['dispatch_attempts'],$payload['feedback_count'],$payload['created_at'],$payload['updated_at'],$payload['receipt'],$payload['rejection'],$payload['approval_target']);if($payload['approval_count']!==$self->approvalCount()){throw new \UnexpectedValueException('Stored intelligence proposal approval count is invalid.');}return$self;
	}

	/** @param list<string> $approverHashes @param array<string,mixed>|null $receipt @param array<string,mixed>|null $rejection */
	public function evolve(string $status,array $approverHashes,int $revision,int $dispatchAttempts,int $feedbackCount,string $updatedAt,?array $receipt=null,?array $rejection=null):self {
		return new self($this->id,$this->signalId,$this->signalDigest,$this->tenantId,$this->proposerHash,$this->command,$this->ability,$this->inputDigest,$this->risk,$this->reason,$this->requiredApprovals,$status,$approverHashes,$revision,$dispatchAttempts,$feedbackCount,$this->createdAt,PanelOperationsGuard::instant($updatedAt),$receipt,$rejection,$this->approvalTarget);
	}

	public function id():string{return$this->id;}public function signalId():string{return$this->signalId;}public function signalDigest():string{return$this->signalDigest;}public function tenantId():string{return$this->tenantId;}public function proposerHash():string{return$this->proposerHash;}public function command():string{return$this->command;}public function ability():string{return$this->ability;}public function inputDigest():string{return$this->inputDigest;}public function risk():string{return$this->risk;}public function reason():string{return$this->reason;}public function requiredApprovals():int{return$this->requiredApprovals;}public function status():string{return$this->status;}public function approvalCount():int{return count($this->approverHashes);}/** @return list<string> */public function approverHashes():array{return$this->approverHashes;}public function revision():int{return$this->revision;}public function dispatchAttempts():int{return$this->dispatchAttempts;}public function feedbackCount():int{return$this->feedbackCount;}public function createdAt():string{return$this->createdAt;}public function updatedAt():string{return$this->updatedAt;}public function approvalTarget():string{return$this->approvalTarget;}public function terminal():bool{return in_array($this->status,['succeeded','failed','denied','rejected'],true);}/** @return array<string,mixed>|null */public function receipt():?array{return$this->receipt;}/** @return array<string,mixed>|null */public function rejection():?array{return$this->rejection;}
	public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_intelligence_proposal','version'=>1]+$this->immutableContract()+['input_redacted'=>true,'status'=>$this->status,'approval_count'=>$this->approvalCount(),'approver_hashes'=>$this->approverHashes,'revision'=>$this->revision,'dispatch_attempts'=>$this->dispatchAttempts,'feedback_count'=>$this->feedbackCount,'updated_at'=>$this->updatedAt,'receipt'=>$this->receipt,'rejection'=>$this->rejection,'approval_target'=>$this->approvalTarget]);}
	/** @return array<string,mixed> */private function immutableContract():array{return['id'=>$this->id,'signal_id'=>$this->signalId,'signal_digest'=>$this->signalDigest,'tenant_id'=>$this->tenantId,'proposer_hash'=>$this->proposerHash,'command'=>$this->command,'ability'=>$this->ability,'input_digest'=>$this->inputDigest,'risk'=>$this->risk,'reason'=>$this->reason,'required_approvals'=>$this->requiredApprovals,'created_at'=>$this->createdAt];}
}
