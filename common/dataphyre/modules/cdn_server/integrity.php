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

class integrity{

	public static function enforce_block_integrity(int $blockid, ?string $expected_hash=null) : bool {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$blockpath=utils::blockid_to_blockpath($blockid);
		$filename=str_replace('-', '/', $blockpath);
		$filepath=\dataphyre\cdn_server::$storage_filepath.$folder."/".$filename;
		if(file_exists($filepath)){
			if(empty($expected_hash)){
				if(false!==$block=sql_select(
					$S="*",
					$L="dataphyre.cdn_blocks",
					$P="WHERE blockid={$blockid}",
					$V=null,
					$F=false,
					$C=false
				)){
					$expected_hash=$block['hash'];
				}
				else
				{
					// Block is not assigned, delete block from storage if it exists
					unlink($filepath);
					return false;
				}
			}
			if(!empty($expected_hash)){
				if(hash_file("sha256", $filepath)!==$expected_hash){
					storage_operations::discard_content($blockid);
					return false;
				}
			}
		}
		return true;
	}

}	