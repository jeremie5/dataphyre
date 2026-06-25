<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Storage;

/**
 * Describes the outcome of a storage operation.
 *
 * StorageResult is a small value object used by storage adapters and callers to
 * return success state, a human-readable message, and adapter-specific metadata
 * without throwing for expected operational failures.
 */
final class StorageResult {

	/**
	 * Stores the immutable storage operation outcome.
	 *
	 * @param bool $ok Whether the operation succeeded.
	 * @param string $message Human-readable result message.
	 * @param array<string, mixed> $data Adapter-specific diagnostics or result metadata.
	 */
	private function __construct(
		private bool $ok,
		private string $message='',
		private array $data=[]
	) {
	}

	/**
	 * Creates a successful storage result.
	 *
	 * @param array<string, mixed> $data Adapter-specific result metadata.
	 * @param string $message Human-readable success message.
	 * @return self Successful result value.
	 */
	public static function ok(array $data=[], string $message='OK'): self {
		return new self(true, $message, $data);
	}

	/**
	 * Creates a failed storage result.
	 *
	 * @param string $message Human-readable failure reason.
	 * @param array<string, mixed> $data Adapter-specific diagnostics.
	 * @return self Failed result value.
	 */
	public static function fail(string $message, array $data=[]): self {
		return new self(false, $message, $data);
	}

	/**
	 * Reports whether the storage operation succeeded.
	 *
	 * @return bool True for success, false for expected storage failure.
	 */
	public function okStatus(): bool {
		return $this->ok;
	}

	/**
	 * Returns the human-readable result message.
	 *
	 * @return string Success message or failure reason.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Returns adapter-specific result data.
	 *
	 * @return array<string, mixed> Result metadata or diagnostic data supplied by the adapter.
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Exposes operation status, message text, and provider metadata.
	 *
	 * @return array{ok: bool, message: string, data: array<string, mixed>}
	 */
	public function toArray(): array {
		return [
			'ok'=>$this->ok,
			'message'=>$this->message,
			'data'=>$this->data,
		];
	}
}
