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


$catched_tracelog['errors']='';
$catched_tracelog['info']='';
if(!function_exists('tracelog')){
	function tracelog($a=null, $b=null, $c=null, $d=null, $e=null, $f=null, $g=null, $h=null){
		global $catched_tracelog;
		if(empty($catched_tracelog['info'])){ $catched_tracelog['info']=''; }
		if(empty($catched_tracelog['errors'])){ $catched_tracelog['errors']=''; }
		if(!empty($f)){
			if($g=='warning' || $g=='fatal'){
				$catched_tracelog['errors'].=$f."<br>";
				return;
			}
			$catched_tracelog['info'].=$f."<br>";
		}
	}
}
if(!function_exists('get_tracelog_errors')){
	function get_tracelog_errors(){
		global $catched_tracelog;
		$result=$catched_tracelog;
		$catched_tracelog['errors']='';
		$catched_tracelog['info']='';
		return $result;
	}
}

function sql_diagnosis(){
	global $rootpath;
	global $dpanel_mode;
	global $configurations;
	$log='';
	$errors=[];

	if(function_exists("core_diagnosis")){
		$result=core_diagnosis();
		$log.="<div class='ml-4'>".$result['log']."</div>";
		$errors=array_merge_recursive($errors, $result['errors']);
	}
	
	require_once(__DIR__."/sql.main.php");
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}
	
	$log.="dp_SQL configurations are valid.<br>";
	
	
	/*
	$rowid=rand(0,99999999999);
	$insert_id=sql::db_insert($L="dataphyre.sql_diag", $F="id", $V=array($rowid), $CC=true);
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}
	if($insert_id===$rowid){
		$log.="<span class='text-success'>dp_SQL Insert successful</span><br>";
	}
	else
	{
		$log.="<span class='text-danger'>dp_SQL Insert failed</span><br>";
		array_push($errors, "dp_SQL Insert failed");
	}
	
	$rowid=rand(0,99999999999);
	$result=sql::db_update($L="dataphyre.sql_diag", $F="id=?", $P="WHERE id=?", $V=array($rowid, $insert_id), $CC=true);
	$catched_tracelog=get_tracelog_errors();
	if(!empty($catched_tracelog['info'])){
		$log.="Tracelog info:<br><div class='ml-4'>".$catched_tracelog['info']."</div>";
	}
	if(!empty($catched_tracelog['errors'])){
		$log.="<span class='text-danger'>Tracelog error(s) have been catched:<br><div class='ml-4'>".$catched_tracelog['errors']."</div></span>";
	}
	if($result===true){
		$log.="<span class='text-success'>dp_SQL Update successful</span><br>";
	}
	else
	{
		$log.="<span class='text-danger'>dp_SQL Update failed</span><br>";
		array_push($errors, "dp_SQL Update failed");
	}
	*/

	
	if(empty($errors)){
		$log.="<span class='text-success'>All tests passed.</span><br>";
	}
	return array("errors"=>$errors, "log"=>$log);
}