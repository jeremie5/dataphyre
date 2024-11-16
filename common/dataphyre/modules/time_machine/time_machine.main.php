<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_module_required('time_machine', 'sql');

class time_machine{

	public static function purge_old(string $period='7 days'){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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

	public static function rollback($changeid, int $userid, int $rollback_request_userid=0){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
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
				if($change['user_can_rollback']!==true){
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
	
	public static function create(string $type, string $rollback_type, array $change_data, bool $user_can_rollback=false){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_TIME_MACHINE_CREATE",...func_get_args())) return $early_return;
		global $userid;
		$executor_data=array(
			"session_id"=>$_SESSION['id']
		);
		$executor_data=json_encode($executor_data);
		$executor_data=core::encrypt_data($executor_data, array($userid));
		$change_data=json_encode($change_data);
		$change_data=core::encrypt_data($change_data, array($userid));
		if(false!==$changeid=sql_insert(
			$L="dataphyre.user_changes", 
			$F=[
				"type"=>$type,
				"rollback_type"=>$rollback_type,
				"user_can_rollback"=>$user_can_rollback,
				"userid"=>$userid,
				"data"=>$change_data,
				"executor"=>$executor_data, 
			]
		)){
			return $changeid;
		}
		core::dialback("DP_TIMEMACHINE_FAILED_CREATING");
		return false;
	}
	
}
