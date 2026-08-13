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
use ReflectionFunction;
use Throwable;
use Traversable;

final class Registry {

	/** @var array<int, CaseDefinition> */
	private static array $cases=[];
	/** @var array<string, iterable<mixed>|Closure> */
	private static array $datasets=[];
	/** @var array<string, FixtureDefinition> */
	private static array $fixtures=[];
	/** @var array<int, Closure> */
	private static array $before_all=[];
	/** @var array<int, Closure> */
	private static array $before_all_ran=[];
	/** @var array<int, Closure> */
	private static array $before_each=[];
	/** @var array<int, Closure> */
	private static array $after_each=[];
	/** @var array<int, Closure> */
	private static array $after_all=[];
	private static ?SuiteDefinition $suite=null;

	public static function reset(): void {
		CoverageParts::reset();
		self::$cases=[];
		self::$datasets=[];
		self::$fixtures=[];
		self::$before_all=[];
		self::$before_all_ran=[];
		self::$before_each=[];
		self::$after_each=[];
		self::$after_all=[];
		self::$suite=null;
	}

	public static function suite(string $name=''): SuiteDefinition {
		return self::$suite=new SuiteDefinition($name);
	}

	public static function test(string $name, callable $body): CaseDefinition {
		$trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
		$case=new CaseDefinition($name, Closure::fromCallable($body), (string)($trace['file'] ?? ''), (int)($trace['line'] ?? 0));
		self::$suite?->applyTo($case);
		self::$cases[]=$case;
		return $case;
	}

	public static function dataset(string $name, iterable|Closure $rows): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Dataset name cannot be blank.');
		}
		self::$datasets[$name]=$rows instanceof Traversable ? Dataset::repeatable($rows) : $rows;
	}

	public static function fixture(string $name, callable $setup, ?callable $teardown=null): void {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Fixture name cannot be blank.');
		}
		self::$fixtures[$name]=new FixtureDefinition($name, Closure::fromCallable($setup), $teardown===null ? null : Closure::fromCallable($teardown));
	}

	public static function beforeEach(callable $callback): void {
		self::$before_each[]=Closure::fromCallable($callback);
	}

	public static function afterEach(callable $callback): void {
		self::$after_each[]=Closure::fromCallable($callback);
	}

	public static function beforeAll(callable $callback): void {
		self::$before_all[]=Closure::fromCallable($callback);
	}

	public static function afterAll(callable $callback): void {
		self::$after_all[]=Closure::fromCallable($callback);
	}

	/** @return array<int, array<string, mixed>> */
	public static function caseSummaries(?string $file=null): array {
		$summaries=[];
		foreach(self::expandedCases() as $index=>$case){
			$metadata=$case->metadata();
			$summaries[]=[
				'index'=>$index,
				'name'=>$case->name,
				'stable_id'=>$case->stable_id,
				'base_stable_id'=>$case->definition->stableIdValue(),
				'suite'=>$case->definition->suiteName(),
				'base_name'=>$case->definition->name,
				'dataset'=>$case->dataset,
				'file'=>$file ?? $case->definition->file,
				'line'=>$case->definition->line,
				'fixtures'=>$case->definition->fixtures(),
				'tags'=>$case->definition->tags(),
				'groups'=>$case->definition->groups(),
				'dependencies'=>$case->definition->dependencies(),
				'order'=>$case->definition->orderValue(),
				'max_millis'=>$case->definition->maxMillisValue(),
				'memory_limit'=>$case->definition->memoryLimitValue(),
				'coverage_memory_limit'=>$case->definition->coverageMemoryLimitValue(),
				'skipped'=>$case->definition->skipReason()!==null,
				'skip_reason'=>$case->definition->skipReason(),
				'todo'=>$case->definition->todoReason()!==null,
				'todo_reason'=>$case->definition->todoReason(),
				'only'=>$case->definition->isOnly(),
				'contract'=>$case->definition->contractValue(),
				'layer'=>$case->definition->layerValue()?->value,
				'risk'=>$case->definition->riskValue()->value,
				'watches'=>$case->definition->watchTargets(),
				'through'=>$case->definition->boundaries(),
				'rootpath_sandboxes'=>$case->definition->rootpathSandboxes(),
				'isolation'=>$case->definition->isolationValue()->value,
				'isolation_explicit'=>$case->definition->hasExplicitIsolation(),
				'repeat'=>$case->definition->repeatValue(),
				'repeat_index'=>$case->repeat_index,
				'repeat_total'=>$case->repeat_total,
				'issue_policy'=>$case->definition->issuePolicy()->value,
				'issues'=>$case->definition->declaredIssues(),
				'output_policy'=>$case->definition->outputPolicy()->value,
				'output_reason'=>$case->definition->outputReason(),
				'assertion_policy'=>$case->definition->assertionPolicy()->value,
				'zero_assertion_reason'=>$case->definition->zeroAssertionReason(),
				'lifecycle'=>[
					'isolation'=>$metadata['isolation'],
					'isolation_explicit'=>$metadata['isolation_explicit'],
					'rootpath_sandboxes'=>$metadata['rootpath_sandboxes'],
					'memory_limit'=>$metadata['memory_limit'],
					'coverage_memory_limit'=>$metadata['coverage_memory_limit'],
					'repeat_index'=>$case->repeat_index,
					'repeat_total'=>$case->repeat_total,
					'issue_policy'=>$metadata['issue_policy'],
					'output_policy'=>$metadata['output_policy'],
					'assertion_policy'=>$metadata['assertion_policy'],
				],
			];
		}
		return $summaries;
	}

	/** @return array<int, ExecutionCase> */
	public static function expandedCases(): array {
		$expanded=[];
		$cases=self::$cases;
		usort($cases, static fn(CaseDefinition $a, CaseDefinition $b): int=>[$a->orderValue(), $a->name] <=> [$b->orderValue(), $b->name]);
		foreach($cases as $case){
			$rows=self::caseDatasetRows($case);
			foreach($rows as $row){
				$label=(string)$row['label'];
				for($repeat=1; $repeat<=$case->repeatValue(); $repeat++){
					$name=$label==='' ? $case->name : $case->name.' ['.$label.']';
					if($case->repeatValue()>1){
						$name.=' {repeat '.$repeat.'/'.$case->repeatValue().'}';
					}
					$stable_id=$case->stableIdValue();
					if($label!==''){
						$stable_id.='.dataset.'.substr(hash('sha256', $label), 0, 12);
					}
					if($case->repeatValue()>1){
						$stable_id.='.repeat.'.$repeat;
					}
					$expanded[]=new ExecutionCase(
						$case,
						$name,
						$label,
						$row['arguments'],
						$repeat,
						$case->repeatValue(),
						$stable_id
					);
				}
			}
		}
		return $expanded;
	}

	/** @return array<string, mixed> */
	public static function run(int $index, ?string $file=null): array {
		$context=null;
		$lifecycle_started=false;
		return self::runCase($index, $file, true, $context, $lifecycle_started);
	}

	/**
	 * Runs selected cases inside one file lifecycle.
	 *
	 * @param list<int> $indexes
	 * @return list<array<string,mixed>>
	 */
	public static function runMany(array $indexes, ?string $file=null): array {
		$results=[];
		$cases=self::expandedCases();
		$lifecycle_started=false;
		$after_all_context=null;
		$last_started_result=null;
		foreach($indexes as $index){
			$context=null;
			$case_started=false;
			$results[]=self::runCase((int)$index, $file, false, $context, $case_started, $cases);
			if($case_started){
				$lifecycle_started=true;
				$after_all_context=$context;
				$last_started_result=array_key_last($results);
			}
		}
		if($lifecycle_started && $after_all_context instanceof Context && $last_started_result!==null){
			$failures=[];
			$issues=[];
			$started=microtime(true);
			$metadata=(array)$after_all_context->metadata();
			$output_level=ob_get_level();
			ob_start();
			$capture_issues=(string)($metadata['issue_policy'] ?? 'inherit')!=='inherit';
			if($capture_issues){
				set_error_handler(static function(int $severity, string $issue_message, string $issue_file, int $issue_line)use(&$issues): bool {
					if((error_reporting()&$severity)===0){
						return false;
					}
					$issues[]=[
						'code'=>$severity,
						'name'=>self::issueName($severity),
						'message'=>$issue_message,
						'file'=>$issue_file,
						'line'=>$issue_line,
					];
					return true;
				});
			}
			foreach(self::$after_all as $callback){
				try{
					self::invoke($callback, [$after_all_context]);
				}catch(Throwable $throwable){
					$failures[]=self::teardownFailure($throwable, 'after_all');
				}
			}
			if($capture_issues){
				restore_error_handler();
			}
			$output_disrupted=ob_get_level()<$output_level+1;
			while(ob_get_level()>$output_level+1){
				ob_end_clean();
			}
			$output=ob_get_level()===$output_level+1 ? (string)ob_get_clean() : '';
			$output_policy=(string)($metadata['output_policy'] ?? 'inherit');
			if($output_policy!=='forbid' && $output!==''){
				echo $output;
			}
			$last=$last_started_result;
			$results[$last]['execution_time']=(float)($results[$last]['execution_time'] ?? 0)+(microtime(true)-$started);
			if($failures!==[]){
				$failure=$failures[array_key_last($failures)];
				$results[$last]['passed']=false;
				$results[$last]['message']='Test teardown failed: '.$failure['message'];
				$results[$last]['details']['teardown']=$failure;
				$existing=(array)($results[$last]['details']['teardown_failures'] ?? []);
				$results[$last]['details']['teardown_failures']=array_merge($existing, $failures);
			}
			if($output_policy!=='inherit' && $output_disrupted){
				self::policyFailure($results[$last]['passed'], $results[$last]['message'], $results[$last]['details'], 'File teardown changed the output-buffer boundary.', [
					'policy'=>'output',
					'phase'=>'after_all',
				]);
			}elseif($output_policy==='forbid' && $output!==''){
				self::policyFailure($results[$last]['passed'], $results[$last]['message'], $results[$last]['details'], 'Test contract forbids output from file teardown.', [
					'policy'=>'output',
					'phase'=>'after_all',
					'captured_output'=>substr($output, -8192),
				]);
			}
			$issue_policy=(string)($metadata['issue_policy'] ?? 'inherit');
			if($issue_policy!=='inherit'){
				$declared=(array)($metadata['issues'] ?? []);
				$unexpected=[];
				if($issue_policy==='fail'){
					$unexpected=$issues;
				}elseif($declared!==[]){
					$unexpected=array_values(array_filter($issues, static fn(array $issue): bool=>!self::issueDeclared((int)$issue['code'], $declared)));
				}
				if($unexpected!==[]){
					self::policyFailure($results[$last]['passed'], $results[$last]['message'], $results[$last]['details'], 'File teardown PHP issue contract was violated.', [
						'policy'=>'issues',
						'phase'=>'after_all',
						'unexpected'=>$unexpected,
						'missing'=>[],
					]);
				}
			}
		}
		return $results;
	}

	/** @return array<string, mixed> */
	private static function runCase(int $index, ?string $file, bool $run_after_all, ?Context &$context_out, bool &$lifecycle_started, ?array $cases=null): array {
		$cases ??= self::expandedCases();
		if(!isset($cases[$index])){
			$lifecycle_started=false;
			return [
				'type'=>'code_unit_test',
				'test_name'=>'case #'.$index,
				'case_index'=>$index,
				'file'=>$file,
				'message'=>'Code-defined unit-test case index does not exist.',
				'passed'=>false,
			];
		}
		$case=$cases[$index];
		$context=new Context($case->name, $case->dataset, $file ?? $case->definition->file, $case->definition->suiteName(), $case->metadata());
		$context_out=$context;
		$lifecycle_started=false;
		$fixtures=[];
		$passed=false;
		$message='Code-defined unit test passed.';
		$details=[];
		$started=microtime(true);
		$skipped=false;
		$todo=false;
		$run_after_each=false;
		$teardown_errors=[];
		$captured_issues=[];
		$output_level=ob_get_level();
		ob_start();
		$captures_issues=$case->definition->issuePolicy()!==TestIssuePolicy::Inherit;
		if($captures_issues){
			set_error_handler(static function(int $severity, string $issue_message, string $issue_file, int $issue_line)use(&$captured_issues): bool {
				if((error_reporting()&$severity)===0){
					return false;
				}
				$captured_issues[]=[
					'code'=>$severity,
					'name'=>self::issueName($severity),
					'message'=>$issue_message,
					'file'=>$issue_file,
					'line'=>$issue_line,
				];
				return true;
			});
		}
		try{
			if($case->definition->todoReason()!==null){
				throw new SkippedTest($case->definition->todoReason() ?? 'Test marked todo.', true);
			}
			if($case->definition->skipReason()!==null){
				throw new SkippedTest($case->definition->skipReason() ?? 'Test skipped.');
			}
			$run_after_each=true;
			$lifecycle_started=true;
			foreach(self::$before_all as $callback_index=>$callback){
				if(isset(self::$before_all_ran[$callback_index])){
					continue;
				}
				self::invoke($callback, [$context]);
				self::$before_all_ran[$callback_index]=$callback;
			}
			foreach(self::$before_each as $callback){
				self::invoke($callback, [$context]);
			}
			foreach($case->definition->beforeEachCallbacks() as $callback){
				self::invoke($callback, [$context]);
			}
			foreach($case->definition->fixtures() as $fixture_name){
				if(!isset(self::$fixtures[$fixture_name])){
					throw new AssertionFailed("Fixture '{$fixture_name}' is not registered.");
				}
				$fixtures[$fixture_name]=self::invoke(self::$fixtures[$fixture_name]->setup, [$context]);
			}
			$context->setFixtures($fixtures);
			$result=self::invoke($case->definition->body, array_merge([$context], $case->arguments));
			if($result===false){
				throw new AssertionFailed('Test returned false.');
			}
			$passed=true;
		}catch(SkippedTest $skip){
			$skipped=true;
			$todo=$skip->isTodo();
			$passed=true;
			$message=$skip->getMessage();
		}catch(AssertionFailed $failure){
			$message=$failure->getMessage();
			$details=$failure->details();
		}catch(Throwable $throwable){
			$message=$throwable->getMessage();
			$details=[
				'exception'=>$throwable::class,
				'file'=>$throwable->getFile(),
				'line'=>$throwable->getLine(),
			];
		}
		foreach(array_reverse($case->definition->fixtures()) as $fixture_name){
			$fixture=self::$fixtures[$fixture_name] ?? null;
			if($fixture?->teardown===null){
				continue;
			}
			try{
				self::invoke($fixture->teardown, [$fixtures[$fixture_name] ?? null, $context]);
			}catch(Throwable $throwable){
				$teardown_errors[]=self::teardownFailure($throwable, 'fixture', $fixture_name);
				$passed=false;
			}
		}
		if($run_after_each===true){
			foreach(array_reverse($case->definition->afterEachCallbacks()) as $callback){
				try{
					self::invoke($callback, [$context]);
				}catch(Throwable $throwable){
					$teardown_errors[]=self::teardownFailure($throwable, 'suite_after_each', $case->definition->suiteName());
					$passed=false;
				}
			}
			foreach(self::$after_each as $callback){
				try{
					self::invoke($callback, [$context]);
				}catch(Throwable $throwable){
					$teardown_errors[]=self::teardownFailure($throwable, 'after_each');
					$passed=false;
				}
			}
			if($run_after_all){
				foreach(self::$after_all as $callback){
					try{
						self::invoke($callback, [$context]);
					}catch(Throwable $throwable){
						$teardown_errors[]=self::teardownFailure($throwable, 'after_all');
						$passed=false;
					}
				}
			}
		}
		try{
			$context->runDeferred();
		}catch(DeferredCleanupFailed $failure){
			foreach($failure->failures() as $throwable){
				$teardown_errors[]=self::teardownFailure($throwable, 'deferred');
			}
			$passed=false;
		}
		if($captures_issues){
			restore_error_handler();
		}
		$output_disrupted=ob_get_level()<$output_level+1;
		while(ob_get_level()>$output_level+1){
			ob_end_clean();
		}
		$captured_output=ob_get_level()===$output_level+1 ? (string)ob_get_clean() : '';
		if($case->definition->outputPolicy()!==TestOutputPolicy::Forbid && $captured_output!==''){
			echo $captured_output;
		}
		if($case->definition->assertionPolicy()===TestAssertionPolicy::Require && $context->assertions()===0 && !$skipped){
			self::policyFailure($passed, $message, $details, 'Test contract requires at least one assertion.', [
				'policy'=>'assertions',
				'assertions'=>0,
			]);
		}
		$output_policy=$case->definition->outputPolicy();
		if(!$skipped && $output_policy!==TestOutputPolicy::Inherit && $output_disrupted){
			self::policyFailure($passed, $message, $details, 'Test changed the output-buffer boundary, so its output contract could not be verified.', [
				'policy'=>'output',
				'output_policy'=>$output_policy->value,
			]);
		}elseif(!$skipped && $output_policy===TestOutputPolicy::Forbid && $captured_output!==''){
			self::policyFailure($passed, $message, $details, 'Test contract forbids output.', [
				'policy'=>'output',
				'captured_output'=>substr($captured_output, -8192),
			]);
		}elseif(!$skipped && $output_policy===TestOutputPolicy::Expect && $captured_output===''){
			self::policyFailure($passed, $message, $details, 'Test contract expected output but produced none.', [
				'policy'=>'output',
				'reason'=>$case->definition->outputReason(),
			]);
		}
		$issue_policy=$case->definition->issuePolicy();
		if(!$skipped && $issue_policy!==TestIssuePolicy::Inherit){
			$declared=$case->definition->declaredIssues();
			$unexpected=[];
			if($issue_policy===TestIssuePolicy::Fail){
				$unexpected=$captured_issues;
			}elseif($declared!==[]){
				$unexpected=array_values(array_filter($captured_issues, static fn(array $issue): bool=>!self::issueDeclared((int)$issue['code'], $declared)));
			}
			$missing=[];
			if($issue_policy===TestIssuePolicy::Expect){
				if($declared===[] && $captured_issues===[]){
					$missing[]=['name'=>'any PHP issue'];
				}else{
					foreach($declared as $expected_issue){
						if(!self::issueObserved((int)$expected_issue['code'], $captured_issues)){
							$missing[]=$expected_issue;
						}
					}
				}
			}
			if($unexpected!==[] || $missing!==[]){
				self::policyFailure($passed, $message, $details, 'Test PHP issue contract was violated.', [
					'policy'=>'issues',
					'issue_policy'=>$issue_policy->value,
					'unexpected'=>$unexpected,
					'missing'=>$missing,
				]);
			}
		}
		$execution_time=microtime(true)-$started;
		$max_millis=$case->definition->maxMillisValue();
		if($passed===true && $max_millis!==null && $execution_time * 1000 > $max_millis){
			$passed=false;
			$message='Execution time exceeded maxMillis threshold.';
			$details=[
				'expected_millis'=>$max_millis,
				'actual_millis'=>$execution_time * 1000,
			];
		}
		if($teardown_errors!==[]){
			$teardown_error=$teardown_errors[array_key_last($teardown_errors)];
			$message='Test teardown failed: '.$teardown_error['message'];
			$details['teardown']=$teardown_error;
			$details['teardown_failures']=$teardown_errors;
		}
		return [
			'type'=>'code_unit_test',
			'test_name'=>$case->name,
			'stable_id'=>$case->stable_id,
			'contract'=>$case->definition->contractValue(),
			'layer'=>$case->definition->layerValue()?->value,
			'risk'=>$case->definition->riskValue()->value,
			'isolation'=>$case->definition->isolationValue()->value,
			'isolation_explicit'=>$case->definition->hasExplicitIsolation(),
			'repeat_index'=>$case->repeat_index,
			'repeat_total'=>$case->repeat_total,
			'watches'=>$case->definition->watchTargets(),
			'through'=>$case->definition->boundaries(),
			'issue_policy'=>$case->definition->issuePolicy()->value,
			'output_policy'=>$case->definition->outputPolicy()->value,
			'assertion_policy'=>$case->definition->assertionPolicy()->value,
			'metadata'=>$case->metadata(),
			'suite'=>$case->definition->suiteName(),
			'case_index'=>$index,
			'dataset'=>$case->dataset,
			'file'=>$file ?? $case->definition->file,
			'line'=>$case->definition->line,
			'assertions'=>$context->assertions(),
			'execution_time'=>$execution_time,
			'message'=>$message,
			'details'=>$details,
			'skipped'=>$skipped,
			'todo'=>$todo,
			'passed'=>$passed,
		];
	}

	/** @return array<int, array{label:string, arguments:array<int, mixed>}> */
	private static function caseDatasetRows(CaseDefinition $case): array {
		if($case->datasets()===[]){
			return [['label'=>'', 'arguments'=>[]]];
		}
		$rows=[];
		foreach($case->datasets() as $dataset){
			foreach(self::normalizeRows(self::resolveDataset($dataset)) as $row){
				$rows[]=$row;
			}
		}
		return $rows!==[] ? $rows : [['label'=>'', 'arguments'=>[]]];
	}

	private static function resolveDataset(string|iterable|Closure $dataset): iterable {
		if(is_string($dataset)){
			if(!array_key_exists($dataset, self::$datasets)){
				throw new \InvalidArgumentException("Dataset '{$dataset}' is not registered.");
			}
			$dataset=self::$datasets[$dataset];
		}
		if($dataset instanceof Closure){
			$dataset=$dataset();
		}
		if(is_array($dataset) || $dataset instanceof Traversable){
			return $dataset;
		}
		throw new \InvalidArgumentException('Dataset must resolve to an array or Traversable value.');
	}

	/** @return array<int, array{label:string, arguments:array<int, mixed>}> */
	private static function normalizeRows(iterable $rows): array {
		$normalized=[];
		foreach($rows as $label=>$row){
			if(is_array($row) && self::isList($row)){
				$arguments=$row;
			}
			elseif(is_array($row))
			{
				$arguments=[$row];
			}
			else
			{
				$arguments=[$row];
			}
			$normalized[]=[
				'label'=>is_string($label) ? $label : (string)count($normalized),
				'arguments'=>$arguments,
			];
		}
		return $normalized;
	}

	/** @param array<int, mixed> $arguments */
	private static function invoke(Closure $callback, array $arguments): mixed {
		$reflection=new ReflectionFunction($callback);
		return $callback(...array_slice($arguments, 0, $reflection->getNumberOfParameters()));
	}

	/** @return array{phase:string,name:string,message:string,exception:class-string<Throwable>,file:string,line:int} */
	private static function teardownFailure(Throwable $throwable, string $phase, string $name=''): array {
		return [
			'phase'=>$phase,
			'name'=>$name,
			'message'=>$throwable->getMessage(),
			'exception'=>$throwable::class,
			'file'=>$throwable->getFile(),
			'line'=>$throwable->getLine(),
		];
	}

	/** @param array<string,mixed> $details @param array<string,mixed> $violation */
	private static function policyFailure(bool &$passed, string &$message, array &$details, string $policy_message, array $violation): void {
		if($passed){
			$message=$policy_message;
		}
		$passed=false;
		$details['policy_violations'][]=['message'=>$policy_message]+$violation;
	}

	/** @param list<array{code:int,name:string}> $declared */
	private static function issueDeclared(int $severity, array $declared): bool {
		foreach($declared as $issue){
			if((int)$issue['code']===E_ALL || (int)$issue['code']===$severity){
				return true;
			}
		}
		return false;
	}

	/** @param list<array<string,mixed>> $observed */
	private static function issueObserved(int $severity, array $observed): bool {
		if($severity===E_ALL){
			return $observed!==[];
		}
		foreach($observed as $issue){
			if((int)($issue['code'] ?? 0)===$severity){
				return true;
			}
		}
		return false;
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
		] as $name=>$known){
			if($known===$code){
				return $name;
			}
		}
		return 'E_'.$code;
	}

	/** @param array<mixed> $value */
	private static function isList(array $value): bool {
		if(function_exists('array_is_list')){
			return array_is_list($value);
		}
		return array_keys($value)===range(0, count($value)-1);
	}
}
