<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Registry and manifest facade for Panel media collections.
 *
 * The library owns named `PanelMediaCollection` definitions, lazily creates
 * missing collections on access, and exposes a compact manifest for operator
 * UI, upload validation, and diagnostics. It does not persist files;
 * persistence and transformation are delegated to the collection/item layers.
 */
final class PanelMediaLibrary implements \JsonSerializable {

	/** @var array<string, PanelMediaCollection> Registered media collections keyed by normalized collection name. */
	private array $collections=[];

	/** @var array<string, mixed> Library-level metadata merged into manifest output. */
	private array $metadata=[];

	/**
	 * Registers an initial set of media collections.
	 *
	 * Associative array keys become collection names when the collection
	 * payload omits an explicit `name`, making configuration maps concise while
	 * still flowing through `PanelMediaCollection::from()` normalization.
	 *
	 * @param array<int|string, PanelMediaCollection|array|string> $collections Initial collection definitions.
	 */
	public function __construct(array $collections=[]) {
		foreach($collections as $key=>$collection){
			if(is_array($collection) && !isset($collection['name']) && is_string($key)){
				$collection['name']=$key;
			}
			$this->register($collection);
		}
	}

	/**
	 * Creates a media library with optional initial collection definitions.
	 *
	 * @param array<int|string, PanelMediaCollection|array|string> $collections Initial collection definitions.
	 * @return self New mutable media library.
	 */
	public static function make(array $collections=[]): self {
		return new self($collections);
	}

	/**
	 * Registers or replaces a named media collection.
	 *
	 * @param PanelMediaCollection|array|string $collection Collection object, payload, or collection name.
	 * @return PanelMediaCollection Normalized collection stored under its canonical name.
	 */
	public function register(PanelMediaCollection|array|string $collection): PanelMediaCollection {
		$collection=PanelMediaCollection::from($collection);
		$this->collections[$collection->name()]=$collection;
		return $collection;
	}

	/**
	 * Returns an existing collection or creates a default definition on demand.
	 *
	 * Names are normalized through `Resource::normalizeName()` and empty names
	 * fall back to `default`. Lazy creation lets upload and validation flows
	 * address a collection before a full media manifest has been declared.
	 *
	 * @param string $name Requested collection name.
	 * @return PanelMediaCollection Registered or newly created collection.
	 */
	public function collection(string $name='default'): PanelMediaCollection {
		$name=Resource::normalizeName($name) ?: 'default';
		if(!isset($this->collections[$name])){
			$this->collections[$name]=PanelMediaCollection::make($name);
		}
		return $this->collections[$name];
	}

	/**
	 * Checks whether a collection has been explicitly registered.
	 *
	 * @param string $name Collection name to normalize and inspect.
	 * @return bool `true` when a collection exists without triggering lazy creation.
	 */
	public function has(string $name): bool {
		return isset($this->collections[Resource::normalizeName($name)]);
	}

	/**
	 * Returns the registered collection objects keyed by collection name.
	 *
	 * @return array<string, PanelMediaCollection> Live collection registry; callers can inspect or continue configuring collections.
	 */
	public function collections(): array {
		return $this->collections;
	}

	/**
	 * Creates a media item in the named collection.
	 *
	 * The collection is resolved lazily, then file shape and caller attributes
	 * are delegated to `PanelMediaCollection::item()` so collection-specific
	 * validation, variants, and metadata rules remain centralized.
	 *
	 * @param string $collection Target collection name.
	 * @param array<string, mixed> $file Uploaded or referenced file descriptor.
	 * @param array<string, mixed> $attributes Caller metadata for the media item.
	 * @return PanelMediaItem Created media item.
	 */
	public function item(string $collection, array $file, array $attributes=[]): PanelMediaItem {
		return $this->collection($collection)->item($file, $attributes);
	}

	/**
	 * Validates a file descriptor against the named collection.
	 *
	 * Validation is delegated to the collection definition and can lazily create
	 * the collection if it has not been registered. The returned payload is the
	 * collection's structured validation result.
	 *
	 * @param string $collection Target collection name.
	 * @param array<string, mixed> $file Uploaded or referenced file descriptor.
	 * @return array<string, mixed> Validation outcome emitted by the collection.
	 */
	public function validate(string $collection, array $file): array {
		return $this->collection($collection)->validate($file);
	}

	/**
	 * Adds library-level metadata to future manifest output.
	 *
	 * Array input stores each key as a string. Single-key input preserves the
	 * supplied key exactly, allowing callers to attach diagnostics, source
	 * labels, or UI grouping hints without modifying collections.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single metadata key.
	 * @param mixed $value Value used when `$key` is a string.
	 * @return self Same library for fluent configuration.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			foreach($key as $name=>$metaValue){
				$this->metadata[(string)$name]=$metaValue;
			}
			return $this;
		}
		$this->metadata[$key]=$value;
		return $this;
	}

	/**
	 * Serializes the media registry for UI and diagnostics.
	 *
	 * The manifest includes every collection's own manifest plus aggregate
	 * collection and variant counts. Per-call metadata is merged over stored
	 * library metadata so request-specific context can be included without
	 * mutating the library.
	 *
	 * @param array<string, mixed> $meta Per-call metadata merged into output.
	 * @return array{collection_count:int, variant_count:int, collections:array<string, mixed>, metadata:array<string, mixed>} Library manifest.
	 */
	public function manifest(array $meta=[]): array {
		$collections=[];
		$variants=0;
		foreach($this->collections as $name=>$collection){
			$definition=$collection->manifest();
			$collections[$name]=$definition;
			$variants+=count($definition['variants'] ?? []);
		}
		return [
			'collection_count'=>count($collections),
			'variant_count'=>$variants,
			'collections'=>$collections,
			'metadata'=>array_merge($this->metadata, $meta),
		];
	}

	/**
	 * Serializes the current media library manifest with stored metadata.
	 *
	 * @return array<string, mixed> Media library manifest without extra call-site metadata.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the media library manifest for JSON output.
	 *
	 * @return array<string, mixed> Serializable media library manifest.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
