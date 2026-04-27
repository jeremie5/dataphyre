<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Database;

final class TransactionResult implements \JsonSerializable {

	public function __construct(
		private readonly ?string $cluster,
		private readonly bool $ok,
		private readonly bool $begun,
		private readonly bool $committed,
		private readonly bool $rolled_back,
		private readonly mixed $value=null,
		private readonly ?\Throwable $exception=null
	){}

	public static function success(?string $cluster, bool $begun, bool $committed, mixed $value=null): self {
		return new self($cluster, true, $begun, $committed, false, $value, null);
	}

	public static function failure(
		?string $cluster,
		bool $begun,
		bool $committed,
		bool $rolled_back,
		\Throwable $exception
	): self {
		return new self($cluster, false, $begun, $committed, $rolled_back, null, $exception);
	}

	public function cluster(): ?string {
		return $this->cluster;
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function failed(): bool {
		return !$this->ok;
	}

	public function begun(): bool {
		return $this->begun;
	}

	public function committed(): bool {
		return $this->committed;
	}

	public function rolledBack(): bool {
		return $this->rolled_back;
	}

	public function value(): mixed {
		return $this->value;
	}

	public function exception(): ?\Throwable {
		return $this->exception;
	}

	public function errorMessage(): ?string {
		return $this->exception?->getMessage();
	}

	public function errorClass(): ?string {
		return $this->exception!==null ? $this->exception::class : null;
	}

	public function jsonSerialize(): array {
		return [
			'cluster'=>$this->cluster,
			'ok'=>$this->ok,
			'begun'=>$this->begun,
			'committed'=>$this->committed,
			'rolled_back'=>$this->rolled_back,
			'value'=>$this->value,
			'error_class'=>$this->errorClass(),
			'error_message'=>$this->errorMessage(),
		];
	}
}
