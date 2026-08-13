<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Process-local environment seam for exact portable fallback scenarios.
 * Every wrapper delegates to the real PHP runtime unless a test explicitly enables one failure.
 */
final class PanelRuntimeEnvironmentCoverageSeam {
	public static bool $enabled=false;
	public static bool $sodiumAvailable=true;
	public static bool $opensslAvailable=true;
	public static bool $failEncryption=false;
	public static bool $throwDuringDecryption=false;
	public static ?string $unresolvablePath=null;

	public static function reset(): void {
		self::$enabled=false;
		self::$sodiumAvailable=true;
		self::$opensslAvailable=true;
		self::$failEncryption=false;
		self::$throwDuringDecryption=false;
		self::$unresolvablePath=null;
	}
}

/** Portable virtual directory containing one non-regular leaf for iterator boundary tests. */
final class PanelVirtualDirectoryCoverageStream {
	/** Stream-wrapper context injected by PHP before directory callbacks. @var resource|null */
	public mixed $context=null;
	private int $position=0;
	/** @var list<string> */
	private array $entries=['.', '..', 'boundary.pipe'];

	public function dir_opendir(string $path, int $options): bool { $this->position=0; return true; }
	public function dir_readdir(): string|false { return $this->entries[$this->position++] ?? false; }
	public function dir_rewinddir(): bool { $this->position=0; return true; }
	public function dir_closedir(): bool { return true; }

	/** @return array<int|string,int>|false */
	public function url_stat(string $path, int $flags): array|false {
		$leaf=str_ends_with(str_replace('\\', '/', $path), '/boundary.pipe');
		$mode=$leaf ? 0010666 : 0040777;
		$values=[0, 0, $mode, 1, 0, 0, 0, 0, 0, 0, 0, -1, -1];
		foreach(['dev','ino','mode','nlink','uid','gid','rdev','size','atime','mtime','ctime','blksize','blocks'] as $index=>$name){ $values[$name]=$values[$index]; }
		return $values;
	}
}

function function_exists(string $function): bool {
	if(!PanelRuntimeEnvironmentCoverageSeam::$enabled){ return \function_exists($function); }
	if(in_array($function, ['sodium_crypto_secretbox', 'sodium_crypto_secretbox_open'], true)){ return PanelRuntimeEnvironmentCoverageSeam::$sodiumAvailable; }
	if(in_array($function, ['openssl_encrypt', 'openssl_decrypt'], true)){ return PanelRuntimeEnvironmentCoverageSeam::$opensslAvailable; }
	return \function_exists($function);
}

function openssl_encrypt(mixed $data, string $cipherAlgorithm, mixed $passphrase, int $options=0, string $iv='', mixed &$tag=null, string $additionalAuthenticatedData='', int $tagLength=16): string|false {
	if(PanelRuntimeEnvironmentCoverageSeam::$enabled && PanelRuntimeEnvironmentCoverageSeam::$failEncryption){ return false; }
	return \openssl_encrypt($data, $cipherAlgorithm, $passphrase, $options, $iv, $tag, $additionalAuthenticatedData, $tagLength);
}

function openssl_decrypt(mixed ...$arguments): string|false {
	if(PanelRuntimeEnvironmentCoverageSeam::$enabled && PanelRuntimeEnvironmentCoverageSeam::$throwDuringDecryption){ throw new \RuntimeException('Simulated OpenSSL provider failure.'); }
	return \openssl_decrypt(...$arguments);
}

function realpath(string $path): string|false {
	if(PanelRuntimeEnvironmentCoverageSeam::$enabled && PanelRuntimeEnvironmentCoverageSeam::$unresolvablePath!==null){
		$expected=str_replace('\\', '/', PanelRuntimeEnvironmentCoverageSeam::$unresolvablePath);
		if(str_replace('\\', '/', $path)===$expected){ return false; }
	}
	return \realpath($path);
}
