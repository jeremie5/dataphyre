<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class SkippedTest extends \Exception {

	public function __construct(string $message='Test skipped.', private bool $todo=false) {
		parent::__construct($message!=='' ? $message : ($todo ? 'Test marked todo.' : 'Test skipped.'));
	}

	public function isTodo(): bool {
		return $this->todo;
	}
}
