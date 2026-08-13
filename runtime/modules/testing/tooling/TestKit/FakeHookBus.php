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

use Closure;

final class FakeHookBus {

	/** @var array<string, array<int, Closure>> */
	private array $listeners=[];
	/** @var array<int, array{scope:string, name:string, payload:mixed, result:mixed}> */
	private array $calls=[];

	public function __construct(private string $kind='hook', private string $default_scope='app') {}

	public function on(string $name, callable $listener, string $scope=''): self {
		$key=$this->key($name, $scope);
		$this->listeners[$key][]=$listener instanceof Closure ? $listener : Closure::fromCallable($listener);
		return $this;
	}

	/** @return array<int, mixed> */
	public function call(string $name, mixed $payload=null, string $scope=''): array {
		$scope=$this->scope($scope);
		$normalized=$this->normalize($name);
		$key=$scope.':'.$normalized;
		$results=[];
		foreach($this->listeners[$key] ?? [] as $listener){
			$results[]=$listener($payload, $normalized, $scope);
		}
		$this->calls[]=[
			'scope'=>$scope,
			'name'=>$normalized,
			'payload'=>$payload,
			'result'=>$results,
		];
		return $results;
	}

	public function dispatch(string $name, mixed $payload=null, string $scope=''): array {
		return $this->call($name, $payload, $scope);
	}

	/** @return array<int, array{scope:string, name:string, payload:mixed, result:mixed}> */
	public function calls(): array {
		return $this->calls;
	}

	public function assertCalled(AssertionContext $t, string $name, string $scope='', mixed $payload_subset=null): void {
		$expected=[
			'scope'=>$this->scope($scope),
			'name'=>$this->normalize($name),
		];
		$found=false;
		foreach($this->calls as $call){
			if($call['scope']!==$expected['scope'] || $call['name']!==$expected['name']){
				continue;
			}
			if(is_array($payload_subset)){
				try{
					$t->subset($payload_subset, $call['payload']);
				}catch(AssertionFailed){
					continue;
				}
			}
			$found=true;
			break;
		}
		if($found===false){
			$t->fail('Expected '.$this->kind.' to be called.', $expected+['payload_subset'=>$payload_subset], $this->calls);
		}
		$t->isTrue(true, ucfirst($this->kind).' was called.');
	}

	public function assertNotCalled(AssertionContext $t, string $name, string $scope=''): void {
		$expected=[
			'scope'=>$this->scope($scope),
			'name'=>$this->normalize($name),
		];
		foreach($this->calls as $call){
			if($call['scope']===$expected['scope'] && $call['name']===$expected['name']){
				$t->fail('Expected '.$this->kind.' not to be called.', 'not called', $call);
			}
		}
		$t->isTrue(true, ucfirst($this->kind).' was not called.');
	}

	public function assertCalledTimes(AssertionContext $t, string $name, int $expected, string $scope=''): void {
		$scope=$this->scope($scope);
		$name=$this->normalize($name);
		$count=0;
		foreach($this->calls as $call){
			if($call['scope']===$scope && $call['name']===$name){
				$count++;
			}
		}
		$t->same($expected, $count, 'Expected '.$this->kind.' call count to match.');
	}

	private function key(string $name, string $scope=''): string {
		return $this->scope($scope).':'.$this->normalize($name);
	}

	private function scope(string $scope): string {
		$scope=strtolower(trim($scope!=='' ? $scope : $this->default_scope));
		return $scope!=='' ? $scope : 'app';
	}

	private function normalize(string $name): string {
		$name=strtoupper(trim($name));
		$name=preg_replace('/[^A-Z0-9]+/', '_', $name) ?? $name;
		return trim($name, '_');
	}
}
