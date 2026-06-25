<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Mutable documentation entry consumed by panel documentation catalogs.
 *
 * Entries normalize a stable identifier, title, category, status, summary,
 * related API references, examples, links, tags, and metadata into a JSON-safe
 * payload that panel documentation surfaces can render and search.
 */
final class PanelDocumentationEntry implements \JsonSerializable {

	/** @var string Normalized documentation entry identifier. */
	private string $id;
	/** @var string Display title shown in documentation indexes. */
	private string $title;
	/** @var string Documentation category used for grouping. */
	private string $category='General';
	/** @var string Normalized lifecycle status such as draft or published. */
	private string $status='draft';
	/** @var string Short searchable description of the entry. */
	private string $summary='';
	/** @var array<int|string, string> API references associated with the entry. */
	private array $api=[];
	/** @var array<int, array{title: string, language: string, code: string}> Runnable or illustrative examples. */
	private array $examples=[];
	/** @var array<int, array{label: string, target: string}> Related documentation links. */
	private array $links=[];
	/** @var array<int, string> Normalized unique tags. */
	private array $tags=[];
	/** @var array<string, mixed> Additional documentation metadata. */
	private array $meta=[];

	/**
	 * Creates a documentation entry with normalized identity.
	 *
	 * Empty titles fall back to a humanized version of the normalized id so every
	 * serialized entry has a usable display label.
	 *
	 * @param string $id Entry identifier before panel resource normalization.
	 * @param string $title Optional display title.
	 */
	public function __construct(string $id, string $title='') {
		$this->id=Resource::normalizeName($id);
		$this->title=trim($title)!=='' ? trim($title) : ucwords(str_replace('_', ' ', $this->id));
	}

	/**
	 * Creates a documentation entry from id and optional title.
	 *
	 *
	 * @param string $id Entry identifier before normalization.
	 * @param string $title Optional display title.
	 * @return self New documentation entry.
	 */
	public static function make(string $id, string $title=''): self {
		return new self($id, $title);
	}

	/**
	 * Hydrates a documentation entry from an existing entry, array, or id string.
	 *
	 * Array definitions may include `id` or `name`, title, category, status,
	 * summary, api, examples, links, tags, and meta. Invalid or blank nested
	 * payloads are ignored by the same fluent methods used for manual building.
	 *
	 * @param self|array<string, mixed>|string $entry Existing entry, definition array, or entry id.
	 * @param ?string $title Optional title override for string definitions.
	 * @return self Hydrated documentation entry.
	 */
	public static function from(self|array|string $entry, ?string $title=null): self {
		if($entry instanceof self){
			return $entry;
		}
		if(is_string($entry)){
			return new self($entry, (string)($title ?? ''));
		}
		$id=trim((string)($entry['id'] ?? $entry['name'] ?? ''));
		$instance=new self($id!=='' ? $id : 'documentation_entry', (string)($entry['title'] ?? $title ?? ''));
		if(isset($entry['category'])){
			$instance->category((string)$entry['category']);
		}
		if(isset($entry['status'])){
			$instance->status((string)$entry['status']);
		}
		if(isset($entry['summary'])){
			$instance->summary((string)$entry['summary']);
		}
		if(isset($entry['api'])){
			$instance->api($entry['api']);
		}
		foreach((array)($entry['examples'] ?? []) as $example){
			if(is_array($example)){
				$instance->example(
					(string)($example['title'] ?? 'Example'),
					(string)($example['code'] ?? ''),
					(string)($example['language'] ?? 'php')
				);
			}
		}
		foreach((array)($entry['links'] ?? []) as $link){
			if(is_array($link)){
				$instance->link((string)($link['label'] ?? $link['target'] ?? 'Link'), (string)($link['target'] ?? ''));
			}
		}
		foreach((array)($entry['tags'] ?? []) as $tag){
			$instance->tag((string)$tag);
		}
		if(isset($entry['meta']) && is_array($entry['meta'])){
			$instance->meta($entry['meta']);
		}
		return $instance;
	}

	/**
	 * Returns the normalized entry identifier.
	 *
	 * @return string Stable entry id used by catalogs and URLs.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Returns the entry display title.
	 *
	 * @return string Human-readable title.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Reads or updates the category used to group the entry.
	 *
	 * Calling without an argument returns the current category. Blank updates
	 * reset the entry to the default `General` category.
	 *
	 * @param ?string $category Optional category update.
	 * @return string|self Current category when reading, otherwise this entry after mutation.
	 */
	public function category(?string $category=null): string|self {
		if($category===null){
			return $this->category;
		}
		$category=trim($category);
		$this->category=$category!=='' ? $category : 'General';
		return $this;
	}

	/**
	 * Reads or updates the normalized documentation status.
	 *
	 * Status updates use panel resource normalization; blank statuses reset to
	 * `draft`.
	 *
	 * @param ?string $status Optional status update.
	 * @return string|self Current status when reading, otherwise this entry after mutation.
	 */
	public function status(?string $status=null): string|self {
		if($status===null){
			return $this->status;
		}
		$status=Resource::normalizeName($status);
		$this->status=$status!=='' ? $status : 'draft';
		return $this;
	}

	/**
	 * Reads or updates the short searchable summary.
	 *
	 * @param ?string $summary Optional summary update.
	 * @return string|self Current summary when reading, otherwise this entry after mutation.
	 */
	public function summary(?string $summary=null): string|self {
		if($summary===null){
			return $this->summary;
		}
		$this->summary=trim($summary);
		return $this;
	}

	/**
	 * Appends API references associated with this documentation entry.
	 *
	 * String input appends one reference. Array input accepts either numeric
	 * values or named references; blank values are ignored and duplicate numeric
	 * references are collapsed after insertion.
	 *
	 * @param array<int|string, mixed>|string $api API reference or reference list.
	 * @return self This entry with API references updated.
	 */
	public function api(array|string $api): self {
		foreach((array)$api as $key=>$value){
			$value=trim((string)$value);
			if($value===''){
				continue;
			}
			if(is_string($key) && !is_numeric($key)){
				$this->api[$key]=$value;
				continue;
			}
			$this->api[]=$value;
		}
		$this->api=array_values(array_unique($this->api));
		return $this;
	}

	/**
	 * Appends a code example to the entry.
	 *
	 * Blank code is ignored so serialized entries do not contain empty example
	 * shells. Languages are normalized for renderer syntax highlighters.
	 *
	 * @param string $title Example title; defaults to `Example` when blank.
	 * @param string $code Example source code.
	 * @param string $language Syntax language identifier.
	 * @return self This entry with the example appended when code is present.
	 */
	public function example(string $title, string $code, string $language='php'): self {
		$title=trim($title);
		$code=trim($code);
		if($code===''){
			return $this;
		}
		$this->examples[]=[
			'title'=>$title!=='' ? $title : 'Example',
			'language'=>Resource::normalizeName($language) ?: 'php',
			'code'=>$code,
		];
		return $this;
	}

	/**
	 * Appends a related documentation link.
	 *
	 * Blank targets are ignored. Blank labels fall back to the target string so
	 * rendered links always have display text.
	 *
	 * @param string $label Link label.
	 * @param string $target URL, route, or documentation target.
	 * @return self This entry with the link appended when target is present.
	 */
	public function link(string $label, string $target): self {
		$target=trim($target);
		if($target===''){
			return $this;
		}
		$label=trim($label);
		$this->links[]=[
			'label'=>$label!=='' ? $label : $target,
			'target'=>$target,
		];
		return $this;
	}

	/**
	 * Adds one normalized tag to the entry.
	 *
	 * Tags are normalized through panel resource naming and de-duplicated.
	 *
	 * @param string $tag Tag value before normalization.
	 * @return self This entry with the tag appended when non-empty and unique.
	 */
	public function tag(string $tag): self {
		$tag=Resource::normalizeName($tag);
		if($tag!=='' && !in_array($tag, $this->tags, true)){
			$this->tags[]=$tag;
		}
		return $this;
	}

	/**
	 * Adds multiple normalized tags to the entry.
	 *
	 *
	 * @param array<int|string, mixed> $tags Tag values before normalization.
	 * @return self This entry with unique non-empty tags appended.
	 */
	public function tags(array $tags): self {
		foreach($tags as $tag){
			$this->tag((string)$tag);
		}
		return $this;
	}

	/**
	 * Adds metadata to the documentation entry.
	 *
	 * Array input shallow-merges metadata. String input writes a single key when
	 * the key is non-blank.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single metadata key.
	 * @param mixed $value Metadata value used with a string key.
	 * @return self This entry with metadata updated.
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
	 * Tests whether the entry matches a documentation search query.
	 *
	 * Empty queries match every entry. Non-empty queries are compared
	 * case-insensitively against id, title, category, status, summary, API
	 * references, and tags.
	 *
	 * @param string $query Search query from documentation UI.
	 * @return bool True when the entry should be included in search results.
	 */
	public function matches(string $query): bool {
		$query=strtolower(trim($query));
		if($query===''){
			return true;
		}
		$haystack=strtolower(implode(' ', [
			$this->id,
			$this->title,
			$this->category,
			$this->status,
			$this->summary,
			implode(' ', $this->api),
			implode(' ', $this->tags),
		]));
		return str_contains($haystack, $query);
	}

	/**
	 * Serializes the documentation entry for panel and documentation renderers.
	 *
	 * @return array{id: string, title: string, category: string, status: string, summary: string, api: array<int|string, string>, examples: array<int, array{title: string, language: string, code: string}>, links: array<int, array{label: string, target: string}>, tags: array<int, string>, meta: array<string, mixed>} Entry payload.
	 */
	public function toArray(): array {
		return [
			'id'=>$this->id,
			'title'=>$this->title,
			'category'=>$this->category,
			'status'=>$this->status,
			'summary'=>$this->summary,
			'api'=>$this->api,
			'examples'=>$this->examples,
			'links'=>$this->links,
			'tags'=>$this->tags,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Serializes the documentation entry for renderer output.
	 *
	 * @return array{id: string, title: string, category: string, status: string, summary: string, api: array<int|string, string>, examples: array<int, array{title: string, language: string, code: string}>, links: array<int, array{label: string, target: string}>, tags: array<int, string>, meta: array<string, mixed>} Entry payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
