<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/** Defines bounded exponential retry behavior for transient transactions. */
final class ReactorRetryPolicy implements \JsonSerializable {
	public function __construct(
		private readonly int $attempts=3,
		private readonly int $initialDelayMs=150,
		private readonly int $maximumDelayMs=5000,
		private readonly float $multiplier=2.0,
		private readonly float $jitter=0.15
	){
		if($attempts<1 || $attempts>25){ throw new \InvalidArgumentException('Retry attempts must be between 1 and 25.'); }
		if($initialDelayMs<0 || $maximumDelayMs<$initialDelayMs){ throw new \InvalidArgumentException('Invalid retry delay bounds.'); }
		if($multiplier<1.0 || $jitter<0.0 || $jitter>1.0){ throw new \InvalidArgumentException('Invalid retry multiplier or jitter.'); }
	}

	public static function fromArray(array $policy): self {
		return new self(
			(int)($policy['attempts'] ?? 3),
			(int)($policy['initial_delay_ms'] ?? 150),
			(int)($policy['maximum_delay_ms'] ?? 5000),
			(float)($policy['multiplier'] ?? 2.0),
			(float)($policy['jitter'] ?? 0.15)
		);
	}

	public function attempts(): int { return $this->attempts; }

	public function delayMs(int $attempt, ?int $entropy=null): int {
		$attempt=max(1, $attempt);
		$base=min($this->maximumDelayMs, (int)round($this->initialDelayMs*($this->multiplier**($attempt-1))));
		if($base===0 || $this->jitter===0.0){ return $base; }
		$entropy=$entropy ?? random_int(0, 1000000);
		$unit=(($entropy%1000001)/1000000)*2-1;
		return max(0, min($this->maximumDelayMs, (int)round($base+($base*$this->jitter*$unit))));
	}

	public function jsonSerialize(): array {
		return [
			'attempts'=>$this->attempts,
			'initial_delay_ms'=>$this->initialDelayMs,
			'maximum_delay_ms'=>$this->maximumDelayMs,
			'multiplier'=>$this->multiplier,
			'jitter'=>$this->jitter,
		];
	}
}
