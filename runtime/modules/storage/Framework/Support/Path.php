<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage\Support;

/**
 * Normalizes storage keys independently of the host filesystem.
 *
 * Storage paths are treated as slash-delimited object keys: backslashes are
 * converted, duplicate separators collapse, current-directory segments are
 * ignored, and parent traversal removes the previous segment without escaping
 * above the normalized root.
 */
final class Path {

	/**
	 * Converts an arbitrary path string into a safe relative storage key.
	 *
	 * Parent-directory segments pop the previous normalized segment and cannot
	 * escape above the returned key root. Absolute-path prefixes are discarded
	 * because storage drivers receive relative object keys.
	 *
	 * @param string $path User or adapter path to normalize.
	 * @return string Slash-delimited relative key with dot segments resolved.
	 */
	public static function normalize(string $path): string {
		$path=str_replace('\\', '/', trim($path));
		$path=preg_replace('#/+#', '/', $path) ?? $path;
		$segments=[];
		foreach(explode('/', $path) as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..'){
				array_pop($segments);
				continue;
			}
			$segments[]=$segment;
		}
		return implode('/', $segments);
	}

	/**
	 * Joins a trusted root prefix with a normalized relative path.
	 *
	 * The root is not normalized beyond slash conversion and trailing separator
	 * trimming; callers must pass a trusted adapter root or already-scoped prefix.
	 *
	 * @param string $root Storage adapter root or prefix.
	 * @param string $path Relative path to normalize before joining.
	 * @return string Root and normalized path separated by a single slash.
	 */
	public static function join(string $root, string $path): string {
		$root=rtrim(str_replace('\\', '/', $root), '/');
		return $root.'/'.self::normalize($path);
	}
}
