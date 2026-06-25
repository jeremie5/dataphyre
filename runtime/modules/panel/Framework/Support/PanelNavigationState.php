<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable navigation tree, active entry, grouping, and search state for a panel.
 *
 * Navigation state normalizes flat or nested entries, attaches children to named
 * parents, marks the active entry from the current request, groups top-level
 * entries for rendering, and carries global-search metadata alongside the tree.
 */
final class PanelNavigationState implements \JsonSerializable {

	/**
	 * Stores a fully normalized navigation state snapshot.
	*
	 * @param array<int, array<string, mixed>> $entries Top-level navigation tree.
	 * @param array<int, array<string, mixed>> $groups Grouped navigation tree for panel chrome.
	 * @param array<string, mixed> $active Active entry descriptor.
	 * @param array{query:string,result_count:int,results:array} $search Global search state.
	 * @param array<string, mixed> $meta Request, counts, and caller metadata.
	 */
	public function __construct(
		private readonly array $entries=[],
		private readonly array $groups=[],
		private readonly array $active=[],
		private readonly array $search=[],
		private readonly array $meta=[]
	){}

	/**
	 * Normalizes raw navigation entries into render-ready navigation state.
	*
	 * Raw entries may be flat with `parent`/`folder` references or already nested
	 * through `children`. The factory sorts entries, builds the tree, derives the
	 * active item from the request resource, propagates active-descendant flags,
	 * groups entries, and records entry/group counts in metadata.
	*
	 * @param array<int, array<string, mixed>|mixed> $entries Raw navigation entries.
	 * @param ?PanelRequest $request Current request used for active-state detection.
	 * @param array<string, mixed> $search Global search query and results.
	 * @param array<string, mixed> $meta Additional metadata for diagnostics.
	 * @return self Normalized navigation state.
	 */
	public static function make(array $entries=[], ?PanelRequest $request=null, array $search=[], array $meta=[]): self {
		$entries=array_values(array_filter(array_map(static fn(mixed $entry): ?array => is_array($entry) ? self::normalizeEntry($entry) : null, $entries)));
		usort($entries, static function(array $left, array $right): int {
			return [(int)($left['sort'] ?? 100), (string)($left['label'] ?? '')] <=> [(int)($right['sort'] ?? 100), (string)($right['label'] ?? '')];
		});
		$active=self::activeEntry($entries, $request);
		$activeName=Resource::normalizeName((string)($active['name'] ?? ''));
		$entries=self::navigationTree($entries);
		foreach($entries as $index=>$entry){
			$entries[$index]=self::markActive($entry, $activeName);
		}
		$groups=self::groupEntries($entries, $active['name'] ?? '');
		return new self($entries, $groups, $active, self::normalizeSearch($search), array_replace([
			'request'=>$request?->toArray(),
			'entry_count'=>self::countEntries($entries),
			'group_count'=>count($groups),
		], $meta));
	}

	/**
	 * Returns the top-level navigation tree.
	 *
	 * @return array<int, array<string, mixed>> Nested entries with active flags.
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Returns every navigation entry flattened in tree order.
	 *
	 * @return array<int, array<string, mixed>> Flattened navigation entries.
	 */
	public function allEntries(): array {
		return self::flattenEntries($this->entries);
	}

	/**
	 * Returns navigation groups used by the panel sidebar/dashboard.
	 *
	 * @return array<int, array{label:string,count:int,active:bool,entries:array}>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Returns the active navigation entry inferred from the request.
	 *
	 * @return array<string, mixed> Active entry descriptor, or an empty array when none matches.
	 */
	public function active(): array {
		return $this->active;
	}

	/**
	 * Returns normalized global-search state attached to the navigation state.
	 *
	 * @return array{query:string,result_count:int,results:array}
	 */
	public function search(): array {
		return $this->search;
	}

	/**
	 * Returns request, count, and caller metadata for this navigation snapshot.
	 *
	 * @return array<string, mixed> Navigation metadata.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Finds one entry anywhere in the navigation tree by normalized name.
	*
	 * @param string $name Entry name to find.
	 * @return ?array<string, mixed> Matching entry or `null`.
	 */
	public function entry(string $name): ?array {
		$name=Resource::normalizeName($name);
		return self::findEntry($this->entries, $name);
	}

	/**
	 * Serializes the navigation state for Panel responses and diagnostics.
	 *
	 * @return array{entries:array,groups:array,active:array,search:array,meta:array}
	 */
	public function jsonSerialize(): array {
		return [
			'entries'=>$this->entries,
			'groups'=>$this->groups,
			'active'=>$this->active,
			'search'=>$this->search,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Groups top-level entries for panel navigation rendering.
	 *
	 * @param array<int, array<string, mixed>> $entries Top-level navigation entries.
	 * @param string $activeName Active entry name used to re-mark grouped copies.
	 * @return array<int, array{label:string,count:int,active:bool,entries:array}>
	 */
	private static function groupEntries(array $entries, string $activeName=''): array {
		$groups=[];
		foreach($entries as $entry){
			$group=trim((string)($entry['group'] ?? ''));
			$group=$group!=='' ? $group : 'Workspace';
			$name=Resource::normalizeName((string)($entry['name'] ?? ''));
			$entry=self::markActive($entry, Resource::normalizeName($activeName));
			$groups[$group] ??=[
				'label'=>$group,
				'count'=>0,
				'active'=>false,
				'entries'=>[],
			];
			$groups[$group]['count']+=self::countEntries([$entry]);
			$groups[$group]['active']=$groups[$group]['active'] || self::entryTreeActive($entry);
			$groups[$group]['entries'][]=$entry;
		}
		$groups=array_values($groups);
		usort($groups, static function(array $left, array $right): int {
			$leftSort=PHP_INT_MAX;
			$rightSort=PHP_INT_MAX;
			foreach($left['entries'] ?? [] as $entry){
				$leftSort=min($leftSort, (int)($entry['sort'] ?? 100));
			}
			foreach($right['entries'] ?? [] as $entry){
				$rightSort=min($rightSort, (int)($entry['sort'] ?? 100));
			}
			$leftSort=$leftSort===PHP_INT_MAX ? 100 : $leftSort;
			$rightSort=$rightSort===PHP_INT_MAX ? 100 : $rightSort;
			return [$leftSort, (string)($left['label'] ?? '')] <=> [$rightSort, (string)($right['label'] ?? '')];
		});
		return $groups;
	}

	/**
	 * Derives the active navigation descriptor from the current request.
	 *
	 * @param array<int, array<string, mixed>> $entries Normalized entries before tree building.
	 * @param ?PanelRequest $request Current panel request.
	 * @return array<string, mixed> Active entry descriptor or dashboard fallback.
	 */
	private static function activeEntry(array $entries, ?PanelRequest $request=null): array {
		$resource=$request?->resourceName();
		$operation=$request?->operation() ?? '';
		foreach(self::flattenEntries($entries) as $entry){
			$name=Resource::normalizeName((string)($entry['name'] ?? ''));
			$kind=Resource::normalizeName((string)($entry['kind'] ?? 'resource'));
			if($resource!==null && $name===Resource::normalizeName($resource) && in_array($kind, ['resource', 'page'], true)){
				return [
					'name'=>$name,
					'kind'=>$kind,
					'label'=>(string)($entry['label'] ?? $name),
					'url'=>(string)($entry['url'] ?? ''),
					'operation'=>$operation,
				];
			}
		}
		if($resource===null){
			return [
				'name'=>'',
				'kind'=>'dashboard',
				'label'=>PanelConfig::homeLabel(),
				'url'=>PanelConfig::url(),
				'operation'=>$operation,
			];
		}
		return [];
	}

	/**
	 * Normalizes global-search payload shape for navigation consumers.
	 *
	 * @param array<string, mixed> $search Raw search payload.
	 * @return array{query:string,result_count:int,results:array}
	 */
	private static function normalizeSearch(array $search): array {
		$query=trim((string)($search['query'] ?? ''));
		$results=is_array($search['results'] ?? null) ? array_values($search['results']) : [];
		return [
			'query'=>$query,
			'result_count'=>count($results),
			'results'=>$results,
		];
	}

	/**
	 * Normalizes one raw navigation entry into the canonical tree shape.
	 *
	 * @param array<string, mixed> $entry Raw navigation entry.
	 * @return array<string, mixed> Normalized entry with sorted normalized children.
	 */
	private static function normalizeEntry(array $entry): array {
		$name=Resource::normalizeName((string)($entry['name'] ?? ''));
		$label=trim((string)($entry['label'] ?? ''));
		$kind=Resource::normalizeName((string)($entry['kind'] ?? 'resource')) ?: 'resource';
		$meta=is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
		$children=[];
		foreach(is_array($entry['children'] ?? null) ? $entry['children'] : [] as $child){
			if(is_array($child)){
				$children[]=self::normalizeEntry($child);
			}
		}
		usort($children, static function(array $left, array $right): int {
			return [(int)($left['sort'] ?? 100), (string)($left['label'] ?? '')] <=> [(int)($right['sort'] ?? 100), (string)($right['label'] ?? '')];
		});
		return [
			'name'=>$name,
			'label'=>$label!=='' ? $label : ($name!=='' ? self::humanize($name) : 'Untitled'),
			'group'=>trim((string)($entry['group'] ?? '')) ?: null,
			'parent'=>Resource::normalizeName((string)($entry['parent'] ?? $entry['folder'] ?? $meta['parent'] ?? $meta['folder'] ?? '')) ?: null,
			'icon'=>trim((string)($entry['icon'] ?? '')) ?: null,
			'url'=>trim((string)($entry['url'] ?? '')),
			'sort'=>(int)($entry['sort'] ?? 100),
			'kind'=>in_array($kind, ['resource', 'page', 'navigation_item'], true) ? $kind : 'navigation_item',
			'description'=>trim((string)($entry['description'] ?? '')) ?: null,
			'badge'=>$entry['badge'] ?? null,
			'badge_tone'=>Resource::normalizeName((string)($entry['badge_tone'] ?? 'neutral')) ?: 'neutral',
			'new_tab'=>($entry['new_tab'] ?? false)===true,
			'folder_only'=>($entry['folder_only'] ?? false)===true,
			'active'=>($entry['active'] ?? false)===true,
			'children'=>$children,
			'meta'=>$meta,
		];
	}

	/**
	 * Builds a navigation tree by attaching entries to their named parents.
	 *
	 * Cycles degrade by returning the already-seen entry at the cycle point, which
	 * prevents malformed navigation config from recursing forever.
	 *
	 * @param array<int, array<string, mixed>> $entries Normalized flat or nested entries.
	 * @return array<int, array<string, mixed>> Top-level sorted navigation tree.
	 */
	private static function navigationTree(array $entries): array {
		$byName=[];
		$order=[];
		$childrenByParent=[];
		foreach($entries as $index=>$entry){
			$entry['children']=self::navigationTree(is_array($entry['children'] ?? null) ? $entry['children'] : []);
			$name=Resource::normalizeName((string)($entry['name'] ?? ''));
			$key=$name!=='' ? $name : '__entry_'.$index;
			$byName[$key]=$entry;
			$order[]=$key;
		}
		$attached=[];
		foreach($order as $key){
			$entry=$byName[$key];
			$parent=Resource::normalizeName((string)($entry['parent'] ?? ''));
			if($parent!=='' && $parent!==$key && isset($byName[$parent])){
				$childrenByParent[$parent] ??=[];
				$childrenByParent[$parent][]=$key;
				$attached[$key]=true;
			}
		}
		$build=function(string $key, array $seen=[]) use (&$build, &$byName, &$childrenByParent): array {
			if(isset($seen[$key])){
				return $byName[$key];
			}
			$seen[$key]=true;
			$entry=$byName[$key];
			$children=is_array($entry['children'] ?? null) ? array_values($entry['children']) : [];
			foreach($childrenByParent[$key] ?? [] as $childKey){
				if(isset($byName[$childKey])){
					$children[]=$build($childKey, $seen);
				}
			}
			usort($children, static function(array $left, array $right): int {
				return [(int)($left['sort'] ?? 100), (string)($left['label'] ?? '')] <=> [(int)($right['sort'] ?? 100), (string)($right['label'] ?? '')];
			});
			$entry['children']=$children;
			return $entry;
		};
		$top=[];
		foreach($order as $key){
			if(!isset($attached[$key])){
				$top[]=$build($key);
			}
		}
		usort($top, static function(array $left, array $right): int {
			return [(int)($left['sort'] ?? 100), (string)($left['label'] ?? '')] <=> [(int)($right['sort'] ?? 100), (string)($right['label'] ?? '')];
		});
		return $top;
	}

	/**
	 * Marks an entry and its descendants with active-state flags.
	 *
	 * @param array<string, mixed> $entry Navigation entry.
	 * @param string $activeName Normalized active entry name.
	 * @return array<string, mixed> Entry with `active` and `active_descendant` flags.
	 */
	private static function markActive(array $entry, string $activeName): array {
		$name=Resource::normalizeName((string)($entry['name'] ?? ''));
		$children=[];
		foreach(is_array($entry['children'] ?? null) ? $entry['children'] : [] as $child){
			if(is_array($child)){
				$children[]=self::markActive($child, $activeName);
			}
		}
		$entry['children']=$children;
		$entry['active']=$activeName!=='' && $name===$activeName;
		$entry['active_descendant']=self::childrenActive($children);
		return $entry;
	}

	/**
	 * Checks whether any child entry in a list is active.
	 *
	 * @param array<int, array<string, mixed>> $children Child entries.
	 * @return bool `true` when any child tree is active.
	 */
	private static function childrenActive(array $children): bool {
		foreach($children as $child){
			if(self::entryTreeActive($child)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks whether an entry or any descendant carries active state.
	 *
	 * @param array<string, mixed> $entry Navigation entry.
	 * @return bool `true` when the entry tree is active.
	 */
	private static function entryTreeActive(array $entry): bool {
		if(!empty($entry['active']) || !empty($entry['active_descendant'])){
			return true;
		}
		return self::childrenActive(is_array($entry['children'] ?? null) ? $entry['children'] : []);
	}

	/**
	 * Counts entries recursively for navigation metadata and group counts.
	 *
	 * @param array<int, array<string, mixed>> $entries Navigation entries.
	 * @return int Total entry count including descendants.
	 */
	private static function countEntries(array $entries): int {
		$count=0;
		foreach($entries as $entry){
			$count++;
			$count+=self::countEntries(is_array($entry['children'] ?? null) ? $entry['children'] : []);
		}
		return $count;
	}

	/**
	 * Flattens a navigation tree into parent-before-child order.
	 *
	 * @param array<int, array<string, mixed>> $entries Navigation tree.
	 * @return array<int, array<string, mixed>> Flattened entries.
	 */
	private static function flattenEntries(array $entries): array {
		$flat=[];
		foreach($entries as $entry){
			$flat[]=$entry;
			$flat=array_merge($flat, self::flattenEntries(is_array($entry['children'] ?? null) ? $entry['children'] : []));
		}
		return $flat;
	}

	/**
	 * Finds one entry recursively by normalized name.
	 *
	 * @param array<int, array<string, mixed>> $entries Navigation tree.
	 * @param string $name Normalized entry name.
	 * @return ?array<string, mixed> Matching entry or `null`.
	 */
	private static function findEntry(array $entries, string $name): ?array {
		foreach($entries as $entry){
			if(Resource::normalizeName((string)($entry['name'] ?? ''))===$name){
				return $entry;
			}
			$child=self::findEntry(is_array($entry['children'] ?? null) ? $entry['children'] : [], $name);
			if($child!==null){
				return $child;
			}
		}
		return null;
	}

	/**
	 * Converts a normalized navigation key into a fallback label.
	 *
	 * @param string $value Normalized entry name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Untitled' : ucwords($value);
	}
}
