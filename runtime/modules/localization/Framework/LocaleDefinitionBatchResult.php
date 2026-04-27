<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Localization;

final class LocaleDefinitionBatchResult implements \JsonSerializable {

	public function __construct(
		private readonly string $operation,
		private readonly bool $ok,
		private readonly int $requested,
		private readonly int $processed,
		private readonly int $skipped=0,
		private readonly bool $rebuilt=false,
		private readonly int $rebuild_targets=0
	){}

	public function operation(): string { return $this->operation; }
	public function ok(): bool { return $this->ok; }
	public function failed(): bool { return !$this->ok; }
	public function requested(): int { return $this->requested; }
	public function processed(): int { return $this->processed; }
	public function skipped(): int { return $this->skipped; }
	public function rebuilt(): bool { return $this->rebuilt; }
	public function rebuildTargets(): int { return $this->rebuild_targets; }
	public function noop(): bool { return $this->requested===0 || ($this->processed===0 && $this->skipped===$this->requested); }

	public function jsonSerialize(): array {
		return [
			'operation'=>$this->operation,
			'ok'=>$this->ok,
			'requested'=>$this->requested,
			'processed'=>$this->processed,
			'skipped'=>$this->skipped,
			'rebuilt'=>$this->rebuilt,
			'rebuild_targets'=>$this->rebuild_targets,
			'noop'=>$this->noop(),
		];
	}
}
