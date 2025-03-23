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

class content_streaming{

	public static function stream_video(string $filepath, int $blockid, string $format, ?array $parameters = null): void {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S = null, $T = 'function_call', $A = func_get_args());
		$valid_formats = ['mp4', 'webm', 'avi', 'mkv', 'wmv', 'mov', 'divx', 'asf', 'av1', 'hevc', 'ogv', '3gp', 'flv', 'prores', 'mpeg2'];
		if (!in_array($format, $valid_formats)) {
			error_display::cannot_display_content("Invalid video format", 410);
		}
		header('Content-Type: video/' . $format);
		header('Accept-Ranges: bytes');
		$block_pos = containerization::get_block_position_in_container($blockid);
		$file_size = $block_pos ? ($block_pos[1] - $block_pos[0]) : filesize($filepath.'.dt');
		$range_start = 0;
		$range_end = $file_size - 1;
		$content_length = $file_size;
		$is_partial = false;
		if (isset($_SERVER['HTTP_RANGE'])) {
			preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
			$range_start = isset($matches[1]) ? (int) $matches[1] : 0;
			$range_end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $range_end;
			if ($range_start >= $file_size || $range_start > $range_end) {
				header('HTTP/1.1 416 Range Not Satisfiable');
				header("Content-Range: bytes */$file_size");
				exit();
			}
			if ($range_end > $file_size - 1) {
				$range_end = $file_size - 1;
			}
			$content_length = ($range_end - $range_start) + 1;
			$is_partial = true;
		}
		if ($is_partial) {
			header('HTTP/1.1 206 Partial Content');
			header("Content-Range: bytes $range_start-$range_end/$file_size");
			header("Content-Length: $content_length");
		} else {
			header('HTTP/1.1 200 OK');
			header("Content-Length: $content_length");
		}
		$fp = $block_pos ? fopen(containerization::get_container($blockid), 'rb') : fopen($filepath.'.dt', 'rb');
		if (!$fp) {
			error_display::cannot_display_content("Failed to open video file", 410);
		}
		if (!flock($fp, LOCK_SH)) {
			fclose($fp);
			error_display::cannot_display_content("Failed to lock file", 410);
		}
		fseek($fp, $block_pos ? $block_pos[0] + $range_start : $range_start, SEEK_SET);
		$bytes_remaining = $content_length;
		while (!feof($fp) && $bytes_remaining > 0) {
			$buffer_size = min(8192, $bytes_remaining);
			$buffer = fread($fp, $buffer_size);
			echo $buffer;
			flush();
			$bytes_remaining -= strlen($buffer);
		}
		flock($fp, LOCK_UN);
		fclose($fp);
		exit();
	}

	public static function stream_file(string $filepath, int $blockid, ?array $parameters=null): void {
		tracelog(__DIR__, __FILE__, __LINE__, __CLASS__, __FUNCTION__, $S=null, $T='function_call', $A=func_get_args());
		global $configurations;
		// Open the file (either from a container or as a standalone file)
		if(false!==$block_pos=containerization::get_block_position_in_container($blockid)){
			[$start, $end]=$block_pos;
			$container_path=containerization::get_container($blockid);
			$fp=fopen($container_path, 'rb');
			fseek($fp, $start, SEEK_SET);
		}
		else
		{
			$fp=fopen($filepath.'.dt', 'rb');
		}
		if($fp===false){
			error_display::cannot_display_content("Failed to open file", 410);
		}
		// Handle decryption if needed
		if(isset($parameters['passkey'])){
			$iv=fread($fp, 16);
			while(!feof($fp)){
				$encrypted_chunk=fread($fp, min($configurations['dataphyre']['cdn_server']['streamed_encryption_chunk_size'], $end - ftell($fp)));
				if(false === $decrypted_chunk=openssl_decrypt($encrypted_chunk, 'aes-256-cbc', $parameters['passkey'], OPENSSL_RAW_DATA, $iv)){
					fclose($fp);
					error_display::cannot_display_content("Decryption failed", 403);
				}
				echo $decrypted_chunk;
				flush();
			}
		}
		else
		{
			header('Content-Length: ' .($block_pos ?($end - $start) : filesize($filepath.'.dt')));
			fpassthru($fp);
		}
		fclose($fp);
		exit();
	}

}	