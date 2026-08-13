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
 * A runner-provisioned disposable ROOTPATH entry for tests that exercise code
 * whose filesystem location is selected through the legacy ROOTPATH map.
 *
 * The marker protocol makes destructive setup fail closed: merely pointing a
 * ROOTPATH key at a directory is insufficient. The runner must explicitly own
 * that directory for this worker, and immutable project roots can never be
 * declared as sandboxes.
 */
final class RootpathSandbox {
	public const FORMAT='dataphyre-test-rootpath-sandbox-v1';
	public const MARKER='.dataphyre-test-rootpath-sandbox.json';

	/** @var list<string> */
	private const PROTECTED_KEYS=[
		'root',
		'common_root',
		'common_dataphyre',
		'common_dataphyre_runtime',
		'applications',
		'application_roots',
		'app',
		'app_override_key',
	];

	/** @param iterable<mixed> $keys @return list<string> */
	public static function normalizeDeclaredKeys(iterable $keys): array {
		$normalized=[];
		foreach($keys as $key){
			if(!is_string($key)){
				throw new \InvalidArgumentException('ROOTPATH sandbox keys must be strings.');
			}
			$key=trim($key);
			if(preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key)!==1){
				throw new \InvalidArgumentException('ROOTPATH sandbox keys must be non-blank PHP-style identifiers.');
			}
			if(in_array($key, self::PROTECTED_KEYS, true)){
				throw new \InvalidArgumentException("ROOTPATH['{$key}'] is an immutable project root and cannot be sandboxed.");
			}
			if(!in_array($key, $normalized, true)){
				$normalized[]=$key;
			}
		}
		return $normalized;
	}

	/** Returns the verified disposable root with one trailing slash. */
	public static function root(string $key): string {
		$key=self::normalizeDeclaredKeys([$key])[0];
		if(!\defined('ROOTPATH')){
			throw new \LogicException("ROOTPATH['{$key}'] sandbox is unavailable outside a Dataphyre test worker.");
		}
		$rootpath=\constant('ROOTPATH');
		if(!is_array($rootpath) || !is_string($rootpath[$key] ?? null) || trim((string)$rootpath[$key])===''){
			throw new \LogicException("The test worker did not provide ROOTPATH['{$key}'].");
		}
		$raw=self::normalizeAbsolute((string)$rootpath[$key]);
		if(is_link($raw)){
			throw new \LogicException("ROOTPATH['{$key}'] is not a safe disposable directory.");
		}
		$resolved=realpath($raw);
		if(!is_string($resolved) || !is_dir($resolved)){
			throw new \LogicException("ROOTPATH['{$key}'] sandbox does not exist.");
		}
		$root=self::normalizeAbsolute($resolved);
		if(self::isFilesystemRoot($root)){
			throw new \LogicException("ROOTPATH['{$key}'] resolved to a filesystem root.");
		}
		foreach(self::PROTECTED_KEYS as $protected_key){
			$protected=$rootpath[$protected_key] ?? null;
			if(!is_string($protected) || trim($protected)===''){
				continue;
			}
			$protected_resolved=realpath(rtrim($protected, '/\\'));
			if(is_string($protected_resolved) && self::containsPath(self::normalizeAbsolute($protected_resolved), $root)){
				throw new \LogicException("ROOTPATH['{$key}'] is inside immutable ROOTPATH['{$protected_key}'].");
			}
		}
		if(is_dir($root.'/.git')){
			throw new \LogicException("ROOTPATH['{$key}'] contains a Git repository and cannot be treated as disposable.");
		}
		$marker_path=$root.'/'.self::MARKER;
		$marker_source=is_file($marker_path) ? file_get_contents($marker_path) : false;
		if(!is_string($marker_source)){
			throw new \LogicException("ROOTPATH['{$key}'] is missing the runner ownership marker.");
		}
		try{
			$marker=json_decode($marker_source, true, 512, JSON_THROW_ON_ERROR);
		}catch(\JsonException $failure){
			throw new \LogicException("ROOTPATH['{$key}'] has an invalid runner ownership marker.", 0, $failure);
		}
		if(
			!is_array($marker)
			|| ($marker['format'] ?? null)!==self::FORMAT
			|| ($marker['rootpath_key'] ?? null)!==$key
			|| !is_string($marker['root'] ?? null)
			|| !self::samePath($root, self::normalizeAbsolute((string)$marker['root']))
			|| !is_string($marker['run_id'] ?? null)
			|| preg_match('/^dataphyre-unit-tests-[a-f0-9]{8}$/', (string)$marker['run_id'])!==1
			|| !is_string($marker['token'] ?? null)
			|| preg_match('/^[a-f0-9]{64}$/', (string)$marker['token'])!==1
		){
			throw new \LogicException("ROOTPATH['{$key}'] runner ownership marker does not match this sandbox.");
		}
		return $root.'/';
	}

	/** Resolves a traversal-safe path under the verified sandbox. */
	public static function path(string $key, string $relative=''): string {
		$root=rtrim(self::root($key), '/');
		$relative=str_replace('\\', '/', trim($relative));
		if($relative===''){
			return $root.'/';
		}
		if(str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative)===1 || str_contains($relative, "\0")){
			throw new \InvalidArgumentException('ROOTPATH sandbox paths must be relative.');
		}
		$segments=[];
		foreach(explode('/', $relative) as $segment){
			if($segment==='' || $segment==='.'){
				continue;
			}
			if($segment==='..'){
				if($segments===[]){
					throw new \InvalidArgumentException('ROOTPATH sandbox path cannot escape its root.');
				}
				array_pop($segments);
				continue;
			}
			$segments[]=$segment;
		}
		return $segments===[] ? $root.'/' : $root.'/'.implode('/', $segments);
	}

	/** Removes every sandbox child except the runner ownership marker. */
	public static function reset(string $key): string {
		$root=self::root($key);
		foreach(new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry){
			if($entry->getFilename()===self::MARKER){
				continue;
			}
			self::remove($entry->getPathname());
		}
		return $root;
	}

	private static function remove(string $path): void {
		if(is_link($path) || is_file($path)){
			if(!unlink($path) && file_exists($path)){
				throw new \RuntimeException('Unable to remove ROOTPATH sandbox file: '.$path);
			}
			return;
		}
		if(!is_dir($path)){
			return;
		}
		foreach(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry){
			self::remove($entry->getPathname());
		}
		if(!rmdir($path) && is_dir($path)){
			throw new \RuntimeException('Unable to remove ROOTPATH sandbox directory: '.$path);
		}
	}

	private static function normalizeAbsolute(string $path): string {
		$normalized=str_replace('\\', '/', trim($path));
		if($normalized==='/'){
			return '/';
		}
		return rtrim($normalized, '/');
	}

	private static function isFilesystemRoot(string $path): bool {
		return $path==='/' || preg_match('/^[A-Za-z]:$/', $path)===1 || preg_match('#^//[^/]+/[^/]+$#', $path)===1;
	}

	private static function containsPath(string $parent, string $candidate): bool {
		if(self::samePath($parent, $candidate)){
			return true;
		}
		$parent=self::comparisonPath($parent);
		$candidate=self::comparisonPath($candidate);
		return str_starts_with($candidate, rtrim($parent, '/').'/');
	}

	private static function samePath(string $left, string $right): bool {
		return self::comparisonPath($left)===self::comparisonPath($right);
	}

	private static function comparisonPath(string $path): string {
		$path=self::normalizeAbsolute($path);
		return preg_match('/^[A-Za-z]:/', $path)===1 ? strtolower($path) : $path;
	}
}
