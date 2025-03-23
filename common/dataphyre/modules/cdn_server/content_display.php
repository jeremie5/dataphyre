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

class content_display{
	
	public static function display_file_content(string $encoded_blockpath_with_ext, ?array $parameters=null) : void {
        tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		while(ob_get_level()){
			if(false===ob_end_flush()){
				error_display::cannot_display_content("Failed flushing buffer.", 500);
			}
		}
		if(empty($encoded_blockpath_with_ext)){
			error_display::cannot_display_content("No block requested.", 500);
		}
		$file_no_extension=pathinfo($encoded_blockpath_with_ext, PATHINFO_FILENAME);
		$blockpath=utils::decode_blockpath($file_no_extension);
		$filepath=\dataphyre\cdn_server::get_filepath($blockpath);
		$blockid=utils::blockpath_to_blockid($blockpath);
		if(false===$mime_type=utils::get_mime_type($filepath)){
			error_display::cannot_display_content("File exists but is unreadable.", 500);
		}
		header('Server: DataphyreCDN');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: GET, OPTIONS');
		header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
		header('Content-Type: '.$mime_type);
		header('Cache-Control: max-age=31536000, immutable');
		header('Expires: '.gmdate('D, d M Y H:i:s', strtotime("+1 year")).'GMT');
		header('Pragma: cache');
		header('Fetch-time: '.number_format((microtime(true)-$_SERVER["REQUEST_TIME_FLOAT"])*1000, 3, '.').'ms');
        if(false===file_exists($filepath)){
			remote_content::display_remote_file_content($encoded_blockpath_with_ext, $parameters);
		}
		if(isset($parameters['proxy_path'])){
			$proxy_path=explode(',', base64_decode($parameters['proxy_path']));
			$proxy_path_string='Client';
			foreach($proxy_path as $server){
				$proxy_path_string.='<->'.$configurations['dataphyre']['cdn_server']['servers'][$server]['name'];
			}
			header('Proxy-path: '.$proxy_path_string);
		}
		else
		{
			header('Proxy-path: Client<->'.\dataphyre\cdn_server::$cdn_server_name);
		}
		if(!empty($parameters['expected_hash'])){
			if(false===integrity::enforce_block_integrity($blockid, $parameters['expected_hash'])){
				error_display::cannot_display_content("Content block $blockid is corrupt. Integrity enforced.", 503);
			}
		}
		$parameters['mime_type']=$mime_type;
		if(in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp'])){
			self::display_image($filepath, $blockid, $parameters);
		}
		elseif(array_key_exists($mime_type, $video_mime_map=[
			'video/mp4'=>'mp4',
			'video/webm'=>'webm',
			'video/x-matroska'=>'mkv',
			'video/x-msvideo'=>'avi',
			'video/mov'=>'mov',
			'video/divx'=>'divx',
			'video/x-ms-asf'=>'asf',
			'video/x-ms-wmv'=>'wmv',
			'video/av1'=>'av1',
			'video/hevc'=>'hevc',
			'video/x-flv'=>'flv',
			'video/quicktime'=>'prores',
			'video/mpeg'=>'mpeg2'
		])){
			content_streaming::stream_video($filepath, $blockid, $video_mime_map[$mime_type], $parameters);
		}
		else
		{
			content_streaming::stream_file($filepath, $blockid, $parameters);
		}
		error_display::cannot_display_content("Unknown error", 400);
    }
		
	public static function display_image(string $filepath, int $blockid, ?array $parameters=null) : void {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		global $configurations;
		$cache_compression_level=$configurations['dataphyre']['cdn_server']['cache_compression_level'];
		$cache_key=hash("sha256", $cache_compression_level.$filepath.$parameters['passkey'].$parameters['quality'].$parameters['raw_file'].$parameters['width'].$parameters['height'].$parameters['mode']);
		if(null!==$image=\dataphyre\cache::get($cache_key)){
			if($cache_compression_level>0){
				echo gzinflate($image);
			}
			else
			{
				echo $image;
			}
			ob_flush();
			flush();
			fastcgi_finish_request();
			\dataphyre\cache::set($cache_key, $image, \dataphyre\cdn_server::$modified_image_cache_lifespan);
			exit();
		}
		if(isset($parameters['raw_file'])){
			$parameters=[];
		}
		if(false!==$block_pos=containerization::get_block_position_in_container($blockid)){
			[$start, $end]=$block_pos;
			$container_path=containerization::get_container($blockid);
			if(false===$fp=fopen($container_path, 'rb')){
				error_display::cannot_display_content("Failed to open container file", 410);
			}
			fseek($fp, $start, SEEK_SET);
			$file_content=fread($fp, $end-$start);
			fclose($fp);
		}
		else
		{
			if(false===$file_content=file_get_contents($filepath)){
				error_display::cannot_display_content("Image is not readable from disk", 410);
			}
		}
		if (isset($parameters['passkey'])){
			$iv=substr($file_content, 0, 16);
			$encrypted_content=substr($file_content, 16);
			if(false===$decrypted_content=openssl_decrypt($encrypted_content, 'aes-256-cbc', $parameters['passkey'], OPENSSL_RAW_DATA, $iv)){
				error_display::cannot_display_content("Decryption failed", 403);
			}
			$file_content=$decrypted_content;
		}
		if(false!==$modified_image=image_processing::modify_image($file_content, $parameters)){
			echo $modified_image;
			ob_flush();
			flush();
			fastcgi_finish_request();
			if($cache_compression_level>0){
				$modified_image_deflated=gzdeflate($modified_image, $cache_compression_level);
				\dataphyre\cache::set($cache_key, $modified_image_deflated, \dataphyre\cdn_server::$modified_image_cache_lifespan);
			}
			else
			{
				\dataphyre\cache::set($cache_key, $modified_image, \dataphyre\cdn_server::$modified_image_cache_lifespan);
			}
			exit();
		}
		error_display::cannot_display_content("Image is not readable from disk", 410);
	}
	
}