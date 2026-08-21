<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Seeds;

/**
 * Identifies the seed definition and lifecycle step that aborted an apply.
 *
 * The original throwable remains chained so callers retain the driver or
 * application failure while command output gains deterministic seed context.
 */
final class SeedExecutionException extends \RuntimeException {
	public function __construct(
		private readonly string $seed_key,
		private readonly string $phase,
		\Throwable $previous,
	){
		$detail=$previous->getMessage();
		parent::__construct(
			'Seed '.$seed_key.' failed during '.$phase.($detail!=='' ? ': '.$detail : '.'),
			0,
			$previous,
		);
	}

	/** Returns the exact `SeedDefinition::key()` that failed. */
	public function seedKey(): string {
		return $this->seed_key;
	}

	/** Returns the deterministic lifecycle step (`preflight`, `apply`, or `ledger`). */
	public function phase(): string {
		return $this->phase;
	}

	/** @return array{seed_key:string,phase:string} */
	public function context(): array {
		return ['seed_key'=>$this->seed_key, 'phase'=>$this->phase];
	}
}
