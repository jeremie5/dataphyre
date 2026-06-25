<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

/**
 * Defines Templating kernel trait responsibilities for caching.
 *
 * Templating kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait caching {

	/**
	 * Returns the directory used for template planning cache files.
	 *
	 * The path is derived from the configured template cache directory and always
	 * ends in a directory separator so plan cache callers can append hash-based file
	 * names without re-normalizing the base path.
	 *
	 * @return string Absolute or configured plan-cache directory path with trailing separator.
	 */
	private static function plan_cache_dir(): string {
		return rtrim(self::$cache_dir, '/\\').DIRECTORY_SEPARATOR.'plans'.DIRECTORY_SEPARATOR;
	}

	/**
	 * Loads a compiled template from the render cache when it is still valid.
	 *
	 * Development mode and missing source templates bypass the cache. Cache
	 * identity includes both the template file path and render cache signature,
	 * so changes to renderer policy do not reuse stale compiled output.
	 *
	 * @param string $template_file Source template file path.
	 *
	 * @return ?string Cached compiled template content, or `null` on miss/stale state.
	 */
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

	/**
	 * Writes compiled template content into the render cache.
	 *
	 * The target filename mirrors `load_from_cache()` by hashing the template
	 * path with the active render signature. The cache directory is created on
	 * demand because templating may bootstrap before runtime cache folders exist.
	 *
	 * @param string $template_content Compiled template content to persist.
	 * @param string $template_file Source template file path.
	 *
	 * @return string Absolute cache file path written for the template.
	 */
    private static function save_to_cache(string $template_content, string $template_file): string {
        if(!is_dir(self::$cache_dir)) @mkdir(self::$cache_dir, 0777, true);
        $cache_file=self::$cache_dir.md5($template_file."\0".self::render_cache_signature()).'.php';
        file_put_contents($cache_file, $template_content);
        return $cache_file;
    }

	/**
	 * Applies a short-lived cache around a rendered conditional fragment.
	 *
	 * The key combines the condition expression and encoded render data, so
	 * the same fragment text can be reused only for equivalent evaluation
	 * inputs. Misses are stored for the default five-minute fragment TTL.
	 *
	 * @param string $template Fragment output to cache on miss.
	 * @param array<string,mixed> $data Data scope participating in the cache key.
	 * @param string $condition Conditional expression or cache discriminator.
	 *
	 * @return string Cached fragment content or the supplied template content.
	 */
    private static function conditional_cache(string $template, array $data, string $condition): string {
        $cache_key=md5($condition.serialize($data));
        $cached_content=self::get_from_cache($cache_key);
        if($cached_content) return $cached_content;
        self::store_in_cache($cache_key, $template, 300);
        return $template;
    }

	/**
	 * Replaces `{{cache "key" ttl}}...{{endcache}}` blocks with cached content.
	 *
	 * Fragment cache blocks are literal template regions controlled by authors.
	 * A block key maps to a JSON fragment payload in the shared template cache;
	 * expired or missing entries store the current inner content for the block's
	 * declared TTL.
	 *
	 * @param string $template Template markup containing optional cache blocks.
	 *
	 * @return string Template markup with cache blocks replaced by fragment content.
	 */
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

	/**
	 * Stores a fragment cache payload with an absolute expiry timestamp.
	 *
	 * Fragment payloads are JSON so future readers can distinguish structured
	 * TTL-aware entries from legacy raw cache files. Durations are clamped to at
	 * least one second to avoid immediately expired writes.
	 *
	 * @param string $cache_key Filesystem-safe fragment cache key.
	 * @param string $content Fragment content to persist.
	 * @param int $duration Time to live in seconds.
	 *
	 * @return void
	 */
	private static function store_in_cache(string $cache_key, string $content, int $duration): void {
		$cache_file=self::$cache_dir.$cache_key.'.cache';
		if(!is_dir(self::$cache_dir)) @mkdir(self::$cache_dir, 0777, true);
		file_put_contents($cache_file, json_encode([
			'expires_at'=>time() + max(1, $duration),
			'content'=>$content,
		]));
	}

	/**
	 * Reads a fragment cache entry if it exists and has not expired.
	 *
	 * JSON payloads are checked against their `expires_at` timestamp and return
	 * only string content. Non-JSON payloads are treated as legacy raw cache
	 * content so older fragment files remain readable.
	 *
	 * @param string $cache_key Filesystem-safe fragment cache key.
	 *
	 * @return ?string Cached fragment content, or `null` on miss/expiry/corruption.
	 */
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

	/**
	 * Loads a serialized template execution plan from the plan cache.
	 *
	 * Plan cache entries are JSON wrappers around the normalized render plan and
	 * the source file modification time used when the plan was built. Supplying
	 * a source mtime makes stale plans fail closed instead of reusing outdated
	 * dependency graphs.
	 *
	 * @param string $cache_key Stable plan cache key.
	 * @param ?int $source_mtime Expected template source modification time.
	 *
	 * @return array<string,mixed>|null Cached render plan, or `null` when missing, stale, or invalid.
	 */
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

	/**
	 * Persists a normalized template execution plan as JSON.
	 *
	 * Plans are kept separate from compiled template output under the `plans`
	 * cache directory because they describe renderer decisions and dependency
	 * state rather than executable PHP/template content.
	 *
	 * @param string $cache_key Stable plan cache key.
	 * @param array<string,mixed> $plan Normalized render plan graph.
	 * @param ?int $source_mtime Template source modification time bound to the plan.
	 *
	 * @return void
	 */
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
