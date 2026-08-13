<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Sanitation\InputBag;
use Dataphyre\Sanitation\SanitationManager;
use Dataphyre\Sanitation\SanitizationException;
use Dataphyre\Sanitation\SanitizationResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'sanitation'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
$dp_sanitation_values_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_sanitation_values_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_sanitation_values_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'sanitation']);
if(!class_exists(\dataphyre\sanitation::class, false)){
	require_once $dp_sanitation_values_modules_root.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

test('input bag traverses nested paths and caches immutable projections', static function(Context $t): void {
	$private=$t->nonPublic(InputBag::class);
	$private->replacePropertyForTest('pathSegmentCache',[]);
	$input=[
		''=>'empty-key',
		'profile'=>[
			'name'=>['first'=>' Ada ', 'last'=>'Lovelace'],
			'nullable'=>null,
			'empty_list'=>[],
			'tags'=>['framework'],
		],
		'scalar'=>'value',
		'empty_string'=>'   ',
		'zero'=>0,
		'object'=>(object)['value'=>1],
		'cache'=>[],
	];
	$bag=new InputBag(new SanitationManager(), $input);

	$t->same($input, $bag->all());
	$t->isTrue($bag->has(''));
	$t->isTrue($bag->has('profile.name.first'));
	$t->isTrue($bag->present('profile.nullable'));
	$t->isFalse($bag->has('profile.name.missing'));
	$t->isFalse($bag->has('scalar.child'));
	$t->isTrue($bag->missing('profile.missing'));
	$t->isTrue($bag->filled('profile.name.first'));
	$t->isFalse($bag->filled('profile.nullable'));
	$t->isFalse($bag->filled('profile.empty_list'));
	$t->isFalse($bag->filled('empty_string'));
	$t->isTrue($bag->filled('profile.tags'));
	$t->isTrue($bag->filled('zero'));
	$t->isTrue($bag->filled('object'));
	$t->isTrue($bag->blank('profile.nullable'));
	$t->same(' Ada ', $bag->get('profile.name.first'));
	$t->same('fallback', $bag->get('profile.name.missing', 'fallback'));

	$onlyKeys=['profile.name.first', 'profile.nullable', 'profile.missing', ''];
	$only=[
		'profile'=>['name'=>['first'=>' Ada '], 'nullable'=>null],
		''=>'empty-key',
	];
	$t->same($only, $bag->only($onlyKeys));
	$t->same($only, $bag->only($onlyKeys));

	$exceptKeys=['profile.name.last', 'profile.missing.deep', 'scalar.child', ''];
	$except=$input;
	unset($except['profile']['name']['last'], $except['']);
	$t->same($except, $bag->except($exceptKeys));
	$t->same($except, $bag->except($exceptKeys));

	$t->same('Ada', $bag->clean('profile.name.first', 'default|trim'));
	$t->same('fallback', $bag->clean('profile.missing', 'required', 'fallback'));
	$t->same('fallback', $bag->clean('profile.missing', 'default', 'fallback'));
	$t->same('ADA', $bag->whenPresent('profile.name.first', static fn(string $value): string=>strtoupper(trim($value))));
	$t->same('fallback', $bag->whenPresent('profile.missing', static fn(): string=>'never', 'fallback'));
	$t->same('ADA', $bag->whenFilled('profile.name.first', static fn(string $value): string=>strtoupper(trim($value))));
	$t->same('fallback', $bag->whenFilled('profile.nullable', static fn(): string=>'never', 'fallback'));

	for($index=0; $index<72; $index++){
		$t->isFalse($bag->has('cache.segment_'.$index));
	}
	$t->same(64,count((array)$private->readProperty('pathSegmentCache')));
})->tag('sanitation', 'coverage', 'value-objects')->group('framework-coverage');

test('sanitization result exposes nested data raw input errors and cached projections', static function(Context $t): void {
	$private=$t->nonPublic(SanitizationResult::class);
	$private->replacePropertyForTest('pathSegmentCache',[]);
	$data=[
		''=>'empty-data',
		'profile'=>[
			'name'=>['first'=>'Ada', 'last'=>'Lovelace'],
			'nullable'=>null,
		],
		'scalar'=>'value',
		'cache'=>[],
	];
	$input=[
		''=>'raw-empty',
		'profile'=>[
			'name'=>['first'=>' Raw Ada ', 'last'=>'Raw Lovelace'],
			'nullable'=>null,
		],
		'scalar'=>'raw-value',
	];
	$errors=[
		'profile.name'=>['First profile error.', 'Second profile error.'],
		'plain'=>'Plain error.',
	];
	$result=new SanitizationResult($data, $errors, $input);

	$t->isTrue($result->failed());
	$t->isTrue($result->fails());
	$t->isFalse($result->passed());
	$t->isFalse($result->passes());
	$t->same($data, $result->all());
	$t->same($data, $result->validated());
	$t->same($data, $result->data());
	$t->same($errors, $result->errors());
	$t->same($errors, $result->messages());
	$t->same($errors, $result->error());
	$t->same($errors['profile.name'], $result->error('profile.name'));
	$t->same(null, $result->error('missing'));
	$t->same('First profile error.', $result->firstError());
	$t->same(null, (new SanitizationResult([], ['empty'=>[]]))->firstError());
	$t->same(null, (new SanitizationResult([], []))->firstError());
	$t->same('Plain first.', (new SanitizationResult([], ['plain'=>'Plain first.']))->firstError());
	$t->isTrue($result->has(''));
	$t->isTrue($result->has('profile.name.first'));
	$t->isTrue($result->has('profile.nullable'));
	$t->isFalse($result->has('profile.name.missing'));
	$t->isFalse($result->has('scalar.child'));
	$t->isTrue($result->invalid('profile.name'));
	$t->isFalse($result->invalid('missing'));
	$t->same('Ada', $result->get('profile.name.first'));
	$t->same(null, $result->get('profile.nullable', 'fallback'));
	$t->same('fallback', $result->get('profile.name.missing', 'fallback'));

	$onlyKeys=['profile.name.first', 'profile.nullable', 'profile.missing', ''];
	$only=[
		'profile'=>['name'=>['first'=>'Ada'], 'nullable'=>null],
		''=>'empty-data',
	];
	$t->same($only, $result->only($onlyKeys));
	$t->same($only, $result->only($onlyKeys));

	$exceptKeys=['profile.name.last', 'profile.missing.deep', 'scalar.child', ''];
	$except=$data;
	unset($except['profile']['name']['last'], $except['']);
	$t->same($except, $result->except($exceptKeys));
	$t->same($except, $result->except($exceptKeys));
	$t->same(' Raw Ada ', $result->raw('profile.name.first'));
	$t->same(null, $result->raw('profile.nullable', 'fallback'));
	$t->same('fallback', $result->raw('profile.name.missing', 'fallback'));
	$t->same($input, $result->input());
	$t->throws(static fn()=>$result->ensureValid('Invalid nested input.', ['source'=>'value-object-test']), SanitizationException::class);
	$t->throws(static fn()=>$result->throwIfFailed(), SanitizationException::class);

	$valid=new SanitizationResult(['ok'=>true], [], ['ok'=>'true']);
	$t->same($valid, $valid->ensureValid());
	$t->same($valid, $valid->throwIfFailed());

	for($index=0; $index<72; $index++){
		$t->isFalse($result->has('cache.segment_'.$index));
	}
	$t->same(64,count((array)$private->readProperty('pathSegmentCache')));
})->tag('sanitation', 'coverage', 'value-objects')->group('framework-coverage');
