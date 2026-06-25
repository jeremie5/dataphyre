<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Provides a scoped view over Panel localization lookups.
 *
 * PanelLocalizationScope binds a PanelLocalization instance to one namespace or
 * component scope so callers can translate relative keys without passing the
 * scope on every request. It is a lightweight proxy and does not own locale
 * state or translation storage.
 */
final class PanelLocalizationScope implements \JsonSerializable {

	/**
	 * Stores the localization backend and fixed scope.
	 *
	 *
	 * @param readonly PanelLocalization $localization Localization service to delegate to.
	 * @param readonly string $scope Scope applied to every lookup.
	 */
	public function __construct(
		private readonly PanelLocalization $localization,
		private readonly string $scope
	){}

	/**
	 * Translates a key within this scope.
	 *
	 * Parameters and locale are passed through to the underlying localization
	 * service. Default is used by that service when the scoped key is missing.
	 *
	 * @param string $key Translation key relative to the scope.
	 * @param array<string, mixed> $parameters Placeholder replacements.
	 * @param ?string $locale Locale override, or null for active Panel locale.
	 * @param string|\Stringable|null $default Fallback text when the key is missing.
	 * @return string Translated text.
	 */
	public function translate(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null): string {
		return $this->localization->translate($key, $parameters, $locale, $default, $this->scope);
	}

	/**
	 * Alias for translate().
	 *
	 *
	 * @param string $key Translation key relative to the scope.
	 * @param array<string, mixed> $parameters Placeholder replacements.
	 * @param ?string $locale Locale override, or null for active Panel locale.
	 * @param string|\Stringable|null $default Fallback text when the key is missing.
	 * @return string Translated text.
	 */
	public function trans(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null): string {
		return $this->translate($key, $parameters, $locale, $default);
	}

	/**
	 * Short alias for translate().
	 *
	 *
	 * @param string $key Translation key relative to the scope.
	 * @param array<string, mixed> $parameters Placeholder replacements.
	 * @param ?string $locale Locale override, or null for active Panel locale.
	 * @param string|\Stringable|null $default Fallback text when the key is missing.
	 * @return string Translated text.
	 */
	public function t(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null): string {
		return $this->translate($key, $parameters, $locale, $default);
	}

	/**
	 * Reports whether a scoped translation exists.
	 *
	 * @param string $key Translation key relative to the scope.
	 * @param ?string $locale Locale override, or null for active Panel locale.
	 * @return bool True when the scoped translation exists.
	 */
	public function has(string $key, ?string $locale=null): bool {
		return $this->localization->has($key, $locale, $this->scope);
	}

	/**
	 * Serializes the scoped localization state.
	 *
	 * @return array{type:string,scope:string,locale:string,fallback_locale:string}
	 */
	public function toArray(): array {
		return [
			'type'=>'panel_localization_scope',
			'scope'=>$this->scope,
			'locale'=>$this->localization->locale(),
			'fallback_locale'=>$this->localization->fallbackLocale(),
		];
	}

	/**
	 * Serializes the scope for JSON diagnostics.
	 *
	 * @return array{type:string,scope:string,locale:string,fallback_locale:string}
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}
}
