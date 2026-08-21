<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Mvc\MvcApplication;

$runtimeModules=rtrim((string)($argv[1] ?? ''), '/\\');
$manifest=(string)($argv[2] ?? '');
if($runtimeModules==='' || !is_dir($runtimeModules) || $manifest==='' || file_exists($manifest)){
	fwrite(STDERR, "Managed MVC cache boundary arguments are invalid.\n");
	exit(2);
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
function dp_source_local_runtime_writes_allowed(): bool {
	return false;
}

require_once $runtimeModules.'/mvc/kernel/route_list.php';
dp_mvc_route_list_require_framework($runtimeModules);
require_once $runtimeModules.'/mvc/Framework/MvcDispatcher.php';

final class MvcManifestCacheBoundaryController {
	public static function show(): string {
		return 'compiled-in-memory';
	}
}

$app=new MvcApplication('managed-cache-boundary', ['manifest_cache'=>$manifest]);
$app->routes()->get('/managed-cache', [
	'class'=>MvcManifestCacheBoundaryController::class,
	'method'=>'show',
	'static'=>true,
]);
$response=$app->dispatcher()->dispatch(Request::create('GET', '/managed-cache'));
echo json_encode([
	'status'=>$response->status,
	'body'=>$response->body,
	'manifest_exists'=>file_exists($manifest) || is_link($manifest),
], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
