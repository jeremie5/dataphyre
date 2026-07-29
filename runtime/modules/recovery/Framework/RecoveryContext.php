<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

/** Request-safe context used to filter corrective actions and incident dimensions. */
final class RecoveryContext {
	/** @var array<string,true> */
	private array $permissions=[];
	/** @var array<string,int|float|string|bool|null> */
	private array $scope=[];
	/** @var array<string,int|float|string|bool|null> */
	private array $attributes=[];
	private mixed $permissionResolver;

	/**
	 * @param array<int,string> $permissions
	 * @param array<string,mixed> $scope
	 * @param array<string,mixed> $attributes
	 */
	public function __construct(
		array $permissions=[],
		array $scope=[],
		private string $locale='en',
		private string $requestMethod='GET',
		private string $requestPath='/',
		private ?string $correlationId=null,
		array $attributes=[],
		?callable $permissionResolver=null
	) {
		foreach($permissions as $permission){
			$permission=strtolower(trim((string)$permission));
			if($permission!=='') $this->permissions[$permission]=true;
		}
		foreach($scope as $name=>$value){
			$name=strtolower(trim((string)$name));
			if(preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)===1 && (is_scalar($value) || $value===null)){
				$this->scope[$name]=$value;
			}
		}
		ksort($this->scope, SORT_STRING);
		foreach($attributes as $name=>$value){
			$name=strtolower(trim((string)$name));
			if(preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)===1 && (is_scalar($value) || $value===null)){
				$this->attributes[$name]=$value;
			}
		}
		$this->locale=trim($this->locale)!=='' ? str_replace('_', '-', strtolower(trim($this->locale))) : 'en';
		$this->requestMethod=strtoupper(trim($this->requestMethod)) ?: 'GET';
		$this->requestPath='/'.ltrim(trim($this->requestPath), '/');
		$this->correlationId=self::validCorrelationId($this->correlationId) ? trim((string)$this->correlationId) : null;
		$this->permissionResolver=$permissionResolver;
	}

	public function can(string $permission): bool {
		$permission=strtolower(trim($permission));
		if($permission==='') return true;
		if(is_callable($this->permissionResolver)){
			return (bool)($this->permissionResolver)($permission, $this);
		}
		if(isset($this->permissions['*']) || isset($this->permissions[$permission])) return true;
		$segments=explode('.', $permission);
		while(count($segments)>1){
			array_pop($segments);
			if(isset($this->permissions[implode('.', $segments).'.*'])) return true;
		}
		return false;
	}

	/** @param array<int,string> $permissions */
	public function canAll(array $permissions): bool {
		foreach($permissions as $permission){
			if(!$this->can((string)$permission)) return false;
		}
		return true;
	}

	public function locale(): string {
		return $this->locale;
	}

	public function requestMethod(): string {
		return $this->requestMethod;
	}

	public function requestPath(): string {
		return $this->requestPath;
	}

	public function correlationId(): ?string {
		return $this->correlationId;
	}

	public function scopeType(): string {
		return strtolower(trim((string)($this->scope['scope_type'] ?? $this->scope['type'] ?? '')));
	}

	public function scopeValue(string $name, mixed $default=null): mixed {
		return $this->scope[strtolower(trim($name))] ?? $default;
	}

	/** @return array<string,int|float|string|bool|null> */
	public function scope(): array {
		return $this->scope;
	}

	public function attribute(string $name, mixed $default=null): mixed {
		return $this->attributes[strtolower(trim($name))] ?? $default;
	}

	/** @return array<string,int|float|string|bool|null> */
	public function attributes(): array {
		return $this->attributes;
	}

	public function withCorrelationId(string $correlationId): self {
		$clone=clone $this;
		$clone->correlationId=self::validCorrelationId($correlationId) ? trim($correlationId) : null;
		return $clone;
	}

	private static function validCorrelationId(?string $value): bool {
		return is_string($value)
			&& preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', trim($value))===1;
	}
}
