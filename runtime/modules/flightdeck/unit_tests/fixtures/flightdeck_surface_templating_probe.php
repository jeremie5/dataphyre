<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

final class templating {
	public static function init(mixed ...$options): void {}

	public static function register_template_contract(string $template, array $contract): void {}

	public static function add_to_global_context(string $name, mixed $value): void {}

	public static function render(string $template, array $data=[], array $options=[], array $slots=[]): string {
		$values=array_map(static fn(mixed $value): string=>is_scalar($value) ? (string)$value : '', array_values($data));
		return implode(' ', $values).' '.implode(' ', array_map('strval', array_values($slots)));
	}
}
