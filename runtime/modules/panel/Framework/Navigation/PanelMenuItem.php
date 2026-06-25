<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Clone-on-write definition for one operator panel menu item.
 *
 * Menu items describe navigation metadata, optional lazy URL resolution,
 * visibility rules, tone, sort order, and arbitrary client metadata. Builder
 * methods return clones so shared item prototypes can be reused safely across
 * panel registries.
 */
final class PanelMenuItem {

	/** @var string Normalized item key used by registries and traces. */
	private string $name;
	/** @var string Display label shown in navigation UI. */
	private string $label;
	/** @var ?string Optional supporting text for richer menu surfaces. */
	private ?string $description=null;
	/** @var ?string Optional icon identifier rendered by panel clients. */
	private ?string $icon=null;
	/** @var ?string Static URL used when no lazy URL resolver is attached. */
	private ?string $url=null;
	/** @var bool True when clients should open the item in a new tab. */
	private bool $newTab=false;
	/** @var bool True when the item is hard-hidden before lazy visibility checks. */
	private bool $hidden=false;
	/** @var int Sort weight used by menu collections. */
	private int $sort=100;
	/** @var string Visual tone constrained to the panel tone palette. */
	private string $tone='neutral';
	/** @var array<string, mixed> Additional metadata emitted with the menu payload. */
	private array $meta=[];
	/** @var ?\Closure Lazy visibility callback receiving request, item, and manager. */
	private ?\Closure $visibilityResolver=null;
	/** @var ?\Closure Lazy URL callback receiving request, item, and manager. */
	private ?\Closure $urlResolver=null;

	/**
	 * Creates a menu item with normalized identity and default label.
	 *
	 * @param string $name Menu item name before panel resource normalization.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Starts a menu item definition.
	 *
	 * @param string $name Menu item name used for identity and default label generation.
	 * @return self Menu item definition with normalized name.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Builds a menu item from an associative definition array.
	 *
	 * Supported keys are `name`, `label`, `description`, `icon`, `url`, `tone`,
	 * `sort`, `new_tab`, `hidden`, and `meta`. Array hydration uses the same
	 * fluent setters as manual construction so normalization rules stay aligned.
	 *
	 * @param array<string, mixed> $definition Menu item definition payload.
	 * @return self Menu item built from supported definition keys.
	 */
	public static function fromArray(array $definition): self {
		$item=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'description', 'icon', 'url', 'tone'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$item=$item->{$key}($definition[$key]);
			}
		}
		if(isset($definition['sort'])){
			$item=$item->sort((int)$definition['sort']);
		}
		if(!empty($definition['new_tab'])){
			$item=$item->newTab();
		}
		if(!empty($definition['hidden'])){
			$item=$item->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$item=$item->meta($definition['meta']);
		}
		return $item;
	}

	/**
	 * Reads the normalized menu item name.
	 *
	 * @return string Item key used by registries, traces, and serialized payloads.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a copy with a custom display label.
	 *
	 *
	 * @param string $label Human-readable navigation label.
	 * @return self Cloned item with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a copy with optional supporting description text.
	 *
	 *
	 * @param string $description Supporting text for menu clients.
	 * @return self Cloned item with trimmed description or null when blank.
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
	 * @param string $icon Icon identifier rendered by panel clients.
	 * @return self Cloned item with trimmed icon or null when blank.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with a static URL or lazy URL resolver.
	 *
	 * Static URLs are trimmed and stored directly. Callable URLs are converted to
	 * closures and evaluated during serialization with request, item, and manager
	 * context.
	 *
	 * @param string|callable $url Static URL or resolver callback.
	 * @return self Cloned item with URL source updated.
	 */
	public function url(string|callable $url): self {
		$clone=clone $this;
		if(is_callable($url)){
			$clone->urlResolver=\Closure::fromCallable($url);
			$clone->url=null;
			return $clone;
		}
		$clone->url=trim($url);
		$clone->urlResolver=null;
		return $clone;
	}

	/**
	 * Returns a copy with a normalized visual tone.
	 *
	 * Unsupported tones fall back to `neutral` so panel clients can rely on a
	 * fixed palette.
	 *
	 * @param string $tone Requested tone name.
	 * @return self Cloned item with palette-safe tone.
	 */
	public function tone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->tone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Returns a copy with menu ordering weight.
	 *
	 *
	 * @param int $sort Sort weight used by menu collections.
	 * @return self Cloned item with updated sort weight.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a copy with new-tab behavior toggled.
	 *
	 *
	 * @param bool $newTab True when clients should open the URL in a new tab.
	 * @return self Cloned item with new-tab flag updated.
	 */
	public function newTab(bool $newTab=true): self {
		$clone=clone $this;
		$clone->newTab=$newTab;
		return $clone;
	}

	/**
	 * Returns a copy with hard-hidden state toggled.
	 *
	 * Hidden items never invoke their lazy visibility resolver.
	 *
	 * @param bool $hidden True to hide the item from navigation.
	 * @return self Cloned item with hidden state updated.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a copy with a lazy visibility resolver.
	 *
	 * Resolver exceptions are traced and treated as hidden so navigation payloads
	 * remain safe to render.
	 *
	 * @param callable $resolver Callback receiving request, item, and manager.
	 * @return self Cloned item with visibility resolver attached.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a copy with merged item metadata.
	 *
	 * Metadata is shallow-merged, allowing later calls to replace individual
	 * keys without clearing unrelated metadata.
	 *
	 * @param array<string, mixed> $meta Metadata to merge into the menu payload.
	 * @return self Cloned item with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Determines whether this menu item should appear for a request.
	 *
	 * Hard-hidden items are rejected immediately. Items without a lazy resolver
	 * are visible by default. Resolver failures are recorded to {@see PanelTrace}
	 * and return false.
	 *
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?PanelManager $manager Panel manager supplying runtime context.
	 * @return bool True when the item should appear in navigation.
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
			PanelTrace::record('menu_item.visibility_error', [
				'item'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Serializes the menu item for navigation clients.
	 *
	 * Lazy URL callbacks are evaluated at serialization time. URL callback
	 * failures are traced and serialized as a null URL instead of failing the
	 * whole menu payload.
	 *
	 * @param ?PanelRequest $request Current panel request for lazy URL resolution.
	 * @param ?PanelManager $manager Panel manager for lazy URL resolution.
	 * @return array{name: string, label: string, description: ?string, icon: ?string, url: ?string, new_tab: bool, sort: int, tone: string, hidden: bool, visible_lazy: bool, url_lazy: bool, meta: array<string, mixed>} Menu item payload.
	 */
	public function toArray(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$url=$this->url;
		if($this->urlResolver!==null){
			try{
				$url=(string)($this->urlResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('menu_item.url_error', [
					'item'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$url=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'icon'=>$this->icon,
			'url'=>$url,
			'new_tab'=>$this->newTab,
			'sort'=>$this->sort,
			'tone'=>$this->tone,
			'hidden'=>$this->hidden,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'url_lazy'=>$this->urlResolver!==null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts a normalized menu item key into a default display label.
	 *
	 * @param string $value Item key to humanize.
	 * @return string Title-cased label with common separators converted to spaces.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
