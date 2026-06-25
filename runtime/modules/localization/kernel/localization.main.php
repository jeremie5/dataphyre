<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");
dp_define_module_config('localization', 'DP_LOCALIZATION_CFG', [
	'custom_parameters'=>[],
	'enable_theme_locales'=>true,
	'enable_global_locales'=>true,
	'database_backed'=>true,
	'locales_table'=>'locales',
	'source_branch'=>null,
	'source_commit'=>null,
	'source_repository_path'=>null,
	'detect_source_from_git'=>true,
	'default_language'=>'en-CA',
	'user_language'=>'en-CA',
	'translation_callback'=>null,
	'available_languages'=>null,
	'available_themes'=>null,
	'user_theme'=>null,
	'global_locale_path'=>null,
	'theme_locale_path'=>null,
	'local_locale_path'=>null,
]);

require_once(__DIR__."/localization.global.php");

if(RUN_MODE!=='diagnostic'){
	localization::init();	
}
else
{
	require_once(__DIR__.'/localization.diagnostic.php');
}

/**
 * Provides Dataphyre's legacy kernel localization runtime.
 *
 * The class keeps process-local locale caches, normalizes configured language
 * and theme state, resolves global/theme/local locale JSON files, records
 * unknown strings during development, persists definitions in SQL, and rebuilds
 * file-backed dictionaries from those definitions. Methods are static for
 * compatibility with the kernel module style, while construction applies the
 * active configuration and cache-file paths.
 */
class localization{

	public static $available_languages;
	public static $available_themes;

	private static $locale=[];
	private static $translation_callback;
	private static $default_language;
	private static $user_theme;
	private static $user_language;
	private static $custom_parameters;
	private static $enable_theme_locales;
	private static $enable_global_locales;
	private static $database_backed;
	private static $source_branch;
	private static $source_commit;
	private static $source_repository_path;
	private static $detect_source_from_git;
	private static $source_snapshot_cache;
	private static $rebuilder_running_lock_file;
	private static $learning_lock_file;
	private static $unknown_locales_file;
	private static $last_locale_sync_check_file;
	private static $last_locale_sync_file;
	private static $last_locales_file;
	private static $locales_table;
	private static $global_locale_path;
	private static $theme_locale_path;
	private static $local_locale_path;

	/**
	 * Returns the module configuration resolved by bootstrap.
	 *
	 * `DP_LOCALIZATION_CFG` is defined by `dp_define_module_config()` before this
	 * class is initialized. The array contains locale paths, table names,
	 * language/theme defaults, feature toggles, and translation callbacks.
	 *
	 * @return array<string,mixed> Bootstrap localization configuration.
	 */
	protected static function configured_initialization(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return DP_LOCALIZATION_CFG;
	}

	/**
	 * Merges caller initialization with configured defaults.
	 *
	 * Top-level initialization values replace configured defaults, while
	 * `custom_parameters` is merged so application-specific tokens can augment
	 * rather than discard module-level replacements.
	 *
	 * @param ?array<string,mixed> $initialization Optional runtime overrides.
	 * @return array<string,mixed> Resolved initialization payload.
	 */
	protected static function resolve_initialization(?array $initialization=null): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$configured=self::configured_initialization();
		$initialization=$initialization ?? [];
		$resolved=array_replace($configured, $initialization);
		$resolved['custom_parameters']=array_replace(
			is_array($configured['custom_parameters'] ?? null) ? $configured['custom_parameters'] : [],
			is_array($initialization['custom_parameters'] ?? null) ? $initialization['custom_parameters'] : []
		);
		return $resolved;
	}

	/**
	 * Initializes localization state and returns the runtime instance.
	 *
	 * Construction applies resolved configuration, cache paths, SQL table
	 * metadata, and the core display language side effect. Calling init again
	 * replaces the static runtime state with the new initialization payload.
	 *
	 * @param ?array<string,mixed> $initialization Optional runtime overrides.
	 * @return self Initialized localization runtime.
	 */
	public static function init(?array $initialization=null): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return new self($initialization);
	}

	/**
	 * Applies a resolved initialization payload to static runtime state.
	 *
	 * The method updates configured locale paths, language/theme lists, feature
	 * toggles, translation callback, and SQL table name. It also mirrors the user
	 * language into `core::$display_language` and defines the locale SQL table
	 * when SQL table helpers are loaded.
	 *
	 * @param array<string,mixed> $initialization Resolved initialization payload.
	 * @return void
	 */
	protected static function apply_resolved_initialization(array $initialization): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		self::$custom_parameters=$initialization['custom_parameters'];
		self::$enable_theme_locales=$initialization['enable_theme_locales'];
		self::$enable_global_locales=$initialization['enable_global_locales'];
		self::$database_backed=(bool)($initialization['database_backed'] ?? true);
		self::$locales_table=$initialization['locales_table'];
		self::$source_branch=$initialization['source_branch'] ?? null;
		self::$source_commit=$initialization['source_commit'] ?? null;
		self::$source_repository_path=$initialization['source_repository_path'] ?? null;
		self::$detect_source_from_git=(bool)($initialization['detect_source_from_git'] ?? true);
		self::$source_snapshot_cache=null;
		self::$default_language=$initialization['default_language'];
		self::$user_language=$initialization['user_language'];
		self::$translation_callback=$initialization['translation_callback'];
		self::$available_languages=$initialization['available_languages'];
		self::$available_themes=$initialization['available_themes'];
		self::$user_theme=$initialization['user_theme'];
		self::$global_locale_path=$initialization['global_locale_path'];
		self::$theme_locale_path=$initialization['theme_locale_path'];
		self::$local_locale_path=$initialization['local_locale_path'];
		\dataphyre\core::$display_language=self::$user_language;
		if(self::$database_backed && function_exists('sql_define_table')){
			sql_define_table((string)self::$locales_table, __DIR__.'/localization.tables.php', 'locales');
		}
	}

	/**
	 * Captures the current localization runtime state.
	 *
	 * The returned payload can be fed to `apply_state()` in tests or diagnostic
	 * contexts to restore configuration-sensitive behavior without re-reading
	 * bootstrap state.
	 *
	 * @return array<string,mixed> Current runtime state snapshot.
	 */
	public static function state(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return [
			'custom_parameters'=>self::$custom_parameters,
			'enable_theme_locales'=>self::$enable_theme_locales,
			'enable_global_locales'=>self::$enable_global_locales,
			'database_backed'=>self::$database_backed,
			'locales_table'=>self::$locales_table,
			'source'=>self::source_snapshot(),
			'source_branch'=>self::$source_branch,
			'source_commit'=>self::$source_commit,
			'source_repository_path'=>self::$source_repository_path,
			'detect_source_from_git'=>self::$detect_source_from_git,
			'default_language'=>self::$default_language,
			'user_language'=>self::$user_language,
			'translation_callback'=>self::$translation_callback,
			'available_languages'=>self::$available_languages,
			'available_themes'=>self::$available_themes,
			'user_theme'=>self::$user_theme,
			'global_locale_path'=>self::$global_locale_path,
			'theme_locale_path'=>self::$theme_locale_path,
			'local_locale_path'=>self::$local_locale_path,
		];
	}

	/**
	 * Restores localization runtime state from a state payload.
	 *
	 * State is merged through the normal initialization resolver before being
	 * applied, so missing keys continue to inherit module defaults.
	 *
	 * @param array<string,mixed> $state State snapshot or partial overrides.
	 * @return void
	 */
	public static function apply_state(array $state): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		self::apply_resolved_initialization(self::resolve_initialization($state));
	}

	/**
	 * Returns the configured default language code.
	 *
	 * The value is used as the fallback when an explicit or user language is not
	 * available in the configured language catalog.
	 *
	 * @return ?string Default language code.
	 */
	public static function default_language(): ?string {
		return self::$default_language;
	}

	/**
	 * Returns the active user language code.
	 *
	 * Locale lookup and unknown-locale learning use this value unless a lookup
	 * call supplies a forced language.
	 *
	 * @return ?string Active user language code.
	 */
	public static function user_language(): ?string {
		return self::$user_language;
	}

	/**
	 * Returns the active user theme key.
	 *
	 * Theme and local locale scopes use this value to resolve dictionary files
	 * and to tag unknown locale discoveries.
	 *
	 * @return ?string Active user theme key.
	 */
	public static function user_theme(): ?string {
		return self::$user_theme;
	}

	/**
	 * Returns the configured language catalog.
	 *
	 * The catalog is usually keyed by language code and is used to validate
	 * definition payloads, rebuild targets, and unknown-locale translation loops.
	 *
	 * @return ?array<string,mixed> Available language map.
	 */
	public static function available_languages(): ?array {
		return self::$available_languages;
	}

	/**
	 * Returns the configured theme catalog.
	 *
	 * Rebuild calls can use this catalog when they request all themes through
	 * the `*` theme selector.
	 *
	 * @return ?array<int|string,mixed> Available theme list or map.
	 */
	public static function available_themes(): ?array {
		return self::$available_themes;
	}

	/**
	 * Resolves the active route path used for local locale dictionaries.
	 *
	 * A forced page wins for tests and explicit lookups. Otherwise the routing
	 * module page is normalized. When routing is unavailable, the runtime reports
	 * a safemode diagnostic and returns an empty path.
	 *
	 * @param ?string $forced_page Optional page path override.
	 * @return string Normalized page path beginning with `/`, or an empty string.
	 */
	public static function active_page(?string $forced_page=null): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($forced_page!==null){
			return self::normalize_local_path($forced_page);
		}
		if(class_exists('dataphyre\routing', false)){
			return self::normalize_local_path((string)\dataphyre\routing::$page);
		}
		\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Active page is unknown", "safemode");
		return '';
	}
	
	/**
	 * Creates a localization runtime and applies configuration.
	 *
	 * The constructor initializes cache and lock file paths under the Dataphyre
	 * cache root, then applies the resolved configuration. Static state is used
	 * by legacy callers, so constructing a new instance intentionally refreshes
	 * the process-wide localization runtime.
	 *
	 * @param ?array<string,mixed> $initialization Optional runtime overrides.
	 */
	function __construct(?array $initialization=null){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$initialization=self::resolve_initialization($initialization);
		self::$rebuilder_running_lock_file=ROOTPATH['dataphyre']."cache/locks/locale_rebuilding";
		self::$learning_lock_file=ROOTPATH['dataphyre']."cache/locks/locale_learning";
		self::$unknown_locales_file=ROOTPATH['dataphyre']."cache/unknown_locales";
		self::$last_locale_sync_check_file=ROOTPATH['dataphyre']."cache/last_locale_sync_check";
		self::$last_locale_sync_file=ROOTPATH['dataphyre']."cache/last_locale_sync";
		self::$last_locales_file=ROOTPATH['dataphyre']."cache/last_locales_file";
		self::apply_resolved_initialization($initialization);
	}
	
	/**
	 * Validates a language code against the configured language catalog.
	 *
	 * Unknown languages fall back to the configured default language. Calling the
	 * method before language metadata exists emits a safemode diagnostic because
	 * validation would otherwise be ambiguous.
	 *
	 * @param string $lang Candidate language code.
	 * @return string Valid language code or default fallback.
	 */
	public static function validate_language_code(string $lang): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		if(!isset(self::$available_languages)){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Available languages unknown when dataphyre\localization::validate_language_code() was called.", "safemode");
		}
		if(!isset(self::$available_languages[$lang])){
			$lang=self::$default_language;
		}
		return $lang;
	}

	/**
	 * Reads one locale JSON dictionary for a scope, path, and language.
	 *
	 * The path is resolved through the configured global/theme/local templates.
	 * Missing files return an empty array; existing files are JSON-decoded into
	 * the dictionary shape consumed by `locale()`.
	 *
	 * @param string $scope Locale scope: global, theme, or local.
	 * @param string $path Local page path for local dictionaries.
	 * @param string $language Language code.
	 * @return array<string,mixed> Decoded locale dictionary.
	 */
	public static function get_locales(string $scope, string $path, string $language) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$locales=[];
		$file_path=self::resolve_locale_file_path($scope, $language, self::$user_theme, $path);
		if($file_path!==null && file_exists($file_path)){
			$locales=json_decode(file_get_contents($file_path), true);
		}
		return $locales;
	}

	/**
	 * Normalizes a local locale page path.
	 *
	 * Empty paths remain empty. Non-empty paths are trimmed and guaranteed to
	 * start with `/` so path-based dictionary keys and file templates line up.
	 *
	 * @param string $path Raw page path.
	 * @return string Normalized page path.
	 */
	protected static function normalize_local_path(string $path): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$path=trim($path);
		if($path===''){
			return '';
		}
		if(!str_starts_with($path, '/')){
			$path='/'.$path;
		}
		return $path;
	}

	/**
	 * Resolves the JSON file path for a locale scope.
	 *
	 * Global paths substitute `%language%`; theme paths require a theme and
	 * substitute `%theme%` plus `%language%`; local paths also require a
	 * normalized active page for `%active_page%`. Unknown or incomplete scopes
	 * return null instead of guessing a file path.
	 *
	 * @param string $scope Locale scope.
	 * @param string $language Language code.
	 * @param ?string $theme Theme key for theme and local scopes.
	 * @param string $path Local page path for local scope.
	 * @return ?string Resolved locale file path.
	 */
	protected static function resolve_locale_file_path(string $scope, string $language, ?string $theme=null, string $path=''): ?string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($scope==="global"){
			return self::$global_locale_path!==null
				? str_replace('%language%', $language, self::$global_locale_path)
				: null;
		}
		if($scope==="theme"){
			if(self::$theme_locale_path===null || empty($theme)){
				return null;
			}
			return str_replace(['%theme%', '%language%'], [$theme, $language], self::$theme_locale_path);
		}
		if($scope==="local"){
			if(self::$local_locale_path===null || empty($theme)){
				return null;
			}
			$path=self::normalize_local_path($path);
			if($path===''){
				return null;
			}
			return str_replace(['%theme%', '%language%', '%active_page%'], [$theme, $language, $path], self::$local_locale_path);
		}
		return null;
	}

	/**
	 * Reports whether localization definitions are persisted through SQL.
	 *
	 * File-backed mode keeps runtime JSON dictionaries as the editable source and
	 * avoids SQL table registration, rebuild queries, and incremental sync work.
	 *
	 * @return bool True when SQL is the locale definition source of truth.
	 */
	public static function database_backed(): bool {
		return self::$database_backed;
	}

	/**
	 * Returns branch and commit metadata for file-backed locale edits.
	 *
	 * Explicit config wins, common CI/git environment variables are used next,
	 * and local git inspection is attempted only when `detect_source_from_git`
	 * remains enabled. Values are cached for the process after first resolution.
	 *
	 * @return array{branch:?string,commit:?string,repository:?string,detected_at:string}
	 */
	public static function source_snapshot(): array {
		if(is_array(self::$source_snapshot_cache)){
			return self::$source_snapshot_cache;
		}
		$repository=self::source_repository_path();
		$branch=self::normalize_source_value(self::$source_branch);
		$commit=self::normalize_source_value(self::$source_commit);
		if($branch===null){
			$branch=self::first_environment_value([
				'DATAPHYRE_SOURCE_BRANCH',
				'GITHUB_REF_NAME',
				'CI_COMMIT_REF_NAME',
				'BRANCH_NAME',
				'GIT_BRANCH',
			]);
		}
		if($commit===null){
			$commit=self::first_environment_value([
				'DATAPHYRE_SOURCE_COMMIT',
				'GITHUB_SHA',
				'CI_COMMIT_SHA',
				'GIT_COMMIT',
			]);
		}
		if(self::$detect_source_from_git && $repository!==null){
			if($branch===null){
				$branch=self::git_value($repository, ['rev-parse', '--abbrev-ref', 'HEAD']);
			}
			if($commit===null){
				$commit=self::git_value($repository, ['rev-parse', 'HEAD']);
			}
		}
		return self::$source_snapshot_cache=[
			'branch'=>$branch,
			'commit'=>$commit,
			'repository'=>$repository,
			'detected_at'=>date('c'),
		];
	}

	/**
	 * Normalizes configured or detected source metadata.
	 *
	 * @param mixed $value Candidate source value.
	 * @return ?string Non-empty string value or null.
	 */
	protected static function normalize_source_value(mixed $value): ?string {
		if($value===null){
			return null;
		}
		$value=trim((string)$value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Returns the first non-empty environment value from a candidate list.
	 *
	 * @param array<int,string> $names Environment variable names.
	 * @return ?string First non-empty value.
	 */
	protected static function first_environment_value(array $names): ?string {
		foreach($names as $name){
			$value=getenv($name);
			if($value!==false && trim((string)$value)!==''){
				return trim((string)$value);
			}
		}
		return null;
	}

	/**
	 * Resolves the nearest git repository for source metadata detection.
	 *
	 * @return ?string Repository path or null when none can be found.
	 */
	protected static function source_repository_path(): ?string {
		$configured=self::normalize_source_value(self::$source_repository_path);
		if($configured!==null){
			return realpath($configured) ?: $configured;
		}
		$start=defined('ROOTPATH') && is_array(ROOTPATH) && isset(ROOTPATH['dataphyre'])
			? ROOTPATH['dataphyre']
			: __DIR__;
		$path=realpath((string)$start) ?: realpath(__DIR__);
		for($i=0; $i<10 && is_string($path) && $path!==''; $i++){
			if(is_dir($path.DIRECTORY_SEPARATOR.'.git')){
				return $path;
			}
			$parent=dirname($path);
			if($parent===$path){
				break;
			}
			$path=$parent;
		}
		return null;
	}

	/**
	 * Runs a read-only git command and returns trimmed output.
	 *
	 * @param string $repository Git repository path.
	 * @param array<int,string> $arguments Git arguments.
	 * @return ?string Trimmed command output.
	 */
	protected static function git_value(string $repository, array $arguments): ?string {
		if(!function_exists('proc_open')){
			return null;
		}
		$command=array_merge(['git', '-C', $repository], array_map('strval', $arguments));
		$pipes=[];
		$process=@proc_open(
			$command,
			[
				0=>['pipe', 'r'],
				1=>['pipe', 'w'],
				2=>['pipe', 'w'],
			],
			$pipes
		);
		if(!is_resource($process)){
			return null;
		}
		if(isset($pipes[0]) && is_resource($pipes[0])){
			fclose($pipes[0]);
		}
		$value=isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : null;
		foreach($pipes as $pipe){
			if(is_resource($pipe)){
				fclose($pipe);
			}
		}
		proc_close($process);
		return self::normalize_source_value($value);
	}

	/**
	 * Reads a locale JSON file as an associative dictionary.
	 *
	 * Missing or invalid files are treated as empty dictionaries so file-backed
	 * mutation can create dictionaries lazily without surfacing SQL diagnostics.
	 *
	 * @param string $file_path Locale JSON file path.
	 * @return array<string,mixed> Locale dictionary.
	 */
	protected static function read_locale_file_data(string $file_path): array {
		if(!file_exists($file_path)){
			return [];
		}
		$data=json_decode((string)file_get_contents($file_path), true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Persists a locale dictionary to its JSON file.
	 *
	 * @param string $file_path Locale JSON file path.
	 * @param array<string,mixed> $data Locale dictionary.
	 * @return bool True when the file was written.
	 */
	protected static function write_locale_file_data(string $file_path, array $data): bool {
		ksort($data);
		return \dataphyre\core::file_put_contents_forced(
			$file_path,
			json_encode($data, JSON_UNESCAPED_UNICODE)
		)!==false;
	}

	/**
	 * Records source metadata beside a file-backed locale dictionary.
	 *
	 * Metadata is written to a sidecar so locale JSON remains a plain key/value
	 * dictionary for existing readers.
	 *
	 * @param string $file_path Locale JSON file path.
	 * @param string $name Locale key.
	 * @param string $operation save or delete.
	 * @return bool True when the sidecar is written.
	 */
	protected static function write_locale_file_source_metadata(string $file_path, string $name, string $operation): bool {
		$metadata_path=$file_path.'.meta.json';
		$metadata=[];
		if(file_exists($metadata_path)){
			$decoded=json_decode((string)file_get_contents($metadata_path), true);
			if(is_array($decoded)){
				$metadata=$decoded;
			}
		}
		$metadata['source']=self::source_snapshot();
		$metadata['entries']=is_array($metadata['entries'] ?? null) ? $metadata['entries'] : [];
		$metadata['entries'][$name]=[
			'operation'=>$operation,
			'updated_at'=>date('c'),
			'source'=>$metadata['source'],
		];
		ksort($metadata['entries']);
		return \dataphyre\core::file_put_contents_forced(
			$metadata_path,
			json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		)!==false;
	}

	/**
	 * Reads source metadata for one entry from a locale sidecar.
	 *
	 * @param string $file_path Locale JSON file path.
	 * @param string $name Locale key.
	 * @return ?array<string,mixed> Source metadata or null when absent.
	 */
	protected static function read_locale_file_source_metadata(string $file_path, string $name): ?array {
		$metadata_path=$file_path.'.meta.json';
		if(!file_exists($metadata_path)){
			return null;
		}
		$metadata=json_decode((string)file_get_contents($metadata_path), true);
		if(!is_array($metadata)){
			return null;
		}
		$entry=$metadata['entries'][$name] ?? null;
		if(is_array($entry) && is_array($entry['source'] ?? null)){
			return $entry['source'];
		}
		return is_array($metadata['source'] ?? null) ? $metadata['source'] : null;
	}

	/**
	 * Replaces locale parameter tokens in a translated string.
	 *
	 * Encoded token delimiters are restored, default runtime tokens are merged
	 * with configured custom parameters, and call-site parameters override by
	 * matching `<{name}>` placeholders.
	 *
	 * @param string $string Locale string containing optional `<{token}>` markers.
	 * @param ?array<string,string|int|float|bool> $parameters Call-site replacement values.
	 * @return string Locale string after parameter replacement.
	 */
	public static function locale_parameters(string $string, ?array $parameters=[]): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$string=str_replace('&lt;{','<{', str_replace('}&gt;','}>', $string));
		if(str_contains($string, '<{') && str_contains($string, '}>')){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale contains parameters");
			$replace=array_merge([
				"<{website_url}>"=>\dataphyre\core::url_self(),
				"<{current_year}>"=>date("Y"),
				"<{current_date}>"=>date("Y-m-d")
			], self::$custom_parameters);
			$string=str_replace(array_keys($replace), array_values($replace), $string);
			if(!empty($parameters)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Contains parameters defined at function call");
				$to_replace=[];
				foreach($parameters as $key=>$value){
					$to_replace[]="<{".$key."}>";
				}
				$string=str_replace($to_replace, $parameters, $string);
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Done");
		return $string;
	}

	/**
	 * Resolves a localized string by scope-aware locale key.
	 *
	 * Keys may be prefixed with `global:`, `theme:`, or `local:`; unprefixed keys
	 * are treated as local. The method checks in-memory dictionaries first,
	 * lazily loads the appropriate JSON file, attempts a rebuild when the file is
	 * missing or corrupt, records unknown fallback strings in development, and
	 * finally returns the fallback or normalized key with parameters applied.
	 *
	 * @param string $string_name Locale key or scope-prefixed key.
	 * @param ?string $fallback_string Fallback string used when the key is missing.
	 * @param ?array<string,string|int|float|bool> $parameters Placeholder replacements.
	 * @param ?string $forced_language Optional language override.
	 * @param ?string $forced_page Optional local page override.
	 * @return string Resolved locale string.
	 */
	public static function locale(string $string_name, ?string $fallback_string=null, ?array $parameters=null, ?string $forced_language=null, ?string $forced_page=null) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$user_theme=self::$user_theme;
		$user_language=self::$user_language;
		if(isset($forced_language)){
			$user_language=$forced_language;
		}
		if(empty($string_name)){
			return self::locale_parameters($fallback_string, $parameters);
		}
		$active_page=self::active_page($forced_page);
		$string_name=preg_replace('/\s+/', '', $string_name);
		if(str_starts_with($string_name, "theme:")){
			if(self::$enable_theme_locales===false){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Theme locales are disabled but $string_name is of type theme", "safemode");
			}
			$string_name=explode("theme:", $string_name)[1];
			$scope="theme";
			$path=str_replace(['%theme%', '%language%'], [$user_theme, $user_language], self::$theme_locale_path);
		}
		elseif(str_starts_with($string_name, "global:")){
			if(self::$enable_global_locales===false){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Global locales are disabled but $string_name is of type global", "safemode");
			}
			$string_name=explode("global:", $string_name)[1];
			$scope="global";
			$path=str_replace(['%language%'], [$user_language], self::$global_locale_path);
		}
		else
		{
			if(str_starts_with($string_name, "local:")){
				$string_name=explode("local:", $string_name)[1];
			}
			$scope="local";
			$path=self::resolve_locale_file_path($scope, $user_language, $user_theme, $active_page);
		}
		$string_name=strtoupper($string_name);
		if(isset($_SESSION['show_locale_names'])){
			return $string_name;
		}
		if(isset(self::$locale[$user_theme][$active_page][$string_name])){
			return self::locale_parameters(self::$locale[$user_theme][$active_page][$string_name], $parameters);
		}
		if(isset(self::$locale[$user_theme][$string_name])){
			return self::locale_parameters(self::$locale[$user_theme][$string_name], $parameters);
		}
		if(isset(self::$locale[$string_name])){
			return self::locale_parameters(self::$locale[$string_name], $parameters);
		}
		if($scope==="global"){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Reading locale file $path into memory");
			if(file_exists($path) && null!==$locale_data=json_decode(file_get_contents($path), true)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Done reading");
				self::$locale=array_merge(self::$locale, $locale_data);
				if(isset(self::$locale[$string_name])){
					return self::locale_parameters(self::$locale[$string_name], $parameters);
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale does not exist", "warning");	
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale file at $path is corrupted or does not exist, attempting rebuild.", "warning");
				if(self::$database_backed){
					self::rebuild_locale([$scope], [$user_language], [$user_theme], [$user_theme]);
				}
			}
		}
		elseif($scope==="theme"){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Reading locale file $path into memory");
			if(file_exists($path) && null!==$locale_data=json_decode(file_get_contents($path), true)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Done reading");
				self::$locale[$user_theme]=$locale_data;
				if(isset(self::$locale[$user_theme][$string_name])){
					return self::locale_parameters(self::$locale[$user_theme][$string_name], $parameters); 
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale does not exist", "warning");	
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale file at $path is corrupted or does not exist, attempting rebuild.", "warning");
				if(self::$database_backed){
					self::rebuild_locale([$scope], [$user_language], [$user_theme], [$user_theme]);
				}
			}
		}
		elseif($scope==="local"){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Reading locale file $path into memory");
			if(file_exists($path) && null!==$locale_data=json_decode(file_get_contents($path), true)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Done reading");
				self::$locale[$user_theme][$active_page]=$locale_data;
				if(isset(self::$locale[$user_theme][$active_page][$string_name])){
					return self::locale_parameters(self::$locale[$user_theme][$active_page][$string_name], $parameters);
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale does not exist", "warning");	
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locale file at $path is corrupted or does not exist, attempting rebuild.", "warning");
				if(self::$database_backed){
					self::rebuild_locale([$scope], [$user_language], [$user_theme], [$active_page]);
				}
			}
		}
		if(!empty($fallback_string)){
			self::create_unknown_locale_data($active_page, $scope, $string_name, $fallback_string);
			return self::locale_parameters($fallback_string, $parameters);
		}
		return self::locale_parameters($string_name, $parameters);
	}

	/**
	 * Records an unresolved locale string for later learning in development.
	 *
	 * Unknown locale capture is disabled outside non-production mode. When a
	 * fallback string is available, the locale key is normalized and persisted
	 * with theme, path, scope, source string, and detection language metadata.
	 *
	 * @param string $path Active local path associated with the missing string.
	 * @param string $scope Locale scope where lookup failed.
	 * @param string $string_name Locale key.
	 * @param string $string Fallback string to learn.
	 * @return bool|null True when a new unknown locale was written, false when disabled or unwritable, null when skipped.
	 */
	protected static function create_unknown_locale_data(string $path='', string $scope='', string $string_name='', string $string=''){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!defined('IS_PRODUCTION') || IS_PRODUCTION!==false){
			return false;
		}
		if(!empty(self::$user_language)){
			if(!empty($string)){
				$string_name=self::normalize_locale_name($string_name);
				$unknown_locales=self::read_unknown_locales_data();
				if(!array_key_exists($string_name, $unknown_locales)){
					$string_data[$string_name]=[
						'theme'=>self::$user_theme,
						'path'=>$path,
						'scope'=>$scope,
						'string'=>$string,
						'detection_lang'=>self::$user_language
					];
					$unknown_locales=array_merge($unknown_locales, $string_data);
					if(false===self::persist_unknown_locales_data($unknown_locales)){
						\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't write to unknown locale file", "safemode");
					}
					return true;
				}
			}
		}
		else
		{
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Invalid user language", "safemode");
		}
	}

	/**
	 * Normalizes a locale key for storage and lookup.
	 *
	 * Locale names are trimmed and uppercased so file dictionaries, SQL rows, and
	 * unknown-locale records use the same key identity.
	 *
	 * @param string $string_name Raw locale key.
	 * @return string Normalized locale key.
	 */
	protected static function normalize_locale_name(string $string_name): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return strtoupper(trim($string_name));
	}

	/**
	 * Validates and normalizes a locale definition type.
	 *
	 * Only global, theme, and local scopes are valid. Invalid values emit a
	 * safemode diagnostic and fall back to global so downstream SQL filters have
	 * a deterministic scope.
	 *
	 * @param string $type Raw locale type.
	 * @return string Normalized locale type.
	 */
	protected static function normalize_locale_type(string $type): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$type=strtolower(trim($type));
		if(!in_array($type, ['global', 'theme', 'local'], true)){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Invalid locale type {$type}", "safemode");
			return 'global';
		}
		return $type;
	}

	/**
	 * Normalizes the identity fields of a locale definition.
	 *
	 * The result captures scope, language, name, optional theme, and optional
	 * path. Global definitions strip theme/path, theme definitions require a
	 * theme, and local definitions require both theme and normalized path.
	 *
	 * @param string $type Locale scope.
	 * @param string $language Language code.
	 * @param string $name Locale key.
	 * @param ?string $theme Theme key for theme/local scopes.
	 * @param ?string $path Local page path for local scope.
	 * @return array{type:string,language:string,name:string,theme:?string,path:?string} Normalized definition identity.
	 */
	protected static function normalize_locale_definition(string $type, string $language, string $name, ?string $theme=null, ?string $path=null): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$type=self::normalize_locale_type($type);
		$language=trim($language);
		if($language===''){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Invalid locale language", "safemode");
		}
		if(is_array(self::$available_languages)){
			$language=self::validate_language_code($language);
		}
		$name=self::normalize_locale_name($name);
		$theme=$theme!==null ? trim($theme) : null;
		$path=$path!==null ? self::normalize_local_path($path) : null;
		if($type==='global'){
			$theme=null;
			$path=null;
		}
		elseif($type==='theme'){
			if($theme===null || $theme===''){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Theme locale definitions require a theme", "safemode");
			}
			$path=null;
		}
		else
		{
			if($theme===null || $theme===''){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Local locale definitions require a theme", "safemode");
			}
			if($path===null || $path===''){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Local locale definitions require a path", "safemode");
			}
		}
		return [
			'type'=>$type,
			'language'=>$language,
			'name'=>$name,
			'theme'=>$theme,
			'path'=>$path,
		];
	}

	/**
	 * Builds the SQL predicate for one normalized locale definition.
	 *
	 * Global definitions match by type, language, and name. Theme definitions add
	 * theme, and local definitions add both theme and path. Values remain bound
	 * variables for the SQL helper.
	 *
	 * @param array{type:string,language:string,name:string,theme:?string,path:?string} $definition Normalized definition identity.
	 * @return array{params:string,vars:list<mixed>} SQL predicate fragment and bound variables.
	 */
	protected static function locale_definition_where(array $definition): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$params="WHERE type=? AND lang=? AND name=?";
		$vars=[
			$definition['type'],
			$definition['language'],
			$definition['name'],
		];
		if($definition['type']==='theme'){
			$params.=" AND theme=?";
			$vars[]=$definition['theme'];
		}
		elseif($definition['type']==='local'){
			$params.=" AND theme=? AND path=?";
			$vars[]=$definition['theme'];
			$vars[]=$definition['path'];
		}
		return [
			'params'=>$params,
			'vars'=>$vars,
		];
	}

	/**
	 * Rebuilds the file target affected by one locale definition.
	 *
	 * Theme and path values are passed only when present so global definitions
	 * rebuild language files, theme definitions rebuild theme files, and local
	 * definitions rebuild the specific page dictionary.
	 *
	 * @param string $type Locale scope.
	 * @param string $language Language code.
	 * @param ?string $theme Optional theme key.
	 * @param ?string $path Optional local page path.
	 * @return bool True when rebuild did not report failure.
	 */
	protected static function locale_definition_rebuild(string $type, string $language, ?string $theme=null, ?string $path=null): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!self::$database_backed){
			return true;
		}
		$themes=$theme!==null && $theme!=='' ? [$theme] : [];
		$paths=$path!==null && $path!=='' ? [$path] : [];
		return self::rebuild_locale([$type], [$language], $themes, $paths)!==false;
	}

	/**
	 * Builds a de-duplication key for a locale rebuild target.
	 *
	 * Type, language, theme, and path are joined into a stable key so batch
	 * mutation methods rebuild each affected dictionary file only once.
	 *
	 * @param array<string,mixed> $definition Normalized definition identity.
	 * @return string Rebuild target key.
	 */
	protected static function locale_definition_target_key(array $definition): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return implode("\n", [
			(string)$definition['type'],
			(string)$definition['language'],
			(string)($definition['theme'] ?? ''),
			(string)($definition['path'] ?? ''),
		]);
	}

	/**
	 * Groups locale definitions by affected rebuild target.
	 *
	 * Invalid non-array entries are skipped. The map value keeps the target
	 * fields needed by `rebuild_locale_definition_targets()`.
	 *
	 * @param array<int,array<string,mixed>|mixed> $definitions Normalized definitions.
	 * @return array<string,array{type:string,language:string,theme:?string,path:?string}> Rebuild targets keyed by target identity.
	 */
	protected static function locale_definition_target_map(array $definitions): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$targets=[];
		foreach($definitions as $definition){
			if(!is_array($definition)){
				continue;
			}
			$targets[self::locale_definition_target_key($definition)]=[
				'type'=>$definition['type'],
				'language'=>$definition['language'],
				'theme'=>$definition['theme'] ?? null,
				'path'=>$definition['path'] ?? null,
			];
		}
		return $targets;
	}

	/**
	 * Rebuilds each unique locale dictionary target in a batch mutation.
	 *
	 * Targets are expected to come from `locale_definition_target_map()`. A false
	 * result from any target stops the batch and reports failure to the caller.
	 *
	 * @param array<string,array<string,mixed>|mixed> $targets Rebuild target map.
	 * @return bool True when all valid targets rebuild successfully.
	 */
	protected static function rebuild_locale_definition_targets(array $targets): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		foreach($targets as $target){
			if(!is_array($target)){
				continue;
			}
			if(false===self::locale_definition_rebuild(
				(string)$target['type'],
				(string)$target['language'],
				isset($target['theme']) ? (string)$target['theme'] : null,
				isset($target['path']) ? (string)$target['path'] : null
			)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalizes a locale definition payload from public CRUD methods.
	 *
	 * Payloads may use `language` or `lang`, and `string` or `value` for the
	 * translated text. When `$require_string` is true, missing string data emits
	 * a safemode diagnostic before returning the normalized shape.
	 *
	 * @param array<string,mixed> $definition Raw definition payload.
	 * @param bool $require_string Whether a translated string is required.
	 * @return array<string,mixed> Normalized definition payload.
	 */
	protected static function normalize_locale_definition_payload(array $definition, bool $require_string=false): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$normalized=self::normalize_locale_definition(
			(string)($definition['type'] ?? ''),
			(string)($definition['language'] ?? $definition['lang'] ?? ''),
			(string)($definition['name'] ?? ''),
			isset($definition['theme']) ? (string)$definition['theme'] : null,
			isset($definition['path']) ? (string)$definition['path'] : null
		);
		if($require_string){
			if(!array_key_exists('string', $definition) && !array_key_exists('value', $definition)){
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Locale definition payload requires a string", "safemode");
			}
			$normalized['string']=(string)($definition['string'] ?? $definition['value'] ?? '');
		}
		return $normalized;
	}

	/**
	 * Normalizes filters accepted by locale definition listing methods.
	 *
	 * Filters are reduced to known SQL columns, with type, language, path, and
	 * name passing through the same normalizers used for definition identities.
	 *
	 * @param array<string,mixed> $filters Raw filter map.
	 * @return array<string,mixed> SQL-safe filter map.
	 */
	protected static function normalize_locale_definition_filters(array $filters=[]): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$normalized_filters=[];
		if(isset($filters['type']) && trim((string)$filters['type'])!==''){
			$normalized_filters['type']=self::normalize_locale_type((string)$filters['type']);
		}
		$language=$filters['language'] ?? $filters['lang'] ?? null;
		if($language!==null && trim((string)$language)!==''){
			$normalized_filters['lang']=is_array(self::$available_languages)
				? self::validate_language_code(trim((string)$language))
				: trim((string)$language);
		}
		if(isset($filters['theme']) && trim((string)$filters['theme'])!==''){
			$normalized_filters['theme']=trim((string)$filters['theme']);
		}
		if(isset($filters['path']) && trim((string)$filters['path'])!==''){
			$normalized_filters['path']=self::normalize_local_path((string)$filters['path']);
		}
		if(isset($filters['name']) && trim((string)$filters['name'])!==''){
			$normalized_filters['name']=self::normalize_locale_name((string)$filters['name']);
		}
		return $normalized_filters;
	}

	/**
	 * Builds a bound SQL predicate from normalized locale definition filters.
	 *
	 * The caller supplies field names produced by
	 * `normalize_locale_definition_filters()`, so this helper only assembles the
	 * parameter fragment and bound variable list.
	 *
	 * @param array<string,mixed> $filters Normalized filters.
	 * @return array{params:string,vars:list<mixed>} SQL predicate fragment and variables.
	 */
	protected static function locale_definition_filters_where(array $filters=[]): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$params="WHERE 1=1";
		$vars=[];
		foreach($filters as $field=>$value){
			$params.=" AND {$field}=?";
			$vars[]=$value;
		}
		return [
			'params'=>$params,
			'vars'=>$vars,
		];
	}

	/**
	 * Converts a file-backed locale entry into the SQL-compatible row shape.
	 *
	 * @param array<string,mixed> $definition Normalized locale definition identity.
	 * @param string $string Locale text from the JSON dictionary.
	 * @return array<string,mixed> Locale definition row.
	 */
	protected static function file_locale_definition_row(array $definition, string $string, ?array $source=null): array {
		$source=$source ?? self::source_snapshot();
		return [
			'id'=>null,
			'lang'=>$definition['language'],
			'theme'=>$definition['theme'],
			'path'=>$definition['path'],
			'type'=>$definition['type'],
			'name'=>$definition['name'],
			'string'=>$string,
			'edit_time'=>null,
			'source_branch'=>$source['branch'],
			'source_commit'=>$source['commit'],
		];
	}

	/**
	 * Resolves the JSON file for a normalized locale definition.
	 *
	 * @param array<string,mixed> $definition Normalized locale definition identity.
	 * @return ?string Locale JSON file path.
	 */
	protected static function locale_definition_file_path(array $definition): ?string {
		return self::resolve_locale_file_path(
			$definition['type'],
			$definition['language'],
			$definition['theme'] ?? null,
			$definition['path'] ?? ''
		);
	}

	/**
	 * Reads one file-backed locale definition from JSON storage.
	 *
	 * @param array<string,mixed> $definition Normalized locale definition identity.
	 * @return ?array<string,mixed> Locale definition row or null when absent.
	 */
	protected static function file_locale_definition(array $definition): ?array {
		$file_path=self::locale_definition_file_path($definition);
		if($file_path===null){
			return null;
		}
		$data=self::read_locale_file_data($file_path);
		if(!array_key_exists($definition['name'], $data)){
			return null;
		}
		return self::file_locale_definition_row(
			$definition,
			(string)$data[$definition['name']],
			self::read_locale_file_source_metadata($file_path, $definition['name'])
		);
	}

	/**
	 * Saves one file-backed locale definition into its JSON dictionary.
	 *
	 * @param array<string,mixed> $definition Normalized locale definition identity.
	 * @param string $string Locale text.
	 * @return bool True when the JSON dictionary was written.
	 */
	protected static function save_file_locale_definition(array $definition, string $string): bool {
		$file_path=self::locale_definition_file_path($definition);
		if($file_path===null){
			return false;
		}
		$data=self::read_locale_file_data($file_path);
		$data[$definition['name']]=$string;
		return self::write_locale_file_data($file_path, $data)
			&& self::write_locale_file_source_metadata($file_path, $definition['name'], 'save');
	}

	/**
	 * Deletes one file-backed locale definition from its JSON dictionary.
	 *
	 * Missing keys are successful no-ops, matching SQL-backed delete semantics.
	 *
	 * @param array<string,mixed> $definition Normalized locale definition identity.
	 * @return bool True when the dictionary was already clear or written.
	 */
	protected static function delete_file_locale_definition(array $definition): bool {
		$file_path=self::locale_definition_file_path($definition);
		if($file_path===null){
			return false;
		}
		$data=self::read_locale_file_data($file_path);
		if(!array_key_exists($definition['name'], $data)){
			return true;
		}
		unset($data[$definition['name']]);
		return self::write_locale_file_data($file_path, $data)
			&& self::write_locale_file_source_metadata($file_path, $definition['name'], 'delete');
	}

	/**
	 * Reads the development unknown-locale queue from disk.
	 *
	 * Missing or unreadable files resolve to an empty queue. Corrupt JSON also
	 * resolves to an empty array so locale lookup does not fail because the
	 * learning queue is damaged.
	 *
	 * @return array<string,array<string,mixed>> Unknown locale records keyed by locale name.
	 */
	protected static function read_unknown_locales_data(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!file_exists(self::$unknown_locales_file)){
			return [];
		}
		$file='[]';
		if(false===($unknown_locale_data=file_get_contents(self::$unknown_locales_file))){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Unable to read unknown locales file", "warning");
		}
		else
		{
			$file=$unknown_locale_data;
		}
		$unknown_locales=json_decode($file, true);
		return is_array($unknown_locales) ? $unknown_locales : [];
	}

	/**
	 * Persists the development unknown-locale queue to disk.
	 *
	 * The write uses Dataphyre's forced file helper so parent directories are
	 * created as needed. Unicode strings are preserved for translator review.
	 *
	 * @param array<string,array<string,mixed>> $unknown_locales Unknown locale records.
	 * @return bool True when the queue was written.
	 */
	protected static function persist_unknown_locales_data(array $unknown_locales): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return \dataphyre\core::file_put_contents_forced(
			self::$unknown_locales_file,
			json_encode($unknown_locales, JSON_UNESCAPED_UNICODE)
		)!==false;
	}

	/**
	 * Returns all currently recorded unknown locale entries.
	 *
	 * This is the public inspection surface for development tools and diagnostic
	 * panels that help turn fallback strings into persisted locale definitions.
	 *
	 * @return array<string,array<string,mixed>> Unknown locale records.
	 */
	public static function unknown_locales(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return self::read_unknown_locales_data();
	}

	/**
	 * Returns one unknown locale entry by key.
	 *
	 * Null input and missing keys return null. Non-null keys are normalized the
	 * same way as locale lookup keys before the queue is inspected.
	 *
	 * @param ?string $string_name Locale key.
	 * @return ?array<string,mixed> Unknown locale record.
	 */
	public static function unknown_locale(?string $string_name): ?array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($string_name===null){
			return null;
		}
		$string_name=self::normalize_locale_name($string_name);
		$unknown_locales=self::read_unknown_locales_data();
		return is_array($unknown_locales[$string_name] ?? null) ? $unknown_locales[$string_name] : null;
	}

	/**
	 * Reports whether an unknown locale entry exists.
	 *
	 * The lookup delegates to `unknown_locale()` so key normalization and queue
	 * reading behavior remain centralized.
	 *
	 * @param string $string_name Locale key.
	 * @return bool True when an unknown locale record exists.
	 */
	public static function has_unknown_locale(string $string_name): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return self::unknown_locale($string_name)!==null;
	}

	/**
	 * Clears one unknown locale entry or the full unknown-locale queue.
	 *
	 * Passing null truncates the queue. Passing a key removes only that
	 * normalized entry; missing entries are treated as a successful no-op.
	 *
	 * @param ?string $string_name Optional locale key to clear.
	 * @return bool True when the queue was left clear or updated successfully.
	 */
	public static function clear_unknown_locale(?string $string_name=null): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($string_name===null){
			return self::persist_unknown_locales_data([]);
		}
		$string_name=self::normalize_locale_name($string_name);
		$unknown_locales=self::read_unknown_locales_data();
		if(!array_key_exists($string_name, $unknown_locales)){
			return true;
		}
		unset($unknown_locales[$string_name]);
		return self::persist_unknown_locales_data($unknown_locales);
	}

	/**
	 * Lists persisted locale definitions from SQL storage.
	 *
	 * Filters are normalized, limits are clamped to a bounded range, and results
	 * are ordered by scope, theme, path, language, and name for deterministic
	 * management UIs.
	 *
	 * @param array<string,mixed> $filters Optional definition filters.
	 * @param int $limit Maximum row count, clamped between 1 and 5000.
	 * @param int $offset Result offset.
	 * @return list<array<string,mixed>> Locale definition rows.
	 */
	public static function locale_definitions(array $filters=[], int $limit=250, int $offset=0): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$limit=max(1, min(5000, $limit));
		$offset=max(0, $offset);
		$normalized_filters=self::normalize_locale_definition_filters($filters);
		if(!self::$database_backed){
			$types=isset($normalized_filters['type']) ? [$normalized_filters['type']] : ['global', 'theme', 'local'];
			$languages=isset($normalized_filters['lang'])
				? [$normalized_filters['lang']]
				: (is_array(self::$available_languages) ? array_keys(self::$available_languages) : array_values(array_unique(array_filter([self::$user_language, self::$default_language]))));
			$available_themes=[];
			if(is_array(self::$available_themes)){
				foreach(self::$available_themes as $theme_key=>$theme_value){
					$available_themes[]=is_string($theme_key) ? $theme_key : (string)$theme_value;
				}
			}
			$themes=isset($normalized_filters['theme'])
				? [$normalized_filters['theme']]
				: array_values(array_unique(array_filter(array_merge(
					self::$user_theme!==null ? [self::$user_theme] : [],
					$available_themes
				))));
			$paths=isset($normalized_filters['path']) ? [$normalized_filters['path']] : [];
			$rows=[];
			foreach($types as $type){
				foreach($languages as $language){
					$targets=[];
					if($type==='global'){
						$targets[]=self::normalize_locale_definition('global', (string)$language, '*');
					}
					elseif($type==='theme'){
						foreach($themes as $theme){
							$targets[]=self::normalize_locale_definition('theme', (string)$language, '*', (string)$theme);
						}
					}
					elseif($paths!==[]){
						foreach($themes as $theme){
							foreach($paths as $path){
								$targets[]=self::normalize_locale_definition('local', (string)$language, '*', (string)$theme, (string)$path);
							}
						}
					}
					foreach($targets as $target){
						$file_path=self::locale_definition_file_path($target);
						if($file_path===null){
							continue;
						}
						foreach(self::read_locale_file_data($file_path) as $name=>$string){
							$definition=$target;
							$definition['name']=self::normalize_locale_name((string)$name);
							if(isset($normalized_filters['name']) && $definition['name']!==$normalized_filters['name']){
								continue;
							}
							$rows[]=self::file_locale_definition_row(
								$definition,
								(string)$string,
								self::read_locale_file_source_metadata($file_path, $definition['name'])
							);
						}
					}
				}
			}
			usort($rows, static fn(array $a, array $b): int => [$a['type'], $a['theme'], $a['path'], $a['lang'], $a['name']] <=> [$b['type'], $b['theme'], $b['path'], $b['lang'], $b['name']]);
			return array_slice($rows, $offset, $limit);
		}
		$where=self::locale_definition_filters_where($normalized_filters);
		$rows=sql_select(
			$S="id,lang,theme,path,type,name,string,edit_time",
			$L=self::$locales_table,
			$P=$where['params']." ORDER BY type ASC, theme ASC, path ASC, lang ASC, name ASC LIMIT ? OFFSET ?",
			$V=array_merge($where['vars'], [$limit, $offset]),
			$F=true,
			$C=false
		);
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Reads one persisted locale definition from SQL storage.
	 *
	 * Identity fields are normalized before the scoped SQL predicate is built,
	 * ensuring callers can use the same input shapes accepted by save/delete
	 * methods.
	 *
	 * @param string $type Locale scope.
	 * @param string $language Language code.
	 * @param string $name Locale key.
	 * @param ?string $theme Theme key for theme/local scopes.
	 * @param ?string $path Local page path for local scope.
	 * @return ?array<string,mixed> Locale definition row.
	 */
	public static function locale_definition(string $type, string $language, string $name, ?string $theme=null, ?string $path=null): ?array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$definition=self::normalize_locale_definition($type, $language, $name, $theme, $path);
		if(!self::$database_backed){
			return self::file_locale_definition($definition);
		}
		$where=self::locale_definition_where($definition);
		$row=sql_select(
			$S="id,lang,theme,path,type,name,string,edit_time",
			$L=self::$locales_table,
			$P=$where['params']." LIMIT 1",
			$V=$where['vars'],
			$F=false,
			$C=false
		);
		return is_array($row) ? $row : null;
	}

	/**
	 * Saves one locale definition and optionally rebuilds its dictionary file.
	 *
	 * The definition identity is normalized, the SQL row is inserted or updated
	 * through `upsert_locale()`, and the affected global/theme/local JSON file is
	 * rebuilt unless `$rebuild` is false.
	 *
	 * @param string $type Locale scope.
	 * @param string $language Language code.
	 * @param string $name Locale key.
	 * @param string $string Translated string.
	 * @param ?string $theme Theme key for theme/local scopes.
	 * @param ?string $path Local page path for local scope.
	 * @param bool $rebuild Whether to rebuild the affected JSON dictionary.
	 * @return bool True when save and optional rebuild succeeded.
	 */
	public static function save_locale_definition(
		string $type,
		string $language,
		string $name,
		string $string,
		?string $theme=null,
		?string $path=null,
		bool $rebuild=true
	): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$definition=self::normalize_locale_definition($type, $language, $name, $theme, $path);
		if(!self::$database_backed){
			return self::save_file_locale_definition($definition, $string);
		}
		self::upsert_locale(
			$definition['language'],
			(string)($definition['theme'] ?? ''),
			(string)($definition['path'] ?? ''),
			$definition['name'],
			$string,
			$definition['type'],
			$definition['type']==='global'
				? ""
				: ($definition['type']==='theme' ? "AND theme=?" : "AND theme=? AND path=?"),
			$definition['type']==='global'
				? []
				: ($definition['type']==='theme'
					? ['theme'=>$definition['theme']]
					: ['theme'=>$definition['theme'], 'path'=>$definition['path']])
		);
		if($rebuild){
			return self::locale_definition_rebuild(
				$definition['type'],
				$definition['language'],
				$definition['theme'],
				$definition['path']
			);
		}
		return true;
	}

	/**
	 * Deletes one locale definition and optionally rebuilds its dictionary file.
	 *
	 * The delete predicate is built from the normalized definition identity so
	 * theme and local scopes cannot accidentally delete broader records.
	 *
	 * @param string $type Locale scope.
	 * @param string $language Language code.
	 * @param string $name Locale key.
	 * @param ?string $theme Theme key for theme/local scopes.
	 * @param ?string $path Local page path for local scope.
	 * @param bool $rebuild Whether to rebuild the affected JSON dictionary.
	 * @return bool True when delete and optional rebuild succeeded.
	 */
	public static function delete_locale_definition(
		string $type,
		string $language,
		string $name,
		?string $theme=null,
		?string $path=null,
		bool $rebuild=true
	): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$definition=self::normalize_locale_definition($type, $language, $name, $theme, $path);
		if(!self::$database_backed){
			return self::delete_file_locale_definition($definition);
		}
		$where=self::locale_definition_where($definition);
		$deleted=sql_delete(
			self::$locales_table,
			$where['params'],
			$where['vars'],
			true
		);
		if($deleted===false){
			return false;
		}
		if($rebuild){
			return self::locale_definition_rebuild(
				$definition['type'],
				$definition['language'],
				$definition['theme'],
				$definition['path']
			);
		}
		return true;
	}

	/**
	 * Saves a batch of locale definitions with de-duplicated rebuilds.
	 *
	 * Valid array payloads are normalized first, then saved without immediate
	 * rebuild. Affected dictionary targets are rebuilt once per unique
	 * type/language/theme/path combination when requested.
	 *
	 * @param array<int,array<string,mixed>|mixed> $definitions Definition payloads.
	 * @param bool $rebuild Whether to rebuild affected dictionaries.
	 * @return array{ok:bool,requested:int,processed:int,skipped:int,rebuilt:bool,rebuild_targets:int} Batch save summary.
	 */
	public static function save_locale_definitions(array $definitions, bool $rebuild=true): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$normalized_definitions=[];
		foreach($definitions as $definition){
			if(is_array($definition)){
				$normalized_definitions[]=self::normalize_locale_definition_payload($definition, true);
			}
		}
		if($normalized_definitions===[]){
			return [
				'ok'=>true,
				'requested'=>0,
				'processed'=>0,
				'skipped'=>0,
				'rebuilt'=>false,
				'rebuild_targets'=>0,
			];
		}
		foreach($normalized_definitions as $definition){
			self::save_locale_definition(
				$definition['type'],
				$definition['language'],
				$definition['name'],
				$definition['string'],
				$definition['theme'],
				$definition['path'],
				false
			);
		}
		$targets=self::locale_definition_target_map($normalized_definitions);
		$rebuilt=false;
		if($rebuild){
			$rebuilt=self::rebuild_locale_definition_targets($targets);
			if($rebuilt===false){
				return [
					'ok'=>false,
					'requested'=>count($normalized_definitions),
					'processed'=>count($normalized_definitions),
					'skipped'=>0,
					'rebuilt'=>false,
					'rebuild_targets'=>count($targets),
				];
			}
		}
		return [
			'ok'=>true,
			'requested'=>count($normalized_definitions),
			'processed'=>count($normalized_definitions),
			'skipped'=>0,
			'rebuilt'=>$rebuilt,
			'rebuild_targets'=>count($targets),
		];
	}

	/**
	 * Deletes a batch of locale definitions with de-duplicated rebuilds.
	 *
	 * Missing definitions are counted as skipped. Existing definitions are
	 * deleted without immediate rebuild, then each affected dictionary target is
	 * rebuilt once when requested.
	 *
	 * @param array<int,array<string,mixed>|mixed> $definitions Definition identity payloads.
	 * @param bool $rebuild Whether to rebuild affected dictionaries.
	 * @return array{ok:bool,requested:int,processed:int,skipped:int,rebuilt:bool,rebuild_targets:int} Batch delete summary.
	 */
	public static function delete_locale_definitions(array $definitions, bool $rebuild=true): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$normalized_definitions=[];
		foreach($definitions as $definition){
			if(is_array($definition)){
				$normalized_definitions[]=self::normalize_locale_definition_payload($definition, false);
			}
		}
		if($normalized_definitions===[]){
			return [
				'ok'=>true,
				'requested'=>0,
				'processed'=>0,
				'skipped'=>0,
				'rebuilt'=>false,
				'rebuild_targets'=>0,
			];
		}
		$processed=0;
		$skipped=0;
		$targets=[];
		foreach($normalized_definitions as $definition){
			if(self::locale_definition(
				$definition['type'],
				$definition['language'],
				$definition['name'],
				$definition['theme'],
				$definition['path']
			)===null){
				$skipped++;
				continue;
			}
			$processed++;
			$targets[self::locale_definition_target_key($definition)]=[
				'type'=>$definition['type'],
				'language'=>$definition['language'],
				'theme'=>$definition['theme'],
				'path'=>$definition['path'],
			];
			if(false===self::delete_locale_definition(
				$definition['type'],
				$definition['language'],
				$definition['name'],
				$definition['theme'],
				$definition['path'],
				false
			)){
				return [
					'ok'=>false,
					'requested'=>count($normalized_definitions),
					'processed'=>$processed,
					'skipped'=>$skipped,
					'rebuilt'=>false,
					'rebuild_targets'=>count($targets),
				];
			}
		}
		$rebuilt=false;
		if($rebuild && $targets!==[]){
			$rebuilt=self::rebuild_locale_definition_targets($targets);
			if($rebuilt===false){
				return [
					'ok'=>false,
					'requested'=>count($normalized_definitions),
					'processed'=>$processed,
					'skipped'=>$skipped,
					'rebuilt'=>false,
					'rebuild_targets'=>count($targets),
				];
			}
		}
		return [
			'ok'=>true,
			'requested'=>count($normalized_definitions),
			'processed'=>$processed,
			'skipped'=>$skipped,
			'rebuilt'=>$rebuilt,
			'rebuild_targets'=>count($targets),
		];
	}

	/**
	 * Reads locale row ids already processed at the last sync timestamp.
	 *
	 * The sync cursor stores a timestamp separately from ids that shared that
	 * exact timestamp. This prevents rows edited in the same second from being
	 * skipped or repeatedly rebuilt across incremental sync runs.
	 *
	 * @return array<int,true> Processed row ids keyed by id.
	 */
	protected static function read_last_synced_locale_ids(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!file_exists(self::$last_locales_file)){
			return [];
		}
		$raw_ids=trim((string)file_get_contents(self::$last_locales_file));
		if($raw_ids===''){
			return [];
		}
		$ids=[];
		foreach(explode(',', $raw_ids) as $raw_id){
			$raw_id=trim($raw_id);
			if($raw_id!=='' && ctype_digit($raw_id)){
				$ids[(int)$raw_id]=true;
			}
		}
		return $ids;
	}

	/**
	 * Persists the incremental locale sync cursor.
	 *
	 * The timestamp is clamped to a non-negative integer and ids at that
	 * timestamp are written as a comma-delimited list for the next sync pass.
	 *
	 * @param int $timestamp Latest processed edit timestamp.
	 * @param array<int,true> $ids_at_timestamp Row ids processed at that timestamp.
	 * @return void
	 */
	protected static function persist_locale_sync_state(int $timestamp, array $ids_at_timestamp): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		\dataphyre\core::file_put_contents_forced(self::$last_locale_sync_file, (string)max(0, $timestamp));
		\dataphyre\core::file_put_contents_forced(
			self::$last_locales_file,
			implode(',', array_keys($ids_at_timestamp))
		);
	}

	/**
	 * Inserts or updates one locale SQL row for a normalized scope.
	 *
	 * Existing rows are updated only when the stored string changes. New rows
	 * include scope fields only when the scope supplies non-empty theme or path
	 * values, preserving the global/theme/local uniqueness model.
	 *
	 * @param string $language Language code.
	 * @param string $user_theme Theme key for scoped definitions.
	 * @param string $path Local page path for local definitions.
	 * @param string $string_name Normalized locale key.
	 * @param string $string Translated string.
	 * @param string $type Locale scope.
	 * @param string $scope_condition SQL predicate suffix for scoped uniqueness.
	 * @param array<string,mixed> $scope_values Bound scope values.
	 * @return void
	 */
	protected static function upsert_locale(string $language, string $user_theme, string $path, string $string_name, string $string, string $type, string $scope_condition, array $scope_values): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(false!==$row=sql_select(
			$S="id,string,name",
			$L=self::$locales_table,
			$P="WHERE lang=? $scope_condition AND name=? AND type=?",
			$V=array_merge([$language], array_values($scope_values), [$string_name, $type]),
			$F=false,
			$C=true
		)){
			if($row['string']!==$string){
				sql_update(
					$L=self::$locales_table,
					$F=["string"=>$string],
					$P="WHERE id=?",
					$V=[$row['id']],
					$CC=true,
					$Q='end'
				);
			}
		}
		else
		{
			$fields=[
				"lang"=>$language,
				"name"=>$string_name,
				"string"=>$string,
				"type"=>$type
			];
			foreach($scope_values as $key=>$value){
				if(!empty($value)){
					$fields[$key]=$value;
				}
			}
			sql_insert(
				$L=self::$locales_table, 
				$F=$fields, 
				$V=null, 
				$CC=true, 
				$Q='end'
			);
		}
	}

	/**
	 * Converts recorded unknown locale fallbacks into persisted locale definitions.
	 *
	 * A short-lived lock prevents concurrent learning. Each unknown fallback is
	 * written for every configured language, using the translation callback for
	 * non-default languages. Entries are removed from the unknown queue only
	 * after their locale rows have been queued for persistence.
	 *
	 * @return int|string Number of learned locale keys, or a string failure/status code.
	 */
	public static function learn_unknown_locales(): int|string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$i=0;
		$function_exit=function(string|int $error, ?callable $callback=null):string|int{
			if(file_exists($file=self::$learning_lock_file))
				unlink(self::$learning_lock_file);
			if(is_callable($callback)){
				$callback();
			}
			return $error;
		};
		if(file_exists(self::$learning_lock_file)){
			if((int)file_get_contents(self::$learning_lock_file)<strtotime("-1 minute")){
				unlink(self::$learning_lock_file);
			}
			else
			{
				return $function_exit("already_learning_locales");
			}
		}
		$unknown_locales=self::read_unknown_locales_data();
		if(empty($unknown_locales)){
			return $function_exit("no_locales_to_learn");
		}
		foreach($unknown_locales as $string_name=>$data){
			\dataphyre\core::file_put_contents_forced(self::$learning_lock_file, time());
			$i++;
			$new_string=$data['string'];
			$user_theme=$data['theme'];
			$scope=$data['scope'];
			$path=$data['path'];
			$languages_to_update=is_array(self::$available_languages) ? array_keys(self::$available_languages) : [];
			if(empty($languages_to_update)){
				return $function_exit("no_language_to_learn");
			}
			foreach($languages_to_update as $language){
				$string=$new_string;
				if($language!==self::$default_language){
					$translation_callback=self::$translation_callback;
					if(false===is_callable($translation_callback)){
						return $function_exit("no_translation_callback");
					}
					$translation=$translation_callback($language, $string);
					$string=html_entity_decode($translation);
				}
				if(!empty($string)){
					if(!self::$database_backed){
						if(false===self::save_locale_definition($scope, $language, $string_name, $string, $user_theme, $path, false)){
							return $function_exit("locale_file_unwritable");
						}
					}
					else
					{
						if($scope==="global"){
							self::upsert_locale($language, $user_theme, $path, $string_name, $string, 'global', "", []);
						}
						elseif($scope==="theme"){
							self::upsert_locale($language, $user_theme, $path, $string_name, $string, 'theme', "AND theme=?", ["theme"=>$user_theme]);
						}
						elseif($scope==="local"){
							self::upsert_locale($language, $user_theme, $path, $string_name, $string, 'local', "AND theme=? AND path=?", ["theme"=>$user_theme, "path"=>$path]);
						}
					}
				}
			}
			unset($unknown_locales[$string_name]);
			if(false===self::persist_unknown_locales_data($unknown_locales)){
				return $function_exit("unknown_locales_unwritable", function(){
					\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't write to unknown locale file", "safemode");
				});
			}
		}
		return $function_exit($i);
	}

	/**
	 * Incrementally rebuilds locale JSON files after SQL definition edits.
	 *
	 * Sync runs at most every five minutes unless forced and is skipped while a
	 * rebuild lock exists. It scans edited locale rows from the persisted cursor,
	 * rebuilds each affected dictionary target once per pass, and records both
	 * the latest edit timestamp and ids processed at that timestamp.
	 *
	 * @param bool $forced Whether to bypass the sync interval.
	 * @return void
	 */
	public static function sync_locales($forced=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!self::$database_backed){
			return;
		}
		if(!file_exists(self::$rebuilder_running_lock_file)){
			$last_sync_check=file_exists($file=self::$last_locale_sync_check_file) ? (int)file_get_contents($file) : 0;
			if($last_sync_check<strtotime("-5 minutes") || $last_sync_check===0 || $forced){
				\dataphyre\core::file_put_contents_forced(self::$last_locale_sync_check_file, time());
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locales are due to be synced to latest definitions");
				$last_update=file_exists($file=self::$last_locale_sync_file) ? (int)file_get_contents($file) : 0;
				$last_synced_ids=self::read_last_synced_locale_ids();
				$latest_synced_timestamp=$last_update;
				$latest_synced_ids=$last_synced_ids;
				$being_rebuilt=[];
				$per_page=500;
				$offset=0;
				while(false!==$rows=sql_select(
					$S="id,type,lang,theme,path,edit_time", 
					$L=self::$locales_table, 
					$P=[
						"mysql"=>"WHERE UNIX_TIMESTAMP(edit_time)>=? ORDER BY edit_time ASC, id ASC LIMIT ? OFFSET ?", 
						"postgresql"=>"WHERE extract(epoch from edit_time) >= ? ORDER BY edit_time ASC, id ASC LIMIT ? OFFSET ?"
					],
					$V=array($last_update, $per_page, $offset), 
					$F=true, 
					$C=false
				)){
					if(empty($rows)){
						break;
					}
					foreach($rows as $row){
						$row_id=(int)($row['id'] ?? 0);
						$row_timestamp=strtotime((string)($row['edit_time'] ?? ''));
						if($row_id<=0 || $row_timestamp===false || $row_timestamp<$last_update){
							continue;
						}
						if($row_timestamp===$last_update && isset($last_synced_ids[$row_id])){
							continue;
						}
						$rebuild_key=implode("\n", [
							(string)($row['type'] ?? ''),
							(string)($row['lang'] ?? ''),
							(string)($row['theme'] ?? ''),
							(string)($row['path'] ?? ''),
						]);
						if(!isset($being_rebuilt[$rebuild_key])){
							$being_rebuilt[$rebuild_key]=true;
							if(false===self::rebuild_locale(
								[(string)($row['type'] ?? '')],
								[(string)($row['lang'] ?? '')],
								[(string)($row['theme'] ?? '')],
								[(string)($row['path'] ?? '')]
							)){
								continue;
							}
						}
						if($row_timestamp>$latest_synced_timestamp){
							$latest_synced_timestamp=$row_timestamp;
							$latest_synced_ids=[];
						}
						if($row_timestamp===$latest_synced_timestamp){
							$latest_synced_ids[$row_id]=true;
						}
					}
					if(count($rows)<$per_page){
						break;
					}
					$offset+=$per_page;
				}
				if($latest_synced_timestamp!==$last_update || $latest_synced_ids!==$last_synced_ids){
					self::persist_locale_sync_state($latest_synced_timestamp, $latest_synced_ids);
				}
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Done syncing allowed amount of locales.");
			}
		}
	}

	/**
	 * Rebuilds file-backed locale dictionaries from SQL definitions.
	 *
	 * The rebuild lock is touched during long operations so sync and lookup
	 * callers can see that dictionary generation is in progress. Global,
	 * theme, and local dictionaries are written as JSON files according to the
	 * configured path templates; `*` selectors expand to configured languages or
	 * themes.
	 *
	 * @param ?array<int,string> $type Locale scopes to rebuild, or empty for all scopes.
	 * @param ?array<int,string> $lang Language codes, `*`, or empty for all languages.
	 * @param ?array<int,string> $theme Theme keys, `*`, or empty for no theme-specific rebuilds.
	 * @param ?array<int,string> $paths Local paths to rebuild, or empty to discover paths.
	 * @return bool|null False on write failure, null on completion.
	 */
	public static function rebuild_locale(?array $type=[], ?array $lang=[], ?array $theme=[], ?array $paths=[]){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!self::$database_backed){
			return null;
		}
		\dataphyre\core::file_put_contents_forced(self::$rebuilder_running_lock_file, "");
		if(in_array("*", $lang) || empty($lang)){
			$lang=array_keys(self::$available_languages);
		}
		if(in_array("*", $theme)){
			$theme=self::$available_themes;
		}
		if(!empty($lang)){
			foreach($lang as $language){
				\dataphyre\core::file_put_contents_forced(self::$rebuilder_running_lock_file, "");
				$theme_lang_data=[];
				if(in_array("global", $type) || empty($type)){
					if(false!==$global_locales=sql_select(
						$S="name,string", 
						$L=self::$locales_table, 
						$P="WHERE type='global' AND lang=? LIMIT 9999999", 
						$V=array($language), 
						$F=true, 
						$C=false
					)){
						$fullpath=str_replace('%language%', $language, self::$global_locale_path);
						foreach($global_locales as $global_locale){
							$theme_lang_data[$global_locale['name']]=$global_locale['string'];
						}
						if(false===\dataphyre\core::file_put_contents_forced($fullpath, json_encode($theme_lang_data, JSON_UNESCAPED_UNICODE))){
							unlink(self::$rebuilder_running_lock_file);
							return false;
						}
					}
				}
				if(!empty($theme)){
					foreach($theme as $user_theme){
						\dataphyre\core::file_put_contents_forced(self::$rebuilder_running_lock_file, "");
						$user_theme=explode('-', $user_theme)[0];
						if(!empty($user_theme)){
							$theme_lang_data=[];
							if(in_array("theme", $type) || empty($type)){
								if(false!==$theme_locales=sql_select(
									$S="name,string", 
									$L=self::$locales_table, 
									$P="WHERE type='theme' AND theme=? AND lang=? LIMIT 9999999", 
									$V=array($user_theme, $language), 
									$F=true, 
									$C=false
								)){
									$fullpath=str_replace(['%theme%', '%language%'], [$user_theme, $language], self::$theme_locale_path);
									foreach($theme_locales as $theme_locale){
										$theme_lang_data[$theme_locale['name']]=$theme_locale['string'];
									}
									if(false===\dataphyre\core::file_put_contents_forced($fullpath, json_encode($theme_lang_data, JSON_UNESCAPED_UNICODE))){
										if(!file_exists($fullpath)){
											\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't create theme global locale file $fullpath", "safemode");
										}
										else
										{
											\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't write to theme global locale file $fullpath", "safemode");
										}
										unlink(self::$rebuilder_running_lock_file);
										return false;
									}
								}
							}
							if(in_array("local", $type) || empty($type)){
								if(empty($paths)){
									$paths=[];
									if(false!==$local_locales=sql_select(
										$S="DISTINCT path", 
										$L=self::$locales_table, 
										$P="WHERE type='local' AND lang=? LIMIT 9999999", 
										$V=array($language), 
										$F=true, 
										$C=false
									)){
										foreach($local_locales as $local_locale){
											array_push($paths, $local_locale['path']);
										}
									}
								}
								if(!empty($paths)){
									foreach($paths as $path){
										\dataphyre\core::file_put_contents_forced(self::$rebuilder_running_lock_file, "");
										$theme_lang_data=[];
										if(false!==$pathed_local_locales=sql_select(
											$S="name,string", 
											$L=self::$locales_table, 
											$P="WHERE type='local' AND theme=? AND lang=? AND path=? LIMIT 9999999", 
											$V=array($user_theme, $language, $path), 
											$F=true, 
											$C=false
										)){
											foreach($pathed_local_locales as $pathed_local_locale){
												$theme_lang_data[$pathed_local_locale['name']]=$pathed_local_locale['string'];
											}
										}
										$fullpath=self::resolve_locale_file_path('local', $language, $user_theme, $path);
										if($fullpath===null){
											\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't resolve theme local locale file path", "safemode");
											unlink(self::$rebuilder_running_lock_file);
											return false;
										}
										if(false==$bytes=\dataphyre\core::file_put_contents_forced($fullpath, json_encode($theme_lang_data, JSON_UNESCAPED_UNICODE))){
											if(!file_exists($fullpath)){
												\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't create theme local locale file $fullpath", "safemode");
											}
											else
											{
												\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't write to theme local locale file $fullpath", "safemode");
											}
											unlink(self::$rebuilder_running_lock_file);
											return false;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		unlink(self::$rebuilder_running_lock_file);
	}

}
