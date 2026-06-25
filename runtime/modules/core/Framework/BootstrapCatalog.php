<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable catalog of discovered application bootstrap plans.
 *
 * `BootstrapCatalog` groups `BootstrapPlan` objects produced during runtime
 * discovery and exposes collection helpers for boot orchestration and diagnostics.
 * Entries are keyed by application id, sorted for
 * deterministic output, and filtered without mutating the original catalog.
 */
final class BootstrapCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, BootstrapPlan> */
	private readonly array $entries;

	/** @var array{project_root:?string,entries:array<int,array<string,mixed>>}|null */
	private ?array $arrayPayload=null;

	/** @var array<int,string>|null */
	private ?array $bootableNamePayload=null;

	/** @var array<int,string>|null */
	private ?array $unbootableNamePayload=null;

	/**
	 * Creates a catalog from discovered bootstrap plans.
	 *
	 * Non-`BootstrapPlan` entries are ignored. The array key is used as the catalog
	 * id when it is non-empty; otherwise the plan's own application id is used.
	 * The normalized catalog is sorted by id so iteration and JSON diagnostics are
	 * stable across discovery order.
	 *
	 * @param ?string $projectRoot Project root that was scanned for bootstrap entries.
	 * @param array<int|string,mixed> $entries Candidate bootstrap plans keyed by application id or numeric discovery order.
	 */
	public function __construct(
		private readonly ?string $projectRoot=null,
		array $entries=[],
		bool $normalized=false
	){
		if($normalized){
			$this->entries=$entries;
			return;
		}
		$normalized=[];
		foreach($entries as $key=>$entry){
			if(!$entry instanceof BootstrapPlan){
				continue;
			}
			$normalized[trim((string)($key ?: $entry->applicationId())) ?: $entry->applicationId()]=$entry;
		}
		ksort($normalized);
		$this->entries=$normalized;
	}

	/**
	 * Returns the project root associated with this discovery catalog.
	 *
	 * @return ?string Project root path, or null when discovery was not rooted.
	 */
	public function projectRoot(): ?string {
		return $this->projectRoot;
	}

	/**
	 * Returns every bootstrap plan in deterministic catalog order.
	 *
	 * The returned array is value-indexed rather than id-keyed so callers can
	 * iterate plans without depending on the internal key map. Use `names()` when
	 * application ids are needed.
	 *
	 * @return array<int,BootstrapPlan> Bootstrap plans sorted by application id.
	 */
	public function all(): array {
		return array_values($this->entries);
	}

	/**
	 * Returns application ids known to this catalog.
	 *
	 * @return array<int,string> Sorted application ids.
	 */
	public function names(): array {
		return array_keys($this->entries);
	}

	/**
	 * Returns the first bootstrap plan in deterministic catalog order.
	 *
	 * Because the constructor sorts entries by id, this is the lexicographically
	 * first application id rather than the first filesystem discovery result.
	 *
	 * @return ?BootstrapPlan First plan, or null when the catalog is empty.
	 */
	public function first(): ?BootstrapPlan {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	/**
	 * Looks up a bootstrap plan by application id.
	 *
	 * @param string $applicationId Application id to look up.
	 * @return ?BootstrapPlan Matching plan, or null for blank or unknown ids.
	 */
	public function get(string $applicationId): ?BootstrapPlan {
		$applicationId=trim($applicationId);
		return $applicationId!=='' ? ($this->entries[$applicationId] ?? null) : null;
	}

	/**
	 * Reports whether a bootstrap plan exists for an application id.
	 *
	 * @param string $applicationId Application id to test.
	 * @return bool True when the catalog contains a plan for the id.
	 */
	public function has(string $applicationId): bool {
		return $this->get($applicationId) instanceof BootstrapPlan;
	}

	/**
	 * Returns a catalog containing only plans that can currently boot.
	 *
	 * Filtering uses `BootstrapPlan::canBoot()` and preserves the same project
	 * root. The current catalog remains unchanged.
	 *
	 * @return self Filtered catalog of bootable applications.
	 */
	public function bootable(): self {
		$bootable=[];
		foreach($this->entries as $name=>$plan){
			if($plan->canBoot()){
				$bootable[$name]=$plan;
			}
		}
		return new self($this->projectRoot, $bootable, true);
	}

	/**
	 * Returns a catalog containing only plans that cannot currently boot.
	 *
	 * This view is useful for diagnostics because each `BootstrapPlan` carries the
	 * reason a discovered application is not bootable.
	 *
	 * @return self Filtered catalog of unbootable applications.
	 */
	public function unbootable(): self {
		$unbootable=[];
		foreach($this->entries as $name=>$plan){
			if($plan->canBoot()===false){
				$unbootable[$name]=$plan;
			}
		}
		return new self($this->projectRoot, $unbootable, true);
	}

	/**
	 * Returns the ids of applications that can currently boot.
	 *
	 * @return array<int,string> Sorted ids for bootable applications.
	 */
	public function bootableNames(): array {
		$this->partitionBootableNames();
		return $this->bootableNamePayload;
	}

	/**
	 * Returns the ids of applications that cannot currently boot.
	 *
	 * @return array<int,string> Sorted ids for unbootable applications.
	 */
	public function unbootableNames(): array {
		$this->partitionBootableNames();
		return $this->unbootableNamePayload;
	}

	/**
	 * Splits bootable and unbootable plan names in one pass for immutable views.
	 */
	private function partitionBootableNames(): void {
		if($this->bootableNamePayload!==null && $this->unbootableNamePayload!==null){
			return;
		}
		$bootable=[];
		$unbootable=[];
		foreach($this->entries as $name=>$plan){
			if($plan->canBoot()){
				$bootable[]=$name;
			}
			else{
				$unbootable[]=$name;
			}
		}
		$this->bootableNamePayload=$bootable;
		$this->unbootableNamePayload=$unbootable;
	}

	/**
	 * Counts plans in this catalog view.
	 *
	 * @return int Number of bootstrap plans in the catalog.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Returns an iterator over bootstrap plans in deterministic catalog order.
	 *
	 * @return Traversable<int,BootstrapPlan> Iterator over value-indexed plans.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	/**
	 * Returns the discovered bootstrap plans for runtime diagnostics.
	 *
	 * Each entry is converted through `BootstrapPlan::toArray()` so traces and
	 * debug surfaces can inspect bootstrap readiness without holding plan objects.
	 *
	 * @return array{project_root:?string,entries:array<int,array<string,mixed>>} Bootstrap plans keyed by discovery order.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$entries=[];
		foreach($this->entries as $plan){
			$entries[]=$plan->toArray();
		}
		return $this->arrayPayload=[
			'project_root'=>$this->projectRoot,
			'entries'=>$entries,
		];
	}

	/**
	 * Serializes discovered bootstrap plans for JSON output.
	 *
	 * @return array{project_root:?string,entries:array<int,array<string,mixed>>} Bootstrap plans keyed by discovery order.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
