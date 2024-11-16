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

if(file_exists($filepath=$rootpath['common_dataphyre']."config/perfstats.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/perfstats.php")){
	require_once($filepath);
}

if($configurations['dataphyre']['perfstats']['get_rps_on_load']===true){
	perfstats::get_rps();
}

/**
 * Class perfstats
 *
 * This class provides functionality for calculating and caching requests per second (RPS) statistics.
 */
class perfstats{
	
    /**
     * @var array $rps_samples Cache for RPS values, indexed by sampling rate.
     */
	private static $rps_samples=[];
	
    /**
     * Get the requests per second (RPS) value for a given sampling rate.
     *
     * This method calculates RPS based on cached samples or updates the cache with new samples.
     * It creates and maintains a directory structure for caching and cleans up outdated files.
     *
     * @param int $sampling_rate The interval in seconds for sampling RPS (default is 60 seconds).
     * @return float|false The calculated RPS value, or false on failure.
     */
	public static function get_rps(int $sampling_rate=60){
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(null!==$early_return=core::dialback("CALL_PERFSTATS_GET_RPS", ...func_get_args())){
			return $early_return;
		}
		global $rootpath;
		$cache_cath=$rootpath['dataphyre'].'cache/perfstats/rps_cache/';
		if(!is_dir($cache_cath)){
			if(!mkdir($cache_cath, 0777, true) && !is_dir($cache_cath)){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Error creating directory");
				return false;
			}
		}
		if(!isset(perfstats::$rps_samples[$sampling_rate])){
			$files=scandir($cache_cath);
			if($files===false){
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Error scanning directory");
				return false;
			}
			$files=array_diff($files, ['.', '..']);
			$samples=[];
			$i=5;
			foreach($files as $filename){
				$file_path=$cache_cath.$filename;
				if((int)$filename<strtotime("-5 minute")){
					unlink($file_path);
				}
				else
				{
					$i++;
					if($i>=1){
						$i=0;
						if(is_file($file_path)){
							$samples[]=file_get_contents($file_path);
						}
					}
				}
			}
			if(strtotime("now")%$sampling_rate==0){
				$current_file=$cache_cath.strtotime("now");
				if(!is_file($current_file)){
					file_put_contents($current_file, 1);
				}
				else
				{
					file_put_contents($current_file, (int)file_get_contents($current_file)+1);
				}
			}
			if(!empty($samples)){
				perfstats::$rps_samples[$sampling_rate]=round(array_sum($samples)/count($samples), 2);
			}
			else
			{
				perfstats::$rps_samples[$sampling_rate]=0;
			}
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Done");
		return perfstats::$rps_samples[$sampling_rate];
	}

}