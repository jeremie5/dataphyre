<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

/**
 * Captures the outcome of a SQL write operation as a serializable value object.
 *
 * MutationResult normalizes raw insert, update, delete, and versioned-update
 * returns into a stable value for repositories and table queries. It preserves
 * the original driver/kernel result, records optional affected-row counts,
 * carries repository or table context for diagnostics, and exposes failure/stale
 * guards without re-executing the mutation.
 */
final class MutationResult implements \JsonSerializable {

	private readonly string|int|null $insertedId;

	/** @var array<string,mixed>|null */
	private ?array $serialized=null;

	/**
	 * Creates a mutation result instance from already-normalized mutation fields.
	 *
	 * The object is immutable after construction. The operation label drives
	 * inserted-id and stale checks, ok records the kernel success decision,
	 * rawResult preserves the original return value, and context remains
	 * caller-defined metadata used by errors, logs, and JSON serialization.
	 *
	 * @param string $operation Mutation operation label such as insert, update, delete, or update_with_version.
	 * @param bool $ok True when the mutation was accepted by the SQL kernel.
	 * @param mixed $rawResult Original SQL kernel or driver result.
	 * @param ?int $affectedRows Non-negative affected-row count when the raw result exposes one.
	 * @param array<string,mixed> $context Repository, table, cache, identity, or caller metadata for diagnostics.
	 * @param ?string $errorMessage Failure message captured or generated for unsuccessful mutations.
	 */
	public function __construct(
		private readonly string $operation,
		private readonly bool $ok,
		private readonly mixed $rawResult=null,
		private readonly ?int $affectedRows=null,
		private readonly array $context=[],
		private readonly ?string $errorMessage=null
	){
		$this->insertedId=$operation==='insert' && (is_string($rawResult) || is_int($rawResult)) ? $rawResult : null;
	}

	/**
	 * Normalizes a raw SQL mutation return into a MutationResult.
	 *
	 * False and null raw results are treated as failures. Integer raw results
	 * become non-negative affected-row counts, while string insert ids and other
	 * driver data are preserved only as rawResult. When a failure has no message,
	 * SqlError builds one from the operation and context.
	 *
	 * @param string $operation Mutation operation label.
	 * @param mixed $rawResult Raw SQL kernel or driver return value.
	 * @param array<string,mixed> $context Diagnostic context for errors and serialization.
	 * @param ?string $errorMessage Optional failure message supplied by the caller.
	 * @return self Normalized mutation result.
	 */
	public static function fromRaw(string $operation, mixed $rawResult, array $context=[], ?string $errorMessage=null): self {
		$ok=$rawResult!==false && $rawResult!==null;
		$affectedRows=is_int($rawResult) ? max(0, $rawResult) : null;
		return new self(
			$operation,
			$ok,
			$rawResult,
			$affectedRows,
			$context,
			$ok ? null : ($errorMessage ?? SqlError::mutationErrorMessage($operation, $context))
		);
	}

	/**
	 * Returns the mutation operation label.
	 *
	 *
	 * @return string Operation label recorded for diagnostics and helper semantics.
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Indicates whether the SQL kernel considered the mutation successful.
	 *
	 * Successful versioned updates may still be stale when the affected-row count
	 * is zero; use stale() or throwIfFailedOrStale() when optimistic locking
	 * matters.
	 *
	 * @return bool True when the mutation did not return false or null during normalization.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Indicates whether the mutation failed at the SQL/kernel level.
	 *
	 *
	 * @return bool True when ok() is false.
	 */
	public function failed(): bool {
		return !$this->ok;
	}

	/**
	 * Returns the original SQL kernel or driver return value.
	 *
	 * The raw result is retained for compatibility with lower-level callers and
	 * diagnostics. Prefer the typed helper methods for success, affected row,
	 * inserted id, and stale checks.
	 *
	 * @return mixed Raw SQL kernel or driver value retained for compatibility and diagnostics.
	 */
	public function rawResult(): mixed {
		return $this->rawResult;
	}

	/**
	 * Returns the normalized affected-row count when available.
	 *
	 * Only integer raw results produce an affected-row count. Insert ids,
	 * booleans, nulls, and other driver data leave this value null because they do
	 * not unambiguously represent affected rows.
	 *
	 * @return ?int Non-negative affected-row count, or null when not available.
	 */
	public function affectedRows(): ?int {
		return $this->affectedRows;
	}

	/**
	 * Indicates an optimistic locking conflict for versioned updates.
	 *
	 * Staleness is defined narrowly: the operation must be update_with_version and
	 * the affected-row count must be exactly zero. Other zero-row mutations are not
	 * automatically treated as conflicts.
	 *
	 * @return bool True when a versioned update matched no rows.
	 */
	public function stale(): bool {
		return $this->operation==='update_with_version' && $this->affectedRows===0;
	}

	/**
	 * Throws a RuntimeException when the mutation failed.
	 *
	 * The provided message wins over the stored error message. When neither is
	 * present, SqlError derives a mutation message from the operation and context
	 * so callers still get a useful diagnostic.
	 *
	 * @param ?string $message Optional exception message override.
	 * @return self Same result when the mutation succeeded.
	 * @throws \RuntimeException When failed() is true.
	 */
	public function throwIfFailed(?string $message=null): self {
		if($this->failed()){
			throw new \RuntimeException($message ?? $this->errorMessage ?? SqlError::mutationErrorMessage($this->operation, $this->context));
		}
		return $this;
	}

	/**
	 * Throws an optimistic-lock exception when the mutation is stale.
	 *
	 * Context owner resolution prefers repository names, then table names, then
	 * the operation label, giving stale errors enough provenance for repositories
	 * and direct table queries.
	 *
	 * @param ?string $message Optional stale conflict message override.
	 * @return self Same result when stale() is false.
	 * @throws \RuntimeException When SqlError reports an optimistic locking conflict.
	 */
	public function throwIfStale(?string $message=null): self {
		if($this->stale()){
			throw SqlError::optimisticLockConflict($this->contextOwner(), $this->context, $message);
		}
		return $this;
	}

	/**
	 * Throws for SQL failure first, then for optimistic-lock staleness.
	 *
	 * Failure checks run before stale checks because a failed mutation cannot
	 * safely be interpreted as an optimistic locking conflict.
	 *
	 * @param ?string $staleMessage Optional stale conflict message override.
	 * @param ?string $failedMessage Optional SQL failure message override.
	 * @return self Same result when the mutation succeeded and is not stale.
	 */
	public function throwIfFailedOrStale(?string $staleMessage=null, ?string $failedMessage=null): self {
		return $this->throwIfFailed($failedMessage)->throwIfStale($staleMessage);
	}

	/**
	 * Returns caller-supplied mutation context.
	 *
	 * Context is intentionally open-ended so repositories, table queries, cache
	 * invalidation code, and diagnostics can attach their own identifiers without
	 * changing this value object's public shape.
	 *
	 * @return array<string,mixed> Diagnostic and serialization metadata.
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Returns the captured or generated mutation failure message.
	 *
	 * Successful mutations normally return null. Failed results created through
	 * fromRaw() have either the caller-supplied message or a SqlError-generated
	 * fallback.
	 *
	 * @return ?string Failure message, or null for successful mutations.
	 */
	public function errorMessage(): ?string {
		return $this->errorMessage;
	}

	/**
	 * Extracts an insert id from successful insert-style raw results.
	 *
	 * Only operation=insert can expose an inserted id here, and only string or
	 * integer raw results are treated as ids. Other mutation operations and
	 * non-scalar insert data return null.
	 *
	 * @return string|int|null Insert id, or null when no insert id is available.
	 */
	public function insertedId(): string|int|null {
		return $this->insertedId;
	}

	/**
	 * Serializes the mutation result for diagnostics, API responses, and examples.
	 *
	 * The serialized data preserves both normalized fields and the raw result so
	 * callers can inspect repository-level status without losing driver detail.
	 *
	 * @return array<string,mixed> Mutation result data for logs and diagnostics.
	 */
	public function jsonSerialize(): array {
		if($this->serialized!==null){
			return $this->serialized;
		}
		return $this->serialized=[
			'operation'=>$this->operation,
			'ok'=>$this->ok,
			'affected_rows'=>$this->affectedRows,
			'inserted_id'=>$this->insertedId,
			'context'=>$this->context,
			'error_message'=>$this->errorMessage,
			'raw_result'=>$this->rawResult,
		];
	}

	/**
	 * Resolves the human-readable owner used in optimistic-lock errors.
	 *
	 * Repository context is preferred because it describes the domain owner. Table
	 * context is used next for direct table queries, and the operation label is the
	 * final fallback.
	 *
	 * @return string Owner label for stale mutation diagnostics.
	 */
	private function contextOwner(): string {
		if(isset($this->context['repository']) && is_string($this->context['repository']) && trim($this->context['repository'])!==''){
			return $this->context['repository'];
		}
		if(isset($this->context['table']) && is_string($this->context['table']) && trim($this->context['table'])!==''){
			return 'table '.$this->context['table'];
		}
		return $this->operation;
	}
}
