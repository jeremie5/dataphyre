<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a panel resource as a complete client contract.
 *
 * Resource manifests compose identity, navigation, data access, tenant scope,
 * forms, infolists, tables, actions, relations, record surfaces, operations,
 * policies, permissions, search, and capability counters without executing write
 * handlers or mutating records.
 */
final class ResourceManifest {

	/**
	 * Stores the resource source and manifest context.
	 *
	 * @param ?Resource $resource Live resource instance, or null when using a definition override.
	 * @param ?PanelRequest $request Current request used by child manifests.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @param ?array<string,mixed> $definitionOverride Serialized resource definition.
	 */
	private function __construct(
		private readonly ?Resource $resource=null,
		private readonly ?PanelRequest $request=null,
		private readonly array $meta=[],
		private readonly ?array $definitionOverride=null
	){}

	/**
	 * Creates a resource manifest builder from a live resource or definition array.
	 *
	 * @param Resource|array<string,mixed> $resource Resource source to describe.
	 * @param ?PanelRequest $request Current request context.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return self New immutable manifest builder.
	 */
	public static function from(Resource|array $resource, ?PanelRequest $request=null, array $meta=[]): self {
		if(is_array($resource)){
			return new self(null, $request, $meta, $resource);
		}
		return new self($resource, $request, $meta);
	}

	/**
	 * Materializes the resource_manifest payload.
	 *
	 * @return array{type:string,name:string,label:string,plural_label:string,navigation:array<string,mixed>,identity:array<string,bool>,data:array<string,mixed>,tenant:array<string,mixed>,forms:array<string,array<string,mixed>>,infolist:array<string,mixed>,table:array<string,mixed>,actions:array<string,array<string,mixed>>,relations:array<string,array<string,mixed>>,record_surface:array<string,mixed>,operations:array<string,mixed>,policies:array<string,mixed>,permission:array<string,mixed>,search:array<string,mixed>,capabilities:array<string,array<string,mixed>>,meta:array<string,mixed>} Resource manifest payload.
	 */
	public function toArray(): array {
		$definition=$this->definitionOverride ?? ($this->resource?->toArray() ?? []);
		$table=$this->tableManifest($definition);
		$form=$this->schemaManifest('create', $definition['form'] ?? []);
		$editForm=$this->schemaManifest('edit', $definition['form'] ?? []);
		$bulkForm=$this->schemaManifest('bulk_update', $definition['bulk_form'] ?? []);
		$infolist=$this->infolistManifest($definition);
		$actions=$this->actionManifests($definition);
		$relations=$this->relationManifests($definition);
		$permission=self::permissionManifest($definition, $actions, $relations);
		$manifest=[
			'type'=>'resource_manifest',
			'name'=>(string)($definition['name'] ?? ''),
			'label'=>(string)($definition['label'] ?? ''),
			'plural_label'=>(string)($definition['plural_label'] ?? $definition['label'] ?? ''),
			'navigation'=>self::navigation($definition),
			'identity'=>self::identity($definition),
			'data'=>self::data($definition),
			'tenant'=>self::tenant($definition),
			'forms'=>[
				'create'=>$form,
				'edit'=>$editForm,
				'bulk_update'=>$bulkForm,
			],
			'infolist'=>$infolist,
			'table'=>$table,
			'actions'=>$actions,
			'relations'=>$relations,
			'record_surface'=>self::recordSurface($definition, $infolist, $relations),
			'operations'=>self::operations($definition, $actions),
			'policies'=>self::policies($definition),
			'permission'=>$permission,
			'search'=>self::search($definition),
			'capabilities'=>self::capabilities($definition, $form, $bulkForm, $infolist, $table, $actions, $relations, $permission),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('resource.manifest.described', [
			'name'=>$manifest['name'],
			'actions'=>count($actions),
			'relations'=>count($relations),
			'fields'=>(int)($manifest['capabilities']['forms']['fields'] ?? 0),
			'columns'=>(int)($manifest['capabilities']['table']['columns'] ?? 0),
		]);
		return $manifest;
	}

	/**
	 * Extracts resource navigation metadata.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @return array{url:mixed,group:mixed,icon:mixed,sort:mixed,hidden:bool,description:mixed,badge:mixed,badge_lazy:bool,badge_tone:string} Navigation payload.
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
	 * Describes whether record identity fields are custom resolvers.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @return array{record_key_custom:bool,record_title_custom:bool,record_subtitle_custom:bool,record_url_custom:bool} Identity capability payload.
	 */
	private static function identity(array $definition): array {
		return [
			'record_key_custom'=>($definition['record_key_custom'] ?? false)===true,
			'record_title_custom'=>($definition['record_title_custom'] ?? false)===true,
			'record_subtitle_custom'=>($definition['record_subtitle_custom'] ?? false)===true,
			'record_url_custom'=>($definition['record_url_custom'] ?? false)===true,
		];
	}

	/**
	 * Extracts the resource persistence and data-mutation contract.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @return array{model:mixed,repository:mixed,table:mixed,queryable:bool,saves:bool,mutates_form_data:bool,mutates_fill_data:bool} Data access payload.
	 */
	private static function data(array $definition): array {
		return [
			'model'=>$definition['model'] ?? null,
			'repository'=>$definition['repository'] ?? null,
			'table'=>$definition['table'] ?? null,
			'queryable'=>($definition['queryable'] ?? false)===true,
			'saves'=>($definition['saves'] ?? false)===true,
			'mutates_form_data'=>($definition['mutates_form_data'] ?? false)===true,
			'mutates_fill_data'=>($definition['mutates_fill_data'] ?? false)===true,
		];
	}

	/**
	 * Describes tenant-scoping requirements for the resource.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @return array{scoped:bool,field:mixed,required:bool,resolves:bool,custom_scope:bool} Tenant metadata payload.
	 */
	private static function tenant(array $definition): array {
		return [
			'scoped'=>($definition['tenant_scoped'] ?? false)===true,
			'field'=>$definition['tenant_field'] ?? null,
			'required'=>($definition['tenant_required'] ?? false)===true,
			'resolves'=>($definition['tenant_resolves'] ?? false)===true,
			'custom_scope'=>($definition['tenant_scope_custom'] ?? false)===true,
		];
	}

	/**
	 * Builds the table manifest for the resource.
	 *
	 * @param array<string,mixed> $definition Resource definition fallback.
	 * @return array<string,mixed> Table manifest or error payload.
	 */
	private function tableManifest(array $definition): array {
		try{
			if($this->resource instanceof Resource){
				return $this->resource->tableManifest($this->request, [
					'surface'=>'resource_manifest',
					'resource'=>$this->resource->name(),
				]);
			}
			return TableManifest::from(is_array($definition['table_schema'] ?? null) ? $definition['table_schema'] : [], null, $this->request, [
				'surface'=>'resource_manifest',
				'resource'=>(string)($definition['name'] ?? ''),
			])->toArray();
		}
		catch(\Throwable $exception){
			return self::errorManifest('table_manifest', $exception);
		}
	}

	/**
	 * Builds form or bulk-form schema manifests for a resource operation.
	 *
	 * @param string $operation Operation name such as create, edit, or bulk_update.
	 * @param mixed $definition Serialized schema definition fallback.
	 * @return array<string,mixed> Schema manifest or error payload.
	 */
	private function schemaManifest(string $operation, mixed $definition): array {
		try{
			if($this->resource instanceof Resource){
				if($operation==='bulk_update'){
					return $this->resource->bulkForm()->manifest($operation, [
						'surface'=>'resource_manifest',
						'resource'=>$this->resource->name(),
					]);
				}
				return $this->resource->form()->manifest($operation, [
					'surface'=>'resource_manifest',
					'resource'=>$this->resource->name(),
				]);
			}
			$schema=is_array($definition) ? ($definition['schema'] ?? $definition) : [];
			return SchemaManifest::from($schema, $operation, [
				'surface'=>'resource_manifest',
				'resource'=>(string)($this->definitionOverride['name'] ?? ''),
			])->toArray();
		}
		catch(\Throwable $exception){
			return self::errorManifest('schema_manifest', $exception);
		}
	}

	/**
	 * Builds the record infolist manifest for show surfaces.
	 *
	 * @param array<string,mixed> $definition Resource definition fallback.
	 * @return array<string,mixed> Infolist schema manifest or error payload.
	 */
	private function infolistManifest(array $definition): array {
		try{
			if($this->resource instanceof Resource){
				$infolist=$this->resource->infolist();
				$manifestSource=$infolist instanceof Infolist ? $infolist : Infolist::fromSchema($infolist);
				return $manifestSource->manifest('show', [
					'surface'=>'resource_manifest',
					'resource'=>$this->resource->name(),
					'usage'=>'infolist',
				]);
			}
			$schema=is_array($definition['infolist'] ?? null) ? $definition['infolist'] : ($definition['form']['schema'] ?? []);
			return SchemaManifest::from($schema, 'show', [
				'surface'=>'resource_manifest',
				'resource'=>(string)($definition['name'] ?? ''),
				'usage'=>'infolist',
			])->toArray();
		}
		catch(\Throwable $exception){
			return self::errorManifest('schema_manifest', $exception);
		}
	}

	/**
	 * Builds resource action manifests without executing action handlers.
	 *
	 * @param array<string,mixed> $definition Resource definition fallback.
	 * @return array<string,array<string,mixed>> Action manifests keyed by action name.
	 */
	private function actionManifests(array $definition): array {
		$actions=[];
		if($this->resource instanceof Resource){
			foreach($this->resource->actionsList() as $action){
				if(!$action instanceof Action && !$action instanceof ActionGroup){
					continue;
				}
				try{
					$manifest=$action->manifest(null, $this->request, $this->resource, 'resource', ['surface'=>'resource_manifest']);
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
	 * Builds relation manifests for the resource.
	 *
	 * @param array<string,mixed> $definition Resource definition fallback.
	 * @return array<string,array<string,mixed>> Relation manifests keyed by relation name.
	 */
	private function relationManifests(array $definition): array {
		$relations=[];
		$source=$this->resource instanceof Resource
			? array_values($this->resource->relationManagers())
			: (array)($definition['relations'] ?? []);
		foreach($source as $index=>$relation){
			if(!$relation instanceof RelationManager && !is_array($relation)){
				continue;
			}
			$manifest=RelationManifest::from($relation, $this->request, [
				'surface'=>'resource_manifest',
				'resource'=>(string)($definition['name'] ?? $this->resource?->name() ?? ''),
			])->toArray();
			$relations[(string)($manifest['name'] ?? 'relation_'.$index)]=$manifest;
		}
		return $relations;
	}

	/**
	 * Describes record-detail sections and record-surface mutators.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @param array<string,mixed> $infolist Infolist manifest payload.
	 * @param array<string,array<string,mixed>> $relations Relation manifests.
	 * @return array{sections:array<string,bool>,section_count:int,infolist_fields:int,infolist_components:int,relations:int,mutators:array<string,bool>} Record-surface manifest payload.
	 */
	private static function recordSurface(array $definition, array $infolist, array $relations): array {
		$sections=[
			'insights'=>($definition['insights'] ?? false)===true,
			'alerts'=>($definition['alerts'] ?? false)===true,
			'links'=>($definition['links'] ?? false)===true,
			'contacts'=>($definition['contacts'] ?? false)===true,
			'locations'=>($definition['locations'] ?? false)===true,
			'changes'=>($definition['changes'] ?? false)===true,
			'tags'=>($definition['tags'] ?? false)===true,
			'items'=>($definition['items'] ?? false)===true,
			'totals'=>($definition['totals'] ?? false)===true,
			'approvals'=>($definition['approvals'] ?? false)===true,
			'activity'=>($definition['activity'] ?? false)===true,
			'notes'=>($definition['notes'] ?? false)===true,
			'messages'=>($definition['messages'] ?? false)===true,
			'shipments'=>($definition['shipments'] ?? false)===true,
			'payments'=>($definition['payments'] ?? false)===true,
			'attachments'=>($definition['attachments'] ?? false)===true,
			'tasks'=>($definition['tasks'] ?? false)===true,
		];
		return [
			'sections'=>$sections,
			'section_count'=>count(array_filter($sections)),
			'infolist_fields'=>(int)($infolist['field_count'] ?? 0),
			'infolist_components'=>(int)($infolist['component_count'] ?? 0),
			'relations'=>count($relations),
			'mutators'=>[
				'tags'=>($definition['updates_tags'] ?? false)===true,
				'approvals'=>($definition['resolves_approvals'] ?? false)===true,
				'notes'=>($definition['adds_notes'] ?? false)===true,
				'messages'=>($definition['sends_messages'] ?? false)===true,
				'attachments'=>($definition['attaches_files'] ?? false)===true,
				'tasks'=>($definition['updates_tasks'] ?? false)===true || ($definition['creates_tasks'] ?? false)===true,
			],
		];
	}

	/**
	 * Summarizes resource write, workflow, and action operations.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @param array<string,array<string,mixed>> $actions Action manifests.
	 * @return array<string,bool|int|mixed> Operation summary payload.
	 */
	private static function operations(array $definition, array $actions): array {
		$operations=[
			'create'=>($definition['saves'] ?? false)===true,
			'update'=>($definition['saves'] ?? false)===true,
			'import'=>($definition['imports'] ?? false)===true,
			'bulk_update'=>($definition['bulk_updates'] ?? false)===true,
			'duplicate'=>($definition['duplicates'] ?? false)===true,
			'delete'=>($definition['deletes'] ?? false)===true,
			'force_delete'=>($definition['force_deletes'] ?? false)===true,
			'restore'=>($definition['restores'] ?? false)===true,
			'transitions'=>is_array($definition['transitions'] ?? null) ? count($definition['transitions']) : 0,
			'status_field'=>$definition['status_field'] ?? null,
			'status_widgets'=>($definition['status_widgets'] ?? false)===true,
			'actions'=>count($actions),
		];
		$operations['writes']=$operations['create'] || $operations['update'] || $operations['import'] || $operations['bulk_update'] || $operations['duplicate'] || $operations['delete'] || $operations['force_delete'] || $operations['restore'] || $operations['transitions']>0 || $operations['actions']>0;
		return $operations;
	}

	/**
	 * Describes policy and form lifecycle hooks declared by the resource.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @return array{authorizes:bool,form_lifecycle:array<string,mixed>} Policy payload.
	 */
	private static function policies(array $definition): array {
		return [
			'authorizes'=>($definition['authorizes'] ?? false)===true,
			'form_lifecycle'=>is_array($definition['form_lifecycle'] ?? null) ? $definition['form_lifecycle'] : [],
		];
	}

	/**
	 * Describes global search participation for the resource.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @return array{global_searchable:bool,columns:array<int,mixed>} Search metadata payload.
	 */
	private static function search(array $definition): array {
		return [
			'global_searchable'=>($definition['global_searchable'] ?? false)===true,
			'columns'=>is_array($definition['global_search_columns'] ?? null) ? array_values($definition['global_search_columns']) : [],
		];
	}

	/**
	 * Builds generated permission names for resource operations, actions, and relations.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @param array<string,array<string,mixed>> $actions Action manifests.
	 * @param array<string,array<string,mixed>> $relations Relation manifests.
	 * @return array{type:string,resource:string,prefix:string,resource_prefix:string,super_permission:string,operations:array<string,string>,actions:array<string,string>,relations:array<string,array<string,string>>,permissions:array<int,string>,counts:array{operations:int,actions:int,relations:int,total:int}} Resource permission manifest payload.
	 */
	private static function permissionManifest(array $definition, array $actions, array $relations): array {
		$options=PanelPermissionBridge::options();
		$resource=PanelPermissionBridge::resourceName((string)($definition['name'] ?? 'resource'));
		$operations=[
			'view_any',
			'view',
			'create',
			'update',
			'delete',
			'force_delete',
			'restore',
			'duplicate',
			'export',
			'import',
		];
		$permissions=[];
		foreach($operations as $operation){
			$permissions[$operation]=PanelPermissionBridge::name($resource, $operation, $options);
		}
		$actionPermissions=[];
		foreach($actions as $name=>$action){
			$name=Resource::normalizeName((string)($action['name'] ?? $name));
			if($name!==''){
				$actionPermissions[$name]=PanelPermissionBridge::actionName($resource, $name, $options);
			}
		}
		$relationPermissions=[];
		foreach($relations as $name=>$relation){
			$name=Resource::normalizeName((string)($relation['name'] ?? $name));
			if($name!==''){
				$relationPermissions[$name]=[
					'view'=>PanelPermissionBridge::relationName($resource, $name, 'view', $options),
					'update'=>PanelPermissionBridge::relationName($resource, $name, 'update', $options),
				];
			}
		}
		$flat=array_values($permissions);
		foreach($actionPermissions as $permission){
			$flat[]=$permission;
		}
		foreach($relationPermissions as $relation){
			foreach($relation as $permission){
				$flat[]=$permission;
			}
		}
		$flat=array_values(array_unique($flat));
		sort($flat, SORT_NATURAL);
		return [
			'type'=>'resource_permission_manifest',
			'resource'=>$resource,
			'prefix'=>(string)($options['permission_prefix'] ?? 'panel'),
			'resource_prefix'=>(string)($options['resource_prefix'] ?? ''),
			'super_permission'=>(string)($options['super_permission'] ?? 'panel.*'),
			'operations'=>$permissions,
			'actions'=>$actionPermissions,
			'relations'=>$relationPermissions,
			'permissions'=>$flat,
			'counts'=>[
				'operations'=>count($permissions),
				'actions'=>count($actionPermissions),
				'relations'=>array_sum(array_map('count', $relationPermissions)),
				'total'=>count($flat),
			],
		];
	}

	/**
	 * Aggregates resource capabilities from child manifests and policies.
	 *
	 * @param array<string,mixed> $definition Resource definition array.
	 * @param array<string,mixed> $form Create/edit form manifest.
	 * @param array<string,mixed> $bulkForm Bulk form manifest.
	 * @param array<string,mixed> $infolist Infolist manifest.
	 * @param array<string,mixed> $table Table manifest.
	 * @param array<string,array<string,mixed>> $actions Action manifests.
	 * @param array<string,array<string,mixed>> $relations Relation manifests.
	 * @param array<string,mixed> $permission Permission manifest payload.
	 * @return array<string,array<string,mixed>> Capability summary payload.
	 */
	private static function capabilities(array $definition, array $form, array $bulkForm, array $infolist, array $table, array $actions, array $relations, array $permission): array {
		$recordSurface=self::recordSurface($definition, $infolist, $relations);
		return [
			'forms'=>[
				'fields'=>(int)($form['field_count'] ?? 0),
				'components'=>(int)($form['component_count'] ?? 0),
				'bulk_fields'=>(int)($bulkForm['field_count'] ?? 0),
				'has_live_state'=>(bool)($form['capabilities']['behavior']['has_live_state'] ?? false),
				'has_conditionals'=>(bool)($form['capabilities']['behavior']['has_conditionals'] ?? false),
				'has_validation'=>(bool)($form['capabilities']['behavior']['has_validation'] ?? false),
			],
			'table'=>[
				'columns'=>(int)($table['capabilities']['columns']['total'] ?? 0),
				'searchable_columns'=>(int)($table['capabilities']['columns']['searchable'] ?? 0),
				'filters'=>(int)($table['capabilities']['controls']['filters'] ?? 0),
				'views'=>(int)($table['capabilities']['controls']['views'] ?? 0),
				'groups'=>(int)($table['capabilities']['controls']['groups'] ?? 0),
				'summaries'=>(int)($table['capabilities']['controls']['summaries'] ?? 0),
			],
			'actions'=>[
				'total'=>count($actions),
				'forms'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['has_form'] ?? false)===true)),
				'modals'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['modal'] ?? false)===true)),
				'bulk'=>count(array_filter($actions, static fn(array $action): bool => ($action['interaction']['bulk'] ?? false)===true)),
			],
			'relations'=>[
				'total'=>count($relations),
				'writable'=>count(array_filter($relations, static fn(array $relation): bool => ($relation['operations']['read_only'] ?? true)===false)),
				'queryable'=>count(array_filter($relations, static fn(array $relation): bool => ($relation['data']['queryable'] ?? false)===true)),
			],
			'record_surface'=>[
				'sections'=>(int)($recordSurface['section_count'] ?? 0),
				'infolist_fields'=>(int)($recordSurface['infolist_fields'] ?? 0),
				'infolist_components'=>(int)($recordSurface['infolist_components'] ?? 0),
			],
			'permission'=>[
				'total'=>(int)($permission['counts']['total'] ?? 0),
				'operations'=>(int)($permission['counts']['operations'] ?? 0),
				'actions'=>(int)($permission['counts']['actions'] ?? 0),
				'relations'=>(int)($permission['counts']['relations'] ?? 0),
			],
		];
	}

	/**
	 * Builds a lightweight action payload when full action manifestation fails.
	 *
	 * @param array<string,mixed> $definition Action definition array.
	 * @param ?\Throwable $exception Optional manifestation failure.
	 * @return array{type:string,kind:mixed,name:string,label:string,interaction:array{has_form:bool,modal:bool,bulk:bool},effects:array{refresh_count:int,event_count:int},error:?string} Fallback action manifest payload.
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
	 * Builds a child-manifest error payload.
	 *
	 * @param string $type Manifest type that failed.
	 * @param \Throwable $exception Failure raised by the child manifest builder.
	 * @return array{type:string,error:string,field_count:int,component_count:int,capabilities:array<string,mixed>} Error manifest payload.
	 */
	private static function errorManifest(string $type, \Throwable $exception): array {
		return [
			'type'=>$type,
			'error'=>$exception->getMessage(),
			'field_count'=>0,
			'component_count'=>0,
			'capabilities'=>[],
		];
	}

	/**
	 * Converts resource machine names into display labels for fallbacks.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Resource when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Resource' : ucwords($value);
	}
}
