<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Machine-readable validation or execution-precondition issue.
 */
final class AutomationValidationIssue implements \JsonSerializable {
	/** @param array<string,mixed> $metadata */
	public function __construct(
		private readonly string $path,
		private readonly string $code,
		private readonly string $message,
		private readonly string $severity='error',
		private readonly array $metadata=[]
	){}

	/** @param self|array<string,mixed>|string $issue */
	public static function from(self|array|string $issue): self {
		if($issue instanceof self){ return $issue; }
		if(is_string($issue)){ return new self('', 'invalid', trim($issue)); }
		return new self(
			trim((string)($issue['path'] ?? '')), WorkflowState::normalize((string)($issue['code'] ?? 'invalid')) ?: 'invalid',
			trim((string)($issue['message'] ?? 'Input is invalid.')), WorkflowState::normalize((string)($issue['severity'] ?? 'error')) ?: 'error',
			is_array($issue['metadata'] ?? null) ? $issue['metadata'] : []
		);
	}

	public function path(): string { return $this->path; }
	public function code(): string { return $this->code; }
	public function message(): string { return $this->message; }
	public function severity(): string { return $this->severity; }
	/** @return array<string,mixed> */
	public function metadata(): array { return $this->metadata; }

	public function jsonSerialize(): array {
		return ['path'=>$this->path, 'code'=>$this->code, 'message'=>$this->message, 'severity'=>$this->severity, 'metadata'=>$this->metadata];
	}
}
