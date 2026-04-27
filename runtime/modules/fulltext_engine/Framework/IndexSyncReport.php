<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

final class IndexSyncReport implements \JsonSerializable {

	/** @var array<int, IndexDefinition> */
	private array $created=[];

	/** @var array<int, IndexDefinition> */
	private array $unchanged=[];

	/** @var array<int, array{current: IndexDefinition, desired: IndexDefinition}> */
	private array $mismatched=[];

	/** @var array<int, IndexDefinition> */
	private array $pruned=[];

	/** @var array<string, string> */
	private array $failed=[];

	public function addCreated(IndexDefinition $definition): void {
		$this->created[]=$definition;
	}

	public function addUnchanged(IndexDefinition $definition): void {
		$this->unchanged[]=$definition;
	}

	public function addMismatched(IndexDefinition $current, IndexDefinition $desired): void {
		$this->mismatched[]=[
			'current'=>$current,
			'desired'=>$desired,
		];
	}

	public function addPruned(IndexDefinition $definition): void {
		$this->pruned[]=$definition;
	}

	public function addFailed(string $index_name, string $reason): void {
		$this->failed[$index_name]=$reason;
	}

	public function created(): array {
		return $this->created;
	}

	public function unchanged(): array {
		return $this->unchanged;
	}

	public function mismatched(): array {
		return $this->mismatched;
	}

	public function pruned(): array {
		return $this->pruned;
	}

	public function failed(): array {
		return $this->failed;
	}

	public function hasFailures(): bool {
		return $this->failed!==[];
	}

	public function hasMismatches(): bool {
		return $this->mismatched!==[];
	}

	public function isClean(): bool {
		return !$this->hasFailures() && !$this->hasMismatches();
	}

	public function summary(): array {
		return [
			'created'=>count($this->created),
			'unchanged'=>count($this->unchanged),
			'mismatched'=>count($this->mismatched),
			'pruned'=>count($this->pruned),
			'failed'=>count($this->failed),
		];
	}

	public function jsonSerialize(): array {
		return [
			'summary'=>$this->summary(),
			'created'=>array_map(static fn(IndexDefinition $definition): array => $definition->jsonSerialize(), $this->created),
			'unchanged'=>array_map(static fn(IndexDefinition $definition): array => $definition->jsonSerialize(), $this->unchanged),
			'mismatched'=>array_map(
				static fn(array $pair): array => [
					'current'=>$pair['current']->jsonSerialize(),
					'desired'=>$pair['desired']->jsonSerialize(),
				],
				$this->mismatched
			),
			'pruned'=>array_map(static fn(IndexDefinition $definition): array => $definition->jsonSerialize(), $this->pruned),
			'failed'=>$this->failed,
		];
	}
}
