<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Resolves MVC route parameters into typed Model instances for handlers.
 *
 * The binder combines application-level and route-level binding metadata,
 * inspects callable parameter types, and returns both rewritten route
 * parameters and unique typed values for dependency injection.
 */
final class RouteModelBinder {

	/**
	 * Resolves model-bound parameters for a callable.
	 *
	 * Route-specific bindings override application bindings. Missing route
	 * parameters are ignored, while failed lookups throw RouteModelNotFoundException.
	 *
	 * @param callable $callable Controller or route handler being invoked.
	 * @param MvcApplication $app MVC application supplying namespaces and global bindings.
	 * @param array<string, mixed> $routeParameters Raw route parameters from the dispatcher.
	 * @param array<string, mixed> $routeBindings Route-level binding overrides.
	 * @return array{parameters: array<string, mixed>, models: array<string, Model>, typed_values: array<class-string, Model>}
	 */
	public static function resolveForCallable(callable $callable, MvcApplication $app, array $routeParameters, array $routeBindings=[]): array {
		$bindings=self::normalizeBindings(array_replace(
			self::normalizeBindings((array)$app->config('model_bindings', [])),
			self::normalizeBindings($routeBindings)
		));
		$resolvedParameters=$routeParameters;
		$models=[];
		foreach(self::reflect($callable)->getParameters() as $parameter){
			$type=$parameter->getType();
			if(!$type instanceof \ReflectionNamedType || $type->isBuiltin()){
				continue;
			}
			$typeName=ltrim($type->getName(), '\\');
			if(!is_a($typeName, Model::class, true)){
				continue;
			}
			$name=$parameter->getName();
			$binding=$bindings[$name] ?? $bindings[$typeName] ?? [];
			$binding=is_array($binding) ? $binding : ['model'=>$binding];
			$modelClass=self::modelClass($binding['model'] ?? $typeName, $app);
			if(!is_a($modelClass, Model::class, true)){
				throw new \RuntimeException("MVC route model binding target '{$modelClass}' must extend ".Model::class.'.');
			}
			$routeParameter=(string)($binding['param'] ?? $binding['parameter'] ?? $name);
			if(!array_key_exists($routeParameter, $routeParameters)){
				continue;
			}
			$key=(string)($binding['key'] ?? self::routeKeyName($modelClass));
			$model=self::resolveModel($modelClass, $routeParameter, $routeParameters[$routeParameter], $key);
			$resolvedParameters[$routeParameter]=$model;
			if($routeParameter!==$name){
				$resolvedParameters[$name]=$model;
			}
			$models[$name]=$model;
		}
		return [
			'parameters'=>$resolvedParameters,
			'models'=>$models,
			'typed_values'=>self::typedValues($models),
		];
	}

	/**
	 * Resolves one route value into a model instance.
	 *
	 * @param class-string<Model> $modelClass Model class to resolve.
	 * @param string $parameter Route parameter name.
	 * @param mixed $value Route parameter value.
	 * @param string $key Model lookup key.
	 * @return Model Resolved model instance.
	 */
	private static function resolveModel(string $modelClass, string $parameter, mixed $value, string $key): Model {
		if($value instanceof $modelClass){
			return $value;
		}
		$result=method_exists($modelClass, 'resolveRouteBinding')
			? $modelClass::resolveRouteBinding($value, $key)
			: $modelClass::find($value, $key);
		if($result instanceof $modelClass){
			return $result;
		}
		if(is_array($result)){
			return new $modelClass($result);
		}
		throw new RouteModelNotFoundException($modelClass, $parameter, $value, $key);
	}

	/**
	 * Normalizes binding declarations by parameter name.
	 *
	 * @param array<string|int, mixed> $bindings Binding declarations from config or route metadata.
	 * @return array<string, array<string, mixed>> Binding arrays keyed by parameter or model class.
	 */
	private static function normalizeBindings(array $bindings): array {
		$normalized=[];
		foreach($bindings as $parameter=>$binding){
			if(is_int($parameter) && is_array($binding)){
				$parameter=$binding['param'] ?? $binding['parameter'] ?? $binding['name'] ?? null;
			}
			if(!is_string($parameter) || trim($parameter)===''){
				continue;
			}
			$normalized[trim($parameter, '\\')]=is_array($binding) ? $binding : ['model'=>$binding];
		}
		return $normalized;
	}

	/**
	 * Resolves a configured model name to a class name.
	 *
	 * @param mixed $model Model class or short model name.
	 * @param MvcApplication $app MVC application providing the model namespace.
	 * @return string Fully qualified or candidate model class name.
	 */
	private static function modelClass(mixed $model, MvcApplication $app): string {
		$model=is_string($model) ? trim($model, '\\') : '';
		if($model==='' || class_exists($model)){
			return $model;
		}
		$namespace=$app->modelNamespace();
		return $namespace!==null ? $namespace.'\\'.$model : $model;
	}

	/**
	 * Resolves the route lookup key for a model class.
	 *
	 * @param class-string<Model> $modelClass Model class being bound.
	 * @return string Route key name, defaulting to id.
	 */
	private static function routeKeyName(string $modelClass): string {
		if(method_exists($modelClass, 'routeKeyName')){
			$key=$modelClass::routeKeyName();
			if(is_string($key) && trim($key)!==''){
				return trim($key);
			}
		}
		return 'id';
	}

	/**
	 * Builds injectable typed values for uniquely resolved model classes.
	 *
	 * @param array<string, Model> $models Models keyed by parameter name.
	 * @return array<class-string, Model> Models keyed by class when exactly one instance of that class was resolved.
	 */
	private static function typedValues(array $models): array {
		$byClass=[];
		foreach($models as $model){
			$byClass[get_class($model)][]=$model;
		}
		$typed=[];
		foreach($byClass as $class=>$values){
			if(count($values)===1){
				$typed[$class]=$values[0];
			}
		}
		return $typed;
	}

	/**
	 * Creates reflection for a supported callable shape.
	 *
	 * @param callable $callable Route handler callable.
	 * @return \ReflectionFunctionAbstract Reflection used to inspect typed parameters.
	 */
	private static function reflect(callable $callable): \ReflectionFunctionAbstract {
		if(is_array($callable)){
			return new \ReflectionMethod($callable[0], (string)$callable[1]);
		}
		if(is_object($callable) && !$callable instanceof \Closure){
			return new \ReflectionMethod($callable, '__invoke');
		}
		return new \ReflectionFunction($callable);
	}
}
