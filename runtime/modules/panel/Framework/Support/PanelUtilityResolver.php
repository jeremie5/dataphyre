<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Resolves Panel callback arguments from named utilities, aliases, and types.
 *
 * Panel components use small user callbacks for labels, visibility, hydration,
 * actions, and validation. This resolver gives those callbacks a predictable
 * argument-injection model across old and new utility names while still
 * supporting typed object discovery and positional fallback keys. It only invokes
 * callables supplied by trusted Panel definitions; it does not sandbox callback
 * side effects.
 */
final class PanelUtilityResolver {

	private const ALIASES=[
		'arguments'=>'params',
		'context'=>'meta',
		'error'=>'exception',
		'errors'=>'messages',
		'fieldName'=>'field',
		'formData'=>'data',
		'getState'=>'get',
		'item'=>'record',
		'model'=>'record',
		'operation'=>'mode',
		'payload'=>'data',
		'row'=>'record',
		'schemaGet'=>'get',
		'schemaSet'=>'set',
		'state'=>'data',
		'user'=>'authUser',
		'values'=>'data',
	];

	/**
	 * Invokes a callback with arguments resolved from a Panel utility map.
	 *
	 * Parameters resolve in this order: exact name, known alias, compatible
	 * object type, positional key, declared default value, nullable null, then
	 * null as a final compatibility fallback. The callback return value is passed
	 * through unchanged. Reflection failures and exceptions thrown by the callback
	 * are not swallowed here; callers decide whether to surface or convert them.
	 *
	 * @param callable $callback User callback or invokable callable to execute.
	 * @param array<string,mixed> $values Utility map containing request, data, record, meta, and caller-specific values.
	 * @param list<string> $positionOrder Optional positional parameter-to-utility key mapping.
	 * @return mixed callback value after named, typed, positional, default, or nullable parameters are resolved.
	 */
	public static function evaluate(callable $callback, array $values=[], array $positionOrder=[]): mixed {
		$closure=\Closure::fromCallable($callback);
		$values=self::normalizeValues($values);
		$reflection=new \ReflectionFunction($closure);
		$arguments=[];
		foreach($reflection->getParameters() as $index=>$parameter){
			$resolved=self::resolveParameter($parameter, $values, $positionOrder[$index] ?? null);
			if($resolved['resolved']){
				$arguments[]=$resolved['value'];
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
			$arguments[]=null;
		}
		return $closure(...$arguments);
	}

	/**
	 * Reads one utility value using canonical and legacy names.
	 *
	 * This is the non-reflection counterpart to evaluate(), used when callers
	 * need to fetch a single utility by name while preserving alias behavior. The
	 * returned value is not cloned or sanitized.
	 *
	 * @param string $name Canonical or alias utility name.
	 * @param array<string,mixed> $values Utility map supplied by the Panel component layer.
	 * @param mixed $default Value returned when neither name nor alias is present.
	 * @return mixed Utility value found by canonical name or alias, or the supplied default when absent.
	 */
	public static function utility(string $name, array $values, mixed $default=null): mixed {
		$values=self::normalizeValues($values);
		if(array_key_exists($name, $values)){
			return $values[$name];
		}
		$alias=self::ALIASES[$name] ?? null;
		return is_string($alias) && array_key_exists($alias, $values) ? $values[$alias] : $default;
	}

	/**
	 * Resolves one reflected callback parameter from the available utilities.
	 *
	 * Name and alias matches take precedence over type-based lookup so explicit
	 * utility keys cannot be displaced by another object of a compatible class.
	 *
	 * @param \ReflectionParameter $parameter Callback parameter being injected.
	 * @param array<string,mixed> $values Normalized utility map.
	 * @param mixed $positionKey Optional positional key for legacy callback signatures.
	 * @return array{resolved:bool,value:mixed} Resolution result consumed by evaluate().
	 */
	private static function resolveParameter(\ReflectionParameter $parameter, array $values, mixed $positionKey): array {
		$name=$parameter->getName();
		if(array_key_exists($name, $values)){
			return ['resolved'=>true, 'value'=>$values[$name]];
		}
		$alias=self::ALIASES[$name] ?? null;
		if(is_string($alias) && array_key_exists($alias, $values)){
			return ['resolved'=>true, 'value'=>$values[$alias]];
		}
		$typed=self::typedValue($parameter, $values);
		if($typed['resolved']){
			return $typed;
		}
		if(is_string($positionKey) && array_key_exists($positionKey, $values)){
			return ['resolved'=>true, 'value'=>$values[$positionKey]];
		}
		return ['resolved'=>false, 'value'=>null];
	}

	/**
	 * Finds a utility value compatible with one of the parameter type hints.
	 *
	 * Scalar and pseudo-types are ignored because utility maps are keyed by
	 * meaning rather than by primitive type. Throwable and Closure receive
	 * special handling for common Panel error and callback hooks. Object matching
	 * scans the normalized utility map in insertion order and returns the first
	 * compatible object.
	 *
	 * @param \ReflectionParameter $parameter Callback parameter with an optional object type.
	 * @param array<string,mixed> $values Normalized utility map to scan.
	 * @return array{resolved:bool,value:mixed} Matching object value or an unresolved marker.
	 */
	private static function typedValue(\ReflectionParameter $parameter, array $values): array {
		foreach(self::typeNames($parameter) as $type){
			$normalized=ltrim($type, '\\');
			$lower=strtolower($normalized);
			if(in_array($lower, ['mixed', 'array', 'callable', 'iterable', 'object', 'null', 'false', 'true'], true)){
				continue;
			}
			if($lower==='closure' && ($values['callback'] ?? null) instanceof \Closure){
				return ['resolved'=>true, 'value'=>$values['callback']];
			}
			if($lower==='throwable'){
				foreach($values as $value){
					if($value instanceof \Throwable){
						return ['resolved'=>true, 'value'=>$value];
					}
				}
			}
			foreach($values as $value){
				if(is_object($value) && is_a($value, $normalized)){
					return ['resolved'=>true, 'value'=>$value];
				}
			}
		}
		return ['resolved'=>false, 'value'=>null];
	}

	/**
	 * Extracts named type strings from a reflected parameter.
	 *
	 * @param \ReflectionParameter $parameter Parameter that may use named or union types.
	 * @return array<int,string> Type names exactly as reported by reflection.
	 */
	private static function typeNames(\ReflectionParameter $parameter): array {
		$type=$parameter->getType();
		if($type instanceof \ReflectionNamedType){
			return [$type->getName()];
		}
		if(!$type instanceof \ReflectionUnionType){
			return [];
		}
		$names=[];
		foreach($type->getTypes() as $namedType){
			if($namedType instanceof \ReflectionNamedType){
				$names[]=$namedType->getName();
			}
		}
		return $names;
	}

	/**
	 * Adds default Panel utilities expected by callback consumers.
	 *
	 * Missing get/set helpers are filled with a dotted-path getter and a no-op
	 * setter. authUser is copied from PanelRequest when available, and meta defaults
	 * to an empty array so callbacks can type expectations consistently.
	 *
	 * @param array<string,mixed> $values Caller-supplied utility map.
	 * @return array<string,mixed> Utility map with get, set, authUser, and meta defaults filled when absent.
	 */
	private static function normalizeValues(array $values): array {
		if(!array_key_exists('get', $values)){
			$values['get']=self::getter($values);
		}
		if(!array_key_exists('set', $values)){
			$values['set']=static function(): void {};
		}
		if(!array_key_exists('authUser', $values) && array_key_exists('request', $values) && $values['request'] instanceof PanelRequest){
			$values['authUser']=$values['request']->user();
		}
		if(!array_key_exists('meta', $values)){
			$values['meta']=[];
		}
		return $values;
	}

	/**
	 * Builds the default dotted-path getter exposed to Panel callbacks.
	 *
	 * The getter searches data, record, and PanelRequest input in that order.
	 * A null or empty path returns the whole data or record value when present.
	 * Request input is read only after data and record miss, which keeps explicit
	 * resolver data ahead of request-derived values.
	 *
	 * @param array<string,mixed> $values Normalized utility map captured by the getter.
	 * @return \Closure(string|null,mixed): mixed Getter closure accepting a path and default.
	 */
	private static function getter(array $values): \Closure {
		return static function(?string $path=null, mixed $default=null) use ($values): mixed {
			if($path===null || trim($path)===''){
				return $values['data'] ?? $values['record'] ?? $default;
			}
			foreach(['data', 'record', 'request'] as $source){
				if(!array_key_exists($source, $values)){
					continue;
				}
				$value=$source==='request' && $values[$source] instanceof PanelRequest
					? $values[$source]->input()
					: $values[$source];
				$found=self::pathValue($value, $path, $default, $exists);
				if($exists){
					return $found;
				}
			}
			return $default;
		};
	}

	/**
	 * Traverses arrays, public object properties, and getX accessors by dotted path.
	 *
	 * Method traversal is limited to conventional zero-argument getters derived
	 * from path segments. Missing segments return the caller default and leave the
	 * output flag false.
	 *
	 * @param mixed $source Array or object root to inspect.
	 * @param string $path Dot-separated lookup path.
	 * @param mixed $default Value returned when a segment cannot be resolved.
	 * @param ?bool $exists Output flag set true when the full path resolves.
	 * @return mixed Value resolved from an array key, public property, getter method, or the supplied default when any path segment is missing.
	 */
	private static function pathValue(mixed $source, string $path, mixed $default, ?bool &$exists=null): mixed {
		$exists=false;
		$value=$source;
		foreach(explode('.', $path) as $segment){
			if(is_array($value) && array_key_exists($segment, $value)){
				$value=$value[$segment];
				continue;
			}
			if(is_object($value)){
				if(isset($value->{$segment})){
					$value=$value->{$segment};
					continue;
				}
				$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $segment)));
				if(method_exists($value, $method)){
					$value=$value->{$method}();
					continue;
				}
			}
			return $default;
		}
		$exists=true;
		return $value;
	}
}
