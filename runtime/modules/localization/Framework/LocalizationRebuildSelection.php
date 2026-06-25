<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Describes which localization assets should be rebuilt.
 *
 * A rebuild selection is an immutable command value passed from maintenance UI,
 * scheduled jobs, or CLI tooling into localization rebuild services. Empty
 * arrays mean "no restriction" for that dimension, so all() can represent a
 * complete rebuild without enumerating every language, theme, or path.
 */
final class LocalizationRebuildSelection implements \JsonSerializable {

	/**
	 * Stores the selected rebuild dimensions.
	 *
	 * The constructor intentionally does not normalize values because callers
	 * may use filesystem paths, locale tags, or theme identifiers whose casing
	 * and separators matter to downstream loaders.
	 *
	 * @param array<int, string> $types Rebuild domains such as global, theme, or local.
	 * @param array<int, string> $languages Locale identifiers to include, or empty for all.
	 * @param array<int, string> $themes Theme identifiers to include, or empty for all.
	 * @param array<int, string> $paths Local translation paths to include, or empty for all.
	 */
	public function __construct(
		private readonly array $types=[],
		private readonly array $languages=[],
		private readonly array $themes=[],
		private readonly array $paths=[]
	){}

	/**
	 * Selects every rebuild domain and every configured locale source.
	 *
	 * @return self Empty-dimension selection representing a complete rebuild.
	 */
	public static function all(): self {
		return new self();
	}

	/**
	 * Selects global translation sources.
	 *
	 * Global rebuilds cover shared translation catalogs that are not scoped to
	 * a theme or local package path.
	 *
	 * @param array<int, string> $languages Locale identifiers to rebuild, or empty for all.
	 * @return self Selection constrained to global translation assets.
	 */
	public static function global(array $languages=[]): self {
		return new self(['global'], $languages);
	}

	/**
	 * Selects theme-scoped translation sources.
	 *
	 * Theme rebuilds are used when localized templates, storefront assets, or
	 * per-theme catalogs need regeneration without touching unrelated local
	 * package translation paths.
	 *
	 * @param array<int, string> $languages Locale identifiers to rebuild, or empty for all.
	 * @param array<int, string> $themes Theme identifiers to rebuild, or empty for all.
	 * @return self Selection constrained to theme translation assets.
	 */
	public static function theme(array $languages=[], array $themes=[]): self {
		return new self(['theme'], $languages, $themes);
	}

	/**
	 * Selects local translation source paths.
	 *
	 * Local rebuilds target package or application translation files that can
	 * optionally be narrowed by language, theme, and concrete filesystem path.
	 *
	 * @param array<int, string> $languages Locale identifiers to rebuild, or empty for all.
	 * @param array<int, string> $themes Theme identifiers to rebuild, or empty for all.
	 * @param array<int, string> $paths Translation source paths to rebuild, or empty for all.
	 * @return self Selection constrained to local translation assets.
	 */
	public static function local(array $languages=[], array $themes=[], array $paths=[]): self {
		return new self(['local'], $languages, $themes, $paths);
	}

	/**
	 * Returns the selected rebuild domains.
	 *
	 * @return array<int, string> Domain names, or an empty array when all domains are selected.
	 */
	public function types(): array {
		return $this->types;
	}

	/**
	 * Returns the selected locales.
	 *
	 * @return array<int, string> Locale identifiers, or an empty array when all locales are selected.
	 */
	public function languages(): array {
		return $this->languages;
	}

	/**
	 * Returns the selected themes.
	 *
	 * @return array<int, string> Theme identifiers, or an empty array when all themes are selected.
	 */
	public function themes(): array {
		return $this->themes;
	}

	/**
	 * Returns the selected local translation paths.
	 *
	 * @return array<int, string> Source paths, or an empty array when all paths are selected.
	 */
	public function paths(): array {
		return $this->paths;
	}

	/**
	 * Serializes the rebuild selection for jobs, manifests, and diagnostics.
	 *
	 * @return array{types:array,languages:array,themes:array,paths:array}
	 */
	public function jsonSerialize(): array {
		return [
			'types'=>$this->types,
			'languages'=>$this->languages,
			'themes'=>$this->themes,
			'paths'=>$this->paths,
		];
	}
}
