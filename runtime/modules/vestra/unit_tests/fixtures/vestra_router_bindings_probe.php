<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class routing {
		public static array $bindings=['filename'=>'bound.css'];
	}
}

namespace {
	define('DATAPHYRE_VESTRA_LOADER_NO_DISPATCH', true);
	require_once dirname(__DIR__, 2).'/kernel/loader.php';

	$emitted=null;
	$response=\dataphyre_vestra_cache_endpoint::bootstrap(true, [
		'cache_directory'=>'/virtual-cache',
		'server'=>['REQUEST_METHOD'=>'GET'],
		'exists'=>static fn(string $path): bool=>$path==='/virtual-cache/bound.css',
		'read'=>static fn(string $path): string=>'body{color:green}',
		'mtime'=>static fn(string $path): int=>1700000000,
		'emit'=>static function(array $response) use (&$emitted): void {
			$emitted=$response;
		},
	]);

	echo json_encode([
		'status'=>$response['status'] ?? null,
		'body'=>$response['body'] ?? null,
		'content_type'=>$response['headers']['Content-Type'] ?? null,
		'emitted'=>$emitted===$response,
	], JSON_THROW_ON_ERROR);
}
