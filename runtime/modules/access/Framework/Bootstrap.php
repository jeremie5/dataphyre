<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

if(!defined('DATAPHYRE_ACCESS_FRAMEWORK_BOOTSTRAPPED')){
	define('DATAPHYRE_ACCESS_FRAMEWORK_BOOTSTRAPPED', true);

	\dataphyre\core::register_dialback('CALL_ACCESS_LOGGED_IN_AUTH_TYPE', static function(string $auth_type): mixed {
		if(strtolower(trim($auth_type))!=='jwt'){
			return null;
		}
		return Auth::guard('jwt')->check();
	});

	\dataphyre\core::register_dialback('CALL_ACCESS_USERID_AUTH_TYPE', static function(string $auth_type): mixed {
		if(strtolower(trim($auth_type))!=='jwt'){
			return null;
		}
		return Auth::guard('jwt')->id() ?? false;
	});

	\dataphyre\core::register_dialback('CALL_ACCESS_VALIDATE_SESSION_AUTH_TYPE', static function(string $auth_type, bool $cache=true): mixed {
		if(strtolower(trim($auth_type))!=='jwt'){
			return null;
		}
		return Auth::guard('jwt')->validate($cache);
	});

	\dataphyre\core::register_dialback('CALL_ACCESS_RECOVER_SESSION_AUTH_TYPE', static function(string $auth_type): mixed {
		if(strtolower(trim($auth_type))!=='jwt'){
			return null;
		}
		return Auth::guard('jwt')->recover();
	});

	\dataphyre\core::register_dialback('CALL_ACCESS_DISABLE_SESSION_AUTH_TYPE', static function(string $auth_type): mixed {
		if(strtolower(trim($auth_type))!=='jwt'){
			return null;
		}
		return false;
	});
}
