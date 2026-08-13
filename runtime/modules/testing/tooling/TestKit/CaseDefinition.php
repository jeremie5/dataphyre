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
use Traversable;

final class CaseDefinition {

	/** @var array<int, string|iterable<mixed>|Closure> */
	private array $datasets=[];
	/** @var array<int, string> */
	private array $fixture_names=[];
	/** @var array<int, string> */
	private array $tags=[];
	/** @var array<int, string> */
	private array $groups=[];
	/** @var array<int, string> */
	private array $dependencies=[];
	/** @var list<Closure> */
	private array $before_each=[];
	/** @var list<Closure> */
	private array $after_each=[];
	private ?int $max_millis=null;
	private ?string $memory_limit=null;
	private ?string $coverage_memory_limit=null;
	private int $order=0;
	private ?string $skip_reason=null;
	private ?string $todo_reason=null;
	private bool $only=false;
	private string $suite_name='';
	private ?string $explicit_id=null;
	private ?string $contract_name=null;
	private string $contract_version='1';
	private ?TestLayer $layer=null;
	private TestRisk $risk=TestRisk::Medium;
	/** @var list<string> */
	private array $watches=[];
	/** @var list<string> */
	private array $boundaries=[];
	/** @var list<string> */
	private array $rootpath_sandboxes=[];
	private TestIsolation $isolation=TestIsolation::CaseScope;
	private bool $isolation_explicit=false;
	private int $repeat=1;
	private TestIssuePolicy $issue_policy=TestIssuePolicy::Inherit;
	/** @var list<array{code:int,name:string}> */
	private array $issues=[];
	private TestOutputPolicy $output_policy=TestOutputPolicy::Inherit;
	private string $output_reason='';
	private TestAssertionPolicy $assertion_policy=TestAssertionPolicy::Inherit;
	private string $zero_assertion_reason='';

	public function __construct(public string $name, public Closure $body, public string $file='', public int $line=0) {}

	public function with(string|iterable|Closure $dataset): self {
		$this->datasets[]=$dataset instanceof Traversable ? Dataset::repeatable($dataset) : $dataset;
		return $this;
	}

	/** @internal Applied by SuiteDefinition when the case is registered. */
	public function suite(string $name): self {
		$this->suite_name=trim($name);
		return $this;
	}

	public function uses(string ...$fixtures): self {
		foreach($fixtures as $fixture){
			$fixture=trim($fixture);
			if($fixture!=='' && !in_array($fixture, $this->fixture_names, true)){
				$this->fixture_names[]=$fixture;
			}
		}
		return $this;
	}

	public function tag(string ...$tags): self {
		foreach($tags as $tag){
			$tag=trim($tag);
			if($tag!=='' && !in_array($tag, $this->tags, true)){
				$this->tags[]=$tag;
			}
		}
		return $this;
	}

	public function group(string ...$groups): self {
		foreach($groups as $group){
			$group=trim($group);
			if($group!=='' && !in_array($group, $this->groups, true)){
				$this->groups[]=$group;
			}
		}
		return $this;
	}

	public function dependsOn(string ...$tests): self {
		foreach($tests as $test){
			$test=trim($test);
			if($test!=='' && !in_array($test, $this->dependencies, true)){
				$this->dependencies[]=$test;
			}
		}
		return $this;
	}

	public function order(int $order): self {
		$this->order=$order;
		return $this;
	}

	public function skip(string $reason=''): self {
		$this->skip_reason=$reason!=='' ? $reason : 'Test skipped.';
		return $this;
	}

	public function todo(string $reason=''): self {
		$this->todo_reason=$reason!=='' ? $reason : 'Test marked todo.';
		return $this;
	}

	public function only(): self {
		$this->only=true;
		return $this;
	}

	public function skipIf(mixed $condition, string $reason=''): self {
		$should_skip=$condition instanceof Closure ? (bool)$condition() : (bool)$condition;
		return $should_skip ? $this->skip($reason) : $this;
	}

	public function skipUnless(mixed $condition, string $reason=''): self {
		$should_run=$condition instanceof Closure ? (bool)$condition() : (bool)$condition;
		return $should_run ? $this : $this->skip($reason);
	}

	public function maxMillis(int $milliseconds): self {
		$this->max_millis=max(1, $milliseconds);
		return $this;
	}

	/** Declares the PHP memory ceiling required by this case's worker. */
	public function memoryLimit(string $limit): self {
		$this->memory_limit=TestMemoryLimit::normalize($limit);
		return $this;
	}

	/** Declares the PHP ceiling used only when exact coverage instruments this case. */
	public function coverageMemoryLimit(string $limit): self {
		$this->coverage_memory_limit=TestMemoryLimit::normalize($limit);
		return $this;
	}

	public function id(string $stable_id): self {
		$stable_id=trim($stable_id);
		if(preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/', $stable_id)!==1){
			throw new \InvalidArgumentException('Stable test IDs must be non-blank identifiers without whitespace.');
		}
		$this->explicit_id=$stable_id;
		return $this;
	}

	public function contract(string $name, string|int $version='1'): self {
		$name=trim($name);
		$version=trim((string)$version);
		if($name==='' || $version===''){
			throw new \InvalidArgumentException('Test contracts need a non-blank name and version.');
		}
		$this->contract_name=$name;
		$this->contract_version=$version;
		return $this;
	}

	public function layer(TestLayer|string $layer): self {
		$this->layer=self::enumValue(TestLayer::class, $layer, 'test layer');
		return $this;
	}

	public function risk(TestRisk|string $risk): self {
		$this->risk=self::enumValue(TestRisk::class, $risk, 'test risk');
		return $this;
	}

	public function watches(string ...$targets): self {
		foreach($targets as $target){
			$target=trim($target);
			if($target!=='' && !in_array($target, $this->watches, true)){
				$this->watches[]=$target;
			}
		}
		return $this;
	}

	/** Declares the observable boundaries crossed by the contract in order. */
	public function through(string ...$boundaries): self {
		foreach($boundaries as $boundary){
			$boundary=trim($boundary);
			if($boundary!=='' && !in_array($boundary, $this->boundaries, true)){
				$this->boundaries[]=$boundary;
			}
		}
		return $this;
	}

	/** Gives this case disposable replacements for the named ROOTPATH keys. */
	public function sandboxesRootpath(string ...$keys): self {
		foreach(RootpathSandbox::normalizeDeclaredKeys($keys) as $key){
			if(!in_array($key, $this->rootpath_sandboxes, true)){
				$this->rootpath_sandboxes[]=$key;
			}
		}
		return $this;
	}

	public function isolation(TestIsolation|string $isolation): self {
		$this->isolation=self::enumValue(TestIsolation::class, $isolation, 'test isolation');
		$this->isolation_explicit=true;
		return $this;
	}

	public function repeat(int $times): self {
		if($times<1){
			throw new \InvalidArgumentException('Test repeat count must be at least one.');
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
		$this->issues=self::normalizeIssues($issues);
		return $this;
	}

	public function expectsIssues(int|string ...$issues): self {
		$this->issue_policy=TestIssuePolicy::Expect;
		$this->issues=self::normalizeIssues($issues);
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

	/** @internal Captured from the active SuiteDefinition. */
	public function beforeEach(callable $callback): self {
		$this->before_each[]=Closure::fromCallable($callback);
		return $this;
	}

	/** @internal Captured from the active SuiteDefinition. */
	public function afterEach(callable $callback): self {
		$this->after_each[]=Closure::fromCallable($callback);
		return $this;
	}

	/** @return array<int, string> */
	public function fixtures(): array {
		return $this->fixture_names;
	}

	/** @return array<int, string> */
	public function tags(): array {
		return $this->tags;
	}

	/** @return array<int, string> */
	public function groups(): array {
		return $this->groups;
	}

	/** @return array<int, string> */
	public function dependencies(): array {
		return $this->dependencies;
	}

	public function maxMillisValue(): ?int {
		return $this->max_millis;
	}

	public function memoryLimitValue(): ?string {
		return $this->memory_limit;
	}

	public function coverageMemoryLimitValue(): ?string {
		return $this->coverage_memory_limit;
	}

	public function orderValue(): int {
		return $this->order;
	}

	public function skipReason(): ?string {
		return $this->skip_reason;
	}

	public function todoReason(): ?string {
		return $this->todo_reason;
	}

	public function isOnly(): bool {
		return $this->only;
	}

	public function stableIdValue(): string {
		if($this->explicit_id!==null){
			return $this->explicit_id;
		}
		$file=str_replace('\\', '/', $this->file);
		$scope=$this->suite_name;
		if(preg_match('#/runtime/modules/([^/]+)/#', '/'.ltrim($file, '/'), $match)===1){
			$scope='module:'.$match[1].'|'.$scope;
		}
		$contract=$this->contract_name===null ? '' : $this->contract_name.'@'.$this->contract_version;
		return 'test.'.substr(hash('sha256', $scope."\0".$this->name."\0".$contract), 0, 24);
	}

	/** @return array{name:string,version:string}|null */
	public function contractValue(): ?array {
		return $this->contract_name===null ? null : ['name'=>$this->contract_name, 'version'=>$this->contract_version];
	}

	public function layerValue(): ?TestLayer {
		return $this->layer;
	}

	public function riskValue(): TestRisk {
		return $this->risk;
	}

	/** @return list<string> */
	public function watchTargets(): array {
		return $this->watches;
	}

	/** @return list<string> */
	public function boundaries(): array {
		return $this->boundaries;
	}

	/** @return list<string> */
	public function rootpathSandboxes(): array {
		return $this->rootpath_sandboxes;
	}

	public function isolationValue(): TestIsolation {
		return $this->isolation;
	}

	public function hasExplicitIsolation(): bool {
		return $this->isolation_explicit;
	}

	public function repeatValue(): int {
		return $this->repeat;
	}

	public function issuePolicy(): TestIssuePolicy {
		return $this->issue_policy;
	}

	/** @return list<array{code:int,name:string}> */
	public function declaredIssues(): array {
		return $this->issues;
	}

	public function outputPolicy(): TestOutputPolicy {
		return $this->output_policy;
	}

	public function outputReason(): string {
		return $this->output_reason;
	}

	public function assertionPolicy(): TestAssertionPolicy {
		return $this->assertion_policy;
	}

	public function zeroAssertionReason(): string {
		return $this->zero_assertion_reason;
	}

	/** @return array<string,mixed> */
	public function metadata(): array {
		return [
			'base_stable_id'=>$this->stableIdValue(),
			'contract'=>$this->contractValue(),
			'layer'=>$this->layer?->value,
			'risk'=>$this->risk->value,
			'watches'=>$this->watches,
			'through'=>$this->boundaries,
			'rootpath_sandboxes'=>$this->rootpath_sandboxes,
			'memory_limit'=>$this->memory_limit,
			'coverage_memory_limit'=>$this->coverage_memory_limit,
			'isolation'=>$this->isolation->value,
			'isolation_explicit'=>$this->isolation_explicit,
			'repeat'=>$this->repeat,
			'issue_policy'=>$this->issue_policy->value,
			'issues'=>$this->issues,
			'output_policy'=>$this->output_policy->value,
			'output_reason'=>$this->output_reason,
			'assertion_policy'=>$this->assertion_policy->value,
			'zero_assertion_reason'=>$this->zero_assertion_reason,
		];
	}

	/** @return array<int, string|iterable<mixed>|Closure> */
	public function datasets(): array {
		return $this->datasets;
	}

	public function suiteName(): string {
		return $this->suite_name;
	}

	/** @return list<Closure> */
	public function beforeEachCallbacks(): array {
		return $this->before_each;
	}

	/** @return list<Closure> */
	public function afterEachCallbacks(): array {
		return $this->after_each;
	}

	/** @return TestLayer|TestRisk|TestIsolation */
	private static function enumValue(string $enum, \BackedEnum|string $value, string $description): TestLayer|TestRisk|TestIsolation {
		if($value instanceof $enum){
			return $value;
		}
		try{
			return $enum::from(strtolower(trim((string)$value)));
		}catch(\ValueError $failure){
			throw new \InvalidArgumentException("Unknown {$description} '{$value}'.", 0, $failure);
		}
	}

	/** @param array<int,int|string> $issues @return list<array{code:int,name:string}> */
	private static function normalizeIssues(array $issues): array {
		$normalized=[];
		foreach($issues as $issue){
			if(is_string($issue)){
				$name=strtoupper(trim($issue));
				if($name==='' || !defined($name) || !is_int(constant($name)) || !str_starts_with($name, 'E_')){
					throw new \InvalidArgumentException("Unknown PHP issue type '{$issue}'.");
				}
				$code=(int)constant($name);
			}else{
				$code=$issue;
				$name=self::issueName($code);
			}
			$key=$code.':'.$name;
			$normalized[$key]=['code'=>$code, 'name'=>$name];
		}
		return array_values($normalized);
	}

	private static function issueName(int $code): string {
		foreach([
			'E_ERROR'=>E_ERROR,
			'E_WARNING'=>E_WARNING,
			'E_PARSE'=>E_PARSE,
			'E_NOTICE'=>E_NOTICE,
			'E_CORE_ERROR'=>E_CORE_ERROR,
			'E_CORE_WARNING'=>E_CORE_WARNING,
			'E_COMPILE_ERROR'=>E_COMPILE_ERROR,
			'E_COMPILE_WARNING'=>E_COMPILE_WARNING,
			'E_USER_ERROR'=>E_USER_ERROR,
			'E_USER_WARNING'=>E_USER_WARNING,
			'E_USER_NOTICE'=>E_USER_NOTICE,
			'E_STRICT'=>2048,
			'E_RECOVERABLE_ERROR'=>E_RECOVERABLE_ERROR,
			'E_DEPRECATED'=>E_DEPRECATED,
			'E_USER_DEPRECATED'=>E_USER_DEPRECATED,
			'E_ALL'=>E_ALL,
		] as $name=>$known){
			if($known===$code){
				return $name;
			}
		}
		throw new \InvalidArgumentException("Unknown PHP issue code '{$code}'.");
	}
}
