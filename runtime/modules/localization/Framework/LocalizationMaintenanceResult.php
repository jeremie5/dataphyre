<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocalizationMaintenanceResult implements \JsonSerializable {

	public function __construct(
		private readonly string $operation,
		private readonly string $status,
		private readonly bool $ok,
		private readonly bool $noop=false,
		private readonly ?int $count=null,
		private readonly bool $forced=false,
		private readonly ?LocalizationRebuildSelection $selection=null
	){}

	public function operation(): string {
		return $this->operation;
	}

	public function status(): string {
		return $this->status;
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function failed(): bool {
		return !$this->ok;
	}

	public function noop(): bool {
		return $this->noop;
	}

	public function count(): ?int {
		return $this->count;
	}

	public function forced(): bool {
		return $this->forced;
	}

	public function selection(): ?LocalizationRebuildSelection {
		return $this->selection;
	}

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
