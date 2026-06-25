<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable layout and accessibility descriptor for a panel form section.
 *
 * Sections group form fields under a label/description, optional collapsible
 * state, responsive grid metadata, and accessibility constraints that renderers
 * can use to keep controls readable, touchable, and contrast-compliant.
 */
final class FormSection {
	use PanelExtensible;

	private string $name;
	private string $label;
	private ?string $description=null;
	private int $columns=0;
	private bool $collapsible=false;
	private bool $collapsed=false;
	private array $meta=[];

	/**
	 * Creates a section with normalized identity and a humanized default label.
	 *
	 * @param string $name Stable section key used in form metadata.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Starts a configured form section definition.
	*
	 * @param string $name Stable section name; normalized with `Resource::normalizeName()`.
	 * @return self New section after panel extension hooks are applied.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Builds a section from a declarative form schema array.
	*
	 * Supported keys include `name`, `label`, `description`, `columns`,
	 * `collapsible`, `collapsed`, `accessibility` or `a11y`, grid/accessibility
	 * shortcuts, `contrast_policy`, and `meta`.
	 *
	 * @param array<string, mixed> $definition Section schema definition.
	 * @return self Configured form section.
	 */
	public static function fromArray(array $definition): self {
		$section=self::make((string)($definition['name'] ?? $definition['label'] ?? ''));
		if(isset($definition['label'])){
			$section=$section->label((string)$definition['label']);
		}
		if(isset($definition['description']) && is_string($definition['description'])){
			$section=$section->description($definition['description']);
		}
		if(isset($definition['columns'])){
			$section=$section->columns(is_array($definition['columns']) ? $definition['columns'] : (int)$definition['columns']);
		}
		if(!empty($definition['collapsible'])){
			$section=$section->collapsible();
		}
		if(!empty($definition['collapsed'])){
			$section=$section->collapsed();
		}
		if(isset($definition['accessibility']) && is_array($definition['accessibility'])){
			$section=$section->accessibilityPolicy($definition['accessibility']);
		}
		if(isset($definition['a11y']) && is_array($definition['a11y'])){
			$section=$section->accessibilityPolicy($definition['a11y']);
		}
		if(isset($definition['min_usable_width'])){
			$section=$section->minUsableWidth((int)$definition['min_usable_width'], (string)($definition['min_usable_width_unit'] ?? 'px'));
		}
		if(isset($definition['min_usable_chars'])){
			$section=$section->minUsableCharacters((int)$definition['min_usable_chars']);
		}
		if(isset($definition['min_touch_target'])){
			$section=$section->minTouchTarget((int)$definition['min_touch_target']);
		}
		if(isset($definition['max_adornment_ratio'])){
			$section=$section->maxAdornmentRatio((float)$definition['max_adornment_ratio']);
		}
		if(isset($definition['max_label_ratio'])){
			$section=$section->maxLabelRatio((float)$definition['max_label_ratio']);
		}
		if(isset($definition['contrast_policy']) && is_array($definition['contrast_policy'])){
			$section=$section->contrastPolicy($definition['contrast_policy']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$section=$section->meta($definition['meta']);
		}
		return $section;
	}

	/**
	 * Returns the normalized section name.
	 *
	 * @return string Stable section key.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a clone with the section label replaced.
	*
	 * @param string $label Display label; empty values fall back to the humanized name.
	 * @return self Cloned section with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label) ?: self::humanize($clone->name);
		return $clone;
	}

	/**
	 * Returns a clone with optional section help text replaced.
	*
	 * @param string $description Help text; empty values clear the description.
	 * @return self Cloned section with updated description.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with section grid column settings.
	*
	 * Integer values clamp to `0..12`. Responsive maps are normalized by breakpoint
	 * and stored in `meta.grid_columns`, while the section-level `columns` value is
	 * set to the largest responsive column count for simple renderers.
	 *
	 * @param int|array<string, int> $columns Fixed column count or responsive breakpoint map.
	 * @return self Cloned section with grid metadata.
	 */
	public function columns(int|array $columns): self {
		$clone=clone $this;
		if(is_array($columns)){
			$normalized=self::normalizeGridColumns($columns);
			$clone->columns=max(0, max(array_map(static fn(mixed $value): int => (int)$value, $normalized ?: [0])));
			$clone->meta=array_replace($clone->meta, ['grid_columns'=>$normalized]);
			return $clone;
		}
		$clone->columns=max(0, min(12, $columns));
		return $clone;
	}

	/**
	 * Returns a clone with responsive column-span metadata.
	*
	 * Span values clamp to `1..12`, except the string `full`, which is preserved
	 * to let renderers stretch a section across the full grid.
	 *
	 * @param int|string|array<string, int|string> $span Fixed or responsive span.
	 * @return self Cloned section with `meta.column_span`.
	 */
	public function columnSpan(int|string|array $span): self {
		return $this->meta(['column_span'=>self::normalizeGridSpan($span)]);
	}

	/**
	 * Returns a clone with responsive grid-start metadata.
	*
	 * @param int|string|array<string, int|string> $start Fixed or responsive grid start value.
	 * @return self Cloned section with `meta.column_start`.
	 */
	public function columnStart(int|string|array $start): self {
		return $this->meta(['column_start'=>self::normalizeGridStart($start)]);
	}

	/**
	 * Returns a clone with collapsible rendering enabled or disabled.
	*
	 * @param bool $collapsible Whether the renderer may collapse this section.
	 * @return self Cloned section with collapsible state.
	 */
	public function collapsible(bool $collapsible=true): self {
		$clone=clone $this;
		$clone->collapsible=$collapsible;
		return $clone;
	}

	/**
	 * Returns a clone with initial collapsed state.
	*
	 * Setting collapsed to true also marks the section collapsible so renderers
	 * have a control to reopen it.
	 *
	 * @param bool $collapsed Whether the section should start collapsed.
	 * @return self Cloned section with initial collapsed state.
	 */
	public function collapsed(bool $collapsed=true): self {
		$clone=clone $this;
		$clone->collapsed=$collapsed;
		if($collapsed){
			$clone->collapsible=true;
		}
		return $clone;
	}

	/**
	 * Returns a clone with arbitrary metadata merged into the section.
	*
	 * @param array<string, mixed> $meta Renderer or extension metadata.
	 * @return self Cloned section with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Returns a clone with normalized accessibility constraints merged into metadata.
	*
	 * The policy accepts aliases for minimum usable width/characters, touch target,
	 * adornment and label ratios, and contrast ratios. Values are clamped to the
	 * ranges renderers can meaningfully enforce.
	 *
	 * @param array<string, mixed> $policy Accessibility constraints.
	 * @return self Cloned section with merged `meta.accessibility`.
	 */
	public function accessibilityPolicy(array $policy): self {
		return $this->meta(['accessibility'=>self::mergeAccessibilityPolicy(is_array($this->meta['accessibility'] ?? null) ? $this->meta['accessibility'] : [], $policy)]);
	}

	/**
	 * Adds a minimum usable width constraint for controls in this section.
	*
	 * @param int $width Minimum width value.
	 * @param string $unit Supported units are normalized later to `px` or `ch`.
	 * @return self Cloned section with updated accessibility policy.
	 */
	public function minUsableWidth(int $width, string $unit='px'): self {
		return $this->accessibilityPolicy([
			'min_usable_width'=>$width,
			'min_usable_width_unit'=>$unit,
		]);
	}

	/**
	 * Adds a minimum character capacity constraint for text controls.
	*
	 * @param int $characters Minimum visible character count.
	 * @return self Cloned section with updated accessibility policy.
	 */
	public function minUsableCharacters(int $characters): self {
		return $this->accessibilityPolicy(['min_usable_chars'=>$characters]);
	}

	/**
	 * Adds a minimum touch target size constraint for interactive controls.
	*
	 * @param int $pixels Minimum target size in pixels.
	 * @return self Cloned section with updated accessibility policy.
	 */
	public function minTouchTarget(int $pixels=44): self {
		return $this->accessibilityPolicy(['min_touch_target'=>$pixels]);
	}

	/**
	 * Adds a maximum adornment-to-control ratio constraint.
	*
	 * @param float $ratio Ratio clamped to `0.0..1.0` during policy normalization.
	 * @return self Cloned section with updated accessibility policy.
	 */
	public function maxAdornmentRatio(float $ratio=0.45): self {
		return $this->accessibilityPolicy(['max_adornment_ratio'=>$ratio]);
	}

	/**
	 * Adds a maximum label-to-control ratio constraint.
	*
	 * @param float $ratio Ratio clamped to `0.0..1.0` during policy normalization.
	 * @return self Cloned section with updated accessibility policy.
	 */
	public function maxLabelRatio(float $ratio=0.55): self {
		return $this->accessibilityPolicy(['max_label_ratio'=>$ratio]);
	}

	/**
	 * Adds or replaces contrast requirements for fields, labels, controls, or inputs.
	*
	 * Float values become `min_ratio` policies. Array values may include
	 * `min_ratio`, `large_text_min_ratio`, and `scope`; scope values outside the
	 * supported renderer set normalize to `control`.
	 *
	 * @param array<string, mixed>|float $policy Contrast policy or minimum ratio.
	 * @param ?string $scope Optional policy scope override.
	 * @return self Cloned section with updated contrast policy.
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
	 * Exports the section schema consumed by form renderers and schema manifests.
	 *
	 * @return array{name:string,label:string,description:?string,columns:int,collapsible:bool,collapsed:bool,meta:array}
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'columns'=>$this->columns,
			'collapsible'=>$this->collapsible,
			'collapsed'=>$this->collapsed,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts a normalized section key into a display label.
	 *
	 * @param string $value Normalized section key.
	 * @return string Title-cased label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}

	/**
	 * Normalizes responsive column counts by breakpoint.
	 *
	 * @param array<string, mixed> $columns Raw breakpoint-to-column map.
	 * @return array<string, int> Supported breakpoints mapped to `1..12` columns.
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
	 * Normalizes fixed or responsive grid span metadata.
	 *
	 * @param int|string|array<string, mixed> $span Raw span value.
	 * @return int|string|array<string, int|string> Clamped span, `full`, or responsive map.
	 */
	private static function normalizeGridSpan(int|string|array $span): int|string|array {
		if(is_array($span)){
			$normalized=[];
			foreach($span as $breakpoint=>$value){
				$breakpoint=self::normalizeGridBreakpoint((string)$breakpoint);
				if($breakpoint!==''){
					$normalized[$breakpoint]=self::normalizeGridSpan($value);
				}
			}
			return $normalized;
		}
		if(is_string($span) && strtolower(trim($span))==='full'){
			return 'full';
		}
		return max(1, min(12, (int)$span));
	}

	/**
	 * Normalizes fixed or responsive grid start metadata.
	 *
	 * @param int|string|array<string, mixed> $start Raw grid start value.
	 * @return int|array<string, int> Clamped grid start or responsive map.
	 */
	private static function normalizeGridStart(int|string|array $start): int|array {
		if(is_array($start)){
			$normalized=[];
			foreach($start as $breakpoint=>$value){
				$breakpoint=self::normalizeGridBreakpoint((string)$breakpoint);
				if($breakpoint!==''){
					$normalized[$breakpoint]=self::normalizeGridStart($value);
				}
			}
			return $normalized;
		}
		return max(1, min(12, (int)$start));
	}

	/**
	 * Normalizes breakpoint aliases used in section grid metadata.
	 *
	 * @param string $breakpoint Raw breakpoint name.
	 * @return string Canonical breakpoint key, or empty string when unsupported.
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

	/**
	 * Merges and clamps accessibility-policy aliases into the canonical metadata shape.
	 *
	 * @param array<string, mixed> $existing Existing normalized accessibility metadata.
	 * @param array<string, mixed> $policy New policy values and aliases.
	 * @return array<string, mixed> Merged normalized accessibility metadata.
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
}
