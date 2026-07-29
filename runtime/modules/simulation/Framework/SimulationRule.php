<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

use Closure;
use InvalidArgumentException;
use JsonSerializable;

/** A causal rule that proposes application intents without applying them itself. */
final class SimulationRule implements JsonSerializable {
	private Closure $planner;
	/** @var array<int,string> */
	private array $affects;

	/**
	 * @param array<int,string> $affects
	 * @param callable(SimulationContext,array<string,mixed>,SimulationRandom):SimulationIntent|array<int,SimulationIntent>|null $planner
	 */
	public function __construct(
		private string $id,
		private string $intentType,
		private string $origin,
		array $affects,
		private float $everySeconds,
		private float $probability,
		callable $planner,
		private int $priority=0,
	) {
		$this->id=self::name($this->id);
		$this->intentType=self::name($this->intentType);
		$this->origin=self::name($this->origin);
		$this->affects=array_values(array_unique(array_filter(array_map([self::class, 'name'], $affects))));
		if($this->id==='' || $this->intentType==='' || $this->origin==='' || $this->affects===[]) throw new InvalidArgumentException('Simulation rules require id, intent type, origin, and affected capabilities.');
		$this->everySeconds=max(0.05, $this->everySeconds);
		$this->probability=max(0.0, min(1.0, $this->probability));
		$this->planner=Closure::fromCallable($planner);
	}

	/** @param array<int,string> $affects */
	public static function every(string $id, string $intentType, string $origin, array $affects, float $seconds, callable $planner): self {
		return new self($id, $intentType, $origin, $affects, $seconds, 1.0, $planner);
	}

	public function probability(float $probability): self {
		$clone=clone $this;
		$clone->probability=max(0.0, min(1.0, $probability));
		return $clone;
	}

	public function priority(int $priority): self {
		$clone=clone $this;
		$clone->priority=$priority;
		return $clone;
	}

	public function id(): string { return $this->id; }
	public function intentType(): string { return $this->intentType; }
	public function origin(): string { return $this->origin; }
	/** @return array<int,string> */
	public function affects(): array { return $this->affects; }
	public function everySeconds(): float { return $this->everySeconds; }
	public function probabilityValue(): float { return $this->probability; }
	public function priorityValue(): int { return $this->priority; }

	/** @param array<string,mixed> $snapshot @return array<int,SimulationIntent> */
	public function plan(SimulationContext $context, array $snapshot, SimulationRandom $random): array {
		$result=($this->planner)($context, $snapshot, $random);
		if($result instanceof SimulationIntent) return [$result];
		if(!is_array($result)) return [];
		return array_values(array_filter($result, static fn(mixed $intent): bool => $intent instanceof SimulationIntent));
	}

	public function jsonSerialize(): array {
		return [
			'id'=>$this->id,
			'intent_type'=>$this->intentType,
			'origin'=>$this->origin,
			'affects'=>$this->affects,
			'every_seconds'=>$this->everySeconds,
			'probability'=>$this->probability,
			'priority'=>$this->priority,
		];
	}

	private static function name(mixed $value): string {
		$value=strtolower(trim((string)$value));
		$value=preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';
		return substr(trim($value, '_.-'), 0, 128);
	}
}
