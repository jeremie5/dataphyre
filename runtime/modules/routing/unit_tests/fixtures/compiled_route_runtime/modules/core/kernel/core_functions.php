<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

if(!class_exists(core::class,false)){
	class core {
		public static function load_framework_module(string $module): bool { return true; }
		public static function load_framework_modules(array $modules): array { return $modules; }
	}
}
