<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Closure;

/**
 * Resolves a path beneath the shared Dataphyre package root.
 *
 * The returned path uses forward slashes on every platform. Absolute inputs,
 * null bytes, and traversal beyond the package root are rejected so test
 * fixtures cannot accidentally escape the repository they describe.
 */
function dataphyre_path(string $relative=''): string {
	$rootpath=defined('ROOTPATH') ? constant('ROOTPATH') : [];
	$configured_root=is_array($rootpath) ? trim((string)($rootpath['common_dataphyre'] ?? '')) : '';
	$root=str_replace('\\', '/', $configured_root!=='' ? $configured_root : dirname(__DIR__, 5));
	if($root!=='/' && preg_match('#^[A-Za-z]:/$#', $root)!==1){
		$root=rtrim($root, '/');
	}
	$relative=str_replace('\\', '/', trim($relative));
	if($relative===''){
		return $root;
	}
	if(str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative)===1){
		throw new \InvalidArgumentException('Dataphyre paths must be relative to the package root.');
	}
	$segments=[];
	foreach(explode('/', $relative) as $segment){
		if($segment==='' || $segment==='.'){
			continue;
		}
		if($segment==='..'){
			if($segments===[]){
				throw new \InvalidArgumentException('Dataphyre path traversal cannot escape the package root.');
			}
			array_pop($segments);
			continue;
		}
		$segments[]=$segment;
	}
	return $segments===[] ? $root : rtrim($root, '/').'/'.implode('/', $segments);
}

function define_test_symbols(string $php): mixed {
	return PhpStub::define($php);
}

function load_test_stub(string $path): mixed {
	return PhpStub::load($path);
}

function test(string $name, callable $body): CaseDefinition {
	return Registry::test($name, $body);
}

function suite(string $name=''): SuiteDefinition {
	return Registry::suite($name);
}

/** @param array<int|string,string|bool>|string $modules */
function framework(array|string $modules=[], array $options=[]): Framework {
	return Framework::boot($modules, $options);
}

function todo(string $name, string $reason=''): CaseDefinition {
	return Registry::test($name, static function(Context $t)use($reason): void {
		$t->todo($reason);
	})->todo($reason);
}

function dataset(string $name, iterable|Closure $rows): void {
	Registry::dataset($name, $rows);
}

function fixture(string $name, callable $setup, ?callable $teardown=null): void {
	Registry::fixture($name, $setup, $teardown);
}

function before_all(callable $callback): void {
	Registry::beforeAll($callback);
}

function after_all(callable $callback): void {
	Registry::afterAll($callback);
}

function before_each(callable $callback): void {
	Registry::beforeEach($callback);
}

function after_each(callable $callback): void {
	Registry::afterEach($callback);
}
