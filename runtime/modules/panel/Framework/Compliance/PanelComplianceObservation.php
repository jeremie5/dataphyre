<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** One immutable, freshness-bounded, secret-safe collector result. */
final class PanelComplianceObservation implements \JsonSerializable {
	public const STATUSES=['satisfied','not_satisfied','indeterminate','not_applicable','error'];
	private readonly string $status;
	private readonly string $observedAt;
	private readonly string $validUntil;
	private readonly string $subject;
	private readonly string $sourceReference;
	/** @var array<string,mixed> */ private readonly array $evidence;
	private readonly string $digest;

	/** @param array<string,mixed> $evidence @param array<string,mixed> $options */
	private function __construct(string $status,array $evidence,array $options){
		$status=PanelOperationsGuard::name($status,'compliance observation status');
		if(!in_array($status,self::STATUSES,true)){throw new \InvalidArgumentException('Compliance observation status is not supported.');}$this->status=$status;
		$this->observedAt=PanelOperationsGuard::instant($options['observed_at']??gmdate('c'),'compliance observed at');
		if(isset($options['valid_until'])){$this->validUntil=PanelOperationsGuard::instant($options['valid_until'],'compliance valid until');}
		else{$seconds=max(1,min(31536000,(int)($options['max_age_seconds']??86400)));$this->validUntil=(new \DateTimeImmutable($this->observedAt))->modify('+'.$seconds.' seconds')->format('Y-m-d\TH:i:s.u\Z');}
		if(strcmp($this->validUntil,$this->observedAt)<0){throw new \InvalidArgumentException('Compliance observation validity must not precede observation time.');}
		$this->subject=PanelOperationsGuard::identifier((string)($options['subject']??'global'),'compliance observation subject',160);
		$this->sourceReference=PanelOperationsGuard::label((string)($options['source_reference']??'collector'),'compliance source reference',512);
		$this->evidence=PanelOperationsGuard::safeMetadata($evidence,max(1,min(512,(int)($options['max_evidence_items']??128))));
		$this->digest=PanelOperationsGuard::digest($this->payload());
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $options */
	public static function make(string $status,array $evidence=[],array $options=[]):self{return new self($status,$evidence,$options);}
	/** @param array<string,mixed> $payload */ public static function fromArray(array $payload):self {
		$instance=new self((string)($payload['status']??''),is_array($payload['evidence']??null)?$payload['evidence']:[],[
			'observed_at'=>$payload['observed_at']??'','valid_until'=>$payload['valid_until']??'',
			'subject'=>$payload['subject']??'global','source_reference'=>$payload['source_reference']??'collector','max_evidence_items'=>max(1,min(512,count(is_array($payload['evidence']??null)?$payload['evidence']:[]))),
		]);
		if(isset($payload['digest'])&&(!is_string($payload['digest'])||!hash_equals($instance->digest,$payload['digest']))){throw new \UnexpectedValueException('Compliance observation digest does not verify.');}
		return$instance;
	}

	public function status():string{return$this->status;}
	public function observedAt():string{return$this->observedAt;}
	public function validUntil():string{return$this->validUntil;}
	public function subject():string{return$this->subject;}
	public function sourceReference():string{return$this->sourceReference;}
	/** @return array<string,mixed> */ public function evidence():array{return$this->evidence;}
	public function digest():string{return$this->digest;}
	public function freshAt(string|int|\DateTimeInterface $instant):bool{$instant=PanelOperationsGuard::instant($instant);return strcmp($instant,$this->observedAt)>=0&&strcmp($instant,$this->validUntil)<=0;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp($this->payload()+['digest'=>$this->digest]);}
	/** @return array<string,mixed> */ private function payload():array{return[
		'type'=>'panel_compliance_observation','version'=>1,'status'=>$this->status,'observed_at'=>$this->observedAt,
		'valid_until'=>$this->validUntil,'subject'=>$this->subject,'source_reference'=>$this->sourceReference,'evidence'=>$this->evidence,
	];}
}
