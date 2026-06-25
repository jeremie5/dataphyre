<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable definition for grouping Panel navigation entries.
 *
 * A cluster carries display metadata, sorting, collapse state, badges, and
 * arbitrary UI metadata for navigation rendering. Mutators clone before
 * changing state so shared cluster presets can be reused safely across panels.
 */
final class NavigationCluster {

	private string $name;
	private string $label;
	private ?string $icon=null;
	private ?string $description=null;
	private int $sort=100;
	private mixed $badge=null;
	private ?\Closure $badgeResolver=null;
	private string $badgeTone='neutral';
	private bool $collapsed=false;
	private array $meta=[];

	/**
	 * Creates a cluster with a normalized name and humanized default label.
	 *
	 * @param string $name Navigation cluster name.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a navigation cluster definition.
	 *
	 * @param string $name Cluster name to normalize.
	 * @return self New immutable cluster builder.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Rehydrates a cluster from a definition payload.
	 *
	 * The payload accepts `name` or `group`, display metadata, sort order,
	 * static badge, badge tone, collapsed flag, and metadata. Badge resolver
	 * callables are intentionally not rehydrated from arrays.
	 *
	 * @param array<string, mixed> $definition Cluster definition payload.
	 * @return self Cluster builder populated from the payload.
	 */
	public static function fromArray(array $definition): self {
		$cluster=self::make((string)($definition['name'] ?? $definition['group'] ?? ''));
		foreach(['label', 'icon', 'description'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$cluster=$cluster->{$key}($definition[$key]);
			}
		}
		if(isset($definition['sort'])){
			$cluster=$cluster->sort((int)$definition['sort']);
		}
		if(array_key_exists('badge', $definition)){
			$cluster=$cluster->badge($definition['badge']);
		}
		if(isset($definition['badge_tone']) && is_string($definition['badge_tone'])){
			$cluster=$cluster->badgeTone($definition['badge_tone']);
		}
		if(!empty($definition['collapsed'])){
			$cluster=$cluster->collapsed();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$cluster=$cluster->meta($definition['meta']);
		}
		return $cluster;
	}

	/**
	 * Returns the normalized cluster name.
	 *
	 * @return string Stable navigation cluster key.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the visible navigation cluster label.
	 *
	 * Blank labels fall back to a humanized name.
	 *
	 * @param string $label Display label.
	 * @return self New cluster with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label) ?: self::humanize($this->name);
		return $clone;
	}

	/**
	 * Sets the optional icon identifier.
	 *
	 * @param string $icon Icon name or empty string to clear.
	 * @return self New cluster with updated icon.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Sets the optional description shown with the cluster.
	 *
	 * @param string $description Description text or empty string to clear.
	 * @return self New cluster with updated description.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Sets the cluster sort order.
	 *
	 * @param int $sort Sort weight used by navigation renderers.
	 * @return self New cluster with updated sort order.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Sets a static badge value or resolver callback.
	 *
	 * Resolver callbacks receive the current request, cluster, and panel manager
	 * during serialization. Static badges are stored directly.
	 *
	 * @param mixed $badge Static badge value or callable resolver.
	 * @return self New cluster with updated badge configuration.
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
	 * Sets the badge visual tone.
	 *
	 * Unknown tones normalize to `neutral`.
	 *
	 * @param string $tone Requested tone.
	 * @return self New cluster with normalized badge tone.
	 */
	public function badgeTone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->badgeTone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Sets the default collapsed state.
	 *
	 * @param bool $collapsed Whether the cluster should render collapsed by default.
	 * @return self New cluster with updated collapse state.
	 */
	public function collapsed(bool $collapsed=true): self {
		$clone=clone $this;
		$clone->collapsed=$collapsed;
		return $clone;
	}

	/**
	 * Merges arbitrary renderer metadata into the cluster.
	 *
	 * @param array<string, mixed> $meta Metadata merged over existing values.
	 * @return self New cluster with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Serializes the cluster for navigation renderers.
	 *
	 * Badge resolver exceptions are recorded through `PanelTrace` and converted
	 * to a null badge so navigation rendering can continue.
	 *
	 * @param ?PanelRequest $request Current panel request passed to badge resolvers.
	 * @param ?PanelManager $manager Panel manager passed to badge resolvers.
	 * @return array{name:string, label:string, icon:?string, description:?string, sort:int, badge:mixed, badge_tone:string, collapsed:bool, meta:array<string, mixed>} Navigation cluster payload.
	 */
	public function toArray(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$badge=$this->badge;
		if($this->badgeResolver!==null){
			try{
				$badge=($this->badgeResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('navigation_cluster.badge_error', [
					'cluster'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$badge=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'icon'=>$this->icon,
			'description'=>$this->description,
			'sort'=>$this->sort,
			'badge'=>$badge,
			'badge_tone'=>$this->badgeTone,
			'collapsed'=>$this->collapsed,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Converts normalized names into display labels.
	 *
	 * @param string $value Raw cluster name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
