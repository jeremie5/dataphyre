<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

/**
 * Mutable report describing how desired fulltext index definitions compared with
 * runtime index state.
 *
 * IndexSyncReport is populated by synchronization workflows as indexes are
 * created, left unchanged, found mismatched, pruned, or failed. It preserves
 * IndexDefinition objects until serialization so diagnostics can inspect full
 * index definitions while summary() gives build tools and diagnostics a compact
 * health snapshot.
 */
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

	/** @var array<string, mixed>|null */
	private ?array $serialized=null;

	/**
	 * Records an index definition that was created during synchronization.
	 *
	 * @param IndexDefinition $definition Created index definition.
	 */
	public function addCreated(IndexDefinition $definition): void {
		$this->created[]=$definition;
		$this->serialized=null;
	}

	/**
	 * Records an index definition that already matched the desired state.
	 *
	 * @param IndexDefinition $definition Existing matching index definition.
	 */
	public function addUnchanged(IndexDefinition $definition): void {
		$this->unchanged[]=$definition;
		$this->serialized=null;
	}

	/**
	 * Records an index whose current definition differs from the desired definition.
	 *
	 * Both definitions are retained so diagnostics can render a meaningful diff or
	 * ask operators to rebuild the index.
	 *
	 * @param IndexDefinition $current Current runtime definition.
	 * @param IndexDefinition $desired Desired configured definition.
	 */
	public function addMismatched(IndexDefinition $current, IndexDefinition $desired): void {
		$this->mismatched[]=[
			'current'=>$current,
			'desired'=>$desired,
		];
		$this->serialized=null;
	}

	/**
	 * Records an index definition removed because it was no longer desired.
	 *
	 * @param IndexDefinition $definition Pruned index definition.
	 */
	public function addPruned(IndexDefinition $definition): void {
		$this->pruned[]=$definition;
		$this->serialized=null;
	}

	/**
	 * Records a synchronization failure for an index name.
	 *
	 * Later failures for the same index replace earlier reasons, keeping the
	 * failure map keyed for quick diagnostics.
	 *
	 * @param string $indexName Index name that failed to synchronize.
	 * @param string $reason Human-readable failure reason.
	 */
	public function addFailed(string $indexName, string $reason): void {
		$this->failed[$indexName]=$reason;
		$this->serialized=null;
	}

	/**
	 * Returns index definitions created during synchronization.
	 *
	 * @return list<IndexDefinition> Created index definitions.
	 */
	public function created(): array {
		return $this->created;
	}

	/**
	 * Returns index definitions that already matched the desired state.
	 *
	 * @return list<IndexDefinition> Unchanged index definitions.
	 */
	public function unchanged(): array {
		return $this->unchanged;
	}

	/**
	 * Returns current/desired pairs for indexes that need manual or rebuild attention.
	 *
	 * @return list<array{current: IndexDefinition, desired: IndexDefinition}> Mismatched definition pairs.
	 */
	public function mismatched(): array {
		return $this->mismatched;
	}

	/**
	 * Returns index definitions removed during synchronization.
	 *
	 * @return list<IndexDefinition> Pruned index definitions.
	 */
	public function pruned(): array {
		return $this->pruned;
	}

	/**
	 * Returns synchronization failures keyed by index name.
	 *
	 * @return array<string, string> Failure reasons keyed by index name.
	 */
	public function failed(): array {
		return $this->failed;
	}

	/**
	 * Reports whether any index failed to synchronize.
	 *
	 * @return bool True when at least one failure reason was recorded.
	 */
	public function hasFailures(): bool {
		return $this->failed!==[];
	}

	/**
	 * Reports whether any current index definition differs from desired configuration.
	 *
	 * @return bool True when at least one mismatch pair was recorded.
	 */
	public function hasMismatches(): bool {
		return $this->mismatched!==[];
	}

	/**
	 * Reports whether the sync finished without failures or mismatched definitions.
	 *
	 * Created, unchanged, and pruned indexes are considered clean because they
	 * reflect successful reconciliation.
	 *
	 * @return bool True when there are no failures and no mismatches.
	 */
	public function isClean(): bool {
		return !$this->hasFailures() && !$this->hasMismatches();
	}

	/**
	 * Returns compact counts for every synchronization outcome bucket.
	 *
	 * @return array{created: int, unchanged: int, mismatched: int, pruned: int, failed: int}
	 */
	public function summary(): array {
		return [
			'created'=>count($this->created),
			'unchanged'=>count($this->unchanged),
			'mismatched'=>count($this->mismatched),
			'pruned'=>count($this->pruned),
			'failed'=>count($this->failed),
		];
	}

	/**
	 * Exports the complete synchronization report for JSON diagnostics.
	 *
	 * @return array<string, mixed> Serialized sync report including summary and definitions.
	 */
	public function jsonSerialize(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		return $this->serialized=[
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
