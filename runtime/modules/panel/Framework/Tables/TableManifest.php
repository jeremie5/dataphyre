<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes panel table configuration for clients and diagnostics.
 *
 * Table manifests expose columns, filters, views, summaries, groups, actions,
 * row behavior, pagination, operations, and current request state without
 * requiring clients to understand ResourceTable or PageTable internals.
 */
final class TableManifest {

	/**
	 * Stores the table source and manifest context.
	 *
	 * @param ResourceTable|PageTable|null $table Live table instance, or null when using a definition override.
	 * @param ?Resource $resource Owning resource for actions and source metadata.
	 * @param ?PanelRequest $request Current request used to summarize table state.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @param ?array<string,mixed> $definitionOverride Serialized table definition.
	 * @param ?string $kindOverride Explicit manifest kind for definition overrides.
	 */
	private function __construct(
		private readonly ResourceTable|PageTable|null $table,
		private readonly ?Resource $resource=null,
		private readonly ?PanelRequest $request=null,
		private readonly array $meta=[],
		private readonly ?array $definitionOverride=null,
		private readonly ?string $kindOverride=null
	){}

	/**
	 * Creates a manifest builder from a table, resource, or serialized definition.
	 *
	 * Passing a Resource uses its ResourceTable automatically. Array definitions
	 * are treated as already-serialized table descriptions and avoid live state.
	 *
	 * @param ResourceTable|PageTable|Resource|array $table Table source to describe.
	 * @param ?Resource $resource Owning resource context.
	 * @param ?PanelRequest $request Current panel request.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return self New immutable manifest builder.
	 */
	public static function from(ResourceTable|PageTable|Resource|array $table, ?Resource $resource=null, ?PanelRequest $request=null, array $meta=[]): self {
		if($table instanceof Resource){
			return new self($table->resourceTable(), $table, $request, $meta);
		}
		if(is_array($table)){
			$kind=isset($table['name']) ? 'page_table' : 'resource_table';
			return new self(null, $resource, $request, $meta, $table, $kind);
		}
		return new self($table, $resource, $request, $meta);
	}

	/**
	 * Materializes the table_manifest payload.
	 *
	 * @return array{type:string,kind:string,name:string,label:string,description:mixed,source:array<string,mixed>,columns:array<string,array<string,mixed>>,filters:array<string,array<string,mixed>>,views:array<string,array<string,mixed>>,summaries:array<string,array<string,mixed>>,groups:array<string,array<string,mixed>>,actions:array<string,array<string,mixed>>,row_behavior:array<string,mixed>,pagination:array<string,mixed>,sort:array<string,mixed>,operations:array<string,mixed>,capabilities:array<string,array<string,int|bool>>,state:array<string,mixed>,meta:array<string,mixed>} Table manifest payload.
	 */
	public function toArray(): array {
		$definition=$this->definitionOverride ?? ($this->table?->toArray() ?? []);
		$resourceDefinition=$this->resource?->toArray();
		$columns=self::columns($definition);
		$filters=self::filters($definition);
		$views=self::views($definition);
		$summaries=self::summaries($definition);
		$groups=self::groups($definition);
		$actions=$this->resource instanceof Resource ? self::actions($this->resource, $this->request) : [];
		$manifest=[
			'type'=>'table_manifest',
			'kind'=>$this->kindOverride ?? ($this->table instanceof PageTable ? 'page_table' : 'resource_table'),
			'name'=>(string)($definition['name'] ?? $resourceDefinition['name'] ?? 'table'),
			'label'=>(string)($definition['label'] ?? $resourceDefinition['plural_label'] ?? $resourceDefinition['label'] ?? 'Table'),
			'description'=>$definition['description'] ?? null,
			'source'=>[
				'resource'=>$resourceDefinition['name'] ?? null,
				'model'=>$resourceDefinition['model'] ?? null,
				'repository'=>$resourceDefinition['repository'] ?? null,
				'table'=>$resourceDefinition['table'] ?? null,
				'queryable'=>($resourceDefinition['queryable'] ?? false)===true,
				'lazy'=>($definition['lazy'] ?? false)===true,
			],
			'columns'=>$columns,
			'filters'=>$filters,
			'views'=>$views,
			'summaries'=>$summaries,
			'groups'=>$groups,
			'actions'=>$actions,
			'row_behavior'=>self::rowBehavior($definition),
			'pagination'=>self::pagination($definition, $resourceDefinition),
			'sort'=>[
				'default'=>$definition['default_sort'] ?? null,
				'direction'=>$definition['default_sort_direction'] ?? $definition['default_sort']['direction'] ?? null,
			],
			'operations'=>self::operations($resourceDefinition, $actions),
			'capabilities'=>self::capabilities($definition, $columns, $filters, $views, $summaries, $groups, $actions, $resourceDefinition),
			'state'=>$this->stateSummary(),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('table.manifest.described', [
			'kind'=>$manifest['kind'],
			'name'=>$manifest['name'],
			'columns'=>count($columns),
			'filters'=>count($filters),
			'views'=>count($views),
			'actions'=>count($actions),
		]);
		return $manifest;
	}

	/**
	 * Normalizes table column definitions.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @return array<string,array{name:string,label:string,type:string,sortable:bool,searchable:bool,toggleable:bool,visible_by_default:bool,conditional:bool,align:string,group:string,computed:bool,formatted:bool,copyable:bool,linked:bool,capabilities:array<int,string>,meta:array<string,mixed>}> Column manifests keyed by column name.
	 */
	private static function columns(array $definition): array {
		$columns=[];
		foreach((array)($definition['columns'] ?? []) as $column){
			if(!is_array($column)){
				continue;
			}
			$name=(string)($column['name'] ?? '');
			if($name===''){
				continue;
			}
			$columns[$name]=[
				'name'=>$name,
				'label'=>(string)($column['label'] ?? self::humanize($name)),
				'type'=>(string)($column['type'] ?? 'text'),
				'sortable'=>($column['sortable'] ?? false)===true,
				'searchable'=>($column['searchable'] ?? false)===true,
				'toggleable'=>($column['toggleable'] ?? true)===true,
				'visible_by_default'=>($column['visible_by_default'] ?? true)===true,
				'conditional'=>($column['conditional'] ?? false)===true,
				'align'=>(string)($column['align'] ?? 'left'),
				'group'=>(string)($column['group'] ?? ''),
				'computed'=>($column['computed'] ?? false)===true,
				'formatted'=>($column['formatted'] ?? false)===true,
				'copyable'=>($column['copyable'] ?? false)===true,
				'linked'=>($column['linked'] ?? false)===true,
				'capabilities'=>array_values(array_filter([
					($column['sortable'] ?? false)===true ? 'sortable' : null,
					($column['searchable'] ?? false)===true ? 'searchable' : null,
					($column['toggleable'] ?? true)===true ? 'toggleable' : null,
					($column['computed'] ?? false)===true ? 'computed' : null,
					($column['formatted'] ?? false)===true ? 'formatted' : null,
					($column['copyable'] ?? false)===true ? 'copyable' : null,
					($column['linked'] ?? false)===true ? 'linked' : null,
				])),
				'meta'=>is_array($column['meta'] ?? null) ? $column['meta'] : [],
			];
		}
		return $columns;
	}

	/**
	 * Normalizes table filter definitions.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @return array<string,array{name:string,label:string,type:string,column:mixed,option_count:int,dynamic_options:bool,range:bool,has_predicate:bool,default:mixed,meta:array<string,mixed>}> Filter manifests keyed by filter name.
	 */
	private static function filters(array $definition): array {
		$filters=[];
		foreach((array)($definition['filters'] ?? []) as $filter){
			if(!is_array($filter)){
				continue;
			}
			$name=(string)($filter['name'] ?? '');
			if($name===''){
				continue;
			}
			$filters[$name]=[
				'name'=>$name,
				'label'=>(string)($filter['label'] ?? self::humanize($name)),
				'type'=>(string)($filter['type'] ?? 'text'),
				'column'=>$filter['column'] ?? null,
				'option_count'=>is_array($filter['options'] ?? null) ? count($filter['options']) : 0,
				'dynamic_options'=>($filter['dynamic_options'] ?? false)===true,
				'range'=>($filter['range'] ?? false)===true,
				'has_predicate'=>($filter['has_predicate'] ?? false)===true,
				'default'=>$filter['default'] ?? null,
				'meta'=>is_array($filter['meta'] ?? null) ? $filter['meta'] : [],
			];
		}
		return $filters;
	}

	/**
	 * Normalizes saved or computed table view definitions.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @return array<string,array{name:string,label:string,default:bool,tone:string,query:array<string,mixed>,has_predicate:bool,has_badge:bool,meta:array<string,mixed>}> View manifests keyed by view name.
	 */
	private static function views(array $definition): array {
		$views=[];
		foreach((array)($definition['views'] ?? []) as $view){
			if(!is_array($view)){
				continue;
			}
			$name=(string)($view['name'] ?? '');
			if($name===''){
				continue;
			}
			$views[$name]=[
				'name'=>$name,
				'label'=>(string)($view['label'] ?? self::humanize($name)),
				'default'=>($view['default'] ?? false)===true,
				'tone'=>(string)($view['tone'] ?? 'neutral'),
				'query'=>is_array($view['query'] ?? null) ? $view['query'] : [],
				'has_predicate'=>($view['has_predicate'] ?? false)===true,
				'has_badge'=>($view['has_badge_resolver'] ?? false)===true || ($view['badge'] ?? null)!==null,
				'meta'=>is_array($view['meta'] ?? null) ? $view['meta'] : [],
			];
		}
		return $views;
	}

	/**
	 * Normalizes table summary definitions.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @return array<string,array{name:string,label:string,type:string,column:mixed,tone:string,computed:bool,formatted:bool,meta:array<string,mixed>}> Summary manifests keyed by summary name.
	 */
	private static function summaries(array $definition): array {
		$summaries=[];
		foreach((array)($definition['summaries'] ?? []) as $summary){
			if(!is_array($summary)){
				continue;
			}
			$name=(string)($summary['name'] ?? '');
			if($name===''){
				continue;
			}
			$summaries[$name]=[
				'name'=>$name,
				'label'=>(string)($summary['label'] ?? self::humanize($name)),
				'type'=>(string)($summary['type'] ?? 'count'),
				'column'=>$summary['column'] ?? null,
				'tone'=>(string)($summary['tone'] ?? 'neutral'),
				'computed'=>($summary['computed'] ?? false)===true,
				'formatted'=>($summary['formatted'] ?? false)===true,
				'meta'=>is_array($summary['meta'] ?? null) ? $summary['meta'] : [],
			];
		}
		return $summaries;
	}

	/**
	 * Normalizes table group definitions.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @return array<string,array{name:string,label:string,direction:string,default:bool,collapsible:bool,collapsed:bool,summary_count:int,action_count:int,meta:array<string,mixed>}> Group manifests keyed by group name.
	 */
	private static function groups(array $definition): array {
		$groups=[];
		foreach((array)($definition['groups'] ?? []) as $group){
			if(!is_array($group)){
				continue;
			}
			$name=(string)($group['name'] ?? '');
			if($name===''){
				continue;
			}
			$groups[$name]=[
				'name'=>$name,
				'label'=>(string)($group['label'] ?? self::humanize($name)),
				'direction'=>(string)($group['direction'] ?? 'asc'),
				'default'=>($group['default'] ?? false)===true,
				'collapsible'=>($group['collapsible'] ?? false)===true,
				'collapsed'=>($group['collapsed'] ?? false)===true,
				'summary_count'=>is_array($group['summaries'] ?? null) ? count($group['summaries']) : 0,
				'action_count'=>is_array($group['actions'] ?? null) ? count($group['actions']) : 0,
				'meta'=>is_array($group['meta'] ?? null) ? $group['meta'] : [],
			];
		}
		return $groups;
	}

	/**
	 * Builds action manifests available from the table's owning resource.
	 *
	 * Action manifest failures are captured as lightweight error payloads so the
	 * table manifest remains usable for diagnostics.
	 *
	 * @param Resource $resource Owning resource.
	 * @param ?PanelRequest $request Current panel request.
	 * @return array<string,array<string,mixed>> Action manifests keyed by action name.
	 */
	private static function actions(Resource $resource, ?PanelRequest $request): array {
		$actions=[];
		foreach($resource->actionsList() as $action){
			if($action instanceof Action || $action instanceof ActionGroup){
				try{
					$manifest=$action->manifest(null, $request, $resource, 'action', ['surface'=>'table']);
				}
				catch(\Throwable $exception){
					$definition=$action->toArray();
					$manifest=[
						'type'=>'action_manifest',
						'kind'=>$definition['type'] ?? 'action',
						'name'=>(string)($definition['name'] ?? 'action_'.count($actions)),
						'interaction'=>[
							'has_form'=>is_array($definition['fields']['fields'] ?? null) && $definition['fields']['fields']!==[],
							'modal'=>($definition['modal'] ?? false)===true,
							'bulk'=>($definition['bulk'] ?? false)===true,
						],
						'effects'=>[
							'refresh_count'=>is_array($definition['effects']['refresh'] ?? null) ? count($definition['effects']['refresh']) : 0,
							'event_count'=>is_array($definition['effects']['events'] ?? null) ? count($definition['effects']['events']) : 0,
						],
						'error'=>$exception->getMessage(),
					];
				}
				$name=(string)($manifest['name'] ?? 'action_'.count($actions));
				$actions[$name]=$manifest;
			}
		}
		return $actions;
	}

	/**
	 * Describes row-click, preview, and dynamic row attribute behavior.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @return array{clickable:bool,click_operation:mixed,click_target:mixed,click_modal:bool,click_dynamic_url:bool,click_action:mixed,preview_action:bool,preview_field_count:int,preview_dynamic:bool,row_attributes_dynamic:bool} Row behavior payload.
	 */
	private static function rowBehavior(array $definition): array {
		$rowClick=is_array($definition['row_click'] ?? null) ? $definition['row_click'] : [];
		$rowPreview=is_array($definition['row_preview'] ?? null) ? $definition['row_preview'] : [];
		return [
			'clickable'=>($rowClick['enabled'] ?? false)===true,
			'click_operation'=>$rowClick['operation'] ?? null,
			'click_target'=>$rowClick['target'] ?? null,
			'click_modal'=>($rowClick['modal'] ?? false)===true,
			'click_dynamic_url'=>($rowClick['dynamic_url'] ?? false)===true,
			'click_action'=>$rowClick['action'] ?? null,
			'preview_action'=>($rowPreview['action'] ?? false)===true,
			'preview_field_count'=>is_array($rowPreview['fields'] ?? null) ? count($rowPreview['fields']) : 0,
			'preview_dynamic'=>($rowPreview['dynamic'] ?? false)===true,
			'row_attributes_dynamic'=>($definition['row_attributes_dynamic'] ?? false)===true,
		];
	}

	/**
	 * Describes pagination defaults and available page-size controls.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @param ?array<string,mixed> $resourceDefinition Owning resource definition.
	 * @return array{default_per_page:int,per_page_options:array<int,mixed>,limit:mixed,record_count:?int} Pagination payload.
	 */
	private static function pagination(array $definition, ?array $resourceDefinition): array {
		return [
			'default_per_page'=>(int)($definition['default_per_page'] ?? $resourceDefinition['per_page'] ?? 25),
			'per_page_options'=>is_array($definition['per_page_options'] ?? null) ? array_values($definition['per_page_options']) : [],
			'limit'=>$definition['limit'] ?? null,
			'record_count'=>isset($definition['record_count']) ? (int)$definition['record_count'] : null,
		];
	}

	/**
	 * Summarizes write and workflow operations exposed by the table resource.
	 *
	 * @param ?array<string,mixed> $resourceDefinition Owning resource definition.
	 * @param array<string,array<string,mixed>> $actions Action manifests available on the table.
	 * @return array{imports:bool,bulk_updates:bool,duplicates:bool,deletes:bool,force_deletes:bool,restores:bool,transitions:int,status_field:mixed,status_widgets:bool,custom_actions:int,bulk_actions:int,has_write_operations:bool} Operation summary payload.
	 */
	private static function operations(?array $resourceDefinition, array $actions): array {
		$operations=[
			'imports'=>($resourceDefinition['imports'] ?? false)===true,
			'bulk_updates'=>($resourceDefinition['bulk_updates'] ?? false)===true,
			'duplicates'=>($resourceDefinition['duplicates'] ?? false)===true,
			'deletes'=>($resourceDefinition['deletes'] ?? false)===true,
			'force_deletes'=>($resourceDefinition['force_deletes'] ?? false)===true,
			'restores'=>($resourceDefinition['restores'] ?? false)===true,
			'transitions'=>is_array($resourceDefinition['transitions'] ?? null) ? count($resourceDefinition['transitions']) : 0,
			'status_field'=>$resourceDefinition['status_field'] ?? null,
			'status_widgets'=>($resourceDefinition['status_widgets'] ?? false)===true,
			'custom_actions'=>count($actions),
			'bulk_actions'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['bulk'] ?? false)===true)),
		];
		$operations['has_write_operations']=$operations['imports'] || $operations['bulk_updates'] || $operations['duplicates'] || $operations['deletes'] || $operations['force_deletes'] || $operations['restores'] || $operations['transitions']>0 || $operations['custom_actions']>0;
		return $operations;
	}

	/**
	 * Aggregates table capability counters from normalized child manifests.
	 *
	 * @param array<string,mixed> $definition Table definition array.
	 * @param array<string,array<string,mixed>> $columns Column manifests.
	 * @param array<string,array<string,mixed>> $filters Filter manifests.
	 * @param array<string,array<string,mixed>> $views View manifests.
	 * @param array<string,array<string,mixed>> $summaries Summary manifests.
	 * @param array<string,array<string,mixed>> $groups Group manifests.
	 * @param array<string,array<string,mixed>> $actions Action manifests.
	 * @param ?array<string,mixed> $resourceDefinition Owning resource definition.
	 * @return array{columns:array<string,int>,controls:array<string,int>,behavior:array<string,bool>,actions:array<string,int>} Capability summary payload.
	 */
	private static function capabilities(array $definition, array $columns, array $filters, array $views, array $summaries, array $groups, array $actions, ?array $resourceDefinition): array {
		return [
			'columns'=>[
				'total'=>count($columns),
				'searchable'=>self::countByFlag($columns, 'searchable'),
				'sortable'=>self::countByFlag($columns, 'sortable'),
				'toggleable'=>self::countByFlag($columns, 'toggleable'),
				'computed'=>self::countByFlag($columns, 'computed'),
				'formatted'=>self::countByFlag($columns, 'formatted'),
				'copyable'=>self::countByFlag($columns, 'copyable'),
				'linked'=>self::countByFlag($columns, 'linked'),
			],
			'controls'=>[
				'filters'=>count($filters),
				'range_filters'=>self::countByFlag($filters, 'range'),
				'dynamic_filters'=>self::countByFlag($filters, 'dynamic_options'),
				'views'=>count($views),
				'views_with_badges'=>self::countByFlag($views, 'has_badge'),
				'groups'=>count($groups),
				'collapsible_groups'=>self::countByFlag($groups, 'collapsible'),
				'summaries'=>count($summaries),
				'computed_summaries'=>self::countByFlag($summaries, 'computed'),
			],
			'behavior'=>[
				'row_click'=>($definition['row_click']['enabled'] ?? false)===true,
				'row_preview'=>($definition['row_preview']['action'] ?? false)===true,
				'row_attributes'=>($definition['row_attributes'] ?? [])!==[] || ($definition['row_attributes_dynamic'] ?? false)===true,
				'tenant_scoped'=>($resourceDefinition['tenant_scoped'] ?? false)===true,
				'global_searchable'=>($resourceDefinition['global_searchable'] ?? false)===true,
			],
			'actions'=>[
				'total'=>count($actions),
				'forms'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['has_form'] ?? false)===true)),
				'modals'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['modal'] ?? false)===true)),
				'bulk'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['bulk'] ?? false)===true)),
				'effects'=>array_sum(array_map(static fn(array $action): int => (int)($action['effects']['refresh_count'] ?? 0) + (int)($action['effects']['event_count'] ?? 0), $actions)),
			],
		];
	}

	/**
	 * Captures current ResourceTable request state when available.
	 *
	 * @return array{query?:string,filters?:array<string,mixed>,sort?:mixed,active_view?:string,active_group?:string,page?:mixed,per_page?:mixed,error?:string} State summary payload or an error payload when state resolution fails.
	 */
	private function stateSummary(): array {
		if(!$this->request instanceof PanelRequest){
			return [];
		}
		try{
			if($this->table instanceof ResourceTable){
				$state=$this->table->state($this->request, [], $this->resource);
				return [
					'query'=>(string)($state->meta()['query'] ?? ''),
					'filters'=>is_array($state->meta()['filters'] ?? null) ? $state->meta()['filters'] : [],
					'sort'=>$state->meta()['sort'] ?? null,
					'active_view'=>$state->meta()['active_view'] ?? '',
					'active_group'=>$state->meta()['active_group'] ?? '',
					'page'=>$state->meta()['page'] ?? 1,
					'per_page'=>$state->meta()['per_page'] ?? null,
				];
			}
		}
		catch(\Throwable $exception){
			return ['error'=>$exception->getMessage()];
		}
		return [];
	}

	/**
	 * Counts normalized manifest rows whose boolean flag is enabled.
	 *
	 * @param array<string,array<string,mixed>> $items Manifest rows.
	 * @param string $flag Boolean flag to inspect.
	 * @return int Number of rows with the flag set to true.
	 */
	private static function countByFlag(array $items, string $flag): int {
		return count(array_filter($items, static fn(array $item): bool => ($item[$flag] ?? false)===true));
	}

	/**
	 * Converts table machine names into display labels for fallbacks.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Table when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Table' : ucwords($value);
	}
}
