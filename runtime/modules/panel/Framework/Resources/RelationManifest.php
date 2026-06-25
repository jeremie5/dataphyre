<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a resource relation manager for clients and diagnostics.
 *
 * Relation manifests expose related data keys, relation operations, table shape,
 * facts, generated permission names, and writable capabilities without executing
 * attach, detach, associate, dissociate, reorder, or pivot handlers.
 */
final class RelationManifest {

	/**
	 * Stores the relation source and manifest context.
	 *
	 * @param RelationManager|array<string,mixed> $relation Live relation manager or serialized relation definition.
	 * @param ?PanelRequest $request Current request used by child table manifests.
	 * @param array<string,mixed> $meta Additional manifest metadata such as owning resource.
	 */
	private function __construct(
		private readonly RelationManager|array $relation,
		private readonly ?PanelRequest $request=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a relation manifest builder.
	 *
	 * @param RelationManager|array<string,mixed> $relation Relation source to describe.
	 * @param ?PanelRequest $request Current request context.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @return self New immutable manifest builder.
	 */
	public static function from(RelationManager|array $relation, ?PanelRequest $request=null, array $meta=[]): self {
		return new self($relation, $request, $meta);
	}

	/**
	 * Materializes the relation_manifest payload.
	 *
	 * @return array<string,mixed> Relation manifest payload.
	 */
	public function toArray(): array {
		$definition=$this->relation instanceof RelationManager ? $this->relation->toArray() : $this->relation;
		$name=(string)($definition['name'] ?? 'relation');
		$table=$this->tableManifest($definition, $name);
		$facts=self::facts($definition);
		$operations=self::operations($definition);
		$data=self::data($definition);
		$permission=$this->permissionManifest($name);
		$manifest=[
			'type'=>'relation_manifest',
			'name'=>$name,
			'label'=>(string)($definition['label'] ?? self::humanize($name)),
			'presentation'=>[
				'description'=>$definition['description'] ?? null,
				'description_dynamic'=>($definition['description_dynamic'] ?? false)===true,
				'parent_title'=>$definition['parent_title'] ?? null,
				'parent_title_dynamic'=>($definition['parent_title_dynamic'] ?? false)===true,
				'badge'=>$definition['badge'] ?? null,
				'badge_dynamic'=>($definition['badge_dynamic'] ?? false)===true,
				'empty_state'=>$definition['empty_state'] ?? null,
				'empty_description'=>$definition['empty_description'] ?? null,
			],
			'data'=>$data,
			'operations'=>$operations,
			'authorization'=>[
				'authorizes'=>($definition['authorizes'] ?? false)===true,
			],
			'authorizes'=>($definition['authorizes'] ?? false)===true,
			'facts'=>$facts,
			'table'=>$table,
			'permission'=>$permission,
			'capabilities'=>self::capabilities($definition, $data, $operations, $facts, $table, $permission),
			'meta'=>array_replace(is_array($definition['meta'] ?? null) ? $definition['meta'] : [], $this->meta),
		];
		PanelTrace::record('relation.manifest.described', [
			'name'=>$manifest['name'],
			'resource'=>(string)($this->meta['resource'] ?? ''),
			'columns'=>(int)($manifest['capabilities']['table']['columns'] ?? 0),
			'facts'=>count($facts),
			'writable'=>($operations['writable'] ?? false)===true,
		]);
		return $manifest;
	}

	/**
	 * Builds the relation table manifest.
	 *
	 * Table manifestation failures are returned as an error payload so the owning
	 * resource manifest can still describe relation operations and permissions.
	 *
	 * @param array<string,mixed> $definition Relation definition array.
	 * @param string $name Relation machine name.
	 * @return array<string,mixed> Table manifest or error payload.
	 */
	private function tableManifest(array $definition, string $name): array {
		try{
			return TableManifest::from(is_array($definition['table_schema'] ?? null) ? $definition['table_schema'] : [], null, $this->request, array_replace([
				'surface'=>'relation_manifest',
				'relation'=>$name,
			], $this->meta))->toArray();
		}
		catch(\Throwable $exception){
			return [
				'type'=>'table_manifest',
				'error'=>$exception->getMessage(),
				'columns'=>[],
				'filters'=>[],
				'views'=>[],
				'groups'=>[],
				'summaries'=>[],
				'capabilities'=>[],
			];
		}
	}

	/**
	 * Extracts relation data-source and key-mapping metadata.
	 *
	 * @param array<string,mixed> $definition Relation definition array.
	 * @return array<string,mixed> Data metadata payload.
	 */
	private static function data(array $definition): array {
		return [
			'related_resource'=>$definition['related_resource'] ?? null,
			'table'=>$definition['table'] ?? null,
			'foreign_key'=>$definition['foreign_key'] ?? null,
			'local_key'=>$definition['local_key'] ?? null,
			'queryable'=>($definition['queryable'] ?? false)===true,
		];
	}

	/**
	 * Describes supported relation operations and handler availability.
	 *
	 * Read-only relations disable writable operation entries even when labels or
	 * handlers are configured, making the emitted operation state authoritative
	 * for clients and renderers.
	 *
	 * @param array<string,mixed> $definition Relation definition array.
	 * @return array<string,mixed> Operation manifest payload.
	 */
	private static function operations(array $definition): array {
		$readOnly=($definition['read_only'] ?? false)===true;
		$create=($definition['create_enabled'] ?? false)===true;
		$attach=($definition['attach_enabled'] ?? false)===true;
		$detach=($definition['detach_enabled'] ?? false)===true;
		$associate=($definition['associate_enabled'] ?? false)===true;
		$dissociate=($definition['dissociate_enabled'] ?? false)===true;
		$reorder=($definition['reorder_enabled'] ?? false)===true;
		$attaches=($definition['attaches'] ?? false)===true;
		$detaches=($definition['detaches'] ?? false)===true;
		$associates=($definition['associates'] ?? false)===true;
		$dissociates=($definition['dissociates'] ?? false)===true;
		$reorders=($definition['reorders'] ?? false)===true;
		$pivotFields=is_array($definition['pivot_fields'] ?? null) ? array_values($definition['pivot_fields']) : [];
		$updatesPivot=($definition['updates_pivot'] ?? false)===true;
		$entries=is_array($definition['operations'] ?? null) ? $definition['operations'] : [];
		$entry=static fn(string $name): array => is_array($entries[$name] ?? null) ? $entries[$name] : [];
		$operationEntries=[
			'attach'=>self::operationEntry($entry('attach'), 'attach', (string)($definition['attach_label'] ?? 'Attach record'), 'Attach record', $attach && $attaches && !$readOnly, !$readOnly, $readOnly, $attaches),
			'detach'=>self::operationEntry($entry('detach'), 'detach', (string)($definition['detach_label'] ?? 'Detach'), 'Detach record', $detach && $detaches && !$readOnly, !$readOnly, $readOnly, $detaches),
			'associate'=>self::operationEntry($entry('associate'), 'associate', (string)($definition['associate_label'] ?? 'Associate record'), 'Associate record', $associate && $associates && !$readOnly, !$readOnly, $readOnly, $associates),
			'dissociate'=>self::operationEntry($entry('dissociate'), 'dissociate', (string)($definition['dissociate_label'] ?? 'Dissociate'), 'Dissociate record', $dissociate && $dissociates && !$readOnly, !$readOnly, $readOnly, $dissociates),
			'reorder'=>array_replace(self::operationEntry($entry('reorder'), 'reorder', (string)($definition['reorder_label'] ?? 'Reorder'), 'Reorder records', $reorder && $reorders && !$readOnly, !$readOnly, $readOnly, $reorders), [
				'order_column'=>$definition['order_column'] ?? null,
			]),
			'update_pivot'=>array_replace(self::operationEntry($entry('update_pivot'), 'update_pivot', 'Update pivot', 'Update pivot fields', $updatesPivot && $pivotFields!==[] && !$readOnly, !$readOnly, $readOnly, $updatesPivot), [
				'pivot_fields'=>$pivotFields,
			]),
		];
		return [
			'read_only'=>$readOnly,
			'create'=>$create,
			'attach'=>$attach,
			'detach'=>$detach,
			'associate'=>$associate,
			'dissociate'=>$dissociate,
			'reorder'=>$reorder,
			'attach_label'=>(string)($definition['attach_label'] ?? 'Attach record'),
			'detach_label'=>(string)($definition['detach_label'] ?? 'Detach'),
			'associate_label'=>(string)($definition['associate_label'] ?? 'Associate record'),
			'dissociate_label'=>(string)($definition['dissociate_label'] ?? 'Dissociate'),
			'reorder_label'=>(string)($definition['reorder_label'] ?? 'Reorder'),
			'order_column'=>$definition['order_column'] ?? null,
			'pivot_fields'=>$pivotFields,
			'attaches'=>$attaches,
			'detaches'=>$detaches,
			'associates'=>$associates,
			'dissociates'=>$dissociates,
			'reorders'=>$reorders,
			'updates_pivot'=>$updatesPivot,
			'entries'=>$operationEntries,
			'attach_entry'=>$operationEntries['attach'],
			'detach_entry'=>$operationEntries['detach'],
			'associate_entry'=>$operationEntries['associate'],
			'dissociate_entry'=>$operationEntries['dissociate'],
			'reorder_entry'=>$operationEntries['reorder'],
			'update_pivot_entry'=>$operationEntries['update_pivot'],
			'writable'=>!$readOnly && ($create || $attach || $detach || $associate || $dissociate || $reorder || $attaches || $detaches || $associates || $dissociates || $reorders || $updatesPivot),
			'custom_attach_handler'=>$attaches,
			'custom_detach_handler'=>$detaches,
			'custom_associate_handler'=>$associates,
			'custom_dissociate_handler'=>$dissociates,
			'custom_reorder_handler'=>$reorders,
			'custom_pivot_handler'=>$updatesPivot,
		];
	}

	/**
	 * Normalizes one relation operation entry.
	 *
	 * @param array<string,mixed> $entry Serialized operation override.
	 * @param string $name Operation machine name.
	 * @param string $label Default button label.
	 * @param string $modalLabel Default modal label.
	 * @param bool $enabled Whether the operation is enabled after configuration checks.
	 * @param bool $authorized Whether the operation is authorized in this manifest context.
	 * @param bool $readOnly Whether the relation is globally read-only.
	 * @param bool $hasHandler Whether a custom handler is registered.
	 * @return array{name:string,label:string,enabled:bool,authorized:bool,modal_label:string,disabled_reason:?string,handler:bool} Normalized operation-entry payload.
	 */
	private static function operationEntry(array $entry, string $name, string $label, string $modalLabel, bool $enabled, bool $authorized, bool $readOnly, bool $hasHandler): array {
		$reason=is_string($entry['disabled_reason'] ?? null) ? trim((string)$entry['disabled_reason']) : '';
		if($readOnly){
			$reason='Relation is read-only.';
		}
		elseif(!$hasHandler){
			$reason='Operation handler is not registered.';
		}
		elseif(!$enabled){
			$reason=$reason!=='' ? $reason : 'Operation is not enabled for this relation.';
		}
		return [
			'name'=>$name,
			'label'=>(string)($entry['label'] ?? $label),
			'enabled'=>$enabled,
			'authorized'=>$authorized,
			'modal_label'=>(string)($entry['modal_label'] ?? $modalLabel),
			'disabled_reason'=>$enabled && $authorized ? null : ($reason!=='' ? $reason : null),
			'handler'=>$hasHandler,
		];
	}

	/**
	 * Extracts fact rows displayed with the relation.
	 *
	 * @param array<string,mixed> $definition Relation definition array.
	 * @return list<array<string,mixed>> Fact payload rows.
	 */
	private static function facts(array $definition): array {
		return is_array($definition['facts'] ?? null) ? array_values($definition['facts']) : [];
	}

	/**
	 * Builds generated permission names for relation viewing and updating.
	 *
	 * @param string $name Relation machine name.
	 * @return array<string,mixed> Relation permission manifest payload.
	 */
	private function permissionManifest(string $name): array {
		$options=PanelPermissionBridge::options();
		$resource=PanelPermissionBridge::resourceName((string)($this->meta['resource'] ?? ''));
		$relation=Resource::normalizeName($name);
		$view=$resource!=='' && $relation!=='' ? PanelPermissionBridge::relationName($resource, $relation, 'view', $options) : null;
		$update=$resource!=='' && $relation!=='' ? PanelPermissionBridge::relationName($resource, $relation, 'update', $options) : null;
		return [
			'type'=>'relation_permission_manifest',
			'resource'=>$resource,
			'relation'=>$relation,
			'operations'=>[
				'view'=>$view,
				'update'=>$update,
			],
			'permissions'=>array_values(array_filter([$view, $update], static fn(?string $permission): bool => is_string($permission) && $permission!=='')),
			'super_permission'=>(string)($options['super_permission'] ?? 'panel.*'),
		];
	}

	/**
	 * Aggregates relation capabilities from data, operations, table, and facts.
	 *
	 * @param array<string,mixed> $definition Relation definition array.
	 * @param array<string,mixed> $data Data metadata payload.
	 * @param array<string,mixed> $operations Operation manifest payload.
	 * @param list<array<string,mixed>> $facts Fact payload rows.
	 * @param array<string,mixed> $table Table manifest payload.
	 * @param array<string,mixed> $permission Permission manifest payload.
	 * @return array<string,mixed> Capability summary payload.
	 */
	private static function capabilities(array $definition, array $data, array $operations, array $facts, array $table, array $permission): array {
		$tableCapabilities=is_array($table['capabilities'] ?? null) ? $table['capabilities'] : [];
		$tableColumns=is_array($tableCapabilities['columns'] ?? null) ? $tableCapabilities['columns'] : [];
		$tableControls=is_array($tableCapabilities['controls'] ?? null) ? $tableCapabilities['controls'] : [];
		return [
			'presentation'=>[
				'dynamic_description'=>($definition['description_dynamic'] ?? false)===true,
				'dynamic_parent_title'=>($definition['parent_title_dynamic'] ?? false)===true,
				'dynamic_badge'=>($definition['badge_dynamic'] ?? false)===true,
				'has_empty_description'=>is_string($definition['empty_description'] ?? null) && trim((string)$definition['empty_description'])!=='',
			],
			'data'=>[
				'queryable'=>($data['queryable'] ?? false)===true,
				'has_related_resource'=>is_string($data['related_resource'] ?? null) && trim((string)$data['related_resource'])!=='',
				'has_database_table'=>is_string($data['table'] ?? null) && trim((string)$data['table'])!=='',
				'key_mapped'=>is_string($data['foreign_key'] ?? null) && is_string($data['local_key'] ?? null) && trim((string)$data['foreign_key'])!=='' && trim((string)$data['local_key'])!=='',
			],
			'operations'=>[
				'read_only'=>($operations['read_only'] ?? false)===true,
				'writable'=>($operations['writable'] ?? false)===true,
				'create'=>($operations['create'] ?? false)===true,
				'attach'=>($operations['attach'] ?? false)===true,
				'detach'=>($operations['detach'] ?? false)===true,
				'associate'=>($operations['associate'] ?? false)===true,
				'dissociate'=>($operations['dissociate'] ?? false)===true,
				'reorder'=>($operations['reorder'] ?? false)===true,
				'pivot_fields'=>is_array($operations['pivot_fields'] ?? null) ? count($operations['pivot_fields']) : 0,
				'updates_pivot'=>($operations['updates_pivot'] ?? false)===true,
				'custom_handlers'=>(($operations['custom_attach_handler'] ?? false)===true ? 1 : 0)
					+ (($operations['custom_detach_handler'] ?? false)===true ? 1 : 0)
					+ (($operations['custom_associate_handler'] ?? false)===true ? 1 : 0)
					+ (($operations['custom_dissociate_handler'] ?? false)===true ? 1 : 0)
					+ (($operations['custom_reorder_handler'] ?? false)===true ? 1 : 0)
					+ (($operations['custom_pivot_handler'] ?? false)===true ? 1 : 0),
			],
			'table'=>[
				'columns'=>(int)($tableColumns['total'] ?? 0),
				'searchable'=>(int)($tableColumns['searchable'] ?? 0),
				'sortable'=>(int)($tableColumns['sortable'] ?? 0),
				'filters'=>(int)($tableControls['filters'] ?? 0),
				'views'=>(int)($tableControls['views'] ?? 0),
				'groups'=>(int)($tableControls['groups'] ?? 0),
				'summaries'=>(int)($tableControls['summaries'] ?? 0),
			],
			'facts'=>[
				'total'=>count($facts),
				'dynamic'=>count(array_filter($facts, static fn(mixed $fact): bool => is_array($fact) && ($fact['type'] ?? '')==='computed')),
				'formatted'=>count(array_filter($facts, static fn(mixed $fact): bool => is_array($fact) && is_string($fact['format'] ?? null) && trim((string)$fact['format'])!=='')),
			],
			'permission'=>[
			'total'=>count($permission['permissions'] ?? []),
			],
		];
	}

	/**
	 * Converts relation machine names into display labels for fallbacks.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Relation when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Relation' : ucwords($value);
	}
}
