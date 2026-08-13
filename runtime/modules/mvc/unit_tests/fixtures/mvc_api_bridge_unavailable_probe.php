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
if($runtimeModules==='' || !is_dir($runtimeModules)){
	fwrite(STDERR, "Runtime modules directory is required.\n");
	exit(2);
}

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}

require_once $runtimeModules.'/mvc/kernel/route_list.php';
dp_mvc_route_list_require_framework($runtimeModules);
require_once $runtimeModules.'/mvc/Framework/MvcDispatcher.php';

$app=new MvcApplication('mvc-api-unavailable', [
	'middleware'=>[],
	'manifest_cache'=>false,
]);
$app->routes()->get('/secure', static fn(): array=>['source'=>'unsafe-fallback'])
	->api([
		'path'=>'/secure',
		'security'=>[['probeKey'=>[]]],
		'security_schemes'=>['probeKey'=>['type'=>'apiKey','name'=>'X-Probe','in'=>'header']],
	]);
$app->routes()->get('/execute', static fn(): array=>['source'=>'unsafe-fallback'])
	->api([
		'path'=>'/execute',
		'execution'=>['target'=>'Probe::execute'],
	]);
$app->routes()->get('/metadata', static fn(): array=>['source'=>'mvc'])
	->api(['path'=>'/metadata','summary'=>'Metadata only']);

$project=static function(string $path)use($app): array {
	$response=$app->dispatcher()->dispatch(Request::create('GET',$path));
	return [
		'status'=>$response->status,
		'cache_control'=>$response->headers['Cache-Control'] ?? null,
		'body'=>json_decode($response->body,true),
	];
};

echo json_encode([
	'secure'=>$project('/secure'),
	'execute'=>$project('/execute'),
	'metadata'=>$project('/metadata'),
], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
