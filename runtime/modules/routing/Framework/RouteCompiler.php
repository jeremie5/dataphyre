<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

final class RouteCompiler {

	public static function compile_file(string $routes_file, array $metadata=[]): array {
		$routes=require($routes_file);
		if(!is_array($routes)){
			throw new \RuntimeException("Routes file must return an array: {$routes_file}");
		}
		$metadata['source_file']=$routes_file;
		return RouteManifest::compile($routes, $metadata);
	}

	public static function write_manifest_file(string $target_file, array $manifest): void {
		$directory=dirname($target_file);
		if(!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)){
			throw new \RuntimeException("Unable to create route manifest directory: {$directory}");
		}
		$payload="<?php\n\nreturn ".var_export($manifest, true).";\n";
		file_put_contents($target_file, $payload);
	}
}
