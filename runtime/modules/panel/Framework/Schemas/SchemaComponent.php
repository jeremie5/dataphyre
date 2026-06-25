<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Recursive schema component for panel forms and infolists.
 *
 * SchemaComponent normalizes field, section, group, tab, step, infolist, and
 * layout definitions into one tree shape. It lets resources describe nested UI
 * structure while still exposing flat field and section lists for renderers,
 * validation, and manifest generation. Components are immutable: mutators clone
 * and return updated trees.
 */
final class SchemaComponent {
	use PanelExtensible;

	private string $kind;
	private string $name;
	private string $label;
	private ?Field $field=null;
	private ?FormSection $section=null;
	/** @var array<int, self> */
	private array $children=[];
	private array $meta=[];

	/**
	 * Creates a normalized component shell.
	 *
	 * @param string $kind Component kind before normalization.
	 * @param string $name Component identifier before normalization.
	 */
	private function __construct(string $kind, string $name='') {
		$this->kind=self::normalizeKind($kind);
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured schema component.
	 *
	 * @param string $kind Component kind.
	 * @param string $name Component name.
	 * @return self New schema component.
	 */
	public static function make(string $kind, string $name=''): self {
		return self::configured(new self($kind, $name));
	}

	/**
	 * Creates a field component from supported field definitions.
	 *
	 * Infolist entries are unwrapped to their underlying read-only field before the
	 * component is created.
	 *
	 * @param Field|InfolistEntry|array<string, mixed>|string $field Field definition.
	 * @param string|null $type Field type used when field is a string.
	 * @return self Field component wrapping the normalized Field.
	 */
	public static function field(Field|InfolistEntry|array|string $field, ?string $type=null): self {
		if($field instanceof InfolistEntry){
			$field=$field->field();
		}
		$field=$field instanceof Field ? $field : (is_array($field) ? Field::fromArray($field) : Field::make((string)$field, $type ?? 'text'));
		$component=new self('field', $field->name());
		$component->field=$field;
		$component->label=(string)($field->toArray()['label'] ?? self::humanize($field->name()));
		return $component;
	}

	/**
	 * Creates a section component with optional children.
	 *
	 * Field children inherit the section label as their field section when they do
	 * not already carry explicit section metadata.
	 *
	 * @param FormSection|array<string, mixed>|string $section Section definition.
	 * @param array<int, mixed> $children Child component definitions.
	 * @return self Section component.
	 */
	public static function section(FormSection|array|string $section, array $children=[]): self {
		$section=$section instanceof FormSection
			? $section
			: (is_array($section) ? FormSection::fromArray($section) : FormSection::make((string)$section));
		$definition=$section->toArray();
		$component=new self('section', (string)($definition['name'] ?? 'section'));
		$component->section=$section;
		$component->label=(string)($definition['label'] ?? self::humanize($component->name));
		return $component->children($children);
	}

	/**
	 * Creates a grouping component.
	 *
	 * @param string $name Group name.
	 * @param array<int, mixed> $children Child component definitions.
	 * @return self Group component.
	 */
	public static function group(string $name, array $children=[]): self {
		return self::make('group', $name)->children($children);
	}

	/**
	 * Creates a tab component.
	 *
	 * Child fields and sections receive tab metadata during normalization.
	 *
	 * @param string $name Tab name.
	 * @param array<int, mixed> $children Child component definitions.
	 * @return self Tab component.
	 */
	public static function tab(string $name, array $children=[]): self {
		return self::make('tab', $name)->children($children);
	}

	/**
	 * Creates a step component for wizard-style schemas.
	 *
	 * Child fields and sections receive step metadata during normalization.
	 *
	 * @param string $name Step name.
	 * @param array<int, mixed> $children Child component definitions.
	 * @return self Step component.
	 */
	public static function step(string $name, array $children=[]): self {
		return self::make('step', $name)->children($children);
	}

	/**
	 * Normalizes an arbitrary schema definition into a component.
	 *
	 * Supported inputs include existing components, fields, infolist entries,
	 * sections, strings, and array manifests. Unknown values return null so callers
	 * can safely filter invalid schema entries.
	 *
	 * @param mixed $component Component definition.
	 * @return self|null Normalized component, or null when the input is unsupported.
	 */
	public static function from(mixed $component): ?self {
		if($component instanceof self){
			return $component;
		}
		if($component instanceof InfolistEntry){
			return self::field($component);
		}
		if($component instanceof Field || is_string($component)){
			return self::field($component);
		}
		if($component instanceof FormSection){
			return self::section($component);
		}
		if(!is_array($component)){
			return null;
		}
		$kind=self::normalizeKind((string)($component['kind'] ?? $component['type'] ?? (isset($component['fields']) || isset($component['children']) ? 'section' : 'field')));
		if($kind==='field'){
			return self::field(is_array($component['field'] ?? null) ? $component['field'] : $component);
		}
		$children=is_array($component['children'] ?? null) ? $component['children'] : (is_array($component['fields'] ?? null) ? $component['fields'] : []);
		$instance=$kind==='section'
			? self::section(is_array($component['section'] ?? null) ? $component['section'] : $component, $children)
			: self::make($kind, (string)($component['name'] ?? $component['label'] ?? ''))->children($children);
		if(isset($component['label'])){
			$instance=$instance->label((string)$component['label']);
		}
		if(isset($component['meta']) && is_array($component['meta'])){
			$instance=$instance->meta($component['meta']);
		}
		return $instance;
	}

	/**
	 * Returns the normalized component kind.
	 *
	 * @return string Component kind used by renderers.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the normalized component name.
	 *
	 * @return string Component identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the display label for this component.
	 *
	 * @param string $label Operator-facing label.
	 * @return self Cloned component with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label) ?: self::humanize($clone->name);
		return $clone;
	}

	/**
	 * Merges renderer metadata into the component.
	 *
	 * @param array<string, mixed> $meta Metadata consumed by panel renderers.
	 * @return self Cloned component with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Replaces the component's child list with normalized children.
	 *
	 * Section parents apply their label to child fields. Tab and step parents
	 * propagate tab/step metadata recursively through children.
	 *
	 * @param array<int, mixed> $children Child component definitions.
	 * @return self Cloned component with normalized children.
	 */
	public function children(array $children): self {
		$clone=clone $this;
		$clone->children=[];
		foreach($children as $child){
			$child=self::from($child);
			if($child instanceof self){
				if($clone->kind==='section' && $child->field instanceof Field){
					$child=self::field($child->field->section($clone->label));
				}
				if($clone->kind==='tab'){
					$child=$child->withTab($clone->label);
				}
				if($clone->kind==='step'){
					$child=$child->withStep($clone->label);
				}
				$clone->children[]=$child;
			}
		}
		return $clone;
	}

	/**
	 * Appends one normalized child component.
	 *
	 * @param self|Field|FormSection|InfolistEntry|array<string, mixed>|string $child Child definition.
	 * @return self Cloned component with the child appended, or unchanged for unsupported input.
	 */
	public function child(self|Field|FormSection|InfolistEntry|array|string $child): self {
		$child=self::from($child);
		if(!$child instanceof self){
			return $this;
		}
		$clone=clone $this;
		if($clone->kind==='section' && $child->field instanceof Field){
			$child=self::field($child->field->section($clone->label));
		}
		if($clone->kind==='tab'){
			$child=$child->withTab($clone->label);
		}
		if($clone->kind==='step'){
			$child=$child->withStep($clone->label);
		}
		$clone->children[]=$child;
		return $clone;
	}

	/**
	 * Returns the field wrapped by this component.
	 *
	 * @return Field|null Field definition for field components.
	 */
	public function fieldDefinition(): ?Field {
		return $this->field;
	}

	/**
	 * Returns the section wrapped by this component.
	 *
	 * @return FormSection|null Section definition for section components.
	 */
	public function sectionDefinition(): ?FormSection {
		return $this->section;
	}

	/**
	 * Returns direct child components.
	 *
	 * @return array<int, self> Normalized direct children.
	 */
	public function childrenList(): array {
		return $this->children;
	}

	/**
	 * Flattens all field definitions in this component tree.
	 *
	 * Later fields with the same name replace earlier entries, matching manifest
	 * key semantics.
	 *
	 * @return array<string, Field> Fields keyed by field name.
	 */
	public function fieldsList(): array {
		$fields=[];
		if($this->field instanceof Field){
			$fields[$this->field->name()]=$this->field;
		}
		foreach($this->children as $child){
			foreach($child->fieldsList() as $name=>$field){
				$fields[$name]=$field;
			}
		}
		return $fields;
	}

	/**
	 * Flattens all section definitions in this component tree.
	 *
	 * Tab and step components synthesize lightweight sections when they directly
	 * contain fields, preserving layout context for renderers that expect sections.
	 *
	 * @return array<string, FormSection> Sections keyed by section name.
	 */
	public function sectionsList(): array {
		$sections=[];
		if($this->kind==='tab'){
			foreach($this->children as $child){
				if($child->field instanceof Field){
					$sectionName=Resource::normalizeName($this->label) ?: $this->name;
					$sections[$sectionName]=FormSection::make($sectionName)->label($this->label)->meta(['tab'=>$this->label]);
					break;
				}
			}
		}
		if($this->kind==='step'){
			foreach($this->children as $child){
				if($child->field instanceof Field){
					$sectionName=Resource::normalizeName($this->label) ?: $this->name;
					$sections[$sectionName]=FormSection::make($sectionName)->label($this->label)->meta(['step'=>$this->label]);
					break;
				}
			}
		}
		if($this->section instanceof FormSection){
			$sections[$this->section->name()]=$this->section;
		}
		foreach($this->children as $child){
			foreach($child->sectionsList() as $name=>$section){
				$sections[$name]=$section;
			}
		}
		return $sections;
	}

	/**
	 * Serializes the component tree for panel manifests.
	 *
	 * @return array<string, mixed> Component kind, name, label, metadata, optional field/section, and children.
	 */
	public function toArray(): array {
		$data=[
			'kind'=>$this->kind,
			'name'=>$this->name,
			'label'=>$this->label,
			'meta'=>$this->meta,
		];
		if($this->field instanceof Field){
			$data['field']=$this->field->toArray();
		}
		if($this->section instanceof FormSection){
			$data['section']=$this->section->toArray();
		}
		if($this->children!==[]){
			$data['children']=array_map(static fn(self $child): array => $child->toArray(), $this->children);
		}
		return $data;
	}

	/**
	 * Normalizes arbitrary kind input to supported component kinds.
	 *
	 * @param string $kind Candidate kind.
	 * @return string Supported kind, defaulting to field.
	 */
	private static function normalizeKind(string $kind): string {
		$kind=Resource::normalizeName($kind);
		return in_array($kind, ['field', 'section', 'group', 'tab', 'step', 'infolist', 'layout'], true) ? $kind : 'field';
	}

	/**
	 * Applies tab metadata recursively to this component tree.
	 *
	 * @param string $tab Tab label/name to propagate.
	 * @return self Cloned component with tab metadata applied.
	 */
	private function withTab(string $tab): self {
		$clone=clone $this;
		$tab=trim($tab);
		if($tab===''){
			return $clone;
		}
		$clone->meta=array_replace($clone->meta, ['tab'=>$tab]);
		if($clone->field instanceof Field){
			$fieldMeta=$clone->field->toArray()['meta'] ?? [];
			$field=$clone->field->meta(['tab'=>$tab]);
			if(trim((string)($fieldMeta['section'] ?? ''))===''){
				$field=$field->section($tab);
			}
			$clone->field=$field;
		}
		if($clone->section instanceof FormSection){
			$clone->section=$clone->section->meta(['tab'=>$tab]);
		}
		$clone->children=array_map(static fn(self $child): self => $child->withTab($tab), $clone->children);
		return $clone;
	}

	/**
	 * Applies step metadata recursively to this component tree.
	 *
	 * @param string $step Step label/name to propagate.
	 * @return self Cloned component with step metadata applied.
	 */
	private function withStep(string $step): self {
		$clone=clone $this;
		$step=trim($step);
		if($step===''){
			return $clone;
		}
		$clone->meta=array_replace($clone->meta, ['step'=>$step]);
		if($clone->field instanceof Field){
			$fieldMeta=$clone->field->toArray()['meta'] ?? [];
			$field=$clone->field->meta(['step'=>$step]);
			if(trim((string)($fieldMeta['section'] ?? ''))===''){
				$field=$field->section($step);
			}
			$clone->field=$field;
		}
		if($clone->section instanceof FormSection){
			$clone->section=$clone->section->meta(['step'=>$step]);
		}
		$clone->children=array_map(static fn(self $child): self => $child->withStep($step), $clone->children);
		return $clone;
	}

	/**
	 * Converts a normalized component name into a readable label.
	 *
	 * @param string $value Normalized component name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
