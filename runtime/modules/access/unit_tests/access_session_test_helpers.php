<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
if(!function_exists('dp_access_unit_create_session')){
	require_once __DIR__.'/../kernel/access.main.php';

	function dp_access_unit_create_session(int $userid, bool $keepalive): bool {
		$GLOBALS['dp_unit_sql_insert']=static function(string $table, array $fields)use($userid): mixed {
			return (($fields['userid'] ?? null)===$userid && $userid!==999) ? ['unit_insert'=>true] : false;
		};
		return \dataphyre\access::create_session($userid, $keepalive);
	}
}

if(!function_exists('dp_access_unit_validate_session_cached')){
	function dp_access_unit_validate_session_cached(bool $valid): bool {
		$_SESSION['dp_access']=[
			'userid'=>123,
			'dpid'=>'unit-session',
			'ip_address'=>defined('REQUEST_IP_ADDRESS') ? REQUEST_IP_ADDRESS : '127.0.0.1',
			'last_valid_session'=>$valid ? time() : 0,
			'auth_type'=>'session',
		];
		return \dataphyre\access::validate_session(true);
	}
}

if(!function_exists('dp_access_unit_recover_session')){
	function dp_access_unit_recover_session(): bool {
		\dataphyre\core::register_dialback('CALL_ACCESS_RECOVER_SESSION', static fn(): bool => true);
		return \dataphyre\access::recover_session();
	}
}

if(!function_exists('dp_access_unit_disable_all_sessions_of_user')){
	function dp_access_unit_disable_all_sessions_of_user(int $userid): bool {
		$GLOBALS['dp_unit_sql_update']=static function(string $table, mixed $fields, mixed $where, array $values)use($userid): mixed {
			return (($values[1] ?? null)===$userid && $userid!==67890) ? ['unit_update'=>true] : false;
		};
		return \dataphyre\access::disable_all_sessions_of_user($userid);
	}
}
