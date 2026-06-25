<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Sanitation;

/**
 * Carries a failed sanitization result through caller error handling.
 *
 * SanitizationException keeps the complete SanitizationResult alongside
 * optional caller context so controllers, jobs, and tests can inspect the
 * rejected input and normalized error list without losing the throwable flow
 * expected by service boundaries.
 */
final class SanitizationException extends \RuntimeException {

	/**
	 * Builds an exception around a failed sanitization result.
	 *
	 * When no message is supplied, the first result error is used so logs and
	 * HTTP responses surface the most specific failure. The result and context
	 * remain available even when a custom message is used.
	 *
	 * @param SanitizationResult $result Sanitization outcome that caused the exception.
	 * @param array<string, mixed> $context Caller-defined context such as field, rule set, or source.
	 * @param ?string $message Optional exception message overriding the first result error.
	 * @param int $code Exception code.
	 * @param ?\Throwable $previous Previous throwable in the sanitization chain.
	 */
	public function __construct(
		private readonly SanitizationResult $result,
		private readonly array $context=[],
		?string $message=null,
		int $code=0,
		?\Throwable $previous=null
	){
		parent::__construct($message ?? $this->defaultMessage($result), $code, $previous);
	}

	/**
	 * Returns the complete sanitization result.
	 *
	 * @return SanitizationResult Result containing input, output, and errors.
	 */
	public function result(): SanitizationResult {
		return $this->result;
	}

	/**
	 * Returns the sanitized error list.
	 *
	 * @return array<int|string, mixed> Errors reported by the failed result.
	 */
	public function errors(): array {
		return $this->result->errors();
	}

	/**
	 * Returns the original input captured by the result.
	 *
	 * @return array<string, mixed> Input values inspected by the sanitizer.
	 */
	public function input(): array {
		return $this->result->input();
	}

	/**
	 * Returns the first human-readable error when available.
	 *
	 * @return ?string First result error, or null when the result has no string error.
	 */
	public function firstError(): ?string {
		return $this->result->firstError();
	}

	/**
	 * Returns caller-supplied context for diagnostics.
	 *
	 * @return array<string, mixed> Context describing where or why sanitization failed.
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Chooses the default exception message from the sanitization result.
	 *
	 * @param SanitizationResult $result Failed sanitization result.
	 * @return string First result error or a generic failure message.
	 */
	private function defaultMessage(SanitizationResult $result): string {
		return $result->firstError() ?? 'Sanitization failed.';
	}
}
