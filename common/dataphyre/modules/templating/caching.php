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

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait caching {
	
	private static function load_from_cache(string $template_file): ?string {
		if(self::$is_dev_mode) return null;
		$cache_file=self::$cache_dir.md5($template_file).'.php';
		return(file_exists($cache_file) && filemtime($cache_file) >= filemtime($template_file)) ? $cache_file : null;
	}
	
    private static function save_to_cache(string $template_content, string $template_file): string {
        if(!is_dir(self::$cache_dir)) mkdir(self::$cache_dir);
        $cache_file=self::$cache_dir.md5($template_file).'.php';
        file_put_contents($cache_file, $template_content);
        return $cache_file;
    }

    private static function conditional_cache(string $template, array $data, string $condition): string {
        $cache_key=md5($condition.serialize($data));
        $cached_content=self::get_from_cache($cache_key);
        if($cached_content) return $cached_content;
        self::store_in_cache($cache_key, $template);
        return $template;
    }
	
	private static function parse_fragment_cache(string $template): string {
		preg_match_all('/{{cache "(\w+)"(\d+)}}(.*?){{endcache}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$cache_key=$match[1];
			$duration =(int)$match[2];
			$content=self::get_from_cache($cache_key);
			if(!$content){
				$content=$match[3];
				self::store_in_cache($cache_key, $content, $duration);
			}
			$template=str_replace($match[0], $content, $template);
		}
		return $template;
	}
	
	private static function store_in_cache(string $cache_key, string $content, int $duration): void {
		$cache_file=self::$cache_dir.$cache_key.'.cache';
		file_put_contents($cache_file, $content);
	}
	
	private static function get_from_cache(string $cache_key): ?string {
		$cache_file=self::$cache_dir.$cache_key.'.cache';
		return(file_exists($cache_file) &&(time() - filemtime($cache_file)) < $duration) 
			? file_get_contents($cache_file) 
			: null;
	}
	
}