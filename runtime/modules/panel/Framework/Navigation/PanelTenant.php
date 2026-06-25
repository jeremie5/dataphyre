<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes one tenant choice exposed by a panel tenant switcher.
 *
 * PanelTenant is a clone-on-write builder for tenant navigation metadata. It stores display labels, iconography, target URLs, badge values, current-state rules, visibility rules, sort order, and metadata before resolving lazy values into panel rendering data.
 */
final class PanelTenant {

	private string $name;
	private string $label;
	private ?string $description=null;
	private ?string $icon=null;
	private ?string $url=null;
	private mixed $badge=null;
	private string $badgeTone='neutral';
	private bool $current=false;
	private bool $hidden=false;
	private int $sort=100;
	private array $meta=[];
	private ?\Closure $visibilityResolver=null;
	private ?\Closure $urlResolver=null;
	private ?\Closure $currentResolver=null;
	private ?\Closure $badgeResolver=null;

	/**
	 * Initializes a tenant from a normalized identifier and derived label.
	 *
	 * @param string $name Tenant identifier supplied by the caller.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a tenant builder from a tenant identifier.
	 *
	 * The identifier is normalized for stable switcher payloads and permission/navigation comparisons.
	 *
	 * @param string $name Tenant identifier before normalization.
	 * @return self New tenant with a humanized default label.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Rehydrates a tenant builder from an array definition.
	 *
	 * Array definitions support static display fields, sort order, badge value, badge tone, current and hidden flags, and metadata. Lazy URL, badge, current, and visibility resolvers cannot be represented in this static payload and must be attached through the fluent methods.
	 *
	 * @param array<string,mixed> $definition Tenant definition data.
	 * @return self Tenant configured from the definition.
	 */
	public static function fromArray(array $definition): self {
		$tenant=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'description', 'icon', 'url'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$tenant=$tenant->{$key}($definition[$key]);
			}
		}
		if(isset($definition['sort'])){
			$tenant=$tenant->sort((int)$definition['sort']);
		}
		if(array_key_exists('badge', $definition)){
			$tenant=$tenant->badge($definition['badge']);
		}
		if(isset($definition['badge_tone']) && is_string($definition['badge_tone'])){
			$tenant=$tenant->badgeTone($definition['badge_tone']);
		}
		if(array_key_exists('current', $definition)){
			$tenant=$tenant->current((bool)$definition['current']);
		}
		if(!empty($definition['hidden'])){
			$tenant=$tenant->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$tenant=$tenant->meta($definition['meta']);
		}
		return $tenant;
	}

	/**
	 * Reads the normalized tenant identifier.
	 *
	 * @return string Stable tenant name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a clone with a custom tenant label.
	 *
	 * @param string $label User-facing tenant label shown by switchers.
	 * @return self Cloned tenant with the trimmed label applied.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a clone with optional supporting text.
	 *
	 * Blank descriptions collapse to null so renderers can distinguish missing text from populated descriptions.
	 *
	 * @param string $description Supporting tenant text shown by switchers.
	 * @return self Cloned tenant with the description updated.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with an optional icon identifier.
	 *
	 * Blank identifiers collapse to null so renderers can omit the icon slot.
	 *
	 * @param string $icon Icon identifier consumed by the switcher renderer.
	 * @return self Cloned tenant with the icon updated.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a clone with a static or lazily resolved tenant URL.
	 *
	 * Static URLs are trimmed and stored directly. Callable URLs are evaluated during toArray() with the request, tenant, and manager; resolver failures are traced and serialized as null URLs.
	 *
	 * @param string|callable $url Static URL or URL resolver callback.
	 * @return self Cloned tenant with URL behavior updated.
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
	 * Returns a clone with a static or lazily resolved badge value.
	 *
	 * Badges may be any scalar or renderable value expected by the panel UI. Callable badges are evaluated during serialization; resolver failures are traced and serialized as null badges.
	 *
	 * @param mixed $badge Static badge value or lazy badge resolver.
	 * @return self Cloned tenant with badge behavior updated.
	 */
	public function badge(mixed $badge): self {
		$clone=clone $this;
		if(is_callable($badge)){
			$clone->badgeResolver=\Closure::fromCallable($badge);
			$clone->badge=null;
			return $clone;
		}
		$clone->badge=$badge;
		$clone->badgeResolver=null;
		return $clone;
	}

	/**
	 * Returns a clone with a normalized badge tone.
	 *
	 * Unknown tones fall back to neutral. Supported tones are neutral, primary, success, warning, danger, and info.
	 *
	 * @param string $tone Requested badge tone.
	 * @return self Cloned tenant with badge tone updated.
	 */
	public function badgeTone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->badgeTone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Returns a clone with static or lazily resolved current-state behavior.
	 *
	 * Static booleans mark the tenant directly. Callable resolvers are evaluated during toArray() with the request, tenant, and manager; resolver failures are traced and treated as not current.
	 *
	 * @param bool|callable $current Current.
	 * @return self Cloned tenant with current-state behavior updated.
	 */
	public function current(bool|callable $current=true): self {
		$clone=clone $this;
		if(is_callable($current)){
			$clone->currentResolver=\Closure::fromCallable($current);
			$clone->current=false;
			return $clone;
		}
		$clone->current=$current;
		$clone->currentResolver=null;
		return $clone;
	}

	/**
	 * Returns a clone with a tenant ordering weight.
	 *
	 * @param int $sort Lower values sort earlier.
	 * @return self Cloned tenant with sort weight updated.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a clone hidden or shown before visibility resolver evaluation.
	 *
	 * Hidden tenants are never visible, even if a visibility resolver would return true.
	 *
	 * @param bool $hidden True to suppress the tenant.
	 * @return self Cloned tenant with hidden flag updated.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a clone with a lazy visibility resolver.
	 *
	 * Resolvers receive the current request, tenant, and manager. Exceptions are caught in isVisible(), recorded through PanelTrace, and treated as invisible.
	 *
	 * @param callable $resolver Callback that decides tenant visibility from request, tenant, and manager context.
	 * @return self Cloned tenant with lazy visibility attached.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a clone with merged tenant metadata.
	 *
	 * Metadata is shallow-merged so later calls replace existing keys while preserving unrelated tenant metadata.
	 *
	 * @param array<string,mixed> $meta Metadata payload.
	 * @return self Cloned tenant with metadata merged.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Determines whether the tenant should be exposed for the current panel context.
	 *
	 * Hidden tenants return false immediately. Tenants without a visibility resolver are visible by default. Resolver exceptions are traced and treated as false so a broken tenant rule does not break panel rendering.
	 *
	 * @param ?PanelRequest $request Optional request context.
	 * @param ?PanelManager $manager Optional panel manager context.
	 * @return bool True when the tenant is visible.
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
			PanelTrace::record('tenant.visibility_error', [
				'tenant'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Serializes the tenant for switcher rendering, diagnostics, and examples.
	 *
	 * Lazy URL, current, and badge resolvers are evaluated at serialization time. The payload exposes lazy flags so tooling can distinguish static tenant definitions from runtime-sensitive tenants.
	 *
	 * @param ?PanelRequest $request Optional request passed to lazy resolvers.
	 * @param ?PanelManager $manager Optional manager passed to lazy resolvers.
	 * @return array<string,mixed> Tenant state for panel rendering.
	 */
	public function toArray(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$url=$this->url;
		if($this->urlResolver!==null){
			try{
				$url=(string)($this->urlResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('tenant.url_error', [
					'tenant'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$url=null;
			}
		}
		$current=$this->current;
		if($this->currentResolver!==null){
			try{
				$current=(bool)($this->currentResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('tenant.current_error', [
					'tenant'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$current=false;
			}
		}
		$badge=$this->badge;
		if($this->badgeResolver!==null){
			try{
				$badge=($this->badgeResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('tenant.badge_error', [
					'tenant'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$badge=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'description'=>$this->description,
			'icon'=>$this->icon,
			'url'=>$url,
			'badge'=>$badge,
			'badge_tone'=>$this->badgeTone,
			'current'=>$current,
			'sort'=>$this->sort,
			'hidden'=>$this->hidden,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'url_lazy'=>$this->urlResolver!==null,
			'current_lazy'=>$this->currentResolver!==null,
			'badge_lazy'=>$this->badgeResolver!==null,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts a normalized tenant name into a default display label.
	 *
	 * Underscores, hyphens, and dots become spaces, then each word is title-cased for readability before callers set a custom label.
	 *
	 * @param string $value Normalized tenant name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
