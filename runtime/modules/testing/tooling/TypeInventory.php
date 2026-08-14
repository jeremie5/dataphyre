<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Test;

/**
 * Readable reflection inventory for API-shape and fluent-contract tests.
 *
 * Tests keep domain-specific argument construction while this object owns
 * ReflectionClass creation, method selection, invocation, and instantiation.
 */
final class TypeInventory {
	private \ReflectionClass $reflection;

	public function __construct(object|string $target) {
		$this->reflection=new \ReflectionClass($target);
	}

	public static function of(object|string $target): self {
		return new self($target);
	}

	public function name(): string {
		return $this->reflection->getName();
	}

	public function isInstantiable(): bool {
		return $this->reflection->isInstantiable();
	}

	public function isFinal(): bool {
		return $this->reflection->isFinal();
	}

	public function parent(): ?string {
		$parent=$this->reflection->getParentClass();
		return $parent instanceof \ReflectionClass ? $parent->getName() : null;
	}

	/** @return list<string> */
	public function interfaces(): array {
		$interfaces=$this->reflection->getInterfaceNames();
		sort($interfaces, SORT_STRING);
		return $interfaces;
	}

	/** @return list<string> */
	public function traits(): array {
		$traits=$this->reflection->getTraitNames();
		sort($traits, SORT_STRING);
		return $traits;
	}

	public function sourceFile(): ?string {
		$file=$this->reflection->getFileName();
		return is_string($file) ? str_replace('\\', '/', $file) : null;
	}

	public function hasMethod(string $method): bool {
		return $this->reflection->hasMethod($method);
	}

	public function method(string $method): \ReflectionMethod {
		return $this->reflection->getMethod($method);
	}

	public function constructor(): ?\ReflectionMethod {
		return $this->reflection->getConstructor();
	}

	/** @return list<\ReflectionMethod> */
	public function methods(?int $filter=null): array {
		return $filter===null ? $this->reflection->getMethods() : $this->reflection->getMethods($filter);
	}

	/** @return list<\ReflectionMethod> */
	public function publicMethods(?string $declaredBy=null, ?bool $static=null): array {
		return array_values(array_filter(
			$this->reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
			static function(\ReflectionMethod $method)use($declaredBy,$static): bool {
				if($declaredBy!==null && $method->getDeclaringClass()->getName()!==$declaredBy){
					return false;
				}
				return $static===null || $method->isStatic()===$static;
			}
		));
	}

	/** @return list<\ReflectionMethod> */
	public function declaredPublicMethods(?bool $static=null): array {
		return $this->publicMethods($this->name(),$static);
	}

	/** @return list<\ReflectionMethod> */
	public function protectedMethods(?string $declaredBy=null): array {
		return array_values(array_filter(
			$this->reflection->getMethods(\ReflectionMethod::IS_PROTECTED),
			static fn(\ReflectionMethod $method): bool=>$declaredBy===null || $method->getDeclaringClass()->getName()===$declaredBy
		));
	}

	/** @return array{public:bool,protected:bool,private:bool,static:bool,parameters:int,return_type:string} */
	public function methodShape(string $method): array {
		$reflection=$this->method($method);
		return [
			'public'=>$reflection->isPublic(),
			'protected'=>$reflection->isProtected(),
			'private'=>$reflection->isPrivate(),
			'static'=>$reflection->isStatic(),
			'parameters'=>$reflection->getNumberOfParameters(),
			'return_type'=>(string)$reflection->getReturnType(),
		];
	}

	public function invoke(string|\ReflectionMethod $method, ?object $target=null, mixed ...$arguments): mixed {
		return $this->invokeWithArguments($method,$target,$arguments);
	}

	/** @param array<int,mixed> $arguments */
	public function invokeWithArguments(string|\ReflectionMethod $method, ?object $target, array $arguments=[]): mixed {
		$method=is_string($method) ? $this->method($method) : $method;
		if(!$method->isPublic()){
			throw new \LogicException('TypeInventory invokes public API methods only: '.$method->getName().'.');
		}
		return $method->invokeArgs($method->isStatic() ? null : $target,$arguments);
	}

	public function newInstance(mixed ...$arguments): object {
		return $this->newInstanceWithArguments($arguments);
	}

	/** @param array<int,mixed> $arguments */
	public function newInstanceWithArguments(array $arguments=[]): object {
		if(!$this->isInstantiable()){
			throw new \LogicException('TypeInventory target is not instantiable: '.$this->name().'.');
		}
		return $this->reflection->newInstanceArgs($arguments);
	}

	/** Creates an instance for boundary tests that must not execute the production constructor. */
	public function withoutConstructor(): object {
		if(!$this->isInstantiable()){
			throw new \LogicException('TypeInventory target is not instantiable: '.$this->name().'.');
		}
		return $this->reflection->newInstanceWithoutConstructor();
	}
}
