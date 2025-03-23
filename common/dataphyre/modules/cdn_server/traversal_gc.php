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

namespace dataphyre\cdn_server;

class traversal_gc{
    
    private $start_time;
    private $timeout;

    public function __construct(){
        $this->timeout=$GLOBALS['configurations']['dataphyre']['cdn_server']['gc']['traversal_timeout']-1;
        $this->start_time=time();
    }

    public function garbage_collect(string $directory){
		echo "Processing directory $directory<br><br>";
        $entries=scandir($directory);
        $entries=array_diff($entries, ['.', '..']);
        $entries=array_values($entries);
        shuffle($entries);
        $directories=[];
        $files=[];
        foreach($entries as $entry){
            $path=$directory.DIRECTORY_SEPARATOR.$entry;
            if(is_dir($path)){
                $directories[]=$path;
            }
			else
			{
                $files[]=$path;
            }
        }
        if(rand(0, 1)===0 && count($files)>0){
            $this->process_files($files);
        }
        foreach($directories as $subdir){
            if($this->has_timed_out()){
                return;
            }
            $this->garbage_collect($subdir);
        }
        if(count($files)>0){
            $this->process_files($files);
        }
    }

    private function process_files(array $files){
        shuffle($files);
        foreach($files as $file){
            if($this->has_timed_out()){
                return;
            }
			$filepath=str_replace(\dataphyre\cdn_server::$storage_filepath."/", '', $file);
			$blockpath=str_replace("/", "-", $filepath);
			$encoded_blockpath=\dataphyre\cdn_server::encode_blockpath($filepath);
			$blockid=\dataphyre\cdn_server::blockpath_to_blockid($blockpath);
            echo "Block $blockid ($encoded_blockpath) is valid? ".json_encode(\dataphyre\cdn_server::enforce_block_integrity($blockid))."<br>";
        }
    }

    private function has_timed_out(){
        return(time()-$this->start_time)>=$this->timeout;
    }
}

// Initialize the garbage collection process
$cdn_gc=new \dataphyre\cdn_server\traversal_gc();
$cdn_gc->garbage_collect(\dataphyre\cdn_server::$storage_filepath);

sql_delete(
	$L="dataphyre.cdn_blocks",
	$P="WHERE replication_count<0",
);