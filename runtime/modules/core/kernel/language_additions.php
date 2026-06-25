<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
if(!function_exists("validate_json")){
	/**
	 * Validates a JSON string and returns the decoder error message on failure.
	 *
	 * The helper intentionally decodes only for validation and does not return
	 * the decoded payload. A valid document returns true; invalid documents return
	 * the legacy human-readable `json_last_error()` message used by callers.
	 *
	 * @param string $json JSON document to validate.
	 * @return bool|string True for valid JSON, or an error message for invalid JSON.
	 */
	function validate_json(string $json): bool|string {
		json_decode($json,true);
		switch(json_last_error()){
			case JSON_ERROR_NONE:
				return true;
			break;
			case JSON_ERROR_DEPTH:
				return 'Maximum stack depth exceeded';
			break;
			case JSON_ERROR_STATE_MISMATCH:
				return 'Underflow or the modes mismatch';
			break;
			case JSON_ERROR_CTRL_CHAR:
				return 'Unexpected control character found';
			break;
			case JSON_ERROR_SYNTAX:
				return 'Syntax error, malformed JSON';
			break;
			case JSON_ERROR_UTF8:
				return 'Malformed UTF-8 characters, possibly incorrectly encoded';
			break;
			default:
				return 'Unknown error';
			break;
		}
	}
}

if(!function_exists("current_datetime")){
	/**
	 * Returns the current local timestamp in Dataphyre's legacy SQL format.
	 *
	 * @return string Timestamp formatted as `Y-m-d H:i:s`.
	 */
	function current_datetime(): string {
		return date('Y-m-d H:i:s', time());
	}
}

if(!function_exists("array_replace_values")){
	/**
	 * Builds a sparse array containing entries whose values matched a target.
	 *
	 * Matching uses strict identity. Non-matching entries are omitted rather than
	 * copied, preserving the historical helper behavior.
	 *
	 * @param array<array-key, mixed> $array Source array.
	 * @param mixed $old_value Value to match.
	 * @param mixed $new_value Replacement value.
	 * @return array<array-key, mixed> Sparse array of replaced entries keyed like the source.
	 */
	function array_replace_values(array $array, mixed $old_value, mixed $new_value): array {
		$result=[];
		foreach($array as $index=>$value){
			if($value===$old_value){
				$result[$index]=$new_value;
			}
		}
		return $result;
	}
}

if(!function_exists("prefix_array_keys")){
	/**
	 * Re-keys an array with a prefix and numeric offset.
	 *
	 * Source keys are added to `$start_at` and appended to `$prefix`, so this is
	 * intended for numerically indexed arrays.
	 *
	 * @param array<int, mixed> $array Source values.
	 * @param string $prefix Prefix for generated keys.
	 * @param int $start_at Numeric offset added to each source key.
	 * @return array<string, mixed> Values keyed by prefixed numeric positions.
	 */
	function prefix_array_keys(array $array, string $prefix, int $start_at=0): array {
		$result=[];
		foreach($array as $index=>$value){
			$result["{$prefix}".($index+$start_at)]=$value;
		}
		return $result;
	}
}

if(!function_exists("is_cli")){
	/**
	 * Detects whether the current PHP process is running from a CLI context.
	 *
	 * Detection combines SAPI, STDIN, environment, and missing web request
	 * markers so bootstrap scripts can run before the full request model exists.
	 *
	 * @return bool True when the process appears to be CLI.
	 */
	function is_cli(): bool {
		return defined('STDIN') || php_sapi_name() === 'cli' || array_key_exists('SHELL', $_ENV) || 
			   (empty($_SERVER['REMOTE_ADDR']) && !isset($_SERVER['HTTP_USER_AGENT']) && count($_SERVER['argv']) > 0) || 
			   !array_key_exists('REQUEST_METHOD', $_SERVER);
	}
}

if(!function_exists("uuid")){
	/**
	 * Generates a random RFC 4122 version 4 UUID.
	 *
	 * Sixteen random bytes are generated and version/variant bits are applied
	 * before formatting into canonical lowercase hex groups.
	 *
	 * @return string UUIDv4 string.
	 *
	 * @throws Random\RandomException When secure random bytes cannot be generated.
	 */
	function uuid(): string {
		$data=random_bytes(16);
		$data[6]=chr(ord($data[6])&0x0f|0x40);
		$data[8]=chr(ord($data[8])&0x3f|0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}

if(!function_exists("is_uuid")){
	/**
	 * Checks whether a string is a canonical UUIDv4 value.
	 *
	 * @param string $string Candidate UUID.
	 * @return bool True when the string matches UUIDv4 format and variant bits.
	 */
	function is_uuid(string $string): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $string) === 1;
	}
}

if(!function_exists("is_base64")){
	/**
	 * Checks whether a string is canonical padded Base64.
	 *
	 * The helper rejects invalid alphabets, failed strict decoding, and values
	 * whose re-encoded form differs from the original string.
	 *
	 * @param string $string Candidate Base64 text.
	 * @return bool True when the value strictly round-trips through Base64.
	 */
	function is_base64(string $string): bool {
		if(!preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $string)) return false;
		$decoded=base64_decode($string, true);
		if(false===$decoded)return false;
		if(base64_encode($decoded)!==$string)return false;
		if(is_null($string))return false;
		return true;
	}
}

if(!function_exists("is_timestamp")){
	/**
	 * Checks whether a Unix timestamp maps to a valid calendar date.
	 *
	 * The timestamp is converted through PHP's date handling and then validated
	 * with `checkdate()`.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return bool True when the derived month/day/year combination is valid.
	 */
	function is_timestamp(int $timestamp): bool {
		$date=date('m-d-Y', $timestamp);
		list($month, $day, $year)=explode('-', $date);
		return checkdate($month, $day, $year);
	}
}

if(!function_exists("ellipsis")){
	/**
	 * Truncates a string with an ellipsis on the requested side.
	 *
	 * Multibyte length and substring functions are used. Direction may be left,
	 * center, or right; unknown directions use right truncation.
	 *
	 * @param string $string Input string.
	 * @param int $length Number of original characters to preserve.
	 * @param string $direction Truncation direction: `left`, `center`, or `right`.
	 * @return string Original or truncated string.
	 */
	function ellipsis(string $string, int $length, string $direction='right'): string {
		if(mb_strlen($string)<= $length){
			return $string;
		}
		switch($direction){
			case 'left':
				return '...'.mb_substr($string, -$length);
			case 'center':
				$half=floor($length / 2);
				return mb_substr($string, 0, $half).'...'.mb_substr($string, -$half);
			case 'right':
			default:
				return mb_substr($string, 0, $length).'...';
		}
	}
}

if(!function_exists("array_average")){
	/**
	 * Calculates the arithmetic average of numeric array values.
	 *
	 * The legacy signature returns int, so PHP coerces the division result for
	 * callers running without strict scalar return enforcement.
	 *
	 * @param array<int|string, int|float> $array Numeric values; must not be empty.
	 * @return int Average value coerced by the declared return type.
	 */
	function array_average(array $array) : int {
		return array_sum($array)/count($array);
	}
}

if(!function_exists("array_shuffle")){
	/**
	 * Randomizes array iteration order while preserving original keys.
	 *
	 * Keys are shuffled independently and used to rebuild the array, unlike
	 * PHP's `shuffle()` which reindexes numeric keys.
	 *
	 * @param array<array-key, mixed> $array Source array.
	 * @return array<array-key, mixed> Array with shuffled key order.
	 */
	function array_shuffle(array $array): array {
		$keys=array_keys($array);
		shuffle($keys);
		foreach($keys as $key){
			$new[$key]=$array[$key];
		}
		$array=$new;
		return $array; 
	}
}

if(!function_exists("array_count")){
	/**
	 * Counts an array-like value with false/null tolerance.
	 *
	 * Non-arrays, null, and false return zero so legacy callers can count query
	 * results without guarding every failure path.
	 *
	 * @param mixed $array Candidate array.
	 * @return int Element count, or zero for non-arrays.
	 */
	function array_count(mixed $array): int {
		if($array===false || is_null($array) || !is_array($array)){
			return 0;
		}
		else
		{
			return count($array);
		}
	}
}

if(!function_exists("copy_folder")){
	/**
	 * Recursively copies a directory tree.
	 *
	 * The destination directory is created if missing. Nested directories are
	 * delegated to `core::copy_folder()` to preserve the historical recursion
	 * path used by the core class.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return void
	 */
	function copy_folder(string $src, string $dst) : void {
		$dir=opendir($src);
		@mkdir($dst);
		while(false!==$file=readdir($dir)){
			if(($file!='.') && ($file!='..' )){
				if(is_dir($src.'/'.$file)){
					core::copy_folder($src.'/'.$file, $dst.'/'.$file);
				}
				else
				{
					copy($src.'/'.$file, $dst.'/'.$file);
				}
			}
		}
		closedir($dir);
	}
}
