<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Serializable runtime state for a resolved panel widget.
 *
 * Widget state preserves the resolved widget array and adds normalized renderer
 * state for identity, type, label, tone, value, chart data, error flags, and
 * request/source metadata. It does not execute widget callbacks or authorize
 * visibility; callers pass in already-resolved widget data.
 */
final class PanelWidgetState implements \JsonSerializable {

	/**
	 * Stores resolved widget data and derived chart/meta state.
	 *
	 * @param array<string,mixed> $widget Resolved widget data.
	 * @param array<string,mixed> $chart Derived chart state for chart-like widgets.
	 * @param array<string,mixed> $meta Renderer or request metadata attached to the state.
	 */
	public function __construct(
		private readonly array $widget=[],
		private readonly array $chart=[],
		private readonly array $meta=[]
	){}

	/**
	 * Creates widget state from a widget definition and resolved widget data.
	 *
	 * The widget object is accepted for call-site clarity; the resolved data is
	 * the authoritative state. Request metadata is serialized when available so
	 * diagnostics can tie widget state back to the panel request that produced it.
	 *
	 * @param Widget $widget Widget definition that produced the resolved data.
	 * @param array<string,mixed> $resolved Resolved widget data.
	 * @param ?PanelRequest $request Request that caused resolution.
	 * @param array<string,mixed> $meta Additional state metadata.
	 * @return self Widget state ready for rendering or serialization.
	 */
	public static function make(Widget $widget, array $resolved, ?PanelRequest $request=null, array $meta=[]): self {
		return self::fromResolved($resolved, array_replace([
			'source'=>'widget',
			'request'=>$request?->toArray(),
		], $meta));
	}

	/**
	 * Creates widget state directly from resolved widget data.
	 *
	 * Chart state is derived only for `chart` and `trend` widget types. Other
	 * widget types keep an empty chart state. Malformed chart metadata is ignored
	 * rather than surfaced as an error flag.
	 *
	 * @param array<string,mixed> $widget Resolved widget data.
	 * @param array<string,mixed> $meta Renderer or request metadata.
	 * @return self Widget state with derived chart metadata when applicable.
	 */
	public static function fromResolved(array $widget, array $meta=[]): self {
		$type=Resource::normalizeName((string)($widget['type'] ?? 'stat')) ?: 'stat';
		$chart=in_array($type, ['chart', 'trend'], true) ? self::chartState($widget) : [];
		return new self($widget, $chart, $meta);
	}

	/**
	 * Returns the resolved widget data used by panel rendering.
	 *
	 * @return array<string,mixed> Resolved widget data.
	 */
	public function widget(): array {
		return $this->widget;
	}

	/**
	 * Returns the widget name.
	 *
	 * @return string Widget name or an empty string when none was resolved.
	 */
	public function name(): string {
		return (string)($this->widget['name'] ?? '');
	}

	/**
	 * Returns the normalized widget type.
	 *
	 * Blank or invalid type values fall back to `stat`.
	 *
	 * @return string Normalized widget type.
	 */
	public function type(): string {
		return Resource::normalizeName((string)($this->widget['type'] ?? 'stat')) ?: 'stat';
	}

	/**
	 * Returns the display label for the widget.
	 *
	 * @return string Widget label, falling back to the widget name.
	 */
	public function label(): string {
		return (string)($this->widget['label'] ?? $this->name());
	}

	/**
	 * Returns the primary widget value.
	 *
	 * @return mixed Resolved value, or null when the widget has no scalar value.
	 */
	public function value(): mixed {
		return $this->widget['value'] ?? null;
	}

	/**
	 * Returns the normalized visual tone for the widget.
	 *
	 * Unsupported tones fall back to `neutral`.
	 *
	 * @return string One of `neutral`, `primary`, `success`, `warning`, `danger`, or `info`.
	 */
	public function tone(): string {
		$tone=Resource::normalizeName((string)($this->widget['tone'] ?? 'neutral'));
		return in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
	}

	/**
	 * Returns the derived chart state.
	 *
	 * @return array<string,mixed> Chart type, labels, datasets, height, and point count.
	 */
	public function chart(): array {
		return $this->chart;
	}

	/**
	 * Returns renderer metadata attached to the widget state.
	 *
	 * @return array<string,mixed> Widget state metadata such as source and request summary.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Reports whether the resolved widget carries an error marker.
	 *
	 * @return bool True when `widget.meta.error` is explicitly true.
	 */
	public function hasError(): bool {
		return ($this->widget['meta']['error'] ?? false)===true;
	}

	/**
	 * Serializes resolved widget data with normalized render state attached.
	 *
	 * The original widget array wins for every key except `state`, which is rebuilt
	 * from normalized accessors. This keeps renderer-facing data intact while giving
	 * API clients a stable state summary.
	 *
	 * @return array<string,mixed> Resolved widget data containing a `state` summary.
	 */
	public function jsonSerialize(): array {
		return array_replace($this->widget, [
			'state'=>[
				'name'=>$this->name(),
				'type'=>$this->type(),
				'label'=>$this->label(),
				'tone'=>$this->tone(),
				'value'=>$this->value(),
				'has_error'=>$this->hasError(),
				'chart'=>$this->chart,
				'meta'=>$this->meta,
			],
		]);
	}

	/**
	 * Derives chart state from chart-like widget data.
	 *
	 * Supported chart types are line, area, bar, donut, and sparkline. Datasets may
	 * be supplied explicitly, or derived from associative `meta.data`. Labels are
	 * generated when datasets exist without labels. Non-numeric values and empty
	 * datasets are discarded so renderers receive only drawable series.
	 *
	 * @param array<string,mixed> $widget Resolved chart or trend widget data.
	 * @return array{type:string,height:int,labels:array<int,string>,datasets:array<int,array<string,mixed>>,point_count:int} Derived chart state.
	 */
	private static function chartState(array $widget): array {
		$meta=is_array($widget['meta'] ?? null) ? $widget['meta'] : [];
		$type=Resource::normalizeName((string)($meta['chart_type'] ?? $meta['type'] ?? 'line')) ?: 'line';
		$type=in_array($type, ['line', 'area', 'bar', 'donut', 'sparkline'], true) ? $type : 'line';
		$labels=array_values(array_map('strval', is_array($meta['labels'] ?? null) ? $meta['labels'] : []));
		$datasets=[];
		if(isset($meta['datasets']) && is_array($meta['datasets'])){
			foreach($meta['datasets'] as $dataset){
				if(!is_array($dataset)){
					continue;
				}
				$values=self::numericValues($dataset['values'] ?? $dataset['data'] ?? []);
				if($values===[]){
					continue;
				}
				$datasets[]=[
					'label'=>(string)($dataset['label'] ?? ''),
					'values'=>$values,
					'tone'=>Resource::normalizeName((string)($dataset['tone'] ?? $widget['tone'] ?? 'primary')) ?: 'primary',
				];
			}
		}
		if($datasets===[] && is_array($meta['data'] ?? null) && $meta['data']!==[]){
			$dataLabels=[];
			$values=[];
			foreach($meta['data'] as $key=>$value){
				if(is_numeric($value)){
					$dataLabels[]=(string)$key;
					$values[]=(float)$value;
				}
			}
			if($labels===[]){
				$labels=$dataLabels;
			}
			if($values!==[]){
				$datasets[]=[
					'label'=>(string)($meta['dataset_label'] ?? ''),
					'values'=>$values,
					'tone'=>(string)($widget['tone'] ?? 'primary'),
				];
			}
		}
		if($labels===[] && $datasets!==[]){
			$count=max(array_map(static fn(array $dataset): int => count($dataset['values']), $datasets));
			$labels=array_map(static fn(int $index): string => (string)($index+1), range(0, max(0, $count-1)));
		}
		return [
			'type'=>$type,
			'height'=>max(120, min(420, (int)($meta['height'] ?? ($type==='sparkline' ? 132 : 190)))),
			'labels'=>$labels,
			'datasets'=>$datasets,
			'point_count'=>$datasets===[] ? 0 : max(array_map(static fn(array $dataset): int => count($dataset['values']), $datasets)),
		];
	}

	/**
	 * Extracts numeric data points from a mixed value list.
	 *
	 * @param mixed $values Candidate chart values.
	 * @return array<int,float> Numeric values cast to floats in original order.
	 */
	private static function numericValues(mixed $values): array {
		if(!is_array($values)){
			return [];
		}
		$normalized=[];
		foreach($values as $value){
			if(is_numeric($value)){
				$normalized[]=(float)$value;
			}
		}
		return $normalized;
	}
}
