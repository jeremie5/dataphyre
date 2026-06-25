<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Routing;

/**
 * Value object for route handlers that call controller classes and methods.
 *
 * Controller actions normalize class, method, static/instance mode, and optional
 * bootstrap files into route compiler metadata.
 */
final class ControllerAction {

	private string $class;
	private string $method;
	private bool $static;
	private ?string $bootstrap;

	/**
	 * Stores normalized controller dispatch metadata.
	 *
	 * @param string $class Controller class name, with leading namespace separators removed.
	 * @param string $method Controller method name.
	 * @param bool $static Whether the dispatcher should call the method statically.
	 * @param ?string $bootstrap Optional bootstrap file required before dispatch.
	 */
	private function __construct(string $class, string $method, bool $static=true, ?string $bootstrap=null){
		$this->class=trim($class, '\\');
		$this->method=trim($method);
		$this->static=$static;
		$this->bootstrap=$bootstrap!==null && trim($bootstrap)!==''
			? trim($bootstrap)
			: null;
	}

	/**
	 * Creates a static controller action.
	 *
	 * @param string $class Controller class name.
	 * @param string $method Static method invoked by the route dispatcher.
	 * @param array{bootstrap?:string|null} $options Optional bootstrap file path.
	 * @return self Controller action descriptor.
	 */
	public static function static(string $class, string $method, array $options=[]): self {
		return new self($class, $method, true, $options['bootstrap'] ?? null);
	}

	/**
	 * Creates an instance controller action.
	 *
	 * @param string $class Controller class name.
	 * @param string $method Instance method invoked on a resolved controller object.
	 * @param array{bootstrap?:string|null} $options Optional bootstrap file path.
	 * @return self Controller action descriptor.
	 */
	public static function instance(string $class, string $method, array $options=[]): self {
		return new self($class, $method, false, $options['bootstrap'] ?? null);
	}

	/**
	 * Builds a controller action from class-at-method route notation.
	 *
	 * Missing methods default to __invoke. A namespace is prepended only when the
	 * class is not already qualified.
	 *
	 * @param string $handler Handler notation such as UserController@show.
	 * @param ?string $namespace Optional namespace for unqualified class names.
	 * @param array{static?:bool,bootstrap?:string|null} $options Optional static flag and bootstrap file path.
	 * @return self Controller action descriptor.
	 */
	public static function fromString(string $handler, ?string $namespace=null, array $options=[]): self {
		[$class, $method]=array_pad(explode('@', $handler, 2), 2, '__invoke');
		$class=trim($class, '\\');
		if($namespace!==null && trim($namespace)!=='' && !str_contains($class, '\\')){
			$class=trim($namespace, '\\').'\\'.$class;
		}
		$method=trim($method) !== '' ? trim($method) : '__invoke';
		$static=(bool)($options['static'] ?? false);
		return new self($class, $method, $static, $options['bootstrap'] ?? null);
	}

	/**
	 * Returns route compiler metadata for this controller action.
	 *
	 * @return array{type:string,class:string,method:string,static:bool,bootstrap:?string} Compiled controller action.
	 */
	public function compile(): array {
		return [
			'type'=>'controller',
			'class'=>$this->class,
			'method'=>$this->method,
			'static'=>$this->static,
			'bootstrap'=>$this->bootstrap,
		];
	}
}
