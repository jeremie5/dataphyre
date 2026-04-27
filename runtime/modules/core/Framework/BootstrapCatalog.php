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

final class BootstrapCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, BootstrapPlan> */
	private readonly array $entries;

	public function __construct(
		private readonly ?string $project_root=null,
		array $entries=[]
	){
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

	public function projectRoot(): ?string {
		return $this->project_root;
	}

	public function all(): array {
		return array_values($this->entries);
	}

	public function names(): array {
		return array_keys($this->entries);
	}

	public function first(): ?BootstrapPlan {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	public function get(string $application_id): ?BootstrapPlan {
		$application_id=trim($application_id);
		return $application_id!=='' ? ($this->entries[$application_id] ?? null) : null;
	}

	public function has(string $application_id): bool {
		return $this->get($application_id) instanceof BootstrapPlan;
	}

	public function bootable(): self {
		return new self(
			$this->project_root,
			array_filter(
				$this->entries,
				static fn(BootstrapPlan $plan): bool => $plan->canBoot()
			)
		);
	}

	public function unbootable(): self {
		return new self(
			$this->project_root,
			array_filter(
				$this->entries,
				static fn(BootstrapPlan $plan): bool => $plan->canBoot()===false
			)
		);
	}

	public function bootableNames(): array {
		return $this->bootable()->names();
	}

	public function unbootableNames(): array {
		return $this->unbootable()->names();
	}

	public function count(): int {
		return count($this->entries);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	public function toArray(): array {
		return [
			'project_root'=>$this->project_root,
			'entries'=>array_map(
				static fn(BootstrapPlan $plan): array => $plan->toArray(),
				$this->all()
			),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
