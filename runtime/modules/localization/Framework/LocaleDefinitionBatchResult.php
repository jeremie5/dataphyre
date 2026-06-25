<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Summarizes a batch operation over locale definitions.
 *
 * LocaleDefinitionBatchResult is the immutable reporting object returned by localization maintenance operations such as importing definitions, skipping unchanged locales, and rebuilding derived locale targets. It keeps aggregate counts separate so callers can distinguish full success, partial work, skipped no-op batches, and rebuild side effects.
 */
final class LocaleDefinitionBatchResult implements \JsonSerializable {

	/**
	 * Captures the aggregate outcome of a locale-definition batch operation.
	 *
	 * Counts are stored exactly as reported by the caller. The object does not infer success from processed/skipped totals because some localization workflows may mark ok=false after validating external files, write permissions, or generated artifact state.
	 *
	 * @param string $operation Operation label such as import, sync, rebuild, or validate.
	 * @param bool $ok True when the batch completed according to the caller's success criteria.
	 * @param int $requested Number of locale definitions requested for processing.
	 * @param int $processed Number of locale definitions actually processed.
	 * @param int $skipped Number of locale definitions intentionally skipped.
	 * @param bool $rebuilt True when the operation rebuilt derived locale artifacts.
	 * @param int $rebuildTargets Number of rebuild targets touched by the operation.
	 */
	public function __construct(
		private readonly string $operation,
		private readonly bool $ok,
		private readonly int $requested,
		private readonly int $processed,
		private readonly int $skipped=0,
		private readonly bool $rebuilt=false,
		private readonly int $rebuildTargets=0
	){}

	/**
	 * Returns the batch operation label.
	 *
	 *
	 * @return string Operation name recorded for diagnostics and serialization.
	 */
	public function operation(): string { return $this->operation; }
	/**
	 * Indicates whether the batch completed successfully.
	 *
	 *
	 * @return bool True when the caller marked the batch successful.
	 */
	public function ok(): bool { return $this->ok; }
	/**
	 * Indicates whether the batch failed according to the caller.
	 *
	 *
	 * @return bool True when ok() is false.
	 */
	public function failed(): bool { return !$this->ok; }
	/**
	 * Returns the number of locale definitions requested.
	 *
	 *
	 * @return int Requested definition count.
	 */
	public function requested(): int { return $this->requested; }
	/**
	 * Returns the number of locale definitions processed.
	 *
	 *
	 * @return int Processed definition count.
	 */
	public function processed(): int { return $this->processed; }
	/**
	 * Returns the number of locale definitions skipped intentionally.
	 *
	 *
	 * @return int Skipped definition count.
	 */
	public function skipped(): int { return $this->skipped; }
	/**
	 * Indicates whether derived localization artifacts were rebuilt.
	 *
	 *
	 * @return bool True when the batch performed a rebuild phase.
	 */
	public function rebuilt(): bool { return $this->rebuilt; }
	/**
	 * Returns the number of derived localization targets rebuilt.
	 *
	 *
	 * @return int Rebuild target count.
	 */
	public function rebuildTargets(): int { return $this->rebuildTargets; }
	/**
	 * Indicates whether the batch performed no effective processing work.
	 *
	 *
	 * @return bool True when nothing was requested, or every requested definition was skipped.
	 */
	public function noop(): bool { return $this->requested===0 || ($this->processed===0 && $this->skipped===$this->requested); }

	/**
	 * Serializes the batch result for diagnostics, APIs, and examples.
	 *
	 * The payload includes raw aggregate counters and the derived noop flag so consumers can present both the operation outcome and the amount of work performed.
	 *
	 * @return array<string,mixed> JSON-safe locale definition batch result.
	 */
	public function jsonSerialize(): array {
		return [
			'operation'=>$this->operation,
			'ok'=>$this->ok,
			'requested'=>$this->requested,
			'processed'=>$this->processed,
			'skipped'=>$this->skipped,
			'rebuilt'=>$this->rebuilt,
			'rebuild_targets'=>$this->rebuildTargets,
			'noop'=>$this->noop(),
		];
	}
}
