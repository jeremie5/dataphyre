<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_module_required('issue', 'sql');

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
	
	public static function recrypt(int $issueid) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		static $recrypted_issues=[];
		if(in_array($issueid, $recrypted_issues)){
			sql_select(
				$S="*", 
				$L="issues", 
				$P="WHERE issueid=?", 
				$V=[$issueid], 
				$F=false, 
				$C=false, 
				$Q='end', 
				$K=function($row){
					if($row!==false){
						$new_data=[];
						$new_data['context']=\dataphyre\core::decrypt_data($row['context'], array($row['date'],$row['server_name']), 'return');
						sql_update(
							$L="issues", 
							$F=$new_data, 
							$P="WHERE issueid=?", 
							$V=[$issueid], 
							$CC=true
						);
					}
				}
			);
			$recrypted_issues[]=$issueid;
			return true;
		}
		return false;
	}

	public static function create(string $type, array $context=[], string $description='', int $severity=0) : bool|int {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(!is_callable(self::$email_sending_callback)){
			return false; // Perhaps should be dataphyre\core::unvailable().
		}
		$context["app_version"]=self::$application_version;
		$md5=md5($type.json_encode($context).$severity);
		// Static context from hereon 
		$context["load_level"]=\dataphyre\core::get_server_load_level();
		sql_select(
			$S="issueid", 
			$L="issues", 
			$P="WHERE md5=? AND status='pending'", 
			$V=array($md5), 
			$F=false, 
			$C=true, 
			$Q='issue_creation', 
			$C=function($result)use($type,$context,$md5,$description,$severity){
				if($result===false){
					$execution_ip=REQUEST_IP_ADDRESS;
					$server_ip=$_SERVER['SERVER_ADDR'];
					$time=date('Y-m-d H:i:s', strtotime('now'));
					$status="pending";
					$context=array_merge($context, self::$additional_context);
					$context=json_encode($context);
					$context_encrypted=\dataphyre\core::encrypt_data($context, array($time,$server_name));
					if(false===$issue=sql_insert(
						$L="issues", 
						$F=[
							"md5"=>$md5,
							"type"=>$type,
							"description"=>$description,
							"context"=>$context_encrypted,
							"server_ip"=>$server_ip,
							"status"=>$status,
							"date"=>$time
						],
						$V=null,
						$CC=true
					)){
						tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Failed creating issue in database", $S="fatal");
					}
					$issueid=$issue['issueid'];
					$body.='Description: '.$description.'<br><br>';
					$body.='Server IP: '.$server_ip.'<br>';
					$body.='Execution IP: '.$execution_ip.'<br>';
					if(!empty($context)){
						$body.='Context: '.$context.'<br>';
					}
					$body.='Severity: '.$severity.'<br>';
					$body.='Status: '.$status.'<br>';
					if(is_numeric($issueid)){
						$body.='Given IssueID: '.$issueid.'<br>';
					}
					else
					{
						$body.='<b>Unknown issueid</b><br>';
					}
					$body.='Time: '.$time.' ('.config("app/base_timezone").')<br>';
					$body.='<b>This is an automated email. All information contained herein is confidential.</b>';
					if($severity>=5){
						$subject="High severity issue ($type) on server ($server_ip)";
					}
					elseif($severity>=2){
						$subject="Medium severity issue ($type) on server ($server_ip)";
					}
					elseif($severity>=0){
						$subject="Low severity issue ($type) on server ($server_ip)";
					}
					self::$email_sending_callback($subject, $body);
					return $issueid;
				}
				else
				{
					return $existing['id'];
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Issue already known", $S="warning");
				}
			}
		);
		return false;
	}
	
}
