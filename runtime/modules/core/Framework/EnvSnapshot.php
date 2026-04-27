<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre;

final class EnvSnapshot implements \JsonSerializable {

	public function __construct(
		private readonly ?string $prefix,
		private readonly string $separator='/',
		private readonly array $values=[]
	){}

	public function prefix(): ?string {
		return $this->prefix;
	}

	public function separator(): string {
		return $this->separator;
	}

	public function all(): array {
		return $this->values;
	}

	public function get(string $key, mixed $default=null): mixed {
		$key=trim($key);
		return $key!=='' && array_key_exists($key, $this->values) ? $this->values[$key] : $default;
	}

	public function has(string $key=''): bool {
		$key=trim($key);
		if($key===''){
			return $this->values!==[];
		}
		return array_key_exists($key, $this->values);
	}

	public function only(array $keys): array {
		$selected=[];
		foreach($keys as $key){
			$key=trim((string)$key);
			if($key==='' || !array_key_exists($key, $this->values)){
				continue;
			}
			$selected[$key]=$this->values[$key];
		}
		return $selected;
	}

	public function except(array $keys): array {
		$values=$this->values;
		foreach($keys as $key){
			unset($values[trim((string)$key)]);
		}
		return $values;
	}

	public function keys(): array {
		return array_keys($this->values);
	}

	public function isEmpty(): bool {
		return $this->values===[];
	}

	public function scope(?string $prefix): self {
		$prefix=static::normalizePrefix($prefix, $this->separator);
		if($prefix===null){
			return $this;
		}
		$scoped=[];
		$scope_prefix=$prefix.$this->separator;
		foreach($this->values as $key=>$value){
			if(!is_string($key) || !str_starts_with($key, $scope_prefix)){
				continue;
			}
			$scoped[substr($key, strlen($scope_prefix))]=$value;
		}
		return new self($this->composePrefix($prefix), $this->separator, $scoped);
	}

	public function toArray(): array {
		return [
			'prefix'=>$this->prefix,
			'separator'=>$this->separator,
			'values'=>$this->values,
		];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}

	private function composePrefix(string $prefix): string {
		if($this->prefix===null || $this->prefix===''){
			return $prefix;
		}
		return $this->prefix.$this->separator.$prefix;
	}

	private static function normalizePrefix(?string $prefix, string $separator): ?string {
		if(!is_string($prefix)){
			return null;
		}
		$prefix=trim($prefix, $separator." \t\n\r\0\x0B");
		return $prefix!=='' ? $prefix : null;
	}
}
