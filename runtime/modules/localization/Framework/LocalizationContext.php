<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Immutable scoped view over the localization manager.
 *
 * A context carries optional language, theme, and page hints so callers can
 * perform translation lookups without passing the same scope on every call. Each
 * scope mutator returns a new context and leaves the current one unchanged.
 * Actual catalog lookup, fallback resolution, parameter interpolation, and
 * plural-choice behavior are delegated to `LocalizationManager`.
 */
final class LocalizationContext {

	/**
	 * Creates a localization lookup context.
	 *
	 * Null scope values let the manager use its active defaults. Non-null values
	 * constrain lookups to a caller-selected language, theme, or page.
	 *
	 * @param LocalizationManager $manager Manager that owns catalog lookup and formatting.
	 * @param ?string $language Optional language code for lookups.
	 * @param ?string $theme Optional theme scope for theme-aware strings.
	 * @param ?string $page Optional page scope for local strings.
	 */
	public function __construct(
		private readonly LocalizationManager $manager,
		private readonly ?string $language=null,
		private readonly ?string $theme=null,
		private readonly ?string $page=null
	){}

	/**
	 * Returns a copy of the context using a different language.
	 *
	 * Passing null removes the language override and lets the manager choose its
	 * default language.
	 *
	 * @param ?string $language Language code to apply to future lookups.
	 * @return self New context with the requested language scope.
	 */
	public function language(?string $language): self {
		return new self($this->manager, $language, $this->theme, $this->page);
	}

	/**
	 * Returns a copy of the context using a different theme scope.
	 *
	 * Theme scope is used by theme-prefixed strings and manager lookup rules. Null
	 * removes the override.
	 *
	 * @param ?string $theme Theme identifier to apply to future lookups.
	 * @return self New context with the requested theme scope.
	 */
	public function theme(?string $theme): self {
		return new self($this->manager, $this->language, $theme, $this->page);
	}

	/**
	 * Returns a copy of the context using a different page scope.
	 *
	 * Page scope is used by local strings and manager lookup rules. Null removes
	 * the override.
	 *
	 * @param ?string $page Page identifier to apply to future lookups.
	 * @return self New context with the requested page scope.
	 */
	public function page(?string $page): self {
		return new self($this->manager, $this->language, $this->theme, $page);
	}

	/**
	 * Translates a key inside the current language/theme/page scope.
	 *
	 * The key may include a domain prefix such as `global:`, `theme:`, or
	 * `local:`. Fallback and parameter interpolation semantics come from the
	 * manager.
	 *
	 * @param string $key Translation key or domain-prefixed key.
	 * @param ?string $fallback Fallback text when the key cannot be resolved.
	 * @param ?array<string,mixed> $parameters Interpolation parameters for the translated string.
	 * @return string Resolved translation or fallback according to manager rules.
	 */
	public function translate(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->manager->translate($key, $fallback, $parameters, $this->language, $this->page, $this->theme);
	}

	/**
	 * Reports whether a translation key exists in the current scope.
	 *
	 *
	 * @return bool True when the manager can resolve the key for this scope.
	 */
	public function has(string $key): bool {
		return $this->manager->has($key, $this->language, $this->page, $this->theme);
	}

	/**
	 * Reports whether a translation key is missing in the current scope.
	 *
	 *
	 * @return bool True when the manager cannot resolve the key for this scope.
	 */
	public function missing(string $key): bool {
		return $this->manager->missing($key, $this->language, $this->page, $this->theme);
	}

	/**
	 * Translates a key and returns null when no translation exists.
	 *
	 * This is the lookup form to use when callers need to distinguish a missing
	 * string from an intentionally empty fallback.
	 *
	 * @param string $key Translation key or domain-prefixed key.
	 * @param ?array<string,mixed> $parameters Interpolation parameters for the translated string.
	 * @return ?string Resolved translation, or null when the key is missing.
	 */
	public function translateOrNull(string $key, ?array $parameters=null): ?string {
		return $this->manager->translateOrNull($key, $parameters, $this->language, $this->page, $this->theme);
	}

	/**
	 * Translates a string from the global localization domain.
	 *
	 * The key is prefixed with `global:` before being passed to `translate()`.
	 *
	 * @param string $key Global-domain key without the `global:` prefix.
	 * @param ?string $fallback Fallback text when the key cannot be resolved.
	 * @param ?array<string,mixed> $parameters Interpolation parameters.
	 * @return string Resolved global translation.
	 */
	public function globalString(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->translate('global:'.$key, $fallback, $parameters);
	}

	/**
	 * Translates a string from the theme localization domain.
	 *
	 * The key is prefixed with `theme:` before being passed to `translate()`.
	 *
	 * @param string $key Theme-domain key without the `theme:` prefix.
	 * @param ?string $fallback Fallback text when the key cannot be resolved.
	 * @param ?array<string,mixed> $parameters Interpolation parameters.
	 * @return string Resolved theme translation.
	 */
	public function themeString(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->translate('theme:'.$key, $fallback, $parameters);
	}

	/**
	 * Translates a string from the current page-local domain.
	 *
	 * The key is prefixed with `local:` before being passed to `translate()`, so
	 * page scope can participate in manager lookup rules.
	 *
	 * @param string $key Local-domain key without the `local:` prefix.
	 * @param ?string $fallback Fallback text when the key cannot be resolved.
	 * @param ?array<string,mixed> $parameters Interpolation parameters.
	 * @return string Resolved local translation.
	 */
	public function local(string $key, ?string $fallback=null, ?array $parameters=null): string {
		return $this->translate('local:'.$key, $fallback, $parameters);
	}

	/**
	 * Resolves a pluralized translation for the current scope.
	 *
	 * Count selection and parameter interpolation are delegated to the manager.
	 * Callers may provide a separate zero key; otherwise the manager chooses
	 * between singular and plural keys.
	 *
	 * @param int|float $count Count used for plural selection.
	 * @param string $oneKey Singular translation key.
	 * @param string $manyKey Plural translation key.
	 * @param ?string $zeroKey Optional translation key for zero.
	 * @param ?array<string,mixed> $parameters Interpolation parameters.
	 * @return string Resolved choice translation.
	 */
	public function choice(
		int|float $count,
		string $oneKey,
		string $manyKey,
		?string $zeroKey=null,
		?array $parameters=null
	): string {
		return $this->manager->choice($count, $oneKey, $manyKey, $zeroKey, $parameters, $this->language, $this->page, $this->theme);
	}
}
