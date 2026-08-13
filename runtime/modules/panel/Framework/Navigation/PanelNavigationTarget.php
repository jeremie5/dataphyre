<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Strict canonicalization for internal navigation targets. */
final class PanelNavigationTarget {
	public const MAX_BYTES=2048;

	public static function normalize(string $target): ?string {
		$target=trim($target);
		if($target==='' || strlen($target)>self::MAX_BYTES || preg_match('/[\x00-\x1F\x7F]/', $target)===1){ return null; }
		if(str_starts_with($target, '//') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/D', $target)===1){ return null; }
		if(str_starts_with($target, '?')){
			$base=parse_url(PanelConfig::url(), PHP_URL_PATH);
			$target=(is_string($base) && $base!=='' ? $base : '/').$target;
		}
		elseif(!str_starts_with($target, '/')){
			$target=rtrim(self::configuredMount(), '/').'/'.ltrim($target, '/');
		}
		$parts=parse_url($target);
		if(!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])){ return null; }
		$path=(string)($parts['path'] ?? '');
		if($path==='' || !str_starts_with($path, '/') || str_contains($path, '\\')){ return null; }
		$decoded=$path;
		for($pass=0;$pass<3;$pass++){
			$next=rawurldecode($decoded);
			if(str_contains($next, "\0") || str_contains($next, '\\')){ return null; }
			foreach(explode('/', $next) as $segment){
				if($segment==='.' || $segment==='..' || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:$/D', $segment)===1){ return null; }
			}
			if($next===$decoded){ break; }
			$decoded=$next;
		}
		$path=preg_replace('#/{2,}#', '/', $path) ?? $path;
		$query=[];
		if(isset($parts['query']) && $parts['query']!==''){
			if(strlen((string)$parts['query'])>1536){ return null; }
			parse_str((string)$parts['query'], $query);
			if(!is_array($query) || self::depth($query)>6){ return null; }
			unset($query['__panel_partial']);
			$query=self::canonicalArray($query);
		}
		$fragment=isset($parts['fragment']) ? trim((string)$parts['fragment']) : '';
		if(strlen($fragment)>128 || ($fragment!=='' && preg_match('/^[A-Za-z0-9._~:-]+$/D', $fragment)!==1)){ return null; }
		$normalized=$path.($query!==[] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '').($fragment!=='' ? '#'.$fragment : '');
		return strlen($normalized)<=self::MAX_BYTES ? $normalized : null;
	}

	public static function samePanel(string $target, ?string $mount=null): bool {
		$target=self::normalize($target);
		if($target===null){ return false; }
		$mount=$mount!==null ? self::normalizeMount($mount) : self::configuredMount();
		$path=(string)parse_url($target, PHP_URL_PATH);
		return $mount==='/' || $path===$mount || str_starts_with($path, rtrim($mount, '/').'/');
	}

	public static function configuredMount(): string {
		$configured=PanelConfig::config('navigation_intent_mount', PanelConfig::config('panel_mount_prefix', ''));
		if(is_string($configured) && trim($configured)!==''){ return self::normalizeMount($configured); }
		$path=parse_url(PanelConfig::url(), PHP_URL_PATH);
		return self::normalizeMount(is_string($path) ? $path : '/');
	}

	private static function normalizeMount(string $mount): string {
		$mount='/'.trim(str_replace('\\', '/', (string)(parse_url($mount, PHP_URL_PATH) ?: '/')), '/');
		return $mount==='/' ? '/' : rtrim($mount, '/');
	}

	/** @param array<mixed> $value */
	private static function depth(array $value, int $level=1): int {
		$max=$level;
		foreach($value as $item){ if(is_array($item)){ $max=max($max, self::depth($item, $level+1)); } }
		return $max;
	}

	/** @param array<mixed> $value @return array<mixed> */
	private static function canonicalArray(array $value): array {
		if(!array_is_list($value)){ ksort($value, SORT_STRING); }
		foreach($value as $key=>$item){ if(is_array($item)){ $value[$key]=self::canonicalArray($item); } }
		return $value;
	}
}
