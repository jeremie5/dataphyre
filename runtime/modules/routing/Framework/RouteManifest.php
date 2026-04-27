<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

final class RouteManifest {

	public static function compile(array $routes, array $metadata=[]): array {
		$compiled_routes=[];
		foreach($routes as $route){
			if($route instanceof CompilableRoute){
				$compiled_routes[]=$route->compile();
				continue;
			}
			if(is_array($route)){
				$compiled_routes[]=$route;
				continue;
			}
			throw new \RuntimeException('Route manifest entries must be Route instances or compiled arrays.');
		}
		return [
			'version'=>1,
			'metadata'=>$metadata,
			'routes'=>$compiled_routes,
		];
	}
}
