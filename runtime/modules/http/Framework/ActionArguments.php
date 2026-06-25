<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Http;

/**
 * Resolves callable arguments for HTTP route actions.
 *
 * The resolver combines typed framework values, named route parameters,
 * positional route parameters, default values, and nullable fallbacks into the
 * ordered argument list required by a controller, closure, or invokable object.
 * It performs reflection only and does not coerce scalar route values, authorize
 * the action, or instantiate arbitrary services.
 */
final class ActionArguments {

	/**
	 * Builds the argument list for a route action callable.
	 *
	 * Request-typed parameters receive the current Request, exact or compatible
	 * typed values are injected next, then route parameters are matched by name
	 * and by remaining position. Required parameters fail fast when no request,
	 * typed value, route value, default, or nullable fallback can satisfy them.
	 *
	 * @param callable $callable Route action callable.
	 * @param Request $request Current HTTP request.
	 * @param array<string|int, mixed> $routeParameters Parameters extracted from route matching.
	 * @param array<class-string|string|int, mixed> $typedValues Injectable values keyed by class name or supplied as objects for instanceof matching.
	 * @return array<int, mixed> Ordered callable arguments.
	 *
	 * @throws \RuntimeException When a required parameter cannot be resolved.
	 */
	public static function resolve(callable $callable, Request $request, array $routeParameters=[], array $typedValues=[]): array {
		$reflection=self::reflect($callable);
		$arguments=[];
		$position=0;
		$usedRouteParameters=[];
		foreach($reflection->getParameters() as $parameter){
			$type=$parameter->getType();
			$typeName=$type instanceof \ReflectionNamedType ? ltrim($type->getName(), '\\') : null;
			if($typeName===Request::class || $typeName==='Dataphyre\\Http\\Request'){
				$arguments[]=$request;
				continue;
			}
			if($typeName!==null && array_key_exists($typeName, $typedValues)){
				$arguments[]=$typedValues[$typeName];
				continue;
			}
			if($typeName!==null){
				foreach($typedValues as $typedValue){
					if(is_object($typedValue) && is_a($typedValue, $typeName)){
						$arguments[]=$typedValue;
						continue 2;
					}
				}
			}
			$name=$parameter->getName();
			if(array_key_exists($name, $routeParameters)){
				$arguments[]=$routeParameters[$name];
				$usedRouteParameters[$name]=true;
				continue;
			}
			$values=[];
			foreach($routeParameters as $routeParameterName=>$value){
				if(isset($usedRouteParameters[$routeParameterName])){
					continue;
				}
				$values[]=$value;
			}
			if(array_key_exists($position, $values)){
				$arguments[]=$values[$position];
				$position++;
				continue;
			}
			if($parameter->isDefaultValueAvailable()){
				$arguments[]=$parameter->getDefaultValue();
				continue;
			}
			if($parameter->allowsNull()){
				$arguments[]=null;
				continue;
			}
			throw new \RuntimeException('Unable to resolve action parameter: '.$name);
		}
		return $arguments;
	}

	/**
	 * Creates the correct reflection object for any supported action callable.
	 *
	 * @param callable $callable Closure, function name, method array, or invokable object.
	 * @return \ReflectionFunctionAbstract Callable reflection used for parameter inspection.
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
