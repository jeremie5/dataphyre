<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Immutable snapshot of runtime localization preferences and availability.
 *
 * The state records configured defaults, user-selected language/theme values,
 * optional lists of available languages and themes, and arbitrary custom
 * parameters exposed by the localization module. It is a read model for UI
 * state and diagnostics; constructing it does not refresh locale files,
 * persist preferences, or validate that the selected values are available.
 */
final class LocalizationState {

	/**
	 * Captures localization state fields exactly as normalized by the caller.
	 *
	 * `null` availability lists mean the source did not provide a list, not
	 * that no languages or themes exist. Custom parameters remain caller-shaped
	 * so module-specific UI state can pass through without this value object
	 * needing to understand every extension.
	 *
	 * @param ?string $defaultLanguage Configured fallback language, when known.
	 * @param ?string $userLanguage Current user language, when known.
	 * @param ?string $userTheme Current user theme, when known.
	 * @param ?array<int|string, mixed> $availableLanguages Optional available language list or map.
	 * @param ?array<int|string, mixed> $availableThemes Optional available theme list or map.
	 * @param array<string, mixed> $customParameters Additional localization parameters exposed by the module.
	 * @param bool $databaseBacked Whether SQL is the definition source of truth.
	 * @param array<string, mixed> $source Source branch/commit metadata.
	 */
	public function __construct(
		private readonly ?string $defaultLanguage,
		private readonly ?string $userLanguage,
		private readonly ?string $userTheme,
		private readonly ?array $availableLanguages,
		private readonly ?array $availableThemes,
		private readonly array $customParameters,
		private readonly bool $databaseBacked=true,
		private readonly array $source=[]
	){}

	/**
	 * Creates a state snapshot from a module state array.
	 *
	 * Scalar language and theme values are string-cast when present. Availability
	 * collections are retained only when they are arrays, and custom parameters
	 * default to an empty array to keep callers from branching on `null`.
	 *
	 * @param array<string, mixed> $state Raw localization state payload.
	 * @return self Normalized immutable state snapshot.
	 */
	public static function fromArray(array $state): self {
		return new self(
			isset($state['default_language']) ? (string)$state['default_language'] : null,
			isset($state['user_language']) ? (string)$state['user_language'] : null,
			isset($state['user_theme']) ? (string)$state['user_theme'] : null,
			is_array($state['available_languages'] ?? null) ? $state['available_languages'] : null,
			is_array($state['available_themes'] ?? null) ? $state['available_themes'] : null,
			is_array($state['custom_parameters'] ?? null) ? $state['custom_parameters'] : [],
			(bool)($state['database_backed'] ?? true),
			is_array($state['source'] ?? null) ? $state['source'] : []
		);
	}

	/**
	 * Returns the configured fallback language.
	 *
	 * @return ?string Default language code, or `null` when not supplied.
	 */
	public function defaultLanguage(): ?string {
		return $this->defaultLanguage;
	}

	/**
	 * Returns the currently selected user language.
	 *
	 * @return ?string User language code, or `null` when no user preference is known.
	 */
	public function userLanguage(): ?string {
		return $this->userLanguage;
	}

	/**
	 * Returns the currently selected user theme.
	 *
	 * @return ?string User theme name, or `null` when no theme preference is known.
	 */
	public function userTheme(): ?string {
		return $this->userTheme;
	}

	/**
	 * Returns the available language collection reported by the module.
	 *
	 * @return ?array<int|string, mixed> Language list or map, or `null` when the source did not expose one.
	 */
	public function availableLanguages(): ?array {
		return $this->availableLanguages;
	}

	/**
	 * Returns the available theme collection reported by the module.
	 *
	 * @return ?array<int|string, mixed> Theme list or map, or `null` when the source did not expose one.
	 */
	public function availableThemes(): ?array {
		return $this->availableThemes;
	}

	/**
	 * Returns module-specific localization parameters.
	 *
	 * @return array<string, mixed> Caller-shaped parameter map, empty when no custom parameters were provided.
	 */
	public function customParameters(): array {
		return $this->customParameters;
	}

	/**
	 * Reports whether SQL is the locale definition source of truth.
	 *
	 * @return bool True for database-backed mode, false for file-backed mode.
	 */
	public function databaseBacked(): bool {
		return $this->databaseBacked;
	}

	/**
	 * Returns source branch and commit metadata for the localization runtime.
	 *
	 * @return array<string, mixed> Source metadata.
	 */
	public function source(): array {
		return $this->source;
	}
}
