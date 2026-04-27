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

class issue{
	
	private static $application_version;
	private static $timezone;
	private static $additional_context;
	
	private static $email_sending_callback;
	
	function __construct(callable $email_sending_callback, string $application_version, string $timezone, array $additional_context=[]){
		self::$email_sending_callback=$email_sending_callback;
		self::$application_version=$application_version;
		self::$timezone=$timezone;
		self::$additional_context=$additional_context;
	}

	private static function base_context(array $context=[]): array {
		$merged_context=array_merge(self::$additional_context ?? [], $context);
		$merged_context['app_version']=self::$application_version ?? 'unknown';
		return $merged_context;
	}

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

	private static function current_execution_ip(): string {
		if(defined('REQUEST_IP_ADDRESS')){
			return (string)REQUEST_IP_ADDRESS;
		}
		if(class_exists('dataphyre\core')){
			return (string)\dataphyre\core::get_client_ip();
		}
		return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
	}

	private static function current_server_ip(): string {
		return (string)($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '0.0.0.0');
	}

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

	private static function encryption_salt(string $time, array $row_or_context=[]): array {
		$server_identifier=(string)($row_or_context['server_name'] ?? $row_or_context['server_ip'] ?? self::current_server_ip());
		return [$time, $server_identifier];
	}

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
	
	public static function recrypt(int $issueid) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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

	public static function create(string $type, array $context=[], string $description='', int $severity=0, mixed $legacy_extra=null) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
