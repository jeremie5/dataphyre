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
 * Immutable catalog of Dataphyre runtime module definitions.
 *
 * The catalog normalizes array definitions into `ModuleDefinition` objects,
 * indexes them by lowercase module name, sorts them deterministically, and
 * exposes filtered enabled/disabled views for bootstrap diagnostics and runtime
 * state reporting.
 */
final class ModuleCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, ModuleDefinition> */
	private readonly array $entries;

	/** @var array<int,array<string,mixed>>|null */
	private ?array $arrayPayload=null;

	/** @var array{enabled:int,disabled:int}|null */
	private ?array $countPayload=null;

	/** @var array{enabled:array<int,string>,disabled:array<int,string>}|null */
	private ?array $namePairPayload=null;

	/** @var array<int|string,mixed>|null */
	private static ?array $lastDefinitionsInput=null;

	/** @var array<string, ModuleDefinition>|null */
	private static ?array $lastDefinitionsEntries=null;

	/**
	 * Creates a normalized catalog from module definitions.
	 *
	 * Array entries are converted through `ModuleDefinition::fromArray()`.
	 * Non-definition entries are ignored. Explicit array keys win when present;
	 * otherwise the module name from the definition is used.
	 *
	 * @param array<int|string,mixed> $entries Module definitions keyed by module name or discovery order.
	 */
	public function __construct(array $entries=[], bool $normalized=false){
		if($normalized){
			$this->entries=$entries;
			return;
		}
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

	/**
	 * Creates a catalog from raw module definition payloads.
	 *
	 *
	 * @return self Normalized module catalog.
	 */
	public static function fromDefinitions(array $definitions): self {
		if(self::$lastDefinitionsInput===$definitions && self::$lastDefinitionsEntries!==null){
			return new self(self::$lastDefinitionsEntries, true);
		}
		$catalog=new self($definitions);
		self::$lastDefinitionsInput=$definitions;
		self::$lastDefinitionsEntries=$catalog->entries;
		return $catalog;
	}

	/**
	 * Returns every module definition in deterministic catalog order.
	 *
	 * @return array<int,ModuleDefinition> Module definitions sorted by module name.
	 */
	public function all(): array {
		return array_values($this->entries);
	}

	/**
	 * Returns every module name known to the catalog.
	 *
	 *
	 * @return array<int,string> Sorted lowercase module names.
	 */
	public function names(): array {
		return array_keys($this->entries);
	}

	/**
	 * Returns module names that are currently enabled.
	 *
	 *
	 * @return array<int,string> Sorted enabled module names.
	 */
	public function enabledNames(): array {
		return $this->enabledDisabledNames()['enabled'];
	}

	/**
	 * Returns module names that are currently disabled.
	 *
	 *
	 * @return array<int,string> Sorted disabled module names.
	 */
	public function disabledNames(): array {
		return $this->enabledDisabledNames()['disabled'];
	}

	/**
	 * Returns the first module definition in deterministic catalog order.
	 *
	 *
	 * @return ?ModuleDefinition First definition, or null when the catalog is empty.
	 */
	public function first(): ?ModuleDefinition {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	/**
	 * Looks up a module definition by name.
	 *
	 *
	 * @return ?ModuleDefinition Matching module definition, or null for blank or unknown names.
	 */
	public function get(string $module): ?ModuleDefinition {
		$module=strtolower(trim($module));
		return $module!=='' ? ($this->entries[$module] ?? null) : null;
	}

	/**
	 * Reports whether the catalog contains a module definition.
	 *
	 *
	 * @param string $module Module name before lowercase lookup normalization.
	 * @return bool True when a definition exists for the normalized module name.
	 */
	public function has(string $module): bool {
		return $this->get($module) instanceof ModuleDefinition;
	}

	/**
	 * Returns a catalog view containing only enabled modules.
	 *
	 * The current catalog is not mutated; a new normalized catalog is returned.
	 *
	 * @return self Filtered catalog of enabled module definitions.
	 */
	public function enabled(): self {
		$enabled=[];
		foreach($this->entries as $name=>$definition){
			if($definition->enabled()){
				$enabled[$name]=$definition;
			}
		}
		return new self($enabled, true);
	}

	/**
	 * Returns a catalog view containing only disabled modules.
	 *
	 * Disabled modules remain useful in diagnostics because their definitions
	 * still describe availability, source files, and bootstrap metadata.
	 *
	 * @return self Filtered catalog of disabled module definitions.
	 */
	public function disabled(): self {
		$disabled=[];
		foreach($this->entries as $name=>$definition){
			if($definition->enabled()===false){
				$disabled[$name]=$definition;
			}
		}
		return new self($disabled, true);
	}

	/**
	 * Counts enabled module definitions without constructing a filtered catalog.
	 *
	 * @return int Number of enabled module definitions.
	 */
	public function enabledCount(): int {
		return $this->enabledDisabledCounts()['enabled'];
	}

	/**
	 * Counts disabled module definitions without constructing a filtered catalog.
	 *
	 * @return int Number of disabled module definitions.
	 */
	public function disabledCount(): int {
		return $this->enabledDisabledCounts()['disabled'];
	}

	/**
	 * Counts enabled and disabled module definitions in one pass.
	 *
	 * @return array{enabled:int,disabled:int}
	 */
	public function enabledDisabledCounts(): array {
		if($this->countPayload!==null){
			return $this->countPayload;
		}
		$enabled=0;
		$disabled=0;
		foreach($this->entries as $definition){
			if($definition->enabled()){
				$enabled++;
				continue;
			}
			$disabled++;
		}
		return $this->countPayload=['enabled'=>$enabled, 'disabled'=>$disabled];
	}

	/**
	 * Returns enabled and disabled module names in one catalog pass.
	 *
	 * @return array{enabled:array<int,string>,disabled:array<int,string>}
	 */
	private function enabledDisabledNames(): array {
		if($this->namePairPayload!==null){
			return $this->namePairPayload;
		}
		$enabled=[];
		$disabled=[];
		foreach($this->entries as $name=>$definition){
			if($definition->enabled()){
				$enabled[]=$name;
				continue;
			}
			$disabled[]=$name;
		}
		return $this->namePairPayload=['enabled'=>$enabled, 'disabled'=>$disabled];
	}

	/**
	 * Counts module definitions in this catalog view.
	 *
	 *
	 * @return int Number of module definitions.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Iterates module definitions in deterministic catalog order.
	 *
	 *
	 * @return Traversable<int,ModuleDefinition> Iterator over module definitions.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	/**
	 * Returns module definitions in bootstrap order.
	 *
	 * @return array<int,array<string,mixed>> Module definitions converted to arrays.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$definitions=[];
		foreach($this->entries as $definition){
			$definitions[]=$definition->toArray();
		}
		return $this->arrayPayload=$definitions;
	}

	/**
	 * Serializes the module catalog for JSON output.
	 *
	 * @return array<int,array<string,mixed>> Module definitions converted to arrays.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
