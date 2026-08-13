<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Immutable outcome for one adapter conformance case. */
final class PanelAdapterConformanceResult implements \JsonSerializable {
	/** @param list<array<string,mixed>> $issues @param array<string,mixed> $evidence */
	public function __construct(private readonly PanelAdapterConformanceCase $case, private readonly string $status, private readonly int $assertions, private readonly float $durationMs, private readonly array $issues=[], private readonly array $evidence=[], private readonly ?string $reason=null){
		if(!in_array($status, ['passed','failed','skipped'], true)){ throw new \InvalidArgumentException('Unknown adapter conformance result status.'); }
		if($assertions<0 || $durationMs<0 || !is_finite($durationMs)){ throw new \InvalidArgumentException('Adapter conformance result counters must be finite and non-negative.'); }
		if(!array_is_list($issues)){ throw new \InvalidArgumentException('Adapter conformance result issues must be a list.'); }
	}
	public function id(): string { return $this->case->id(); }
	public function status(): string { return $this->status; }
	public function passed(): bool { return $this->status==='passed'; }
	public function assertions(): int { return $this->assertions; }
	public function durationMs(): float { return $this->durationMs; }
	/** @return list<array<string,mixed>> */ public function issues(): array { return $this->issues; }
	/** @return array<string,mixed> */ public function evidence(): array { return $this->evidence; }
	public function reason(): ?string { return $this->reason; }
	/** @return array<string,mixed> */ public function jsonSerialize(): array { return ['case'=>$this->case->jsonSerialize(),'status'=>$this->status,'assertions'=>$this->assertions,'duration_ms'=>round($this->durationMs,3),'issues'=>PanelSensitiveDataSanitizer::sanitize($this->issues),'evidence'=>PanelSensitiveDataSanitizer::sanitize($this->evidence),'reason'=>PanelSensitiveDataSanitizer::sanitize($this->reason)]; }
}
