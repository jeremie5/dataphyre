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
	'locales_table'=>'locales',
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

	protected static function configured_initialization(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return DP_LOCALIZATION_CFG;
	}

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

	public static function init(?array $initialization=null): self {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return new self($initialization);
	}

	protected static function apply_resolved_initialization(array $initialization): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		self::$custom_parameters=$initialization['custom_parameters'];
		self::$enable_theme_locales=$initialization['enable_theme_locales'];
		self::$enable_global_locales=$initialization['enable_global_locales'];
		self::$locales_table=$initialization['locales_table'];
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
	}

	public static function state(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return [
			'custom_parameters'=>self::$custom_parameters,
			'enable_theme_locales'=>self::$enable_theme_locales,
			'enable_global_locales'=>self::$enable_global_locales,
			'locales_table'=>self::$locales_table,
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

	public static function apply_state(array $state): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		self::apply_resolved_initialization(self::resolve_initialization($state));
	}

	public static function default_language(): ?string {
		return self::$default_language;
	}

	public static function user_language(): ?string {
		return self::$user_language;
	}

	public static function user_theme(): ?string {
		return self::$user_theme;
	}

	public static function available_languages(): ?array {
		return self::$available_languages;
	}

	public static function available_themes(): ?array {
		return self::$available_themes;
	}

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

	public static function get_locales(string $scope, string $path, string $language) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		$locales=[];
		$file_path=self::resolve_locale_file_path($scope, $language, self::$user_theme, $path);
		if($file_path!==null && file_exists($file_path)){
			$locales=json_decode(file_get_contents($file_path), true);
		}
		return $locales;
	}

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
				self::rebuild_locale([$scope], [$user_language], [$user_theme], [$user_theme]);
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
				self::rebuild_locale([$scope], [$user_language], [$user_theme], [$user_theme]);
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
				self::rebuild_locale([$scope], [$user_language], [$user_theme], [$active_page]);
			}
		}
		if(!empty($fallback_string)){
			self::create_unknown_locale_data($active_page, $scope, $string_name, $fallback_string);
			return self::locale_parameters($fallback_string, $parameters);
		}
		return self::locale_parameters($string_name, $parameters);
	}

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

	protected static function normalize_locale_name(string $string_name): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return strtoupper(trim($string_name));
	}

	protected static function normalize_locale_type(string $type): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$type=strtolower(trim($type));
		if(!in_array($type, ['global', 'theme', 'local'], true)){
			\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Invalid locale type {$type}", "safemode");
			return 'global';
		}
		return $type;
	}

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

	protected static function locale_definition_rebuild(string $type, string $language, ?string $theme=null, ?string $path=null): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$themes=$theme!==null && $theme!=='' ? [$theme] : [];
		$paths=$path!==null && $path!=='' ? [$path] : [];
		return self::rebuild_locale([$type], [$language], $themes, $paths)!==false;
	}

	protected static function locale_definition_target_key(array $definition): string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return implode("\n", [
			(string)$definition['type'],
			(string)$definition['language'],
			(string)($definition['theme'] ?? ''),
			(string)($definition['path'] ?? ''),
		]);
	}

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

	protected static function persist_unknown_locales_data(array $unknown_locales): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return \dataphyre\core::file_put_contents_forced(
			self::$unknown_locales_file,
			json_encode($unknown_locales, JSON_UNESCAPED_UNICODE)
		)!==false;
	}

	public static function unknown_locales(): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return self::read_unknown_locales_data();
	}

	public static function unknown_locale(?string $string_name): ?array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($string_name===null){
			return null;
		}
		$string_name=self::normalize_locale_name($string_name);
		$unknown_locales=self::read_unknown_locales_data();
		return is_array($unknown_locales[$string_name] ?? null) ? $unknown_locales[$string_name] : null;
	}

	public static function has_unknown_locale(string $string_name): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		return self::unknown_locale($string_name)!==null;
	}

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

	public static function locale_definitions(array $filters=[], int $limit=250, int $offset=0): array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$limit=max(1, min(5000, $limit));
		$offset=max(0, $offset);
		$where=self::locale_definition_filters_where(self::normalize_locale_definition_filters($filters));
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

	public static function locale_definition(string $type, string $language, string $name, ?string $theme=null, ?string $path=null): ?array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$definition=self::normalize_locale_definition($type, $language, $name, $theme, $path);
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

	protected static function persist_locale_sync_state(int $timestamp, array $ids_at_timestamp): void {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		\dataphyre\core::file_put_contents_forced(self::$last_locale_sync_file, (string)max(0, $timestamp));
		\dataphyre\core::file_put_contents_forced(
			self::$last_locales_file,
			implode(',', array_keys($ids_at_timestamp))
		);
	}

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
			unset($unknown_locales[$string_name]);
			if(false===self::persist_unknown_locales_data($unknown_locales)){
				return $function_exit("unknown_locales_unwritable", function(){
					\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't write to unknown locale file", "safemode");
				});
			}
		}
		return $function_exit($i);
	}

	public static function sync_locales($forced=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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

	public static function rebuild_locale(?array $type=[], ?array $lang=[], ?array $theme=[], ?array $paths=[]){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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
