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
	if(!defined('DP_ISSUE_WORKER_SCENARIO_LOADED') && !class_exists(DpIssueWorkerScenario::class,false)){
		define('DP_ISSUE_WORKER_SCENARIO_LOADED', true);
		final class DpIssueWorkerScenario {
			/** @var list<array{subject:string,body:string}> */
			private static array $notifications=[];
			/** @var array{data:string,salt:array<int,mixed>}|null */
			private static ?array $encryption=null;

			public static function begin(?string $serverAddress=null): void {
				\dataphyre_dpanel_worker_fixture_state::resetSql();
				self::$notifications=[];
				self::$encryption=null;
				if($serverAddress!==null){
					\dataphyre_dpanel_worker_application_state::serverAddress($serverAddress);
				}
			}

			public static function recordNotification(string $subject,string $body): void {
				self::$notifications[]=['subject'=>$subject,'body'=>$body];
			}

			public static function notificationCount(): int {
				return count(self::$notifications);
			}

			/** @return array{subject:string,body:string} */
			public static function firstNotification(): array {
				return self::$notifications[0] ?? ['subject'=>'','body'=>''];
			}

			/** @param array<int,mixed> $salt */
			public static function recordEncryption(string $data,array $salt): void {
				self::$encryption=['data'=>$data,'salt'=>$salt];
			}

			/** @return array{data:string,salt:array<int,mixed>}|null */
			public static function encryption(): ?array {
				return self::$encryption;
			}
		}
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('select',$args,false);
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('insert',$args,false);
		}
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\core', false)){
		class core{
			/** @var array<string,callable> */
			private static array $dialbacks=[];
			public static function config_all(): array { return ['base_timezone'=>'America/Toronto']; }
			public static function get_client_ip(): string { return '127.0.0.1'; }
			public static function get_server_load_level(): string { return 'low'; }
			public static function register_dialback(string $event, callable $callback): void { self::$dialbacks[$event]=$callback; }
			public static function defer_recrypt(string $scope, string|int $identifier, callable $scheduler, string $queue='end'): bool {
				$callback=self::$dialbacks['CALL_CORE_DEFER_RECRYPT'] ?? null;
				if(is_callable($callback)){
					return (bool)$callback($scope, $identifier, $scheduler, $queue);
				}
				$scheduler($queue);
				return true;
			}
			public static function encrypt_data(string $data, array $salt): string {
				\DpIssueWorkerScenario::recordEncryption($data,$salt);
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
		$context=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\issue::class,'base_context',[[
			'request'=>'/orders/1',
			'unicode'=>'cafe',
		]]);
		ksort($context);
		return json_encode([
			'context'=>$context,
			'encoded'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\issue::class,'encode_context',[[
				'path'=>'/orders/1',
				'unicode'=>'cafe',
			]]),
			'timezone'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\issue::class,'current_timezone_label'),
			'userid'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\issue::class,'current_execution_userid'),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_create_duplicate_json(): string {
		DpIssueWorkerScenario::begin();
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',['issueid'=>'7301']);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert',false);

		new \dataphyre\issue(static function(string $subject, string $body): void {
			DpIssueWorkerScenario::recordNotification($subject,$body);
		}, '2.0.0', 'UTC', ['userid'=>51]);
		$result=\dataphyre\issue::create('duplicate_type', ['path'=>'/cart'], 'Already pending', 1);

		return json_encode([
			'result'=>$result,
			'insert_called'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert')>0,
			'notifications'=>DpIssueWorkerScenario::notificationCount(),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_create_insert_json(): string {
		DpIssueWorkerScenario::begin('198.51.100.5');
		\dataphyre\core::register_dialback('CALL_CORE_GET_SERVER_LOAD_LEVEL', static fn(): int => 0);
		\dataphyre\core::register_dialback('CALL_CORE_ENCRYPT_DATA', static function(string $data, array $salt): string {
			DpIssueWorkerScenario::recordEncryption($data,$salt);
			return 'encrypted:'.md5($data.implode('|', $salt));
		});
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',false);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert',['issueid'=>8802]);

		new \dataphyre\issue(static function(string $subject, string $body): void {
			DpIssueWorkerScenario::recordNotification($subject,$body);
		}, '3.1.4', 'America/Vancouver', ['userid'=>77]);
		$result=\dataphyre\issue::create('insert_type', ['request'=>'/checkout'], 'Inserted issue', 2);
		$insert=\dataphyre_dpanel_worker_fixture_state::sqlCall('insert');
		$record=$insert[1] ?? [];
		$encryption=DpIssueWorkerScenario::encryption();
		$notification=DpIssueWorkerScenario::firstNotification();

		return json_encode([
			'result'=>$result,
			'insert_table'=>$insert[0] ?? null,
			'insert_has_userid'=>($record['execution_userid'] ?? null)===77,
			'insert_status'=>$record['status'] ?? null,
			'insert_type'=>$record['type'] ?? null,
			'insert_context_is_encrypted'=>str_starts_with((string)($record['context'] ?? ''), 'encrypted:'),
			'encrypt_context'=>$encryption['data'] ?? null,
			'encrypt_salt_uses_server'=>($encryption['salt'][1] ?? null)==='198.51.100.5',
			'notification'=>[
				'subject'=>$notification['subject'],
				'has_description'=>str_contains($notification['body'],'Description: Inserted issue'),
				'has_context'=>str_contains($notification['body'],'Context: {"userid":77,"request":"/checkout","app_version":"3.1.4","load_level":0}'),
				'has_issueid'=>str_contains($notification['body'],'Given IssueID: 8802'),
				'has_timezone'=>str_contains($notification['body'],'(America/Vancouver)'),
			],
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_insert_without_userid_json(): string {
		DpIssueWorkerScenario::begin('203.0.113.5');
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',false);
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('insert',['issueid'=>9904]);

		new \dataphyre\issue(static function(string $subject, string $body): void {
			DpIssueWorkerScenario::recordNotification($subject,$body);
		}, '4.0.0', '', ['userid'=>null, 'tenant'=>'anonymous']);
		$result=\dataphyre\issue::create('anonymous_type', ['path'=>'/status'], 'Anonymous issue');
		$record=\dataphyre_dpanel_worker_fixture_state::sqlCall('insert')[1] ?? [];
		$notification=DpIssueWorkerScenario::firstNotification();

		return json_encode([
			'result'=>$result,
			'insert_calls'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert'),
			'has_execution_userid'=>array_key_exists('execution_userid', $record),
			'insert_type'=>$record['type'] ?? null,
			'insert_status'=>$record['status'] ?? null,
			'notification_subject'=>$notification['subject'],
			'notification_unknown_issue'=>str_contains($notification['body'],'<b>Unknown issueid</b>'),
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_issue_unit_insert_retries_without_optional_userid_json(): string {
		DpIssueWorkerScenario::begin('203.0.113.6');
		\dataphyre_dpanel_worker_fixture_state::returnFromSql('select',false);
		\dataphyre_dpanel_worker_fixture_state::respondToSql('insert',static function(...$args): array|false {
			return \dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert')===1
				? false
				: ['issueid'=>9910];
		});

		new \dataphyre\issue(static function(string $subject, string $body): void {}, '4.1.0', 'UTC', ['userid'=>64]);
		$result=\dataphyre\issue::create('retry_type', ['path'=>'/retry'], 'Retry issue');
		$first_record=\dataphyre_dpanel_worker_fixture_state::sqlCall('insert',0)[1] ?? [];
		$second_record=\dataphyre_dpanel_worker_fixture_state::sqlCall('insert',1)[1] ?? [];

		return json_encode([
			'result'=>$result,
			'insert_calls'=>\dataphyre_dpanel_worker_fixture_state::sqlCallCount('insert'),
			'first_has_execution_userid'=>($first_record['execution_userid'] ?? null)===64,
			'second_has_execution_userid'=>array_key_exists('execution_userid', $second_record),
			'second_type'=>$second_record['type'] ?? null,
			'second_status'=>$second_record['status'] ?? null,
		], JSON_UNESCAPED_SLASHES);
	}
}
