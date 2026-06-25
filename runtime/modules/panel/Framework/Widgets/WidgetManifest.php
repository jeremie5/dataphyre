<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes panel widgets for dashboards, clients, and manifests.
 *
 * Widget manifests expose presentation, static or resolved data state, chart
 * metadata, interaction affordances, and safe meta values. Runtime resolution is
 * opt-in so callers can describe widget structure without invoking live callbacks.
 */
final class WidgetManifest {

	/**
	 * Stores the widget source and resolution options for manifest generation.
	 *
	 * @param Widget|array $widget Widget source to describe.
	 * @param ?PanelRequest $request Current request context for live resolution.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @param bool $resolve True to include live widget state.
	 */
	private function __construct(
		private readonly Widget|array $widget,
		private readonly ?PanelRequest $request=null,
		private readonly array $meta=[],
		private readonly bool $resolve=false
	){}

	/**
	 * Creates a manifest builder for a widget object or serialized definition.
	 *
	 * @param Widget|array $widget Widget source to describe.
	 * @param ?PanelRequest $request Current panel request used when resolving live state.
	 * @param array<string,mixed> $meta Additional context such as surface or dashboard scope.
	 * @param bool $resolve True to invoke Widget::state() and include live values.
	 * @return self New immutable manifest builder.
	 */
	public static function from(Widget|array $widget, ?PanelRequest $request=null, array $meta=[], bool $resolve=false): self {
		return new self($widget, $request, $meta, $resolve);
	}

	/**
	 * Materializes the widget_manifest payload.
	 *
	 * @return array<string,mixed> Widget manifest payload.
	 */
	public function toArray(): array {
		$definition=$this->widget instanceof Widget ? $this->widget->toArray() : $this->widget;
		$resolved=$this->resolved($definition);
		$effective=$resolved ?? $definition;
		$chart=self::chart($effective);
		$manifest=[
			'type'=>'widget_manifest',
			'name'=>(string)($definition['name'] ?? ''),
			'widget_type'=>Resource::normalizeName((string)($definition['type'] ?? 'stat')) ?: 'stat',
			'presentation'=>[
				'label'=>(string)($definition['label'] ?? self::humanize((string)($definition['name'] ?? 'widget'))),
				'description'=>$definition['description'] ?? null,
				'tone'=>(string)($definition['tone'] ?? 'neutral'),
				'icon'=>$definition['icon'] ?? null,
				'group'=>$definition['group'] ?? null,
				'sort'=>$definition['sort'] ?? null,
			],
			'data'=>[
				'value'=>$definition['lazy'] ?? false ? null : ($definition['value'] ?? null),
				'lazy'=>($definition['lazy'] ?? false)===true,
				'resolved'=>$resolved!==null,
				'resolved_value'=>$resolved['value'] ?? null,
				'has_error'=>($resolved['state']['has_error'] ?? $definition['meta']['error'] ?? false)===true,
			],
			'interaction'=>[
				'url'=>$definition['url'] ?? null,
				'linked'=>trim((string)($definition['url'] ?? ''))!=='',
			],
			'chart'=>$chart,
			'capabilities'=>self::capabilities($definition, $chart),
			'state'=>$resolved['state'] ?? null,
			'meta'=>array_replace(self::safeMeta(is_array($definition['meta'] ?? null) ? $definition['meta'] : []), $this->meta),
		];
		PanelTrace::record('widget.manifest.described', [
			'name'=>$manifest['name'],
			'type'=>$manifest['widget_type'],
			'lazy'=>$manifest['data']['lazy'],
			'chart'=>$manifest['capabilities']['chart']['enabled'],
			'resolved'=>$manifest['data']['resolved'],
		]);
		return $manifest;
	}

	/**
	 * Optionally resolves live widget state for runtime manifests.
	 *
	 * Resolution failures are captured as warning-state payloads so a dashboard
	 * or manifest render can show the widget contract even when data is unavailable.
	 *
	 * @param array<string,mixed> $definition Static widget definition.
	 * @return ?array<string,mixed> Resolved widget state, warning fallback, or null when resolution is disabled.
	 */
	private function resolved(array $definition): ?array {
		if(!$this->resolve || !$this->widget instanceof Widget){
			return null;
		}
		try{
			return $this->widget->state($this->request, [
				'scope'=>$this->meta['scope'] ?? 'manifest',
				'surface'=>$this->meta['surface'] ?? 'widget_manifest',
			])->jsonSerialize();
		}
		catch(\Throwable $exception){
			return array_replace($definition, [
				'value'=>'Unavailable',
				'tone'=>'warning',
				'state'=>[
					'has_error'=>true,
					'meta'=>['error_message'=>$exception->getMessage()],
				],
			]);
		}
	}

	/**
	 * Extracts chart metadata from widget definition, meta, and resolved state.
	 *
	 * @param array<string,mixed> $definition Static or resolved widget definition.
	 * @return array<string,mixed> Chart manifest payload.
	 */
	private static function chart(array $definition): array {
		$type=Resource::normalizeName((string)($definition['type'] ?? 'stat')) ?: 'stat';
		$meta=is_array($definition['meta'] ?? null) ? $definition['meta'] : [];
		$stateChart=is_array($definition['state']['chart'] ?? null) ? $definition['state']['chart'] : [];
		$enabled=in_array($type, ['chart', 'trend'], true) || $stateChart!==[] || isset($meta['chart_type']) || isset($meta['datasets']) || isset($meta['data']);
		$chartType=Resource::normalizeName((string)($stateChart['type'] ?? $meta['chart_type'] ?? $meta['type'] ?? ($type==='trend' ? 'line' : '')));
		if($chartType===''){
			$chartType='line';
		}
		$datasets=is_array($stateChart['datasets'] ?? null) ? $stateChart['datasets'] : (is_array($meta['datasets'] ?? null) ? $meta['datasets'] : []);
		$labels=is_array($stateChart['labels'] ?? null) ? $stateChart['labels'] : (is_array($meta['labels'] ?? null) ? $meta['labels'] : []);
		$data=is_array($meta['data'] ?? null) ? $meta['data'] : [];
		return [
			'enabled'=>$enabled,
			'type'=>$enabled ? $chartType : null,
			'height'=>(int)($stateChart['height'] ?? $meta['height'] ?? 0),
			'label_count'=>count($labels),
			'dataset_count'=>count($datasets),
			'point_count'=>(int)($stateChart['point_count'] ?? self::pointCount($datasets, $data)),
			'data_dynamic'=>self::containsCallable($meta['data'] ?? null) || self::containsCallable($meta['datasets'] ?? null) || self::containsCallable($meta['labels'] ?? null),
			'datasets'=>self::datasetManifests($datasets),
		];
	}

	/**
	 * Summarizes display, data, interaction, and chart capabilities.
	 *
	 * @param array<string,mixed> $definition Widget definition array.
	 * @param array<string,mixed> $chart Chart manifest payload.
	 * @return array<string,mixed> Capability summary payload.
	 */
	private static function capabilities(array $definition, array $chart): array {
		$type=Resource::normalizeName((string)($definition['type'] ?? 'stat')) ?: 'stat';
		return [
			'display'=>[
				'stat'=>$type==='stat',
				'chart'=>($chart['enabled'] ?? false)===true,
				'trend'=>$type==='trend',
				'custom'=>!in_array($type, ['stat', 'chart', 'trend'], true),
			],
			'data'=>[
				'lazy'=>($definition['lazy'] ?? false)===true,
				'static_value'=>($definition['lazy'] ?? false)!==true && array_key_exists('value', $definition),
				'dynamic_chart'=>($chart['data_dynamic'] ?? false)===true,
				'has_error_flag'=>($definition['meta']['error'] ?? false)===true,
			],
			'interaction'=>[
				'linked'=>trim((string)($definition['url'] ?? ''))!=='',
			],
			'chart'=>[
				'enabled'=>($chart['enabled'] ?? false)===true,
				'datasets'=>(int)($chart['dataset_count'] ?? 0),
				'points'=>(int)($chart['point_count'] ?? 0),
			],
		];
	}

	/**
	 * Normalizes chart datasets into documentation-safe summaries.
	 *
	 * @param list<array<string,mixed>>|array<int|string,mixed> $datasets Chart dataset definitions.
	 * @return list<array{label:string,tone:string,point_count:int,dynamic:bool}> Dataset manifest rows.
	 */
	private static function datasetManifests(array $datasets): array {
		$rows=[];
		foreach($datasets as $index=>$dataset){
			if(!is_array($dataset)){
				continue;
			}
			$values=$dataset['values'] ?? $dataset['data'] ?? [];
			$rows[]=[
				'label'=>(string)($dataset['label'] ?? 'Dataset '.($index+1)),
				'tone'=>(string)($dataset['tone'] ?? 'primary'),
				'point_count'=>is_array($values) ? count(array_filter($values, 'is_numeric')) : 0,
				'dynamic'=>self::containsCallable($values),
			];
		}
		return $rows;
	}

	/**
	 * Calculates the largest numeric point count across datasets or flat data.
	 *
	 * @param list<array<string,mixed>>|array<int|string,mixed> $datasets Dataset definitions.
	 * @param array<int|string,mixed> $data Flat chart data fallback.
	 * @return int Maximum numeric point count.
	 */
	private static function pointCount(array $datasets, array $data): int {
		$count=0;
		foreach($datasets as $dataset){
			if(!is_array($dataset)){
				continue;
			}
			$values=$dataset['values'] ?? $dataset['data'] ?? [];
			if(is_array($values)){
				$count=max($count, count(array_filter($values, 'is_numeric')));
			}
		}
		if($count===0 && $data!==[]){
			$count=count(array_filter($data, 'is_numeric'));
		}
		return $count;
	}

	/**
	 * Removes executable values from widget metadata before manifest emission.
	 *
	 * @param array<string,mixed> $meta Raw widget metadata.
	 * @return array<string,mixed> Metadata containing only scalars, nulls, arrays, and callable markers.
	 */
	private static function safeMeta(array $meta): array {
		$safe=[];
		foreach($meta as $key=>$value){
			$key=(string)$key;
			if($value instanceof \Closure || is_callable($value)){
				$safe[$key]=['dynamic'=>true];
				continue;
			}
			if(is_array($value)){
				$safe[$key]=self::safeMeta($value);
				continue;
			}
			if(is_scalar($value) || $value===null){
				$safe[$key]=$value;
			}
		}
		return $safe;
	}

	/**
	 * Detects callable values nested inside chart or metadata payloads.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool True when the value or any nested value is callable.
	 */
	private static function containsCallable(mixed $value): bool {
		if($value instanceof \Closure || is_callable($value)){
			return true;
		}
		if(!is_array($value)){
			return false;
		}
		foreach($value as $nested){
			if(self::containsCallable($nested)){
				return true;
			}
		}
		return false;
	}

	/**
	 * Converts widget machine names into display labels for fallbacks.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Widget when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Widget' : ucwords($value);
	}
}
