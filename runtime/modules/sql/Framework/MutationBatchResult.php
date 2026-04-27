<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class MutationBatchResult implements \Countable, \IteratorAggregate, \JsonSerializable {

	/** @var array<int, MutationResult> */
	private array $results;

	public function __construct(
		private readonly string $operation,
		array $results,
		private readonly int $requested
	){
		$this->results=array_values(array_filter(
			$results,
			static fn(mixed $result): bool => $result instanceof MutationResult
		));
	}

	public function operation(): string {
		return $this->operation;
	}

	/** @return array<int, MutationResult> */
	public function results(): array {
		return $this->results;
	}

	public function requested(): int {
		return $this->requested;
	}

	public function processed(): int {
		return count($this->results);
	}

	public function successful(): int {
		return count(array_filter(
			$this->results,
			static fn(MutationResult $result): bool => $result->ok()
		));
	}

	public function failedCount(): int {
		return $this->processed()-$this->successful();
	}

	public function errorMessages(): array {
		return array_values(array_filter(array_map(
			static fn(MutationResult $result): ?string => $result->failed() ? $result->errorMessage() : null,
			$this->results
		), static fn(?string $message): bool => $message!==null && trim($message)!==''));
	}

	public function firstErrorMessage(): ?string {
		$messages=$this->errorMessages();
		return $messages[0] ?? null;
	}

	public function ok(): bool {
		return $this->processed()===$this->requested && $this->failedCount()===0;
	}

	public function failed(): bool {
		return !$this->ok();
	}

	public function noop(): bool {
		return $this->requested===0;
	}

	public function count(): int {
		return count($this->results);
	}

	public function getIterator(): \Traversable {
		return new \ArrayIterator($this->results);
	}

	public function jsonSerialize(): array {
		return [
			'operation'=>$this->operation,
			'ok'=>$this->ok(),
			'requested'=>$this->requested,
			'processed'=>$this->processed(),
			'successful'=>$this->successful(),
			'failed'=>$this->failedCount(),
			'noop'=>$this->noop(),
			'error_messages'=>$this->errorMessages(),
			'results'=>array_map(
				static fn(MutationResult $result): array => $result->jsonSerialize(),
				$this->results
			),
		];
	}
}
