<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes Panel schemas as serializable manifests for tooling and diagnostics.
 *
 * Schema manifests flatten nested components, attach lifecycle-derived field
 * state, summarize sections and capabilities, and record trace metadata so
 * Panel inspectors can reason about forms without re-running renderer logic.
 */
final class SchemaManifest {

	/**
	 * Stores schema context for a later manifest description.
	 *
	 * @param Schema $schema Schema being described.
	 * @param ?string $operation Optional operation context.
	 * @param array<string,mixed> $meta Additional metadata merged into lifecycle and manifest output.
	 */
	private function __construct(
		private readonly Schema $schema,
		private readonly ?string $operation=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a manifest builder from a Schema instance.
	 *
	 * @param Schema $schema Schema to describe.
	 * @param ?string $operation Optional operation context such as create, edit, or view.
	 * @param array<string,mixed> $meta Additional lifecycle and manifest metadata.
	 * @return self Manifest builder.
	 */
	public static function fromSchema(Schema $schema, ?string $operation=null, array $meta=[]): self {
		return new self($schema, $operation, $meta);
	}

	/**
	 * Creates a manifest builder from a ResourceForm.
	 *
	 * Form metadata is merged before caller metadata so explicit manifest options
	 * can override form defaults.
	 *
	 * @param ResourceForm $form Form whose schema and metadata should be described.
	 * @param ?string $operation Optional operation context.
	 * @param array<string,mixed> $meta Additional lifecycle and manifest metadata.
	 * @return self Manifest builder.
	 */
	public static function fromForm(ResourceForm $form, ?string $operation=null, array $meta=[]): self {
		return new self(Schema::fromForm($form), $operation, array_replace($form->metadata(), $meta));
	}

	/**
	 * Creates a manifest builder from any supported schema definition.
	 *
	 * Existing manifests are returned as-is. Infolists are marked with usage
	 * metadata, forms use their ResourceForm metadata, and raw definitions are
	 * normalized through Schema::from().
	 *
	 * @param mixed $definition SchemaManifest, Schema, Infolist, ResourceForm, or raw schema definition.
	 * @param ?string $operation Optional operation context.
	 * @param array<string,mixed> $meta Additional lifecycle and manifest metadata.
	 * @return self Manifest builder.
	 */
	public static function from(mixed $definition, ?string $operation=null, array $meta=[]): self {
		if($definition instanceof self){
			return $definition;
		}
		if($definition instanceof Schema){
			return self::fromSchema($definition, $operation, $meta);
		}
		if($definition instanceof Infolist){
			return self::fromSchema($definition->schema(), $operation, array_replace(['usage'=>'infolist'], $meta));
		}
		if($definition instanceof ResourceForm){
			return self::fromForm($definition, $operation, $meta);
		}
		return self::fromSchema(Schema::from($definition) ?? Schema::make(), $operation, $meta);
	}

	/**
	 * Builds the schema renderer manifest with flattened fields and sections.
	 *
	 * @return array{type:string,operation:?string,columns:int,responsive_columns:array<string,int>,component_count:int,field_count:int,section_count:int,components:array<int,array<string,mixed>>,fields:array<string,array<string,mixed>>,sections:array<string,array<string,mixed>>,capabilities:array{layouts:array<string,int>,fields:array<string,int>,behavior:array<string,bool>},lifecycle:array<string,mixed>,meta:array<string,mixed>} Schema manifest.
	 */
	public function toArray(): array {
		$schema=$this->schema->toArray();
		$components=self::flattenComponents((array)($schema['components'] ?? []));
		$lifecycle=$this->schema->lifecycle($this->meta)->describe($this->operation);
		$fields=self::fieldManifests($lifecycle, $components);
		$sections=self::sectionManifests((array)($schema['sections'] ?? []), $components);
		$capabilities=self::capabilities($components, $fields, $sections);
		$manifest=[
			'type'=>'schema_manifest',
			'operation'=>$this->operation,
			'columns'=>(int)($schema['columns'] ?? 1),
			'responsive_columns'=>is_array($schema['meta']['grid_columns'] ?? null) ? $schema['meta']['grid_columns'] : ['default'=>(int)($schema['columns'] ?? 1)],
			'component_count'=>count($components),
			'field_count'=>count($fields),
			'section_count'=>count($sections),
			'components'=>$components,
			'fields'=>$fields,
			'sections'=>$sections,
			'capabilities'=>$capabilities,
			'lifecycle'=>$lifecycle,
			'meta'=>array_replace(is_array($schema['meta'] ?? null) ? $schema['meta'] : [], $this->meta),
		];
		PanelTrace::record('schema.manifest.described', [
			'operation'=>$this->operation,
			'component_count'=>$manifest['component_count'],
			'field_count'=>$manifest['field_count'],
			'section_count'=>$manifest['section_count'],
			'capabilities'=>$capabilities,
		]);
		return $manifest;
	}

	/**
	 * Flattens nested schema components into path-addressable manifest rows.
	 *
	 * @param array<int,array<string,mixed>|mixed> $components Nested schema component definitions.
	 * @param array<int,string> $path Parent path segments accumulated during recursion.
	 * @param ?string $parentId Stable id of the parent component row.
	 * @return array<int,array{id:string,parent_id:?string,kind:string,name:string,label:string,path:string,depth:int,field_name:?string,section_name:?string,children_count:int,capabilities:array<int,string>,meta:array<string,mixed>}> Flattened component rows.
	 */
	private static function flattenComponents(array $components, array $path=[], ?string $parentId=null): array {
		$rows=[];
		foreach(array_values($components) as $index=>$component){
			if(!is_array($component)){
				continue;
			}
			$kind=(string)($component['kind'] ?? 'field');
			$name=Resource::normalizeName((string)($component['name'] ?? $component['field']['name'] ?? $kind.'_'.$index));
			$label=trim((string)($component['label'] ?? $component['field']['label'] ?? $component['section']['label'] ?? self::humanize($name)));
			$currentPath=[...$path, $name!=='' ? $name : $kind.'_'.$index];
			$id=self::stableId($kind, $currentPath);
			$field=is_array($component['field'] ?? null) ? $component['field'] : null;
			$section=is_array($component['section'] ?? null) ? $component['section'] : null;
			$children=is_array($component['children'] ?? null) ? $component['children'] : [];
			$rows[]=[
				'id'=>$id,
				'parent_id'=>$parentId,
				'kind'=>$kind,
				'name'=>$name,
				'label'=>$label,
				'path'=>implode('.', $currentPath),
				'depth'=>count($path),
				'field_name'=>$field!==null ? (string)($field['name'] ?? '') : null,
				'section_name'=>$section!==null ? (string)($section['name'] ?? '') : null,
				'children_count'=>count($children),
				'capabilities'=>self::componentCapabilities($kind, $field, $section, $children),
				'meta'=>is_array($component['meta'] ?? null) ? $component['meta'] : [],
			];
			if($children!==[]){
				array_push($rows, ...self::flattenComponents($children, $currentPath, $id));
			}
		}
		return $rows;
	}

	/**
	 * Combines lifecycle field data with component ids, paths, and capabilities.
	 *
	 * @param array<string,mixed> $lifecycle Schema lifecycle description.
	 * @param array<int,array<string,mixed>> $components Flattened component rows.
	 * @return array<string,array<string,mixed>> Field manifest rows keyed by field name.
	 */
	private static function fieldManifests(array $lifecycle, array $components): array {
		$fieldComponents=[];
		foreach($components as $component){
			$fieldName=(string)($component['field_name'] ?? '');
			if($fieldName!==''){
				$fieldComponents[$fieldName]=$component;
			}
		}
		$fields=[];
		foreach((array)($lifecycle['fields'] ?? []) as $name=>$field){
			if(!is_array($field)){
				continue;
			}
			$name=(string)($field['name'] ?? $name);
			$component=$fieldComponents[$name] ?? [];
			$fields[$name]=array_replace($field, [
				'component_id'=>$component['id'] ?? null,
				'component_path'=>$component['path'] ?? null,
				'component_depth'=>$component['depth'] ?? null,
				'capabilities'=>self::fieldCapabilities($field),
			]);
		}
		return $fields;
	}

	/**
	 * Combines schema section definitions with their matching component rows.
	 *
	 * @param array<int,array<string,mixed>|mixed> $sections Section definitions from the schema.
	 * @param array<int,array<string,mixed>> $components Flattened component rows.
	 * @return array<string,array<string,mixed>> Section manifest rows keyed by section name.
	 */
	private static function sectionManifests(array $sections, array $components): array {
		$sectionComponents=[];
		foreach($components as $component){
			$sectionName=(string)($component['section_name'] ?? '');
			if($sectionName!==''){
				$sectionComponents[$sectionName]=$component;
			}
		}
		$rows=[];
		foreach($sections as $section){
			if(!is_array($section)){
				continue;
			}
			$name=(string)($section['name'] ?? '');
			if($name===''){
				continue;
			}
			$component=$sectionComponents[$name] ?? [];
			$rows[$name]=[
				'name'=>$name,
				'label'=>(string)($section['label'] ?? self::humanize($name)),
				'description'=>(string)($section['description'] ?? ''),
				'component_id'=>$component['id'] ?? null,
				'component_path'=>$component['path'] ?? null,
				'meta'=>is_array($section['meta'] ?? null) ? $section['meta'] : [],
			];
		}
		return $rows;
	}

	/**
	 * Summarizes layout, field, and behavioral capabilities for a schema.
	 *
	 * @param array<int,array<string,mixed>> $components Flattened component rows.
	 * @param array<string,array<string,mixed>> $fields Field manifest rows.
	 * @param array<string,array<string,mixed>> $sections Section manifest rows.
	 * @return array{layouts:array,fields:array,behavior:array} Capability summary.
	 */
	private static function capabilities(array $components, array $fields, array $sections): array {
		$kindCounts=[];
		foreach($components as $component){
			$kind=(string)($component['kind'] ?? 'field');
			$kindCounts[$kind]=($kindCounts[$kind] ?? 0)+1;
		}
		return [
			'layouts'=>[
				'tabs'=>(int)($kindCounts['tab'] ?? 0),
				'steps'=>(int)($kindCounts['step'] ?? 0),
				'groups'=>(int)($kindCounts['group'] ?? 0),
				'sections'=>count($sections),
				'nested_depth'=>self::maxDepth($components),
			],
			'fields'=>[
				'required'=>self::countFieldCapability($fields, 'required'),
				'readonly'=>self::countFieldCapability($fields, 'readonly'),
				'reactive'=>self::countFieldCapability($fields, 'reactive'),
				'state_updates'=>self::countFieldCapability($fields, 'state_updates'),
				'conditional'=>self::countFieldCapability($fields, 'conditional'),
				'hydrates'=>self::countFieldCapability($fields, 'hydrates'),
				'dehydrates'=>self::countFieldCapability($fields, 'dehydrates'),
				'previewable'=>self::countFieldCapability($fields, 'preview'),
				'suggested'=>self::countFieldCapability($fields, 'suggestions'),
				'masked'=>self::countFieldCapability($fields, 'mask'),
				'custom_controls'=>self::countFieldCapability($fields, 'custom_control'),
			],
			'behavior'=>[
				'has_live_state'=>self::countFieldCapability($fields, 'reactive')>0 || self::countFieldCapability($fields, 'state_updates')>0,
				'has_conditionals'=>self::countFieldCapability($fields, 'conditional')>0,
				'has_custom_hydration'=>self::countFieldCapability($fields, 'hydrates')>0 || self::countFieldCapability($fields, 'dehydrates')>0,
				'has_validation'=>self::countFieldCapability($fields, 'required')>0,
				'has_component_affordances'=>self::countFieldCapability($fields, 'preview')>0 || self::countFieldCapability($fields, 'suggestions')>0 || self::countFieldCapability($fields, 'mask')>0,
			],
		];
	}

	/**
	 * Derives capabilities for one schema component.
	 *
	 * @param string $kind Component kind such as field, section, tab, step, or group.
	 * @param ?array<string,mixed> $field Embedded field definition, when present.
	 * @param ?array<string,mixed> $section Embedded section definition, when present.
	 * @param array<int,array<string,mixed>|mixed> $children Child component definitions.
	 * @return array<int,string> Capability labels for the component.
	 */
	private static function componentCapabilities(string $kind, ?array $field, ?array $section, array $children): array {
		return array_values(array_filter([
			in_array($kind, ['tab', 'step', 'group', 'section'], true) ? 'layout' : null,
			$field!==null ? 'field' : null,
			$section!==null ? 'section' : null,
			$children!==[] ? 'children' : null,
			$field!==null && (($field['live'] ?? false) || ($field['reactive'] ?? false)) ? 'live_state' : null,
			$field!==null && (($field['conditional'] ?? false) || (($field['depends_on'] ?? [])!==[])) ? 'dependencies' : null,
			$field!==null && (($field['required'] ?? false) || (($field['rules'] ?? [])!==[])) ? 'validation' : null,
		], static fn(?string $value): bool => $value!==null));
	}

	/**
	 * Derives behavior and affordance capabilities for one field.
	 *
	 * @param array<string,mixed> $field Lifecycle field description.
	 * @return array<int,string> Unique capability labels for the field.
	 */
	private static function fieldCapabilities(array $field): array {
		$capabilities=[];
		foreach(['required', 'readonly', 'state_updates', 'hydrates', 'dehydrates'] as $flag){
			if(($field[$flag] ?? false)===true){
				$capabilities[]=$flag;
			}
		}
		if(($field['reactive'] ?? false)===true || ($field['live'] ?? false)===true || ($field['state_updates'] ?? false)===true){
			$capabilities[]='reactive';
		}
		if(($field['conditional'] ?? false)===true || (is_array($field['depends_on'] ?? null) && $field['depends_on']!==[])){
			$capabilities[]='conditional';
		}
		foreach((array)($field['component']['capabilities'] ?? []) as $capability){
			$capability=(string)$capability;
			if($capability!==''){
				$capabilities[]=$capability;
			}
		}
		return array_values(array_unique($capabilities));
	}

	/**
	 * Counts fields that expose a named capability.
	 *
	 * @param array<string,array<string,mixed>> $fields Field manifest rows.
	 * @param string $capability Capability label to count.
	 * @return int Number of fields containing the capability.
	 */
	private static function countFieldCapability(array $fields, string $capability): int {
		$count=0;
		foreach($fields as $field){
			if(in_array($capability, (array)($field['capabilities'] ?? []), true)){
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Returns the deepest component nesting level in a flattened schema.
	 *
	 * @param array<int,array<string,mixed>> $components Flattened component rows.
	 * @return int Maximum depth value.
	 */
	private static function maxDepth(array $components): int {
		$max=0;
		foreach($components as $component){
			$max=max($max, (int)($component['depth'] ?? 0));
		}
		return $max;
	}

	/**
	 * Builds a stable component id from kind and path.
	 *
	 * @param string $kind Component kind.
	 * @param array<int,string> $path Component path segments.
	 * @return string Stable schema component id.
	 */
	private static function stableId(string $kind, array $path): string {
		return 'schema_'.substr(sha1($kind.'|'.implode('|', $path)), 0, 12);
	}

	/**
	 * Converts a machine name into a readable fallback label.
	 *
	 * @param string $value Raw schema name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-'], ' ', $value));
		return $value==='' ? 'Untitled' : ucwords($value);
	}
}
