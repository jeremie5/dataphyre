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

final class ApplicationCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, Application> */
	private readonly array $entries;

	public function __construct(
		private readonly ?string $project_root=null,
		array $entries=[]
	){
		$normalized=[];
		foreach($entries as $key=>$entry){
			if(!$entry instanceof Application){
				continue;
			}
			$normalized[trim((string)($key ?: $entry->id)) ?: $entry->id]=$entry;
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

	public function first(): ?Application {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	public function get(string $application_id): ?Application {
		$application_id=trim($application_id);
		return $application_id!=='' ? ($this->entries[$application_id] ?? null) : null;
	}

	public function has(string $application_id): bool {
		return $this->get($application_id) instanceof Application;
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
				static fn(Application $application): array => $application->toArray(),
				$this->all()
			),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
