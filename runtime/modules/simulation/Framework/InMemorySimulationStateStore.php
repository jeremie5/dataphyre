<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Simulation;

/** Deterministic store for tests, previews, and embedded single-process tools. */
final class InMemorySimulationStateStore implements SimulationStateStore {
	/** @var array<string,array<string,mixed>> */
	private array $states=[];

	public function load(string $domain, SimulationScope|string $scope): ?array {
		$key=$this->key($domain, $scope);
		return isset($this->states[$key]) ? $this->copy($this->states[$key]) : null;
	}

	public function save(string $domain, SimulationScope|string $scope, array $state, int $expectedRevision): bool {
		$key=$this->key($domain, $scope);
		$current=(int)($this->states[$key]['revision'] ?? 0);
		if($current!==max(0, $expectedRevision)) return false;
		$next=(int)($state['revision'] ?? -1);
		if($next!==$current+1) return false;
		$this->states[$key]=$this->copy($state);
		return true;
	}

	/** Test and diagnostic seed that still enforces state shape at runtime use. */
	public function seed(string $domain, SimulationScope|string $scope, array $state): void {
		$scopeKey=$scope instanceof SimulationScope ? $scope->key() : trim($scope);
		$this->states[$this->key($domain, $scopeKey)]=$this->copy($state);
	}

	/** @return array<string,array<string,mixed>> */
	public function all(): array {
		return $this->copy($this->states);
	}

	private function key(string $domain, SimulationScope|string $scope): string {
		$scopeKey=$scope instanceof SimulationScope ? $scope->key() : trim($scope);
		return strtolower(trim($domain)).':'.$scopeKey;
	}

	private function copy(array $value): array {
		return unserialize(serialize($value), ['allowed_classes'=>false]);
	}
}
