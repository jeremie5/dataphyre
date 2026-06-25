<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes one computed summary tile for a Panel resource table.
 *
 * Table summaries are immutable builder objects: every mutator returns a clone
 * so resource definitions can safely share base instances while tailoring
 * labels, aggregate types, display tone, formatting metadata, or custom
 * callbacks. The object itself performs no persistence; it consumes the
 * already-filtered record set supplied by the renderer and returns a small
 * array payload for Panel views, traces, tests, and diagnostics.
 */
final class TableSummary {
	use PanelExtensible;

	/** Normalized machine name used as the summary key in rendered payloads. */
	private string $name;

	/** Aggregate strategy: count, sum, avg/average, min, max, or a custom name. */
	private string $type='count';

	/** Human-readable label shown in the operator table summary strip. */
	private string $label;

	/** Normalized record field used by numeric aggregate summaries. */
	private ?string $column=null;

	/** Semantic UI tone consumed by Panel CSS and component renderers. */
	private string $tone='neutral';

	/** Optional callback that replaces the built-in aggregate calculation. */
	private ?\Closure $valueResolver=null;

	/** Optional callback that converts the resolved value into display text. */
	private ?\Closure $formatter=null;

	/** Arbitrary renderer metadata such as money/percent formatting hints. */
	private array $meta=[];

	/**
	 * Normalizes the summary identity before public configuration is applied.
	 *
	 * The constructor is private so callers enter through make(), allowing
	 * PanelExtensible configuration hooks to run consistently for every
	 * summary instance.
	 *
	 * @param string $name Raw summary name from a resource definition.
	 * @param string $type Raw aggregate strategy, defaulting to count.
	 */
	private function __construct(string $name, string $type='count') {
		$this->name=Resource::normalizeName($name);
		$this->type=Resource::normalizeName($type) ?: 'count';
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured summary definition.
	 *
	 * The name is normalized for stable array keys and CSS/data attributes. An
	 * empty or unsupported type is not rejected here because custom resolvers
	 * may use domain-specific type names; built-in aggregation falls back to a
	 * count when it cannot match the type later.
	 *
	 * @param string $name Summary key from a Panel resource definition.
	 * @param string $type Aggregate strategy or custom resolver label.
	 * @return self Immutable summary definition after extension hooks run.
	 */
	public static function make(string $name, string $type='count'): self {
		return self::configured(new self($name, $type));
	}

	/**
	 * Rehydrates a summary from manifest or array configuration.
	 *
	 * Only scalar configuration survives this array form. Runtime callbacks are
	 * intentionally excluded because manifests and generated package metadata
	 * must remain serializable and safe to inspect without executing user code.
	 *
	 * @param array{name?:string,type?:string,label?:string,column?:string,tone?:string,meta?:array<string,mixed>} $definition Array with optional name, type, label, column, tone, and meta keys.
	 * @return self Immutable summary configured from the supplied definition.
	 */
	public static function fromArray(array $definition): self {
		$summary=self::make((string)($definition['name'] ?? ''), (string)($definition['type'] ?? 'count'));
		if(isset($definition['label'])){
			$summary=$summary->label((string)$definition['label']);
		}
		if(isset($definition['column']) && is_string($definition['column'])){
			$summary=$summary->column($definition['column']);
		}
		if(isset($definition['tone']) && is_string($definition['tone'])){
			$summary=$summary->tone($definition['tone']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$summary=$summary->meta($definition['meta']);
		}
		return $summary;
	}

	/**
	 * Returns the normalized summary key.
	 *
	 * @return string Stable machine name used in payloads and trace records.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the operator-facing label.
	 *
	 * The value is trimmed but otherwise preserved so resources can provide
	 * punctuation, localized strings, or concise dashboard terminology.
	 *
	 * @param string $label Text shown beside the resolved summary value.
	 * @return self Cloned summary with the updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Sets the aggregate strategy.
	 *
	 * Built-in strategies are count, sum, avg/average, min, and max. Other
	 * normalized values are allowed for custom resolvers and metadata consumers;
	 * the built-in aggregate path treats unknown values as count.
	 *
	 * @param string $type Aggregate strategy name.
	 * @return self Cloned summary with the normalized type.
	 */
	public function type(string $type): self {
		$clone=clone $this;
		$clone->type=Resource::normalizeName($type) ?: 'count';
		return $clone;
	}

	/**
	 * Sets the record column used by numeric aggregates.
	 *
	 * Column names are normalized through the Panel resource naming rules. Empty
	 * names clear the column, which makes numeric aggregate helpers resolve to
	 * no data instead of accidentally scanning every record.
	 *
	 * @param string $column Record key or accessor suffix to aggregate.
	 * @return self Cloned summary with the selected column.
	 */
	public function column(string $column): self {
		$clone=clone $this;
		$clone->column=Resource::normalizeName($column) ?: null;
		return $clone;
	}

	/**
	 * Sets the summary to count records.
	 *
	 * Count summaries do not require a column and count the records supplied to
	 * resolve(), after the renderer has already applied filters and selection
	 * rules.
	 *
	 * @return self Cloned count summary with any prior numeric column cleared.
	 */
	public function count(): self {
		$clone=clone $this;
		$clone->type='count';
		$clone->column=null;
		return $clone;
	}

	/**
	 * Sets the summary to sum numeric values in the selected column.
	 *
	 * @param string $column Record key or getter-backed field to read.
	 * @return self Cloned sum summary.
	 */
	public function sum(string $column): self {
		return $this->type('sum')->column($column);
	}

	/**
	 * Sets the summary to average numeric values in the selected column.
	 *
	 * @param string $column Record key or getter-backed field to read.
	 * @return self Cloned average summary.
	 */
	public function avg(string $column): self {
		return $this->type('avg')->column($column);
	}

	/**
	 * Sets the summary to read the minimum numeric value in the selected column.
	 *
	 * @param string $column Record key or getter-backed field to read.
	 * @return self Cloned minimum summary.
	 */
	public function min(string $column): self {
		return $this->type('min')->column($column);
	}

	/**
	 * Sets the summary to read the maximum numeric value in the selected column.
	 *
	 * @param string $column Record key or getter-backed field to read.
	 * @return self Cloned maximum summary.
	 */
	public function max(string $column): self {
		return $this->type('max')->column($column);
	}

	/**
	 * Installs a callback that computes the raw summary value.
	 *
	 * The callback is evaluated through PanelUtilityResolver with records,
	 * resource, request, and the summary instance available by name. Exceptions
	 * are caught by resolve(), traced, and converted into the configured empty
	 * display value so one broken summary does not take down the table page.
	 *
	 * @param callable $resolver Resolver callable for the raw value.
	 * @return self Cloned summary carrying the resolver closure.
	 */
	public function valueUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->valueResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Installs a callback that formats the resolved value.
	 *
	 * The formatter receives the resolved value plus the same contextual objects
	 * used by valueUsing(). Its return value is coerced through stringValue() so
	 * arrays and objects remain inspectable instead of leaking PHP notices into
	 * the operator UI.
	 *
	 * @param callable $formatter Formatter callable for display text.
	 * @return self Cloned summary carrying the formatter closure.
	 */
	public function format(callable $formatter): self {
		$clone=clone $this;
		$clone->formatter=\Closure::fromCallable($formatter);
		return $clone;
	}

	/**
	 * Adds built-in money formatting metadata.
	 *
	 * Decimals are clamped between 0 and 8 to avoid unreasonable number_format()
	 * output while still allowing currencies or measurements with high
	 * precision.
	 *
	 * @param string $currency Optional currency prefix such as CAD or USD.
	 * @param int $decimals Number of decimal places to render.
	 * @return self Cloned summary with money format metadata merged.
	 */
	public function money(string $currency='', int $decimals=2): self {
		return $this->meta([
			'format'=>'money',
			'currency'=>trim($currency),
			'decimals'=>max(0, min(8, $decimals)),
		]);
	}

	/**
	 * Adds built-in percentage formatting metadata.
	 *
	 * The multiplier defaults to 100 so fractional values such as 0.42 render
	 * as 42%. Pass 1.0 when records already store whole-percentage values.
	 *
	 * @param int $decimals Number of decimal places to render.
	 * @param float $multiplier Factor applied before appending the percent sign.
	 * @return self Cloned summary with percent format metadata merged.
	 */
	public function percent(int $decimals=2, float $multiplier=100.0): self {
		return $this->meta([
			'format'=>'percent',
			'decimals'=>max(0, min(8, $decimals)),
			'multiplier'=>$multiplier,
		]);
	}

	/**
	 * Sets the semantic visual tone for the summary tile.
	 *
	 * Unknown tones collapse to neutral so generated resource manifests cannot
	 * accidentally emit unsupported UI classes.
	 *
	 * @param string $tone One of neutral, primary, success, warning, danger, or info.
	 * @return self Cloned summary with the normalized tone.
	 */
	public function tone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->tone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Merges renderer metadata into the summary.
	 *
	 * Metadata is intentionally open-ended because summaries are extension
	 * points for package renderers. Later calls override matching keys while
	 * preserving unrelated hints.
	 *
	 * @param array<string,mixed> $meta Serializable metadata consumed by renderers or callbacks.
	 * @return self Cloned summary with metadata merged over existing values.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Resolves this summary against the current table record set.
	 *
	 * Built-in summaries inspect only numeric values for column aggregates and
	 * ignore missing or non-numeric values. Custom resolver and formatter
	 * failures are converted to an unavailable display value and recorded in
	 * PanelTrace, preserving the page response while leaving diagnostics for
	 * developers.
	 *
	 * @param list<mixed> $records Filtered records visible to the current table request.
	 * @param Resource $resource Resource that owns the table definition.
	 * @param PanelRequest $request Current Panel request context.
	 * @return array{name:string,type:string,label:string,column:?string,value:mixed,formatted:string,tone:string,meta:array<string,mixed>}
	 */
	public function resolve(array $records, Resource $resource, PanelRequest $request): array {
		$value=null;
		$formatted='';
		try{
			$value=$this->valueResolver!==null
				? PanelUtilityResolver::evaluate($this->valueResolver, [
					'records'=>$records,
					'data'=>$records,
					'resource'=>$resource,
					'request'=>$request,
					'summary'=>$this,
				], ['records', 'resource', 'request', 'summary'])
				: $this->aggregate($records);
			$formatted=$this->formatValue($value, $records, $resource, $request);
		}
		catch(\Throwable $exception){
			PanelTrace::record('summary.error', [
				'summary'=>$this->name,
				'resource'=>$resource,
				'message'=>$exception->getMessage(),
			]);
			$value=null;
			$formatted=(string)($this->meta['empty'] ?? 'Unavailable');
		}
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'label'=>$this->label,
			'column'=>$this->column,
			'value'=>$value,
			'formatted'=>$formatted,
			'tone'=>$this->tone,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Exports the declarative summary definition without evaluating records.
	 *
	 * The payload is safe for manifests and diagnostics because callbacks are
	 * represented as booleans rather than serialized executable state.
	 *
	 * @return array{name:string,type:string,label:string,column:?string,tone:string,computed:bool,formatted:bool,meta:array}
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'label'=>$this->label,
			'column'=>$this->column,
			'tone'=>$this->tone,
			'computed'=>$this->valueResolver!==null,
			'formatted'=>$this->formatter!==null || isset($this->meta['format']),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Calculates the built-in aggregate value for the supplied records.
	 *
	 * Unknown aggregate types intentionally return a count, matching the safe
	 * default used by type() and make(). Numeric aggregate types return null
	 * when no selected column values can be interpreted as numbers.
	 *
	 * @param list<mixed> $records Filtered records passed to resolve().
	 * @return mixed Count, numeric aggregate, or null when numeric data is absent.
	 */
	private function aggregate(array $records): mixed {
		if($this->type==='count'){
			return count($records);
		}
		$values=$this->numericValues($records);
		if($values===[]){
			return null;
		}
		return match($this->type){
			'sum'=>array_sum($values),
			'avg', 'average'=>array_sum($values)/count($values),
			'min'=>min($values),
			'max'=>max($values),
			default=>count($records),
		};
	}

	/**
	 * Extracts numeric values from array or object records.
	 *
	 * Objects may expose a public property or a conventional getter matching
	 * the normalized column name. Non-numeric, missing, and null values are
	 * skipped so partial datasets can still produce useful aggregates.
	 *
	 * @param list<mixed> $records Records to inspect for the configured column.
	 * @return array<int,float> Numeric values ready for aggregate math.
	 */
	private function numericValues(array $records): array {
		if($this->column===null){
			return [];
		}
		$values=[];
		foreach($records as $record){
			$value=self::recordValue($record, $this->column, null);
			if(is_numeric($value)){
				$values[]=(float)$value;
			}
		}
		return $values;
	}

	/**
	 * Converts a raw summary value into operator-facing display text.
	 *
	 * Custom formatters run first. If none is configured, built-in metadata
	 * supports money and percent formatting before scalar fallback conversion.
	 * Empty values use the configured meta.empty string or the default "No data".
	 *
	 * @param mixed $value Raw value from the resolver or aggregate path.
	 * @param list<mixed> $records Records that produced the value.
	 * @param ?Resource $resource Resource context for custom formatters.
	 * @param ?PanelRequest $request Request context for custom formatters.
	 * @return string Display-safe summary text.
	 */
	private function formatValue(mixed $value, array $records=[], ?Resource $resource=null, ?PanelRequest $request=null): string {
		if($this->formatter!==null){
			return self::stringValue(PanelUtilityResolver::evaluate($this->formatter, [
				'value'=>$value,
				'records'=>$records,
				'data'=>$records,
				'resource'=>$resource,
				'request'=>$request,
				'summary'=>$this,
			], ['value', 'summary']));
		}
		if($value===null || $value===''){
			return (string)($this->meta['empty'] ?? 'No data');
		}
		$format=(string)($this->meta['format'] ?? '');
		if($format==='money' && is_numeric($value)){
			$decimals=(int)($this->meta['decimals'] ?? 2);
			$amount=number_format((float)$value, max(0, min(8, $decimals)));
			$currency=trim((string)($this->meta['currency'] ?? ''));
			return $currency!=='' ? $currency.' '.$amount : $amount;
		}
		if($format==='percent' && is_numeric($value)){
			$decimals=(int)($this->meta['decimals'] ?? 2);
			$multiplier=(float)($this->meta['multiplier'] ?? 100);
			return number_format((float)$value*$multiplier, max(0, min(8, $decimals))).'%';
		}
		if(is_float($value)){
			return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
		}
		return self::stringValue($value);
	}

	/**
	 * Reads one field from an array or object record.
	 *
	 * This helper keeps summary aggregation independent from any specific ORM:
	 * arrays, public properties, and getter methods all participate in the same
	 * table summary rules.
	 *
	 * @param mixed $record Array or object record supplied to the table.
	 * @param string $key Normalized field name to read.
	 * @param mixed $default Value returned when the field is unavailable.
	 * @return mixed array value, public property, getter result, or the caller default when unavailable.
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
	 * Coerces formatter output into a stable string.
	 *
	 * Scalar values are string-cast directly, booleans use numeric display
	 * values, and structured values become JSON for diagnostics-friendly output.
	 *
	 * @param mixed $value Value returned by a formatter or aggregate path.
	 * @return string String safe to embed in Panel response content.
	 */
	private static function stringValue(mixed $value): string {
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if(is_scalar($value) || $value===null){
			return (string)$value;
		}
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
	}

	/**
	 * Converts a normalized summary key into a default label.
	 *
	 * Separators become spaces and each word is capitalized, giving resource
	 * authors useful labels even when they only provide a machine name.
	 *
	 * @param string $value Normalized or raw summary key.
	 * @return string Human-readable default label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
