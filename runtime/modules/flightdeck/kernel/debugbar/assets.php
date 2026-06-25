<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_DEBUGBAR_ASSETS_TRAIT_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_DEBUGBAR_ASSETS_TRAIT_LOADED', true);

/**
 * Defines Flightdeck kernel trait responsibilities for dataphyre flightdeck debugbar assets.
 *
 * Flightdeck kernel boundary: requests, route dispatch, controller execution, operator UI, generated assets, or response rendering.
 */
trait dataphyre_flightdeck_debugbar_assets {

	/**
	 * Extracts externally referenced assets from an HTML response buffer.
	 *
	 * the scanner records stylesheets, scripts, images, and source tags
	 * without executing or fetching remote resources. Each URL is decoded, probed
	 * against safe local candidates, classified for common deployment issues, and
	 * capped to keep Debugbar snapshots bounded.
	 *
	 * @param string $buffer Rendered HTML response body.
	 * @return array<int, array<string, int|string>> Asset diagnostics keyed by kind, URL, status, path, size, and mime metadata.
	 */
	private static function response_assets(string $buffer): array {
		$assets=[];
		$patterns=[
			'stylesheet'=>'/<link\b(?=[^>]*\brel=["\']?stylesheet\b)[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i',
			'script'=>'/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
			'image'=>'/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
			'source'=>'/<source\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i',
		];
		foreach($patterns as $kind=>$pattern){
			if(preg_match_all($pattern, $buffer, $matches)===false){
				continue;
			}
			foreach($matches[1] ?? [] as $url){
				$url=html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if($url===''){
					continue;
				}
				$probe=self::asset_probe($url);
				$assets[]=[
					'kind'=>$kind,
					'url'=>$url,
					'issue'=>self::asset_issue($url, $probe),
					'status'=>(string)($probe['status'] ?? ''),
					'path'=>(string)($probe['path'] ?? ''),
					'local_path'=>(string)($probe['local_path'] ?? ''),
					'size_bytes'=>(int)($probe['size_bytes'] ?? 0),
					'mime'=>(string)($probe['mime'] ?? ''),
					'expected_mime'=>(string)($probe['expected_mime'] ?? ''),
					'candidate_count'=>(int)($probe['candidate_count'] ?? 0),
				];
				if(count($assets)>=180){
					break 2;
				}
			}
		}
		return $assets;
	}

	/**
	 * Probes one asset URL for local availability and metadata.
	 *
	 * embedded schemes are treated as non-file assets, remote hosts are
	 * reported without network access, and same-host or relative paths are resolved
	 * only after rejecting empty paths and parent-directory traversal. Successful
	 * local matches include size and best-effort MIME type evidence.
	 *
	 * @param string $url Asset URL from a response buffer.
	 * @return array{status:string,path:string,local_path:string,size_bytes:int,mime:string,expected_mime:string,candidate_count:int} Probe result.
	 */
	private static function asset_probe(string $url): array {
		$url=trim($url);
		$probe=[
			'status'=>'unknown',
			'path'=>'',
			'local_path'=>'',
			'size_bytes'=>0,
			'mime'=>'',
			'expected_mime'=>'',
			'candidate_count'=>0,
		];
		if($url===''){
			$probe['status']='empty';
			return $probe;
		}
		if(preg_match('#^(?:data|blob|mailto|javascript):#i', $url)===1){
			$probe['status']='embedded';
			return $probe;
		}
		$parts=parse_url($url);
		if(!is_array($parts)){
			$probe['status']='unparseable';
			return $probe;
		}
		$path=(string)($parts['path'] ?? '');
		$probe['path']=$path;
		$probe['expected_mime']=self::expected_asset_mime($path);
		$host=strtolower((string)($parts['host'] ?? ''));
		$request_host=strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
		$request_host=strtok($request_host, ':') ?: $request_host;
		if($host!=='' && $request_host!=='' && $host!==$request_host){
			$probe['status']='remote';
			return $probe;
		}
		if($path===''){
			$probe['status']='empty_path';
			return $probe;
		}
		$relative=ltrim(rawurldecode($path), '/\\');
		if($relative==='' || self::path_has_parent_segment($relative)===true){
			$probe['status']='unsafe_path';
			return $probe;
		}
		$candidates=self::asset_candidate_paths($relative);
		$probe['candidate_count']=count($candidates);
		foreach($candidates as $candidate){
			if(is_file($candidate)){
				$probe['status']='found';
				$probe['local_path']=str_replace('\\', '/', $candidate);
				$probe['size_bytes']=(int)@filesize($candidate);
				$mime=function_exists('mime_content_type') ? @mime_content_type($candidate) : false;
				$probe['mime']=is_string($mime) ? $mime : '';
				return $probe;
			}
		}
		$probe['status']='missing';
		return $probe;
	}

	/**
	 * Classifies the most actionable issue for an asset URL/probe pair.
	 *
	 * issue detection covers malformed or risky URLs, loopback host leaks,
	 * mixed-content HTTP assets on HTTPS requests, duplicated slashes, unsafe local
	 * paths, and missing local files. A blank string means no known issue was found.
	 *
	 * @param string $url Asset URL from a response buffer.
	 * @param array<string, mixed> $probe Probe metadata returned by asset_probe().
	 * @return string Stable issue code, or an empty string when the asset looks healthy.
	 */
	private static function asset_issue(string $url, array $probe=[]): string {
		$url=trim($url);
		if($url===''){
			return 'empty_url';
		}
		if(preg_match('/\s/', $url)===1){
			return 'whitespace_in_url';
		}
		$parts=parse_url($url);
		if(is_array($parts)){
			$path=(string)($parts['path'] ?? '');
			$host=strtolower((string)($parts['host'] ?? ''));
			$request_host=strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
			$request_host=strtok($request_host, ':') ?: $request_host;
			if(in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0'], true) && !in_array($request_host, ['127.0.0.1', 'localhost', '0.0.0.0'], true)){
				return 'loopback_host';
			}
			$scheme=strtolower((string)($parts['scheme'] ?? ''));
			$request_https=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')==='https');
			if($request_https===true && $scheme==='http'){
				return 'insecure_on_https';
			}
			if($path!=='' && str_contains($path, '//')){
				return str_contains($path, '/assets//') ? 'double_slash_assets' : 'double_slash_path';
			}
		}
		if(str_contains($url, '/assets//')){
			return 'double_slash_assets';
		}
		$status=(string)($probe['status'] ?? '');
		if($status==='unsafe_path'){
			return 'unsafe_asset_path';
		}
		if($status==='missing'){
			return 'local_file_not_found';
		}
		return '';
	}

	/**
	 * Builds bounded local filesystem candidates for a relative asset path.
	 *
	 * candidates are generated from explicit Debugbar asset roots,
	 * document/project roots, selected ROOTPATH entries, and application roots.
	 * The method does not check traversal safety itself; callers must reject parent
	 * segments before asking for candidates.
	 *
	 * @param string $relative Slash-normalized relative asset path.
	 * @return array<int, string> Unique candidate file paths, capped for snapshot size.
	 */
	private static function asset_candidate_paths(string $relative): array {
		$relative=str_replace('\\', '/', ltrim($relative, '/'));
		$roots=[];
		$config=defined('DATAPHYRE_FLIGHTDECK_CONFIG') && is_array(DATAPHYRE_FLIGHTDECK_CONFIG) ? DATAPHYRE_FLIGHTDECK_CONFIG : [];
		$debugbar=is_array($config['debugbar'] ?? null) ? $config['debugbar'] : [];
		if(is_array($debugbar['asset_roots'] ?? null)){
			foreach($debugbar['asset_roots'] as $root){
				if(is_string($root) && trim($root)!==''){
					$roots[]=$root;
				}
			}
		}
		if(!empty($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])){
			$roots[]=$_SERVER['DOCUMENT_ROOT'];
		}
		if(defined('DATAPHYRE_PROJECT_ROOT')){
			$roots[]=DATAPHYRE_PROJECT_ROOT;
		}
		if(defined('ROOTPATH') && is_array(ROOTPATH)){
			foreach(['root', 'common_root', 'themes', 'common_themes', 'views', 'backend', 'dataphyre', 'common_dataphyre'] as $key){
				if(!empty(ROOTPATH[$key]) && is_string(ROOTPATH[$key])){
					$roots[]=ROOTPATH[$key];
				}
			}
		}
		if(defined('DATAPHYRE_APPLICATION_ROOTS') && is_array(DATAPHYRE_APPLICATION_ROOTS) && defined('APP')){
			foreach(DATAPHYRE_APPLICATION_ROOTS as $application_root){
				if(is_string($application_root) && trim($application_root)!==''){
					$roots[]=rtrim($application_root, '/\\').'/'.APP;
				}
			}
		}
		$candidates=[];
		foreach(array_values(array_unique(array_map(static fn(string $root): string => rtrim($root, '/\\'), $roots))) as $root){
			if($root===''){
				continue;
			}
			foreach(self::asset_relative_variants($relative) as $variant){
				$candidate=str_replace('\\', '/', $root.'/'.$variant);
				if(!in_array($candidate, $candidates, true)){
					$candidates[]=$candidate;
				}
			}
			if(count($candidates)>=80){
				break;
			}
		}
		return $candidates;
	}

	/**
	 * Returns equivalent relative path variants for asset lookup.
	 *
	 * variants include the original path, a collapsed-slash path, and
	 * versions without a leading `assets/` segment so applications that mount
	 * public assets at different roots can still be diagnosed.
	 *
	 * @param string $relative Relative asset path.
	 * @return array<int, string> Unique relative path variants.
	 */
	private static function asset_relative_variants(string $relative): array {
		$variants=[$relative];
		$collapsed=preg_replace('#/+#', '/', $relative) ?? $relative;
		if($collapsed!==$relative){
			$variants[]=$collapsed;
		}
		if(str_starts_with($relative, 'assets/')){
			$without_assets=substr($relative, 7);
			if($without_assets!=='' && !in_array($without_assets, $variants, true)){
				$variants[]=$without_assets;
			}
		}
		if(str_starts_with($collapsed, 'assets/')){
			$without_assets=substr($collapsed, 7);
			if($without_assets!=='' && !in_array($without_assets, $variants, true)){
				$variants[]=$without_assets;
			}
		}
		return $variants;
	}

	/**
	 * Infers the expected MIME type from an asset path extension.
	 *
	 * @param string $path URL path or local path.
	 * @return string Expected MIME type, or an empty string for unknown extensions.
	 */
	private static function expected_asset_mime(string $path): string {
		$extension=strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
		return match($extension){
			'css'=>'text/css',
			'js', 'mjs'=>'application/javascript',
			'json', 'map'=>'application/json',
			'png'=>'image/png',
			'jpg', 'jpeg'=>'image/jpeg',
			'gif'=>'image/gif',
			'svg'=>'image/svg+xml',
			'webp'=>'image/webp',
			'avif'=>'image/avif',
			'ico'=>'image/x-icon',
			'woff'=>'font/woff',
			'woff2'=>'font/woff2',
			'ttf'=>'font/ttf',
			'otf'=>'font/otf',
			'mp3'=>'audio/mpeg',
			'mp4'=>'video/mp4',
			default=>'',
		};
	}

	/**
	 * Checks whether a path contains parent-directory traversal.
	 *
	 * path segments are split on both slash styles so URL-decoded Windows
	 * and POSIX separators are rejected consistently before local probing.
	 *
	 * @param string $path Relative path to inspect.
	 * @return bool True when any segment is `..`.
	 */
	private static function path_has_parent_segment(string $path): bool {
		$segments=preg_split('#[\\\\/]+#', $path) ?: [];
		foreach($segments as $segment){
			if($segment==='..'){
				return true;
			}
		}
		return false;
	}

	/**
	 * Finds duplicate HTML id attributes in a response buffer.
	 *
	 * IDs are counted exactly as rendered after trimming. The returned map
	 * is sorted with the most repeated IDs first so Debugbar can surface the highest
	 * impact markup collisions.
	 *
	 * @param string $buffer Rendered HTML response body.
	 * @return array<string, int> Duplicate id values keyed by id.
	 */
	private static function duplicate_html_ids(string $buffer): array {
		$counts=[];
		if(preg_match_all('/\bid=(["\'])(.*?)\1/i', $buffer, $matches)===false){
			return [];
		}
		foreach($matches[2] ?? [] as $id){
			$id=trim((string)$id);
			if($id===''){
				continue;
			}
			$counts[$id]=($counts[$id] ?? 0) + 1;
		}
		$duplicates=array_filter($counts, static fn(int $count): bool => $count>1);
		arsort($duplicates);
		return $duplicates;
	}

	/**
	 * Counts common mojibake markers in a response buffer.
	 *
	 * the UTF-8 regex path catches replacement characters and common
	 * double-encoding lead bytes; a byte-safe fallback counts decoded marker
	 * characters when the buffer is not valid UTF-8.
	 *
	 * @param string $buffer Rendered response body.
	 * @return int Number of detected mojibake markers.
	 */
	private static function mojibake_count(string $buffer): int {
		$count=@preg_match_all('/(?:\x{00c3}.|\x{00c2}.|\x{00e2}.|\x{fffd})/u', $buffer);
		if(is_int($count)){
			return $count;
		}
		$markers=[
			html_entity_decode('&#195;', ENT_NOQUOTES, 'UTF-8'),
			html_entity_decode('&#194;', ENT_NOQUOTES, 'UTF-8'),
			html_entity_decode('&#226;', ENT_NOQUOTES, 'UTF-8'),
			html_entity_decode('&#65533;', ENT_NOQUOTES, 'UTF-8'),
		];
		$total=0;
		foreach($markers as $marker){
			$total+=substr_count($buffer, $marker);
		}
		return $total;
	}

}
