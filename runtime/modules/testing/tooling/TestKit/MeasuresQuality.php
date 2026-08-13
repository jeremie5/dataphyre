<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Throwable;

/** Owns the Context capabilities described by its name. */
trait MeasuresQuality {

	public function benchmark(callable $callback, int $iterations=1, int $warmup=0): BenchmarkResult {
		$iterations=max(1, $iterations);
		for($i=0; $i<$warmup; $i++){
			$callback();
		}
		$memory_before=memory_get_usage(true);
		$peak_before=memory_get_peak_usage(true);
		$durations=[];
		for($i=0; $i<$iterations; $i++){
			$started=hrtime(true);
			$callback();
			$durations[]=(hrtime(true)-$started)/1000000;
		}
		return new BenchmarkResult($durations, memory_get_usage(true)-$memory_before, max(0, memory_get_peak_usage(true)-$peak_before));
	}

	public function performanceUnder(BenchmarkResult|callable $benchmark, int|float $max_millis, ?int $iterations=null, string $message=''): BenchmarkResult {
		$result=$benchmark instanceof BenchmarkResult ? $benchmark : $this->benchmark($benchmark, $iterations ?? 1);
		$this->recordAssertion();
		if($result->maxMillis()>$max_millis){
			$this->fail($message!=='' ? $message : 'Expected benchmark max duration to stay under threshold.', $max_millis, $result->maxMillis(), ['benchmark'=>$result->toArray()]);
		}
		return $result;
	}

	public function memoryUnder(callable $callback, int $max_bytes, string $message=''): void {
		$this->recordAssertion();
		$before=memory_get_usage(true);
		$callback();
		$used=max(0, memory_get_usage(true)-$before);
		if($used>$max_bytes){
			$this->fail($message!=='' ? $message : 'Expected memory delta to stay under threshold.', $max_bytes, $used);
		}
	}

	public function forAll(iterable $cases, callable $assertion, int $limit=100): void {
		$index=0;
		foreach($cases as $label=>$case){
			if($index++>=$limit){
				break;
			}
			try{
				$args=is_array($case) && self::isListValue($case) ? $case : [$case];
				$assertion($this, ...$args);
				$this->recordAssertion();
			}catch(Throwable $throwable){
				$this->fail('Property failed for generated case '.$label.'.', 'property holds', ['case'=>$case, 'error'=>$throwable->getMessage()]);
			}
		}
	}

	public function fuzz(GeneratedCases $cases, callable $assertion): void {
		$replay=(string)(getenv('DATAPHYRE_FUZZ_REPLAY') ?: '');
		$rows=$replay!=='' ? $cases->replay($replay, true) : $cases;
		foreach($rows as $label=>$case){
			try{
				$args=is_array($case) && self::isListValue($case) ? $case : [$case];
				$assertion($this, ...$args);
				$this->recordAssertion();
			}catch(Throwable $throwable){
				$shrink=$cases->shrinkResult($case, $assertion, $this);
				$replay_token=$cases->replayToken('shrunk:'.(string)$label, $shrink->minimal);
				$corpus_id=null;
				$corpus_path=(string)(getenv('DATAPHYRE_FUZZ_CORPUS') ?: '');
				if($corpus_path!==''){
					$contract=$this->contract()!=='' ? $this->contract() : ($this->stableId()!=='' ? $this->stableId() : $this->name());
					$corpus_id=FailureCorpus::open($corpus_path)->recordReplay($contract, $replay_token, [
						'test'=>$this->name(),
						'stable_id'=>$this->stableId(),
						'seed'=>$cases->seed(),
					]);
				}
				$this->fail('Fuzz property failed for generated case '.$label.'.', 'property holds', [
					'case'=>$case,
					'shrunk'=>$shrink->minimal,
					'shrink'=>$shrink->toArray(),
					'error'=>$throwable->getMessage(),
				], [
					'seed'=>$cases->seed(),
					'generator'=>$cases->fingerprint(),
					'replay'=>$replay_token,
					'original_replay'=>$cases->replayToken($label, $case),
					'corpus_id'=>$corpus_id,
				]);
			}
		}
	}

	public function hasConsistentSerialization(object $value, mixed $expected=null, string $message=''): void {
		if(!is_callable([$value, 'toArray']) || !$value instanceof \JsonSerializable){
			$this->fail($message!=='' ? $message : 'Expected value to expose toArray() and JsonSerializable.', 'toArray()+JsonSerializable', $value::class);
		}
		$array=$value->toArray();
		if(func_num_args()>=2){
			$this->same($expected, $array, $message);
		}
		$this->same($array, $value->jsonSerialize(), $message!=='' ? $message : 'Expected JSON serialization to match toArray().');
	}
}
