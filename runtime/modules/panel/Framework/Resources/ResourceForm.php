<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable builder for Panel resource forms, schema metadata, accessibility policy, and lifecycle state.
 *
 * ResourceForm collects fields, sections, responsive column layout, metadata, and UI accessibility constraints, then bridges
 * that structure into Schema, SchemaLifecycle, SchemaManifest, and PanelFormState. Every mutator returns a clone so resource
 * definitions can be shared safely across request hydration, validation, and rendering.
 */
final class ResourceForm {

	/** @var array<string, Field> */
	private array $fields=[];
	/** @var array<string, FormSection> */
	private array $sections=[];
	private int $columns=1;
	private array $meta=[];

	/**
	 * Creates an empty form builder.
	 *
	 * @return self New resource form builder.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Replaces the form's field registry with a normalized field list.
	 *
	 * @param array<int|string, Field|array<string, mixed>|string> $fields Field objects, array definitions, or field names.
	 * @return self Cloned form with the replacement field registry.
	 */
	public function fields(array $fields): self {
		$clone=clone $this;
		$clone->fields=[];
		foreach($fields as $field){
			$clone=$clone->field($field);
		}
		return $clone;
	}

	/**
	 * Adds or replaces one field by its normalized field name.
	 *
	 * String input creates a Field with the supplied type, array input is delegated to Field::fromArray(), and Field objects
	 * are stored as-is.
	 *
	 * @param Field|array<string, mixed>|string $field Field object, definition array, or field name.
	 * @param ?string $type Field type used when the field is supplied as a string.
	 * @return self Cloned form with the field registered.
	 */
	public function field(Field|array|string $field, ?string $type=null): self {
		$field=$field instanceof Field ? $field : (is_array($field) ? Field::fromArray($field) : Field::make((string)$field, $type ?? 'text'));
		$clone=clone $this;
		$clone->fields[$field->name()]=$field;
		return $clone;
	}

	/**
	 * Replaces the form's section registry with normalized sections.
	 *
	 * @param array<int|string, FormSection|array<string, mixed>|string> $sections Section objects, definitions, or names.
	 * @return self Cloned form with the replacement section registry.
	 */
	public function sections(array $sections): self {
		$clone=clone $this;
		$clone->sections=[];
		foreach($sections as $section){
			$clone=$clone->section($section);
		}
		return $clone;
	}

	/**
	 * Adds or replaces one form section and optionally assigns fields to it.
	 *
	 * Field assignments use the section label when available so renderers can display human-friendly section grouping while
	 * the section registry remains keyed by the normalized section name.
	 *
	 * @param FormSection|array<string, mixed>|string $section Section object, definition array, or section name.
	 * @param ?array<int|string, Field|array<string, mixed>|string> $fields Optional fields to add under the section.
	 * @return self Cloned form with the section and optional fields registered.
	 */
	public function section(FormSection|array|string $section, ?array $fields=null): self {
		$section=$section instanceof FormSection
			? $section
			: (is_array($section) ? FormSection::fromArray($section) : FormSection::make((string)$section));
		$clone=clone $this;
		$clone->sections[$section->name()]=$section;
		if($fields!==null){
			foreach($fields as $field){
				$field=$field instanceof Field ? $field : (is_array($field) ? Field::fromArray($field) : Field::make((string)$field));
				$clone=$clone->field($field->section((string)($section->toArray()['label'] ?? $section->name())));
			}
		}
		return $clone;
	}

	/**
	 * Sets fixed or responsive grid column counts.
	 *
	 * Integer input is clamped from one to twelve. Array input is normalized by breakpoint and stored in metadata while the
	 * form's column count becomes the largest configured breakpoint value.
	 *
	 * @param int|array<string, int> $columns Fixed column count or responsive breakpoint map.
	 * @return self Cloned form with updated grid layout metadata.
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
	 * Merges metadata into the form definition.
	 *
	 * @param array<string, mixed> $meta Metadata used by schema, lifecycle, renderers, or accessibility checks.
	 * @return self Cloned form with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Merges form accessibility constraints into metadata.
	 *
	 * Policy keys are normalized by mergeAccessibilityPolicy() so renderers and diagnostics can consume one canonical
	 * accessibility contract regardless of which shorthand a resource definition used.
	 *
	 * @param array<string, mixed> $policy Accessibility policy fragment.
	 * @return self Cloned form with merged accessibility metadata.
	 */
	public function accessibilityPolicy(array $policy): self {
		return $this->meta(['accessibility'=>self::mergeAccessibilityPolicy(is_array($this->meta['accessibility'] ?? null) ? $this->meta['accessibility'] : [], $policy)]);
	}

	/**
	 * Sets the minimum usable field width expected by form layout diagnostics.
	 *
	 * @param int $width Minimum width value.
	 * @param string $unit Width unit, normalized to px or ch.
	 * @return self Cloned form with updated accessibility metadata.
	 */
	public function minUsableWidth(int $width, string $unit='px'): self {
		return $this->accessibilityPolicy([
			'min_usable_width'=>$width,
			'min_usable_width_unit'=>$unit,
		]);
	}

	/**
	 * Sets the minimum useful character capacity for compact controls.
	 *
	 * @param int $characters Minimum character count.
	 * @return self Cloned form with updated accessibility metadata.
	 */
	public function minUsableCharacters(int $characters): self {
		return $this->accessibilityPolicy(['min_usable_chars'=>$characters]);
	}

	/**
	 * Sets the minimum touch target size expected by form diagnostics.
	 *
	 * @param int $pixels Minimum target size in pixels.
	 * @return self Cloned form with updated accessibility metadata.
	 */
	public function minTouchTarget(int $pixels=44): self {
		return $this->accessibilityPolicy(['min_touch_target'=>$pixels]);
	}

	/**
	 * Sets the maximum portion of a control that may be consumed by adornments.
	 *
	 * @param float $ratio Ratio clamped between zero and one.
	 * @return self Cloned form with updated accessibility metadata.
	 */
	public function maxAdornmentRatio(float $ratio=0.45): self {
		return $this->accessibilityPolicy(['max_adornment_ratio'=>$ratio]);
	}

	/**
	 * Sets the maximum portion of a control row that may be consumed by labels.
	 *
	 * @param float $ratio Ratio clamped between zero and one.
	 * @return self Cloned form with updated accessibility metadata.
	 */
	public function maxLabelRatio(float $ratio=0.55): self {
		return $this->accessibilityPolicy(['max_label_ratio'=>$ratio]);
	}

	/**
	 * Sets minimum contrast requirements for form controls or labels.
	 *
	 * Float input becomes the min_ratio shorthand. Array input may include min_ratio, ratio, large_text_min_ratio, and scope.
	 *
	 * @param array<string, mixed>|float $policy Contrast policy or minimum contrast ratio.
	 * @param ?string $scope Optional contrast scope override.
	 * @return self Cloned form with updated contrast metadata.
	 */
	public function contrastPolicy(array|float $policy=4.5, ?string $scope=null): self {
		if(is_float($policy) || is_int($policy)){
			$policy=['min_ratio'=>(float)$policy];
		}
		if($scope!==null){
			$policy['scope']=$scope;
		}
		return $this->accessibilityPolicy(['contrast'=>$policy]);
	}

	/**
	 * Converts between ResourceForm and Schema representations.
	 *
	 * With no argument, the current form is projected into a Schema. When a Schema is supplied, its fields, sections,
	 * columns, and metadata replace the corresponding form pieces on a clone.
	 *
	 * @param ?Schema $schema Optional schema to import into the form.
	 * @return Schema|self Schema view of the current form, or cloned form imported from the supplied schema.
	 */
	public function schema(?Schema $schema=null): Schema|self {
		if($schema===null){
			return Schema::fromForm($this);
		}
		$clone=clone $this;
		$clone->fields=$schema->fieldsList();
		$clone->sections=$schema->sectionsList();
		$clone->columns=$schema->columnsCount();
		$clone->meta=array_replace($clone->meta, $schema->toArray()['meta'] ?? []);
		return $clone;
	}

	/**
	 * Builds the lifecycle engine used to hydrate, dehydrate, validate, and submit this form.
	 *
	 * @return SchemaLifecycle Lifecycle configured with form fields and metadata.
	 */
	public function lifecycle(): SchemaLifecycle {
		return SchemaLifecycle::make($this->fields, array_replace($this->meta, [
			'lifecycle_trace_prefix'=>'form',
		]));
	}

	/**
	 * Builds the serialized form manifest for a Panel operation.
	 *
	 * @param ?string $operation Optional operation name such as create, edit, or view.
	 * @param array<string, mixed> $meta Manifest metadata merged over form metadata.
	 * @return array<string, mixed> Schema manifest payload for Panel clients.
	 */
	public function manifest(?string $operation=null, array $meta=[]): array {
		return SchemaManifest::fromForm($this, $operation, array_replace($this->meta, $meta))->toArray();
	}

	/**
	 * Returns the form metadata map.
	 *
	 * @return array<string, mixed> Form metadata used by schema, lifecycle, manifest, and rendering.
	 */
	public function metadata(): array {
		return $this->meta;
	}

	/**
	 * Returns the maximum configured form column count.
	 *
	 * @return int Column count clamped to at least one.
	 */
	public function columnsCount(): int {
		return $this->columns;
	}

	/**
	 * Returns responsive grid column settings.
	 *
	 * @return array<string, int> Breakpoint column map, defaulting to the fixed column count.
	 */
	public function responsiveColumns(): array {
		return is_array($this->meta['grid_columns'] ?? null) ? $this->meta['grid_columns'] : ['default'=>$this->columns];
	}

	/**
	 * Returns field objects keyed by field name.
	 *
	 * @return array<string, Field> Field registry.
	 */
	public function fieldsList(): array {
		return $this->fields;
	}

	/**
	 * Resolves server-driven live field state from current values and request context.
	 *
	 * @param array<string, mixed> $values Current form values.
	 * @param mixed $record Optional record being edited.
	 * @param ?PanelRequest $request Optional Panel request.
	 * @param ?string $operation Optional operation name.
	 * @return array<string, mixed> Live state payload produced by SchemaLifecycle.
	 */
	public function resolveLiveState(array $values, mixed $record=null, ?PanelRequest $request=null, ?string $operation=null): array {
		return $this->lifecycle()->resolveLiveState($values, $record, $request, $operation);
	}

	/**
	 * Returns section objects keyed by section name.
	 *
	 * @return array<string, FormSection> Section registry.
	 */
	public function sectionsList(): array {
		return $this->sections;
	}

	/**
	 * Hydrates form state from a record and optional request context.
	 *
	 * @param mixed $record Record supplying default field values.
	 * @param ?PanelRequest $request Optional request carrying prefill data.
	 * @return PanelFormState Hydrated form state.
	 */
	public function hydrate(mixed $record=null, ?PanelRequest $request=null): PanelFormState {
		return $this->lifecycle()->hydrate($record, $request);
	}

	/**
	 * Dehydrates submitted request input into form state.
	 *
	 * @param PanelRequest $request Request containing submitted values and files.
	 * @param mixed $record Optional record being edited.
	 * @param ?string $operation Optional operation name.
	 * @return PanelFormState Dehydrated form state.
	 */
	public function dehydrate(PanelRequest $request, mixed $record=null, ?string $operation=null): PanelFormState {
		return $this->lifecycle()->dehydrate($request, $record, $operation);
	}

	/**
	 * Validates an explicit value map against the form lifecycle.
	 *
	 * @param array<string, mixed> $values Values to validate.
	 * @param mixed $record Optional record being edited.
	 * @param ?PanelRequest $request Optional request context.
	 * @param ?string $operation Optional operation name.
	 * @return PanelFormState Validated form state with errors and normalized values.
	 */
	public function validate(array $values, mixed $record=null, ?PanelRequest $request=null, ?string $operation=null): PanelFormState {
		return $this->lifecycle()->validate($values, $record, $request, $operation);
	}

	/**
	 * Dehydrates and validates a Panel request as a form submission.
	 *
	 * @param PanelRequest $request Submitted Panel request.
	 * @param mixed $record Optional record being edited.
	 * @param ?string $operation Optional operation name.
	 * @return PanelFormState Submitted form state.
	 */
	public function submit(PanelRequest $request, mixed $record=null, ?string $operation=null): PanelFormState {
		return $this->lifecycle()->submit($request, $record, $operation);
	}

	/**
	 * Builds form state through the lifecycle engine with optional validation.
	 *
	 * @param mixed $record Optional record supplying defaults.
	 * @param ?PanelRequest $request Optional Panel request.
	 * @param ?string $operation Optional operation name.
	 * @param array<string, mixed> $input Explicit input values.
	 * @param bool $validate Whether to run validation.
	 * @return PanelFormState Form state for rendering or submission handling.
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
	 * Exports the form definition as a schema-backed array.
	 *
	 * @return array{columns: int, schema: array<string, mixed>, fields: list<array<string, mixed>>, sections: list<array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'columns'=>$this->columns,
			'schema'=>$this->schema()->toArray(),
			'fields'=>array_map(static fn(Field $field): array => $field->toArray(), array_values($this->fields)),
			'sections'=>array_map(static fn(FormSection $section): array => $section->toArray(), array_values($this->sections)),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Reads a field value from an array, public property, or conventional getter method.
	 *
	 * @param mixed $record Record array or object.
	 * @param string $key Field key.
	 * @param mixed $default Value returned when the key is unavailable.
	 * @return mixed array value, public property, getter result, or the caller default when unavailable.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=null): mixed {
		if(is_array($record)){
			return $record[$key] ?? $default;
		}
		if(is_object($record)){
			if(isset($record->{$key})){
				return $record->{$key};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
			if(method_exists($record, $method)){
				return $record->{$method}();
			}
		}
		return $default;
	}

	/**
	 * Applies a server-resolved live state value and records propagation flags.
	 *
	 * @param array<string, mixed> $stateValues Current state values, updated by reference.
	 * @param array<string, mixed> $serverValues Server state metadata, updated by reference.
	 * @param string $name Field name being resolved.
	 * @param mixed $value Value resolved by live-state evaluation.
	 * @param array<string, mixed> $flags force_value and propagate flags.
	 * @return bool True when the field value changed.
	 */
	private static function applyResolvedStateValue(array &$stateValues, array &$serverValues, string $name, mixed $value, array $flags=[]): bool {
		$changed=!array_key_exists($name, $stateValues) || $stateValues[$name]!==$value;
		$stateValues[$name]=$value;
		$serverValues[$name]=[
			'force_value'=>($flags['force_value'] ?? false)===true,
			'propagate'=>($flags['propagate'] ?? false)===true,
		];
		return $changed;
	}

	/**
	 * Extracts scalar prefill values from a Panel request query.
	 *
	 * @param PanelRequest $request Request carrying an optional prefill query map.
	 * @return array<string, scalar|null> Normalized scalar prefill values keyed by field name.
	 */
	private static function prefillValues(PanelRequest $request): array {
		$prefill=$request->query('prefill', []);
		if(!is_array($prefill)){
			return [];
		}
		$values=[];
		foreach($prefill as $field=>$value){
			$field=Resource::normalizeName((string)$field);
			if($field!=='' && (is_scalar($value) || $value===null)){
				$values[$field]=$value;
			}
		}
		return $values;
	}

	/**
	 * Reports whether a file input payload contains no selected file.
	 *
	 * Handles both single-file and multi-file upload array shapes from PHP.
	 *
	 * @param mixed $value Candidate file input value.
	 * @return bool True when the upload payload is empty or only contains UPLOAD_ERR_NO_FILE entries.
	 */
	private static function fileInputBlank(mixed $value): bool {
		if(!is_array($value)){
			return true;
		}
		if(isset($value['error']) && !is_array($value['error'])){
			return (int)$value['error']===UPLOAD_ERR_NO_FILE || trim((string)($value['name'] ?? ''))==='';
		}
		if(isset($value['name']) && is_array($value['name'])){
			foreach($value['name'] as $index=>$name){
				$error=is_array($value['error'] ?? null) ? (int)($value['error'][$index] ?? UPLOAD_ERR_OK) : UPLOAD_ERR_OK;
				if($error!==UPLOAD_ERR_NO_FILE && trim((string)$name)!==''){
					return false;
				}
			}
			return true;
		}
		return $value===[];
	}

	/**
	 * Compares form values using form-friendly scalar and structural semantics.
	 *
	 * @param mixed $left First value.
	 * @param mixed $right Second value.
	 * @return bool True when both values are equivalent for form dirty-state comparison.
	 */
	private static function valuesMatch(mixed $left, mixed $right): bool {
		if(is_bool($left) || is_bool($right)){
			return (bool)$left===(bool)$right;
		}
		if((is_scalar($left) || $left===null) && (is_scalar($right) || $right===null)){
			return (string)$left===(string)$right;
		}
		return self::stableValue($left)===self::stableValue($right);
	}

	/**
	 * Encodes a comparable value into a stable string.
	 *
	 * @param mixed $value Value to normalize and encode.
	 * @return string Stable JSON representation, or an empty string on encoding failure.
	 */
	private static function stableValue(mixed $value): string {
		return json_encode(self::normalizeComparableValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
	}

	/**
	 * Normalizes arrays and objects before stable comparison.
	 *
	 * Associative arrays are sorted by key so equivalent form data compares equal even when insertion order differs.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed scalar value, list with preserved order, or key-sorted associative/object array.
	 */
	private static function normalizeComparableValue(mixed $value): mixed {
		if(is_object($value)){
			$value=get_object_vars($value);
		}
		if(!is_array($value)){
			return $value;
		}
		if(!array_is_list($value)){
			ksort($value);
		}
		foreach($value as $key=>$child){
			$value[$key]=self::normalizeComparableValue($child);
		}
		return $value;
	}

	/**
	 * Normalizes responsive grid breakpoint column settings.
	 *
	 * @param array<string, mixed> $columns Raw breakpoint map.
	 * @return array<string, int> Valid breakpoint column counts clamped from one to twelve.
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
	 * Merges and canonicalizes form accessibility policy metadata.
	 *
	 * Supports shorthand aliases for width, character capacity, touch target, adornment/label ratios, and contrast policy,
	 * then clamps numeric values into ranges usable by renderers and diagnostics.
	 *
	 * @param array<string, mixed> $existing Existing accessibility metadata.
	 * @param array<string, mixed> $policy Policy fragment to merge.
	 * @return array<string, mixed> Canonical accessibility metadata.
	 */
	private static function mergeAccessibilityPolicy(array $existing, array $policy): array {
		$normalized=[];
		if(isset($policy['min_usable_width']) || isset($policy['min_width'])){
			$normalized['min_usable_width']=max(0, (int)($policy['min_usable_width'] ?? $policy['min_width']));
			$unit=strtolower(trim((string)($policy['min_usable_width_unit'] ?? $policy['unit'] ?? 'px')));
			$normalized['min_usable_width_unit']=in_array($unit, ['px', 'ch'], true) ? $unit : 'px';
		}
		if(isset($policy['min_usable_chars']) || isset($policy['min_chars'])){
			$normalized['min_usable_chars']=max(0, (int)($policy['min_usable_chars'] ?? $policy['min_chars']));
		}
		if(isset($policy['min_touch_target']) || isset($policy['touch_target'])){
			$normalized['min_touch_target']=max(0, (int)($policy['min_touch_target'] ?? $policy['touch_target']));
		}
		if(isset($policy['max_adornment_ratio']) || isset($policy['adornment_ratio'])){
			$normalized['max_adornment_ratio']=max(0.0, min(1.0, (float)($policy['max_adornment_ratio'] ?? $policy['adornment_ratio'])));
		}
		if(isset($policy['max_label_ratio']) || isset($policy['label_ratio'])){
			$normalized['max_label_ratio']=max(0.0, min(1.0, (float)($policy['max_label_ratio'] ?? $policy['label_ratio'])));
		}
		$contrast=is_array($policy['contrast'] ?? null) ? $policy['contrast'] : (is_array($policy['contrast_policy'] ?? null) ? $policy['contrast_policy'] : null);
		if($contrast!==null || isset($policy['contrast_min_ratio']) || isset($policy['min_ratio'])){
			$contrast=$contrast ?? ['min_ratio'=>$policy['contrast_min_ratio'] ?? $policy['min_ratio'] ?? 4.5];
			$scope=Resource::normalizeName((string)($contrast['scope'] ?? 'control'));
			$normalized['contrast_policy']=[
				'min_ratio'=>max(1.0, min(21.0, (float)($contrast['min_ratio'] ?? $contrast['ratio'] ?? 4.5))),
				'scope'=>in_array($scope, ['field', 'label', 'control', 'input'], true) ? $scope : 'control',
				'large_text_min_ratio'=>max(1.0, min(21.0, (float)($contrast['large_text_min_ratio'] ?? 3.0))),
			];
		}
		return array_replace_recursive($existing, $normalized);
	}

	/**
	 * Normalizes responsive grid breakpoint aliases.
	 *
	 * @param string $breakpoint Raw breakpoint name.
	 * @return string Canonical breakpoint key, or an empty string for unsupported aliases.
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
