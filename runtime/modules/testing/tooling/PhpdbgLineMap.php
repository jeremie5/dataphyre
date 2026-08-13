<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Detaches the only coverage evidence Dataphyre needs from phpdbg-owned maps. */
final class PhpdbgLineMap {
	private const MAX_FILENAME_BYTES=32768;

	/** @return array<string,array<int,true>> */
	public static function detach(mixed $raw): array {
		$map=[];
		foreach(is_array($raw) ? $raw : [] as $file=>$lines){
			if(
				!is_string($file)
				|| $file===''
				|| strlen($file)>self::MAX_FILENAME_BYTES
				|| !is_array($lines)
			){
				continue;
			}
			// Force a filename copy instead of retaining phpdbg's allocator-owned
			// hash key across the next debugger API call.
			$detached_file=substr($file."\0", 0, -1);
			$map[$detached_file]=[];
			foreach(array_keys($lines) as $line){
				$map[$detached_file][(int)$line]=true;
			}
		}
		return $map;
	}
}
