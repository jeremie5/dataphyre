<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes custom panel pages for navigation, permissions, and clients.
 *
 * Page manifests expose rendering mode, page-level actions, widgets, tables,
 * navigation hints, and generated permission names without invoking the page
 * renderer itself.
 */
final class PageManifest {

	/**
	 * Stores the page source and manifest context.
	 *
	 * @param ?PanelPage $page Live page instance, or null when using a definition override.
	 * @param ?PanelRequest $request Current request used by child manifests.
	 * @param ?PanelManager $manager Owning manager when available.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @param ?array $definitionOverride Serialized page definition.
	 */
	private function __construct(
		private readonly ?PanelPage $page=null,
		private readonly ?PanelRequest $request=null,
		private readonly ?PanelManager $manager=null,
		private readonly array $meta=[],
		private readonly ?array $definitionOverride=null
	){}

	/**
	 * Creates a page manifest builder from a live page or serialized definition.
	 *
	 * @param PanelPage|array $page Page source to describe.
	 * @param ?PanelRequest $request Current request context.
	 * @param ?PanelManager $manager Owning panel manager.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return self New immutable manifest builder.
	 */
	public static function from(PanelPage|array $page, ?PanelRequest $request=null, ?PanelManager $manager=null, array $meta=[]): self {
		if(is_array($page)){
			return new self(null, $request, $manager, $meta, $page);
		}
		return new self($page, $request, $manager, $meta);
	}

	/**
	 * Materializes the page_manifest payload.
	 *
	 * @return array<string,mixed> Page manifest payload.
	 */
	public function toArray(): array {
		$definition=$this->definitionOverride ?? ($this->page?->toArray() ?? []);
		$actions=$this->actions($definition);
		$widgets=$this->widgets($definition);
		$tables=$this->tables($definition);
		$permission=$this->permissionManifest((string)($definition['name'] ?? 'page'), $actions);
		$manifest=[
			'type'=>'page_manifest',
			'name'=>(string)($definition['name'] ?? ''),
			'label'=>(string)($definition['label'] ?? self::humanize((string)($definition['name'] ?? 'page'))),
			'navigation'=>self::navigation($definition),
			'rendering'=>[
				'custom_renderer'=>($definition['renders'] ?? false)===true,
				'has_static_content'=>($definition['has_content'] ?? false)===true,
				'authorizes'=>($definition['authorizes'] ?? false)===true,
			],
			'actions'=>$actions,
			'widgets'=>$widgets,
			'tables'=>$tables,
			'permission'=>$permission,
			'capabilities'=>self::capabilities($definition, $actions, $widgets, $tables, $permission),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('page.manifest.described', [
			'name'=>$manifest['name'],
			'actions'=>count($actions),
			'widgets'=>count($widgets),
			'tables'=>count($tables),
			'custom_renderer'=>$manifest['rendering']['custom_renderer'],
		]);
		return $manifest;
	}

	/**
	 * Extracts navigation metadata for the page.
	 *
	 * @param array<string,mixed> $definition Page definition array.
	 * @return array<string,mixed> Navigation payload.
	 */
	private static function navigation(array $definition): array {
		return [
			'url'=>$definition['url'] ?? null,
			'group'=>$definition['group'] ?? null,
			'icon'=>$definition['icon'] ?? null,
			'sort'=>$definition['sort'] ?? null,
			'hidden'=>($definition['hidden_from_navigation'] ?? false)===true,
			'description'=>$definition['navigation_description'] ?? null,
			'badge'=>$definition['navigation_badge'] ?? null,
			'badge_lazy'=>($definition['navigation_badge_lazy'] ?? false)===true,
			'badge_tone'=>(string)($definition['navigation_badge_tone'] ?? 'neutral'),
		];
	}

	/**
	 * Builds page action manifests from live actions or serialized definitions.
	 *
	 * @param array<string,mixed> $definition Page definition fallback.
	 * @return array<string,array<string,mixed>> Action manifests keyed by action name.
	 */
	private function actions(array $definition): array {
		$actions=[];
		if($this->page instanceof PanelPage){
			foreach($this->page->actionsList() as $action){
				if(!$action instanceof Action && !$action instanceof ActionGroup){
					continue;
				}
				try{
					$manifest=$action->manifest(null, $this->request, null, 'page', [
						'surface'=>'page_manifest',
						'page'=>$this->page->name(),
					]);
				}
				catch(\Throwable $exception){
					$manifest=self::fallbackActionManifest($action->toArray(), $exception);
				}
				$actions[(string)($manifest['name'] ?? 'action_'.count($actions))]=$manifest;
			}
			return $actions;
		}
		foreach((array)($definition['actions'] ?? []) as $index=>$action){
			if(!is_array($action)){
				continue;
			}
			$actions[(string)($action['name'] ?? 'action_'.$index)]=self::fallbackActionManifest($action);
		}
		return $actions;
	}

	/**
	 * Builds widget manifests for widgets embedded on the page.
	 *
	 * @param array<string,mixed> $definition Page definition fallback.
	 * @return array<string,array<string,mixed>> Widget manifests keyed by widget name.
	 */
	private function widgets(array $definition): array {
		$source=$this->page instanceof PanelPage
			? array_values($this->page->widgetsList())
			: (array)($definition['widgets'] ?? []);
		$widgets=[];
		foreach($source as $index=>$widget){
			if(!$widget instanceof Widget && !is_array($widget)){
				continue;
			}
			$manifest=WidgetManifest::from($widget, $this->request, [
				'surface'=>'page_manifest',
				'page'=>(string)($definition['name'] ?? $this->page?->name() ?? ''),
				'scope'=>'page',
			])->toArray();
			$widgets[(string)($manifest['name'] ?? 'widget_'.$index)]=$manifest;
		}
		return $widgets;
	}

	/**
	 * Builds table manifests for tables embedded on the page.
	 *
	 * @param array<string,mixed> $definition Page definition fallback.
	 * @return array<string,array<string,mixed>> Table manifests keyed by table name.
	 */
	private function tables(array $definition): array {
		$source=$this->page instanceof PanelPage
			? array_values($this->page->tablesList())
			: (array)($definition['tables'] ?? []);
		$tables=[];
		foreach($source as $index=>$table){
			if(!$table instanceof PageTable && !is_array($table)){
				continue;
			}
			$manifest=TableManifest::from($table, null, $this->request, [
				'surface'=>'page_manifest',
				'page'=>(string)($definition['name'] ?? $this->page?->name() ?? ''),
			])->toArray();
			$tables[(string)($manifest['name'] ?? 'table_'.$index)]=$manifest;
		}
		return $tables;
	}

	/**
	 * Builds generated permission names for page viewing and page actions.
	 *
	 * @param string $name Page machine name.
	 * @param array<string,array<string,mixed>> $actions Action manifests available on the page.
	 * @return array<string,mixed> Page permission manifest payload.
	 */
	private function permissionManifest(string $name, array $actions): array {
		$options=PanelPermissionBridge::options();
		$view=PanelPermissionBridge::pageName($name, 'view', $options);
		$actionPermissions=[];
		foreach(array_keys($actions) as $action){
			$action=Resource::normalizeName((string)$action);
			if($action!==''){
				$actionPermissions[$action]=PanelPermissionBridge::name($name, 'action.'.$action, $options);
			}
		}
		$permissions=array_values(array_unique(array_merge([$view], array_values($actionPermissions))));
		sort($permissions, SORT_NATURAL);
		return [
			'type'=>'page_permission_manifest',
			'page'=>Resource::normalizeName($name),
			'guest_allowed'=>PanelPermissionBridge::allowsGuestPage($name, $options),
			'operations'=>[
				'view'=>$view,
			],
			'actions'=>$actionPermissions,
			'permissions'=>$permissions,
			'counts'=>[
				'total'=>count($permissions),
				'actions'=>count($actionPermissions),
			],
		];
	}

	/**
	 * Aggregates page capability counters from child manifests.
	 *
	 * @param array<string,mixed> $definition Page definition array.
	 * @param array<string,array<string,mixed>> $actions Action manifests.
	 * @param array<string,array<string,mixed>> $widgets Widget manifests.
	 * @param array<string,array<string,mixed>> $tables Table manifests.
	 * @param array<string,mixed> $permission Permission manifest payload.
	 * @return array<string,mixed> Capability summary payload.
	 */
	private static function capabilities(array $definition, array $actions, array $widgets, array $tables, array $permission): array {
		return [
			'navigation'=>[
				'hidden'=>($definition['hidden_from_navigation'] ?? false)===true,
				'badge'=>($definition['navigation_badge'] ?? null)!==null || ($definition['navigation_badge_lazy'] ?? false)===true,
				'lazy_badge'=>($definition['navigation_badge_lazy'] ?? false)===true,
			],
			'rendering'=>[
				'custom_renderer'=>($definition['renders'] ?? false)===true,
				'static_content'=>($definition['has_content'] ?? false)===true,
				'authorizes'=>($definition['authorizes'] ?? false)===true,
			],
			'actions'=>[
				'total'=>count($actions),
				'forms'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['has_form'] ?? false)===true)),
				'modals'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['modal'] ?? false)===true)),
				'effects'=>array_sum(array_map(static fn(array $action): int => (int)($action['effects']['refresh_count'] ?? 0) + (int)($action['effects']['event_count'] ?? 0), $actions)),
			],
			'widgets'=>[
				'total'=>count($widgets),
				'lazy'=>count(array_filter($widgets, static fn(array $widget): bool => ($widget['data']['lazy'] ?? false)===true)),
				'linked'=>count(array_filter($widgets, static fn(array $widget): bool => ($widget['interaction']['linked'] ?? false)===true)),
				'charts'=>count(array_filter($widgets, static fn(array $widget): bool => ($widget['capabilities']['chart']['enabled'] ?? false)===true)),
			],
			'tables'=>[
				'total'=>count($tables),
				'columns'=>array_sum(array_map(static fn(array $table): int => (int)($table['capabilities']['columns']['total'] ?? 0), $tables)),
				'filters'=>array_sum(array_map(static fn(array $table): int => (int)($table['capabilities']['controls']['filters'] ?? 0), $tables)),
				'views'=>array_sum(array_map(static fn(array $table): int => (int)($table['capabilities']['controls']['views'] ?? 0), $tables)),
			],
			'permission'=>[
				'total'=>(int)($permission['counts']['total'] ?? 0),
				'actions'=>(int)($permission['counts']['actions'] ?? 0),
			],
		];
	}

	/**
	 * Builds a lightweight action payload when full action manifestation fails.
	 *
	 * @param array<string,mixed> $definition Action definition array.
	 * @param ?\Throwable $exception Optional manifestation failure.
	 * @return array<string,mixed> Fallback action manifest payload.
	 */
	private static function fallbackActionManifest(array $definition, ?\Throwable $exception=null): array {
		return [
			'type'=>'action_manifest',
			'kind'=>$definition['type'] ?? 'action',
			'name'=>(string)($definition['name'] ?? 'action'),
			'label'=>(string)($definition['label'] ?? $definition['name'] ?? 'Action'),
			'interaction'=>[
				'has_form'=>is_array($definition['fields']['fields'] ?? null) && $definition['fields']['fields']!==[],
				'modal'=>($definition['modal'] ?? false)===true,
				'bulk'=>($definition['bulk'] ?? false)===true,
			],
			'effects'=>[
				'refresh_count'=>is_array($definition['effects']['refresh'] ?? null) ? count($definition['effects']['refresh']) : 0,
				'event_count'=>is_array($definition['effects']['events'] ?? null) ? count($definition['effects']['events']) : 0,
			],
			'error'=>$exception?->getMessage(),
		];
	}

	/**
	 * Converts page machine names into display labels for fallbacks.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Page when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Page' : ucwords($value);
	}
}
