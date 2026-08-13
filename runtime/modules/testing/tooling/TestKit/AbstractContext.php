<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Closure;
use Throwable;

/**
 * Owns lifecycle state and assertion accounting shared by every concrete test context.
 *
 * Capability implementations live in focused traits; this base protects the
 * invariants that must remain consistent across all of them.
 */
abstract class AbstractContext {

	/** @var array<string, mixed> */
	protected array $fixtures=[];

	/** @var list<Closure> */
	protected array $deferred=[];

	private int $assertions=0;

	/** @param array<string,mixed> $metadata */
	public function __construct(protected string $name, protected string $dataset='', protected string $file='', protected string $suite='', protected array $metadata=[]) {}

	public function name(): string {
		return $this->name;
	}

	public function dataset(): string {
		return $this->dataset;
	}

	public function suite(): string {
		return $this->suite;
	}

	public function assertions(): int {
		return $this->assertions;
	}

	public function metadata(?string $key=null, mixed $default=null): mixed {
		return $key===null ? $this->metadata : ($this->metadata[$key] ?? $default);
	}

	public function stableId(): string {
		return (string)($this->metadata['stable_id'] ?? '');
	}

	public function contract(): string {
		$contract=$this->metadata['contract'] ?? [];
		return is_array($contract) ? (string)($contract['name'] ?? '') : '';
	}

	public function contractVersion(): string {
		$contract=$this->metadata['contract'] ?? [];
		return is_array($contract) ? (string)($contract['version'] ?? '') : '';
	}

	/** @param array<string, mixed> $fixtures */
	public function setFixtures(array $fixtures): void {
		$this->fixtures=$fixtures;
	}

	public function fixture(string $name): mixed {
		if(!array_key_exists($name, $this->fixtures)){
			throw new AssertionFailed("Fixture '{$name}' is not available.");
		}
		return $this->fixtures[$name];
	}

	/** Registers a LIFO cleanup callback that runs even when the test fails. */
	public function cleanup(callable $cleanup): void {
		$this->deferred[]=Closure::fromCallable($cleanup);
	}

	/** Concise alias for cleanup(), useful when modeling resource acquisition. */
	public function defer(callable $cleanup): void {
		$this->cleanup($cleanup);
	}

	/** @internal Called by the test registry after the case body finishes. */
	public function runDeferred(): void {
		$errors=[];
		while(($cleanup=array_pop($this->deferred)) instanceof Closure){
			try{
				$cleanup();
			}catch(Throwable $throwable){
				$errors[]=$throwable;
			}
		}
		if($errors!==[]){
			throw new DeferredCleanupFailed($errors);
		}
	}

	public function skip(string $reason=''): never { throw new SkippedTest($reason!=='' ? $reason : 'Test skipped.'); }

	public function todo(string $reason=''): never { throw new SkippedTest($reason!=='' ? $reason : 'Test marked todo.', true); }

	public function fail(string $message, mixed $expected=null, mixed $actual=null, array $meta=[]): never { throw new AssertionFailed($message, $expected, $actual, $meta); }

	final protected function recordAssertion(): void {
		$this->assertions++;
	}
}
