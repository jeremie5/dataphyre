<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Collects panel documentation entries into a searchable, serializable catalog.
 *
 * The catalog is the aggregation point for panel documentation surfaces. It normalizes entry ids, replaces entries by id, filters by category and publication status, produces deterministic ordering for UI rendering, and exports aggregate counts for diagnostics and operator-facing manifests.
 */
final class PanelDocumentationCatalog implements \JsonSerializable {

	/** @var array<string, PanelDocumentationEntry> */
	private array $entries=[];
	private array $meta=[];

	/**
	 * Builds a catalog from entry objects, array payloads, or keyed shorthand entries.
	 *
	 * String keys on array entries become the entry id when the payload does not already provide one. Every item is routed through register(), so constructor input follows the same normalization, replacement, and PanelDocumentationEntry conversion rules as runtime registration.
	 *
	 * @param array<int|string,PanelDocumentationEntry|array|string> $entries Initial entries or entry payloads.
	 */
	public function __construct(array $entries=[]) {
		foreach($entries as $key=>$entry){
			if(is_array($entry) && !isset($entry['id']) && is_string($key)){
				$entry['id']=$key;
			}
			$this->register($entry);
		}
	}

	/**
	 * Creates a documentation catalog for fluent registration.
	 *
	 *
	 * @return self New catalog seeded with the supplied entries.
	 */
	public static function make(array $entries=[]): self {
		return new self($entries);
	}

	/**
	 * Retrieves an existing entry by id or creates it when absent.
	 *
	 * Entry ids are normalized with Resource naming rules. Blank ids fall back to documentation_entry so builders always receive a mutable entry object instead of a null branch. The title is only used when a new entry is created.
	 *
	 * @param string $id Requested documentation entry id.
	 * @param string $title Optional title used for newly created entries.
	 * @return PanelDocumentationEntry Existing or newly created entry stored in the catalog.
	 */
	public function entry(string $id, string $title=''): PanelDocumentationEntry {
		$id=Resource::normalizeName($id);
		if($id===''){
			$id='documentation_entry';
		}
		if(!isset($this->entries[$id])){
			$this->entries[$id]=PanelDocumentationEntry::make($id, $title);
		}
		return $this->entries[$id];
	}

	/**
	 * Registers or replaces a documentation entry by normalized id.
	 *
	 * Inputs are normalized through PanelDocumentationEntry::from(), allowing callers to pass full entry objects, array payloads, or shorthand ids. Registering another entry with the same id replaces the previous one, keeping the catalog idempotent for repeated bootstrap passes.
	 *
	 * @param PanelDocumentationEntry|array|string $entry Entry object, array payload, or shorthand id.
	 * @param ?string $title Optional title used when creating from shorthand input.
	 * @return PanelDocumentationEntry Normalized entry stored in the catalog.
	 */
	public function register(PanelDocumentationEntry|array|string $entry, ?string $title=null): PanelDocumentationEntry {
		$entry=PanelDocumentationEntry::from($entry, $title);
		$this->entries[$entry->id()]=$entry;
		return $entry;
	}

	/**
	 * Checks whether the catalog contains a documentation entry id.
	 *
	 * The lookup uses the same Resource id normalization as entry() and register(), so callers can check user-facing labels or already-normalized ids consistently.
	 *
	 * @param string $id Documentation entry id to check.
	 * @return bool True when a normalized id exists in the catalog.
	 */
	public function has(string $id): bool {
		return isset($this->entries[Resource::normalizeName($id)]);
	}

	/**
	 * Retrieves a documentation entry by normalized id.
	 *
	 *
	 * @return ?PanelDocumentationEntry Stored entry, or null when the id is absent.
	 */
	public function get(string $id): ?PanelDocumentationEntry {
		return $this->entries[Resource::normalizeName($id)] ?? null;
	}

	/**
	 * Lists catalog entries with optional category and status filters.
	 *
	 * Category filters compare the exact category string after trimming caller input. Status filters normalize the requested status with Resource rules. Results are sorted by category and then title so JSON exports remain stable across registration order.
	 *
	 * @param ?string $category Optional category name to include.
	 * @param ?string $status Optional normalized publication or lifecycle status to include.
	 * @return array<int,PanelDocumentationEntry> Sorted entry objects matching the filters.
	 */
	public function entries(?string $category=null, ?string $status=null): array {
		$entries=array_values($this->entries);
		if($category!==null && trim($category)!==''){
			$category=trim($category);
			$entries=array_values(array_filter($entries, static fn(PanelDocumentationEntry $entry): bool => $entry->category()===$category));
		}
		if($status!==null && trim($status)!==''){
			$status=Resource::normalizeName($status);
			$entries=array_values(array_filter($entries, static fn(PanelDocumentationEntry $entry): bool => $entry->status()===$status));
		}
		usort($entries, static function(PanelDocumentationEntry $a, PanelDocumentationEntry $b): int {
			return [$a->category(), $a->title()] <=> [$b->category(), $b->title()];
		});
		return $entries;
	}

	/**
	 * Searches documentation entries using each entry's matching rules.
	 *
	 * Search operates on the sorted entries() result, preserving deterministic output while delegating text matching, keyword matching, and field coverage to PanelDocumentationEntry::matches().
	 *
	 * @param string $query Search text supplied by a documentation surface or diagnostic tool.
	 * @return array<int,PanelDocumentationEntry> Entries whose searchable content matches the query.
	 */
	public function search(string $query): array {
		return array_values(array_filter($this->entries(), static fn(PanelDocumentationEntry $entry): bool => $entry->matches($query)));
	}

	/**
	 * Counts documentation entries by category.
	 *
	 * Categories are sorted by key so navigation facets and generated manifests remain deterministic.
	 *
	 * @return array<string,int> Category names mapped to entry counts.
	 */
	public function categories(): array {
		$counts=[];
		foreach($this->entries as $entry){
			$category=$entry->category();
			$counts[$category]=($counts[$category] ?? 0)+1;
		}
		ksort($counts);
		return $counts;
	}

	/**
	 * Counts documentation entries by status.
	 *
	 * Status keys are sorted for deterministic manifests and stable UI facets.
	 *
	 * @return array<string,int> Normalized status names mapped to entry counts.
	 */
	public function statuses(): array {
		$counts=[];
		foreach($this->entries as $entry){
			$status=$entry->status();
			$counts[$status]=($counts[$status] ?? 0)+1;
		}
		ksort($counts);
		return $counts;
	}

	/**
	 * Merges catalog-level metadata into future manifests.
	 *
	 * Array input merges multiple keys at once; string input stores one key when the trimmed key is not blank. Metadata is preserved as caller-owned context for generated pages, release notes, diagnostics, or catalog annotations.
	 *
	 * @param array<string,mixed>|string $key Metadata map or individual metadata key.
	 * @param mixed $value Metadata value used when $key is a string.
	 * @return self Same catalog for fluent registration.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Builds the JSON-safe catalog manifest used by documentation surfaces.
	 *
	 * The manifest includes sorted entry payloads, category and status facets, aggregate counts for examples, API references, and links, plus caller-supplied metadata overlaid on catalog metadata. This method is read-only; it does not cache or mutate entry state.
	 *
	 * @param array<string,mixed> $meta Metadata overlaid on the catalog's stored metadata for this export.
	 * @return array{type:string,entry_count:int,category_count:int,status_count:int,example_count:int,api_reference_count:int,link_count:int,categories:array<string,int>,statuses:array<string,int>,entries:list<array<string,mixed>>,meta:array<string,mixed>} Serializable documentation catalog manifest.
	 */
	public function manifest(array $meta=[]): array {
		$entries=array_map(static fn(PanelDocumentationEntry $entry): array => $entry->toArray(), $this->entries());
		$examples=0;
		$api=0;
		$links=0;
		foreach($entries as $entry){
			$examples+=count((array)($entry['examples'] ?? []));
			$api+=count((array)($entry['api'] ?? []));
			$links+=count((array)($entry['links'] ?? []));
		}
		return [
			'type'=>'panel_documentation_catalog',
			'entry_count'=>count($entries),
			'category_count'=>count($this->categories()),
			'status_count'=>count($this->statuses()),
			'example_count'=>$examples,
			'api_reference_count'=>$api,
			'link_count'=>$links,
			'categories'=>$this->categories(),
			'statuses'=>$this->statuses(),
			'entries'=>$entries,
			'meta'=>array_replace($this->meta, $meta),
		];
	}

	/**
	 * Exposes catalog counts, groupings, entries, and renderer metadata.
	 *
	 * @return array{type:string,entry_count:int,category_count:int,status_count:int,example_count:int,api_reference_count:int,link_count:int,categories:array<string,int>,statuses:array<string,int>,entries:list<array<string,mixed>>,meta:array<string,mixed>} Serializable documentation catalog manifest.
	 */
	public function toArray(): array {
		return $this->manifest();
	}

	/**
	 * Serializes the documentation catalog manifest for renderer output.
	 *
	 * @return array{type:string,entry_count:int,category_count:int,status_count:int,example_count:int,api_reference_count:int,link_count:int,categories:array<string,int>,statuses:array<string,int>,entries:list<array<string,mixed>>,meta:array<string,mixed>} Serializable documentation catalog manifest.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
