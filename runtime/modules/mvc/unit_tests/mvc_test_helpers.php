<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once __DIR__.'/../Framework/ValidationException.php';
require_once __DIR__.'/../Framework/Validator.php';

function mvc_validator_failure_json(): string {
	$validator=\Dataphyre\Mvc\Validator::make(
		[
			'email'=>'bad',
			'quantity'=>'0',
			'note'=>'',
		],
		[
			'email'=>'required|email',
			'quantity'=>'required|integer|min:1',
			'note'=>'nullable|string',
			'status'=>'sometimes|in:paid,pending',
		],
		[
			'email.email'=>'Use a real email.',
			'quantity.min'=>'Quantity too small.',
		],
		[
			'email'=>'email address',
		]
	);
	return json_encode([
		'errors'=>$validator->errors(),
		'fails'=>$validator->fails(),
		'safe_missing'=>$validator->safe('status', 'missing'),
		'validated'=>$validator->validated(),
	], JSON_UNESCAPED_SLASHES);
}

function mvc_validator_callable_json(): string {
	$validator=\Dataphyre\Mvc\Validator::make(
		['slug'=>'valid-slug', 'flag'=>'1'],
		[
			'slug'=>[
				'required',
				static fn(mixed $value): bool|string => preg_match('/^[a-z0-9-]+$/', (string)$value) === 1 ? true : 'Bad slug',
			],
			'flag'=>'boolean',
		]
	);
	return json_encode([
		'passes'=>$validator->passes(),
		'safe_slug'=>$validator->safe('slug'),
		'to_array'=>$validator->toArray(),
	], JSON_UNESCAPED_SLASHES);
}
