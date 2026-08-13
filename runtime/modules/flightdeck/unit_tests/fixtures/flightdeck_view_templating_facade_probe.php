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
	public static array $contracts=[];
	public static array $context=[];
	public static function init(bool $is_dev_mode,string $cache_dir,bool $strict_mode): void {}
	public static function register_template_contract(string $template,array $contract): void {
		self::$contracts[$template]=$contract;
	}
	public static function add_to_global_context(string $key,mixed $value): void {
		self::$context[$key]=$value;
	}
	public static function render(string $template,array $values=[],array $context=[],array $slots=[]): string {
		return '<html><body>'.($slots['nav'] ?? '').($slots['content'] ?? '').'</body></html>';
	}
}
