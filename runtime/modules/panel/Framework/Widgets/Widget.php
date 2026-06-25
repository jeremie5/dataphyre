<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a panel dashboard widget.
 *
 * Widgets describe stat cards, charts, trends, and other dashboard summaries.
 * Mutator methods clone the definition and return the modified copy, allowing
 * widget definitions to be reused safely. Values and chart metadata may be
 * static or lazy callables evaluated against the current `PanelRequest`.
 */
final class Widget {
	use PanelExtensible;

	private string $name;
	private string $type='stat';
	private string $label;
	private mixed $value=null;
	private ?\Closure $valueResolver=null;
	private ?string $description=null;
	private string $tone='neutral';
	private ?string $icon=null;
	private ?string $url=null;
	private ?string $group=null;
	private int $sort=100;
	private array $meta=[];

	/**
	 * Creates a normalized widget definition.
	 *
	 * @param string $name Widget identifier before panel-name normalization.
	 * @param string $type Widget type, defaulting to `stat` when blank.
	 */
	private function __construct(string $name, string $type='stat') {
		$this->name=Resource::normalizeName($name);
		$this->type=Resource::normalizeName($type) ?: 'stat';
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured widget definition.
	 *
	 * Extension hooks run after the base immutable definition is created so packages can add defaults without mutating caller state.
	 *
	 * @param string $name Widget identifier before panel-name normalization.
	 * @param string $type Widget type before normalization; blank input falls back to `stat`.
	 * @return self Widget definition after extension hooks have been applied.
	 */
	public static function make(string $name, string $type='stat'): self {
		return self::configured(new self($name, $type));
	}

	/**
	 * Creates a widget definition from an array manifest.
	 *
	 * Supported keys mirror the fluent builder: name, type, label, value,
	 * description, tone, icon, url, group, sort, and meta.
	 *
	 * @param array<string,mixed> $definition Widget definition data.
	 * @return self Widget definition described by the array.
	 */
	public static function fromArray(array $definition): self {
		$widget=self::make((string)($definition['name'] ?? ''), (string)($definition['type'] ?? 'stat'));
		if(isset($definition['label'])){
			$widget=$widget->label((string)$definition['label']);
		}
		if(array_key_exists('value', $definition)){
			$widget=$widget->value($definition['value']);
		}
		foreach(['description', 'tone', 'icon', 'url', 'group'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$widget=$widget->{$key}($definition[$key]);
			}
		}
		if(isset($definition['sort'])){
			$widget=$widget->sort((int)$definition['sort']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$widget=$widget->meta($definition['meta']);
		}
		return $widget;
	}

	/**
	 * Returns the normalized widget name.
	 *
	 *
	 * @return string Widget identifier.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a copy with a custom display label.
	 *
	 * Blank labels are preserved as an explicit empty display label rather than falling back to the humanized name.
	 *
	 * @param string $label Display label shown by dashboard renderers.
	 * @return self Cloned widget with the requested label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a copy with a normalized widget type.
	 *
	 *
	 * @return self Cloned widget with the requested type, or `stat` for blank input.
	 */
	public function type(string $type): self {
		$clone=clone $this;
		$clone->type=Resource::normalizeName($type) ?: 'stat';
		return $clone;
	}

	/**
	 * Returns a copy with a static or lazy widget value.
	 *
	 * Callable values are stored as lazy resolvers and evaluated during
	 * `resolve()`. Non-callable values are stored directly and serialized by
	 * `toArray()`.
	 *
	 * @param mixed $value Static value or callable value resolver.
	 * @return self Cloned widget with updated value behavior.
	 */
	public function value(mixed $value): self {
		$clone=clone $this;
		if(is_callable($value)){
			$clone->valueResolver=\Closure::fromCallable($value);
			$clone->value=null;
			return $clone;
		}
		$clone->value=$value;
		$clone->valueResolver=null;
		return $clone;
	}

	/**
	 * Returns a copy with optional supporting description text.
	 *
	 * Blank descriptions are normalized to null so the renderer can omit the secondary text region.
	 *
	 * @param string $description Supporting description text.
	 * @return self Cloned widget with description text or null for blank input.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with a normalized visual tone.
	 *
	 * Unsupported tones fall back to `neutral`.
	 *
	 * @param string $tone Requested tone name.
	 * @return self Cloned widget with normalized tone.
	 */
	public function tone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->tone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Returns a copy with an optional icon identifier.
	 *
	 * Blank identifiers are normalized to null so no icon is emitted.
	 *
	 * @param string $icon Icon identifier consumed by the renderer.
	 * @return self Cloned widget with icon identifier or null for blank input.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with an optional target URL.
	 *
	 * The URL is stored as provided after trimming; routing and safety checks live
	 * with the renderer or caller that emits the link.
	 *
	 * @param string $url Target URL shown by the renderer.
	 * @return self Cloned widget with URL or null for blank input.
	 */
	public function url(string $url): self {
		$clone=clone $this;
		$clone->url=trim($url) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy assigned to a dashboard widget group.
	 *
	 * Blank groups are normalized to null, leaving grouping to the dashboard layout.
	 *
	 * @param string $group Dashboard group label or key.
	 * @return self Cloned widget with group label or null for blank input.
	 */
	public function group(string $group): self {
		$clone=clone $this;
		$clone->group=trim($group) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with a dashboard sort weight.
	 *
	 * Lower values sort earlier when dashboard renderers order widgets.
	 *
	 * @param int $sort Dashboard ordering weight.
	 * @return self Cloned widget with sort weight.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a copy with merged metadata.
	 *
	 * Metadata is shallow-merged into the existing metadata map. Nested callable
	 * metadata is evaluated during `resolve()`.
	 *
	 * @param array<string,mixed> $meta Metadata to merge.
	 * @return self Cloned widget with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Returns a copy configured as a chart widget.
	 *
	 * Chart type is normalized and stored in metadata while the widget type is set to `chart`.
	 *
	 * @param string $chartType Requested chart renderer type.
	 * @return self Cloned chart widget with chart type metadata.
	 */
	public function chart(string $chartType='line'): self {
		return $this->type('chart')->meta(['chart_type'=>Resource::normalizeName($chartType) ?: 'line']);
	}

	/**
	 * Returns a copy with chart data metadata.
	 *
	 * Callable data is stored in metadata and resolved later with the current panel request and widget.
	 *
	 * @param array|callable $data Static chart data or lazy data resolver.
	 * @return self Cloned widget with chart data metadata.
	 */
	public function data(array|callable $data): self {
		return $this->meta(['data'=>$data]);
	}

	/**
	 * Returns a copy with chart label metadata.
	 *
	 * Callable labels are stored in metadata and resolved later with the current panel request and widget.
	 *
	 * @param array|callable $labels Static labels or lazy label resolver.
	 * @return self Cloned widget with chart labels metadata.
	 */
	public function labels(array|callable $labels): self {
		return $this->meta(['labels'=>$labels]);
	}

	/**
	 * Returns a copy with an appended chart dataset.
	 *
	 * Dataset values may be static or lazy. Tone is normalized and falls back to
	 * `primary` for unsupported values.
	 *
	 * @param string $label Dataset label shown in chart legends.
	 * @param array|callable $values Static values or lazy values resolver.
	 * @param string $tone Dataset tone before normalization.
	 * @return self Cloned widget with the dataset appended.
	 */
	public function dataset(string $label, array|callable $values, string $tone='primary'): self {
		$datasets=$this->meta['datasets'] ?? [];
		if(!is_array($datasets)){
			$datasets=[];
		}
		$tone=Resource::normalizeName($tone);
		$datasets[]=[
			'label'=>trim($label),
			'values'=>$values,
			'tone'=>in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'primary',
		];
		return $this->meta(['datasets'=>$datasets]);
	}

	/**
	 * Returns a copy with chart height metadata.
	 *
	 * Heights are clamped between 120 and 420 pixels to keep dashboard cards
	 * within the expected panel layout range.
	 *
	 * @param int $height Requested chart height in pixels.
	 * @return self Cloned widget with clamped height metadata.
	 */
	public function height(int $height): self {
		return $this->meta(['height'=>max(120, min(420, $height))]);
	}

	/**
	 * Returns a copy with a display unit metadata value.
	 *
	 * The unit string is trimmed and stored as renderer metadata.
	 *
	 * @param string $unit Display unit suffix or label.
	 * @return self Cloned widget with unit metadata.
	 */
	public function unit(string $unit): self {
		return $this->meta(['unit'=>trim($unit)]);
	}

	/**
	 * Resolves lazy widget value and metadata for a request.
	 *
	 * Value resolver exceptions are traced and converted into a warning widget
	 * state so dashboard rendering can continue. Metadata closures are resolved
	 * recursively by `resolveMeta()`.
	 *
	 * @param ?PanelRequest $request Request used to resolve lazy value and metadata callbacks.
	 * @return array<string,mixed> Resolved widget data.
	 */
	public function resolve(?PanelRequest $request=null): array {
		$data=$this->toArray();
		if($this->valueResolver===null){
			$data['value']=$this->value;
			$data['meta']=self::resolveMeta($data['meta'], $request, $this);
			return $data;
		}
		try{
			$data['value']=PanelUtilityResolver::evaluate($this->valueResolver, [
				'request'=>$request,
				'widget'=>$this,
			], ['request', 'widget']);
		}
		catch(\Throwable $exception){
			PanelTrace::record('widget.error', [
				'widget'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			$data['value']='Unavailable';
			$data['tone']='warning';
			$data['meta']=array_replace($data['meta'], ['error'=>true]);
		}
		$data['meta']=self::resolveMeta($data['meta'], $request, $this);
		return $data;
	}

	/**
	 * Returns typed runtime state for this widget.
	 *
	 * @param ?PanelRequest $request Request used to resolve lazy values.
	 * @param array<string,mixed> $meta Additional state metadata.
	 * @return PanelWidgetState Resolved widget state object.
	 */
	public function state(?PanelRequest $request=null, array $meta=[]): PanelWidgetState {
		return PanelWidgetState::make($this, $this->resolve($request), $request, $meta);
	}

	/**
	 * Returns the widget manifest used by rendering and diagnostics.
	 *
	 * @param ?PanelRequest $request Request used when resolving lazy values.
	 * @param array<string,mixed> $meta Additional manifest metadata.
	 * @param bool $resolve True when lazy values should be resolved before manifest export.
	 * @return array<string,mixed> Widget manifest data.
	 */
	public function manifest(?PanelRequest $request=null, array $meta=[], bool $resolve=false): array {
		return WidgetManifest::from($this, $request, $meta, $resolve)->toArray();
	}

	/**
	 * Recursively resolves callable metadata values.
	 *
	 * Metadata resolver exceptions are traced and converted to null values so a
	 * single failed metadata field does not prevent dashboard rendering.
	 *
	 * @param array<string,mixed> $meta Metadata map to resolve.
	 * @param ?PanelRequest $request Current panel request.
	 * @param self $widget Widget being resolved.
	 * @return array<string,mixed> Metadata with callables evaluated.
	 */
	private static function resolveMeta(array $meta, ?PanelRequest $request, self $widget): array {
		foreach($meta as $key=>$value){
			if($value instanceof \Closure){
				try{
					$meta[$key]=PanelUtilityResolver::evaluate($value, [
						'request'=>$request,
						'widget'=>$widget,
						'meta'=>$meta,
						'key'=>(string)$key,
					], ['request', 'widget']);
				}
				catch(\Throwable $exception){
					PanelTrace::record('widget.meta_error', [
						'widget'=>$widget->name,
						'meta_key'=>(string)$key,
						'message'=>$exception->getMessage(),
					]);
					$meta[$key]=null;
				}
			}
			elseif(is_array($value)){
				$meta[$key]=self::resolveMeta($value, $request, $widget);
			}
		}
		return $meta;
	}

	/**
	 * Returns static widget definition data without invoking lazy resolvers.
	 *
	 * Lazy values are represented as null with `lazy` set to true. Use `resolve()`
	 * when callers need evaluated values and metadata.
	 *
	 * @return array<string,mixed> Static widget definition data.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'type'=>$this->type,
			'label'=>$this->label,
			'value'=>$this->valueResolver===null ? $this->value : null,
			'lazy'=>$this->valueResolver!==null,
			'description'=>$this->description,
			'tone'=>$this->tone,
			'icon'=>$this->icon,
			'url'=>$this->url,
			'group'=>$this->group,
			'sort'=>$this->sort,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts a normalized widget name into a default display label.
	 *
	 * @param string $value Widget name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
