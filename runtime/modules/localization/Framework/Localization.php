<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Static facade for typed Dataphyre localization lookups and maintenance.
 *
 * The facade exposes manager state, scoped contexts, locale lookup, unknown-string capture,
 * SQL-backed definitions, sync, and rebuild operations without leaking the kernel's
 * snake_case helpers into framework callers.
 */
final class Localization {

	/**
	 * Returns the process-local localization manager that owns lookup and maintenance state.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return LocalizationManager Localization object described by the native return type.
	 */
	public static function manager(): LocalizationManager {
		return LocalizationManager::instance();
	}

	/**
	 * Clears the process-local localization manager so subsequent calls reload kernel state.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return void Manager state is reset in place.
	 */
	public static function flush(): void {
		LocalizationManager::flush();
	}

	/**
	 * Returns a typed snapshot of active language, theme, catalogs, paths, and maintenance configuration.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return LocalizationState Typed localization state snapshot.
	 */
	public static function state(): LocalizationState {
		return self::manager()->state();
	}

	/**
	 * Builds a scoped localization context with optional language, theme, and page overrides.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @return LocalizationContext Scoped context that applies overrides without mutating global state.
	 */
	public static function context(?string $language=null, ?string $theme=null, ?string $page=null): LocalizationContext {
		return self::manager()->context($language, $theme, $page);
	}

	/**
	 * Reads normalized localization state exposed by the active manager.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return ?string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function defaultLanguage(): ?string {
		return self::manager()->defaultLanguage();
	}

	/**
	 * Reads normalized localization state exposed by the active manager.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return ?string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function userLanguage(): ?string {
		return self::manager()->userLanguage();
	}

	/**
	 * Reads normalized localization state exposed by the active manager.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return ?string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function userTheme(): ?string {
		return self::manager()->userTheme();
	}

	/**
	 * Reads normalized localization state exposed by the active manager.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return ?array Localization object described by the native return type.
	 */
	public static function availableLanguages(): ?array {
		return self::manager()->availableLanguages();
	}

	/**
	 * Reads normalized localization state exposed by the active manager.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return ?array Localization object described by the native return type.
	 */
	public static function availableThemes(): ?array {
		return self::manager()->availableThemes();
	}

	/**
	 * Resolves the active local-page path, honoring a caller override when provided.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param ?string $forcedPage Active page or locale path segment used for local-scope strings.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function activePage(?string $forcedPage=null): string {
		return self::manager()->activePage($forcedPage);
	}

	/**
	 * Normalizes and validates a language code according to the manager rules.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function validateLanguage(string $language): string {
		return self::manager()->validateLanguage($language);
	}

	/**
	 * Interpolates custom and caller-provided placeholders into locale text.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $string Locale text before placeholder interpolation.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function parameters(string $string, ?array $parameters=[]): string {
		return self::manager()->parameters($string, $parameters);
	}

	/**
	 * Reports whether a locale key resolves for the requested language, page, and theme scope.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return bool True when the requested locale or maintenance condition is satisfied.
	 */
	public static function has(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		return self::manager()->has($key, $language, $page, $theme);
	}

	/**
	 * Reports whether a locale key cannot be resolved for the requested context.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return bool True when the requested locale or maintenance condition is satisfied.
	 */
	public static function missing(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		return self::manager()->missing($key, $language, $page, $theme);
	}

	/**
	 * Attempts locale lookup without falling back to a non-null string.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return ?string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function translateOrNull(
		string $key,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): ?string {
		return self::manager()->translateOrNull($key, $parameters, $language, $page, $theme);
	}

	/**
	 * Returns a typed catalog for a locale scope, path, language, and optional theme.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $scope Locale catalog scope such as global, theme, or local.
	 * @param string $path Catalog path or generated locale target path.
	 * @param string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return LocaleCatalog Typed catalog wrapping normalized locale records for runtime callers.
	 */
	public static function locales(string $scope, string $path, string $language, ?string $theme=null): LocaleCatalog {
		return self::manager()->locales($scope, $path, $language, $theme);
	}

	/**
	 * Resolves a locale key through scoped catalogs, fallback text, and placeholder interpolation.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?string $fallback Fallback text returned when no locale source resolves the key.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function translate(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return self::manager()->translate($key, $fallback, $parameters, $language, $page, $theme);
	}

	/**
	 * Resolves a locale key through scoped catalogs, fallback text, and placeholder interpolation.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?string $fallback Fallback text returned when no locale source resolves the key.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function globalString(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $theme=null
	): string {
		return self::translate('global:'.$key, $fallback, $parameters, $language, null, $theme);
	}

	/**
	 * Resolves a locale key through scoped catalogs, fallback text, and placeholder interpolation.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?string $fallback Fallback text returned when no locale source resolves the key.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function themeString(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $theme=null
	): string {
		return self::translate('theme:'.$key, $fallback, $parameters, $language, null, $theme);
	}

	/**
	 * Resolves a locale key through scoped catalogs, fallback text, and placeholder interpolation.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $key Locale key requested from the active catalog.
	 * @param ?string $fallback Fallback text returned when no locale source resolves the key.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function local(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return self::translate('local:'.$key, $fallback, $parameters, $language, $page, $theme);
	}

	/**
	 * Selects zero, singular, or plural locale keys from a count and resolves the chosen text.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param int|float $count Quantity used to choose singular, plural, or zero locale keys.
	 * @param string $oneKey Locale key requested from the active catalog.
	 * @param string $manyKey Locale key requested from the active catalog.
	 * @param ?string $zeroKey Locale key requested from the active catalog.
	 * @param ?array $parameters Placeholder values merged into the resolved locale string.
	 * @param ?string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param ?string $page Active page or locale path segment used for local-scope strings.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @return string Resolved, interpolated locale string; nullable variants return null when lookup misses.
	 */
	public static function choice(
		int|float $count,
		string $oneKey,
		string $manyKey,
		?string $zeroKey=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return self::manager()->choice($count, $oneKey, $manyKey, $zeroKey, $parameters, $language, $page, $theme);
	}

	/**
	 * Reads, clears, or promotes locale keys captured during unresolved lookup.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return UnknownLocaleCatalog Typed catalog wrapping normalized unresolved-locale records.
	 */
	public static function unknownLocales(): UnknownLocaleCatalog {
		return self::manager()->unknownLocales();
	}

	/**
	 * Reads, clears, or promotes locale keys captured during unresolved lookup.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $stringName Unknown-locale key normalized for lookup or clearing.
	 * @return ?UnknownLocaleEntry Typed unknown-locale entry or null when the key was not captured.
	 */
	public static function unknownLocale(string $stringName): ?UnknownLocaleEntry {
		return self::manager()->unknownLocale($stringName);
	}

	/**
	 * Reads, clears, or promotes locale keys captured during unresolved lookup.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $stringName Unknown-locale key normalized for lookup or clearing.
	 * @return bool True when the requested locale or maintenance condition is satisfied.
	 */
	public static function hasUnknownLocale(string $stringName): bool {
		return self::manager()->hasUnknownLocale($stringName);
	}

	/**
	 * Reads, clears, or promotes locale keys captured during unresolved lookup.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param ?string $stringName Unknown-locale key normalized for lookup or clearing.
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function clearUnknown(?string $stringName=null): LocalizationMaintenanceResult {
		return self::manager()->clearUnknown($stringName);
	}

	/**
	 * Reads, saves, deletes, or batches SQL-backed locale definitions.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param array<string,mixed> $filters Definition filters passed to the SQL-backed locale definition catalog.
	 * @param int $limit Maximum number of locale definitions returned.
	 * @param int $offset Number of locale definitions skipped before reading.
	 * @return LocaleDefinitionCatalog Typed catalog wrapping normalized locale definition records.
	 */
	public static function definitions(array $filters=[], int $limit=250, int $offset=0): LocaleDefinitionCatalog {
		return self::manager()->definitions($filters, $limit, $offset);
	}

	/**
	 * Reads, saves, deletes, or batches SQL-backed locale definitions.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $type Locale definition type or type filter, such as global, theme, or local.
	 * @param string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param string $name Locale definition name.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @param ?string $path Catalog path or generated locale target path.
	 * @return ?LocaleDefinition Typed locale definition object or null when no matching definition exists.
	 */
	public static function definition(
		string $type,
		string $language,
		string $name,
		?string $theme=null,
		?string $path=null
	): ?LocaleDefinition {
		return self::manager()->definition($type, $language, $name, $theme, $path);
	}

	/**
	 * Reads, saves, deletes, or batches SQL-backed locale definitions.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $type Locale definition type or type filter, such as global, theme, or local.
	 * @param string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param string $name Locale definition name.
	 * @param string $string Locale text before placeholder interpolation.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @param ?string $path Catalog path or generated locale target path.
	 * @param bool $rebuild Whether generated locale targets should be rebuilt after mutation.
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function saveDefinition(
		string $type,
		string $language,
		string $name,
		string $string,
		?string $theme=null,
		?string $path=null,
		bool $rebuild=true
	): LocalizationMaintenanceResult {
		return self::manager()->saveDefinition($type, $language, $name, $string, $theme, $path, $rebuild);
	}

	/**
	 * Reads, saves, deletes, or batches SQL-backed locale definitions.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param array<int,array<string,mixed>|LocaleDefinition|LocaleDefinitionMutation> $definitions Locale definition payloads for batch persistence.
	 * @param bool $rebuild Whether generated locale targets should be rebuilt after mutation.
	 * @return LocaleDefinitionBatchResult Batch result with per-definition success and failure details.
	 */
	public static function saveDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		return self::manager()->saveDefinitions($definitions, $rebuild);
	}

	/**
	 * Reads, saves, deletes, or batches SQL-backed locale definitions.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param string $type Locale definition type or type filter, such as global, theme, or local.
	 * @param string $language Language code(s) normalized through the localization manager before lookup or rebuild.
	 * @param string $name Locale definition name.
	 * @param ?string $theme Theme code(s) used for themed locale lookup or rebuild targets.
	 * @param ?string $path Catalog path or generated locale target path.
	 * @param bool $rebuild Whether generated locale targets should be rebuilt after mutation.
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function deleteDefinition(
		string $type,
		string $language,
		string $name,
		?string $theme=null,
		?string $path=null,
		bool $rebuild=true
	): LocalizationMaintenanceResult {
		return self::manager()->deleteDefinition($type, $language, $name, $theme, $path, $rebuild);
	}

	/**
	 * Reads, saves, deletes, or batches SQL-backed locale definitions.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param array<int,array<string,mixed>|LocaleDefinition|LocaleDefinitionMutation> $definitions Locale definition payloads for batch deletion.
	 * @param bool $rebuild Whether generated locale targets should be rebuilt after mutation.
	 * @return LocaleDefinitionBatchResult Batch result with per-definition success and failure details.
	 */
	public static function deleteDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		return self::manager()->deleteDefinitions($definitions, $rebuild);
	}

	/**
	 * Reads, clears, or promotes locale keys captured during unresolved lookup.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function learnUnknown(): LocalizationMaintenanceResult {
		return self::manager()->learnUnknown();
	}

	/**
	 * Synchronizes changed locale definitions into generated runtime locale files.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param bool $forced Whether synchronization should run regardless of cached sync state.
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function sync(bool $forced=false): LocalizationMaintenanceResult {
		return self::manager()->sync($forced);
	}

	/**
	 * Rebuilds generated locale targets for selected types, languages, themes, and paths.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param array<int,string> $types Locale definition type filters, such as global, theme, or local.
	 * @param array<int,string> $languages Language codes normalized through the localization manager before rebuild.
	 * @param array<int,string> $themes Theme codes used for themed locale rebuild targets.
	 * @param array<int,string> $paths Active page or locale path segments used for local-scope strings.
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function rebuild(
		array $types=[],
		array $languages=[],
		array $themes=[],
		array $paths=[]
	): LocalizationMaintenanceResult {
		return self::manager()->rebuild($types, $languages, $themes, $paths);
	}

	/**
	 * Rebuilds generated locale targets for selected types, languages, themes, and paths.
	 *
	 * The facade preserves deterministic lookup order: explicit overrides, active user state, configured defaults, then fallback capture for missing strings and maintenance tooling.
	 *
	 * @param LocalizationRebuildSelection $selection Typed rebuild selection carrying type, language, theme, and path filters.
	 * @return LocalizationMaintenanceResult Maintenance result describing success, affected targets, and backend failure details.
	 */
	public static function rebuildSelection(LocalizationRebuildSelection $selection): LocalizationMaintenanceResult {
		return self::manager()->rebuildSelection($selection);
	}
}
