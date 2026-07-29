<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use InvalidArgumentException;

/** Replayable pseudo-random source whose cursor is persisted with the simulation session. */
final class SimulationRandom {
	private string $seed;
	private int $cursor;

	public function __construct(string|int $seed, int $cursor=0) {
		$this->seed=trim((string)$seed)!=='' ? (string)$seed : 'dataphyre-simulation';
		$this->cursor=max(0, $cursor);
	}

	public function cursor(): int {
		return $this->cursor;
	}

	public function float(): float {
		$hex=substr(hash('sha256', $this->seed.':'.$this->cursor++), 0, 8);
		return hexdec($hex)/4294967296;
	}

	public function chance(float $probability): bool {
		return $this->float()<max(0.0, min(1.0, $probability));
	}

	public function int(int $minimum, int $maximum): int {
		if($maximum<$minimum) throw new InvalidArgumentException('Simulation random maximum must not be below minimum.');
		if($maximum===$minimum) return $minimum;
		return $minimum+(int)floor($this->float()*(($maximum-$minimum)+1));
	}

	/** @template T @param array<int,T> $values @return T */
	public function pick(array $values): mixed {
		$values=array_values($values);
		if($values===[]) throw new InvalidArgumentException('Simulation random cannot pick from an empty list.');
		return $values[$this->int(0, count($values)-1)];
	}

	/** @template T @param array<int,T> $values @return array<int,T> */
	public function shuffled(array $values): array {
		$values=array_values($values);
		for($index=count($values)-1;$index>0;$index--){
			$swap=$this->int(0, $index);
			[$values[$index], $values[$swap]]=[$values[$swap], $values[$index]];
		}
		return $values;
	}
}
