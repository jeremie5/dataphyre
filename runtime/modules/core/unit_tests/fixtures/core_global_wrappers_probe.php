<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	final class core {
		public static function encrypt_data(mixed $value=null, mixed $context=null): array { return ['encrypt',$value,$context]; }
		public static function decrypt_data(mixed $value=null, mixed $context=null): array { return ['decrypt',$value,$context]; }
		public static function convert_storage_unit(mixed $value=null): array { return ['storage',$value]; }
		public static function get_config(mixed $value=null): array { return ['config',$value]; }
	}
}

namespace {
	$source=(string)($argv[1] ?? '');
	require $source;
	echo json_encode([
		'encrypt'=>encrypt_data('plain', ['scope']),
		'decrypt'=>decrypt_data('cipher', ['scope']),
		'storage'=>convert_storage_unit('10MB'),
		'config'=>config('core/timezone'),
	], JSON_THROW_ON_ERROR);
}
