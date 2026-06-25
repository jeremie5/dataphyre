<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Module initialization");

/**
 * Kernel sanitation helpers for scalar input normalization and validation.
 *
 * The sanitation module converts supported raw inputs to strings, validates common
 * datatypes, strips risky encoded/HTML vectors, preserves legacy sanitize()
 * calling conventions, and exposes dialback hooks for application overrides. It
 * does not authorize callers, parameterize SQL, or make unrestricted/basic HTML
 * safe for every output context.
 */
class sanitation {

	/**
	 * Masks the local part of an email address while preserving its domain.
	 *
	 * Invalid or empty email-like input returns an empty string. The first $count
	 * characters of the local part remain visible and the rest are replaced by $char.
	 * A CALL_SANITATION_ANONYMIZE_EMAIL dialback may override the return value.
	 *
	 * @param string $str Email address to mask.
	 * @param int $count Number of local-part characters to leave visible.
	 * @param string $char Mask character or string.
	 * @return string Masked email address, or an empty string for invalid input.
	 */
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

	/**
	 * Sanitizes one scalar/stringable value for a declared datatype.
	 *
	 * Supported datatype aliases include url, phone/tel, email, numeric, int/integer,
	 * float, bool/boolean, slug, username, postal/postal_code, alphanumeric,
	 * text_nospecial, name/person_name, ascii, html/basic_html, unrestricted, text,
	 * and default. Invalid typed values return false; valid empty values return an
	 * empty string. Non-scalar/non-stringable values return false. A
	 * CALL_SANITATION_SANITIZE dialback may override the result. The unrestricted
	 * type skips encoded-vector cleanup and HTML escaping by design.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param mixed $datatype_or_trim Datatype string, or legacy trim flag.
	 * @param bool|null $escape_html_legacy Optional legacy HTML escaping flag.
	 * @param string|null $legacy_datatype Optional legacy datatype when second argument is trim flag.
	 * @return string|bool Sanitized string, or false when input violates the requested datatype.
	 */
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
				$options['escape_html']=false;
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

	/**
	 * Sanitizes multiple input fields according to a schema map.
	 *
	 * Schema keys select input fields and schema values name datatypes. Invalid values
	 * are omitted by default or preserved as false when $preserve_invalid is true.
	 *
	 * @param array<string, mixed> $input Raw input values keyed by field.
	 * @param array<string, string|mixed> $schema Datatype names keyed by field.
	 * @param bool $preserve_invalid True to include false for invalid fields.
	 * @return array<string, string|false> Sanitized field values.
	 */
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

	/**
	 * Normalizes modern and legacy sanitize() call signatures into options.
	 *
	 * Legacy calls may pass a boolean trim flag as the second argument, an HTML escape
	 * flag as the third argument, and the datatype as the fourth argument. Modern
	 * calls pass the datatype as the second argument and may still override HTML
	 * escaping with the third argument.
	 *
	 * @param mixed $datatype_or_trim Datatype string or legacy trim flag.
	 * @param bool|null $escape_html_legacy Optional HTML escape flag.
	 * @param string|null $legacy_datatype Optional legacy datatype.
	 * @return array{datatype:string, trim:bool, escape_html:bool} Sanitization options.
	 */
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

	/**
	 * Converts supported raw inputs into strings before sanitization.
	 *
	 * Strings, integers, floats, booleans, and Stringable objects are accepted. Arrays,
	 * objects without __toString(), resources, and null are rejected with null.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null String representation, or null when unsupported.
	 */
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

	/**
	 * Normalizes datatype aliases to canonical sanitizer keys.
	 *
	 * Empty datatypes and text resolve to default; phone/tel, name, int, bool, postal,
	 * and html map to their canonical sanitizer names.
	 *
	 * @param string $datatype Requested datatype or alias.
	 * @return string Canonical datatype key.
	 */
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

	/**
	 * Validates a URL and rejects encoded HTML tag vectors.
	 *
	 * The original URL string is preserved on success after validation. Empty strings
	 * are valid empty values.
	 *
	 * @param string $string Candidate URL.
	 * @return string|bool Original URL string, empty string, or false when invalid/unsafe.
	 */
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

	/**
	 * Validates a phone-number-like string.
	 *
	 * Allowed characters are digits, plus, hyphen, parentheses, dot, slash, and space.
	 *
	 * @param string $string Candidate phone number.
	 * @return string|bool Original string, empty string, or false when invalid.
	 */
	protected static function sanitize_phone_number(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^[0-9+\-(). \/]+$/', $string)===1 ? $string : false;
	}

	/**
	 * Validates an email address using PHP's email filter.
	 *
	 * @param string $string Candidate email address.
	 * @return string|bool Normalized filter result, empty string, or false when invalid.
	 */
	protected static function sanitize_email(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$email=filter_var($string, FILTER_VALIDATE_EMAIL);
		return $email===false ? false : $email;
	}

	/**
	 * Validates a digits-only numeric string.
	 *
	 * @param string $string Candidate numeric value.
	 * @return string|bool Digits-only string, empty string, or false when invalid.
	 */
	protected static function sanitize_numeric(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^\d+$/', $string)===1 ? $string : false;
	}

	/**
	 * Validates a signed integer string.
	 *
	 * @param string $string Candidate integer value.
	 * @return string|bool Integer string, empty string, or false when invalid.
	 */
	protected static function sanitize_integer(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^-?\d+$/', $string)===1 ? $string : false;
	}

	/**
	 * Validates a signed decimal string.
	 *
	 * @param string $string Candidate floating-point value.
	 * @return string|bool Float string, empty string, or false when invalid.
	 */
	protected static function sanitize_float(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^-?(?:\d+|\d*\.\d+)$/', $string)===1 ? $string : false;
	}

	/**
	 * Normalizes common boolean strings to 1 or 0.
	 *
	 * Accepted true values are 1, true, yes, and on. Accepted false values are 0,
	 * false, no, and off.
	 *
	 * @param string $string Candidate boolean value.
	 * @return string|bool '1', '0', empty string, or false when unrecognized.
	 */
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

	/**
	 * Converts a string into a lowercase ASCII URL slug.
	 *
	 * Non-alphanumeric runs become hyphens. Inputs that collapse to an empty slug are
	 * treated as invalid.
	 *
	 * @param string $string Candidate slug text.
	 * @return string|bool Slug, empty string, or false when no slug remains.
	 */
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

	/**
	 * Validates a username token.
	 *
	 * Usernames may contain ASCII letters, digits, dot, underscore, and hyphen, with a
	 * maximum length of 64 characters.
	 *
	 * @param string $string Candidate username.
	 * @return string|bool Original username, empty string, or false when invalid.
	 */
	protected static function sanitize_username(string $string) : string|bool {
		if($string===''){
			return '';
		}
		return preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $string)===1 ? $string : false;
	}

	/**
	 * Normalizes and validates a postal-code-like token.
	 *
	 * The value is uppercased and may contain letters, digits, spaces, and hyphens.
	 *
	 * @param string $string Candidate postal code.
	 * @return string|bool Uppercase postal code, empty string, or false when invalid.
	 */
	protected static function sanitize_postal_code(string $string) : string|bool {
		if($string===''){
			return '';
		}
		$string=strtoupper(trim($string));
		return preg_match('/^[A-Z0-9][A-Z0-9 \-]{1,15}$/', $string)===1 ? $string : false;
	}

	/**
	 * Removes all characters except Unicode letters and numbers.
	 *
	 * @param string $string Raw text.
	 * @return string Alphanumeric-only text.
	 */
	protected static function sanitize_alphanumeric(string $string) : string {
		if($string===''){
			return '';
		}
		return preg_replace('/[^\p{L}\p{N}]/u', '', $string) ?? $string;
	}

	/**
	 * Removes most symbols while preserving common prose punctuation.
	 *
	 * Unicode letters/numbers, apostrophes, commas, periods, spaces, and hyphens are
	 * retained.
	 *
	 * @param string $string Raw text.
	 * @return string Text with unsupported special characters removed.
	 */
	protected static function sanitize_text_nospecial(string $string) : string {
		if($string===''){
			return '';
		}
		return preg_replace("/[^\p{L}\p{N}'\x{2019},. \-]/u", '', $string) ?? $string;
	}

	/**
	 * Sanitizes and title-cases a personal name.
	 *
	 * Letters, combining marks, apostrophes, commas, periods, spaces, and hyphens are
	 * retained. Hyphenated and apostrophe-separated name parts are re-capitalized.
	 *
	 * @param string $string Raw personal name.
	 * @return string Sanitized display name, or an empty string when no name remains.
	 */
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

	/**
	 * Normalizes encoded entity vectors before HTML escaping decisions.
	 *
	 * The method defangs selected entity forms and decodes entities so later escaping
	 * or basic HTML filtering sees the actual characters being submitted.
	 *
	 * @param string $string Raw string.
	 * @return string Entity-normalized string.
	 */
	protected static function clean_encoded_vectors(string $string) : string {
		$string=str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $string);
		$string=preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $string) ?? $string;
		$string=preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $string) ?? $string;
		return html_entity_decode($string, ENT_COMPAT, 'UTF-8');
	}

	/**
	 * Removes high-risk HTML constructs from basic HTML input.
	 *
	 * Event attributes, javascript/vbscript style URLs, Mozilla bindings, dangerous
	 * tags, and namespaced tags are stripped while allowing simpler markup through.
	 *
	 * @param string $string Raw basic HTML.
	 * @return string Filtered HTML string.
	 */
	protected static function sanitize_basic_html(string $string) : string {
		$string=preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $string) ?? $string;
		$string=preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $string) ?? $string;
		$string=preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $string) ?? $string;
		$string=preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $string) ?? $string;
		$string=preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|input|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $string) ?? $string;
		$string=preg_replace('#</*\w+:\w[^>]*+>#i', '', $string) ?? $string;
		return $string;
	}

	/**
	 * Attempts to transliterate UTF-8 text to ASCII.
	 *
	 * iconv is used when available; if transliteration fails the original string is
	 * returned.
	 *
	 * @param string $string Raw text.
	 * @return string ASCII-folded text or original text.
	 */
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
