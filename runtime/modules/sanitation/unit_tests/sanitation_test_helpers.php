<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
}

namespace dataphyre {
	if(!class_exists(__NAMESPACE__.'\\core', false)){
		class core{
			public static function dialback(...$args): mixed { return null; }
		}
	}
}

namespace {
	require_once __DIR__.'/../kernel/sanitation.main.php';

	function dp_sanitation_unit_many_and_masks_json(): string {
		$result=\dataphyre\sanitation::sanitize_many([
			'email'=>'USER@example.com',
			'age'=>'42',
			'bad_email'=>'not-an-email',
			'slug'=>' Hello, World! ',
		], [
			'email'=>'email',
			'age'=>'integer',
			'bad_email'=>'email',
			'slug'=>'slug',
		], true);
		ksort($result);
		return json_encode([
			'anonymized'=>\dataphyre\sanitation::anonymize_email('billing@example.com', 3),
			'invalid_mask'=>\dataphyre\sanitation::anonymize_email('not an email'),
			'many'=>$result,
		], JSON_UNESCAPED_SLASHES);
	}

	function dp_sanitation_unit_scalar_types_json(): string {
		return json_encode([
			'alphanumeric'=>\dataphyre\sanitation::sanitize('abc-123 !', 'alphanumeric'),
			'boolean_false'=>\dataphyre\sanitation::sanitize('off', 'boolean'),
			'float'=>\dataphyre\sanitation::sanitize('-10.25', 'float'),
			'postal'=>\dataphyre\sanitation::sanitize(' h2x 1y4 ', 'postal_code'),
			'url_bad'=>\dataphyre\sanitation::sanitize('javascript:alert(1)', 'url'),
		], JSON_UNESCAPED_SLASHES);
	}
}
