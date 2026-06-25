<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

/**
 * Resolves MVC services, controllers, and callable dependencies.
 *
 * The container supports explicit bindings, singletons, prebuilt instances,
 * aliases, reflection-based class construction, typed parameter overrides, and
 * callable invocation. It is intentionally small: it favors predictable request
 * dispatch dependency injection over a full application-wide service container.
 */
final class Container {

	/** @var array<string, array{concrete:callable|string,shared:bool}> Explicit service bindings. */
	private array $bindings=[];

	/** @var array<string, mixed> Shared singleton instances and manually registered objects. */
	private array $instances=[];

	/** @var array<string, string> Alias names mapped to canonical abstract identifiers. */
	private array $aliases=[];

	/** @var array<string, bool> Classes currently being reflected, used to detect cycles. */
	private array $building=[];

	/**
	 * Registers a service binding.
	 *
	 * The abstract identifier is normalized and any cached instance for that
	 * identifier is cleared. A null concrete value means the abstract should be
	 * built directly as a class name.
	 *
	 * @param string $abstract Service identifier or class/interface name.
	 * @param callable|string|null $concrete Factory callable, concrete class name, or null for self-binding.
	 * @param bool $shared Whether resolved objects should be cached as instances.
	 * @return self Container instance for fluent registration.
	 */
	public function bind(string $abstract, callable|string|null $concrete=null, bool $shared=false): self {
		$abstract=$this->normalizeIdentifier($abstract);
		$concrete=$concrete ?? $abstract;
		unset($this->instances[$abstract]);
		$this->bindings[$abstract]=[
			'concrete'=>$concrete,
			'shared'=>$shared,
		];
		return $this;
	}

	/**
	 * Registers a shared service binding.
	 *
	 *
	 * @param string $abstract Service identifier or class/interface name.
	 * @param callable|string|null $concrete Factory callable, concrete class name, or null for self-binding.
	 * @return self Container instance for fluent registration.
	 */
	public function singleton(string $abstract, callable|string|null $concrete=null): self {
		return $this->bind($abstract, $concrete, true);
	}

	/**
	 * Registers an already-built instance.
	 *
	 * Instance registration replaces any binding for the same identifier and is
	 * returned directly by make() without invoking reflection or factories.
	 *
	 * @param string $abstract Service identifier or class/interface name.
	 * @param mixed $instance Prebuilt value to return for the abstract.
	 * @return self Container instance for fluent registration.
	 */
	public function instance(string $abstract, mixed $instance): self {
		$abstract=$this->normalizeIdentifier($abstract);
		$this->instances[$abstract]=$instance;
		unset($this->bindings[$abstract]);
		return $this;
	}

	/**
	 * Registers an alias for another abstract identifier.
	 *
	 * Aliases are resolved transitively and circular alias chains are rejected
	 * during resolution. Self-aliases are rejected immediately because they are
	 * never useful and obscure dependency errors.
	 *
	 * @param string $abstract Canonical service identifier.
	 * @param string $alias Alternate name accepted by has() and make().
	 * @return self Container instance for fluent registration.
	 * @throws ContainerException When an alias points to itself.
	 */
	public function alias(string $abstract, string $alias): self {
		$abstract=$this->normalizeIdentifier($abstract);
		$alias=$this->normalizeIdentifier($alias);
		if($abstract===$alias){
			throw new ContainerException('Container alias cannot reference itself: '.$alias);
		}
		$this->aliases[$alias]=$abstract;
		return $this;
	}

	/**
	 * Reports whether the container can resolve an identifier.
	 *
	 * A service is available when it has a registered instance, an explicit
	 * binding, or an instantiable class name that reflection can construct.
	 *
	 * @param string $abstract Service identifier or class/interface name.
	 * @return bool True when make() has enough information to attempt resolution.
	 */
	public function has(string $abstract): bool {
		$abstract=$this->resolveAlias($this->normalizeIdentifier($abstract));
		return array_key_exists($abstract, $this->instances)
			|| array_key_exists($abstract, $this->bindings)
			|| (class_exists($abstract) && $this->isInstantiable($abstract));
	}

	/**
	 * Resolves a service or class.
	 *
	 * Explicit instances win first, then bindings, then reflection-based class
	 * construction. Named parameters override constructor parameters by name;
	 * typed values override dependencies by class/interface before the container
	 * tries to resolve them recursively.
	 *
	 * @param string $abstract Service identifier or class/interface name.
	 * @param array<string|int, mixed> $parameters Named or positional constructor/factory parameters.
	 * @param array<string, mixed>|array<int, object> $typedValues Values matched by type before recursive resolution.
	 * @return mixed Shared instance, factory output, bound alias target, or reflection-built object.
	 * @throws ContainerException When the target cannot be built.
	 */
	public function make(string $abstract, array $parameters=[], array $typedValues=[]): mixed {
		$abstract=$this->resolveAlias($this->normalizeIdentifier($abstract));
		if(array_key_exists($abstract, $this->instances)){
			return $this->instances[$abstract];
		}
		$binding=$this->bindings[$abstract] ?? null;
		$concrete=$binding['concrete'] ?? $abstract;
		$shared=(bool)($binding['shared'] ?? false);
		$object=$this->resolveConcrete($abstract, $concrete, $parameters, $typedValues);
		if($shared){
			$this->instances[$abstract]=$object;
		}
		return $object;
	}

	/**
	 * Invokes a callable with container-resolved arguments.
	 *
	 * Callable strings may use Class@method or Class::method syntax. Non-static
	 * class method references are instantiated through make() before invocation.
	 *
	 * @param callable|array|string $callable Callable, invokable class, or controller method reference.
	 * @param array<string|int, mixed> $parameters Named or positional call parameters.
	 * @param array<string, mixed>|array<int, object> $typedValues Values matched by type before recursive resolution.
	 * @return mixed Value returned after parameter names, positional values, defaults, and typed services are resolved.
	 * @throws ContainerException When the callable or one of its dependencies cannot be resolved.
	 */
	public function call(callable|array|string $callable, array $parameters=[], array $typedValues=[]): mixed {
		$callable=$this->normalizeCallable($callable);
		return $callable(...$this->resolveArguments($this->reflectCallable($callable), $parameters, $typedValues));
	}

	/**
	 * Resolves a binding concrete into a runtime value.
	 *
	 * String concretes may point to another registered binding/instance or to a
	 * class that should be built through reflection. Factory callables receive
	 * the container plus the raw parameter and typed-value arrays.
	 *
	 * @param string $abstract Canonical abstract currently being resolved.
	 * @param callable|string $concrete Factory callable or concrete class/abstract name.
	 * @param array<string|int, mixed> $parameters Resolution parameters.
	 * @param array<string, mixed>|array<int, object> $typedValues Typed override values.
	 * @return mixed Factory output, recursively resolved binding, or newly built class instance.
	 */
	private function resolveConcrete(string $abstract, callable|string $concrete, array $parameters, array $typedValues): mixed {
		if(is_string($concrete)){
			$concrete=$this->resolveAlias($this->normalizeIdentifier($concrete));
			if($concrete!==$abstract && (array_key_exists($concrete, $this->bindings) || array_key_exists($concrete, $this->instances))){
				return $this->make($concrete, $parameters, $typedValues);
			}
			return $this->build($concrete, $parameters, $typedValues);
		}
		return $concrete($this, $parameters, $typedValues);
	}

	/**
	 * Builds an instantiable class through constructor reflection.
	 *
	 * The building stack detects circular constructor dependencies and is always
	 * cleared in a finally block so failed builds do not poison later requests.
	 *
	 * @param string $class Class name to instantiate.
	 * @param array<string|int, mixed> $parameters Constructor parameters.
	 * @param array<string, mixed>|array<int, object> $typedValues Typed override values.
	 * @return object Instance created with resolved constructor dependencies.
	 * @throws ContainerException When the class is missing, abstract, an interface, cyclic, or has unresolved parameters.
	 */
	private function build(string $class, array $parameters=[], array $typedValues=[]): mixed {
		if(!class_exists($class)){
			if(interface_exists($class)){
				throw new ContainerException('Container cannot instantiate unbound interface: '.$class);
			}
			throw new ContainerException('Container target class does not exist: '.$class);
		}
		if(isset($this->building[$class])){
			throw new ContainerException('Circular container dependency detected while building: '.$class);
		}
		$reflection=new \ReflectionClass($class);
		if(!$reflection->isInstantiable()){
			throw new ContainerException('Container target is not instantiable: '.$class);
		}
		$this->building[$class]=true;
		try{
			$constructor=$reflection->getConstructor();
			if($constructor===null){
				return new $class();
			}
			return $reflection->newInstanceArgs($this->resolveArguments($constructor, $parameters, $typedValues));
		}finally{
			unset($this->building[$class]);
		}
	}

	/**
	 * Resolves reflected function or method parameters.
	 *
	 * Resolution order is type override, container-resolvable type, named
	 * parameter, positional parameter, default value, nullable fallback, then
	 * exception. Positional matching skips named parameters already consumed.
	 *
	 * @param \ReflectionFunctionAbstract $reflection Callable or constructor reflection.
	 * @param array<string|int, mixed> $parameters Named or positional values.
	 * @param array<string, mixed>|array<int, object> $typedValues Values matched by parameter class.
	 * @return array<int, mixed> Arguments ready for invocation.
	 * @throws ContainerException When a required parameter cannot be resolved.
	 */
	private function resolveArguments(\ReflectionFunctionAbstract $reflection, array $parameters=[], array $typedValues=[]): array {
		$arguments=[];
		$position=0;
		$usedParameters=[];
		foreach($reflection->getParameters() as $parameter){
			$name=$parameter->getName();
			$typeName=$this->parameterClassName($parameter);
			if($typeName!==null){
				$typedValue=$this->typedValue($typeName, $typedValues);
				if($typedValue['found']){
					$arguments[]=$typedValue['value'];
					continue;
				}
				if($this->has($typeName)){
					$arguments[]=$this->make($typeName);
					continue;
				}
			}
			if(array_key_exists($name, $parameters)){
				$arguments[]=$parameters[$name];
				$usedParameters[$name]=true;
				continue;
			}
			$values=[];
			foreach($parameters as $parameterName=>$value){
				if(!is_int($parameterName) && isset($usedParameters[$parameterName])){
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
			throw new ContainerException('Unable to resolve container parameter: '.$name);
		}
		return $arguments;
	}

	/**
	 * Finds a typed override for a class/interface name.
	 *
	 * Overrides may be keyed by normalized type name or supplied as object
	 * values where is_a() confirms compatibility with the requested type.
	 *
	 * @param string $typeName Class/interface type requested by a parameter.
	 * @param array<string, mixed>|array<int, object> $typedValues Typed override values.
	 * @return array{found:bool,value:mixed}
	 */
	private function typedValue(string $typeName, array $typedValues): array {
		$typeName=$this->normalizeIdentifier($typeName);
		if(array_key_exists($typeName, $typedValues)){
			return [
				'found'=>true,
				'value'=>$typedValues[$typeName],
			];
		}
		foreach($typedValues as $typedValue){
			if(is_object($typedValue) && is_a($typedValue, $typeName)){
				return [
					'found'=>true,
					'value'=>$typedValue,
				];
			}
		}
		return [
			'found'=>false,
			'value'=>null,
		];
	}

	/**
	 * Converts supported callable notation into a PHP callable.
	 *
	 * Non-static class methods are resolved on a container-built instance.
	 * Invokable class strings are also instantiated through make().
	 *
	 * @param callable|array|string $callable Callable notation to normalize.
	 * @return callable Callable ready for reflection and invocation.
	 * @throws ContainerException When the callable notation is unsupported.
	 */
	private function normalizeCallable(callable|array|string $callable): callable {
		if(is_array($callable)){
			if(isset($callable[0], $callable[1]) && is_string($callable[0]) && class_exists($callable[0])){
				$method=new \ReflectionMethod($callable[0], (string)$callable[1]);
				if($method->isStatic()){
					return [$callable[0], (string)$callable[1]];
				}
				return [$this->make($callable[0]), (string)$callable[1]];
			}
			if(is_callable($callable)){
				return $callable;
			}
		}
		if(is_string($callable)){
			if(str_contains($callable, '@')){
				[$class, $method]=explode('@', $callable, 2);
				return [$this->make($class), $method];
			}
			if(str_contains($callable, '::')){
				[$class, $method]=explode('::', $callable, 2);
				$reflection=new \ReflectionMethod($class, $method);
				if($reflection->isStatic()){
					return [$class, $method];
				}
				return [$this->make($class), $method];
			}
			if(class_exists($callable)){
				return $this->make($callable);
			}
		}
		if(is_callable($callable)){
			return $callable;
		}
		throw new ContainerException('Container callable is invalid or unsupported.');
	}

	/**
	 * Creates a reflection object for a normalized callable.
	 *
	 * @param callable $callable Callable returned by normalizeCallable().
	 * @return \ReflectionFunctionAbstract Reflection function or method used for argument resolution.
	 */
	private function reflectCallable(callable $callable): \ReflectionFunctionAbstract {
		if(is_array($callable)){
			return new \ReflectionMethod($callable[0], (string)$callable[1]);
		}
		if(is_object($callable) && !$callable instanceof \Closure){
			return new \ReflectionMethod($callable, '__invoke');
		}
		return new \ReflectionFunction($callable);
	}

	/**
	 * Returns the non-builtin class name for a parameter type.
	 *
	 * Union, intersection, and builtin-only parameters are left to named,
	 * positional, default, or nullable resolution paths.
	 *
	 * @param \ReflectionParameter $parameter Parameter to inspect.
	 * @return ?string Normalized class/interface name, or null when unavailable.
	 */
	private function parameterClassName(\ReflectionParameter $parameter): ?string {
		$type=$parameter->getType();
		if(!$type instanceof \ReflectionNamedType || $type->isBuiltin()){
			return null;
		}
		return $this->normalizeIdentifier($type->getName());
	}

	/**
	 * Reports whether a class can be instantiated through reflection.
	 *
	 * @param string $class Class name to inspect.
	 * @return bool True when the class exists and is instantiable.
	 */
	private function isInstantiable(string $class): bool {
		try{
			return (new \ReflectionClass($class))->isInstantiable();
		}catch(\ReflectionException){
			return false;
		}
	}

	/**
	 * Resolves an abstract identifier through the alias table.
	 *
	 * @param string $abstract Normalized identifier to resolve.
	 * @return string Canonical identifier after following aliases.
	 * @throws ContainerException When aliases form a cycle.
	 */
	private function resolveAlias(string $abstract): string {
		$seen=[];
		while(isset($this->aliases[$abstract])){
			if(isset($seen[$abstract])){
				throw new ContainerException('Circular container alias detected: '.$abstract);
			}
			$seen[$abstract]=true;
			$abstract=$this->aliases[$abstract];
		}
		return $abstract;
	}

	/**
	 * Normalizes a service identifier or class name.
	 *
	 * Leading namespace separators are removed so equivalent class names share
	 * the same binding key. Blank identifiers are rejected early to keep
	 * dependency errors precise.
	 *
	 * @param string $identifier Raw service identifier.
	 * @return string Normalized identifier used for bindings, instances, and aliases.
	 * @throws ContainerException When the identifier is blank.
	 */
	private function normalizeIdentifier(string $identifier): string {
		$identifier=trim($identifier);
		if($identifier===''){
			throw new ContainerException('Container identifier cannot be empty.');
		}
		return ltrim($identifier, '\\');
	}
}
