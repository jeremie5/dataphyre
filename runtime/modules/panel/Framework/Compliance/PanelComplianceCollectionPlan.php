<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable execution plan pinned to exact framework and collector fingerprints. */
final class PanelComplianceCollectionPlan implements \JsonSerializable {
	private readonly string $generatedAt;
	private readonly string $deadlineAt;
	private readonly string $subject;
	private readonly string $catalogFingerprint;
	private readonly string $registryFingerprint;
	/** @var array<string,array<string,mixed>> */ private readonly array $entries;
	/** @var array<string,array<string,mixed>> */ private readonly array $inputs;
	/** @var array<string,mixed> */ private readonly array $metadata;
	private readonly string $fingerprint;

	/** @param array<string,array<string,mixed>> $entries @param array<string,array<string,mixed>> $inputs @param array<string,mixed> $metadata */
	private function __construct(string $generatedAt,string $deadlineAt,string $subject,string $catalogFingerprint,string $registryFingerprint,array $entries,array $inputs,array $metadata){
		$this->generatedAt=PanelOperationsGuard::instant($generatedAt,'compliance plan generated at');$this->deadlineAt=PanelOperationsGuard::instant($deadlineAt,'compliance plan deadline');
		if(strcmp($this->deadlineAt,$this->generatedAt)<0){throw new \InvalidArgumentException('Compliance collection plan deadline must not precede generation.');}
		$this->subject=PanelOperationsGuard::identifier($subject,'compliance plan subject',160);
		foreach([$catalogFingerprint,$registryFingerprint]as$fingerprint){if(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){throw new \InvalidArgumentException('Compliance plan dependency fingerprint is invalid.');}}
		$this->catalogFingerprint=$catalogFingerprint;$this->registryFingerprint=$registryFingerprint;
		if($entries===[]||count($entries)>2000){throw new \LengthException('Compliance collection plans require between one and 2000 controls.');}$this->entries=$entries;$this->inputs=$inputs;$this->metadata=PanelOperationsGuard::safeMetadata($metadata,128);$this->fingerprint=PanelOperationsGuard::digest($this->payload());
	}

	/** @param list<string> $frameworkIds @param array<string,mixed> $bindings @param array<string,mixed> $options */
	public static function build(PanelComplianceFrameworkCatalog $catalog,PanelComplianceCollectorRegistry $registry,array $frameworkIds,array $bindings=[],array $options=[]):self {
		$frameworkIds=PanelOperationsGuard::names($frameworkIds,'compliance framework id',96,64);if($frameworkIds===[]){throw new \InvalidArgumentException('Compliance collection plans require at least one framework.');}
		if($bindings!==[]&&array_is_list($bindings)){throw new \InvalidArgumentException('Compliance collector bindings must be an object-like map.');}
		$generatedAt=PanelOperationsGuard::instant($options['generated_at']??gmdate('c'),'compliance plan generated at');
		$deadlineAt=isset($options['deadline_at'])?PanelOperationsGuard::instant($options['deadline_at'],'compliance plan deadline'):(new \DateTimeImmutable($generatedAt))->modify('+15 minutes')->format('Y-m-d\TH:i:s.u\Z');
		$subject=PanelOperationsGuard::identifier((string)($options['subject']??'global'),'compliance plan subject',160);
		$globalInput=is_array($options['input']??null)?PanelOperationsGuard::safeMetadata($options['input'],256):[];
		$inputsOption=is_array($options['inputs']??null)?$options['inputs']:[];if($inputsOption!==[]&&array_is_list($inputsOption)){throw new \InvalidArgumentException('Compliance plan inputs must be an object-like map.');}
		$entries=[];$inputs=[];
		foreach($frameworkIds as$frameworkId){$pack=$catalog->get($frameworkId);foreach($pack->controls()as$controlId=>$control){
			$entryId=$frameworkId.'.'.$controlId;$collectorIds=self::binding($bindings,$frameworkId,$controlId,$control['collectors']);if(count($collectorIds)>32){throw new \LengthException('Compliance controls support at most 32 collectors.');}
			$collectors=[];foreach($collectorIds as$collectorId){$collector=$registry->get($collectorId);$collectors[]=['id'=>$collector->id(),'fingerprint'=>$collector->fingerprint()];}
			$input=self::input($inputsOption,$frameworkId,$controlId,$globalInput);$inputs[$entryId]=$input;
			$ledgerId=self::ledgerControlId($frameworkId,$controlId);
			$entries[$entryId]=['id'=>$entryId,'ledger_control_id'=>$ledgerId,'framework_id'=>$frameworkId,'framework_version'=>$pack->frameworkVersion(),'framework_fingerprint'=>$pack->fingerprint(),'framework_control_id'=>$controlId,'control_fingerprint'=>$control['fingerprint'],'title'=>$control['title'],'references'=>$control['references'],'domains'=>$control['domains'],'evidence_requirements'=>$control['evidence_requirements'],'collectors'=>$collectors,'subject'=>$subject,'input_digest'=>PanelOperationsGuard::digest($input)];
		}}
		ksort($entries,SORT_STRING);ksort($inputs,SORT_STRING);
		return new self($generatedAt,$deadlineAt,$subject,$catalog->fingerprint(),$registry->fingerprint(),$entries,$inputs,is_array($options['metadata']??null)?$options['metadata']:[]);
	}

	public function generatedAt():string{return$this->generatedAt;}
	public function deadlineAt():string{return$this->deadlineAt;}
	public function subject():string{return$this->subject;}
	public function fingerprint():string{return$this->fingerprint;}
	/** @return array<string,array<string,mixed>> */ public function entries():array{return$this->entries;}
	/** @return array<string,mixed> */ public function inputFor(string $entryId):array {$entryId=PanelOperationsGuard::name($entryId,'compliance plan entry id',193);return$this->inputs[$entryId]??throw new \OutOfBoundsException('Compliance plan entry input does not exist.');}
	public function expiredAt(string|int|\DateTimeInterface $instant):bool{return strcmp(PanelOperationsGuard::instant($instant),$this->deadlineAt)>0;}

	/** @return list<array<string,mixed>> */
	public function drift(PanelComplianceFrameworkCatalog $catalog,PanelComplianceCollectorRegistry $registry):array {
		$drift=[];foreach($this->entries as$entry){
			try{$pack=$catalog->get($entry['framework_id']);if(!hash_equals($entry['framework_fingerprint'],$pack->fingerprint())){$drift[]=['entry_id'=>$entry['id'],'code'=>'framework_fingerprint_changed','expected'=>$entry['framework_fingerprint'],'actual'=>$pack->fingerprint()];continue;}$control=$pack->control($entry['framework_control_id']);if(!is_array($control)||!hash_equals($entry['control_fingerprint'],(string)($control['fingerprint']??''))){$drift[]=['entry_id'=>$entry['id'],'code'=>'control_fingerprint_changed','expected'=>$entry['control_fingerprint'],'actual'=>is_array($control)?($control['fingerprint']??null):null];continue;}}
			catch(\Throwable){$drift[]=['entry_id'=>$entry['id'],'code'=>'framework_missing','expected'=>$entry['framework_fingerprint'],'actual'=>null];continue;}
			foreach($entry['collectors']as$pin){try{$collector=$registry->get($pin['id']);if(!hash_equals($pin['fingerprint'],$collector->fingerprint())){$drift[]=['entry_id'=>$entry['id'],'collector_id'=>$pin['id'],'code'=>'collector_fingerprint_changed','expected'=>$pin['fingerprint'],'actual'=>$collector->fingerprint()];}}catch(\Throwable){$drift[]=['entry_id'=>$entry['id'],'collector_id'=>$pin['id'],'code'=>'collector_missing','expected'=>$pin['fingerprint'],'actual'=>null];}}
		}return$drift;
	}

	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp($this->payload()+['fingerprint'=>$this->fingerprint]);}
	/** @return array<string,mixed> */ private function payload():array{return[
		'type'=>'panel_compliance_collection_plan','version'=>1,'generated_at'=>$this->generatedAt,'deadline_at'=>$this->deadlineAt,
		'subject'=>$this->subject,'catalog_fingerprint'=>$this->catalogFingerprint,'registry_fingerprint'=>$this->registryFingerprint,
		'entries'=>$this->entries,'metadata'=>$this->metadata,'inputs_serialized'=>false,
	];}

	/** @param array<string,mixed> $bindings @param list<string> $defaults @return list<string> */
	private static function binding(array $bindings,string $frameworkId,string $controlId,array $defaults):array {
		$value=$defaults;$exact=$frameworkId.'.'.$controlId;
		if(array_key_exists('*',$bindings)){$value=$bindings['*'];}
		if(array_key_exists($frameworkId.'.*',$bindings)){$value=$bindings[$frameworkId.'.*'];}
		if(is_array($bindings[$frameworkId]??null)&&array_key_exists($controlId,$bindings[$frameworkId])){$value=$bindings[$frameworkId][$controlId];}
		if(array_key_exists($exact,$bindings)){$value=$bindings[$exact];}
		if(is_string($value)){$value=[$value];}if(!is_array($value)||($value!==[]&&!array_is_list($value))){throw new \InvalidArgumentException('Compliance collector bindings must be collector ids or lists.');}
		return PanelOperationsGuard::names($value,'compliance collector id',96,32);
	}

	/** @param array<string,mixed> $inputs @param array<string,mixed> $global @return array<string,mixed> */
	private static function input(array $inputs,string $frameworkId,string $controlId,array $global):array {
		$value=$global;$exact=$frameworkId.'.'.$controlId;
		if(is_array($inputs['*']??null)){$value=array_replace($value,$inputs['*']);}
		if(is_array($inputs[$frameworkId.'.*']??null)){$value=array_replace($value,$inputs[$frameworkId.'.*']);}
		if(is_array($inputs[$frameworkId]??null)&&is_array($inputs[$frameworkId][$controlId]??null)){$value=array_replace($value,$inputs[$frameworkId][$controlId]);}
		if(is_array($inputs[$exact]??null)){$value=array_replace($value,$inputs[$exact]);}
		return PanelOperationsGuard::safeMetadata($value,256);
	}

	private static function ledgerControlId(string $frameworkId,string $controlId):string {
		$id=$frameworkId.'.'.$controlId;if(strlen($id)>96){$id=substr($frameworkId,0,38).'.'.substr($controlId,0,38).'.'.substr(hash('sha256',$id),0,16);}
		return PanelOperationsGuard::name($id,'ledger compliance control id');
	}
}
