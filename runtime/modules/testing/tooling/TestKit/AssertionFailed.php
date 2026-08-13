<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

final class AssertionFailed extends \Exception {

	/** @param array<string, mixed> $meta */
	public function __construct(string $message, private mixed $expected=null, private mixed $actual=null, private array $meta=[]) {
		parent::__construct($message);
	}

	/** @return array<string, mixed> */
	public function details(): array {
		return [
			'message'=>$this->getMessage(),
			'expected'=>$this->expected,
			'actual'=>$this->actual,
			'meta'=>$this->meta,
		];
	}
}
