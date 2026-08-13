<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/**
 * One cross-platform path vocabulary for the test orchestrator and workers.
 *
 * Paths are normalized to forward slashes for protocol payloads. Absolute-path
 * recognition deliberately understands POSIX roots, Windows drive roots, UNC
 * shares, and rooted backslash input regardless of the current host OS.
 */
final class PathSemantics {
	public static function normalize(string $path): string {
		return str_replace('\\', '/', trim($path));
	}

	public static function isAbsolute(string $path): bool {
		$path=self::normalize($path);
		return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path)===1;
	}

	/** Resolves a relative protocol path without rewriting native absolute paths. */
	public static function resolve(string $base, string $path): string {
		$path=self::normalize($path);
		if($path==='' || self::isAbsolute($path)){
			return $path;
		}
		return rtrim(self::normalize($base), '/').'/'.ltrim($path, '/');
	}
}
