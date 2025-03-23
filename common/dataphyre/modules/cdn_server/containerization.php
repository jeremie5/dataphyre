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

/* 
Whitepaper:
Dataphyre's CDN while being self sharded and replicated across different regions and nodes with adjustable durability the inodes are a major bottleneck to server maintenance.
I am designing this system to be able of object storage and handle insane amounts of documents like more than a trillion.
So my idea is to introduce a containerization system for files below a certain threshold in size such that we may be able to reduce the inodes a thousand fold.
The file per blockid system would need to remain in the servers holding the 150gb of current data, the system is such that migration would occur automatically from the old architecture to the newer in real time. 
I guess migration is not even a concern if we implement the standalone file check discussed below.
When content is added to a block the content will be appended to the container file and the index will be altered to reflect the position and length of the appended content. 
The index will be a fixed length file such that we will be able to get positioning data without reading the whole file.
Rewrites of data will not be possible directly it will need to be deleted then rewritten. 
During a delete the data will be marked as positionless in the index.
When a compaction function is ran on the container it will add up the sum of the lengths of all positionless(or perhaps this is pre-baked in the index).
If the sum exceeds a threshold a temp file is created and the index is iterated and non positionless blocks are copied over to the new container.
Once all blocks are copied over the temp file is renamed to replace the container data file.
*/

class containerization{
	
	const INDEX_ENTRY_SIZE=44;
	const INDEX_OFFSET_START=8;
	const CONCURRENCY_RETRIES=10;
	const CONCURRENCY_TIMEOUT_MICROS=10000; 
	
	public static function get_container(int $blockid): string {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$container_id=$blockid %(\dataphyre\cdn_server::$inodes_per_directory_depth / \dataphyre\cdn_server::$container_block_count);
		$folder=utils::blockid_to_blockpath($blockid);
		$folder=dirname($folder); // Remove the last "directory"(actually the non-container block filename)
		$container_path=\dataphyre\cdn_server::$storage_filepath."{$folder}/{$container_id}.dat";
		return $container_path;
	}
	
	public static function get_block_position_in_container(int $blockid, int $attempt=0): array|bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($attempt>self::CONCURRENCY_RETRIES){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Exceeded CONCURRENCY_RETRIES', $T='warning');
			return false;
		}
		if(false===$container_path=self::get_container($blockid)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Waterfall failure of get_container()');
			return false;
		}
		// Compute the exact byte offset in the index
		$index_position=($blockid % \dataphyre\cdn_server::$container_block_count);
		$index_offset=self::INDEX_OFFSET_START+($index_position * self::INDEX_ENTRY_SIZE);
		// Open the container file for reading
		if(false===$fp=fopen($container_path, 'r')){
			if(file_exists($container_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::get_block_position_in_container($blockid, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, does not exist.', $T='warning');
			return false;
		}
		fseek($fp, $index_offset, SEEK_SET);
		if(false===$entry=fread($fp, self::INDEX_ENTRY_SIZE)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed read of container index');
			return false;
		}
		if(false===fclose($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container index');
			return false;
		}
		$blockid_read=(int)substr($entry, 0, 20);
		$start=(int)trim(substr($entry, 20, 12), " \0\0");
		$end=(int)trim(substr($entry, 32, 12), " \0\0");
		if(!is_numeric($start) || !is_numeric($end)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Position overflow', $T='warning');
			return false;
		}
		if($blockid_read !== $blockid || $start==0 && $end==0){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Block not assigned or deleted');
			return false;
		}
		return [$start, $end];
	}
	
	public static function log_pending_change(int $blockid, string $container_path, int $start, int $end, bool $index_written=false): bool {
		$log_file="{$container_path}.pending";
		if(false===$fp=fopen($log_file, 'a')){
			tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S="Failed to open pending log");
			return false;
		}
		// Format: blockid,start,end,index_written(0=pending, 1=committed)
		$entry="{$blockid},{$start},{$end}," .($index_written ? "1" : "0")."\n";
		if(false===fwrite($fp, $entry)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed write to pending log');
		}
		if(false===fflush($fp) || fsync($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed validating pending log writes were flushed and commited to disk');
		}
		if(false===fclose($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing pending log');
		}
		return true;
	}
	
	public static function recover_pending_changes(string $container_path): bool {
		$log_file="{$container_path}.pending";
		if(!file_exists($log_file)){
			return true; // No pending writes to recover
		}
		if(false===$fp=fopen($log_file, 'r')){
			tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, "Failed to read pending log", "warning");
			return false;
		}
		$valid_entries=[];
		$rollback_needed=false;
		while(($line=fgets($fp)) !== false){
			[$blockid, $start, $end, $index_written]=explode(",", trim($line));
			$start=(int)$start;
			$end=(int)$end;
			$index_written=(bool) $index_written;
			// If file size is smaller than expected, rollback
			if(filesize($container_path)<$end){
				$rollback_needed=true;
			}
			else
			{
				$valid_entries[]=[$blockid, $start, $end, $index_written];
			}
		}
		if(false===fclose($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing pending log');
		}
		// If rollback is needed, truncate to the last valid position
		if($rollback_needed){
			$last_valid_end=max(array_column($valid_entries, 2)); // Find max valid end position
			if(false===ftruncate(fopen($container_path, 'r+'), $last_valid_end)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed truncating container');
			}
		}
		// Now restore missing index entries
		foreach($valid_entries as [$blockid, $start, $end, $index_written]){
			if(!$index_written){
				if(false===self::write_index_entry($container_path, $blockid, $start, $end)){
					tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Waterfall failure of write_index_entry()');
				}
			}
		}
		if(false===unlink($log_file)){ // Clear log after recovery
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed deleting pending log');
		}
		return true;
	}

	public static function write_index_entry(string $container_path, int $blockid, int $start, int $end): bool {
		if(false===$fp=fopen($container_path, 'r+')){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed opening container');
			return false;
		}
		if(false===flock($fp, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed locking container');
		}
		$index_position=($blockid % \dataphyre\cdn_server::$container_block_count);
		$index_offset=self::INDEX_OFFSET_START+($index_position * self::INDEX_ENTRY_SIZE);
		// Construct updated entry
		$updated_entry=str_pad($blockid, 20, "0", STR_PAD_LEFT)
					  .str_pad($start, 12, "0", STR_PAD_LEFT)
					  .str_pad($end, 12, "0", STR_PAD_LEFT);
		fseek($fp, $index_offset, SEEK_SET);
		if(false===fwrite($fp, $updated_entry)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed write to container');
		}
		if(false===fflush($fp) || false===fsync($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
		}
		if(false===fclose($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
		}
		return true;
	}
	
	public static function create_container(string $container_path, int $attempt=0): bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($attempt>self::CONCURRENCY_RETRIES){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Exceeded CONCURRENCY_RETRIES', $T='warning');
			return false;
		}
		if(file_exists($container_path)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Container file already exists.', $T='warning');
			return false;
		}
		// Open container file for writing
		if(false===$fp=fopen($container_path, 'x')){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to create container file, retrying.');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::create_container($container_path, $attempt+1);
		}
		if(false===flock($fp, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock, retrying.');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::create_container($container_path, $attempt+1);
		}
		// Write 8-byte uncontainerization counter(zero-padded)
		if(false===fwrite($fp, str_pad("0", 8, "0", STR_PAD_LEFT))){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed validating container writes were flushed and commited to disk');
		}
		// Fill index with placeholder(unassigned) entries
		$empty_entry=str_pad("0", 20, "0", STR_PAD_LEFT)  // Block ID
					.str_pad("0", 12, "0", STR_PAD_LEFT)  // Start position
					.str_pad("0", 12, "0", STR_PAD_LEFT); // End position
		for($i=0; $i<\dataphyre\cdn_server::$container_block_count; $i++){
			if(false===fwrite($fp, $empty_entry)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed write to index');
			}
		}
		if(false===fclose($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
		}
		return true;
	}

	public static function containerize_block(int $blockid, int $attempt=0): array|bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($attempt>self::CONCURRENCY_RETRIES){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Exceeded CONCURRENCY_RETRIES', $T='warning');
			return false;
		}
		if(false===$container_path=self::get_container($blockid)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Waterfall failure from get_container.');
			return false;
		}
		// Open the container file for reading and appending
		if(false===$fp_container=fopen($container_path, 'r+')){
			if(file_exists($container_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::containerize_block($blockid, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, does not exist.');
			return false;
		}
		if(false===flock($fp_container, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock', $T='warning');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::containerize_block($blockid, $attempt+1);
		}
		// Open the block file for reading in streaming mode
		$block_path=utils::blockid_to_blockpath($blockid);
		if(false===$fp_block=fopen($block_path, 'r')){
			if(file_exists($block_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read block, exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::containerize_block($blockid, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read block, does not exist.');
			if(false===fclose($fp_container)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
			}
			return false;
		}
		if(false===flock($fp_block, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock, retrying.', $T='warning');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::containerize_block($blockid, $attempt+1);
		}
		// Compute the total index size
		$index_size=self::INDEX_OFFSET_START+(\dataphyre\cdn_server::$container_block_count * self::INDEX_ENTRY_SIZE);
		// Compute the maximum data section size
		$max_data_size=999_999_999_999-$index_size; 
		// Find the current file size(end of data section)
		fseek($fp_container, 0, SEEK_END);
		if(false===$file_size=ftell($fp_container)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed asserting container size');
		}
		// Calculate where new data should start
		$new_start=max($file_size, $index_size);
		// Check if the new size exceeds the true container limit
		if(false===$block_size=filesize($block_path)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed asserting block size');
		}
		$new_end=$new_start+$block_size;
		// Check against computed max data size
		if($new_end>$max_data_size){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Container is full', $T='warning');
			if(false===fclose($fp_container)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
			}
			if(false===fclose($fp_block)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing block');
			}
			return false;
		}
		if(false===self::log_pending_change($blockid, $container_path, $new_start, $new_end, false)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Watefall failure of log_pending_change', $T='warning');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::containerize_block($blockid, $attempt+1);
		}
		// Stream block file into the container(chunked write)
		fseek($fp_container, $new_start, SEEK_SET);
		while(!feof($fp_block)){
			if(false===$chunk=fread($fp_block, 65536)){ // Read in 64KB chunks
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed a block stream read');
			}
			if(false===fwrite($fp_container, $chunk)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed a block stream write to container');
			}
		}
		fclose($fp_block); // Close the block file
		if(false===fflush($fp_container) || false===fsync($fp_container)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed validating container writes were flushed and commited to disk', $T='warning');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			ftruncate($fp_container, $new_start); // Rollback
			if(false===fclose($fp_container)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
			}
			return self::containerize_block($blockid, $attempt+1);
		}
		if(!self::write_index_entry($container_path, $blockid, $new_start, $new_end)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Watefall failure of write_index_entry', $T='warning');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			ftruncate($fp_container, $new_start); // Rollback
			if(false===fclose($fp_container)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
			}
			return self::containerize_block($blockid, $attempt+1);
		}
		if(false===self::log_pending_change($blockid, $container_path, $new_start, $new_end, true)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Watefall failure of log_pending_change', $T='warning');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::containerize_block($blockid, $attempt+1);
		}
		if(false===fclose($fp_container)){ // Close the container file
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
		}
		if(false===unlink("{$container_path}.pending")){ // Delete pending log as operation was successful
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed deleting pending log');
		}
		return [
			"start"=>$new_start, 
			"end"=>$new_end, 
			"container"=>$container_path
		];
	}

	public static function uncontainerize_block(int $blockid, int $attempt=0): bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($attempt>self::CONCURRENCY_RETRIES){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Exceeded CONCURRENCY_RETRIES', $T='warning');
			return false;
		}
		if(false===$container_path=self::get_container($blockid)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Waterfall failure of get_container()');
		}
		// Open container for read/write
		if(false===$fp=fopen($container_path, 'r+')){
			if(file_exists($container_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::uncontainerize_block($blockid, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, does not exist.');
			return false;
		}
		if(false===flock($fp, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::uncontainerize_block($blockid, $attempt+1);
		}
		if(false===self::log_pending_change($blockid, $container_path, 0, 0, false)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Watefall failure of log_pending_change');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::uncontainerize_block($blockid, $attempt+1);
		}
		// Read current uncontainerization count(first 8 bytes)
		fseek($fp, 0, SEEK_SET);
		$current_count=(int)fread($fp, 8);
		// Possible edge case, what if fread returns a null and it gets cast to 0?
		// Increment and write back
		$current_count++;
		fseek($fp, 0, SEEK_SET);
		if(false===fwrite($fp, str_pad($current_count, 8, "0", STR_PAD_LEFT))){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed updating uncontainerization count in index');
		}
		if(false===self::write_index_entry($container_path, $blockid, 0, 0)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Watefall failure of write_index_entry');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			if(false===fclose($fp)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
			}
			return self::uncontainerize_block($blockid, $attempt+1);
		}
		// Trigger compaction if threshold is exceeded
		if($current_count >= \dataphyre\cdn_server::$container_uncontainerize_threshold){
			if(false===self::compact_container($container_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Waterfall failure of compact_container()');
			}
		}
		if(false===self::log_pending_change($blockid, $container_path, 0, 0, true)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Watefall failure of log_pending_change');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::uncontainerize_block($blockid, $attempt+1);
		}
		if(false===fclose($fp)){ // Close the container file
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
		}
		if(false===unlink("{$container_path}.pending")){ // Delete pending log as operation was successful
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed deleting pending log');
		}
		return true;
	}

	public static function compact_container(string $container_path, int $attempt=0): bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($attempt>self::CONCURRENCY_RETRIES){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Exceeded CONCURRENCY_RETRIES', $T='warning');
			return false;
		}
		$temp_path=$container_path.".tmp";
		if(false===$fp_old=fopen($container_path, 'r')){
			if(file_exists($container_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::compact_container($container_path, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, does not exist.', $T='warning');
			return false;
		}
		if(false===flock($fp_old, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock on old container');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS); // Retry acquiring lock in 10ms
			return self::compact_container($container_path, $attempt+1);
		}
		if(false===$fp_new=fopen($temp_path, 'w')){
			if(file_exists($temp_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::compact_container($temp_path, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, does not exist.', $T='warning');
			return false;
		}
		if(false===flock($fp_new, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock on new container, retrying');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS); // Retry acquiring lock in 10ms
			return self::compact_container($container_path, $attempt+1);
		}
		if(false===fwrite($fp_new, str_pad("0", 8, "0", STR_PAD_LEFT))){ // Reset uncontainerization counter in new file
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed writing uncontainerization counter to container');
		}
		$block_updates=[];
		$current_offset=8+(\dataphyre\cdn_server::$container_block_count * self::INDEX_ENTRY_SIZE); // Start after index
		for($i=0; $i<\dataphyre\cdn_server::$container_block_count; $i++){
			fseek($fp_old, 8+($i * self::INDEX_ENTRY_SIZE), SEEK_SET);
			$entry=fread($fp_old, self::INDEX_ENTRY_SIZE);
			if($entry===false){
				tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S='Failed a read from old container');
				continue;
			}
			$blockid=(int)substr($entry, 0, 20);
			$start=(int)substr($entry, 20, 12);
			$end=(int)substr($entry, 32, 12);
			if($start===0 && $end===0){
				// This block has been deleted or never assigned, so skip it
				continue;
			}
			// Calculate total bytes in this block
			$block_size=$end - $start;
			if($block_size <= 0){
				// Shouldn’t happen if the index is valid, but just in case
				continue;
			}
			// Position fp_old at the start of this block
			fseek($fp_old, $start, SEEK_SET);
			// Stream the block data in chunks from old container to the new container
			$remaining=$block_size;
			$chunk_size=65536; // 64KB, adjust as needed
			while($remaining>0){
				$read_length=min($remaining, $chunk_size);
				$data_chunk=fread($fp_old, $read_length);
				if($data_chunk===false){
					tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S='Failed reading chunk from old container');
					break; // handle error or do a retry mechanism
				}
				$written=fwrite($fp_new, $data_chunk);
				if($written===false || $written < strlen($data_chunk)){
					tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S='Failed writing chunk to new container');
					break; // handle partial writes or do a retry
				}
				$remaining-=$written;
			}
			// Record the new start/end positions in the new container
			$block_updates[$blockid]=[
				$current_offset,
				$current_offset+$block_size
			];
			$current_offset+= $block_size;
		}
		if(false===fclose($fp_old)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing old container');
		}
		if(false===fflush($fp_new) || false===fsync($fp_new)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed validating new container writes were flushed and commited to disk');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::compact_container($container_path, $attempt+1);
		}
		if(false===fclose($fp_new)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing new container');
		}
		return self::batch_container_reassign($temp_path, $block_updates) && rename($temp_path, $container_path);
	}
	
	public static function batch_container_reassign(string $container_path, array $block_updates, int $attempt=0): bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if($attempt>self::CONCURRENCY_RETRIES){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Exceeded CONCURRENCY_RETRIES', $T='warning');
			return false;
		}
		// Open the container file for updating the index in-place
		if(false===$fp=fopen($container_path, 'r+')){ // Read+Write mode
			if(file_exists($container_path)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read or write to container, file exists, retrying.');
				usleep(self::CONCURRENCY_TIMEOUT_MICROS);
				return self::batch_container_reassign($container_path, $block_updates, $attempt+1);
			}
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Unable to read container, does not exist.', $T='warning');
			return false;
		}
		if(false===flock($fp, LOCK_EX)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed acquiring lock, retrying');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::batch_container_reassign($container_path, $block_updates, $attempt+1);
		}
		foreach($block_updates as $blockid=>[$new_start, $new_end]){
			// Compute the exact byte offset of the block's index entry
			$index_position=($blockid % \dataphyre\cdn_server::$container_block_count);
			$index_offset=self::INDEX_OFFSET_START+($index_position * self::INDEX_ENTRY_SIZE);
			// Construct updated 44-byte entry(zero-padded)
			$updated_entry=str_pad($blockid, 20, "0", STR_PAD_LEFT) // Block ID
						  .str_pad($new_start, 12, "0", STR_PAD_LEFT) // Start position
						  .str_pad($new_end, 12, "0", STR_PAD_LEFT); // End position
			// Seek and overwrite only this index entry
			fseek($fp, $index_offset, SEEK_SET);
			if(false===fwrite($fp, $updated_entry)){
				tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed a write to index');
			}
		}
		if(false===fflush($fp) || false===fsync($fp)){
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed validating container writes were flushed and commited to disk');
			usleep(self::CONCURRENCY_TIMEOUT_MICROS);
			return self::batch_container_reassign($container_path, $block_updates, $attempt+1);
		}
		if(false===fclose($fp)){ // Close file handle after updating all entries
			tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S='Failed closing container');
		}
		return true;
	}

}