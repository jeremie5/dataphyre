<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\UploadedFile;
use Dataphyre\Mvc\ValidationException;
use Dataphyre\Mvc\Validator;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'mvc']);

test('mvc validator accepts the complete scalar date collection wildcard and upload rule grammar', static function(Context $t): void {
	$fixture=$t->workspace('mvc-validator-upload')->file(
		'pixel.png',
		base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=', true) ?: 'png',
	);
	$upload=new UploadedFile('pixel.png', 'image/png', $fixture, UPLOAD_ERR_OK, filesize($fixture) ?: 0);
	{
		$data=[
			'accepted'=>'yes', 'boolean'=>'true', 'string'=>'Ada', 'integer'=>'42', 'numeric'=>'4.25',
			'array'=>['a', 'b'], 'alpha'=>'Élodie', 'alpha_num'=>'Ada42', 'prefix'=>'pre-value',
			'suffix'=>'value-end', 'digits'=>'1234', 'digits_between'=>'12345', 'choice'=>'open',
			'password'=>'secret123', 'password_confirmation'=>'secret123', 'different'=>'other',
			'regex'=>'ABC-123', 'email'=>'ada@example.com', 'url'=>'https://example.com',
			'start'=>'2026-07-01', 'end'=>'2026-07-10', 'same_day'=>'2026-07-10',
			'min_text'=>'abcd', 'between_text'=>'abc', 'size_text'=>'abc', 'max_text'=>'abc',
			'min_number'=>5, 'between_number'=>5, 'size_number'=>5, 'max_number'=>5,
			'image'=>$upload, 'file'=>$upload,
			'items'=>[
				['email'=>'ada@example.com', 'confirm'=>'ada@example.com', 'code'=>'A'],
				['email'=>'grace@example.com', 'confirm'=>'grace@example.com', 'code'=>'B'],
			],
			'nullable'=>null,
		];
		$rules=[
			'accepted'=>'required|accepted',
			'boolean'=>'boolean', 'string'=>'string', 'integer'=>'integer', 'numeric'=>'numeric', 'array'=>'array|min:1|max:3',
			'alpha'=>'alpha', 'alpha_num'=>'alpha_num', 'prefix'=>'starts_with:pre,begin', 'suffix'=>'ends_with:end,finish',
			'digits'=>'digits:4', 'digits_between'=>'digits_between:4,6', 'choice'=>'in:open,closed',
			'password'=>'confirmed|min:8', 'different'=>'different:password', 'regex'=>'regex:/^[A-Z]{3}-\d{3}$/',
			'email'=>'email', 'url'=>'url', 'start'=>'date|before:end|before_or_equal:same_day',
			'end'=>'date|after:start|after_or_equal:same_day',
			'min_text'=>'min:3', 'between_text'=>'between:2,4', 'size_text'=>'size:3', 'max_text'=>'max:4',
			'min_number'=>'min:4', 'between_number'=>'between:4,6', 'size_number'=>'size:5', 'max_number'=>'max:6',
			'image'=>'file|image|mimes:png|mimetypes:image/png|max:100000',
			'file'=>'file',
			'items.*.email'=>'required|email|distinct|same:items.*.confirm',
			'items.*.code'=>'required|alpha|distinct:ignore_case',
			'nullable'=>'nullable|string',
			'sometimes_missing'=>'sometimes|required',
		];
		$validator=Validator::make($data, $rules, [], ['alpha_num'=>'account code']);
		$t->isTrue($validator->passes());
		$t->isFalse($validator->fails());
		$t->same([], $validator->errors());
		$t->same($validator->validated(), $validator->safe());
		$t->same('Ada', $validator->safe('string'));
		$t->same('fallback', $validator->safe('missing', 'fallback'));
		$t->same($validator->validated(), $validator->validateOrThrow());
		$t->isTrue($validator->toArray()['valid']);
		$t->same($validator->validated(), Validator::validate($data, $rules));
		// Cached result accessors cover the lazy-run fast path.
		$t->isTrue($validator->passes());
		$t->same([], $validator->errors());
	}
})->tag('mvc', 'validator', 'coverage')->group('framework-coverage');

test('mvc validator reports every failure family with custom messages attributes bail and callable rules', static function(Context $t): void {
	$missing=$t->workspace('mvc-missing-validation-upload')->path('missing.txt');
	$badUpload=new UploadedFile('bad.txt', 'text/plain', $missing, UPLOAD_ERR_NO_FILE, 0);
	$data=[
		'present'=>'yes', 'prohibited'=>'filled', 'prohibited_if'=>'filled', 'prohibited_unless'=>'filled',
		'boolean'=>'maybe', 'accepted'=>'no', 'string'=>[], 'integer'=>'4.2', 'numeric'=>'abc', 'array'=>'abc',
		'duplicate'=>['A', 'A'], 'alpha'=>'A1', 'alpha_num'=>'A-1', 'prefix'=>'value', 'suffix'=>'value',
		'digits'=>'12a', 'digits_between'=>'12', 'file'=>$badUpload, 'image'=>$badUpload,
		'mimes'=>$badUpload, 'mimetypes'=>$badUpload, 'choice'=>'draft', 'same'=>'left', 'other'=>'right',
		'different'=>'same', 'different_other'=>'same', 'password'=>'secret', 'password_confirmation'=>'different',
		'regex'=>'bad', 'email'=>'bad', 'url'=>'javascript:bad', 'date'=>'not-date',
		'before'=>'2026-07-10', 'after'=>'2026-07-01', 'minimum'=>'a', 'between'=>'abcdef', 'size'=>'ab', 'maximum'=>'abcdef',
		'mode'=>'business', 'with'=>'yes', 'without'=>'', 'custom_string'=>'value', 'custom_false'=>'value',
	];
	$rules=[
		'missing'=>'required', 'missing_if'=>'required_if:mode,business', 'missing_unless'=>'required_unless:mode,personal',
		'missing_with'=>'required_with:with', 'missing_without'=>'required_without:without', 'absent_present'=>'present',
		'prohibited'=>'prohibited', 'prohibited_if'=>'prohibited_if:mode,business', 'prohibited_unless'=>'prohibited_unless:mode,personal',
		'boolean'=>'boolean', 'accepted'=>'accepted', 'string'=>'string', 'integer'=>'integer', 'numeric'=>'numeric', 'array'=>'array',
		'duplicate.*'=>'distinct', 'alpha'=>'alpha', 'alpha_num'=>'alpha_numeric', 'prefix'=>'starts_with:pre', 'suffix'=>'ends_with:end',
		'digits'=>'digits:3', 'digits_between'=>'digits_between:3,5', 'file'=>'file', 'image'=>'image',
		'mimes'=>'mimes:png,jpg', 'mimetypes'=>'mimetypes:image/png', 'choice'=>'in:open,closed',
		'same'=>'same:other', 'different'=>'different:different_other', 'password'=>'confirmed', 'regex'=>'regex:/^OK$/',
		'email'=>'email', 'url'=>'url', 'date'=>'date', 'before'=>'before:2026-07-01', 'after'=>'after:2026-07-10',
		'minimum'=>'min:3', 'between'=>'between:2,4', 'size'=>'size:3', 'maximum'=>'max:4',
		'custom_string'=>[static fn(): string=>'Explicit callable failure.'],
		'custom_false'=>[static fn(): bool=>false],
		'bail_field'=>'bail|required|email|min:100',
		''=>'required',
		'ignored_rules'=>new stdClass(),
	];
	$validator=(new Validator($data, $rules, [
		'missing.required'=>'A custom :attribute is mandatory.',
		'email'=>'Email override.',
	], ['missing'=>'display name']))->stopOnFirstFailure(false);
	$t->isFalse($validator->passes());
	$t->isTrue($validator->fails());
	$errors=$validator->errors();
	foreach([
		'missing', 'missing_if', 'missing_unless', 'missing_with', 'missing_without', 'absent_present',
		'prohibited', 'boolean', 'accepted', 'string', 'integer', 'numeric', 'array', 'duplicate.1',
		'alpha', 'alpha_num', 'prefix', 'suffix', 'digits', 'digits_between', 'file', 'image', 'mimes',
		'mimetypes', 'choice', 'same', 'different', 'password', 'regex', 'email', 'url', 'date', 'before',
		'after', 'minimum', 'between', 'size', 'maximum', 'custom_string', 'custom_false', 'bail_field',
	] as $field){
		$t->contains($field, array_keys($errors));
	}
	$t->contains('display name', $errors['missing'][0]);
	$t->same('Email override.', $errors['email'][0]);
	$t->same('Explicit callable failure.', $errors['custom_string'][0]);
	$t->same(1, count($errors['bail_field']));
	$t->isFalse(array_key_exists('missing', $validator->validated()));
	$t->isFalse($validator->toArray()['valid']);
	$exception=$t->throws(static fn()=>$validator->validateOrThrow(), ValidationException::class);
	$t->same(422, $exception->status());
	$t->same($errors, $exception->errors());
	$t->same('default', $exception->errorBag());
	$t->same($validator->validated(), $exception->validated());
	$t->same(['message', 'errors', 'error_bag'], array_keys($exception->toArray()));
	$t->same(422, $exception->toResponse()->status);

	$first=(new Validator([], ['a'=>'required', 'b'=>'required']))->stopOnFirstFailure();
	$t->same(1, count($first->errors()));
})->tag('mvc', 'validator', 'coverage')->group('framework-coverage');

test('mvc validator conditional exclusion nullable and wildcard edge cases preserve safe data', static function(Context $t): void {
	$data=[
		'mode'=>'public', 'with'=>'yes', 'without'=>'',
		'exclude'=>'secret', 'exclude_if'=>'secret', 'exclude_unless'=>'secret', 'exclude_with'=>'secret', 'exclude_without'=>'secret',
		'nullable'=>null, 'present_empty'=>'', 'items'=>'not-an-array',
		'nested'=>[['value'=>'one'], ['value'=>'two']],
	];
	$rules=[
		'exclude'=>'exclude|required',
		'exclude_if'=>'exclude_if:mode,public|required',
		'exclude_unless'=>'exclude_unless:mode,private|required',
		'exclude_with'=>'exclude_with:with|required',
		'exclude_without'=>'exclude_without:without|required',
		'nullable'=>'nullable|string',
		'present_empty'=>'present|nullable',
		'items.*.value'=>'required',
		'nested.*.value'=>['required', static fn(string $value): bool=>$value!=='bad'],
		'nested.*.confirmation'=>'sometimes|same:nested.*.value',
	];
	$validator=Validator::make($data, $rules);
	$t->isTrue($validator->passes());
	$validated=$validator->validated();
	foreach(['exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without'] as $field){
		$t->isFalse(array_key_exists($field, $validated));
	}
	$t->same(null, $validated['nullable']);
	$t->same('', $validated['present_empty']);
	$t->same('one', $validator->safe('nested.0.value'));
})->tag('mvc', 'validator', 'coverage')->group('framework-coverage');
