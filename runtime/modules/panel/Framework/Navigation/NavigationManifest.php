<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes panel navigation hierarchy, active state, and search affordances.
 *
 * Navigation manifests accept a live navigation state, panel instance, manager,
 * serialized state, or global panel fallback. The resulting manifest preserves the
 * original hierarchy and adds a flattened index for clients.
 */
final class NavigationManifest {

	/**
	 * Stores the navigation source and request/search context.
	 *
	 * @param PanelNavigationState|PanelInstance|PanelManager|array|null $navigation Navigation source to describe.
	 * @param ?PanelRequest $request Current request used for active-state resolution.
	 * @param array<string,mixed> $search Search state or query metadata.
	 * @param array<string,mixed> $meta Manifest metadata and layout overrides.
	 */
	private function __construct(
		private readonly PanelNavigationState|PanelInstance|PanelManager|array|null $navigation=null,
		private readonly ?PanelRequest $request=null,
		private readonly array $search=[],
		private readonly array $meta=[]
	){}

	/**
	 * Creates a navigation manifest builder.
	 *
	 * @param PanelNavigationState|PanelInstance|PanelManager|array|null $navigation Navigation source to describe.
	 * @param ?PanelRequest $request Current request context.
	 * @param array<string,mixed> $search Search metadata included in the manifest.
	 * @param array<string,mixed> $meta Layout, mode, or caller metadata overrides.
	 * @return self New immutable manifest builder.
	 */
	public static function from(PanelNavigationState|PanelInstance|PanelManager|array|null $navigation=null, ?PanelRequest $request=null, array $search=[], array $meta=[]): self {
		return new self($navigation, $request, $search, $meta);
	}

	/**
	 * Materializes the navigation_manifest payload.
	 *
	 * @return array{type:string,layout:string,mode:string,entries:array<int,array<string,mixed>>,entries_flat:array<int,array<string,mixed>>,groups:array<int,array<string,mixed>>,active:array<string,mixed>,search:array<string,mixed>,counts:array<string,int>,capabilities:array<string,array<string,bool|int|string>>,meta:array<string,mixed>} Navigation manifest payload with hierarchical and flat entries.
	 */
	public function toArray(): array {
		$state=$this->state();
		$entries=is_array($state['entries'] ?? null) ? array_values($state['entries']) : [];
		$flat=self::flattenEntries($entries);
		$groups=is_array($state['groups'] ?? null) ? array_values($state['groups']) : [];
		$active=is_array($state['active'] ?? null) ? $state['active'] : [];
		$search=is_array($state['search'] ?? null) ? $state['search'] : [];
		$stateMeta=is_array($state['meta'] ?? null) ? $state['meta'] : [];
		$layout=(string)($this->meta['navigation_layout'] ?? $this->meta['layout'] ?? $stateMeta['layout'] ?? 'sidebar');
		$mode=(string)($this->meta['navigation_mode'] ?? $this->meta['mode'] ?? $stateMeta['mode'] ?? 'floating');
		$manifest=[
			'type'=>'navigation_manifest',
			'layout'=>$layout,
			'mode'=>$mode,
			'entries'=>$entries,
			'entries_flat'=>$flat,
			'groups'=>$groups,
			'active'=>$active,
			'search'=>$search,
			'counts'=>self::counts($entries, $flat, $groups, $stateMeta),
			'capabilities'=>self::capabilities($entries, $flat, $groups, $layout, $mode, $search),
			'meta'=>array_replace($stateMeta, $this->meta),
		];
		PanelTrace::record('navigation.manifest.described', [
			'entries'=>(int)($manifest['counts']['entries'] ?? 0),
			'groups'=>(int)($manifest['counts']['groups'] ?? 0),
			'layout'=>$layout,
			'mode'=>$mode,
			'active'=>(string)($active['name'] ?? ''),
		]);
		return $manifest;
	}

	/**
	 * Resolves the raw navigation state from the configured source.
	 *
	 * @return array<string,mixed> Serialized navigation state.
	 */
	private function state(): array {
		if($this->navigation instanceof PanelNavigationState){
			return $this->navigation->jsonSerialize();
		}
		if($this->navigation instanceof PanelInstance || $this->navigation instanceof PanelManager){
			return $this->navigation->navigationState($this->request, $this->search)->jsonSerialize();
		}
		if(is_array($this->navigation)){
			if(is_array($this->navigation['navigation_state'] ?? null)){
				return $this->navigation['navigation_state'];
			}
			if(is_array($this->navigation['entries'] ?? null) || is_array($this->navigation['groups'] ?? null)){
				return $this->navigation;
			}
			$entries=is_array($this->navigation['navigation'] ?? null) ? $this->navigation['navigation'] : [];
			return PanelNavigationState::make($entries, $this->request, $this->search, [
				'source'=>'array',
				'custom_items'=>is_array($this->navigation['navigation_items'] ?? null) ? count($this->navigation['navigation_items']) : 0,
			])->jsonSerialize();
		}
		return Panel::navigationState($this->request, $this->search)->jsonSerialize();
	}

	/**
	 * Counts entries, hierarchy, badges, groups, and content hints.
	 *
	 * @param array<int,array<string,mixed>> $entries Top-level navigation entries.
	 * @param array<int,array<string,mixed>> $flat Flattened navigation entries.
	 * @param array<int,array<string,mixed>> $groups Navigation group payloads.
	 * @param array<string,mixed> $meta Navigation-state metadata.
	 * @return array{entries:int,top_level:int,groups:int,resources:int,pages:int,custom_items:int,folders:int,leaves:int,badges:int,descriptions:int,new_tabs:int,max_depth:int} Navigation count summary.
	 */
	private static function counts(array $entries, array $flat, array $groups, array $meta): array {
		return [
			'entries'=>count($flat),
			'top_level'=>count($entries),
			'groups'=>count($groups),
			'resources'=>(int)($meta['resources'] ?? count(array_filter($flat, static fn(array $entry): bool => ($entry['kind'] ?? '')==='resource'))),
			'pages'=>(int)($meta['pages'] ?? count(array_filter($flat, static fn(array $entry): bool => ($entry['kind'] ?? '')==='page'))),
			'custom_items'=>(int)($meta['custom_items'] ?? count(array_filter($flat, static fn(array $entry): bool => ($entry['kind'] ?? '')==='navigation_item'))),
			'folders'=>count(array_filter($flat, static fn(array $entry): bool => ($entry['folder_only'] ?? false)===true || self::children($entry)!==[])),
			'leaves'=>count(array_filter($flat, static fn(array $entry): bool => self::children($entry)===[])),
			'badges'=>count(array_filter($flat, static fn(array $entry): bool => self::hasValue($entry['badge'] ?? null))),
			'descriptions'=>count(array_filter($flat, static fn(array $entry): bool => self::hasValue($entry['description'] ?? null))),
			'new_tabs'=>count(array_filter($flat, static fn(array $entry): bool => ($entry['new_tab'] ?? false)===true)),
			'max_depth'=>self::maxDepth($entries),
		];
	}

	/**
	 * Describes layout, mode, hierarchy, group, entry, and search support.
	 *
	 * @param array<int,array<string,mixed>> $entries Top-level navigation entries.
	 * @param array<int,array<string,mixed>> $flat Flattened navigation entries.
	 * @param array<int,array<string,mixed>> $groups Navigation group payloads.
	 * @param string $layout Active shell layout.
	 * @param string $mode Active navigation presentation mode.
	 * @param array<string,mixed> $search Search state included with navigation.
	 * @return array{layout:array<string,bool|string>,mode:array<string,bool|string>,hierarchy:array{enabled:bool,max_depth:int,folders:int,active_descendants:int},groups:array{total:int,active:int},entries:array{total:int,linked:int,folder_only:int,badged:int,described:int},search:array{enabled:bool,query:string,result_count:int}} Capability summary payload.
	 */
	private static function capabilities(array $entries, array $flat, array $groups, string $layout, string $mode, array $search): array {
		return [
			'layout'=>[
				'active'=>$layout,
				'sidebar'=>true,
				'horizontal'=>true,
				'mobile'=>true,
				'none'=>true,
			],
			'mode'=>[
				'active'=>$mode,
				'floating'=>true,
				'docked'=>true,
				'edge'=>true,
				'overlay'=>true,
			],
			'hierarchy'=>[
				'enabled'=>self::maxDepth($entries)>1,
				'max_depth'=>self::maxDepth($entries),
				'folders'=>count(array_filter($flat, static fn(array $entry): bool => ($entry['folder_only'] ?? false)===true || self::children($entry)!==[])),
				'active_descendants'=>count(array_filter($flat, static fn(array $entry): bool => ($entry['active_descendant'] ?? false)===true)),
			],
			'groups'=>[
				'total'=>count($groups),
				'active'=>count(array_filter($groups, static fn(array $group): bool => ($group['active'] ?? false)===true)),
			],
			'entries'=>[
				'total'=>count($flat),
				'linked'=>count(array_filter($flat, static fn(array $entry): bool => trim((string)($entry['url'] ?? ''))!=='')),
				'folder_only'=>count(array_filter($flat, static fn(array $entry): bool => ($entry['folder_only'] ?? false)===true)),
				'badged'=>count(array_filter($flat, static fn(array $entry): bool => self::hasValue($entry['badge'] ?? null))),
				'described'=>count(array_filter($flat, static fn(array $entry): bool => self::hasValue($entry['description'] ?? null))),
			],
			'search'=>[
				'enabled'=>true,
				'query'=>(string)($search['query'] ?? ''),
				'result_count'=>(int)($search['result_count'] ?? (is_array($search['results'] ?? null) ? count($search['results']) : 0)),
			],
		];
	}

	/**
	 * Flattens top-level navigation entries while preserving each entry payload.
	 *
	 * @param array<int,array<string,mixed>|mixed> $entries Hierarchical navigation entries.
	 * @return array<int,array<string,mixed>> Flattened entries annotated with depth and child_count.
	 */
	private static function flattenEntries(array $entries): array {
		$flat=[];
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$children=self::children($entry);
			$entry['depth']=(int)($entry['depth'] ?? 1);
			$entry['child_count']=count($children);
			$flat[]=$entry;
			foreach(self::flattenEntriesWithDepth($children, $entry['depth']+1) as $child){
				$flat[]=$child;
			}
		}
		return $flat;
	}

	/**
	 * Recursively flattens child navigation entries at a known depth.
	 *
	 * @param array<int,array<string,mixed>|mixed> $entries Child navigation entries.
	 * @param int $depth Depth assigned to the current entries.
	 * @return array<int,array<string,mixed>> Flattened child entries.
	 */
	private static function flattenEntriesWithDepth(array $entries, int $depth): array {
		$flat=[];
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$children=self::children($entry);
			$entry['depth']=$depth;
			$entry['child_count']=count($children);
			$flat[]=$entry;
			foreach(self::flattenEntriesWithDepth($children, $depth+1) as $child){
				$flat[]=$child;
			}
		}
		return $flat;
	}

	/**
	 * Calculates the deepest visible hierarchy level.
	 *
	 * @param array<int,array<string,mixed>|mixed> $entries Hierarchical navigation entries.
	 * @param int $depth Current recursion depth.
	 * @return int Maximum entry depth, or 0 for an empty tree.
	 */
	private static function maxDepth(array $entries, int $depth=1): int {
		$max=$entries===[] ? 0 : $depth;
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$children=self::children($entry);
			if($children!==[]){
				$max=max($max, self::maxDepth($children, $depth+1));
			}
		}
		return $max;
	}

	/**
	 * Extracts child entries from a navigation entry.
	 *
	 * @param array<string,mixed> $entry Navigation entry payload.
	 * @return array<int,array<string,mixed>> Child entries filtered to arrays.
	 */
	private static function children(array $entry): array {
		return is_array($entry['children'] ?? null) ? array_values(array_filter($entry['children'], 'is_array')) : [];
	}

	/**
	 * Determines whether an optional navigation value should count as present.
	 *
	 * @param mixed $value Candidate badge, description, or scalar flag.
	 * @return bool True when the value is meaningful for documentation.
	 */
	private static function hasValue(mixed $value): bool {
		if(is_bool($value)){
			return true;
		}
		if(is_scalar($value)){
			return trim((string)$value)!=='';
		}
		return $value!==null;
	}
}
