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

class image_processing{

	public static function modify_image(string $file_content, array $parameters) : string {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
		if(false===$previous_memory_limit=ini_set('memory_limit', '256M')){
			error_display::cannot_display_content("Failed temporary process memory limit override for OTF image transformations.", 500);
		}
		if(false===$image=imagecreatefromstring($file_content)){
			error_display::cannot_display_content("Invalid image data", 410);
		}
		if(isset($parameters['width']) || isset($parameters['height'])){
			$mode=$parameters['mode'] ?? 'fit';
			$width=$parameters['width'] ?? imagesx($image);
			$height=$parameters['height'] ?? imagesy($image);
			if($mode==='stretch'){
				$image = self::stretch_image($image, $width, $height);
			}
			elseif($mode==='reframe'){
				$image=self::reframe_image($image, $width, $height);
			}
			else
			{
				$image=imagescale($image, $width, $height);
			}
		}
		imageinterlace($image, 1);
		$quality=$parameters['quality'] ?? 75;
		$mime_type=$parameters['mime_type'] ?? 'image/jpeg';
		$jpeg_interlacing=$parameters['jpeg_interlacing'] ?? false;
		ob_clean();
		ob_start();
		switch($mime_type){
			case 'image/jpeg':
				if($jpeg_interlacing){
					imageinterlace($image, 1);
				}
				imagejpeg($image, null, $quality);
				break;
			case 'image/png':
				$quality = min(9, max(0, (int)($quality / 10))); // Ensure quality is within PNG range
				imagepng($image, null, $quality);
				break;
			case 'image/webp':
				imagewebp($image, null, $quality);
				break;
			default:
				imagedestroy($image);
				error_display::cannot_display_content("Invalid MIME type");
		}
		$contents=ob_get_contents();
		if(false===ob_end_clean()){
			error_display::cannot_display_content("Failed clearing output buffer.", 500);
		}
		if(false===ini_set('memory_limit', $previous_memory_limit)){
			error_display::cannot_display_content("Failed reverting temporary process memory limit override for OTF image transformations.", 500);
		}
		return $contents;
	}
	
    public static function stretch_image(object $image, int $new_width, int $new_height) : object {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
        $dst_image=imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($dst_image, $image, 0, 0, 0, 0, $new_width, $new_height, imagesx($image), imagesy($image));
        imagedestroy($image);
        return $dst_image;
    }

    public static function reframe_image(object $image, int $new_width, int $new_height) : object {
		tracelog(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $S=null, $T='function_call', $A=func_get_args()); // Log the function call
        $src_aspect=imagesx($image)/imagesy($image);
        $new_aspect=$new_width/$new_height;
        if($src_aspect>$new_aspect){
            $src_height=imagesy($image);
            $src_width=imagesy($image)*$new_aspect;
            $src_x=(imagesx($image)-$src_width)/2;
            $src_y=0;
        }
		else
		{
            $src_width=imagesx($image);
            $src_height=imagesx($image)/$new_aspect;
            $src_x=0;
            $src_y=(imagesy($image)-$src_height)/2;
        }
        $dst_image=imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($dst_image, $image, 0, 0, $src_x, $src_y, $new_width, $new_height, $src_width, $src_height);
        imagedestroy($image);
        return $dst_image;
    }

}	