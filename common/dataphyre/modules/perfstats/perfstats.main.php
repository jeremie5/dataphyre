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

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Loaded");

if(file_exists($filepath=$rootpath['common_dataphyre']."config/perfstats.php")){
	require_once($filepath);
}
if(file_exists($filepath=$rootpath['dataphyre']."config/perfstats.php")){
	require_once($filepath);
}
if(!isset($configurations['dataphyre']['perfstats'])){
	//core::unavailable("MOD_PERFSTATS_NO_CONFIG", "safemode");
}

if(core::get_config("dataphyre/perfstats/get_rps_on_load")===true){
	perfstats::get_rps();
}

class perfstats{
	
	private static $rps_samples=[];
	
	public static function get_rps($sampling_rate=60){
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S = null, $T = 'function_call', $A = func_get_args()); // Log the function call
		if (null !== $early_return = core::dialback("CALL_PERFSTATS_GET_RPS", ...func_get_args())) {
			return $early_return;
		}
		global $rootpath;
		$cachePath = $rootpath['dataphyre'] . 'cache/perfstats/rps_cache/';
		// Ensure the cache directory exists
		if (!is_dir($cachePath)) {
			// Using recursive directory creation
			if (!mkdir($cachePath, 0777, true) && !is_dir($cachePath)) {
				// Log error if unable to create the directory
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S = "Error creating directory");
				return false;
			}
		}
		if (!isset(perfstats::$rps_samples[$sampling_rate])) {
			$files = scandir($cachePath);
			if ($files === false) {
				// Log error if unable to scan directory
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S = "Error scanning directory");
				return false;
			}
			$files = array_diff($files, ['.', '..']);
			$samples = [];
			$i = 5;
			foreach ($files as $filename) {
				$filePath = $cachePath . $filename;
				if ((int)$filename < strtotime("-5 minute")) {
					unlink($filePath);
				} else {
					$i++;
					if ($i >= 1) {
						$i = 0;
						if (is_file($filePath)) {
							$samples[] = file_get_contents($filePath);
						}
					}
				}
			}
			if (strtotime("now") % $sampling_rate == 0) {
				$current_file = $cachePath . strtotime("now");
				if (!is_file($current_file)) {
					file_put_contents($current_file, 1);
				} else {
					file_put_contents($current_file, (int)file_get_contents($current_file) + 1);
				}
			}
			if (!empty($samples)) {
				perfstats::$rps_samples[$sampling_rate] = round(array_sum($samples) / count($samples), 2);
			} else {
				perfstats::$rps_samples[$sampling_rate] = 0;
			}
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $S = "Done");
		return perfstats::$rps_samples[$sampling_rate];
	}

}