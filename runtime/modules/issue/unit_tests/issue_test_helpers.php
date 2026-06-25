<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!defined('DP_CORE_CFG')){
		define('DP_CORE_CFG', ['timezone'=>'UTC']);
	}
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(...$args): bool { return true; }
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_select'] ?? null)
				? $GLOBALS['dp_unit_sql_select'](...$args)
				: false;
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return is_callable($GLOBALS['dp_unit_sql_insert'] ?? null)
				? $GLOBALS['dp_unit_sql_insert'](...$args)
				: false;
		}
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\core', false)){
		class core{
			public static function config_all(): array { return ['base_timezone'=>'America/Toronto']; }
			public static function get_client_ip(): string { return '127.0.0.1'; }
			public static function get_server_load_level(): string { return 'low'; }
			public static function encrypt_data(string $data, array $salt): string {
				$GLOBALS['dp_issue_encrypt_payload']=[
					'data'=>$data,
					'salt'=>$salt,
				];
				return 'encrypted:'.md5($data.implode('|', $salt));
			}
		}
	}
}

namespace {
	require_once __DIR__.'/../kernel/issue.main.php';

	function dp_issue_unit_context_helpers_json(): string {
		new \dataphyre\issue(static fn(): bool => true, '1.2.3', 'Europe/Paris', [
			'tenant'=>'unit',
			'userid'=>42,
		]);
		$reflection=new \ReflectionClass(\dataphyre\issue::class);
		$base_context=$reflection->getMethod('base_context');
		$base_context->setAccessible(true);
		$encode_context=$reflection->getMethod('encode_context');
		$encode_context->setAccessible(true);
		$current_timezone_label=$reflection->getMethod('current_timezone_label');
		$current_timezone_label->setAccessible(true);
		$current_userid=$reflection->getMethod('current_execution_userid');
		$current_userid->setAccessible(true);

		$context=$base_context->invoke(null, ['request'=>'/orders/1', 'unicode'=>'cafe']);
		ksort($context);
		return json_encode([
			'context'=>$context,
			'encoded'=>$encode_context->invoke(null, ['path'=>'/orders/1', 'unicode'=>'cafe']),
			'timezone'=>$current_timezone_label->invoke(null),
			'userid'=>$current_userid->invoke(null),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_create_duplicate_json(): string {
		$GLOBALS['dp_issue_insert_called']=false;
		$GLOBALS['dp_issue_notifications']=[];
		$GLOBALS['dp_unit_sql_select']=static fn(...$args): array => ['issueid'=>'7301'];
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): bool {
			$GLOBALS['dp_issue_insert_called']=true;
			return false;
		};

		new \dataphyre\issue(static function(string $subject, string $body): void {
			$GLOBALS['dp_issue_notifications'][]=[$subject, $body];
		}, '2.0.0', 'UTC', ['userid'=>51]);
		$result=\dataphyre\issue::create('duplicate_type', ['path'=>'/cart'], 'Already pending', 1);

		return json_encode([
			'result'=>$result,
			'insert_called'=>$GLOBALS['dp_issue_insert_called'],
			'notifications'=>count($GLOBALS['dp_issue_notifications']),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_create_insert_json(): string {
		$_SERVER['SERVER_ADDR']='198.51.100.5';
		$GLOBALS['dp_issue_insert_args']=null;
		$GLOBALS['dp_issue_notifications']=[];
		$GLOBALS['dp_issue_encrypt_payload']=null;
		\dataphyre\core::register_dialback('CALL_CORE_GET_SERVER_LOAD_LEVEL', static fn(): int => 0);
		\dataphyre\core::register_dialback('CALL_CORE_ENCRYPT_DATA', static function(string $data, array $salt): string {
			$GLOBALS['dp_issue_encrypt_payload']=[
				'data'=>$data,
				'salt'=>$salt,
			];
			return 'encrypted:'.md5($data.implode('|', $salt));
		});
		$GLOBALS['dp_unit_sql_select']=static fn(...$args): bool => false;
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): array {
			$GLOBALS['dp_issue_insert_args']=$args;
			return ['issueid'=>8802];
		};

		new \dataphyre\issue(static function(string $subject, string $body): void {
			$GLOBALS['dp_issue_notifications'][]=[
				'subject'=>$subject,
				'has_description'=>str_contains($body, 'Description: Inserted issue'),
				'has_context'=>str_contains($body, 'Context: {"userid":77,"request":"/checkout","app_version":"3.1.4","load_level":0}'),
				'has_issueid'=>str_contains($body, 'Given IssueID: 8802'),
				'has_timezone'=>str_contains($body, '(America/Vancouver)'),
			];
		}, '3.1.4', 'America/Vancouver', ['userid'=>77]);
		$result=\dataphyre\issue::create('insert_type', ['request'=>'/checkout'], 'Inserted issue', 2);
		$record=$GLOBALS['dp_issue_insert_args'][1] ?? [];

		return json_encode([
			'result'=>$result,
			'insert_table'=>$GLOBALS['dp_issue_insert_args'][0] ?? null,
			'insert_has_userid'=>($record['execution_userid'] ?? null)===77,
			'insert_status'=>$record['status'] ?? null,
			'insert_type'=>$record['type'] ?? null,
			'insert_context_is_encrypted'=>str_starts_with((string)($record['context'] ?? ''), 'encrypted:'),
			'encrypt_context'=>$GLOBALS['dp_issue_encrypt_payload']['data'] ?? null,
			'encrypt_salt_uses_server'=>($GLOBALS['dp_issue_encrypt_payload']['salt'][1] ?? null)==='198.51.100.5',
			'notification'=>$GLOBALS['dp_issue_notifications'][0] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_insert_without_userid_json(): string {
		$_SERVER['SERVER_ADDR']='203.0.113.5';
		$GLOBALS['dp_issue_insert_calls']=[];
		$GLOBALS['dp_issue_notifications']=[];
		$GLOBALS['dp_unit_sql_select']=static fn(...$args): bool => false;
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): array {
			$GLOBALS['dp_issue_insert_calls'][]=$args;
			return ['issueid'=>9904];
		};

		new \dataphyre\issue(static function(string $subject, string $body): void {
			$GLOBALS['dp_issue_notifications'][]=[
				'subject'=>$subject,
				'has_unknown_issue'=>str_contains($body, '<b>Unknown issueid</b>'),
			];
		}, '4.0.0', '', ['userid'=>null, 'tenant'=>'anonymous']);
		$result=\dataphyre\issue::create('anonymous_type', ['path'=>'/status'], 'Anonymous issue');
		$record=$GLOBALS['dp_issue_insert_calls'][0][1] ?? [];

		return json_encode([
			'result'=>$result,
			'insert_calls'=>count($GLOBALS['dp_issue_insert_calls']),
			'has_execution_userid'=>array_key_exists('execution_userid', $record),
			'insert_type'=>$record['type'] ?? null,
			'insert_status'=>$record['status'] ?? null,
			'notification_subject'=>$GLOBALS['dp_issue_notifications'][0]['subject'] ?? null,
			'notification_unknown_issue'=>$GLOBALS['dp_issue_notifications'][0]['has_unknown_issue'] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_insert_retries_without_optional_userid_json(): string {
		$_SERVER['SERVER_ADDR']='203.0.113.6';
		$GLOBALS['dp_issue_insert_calls']=[];
		$GLOBALS['dp_unit_sql_select']=static fn(...$args): bool => false;
		$GLOBALS['dp_unit_sql_insert']=static function(...$args): array|false {
			$GLOBALS['dp_issue_insert_calls'][]=$args;
			return count($GLOBALS['dp_issue_insert_calls'])===1
				? false
				: ['issueid'=>9910];
		};

		new \dataphyre\issue(static function(string $subject, string $body): void {}, '4.1.0', 'UTC', ['userid'=>64]);
		$result=\dataphyre\issue::create('retry_type', ['path'=>'/retry'], 'Retry issue');
		$first_record=$GLOBALS['dp_issue_insert_calls'][0][1] ?? [];
		$second_record=$GLOBALS['dp_issue_insert_calls'][1][1] ?? [];

		return json_encode([
			'result'=>$result,
			'insert_calls'=>count($GLOBALS['dp_issue_insert_calls']),
			'first_has_execution_userid'=>($first_record['execution_userid'] ?? null)===64,
			'second_has_execution_userid'=>array_key_exists('execution_userid', $second_record),
			'second_type'=>$second_record['type'] ?? null,
			'second_status'=>$second_record['status'] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}
}
