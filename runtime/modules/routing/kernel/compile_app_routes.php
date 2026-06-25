<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

$runtime_root=dirname(__DIR__, 3);
$package_root=dirname($runtime_root);
$project_root=resolve_project_root($package_root);

require_once($runtime_root.'/modules/core/kernel/bootstrap.php');
require_once($runtime_root.'/modules/core/kernel/core_functions.php');
\dataphyre\autoloader::register($runtime_root.'/modules');
\dataphyre\core::load_framework_modules(['routing', 'api', 'sql', 'fulltext_engine']);

$application_name=$argv[1] ?? ($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'] ?? null);

if(empty($application_name)){
	fwrite(STDERR, "Usage: php runtime/modules/routing/kernel/compile_app_routes.php <application>\n");
	exit(1);
}

$target=\Dataphyre\Routing\Tools\CompileApplicationRoutes::compile($project_root, $application_name);

fwrite(STDOUT, "Compiled routes written to {$target}\n");

/**
 * Resolves the project root used by the route compiler CLI.
 *
 * The DATAPHYRE_PROJECT_ROOT environment variable wins when present. Otherwise
 * embedded common/dataphyre layouts resolve to the application project root, and
 * standalone package layouts use the package root itself.
 *
 * @param string $package_root Runtime package root discovered from this script.
 * @return string Normalized absolute or configured project root.
 */
function resolve_project_root(string $package_root): string {
	$env=getenv('DATAPHYRE_PROJECT_ROOT');
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
	$resolved=realpath($package_root);
	return rtrim($resolved!==false ? $resolved : $package_root, '/\\');
}
