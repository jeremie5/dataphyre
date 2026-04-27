<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class MutationResult implements \JsonSerializable {

	public function __construct(
		private readonly string $operation,
		private readonly bool $ok,
		private readonly mixed $raw_result=null,
		private readonly ?int $affected_rows=null,
		private readonly array $context=[],
		private readonly ?string $error_message=null
	){}

	public static function fromRaw(string $operation, mixed $raw_result, array $context=[], ?string $error_message=null): self {
		$ok=$raw_result!==false && $raw_result!==null;
		$affected_rows=is_int($raw_result) ? max(0, $raw_result) : null;
		return new self(
			$operation,
			$ok,
			$raw_result,
			$affected_rows,
			$context,
			$ok ? null : ($error_message ?? SqlError::mutationErrorMessage($operation, $context))
		);
	}

	public function operation(): string {
		return $this->operation;
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function failed(): bool {
		return !$this->ok;
	}

	public function rawResult(): mixed {
		return $this->raw_result;
	}

	public function affectedRows(): ?int {
		return $this->affected_rows;
	}

	public function context(): array {
		return $this->context;
	}

	public function errorMessage(): ?string {
		return $this->error_message;
	}

	public function insertedId(): string|int|null {
		if($this->operation!=='insert'){
			return null;
		}
		if(is_string($this->raw_result) || is_int($this->raw_result)){
			return $this->raw_result;
		}
		return null;
	}

	public function jsonSerialize(): array {
		return [
			'operation'=>$this->operation,
			'ok'=>$this->ok,
			'affected_rows'=>$this->affected_rows,
			'inserted_id'=>$this->insertedId(),
			'context'=>$this->context,
			'error_message'=>$this->error_message,
			'raw_result'=>$this->raw_result,
		];
	}
}
