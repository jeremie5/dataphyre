<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

final class SanitizationException extends \RuntimeException {

	public function __construct(
		private readonly SanitizationResult $result,
		private readonly array $context=[],
		?string $message=null,
		int $code=0,
		?\Throwable $previous=null
	){
		parent::__construct($message ?? $this->defaultMessage($result), $code, $previous);
	}

	public function result(): SanitizationResult {
		return $this->result;
	}

	public function errors(): array {
		return $this->result->errors();
	}

	public function input(): array {
		return $this->result->input();
	}

	public function firstError(): ?string {
		return $this->result->firstError();
	}

	public function context(): array {
		return $this->context;
	}

	private function defaultMessage(SanitizationResult $result): string {
		return $result->firstError() ?? 'Sanitization failed.';
	}
}
