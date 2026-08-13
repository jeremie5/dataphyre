<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class FunctionPatches {

	/** @var array<string, Spy> */
	private static array $spies=[];

	public static function define(string $qualified_function, ?callable $handler=null): Spy {
		$qualified_function=trim($qualified_function, '\\');
		if(!str_contains($qualified_function, '\\')){
			throw new \InvalidArgumentException('Function patches must target a namespaced function.');
		}
		if(function_exists('\\'.$qualified_function)){
			throw new \InvalidArgumentException('Cannot patch an already defined PHP function: '.$qualified_function);
		}
		$parts=explode('\\', $qualified_function);
		$function=array_pop($parts);
		$namespace=implode('\\', $parts);
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $function)!==1){
			throw new \InvalidArgumentException('Invalid function name for patch.');
		}
		foreach($parts as $part){
			if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)!==1){
				throw new \InvalidArgumentException('Invalid namespace for function patch.');
			}
		}
		$spy=new Spy($handler);
		self::$spies[$qualified_function]=$spy;
		$code='namespace '.$namespace.'; function '.$function.'(...$arguments): mixed { return \\Dataphyre\\Test\\FunctionPatches::call('.var_export($qualified_function, true).', $arguments); }';
		PhpStub::define($code);
		return $spy;
	}

	/** @param array<int, mixed> $arguments */
	public static function call(string $qualified_function, array $arguments): mixed {
		if(!isset(self::$spies[$qualified_function])){
			throw new \BadFunctionCallException('Function patch is not registered: '.$qualified_function);
		}
		return (self::$spies[$qualified_function])(...$arguments);
	}
}
