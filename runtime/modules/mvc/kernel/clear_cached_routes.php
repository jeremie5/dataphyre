<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once(__DIR__.'/route_list.php');

if(PHP_SAPI!=='cli'){
	http_response_code(404);
	echo "MVC route cache clear is only available from CLI.\n";
	exit(2);
}

$module_root=dirname(__DIR__);
$runtime_modules=dirname($module_root);
dp_mvc_route_list_require_framework($runtime_modules);
$options=dp_mvc_route_list_options($argv);
if(isset($options['help'])){
	echo "Usage: php runtime/modules/mvc/kernel/clear_cached_routes.php [app] [--app=<name>] [--config=<path>]\n";
	exit(0);
}
$app_name=$options['app'] ?? 'default';
$config_file=$options['config'] ?? null;

try{
	$app=dp_mvc_route_list_app($app_name, $config_file, $runtime_modules);
	$cache_file=$app->manifestCacheFile();
	if($cache_file===null){
		throw new \RuntimeException('MVC manifest cache is not configured for app: '.$app->name());
	}
	if(is_file($cache_file)){
		if(!unlink($cache_file)){
			throw new \RuntimeException('Unable to delete MVC route cache: '.$cache_file);
		}
		fwrite(STDOUT, "MVC route cache cleared for {$app->name()} at {$cache_file}\n");
		exit(0);
	}
	fwrite(STDOUT, "MVC route cache already clear for {$app->name()} at {$cache_file}\n");
	exit(0);
}catch(\Throwable $throwable){
	fwrite(STDERR, $throwable->getMessage().PHP_EOL);
	exit(1);
}
