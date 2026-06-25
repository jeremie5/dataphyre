<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Converts compiled MVC routes into a human-readable route manifest.
 *
 * RouteList is used by tooling and panel surfaces that need route metadata
 * without exposing raw handler callables or internal RouteDefinition objects.
 */
final class RouteList {

	/**
	 * Builds route list entries from a route collection.
	 *
	 * Compiled route data is preferred for dispatch-relevant fields while the
	 * original definitions provide labels for handlers, middleware, bindings,
	 * constraints, and defaults.
	 *
	 * @param RouteCollection $routes Route collection to compile and describe.
	 * @return array<int, array<string, mixed>> Route manifest entries for UI or CLI display.
	 */
	public static function from(RouteCollection $routes): array {
		$manifest=$routes->compile();
		$definitions=$routes->all();
		$list=[];
		foreach($manifest['routes'] ?? [] as $index=>$compiled){
			$definition=$definitions[$index] ?? null;
			$list[]=self::entry($compiled, $definition);
		}
		return $list;
	}

	/**
	 * Merges one compiled route with its source definition metadata.
	 *
	 * @param array<string, mixed> $compiled Compiled route entry from RouteCollection.
	 * @param ?RouteDefinition $definition Original route definition when available.
	 * @return array<string, mixed> Display-safe route list entry.
	 */
	private static function entry(array $compiled, ?RouteDefinition $definition): array {
		return [
			'methods'=>array_values((array)($compiled['methods'] ?? $definition?->methods() ?? [])),
			'domain'=>$compiled['domain'] ?? $definition?->domainValue(),
			'path'=>(string)($compiled['path'] ?? $definition?->path() ?? '/'),
			'name'=>$compiled['name'] ?? $definition?->nameValue(),
			'action'=>self::handlerLabel($compiled['handler'] ?? $definition?->handler()),
			'middleware'=>self::middlewareLabels($compiled['middleware'] ?? $definition?->middlewareDefinitions() ?? []),
			'without_middleware'=>self::middlewareLabels($definition?->excludedMiddlewareDefinitions() ?? []),
			'bindings'=>$definition?->modelBindings() ?? [],
			'constraints'=>$compiled['constraints'] ?? $definition?->constraints() ?? [],
			'defaults'=>$compiled['defaults'] ?? $definition?->defaultsValues() ?? [],
		];
	}

	/**
	 * Converts a route handler into a stable display label.
	 *
	 * @param mixed $handler Controller, include, callable, closure, object, or scalar route handler.
	 * @return string Label suitable for route lists without serializing callables.
	 */
	private static function handlerLabel(mixed $handler): string {
		if(is_string($handler)){
			return $handler;
		}
		if(is_array($handler)){
			if(($handler['type'] ?? null)==='controller' || isset($handler['class'], $handler['method'])){
				return trim((string)($handler['class'] ?? ''), '\\').'@'.(string)($handler['method'] ?? '__invoke');
			}
			if(($handler['type'] ?? null)==='include'){
				return 'include:'.(string)($handler['target'] ?? '');
			}
			if(($handler['type'] ?? null)==='callable'){
				return 'callable';
			}
			if(isset($handler[0], $handler[1])){
				$target=is_object($handler[0]) ? $handler[0]::class : (string)$handler[0];
				return trim($target, '\\').'@'.(string)$handler[1];
			}
			return 'array';
		}
		if($handler instanceof \Closure){
			return 'Closure';
		}
		if(is_object($handler)){
			return $handler::class;
		}
		if(is_callable($handler)){
			return 'callable';
		}
		return get_debug_type($handler);
	}

	/**
	 * Converts middleware definitions into route-list labels.
	 *
	 * @param array<int, mixed> $middleware Middleware aliases, classes, callables, or compiled definitions.
	 * @return array<int, string> Display labels preserving alias parameters where present.
	 */
	private static function middlewareLabels(array $middleware): array {
		$labels=[];
		foreach($middleware as $definition){
			if(is_string($definition)){
				$labels[]=$definition;
				continue;
			}
			if(is_array($definition)){
				if(isset($definition['alias'])){
					$parameters=(array)($definition['parameters'] ?? []);
					$labels[]=$parameters===[]
						? (string)$definition['alias']
						: (string)$definition['alias'].':'.implode(',', array_map('strval', $parameters));
					continue;
				}
				if(isset($definition['class'])){
					$labels[]=trim((string)$definition['class'], '\\');
					continue;
				}
				if(isset($definition['target']) && is_callable($definition['target'])){
					$labels[]='callable';
					continue;
				}
			}
			$labels[]=get_debug_type($definition);
		}
		return $labels;
	}
}
