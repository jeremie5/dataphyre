<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Resolves and reads a non-empty shared-request secret. */
if(!function_exists('dp_shared_request_secret')){
	function dp_shared_request_secret(string $secret_file): string|false {
		$secret_file=trim($secret_file);
		if($secret_file==='') return false;
		$candidates=[];
		if(is_file($secret_file)) $candidates[]=$secret_file;
		if(preg_match('/^[A-Za-z0-9._-]+$/D', $secret_file)===1){
			if(defined('DATAPHYRE_PROJECT_ROOT')) $candidates[]=rtrim((string)DATAPHYRE_PROJECT_ROOT, '/\\').'/'.$secret_file;
			if(defined('ROOTPATH') && is_array(ROOTPATH) && isset(ROOTPATH[$secret_file])) $candidates[]=(string)ROOTPATH[$secret_file];
		}
		foreach(array_values(array_unique($candidates)) as $candidate){
			if(!is_file($candidate) || !is_readable($candidate)) continue;
			$secret=trim((string)@file_get_contents($candidate));
			if($secret!=='') return $secret;
		}
		return false;
	}
}

/** Creates a purpose-, context-, and time-bucket-bound shared request token. */
if(!function_exists('dp_shared_request_key')){
	function dp_shared_request_key(string $secret_file, string $purpose, string $context='', ?int $timestamp=null, ?int $period=null): string|false {
		$purpose=trim($purpose);
		$secret=dp_shared_request_secret($secret_file);
		if($purpose==='' || $secret===false) return false;
		$timestamp ??= time();
		$period=max(1, $period ?? 60);
		$bucket=(int)floor($timestamp/$period);
		return hash_hmac('sha256', $purpose.'|'.$context.'|'.$bucket, $secret);
	}
}

/** Verifies a shared request token across a bounded adjacent-bucket window. */
if(!function_exists('dp_verify_shared_request_key')){
	function dp_verify_shared_request_key(string $token, string $secret_file, string $purpose, string $context='', int $window=1, ?int $timestamp=null, ?int $period=null): bool {
		$token=strtolower(trim($token));
		if(preg_match('/^[a-f0-9]{64}$/D', $token)!==1) return false;
		$timestamp ??= time();
		$period=max(1, $period ?? 60);
		$window=min(32, max(0, $window));
		for($offset=-$window;$offset<=$window;$offset++){
			$candidate=dp_shared_request_key($secret_file, $purpose, $context, $timestamp+($offset*$period), $period);
			if(is_string($candidate) && hash_equals($candidate, $token)) return true;
		}
		return false;
	}
}

/** Resolves a signed or legacy application-override request value. */
if(!function_exists('dp_app_override_application')){
	function dp_app_override_application(string $request_value, string $secret_file='app_override_key'): string|false {
		$parts=explode(',', $request_value, 2);
		$application=trim((string)($parts[0] ?? ''));
		$token=trim((string)($parts[1] ?? ''));
		if(preg_match('/^[A-Za-z0-9._-]+$/D', $application)!==1 || $token==='') return false;
		$secret=dp_shared_request_secret($secret_file);
		if($secret===false) return false;
		$legacy_valid=hash_equals($secret, $token);
		$signed_valid=dp_verify_shared_request_key($token, $secret_file, 'app_override', $application, 1);
		return $legacy_valid || $signed_valid ? $application : false;
	}
}
