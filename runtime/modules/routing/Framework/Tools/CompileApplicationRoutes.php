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

/**
 * Compiles an application's framework routes into its configured cache file.
 *
 * The tool locates the application, loads conventional or explicit application
 * metadata, compiles the configured routes file with RouteCompiler, and writes
 * the generated manifest to the application definition's compiled output path.
 */
final class CompileApplicationRoutes {

	/**
	 * Compiles routes for a named application and returns the output file path.
	 *
	 * @param string $projectRoot Project root containing application roots.
	 * @param string $applicationName Application id or name to locate.
	 * @return string Compiled route manifest file path.
	 *
	 * @throws \RuntimeException When the application, routes file, or output path is unavailable.
	 */
	public static function compile(string $projectRoot, string $applicationName): string {
		$projectRoot=rtrim($projectRoot, '/\\');
		$applicationDirectory=app_locator::locate($projectRoot, $applicationName);
		if($applicationDirectory===null){
			throw new \RuntimeException("Application {$applicationName} was not found in any configured application root.");
		}
		$definition=self::loadApplicationDefinition($applicationName, $applicationDirectory);
		if(empty($definition->routesFile) || !is_file($definition->routesFile)){
			throw new \RuntimeException("Application has no framework routes file: {$applicationName}");
		}
		if(empty($definition->compiledRoutesFile)){
			throw new \RuntimeException("Application has no compiled routes output path: {$applicationName}");
		}
		$manifest=RouteCompiler::compileFile($definition->routesFile, [
			'application'=>$definition->id,
			'compiled_at'=>gmdate('c'),
		]);
		RouteCompiler::writeManifestFile($definition->compiledRoutesFile, $manifest);
		return $definition->compiledRoutesFile;
	}

	/**
	 * Loads application metadata from conventions and optional app.php overrides.
	 *
	 * @param string $applicationName Application id or name.
	 * @param string $applicationDirectory Located application directory.
	 * @return application_definition Effective application definition.
	 *
	 * @throws \RuntimeException When app.php returns an unsupported value.
	 */
	private static function loadApplicationDefinition(string $applicationName, string $applicationDirectory): application_definition {
		$conventionalDefinition=application_definition::from_conventions($applicationName, $applicationDirectory);
		$definitionFile=$applicationDirectory.'/app.php';
		if(!is_file($definitionFile)){
			return $conventionalDefinition;
		}
		$definition=require($definitionFile);
		if($definition instanceof application_definition){
			return $definition;
		}
		if(is_array($definition)){
			return $conventionalDefinition->withOverrides($definition);
		}
		throw new \RuntimeException("Application definition must return an array or application_definition: {$definitionFile}");
	}
}
