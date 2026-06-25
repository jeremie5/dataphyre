<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Immutable outcome record returned by SQL transaction helpers.
 *
 * The result separates transaction lifecycle facts from callback outcome:
 * beginning, committing, rolling back, retry attempts, return value, and the
 * captured exception are all exposed independently so diagnostics can explain
 * exactly where a transactional operation stopped.
 */
final class TransactionResult implements \JsonSerializable {

	/** @var ?class-string<\Throwable> */
	private readonly ?string $errorClass;
	private readonly ?string $errorMessage;
	private ?array $serialized=null;

	/**
	 * Captures the complete lifecycle state for one transactional execution.
	 *
	 * Values are stored without additional inference. Factory constructors clamp
	 * attempts to at least one and enforce the success/failure invariants used by
	 * framework transaction helpers.
	 *
	 * @param ?string $cluster Database cluster that executed the transaction, or null for the default connection.
	 * @param bool $ok True when the transactional callback completed successfully.
	 * @param bool $begun True when a transaction was opened before completion or failure.
	 * @param bool $committed True when the transaction reached a commit call.
	 * @param bool $rolledBack True when failure handling reached a rollback call.
	 * @param mixed $value Callback return value captured on success.
	 * @param ?\Throwable $exception Exception captured on failure.
	 * @param int $attempts Number of attempts consumed, including retries.
	 */
	public function __construct(
		private readonly ?string $cluster,
		private readonly bool $ok,
		private readonly bool $begun,
		private readonly bool $committed,
		private readonly bool $rolledBack,
		private readonly mixed $value=null,
		private readonly ?\Throwable $exception=null,
		private readonly int $attempts=1
	){
		$this->errorClass=$exception!==null ? $exception::class : null;
		$this->errorMessage=$exception?->getMessage();
	}

	/**
	 * Builds a successful transaction result.
	 *
	 * Successful results never carry an exception and never mark rollback. The
	 * caller supplies whether a transaction was actually begun and committed so
	 * read-only or driver-short-circuited flows can still report precise state.
	 *
	 * @param ?string $cluster Database cluster that executed the transaction, or null for the default connection.
	 * @param bool $begun True when the transaction opened before the callback completed.
	 * @param bool $committed True when the transaction reached a commit call.
	 * @param mixed $value Callback return value to expose to callers and diagnostics.
	 * @param int $attempts Attempt count before success; clamped to at least one.
	 * @return self Successful transaction outcome with no exception.
	 */
	public static function success(?string $cluster, bool $begun, bool $committed, mixed $value=null, int $attempts=1): self {
		return new self($cluster, true, $begun, $committed, false, $value, null, max(1, $attempts));
	}

	/**
	 * Builds a failed transaction result from the captured exception.
	 *
	 * Failed results discard the callback value because the exception is the
	 * authoritative outcome. Commit and rollback flags are preserved separately
	 * for cases where an exception occurs during commit, rollback, or retry
	 * handling.
	 *
	 * @param ?string $cluster Database cluster that attempted the transaction, or null for the default connection.
	 * @param bool $begun True when a transaction opened before failure.
	 * @param bool $committed True when commit completed or was reached before failure reporting.
	 * @param bool $rolledBack True when rollback completed or was reached during failure handling.
	 * @param \Throwable $exception Exception that caused the transaction helper to fail.
	 * @param int $attempts Attempt count before failure; clamped to at least one.
	 * @return self Failed transaction outcome with exception metadata.
	 */
	public static function failure(
		?string $cluster,
		bool $begun,
		bool $committed,
		bool $rolledBack,
		\Throwable $exception,
		int $attempts=1
	): self {
		return new self($cluster, false, $begun, $committed, $rolledBack, null, $exception, max(1, $attempts));
	}

	/**
	 * Returns the database cluster associated with the transaction attempt.
	 *
	 * @return ?string Cluster identifier, or null when the default connection was used.
	 */
	public function cluster(): ?string {
		return $this->cluster;
	}

	/**
	 * Indicates whether the transactional callback completed successfully.
	 *
	 * @return bool True when no exception was captured for the transaction helper.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Indicates whether the transaction helper captured a failure.
	 *
	 * @return bool True when the transaction outcome contains an exception state.
	 */
	public function failed(): bool {
		return !$this->ok;
	}

	/**
	 * Indicates whether the transaction reached the begin phase.
	 *
	 * @return bool True when the helper opened a transaction before finishing.
	 */
	public function begun(): bool {
		return $this->begun;
	}

	/**
	 * Indicates whether the transaction reached the commit phase.
	 *
	 * @return bool True when commit was reached for the recorded attempt.
	 */
	public function committed(): bool {
		return $this->committed;
	}

	/**
	 * Indicates whether failure handling reached the rollback phase.
	 *
	 * @return bool True when rollback was reached for the recorded attempt.
	 */
	public function rolledBack(): bool {
		return $this->rolledBack;
	}

	/**
	 * Counts how many transaction attempts were consumed.
	 *
	 * @return int Positive attempt count after factory-level clamping.
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Returns the successful callback value.
	 *
	 * Failed transaction results always expose null here because the exception is
	 * the authoritative failure signal. Successful callbacks may also return
	 * null, so callers should inspect {@see ok()} before interpreting the value.
	 *
	 * @return mixed Callback result captured on success, or null for failures.
	 */
	public function value(): mixed {
		return $this->value;
	}

	/**
	 * Returns the exception captured by a failed transaction helper.
	 *
	 * @return ?\Throwable Failure exception, or null for successful results.
	 */
	public function exception(): ?\Throwable {
		return $this->exception;
	}

	/**
	 * Returns the captured exception message for diagnostics.
	 *
	 * @return ?string Exception message, or null when the transaction succeeded.
	 */
	public function errorMessage(): ?string {
		return $this->errorMessage;
	}

	/**
	 * Returns the captured exception class for diagnostics.
	 *
	 * @return ?class-string<\Throwable> Exception class name, or null when the transaction succeeded.
	 */
	public function errorClass(): ?string {
		return $this->errorClass;
	}

	/**
	 * Serializes the transaction outcome for logs, tooling, and API diagnostics.
	 *
	 * The exception object itself is intentionally omitted; only its class and
	 * message are emitted so JSON diagnostics preserve the
	 * failure identity needed during troubleshooting.
	 *
	 * @return array{cluster: ?string, ok: bool, begun: bool, committed: bool, rolled_back: bool, attempts: int, value: mixed, error_class: ?class-string<\Throwable>, error_message: ?string} JSON-safe transaction outcome.
	 */
	public function jsonSerialize(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		return $this->serialized=[
			'cluster'=>$this->cluster,
			'ok'=>$this->ok,
			'begun'=>$this->begun,
			'committed'=>$this->committed,
			'rolled_back'=>$this->rolledBack,
			'attempts'=>$this->attempts,
			'value'=>$this->value,
			'error_class'=>$this->errorClass(),
			'error_message'=>$this->errorMessage(),
		];
	}
}
