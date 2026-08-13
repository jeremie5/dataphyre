<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once(__DIR__.'/route_list.php');

use Dataphyre\Routing\RouteCompiler;

/**
 * Runs the MVC route-cache command and returns its process status.
 *
 * @param array<int,string> $argv CLI argument vector.
 * @param bool|null $cli Optional SAPI override used by isolated command tests.
 * @param callable|null $writeOut Optional stdout writer.
 * @param callable|null $writeErr Optional stderr writer.
 * @return int Process exit status.
 */
function dp_mvc_cache_routes_main(array $argv, ?bool $cli=null, ?callable $writeOut=null, ?callable $writeErr=null): int {
	$cli ??= PHP_SAPI==='cli';
	$writeOut ??= static function(string $message): void { echo $message; };
	$writeErr ??= static function(string $message): void { fwrite(STDERR, $message); };
	if(!$cli){
		http_response_code(404);
		$writeOut("MVC route cache is only available from CLI.\n");
		return 2;
	}

	$module_root=dirname(__DIR__);
	$runtime_modules=dirname($module_root);
	dp_mvc_route_list_require_framework($runtime_modules);
	$options=dp_mvc_route_list_options($argv);
	if(isset($options['help'])){
		$writeOut("Usage: php runtime/modules/mvc/kernel/cache_routes.php [app] [--app=<name>] [--config=<path>]\n");
		return 0;
	}
	$app_name=$options['app'] ?? 'default';
	$config_file=$options['config'] ?? null;

	try{
		$app=dp_mvc_route_list_app($app_name, $config_file, $runtime_modules);
		$cache_file=$app->manifestCacheFile();
		if($cache_file===null){
			throw new \RuntimeException('MVC manifest cache is not configured for app: '.$app->name());
		}
		$manifest=$app->routes()->compile([
			'signature'=>RouteCompiler::manifestSignature([
				'app'=>$app->name(),
				'revision'=>$app->routes()->revision(),
				'sources'=>$app->routeSources(),
			]),
			'route_sources'=>$app->routeSources(),
		]);
		if(RouteCompiler::tryWriteManifestFile($cache_file, $manifest)!==true){
			throw new \RuntimeException('MVC route manifest is not exportable; remove closures or use manifest-safe handlers.');
		}
		$writeOut("MVC routes cached for {$app->name()} at {$cache_file}\n");
		return 0;
	}catch(\Throwable $throwable){
		$writeErr($throwable->getMessage().PHP_EOL);
		return 1;
	}
}

(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) && exit(dp_mvc_cache_routes_main($argv));
