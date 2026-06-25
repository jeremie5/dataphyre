<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Immutable aggregate result for a batch of SQL mutations.
 *
 * MutationBatchResult records the intended operation, how many mutations were
 * requested, and the MutationResult objects produced by execution. It performs no
 * SQL itself; its job is to expose batch accounting, failure summaries, iteration,
 * and stable JSON data for APIs and diagnostics.
 */
final class MutationBatchResult implements \Countable, \IteratorAggregate, \JsonSerializable {

	/** @var array<int, MutationResult> */
	private array $results;

	/** @var array<string, mixed>|null */
	private ?array $serialized=null;

	private ?int $successfulCount=null;

	/**
	 * Creates a batch result and normalizes child results.
	 *
	 * Non-MutationResult values are discarded so callers can pass partially built
	 * result lists without corrupting counters or serialization.
	 *
	 * @param string $operation Mutation operation name, such as insert, update, delete, or upsert.
	 * @param array<int, mixed> $results Candidate per-row mutation results.
	 * @param int $requested Number of mutations the caller attempted to process.
	 */
	public function __construct(
		private readonly string $operation,
		array $results,
		private readonly int $requested
	){
		$normalized=[];
		foreach($results as $result){
			if($result instanceof MutationResult){
				$normalized[]=$result;
			}
		}
		$this->results=$normalized;
	}

	/**
	 * Returns the mutation operation represented by this batch.
	 *
	 * @return string Operation name supplied by the caller.
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Returns normalized per-mutation results.
	 *
	 * @return array<int, MutationResult> Results preserved in batch order.
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * Returns how many mutations were requested.
	 *
	 * @return int Intended batch size before filtering/processing.
	 */
	public function requested(): int {
		return $this->requested;
	}

	/**
	 * Returns how many mutation results were recorded.
	 *
	 * @return int Count of normalized MutationResult objects.
	 */
	public function processed(): int {
		return count($this->results);
	}

	/**
	 * Counts successful child mutations.
	 *
	 * @return int Number of child results whose ok() state is true.
	 */
	public function successful(): int {
		if($this->successfulCount!==null){
			return $this->successfulCount;
		}
		$count=0;
		foreach($this->results as $result){
			if($result->ok()){
				$count++;
			}
		}
		return $this->successfulCount=$count;
	}

	/**
	 * Counts failed or missing child mutations.
	 *
	 * Failed count is derived from processed minus successful. A batch can still be
	 * failed when processed is lower than requested, even if no child result reports
	 * an explicit failure.
	 *
	 * @return int Number of processed child results that failed.
	 */
	public function failedCount(): int {
		return $this->processed()-$this->successful();
	}

	/**
	 * Returns non-empty error messages from failed child results.
	 *
	 * @return array<int, string> Failure messages in result order.
	 */
	public function errorMessages(): array {
		$messages=[];
		foreach($this->results as $result){
			if(!$result->failed()){
				continue;
			}
			$message=$result->errorMessage();
			if($message!==null && trim($message)!==''){
				$messages[]=$message;
			}
		}
		return $messages;
	}

	/**
	 * Returns the first child failure message.
	 *
	 * @return string|null First non-empty error message, or null when none were recorded.
	 */
	public function firstErrorMessage(): ?string {
		foreach($this->results as $result){
			if(!$result->failed()){
				continue;
			}
			$message=$result->errorMessage();
			if($message!==null && trim($message)!==''){
				return $message;
			}
		}
		return null;
	}

	/**
	 * Reports whether the entire batch completed successfully.
	 *
	 * A batch is ok only when every requested mutation produced a result and no
	 * processed child mutation failed.
	 *
	 * @return bool True when requested, processed, and successful counts all match.
	 */
	public function ok(): bool {
		if(count($this->results)!==$this->requested){
			return false;
		}
		foreach($this->results as $result){
			if($result->failed()){
				return false;
			}
		}
		return true;
	}

	/**
	 * Reports whether the batch has any missing or failed mutations.
	 *
	 * @return bool True when ok() is false.
	 */
	public function failed(): bool {
		return !$this->ok();
	}

	/**
	 * Reports whether the caller requested an empty batch.
	 *
	 * @return bool True when requested() is zero.
	 */
	public function noop(): bool {
		return $this->requested===0;
	}

	/**
	 * Counts processed mutation results.
	 *
	 * @return int Same value as processed().
	 */
	public function count(): int {
		return count($this->results);
	}

	/**
	 * Iterates over child mutation results.
	 *
	 * @return \Traversable<int, MutationResult> Iterator preserving result order.
	 */
	public function getIterator(): \Traversable {
		return new \ArrayIterator($this->results);
	}

	/**
	 * Serializes the batch outcome for APIs and diagnostics.
	 *
	 * @return array<string, mixed> Operation, status flags, counters, error messages, and child result data.
	 */
	public function jsonSerialize(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		$successful=0;
		$errorMessages=[];
		$results=[];
		foreach($this->results as $result){
			if($result->ok()){
				$successful++;
			}elseif(($message=$result->errorMessage())!==null && trim($message)!==''){
				$errorMessages[]=$message;
			}
			$results[]=$result->jsonSerialize();
		}
		$processed=count($this->results);
		$failed=$processed - $successful;
		$this->successfulCount=$successful;
		return $this->serialized=[
			'operation'=>$this->operation,
			'ok'=>$processed===$this->requested && $failed===0,
			'requested'=>$this->requested,
			'processed'=>$processed,
			'successful'=>$successful,
			'failed'=>$failed,
			'noop'=>$this->noop(),
			'error_messages'=>$errorMessages,
			'results'=>$results,
		];
	}
}
