<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocalizationManager {

	private static ?self $instance=null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function state(): LocalizationState {
		return LocalizationState::fromArray(\dataphyre\localization::state());
	}

	public function context(?string $language=null, ?string $theme=null, ?string $page=null): LocalizationContext {
		return new LocalizationContext($this, $language, $theme, $page);
	}

	public function defaultLanguage(): ?string {
		return \dataphyre\localization::default_language();
	}

	public function userLanguage(): ?string {
		return \dataphyre\localization::user_language();
	}

	public function userTheme(): ?string {
		return \dataphyre\localization::user_theme();
	}

	public function availableLanguages(): ?array {
		return \dataphyre\localization::available_languages();
	}

	public function availableThemes(): ?array {
		return \dataphyre\localization::available_themes();
	}

	public function activePage(?string $forced_page=null): string {
		return \dataphyre\localization::active_page($forced_page);
	}

	public function validateLanguage(string $language): string {
		return \dataphyre\localization::validate_language_code($language);
	}

	public function parameters(string $string, ?array $parameters=[]): string {
		return \dataphyre\localization::locale_parameters($string, $parameters);
	}

	public function has(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		$key_context=$this->resolveKeyContext($key, $page, $theme);
		$catalog=$this->locales(
			$key_context['scope'],
			$key_context['path'],
			$this->resolveLanguage($language),
			$key_context['theme']
		);
		return $catalog->has($key_context['name']);
	}

	public function missing(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		return !$this->has($key, $language, $page, $theme);
	}

	public function translateOrNull(
		string $key,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): ?string {
		if($this->missing($key, $language, $page, $theme)){
			return null;
		}
		return $this->translate($key, null, $parameters, $language, $page, $theme);
	}

	public function locales(string $scope, string $path, string $language, ?string $theme=null): LocaleCatalog {
		return $this->withStateOverrides(
			[
				'user_theme'=>$theme,
			],
			static fn() => new LocaleCatalog(
				$scope,
				$path,
				$language,
				\dataphyre\localization::get_locales($scope, $path, $language)
			)
		);
	}

	public function translate(
		string $key,
		?string $fallback=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		return $this->withStateOverrides(
			[
				'user_language'=>$language,
				'user_theme'=>$theme,
			],
			static fn() => \dataphyre\localization::locale($key, $fallback, $parameters, $language, $page)
		);
	}

	public function choice(
		int|float $count,
		string $one_key,
		string $many_key,
		?string $zero_key=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		$selected_key=$zero_key!==null && (float)$count===0.0
			? $zero_key
			: ((float)abs($count)===1.0 ? $one_key : $many_key);
		return $this->translate(
			$selected_key,
			null,
			$this->mergeCountParameters($count, $parameters),
			$language,
			$page,
			$theme
		);
	}

	public function unknownLocales(): UnknownLocaleCatalog {
		return UnknownLocaleCatalog::fromArray(\dataphyre\localization::unknown_locales());
	}

	public function unknownLocale(string $string_name): ?UnknownLocaleEntry {
		$entry=\dataphyre\localization::unknown_locale($string_name);
		return is_array($entry) ? UnknownLocaleEntry::fromArray($string_name, $entry) : null;
	}

	public function hasUnknownLocale(string $string_name): bool {
		return \dataphyre\localization::has_unknown_locale($string_name);
	}

	public function clearUnknown(?string $string_name=null): LocalizationMaintenanceResult {
		$count=$string_name===null
			? count(\dataphyre\localization::unknown_locales())
			: (\dataphyre\localization::has_unknown_locale($string_name) ? 1 : 0);
		$cleared=\dataphyre\localization::clear_unknown_locale($string_name);
		if($cleared===false){
			return new LocalizationMaintenanceResult(
				'clear_unknown',
				$string_name===null ? 'clear_unknown_locales_failed' : 'clear_unknown_locale_failed',
				false,
				false,
				$count
			);
		}
		if($count===0){
			return new LocalizationMaintenanceResult(
				'clear_unknown',
				$string_name===null ? 'no_unknown_locales' : 'unknown_locale_not_found',
				true,
				true,
				0
			);
		}
		return new LocalizationMaintenanceResult(
			'clear_unknown',
			$string_name===null ? 'cleared_unknown_locales' : 'cleared_unknown_locale',
			true,
			false,
			$count
		);
	}

	public function definitions(array $filters=[], int $limit=250, int $offset=0): LocaleDefinitionCatalog {
		return LocaleDefinitionCatalog::fromArray(
			\dataphyre\localization::locale_definitions($filters, $limit, $offset),
			$filters,
			$limit,
			$offset
		);
	}

	public function definition(
		string $type,
		string $language,
		string $name,
		?string $theme=null,
		?string $path=null
	): ?LocaleDefinition {
		$definition=\dataphyre\localization::locale_definition($type, $language, $name, $theme, $path);
		return is_array($definition) ? LocaleDefinition::fromArray($definition) : null;
	}

	public function saveDefinition(
		string $type,
		string $language,
		string $name,
		string $string,
		?string $theme=null,
		?string $path=null,
		bool $rebuild=true
	): LocalizationMaintenanceResult {
		$saved=\dataphyre\localization::save_locale_definition($type, $language, $name, $string, $theme, $path, $rebuild);
		return new LocalizationMaintenanceResult(
			'save_definition',
			$saved ? 'saved_definition' : 'save_definition_failed',
			$saved,
			false,
			$saved ? 1 : 0,
			$rebuild
		);
	}

	public function saveDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		$payloads=[];
		foreach($definitions as $definition){
			$payload=$this->normalizeDefinitionMutationPayload($definition);
			if($payload!==null){
				$payloads[]=$payload;
			}
		}
		$result=\dataphyre\localization::save_locale_definitions($payloads, $rebuild);
		return new LocaleDefinitionBatchResult(
			'save_definitions',
			(bool)($result['ok'] ?? false),
			(int)($result['requested'] ?? 0),
			(int)($result['processed'] ?? 0),
			(int)($result['skipped'] ?? 0),
			(bool)($result['rebuilt'] ?? false),
			(int)($result['rebuild_targets'] ?? 0)
		);
	}

	public function deleteDefinition(
		string $type,
		string $language,
		string $name,
		?string $theme=null,
		?string $path=null,
		bool $rebuild=true
	): LocalizationMaintenanceResult {
		$existing=\dataphyre\localization::locale_definition($type, $language, $name, $theme, $path);
		if(!is_array($existing)){
			return new LocalizationMaintenanceResult(
				'delete_definition',
				'definition_not_found',
				true,
				true,
				0,
				$rebuild
			);
		}
		$deleted=\dataphyre\localization::delete_locale_definition($type, $language, $name, $theme, $path, $rebuild);
		return new LocalizationMaintenanceResult(
			'delete_definition',
			$deleted ? 'deleted_definition' : 'delete_definition_failed',
			$deleted,
			false,
			$deleted ? 1 : 0,
			$rebuild
		);
	}

	public function deleteDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		$payloads=[];
		foreach($definitions as $definition){
			$payload=$this->normalizeDefinitionMutationPayload($definition);
			if($payload!==null){
				$payloads[]=$payload;
			}
		}
		$result=\dataphyre\localization::delete_locale_definitions($payloads, $rebuild);
		return new LocaleDefinitionBatchResult(
			'delete_definitions',
			(bool)($result['ok'] ?? false),
			(int)($result['requested'] ?? 0),
			(int)($result['processed'] ?? 0),
			(int)($result['skipped'] ?? 0),
			(bool)($result['rebuilt'] ?? false),
			(int)($result['rebuild_targets'] ?? 0)
		);
	}

	public function learnUnknown(): LocalizationMaintenanceResult {
		$result=\dataphyre\localization::learn_unknown_locales();
		if(is_int($result)){
			return new LocalizationMaintenanceResult('learn_unknown', 'learned', true, false, $result);
		}
		return match($result){
			'no_locales_to_learn'=>new LocalizationMaintenanceResult('learn_unknown', $result, true, true, 0),
			'already_learning_locales'=>new LocalizationMaintenanceResult('learn_unknown', $result, false, true),
			default=>new LocalizationMaintenanceResult('learn_unknown', (string)$result, false),
		};
	}

	public function sync(bool $forced=false): LocalizationMaintenanceResult {
		\dataphyre\localization::sync_locales($forced);
		return new LocalizationMaintenanceResult(
			'sync',
			$forced ? 'synced_forced' : 'synced',
			true,
			false,
			null,
			$forced
		);
	}

	public function rebuild(
		array $types=[],
		array $languages=[],
		array $themes=[],
		array $paths=[]
	): LocalizationMaintenanceResult {
		return $this->rebuildSelection(new LocalizationRebuildSelection($types, $languages, $themes, $paths));
	}

	public function rebuildSelection(LocalizationRebuildSelection $selection): LocalizationMaintenanceResult {
		$result=\dataphyre\localization::rebuild_locale(
			$selection->types(),
			$selection->languages(),
			$selection->themes(),
			$selection->paths()
		);
		return new LocalizationMaintenanceResult(
			'rebuild',
			$result===false ? 'rebuild_failed' : 'rebuilt',
			$result!==false,
			false,
			null,
			false,
			$selection
		);
	}

	private function resolveLanguage(?string $language=null): string {
		$language=$language ?? $this->userLanguage() ?? $this->defaultLanguage() ?? 'en-CA';
		if(is_array($this->availableLanguages())){
			return $this->validateLanguage($language);
		}
		return $language;
	}

	private function resolveKeyContext(string $key, ?string $page=null, ?string $theme=null): array {
		$key=preg_replace('/\s+/', '', trim($key));
		$scope='local';
		if(str_starts_with($key, 'theme:')){
			$scope='theme';
			$key=substr($key, 6);
		}
		elseif(str_starts_with($key, 'global:')){
			$scope='global';
			$key=substr($key, 7);
		}
		elseif(str_starts_with($key, 'local:')){
			$key=substr($key, 6);
		}
		$path=$scope==='local'
			? $this->activePage($page)
			: '';
		return [
			'scope'=>$scope,
			'name'=>strtoupper($key),
			'path'=>$path,
			'theme'=>$theme,
		];
	}

	private function mergeCountParameters(int|float $count, ?array $parameters=null): array {
		$parameters=$parameters ?? [];
		return array_replace(
			[
				0=>$count,
				'count'=>$count,
			],
			$parameters
		);
	}

	private function normalizeDefinitionMutationPayload(mixed $definition): ?array {
		if($definition instanceof LocaleDefinitionMutation || $definition instanceof LocaleDefinition){
			return $definition->jsonSerialize();
		}
		return is_array($definition) ? $definition : null;
	}

	private function withStateOverrides(array $overrides, callable $callback): mixed {
		$filtered_overrides=[];
		foreach($overrides as $key=>$value){
			if($value!==null){
				$filtered_overrides[$key]=$value;
			}
		}
		if($filtered_overrides===[]){
			return $callback();
		}
		$state=\dataphyre\localization::state();
		try{
			\dataphyre\localization::apply_state(array_replace($state, $filtered_overrides));
			return $callback();
		}
		finally
		{
			\dataphyre\localization::apply_state($state);
		}
	}
}
