<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

if(!class_exists(localization::class,false)){
	final class localization {
		public static function locale(string $key,string $fallback): string {
			return 'L:'.$key;
		}
	}
}
