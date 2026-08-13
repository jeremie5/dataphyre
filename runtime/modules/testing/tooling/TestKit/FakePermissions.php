<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Dataphyre\Test\Contracts\AssertionContext;

final class FakePermissions {

	/** @var array<int, array{effect:bool, actor:mixed, ability:string, resource:mixed, condition:mixed}> */
	private array $rules=[];

	public function allow(string $ability, mixed $resource='*', mixed $actor='*', ?callable $condition=null): self {
		return $this->rule(true, $ability, $resource, $actor, $condition);
	}

	public function deny(string $ability, mixed $resource='*', mixed $actor='*', ?callable $condition=null): self {
		return $this->rule(false, $ability, $resource, $actor, $condition);
	}

	public function permits(mixed $actor, string $ability, mixed $resource=null): bool {
		$allowed=false;
		foreach($this->rules as $rule){
			if(!$this->matchesRule($rule, $actor, $ability, $resource)){
				continue;
			}
			if($rule['effect']===false){
				return false;
			}
			$allowed=true;
		}
		return $allowed;
	}

	public function assertPermits(AssertionContext $t, mixed $actor, string $ability, mixed $resource=null): void {
		$t->permits($this, $actor, $ability, $resource);
	}

	public function assertDenies(AssertionContext $t, mixed $actor, string $ability, mixed $resource=null): void {
		$t->denies($this, $actor, $ability, $resource);
	}

	private function rule(bool $effect, string $ability, mixed $resource, mixed $actor, ?callable $condition): self {
		$this->rules[]=[
			'effect'=>$effect,
			'actor'=>$actor,
			'ability'=>strtolower($ability),
			'resource'=>$resource,
			'condition'=>$condition,
		];
		return $this;
	}

	private function matchesRule(array $rule, mixed $actor, string $ability, mixed $resource): bool {
		if($rule['ability']!=='*' && $rule['ability']!==strtolower($ability)){
			return false;
		}
		if($rule['actor']!=='*' && $this->identity($rule['actor'])!==$this->identity($actor)){
			return false;
		}
		if($rule['resource']!=='*' && $this->identity($rule['resource'])!==$this->identity($resource)){
			return false;
		}
		return !is_callable($rule['condition']) || (bool)$rule['condition']($actor, $resource, $ability);
	}

	private function identity(mixed $value): mixed {
		if(is_array($value)){
			return $value['id'] ?? $value['key'] ?? json_encode($value, JSON_UNESCAPED_SLASHES);
		}
		if(is_object($value)){
			return $value->id ?? $value->key ?? $value::class;
		}
		return $value;
	}
}
