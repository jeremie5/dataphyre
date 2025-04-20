<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

if(file_exists($filepath=ROOTPATH['common_dataphyre']."config/localization.php")){
	require_once($filepath);
}
if(file_exists($filepath=ROOTPATH['dataphyre']."config/localization.php")){
	require_once($filepath);
}

require(__DIR__."/localization.global.php");

if(RUN_MODE!=='diagnostic'){
	new localization();	
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
	private static $last_locale_sync_file;
	private static $last_locales_file;
	private static $locales_table;
	private static $global_locale_path;
	private static $theme_locale_path;
	private static $local_locale_path;
	
	function __construct(?array $initialization=null){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call_with_test', $A=func_get_args()); // Log the function call
		self::$rebuilder_running_lock_file=ROOTPATH['dataphyre']."cache/locks/locale_rebuilding";
		self::$learning_lock_file=ROOTPATH['dataphyre']."cache/locks/locale_learning";
		self::$unknown_locales_file=ROOTPATH['dataphyre']."cache/unknown_locales";
		self::$last_locale_sync_file=ROOTPATH['dataphyre']."cache/last_locale_sync";
		self::$last_locales_file=ROOTPATH['dataphyre']."cache/last_locales_file";
		self::$custom_parameters=$initialization['custom_parameters'] ?? [];
		self::$enable_theme_locales=$initialization['enable_theme_locales'] ?? true;
		self::$enable_global_locales=$initialization['enable_global_locales'] ?? true;
		self::$locales_table=$initialization['locales_table'] ?? 'locales';
		self::$default_language=$initialization['default_language'] ?? 'en-CA';
		self::$user_language=$initialization['user_language'] ?? 'en-CA';
		self::$translation_callback=$initialization['translation_callback'] ?? null;
		self::$available_languages=$initialization['available_languages'] ?? null;
		self::$available_themes=$initialization['available_themes'] ?? null;
		self::$user_theme=$initialization['user_theme'] ?? null;
		self::$global_locale_path=$initialization['global_locale_path'] ?? null;
		self::$theme_locale_path=$initialization['theme_locale_path'] ?? null;
		self::$local_locale_path=$initialization['local_locale_path'] ?? null;
		\dataphyre\core::$display_language=self::$user_language;
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
		if(str_starts_with($path, '/')){
			$path=str_replace(['%theme%', '%language%', '%active_page%'], [self::$user_theme, $language, $path], self::$local_locale_path);
			if($scope==="global"){
				$path=str_replace('%language%', $language, self::$global_locale_path);
			}
			elseif($scope==="theme"){
				$path=str_replace(['%theme%', '%language%'], [self::$user_theme, $language], self::$theme_locale_path);
			}
			if(file_exists($path)){
				$locales=json_decode(file_get_contents($path), true);
			}
		}
		return $locales;
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
		$active_page="";
		if(isset($forced_page)){
			$active_page=$forced_page;
		}
		else
		{
			if(class_exists('dataphyre\routing', false)){
				$active_page=\dataphyre\routing::$page;
			}
			else
			{
				\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Active page is unknown", "safemode");
			}
		}
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
			$path=str_replace(['%theme%', '%language%', '%active_page%'], [$user_theme, $user_language, $active_page], self::$local_locale_path);
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
				self::rebuild_locale([$scope], [$user_language], [$user_theme], [$user_theme]);
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
		if(!empty(self::$user_language)){
			if(!empty($string)){
				if(false===$file=file_get_contents(self::$unknown_locales_file)){
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Unable to read unknown locales file", "warning");
				}
				if(null===$unknown_locales=json_decode($file, true)){
					$unknown_locales=[];
				}
				if(!in_array($string_name, $unknown_locales)){
					$string_data[$string_name]=[
						'theme'=>self::$user_theme,
						'path'=>$path,
						'scope'=>$scope,
						'string'=>$string,
						'detection_lang'=>$user_language
					];
					$unknown_locales=array_merge($unknown_locales, $string_data);
					if(false===\dataphyre\core::file_put_contents_forced(self::$unknown_locales_file, json_encode($unknown_locales, JSON_UNESCAPED_UNICODE))){
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
			if(file_get_contents(self::$learning_lock_file)<strtotime("-1 minute")){
				unlink(self::$learning_lock_file);
			}
			return $function_exit("already_learning_locales");
		}
		if(null!==$unknown_locales=json_decode(file_get_contents(self::$unknown_locales_file), true)){
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
				$languages_to_update=array_keys(self::$available_languages);
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
				if(false===\dataphyre\core::file_put_contents_forced(self::$unknown_locales_file, json_encode($unknown_locales, JSON_UNESCAPED_UNICODE))){
					return $function_exit("unknown_locales_unwritable", function(){
						\dataphyre\core::unavailable(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D="Can't write to unknown locale file", "safemode");
					});
				}
			}
		}
		else
		{
			return $function_exit("invalid_unknown_locales");
		}
		return $function_exit($i);
	}

	public static function sync_locales($forced=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(!file_exists(self::$rebuilder_running_lock_file)){
			$last_sync=file_exists($file=self::$last_locale_sync_file) ? file_get_contents($file) : 0;
			if($last_sync<strtotime("-5 minutes") || $last_sync==false || $forced){
				\dataphyre\core::file_put_contents_forced(self::$last_locale_sync_file, time());
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S="Locales are due to be synced to latest definitions");
				$last_update=file_exists($file=self::$last_locale_sync_file) ? file_get_contents($file) : 0;
				$last_locales_file_synced=file_exists($file=self::$last_locales_file) ? file_get_contents($file) : "null";
				if(empty($last_locales_file_synced))$last_locales_file_synced="null";
				if(false!==$count=sql_count(
					$L=self::$locales_table, 
					$P=[
						"mysql"=>"WHERE UNIX_TIMESTAMP(edit_time)>? AND id NOT IN ($last_locales_file_synced)", 
						"postgresql"=>"WHERE edit_time>TO_TIMESTAMP(?) AND id NOT IN ($last_locales_file_synced)", 
					],
					$V=array(intval($last_update)), 
					$C=false
				)){
					$synced_locales=[];
					$per_page=500;
					$total_pages=ceil($count/$per_page);
					for($page=1; $page<=$total_pages; $page++){
						$offset=($page-1)*$per_page;
						if(false!==$rows=sql_select(
							$S="id,type,lang,theme,path,edit_time", 
							$L=self::$locales_table, 
							$P=[
								"mysql"=>"WHERE UNIX_TIMESTAMP(edit_time)>? AND id NOT IN (?) ORDER BY edit_time ASC LIMIT ? OFFSET ?", 
								"postgresql"=>"WHERE extract(epoch from edit_time) > $1 AND id NOT IN ($2) ORDER BY edit_time ASC LIMIT $3 OFFSET $4"
							],
							$V=array(intval($last_update), $last_locales_file_synced, $per_page, $offset), 
							$F=true, 
							$C=false
						)){
							foreach($rows as $row){
								if(!isset($being_rebuilt[$row['type']][$row['lang']][$row['theme']][$row['path']])){
									$being_rebuilt[$row['type']][$row['lang']][$row['theme']][$row['path']]=true;
									if(false!==rebuild_locale(array($row['type']), array($row['lang']), array($row['theme']), array($row['path']))){
										$synced_locales[]=$row['id'];
									}
								}
								\dataphyre\core::file_put_contents_forced(self::$last_locale_sync_file, strtotime($row['edit_time']));
							}
						}
					}
				}
				if(is_array($synced_locales)){
					\dataphyre\core::file_put_contents_forced(self::$last_locales_file, implode(',',$synced_locales));
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
										$fullpath=str_replace(['%theme%', '%language%', '%active_page%'], [$user_theme, $language, $path], self::$local_locale_path);
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