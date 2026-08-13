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
 * Defines test-only PHP symbols through a real temporary file.
 *
 * Requiring parsed PHP is portable across standard CLI environments and keeps
 * test scaffolding out of synthetic eval filenames, coverage, and stack traces.
 */
final class PhpStub {

	public static function define(string $source): mixed {
		$source=self::normalize($source);
		if($source===''){
			throw new \InvalidArgumentException('Test stub PHP must not be empty.');
		}
		$php="<?php\ndeclare(strict_types=1);\n".$source."\n";
		try{
			token_get_all($php, TOKEN_PARSE);
		}catch(\ParseError $failure){
			throw new \InvalidArgumentException('Test stub PHP is invalid: '.$failure->getMessage(), 0, $failure);
		}
		$path=tempnam(sys_get_temp_dir(), 'dataphyre-test-stub-');
		if($path===false){
			throw new \RuntimeException('Unable to create a temporary PHP test stub.');
		}
		if(file_put_contents($path, $php)===false){
			@unlink($path);
			throw new \RuntimeException('Unable to write a temporary PHP test stub.');
		}
		try{
			return require $path;
		}finally { @unlink($path); }
	}

	public static function load(string $path): mixed {
		if(!is_file($path)){
			throw new \RuntimeException('Test stub file is unavailable: '.$path);
		}
		return require_once $path;
	}

	private static function normalize(string $source): string {
		$source=ltrim($source, "\xEF\xBB\xBF \t\r\n");
		if(str_starts_with($source, '<?php')){
			$source=substr($source, 5);
		}
		return trim($source);
	}
}
