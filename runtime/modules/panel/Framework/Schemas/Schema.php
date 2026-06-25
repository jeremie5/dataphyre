<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable panel schema containing fields, sections, groups, tabs, steps, grid settings, and metadata.
 *
 * Schema is the bridge between legacy ResourceForm definitions and the newer component tree used
 * by manifests and lifecycle processing. It can normalize array definitions, expose flattened field
 * and section indexes, build manifests, and drive hydrate/dehydrate/validate/submit state flows.
 */
final class Schema {
	use PanelExtensible;

	/** @var array<int, SchemaComponent> */
	private array $components=[];
	private int $columns=1;
	private array $meta=[];

	/**
	 * Creates a configured schema from component definitions.
	 *
	 * @param list<SchemaComponent|Field|array<string,mixed>|string> $components Initial SchemaComponent, Field, section, array, or string definitions.
	 * @return self New schema after PanelExtensible configuration hooks run.
	 */
	public static function make(array $components=[]): self {
		return self::configured(new self())->components($components);
	}

	/**
	 * Normalizes a schema-like value into a Schema instance.
	 *
	 * Accepts existing Schema instances, ResourceForm instances, list-style component arrays, and
	 * associative payloads containing `components`, or legacy `fields`/`sections` definitions.
	 *
	 * @param mixed $definition Schema, ResourceForm, array payload, or unsupported value.
	 * @return ?self Normalized schema, or null when the value cannot describe a schema.
	 */
	public static function from(mixed $definition): ?self {
		if($definition instanceof self){
			return $definition;
		}
		if($definition instanceof ResourceForm){
			return self::fromForm($definition);
		}
		if(!is_array($definition)){
			return null;
		}
		if(is_array($definition['components'] ?? null)){
			$schema=self::make($definition['components']);
			if(isset($definition['columns'])){
				$schema=$schema->columns(is_array($definition['columns']) ? $definition['columns'] : (int)$definition['columns']);
			}
			if(isset($definition['meta']) && is_array($definition['meta'])){
				$schema=$schema->meta($definition['meta']);
			}
			return $schema;
		}
		if(is_array($definition['fields'] ?? null) || is_array($definition['sections'] ?? null)){
			$form=ResourceForm::make();
			if(isset($definition['columns'])){
				$form=$form->columns(is_array($definition['columns']) ? $definition['columns'] : (int)$definition['columns']);
			}
			if(isset($definition['meta']) && is_array($definition['meta'])){
				$form=$form->meta($definition['meta']);
			}
			if(is_array($definition['sections'] ?? null)){
				$form=$form->sections($definition['sections']);
			}
			if(is_array($definition['fields'] ?? null)){
				$form=$form->fields($definition['fields']);
			}
			return self::fromForm($form);
		}
		return array_is_list($definition) ? self::make($definition) : null;
	}

	/**
	 * Converts a ResourceForm into a component schema.
	 *
	 * Fields with `meta.section` are grouped under matching form sections; unsectioned fields are
	 * appended as loose field components. Form columns and metadata are preserved.
	 *
	 * @param ResourceForm $form Form definition to convert.
	 * @return self Schema containing section components and loose field components.
	 */
	public static function fromForm(ResourceForm $form): self {
		$schema=self::make();
		$sections=$form->sectionsList();
		$sectioned=[];
		$loose=[];
		foreach($form->fieldsList() as $field){
			$meta=$field->toArray();
			$sectionName=Resource::normalizeName((string)($meta['meta']['section'] ?? ''));
			if($sectionName!=='' && isset($sections[$sectionName])){
				$sectioned[$sectionName][]=$field;
				continue;
			}
			$loose[]=$field;
		}
		foreach($sections as $sectionName=>$section){
			$schema=$schema->component(SchemaComponent::section($section, $sectioned[$sectionName] ?? []));
		}
		foreach($loose as $field){
			$schema=$schema->field($field);
		}
		return $schema->columns($form->columnsCount())->meta($form->metadata());
	}

	/**
	 * Replaces the component tree with normalized component definitions.
	 *
	 * @param list<SchemaComponent|Field|array<string,mixed>|string> $components Component definitions accepted by component().
	 * @return self Cloned schema with a fresh component list.
	 */
	public function components(array $components): self {
		$clone=clone $this;
		$clone->components=[];
		foreach($components as $component){
			$clone=$clone->component($component);
		}
		return $clone;
	}

	/**
	 * Appends one component to the schema.
	 *
	 * Infolist entries are converted to fields before component normalization. Unsupported or empty
	 * component definitions are ignored and return the current schema unchanged.
	 *
	 * @param SchemaComponent|Field|FormSection|InfolistEntry|array|string $component Component definition.
	 * @return self Cloned schema when a component is accepted; current schema when ignored.
	 */
	public function component(SchemaComponent|Field|FormSection|InfolistEntry|array|string $component): self {
		if($component instanceof InfolistEntry){
			$component=$component->field();
		}
		$component=SchemaComponent::from($component);
		if(!$component instanceof SchemaComponent){
			return $this;
		}
		$clone=clone $this;
		$clone->components[]=$component;
		return $clone;
	}

	/**
	 * Appends a field component.
	 *
	 * @param Field|InfolistEntry|array|string $field Field or field-like definition.
	 * @param ?string $type Optional field type when the field is created from a string/array.
	 * @return self Schema with the field component appended.
	 */
	public function field(Field|InfolistEntry|array|string $field, ?string $type=null): self {
		return $this->component(SchemaComponent::field($field, $type));
	}

	/**
	 * Appends an infolist entry as a field component.
	 *
	 * @param Field|InfolistEntry|array|string $field Entry or field-like definition.
	 * @param ?string $type Optional entry type when built from a string/array.
	 * @return self Schema with the entry's backing field appended.
	 */
	public function entry(Field|InfolistEntry|array|string $field, ?string $type=null): self {
		$entry=InfolistEntry::from($field, $type);
		return $this->field($entry->field());
	}

	/**
	 * Appends a section component with optional child fields.
	 *
	 * @param FormSection|array|string $section Section definition.
	 * @param list<SchemaComponent|Field|array<string,mixed>|string> $fields Child field/component definitions placed in the section.
	 * @return self Schema with the section component appended.
	 */
	public function section(FormSection|array|string $section, array $fields=[]): self {
		return $this->component(SchemaComponent::section($section, $fields));
	}

	/**
	 * Appends a named group component.
	 *
	 * @param string $name Group label/name.
	 * @param list<SchemaComponent|Field|array<string,mixed>|string> $children Child component definitions.
	 * @return self Schema with the group component appended.
	 */
	public function group(string $name, array $children=[]): self {
		return $this->component(SchemaComponent::group($name, $children));
	}

	/**
	 * Appends a tab component.
	 *
	 * @param string $name Tab label/name.
	 * @param list<SchemaComponent|Field|array<string,mixed>|string> $children Child component definitions.
	 * @return self Schema with the tab component appended.
	 */
	public function tab(string $name, array $children=[]): self {
		return $this->component(SchemaComponent::tab($name, $children));
	}

	/**
	 * Appends a step component for wizard-style layouts.
	 *
	 * @param string $name Step label/name.
	 * @param list<SchemaComponent|Field|array<string,mixed>|string> $children Child component definitions.
	 * @return self Schema with the step component appended.
	 */
	public function step(string $name, array $children=[]): self {
		return $this->component(SchemaComponent::step($name, $children));
	}

	/**
	 * Sets fixed or responsive schema grid columns.
	 *
	 * Integer values are clamped to 1..12. Responsive arrays are normalized by breakpoint and also
	 * stored in metadata as `grid_columns`.
	 *
	 * @param int|array $columns Fixed column count or breakpoint-to-count map.
	 * @return self Cloned schema with updated grid settings.
	 */
	public function columns(int|array $columns): self {
		$clone=clone $this;
		if(is_array($columns)){
			$normalized=self::normalizeGridColumns($columns);
			$clone->columns=max(1, max(array_map(static fn(mixed $value): int => (int)$value, $normalized ?: [1])));
			$clone->meta=array_replace($clone->meta, ['grid_columns'=>$normalized]);
			return $clone;
		}
		$clone->columns=max(1, min(12, $columns));
		return $clone;
	}

	/**
	 * Merges metadata into the schema.
	 *
	 * @param array<string,mixed> $meta Metadata consumed by manifests, lifecycle, or panel extensions.
	 * @return self Cloned schema with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Returns the root component list.
	 *
	 * @return array<int,SchemaComponent> Root components in render order.
	 */
	public function componentsList(): array {
		return $this->components;
	}

	/**
	 * Returns flattened fields from every component.
	 *
	 * Later components with the same field name replace earlier entries, matching manifest/lifecycle lookup behavior.
	 *
	 * @return array<string,Field> Fields keyed by normalized field name.
	 */
	public function fieldsList(): array {
		$fields=[];
		foreach($this->components as $component){
			foreach($component->fieldsList() as $name=>$field){
				$fields[$name]=$field;
			}
		}
		return $fields;
	}

	/**
	 * Returns flattened sections from every component.
	 *
	 * @return array<string,FormSection> Sections keyed by normalized section name.
	 */
	public function sectionsList(): array {
		$sections=[];
		foreach($this->components as $component){
			foreach($component->sectionsList() as $name=>$section){
				$sections[$name]=$section;
			}
		}
		return $sections;
	}

	/**
	 * Returns the effective maximum column count.
	 *
	 * @return int Fixed column count, or the maximum configured responsive count.
	 */
	public function columnsCount(): int {
		return $this->columns;
	}

	/**
	 * Returns responsive grid columns.
	 *
	 * @return array<string,int> Breakpoint-to-column map, falling back to `default`.
	 */
	public function responsiveColumns(): array {
		return is_array($this->meta['grid_columns'] ?? null) ? $this->meta['grid_columns'] : ['default'=>$this->columns];
	}

	/**
	 * Returns schema metadata.
	 *
	 * @return array<string,mixed> Metadata attached to manifests and lifecycle construction.
	 */
	public function metadata(): array {
		return $this->meta;
	}

	/**
	 * Converts the schema back into a ResourceForm.
	 *
	 * Sections and fields are flattened into the form API while preserving schema metadata and
	 * either the requested column count or the schema's effective count.
	 *
	 * @param ?int $columns Optional form column count override.
	 * @return ResourceForm Form representation of the schema.
	 */
	public function toForm(?int $columns=null): ResourceForm {
		$form=ResourceForm::make()->columns($columns ?? $this->columns)->meta($this->meta);
		foreach($this->sectionsList() as $section){
			$form=$form->section($section);
		}
		foreach($this->fieldsList() as $field){
			$form=$form->field($field);
		}
		return $form;
	}

	/**
	 * Creates a lifecycle processor for the schema fields.
	 *
	 * @param array<string,mixed> $meta Runtime metadata merged over schema metadata.
	 * @return SchemaLifecycle Lifecycle processor for hydrate/dehydrate/validate/submit flows.
	 */
	public function lifecycle(array $meta=[]): SchemaLifecycle {
		return SchemaLifecycle::make($this->fieldsList(), array_replace($this->meta, $meta));
	}

	/**
	 * Builds the panel manifest for this schema.
	 *
	 * @param ?string $operation Operation context such as create, edit, view, or index.
	 * @param array<string,mixed> $meta Runtime metadata merged over schema metadata.
	 * @return array Manifest payload generated by SchemaManifest.
	 */
	public function manifest(?string $operation=null, array $meta=[]): array {
		return SchemaManifest::fromSchema($this, $operation, array_replace($this->meta, $meta))->toArray();
	}

	/**
	 * Hydrates schema field state from an optional record and request.
	 *
	 * @param mixed $record Existing record used as field source data.
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @return PanelFormState Hydrated field state.
	 */
	public function hydrate(mixed $record=null, ?PanelRequest $request=null): PanelFormState {
		return $this->lifecycle()->hydrate($record, $request);
	}

	/**
	 * Dehydrates request input into schema field state.
	 *
	 * @param PanelRequest $request Current panel request containing submitted values.
	 * @param mixed $record Existing record context.
	 * @param ?string $operation Operation context for field rules.
	 * @return PanelFormState Dehydrated field state.
	 */
	public function dehydrate(PanelRequest $request, mixed $record=null, ?string $operation=null): PanelFormState {
		return $this->lifecycle()->dehydrate($request, $record, $operation);
	}

	/**
	 * Validates a value array against schema field rules.
	 *
	 * @param array<string,mixed> $values Candidate field values.
	 * @param mixed $record Existing record context.
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?string $operation Operation context for field rules.
	 * @return PanelFormState Validation state with values and errors.
	 */
	public function validate(array $values, mixed $record=null, ?PanelRequest $request=null, ?string $operation=null): PanelFormState {
		return $this->lifecycle()->validate($values, $record, $request, $operation);
	}

	/**
	 * Dehydrates and validates the current request as a form submission.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param mixed $record Existing record context.
	 * @param ?string $operation Operation context for field rules.
	 * @return PanelFormState Submitted state with validation results.
	 */
	public function submit(PanelRequest $request, mixed $record=null, ?string $operation=null): PanelFormState {
		return $this->lifecycle()->submit($request, $record, $operation);
	}

	/**
	 * Resolves schema field state from record, request, operation, and optional input values.
	 *
	 * @param mixed $record Existing record context.
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?string $operation Operation context for field rules.
	 * @param array<string,mixed> $input Explicit input values.
	 * @param bool $validate Whether validation should run while building state.
	 * @return PanelFormState Resolved schema state.
	 */
	public function state(
		mixed $record=null,
		?PanelRequest $request=null,
		?string $operation=null,
		array $input=[],
		bool $validate=false
	): PanelFormState {
		return $this->lifecycle()->state($record, $request, $operation, $input, $validate);
	}

	/**
	 * Serializes the schema for diagnostics and manifest tooling.
	 *
	 * @return array{type:string,columns:int,components:list<array<string,mixed>>,fields:list<array<string,mixed>>,sections:list<array<string,mixed>>,meta:array<string,mixed>} Schema payload.
	 */
	public function toArray(): array {
		return [
			'type'=>'schema',
			'columns'=>$this->columns,
			'components'=>array_map(static fn(SchemaComponent $component): array => $component->toArray(), $this->components),
			'fields'=>array_map(static fn(Field $field): array => $field->toArray(), array_values($this->fieldsList())),
			'sections'=>array_map(static fn(FormSection $section): array => $section->toArray(), array_values($this->sectionsList())),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes responsive grid column definitions.
	 *
	 * Breakpoint aliases are collapsed through normalizeGridBreakpoint(), invalid
	 * breakpoint names are discarded, and every retained column count is clamped to
	 * the renderer-supported 1..12 range used by schema manifests and form layout.
	 *
	 * @param array<string,int|string> $columns Breakpoint-to-column map supplied by schema callers.
	 * @return array<string, int> Normalized responsive column map.
	 */
	private static function normalizeGridColumns(array $columns): array {
		$normalized=[];
		foreach($columns as $breakpoint=>$value){
			$breakpoint=self::normalizeGridBreakpoint((string)$breakpoint);
			if($breakpoint!==''){
				$normalized[$breakpoint]=max(1, min(12, (int)$value));
			}
		}
		return $normalized;
	}

	/**
	 * Converts public breakpoint aliases into manifest breakpoint keys.
	 *
	 * Blank/base/default aliases map to default, common size names map to the
	 * renderer's responsive keys, and unknown names return an empty string so the
	 * caller can drop unsupported breakpoints without leaking invalid metadata.
	 *
	 * @param string $breakpoint Caller-supplied breakpoint name.
	 * @return string Normalized breakpoint key, or empty string when unsupported.
	 */
	private static function normalizeGridBreakpoint(string $breakpoint): string {
		$breakpoint=strtolower(trim(str_replace(['-', ' '], '_', $breakpoint)));
		return match($breakpoint){
			'', 'base', 'default', 'initial'=>'default',
			'sm', 'small'=>'sm',
			'md', 'medium'=>'md',
			'lg', 'large'=>'lg',
			'xl'=>'xl',
			'2xl', 'xxl', 'wide'=>'2xl',
			default=>'',
		};
	}
}
