<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\sql;

\dp_define_module_config('sql', 'DP_SQL_CFG');

\dataphyre\sql\diagnostic::tests();

class diagnostic{

    public static function tests(): void {
		$verbose=[];
		if(!\function_exists('sql_query')){
			$verbose[]=[
				'module'=>'sql',
				'level'=>'warning',
				'message'=>'SQL cluster probes were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>time(),
			];
			\dataphyre\dpanel::add_verbose($verbose);
			return;
		}
		// Make sure all database servers across available clusters are on the same timezone as php
		// CAUTION: This uses a multipoint raw query which will return the one result that is unlike the others.
		$query['postgresql']='SELECT EXTRACT(EPOCH FROM (NOW() - TIMEZONE(\'UTC\', NOW()))) AS timediff';
		$query['mysql']='SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP) AS timediff;';
		$query['sqlite']='SELECT ROUND((julianday(\'now\', \'localtime\') - julianday(\'now\')) * 86400.0) AS timediff;';
		foreach(["postgresql", "mysql", "sqlite"] as $dbms){
			$matching_clusters=[];
			foreach(DP_SQL_CFG['datacenters'] ?? [] as $location=>$location_data){
				foreach($location_data['dbms_clusters'] as $cluster_name=>$cluster_data){
					if($cluster_data['dbms']===$dbms){
						$matching_clusters[]=$cluster_name;
					}
				}
			}
			if(empty($matching_clusters))continue;
			foreach($matching_clusters as $cluster){
				if(false!==$result=\sql_query(
					$Q=[
						"dbms_cluster_override"=>$cluster, 
						$dbms=>$query[$dbms]
					],
					$V=null, 
					$M=true, 
					$C=true, 
					$CC=false, 
					$Q=false
				)){
					$timediff_seconds=0;
					if($dbms==='postgresql'){
						$timediff_seconds=abs((int) $result['timediff']);
					}
					elseif($dbms==='sqlite'){
						$timediff_seconds=abs((int) $result['timediff']);
					}
					elseif($dbms==='mysql'){
						$parts=explode(':', $result['timediff']);
						$timediff_seconds=abs(((int)$parts[0]*3600+(int)$parts[1]*60+(int)$parts[2]));
					}
					if(abs($timediff_seconds)>1){
						$verbose[]=['module'=>'sql', 'error'=>'Time mismatch ('.$timediff_seconds.' seconds) between web server and cluster '.$cluster, 'time'=>time()];
					}
					else
					{
						$verbose[]=['module'=>'sql', 'error'=>'No time mismatch between web server and cluster '.$cluster, 'time'=>time()];
					}
				}
			}
		}
		\dataphyre\dpanel::add_verbose($verbose);
    }

}
