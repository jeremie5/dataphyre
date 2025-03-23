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

class utils{

	public static function get_mime_type(string $filepath) : string|bool {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$file_extension=strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
		$mime_types=[
			'js'=>'text/javascript',
			'css'=>'text/css',
			'woff'=>'application/font-woff',
			'ttf'=>'application/font-sfnt',
			'jpg'=>'image/jpeg',
			'jpeg'=>'image/jpeg',
		];
		if(array_key_exists($file_extension, $mime_types)){
			return $mime_types[$file_extension];
		}
		if(file_exists($filepath) && false===is_readable($filepath)){
			return false;
		}
		if($finfo=finfo_open(FILEINFO_MIME_TYPE)){
			if(false!==$mime_type=finfo_file($finfo, $filepath)){
				return $mime_type;
			}
			finfo_close($finfo);
		}
		return 'application/octet-stream';
	}
	
	public static function encode_blockpath(string $blockpath) : string {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$paths=explode('/', $blockpath);
		foreach($paths as $id=>$path){
			$paths[$id]=strtoupper(dechex(intval($path)));
		}
		return implode('-',$paths);
	}

	public static function decode_blockpath(string $blockpath) : string {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$paths=explode('-', $blockpath);
		foreach($paths as $id=>$path){
			$paths[$id]=hexdec($path);
		}
		return implode('-',$paths);
	}

	public static function blockid_to_blockpath(int $blockid) : string {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		while($blockid>0){
			$remainder=$blockid%\dataphyre\cdn_server::$inodes_per_directory_depth;
			$path='-'.$remainder.$path;
			$temp=$blockid-$remainder;
			$blockid=$temp/\dataphyre\cdn_server::$inodes_per_directory_depth;
		}
		return ltrim($path, '-');
	}
	
	public static function blockpath_to_blockid(string $blockpath) : int {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		$blockid=0;
		$pathParts=explode('-', $blockpath);
		foreach($pathParts as $part){
			if($part!==''){
				$blockid=$blockid*\dataphyre\cdn_server::$inodes_per_directory_depth+intval($part);
			}
		}
		return $blockid;
	}

}	