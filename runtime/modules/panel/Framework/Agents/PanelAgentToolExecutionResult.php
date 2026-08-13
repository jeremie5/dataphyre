<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Raw adapter result. The runtime applies redaction and byte limits afterward. */
final class PanelAgentToolExecutionResult {
	private function __construct(
		private readonly bool $ok,
		private readonly mixed $output,
		private readonly ?string $error,
		private readonly bool $retryable,
		private readonly array $metadata
	){}

	/** @param array<string,mixed> $metadata */
	public static function success(mixed $output=null, array $metadata=[]): self { return new self(true, $output, null, false, $metadata); }
	/** @param array<string,mixed> $metadata */
	public static function failure(string $error, bool $retryable=false, array $metadata=[]): self { return new self(false, null, $error, $retryable, $metadata); }
	public function ok(): bool { return $this->ok; }
	public function output(): mixed { return $this->output; }
	public function error(): ?string { return $this->error; }
	public function retryable(): bool { return $this->retryable; }
	/** @return array<string,mixed> */ public function metadata(): array { return $this->metadata; }
}
