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

final class ModuleCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, ModuleDefinition> */
	private readonly array $entries;

	public function __construct(array $entries=[]){
		$normalized=[];
		foreach($entries as $key=>$entry){
			if(is_array($entry)){
				$entry=ModuleDefinition::fromArray($entry);
			}
			if(!$entry instanceof ModuleDefinition){
				continue;
			}
			$normalized[strtolower(trim((string)($key ?: $entry->module()))) ?: $entry->module()]=$entry;
		}
		ksort($normalized);
		$this->entries=$normalized;
	}

	public static function fromDefinitions(array $definitions): self {
		return new self($definitions);
	}

	public function all(): array {
		return array_values($this->entries);
	}

	public function names(): array {
		return array_keys($this->entries);
	}

	public function enabledNames(): array {
		return $this->enabled()->names();
	}

	public function disabledNames(): array {
		return $this->disabled()->names();
	}

	public function first(): ?ModuleDefinition {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	public function get(string $module): ?ModuleDefinition {
		$module=strtolower(trim($module));
		return $module!=='' ? ($this->entries[$module] ?? null) : null;
	}

	public function has(string $module): bool {
		return $this->get($module) instanceof ModuleDefinition;
	}

	public function enabled(): self {
		return new self(array_filter(
			$this->entries,
			static fn(ModuleDefinition $definition): bool => $definition->enabled()
		));
	}

	public function disabled(): self {
		return new self(array_filter(
			$this->entries,
			static fn(ModuleDefinition $definition): bool => $definition->enabled()===false
		));
	}

	public function count(): int {
		return count($this->entries);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	public function toArray(): array {
		return array_map(
			static fn(ModuleDefinition $definition): array => $definition->toArray(),
			$this->all()
		);
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
