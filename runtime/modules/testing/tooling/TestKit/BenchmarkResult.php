<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class BenchmarkResult {

	/** @param array<int, float> $durations */
	public function __construct(private array $durations, private int $memory_delta, private int $peak_delta) {}

	public function iterations(): int {
		return count($this->durations);
	}

	public function totalMillis(): float {
		return array_sum($this->durations);
	}

	public function meanMillis(): float {
		return $this->durations===[] ? 0.0 : $this->totalMillis()/count($this->durations);
	}

	public function maxMillis(): float {
		return $this->durations===[] ? 0.0 : max($this->durations);
	}

	public function percentileMillis(float $percentile): float {
		if($this->durations===[]){
			return 0.0;
		}
		$values=$this->durations;
		sort($values);
		$index=(int)ceil((max(0, min(100, $percentile))/100)*count($values))-1;
		return $values[max(0, min(count($values)-1, $index))];
	}

	public function memoryDeltaBytes(): int {
		return $this->memory_delta;
	}

	public function peakDeltaBytes(): int {
		return $this->peak_delta;
	}

	/** @return array<string, mixed> */
	public function toArray(): array {
		return [
			'iterations'=>$this->iterations(),
			'total_millis'=>$this->totalMillis(),
			'mean_millis'=>$this->meanMillis(),
			'max_millis'=>$this->maxMillis(),
			'p95_millis'=>$this->percentileMillis(95),
			'memory_delta_bytes'=>$this->memory_delta,
			'peak_delta_bytes'=>$this->peak_delta,
		];
	}
}
