<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

\dp_module_required('issue', 'sql');
if(function_exists('sql_define_table')){
	sql_define_table('issues', __DIR__.'/issue.tables.php', 'issues');
}

/**
 * Records application issues with encrypted context and optional notifications.
 *
 * The legacy issue module deduplicates pending issues by type and normalized
 * context, persists encrypted diagnostic context in SQL, and can notify an
 * application-supplied mail callback after a new issue is created.
 */
class issue{
	
	private static $application_version;
	private static $timezone;
	private static $additional_context;
	
	private static $email_sending_callback;
	
	/**
	 * Configures issue reporting for the current application process.
	 *
	 * @param callable $email_sending_callback Callback receiving notification subject and HTML body.
	 * @param string $application_version Version string stored with created issue context.
	 * @param string $timezone Preferred timezone for issue timestamps and notification labels.
	 * @param array<string,mixed> $additional_context Process-wide diagnostic fields merged into every issue before hashing and encryption.
	 */
	function __construct(callable $email_sending_callback, string $application_version, string $timezone, array $additional_context=[]){
		self::$email_sending_callback=$email_sending_callback;
		self::$application_version=$application_version;
		self::$timezone=$timezone;
		self::$additional_context=$additional_context;
	}

	/**
	 * Merges caller context with module-wide issue metadata.
	 *
	 * @param array<string,mixed> $context Per-issue diagnostic fields supplied by the caller.
	 * @return array<string,mixed> Context including configured additional fields and the app_version value used for deduplication.
	 */
	private static function base_context(array $context=[]): array {
		$merged_context=array_merge(self::$additional_context ?? [], $context);
		$merged_context['app_version']=self::$application_version ?? 'unknown';
		return $merged_context;
	}

	/**
	 * Encodes issue context for hashing, storage, and notification output.
	 *
	 * @param array<string,mixed> $context Diagnostic context encoded for hashing, encrypted storage, and notification output.
	 * @return string JSON object string, or "{}" when encoding cannot fully succeed.
	 */
	private static function encode_context(array $context): string {
		$encoded_context=json_encode(
			$context,
			JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR
		);
		if($encoded_context===false){
			return '{}';
		}
		return $encoded_context;
	}

	/**
	 * Returns the issue timestamp in the configured application timezone.
	 *
	 * @return string Timestamp formatted as Y-m-d H:i:s.
	 */
	private static function current_time_string(): string {
		$timezone_identifier=self::$timezone;
		if(empty($timezone_identifier) && class_exists('\dataphyre\core', false)){
			$timezone_identifier=(string)(\dataphyre\core::config_all()['base_timezone'] ?? '');
		}
		if($timezone_identifier===''){
			$timezone_identifier=(string)(DP_CORE_CFG['timezone'] ?? '');
		}
		$timezone_identifier=$timezone_identifier ?: date_default_timezone_get();
		try{
			$now=new \DateTimeImmutable('now', new \DateTimeZone($timezone_identifier));
			return $now->format('Y-m-d H:i:s');
		}catch(\Throwable){
			return date('Y-m-d H:i:s', strtotime('now'));
		}
	}

	/**
	 * Resolves the timezone label shown in issue notification messages.
	 *
	 * @return string Configured module, core, or PHP default timezone identifier.
	 */
	private static function current_timezone_label(): string {
		if(!empty(self::$timezone)){
			return self::$timezone;
		}
		if(class_exists('\dataphyre\core', false)){
			$configured_timezone=(string)(\dataphyre\core::config_all()['base_timezone'] ?? '');
			if($configured_timezone!==''){
				return $configured_timezone;
			}
		}
		$core_timezone=(string)(DP_CORE_CFG['timezone'] ?? '');
		if($core_timezone!==''){
			return $core_timezone;
		}
		return date_default_timezone_get();
	}

	/**
	 * Resolves the client or execution IP associated with the issue.
	 *
	 * @return string Request IP, core client IP, remote address, or 0.0.0.0 fallback.
	 */
	private static function current_execution_ip(): string {
		if(defined('REQUEST_IP_ADDRESS')){
			return (string)REQUEST_IP_ADDRESS;
		}
		if(class_exists('dataphyre\core')){
			return (string)\dataphyre\core::get_client_ip();
		}
		return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
	}

	/**
	 * Resolves the server IP recorded with the issue row.
	 *
	 * @return string Server address, local address, or 0.0.0.0 fallback.
	 */
	private static function current_server_ip(): string {
		return (string)($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '0.0.0.0');
	}

	/**
	 * Resolves the authenticated user id for issue attribution when available.
	 *
	 * @return ?int User id from additional context or access module, or null.
	 */
	private static function current_execution_userid(): ?int {
		$userid=self::$additional_context['userid'] ?? null;
		if($userid===null && class_exists('dataphyre\access') && method_exists('dataphyre\access', 'userid')){
			$userid=\dataphyre\access::userid();
		}
		if(is_numeric($userid)){
			return (int)$userid;
		}
		return null;
	}

	/**
	 * Builds the encryption salt tuple used for stored issue context.
	 *
	 * @param string $time Issue timestamp stored on the row.
	 * @param array{server_name?:string,server_ip?:string}|array<string,mixed> $row_or_context Stored issue row or creation context containing server identity.
	 * @return array{0:string,1:string} Salt tuple for core encryption helpers.
	 */
	private static function encryption_salt(string $time, array $row_or_context=[]): array {
		$server_identifier=(string)($row_or_context['server_name'] ?? $row_or_context['server_ip'] ?? self::current_server_ip());
		return [$time, $server_identifier];
	}

	/**
	 * Inserts an issue row, adding execution_userid when it can be resolved.
	 *
	 * @param array{md5:string,type:string,description:string,context:string,server_ip:string,status:string,date:string} $record SQL fields for the issues table before optional execution_userid enrichment.
	 * @return false|array<string,mixed> sql_insert result, including issueid when the SQL layer returns inserted identifiers.
	 */
	private static function insert_issue(array $record): bool|array {
		$optional_fields=array_filter([
			'execution_userid'=>self::current_execution_userid(),
		], static fn($value): bool => $value!==null);
		if(!empty($optional_fields)){
			$issue=sql_insert(
				$L="issues",
				$F=array_merge($record, $optional_fields),
				$V=null,
				$CC=true
			);
			if($issue!==false){
				return $issue;
			}
		}
		return sql_insert(
			$L="issues",
			$F=$record,
			$V=null,
			$CC=true
		);
	}

	/**
	 * Sends an issue notification through the configured callback.
	 *
	 * Notification failures are logged and do not change issue creation outcome.
	 *
	 * @param string $subject Email subject.
	 * @param string $body HTML email body.
	 * @return void
	 */
	private static function notify_issue(string $subject, string $body): void {
		$email_sending_callback=self::$email_sending_callback ?? null;
		if(!is_callable($email_sending_callback)){
			return;
		}
		try{
			$email_sending_callback($subject, $body);
		}catch(\Throwable $exception){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Issue notification callback failed: '.$exception->getMessage(), $S='warning');
		}
	}
	
	/**
	 * Re-encrypts stored context for one issue through the core defer queue.
	 *
	 * The issue row is loaded, its context is decrypted with the row timestamp
	 * and server identity, then the clear context is handed back to the deferred
	 * recrypt helper so active encryption policy can rewrite it.
	 *
	 * @param int $issueid Issue row identifier.
	 * @return bool True when the deferred recrypt task is queued or completed by core.
	 */
	public static function recrypt(int $issueid) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		return \dataphyre\core::defer_recrypt(__METHOD__, $issueid, function(string $queue)use($issueid){
			sql_select(
				$S="*",
				$L="issues",
				$P="WHERE issueid=?",
				$V=[$issueid],
				$F=false,
				$C=false,
				$Q=$queue,
				$C=function($row)use($issueid, $queue){
					if($row===false){
						return;
					}
					$new_data=[
						'context'=>\dataphyre\core::decrypt_data(
							(string)($row['context'] ?? ''),
							self::encryption_salt((string)($row['date'] ?? ''), $row),
							'return'
						)
					];
					sql_update(
						$L="issues",
						$F=$new_data,
						$P="WHERE issueid=?",
						$V=[$issueid],
						$CC=true,
						$Q=$queue
					);
				}
			);
		});
	}

	/**
	 * Creates or returns a pending issue for a type and context.
	 *
	 * Pending issues are deduplicated by md5(type + encoded base context). New
	 * issues capture server load, execution and server IPs, encrypted JSON
	 * context, status, timestamp, and optional user attribution before sending
	 * the notification callback.
	 *
	 * @param string $type Stable issue category used for deduplication and subject text.
	 * @param array<string,mixed> $context Diagnostic context merged with module base context, hashed for pending dedupe, then encrypted for SQL persistence.
	 * @param string $description Human-readable issue description.
	 * @param int $severity Legacy severity slot retained for old callers.
	 * @param mixed $legacy_extra Legacy extension slot retained for signature compatibility.
	 * @return bool|int Existing or created issue id; false when the database insert fails.
	 */
	public static function create(string $type, array $context=[], string $description='', int $severity=0, mixed $legacy_extra=null) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		$base_context=self::base_context($context);
		$md5=md5($type.self::encode_context($base_context));
		$existing_issue=sql_select(
			$S="issueid",
			$L="issues",
			$P="WHERE md5=? AND status='pending'",
			$V=[$md5],
			$F=false,
			$C=false
		);
		if($existing_issue!==false && isset($existing_issue['issueid']) && is_numeric($existing_issue['issueid'])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Issue already known", $S="warning");
			return (int)$existing_issue['issueid'];
		}
		$context_for_storage=$base_context;
		$context_for_storage["load_level"]=\dataphyre\core::get_server_load_level();
		$execution_ip=self::current_execution_ip();
		$server_ip=self::current_server_ip();
		$time=self::current_time_string();
		$status="pending";
		$context_json=self::encode_context($context_for_storage);
		$context_encrypted=\dataphyre\core::encrypt_data($context_json, self::encryption_salt($time, ['server_ip'=>$server_ip]));
		$issue=self::insert_issue([
			"md5"=>$md5,
			"type"=>$type,
			"description"=>$description,
			"context"=>$context_encrypted,
			"server_ip"=>$server_ip,
			"status"=>$status,
			"date"=>$time
		]);
		if($issue===false){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed creating issue in database", $S="fatal");
			return false;
		}
		$issueid=is_array($issue) && isset($issue['issueid']) && is_numeric($issue['issueid'])
			? (int)$issue['issueid']
			: false;
		$body='';
		$body.='Description: '.$description.'<br><br>';
		$body.='Server IP: '.$server_ip.'<br>';
		$body.='Execution IP: '.$execution_ip.'<br>';
		if($context_json!=='{}'){
			$body.='Context: '.$context_json.'<br>';
		}
		$body.='Status: '.$status.'<br>';
		if($issueid!==false){
			$body.='Given IssueID: '.$issueid.'<br>';
		}
		else
		{
			$body.='<b>Unknown issueid</b><br>';
		}
		$body.='Time: '.$time.' ('.self::current_timezone_label().')<br>';
		$body.='<b>This is an automated email. All information contained herein is confidential.</b>';
		$subject="Issue ($type) on server ($server_ip)";
		self::notify_issue($subject, $body);
		return $issueid;
	}
	
}

if(!class_exists('issue', false)){
	class_alias(__NAMESPACE__.'\\issue', 'issue');
}
