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
 * Immutable catalog of dialback event snapshots for diagnostics and filtering.
 *
 * The catalog normalizes raw registry arrays into {@see DialbackEvent} values,
 * indexes them by event name, and sorts keys for stable serialized output. Scope
 * and selection methods return new catalogs so callers can derive views without
 * mutating the original registry snapshot.
 */
final class DialbackCatalog implements Countable, IteratorAggregate, \JsonSerializable {

	/**
	 * Event snapshots keyed by exact event name.
	 *
	 * @var array<string, DialbackEvent>
	 */
	private readonly array $entries;

	/** @var array{prefix:?string, callback_count:int, entries:array<int, array{name:string, callback_count:int, callbacks:array<int, array<string, int|string|null>>}>}|null */
	private ?array $arrayPayload=null;

	private ?string $scopePrefixPayload=null;
	private ?self $scopePayload=null;
	private ?array $onlyNamesPayload=null;
	private ?self $onlyPayload=null;

	/**
	 * Normalizes registry entries into a stable catalog view.
	 *
	 * Array values are interpreted as callback lists and converted into
	 * {@see DialbackEvent} snapshots using their array key as the event name.
	 * Non-event values are ignored, and the resulting catalog is sorted by event
	 * name to keep JSON and documentation diffs deterministic.
	 *
	 * @param ?string $prefix Prefix represented by this catalog view, or null for an unscoped catalog.
	 * @param array<string|int, DialbackEvent|array<int|string, mixed>|mixed> $entries Raw registry entries or event snapshots.
	 */
	public function __construct(
		private readonly ?string $prefix=null,
		array $entries=[],
		bool $normalized=false
	){
		if($normalized){
			$this->entries=$entries;
			return;
		}
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

	/**
	 * Returns the prefix used to derive this catalog view.
	 *
	 * @return ?string Prefix filter for this view, or null when unscoped.
	 */
	public function prefix(): ?string {
		return $this->prefix;
	}

	/**
	 * Lists every event snapshot in deterministic event-name order.
	 *
	 * The returned array is value-indexed for iteration and serialization, while
	 * lookup operations continue to use the internal event-name map.
	 *
	 * @return array<int, DialbackEvent> Event snapshots in sorted order.
	 */
	public function all(): array {
		return array_values($this->entries);
	}

	/**
	 * Lists event names available in this catalog view.
	 *
	 * @return array<int, string> Sorted event names keyed by numeric offset.
	 */
	public function names(): array {
		return array_keys($this->entries);
	}

	/**
	 * Returns the first event in deterministic catalog order.
	 *
	 * @return ?DialbackEvent First event snapshot, or null when the catalog is empty.
	 */
	public function first(): ?DialbackEvent {
		foreach($this->entries as $entry){
			return $entry;
		}
		return null;
	}

	/**
	 * Looks up an event snapshot by exact event name.
	 *
	 * Event names are trimmed before lookup. Blank names never match, and prefix
	 * filters are not applied here because the catalog already represents its
	 * active scope.
	 *
	 * @param string $eventName Event name to resolve from this catalog view.
	 * @return ?DialbackEvent Matching event snapshot, or null when absent.
	 */
	public function get(string $eventName): ?DialbackEvent {
		$eventName=trim($eventName);
		return $eventName!=='' ? ($this->entries[$eventName] ?? null) : null;
	}

	/**
	 * Indicates whether an event name exists in this catalog view.
	 *
	 * @param string $eventName Event name to test after trimming.
	 * @return bool True when the catalog contains the requested event.
	 */
	public function has(string $eventName): bool {
		$eventName=trim($eventName);
		return $eventName!=='' && isset($this->entries[$eventName]);
	}

	/**
	 * Counts events in this catalog view.
	 *
	 * @return int Number of event snapshots after normalization and filtering.
	 */
	public function count(): int {
		return count($this->entries);
	}

	/**
	 * Counts callbacks across every event in this catalog view.
	 *
	 * @return int Sum of callback counts from all event snapshots.
	 */
	public function callbackCount(): int {
		$count=0;
		foreach($this->entries as $entry){
			$count+=$entry->callbackCount();
		}
		return $count;
	}

	/**
	 * Derives a catalog containing only events with the requested prefix.
	 *
	 * Null and blank prefixes return the current catalog unchanged because they
	 * represent an unscoped view. Non-blank prefixes are trimmed and matched from
	 * the start of each event name.
	 *
	 * @param ?string $prefix Event-name prefix used to filter the catalog.
	 * @return self Current catalog for an empty prefix, otherwise a scoped catalog snapshot.
	 */
	public function scope(?string $prefix): self {
		$prefix=static::normalizePrefix($prefix);
		if($prefix===null){
			return $this;
		}
		if($this->scopePrefixPayload===$prefix && $this->scopePayload!==null){
			return $this->scopePayload;
		}
		$scoped=[];
		foreach($this->entries as $name=>$entry){
			if(str_starts_with($name, $prefix)){
				$scoped[$name]=$entry;
			}
		}
		$this->scopePrefixPayload=$prefix;
		return $this->scopePayload=new self($prefix, $scoped, true);
	}

	/**
	 * Derives a catalog containing only explicitly named events.
	 *
	 * Candidate names are trimmed and ignored when blank or absent from the
	 * current catalog. Duplicate names collapse to one entry because the catalog
	 * remains keyed by exact event name.
	 *
	 * @param array<int|string, mixed> $eventNames Event names to preserve from this catalog.
	 * @return self New catalog snapshot preserving this catalog's prefix metadata.
	 */
	public function only(array $eventNames): self {
		if($this->onlyNamesPayload===$eventNames && $this->onlyPayload!==null){
			return $this->onlyPayload;
		}
		$selected=[];
		foreach($eventNames as $eventName){
			$eventName=trim((string)$eventName);
			if($eventName==='' || !isset($this->entries[$eventName])){
				continue;
			}
			$selected[$eventName]=$this->entries[$eventName];
		}
		ksort($selected);
		$this->onlyNamesPayload=$eventNames;
		return $this->onlyPayload=new self($this->prefix, $selected, true);
	}

	/**
	 * Iterates event snapshots in deterministic event-name order.
	 *
	 * @return Traversable<int, DialbackEvent> Iterator over catalog event snapshots.
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator($this->all());
	}

	/**
	 * Serializes catalog metadata and event diagnostics.
	 *
	 * Entries are emitted as value-indexed event payloads so JSON consumers can
	 * render the catalog in sorted order without relying on object key ordering.
	 *
	 * @return array{prefix: ?string, callback_count: int, entries: array<int, array{name: string, callback_count: int, callbacks: array<int, array<string, int|string|null>>}>} Catalog diagnostic payload.
	 */
	public function toArray(): array {
		if($this->arrayPayload!==null){
			return $this->arrayPayload;
		}
		$callbackCount=0;
		$entries=[];
		foreach($this->entries as $event){
			$callbackCount+=$event->callbackCount();
			$entries[]=$event->toArray();
		}
		return $this->arrayPayload=[
			'prefix'=>$this->prefix,
			'callback_count'=>$callbackCount,
			'entries'=>$entries,
		];
	}

	/**
	 * Serializes the catalog diagnostic description for JSON output.
	 *
	 * @return array{prefix: ?string, callback_count: int, entries: array<int, array{name: string, callback_count: int, callbacks: array<int, array<string, int|string|null>>}>} Catalog diagnostic payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Normalizes optional prefix filters for catalog scoping.
	 *
	 * Blank values collapse to null, allowing callers to treat missing and
	 * whitespace-only filters as an unscoped catalog request.
	 *
	 * @param ?string $prefix Prefix candidate provided by a caller.
	 * @return ?string Trimmed prefix, or null when no filtering should be applied.
	 */
	private static function normalizePrefix(?string $prefix): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix);
		return $prefix!=='' ? $prefix : null;
	}
}
