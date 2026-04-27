<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing\Tools;

use Dataphyre\Routing\RouteCompiler;
use dataphyre\application_definition;
use dataphyre\app_locator;

final class CompileApplicationRoutes {

	public static function compile(string $project_root, string $application_name): string {
		$project_root=rtrim($project_root, '/\\');
		$application_directory=app_locator::locate($project_root, $application_name);
		if($application_directory===null){
			throw new \RuntimeException("Application {$application_name} was not found in any configured application root.");
		}
		$definition=self::load_application_definition($application_name, $application_directory);
		if(empty($definition->routes_file) || !is_file($definition->routes_file)){
			throw new \RuntimeException("Application has no framework routes file: {$application_name}");
		}
		if(empty($definition->compiled_routes_file)){
			throw new \RuntimeException("Application has no compiled routes output path: {$application_name}");
		}
		$manifest=RouteCompiler::compile_file($definition->routes_file, [
			'application'=>$definition->id,
			'compiled_at'=>gmdate('c'),
		]);
		RouteCompiler::write_manifest_file($definition->compiled_routes_file, $manifest);
		return $definition->compiled_routes_file;
	}

	private static function load_application_definition(string $application_name, string $application_directory): application_definition {
		$conventional_definition=application_definition::from_conventions($application_name, $application_directory);
		$definition_file=$application_directory.'/app.php';
		if(!is_file($definition_file)){
			return $conventional_definition;
		}
		$definition=require($definition_file);
		if($definition instanceof application_definition){
			return $definition;
		}
		if(is_array($definition)){
			return $conventional_definition->with_overrides($definition);
		}
		throw new \RuntimeException("Application definition must return an array or application_definition: {$definition_file}");
	}
}
