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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

class sanitation{
	
	public static function anonymize_email(string $str, int $count=2, string $char='*') : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SANITATION_ANONYMIZE_EMAIL",...func_get_args())) return $early_return;
		list($local, $domain)=explode("@",$str);
		return substr($local,0,$count).str_repeat($char,strlen($local)-$count)."@".$domain;
	}

	public static function sanitize(mixed $string, string $datatype="default") : string|bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_SANITATION_SANITIZE",...func_get_args())) return $early_return;
		if(!is_string($string)){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Input to be sanitized was not a string", $S="warning");
			return false;
		}
		$html_level=false;
		$has_made_infraction=false;
		if($datatype==='url'){
			$decoded_url=urldecode($string);
			$decoded_url=preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', function($match){return chr(hexdec($match[1]));}, $decoded_url);
			$decoded_url=html_entity_decode($decoded_url, ENT_QUOTES, 'UTF-8');
			$decoded_url=htmlspecialchars_decode($decoded_url, ENT_QUOTES);
			if(preg_match("/<\s*[a-z]+\b[^>]*>/i", $decoded_url)){
				$has_made_infraction=true;
				return core::url_self();
			}
			if(filter_var($string, FILTER_VALIDATE_URL)===false){
				$has_made_infraction=true;
			}
		}
		elseif($datatype==='phone_number'){
			$phone_pattern='/^[0-9+\-(). ]+$/';
			if(preg_match($phone_pattern, $string)===0){
				$has_made_infraction=true;
			}
		}
		elseif($datatype==='basic_html'){
			$html_level='basic';
		}
		elseif($datatype==='unrestricted'){
			$html_level='unrestricted';
		}
		elseif($datatype==='text_nospecial'){
			$string=preg_replace("/[^a-zA-Z0-9àâáçéèèêëìîíïôòóùûüÂÊÎÔúÛÄËÏÖÜÀÆæÇÉÈŒœÙñý'’,. ]/", "", $string);
		}
		elseif($datatype==='person_name'){
			$string=stripslashes($string);
			$string=preg_replace("/[^\-a-zA-ZàâáçéèèêëìîíïôòóùûüÂÊÎÔúÛÄËÏÖÜÀÆæÇÉÈŒœÙñý'’,. ]/", "", $string);
			$string=mb_strtolower($string);
			$string=ucwords(strtolower($string));
			foreach(array('-', '\'') as $delimiter){
				if(strpos($string, $delimiter)!==false){
					$string=implode($delimiter, array_map('ucfirst', explode($delimiter, $string)));
				}
			}
		}
		elseif($datatype==='email'){
			if(!preg_match("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$^", $string)){
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Badly formatted email address");
				$has_made_infraction=true;
			}
		}
		elseif($datatype==='default'){
			// Nothing too special happening here... Jérémie Fréreault - 2024-07-22
		}
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown sanitation type: ".$datatype, $S="fatal");
		}
		// Remove XSS injection stuff. Jérémie Fréreault - Prior to 2024-07-22
		$string=str_replace(array('&amp;','&lt;','&gt;'), array('&amp;amp;','&amp;lt;','&amp;gt;'), $string);
		$string=preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $string);
		$string=preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $string);
		$string=html_entity_decode($string, ENT_COMPAT, 'UTF-8'); // Decode utf8 codes into utf8 characters to prevent deliberate obfuscation of XSS. Jérémie Fréreault - 2024-07-22
		if($html_level===false){
			$string=htmlspecialchars($string, ENT_QUOTES, 'UTF-8'); // Escape html characters into html representations. Jérémie Fréreault - 2024-07-22
		}
		elseif($html_level==='basic'){
			$string=preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $string);
			$string=preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $string);
			$string=preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $string);
			$string=preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $string);
			$string=preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|input|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $string);
			$string=preg_replace('#</*\w+:\w[^>]*+>#i', '', $string);
		}
		if($has_made_infraction===true){ 
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="String was dirty: $string", $S="warning");
			return false; 
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="String has been sanitized: $string");
		return $string;
	}
	
}