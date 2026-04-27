<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Cdn;

final class IngestionResult {

	private string $html;
	private array $changes;

	public function __construct(string $html, array $changes=[]){
		$this->html=$html;
		$this->changes=$changes;
	}

	public static function fromArray(array $payload): self {
		return new self(
			(string)($payload['new_html'] ?? ''),
			is_array($payload['changes'] ?? null) ? $payload['changes'] : []
		);
	}

	public function html(): string {
		return $this->html;
	}

	public function changes(): array {
		return $this->changes;
	}

	public function changed(): bool {
		return $this->changes!==[];
	}

	public function toArray(): array {
		return [
			'new_html'=>$this->html,
			'changes'=>$this->changes,
		];
	}
}
