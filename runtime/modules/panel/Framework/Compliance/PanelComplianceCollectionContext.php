<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable, sanitized, deadline-aware input passed to one collector. */
final class PanelComplianceCollectionContext implements \JsonSerializable {
	private readonly string $controlId;
	private readonly string $frameworkId;
	private readonly string $frameworkControlId;
	private readonly string $subject;
	private readonly string $requestedAt;
	private readonly string $deadlineAt;
	private readonly string $planFingerprint;
	/** @var list<string> */ private readonly array $references;
	/** @var array<string,mixed> */ private readonly array $input;
	/** @var array<string,mixed> */ private readonly array $attributes;
	private readonly int $maxEvidenceItems;

	/** @param list<string> $references @param array<string,mixed> $input @param array<string,mixed> $attributes */
	public function __construct(string $controlId,string $frameworkId,string $frameworkControlId,string|int $subject,string $requestedAt,string $deadlineAt,string $planFingerprint,array $references=[],array $input=[],array $attributes=[],int $maxEvidenceItems=128){
		$this->controlId=PanelOperationsGuard::name($controlId,'ledger compliance control id');
		$this->frameworkId=PanelOperationsGuard::name($frameworkId,'compliance framework id');
		$this->frameworkControlId=PanelOperationsGuard::name($frameworkControlId,'framework control id');
		$this->subject=PanelOperationsGuard::identifier($subject,'compliance evidence subject',160);
		$this->requestedAt=PanelOperationsGuard::instant($requestedAt,'compliance collection requested at');
		$this->deadlineAt=PanelOperationsGuard::instant($deadlineAt,'compliance collection deadline');
		if(strcmp($this->deadlineAt,$this->requestedAt)<0){throw new \InvalidArgumentException('Compliance collection deadline must not precede its request.');}
		if(preg_match('/^[a-f0-9]{64}$/D',$planFingerprint)!==1){throw new \InvalidArgumentException('Compliance collection plan fingerprint is invalid.');}
		$this->planFingerprint=$planFingerprint;
		if(count($references)>64){throw new \LengthException('Compliance collection references exceed their limit.');}
		$normalized=[];foreach($references as$reference){$reference=PanelOperationsGuard::label((string)$reference,'compliance reference',256);$normalized[$reference]=true;}$references=array_keys($normalized);sort($references,SORT_STRING);$this->references=$references;
		$this->input=PanelOperationsGuard::safeMetadata($input,256);
		$this->attributes=PanelOperationsGuard::safeMetadata($attributes,128);
		$this->maxEvidenceItems=max(1,min(512,$maxEvidenceItems));
	}

	public function controlId():string{return$this->controlId;}
	public function frameworkId():string{return$this->frameworkId;}
	public function frameworkControlId():string{return$this->frameworkControlId;}
	public function subject():string{return$this->subject;}
	public function requestedAt():string{return$this->requestedAt;}
	public function deadlineAt():string{return$this->deadlineAt;}
	public function planFingerprint():string{return$this->planFingerprint;}
	/** @return list<string> */ public function references():array{return$this->references;}
	/** @return array<string,mixed> */ public function input():array{return$this->input;}
	public function inputValue(string $path,mixed $default=null):mixed{return PanelOperationsGuard::valueAt($this->input,$path,$default);}
	/** @return array<string,mixed> */ public function attributes():array{return$this->attributes;}
	public function maxEvidenceItems():int{return$this->maxEvidenceItems;}
	public function expiredAt(string|int|\DateTimeInterface $instant):bool{return strcmp(PanelOperationsGuard::instant($instant),$this->deadlineAt)>0;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp([
		'type'=>'panel_compliance_collection_context','version'=>1,'control_id'=>$this->controlId,
		'framework_id'=>$this->frameworkId,'framework_control_id'=>$this->frameworkControlId,'subject'=>$this->subject,
		'requested_at'=>$this->requestedAt,'deadline_at'=>$this->deadlineAt,'plan_fingerprint'=>$this->planFingerprint,
		'references'=>$this->references,'input_digest'=>PanelOperationsGuard::digest($this->input),'attributes'=>$this->attributes,
		'max_evidence_items'=>$this->maxEvidenceItems,'input_serialized'=>false,
	]);}
}
