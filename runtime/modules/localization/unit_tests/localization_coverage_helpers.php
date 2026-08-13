<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace dataphyre;

final class localization {
	public static array $state=[
		'default_language'=>'en-CA',
		'user_language'=>'en-CA',
		'user_theme'=>'light',
		'available_languages'=>['en-CA'=>'English', 'fr-CA'=>'Français'],
		'available_themes'=>['light'=>'Light', 'dark'=>'Dark'],
		'custom_parameters'=>['app'=>'Dataphyre'],
		'database_backed'=>true,
		'source'=>['kind'=>'coverage'],
	];
	public static array $unknown=[
		'missing.key'=>['theme'=>'light', 'path'=>'home', 'scope'=>'local', 'string'=>'Fallback', 'language'=>'en-CA'],
	];
	public static array $definitions=[
		'global|en-CA|||hello'=>[
			'id'=>1, 'language'=>'en-CA', 'theme'=>null, 'path'=>null,
			'type'=>'global', 'name'=>'hello', 'string'=>'Hello :name',
		],
		'global|en-CA|||zero'=>[
			'id'=>4, 'language'=>'en-CA', 'theme'=>null, 'path'=>null,
			'type'=>'global', 'name'=>'zero', 'string'=>':name',
		],
		'global|en-CA|||many'=>[
			'id'=>5, 'language'=>'en-CA', 'theme'=>null, 'path'=>null,
			'type'=>'global', 'name'=>'many', 'string'=>':count items',
		],
		'theme|en-CA|light||title'=>[
			'id'=>2, 'language'=>'en-CA', 'theme'=>'light', 'path'=>null,
			'type'=>'theme', 'name'=>'title', 'string'=>'Light title',
		],
		'local|en-CA|light|home|welcome'=>[
			'id'=>3, 'language'=>'en-CA', 'theme'=>'light', 'path'=>'home',
			'type'=>'local', 'name'=>'welcome', 'string'=>'Welcome :name',
		],
	];
	public static string|int $learnResult=1;
	public static bool $clearResult=true;

	public static function state(): array { return self::$state; }
	public static function apply_state(array $state): void { self::$state=$state; }
	public static function default_language(): ?string { return self::$state['default_language'] ?? null; }
	public static function user_language(): ?string { return self::$state['user_language'] ?? null; }
	public static function user_theme(): ?string { return self::$state['user_theme'] ?? null; }
	public static function available_languages(): ?array { return self::$state['available_languages'] ?? null; }
	public static function available_themes(): ?array { return self::$state['available_themes'] ?? null; }
	public static function active_page(?string $forced=null): string { return trim((string)($forced ?? 'home'), '/'); }
	public static function validate_language_code(string $language): string {
		$language=trim($language);
		return array_key_exists($language, self::$state['available_languages'] ?? []) ? $language : (self::default_language() ?? 'en-CA');
	}
	public static function locale_parameters(string $string, ?array $parameters=[]): string {
		$parameters=array_replace(self::$state['custom_parameters'] ?? [], $parameters ?? []);
		foreach($parameters as $key=>$value){
			$string=str_replace([':'.$key, '<{'.$key.'}>'], (string)$value, $string);
		}
		return $string;
	}
	public static function get_locales(string $scope, string $path, string $language): array {
		$theme=self::user_theme();
		$out=[];
		foreach(self::$definitions as $row){
			if($row['type']!==$scope || $row['language']!==$language){ continue; }
			if($scope==='theme' && $row['theme']!==$theme){ continue; }
			if($scope==='local' && ($row['theme']!==$theme || $row['path']!==$path)){ continue; }
			$out[strtoupper($row['name'])]=$row['string'];
		}
		return $out;
	}
	public static function locale(string $key, ?string $fallback=null, ?array $parameters=null, ?string $language=null, ?string $page=null): string {
		$language=$language ?: self::user_language() ?: self::default_language() ?: 'en-CA';
		$scope='global'; $name=$key; $path='';
		if(str_starts_with($key, 'global.') || str_starts_with($key, 'global:')){ $name=substr($key, 7); }
		elseif(str_starts_with($key, 'theme.') || str_starts_with($key, 'theme:')){ $scope='theme'; $name=substr($key, 6); }
		elseif(str_starts_with($key, 'local.') || str_starts_with($key, 'local:')){ $scope='local'; $name=substr($key, 6); $path=self::active_page($page); }
		$catalog=self::get_locales($scope, $path, $language);
		$catalogName=strtoupper($name);
		$value=$catalog[$catalogName] ?? $fallback ?? $key;
		if(!isset($catalog[$catalogName])){
			self::$unknown[$key]=['theme'=>self::user_theme(), 'path'=>$path, 'scope'=>$scope, 'string'=>$fallback, 'language'=>$language];
		}
		return self::locale_parameters((string)$value, $parameters);
	}
	public static function unknown_locales(): array { return self::$unknown; }
	public static function unknown_locale(string $name): array|false { return self::$unknown[$name] ?? false; }
	public static function has_unknown_locale(string $name): bool { return isset(self::$unknown[$name]); }
	public static function clear_unknown_locale(?string $name=null): bool {
		if(self::$clearResult===false){ return false; }
		if($name===null){ self::$unknown=[]; return true; }
		unset(self::$unknown[$name]); return true;
	}
	public static function locale_definitions(array $filters=[], int $limit=250, int $offset=0): array {
		$rows=array_values(array_filter(self::$definitions, static function(array $row) use($filters): bool {
			foreach($filters as $key=>$value){ if(($row[$key] ?? null)!=$value){ return false; } }
			return true;
		}));
		return array_slice($rows, $offset, $limit);
	}
	private static function definitionKey(string $type,string $language,string $name,?string $theme,?string $path): string {
		return implode('|',[$type,$language,$theme ?? '',$path ?? '',$name]);
	}
	public static function locale_definition(string $type,string $language,string $name,?string $theme=null,?string $path=null): array|false {
		return self::$definitions[self::definitionKey($type,$language,$name,$theme,$path)] ?? false;
	}
	public static function save_locale_definition(string $type,string $language,string $name,string $string,?string $theme=null,?string $path=null,bool $rebuild=true): bool {
		if(trim($name)===''){ return false; }
		self::$definitions[self::definitionKey($type,$language,$name,$theme,$path)]=compact('language','theme','path','type','name','string');
		return true;
	}
	public static function save_locale_definitions(array $rows,bool $rebuild=true): array {
		$processed=0;
		foreach($rows as $row){
			if(self::save_locale_definition((string)($row['type']??''),(string)($row['language']??''),(string)($row['name']??''),(string)($row['string']??''),$row['theme']??null,$row['path']??null,false)){ $processed++; }
		}
		return ['ok'=>true,'requested'=>count($rows),'processed'=>$processed,'skipped'=>count($rows)-$processed,'rebuilt'=>$rebuild,'rebuild_targets'=>$rebuild ? $processed : 0];
	}
	public static function delete_locale_definition(string $type,string $language,string $name,?string $theme=null,?string $path=null,bool $rebuild=true): bool {
		$key=self::definitionKey($type,$language,$name,$theme,$path);
		if(!isset(self::$definitions[$key])){ return false; }
		unset(self::$definitions[$key]); return true;
	}
	public static function delete_locale_definitions(array $rows,bool $rebuild=true): array {
		$processed=0;
		foreach($rows as $row){
			if(self::delete_locale_definition((string)($row['type']??''),(string)($row['language']??''),(string)($row['name']??''),$row['theme']??null,$row['path']??null,false)){ $processed++; }
		}
		return ['ok'=>true,'requested'=>count($rows),'processed'=>$processed,'skipped'=>count($rows)-$processed,'rebuilt'=>$rebuild,'rebuild_targets'=>$rebuild ? $processed : 0];
	}
	public static function learn_unknown_locales(): string|int { return self::$learnResult; }
	public static function sync_locales(bool $forced=false): void {}
	public static function rebuild_locale(array $types=[],array $languages=[],array $themes=[],array $paths=[]): bool {
		return !in_array('fail',$types,true);
	}
}
