<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Mvc\MvcApplication;

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	if(PHP_SAPI!=='cli'){
		http_response_code(404);
		echo "MVC route list is only available from CLI.\n";
		exit(2);
	}

	$module_root=dirname(__DIR__);
	$runtime_modules=dirname($module_root);
	dp_mvc_route_list_require_framework($runtime_modules);

	$options=dp_mvc_route_list_options($argv);
	$app_name=$options['app'] ?? 'default';
	$config_file=$options['config'] ?? null;
	$format=$options['format'] ?? 'table';

	try{
		$app=dp_mvc_route_list_app($app_name, $config_file, $runtime_modules);
		$routes=$app->routes()->list();
		if($format==='json'){
			echo json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
			exit(0);
		}
		echo dp_mvc_route_list_table($routes);
		exit(0);
	}catch(\Throwable $throwable){
		fwrite(STDERR, $throwable->getMessage().PHP_EOL);
		exit(1);
	}
}

/**
 * Loads the minimal MVC, HTTP, and Routing Framework files needed by the standalone route-list CLI.
 *
 * The command may run before Dataphyre's normal autoloader is active, so it requires framework
 * files explicitly in dependency order before constructing an `MvcApplication`.
 *
 * @param string $runtime_modules Runtime modules directory containing Framework source files.
 * @return void
 */
function dp_mvc_route_list_require_framework(string $runtime_modules): void {
	$files=[
		$runtime_modules.'/http/Framework/UploadedFile.php',
		$runtime_modules.'/http/Framework/Request.php',
		$runtime_modules.'/http/Framework/Response.php',
		$runtime_modules.'/routing/Framework/CompilableRoute.php',
		$runtime_modules.'/routing/Framework/ControllerAction.php',
		$runtime_modules.'/routing/Framework/Route.php',
		$runtime_modules.'/routing/Framework/RouteManifest.php',
		$runtime_modules.'/routing/Framework/RouteCompiler.php',
		$runtime_modules.'/routing/kernel/compiled_route_dispatcher.php',
		$runtime_modules.'/mvc/Framework/ResponseResult.php',
		$runtime_modules.'/mvc/Framework/ContainerException.php',
		$runtime_modules.'/mvc/Framework/Container.php',
		$runtime_modules.'/mvc/Framework/ServiceProviderContract.php',
		$runtime_modules.'/mvc/Framework/ServiceProvider.php',
		$runtime_modules.'/mvc/Framework/CallbackServiceProvider.php',
		$runtime_modules.'/mvc/Framework/ProviderRegistry.php',
		$runtime_modules.'/mvc/Framework/RedirectResult.php',
		$runtime_modules.'/mvc/Framework/ViewResult.php',
		$runtime_modules.'/mvc/Framework/HttpException.php',
		$runtime_modules.'/mvc/Framework/Validator.php',
		$runtime_modules.'/mvc/Framework/ValidationException.php',
		$runtime_modules.'/mvc/Framework/FormRequest.php',
		$runtime_modules.'/mvc/Framework/Model.php',
		$runtime_modules.'/mvc/Framework/Mvc.php',
		$runtime_modules.'/mvc/Framework/Controller.php',
		$runtime_modules.'/mvc/Framework/MvcRouteContext.php',
		$runtime_modules.'/mvc/Framework/RouteDefinition.php',
		$runtime_modules.'/mvc/Framework/RouteModelNotFoundException.php',
		$runtime_modules.'/mvc/Framework/RouteModelBinder.php',
		$runtime_modules.'/mvc/Framework/RouteList.php',
		$runtime_modules.'/mvc/Framework/SignedUrl.php',
		$runtime_modules.'/mvc/Framework/AccessMiddleware.php',
		$runtime_modules.'/mvc/Framework/GuestMiddleware.php',
		$runtime_modules.'/mvc/Framework/PermissionMiddleware.php',
		$runtime_modules.'/mvc/Framework/PermissionAnyMiddleware.php',
		$runtime_modules.'/mvc/Framework/SignedUrlMiddleware.php',
		$runtime_modules.'/mvc/Framework/ThrottleMiddleware.php',
		$runtime_modules.'/mvc/Framework/CacheMiddleware.php',
		$runtime_modules.'/mvc/Framework/Session.php',
		$runtime_modules.'/mvc/Framework/SessionMiddleware.php',
		$runtime_modules.'/mvc/Framework/CsrfMiddleware.php',
		$runtime_modules.'/mvc/Framework/RouteCollection.php',
		$runtime_modules.'/mvc/Framework/MvcManager.php',
		$runtime_modules.'/mvc/Framework/MvcApplication.php',
	];
	foreach($files as $file){
		require_once($file);
	}
}

/**
 * Parses CLI options for the MVC route-list command.
 *
 * Supported inputs are `--app=name`, `--config=file`, `--format=table|json`, `--json`, and a
 * positional app name. Unknown arguments are ignored so wrapper scripts can pass extra context
 * without breaking the command.
 *
 * @param array<int, string> $argv CLI argument vector.
 * @return array{app?:string, config?:string, format?:string} Parsed command options.
 */
function dp_mvc_route_list_options(array $argv): array {
	$options=[];
	foreach(array_slice($argv, 1) as $argument){
		if(str_starts_with($argument, '--app=')){
			$options['app']=substr($argument, 6);
			continue;
		}
		if(str_starts_with($argument, '--config=')){
			$options['config']=substr($argument, 9);
			continue;
		}
		if(str_starts_with($argument, '--format=')){
			$options['format']=strtolower(substr($argument, 9));
			continue;
		}
		if($argument==='--json'){
			$options['format']='json';
			continue;
		}
		if(!isset($options['app'])){
			$options['app']=$argument;
		}
	}
	return $options;
}

/**
 * Builds the MVC application whose routes should be listed.
 *
 * A config file path creates a standalone application from that array, with app-specific
 * overrides from `apps[name]` when present. Without a config file, the helper boots enough of
 * Dataphyre core to use the normal MVC application registry.
 *
 * @param string $name Application name.
 * @param ?string $config_file Optional standalone MVC config file returning an array.
 * @param string $runtime_modules Runtime modules directory used for fallback bootstrapping.
 * @return MvcApplication Application instance used to read registered routes.
 *
 * @throws \RuntimeException When standalone config is invalid or Dataphyre core cannot boot.
 */
function dp_mvc_route_list_app(string $name, ?string $config_file, string $runtime_modules): MvcApplication {
	if($config_file!==null && trim($config_file)!==''){
		$config=require($config_file);
		if(!is_array($config)){
			throw new \RuntimeException('MVC route list config file must return an array.');
		}
		$apps=$config['apps'] ?? [];
		if(is_array($apps) && isset($apps[$name]) && is_array($apps[$name])){
			$config=array_replace_recursive($config, $apps[$name]);
		}
		return new MvcApplication($name, $config);
	}
	$bootstrap=$runtime_modules.'/core/kernel/bootstrap.php';
	$core_functions=$runtime_modules.'/core/kernel/core_functions.php';
	if(is_file($bootstrap)){
		require_once($bootstrap);
	}
	if(is_file($core_functions)){
		require_once($core_functions);
	}
	if(class_exists('\dataphyre\autoloader', false)){
		\dataphyre\autoloader::register($runtime_modules);
	}
	if(class_exists('\dataphyre\core', false)){
		\dataphyre\core::load_framework_modules(['mvc']);
		return \Dataphyre\Mvc\Mvc::app($name);
	}
	throw new \RuntimeException('Unable to boot MVC route list. Pass --config=/path/to/mvc.php for standalone usage.');
}

/**
 * Formats MVC routes as a fixed-width CLI table.
 *
 * @param array<int, array<string, mixed>> $routes Route rows returned by `RouteList::list()`.
 * @return string Table text with method, path, name, action, and middleware columns.
 */
function dp_mvc_route_list_table(array $routes): string {
	$headers=['Method', 'Path', 'Name', 'Action', 'Middleware'];
	$rows=[];
	foreach($routes as $route){
		$rows[]=[
			implode('|', (array)($route['methods'] ?? [])),
			(string)($route['path'] ?? ''),
			(string)($route['name'] ?? ''),
			(string)($route['action'] ?? ''),
			implode(',', (array)($route['middleware'] ?? [])),
		];
	}
	$widths=array_map('strlen', $headers);
	foreach($rows as $row){
		foreach($row as $index=>$cell){
			$widths[$index]=max($widths[$index], strlen($cell));
		}
	}
	$line=static fn(array $cells): string => implode('  ', array_map(
		static fn(string $cell, int $index): string => str_pad($cell, $widths[$index]),
		$cells,
		array_keys($cells)
	)).PHP_EOL;
	$output=$line($headers);
	$output.=$line(array_map(static fn(int $width): string => str_repeat('-', $width), $widths));
	foreach($rows as $row){
		$output.=$line($row);
	}
	return $output;
}
