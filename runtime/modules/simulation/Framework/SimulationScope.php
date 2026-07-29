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
use JsonSerializable;

/** Closed, stable business scope passed to every simulated observation and mutation. */
final class SimulationScope implements JsonSerializable {
	/** @var array<string,int|float|string|bool> */
	private array $dimensions;

	/** @param array<string,mixed> $dimensions */
	public function __construct(array $dimensions) {
		$normalized=[];
		foreach($dimensions as $name=>$value){
			$key=strtolower(trim((string)$name));
			if(preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)!==1) throw new InvalidArgumentException('Simulation scope dimension names must be path-safe identifiers.');
			if(!is_int($value) && !is_float($value) && !is_string($value) && !is_bool($value)) throw new InvalidArgumentException('Simulation scope values must be scalar.');
			if(is_string($value)){
				$value=trim($value);
				if($value==='') throw new InvalidArgumentException('Simulation scope string values cannot be blank.');
			}
			$normalized[$key]=$value;
		}
		if($normalized===[]) throw new InvalidArgumentException('Simulation scope requires at least one dimension.');
		ksort($normalized, SORT_STRING);
		$this->dimensions=$normalized;
	}

	public static function from(array|self $scope): self {
		return $scope instanceof self ? $scope : new self($scope);
	}

	public function has(string $name): bool {
		return array_key_exists(strtolower(trim($name)), $this->dimensions);
	}

	public function get(string $name, mixed $default=null): mixed {
		return $this->dimensions[strtolower(trim($name))] ?? $default;
	}

	public function requireInt(string $name): int {
		$value=$this->get($name);
		if(!is_int($value) && !(is_string($value) && ctype_digit($value))) throw new InvalidArgumentException('Required simulation scope integer is missing: '.$name);
		$value=(int)$value;
		if($value<=0) throw new InvalidArgumentException('Required simulation scope integer must be positive: '.$name);
		return $value;
	}

	/** @return array<string,int|float|string|bool> */
	public function all(): array {
		return $this->dimensions;
	}

	public function key(): string {
		return hash('sha256', json_encode($this->dimensions, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
	}

	public function jsonSerialize(): array {
		return $this->dimensions;
	}
}
