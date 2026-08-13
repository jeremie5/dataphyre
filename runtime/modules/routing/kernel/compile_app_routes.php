<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

require_once dirname(__DIR__, 3).'/http.php';

/** @param array<int,string> $arguments @param array<string,mixed> $runtime */
function dp_route_compiler_entrypoint(array $arguments, ?bool $dispatch=null, array $runtime=[]): ?int {
	$dispatch ??= !defined('DATAPHYRE_ROUTE_COMPILER_NO_DISPATCH');
	if(!$dispatch){
		return null;
	}
	$status=dp_route_compiler_run($arguments, $runtime);
	$terminate=$runtime['terminate'] ?? 'dataphyre_process_terminate';
	if(!is_callable($terminate)){
		throw new LogicException('Route compiler terminator must be callable.');
	}
	$terminate($status);
	return $status;
}

/** @param array<int,string> $arguments @param array<string,mixed> $runtime */
function dp_route_compiler_run(array $arguments, array $runtime=[]): int {
	if(!in_array((string)($runtime['sapi'] ?? PHP_SAPI), ['cli','phpdbg'], true)){
		http_response_code(404);
		echo "Route compiler is only available from CLI.\n";
		return 2;
	}
	if(in_array('--help', $arguments, true) || in_array('-h', $arguments, true) || in_array('help', $arguments, true)){
		echo "Usage: php runtime/modules/routing/kernel/compile_app_routes.php <application>\n";
		echo "Set DATAPHYRE_PROJECT_ROOT when compiling routes from a Composer vendor install.\n";
		return 0;
	}
	$application=trim((string)($runtime['application'] ?? ($arguments[1] ?? '')));
	if($application===''){
		$error=$runtime['error'] ?? 'dataphyre_process_error';
		if(!is_callable($error)){
			throw new LogicException('Route compiler error writer must be callable.');
		}
		$error("Usage: php runtime/modules/routing/kernel/compile_app_routes.php <application>\nRun with --help for details.\n");
		return 1;
	}
	try{
		$runtimeRoot=rtrim((string)($runtime['runtime_root'] ?? dirname(__DIR__, 3)), '/\\');
		$packageRoot=rtrim((string)($runtime['package_root'] ?? dirname($runtimeRoot)), '/\\');
		$projectRoot=rtrim((string)($runtime['project_root'] ?? resolve_project_root($packageRoot)), '/\\');
		$bootstrap=$runtime['bootstrap'] ?? 'dp_route_compiler_bootstrap';
		$compile=$runtime['compile'] ?? [\Dataphyre\Routing\Tools\CompileApplicationRoutes::class, 'compile'];
		if(!is_callable($bootstrap) || !is_callable($compile)){
			throw new LogicException('Route compiler bootstrap and compiler boundaries must be callable.');
		}
		$bootstrap($runtimeRoot);
		$target=(string)$compile($projectRoot, $application);
		echo "Compiled routes written to {$target}\n";
		return 0;
	}
	catch(Throwable $exception){
		$error=$runtime['error'] ?? 'dataphyre_process_error';
		if(!is_callable($error)){
			throw new LogicException('Route compiler error writer must be callable.', 0, $exception);
		}
		$error('[ERROR] '.$exception->getMessage().PHP_EOL);
		return 2;
	}
}

/** Loads the minimum framework surface needed by the standalone compiler. */
function dp_route_compiler_bootstrap(string $runtimeRoot): void {
	require_once $runtimeRoot.'/bootstrap_config.php';
	$bootstrapState=\dataphyre\bootstrap_config::resolve($runtimeRoot);
	dataphyre_define_if_missing('DATAPHYRE_BOOTSTRAP_CONFIG', $bootstrapState['bootstrap']);
	dataphyre_define_if_missing('DATAPHYRE_MODULE_POLICY', $bootstrapState['modules']);
	require_once $runtimeRoot.'/modules/core/kernel/bootstrap.php';
	require_once $runtimeRoot.'/modules/core/kernel/core_functions.php';
	\dataphyre\autoloader::register($runtimeRoot.'/modules');
	\dataphyre\autoloader::register_framework_modules(['routing', 'api', 'sql', 'fulltext_engine']);
	\dataphyre\core::load_framework_modules(['routing', 'api', 'sql', 'fulltext_engine']);
}

dp_route_compiler_entrypoint($argv ?? []);

/**
 * Resolves the project root used by the route compiler CLI.
 *
 * The DATAPHYRE_PROJECT_ROOT environment variable wins when present. Otherwise
 * embedded project/dataphyre layouts resolve to the application project root,
 * and standalone package layouts use the package root itself. The former
 * dataphyre layout remains a resolution-only fallback.
 *
 * @param string $package_root Runtime package root discovered from this script.
 * @return string Normalized absolute or configured project root.
 */
function resolve_project_root(string $package_root, string|false|null $configuredRoot=null): string {
	$env=$configuredRoot ?? getenv('DATAPHYRE_PROJECT_ROOT');
	if(is_string($env) && trim($env)!==''){
		$resolved=realpath($env);
		return rtrim($resolved!==false ? $resolved : $env, '/\\');
	}
	$parent=dirname($package_root);
	if(basename($parent)==='common'){
		$embedded_root=dirname($parent);
		$resolved=realpath($embedded_root);
		return rtrim($resolved!==false ? $resolved : $embedded_root, '/\\');
	}
	if(
		strtolower(basename($package_root))==='dataphyre'
		&& (is_file($parent.'/flight_sheet.php') || is_file($parent.'/dataphyre.project.json') || is_dir($parent.'/applications'))
	){
		$resolved=realpath($parent);
		return rtrim($resolved!==false ? $resolved : $parent, '/\\');
	}
	$resolved=realpath($package_root);
	return rtrim($resolved!==false ? $resolved : $package_root, '/\\');
}
