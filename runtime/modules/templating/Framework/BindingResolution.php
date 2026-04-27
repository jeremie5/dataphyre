<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class BindingResolution {

	private function __construct(
		private readonly mixed $value,
		private readonly bool $skipped=false
	){}

	public static function value(mixed $value): self {
		return new self($value, false);
	}

	public static function skipped(mixed $value=null): self {
		return new self($value, true);
	}

	public function result(): mixed {
		return $this->value;
	}

	public function isSkipped(): bool {
		return $this->skipped;
	}
}
