<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Clone-on-write definition for one panel global-search source.
 *
 * A search provider describes how an operator-facing search surface should
 * label a source, decide visibility for the current request, execute a lazy
 * search callback, and normalize provider-specific rows into a shared result
 * payload for panel clients.
 */
final class PanelSearchProvider {

	/** @var string Normalized provider key used as result source/resource identifier. */
	private string $name;
	/** @var string Human-readable source label shown in search UI. */
	private string $label;
	/** @var ?string Optional provider description for catalogs or empty states. */
	private ?string $description=null;
	/** @var ?string Optional icon identifier inherited by results without their own icon. */
	private ?string $icon=null;
	/** @var int Sort weight used when ordering providers. */
	private int $sort=100;
	/** @var int Maximum result count requested from the provider callback. */
	private int $limit=5;
	/** @var bool True when the provider is hard-hidden before lazy visibility checks. */
	private bool $hidden=false;
	/** @var array<string, mixed> Provider metadata emitted to search clients. */
	private array $meta=[];
	/** @var ?\Closure Lazy search callback receiving query, request, provider, limit, and manager. */
	private ?\Closure $searchHandler=null;
	/** @var ?\Closure Lazy visibility callback receiving request, provider, and manager. */
	private ?\Closure $visibilityResolver=null;

	/**
	 * Creates a provider with normalized identity and default label.
	 *
	 * @param string $name Provider name before panel resource normalization.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Starts a search provider definition.
	 *
	 * @param string $name Provider name used for source identity and default label generation.
	 * @return self Provider definition with normalized name.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Builds a provider from an associative definition array.
	 *
	 * Supported keys are `name`, `label`, `description`, `icon`, `sort`, `limit`,
	 * `handler` or `search`, `hidden`, and `meta`. Callable search declarations
	 * are wrapped through {@see searchUsing()} so array and fluent definitions
	 * share callback semantics.
	 *
	 * @param array<string, mixed> $definition Provider definition payload.
	 * @return self Provider definition built from supported keys.
	 */
	public static function fromArray(array $definition): self {
		$provider=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'description', 'icon'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$provider=$provider->{$key}($definition[$key]);
			}
		}
		if(isset($definition['sort'])){
			$provider=$provider->sort((int)$definition['sort']);
		}
		if(isset($definition['limit'])){
			$provider=$provider->limit((int)$definition['limit']);
		}
		if(isset($definition['handler']) && is_callable($definition['handler'])){
			$provider=$provider->searchUsing($definition['handler']);
		}
		if(isset($definition['search']) && is_callable($definition['search'])){
			$provider=$provider->searchUsing($definition['search']);
		}
		if(!empty($definition['hidden'])){
			$provider=$provider->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$provider=$provider->meta($definition['meta']);
		}
		return $provider;
	}

	/**
	 * Reads the normalized provider name.
	 *
	 * @return string Provider key used as result source/resource identity.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a copy with a custom display label.
	 *
	 *
	 * @param string $label Human-readable label for this search source.
	 * @return self Cloned provider with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a copy with optional provider description text.
	 *
	 *
	 * @param string $description Description for provider catalogs or search UI.
	 * @return self Cloned provider with trimmed description or null when blank.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with an icon identifier.
	 *
	 *
	 * @param string $icon Icon identifier inherited by normalized results without their own icon.
	 * @return self Cloned provider with trimmed icon or null when blank.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with provider ordering weight.
	 *
	 *
	 * @param int $sort Sort weight used by provider catalogs.
	 * @return self Cloned provider with updated sort weight.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a copy with a per-provider result limit.
	 *
	 * Limits are clamped to the inclusive range 1..50 so provider callbacks and
	 * clients share a bounded result contract.
	 *
	 * @param int $limit Desired result limit.
	 * @return self Cloned provider with clamped limit.
	 */
	public function limit(int $limit): self {
		$clone=clone $this;
		$clone->limit=max(1, min(50, $limit));
		return $clone;
	}

	/**
	 * Returns a copy with a lazy search callback.
	 *
	 * The callback receives `(string $query, PanelRequest $request,
	 * PanelSearchProvider $provider, int $limit, ?PanelManager $manager)` and
	 * should return an array of result-like arrays.
	 *
	 * @param callable $handler Provider search callback.
	 * @return self Cloned provider with search handler attached.
	 */
	public function searchUsing(callable $handler): self {
		$clone=clone $this;
		$clone->searchHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Returns a copy with hard-hidden state toggled.
	 *
	 * Hidden providers never invoke their lazy visibility resolver.
	 *
	 * @param bool $hidden True to hide the provider from search catalogs.
	 * @return self Cloned provider with hidden state updated.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a copy with a lazy visibility resolver.
	 *
	 * Resolver exceptions are traced and treated as not visible so operator
	 * search surfaces degrade safely.
	 *
	 * @param callable $resolver Callback receiving request, provider, and manager.
	 * @return self Cloned provider with visibility resolver attached.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a copy with merged provider metadata.
	 *
	 * Metadata is shallow-merged so later calls replace existing keys while
	 * preserving unrelated metadata.
	 *
	 * @param array<string, mixed> $meta Provider metadata to merge.
	 * @return self Cloned provider with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Determines whether this provider should be available for a request.
	 *
	 * Hard-hidden providers are rejected immediately. Providers without a lazy
	 * resolver are visible by default. Resolver failures are recorded to
	 * {@see PanelTrace} and return false.
	 *
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?PanelManager $manager Panel manager supplying runtime context.
	 * @return bool True when the provider should appear in search.
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
			PanelTrace::record('search_provider.visibility_error', [
				'provider'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Executes the provider search callback and normalizes result rows.
	 *
	 * Blank queries and providers without callbacks return no results. Callback
	 * exceptions are traced and converted to an empty result list to keep the
	 * operator search response usable.
	 *
	 * @param string $query Operator search query.
	 * @param PanelRequest $request Current panel request.
	 * @param ?PanelManager $manager Panel manager supplying runtime context.
	 * @param ?int $limit Optional per-call result limit overriding the provider default.
	 * @return array<int, array<string, mixed>> Normalized search results capped by limit.
	 */
	public function search(string $query, PanelRequest $request, ?PanelManager $manager=null, ?int $limit=null): array {
		$query=trim($query);
		if($query==='' || $this->searchHandler===null){
			return [];
		}
		$limit=max(1, min(50, $limit ?? $this->limit));
		try{
			$results=($this->searchHandler)($query, $request, $this, $limit, $manager);
			return $this->normalizeResults(is_array($results) ? $results : [], $limit);
		}
		catch(\Throwable $exception){
			PanelTrace::record('search_provider.error', [
				'provider'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Serializes provider configuration without executing lazy callbacks.
	 *
	 * `visible_lazy` and `search_lazy` expose whether callbacks are attached
	 * without serializing executable closures.
	 *
	 * @return array{name: string, label: string, description: ?string, icon: ?string, sort: int, limit: int, hidden: bool, visible_lazy: bool, search_lazy: bool, meta: array<string, mixed>} Provider descriptor.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'icon'=>$this->icon,
			'sort'=>$this->sort,
			'limit'=>$this->limit,
			'hidden'=>$this->hidden,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'search_lazy'=>$this->searchHandler!==null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes provider-specific result rows into the shared panel shape.
	 *
	 * Rows without a title or label are ignored. Each result receives provider
	 * identity, source labels, display text, record key, URL, icon, and metadata
	 * fields expected by panel clients.
	 *
	 * @param array<int|string, mixed> $results Raw callback results.
	 * @param int $limit Maximum normalized rows to emit.
	 * @return array<int, array{resource: string, resource_label: string, source: string, source_label: string, title: string, subtitle: string, record_key: string, url: string, icon: string, meta: array<string, mixed>}> Normalized result rows.
	 */
	private function normalizeResults(array $results, int $limit): array {
		$normalized=[];
		foreach($results as $result){
			if(!is_array($result)){
				continue;
			}
			$title=trim((string)($result['title'] ?? $result['label'] ?? ''));
			if($title===''){
				continue;
			}
			$normalized[]=[
				'resource'=>$this->name,
				'resource_label'=>(string)($result['resource_label'] ?? $result['source_label'] ?? $this->label),
				'source'=>$this->name,
				'source_label'=>(string)($result['source_label'] ?? $result['resource_label'] ?? $this->label),
				'title'=>$title,
				'subtitle'=>(string)($result['subtitle'] ?? $result['description'] ?? ''),
				'record_key'=>trim((string)($result['record_key'] ?? $result['key'] ?? '')),
				'url'=>trim((string)($result['url'] ?? $result['href'] ?? '')),
				'icon'=>(string)($result['icon'] ?? $this->icon ?? ''),
				'meta'=>is_array($result['meta'] ?? null) ? $result['meta'] : [],
			];
			if(count($normalized)>=$limit){
				break;
			}
		}
		return $normalized;
	}

	/**
	 * Converts a normalized provider key into a default display label.
	 *
	 * @param string $value Provider key to humanize.
	 * @return string Title-cased label with separators converted to spaces.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
