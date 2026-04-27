<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait caching {

	private static function plan_cache_dir(): string {
		return rtrim(self::$cache_dir, '/\\').DIRECTORY_SEPARATOR.'plans'.DIRECTORY_SEPARATOR;
	}
	
	private static function load_from_cache(string $template_file): ?string {
		self::ensure_initialized();
		if(self::$is_dev_mode || !is_file($template_file)) return null;
		$cache_file=self::$cache_dir.md5($template_file."\0".self::render_cache_signature()).'.php';
		if(!file_exists($cache_file) || filemtime($cache_file) < filemtime($template_file)){
			return null;
		}
		$cached=@file_get_contents($cache_file);
		return is_string($cached) ? $cached : null;
	}
	
    private static function save_to_cache(string $template_content, string $template_file): string {
        if(!is_dir(self::$cache_dir)) @mkdir(self::$cache_dir, 0777, true);
        $cache_file=self::$cache_dir.md5($template_file."\0".self::render_cache_signature()).'.php';
        file_put_contents($cache_file, $template_content);
        return $cache_file;
    }

    private static function conditional_cache(string $template, array $data, string $condition): string {
        $cache_key=md5($condition.serialize($data));
        $cached_content=self::get_from_cache($cache_key);
        if($cached_content) return $cached_content;
        self::store_in_cache($cache_key, $template, 300);
        return $template;
    }
	
	private static function parse_fragment_cache(string $template): string {
		preg_match_all('/{{cache\s+"([\w\-]+)"\s+(\d+)}}(.*?){{endcache}}/s', $template, $matches, PREG_SET_ORDER);
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
		if(!is_dir(self::$cache_dir)) @mkdir(self::$cache_dir, 0777, true);
		file_put_contents($cache_file, json_encode([
			'expires_at'=>time() + max(1, $duration),
			'content'=>$content,
		]));
	}
	
	private static function get_from_cache(string $cache_key): ?string {
		$cache_file=self::$cache_dir.$cache_key.'.cache';
		if(!file_exists($cache_file)){
			return null;
		}
		$payload=@file_get_contents($cache_file);
		if(!is_string($payload) || $payload===''){
			return null;
		}
		$decoded=json_decode($payload, true);
		if(!is_array($decoded)){
			return $payload;
		}
		if((int)($decoded['expires_at'] ?? 0) < time()){
			return null;
		}
		return is_string($decoded['content'] ?? null) ? $decoded['content'] : null;
	}

	private static function load_plan_from_cache(string $cache_key, ?int $source_mtime=null): ?array {
		$cache_file=self::plan_cache_dir().$cache_key.'.json';
		if(!is_file($cache_file)){
			return null;
		}
		$payload=@file_get_contents($cache_file);
		if(!is_string($payload) || $payload===''){
			return null;
		}
		$decoded=json_decode($payload, true);
		if(!is_array($decoded) || !is_array($decoded['plan'] ?? null)){
			return null;
		}
		if($source_mtime!==null && (int)($decoded['source_mtime'] ?? 0)!==$source_mtime){
			return null;
		}
		return $decoded['plan'];
	}

	private static function save_plan_to_cache(string $cache_key, array $plan, ?int $source_mtime=null): void {
		$cache_dir=self::plan_cache_dir();
		if(!is_dir($cache_dir)){
			@mkdir($cache_dir, 0777, true);
		}
		file_put_contents($cache_dir.$cache_key.'.json', json_encode([
			'source_mtime'=>$source_mtime,
			'plan'=>$plan,
		], JSON_PRETTY_PRINT));
	}
	
}
