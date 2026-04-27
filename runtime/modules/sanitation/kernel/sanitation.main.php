<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Module initialization");

class sanitation {

	public static function anonymize_email(string $str, int $count=2, string $char='*') : string {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_SANITATION_ANONYMIZE_EMAIL", ...func_get_args())) return $early_return;
		$str=trim($str);
		if($str==='' || str_contains($str, '@')===false){
			return '';
		}
		[$local, $domain]=explode('@', $str, 2);
		if($local===''){
			return '';
		}
		$count=max(0, min($count, strlen($local)));
		$mask_length=max(0, strlen($local)-$count);
		return substr($local, 0, $count).str_repeat($char, $mask_length).'@'.$domain;
	}

	public static function sanitize(
		mixed $value,
		mixed $datatype_or_trim="default",
		?bool $escape_html_legacy=null,
		?string $legacy_datatype=null
	) : string|bool {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		if(null!==$early_return=core::dialback("CALL_SANITATION_SANITIZE", ...func_get_args())) return $early_return;
		$options=self::resolve_sanitize_signature($datatype_or_trim, $escape_html_legacy, $legacy_datatype);
		$string=self::stringify_input($value);
		if($string===null){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Input to be sanitized was not scalar/stringable", $S="warning");
			return false;
		}
		if($options['trim']===true){
			$string=trim($string);
		}
		$datatype=self::normalize_datatype($options['datatype']);
		$html_level=false;
		$has_made_infraction=false;

		switch($datatype){
			case 'url':
				$url=self::sanitize_url($string);
				if($url===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$url;
				}
				break;
			case 'phone_number':
				$phone=self::sanitize_phone_number($string);
				if($phone===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$phone;
				}
				break;
			case 'basic_html':
				$html_level='basic';
				break;
			case 'unrestricted':
				$html_level='unrestricted';
				break;
			case 'text_nospecial':
				$string=self::sanitize_text_nospecial($string);
				break;
			case 'person_name':
				$string=self::sanitize_person_name($string);
				break;
			case 'email':
				$email=self::sanitize_email($string);
				if($email===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$email;
				}
				break;
			case 'numeric':
				$numeric=self::sanitize_numeric($string);
				if($numeric===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$numeric;
				}
				break;
			case 'integer':
				$integer=self::sanitize_integer($string);
				if($integer===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$integer;
				}
				break;
			case 'float':
				$float=self::sanitize_float($string);
				if($float===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$float;
				}
				break;
			case 'boolean':
				$boolean=self::sanitize_boolean($string);
				if($boolean===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$boolean;
				}
				break;
			case 'slug':
				$slug=self::sanitize_slug($string);
				if($slug===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$slug;
				}
				break;
			case 'username':
				$username=self::sanitize_username($string);
				if($username===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$username;
				}
				break;
			case 'postal_code':
				$postal_code=self::sanitize_postal_code($string);
				if($postal_code===false){
					$has_made_infraction=true;
				}
				else
				{
					$string=$postal_code;
				}
				break;
			case 'alphanumeric':
				$string=self::sanitize_alphanumeric($string);
				break;
			case 'ascii':
				$string=self::ascii_fold($string);
				break;
			case 'default':
				break;
			default:
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Unknown sanitation type: ".$datatype, $S="warning");
				$datatype='default';
				break;
		}

		if($has_made_infraction===true){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="String was dirty: ".$string, $S="warning");
			return false;
		}

		if($html_level==='unrestricted'){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="String has been sanitized: ".$string);
			return $string;
		}

		$string=self::clean_encoded_vectors($string);
		if($html_level===false){
			if($options['escape_html']===true){
				$string=htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
			}
		}
		elseif($html_level==='basic'){
			$string=self::sanitize_basic_html($string);
		}

		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="String has been sanitized: ".$string);
		return $string;
	}

	public static function sanitize_many(array $input, array $schema, bool $preserve_invalid=false) : array {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args());
		$sanitized=[];
		foreach($schema as $field=>$datatype){
			$raw=$input[$field] ?? null;
			$value=self::sanitize($raw, is_string($datatype) ? $datatype : "default");
			if($value===false){
				if($preserve_invalid===true){
					$sanitized[$field]=false;
				}
				continue;
			}
			$sanitized[$field]=$value;
		}
		return $sanitized;
	}

	protected static function resolve_sanitize_signature(mixed $datatype_or_trim, ?bool $escape_html_legacy, ?string $legacy_datatype) : array {
		$options=[
			'datatype'=>'default',
			'trim'=>true,
			'escape_html'=>true,
		];
		if(is_bool($datatype_or_trim)){
			$options['trim']=$datatype_or_trim;
			if($escape_html_legacy!==null){
				$options['escape_html']=$escape_html_legacy;
			}
			if(is_string($legacy_datatype) && trim($legacy_datatype)!==''){
				$options['datatype']=$legacy_datatype;
			}
			return $options;
		}
		if(is_string($datatype_or_trim) && trim($datatype_or_trim)!==''){
			$options['datatype']=$datatype_or_trim;
		}
		if($escape_html_legacy!==null){
			$options['escape_html']=$escape_html_legacy;
		}
		return $options;
	}

	protected static function stringify_input(mixed $value) : ?string {
		if(is_string($value)){
			return $value;
		}
		if(is_int($value) || is_float($value)){
			return (string)$value;
		}
		if(is_bool($value)){
			return $value ? '1' : '0';
		}
		if($value instanceof \Stringable){
			return (string)$value;
		}
		return null;
	}

	protected static function normalize_datatype(string $datatype) : string {
		$datatype=strtolower(trim($datatype));
		return match($datatype){
			'phone', 'tel'=>'phone_number',
			'name'=>'person_name',
			'int'=>'integer',
			'bool'=>'boolean',
			'postal'=>'postal_code',
			'text'=>'default',
			'html'=>'basic_html',
			default=>$datatype==='' ? 'default' : $datatype,
		};
	}

	protected static function sanitize_url(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$decoded=urldecode($string);
		$decoded=preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', static function(array $match): string {
			return chr(hexdec($match[1]));
		}, $decoded) ?? $decoded;
		$decoded=html_entity_decode($decoded, ENT_QUOTES, 'UTF-8');
		$decoded=htmlspecialchars_decode($decoded, ENT_QUOTES);
		if(preg_match("/<\s*[a-z]+\b[^>]*>/i", $decoded)===1){
			return false;
		}
		if(filter_var($string, FILTER_VALIDATE_URL)===false){
			return false;
		}
		return $string;
	}

	protected static function sanitize_phone_number(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^[0-9+\-(). \/]+$/', $string)===1 ? $string : false;
	}

	protected static function sanitize_email(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$email=filter_var($string, FILTER_VALIDATE_EMAIL);
		return $email===false ? false : $email;
	}

	protected static function sanitize_numeric(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^\d+$/', $string)===1 ? $string : false;
	}

	protected static function sanitize_integer(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^-?\d+$/', $string)===1 ? $string : false;
	}

	protected static function sanitize_float(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^-?(?:\d+|\d*\.\d+)$/', $string)===1 ? $string : false;
	}

	protected static function sanitize_boolean(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$normalized=strtolower(trim($string));
		return match($normalized){
			'1', 'true', 'yes', 'on'=>'1',
			'0', 'false', 'no', 'off'=>'0',
			default=>false,
		};
	}

	protected static function sanitize_slug(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$string=self::ascii_fold($string);
		$string=strtolower($string);
		$string=preg_replace('/[^a-z0-9]+/', '-', $string) ?? $string;
		$string=trim($string, '-');
		return $string==='' ? false : $string;
	}

	protected static function sanitize_username(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $string)===1 ? $string : false;
	}

	protected static function sanitize_postal_code(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$string=strtoupper(trim($string));
		return preg_match('/^[A-Z0-9][A-Z0-9 \-]{1,15}$/', $string)===1 ? $string : false;
	}

	protected static function sanitize_alphanumeric(string $string) : string {
		if($string===''){
			return '';
		}
		return preg_replace('/[^\p{L}\p{N}]/u', '', $string) ?? $string;
	}

	protected static function sanitize_text_nospecial(string $string) : string {
		if($string===''){
			return '';
		}
		return preg_replace("/[^\p{L}\p{N}'\x{2019},. \-]/u", '', $string) ?? $string;
	}

	protected static function sanitize_person_name(string $string) : string {
		if($string===''){
			return '';
		}
		$string=stripslashes($string);
		$string=preg_replace("/[^\p{L}\p{M}'\x{2019},. \-]/u", '', $string) ?? $string;
		$string=preg_replace('/\s+/u', ' ', trim($string)) ?? trim($string);
		if($string===''){
			return '';
		}
		$string=mb_convert_case(mb_strtolower($string, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
		foreach(['-', '\'', "\u{2019}"] as $delimiter){
			if(str_contains($string, $delimiter)===false){
				continue;
			}
			$string=implode($delimiter, array_map(static function(string $part): string {
				if($part===''){
					return $part;
				}
				return mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($part, 1, null, 'UTF-8');
			}, explode($delimiter, $string)));
		}
		return $string;
	}

	protected static function clean_encoded_vectors(string $string) : string {
		$string=str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $string);
		$string=preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $string) ?? $string;
		$string=preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $string) ?? $string;
		return html_entity_decode($string, ENT_COMPAT, 'UTF-8');
	}

	protected static function sanitize_basic_html(string $string) : string {
		$string=preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $string) ?? $string;
		$string=preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $string) ?? $string;
		$string=preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $string) ?? $string;
		$string=preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $string) ?? $string;
		$string=preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|input|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $string) ?? $string;
		$string=preg_replace('#</*\w+:\w[^>]*+>#i', '', $string) ?? $string;
		return $string;
	}

	protected static function ascii_fold(string $string) : string {
		if($string===''){
			return '';
		}
		if(function_exists('iconv')){
			$converted=@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
			if(is_string($converted) && $converted!==''){
				return $converted;
			}
		}
		return $string;
	}
}
