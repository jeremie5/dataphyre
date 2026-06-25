<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Vestra;

/**
 * Carries the result of Vestra HTML ingestion.
 *
 * IngestionResult stores the rewritten HTML plus a list of change records
 * produced by the Vestra ingestion pipeline. It is intentionally immutable from the
 * outside so callers can decide whether to persist the rewritten HTML by checking
 * the change list.
 */
final class IngestionResult {

	private string $html;
	private array $changes;

	/**
	 * Creates a Vestra ingestion result.
	 *
	 * @param string $html Rewritten HTML produced by the ingestion pipeline.
	 * @param array<int, array<string, mixed>> $changes Change records describing ingested or rewritten assets.
	 */
	public function __construct(string $html, array $changes=[]){
		$this->html=$html;
		$this->changes=$changes;
	}

	/**
	 * Rehydrates an ingestion result from a serialized payload.
	 *
	 * The payload shape matches toArray(): `new_html` for rewritten HTML and
	 * `changes` for ingestion change records. Missing or malformed changes become
	 * an empty list.
	 *
	 * @param array<string, mixed> $payload Serialized ingestion result.
	 * @return self Rehydrated ingestion result.
	 */
	public static function fromArray(array $payload): self {
		return new self(
			(string)($payload['new_html'] ?? ''),
			is_array($payload['changes'] ?? null) ? $payload['changes'] : []
		);
	}

	/**
	 * Returns rewritten HTML.
	 *
	 * @return string HTML after Vestra ingestion and asset rewriting.
	 */
	public function html(): string {
		return $this->html;
	}

	/**
	 * Returns Vestra ingestion change records.
	 *
	 * Change records preserve asset URLs, rewrite targets, and persistence details
	 * emitted by the ingestion pipeline.
	 *
	 * @return array<int, array<string, mixed>> Change records emitted by the ingestion pipeline.
	 */
	public function changes(): array {
		return $this->changes;
	}

	/**
	 * Reports whether ingestion rewrote or recorded any assets.
	 *
	 * @return bool True when at least one change record exists.
	 */
	public function changed(): bool {
		return $this->changes!==[];
	}

	/**
	 * Exposes rewritten HTML with the Vestra changes detected during ingestion.
	 *
	 * @return array{new_html: string, changes: array<int, array<string, mixed>>}
	 */
	public function toArray(): array {
		return [
			'new_html'=>$this->html,
			'changes'=>$this->changes,
		];
	}
}
