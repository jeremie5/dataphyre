<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

/**
 * Immutable outcome record for localization maintenance operations.
 *
 * The result captures the requested operation, its status label, whether work
 * succeeded, whether it was intentionally skipped, how many items were touched,
 * and the rebuild selection that scoped the work. It is designed for command
 * responses, diagnostics, examples, and JSON APIs that need to expose
 * localization maintenance state without leaking service internals.
 */
final class LocalizationMaintenanceResult implements \JsonSerializable {

	/**
	 * Captures a completed localization maintenance outcome.
	 *
	 * @param string $operation Machine-readable operation name such as rebuild, sync, or prune.
	 * @param string $status Human-readable status or reason emitted by the maintenance service.
	 * @param bool $ok Whether the maintenance operation completed successfully.
	 * @param bool $noop Whether the operation intentionally made no changes.
	 * @param ?int $count Number of affected catalogs, keys, files, or records when the service reports one.
	 * @param bool $forced Whether the operation bypassed freshness or cache checks.
	 * @param ?LocalizationRebuildSelection $selection Rebuild scope used by the operation, when applicable.
	 */
	public function __construct(
		private readonly string $operation,
		private readonly string $status,
		private readonly bool $ok,
		private readonly bool $noop=false,
		private readonly ?int $count=null,
		private readonly bool $forced=false,
		private readonly ?LocalizationRebuildSelection $selection=null
	){}

	/**
	 * Returns the machine-readable maintenance operation name.
	 *
	 * @return string Operation identifier supplied by the localization maintenance service.
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Returns the maintenance status label or explanatory message.
	 *
	 * @return string Status text suitable for diagnostics or operator output.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Reports whether the maintenance operation succeeded.
	 *
	 * @return bool `true` for successful completion, including successful no-op outcomes.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Reports whether the maintenance operation failed.
	 *
	 * @return bool `true` when `ok()` is false.
	 */
	public function failed(): bool {
		return !$this->ok;
	}

	/**
	 * Reports whether the operation completed without applying changes.
	 *
	 * @return bool `true` when the maintenance service intentionally skipped work.
	 */
	public function noop(): bool {
		return $this->noop;
	}

	/**
	 * Returns the number of affected localization units when reported.
	 *
	 * @return ?int Affected item count, or `null` when the operation does not expose a count.
	 */
	public function count(): ?int {
		return $this->count;
	}

	/**
	 * Reports whether freshness checks were bypassed.
	 *
	 * @return bool `true` when the maintenance operation was explicitly forced.
	 */
	public function forced(): bool {
		return $this->forced;
	}

	/**
	 * Returns the rebuild selection that scoped the maintenance operation.
	 *
	 * @return ?LocalizationRebuildSelection Selection object, or `null` for unscoped operations.
	 */
	public function selection(): ?LocalizationRebuildSelection {
		return $this->selection;
	}

	/**
	 * Serializes the maintenance outcome for APIs, logs, and operator diagnostics.
	 *
	 * @return array{operation:string, status:string, ok:bool, noop:bool, count:?int, forced:bool, selection:mixed} Stable result payload.
	 */
	public function jsonSerialize(): array {
		return [
			'operation'=>$this->operation,
			'status'=>$this->status,
			'ok'=>$this->ok,
			'noop'=>$this->noop,
			'count'=>$this->count,
			'forced'=>$this->forced,
			'selection'=>$this->selection?->jsonSerialize(),
		];
	}
}
