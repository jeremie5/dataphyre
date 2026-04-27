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

final class DialbackCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/** @var array<string, DialbackEvent> */
	private readonly array $entries;

	public function __construct(
		private readonly ?string $prefix=null,
		array $entries=[]
	){
		$normalized=[];
		foreach($entries as $key=>$entry){
			if(is_array($entry)){
				$entry=DialbackEvent::fromCallbacks((string)$key, $entry);
			}
			if(!$entry instanceof DialbackEvent){
				continue;
			}
			$normalized[$entry->name()]=$entry;
		}
		ksort($normalized);
		$this->entries=$normalized;
	}

	public function prefix(): ?string {
		return $this->prefix;
	}

	public function all(): array {
		return array_values($this->entries);
	}

	public function names(): array {
		return array_keys($this->entries);
	}

	public function first(): ?DialbackEvent {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	public function get(string $event_name): ?DialbackEvent {
		$event_name=trim($event_name);
		return $event_name!=='' ? ($this->entries[$event_name] ?? null) : null;
	}

	public function has(string $event_name): bool {
		return $this->get($event_name) instanceof DialbackEvent;
	}

	public function count(): int {
		return count($this->entries);
	}

	public function callbackCount(): int {
		$count=0;
		foreach($this->entries as $entry){
			$count+=$entry->callbackCount();
		}
		return $count;
	}

	public function scope(?string $prefix): self {
		$prefix=static::normalizePrefix($prefix);
		if($prefix===null){
			return $this;
		}
		$scoped=[];
		foreach($this->entries as $name=>$entry){
			if(str_starts_with($name, $prefix)){
				$scoped[$name]=$entry;
			}
		}
		return new self($prefix, $scoped);
	}

	public function only(array $event_names): self {
		$selected=[];
		foreach($event_names as $event_name){
			$event_name=trim((string)$event_name);
			if($event_name==='' || !isset($this->entries[$event_name])){
				continue;
			}
			$selected[$event_name]=$this->entries[$event_name];
		}
		return new self($this->prefix, $selected);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	public function toArray(): array {
		return [
			'prefix'=>$this->prefix,
			'callback_count'=>$this->callbackCount(),
			'entries'=>array_map(
				static fn(DialbackEvent $event): array => $event->toArray(),
				$this->all()
			),
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private static function normalizePrefix(?string $prefix): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix);
		return $prefix!=='' ? $prefix : null;
	}
}
