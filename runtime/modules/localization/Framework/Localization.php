<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class Localization {

	public static function manager(): LocalizationManager {
		return LocalizationManager::instance();
	}

	public static function flush(): void {
		LocalizationManager::flush();
	}

	public static function state(): LocalizationState {
		return self::manager()->state();
	}

	public static function context(?string $language=null, ?string $theme=null, ?string $page=null): LocalizationContext {
		return self::manager()->context($language, $theme, $page);
	}

	public static function defaultLanguage(): ?string {
		return self::manager()->defaultLanguage();
	}

	public static function userLanguage(): ?string {
		return self::manager()->userLanguage();
	}

	public static function userTheme(): ?string {
		return self::manager()->userTheme();
	}

	public static function availableLanguages(): ?array {
		return self::manager()->availableLanguages();
	}

	public static function availableThemes(): ?array {
		return self::manager()->availableThemes();
	}

	public static function activePage(?string $forced_page=null): string {
		return self::manager()->activePage($forced_page);
	}

	public static function validateLanguage(string $language): string {
		return self::manager()->validateLanguage($language);
	}

	public static function parameters(string $string, ?array $parameters=[]): string {
		return self::manager()->parameters($string, $parameters);
	}

	public static function has(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		return self::manager()->has($key, $language, $page, $theme);
	}

	public static function missing(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		return self::manager()->missing($key, $language, $page, $theme);
	}

	public static function translateOrNull(
		string $key,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): ?string {
		return self::manager()->translateOrNull($key, $parameters, $language, $page, $theme);
	}

	public static function locales(string $scope, string $path, string $language, ?string $theme=null): LocaleCatalog {
		return self::manager()->locales($scope, $path, $language, $theme);
	}

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

	public static function globalString(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $theme=null
	): string {
		return self::translate('global:'.$key, $fallback, $parameters, $language, null, $theme);
	}

	public static function themeString(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $theme=null
	): string {
		return self::translate('theme:'.$key, $fallback, $parameters, $language, null, $theme);
	}

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

	public static function choice(
		int|float $count,
		string $one_key,
		string $many_key,
		?string $zero_key=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return self::manager()->choice($count, $one_key, $many_key, $zero_key, $parameters, $language, $page, $theme);
	}

	public static function unknownLocales(): UnknownLocaleCatalog {
		return self::manager()->unknownLocales();
	}

	public static function unknownLocale(string $string_name): ?UnknownLocaleEntry {
		return self::manager()->unknownLocale($string_name);
	}

	public static function hasUnknownLocale(string $string_name): bool {
		return self::manager()->hasUnknownLocale($string_name);
	}

	public static function clearUnknown(?string $string_name=null): LocalizationMaintenanceResult {
		return self::manager()->clearUnknown($string_name);
	}

	public static function definitions(array $filters=[], int $limit=250, int $offset=0): LocaleDefinitionCatalog {
		return self::manager()->definitions($filters, $limit, $offset);
	}

	public static function definition(
		string $type,
		string $language,
		string $name,
		?string $theme=null,
		?string $path=null
	): ?LocaleDefinition {
		return self::manager()->definition($type, $language, $name, $theme, $path);
	}

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

	public static function saveDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		return self::manager()->saveDefinitions($definitions, $rebuild);
	}

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

	public static function deleteDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		return self::manager()->deleteDefinitions($definitions, $rebuild);
	}

	public static function learnUnknown(): LocalizationMaintenanceResult {
		return self::manager()->learnUnknown();
	}

	public static function sync(bool $forced=false): LocalizationMaintenanceResult {
		return self::manager()->sync($forced);
	}

	public static function rebuild(
		array $types=[],
		array $languages=[],
		array $themes=[],
		array $paths=[]
	): LocalizationMaintenanceResult {
		return self::manager()->rebuild($types, $languages, $themes, $paths);
	}

	public static function rebuildSelection(LocalizationRebuildSelection $selection): LocalizationMaintenanceResult {
		return self::manager()->rebuildSelection($selection);
	}
}
