<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

if(function_exists('sql_define_table')){
	sql_define_table('dataphyre.user_changes', __DIR__.'/time_machine.tables.php', 'user_changes');
}

if(RUN_MODE==='diagnostic'){
	require_once(__DIR__.'/time_machine.diagnostic.php');
}

/**
 * Records encrypted, user-scoped change events that can be replayed back into prior state.
 *
 * The Time Machine kernel persists audit entries in `dataphyre.user_changes`, binding each
 * change payload to the acting user through `core::encrypt_data()`. Rollback handlers decode
 * those payloads and perform the inverse SQL or preference mutation for supported rollback
 * types. Dialbacks can intercept create and rollback calls before the built-in persistence path
 * runs. Stored entries are not re-keyed after creation; key rotation must migrate rows outside
 * this kernel before old encryption material is retired.
 *
 * @internal Kernel service; callers should treat stored payload formats as module-owned.
 */
class time_machine{

	/**
	 * Generates a version-4 UUID for a persisted change record.
	 *
	 * The identifier is random, non-sequential, and suitable for exposing in operational
	 * rollback workflows without leaking insert order or user identity.
	 *
	 * @return string RFC 4122 version-4 UUID text used as `dataphyre.user_changes.changeid`.
	 *
	 * @throws \Random\RandomException When the runtime cannot provide cryptographic randomness.
	 */
	private static function change_id(): string {
		$data=random_bytes(16);
		$data[6]=chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8]=chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Removes change records older than the supplied retention expression.
	 *
	 * The period is converted with `strtotime()` and mapped to SQL dialect-specific predicates.
	 * A `CALL_TIME_MACHINE_ROLLBACK` dialback may short-circuit the purge with a replacement
	 * boolean result before any database mutation occurs.
	 *
	 * @param string $period Human-readable `strtotime()` interval such as `7 days`.
	 * @return bool `true` when the delete call reports success, otherwise `false`.
	 */
	public static function purge_old(string $period='7 days'): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$early_return=core::dialback("CALL_TIME_MACHINE_ROLLBACK",...func_get_args())) return $early_return;
		if(false!==sql_delete(
			$L="dataphyre.user_changes", 
			$P=[
				"mysql"=>"WHERE time>".strtotime($period), 
				"postgresql"=>"WHERE time>to_timestamp(".strtotime($period).")"
			],
			$V=null,
			$CC=true
		)){
			return true;
		}
		return false;
	}

	/**
	 * Applies the inverse operation stored for a single change entry.
	 *
	 * Rollback is intentionally narrow: the target change must belong to `$userid`, requester
	 * self-service rollbacks honor the entry's `can_rollback` flag, and only known rollback
	 * shapes are executed. Supported payloads restore user preferences and invert SQL delete,
	 * insert, or update snapshots before marking the change row as rolled back.
	 *
	 * @param string $changeid Opaque change identifier produced by `create()`.
	 * @param int $userid Owner of the original change entry.
	 * @param int $rollback_request_userid User requesting rollback; `0` represents a system rollback.
	 * @return bool `true` after the inverse mutation and rollback marker are stored, otherwise `false`.
	 */
	public static function rollback(string $changeid, int $userid, int $rollback_request_userid=0): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$early_return=core::dialback("CALL_TIME_MACHINE_ROLLBACK",...func_get_args())) return $early_return;
		if(false!==$change=sql_select(
			$S="changeid", 
			$L="dataphyre.user_changes", 
			$P="WHERE changeid=?", 
			$V=array($changeid), 
			$F=false, 
			$C=false
		)){
			if($change['userid']===$rollback_request_userid){
				if($change['can_rollback']!==true){
					return false;
				}
			}
			if($userid===$change['userid']){
				$change_data=core::decrypt_data($change['data'], array($change['userid'], $changeid));
				$change_data=json_decode($change['data'],true);
				if($change['rollback_type']==='USER_PARAMETER'){
					if(false!==$userdata=\user::get($userid)){
						$user_preferences=$userdata['preferences'];
						$user_preferences[$change_data['setting_name']]=$change_data['old_value'];
						if(false!==sql_update(
							$L="users", 
							$F="preferences=?", 
							$P="WHERE userid=?", 
							$V=array($user_preferences, $change['userid']), 
							$CC=true
						)){
							\user::clear_cache($userid);
						}
					}
				}
				elseif($change['rollback_type']==='SQL_DELETE'){
					if(isset($change_data['rows'])){
						foreach($change_data['rows'] as $row){
							sql_delete(
								$L=$change_data['table'], 
								$P=$row['parameters'], 
								$V=$row['values'], 
								$CC=true
							);
						}
					}
					else
					{
						sql_delete(
							$L=$change_data['table'], 
							$P=$change_data['parameters'],
							$V=$change_data['values'], 
							$CC=true
						);
					}
				}
				elseif($change['rollback_type']==='SQL_INSERT'){
					if(isset($change_data['rows'])){
						foreach($change_data['rows'] as $row){
							sql_insert(
								$L=$change_data['table'], 
								$F=$row, 
								$V=null, 
								$CC=true
							);
						}
					}
					else
					{
						sql_insert(
							$L=$change_data['table'], 
							$F=$change_data['row'], 
							$V=null, 
							$CC=true
						);
					}
				}
				elseif($change['rollback_type']==='SQL_UPDATE'){
					if(isset($change_data['rows'])){
						foreach($change_data['rows'] as $row){
							sql_update(
								$L=$change_data['table'], 
								$F=$row, 
								$P=$change_data['parameters'], 
								$V=$change_data['values'], 
								$CC=true
							);
						}
					}
					else
					{
						sql_update(
							$L=$change_data['table'], 
							$F=$change_data['row'], 
							$P=$change_data['parameters'], 
							$V=$change_data['values'], 
							$CC=true
						);
					}
				}
				else
				{
					return false;
				}
				if(false!==sql_update(
					$L="dataphyre.user_changes", 
					$F=[
						"mysql"=>"rollback=?,rollback_by=?,rollback_time=concat(curdate(),' ',curtime())", 
						"postgresql"=>"rollback=?, rollback_by=?, rollback_time=CURRENT_TIMESTAMP", 
					],
					$P="WHERE changeid=?", 
					$V=array(true,$rollback_request_userid, $changeid), 
					$CC=true
				)){
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * Persists an encrypted change record for future audit and optional rollback.
	 *
	 * The stored row includes the current global user id, the Dataphyre process/request
	 * identifiers, a module-level change type, a rollback strategy, and the JSON-encoded
	 * change data encrypted for that user. The payload shape is determined by `$rollback_type`
	 * and consumed by `rollback()`.
	 *
	 * @param string $type Domain-specific audit category for the originating change.
	 * @param string $rollback_type Rollback strategy such as `USER_PARAMETER`, `SQL_DELETE`, `SQL_INSERT`, or `SQL_UPDATE`.
	 * @param array<string, mixed> $change_data Payload required to replay or invert the change.
	 * @param bool $can_rollback Whether the original user may self-serve the rollback.
	 * @return bool `true` when the row is inserted, or `false` after insert failure.
	 */
	public static function create(string $type, string $rollback_type, array $change_data, bool $can_rollback=false): bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null);
		if(null!==$early_return=core::dialback("CALL_TIME_MACHINE_CREATE",...func_get_args())) return $early_return;
		global $userid;
		$executor_data=[
			"dpid"=>DPID,
			"rqid"=>RQID
		];
		$executor_data=json_encode($executor_data);
		$executor_data=core::encrypt_data($executor_data, array($userid));
		$change_data=json_encode($change_data);
		$change_data=core::encrypt_data($change_data, array($userid));
		if(false!==$change=sql_insert(
			$L="dataphyre.user_changes", 
			$F=[
				"changeid"=>self::change_id(),
				"type"=>$type,
				"rollback_type"=>$rollback_type,
				"can_rollback"=>$can_rollback,
				"userid"=>$userid,
				"data"=>$change_data,
				"executor"=>$executor_data, 
			]
		)){
			return $change['changeid'];
		}
		core::dialback("CALL_TIME_MACHINE_FAILED_CREATING");
		return false;
	}
	
}
