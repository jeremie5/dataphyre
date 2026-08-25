<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Resolves the one cache/staging directory shared by Vestra writes and delivery. */
final class dataphyre_vestra_cache_directory {
	private const MANAGED_RELEASE_PATTERN='/^dep_[a-f0-9]{40}$/D';

	/** @param array<string,mixed> $runtime */
	public static function resolve(array $runtime=[]): string {
		$configured=trim((string)($runtime['cache_directory'] ?? ''));
		if($configured!==''){
			return self::withTrailingSeparator($configured);
		}
		if(self::usesManagedDefault($runtime)){
			$temporary=array_key_exists('system_temp_directory', $runtime)
				? trim((string)$runtime['system_temp_directory'])
				: trim(sys_get_temp_dir()); // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Immutable managed releases need one UID-writable staging root outside their read-only source tree."
			return $temporary==='' ? '' : self::withTrailingSeparator($temporary)
				.'dataphyre'.DIRECTORY_SEPARATOR.'vestra'.DIRECTORY_SEPARATOR;
		}
		$roots=defined('ROOTPATH') && is_array(ROOTPATH) ? ROOTPATH : [];
		$root=trim((string)($roots['common_dataphyre'] ?? ''));
		return $root==='' ? '' : self::withTrailingSeparator($root)
			.'cache'.DIRECTORY_SEPARATOR.'vestra'.DIRECTORY_SEPARATOR;
	}

	/** @param array<string,mixed> $runtime */
	public static function creationMode(array $runtime=[]): int {
		return self::usesManagedDefault($runtime) ? 0700 : 0775;
	}

	/** @param array<string,mixed> $runtime */
	private static function usesManagedDefault(array $runtime): bool {
		if(trim((string)($runtime['cache_directory'] ?? ''))!==''){
			return false;
		}
		$release=getenv('DATAPHYRE_APPLICATION_RELEASE');
		return is_string($release) && preg_match(self::MANAGED_RELEASE_PATTERN, $release)===1;
	}

	private static function withTrailingSeparator(string $directory): string {
		return rtrim($directory, '/\\').DIRECTORY_SEPARATOR;
	}
}
