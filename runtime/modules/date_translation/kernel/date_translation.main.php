<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

/**
 * Translates formatted English date fragments into configured locale labels.
 *
 * Locale dictionaries are loaded lazily from application overrides first and
 * common Dataphyre config second. PHP arrays are used when opcache is enabled;
 * JSON dictionaries are used otherwise.
 */
class date_translation{
	
	private static $date_locales=[];
	
	/**
	 * Translates month, weekday, abstract, and ordinal date tokens.
	 *
	 * English locales return the input unchanged. French date formats receive
	 * additional ordering and ordinal handling for legacy display formats.
	 *
	 * @param string $string Formatted date string to translate.
	 * @param string $lang Locale key used to load a date_translation dictionary.
	 * @param string $format Original date format, used for locale-specific ordering.
	 * @return string|null Translated date string, or null if legacy loading leaves no locale payload.
	 */
	static function translate_date(string $string, string $lang, string $format, ?callable $locale_loader=null) : string|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(str_starts_with($lang, 'en')){
			return $string;
		}
		$chunks=explode(' ',$string);
		if(!isset(date_translation::$date_locales[$lang])){
			$date_locale=$locale_loader!==null ? $locale_loader($lang) : self::load_date_locale($lang);
			if(!is_array($date_locale) || !is_array($date_locale[$lang] ?? null)){
				return null;
			}
			date_translation::$date_locales[$lang]=$date_locale[$lang];
		}
		foreach($chunks as $key=>$chunk){
			$chunk=strtolower($chunk);
			foreach(date_translation::$date_locales[$lang]['abstract'] as $name=>$value){
				if($chunk==$name){
					$chunks[$key]=$value;
				}
			}
			foreach(date_translation::$date_locales[$lang]['months'] as $name=>$values){
				if($chunk==$name){
					$chunks[$key]=$values[0];
				}
				elseif(str_starts_with($chunk, substr($name,0,3))){
					$chunks[$key]=$values[1];
				}
			}
			foreach(date_translation::$date_locales[$lang]['weekdays'] as $name=>$values){
				if($chunk==$name){
					$chunks[$key]=$values[0];
				}
				elseif(str_starts_with($chunk, substr($name,0,3))){
					$chunks[$key]=$values[1];
				}
			}
			if(str_starts_with($lang, 'fr')){
				if(str_ends_with($chunk, "st") || str_ends_with($chunk, "nd") || str_ends_with($chunk, "rd") || str_ends_with($chunk, "th")){
					if($chunk==='1st'){
						$chunks[$key]='1er';
					}
					elseif($chunk==='2nd'){
						$chunks[$key]='2e';
					}
					else
					{
						$chunks[$key]=preg_replace('/\\b(\d+)(?:st|nd|rd|th)\\b/', '$1', $chunk);
					}
				}
			}
			else
			{
				if(str_ends_with($chunk, "st") || str_ends_with($chunk, "nd") || str_ends_with($chunk, "rd") || str_ends_with($chunk, "th")){
					$chunks[$key]=preg_replace('/\\b(\d+)(?:st|nd|rd|th)\\b/', '$1', $chunk);
				}
			}
		}
		if(str_starts_with($lang, 'fr')){
			if($format==='d M Y'){
				$chunks[0]="le ".$chunks[0];
			}
			elseif($format==='F j'){
				$chunks=array_reverse($chunks);
			}
			elseif($format==='F jS'){
				$chunks=array_reverse($chunks);
			}
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Done");
		$translated=implode(' ', $chunks);
		return $translated;
	}

	/**
	 * Loads one locale catalog through deterministic filesystem and format seams.
	 *
	 * @return ?array<string,mixed> Catalog keyed by language code.
	 */
	protected static function load_date_locale(
		string $lang,
		?bool $opcache_enabled=null,
		?callable $file_exists=null,
		?callable $php_loader=null,
		?callable $json_reader=null
	): ?array {
		$opcache_enabled??=ini_get('opcache.enable')==='1';
		$file_exists??=static fn(string $path): bool=>file_exists($path);
		$php_loader??=static function(string $path): ?array {
			$date_locale=null;
			require $path;
			return is_array($date_locale) ? $date_locale : null;
		};
		$json_reader??=static function(string $path): ?array {
			$decoded=json_decode((string)file_get_contents($path), true);
			return is_array($decoded) ? $decoded : null;
		};
		$extension=$opcache_enabled ? 'php' : 'json';
		$local=ROOTPATH['dataphyre'].'config/date_translation/languages/'.$lang.'.'.$extension;
		$common=ROOTPATH['common_dataphyre'].'config/date_translation/languages/'.$lang.'.'.$extension;
		$path=$file_exists($local) ? $local : $common;
		return $opcache_enabled ? $php_loader($path) : $json_reader($path);
	}

}
