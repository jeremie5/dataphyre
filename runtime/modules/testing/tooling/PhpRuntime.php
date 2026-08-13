<?php
declare(strict_types=1);

/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/**
 * Produces commands for ordinary child PHP processes, even when the parent
 * test runner itself is executing under phpdbg for coverage collection.
 */
final class PhpRuntime {

	public static function binary(?string $binary=null): string {
		$binary=trim($binary ?? PHP_BINARY);
		if($binary===''){
			throw new \InvalidArgumentException('A PHP runtime binary is required.');
		}
		if(!self::isDebugger($binary)){
			return $binary;
		}
		$extension=strtolower(pathinfo($binary, PATHINFO_EXTENSION))==='exe' ? '.exe' : '';
		$candidate=dirname($binary).DIRECTORY_SEPARATOR.'php'.$extension;
		$resolved=is_file($candidate) ? realpath($candidate) : false;
		return is_string($resolved) ? $resolved : $binary;
	}

	/**
	 * @param list<string> $arguments
	 * @param array<string,scalar|null> $ini
	 * @return list<string>
	 */
	public static function command(array $arguments=[], ?string $binary=null, array $ini=[]): array {
		return [self::binary($binary), ...self::iniArguments($ini), ...array_map('strval', $arguments)];
	}

	/** @param array<string,scalar|null> $settings @return list<string> */
	public static function iniArguments(array $settings): array {
		$arguments=[];
		foreach($settings as $name=>$value){
			if(!is_string($name) || preg_match('/^[A-Za-z0-9_.-]+$/', $name)!==1){
				throw new \InvalidArgumentException('PHP ini setting names may contain only letters, numbers, dots, underscores, and hyphens.');
			}
			if($value!==null && !is_scalar($value)){
				throw new \InvalidArgumentException('PHP ini setting values must be scalar or null.');
			}
			$normalized=is_bool($value) ? ($value ? '1' : '0') : (string)($value ?? '');
			$arguments[]='-d';
			$arguments[]=$name.'='.$normalized;
		}
		return $arguments;
	}

	public static function isDebugger(?string $binary=null): bool {
		$binary=$binary ?? PHP_BINARY;
		return str_contains(strtolower(basename($binary)), 'phpdbg');
	}
}
