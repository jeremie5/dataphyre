<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, artifact-backed evidence for one inclusive-quality case. */
final class PanelQualityEvidence implements \JsonSerializable {
	private const STATUSES=['passed','failed','blocked','not_run'];
	private const EXECUTIONS=['php','browser','adapter','manual'];

	/** @param list<string> $capabilities */
	public function __construct(
		private readonly string $caseId,
		private readonly string $status,
		private readonly string $execution,
		private readonly ?string $executor=null,
		private readonly ?string $artifact=null,
		private readonly int $assertions=0,
		private readonly float $durationMs=0.0,
		private readonly array $capabilities=[],
		private readonly ?string $notes=null,
		private readonly ?string $observedAt=null,
		private readonly ?string $matrixDigest=null
	){
		if(self::id($caseId)==='' || $caseId!==self::id($caseId)){ throw new \InvalidArgumentException('Panel quality evidence requires a stable case id.'); }
		if(!in_array($status,self::STATUSES,true) || !in_array($execution,self::EXECUTIONS,true)){ throw new \InvalidArgumentException('Panel quality evidence has an invalid status or execution channel.'); }
		if($assertions<0 || !is_finite($durationMs) || $durationMs<0 || $durationMs>3600000){ throw new \InvalidArgumentException('Panel quality evidence counters must be finite and within budget.'); }
		if(count($capabilities)>32 || self::ids($capabilities)!==$capabilities){ throw new \InvalidArgumentException('Panel quality evidence capabilities must be a sorted unique id list.'); }
		if($executor!==self::text($executor,256,true) || $artifact!==self::text($artifact,2048,true) || $notes!==self::text($notes,2048,true)){ throw new \InvalidArgumentException('Panel quality evidence text must already be normalized.'); }
		if(in_array($status,['passed','failed'],true) && ($executor===null || $artifact===null)){ throw new \InvalidArgumentException('Executed Panel quality evidence requires an executor and artifact.'); }
		if(in_array($status,['passed','failed'],true) && $observedAt===null){ throw new \InvalidArgumentException('Executed Panel quality evidence requires an observed_at timestamp.'); }
		if($status==='passed' && $assertions<1){ throw new \InvalidArgumentException('Passing Panel quality evidence requires at least one assertion.'); }
		if($status==='not_run' && ($assertions!==0 || $durationMs!==0.0)){ throw new \InvalidArgumentException('Not-run Panel quality evidence cannot claim execution counters.'); }
		if($observedAt!==null && (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$observedAt)!==1 || !self::validTime($observedAt))){ throw new \InvalidArgumentException('Panel quality evidence observed_at must be RFC 3339.'); }
		if($matrixDigest!==null && preg_match('/^[a-f0-9]{64}$/D',$matrixDigest)!==1){ throw new \InvalidArgumentException('Panel quality evidence matrix_digest must be a SHA-256 digest.'); }
	}

	/** @param array<string,mixed> $payload */
	public static function fromArray(array $payload): self {
		$capabilities=self::ids(is_array($payload['capabilities'] ?? null)?$payload['capabilities']:[]);
		return new self(
			self::id((string)($payload['case_id'] ?? '')),
			strtolower(trim((string)($payload['status'] ?? ''))),
			strtolower(trim((string)($payload['execution'] ?? ''))),
			self::text($payload['executor'] ?? null,256,true),
			self::text($payload['artifact'] ?? null,2048,true),
			(int)($payload['assertions'] ?? 0),
			(float)($payload['duration_ms'] ?? 0.0),
			$capabilities,
			self::text($payload['notes'] ?? null,2048,true),
			self::text($payload['observed_at'] ?? null,64,true),
			self::text($payload['matrix_digest'] ?? null,64,true)
		);
	}

	public function caseId(): string { return $this->caseId; }
	public function status(): string { return $this->status; }
	public function execution(): string { return $this->execution; }
	public function assertions(): int { return $this->assertions; }
	public function durationMs(): float { return $this->durationMs; }
	public function matrixDigest(): ?string { return $this->matrixDigest; }
	/** @return list<string> */ public function capabilities(): array { return $this->capabilities; }

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return ['type'=>'panel_quality_evidence','version'=>1,'case_id'=>$this->caseId,'status'=>$this->status,'execution'=>$this->execution,'executor'=>$this->executor,'artifact'=>$this->artifact,'assertions'=>$this->assertions,'duration_ms'=>round($this->durationMs,3),'capabilities'=>$this->capabilities,'notes'=>$this->notes,'observed_at'=>$this->observedAt,'matrix_digest'=>$this->matrixDigest];
	}

	private static function validTime(string $value): bool {
		if(preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|([+-])(\d{2}):(\d{2}))$/D',$value,$parts)!==1){ return false; }
		if(!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]) || (int)$parts[4]>23 || (int)$parts[5]>59 || (int)$parts[6]>59){ return false; }
		return $parts[7]==='Z' || ((int)$parts[9]<=14 && (int)$parts[10]<=59 && !((int)$parts[9]===14 && (int)$parts[10]!==0));
	}

	private static function id(string $value): string {
		$value=strtolower(trim($value));
		return preg_match('/^[a-z][a-z0-9_.-]{0,383}$/D',$value)===1 ? $value : '';
	}

	/** @param array<mixed> $values @return list<string> */
	private static function ids(array $values): array {
		$out=[];
		foreach($values as $value){ $id=strtolower(trim((string)$value)); if(preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D',$id)!==1){ throw new \InvalidArgumentException('Panel quality evidence contains an invalid capability id.'); } $out[$id]=true; }
		$out=array_keys($out); sort($out,SORT_STRING); return $out;
	}

	private static function text(mixed $value,int $maximum,bool $nullable=false): ?string {
		if($value===null && $nullable){ return null; }
		if(!is_scalar($value)){ throw new \InvalidArgumentException('Panel quality evidence text must be scalar.'); }
		$value=trim((string)$value);
		if($value==='' && $nullable){ return null; }
		if($value==='' || preg_match('//u',$value)!==1 || strlen($value)>$maximum || preg_match('/[\x00-\x1F\x7F]/',$value)===1){ throw new \InvalidArgumentException('Panel quality evidence text is invalid or exceeds its budget.'); }
		return $value;
	}
}
