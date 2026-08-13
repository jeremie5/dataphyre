<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

use Throwable;

/** Preserves every original throwable raised while draining deferred cleanup. */
final class DeferredCleanupFailed extends \RuntimeException {

	/** @param list<Throwable> $failures */
	public function __construct(private array $failures) {
		parent::__construct(
			'Deferred test cleanup failed: '.implode(' | ', array_map(
				static fn(Throwable $failure): string=>$failure::class.': '.$failure->getMessage(),
				$failures
			)),
			0,
			$failures[0] ?? null
		);
	}

	/** @return list<Throwable> */
	public function failures(): array {
		return $this->failures;
	}
}
