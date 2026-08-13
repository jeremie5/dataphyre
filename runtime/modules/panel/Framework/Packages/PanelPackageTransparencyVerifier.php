<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Stateful split-view detector and first-party registry transparency hook. */
final class PanelPackageTransparencyVerifier implements \JsonSerializable {
	private readonly \Closure $signatureVerifier;
	private readonly \Closure $clock;
	/** @var array<string,bool> */
	private array $allowedLogs=[];
	/** @var list<string> */
	private array $allowedWitnesses=[];
	/** @var array<string,array{tree_size:int,root_hash:string,issued_at:string,digest:string}> */
	private array $trusted=[];
	private readonly int $requiredWitnesses;
	private readonly bool $allowTrustOnFirstUse;
	private readonly bool $requireConsistency;
	private readonly int $maxCheckpointAge;
	private readonly int $clockSkew;

	/**
	 * @param list<string> $allowedLogs
	 * @param array<string,array<string,mixed>> $trustedCheckpoints
	 * @param array<string,mixed> $options
	 */
	public function __construct(callable $signatureVerifier,array $allowedLogs,array $trustedCheckpoints=[],array $options=[]){
		$this->signatureVerifier=\Closure::fromCallable($signatureVerifier);
		$this->clock=is_callable($options['clock']??null)?\Closure::fromCallable($options['clock']):static fn():int=>time();
		foreach($allowedLogs as$id){$this->allowedLogs[PanelOperationsGuard::name((string)$id,'transparency log id')]=true;}
		if($this->allowedLogs===[]){throw new \InvalidArgumentException('Transparency verifier requires at least one allowed log.');}
		$this->allowedWitnesses=PanelOperationsGuard::identifiers(is_array($options['allowed_witnesses']??null)?$options['allowed_witnesses']:[],'transparency witness id',256,64);
		$this->requiredWitnesses=max(0,min(32,(int)($options['required_witnesses']??0)));
		if($this->requiredWitnesses>count($this->allowedWitnesses)&&$this->allowedWitnesses!==[]){throw new \InvalidArgumentException('Transparency witness quorum exceeds the witness allowlist.');}
		$this->allowTrustOnFirstUse=($options['allow_trust_on_first_use']??false)===true;
		$this->requireConsistency=($options['require_consistency']??true)!==false;
		$this->maxCheckpointAge=max(60,min(31536000,(int)($options['max_checkpoint_age_seconds']??86400)));
		$this->clockSkew=max(0,min(3600,(int)($options['clock_skew_seconds']??300)));
		$this->restore(['checkpoints'=>$trustedCheckpoints]);
	}

	public function __invoke(string $kind,array $subject,array $proof):bool {
		try{
			$kind=match($kind){
				'index'=>'registry_index',
				'package','artifact'=>(($subject['yanked']??false)===true?'package_yank':'package_release'),
				default=>$kind,
			};
			$receipt=PanelPackageTransparencyReceipt::fromArray($proof);
			$checkpoint=$receipt->checkpoint();
			$issued=$this->timestamp($checkpoint->issuedAt());
			$now=$this->now();
			if($issued>$now+$this->clockSkew
				||$issued<$now-$this->maxCheckpointAge-$this->clockSkew
				||!isset($this->allowedLogs[$checkpoint->logId()])
				||!$receipt->verify($kind,$subject,$this->signatureVerifier,$this->requiredWitnesses,$this->allowedWitnesses)){
				return false;
			}
			$previous=$this->trusted[$checkpoint->logId()]??null;
			if(!is_array($previous)){
				if(!$this->allowTrustOnFirstUse){return false;}
			}
			elseif($issued<$this->timestamp($previous['issued_at'])||$checkpoint->treeSize()<$previous['tree_size']){
				return false;
			}
			elseif($checkpoint->treeSize()===$previous['tree_size']){
				if(!hash_equals($previous['root_hash'],$checkpoint->rootHash())){return false;}
			}
			else{
				$consistency=$receipt->consistency();
				if($this->requireConsistency&&(!$consistency instanceof PanelPackageTransparencyConsistencyProof
					||$consistency->oldSize()!==$previous['tree_size']
					||!hash_equals($consistency->oldRoot(),$previous['root_hash'])
					||$consistency->newSize()!==$checkpoint->treeSize()
					||!hash_equals($consistency->newRoot(),$checkpoint->rootHash())
					||!$consistency->verify())){
					return false;
				}
			}
			$this->trusted[$checkpoint->logId()]=[
				'tree_size'=>$checkpoint->treeSize(),
				'root_hash'=>$checkpoint->rootHash(),
				'issued_at'=>$checkpoint->issuedAt(),
				'digest'=>$checkpoint->digest(),
			];
			ksort($this->trusted,SORT_STRING);
			return true;
		}
		catch(\Throwable){return false;}
	}

	/** @return list<string> */
	public function allowedLogs():array{return array_keys($this->allowedLogs);}
	/** @return array<string,array{tree_size:int,root_hash:string,issued_at:string,digest:string}> */
	public function trustedCheckpoints():array{return$this->trusted;}
	/** @return array<string,mixed> */
	public function checkpoint():array{return['type'=>'panel_package_transparency_verifier_checkpoint','version'=>1,'checkpoints'=>$this->trusted,'digest'=>PanelOperationsGuard::digest($this->trusted)];}

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint):self {
		$items=$checkpoint['checkpoints']??$checkpoint;
		if(!is_array($items)){throw new \InvalidArgumentException('Transparency verifier checkpoint is invalid.');}
		$restored=[];
		foreach($items as$logId=>$item){
			$logId=PanelOperationsGuard::name((string)$logId,'transparency log id');
			if(!isset($this->allowedLogs[$logId])||!is_array($item)){throw new \InvalidArgumentException('Transparency verifier checkpoint contains an unknown log.');}
			$size=(int)($item['tree_size']??0);
			$root=(string)($item['root_hash']??'');
			$issued=PanelOperationsGuard::instant($item['issued_at']??'','transparency checkpoint issued at');
			$digest=(string)($item['digest']??'');
			if($size<1||preg_match('/^[a-f0-9]{64}$/D',$root)!==1||($digest!==''&&preg_match('/^[a-f0-9]{64}$/D',$digest)!==1)){
				throw new \InvalidArgumentException('Transparency verifier checkpoint entry is invalid.');
			}
			$restored[$logId]=['tree_size'=>$size,'root_hash'=>$root,'issued_at'=>$issued,'digest'=>$digest];
		}
		$this->trusted=$restored;
		ksort($this->trusted,SORT_STRING);
		return$this;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array{
		return PanelManifestContract::stamp([
			'type'=>'panel_package_transparency_verifier_manifest','version'=>1,
			'allowed_logs'=>array_keys($this->allowedLogs),'trusted_checkpoints'=>$this->trusted,
			'required_witnesses'=>$this->requiredWitnesses,'allowed_witnesses'=>$this->allowedWitnesses,
			'allow_trust_on_first_use'=>$this->allowTrustOnFirstUse,'require_consistency'=>$this->requireConsistency,
			'max_checkpoint_age_seconds'=>$this->maxCheckpointAge,'clock_skew_seconds'=>$this->clockSkew,
			'trusted_clock'=>true,'signature_verifier_serialized'=>false,'clock_serialized'=>false,
		]);
	}

	private function now():int{
		$value=($this->clock)();
		if($value instanceof \DateTimeInterface){return$value->getTimestamp();}
		if(is_int($value)){return$value;}
		if(is_string($value)&&trim($value)!==''){return$this->timestamp(PanelOperationsGuard::instant($value));}
		throw new \UnexpectedValueException('Transparency verifier clock must return an instant.');
	}
	private function timestamp(string $instant):int{return(new \DateTimeImmutable($instant))->getTimestamp();}
}
