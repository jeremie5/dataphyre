<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Stateful bridge for locale lookup, definition maintenance, and rebuild work.
 *
 * The manager wraps the localization kernel with typed state, scoped contexts,
 * deterministic lookup/fallback behavior, unknown-string capture, SQL-backed
 * definition mutation, sync, and generated locale rebuild results. It does not
 * authorize maintenance calls or HTML-escape translated strings; callers remain
 * responsible for access control and output-context escaping.
 */
final class LocalizationManager {

	private static ?self $instance=null;

	/**
	 * Returns the process-local localization manager.
	 *
	 * The singleton keeps framework callers on one bridge to the kernel state for
	 * the current PHP process; `flush()` forces a fresh bridge.
	 *
	 * @return self Active localization manager instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Clears the process-local localization manager singleton.
	 *
	 * Existing kernel localization state is not mutated here; active language,
	 * theme, page, unknown-locale buffers, and generated catalogs remain under
	 * kernel ownership. The next `instance()` call creates a new manager wrapper.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Returns the current kernel localization state as a value object.
	 *
	 * The state snapshot includes active language, theme, page, available catalogs,
	 * and other kernel-maintained localization metadata.
	 *
	 * @return LocalizationState Current localization state snapshot.
	 */
	public function state(): LocalizationState {
		return LocalizationState::fromArray(\dataphyre\localization::state());
	}

	/**
	 * Creates a scoped localization context.
	 *
	 * The returned context carries optional language, theme, and page overrides so
	 * chained lookups can run without permanently changing process-local kernel
	 * state.
	 *
	 * @param ?string $language Optional language override for lookups and catalogs.
	 * @param ?string $theme Optional theme override for themed catalogs.
	 * @param ?string $page Optional local page/path override.
	 * @return LocalizationContext Context bound to this manager.
	 */
	public function context(?string $language=null, ?string $theme=null, ?string $page=null): LocalizationContext {
		return new LocalizationContext($this, $language, $theme, $page);
	}

	/**
	 * Returns the configured default language.
	 *
	 * Null means the kernel has no configured default and callers should fall back through `resolveLanguage()`.
	 *
	 * @return ?string Default language code, or null when unavailable.
	 */
	public function defaultLanguage(): ?string {
		return \dataphyre\localization::default_language();
	}

	/**
	 * Returns the active user language.
	 *
	 * The value comes from kernel state and may be null before request/session language detection has run.
	 *
	 * @return ?string Active user language code, or null when unset.
	 */
	public function userLanguage(): ?string {
		return \dataphyre\localization::user_language();
	}

	/**
	 * Returns the active user theme.
	 *
	 * Theme-aware lookups can override this value temporarily through `withStateOverrides()`.
	 *
	 * @return ?string Active theme code, or null when unset.
	 */
	public function userTheme(): ?string {
		return \dataphyre\localization::user_theme();
	}

	/**
	 * Returns languages exposed by the kernel.
	 *
	 * Null means the kernel does not currently expose a language list; callers should avoid treating that as an empty catalog.
	 *
	 * @return ?array Available language metadata or null when unavailable.
	 */
	public function availableLanguages(): ?array {
		return \dataphyre\localization::available_languages();
	}

	/**
	 * Returns themes exposed by the kernel.
	 *
	 * Null means the kernel does not currently expose a theme list; callers should avoid treating that as an empty catalog.
	 *
	 * @return ?array Available theme metadata or null when unavailable.
	 */
	public function availableThemes(): ?array {
		return \dataphyre\localization::available_themes();
	}

	/**
	 * Resolves the active page for local locale lookup.
	 *
	 * A forced page is passed through to the kernel so local keys can resolve
	 * against a caller-specified path instead of request state.
	 *
	 * @param ?string $forcedPage Optional local page/path override.
	 * @return string Active locale page/path.
	 */
	public function activePage(?string $forcedPage=null): string {
		return \dataphyre\localization::active_page($forcedPage);
	}

	/**
	 * Normalizes a language code through the kernel.
	 *
	 * Validation preserves the kernel's language-code rules for lookups, definition mutations, sync, and rebuild selection.
	 *
	 * @param string $language Candidate language code.
	 * @return string Kernel-normalized language code.
	 */
	public function validateLanguage(string $language): string {
		return \dataphyre\localization::validate_language_code($language);
	}

	/**
	 * Interpolates configured and caller-provided placeholders into locale text.
	 *
	 * Placeholder syntax and escaping stay delegated to the kernel so direct interpolation matches full locale lookup output.
	 *
	 * @param string $string Locale text before interpolation.
	 * @param ?array $parameters Placeholder values interpolated into the resolved locale string.
	 * @return string Interpolated locale text.
	 */
	public function parameters(string $string, ?array $parameters=[]): string {
		return \dataphyre\localization::locale_parameters($string, $parameters);
	}

	/**
	 * Checks whether a locale key exists in its resolved catalog.
	 *
	 * Key prefixes select global, theme, or local scope; local keys use the active
	 * page, and language/theme overrides are applied only for this lookup.
	 *
	 * @param string $key Locale key or object name being resolved.
	 * @param ?string $language Language override used for this catalog lookup.
	 * @param ?string $page Local page/path override used when the key resolves to local scope.
	 * @param ?string $theme Theme override used when the key resolves to theme scope.
	 * @return bool True when the scoped catalog contains the normalized key.
	 */
	public function has(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		$keyContext=$this->resolveKeyContext($key, $page, $theme);
		$catalog=$this->locales(
			$keyContext['scope'],
			$keyContext['path'],
			$this->resolveLanguage($language),
			$keyContext['theme']
		);
		return $catalog->has($keyContext['name']);
	}

	/**
	 * Checks whether a locale key is missing from its resolved catalog.
	 *
	 * This delegates to `has()` so scope parsing, language fallback, page resolution, and theme override rules remain identical.
	 *
	 * @param string $key Locale key or object name being resolved.
	 * @param ?string $language Language override used for this catalog lookup.
	 * @param ?string $page Local page/path override used when the key resolves to local scope.
	 * @param ?string $theme Theme override used when the key resolves to theme scope.
	 * @return bool True when the scoped catalog does not contain the normalized key.
	 */
	public function missing(
		string $key,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): bool {
		return !$this->has($key, $language, $page, $theme);
	}

	/**
	 * Translates a key only when its catalog entry exists.
	 *
	 * Missing keys return null without invoking the fallback string path; existing
	 * keys are translated with the same interpolation and scoped state as
	 * `translate()`.
	 *
	 * @param string $key Locale key or object name being resolved.
	 * @param ?array $parameters Placeholder values interpolated into the resolved locale string.
	 * @param ?string $language Language override used for this translation lookup.
	 * @param ?string $page Local page/path override used when the key resolves to local scope.
	 * @param ?string $theme Theme override used when the key resolves to theme scope.
	 * @return ?string Translated text, or null when the key is missing.
	 */
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

	/**
	 * Builds a typed locale catalog for the requested scope/path/language/theme.
	 *
	 * Theme is applied as a temporary kernel-state override while the catalog is read, then the previous state is restored.
	 *
	 * @param string $scope Locale catalog scope such as global, theme, or local.
	 * @param string $path Locale/panel path or dot-path used for lookup and generated targets.
	 * @param string $language Language code used when reading the catalog.
	 * @param ?string $theme Theme override applied while reading themed catalog data.
	 * @return LocaleCatalog Catalog wrapper for the resolved locale rows.
	 */
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

	/**
	 * Translates a locale key with optional fallback and interpolation.
	 *
	 * Language and theme overrides are scoped to the kernel call, preserving surrounding request state after the lookup finishes.
	 * Returned text is interpolated but not escaped for HTML, JavaScript, shell,
	 * or SQL contexts.
	 *
	 * @param string $key Locale key or object name being resolved.
	 * @param ?string $fallback Fallback string used when no locale catalog resolves the key.
	 * @param ?array $parameters Placeholder values interpolated into the resolved locale string.
	 * @param ?string $language Language override used for this translation lookup.
	 * @param ?string $page Local page/path override used when the key resolves to local scope.
	 * @param ?string $theme Theme override applied while translating themed catalog data.
	 * @return string Resolved locale text after fallback and interpolation.
	 */
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

	/**
	 * Selects and translates a pluralized locale key.
	 *
	 * Zero uses `$zeroKey` when provided, absolute one uses `$oneKey`, and every
	 * other count uses `$manyKey`; the count is injected into interpolation
	 * parameters.
	 *
	 * @param int|float $count Quantity used to choose zero, singular, or plural locale keys.
	 * @param string $oneKey Locale key used for singular counts.
	 * @param string $manyKey Locale key used for plural counts.
	 * @param ?string $zeroKey Optional locale key used when count is exactly zero.
	 * @param ?array $parameters Placeholder values interpolated into the resolved locale string.
	 * @param ?string $language Language override used for the selected translation key.
	 * @param ?string $page Local page/path override used when the selected key resolves to local scope.
	 * @param ?string $theme Theme override applied while translating themed catalog data.
	 * @return string Resolved pluralized locale text.
	 */
	public function choice(
		int|float $count,
		string $oneKey,
		string $manyKey,
		?string $zeroKey=null,
		?array $parameters=null,
		?string $language=null,
		?string $page=null,
		?string $theme=null
	): string {
		$selectedKey=$zeroKey!==null && (float)$count===0.0
			? $zeroKey
			: ((float)abs($count)===1.0 ? $oneKey : $manyKey);
		return $this->translate(
			$selectedKey,
			null,
			$this->mergeCountParameters($count, $parameters),
			$language,
			$page,
			$theme
		);
	}

	/**
	 * Returns unknown locale entries captured by fallback lookup.
	 *
	 * The catalog reflects the kernel's maintenance buffer for strings that were
	 * requested but not present in generated locale data.
	 *
	 * @return UnknownLocaleCatalog Captured unknown locale catalog.
	 */
	public function unknownLocales(): UnknownLocaleCatalog {
		return UnknownLocaleCatalog::fromArray(\dataphyre\localization::unknown_locales());
	}

	/**
	 * Returns one captured unknown locale entry.
	 *
	 * Missing entries return null instead of an empty value object so callers can
	 * distinguish absence from a captured entry whose fallback string is empty.
	 *
	 * @param string $stringName Unknown locale key captured by fallback resolution.
	 * @return ?UnknownLocaleEntry Captured entry, or null when the key has not been recorded.
	 */
	public function unknownLocale(string $stringName): ?UnknownLocaleEntry {
		$entry=\dataphyre\localization::unknown_locale($stringName);
		return is_array($entry) ? UnknownLocaleEntry::fromArray($stringName, $entry) : null;
	}

	/**
	 * Checks whether one unknown locale key has been captured.
	 *
	 * This reads the kernel unknown-locale buffer without clearing or promoting the entry.
	 *
	 * @param string $stringName Unknown locale key captured by fallback resolution.
	 * @return bool True when the unknown key exists in the capture buffer.
	 */
	public function hasUnknownLocale(string $stringName): bool {
		return \dataphyre\localization::has_unknown_locale($stringName);
	}

	/**
	 * Clears one unknown locale entry or the full unknown buffer.
	 *
	 * The result distinguishes successful no-op clears from failed kernel clear
	 * calls and reports how many entries were expected to be removed.
	 *
	 * @param ?string $stringName Unknown locale key captured by fallback resolution.
	 * @return LocalizationMaintenanceResult Clear outcome and affected count.
	 */
	public function clearUnknown(?string $stringName=null): LocalizationMaintenanceResult {
		$count=$stringName===null
			? count(\dataphyre\localization::unknown_locales())
			: (\dataphyre\localization::has_unknown_locale($stringName) ? 1 : 0);
		$cleared=\dataphyre\localization::clear_unknown_locale($stringName);
		if($cleared===false){
			return new LocalizationMaintenanceResult(
				'clear_unknown',
				$stringName===null ? 'clear_unknown_locales_failed' : 'clear_unknown_locale_failed',
				false,
				false,
				$count
			);
		}
		if($count===0){
			return new LocalizationMaintenanceResult(
				'clear_unknown',
				$stringName===null ? 'no_unknown_locales' : 'unknown_locale_not_found',
				true,
				true,
				0
			);
		}
		return new LocalizationMaintenanceResult(
			'clear_unknown',
			$stringName===null ? 'cleared_unknown_locales' : 'cleared_unknown_locale',
			true,
			false,
			$count
		);
	}

	/**
	 * Reads SQL-backed locale definitions.
	 *
	 * Filters, limit, and offset are passed to the kernel and echoed into the
	 * returned catalog wrapper for pagination-aware maintenance UIs.
	 *
	 * @param array<string,mixed> $filters Filters applied to locale definition catalog reads.
	 * @param int $limit Maximum number of catalog records returned.
	 * @param int $offset Definition offset for paginated catalog reads.
	 * @return LocaleDefinitionCatalog Definition rows with read filters and pagination metadata.
	 */
	public function definitions(array $filters=[], int $limit=250, int $offset=0): LocaleDefinitionCatalog {
		return LocaleDefinitionCatalog::fromArray(
			\dataphyre\localization::locale_definitions($filters, $limit, $offset),
			$filters,
			$limit,
			$offset
		);
	}

	/**
	 * Reads one SQL-backed locale definition.
	 *
	 * Missing kernel rows return null; existing rows are wrapped as typed definitions without mutating generated locale files.
	 *
	 * @param string $type Locale definition type or panel/action type.
	 * @param string $language Language code for the stored definition row.
	 * @param string $name Locale definition key stored in the SQL-backed catalog.
	 * @param ?string $theme Theme code for themed definition rows.
	 * @param ?string $path Locale or panel path associated with local definition rows.
	 * @return ?LocaleDefinition Stored definition row, or null when absent.
	 */
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

	/**
	 * Saves one SQL-backed locale definition.
	 *
	 * The kernel persists the row and optionally rebuilds generated locale targets;
	 * the result reports success, affected count, and rebuild intent. This method
	 * is a maintenance entry point and assumes the caller already enforced any
	 * administrative authorization required for editing locale definitions.
	 *
	 * @param string $type Locale definition type or panel/action type.
	 * @param string $language Language code for the stored definition row.
	 * @param string $name Locale definition key stored in the SQL-backed catalog.
	 * @param string $string Locale text before interpolation.
	 * @param ?string $theme Theme code for themed definition rows.
	 * @param ?string $path Locale or panel path associated with local definition rows.
	 * @param bool $rebuild Whether generated locale files should be rebuilt after mutation.
	 * @return LocalizationMaintenanceResult Save outcome and rebuild flag.
	 */
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

	/**
	 * Saves a batch of SQL-backed locale definitions.
	 *
	 * Typed definitions, mutations, and raw arrays are normalized into kernel
	 * mutation data; unsupported entries are skipped before the batch call. Batch
	 * saves have the same authorization boundary as `saveDefinition()`.
	 *
	 * @param array<int,array<string,mixed>|LocaleDefinition|LocaleDefinitionMutation> $definitions
	 * Locale definitions for batch mutation.
	 * @param bool $rebuild Whether generated locale files should be rebuilt after mutation.
	 * @return LocaleDefinitionBatchResult Requested, processed, skipped, rebuild, and target counts.
	 */
	public function saveDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		$mutationRows=[];
		foreach($definitions as $definition){
			$mutationData=$this->normalizeDefinitionMutationData($definition);
			if($mutationData!==null){
				$mutationRows[]=$mutationData;
			}
		}
		$result=\dataphyre\localization::save_locale_definitions($mutationRows, $rebuild);
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

	/**
	 * Deletes one SQL-backed locale definition.
	 *
	 * Missing rows are treated as successful no-ops; existing rows are deleted
	 * through the kernel and may trigger generated locale rebuilds. Callers must
	 * enforce maintenance permissions before deleting stored locale definitions.
	 *
	 * @param string $type Locale definition type or panel/action type.
	 * @param string $language Language code for the stored definition row.
	 * @param string $name Locale definition key stored in the SQL-backed catalog.
	 * @param ?string $theme Theme code for themed definition rows.
	 * @param ?string $path Locale or panel path associated with local definition rows.
	 * @param bool $rebuild Whether generated locale files should be rebuilt after mutation.
	 * @return LocalizationMaintenanceResult Delete outcome and rebuild flag.
	 */
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

	/**
	 * Deletes a batch of SQL-backed locale definitions.
	 *
	 * Typed definitions, mutations, and raw arrays are normalized into kernel
	 * mutation data; unsupported entries are skipped before the batch call. Batch
	 * deletes have the same authorization boundary as `deleteDefinition()`.
	 *
	 * @param array<int,array<string,mixed>|LocaleDefinition|LocaleDefinitionMutation> $definitions
	 * Locale definitions for batch mutation.
	 * @param bool $rebuild Whether generated locale files should be rebuilt after mutation.
	 * @return LocaleDefinitionBatchResult Requested, processed, skipped, rebuild, and target counts.
	 */
	public function deleteDefinitions(array $definitions, bool $rebuild=true): LocaleDefinitionBatchResult {
		$mutationRows=[];
		foreach($definitions as $definition){
			$mutationData=$this->normalizeDefinitionMutationData($definition);
			if($mutationData!==null){
				$mutationRows[]=$mutationData;
			}
		}
		$result=\dataphyre\localization::delete_locale_definitions($mutationRows, $rebuild);
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

	/**
	 * Promotes captured unknown locales into stored definitions.
	 *
	 * Kernel return codes are preserved in the maintenance result so callers can
	 * tell learned entries from no-op or already-running maintenance.
	 *
	 * @return LocalizationMaintenanceResult Learn outcome and learned count when available.
	 */
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

	/**
	 * Synchronizes generated locale targets from definition state.
	 *
	 * Forced sync bypasses stored timestamp checks; the kernel performs the
	 * filesystem work while this wrapper reports the requested mode.
	 *
	 * @param bool $forced Whether sync bypasses stored timestamp checks.
	 * @return LocalizationMaintenanceResult Sync outcome and forced flag.
	 */
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

	/**
	 * Rebuilds generated locale targets for selected dimensions.
	 *
	 * Empty filter arrays let the kernel rebuild its default target set; non-empty
	 * arrays constrain type, language, theme, and path targets.
	 *
	 * @param array<int,string> $types Locale definition type or panel/action type filters.
	 * @param array<int,string> $languages Language filters applied to generated rebuild targets.
	 * @param array<int,string> $themes Theme filters applied to generated rebuild targets.
	 * @param array<int,string> $paths Locale or panel path filters applied to generated rebuild targets.
	 * @return LocalizationMaintenanceResult Rebuild outcome with the selected filters.
	 */
	public function rebuild(
		array $types=[],
		array $languages=[],
		array $themes=[],
		array $paths=[]
	): LocalizationMaintenanceResult {
		return $this->rebuildSelection(new LocalizationRebuildSelection($types, $languages, $themes, $paths));
	}

	/**
	 * Rebuilds generated locale targets from a selection object.
	 *
	 * The selection object is passed through to the result so callers can report
	 * the exact filters used for the rebuild attempt.
	 *
	 * @param LocalizationRebuildSelection $selection Typed rebuild selection containing type/language/theme/path filters.
	 * @return LocalizationMaintenanceResult Rebuild outcome with the original selection.
	 */
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

	/**
	 * Resolves the effective language for a lookup or catalog operation.
	 *
	 * Explicit language wins, then user language, then default language,
	 * then the historical `en-CA` fallback. When the kernel exposes an available
	 * language list, the selected language is normalized through validation before
	 * use so catalog lookups and mutations share the same language normalization.
	 *
	 * @param string|null $language Optional caller-supplied language code.
	 * @return string Effective language code.
	 */
	private function resolveLanguage(?string $language=null): string {
		$language=$language ?? $this->userLanguage() ?? $this->defaultLanguage() ?? 'en-CA';
		if(is_array($this->availableLanguages())){
			return $this->validateLanguage($language);
		}
		return $language;
	}

	/**
	 * Parses a locale key into scope, name, path, and theme context.
	 *
	 * Keys may use `global:`, `theme:`, or `local:` prefixes. Whitespace is
	 * removed, names are uppercased for kernel catalog compatibility, and local
	 * keys resolve against the active page while global/theme keys have no local
	 * path.
	 *
	 * @param string $key Locale key with optional scope prefix.
	 * @param string|null $page Optional active page override for local keys.
	 * @param string|null $theme Optional theme override for themed lookup.
	 * @return array{scope:string,name:string,path:string,theme:?string} Normalized key context.
	 */
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

	/**
	 * Adds pluralization count placeholders to interpolation parameters.
	 *
	 * The numeric count is available both as positional parameter `0` and
	 * named parameter `count`, while caller-supplied parameters can intentionally
	 * override either key.
	 *
	 * @param int|float $count Quantity used for plural choice.
	 * @param array<string|int, mixed>|null $parameters Caller interpolation parameters.
	 * @return array<string|int, mixed> Parameters including count defaults.
	 */
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

	/**
	 * Normalizes one definition mutation input into kernel mutation data.
	 *
	 * Typed mutation/value objects serialize themselves; arrays are passed
	 * through for legacy callers; unsupported inputs are skipped by batch mutation
	 * methods instead of reaching the kernel with an ambiguous shape.
	 *
	 * @param mixed $definition Locale definition mutation, definition value, or raw array.
	 * @return array<string, mixed>|null Definition mutation data, or null when unsupported.
	 */
	private function normalizeDefinitionMutationData(mixed $definition): ?array {
		if($definition instanceof LocaleDefinitionMutation || $definition instanceof LocaleDefinition){
			return $definition->jsonSerialize();
		}
		return is_array($definition) ? $definition : null;
	}

	/**
	 * Temporarily applies localization state overrides while running a callback.
	 *
	 * Null override values are ignored, the current kernel state is restored in a
	 * finally block, and the callback result is returned unchanged. This keeps
	 * scoped language/theme lookups isolated from the process-local localization
	 * state used by other runtime work in the same request, but nested callers
	 * still observe the temporary state while the callback is executing.
	 *
	 * @param array<string, mixed> $overrides Kernel localization state overrides.
	 * @param callable $callback Work executed under the scoped state.
	 * @return mixed value returned by the callback while scoped localization state is active.
	 */
	private function withStateOverrides(array $overrides, callable $callback): mixed {
		$filteredOverrides=[];
		foreach($overrides as $key=>$value){
			if($value!==null){
				$filteredOverrides[$key]=$value;
			}
		}
		if($filteredOverrides===[]){
			return $callback();
		}
		$state=\dataphyre\localization::state();
		try{
			\dataphyre\localization::apply_state(array_replace($state, $filteredOverrides));
			return $callback();
		}
		finally
		{
			\dataphyre\localization::apply_state($state);
		}
	}
}
