<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable table filter definition for panel tables and in-memory record filtering.
 *
 * A filter owns its query key, display label, input type, source column, static or dynamic options,
 * default value, optional predicate, visibility rules, active-state indicators, and manifest metadata.
 * Mutators clone the definition so table builders can safely reuse configured filters.
 */
final class TableFilter {
	use PanelExtensible;

	private string $name;
	private string $type;
	private string $label;
	private ?string $column=null;
	private array $options=[];
	private ?\Closure $optionsCallback=null;
	private mixed $default=null;
	private array $meta=[];
	private ?\Closure $predicate=null;
	private string|\Closure|null $indicator=null;
	private string $indicatorTone='neutral';
	private bool $hidden=false;
	private array $visibleOn=[];
	private array $hiddenOn=[];
	private ?\Closure $visibilityCallback=null;
	private ?\Closure $hiddenCallback=null;

	/**
	 * Creates a normalized filter definition with a default source column.
	 *
	 * Construction is private so filters pass through make() and configuration
	 * hooks before being attached to a table.
	 *
	 * @param string $name Raw query key and default column.
	 * @param string $type Raw filter type.
	 */
	private function __construct(string $name, string $type='text') {
		$this->name=Resource::normalizeName($name);
		$this->type=Resource::normalizeName($type) ?: 'text';
		$this->label=self::humanize($this->name);
		$this->column=$this->name;
	}

	/**
	 * Creates a configured table filter with normalized name and type.
	 *
	 * @param string $name Query-string key and default source column.
	 * @param string $type Filter input type, defaulting to text.
	 * @return self New filter after PanelExtensible configuration hooks run.
	 */
	public static function make(string $name, string $type='text'): self {
		return self::configured(new self($name, $type));
	}

	/**
	 * Rebuilds a table filter from a manifest-style array.
	 *
	 * Recognized keys include name, type, label, column, options, default, hidden, visible_on,
	 * hidden_on, meta, indicator, and indicator_tone. Callback-only behavior such as predicates
	 * and dynamic options must be registered through the fluent API.
	 *
	 * @param array<string,mixed> $definition Serialized filter definition.
	 * @return self Filter rebuilt from the supplied definition.
	 */
	public static function fromArray(array $definition): self {
		$filter=self::make((string)($definition['name'] ?? ''), (string)($definition['type'] ?? 'text'));
		if(isset($definition['label'])){
			$filter=$filter->label((string)$definition['label']);
		}
		if(isset($definition['column']) && is_string($definition['column'])){
			$filter=$filter->column($definition['column']);
		}
		if(isset($definition['options']) && is_array($definition['options'])){
			$filter=$filter->options($definition['options']);
		}
		if(array_key_exists('default', $definition)){
			$filter=$filter->default($definition['default']);
		}
		if(array_key_exists('hidden', $definition)){
			$filter=$filter->hidden((bool)$definition['hidden']);
		}
		if(isset($definition['visible_on'])){
			$filter=$filter->visibleOn(is_array($definition['visible_on']) ? $definition['visible_on'] : (string)$definition['visible_on']);
		}
		if(isset($definition['hidden_on'])){
			$filter=$filter->hiddenOn(is_array($definition['hidden_on']) ? $definition['hidden_on'] : (string)$definition['hidden_on']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$filter=$filter->meta($definition['meta']);
		}
		if(isset($definition['indicator']) && (is_string($definition['indicator']) || is_callable($definition['indicator']))){
			$filter=$filter->indicator($definition['indicator']);
		}
		if(isset($definition['indicator_tone']) && is_string($definition['indicator_tone'])){
			$filter=$filter->indicatorTone($definition['indicator_tone']);
		}
		return $filter;
	}

	/**
	 * Returns the normalized filter name.
	 *
	 * @return string Query-string key and manifest identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the human-readable label shown in filter controls and indicators.
	 *
	 * @param string $label Label text.
	 * @return self Cloned filter with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Sets the filter input and matching type.
	 *
	 * Type names are normalized. Empty values fall back to `text`.
	 *
	 * @param string $type Type token such as text, select, boolean, date, range, date_range, or number_range.
	 * @return self Cloned filter with normalized type.
	 */
	public function type(string $type): self {
		$clone=clone $this;
		$clone->type=Resource::normalizeName($type) ?: 'text';
		return $clone;
	}

	/**
	 * Marks the filter as a range-style filter.
	 *
	 * Range filters read `<name>_from` and `<name>_to` from the request instead of a single query key.
	 *
	 * @param string $type Range type token.
	 * @return self Cloned filter with range type.
	 */
	public function range(string $type='range'): self {
		return $this->type($type);
	}

	/**
	 * Sets the filter type to date_range.
	 *
	 * @return self Cloned date-range filter.
	 */
	public function dateRange(): self {
		return $this->type('date_range');
	}

	/**
	 * Sets the filter type to number_range.
	 *
	 * @return self Cloned numeric-range filter.
	 */
	public function numberRange(): self {
		return $this->type('number_range');
	}

	/**
	 * Sets the record column/property read when no custom predicate is supplied.
	 *
	 * Empty normalized columns fall back to the filter name.
	 *
	 * @param string $column Record key, property, or getter-derived field name.
	 * @return self Cloned filter with updated source column.
	 */
	public function column(string $column): self {
		$clone=clone $this;
		$clone->column=Resource::normalizeName($column) ?: $this->name;
		return $clone;
	}

	/**
	 * Sets static selectable options.
	 *
	 * Options may be flat value-label pairs, option arrays with value/label fields, or grouped
	 * option structures containing nested options.
	 *
	 * @param array<int|string,mixed> $options Option definitions used by the panel UI and select validation.
	 * @return self Cloned filter with static options.
	 */
	public function options(array $options): self {
		$clone=clone $this;
		$clone->options=$options;
		return $clone;
	}

	/**
	 * Registers a callback that resolves options for the current request.
	 *
	 * The callback is evaluated with request and filter context and must return an option array.
	 *
	 * @param callable $callback Dynamic options resolver.
	 * @return self Cloned filter with dynamic options resolver.
	 */
	public function optionsUsing(callable $callback): self {
		$clone=clone $this;
		$clone->optionsCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Sets the default active value when the request does not provide one.
	 *
	 * @param mixed $value Default filter value.
	 * @return self Cloned filter with default value.
	 */
	public function default(mixed $value): self {
		$clone=clone $this;
		$clone->default=$value;
		return $clone;
	}

	/**
	 * Registers a custom record predicate for in-memory filtering.
	 *
	 * The predicate receives record, value, request, and filter context through PanelUtilityResolver.
	 *
	 * @param callable $predicate Predicate returning true when a record matches the active filter value.
	 * @return self Cloned filter with custom predicate.
	 */
	public function where(callable $predicate): self {
		$clone=clone $this;
		$clone->predicate=\Closure::fromCallable($predicate);
		return $clone;
	}

	/**
	 * Makes the filter visible or registers a dynamic visibility predicate.
	 *
	 * Boolean false hides the filter immediately; callable values are evaluated by isVisible().
	 *
	 * @param bool|callable $visible Static visibility flag or callback.
	 * @return self Cloned filter with visibility rule.
	 */
	public function visible(bool|callable $visible=true): self {
		if(is_callable($visible) && !is_bool($visible)){
			return $this->visibleUsing($visible);
		}
		$clone=clone $this;
		$clone->hidden=!$visible;
		return $clone;
	}

	/**
	 * Hides the filter or registers a dynamic hidden predicate.
	 *
	 * Callable values are evaluated by isVisible(); true means the filter is hidden.
	 *
	 * @param bool|callable $hidden Static hidden flag or callback.
	 * @return self Cloned filter with hidden rule.
	 */
	public function hidden(bool|callable $hidden=true): self {
		if(is_callable($hidden) && !is_bool($hidden)){
			return $this->hiddenUsing($hidden);
		}
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Registers a callback that must return true for the filter to be visible.
	 *
	 * @param callable $callback Visibility callback.
	 * @return self Cloned filter with visibility callback.
	 */
	public function visibleUsing(callable $callback): self {
		$clone=clone $this;
		$clone->visibilityCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Registers a callback that hides the filter when it returns true.
	 *
	 * @param callable $callback Hidden callback.
	 * @return self Cloned filter with hidden callback.
	 */
	public function hiddenUsing(callable $callback): self {
		$clone=clone $this;
		$clone->hiddenCallback=\Closure::fromCallable($callback);
		return $clone;
	}

	/**
	 * Restricts filter visibility to specific panel operations.
	 *
	 * Operation names are normalized and may be passed as variadic strings or arrays.
	 *
	 * @param array|string ...$operations Operations where the filter should be visible.
	 * @return self Cloned filter with visible operation allow-list.
	 */
	public function visibleOn(array|string ...$operations): self {
		$clone=clone $this;
		$clone->visibleOn=self::normalizeOperations($operations);
		return $clone;
	}

	/**
	 * Alias for visibleOn().
	 *
	 * @param array|string ...$operations Operations where the filter should be visible.
	 * @return self Cloned filter with visible operation allow-list.
	 */
	public function onlyOn(array|string ...$operations): self {
		return $this->visibleOn(...$operations);
	}

	/**
	 * Hides the filter on specific panel operations.
	 *
	 * @param array|string ...$operations Operations where the filter should be hidden.
	 * @return self Cloned filter with hidden operation list.
	 */
	public function hiddenOn(array|string ...$operations): self {
		$clone=clone $this;
		$clone->hiddenOn=self::normalizeOperations($operations);
		return $clone;
	}

	/**
	 * Alias for hiddenOn().
	 *
	 * @param array|string ...$operations Operations where the filter should be hidden.
	 * @return self Cloned filter with hidden operation list.
	 */
	public function exceptOn(array|string ...$operations): self {
		return $this->hiddenOn(...$operations);
	}

	/**
	 * Sets a static indicator label or dynamic indicator resolver.
	 *
	 * Null disables custom indicator labeling and lets indicators() use the filter label.
	 *
	 * @param string|callable|null $indicator Static label, resolver callback, or null.
	 * @return self Cloned filter with indicator configuration.
	 */
	public function indicator(string|callable|null $indicator): self {
		$clone=clone $this;
		$clone->indicator=is_callable($indicator) && !is_string($indicator) ? \Closure::fromCallable($indicator) : ($indicator===null ? null : trim((string)$indicator));
		return $clone;
	}

	/**
	 * Registers a dynamic active-filter indicator resolver.
	 *
	 * @param callable $indicator Callback returning an indicator string, record, list, null, or false.
	 * @return self Cloned filter with dynamic indicator resolver.
	 */
	public function indicatorUsing(callable $indicator): self {
		return $this->indicator($indicator);
	}

	/**
	 * Sets the semantic tone for generated active-filter indicators.
	 *
	 * @param string $tone Tone token used by the panel UI.
	 * @return self Cloned filter with normalized indicator tone.
	 */
	public function indicatorTone(string $tone): self {
		$clone=clone $this;
		$tone=Resource::normalizeName($tone);
		$clone->indicatorTone=$tone!=='' ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Merges arbitrary metadata into the filter manifest.
	 *
	 * @param array<string,mixed> $meta Metadata consumed by panel renderers or extensions.
	 * @return self Cloned filter with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Resolves the active request value for this filter.
	 *
	 * Range filters read `<name>_from` and `<name>_to`. Non-range filters read `<name>` and fall
	 * back to the configured default. Select-like values are rejected when they are not present in
	 * the resolved options.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param ?array $options Pre-resolved options to avoid invoking dynamic option callbacks twice.
	 * @return mixed Null when inactive, array{from:mixed,to:mixed} for ranges, or scalar/stringable filter value.
	 */
	public function activeValue(PanelRequest $request, ?array $options=null): mixed {
		if($this->isRange()){
			$from=$request->query($this->name.'_from', null);
			$to=$request->query($this->name.'_to', null);
			$from=self::blank($from) ? null : $from;
			$to=self::blank($to) ? null : $to;
			return $from===null && $to===null ? null : ['from'=>$from, 'to'=>$to];
		}
		$value=$request->query($this->name, $this->default);
		if(self::blank($value)){
			return null;
		}
		$options ??=$this->optionsFor($request);
		if($this->optionValidationEnabled() && $options!==[] && !in_array((string)$value, self::optionValues($options), true)){
			return null;
		}
		return $value;
	}

	/**
	 * Tests whether a record matches this filter for the current request.
	 *
	 * Invisible or inactive filters match all records. Custom predicates receive record, value,
	 * request, and filter context; otherwise built-in matching is based on filter type.
	 *
	 * @param mixed $record Record array or object to inspect.
	 * @param PanelRequest $request Current panel request.
	 * @return bool True when the record should remain in the filtered table.
	 */
	public function matches(mixed $record, PanelRequest $request): bool {
		if(!$this->isVisible($request)){
			return true;
		}
		$value=$this->activeValue($request);
		if($value===null){
			return true;
		}
		if($this->predicate!==null){
			return (bool)PanelUtilityResolver::evaluate($this->predicate, [
				'record'=>$record,
				'value'=>$value,
				'request'=>$request,
				'filter'=>$this,
			], ['record', 'value', 'request', 'filter']);
		}
		$recordValue=self::recordValue($record, $this->column ?? $this->name);
		if($this->isRange()){
			return self::matchesRange($recordValue, is_array($value) ? $value : [], $this->type);
		}
		return match($this->type){
			'boolean', 'bool', 'checkbox', 'toggle'=>self::truthy($recordValue)===self::truthy($value),
			'select', 'enum'=>((string)$recordValue)===(string)$value,
			'date'=>substr((string)$recordValue, 0, 10)===(string)$value,
			default=>stripos((string)$recordValue, (string)$value)!==false,
		};
	}

	/**
	 * Resolves whether the filter should be visible for a request context.
	 *
	 * Operation allow/deny lists are evaluated first, then static hidden state, then visibility and
	 * hidden callbacks with operation, request, filter, resource, and table context.
	 *
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param mixed $resource Owning resource context.
	 * @param mixed $table Owning table context.
	 * @return bool True when the filter should be shown and applied.
	 */
	public function isVisible(?PanelRequest $request=null, mixed $resource=null, mixed $table=null): bool {
		$operation=self::normalizeOperation($request?->operation() ?? 'index');
		if($this->visibleOn!==[] && !in_array($operation, $this->visibleOn, true)){
			return false;
		}
		if(in_array($operation, $this->hiddenOn, true)){
			return false;
		}
		if($this->hidden){
			return false;
		}
		$values=[
			'operation'=>$operation,
			'mode'=>$operation,
			'request'=>$request,
			'filter'=>$this,
			'resource'=>$resource,
			'table'=>$table,
		];
		$order=['operation', 'request', 'filter', 'resource', 'table'];
		if($this->visibilityCallback!==null && (bool)PanelUtilityResolver::evaluate($this->visibilityCallback, $values, $order)===false){
			return false;
		}
		if($this->hiddenCallback!==null && (bool)PanelUtilityResolver::evaluate($this->hiddenCallback, $values, $order)===true){
			return false;
		}
		return true;
	}

	/**
	 * Builds active-filter indicator records for the current request.
	 *
	 * Dynamic indicator callbacks may return strings, single indicator arrays, lists of indicators,
	 * null, or false. Default indicators include clear keys for the base and range query names.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param ?array $options Pre-resolved options for value labeling.
	 * @return list<array{filter:string,label:string,value:string,tone:string,clear:array<int,string>}> Active indicator records.
	 */
	public function indicators(PanelRequest $request, ?array $options=null): array {
		$options ??=$this->optionsFor($request);
		$value=$this->activeValue($request, $options);
		if($value===null){
			return [];
		}
		if($this->indicator instanceof \Closure){
			$result=PanelUtilityResolver::evaluate($this->indicator, [
				'value'=>$value,
				'request'=>$request,
				'filter'=>$this,
				'options'=>$options,
			], ['value', 'request', 'filter', 'options']);
			return self::normalizeIndicators($result, $this->name, $this->label, $this->indicatorTone, $value);
		}
		$label=is_string($this->indicator) && $this->indicator!=='' ? $this->indicator : $this->label;
		$display=$this->valueLabel($value, $options);
		return [[
			'filter'=>$this->name,
			'label'=>$label,
			'value'=>$display,
			'tone'=>$this->indicatorTone,
			'clear'=>[$this->name, $this->name.'_from', $this->name.'_to'],
		]];
	}

	/**
	 * Serializes the filter definition for panel manifests.
	 *
	 * @return array{name:string,type:string,label:string,column:?string,options:array,dynamic_options:bool,default:mixed,has_predicate:bool,hidden:bool,visible_on:array,hidden_on:array,conditional:bool,indicator:string,indicator_dynamic:bool,indicator_tone:string,meta:array,range:bool} Manifest-ready filter payload.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'label'=>$this->label,
			'column'=>$this->column,
			'options'=>$this->options,
			'dynamic_options'=>$this->optionsCallback!==null,
			'default'=>$this->default,
			'has_predicate'=>$this->predicate!==null,
			'hidden'=>$this->hidden,
			'visible_on'=>$this->visibleOn,
			'hidden_on'=>$this->hiddenOn,
			'conditional'=>$this->hidden || $this->visibleOn!==[] || $this->hiddenOn!==[] || $this->visibilityCallback!==null || $this->hiddenCallback!==null,
			'indicator'=>is_string($this->indicator) ? $this->indicator : '',
			'indicator_dynamic'=>$this->indicator instanceof \Closure,
			'indicator_tone'=>$this->indicatorTone,
			'meta'=>$this->meta,
			'range'=>$this->isRange(),
		];
	}

	/**
	 * Resolves static or dynamic options for a request.
	 *
	 * Dynamic option callbacks receive request and filter context. Non-array callback results are
	 * treated as no options so downstream validation can stay predictable.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return array Option definitions for this request.
	 */
	public function optionsFor(PanelRequest $request): array {
		if($this->optionsCallback===null){
			return $this->options;
		}
		$options=PanelUtilityResolver::evaluate($this->optionsCallback, [
			'request'=>$request,
			'filter'=>$this,
		], ['request', 'filter']);
		return is_array($options) ? $options : [];
	}

	/**
	 * Reads a record value from arrays, public object properties, or getter methods.
	 *
	 * Getter names are derived from the filter key by converting separators to
	 * words and prefixing get, matching Resource record access behavior.
	 *
	 * @param mixed $record Record being filtered.
	 * @param string $key Source column or property name.
	 * @param mixed $default Value used when the key is unavailable.
	 * @return mixed array value, public property, getter return value, or the caller default when absent.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=''): mixed {
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
	 * Detects inactive filter values.
	 *
	 * Null, blank strings, and empty arrays are treated as absent; numeric zero and
	 * boolean false remain meaningful filter values.
	 *
	 * @param mixed $value Candidate request value.
	 * @return bool Whether the value should be considered blank.
	 */
	private static function blank(mixed $value): bool {
		return $value===null || (is_string($value) && trim($value)==='') || (is_array($value) && $value===[]);
	}

	/**
	 * Interprets submitted and record values as booleans for toggle filters.
	 *
	 * Numeric values use zero/non-zero semantics and common HTML string tokens are
	 * recognized for checkbox and toggle controls.
	 *
	 * @param mixed $value Raw value.
	 * @return bool Boolean interpretation.
	 */
	private static function truthy(mixed $value): bool {
		if(is_bool($value)){
			return $value;
		}
		if(is_int($value) || is_float($value)){
			return $value!==0;
		}
		if(is_string($value)){
			return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
		}
		return $value!==null;
	}

	/**
	 * Determines whether active values must exist in the option set.
	 *
	 * Select and enum filters always validate; other filter types validate when
	 * static or dynamic options were supplied.
	 *
	 * @return bool Whether request values are constrained to optionValues().
	 */
	private function optionValidationEnabled(): bool {
		return in_array($this->type, ['select', 'enum'], true) || $this->options!==[] || $this->optionsCallback!==null;
	}

	/**
	 * Identifies filter types that read from/to request values.
	 *
	 * Range filters use `<name>_from` and `<name>_to` and compare values through
	 * matchesRange().
	 *
	 * @return bool Whether this filter is range-based.
	 */
	private function isRange(): bool {
		return in_array($this->type, ['range', 'date_range', 'number_range', 'numeric_range', 'money_range'], true);
	}

	/**
	 * Checks whether a record value falls inside a filter range.
	 *
	 * Numeric and money ranges require numeric record values, date ranges compare
	 * the YYYY-MM-DD prefix, and generic ranges compare string values.
	 *
	 * @param mixed $recordValue Value read from the record.
	 * @param array{from?:mixed,to?:mixed} $range Active range request value.
	 * @param string $type Range filter type.
	 * @return bool Whether the value is inside the range.
	 */
	private static function matchesRange(mixed $recordValue, array $range, string $type): bool {
		$from=$range['from'] ?? null;
		$to=$range['to'] ?? null;
		if($from===null && $to===null){
			return true;
		}
		if(in_array($type, ['number_range', 'numeric_range', 'money_range'], true)){
			if(!is_numeric($recordValue)){
				return false;
			}
			$value=(float)$recordValue;
			return ($from===null || $value>=(float)$from) && ($to===null || $value<=(float)$to);
		}
		if($type==='date_range'){
			$value=substr((string)$recordValue, 0, 10);
			return ($from===null || $value>=(string)$from) && ($to===null || $value<=(string)$to);
		}
		$value=(string)$recordValue;
		return ($from===null || $value>=(string)$from) && ($to===null || $value<=(string)$to);
	}

	/**
	 * Extracts valid submitted values from nested option definitions.
	 *
	 * Flat maps, option arrays, and grouped options are traversed recursively so
	 * active value validation matches the labels shown by renderers.
	 *
	 * @param array<string|int,mixed> $options Filter option definitions.
	 * @return array<int,string> Unique accepted option values.
	 */
	private static function optionValues(array $options): array {
		$values=[];
		foreach($options as $value=>$label){
			if(is_array($label) && self::isOptionGroup($label)){
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options']);
				$values=array_merge($values, self::optionValues($groupOptions));
				continue;
			}
			if(is_array($label)){
				$values[]=(string)($label['value'] ?? $value);
				continue;
			}
			$values[]=is_int($value) ? (string)$label : (string)$value;
		}
		return array_values(array_unique($values));
	}

	/**
	 * Formats an active filter value for indicator display.
	 *
	 * Ranges become from/to labels, boolean filters become Yes/No, option-backed
	 * filters use option labels, and remaining values are stringified.
	 *
	 * @param mixed $value Active filter value.
	 * @param array<string|int,mixed> $options Resolved options.
	 * @return string Indicator value text.
	 */
	private function valueLabel(mixed $value, array $options): string {
		if(is_array($value)){
			$from=self::stringValue($value['from'] ?? '');
			$to=self::stringValue($value['to'] ?? '');
			if($from!=='' && $to!==''){
				return $from.' to '.$to;
			}
			return $from!=='' ? 'from '.$from : 'to '.$to;
		}
		if(in_array($this->type, ['boolean', 'bool', 'checkbox', 'toggle'], true)){
			return self::truthy($value) ? 'Yes' : 'No';
		}
		if($options!==[]){
			$label=self::optionLabel($options, (string)$value);
			if($label!==null){
				return $label;
			}
		}
		return self::stringValue($value);
	}

	/**
	 * Normalizes custom indicator callback output into manifest records.
	 *
	 * Callbacks may return scalar text, one associative indicator, a list of
	 * indicators, null, or false. Clear keys default to the base and range query
	 * keys so the UI can remove active filters reliably.
	 *
	 * @param mixed $result Raw indicator callback result.
	 * @param string $filter Filter name.
	 * @param string $label Default indicator label.
	 * @param string $tone Default indicator tone.
	 * @param mixed $value Active filter value.
	 * @return list<array{filter:string,label:string,value:string,tone:string,clear:array<int,string>}> Normalized indicators.
	 */
	private static function normalizeIndicators(mixed $result, string $filter, string $label, string $tone, mixed $value): array {
		if($result===null || $result===false || $result===''){
			return [];
		}
		if(is_scalar($result) || $result instanceof \Stringable){
			return [[
				'filter'=>$filter,
				'label'=>$label,
				'value'=>(string)$result,
				'tone'=>$tone,
				'clear'=>[$filter, $filter.'_from', $filter.'_to'],
			]];
		}
		$items=array_is_list($result) ? $result : [$result];
		$indicators=[];
		foreach($items as $item){
			if(is_scalar($item) || $item instanceof \Stringable){
				$indicators[]=[
					'filter'=>$filter,
					'label'=>$label,
					'value'=>(string)$item,
					'tone'=>$tone,
					'clear'=>[$filter, $filter.'_from', $filter.'_to'],
				];
				continue;
			}
			if(!is_array($item)){
				continue;
			}
			$clear=$item['clear'] ?? [$filter, $filter.'_from', $filter.'_to'];
			$indicators[]=[
				'filter'=>Resource::normalizeName((string)($item['filter'] ?? $filter)) ?: $filter,
				'label'=>trim((string)($item['label'] ?? $label)),
				'value'=>self::stringValue($item['value'] ?? $item['text'] ?? ''),
				'tone'=>Resource::normalizeName((string)($item['tone'] ?? $tone)) ?: 'neutral',
				'clear'=>is_array($clear) ? array_values(array_filter(array_map(static fn(mixed $key): string => Resource::normalizeName((string)$key), $clear))) : [Resource::normalizeName((string)$clear)],
			];
		}
		return array_values(array_filter($indicators, static fn(array $indicator): bool => ($indicator['label'] ?? '')!=='' || ($indicator['value'] ?? '')!==''));
	}

	/**
	 * Resolves the display label for one submitted option value.
	 *
	 * The lookup walks grouped options recursively and supports both value-label
	 * maps and option arrays containing value and label keys.
	 *
	 * @param array<string|int,mixed> $options Filter option definitions.
	 * @param string $needle Submitted option value.
	 * @return ?string Matching display label, or null.
	 */
	private static function optionLabel(array $options, string $needle): ?string {
		foreach($options as $value=>$label){
			if(is_array($label) && self::isOptionGroup($label)){
				$groupOptions=is_array($label['options'] ?? null) ? $label['options'] : $label;
				unset($groupOptions['label'], $groupOptions['options']);
				$match=self::optionLabel($groupOptions, $needle);
				if($match!==null){
					return $match;
				}
				continue;
			}
			$optionValue=is_array($label) ? (string)($label['value'] ?? $value) : (is_int($value) ? (string)$label : (string)$value);
			if($optionValue!==$needle){
				continue;
			}
			if(is_array($label)){
				return self::stringValue($label['label'] ?? $optionValue);
			}
			return self::stringValue($label);
		}
		return null;
	}

	/**
	 * Converts indicator and option values into compact strings.
	 *
	 * Scalars and Stringable objects are trimmed directly, booleans use 1/0, and
	 * arrays or objects fall back to JSON for predictable display text.
	 *
	 * @param mixed $value Raw value.
	 * @return string Display string.
	 */
	private static function stringValue(mixed $value): string {
		if($value===null){
			return '';
		}
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if(is_scalar($value) || $value instanceof \Stringable){
			return trim((string)$value);
		}
		return trim(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
	}

	/**
	 * Normalizes a Panel operation name for visibility matching.
	 *
	 * Empty operation values fall back to index, matching the default table route.
	 *
	 * @param string $operation Raw operation or mode.
	 * @return string Normalized operation name.
	 */
	private static function normalizeOperation(string $operation): string {
		return Resource::normalizeName($operation) ?: 'index';
	}

	/**
	 * Flattens and normalizes operation allow/deny lists.
	 *
	 * Variadic arrays from visibleOn()/hiddenOn() are collapsed, normalized, and
	 * de-duplicated before runtime visibility checks.
	 *
	 * @param array<int,mixed> $operations Raw operation values.
	 * @return array<int,string> Normalized operation names.
	 */
	private static function normalizeOperations(array $operations): array {
		$flat=[];
		foreach($operations as $operation){
			if(is_array($operation)){
				array_push($flat, ...$operation);
			}
			else {
				$flat[]=$operation;
			}
		}
		return array_values(array_unique(array_filter(array_map(
			static fn(mixed $operation): string => self::normalizeOperation((string)$operation),
			$flat
		))));
	}

	/**
	 * Detects grouped option structures.
	 *
	 * Explicit options arrays are groups, and associative arrays without value or
	 * label keys are treated as nested option maps.
	 *
	 * @param array<string|int,mixed> $option Option definition candidate.
	 * @return bool Whether the option should be traversed as a group.
	 */
	private static function isOptionGroup(array $option): bool {
		if(isset($option['options']) && is_array($option['options'])){
			return true;
		}
		return !array_key_exists('value', $option) && !array_key_exists('label', $option) && !array_is_list($option);
	}

	/**
	 * Builds a default label from a normalized filter key.
	 *
	 * Separators become spaces and words are title-cased so filters have readable
	 * labels without explicit configuration.
	 *
	 * @param string $value Filter key.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
