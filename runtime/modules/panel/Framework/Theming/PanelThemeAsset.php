<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a stylesheet asset used by a Panel theme.
 *
 * Theme assets normalize a public name, href, and safe scalar HTML attributes so
 * theme definitions can be declared as strings, arrays, or ready-made asset
 * instances and later rendered deterministically by Panel.
 */
final class PanelThemeAsset {

	private string $name;
	private string $href;
	private array $attributes=[];

	/**
	 * Creates a theme stylesheet asset.
	 *
	 * The asset name is explicit when provided, otherwise it is derived from the
	 * href filename and finally from a short hash when the href has no usable
	 * filename.
	 *
	 * @param string $href Stylesheet URL or path.
	 * @param ?string $name Optional stable asset name.
	 * @param array<string, mixed> $attributes Link attributes to preserve.
	 */
	private function __construct(string $href, ?string $name=null, array $attributes=[]) {
		$this->href=trim($href);
		$this->name=Resource::normalizeName((string)($name ?? '')) ?: Resource::normalizeName(pathinfo(parse_url($this->href, PHP_URL_PATH) ?: $this->href, PATHINFO_FILENAME));
		if($this->name===''){
			$this->name='asset_'.substr(hash('sha256', $this->href), 0, 10);
		}
		$this->attributes($attributes);
	}

	/**
	 * Creates a stylesheet asset from explicit values.
	 *
	 * @param string $href Stylesheet URL or path.
	 * @param ?string $name Optional stable asset name.
	 * @param array<string, mixed> $attributes Link attributes such as media, integrity, crossorigin, or nonce.
	 * @return self Theme stylesheet asset.
	 */
	public static function stylesheet(string $href, ?string $name=null, array $attributes=[]): self {
		return new self($href, $name, $attributes);
	}

	/**
	 * Normalizes a theme asset definition.
	 *
	 * Accepted definitions are existing PanelThemeAsset instances, non-empty href
	 * strings, or arrays containing `href`, `url`, or `path`. Common link
	 * attributes may be supplied either under `attributes` or as top-level keys.
	 *
	 * @param mixed $definition Theme asset definition from configuration.
	 * @return ?self Normalized asset, or null when the definition has no href.
	 */
	public static function from(mixed $definition): ?self {
		if($definition instanceof self){
			return $definition;
		}
		if(is_string($definition)){
			$href=trim($definition);
			return $href!=='' ? self::stylesheet($href) : null;
		}
		if(!is_array($definition)){
			return null;
		}
		$href=(string)($definition['href'] ?? $definition['url'] ?? $definition['path'] ?? '');
		if(trim($href)===''){
			return null;
		}
		$name=isset($definition['name']) ? (string)$definition['name'] : null;
		$attributes=is_array($definition['attributes'] ?? null) ? $definition['attributes'] : [];
		foreach(['media', 'integrity', 'crossorigin', 'referrerpolicy', 'nonce', 'title'] as $attribute){
			if(isset($definition[$attribute])){
				$attributes[$attribute]=$definition[$attribute];
			}
		}
		return self::stylesheet($href, $name, $attributes);
	}

	/**
	 * Returns the normalized asset name.
	 *
	 * @return string Stable asset name for de-duplication and diagnostics.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the stylesheet href.
	 *
	 * @return string Stylesheet URL or path.
	 */
	public function href(): string {
		return $this->href;
	}

	/**
	 * Merges scalar link attributes into the asset.
	 *
	 * Attribute names are normalized through Resource::normalizeName(), and only
	 * scalar or null values are retained so renderers can safely emit the payload
	 * without recursively serializing arbitrary data.
	 *
	 * @param array<string, mixed> $attributes Attribute map to merge.
	 * @return self Current asset instance.
	 */
	public function attributes(array $attributes): self {
		foreach($attributes as $name=>$value){
			$name=Resource::normalizeName((string)$name);
			if($name!=='' && (is_scalar($value) || $value===null)){
				$this->attributes[$name]=$value===null ? null : trim((string)$value);
			}
		}
		return $this;
	}

	/**
	 * Returns the theme asset name, URL, and non-empty link attributes.
	 *
	 * Empty or null attributes are removed so renderers receive only meaningful
	 * link attributes.
	 *
	 * @return array{name: string, href: string, attributes: array<string, string>}
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'href'=>$this->href,
			'attributes'=>array_filter($this->attributes, static fn(mixed $value): bool => $value!==null && $value!==''),
		];
	}
}
