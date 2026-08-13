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

/**
 * File-level defaults for a cohesive test suite. The declaration doubles as a
 * readable summary of the framework modules and concerns covered by the file.
 */
final class SuiteDefinition {

	/** @var list<string> */
	private array $fixtures=[];
	/** @var list<string> */
	private array $tags=[];
	/** @var list<string> */
	private array $groups=[];
	/** @var list<Closure> */
	private array $before_each=[];
	/** @var list<Closure> */
	private array $after_each=[];
	/** @var list<string> */
	private array $watches=[];
	/** @var list<string> */
	private array $boundaries=[];
	/** @var list<string> */
	private array $rootpath_sandboxes=[];
	/** @var list<int|string> */
	private array $issues=[];
	private ?int $max_millis=null;
	private ?string $memory_limit=null;
	private ?string $coverage_memory_limit=null;
	private ?array $contract=null;
	private ?TestLayer $layer=null;
	private ?TestRisk $risk=null;
	private ?TestIsolation $isolation=null;
	private ?int $repeat=null;
	private ?TestIssuePolicy $issue_policy=null;
	private ?TestOutputPolicy $output_policy=null;
	private string $output_reason='';
	private ?TestAssertionPolicy $assertion_policy=null;
	private string $zero_assertion_reason='';

	public function __construct(private string $name='') {}

	public function framework(array|string $modules=[], array $options=[]): self {
		Framework::boot($modules, $options);
		return $this;
	}

	public function uses(string ...$fixtures): self {
		$this->fixtures=self::mergeNames($this->fixtures, $fixtures);
		return $this;
	}

	public function tag(string ...$tags): self {
		$this->tags=self::mergeNames($this->tags, $tags);
		return $this;
	}

	public function group(string ...$groups): self {
		$this->groups=self::mergeNames($this->groups, $groups);
		return $this;
	}

	public function maxMillis(int $milliseconds): self {
		$this->max_millis=max(1, $milliseconds);
		return $this;
	}

	/** Declares the PHP memory ceiling required by each case in this suite. */
	public function memoryLimit(string $limit): self {
		$this->memory_limit=TestMemoryLimit::normalize($limit);
		return $this;
	}

	/** Declares the higher PHP ceiling needed only while exact coverage is active. */
	public function coverageMemoryLimit(string $limit): self {
		$this->coverage_memory_limit=TestMemoryLimit::normalize($limit);
		return $this;
	}

	public function contract(string $name, string|int $version='1'): self {
		$name=trim($name);
		$version=trim((string)$version);
		if($name==='' || $version===''){
			throw new \InvalidArgumentException('Suite contracts need a non-blank name and version.');
		}
		$this->contract=['name'=>$name, 'version'=>$version];
		return $this;
	}

	public function layer(TestLayer|string $layer): self {
		$this->layer=$layer instanceof TestLayer ? $layer : TestLayer::tryFrom(strtolower(trim($layer)));
		if($this->layer===null){
			throw new \InvalidArgumentException("Unknown test layer '{$layer}'.");
		}
		return $this;
	}

	public function risk(TestRisk|string $risk): self {
		$this->risk=$risk instanceof TestRisk ? $risk : TestRisk::tryFrom(strtolower(trim($risk)));
		if($this->risk===null){
			throw new \InvalidArgumentException("Unknown test risk '{$risk}'.");
		}
		return $this;
	}

	public function watches(string ...$targets): self {
		$this->watches=self::mergeNames($this->watches, $targets);
		return $this;
	}

	/** Declares the observable boundaries crossed by the contract in order. */
	public function through(string ...$boundaries): self {
		$this->boundaries=self::mergeNames($this->boundaries, $boundaries);
		return $this;
	}

	/** Gives each worker disposable replacements for the named ROOTPATH keys. */
	public function sandboxesRootpath(string ...$keys): self {
		$this->rootpath_sandboxes=self::mergeNames($this->rootpath_sandboxes, RootpathSandbox::normalizeDeclaredKeys($keys));
		return $this;
	}

	public function isolation(TestIsolation|string $isolation): self {
		$this->isolation=$isolation instanceof TestIsolation ? $isolation : TestIsolation::tryFrom(strtolower(trim($isolation)));
		if($this->isolation===null){
			throw new \InvalidArgumentException("Unknown test isolation '{$isolation}'.");
		}
		return $this;
	}

	public function repeat(int $times): self {
		if($times<1){
			throw new \InvalidArgumentException('Suite repeat count must be at least one.');
		}
		$this->repeat=$times;
		return $this;
	}

	public function strictIssues(): self {
		$this->issue_policy=TestIssuePolicy::Fail;
		$this->issues=[];
		return $this;
	}

	public function allowsIssues(int|string ...$issues): self {
		$this->issue_policy=TestIssuePolicy::Allow;
		$this->issues=$issues;
		return $this;
	}

	public function expectsIssues(int|string ...$issues): self {
		$this->issue_policy=TestIssuePolicy::Expect;
		$this->issues=$issues;
		return $this;
	}

	public function forbidsOutput(): self {
		$this->output_policy=TestOutputPolicy::Forbid;
		$this->output_reason='';
		return $this;
	}

	public function allowsOutput(string $reason): self {
		if(trim($reason)===''){
			throw new \InvalidArgumentException('Allowed test output needs a reason.');
		}
		$this->output_policy=TestOutputPolicy::Allow;
		$this->output_reason=trim($reason);
		return $this;
	}

	public function expectsOutput(string $reason='expected output contract'): self {
		$this->output_policy=TestOutputPolicy::Expect;
		$this->output_reason=trim($reason)!=='' ? trim($reason) : 'expected output contract';
		return $this;
	}

	public function requiresAssertions(): self {
		$this->assertion_policy=TestAssertionPolicy::Require;
		$this->zero_assertion_reason='';
		return $this;
	}

	public function allowsNoAssertions(string $reason): self {
		if(trim($reason)===''){
			throw new \InvalidArgumentException('A zero-assertion test needs a reason.');
		}
		$this->assertion_policy=TestAssertionPolicy::AllowNone;
		$this->zero_assertion_reason=trim($reason);
		return $this;
	}

	public function beforeEach(callable $callback): self {
		$this->before_each[]=Closure::fromCallable($callback);
		return $this;
	}

	public function afterEach(callable $callback): self {
		$this->after_each[]=Closure::fromCallable($callback);
		return $this;
	}

	public function applyTo(CaseDefinition $case): void {
		$case->suite($this->name);
		if($this->fixtures!==[]){
			$case->uses(...$this->fixtures);
		}
		if($this->tags!==[]){
			$case->tag(...$this->tags);
		}
		if($this->groups!==[]){
			$case->group(...$this->groups);
		}
		if($this->max_millis!==null){
			$case->maxMillis($this->max_millis);
		}
		if($this->memory_limit!==null){
			$case->memoryLimit($this->memory_limit);
		}
		if($this->coverage_memory_limit!==null){
			$case->coverageMemoryLimit($this->coverage_memory_limit);
		}
		if($this->contract!==null){
			$case->contract((string)$this->contract['name'], (string)$this->contract['version']);
		}
		if($this->layer!==null){
			$case->layer($this->layer);
		}
		if($this->risk!==null){
			$case->risk($this->risk);
		}
		if($this->watches!==[]){
			$case->watches(...$this->watches);
		}
		if($this->boundaries!==[]){
			$case->through(...$this->boundaries);
		}
		if($this->rootpath_sandboxes!==[]){
			$case->sandboxesRootpath(...$this->rootpath_sandboxes);
		}
		if($this->isolation!==null){
			$case->isolation($this->isolation);
		}
		if($this->repeat!==null){
			$case->repeat($this->repeat);
		}
		if($this->issue_policy===TestIssuePolicy::Fail){
			$case->strictIssues();
		}elseif($this->issue_policy===TestIssuePolicy::Allow){
			$case->allowsIssues(...$this->issues);
		}elseif($this->issue_policy===TestIssuePolicy::Expect){
			$case->expectsIssues(...$this->issues);
		}
		if($this->output_policy===TestOutputPolicy::Forbid){
			$case->forbidsOutput();
		}elseif($this->output_policy===TestOutputPolicy::Allow){
			$case->allowsOutput($this->output_reason);
		}elseif($this->output_policy===TestOutputPolicy::Expect){
			$case->expectsOutput($this->output_reason);
		}
		if($this->assertion_policy===TestAssertionPolicy::Require){
			$case->requiresAssertions();
		}elseif($this->assertion_policy===TestAssertionPolicy::AllowNone){
			$case->allowsNoAssertions($this->zero_assertion_reason);
		}
		foreach($this->before_each as $callback){
			$case->beforeEach($callback);
		}
		foreach($this->after_each as $callback){
			$case->afterEach($callback);
		}
	}

	/** @param list<string> $current @param array<int,string> $incoming @return list<string> */
	private static function mergeNames(array $current, array $incoming): array {
		foreach($incoming as $name){
			$name=trim($name);
			if($name!=='' && !in_array($name, $current, true)){
				$current[]=$name;
			}
		}
		return $current;
	}
}
