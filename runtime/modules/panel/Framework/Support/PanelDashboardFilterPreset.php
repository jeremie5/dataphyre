<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable builder for reusable dashboard filter presets in the panel UI.
 *
 * A preset captures a named set of dashboard filter values plus label, tone,
 * icon, visibility, current-state detection, and URL generation. Values and
 * current detection may be lazy closures evaluated against the current request
 * and panel manager, with resolver failures traced and degraded to safe output.
 */
final class PanelDashboardFilterPreset {

	private string $name;
	private string $label;
	private ?string $description=null;
	private string $tone='neutral';
	private ?string $icon=null;
	private array $values=[];
	private bool $hidden=false;
	private int $sort=100;
	private array $meta=[];
	private ?\Closure $valuesResolver=null;
	private ?\Closure $visibilityResolver=null;
	private ?\Closure $currentResolver=null;

	/**
	 * Creates a preset with normalized identity and a humanized default label.
	 *
	 * @param string $name Stable preset key used in renderer data, URLs, and traces.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Starts a dashboard filter preset definition.
	*
	 * @param string $name Stable preset key; normalized with `Resource::normalizeName()`.
	 * @return self New preset with default label, neutral tone, and no filter values.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Builds a preset from a dashboard definition array.
	*
	 * Supported keys include `name`, `label`, `description`, `tone`, `icon`,
	 * `values` or `query`, `sort`, `hidden`, and `meta`. Closures are configured
	 * through the fluent API rather than this array path.
	 *
	 * @param array<string, mixed> $definition Preset definition.
	 * @return self Configured dashboard filter preset.
	 */
	public static function fromArray(array $definition): self {
		$preset=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'description', 'tone', 'icon'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$preset=$preset->{$key}($definition[$key]);
			}
		}
		if(isset($definition['values']) && is_array($definition['values'])){
			$preset=$preset->values($definition['values']);
		}
		if(isset($definition['query']) && is_array($definition['query'])){
			$preset=$preset->values($definition['query']);
		}
		if(isset($definition['sort'])){
			$preset=$preset->sort((int)$definition['sort']);
		}
		if(!empty($definition['hidden'])){
			$preset=$preset->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$preset=$preset->meta($definition['meta']);
		}
		return $preset;
	}

	/**
	 * Returns the normalized preset key.
	 *
	 * @return string Stable preset name used in renderer state.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a clone with the operator-facing label replaced.
	*
	 * @param string $label Display label for the preset.
	 * @return self Cloned preset with the trimmed label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a clone with optional help text replaced.
	*
	 * @param string $description Preset description; empty values clear it.
	 * @return self Cloned preset with description metadata.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with a normalized visual tone.
	*
	 * Allowed tones are `neutral`, `primary`, `success`, `warning`, `danger`, and
	 * `info`. Unknown values normalize to `neutral` for renderer safety.
	 *
	 * @param string $tone Requested visual tone.
	 * @return self Cloned preset with a safe tone.
	 */
	public function tone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->tone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Returns a clone with the optional icon identifier replaced.
	*
	 * @param string $icon Panel icon name; empty values clear the icon.
	 * @return self Cloned preset with icon metadata.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with static filter values or a lazy value resolver.
	*
	 * Static values are normalized to scalar query parameters keyed by normalized
	 * filter names. Resolver callbacks receive `(?PanelRequest, self, ?PanelManager)`
	 * during serialization and are normalized the same way.
	 *
	 * @param array<string, mixed>|callable $values Static filter values or resolver callback.
	 * @return self Cloned preset with value behavior.
	 */
	public function values(array|callable $values): self {
		$clone=clone $this;
		if(is_callable($values)){
			$clone->valuesResolver=\Closure::fromCallable($values);
			$clone->values=[];
			return $clone;
		}
		$clone->values=self::normalizeValues($values);
		$clone->valuesResolver=null;
		return $clone;
	}

	/**
	 * Alias for `values()` for configuration that names presets by query fragments.
	*
	 * @param array<string, mixed>|callable $values Static query values or resolver callback.
	 * @return self Cloned preset with value behavior.
	 */
	public function query(array|callable $values): self {
		return $this->values($values);
	}

	/**
	 * Returns a clone with custom current-state detection.
	*
	 * The resolver receives `(?PanelRequest, self, ?PanelManager, array $values)`.
	 * Exceptions are traced and treated as not current.
	 *
	 * @param callable $resolver Current-state resolver.
	 * @return self Cloned preset with custom current detection.
	 */
	public function currentUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->currentResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a clone with the static hidden flag changed.
	*
	 * @param bool $hidden Whether the preset should be suppressed before resolver checks.
	 * @return self Cloned preset with static visibility changed.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a clone with lazy visibility logic.
	*
	 * The resolver receives `(?PanelRequest, self, ?PanelManager)`. Exceptions are
	 * traced and treated as invisible to protect dashboard rendering.
	 *
	 * @param callable $resolver Visibility resolver.
	 * @return self Cloned preset with lazy visibility behavior.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a clone with the dashboard display sort weight replaced.
	*
	 * @param int $sort Lower values appear earlier in preset lists.
	 * @return self Cloned preset with updated sort order.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a clone with additional renderer metadata merged.
	*
	 * Later calls override existing keys while preserving unrelated metadata.
	 *
	 * @param array<string, mixed> $meta Renderer or extension metadata.
	 * @return self Cloned preset with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Evaluates whether this preset should be included for the current dashboard.
	*
	 * Static hidden presets are always excluded. Lazy resolver failures are traced
	 * under `dashboard_filter_preset.visibility_error` and treated as invisible.
	 *
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?PanelManager $manager Active panel manager, when available.
	 * @return bool `true` when the preset should be rendered.
	 */
	public function isVisible(?PanelRequest $request=null, ?PanelManager $manager=null): bool {
		if($this->hidden){
			return false;
		}
		if($this->visibilityResolver===null){
			return true;
		}
		try{
			return (bool)($this->visibilityResolver)($request, $this, $manager);
		}
		catch(\Throwable $exception){
			PanelTrace::record('dashboard_filter_preset.visibility_error', [
				'preset'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Resolves dynamic values and exports the preset for dashboard rendering.
	 *
	 * @param ?PanelRequest $request Current panel request used for URL and resolver context.
	 * @param ?PanelManager $manager Active panel manager used for filter cleanup and resolver context.
	 * @return array{name:string,label:string,description:?string,tone:string,icon:?string,values:array,url:string,current:bool,sort:int,hidden:bool,values_lazy:bool,visible_lazy:bool,current_lazy:bool,meta:array}
	 */
	public function toArray(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$values=$this->resolvedValues($request, $manager);
		$url=$this->url($values, $request, $manager);
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'tone'=>$this->tone,
			'icon'=>$this->icon,
			'values'=>$values,
			'url'=>$url,
			'current'=>$this->isCurrent($values, $request, $manager),
			'sort'=>$this->sort,
			'hidden'=>$this->hidden,
			'values_lazy'=>$this->valuesResolver!==null,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'current_lazy'=>$this->currentResolver!==null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Resolves and normalizes the preset's query values for the current request.
	 *
	 * Resolver exceptions are traced under `dashboard_filter_preset.values_error`
	 * and degrade to an empty value set, which represents the "clear filters"
	 * preset behavior.
	 *
	 * @param ?PanelRequest $request Current panel request passed to lazy resolvers.
	 * @param ?PanelManager $manager Active panel manager passed to lazy resolvers.
	 * @return array<string, scalar|null> Normalized query values.
	 */
	private function resolvedValues(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		if($this->valuesResolver===null){
			return $this->values;
		}
		try{
			$values=($this->valuesResolver)($request, $this, $manager);
			return is_array($values) ? self::normalizeValues($values) : [];
		}
		catch(\Throwable $exception){
			PanelTrace::record('dashboard_filter_preset.values_error', [
				'preset'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Determines whether the current dashboard query matches this preset's values.
	 *
	 * Empty presets are current only when none of the known dashboard filter query
	 * fields are populated. Non-empty presets require exact string equality for
	 * each normalized value.
	 *
	 * @param array<string, scalar|null> $values Resolved preset values.
	 * @param ?PanelRequest $request Current panel request.
	 * @param ?PanelManager $manager Active panel manager.
	 * @return bool `true` when the request query represents this preset.
	 */
	private function isCurrent(array $values, ?PanelRequest $request=null, ?PanelManager $manager=null): bool {
		if($this->currentResolver!==null){
			try{
				return (bool)($this->currentResolver)($request, $this, $manager, $values);
			}
			catch(\Throwable $exception){
				PanelTrace::record('dashboard_filter_preset.current_error', [
					'preset'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				return false;
			}
		}
		$query=$request instanceof PanelRequest ? $request->query() : [];
		$filterNames=$manager instanceof PanelManager ? array_keys($manager->dashboardFilters()) : array_keys($values);
		if($values===[]){
			foreach($filterNames as $name){
				if(trim((string)($query[$name] ?? ''))!=='' || trim((string)($query[$name.'_from'] ?? ''))!=='' || trim((string)($query[$name.'_to'] ?? ''))!==''){
					return false;
				}
			}
			return true;
		}
		foreach($values as $key=>$value){
			if((string)($query[$key] ?? '')!==(string)$value){
				return false;
			}
		}
		return true;
	}

	/**
	 * Builds the dashboard URL that applies this preset's values.
	 *
	 * Resource/action/pagination fields and existing dashboard filters are removed
	 * before preset values are applied, producing a clean filter URL for the panel
	 * dashboard instead of carrying stale view state forward.
	 *
	 * @param array<string, scalar|null> $values Resolved preset values.
	 * @param ?PanelRequest $request Current panel request.
	 * @param ?PanelManager $manager Active panel manager used to identify filter names.
	 * @return string Panel URL for applying the preset.
	 */
	private function url(array $values, ?PanelRequest $request=null, ?PanelManager $manager=null): string {
		$query=$request instanceof PanelRequest ? $request->query() : [];
		unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action'], $query['page']);
		if($manager instanceof PanelManager){
			foreach(array_keys($manager->dashboardFilters()) as $name){
				unset($query[$name], $query[$name.'_from'], $query[$name.'_to']);
			}
		}
		foreach($values as $key=>$value){
			$key=Resource::normalizeName((string)$key);
			if($key===''){
				continue;
			}
			if($value===null || (is_scalar($value) && trim((string)$value)==='')){
				unset($query[$key]);
				continue;
			}
			if(is_scalar($value)){
				$query[$key]=$value;
			}
		}
		return PanelConfig::url('', $query);
	}

	/**
	 * Filters a preset value map down to scalar query parameters with normalized keys.
	 *
	 * Arrays and objects are ignored because dashboard filter URLs can only carry
	 * scalar query values through the current panel URL builder.
	 *
	 * @param array<string|int, mixed> $values Raw preset values.
	 * @return array<string, scalar|null> Normalized query value map.
	 */
	private static function normalizeValues(array $values): array {
		$normalized=[];
		foreach($values as $key=>$value){
			$key=Resource::normalizeName((string)$key);
			if($key==='' || is_array($value) || is_object($value)){
				continue;
			}
			$normalized[$key]=$value;
		}
		return $normalized;
	}

	/**
	 * Converts normalized identifiers into default operator-facing labels.
	 *
	 * @param string $value Normalized resource-style identifier.
	 * @return string Title-cased label with separators converted to spaces.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
