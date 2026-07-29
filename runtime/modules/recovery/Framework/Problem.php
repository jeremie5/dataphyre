<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

use JsonSerializable;

/** One RFC 9457-compatible public problem occurrence with bounded extensions. */
final class Problem implements JsonSerializable {
	/**
	 * @param array<int,RecoveryAction> $actions
	 */
	public function __construct(
		private ProblemDefinition $definition,
		private string $title,
		private string $detail,
		private string $instance,
		private string $correlationId,
		private array $actions=[],
		private ?Evidence $evidence=null,
		private ?string $incidentFingerprint=null,
		private bool $incidentAcknowledged=false
	) {}

	public function definition(): ProblemDefinition { return $this->definition; }
	public function code(): string { return $this->definition->id(); }
	public function httpStatus(): int { return $this->definition->httpStatus(); }
	public function correlationId(): string { return $this->correlationId; }
	public function incidentFingerprint(): ?string { return $this->incidentFingerprint; }
	public function incidentAcknowledged(): bool { return $this->incidentFingerprint!==null && $this->incidentAcknowledged; }
	/** @return array<int,RecoveryAction> */ public function actions(): array { return $this->actions; }
	public function evidence(): Evidence { return $this->evidence ?? Evidence::from([], []); }

	public function withIncidentAcknowledgement(bool $acknowledged=true): self {
		$clone=clone $this;
		$clone->incidentAcknowledged=$clone->incidentFingerprint!==null && $acknowledged;
		return $clone;
	}

	public function jsonSerialize(): array {
		$retry=[
			'policy'=>$this->definition->retryPolicy(),
			'allowed'=>$this->definition->retryPolicy()!=='none',
		];
		if($this->definition->retryAfterSeconds()!==null) $retry['after_seconds']=$this->definition->retryAfterSeconds();
		return array_filter([
			'type'=>$this->definition->typeUri(),
			'title'=>$this->title,
			'status'=>$this->definition->httpStatus(),
			'detail'=>$this->detail,
			'instance'=>$this->instance,
			'code'=>$this->definition->id(),
			'severity'=>$this->definition->severity(),
			'help_topic'=>$this->definition->helpTopic(),
			'help_url'=>$this->definition->helpUrl(),
			'correlation_id'=>$this->correlationId,
			'retry'=>$retry,
			'data_state'=>$this->definition->dataState(),
			'actions'=>$this->actions,
			'incident'=>$this->incidentFingerprint===null ? null : [
				'fingerprint'=>$this->incidentFingerprint,
				'policy'=>$this->definition->incidentPolicy(),
				'acknowledged'=>$this->incidentAcknowledged,
			],
			'support_evidence'=>$this->evidence?->all(),
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}
}
