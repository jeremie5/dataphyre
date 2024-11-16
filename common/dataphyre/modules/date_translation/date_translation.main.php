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

class date_translation{
	
	private static $date_locales=[];
	
	static function translate_date(string $string, string $lang, string $format) : string|null {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $rootpath;
		if(str_starts_with($lang, 'en')){
			return $string;
		}
		$chunks=explode(' ',$string);
		if(!isset(date_translation::$date_locales[$lang])){
			if(ini_get("opcache.enable")=="1"){
				if(file_exists($file=$rootpath['dataphyre']."config/date_translation/languages/".$lang.".php")){
					require_once($file);
				}
				else
				{
					require_once($rootpath['common_dataphyre']."config/date_translation/languages/".$lang.".php");
				}
			}
			else
			{
				if(file_exists($file=$rootpath['dataphyre']."config/date_translation/languages/".$lang.".json")){
					$date_locale=json_decode(file_get_contents($file), true);
				}
				else
				{
					$date_locale=json_decode(file_get_contents($rootpath['common_dataphyre']."config/date_translation/languages/".$lang.".json"), true);
				}
			}
			date_translation::$date_locales[$lang]=$date_locale[$lang];
			unset($date_locale);
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
	
}